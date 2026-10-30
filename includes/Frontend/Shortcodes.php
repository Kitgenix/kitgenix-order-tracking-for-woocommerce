<?php
/**
 * Frontend shortcodes for Kitgenix Order Tracking.
 *
 * @package Kitgenix\OrderTracking
 */

declare(strict_types=1);

namespace Kitgenix\OrderTracking\Frontend;

defined( 'ABSPATH' ) || exit;

use WC_Order;
use Kitgenix\OrderTracking\Orders\Order_Tracking;
use Kitgenix\OrderTracking\Support\Carriers;

final class Shortcodes {

	/**
	 * Default map latitude (rough UK/Ireland centre).
	 *
	 * @var float
	 */
	private const DEFAULT_MAP_LAT = 54.0;

	/**
	 * Default map longitude (rough UK/Ireland centre).
	 *
	 * @var float
	 */
	private const DEFAULT_MAP_LNG = -2.0;

	/**
	 * Default map zoom.
	 *
	 * @var int
	 */
	private const DEFAULT_MAP_ZOOM = 5;

	/** Failed-lookup throttle, per remote IP: how many failures within the window trigger a lockout. */
	private const MAX_FAILED_LOOKUPS_PER_IP = 20;
	private const IP_FAILURE_WINDOW         = 15 * MINUTE_IN_SECONDS;
	private const IP_LOCKOUT_DURATION       = 15 * MINUTE_IN_SECONDS;

	/** Failed-lookup throttle, per order: how many wrong email/postcode attempts against the same order trigger a lockout. */
	private const MAX_FAILED_LOOKUPS_PER_ORDER = 5;
	private const ORDER_FAILURE_WINDOW         = DAY_IN_SECONDS;
	private const ORDER_LOCKOUT_DURATION       = 15 * MINUTE_IN_SECONDS;

	/**
	 * Register shortcodes.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_shortcode( 'kitgenix_tracking_form', [ self::class, 'render_tracking_form' ] );
		add_action( 'wp_enqueue_scripts', [ self::class, 'enqueue_styles' ] );
	}

	/**
	 * Enqueue public styles (hooked properly to wp_enqueue_scripts).
	 */
	public static function enqueue_styles(): void {
		$frontend_css_path = KITGENIX_ORDER_TRACKING_FOR_WOOCOMMERCE_PLUGIN_DIR . '/assets/css/frontend.css';
		$frontend_css_ver  = file_exists( $frontend_css_path ) ? (string) filemtime( $frontend_css_path ) : ( defined( 'KITGENIX_ORDER_TRACKING_FOR_WOOCOMMERCE_VERSION' ) ? (string) KITGENIX_ORDER_TRACKING_FOR_WOOCOMMERCE_VERSION : '1.0.5' );
		wp_enqueue_style(
			'kitgenix-order-tracking-frontend',
			KITGENIX_ORDER_TRACKING_FOR_WOOCOMMERCE_PLUGIN_URL . 'assets/css/frontend.css',
			[],
			$frontend_css_ver
		);
	}

	/**
	 * Resolve a submitted order number to a WC_Order.
	 *
	 * wc_get_order() only accepts the *internal* order ID. Stores using a
	 * sequential or custom order-number display plugin (WebToffee/Skyverge
	 * Sequential Order Numbers, Algoritmika Custom Order Numbers, Kitgenix
	 * MultiStore Sync's own network order numbers, etc.) show customers a
	 * different number, so that number has to be translated back to the
	 * internal ID before it can be looked up.
	 *
	 * This mirrors how WooCommerce's own core order-tracking shortcode
	 * resolves the number, via the `woocommerce_shortcode_order_tracking_order_id`
	 * filter, then falls back to a short list of known order-number meta
	 * keys in case the active numbering plugin doesn't hook that filter.
	 *
	 * @param string $order_number Raw order number as submitted by the visitor.
	 *
	 * @return WC_Order|null
	 */
	private static function resolve_order_from_number( string $order_number ): ?WC_Order {
		$order_number = ltrim( trim( $order_number ), '#' );

		if ( '' === $order_number ) {
			return null;
		}

		// As WooCommerce's own order-tracking shortcode does: let a custom or
		// sequential order-number plugin translate the number back to the
		// real internal order ID.
		$filtered = apply_filters( 'woocommerce_shortcode_order_tracking_order_id', $order_number );

		$order = wc_get_order( $filtered );

		if ( $order instanceof WC_Order ) {
			return $order;
		}

		// Fall back to known order-number meta keys used by popular
		// sequential/custom order-number plugins, in case the active one
		// doesn't hook the filter above.
		$meta_keys = (array) apply_filters(
			'kitgenix_order_tracking_for_woocommerce_order_number_meta_keys',
			[
				'_order_number',                                                 // WebToffee / Skyverge Sequential Order Numbers.
				'_order_number_formatted',                                       // WebToffee / Skyverge Sequential Order Numbers (formatted display value).
				'_alg_wc_custom_order_number',                                   // Algoritmika Custom Order Numbers for WooCommerce.
				'_kitgenix_multistore_sync_for_woocommerce_network_order_number', // Kitgenix MultiStore Sync network order numbers.
			]
		);

		foreach ( $meta_keys as $meta_key ) {
			if ( ! is_string( $meta_key ) || '' === $meta_key ) {
				continue;
			}

			$orders = wc_get_orders(
				[
					'limit'      => 1,
					'meta_key'   => $meta_key,
					'meta_value' => $order_number,
					'return'     => 'objects',
				]
			);

			if ( ! empty( $orders[0] ) && $orders[0] instanceof WC_Order ) {
				return $orders[0];
			}
		}

		return null;
	}

	/**
	 * Render the tracking form shortcode.
	 *
	 * @param array       $atts    Shortcode attributes.
	 * @param string|null $content Optional content.
	 *
	 * @return string
	 */
	public static function render_tracking_form( array $atts = [], ?string $content = null ): string {
		$atts = shortcode_atts( [], $atts, 'kitgenix_tracking_form' );
		unset( $atts, $content ); // Currently unused, but kept for future extension.

		// Frontend CSS is enqueued via wp_enqueue_scripts hook.

		$output    = '';
		$order     = null;
		$error     = '';
		$shipments = [];

		$request_method = isset( $_SERVER['REQUEST_METHOD'] )
			? (string) sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) )
			: '';

		$remote_ip = self::get_remote_ip();

		if ( 'POST' === strtoupper( $request_method ) && isset( $_POST['kitgenix_tracking_nonce'] ) ) {
			$nonce = sanitize_text_field( wp_unslash( $_POST['kitgenix_tracking_nonce'] ) );

			if ( self::is_ip_rate_limited( $remote_ip ) ) {
				$error = __( 'Too many lookup attempts. Please wait a few minutes and try again.', 'kitgenix-order-tracking-for-woocommerce' );
			} elseif ( wp_verify_nonce( $nonce, 'kitgenix_tracking_lookup' ) ) {
				$mode = isset( $_POST['kitgenix_tracking_mode'] )
					? sanitize_text_field( wp_unslash( $_POST['kitgenix_tracking_mode'] ) )
					: 'order';

				// Deliberately identical whether the order number doesn't exist or
				// the email doesn't match it, so this form cannot be used to
				// discover which order numbers exist.
				$order_not_found_message = __( 'We could not find a matching order for that order number and email address. Please check your details and try again.', 'kitgenix-order-tracking-for-woocommerce' );

				if ( 'tracking' === $mode ) {
					$tracking_number = isset( $_POST['kitgenix_tracking_number'] )
						? sanitize_text_field( wp_unslash( $_POST['kitgenix_tracking_number'] ) )
						: '';

					if ( '' === $tracking_number ) {
						$error = __( 'Please enter a tracking number.', 'kitgenix-order-tracking-for-woocommerce' );
					} else {
						$order = Order_Tracking::find_order_by_tracking_number( $tracking_number );

						if ( ! $order instanceof WC_Order ) {
							$error = __( 'No order found for that tracking number.', 'kitgenix-order-tracking-for-woocommerce' );
							self::record_ip_failure( $remote_ip );
						} else {
							self::increment_successful_lookup_count();
							self::clear_order_failures( (int) $order->get_id() );
							$shipments = Order_Tracking::get_shipments( $order );
							// If there are no shipments yet, do not set an error.
							// The UI will render a friendly unshipped state below.
						}
					}
				} else {
					$order_number = isset( $_POST['kitgenix_order_number'] )
						? sanitize_text_field( wp_unslash( $_POST['kitgenix_order_number'] ) )
						: '';

					$email = isset( $_POST['kitgenix_order_email'] )
						? sanitize_email( wp_unslash( $_POST['kitgenix_order_email'] ) )
						: '';

					if ( '' === $order_number || '' === $email ) {
						$error = __( 'Please enter your order number and billing email.', 'kitgenix-order-tracking-for-woocommerce' );
					} else {
						$candidate_order    = self::resolve_order_from_number( $order_number );
						$candidate_order_id = $candidate_order instanceof WC_Order ? (int) $candidate_order->get_id() : 0;

						if ( ! $candidate_order instanceof WC_Order || self::is_order_locked( $candidate_order_id ) ) {
							$error = $order_not_found_message;
							self::record_ip_failure( $remote_ip );
						} else {
							$billing_email = $candidate_order->get_billing_email() ?: '';

							if ( 0 !== strcasecmp( $billing_email, $email ) ) {
								$error = $order_not_found_message;
								self::record_ip_failure( $remote_ip );
								self::record_order_failure( $candidate_order_id );
							} else {
								$order = $candidate_order;
								self::increment_successful_lookup_count();
								self::clear_order_failures( $candidate_order_id );
								$shipments = Order_Tracking::get_shipments( $order );
								// If there are no shipments yet, do not set an error.
								// The UI will render a friendly unshipped state below.
							}
						}
					}
				}
			} else {
				$error = __( 'Your request could not be verified. Please try again.', 'kitgenix-order-tracking-for-woocommerce' );
			}
		}

		// Prepare sanitized posted values for display in the form.
		$posted_tracking_mode = isset( $_POST['kitgenix_tracking_mode'] )
			? sanitize_text_field( wp_unslash( $_POST['kitgenix_tracking_mode'] ) )
			: 'order';

		$posted_order_number = isset( $_POST['kitgenix_order_number'] )
			? sanitize_text_field( wp_unslash( $_POST['kitgenix_order_number'] ) )
			: '';

		$posted_order_email = isset( $_POST['kitgenix_order_email'] )
			? sanitize_email( wp_unslash( $_POST['kitgenix_order_email'] ) )
			: '';

		$posted_tracking_number = isset( $_POST['kitgenix_tracking_number'] )
			? sanitize_text_field( wp_unslash( $_POST['kitgenix_tracking_number'] ) )
			: '';

		$posted_order_postcode = isset( $_POST['kitgenix_order_postcode'] )
			? sanitize_text_field( wp_unslash( $_POST['kitgenix_order_postcode'] ) )
			: '';

		$store_location      = self::get_store_location();
		$postal_code_country = (string) $store_location['country'];
		$postal_code_label   = self::get_postal_code_label( $postal_code_country );
		$postal_code_help    = self::get_postal_code_help_text( $postal_code_country );

		// Postcode verification for showing full order details.
		$postcode_verified              = false;
		$postcode_verification_possible = false;

		if ( $order instanceof WC_Order ) {
			$shipping_postcode = (string) $order->get_shipping_postcode();
			$billing_postcode  = (string) $order->get_billing_postcode();
			$shipping_country  = strtoupper( (string) $order->get_shipping_country() );
			$billing_country   = strtoupper( (string) $order->get_billing_country() );
			$postal_code_country = '' !== $shipping_country ? $shipping_country : $billing_country;
			$postal_code_label   = self::get_postal_code_label( $postal_code_country );
			$postal_code_help    = self::get_postal_code_help_text( $postal_code_country );

			// Only enable verification if at least one postcode exists on the order.
			if ( '' !== $shipping_postcode || '' !== $billing_postcode ) {
				$postcode_verification_possible = true;
				$order_id                       = (int) $order->get_id();

				if ( self::is_order_locked( $order_id ) ) {
					// Stay locked out; do not evaluate the submitted postcode at all.
					if ( '' !== trim( $posted_order_postcode ) ) {
						$error = __( 'Too many lookup attempts for this order. Please wait a few minutes and try again.', 'kitgenix-order-tracking-for-woocommerce' );
					}
				} elseif ( '' !== trim( $posted_order_postcode ) ) {
					if (
						self::postal_codes_match( $posted_order_postcode, $shipping_postcode, $shipping_country )
						|| self::postal_codes_match( $posted_order_postcode, $billing_postcode, $billing_country )
					) {
						$postcode_verified = true;
						self::clear_order_failures( $order_id );
					} else {
						self::record_ip_failure( $remote_ip );
						self::record_order_failure( $order_id );
					}
				}
			} else {
				// If there is no postcode stored on the order at all, skip the gate.
				$postcode_verified              = true;
				$postcode_verification_possible = false;
			}
		}

		$can_show_sensitive_order_details = false;
		if ( $order instanceof WC_Order ) {
			$can_show_sensitive_order_details = $postcode_verified || ( ! $postcode_verification_possible && 'order' === $posted_tracking_mode );
		}

		// Map: store + customer addresses → OpenStreetMap/Leaflet.
		$store_address            = '';
		$customer_address         = '';
		$origin_country_code      = '';
		$destination_country_code = '';

		if ( $order instanceof WC_Order ) {
			$store_location      = self::get_store_location();
			$store_address       = $store_location['address'];
			$origin_country_code = $store_location['country'];

			if ( $can_show_sensitive_order_details ) {
				$order_location           = self::get_order_location( $order );
				$customer_address         = $order_location['address'];
				$destination_country_code = $order_location['country'];
			}
		}

        // Index order line items by item ID for quick lookup.
        $order_items_index = [];
        if ( $order instanceof WC_Order ) {
            foreach ( $order->get_items() as $item_id => $item ) {
                $order_items_index[ (int) $item_id ] = $item;
            }
        }

		ob_start();
		?>
		<div class="kitgenix-tracking-wrapper">
			<div class="kitgenix-tracking-card">
				<h2 class="kitgenix-tracking-title">
					<?php esc_html_e( 'Track your order', 'kitgenix-order-tracking-for-woocommerce' ); ?>
				</h2>
				<p class="kitgenix-tracking-intro">
					<?php esc_html_e( 'Enter your order details or tracking number below to see the latest shipment information.', 'kitgenix-order-tracking-for-woocommerce' ); ?>
				</p>

				<form method="post" class="kitgenix-tracking-form">
					<?php wp_nonce_field( 'kitgenix_tracking_lookup', 'kitgenix_tracking_nonce' ); ?>

					<div class="kitgenix-tracking-mode-switch">
						<label class="kitgenix-mode-option">
							<input type="radio" name="kitgenix_tracking_mode" value="order"
								<?php checked( 'order' === $posted_tracking_mode ); ?> />
							<span><?php esc_html_e( 'Order number & email', 'kitgenix-order-tracking-for-woocommerce' ); ?></span>
						</label>
						<label class="kitgenix-mode-option">
							<input type="radio" name="kitgenix_tracking_mode" value="tracking"
								<?php checked( 'tracking' === $posted_tracking_mode ); ?> />
							<span><?php esc_html_e( 'Tracking number only', 'kitgenix-order-tracking-for-woocommerce' ); ?></span>
						</label>
					</div>

					<div class="kitgenix-tracking-fields kitgenix-tracking-mode-order">
						<div class="kitgenix-field-group">
							<label>
								<span class="kitgenix-label">
									<?php esc_html_e( 'Order number', 'kitgenix-order-tracking-for-woocommerce' ); ?>
								</span>
								<input type="text" name="kitgenix_order_number"
								       value="<?php echo esc_attr( (string) $posted_order_number ); ?>" />
							</label>
						</div>
						<div class="kitgenix-field-group">
							<label>
								<span class="kitgenix-label">
									<?php esc_html_e( 'Billing email', 'kitgenix-order-tracking-for-woocommerce' ); ?>
								</span>
								<input type="email" name="kitgenix_order_email"
								       value="<?php echo esc_attr( $posted_order_email ); ?>" />
							</label>
						</div>
					</div>

					<div class="kitgenix-tracking-fields kitgenix-tracking-mode-tracking">
						<div class="kitgenix-field-group">
							<label>
								<span class="kitgenix-label">
									<?php esc_html_e( 'Tracking number', 'kitgenix-order-tracking-for-woocommerce' ); ?>
								</span>
								<input type="text" name="kitgenix_tracking_number"
								       value="<?php echo esc_attr( (string) $posted_tracking_number ); ?>" />
							</label>
						</div>
					</div>

					<div class="kitgenix-field-group kitgenix-field-postcode">
						<label>
							<span class="kitgenix-label">
								<?php echo esc_html( $postal_code_label ); ?>
							</span>
							<input type="text" name="kitgenix_order_postcode"
							       value="<?php echo esc_attr( $posted_order_postcode ); ?>" />
							<span class="kitgenix-help">
								<?php echo esc_html( $postal_code_help ); ?>
							</span>
						</label>
					</div>

					<div class="kitgenix-actions">
						<button type="submit" class="button kitgenix-button-primary">
							<?php esc_html_e( 'Track your order', 'kitgenix-order-tracking-for-woocommerce' ); ?>
						</button>
					</div>

					<?php if ( '' !== $error ) : ?>
						<div class="kitgenix-tracking-error">
							<?php echo esc_html( $error ); ?>
						</div>
					<?php endif; ?>
				</form>
			</div>

			<?php if ( $order instanceof WC_Order && ! empty( $shipments ) ) : ?>
				<div class="kitgenix-tracking-results">
					<div class="kitgenix-results-grid">
						<div class="kitgenix-card kitgenix-card-summary">
							<h3><?php \esc_html_e( 'Order summary', 'kitgenix-order-tracking-for-woocommerce' ); ?></h3>
							<p class="kitgenix-order-meta">
								<?php
								/* translators: 1: WooCommerce order number. */
								printf(
									/* translators: 1: WooCommerce order number. */
									/* translators: %1$s: order number */
									esc_html__( 'Order #%1$s', 'kitgenix-order-tracking-for-woocommerce' ),
									esc_html( $order->get_order_number() )
								);
								?>
							</p>
							<p class="kitgenix-order-meta">
								<?php
								$date_string = '';
								if ( $order->get_date_created() ) {
									$date_string = $order->get_date_created()->date_i18n( get_option( 'date_format' ) );
								}
								/* translators: %s: order date. */
								printf(
									/* translators: %s: order date. */
									/* translators: %s: order date */
									esc_html__( 'Placed on %s', 'kitgenix-order-tracking-for-woocommerce' ),
									esc_html( $date_string )
								);
								?>
							</p>
							<p class="kitgenix-order-status">
								<?php
								/* translators: %s: order status (e.g. Completed). */
								printf(
									/* translators: %s: order status (e.g. Completed). */
									/* translators: %s: order status */
									esc_html__( 'Status: %s', 'kitgenix-order-tracking-for-woocommerce' ),
									esc_html( wc_get_order_status_name( $order->get_status() ) )
								);
								?>
							</p>
						</div>

						<div class="kitgenix-card kitgenix-card-shipments">
							<h3><?php esc_html_e( 'Shipment Tracking', 'kitgenix-order-tracking-for-woocommerce' ); ?></h3>
							<ul class="kitgenix-tracking-list">
								<?php foreach ( $shipments as $shipment ) : ?>
									<?php
									$carrier       = $shipment['carrier'] ?? '';
									$number        = $shipment['tracking_number'] ?? '';
									$url           = $shipment['tracking_url'] ?? '';
									$carrier_label = Carriers::get_label( $carrier );
									$icon          = Carriers::get_icon( $carrier );
									?>
									<li class="kitgenix-tracking-item">
										<div class="kitgenix-tracking-header">
											<?php if ( $icon ) : ?>
												<span class="kitgenix-carrier-icon">
													<img src="<?php echo esc_url( $icon ); ?>" alt="" loading="lazy" />
												</span>
											<?php endif; ?>
											<span class="kitgenix-carrier-name">
												<?php echo esc_html( $carrier_label ); ?>
											</span>
										</div>
										<div class="kitgenix-tracking-body">
											<p class="kitgenix-tracking-number">
												<span class="kitgenix-label">
													<?php esc_html_e( 'Tracking number:', 'kitgenix-order-tracking-for-woocommerce' ); ?>
												</span>
												<span class="kitgenix-value">
													<?php echo esc_html( $number ); ?>
												</span>
											</p>
											<?php if ( $url ) : ?>
												<p class="kitgenix-tracking-link">
													<a href="<?php echo esc_url( $url ); ?>" target="_blank" rel="noopener noreferrer">
														<?php esc_html_e( 'Open carrier tracking page', 'kitgenix-order-tracking-for-woocommerce' ); ?>
													</a>
												</p>
											<?php else : ?>
												<p class="kitgenix-tracking-link kitgenix-tracking-link-muted">
													<?php esc_html_e( 'Online tracking is not available for this carrier.', 'kitgenix-order-tracking-for-woocommerce' ); ?>
												</p>
											<?php endif; ?>

											<?php
											$shipment_items = isset( $shipment['items'] ) && is_array( $shipment['items'] ) ? $shipment['items'] : [];
											if ( ! empty( $shipment_items ) ) :
											?>
												<div class="kitgenix-shipment-items">
													<span class="kitgenix-label">
														<?php esc_html_e( 'Items in this shipment:', 'kitgenix-order-tracking-for-woocommerce' ); ?>
													</span>
													<ul class="kitgenix-items-list kitgenix-items-list--shipment">
														<?php foreach ( $shipment_items as $s_item ) : ?>
															<?php
															$s_item_id = isset( $s_item['item_id'] ) ? (int) $s_item['item_id'] : 0;
															$s_qty     = isset( $s_item['qty'] ) ? (float) $s_item['qty'] : 0.0;
															if ( ! $s_item_id || $s_qty <= 0 ) {
																continue;
															}

															$order_item = $order_items_index[ $s_item_id ] ?? null;
															/* translators: %d: order item ID */
															$name       = $order_item ? $order_item->get_name() : sprintf( __( 'Item #%d', 'kitgenix-order-tracking-for-woocommerce' ), $s_item_id );
															$ordered_q  = $order_item ? (float) $order_item->get_quantity() : 0.0;
															?>
															<li class="kitgenix-item">
																<span class="kitgenix-item-name"><?php echo esc_html( $name ); ?></span>
																<span class="kitgenix-item-qty">
																	<?php
																	if ( $ordered_q > 0 ) {
																		printf(
																			/* translators: 1: shipped qty, 2: total ordered qty */
																			/* translators: %1$s: shipped quantity, %2$s: total ordered quantity */
																			esc_html__( '%1$s of %2$s', 'kitgenix-order-tracking-for-woocommerce' ),
																			esc_html( wc_format_decimal( $s_qty, 0 ) ),
																			esc_html( wc_format_decimal( $ordered_q, 0 ) )
																		);
																	} else {
																		printf(
																			/* translators: %s: quantity shipped */
																			/* translators: %s: quantity shipped */
																			esc_html__( '× %s', 'kitgenix-order-tracking-for-woocommerce' ),
																			esc_html( wc_format_decimal( $s_qty, 0 ) )
																		);
																	}
																	?>
																</span>
															</li>
														<?php endforeach; ?>
													</ul>
												</div>
											<?php endif; ?>
										</div>
									</li>
								<?php endforeach; ?>
							</ul>
						</div>
					</div>

					<div class="kitgenix-card kitgenix-card-details kitgenix-mt-16">
						<h3><?php esc_html_e( 'Order details', 'kitgenix-order-tracking-for-woocommerce' ); ?></h3>

						<?php if ( $can_show_sensitive_order_details ) : ?>
							<div class="kitgenix-details-grid">
								<div class="kitgenix-details-column">
									<h4><?php esc_html_e( 'Billing address', 'kitgenix-order-tracking-for-woocommerce' ); ?></h4>
									<p class="kitgenix-details-address">
										<?php
										$billing_address = $order->get_formatted_billing_address();
										if ( $billing_address ) {
											echo wp_kses_post( $billing_address );
										} else {
											esc_html_e( 'No billing address available.', 'kitgenix-order-tracking-for-woocommerce' );
										}
										?>
									</p>
								</div>
								<div class="kitgenix-details-column">
									<h4><?php esc_html_e( 'Shipping address', 'kitgenix-order-tracking-for-woocommerce' ); ?></h4>
									<p class="kitgenix-details-address">
										<?php
										$shipping_address = $order->get_formatted_shipping_address();
										if ( $shipping_address ) {
											echo wp_kses_post( $shipping_address );
										} else {
											esc_html_e( 'No shipping address available.', 'kitgenix-order-tracking-for-woocommerce' );
										}
										?>
									</p>
								</div>
							</div>

							<?php
							// Build a shipped qty map for this order.
							$shipped_map = [];
							foreach ( $shipments as $shipment ) {
								$shipment_items = isset( $shipment['items'] ) && is_array( $shipment['items'] ) ? $shipment['items'] : [];
								foreach ( $shipment_items as $s_item ) {
									$sid = isset( $s_item['item_id'] ) ? (int) $s_item['item_id'] : 0;
									$sq  = isset( $s_item['qty'] ) ? (float) $s_item['qty'] : 0.0;
									if ( $sid && $sq > 0 ) {
										$shipped_map[ $sid ] = ( $shipped_map[ $sid ] ?? 0 ) + $sq;
									}
								}
							}
							?>

							<div class="kitgenix-details-items">
								<h4><?php esc_html_e( 'Items in this order', 'kitgenix-order-tracking-for-woocommerce' ); ?></h4>
								<ul class="kitgenix-items-list kitgenix-items-list--order">
									<?php foreach ( $order->get_items() as $item_id => $item ) : ?>
										<?php
										$product   = $item->get_product();
										$image_html = $product ? $product->get_image( 'thumbnail' ) : '';
										$item_name = $item->get_name();
										$ordered   = (float) $item->get_quantity();
										$shipped   = isset( $shipped_map[ (int) $item_id ] ) ? (float) $shipped_map[ (int) $item_id ] : 0.0;
										$shipped   = min( $shipped, $ordered );
										$left      = max( 0.0, $ordered - $shipped );
										?>
										<li class="kitgenix-item kitgenix-item--order">
											<span class="kitgenix-item-left">
												<?php if ( $image_html ) : ?>
													<span class="kitgenix-item-image"><?php echo wp_kses_post( $image_html ); ?></span>
												<?php endif; ?>
												<span class="kitgenix-item-name"><?php echo esc_html( $item_name ); ?></span>
											</span>
											<span class="kitgenix-item-stats">
												<span class="kitgenix-item-shipped">
													<?php
													printf(
														/* translators: %s: shipped qty */
														/* translators: %s: quantity shipped */
														/* translators: %s: shipped quantity */
														esc_html__( 'Shipped: %s', 'kitgenix-order-tracking-for-woocommerce' ),
														esc_html( wc_format_decimal( $shipped, 0 ) )
													);
													?>
												</span>
												<span class="kitgenix-item-remaining">
													<?php
													printf(
														/* translators: %s: not shipped qty */
														/* translators: %s: quantity not shipped */
														/* translators: %s: quantity not shipped */
														esc_html__( 'Not shipped: %s', 'kitgenix-order-tracking-for-woocommerce' ),
														esc_html( wc_format_decimal( $left, 0 ) )
													);
													?>
												</span>
											</span>
										</li>
									<?php endforeach; ?>
								</ul>
							</div>
						<?php else : ?>
							<p class="kitgenix-details-note">
								<?php
								if ( $postcode_verification_possible ) {
									esc_html_e( 'For your privacy, full addresses, route maps, and item details are only shown after you confirm the postcode, ZIP code, or postal code used on your order.', 'kitgenix-order-tracking-for-woocommerce' );
								} else {
									esc_html_e( 'Full addresses, route maps, and item details are not available for this order from this lookup method.', 'kitgenix-order-tracking-for-woocommerce' );
								}
								?>
							</p>
						<?php endif; ?>
					</div>

					<?php if ( $store_address && $customer_address ) : ?>
						<div class="kitgenix-card kitgenix-card-map kitgenix-mt-16">
							<h3><?php esc_html_e( 'Route from store to delivery address', 'kitgenix-order-tracking-for-woocommerce' ); ?></h3>

							<div class="kitgenix-map-embed">
								<div
									id="kitgenix-order-map"
									data-origin="<?php echo esc_attr( $store_address ); ?>"
									data-origin-country="<?php echo esc_attr( $origin_country_code ); ?>"
									data-destination="<?php echo esc_attr( $customer_address ); ?>"
									data-destination-country="<?php echo esc_attr( $destination_country_code ); ?>"></div>
							</div>

							<p id="kitgenix-order-map-message" class="kitgenix-map-message"></p>

							<p class="kitgenix-map-note">
								<?php esc_html_e( 'Map powered by OpenStreetMap. Route is illustrative only and may not precisely match the actual journey or address.', 'kitgenix-order-tracking-for-woocommerce' ); ?>
							</p>
						</div>
					<?php endif; ?>
				</div>
			<?php elseif ( $order instanceof WC_Order && empty( $shipments ) && '' === $error ) : ?>
				<div class="kitgenix-tracking-results">
					<div class="kitgenix-results-grid">
						<div class="kitgenix-card kitgenix-card-summary">
							<h3><?php esc_html_e( 'Order summary', 'kitgenix-order-tracking-for-woocommerce' ); ?></h3>
							<p class="kitgenix-order-meta">
								<?php
								printf(
									/* translators: %1$s: order number */
									\esc_html__( 'Order #%1$s', 'kitgenix-order-tracking-for-woocommerce' ),
									\esc_html( $order->get_order_number() )
								);
								?>
							</p>
							<p class="kitgenix-order-meta">
								<?php
								$date_string = '';
								if ( $order->get_date_created() ) {
									$date_string = $order->get_date_created()->date_i18n( \get_option( 'date_format' ) );
								}
								printf(
									/* translators: %s: order date. */
									\esc_html__( 'Placed on %s', 'kitgenix-order-tracking-for-woocommerce' ),
									\esc_html( $date_string )
								);
								?>
							</p>
							<p class="kitgenix-order-status">
								<?php
								printf(
									/* translators: %s: order status (e.g. Completed). */
									\esc_html__( 'Status: %s', 'kitgenix-order-tracking-for-woocommerce' ),
									\esc_html( \wc_get_order_status_name( $order->get_status() ) )
								);
								?>
							</p>
						</div>

						<div class="kitgenix-card kitgenix-card-shipments">
							<h3><?php \esc_html_e( 'Shipment Tracking', 'kitgenix-order-tracking-for-woocommerce' ); ?></h3>
							<p class="kitgenix-tracking-no-shipments">
								<?php \esc_html_e( 'This order has not yet been dispatched. Tracking information will be displayed here once your items have been shipped. Please refer to your order confirmation email to confirm that your goods are not on back-order.', 'kitgenix-order-tracking-for-woocommerce' ); ?>
							</p>
						</div>
					</div>

					<div class="kitgenix-card kitgenix-card-details kitgenix-mt-16">
							<h3><?php \esc_html_e( 'Order details', 'kitgenix-order-tracking-for-woocommerce' ); ?></h3>

						<?php if ( $can_show_sensitive_order_details ) : ?>
							<div class="kitgenix-details-grid">
								<div class="kitgenix-details-column">
									<h4><?php \esc_html_e( 'Billing address', 'kitgenix-order-tracking-for-woocommerce' ); ?></h4>
									<p class="kitgenix-details-address">
										<?php
										$billing_address = $order->get_formatted_billing_address();
										if ( $billing_address ) {
											echo \wp_kses_post( $billing_address );
										} else {
												\esc_html_e( 'No billing address available.', 'kitgenix-order-tracking-for-woocommerce' );
										}
										?>
									</p>
								</div>
								<div class="kitgenix-details-column">
									<h4><?php \esc_html_e( 'Shipping address', 'kitgenix-order-tracking-for-woocommerce' ); ?></h4>
									<p class="kitgenix-details-address">
										<?php
										$shipping_address = $order->get_formatted_shipping_address();
										if ( $shipping_address ) {
											echo \wp_kses_post( $shipping_address );
										} else {
												\esc_html_e( 'No shipping address available.', 'kitgenix-order-tracking-for-woocommerce' );
										}
										?>
									</p>
								</div>
							</div>

							<?php
							// With no shipments, everything is not shipped yet.
							$shipped_map = [];
							?>

							<div class="kitgenix-details-items">
								<h4><?php \esc_html_e( 'Items in this order', 'kitgenix-order-tracking-for-woocommerce' ); ?></h4>
								<ul class="kitgenix-items-list kitgenix-items-list--order">
									<?php foreach ( $order->get_items() as $item_id => $item ) : ?>
										<?php
										$product    = $item->get_product();
										$image_html = $product ? $product->get_image( 'thumbnail' ) : '';
										$item_name  = $item->get_name();
										$ordered    = (float) $item->get_quantity();
										$shipped    = 0.0;
										$left       = max( 0.0, $ordered - $shipped );
										?>
										<li class="kitgenix-item kitgenix-item--order">
											<span class="kitgenix-item-left">
												<?php if ( $image_html ) : ?>
													<span class="kitgenix-item-image"><?php echo \wp_kses_post( $image_html ); ?></span>
												<?php endif; ?>
												<span class="kitgenix-item-name"><?php echo esc_html( $item_name ); ?></span>
											</span>
											<span class="kitgenix-item-stats">
												<span class="kitgenix-item-shipped">
													<?php
													printf(
														/* translators: %s: shipped quantity */
														\esc_html__( 'Shipped: %s', 'kitgenix-order-tracking-for-woocommerce' ),
														\esc_html( wc_format_decimal( $shipped, 0 ) )
													);
													?>
												</span>
												<span class="kitgenix-item-remaining">
													<?php
													printf(
														/* translators: %s: quantity not shipped */
														\esc_html__( 'Not shipped: %s', 'kitgenix-order-tracking-for-woocommerce' ),
														\esc_html( wc_format_decimal( $left, 0 ) )
													);
													?>
												</span>
											</span>
										</li>
									<?php endforeach; ?>
								</ul>
							</div>
						<?php else : ?>
							<p class="kitgenix-details-note">
								<?php
								if ( $postcode_verification_possible ) {
									esc_html_e( 'For your privacy, full addresses, route maps, and item details are only shown after you confirm the postcode, ZIP code, or postal code used on your order.', 'kitgenix-order-tracking-for-woocommerce' );
								} else {
									esc_html_e( 'Full addresses, route maps, and item details are not available for this order from this lookup method.', 'kitgenix-order-tracking-for-woocommerce' );
								}
								?>
							</p>
						<?php endif; ?>
					</div>

					<?php if ( $store_address && $customer_address ) : ?>
						<div class="kitgenix-card kitgenix-card-map kitgenix-mt-16">
							<h3><?php esc_html_e( 'Route from store to delivery address', 'kitgenix-order-tracking-for-woocommerce' ); ?></h3>

							<div class="kitgenix-map-embed">
								<div
									id="kitgenix-order-map"
									data-origin="<?php echo esc_attr( $store_address ); ?>"
									data-origin-country="<?php echo esc_attr( $origin_country_code ); ?>"
									data-destination="<?php echo esc_attr( $customer_address ); ?>"
									data-destination-country="<?php echo esc_attr( $destination_country_code ); ?>"></div>
							</div>

							<p id="kitgenix-order-map-message" class="kitgenix-map-message"></p>

							<p class="kitgenix-map-note">
								<?php esc_html_e( 'Map powered by OpenStreetMap. Route is illustrative only and may not precisely match the actual journey or address.', 'kitgenix-order-tracking-for-woocommerce' ); ?>
							</p>
						</div>
					<?php endif; ?>
				</div>
			<?php endif; ?>
		</div>
		<?php

		$output = (string) ob_get_clean();

		// Styles are now enqueued via wp_enqueue_style('kitgenix-order-tracking-frontend').

		// Leaflet (OpenStreetMap) from local vendor copies (no CDN by default).
		$leaflet_css_file = KITGENIX_ORDER_TRACKING_FOR_WOOCOMMERCE_PLUGIN_DIR . '/assets/vendor/leaflet/leaflet.css';
		$leaflet_js_file  = KITGENIX_ORDER_TRACKING_FOR_WOOCOMMERCE_PLUGIN_DIR . '/assets/vendor/leaflet/leaflet.js';

		$leaflet_css_url = KITGENIX_ORDER_TRACKING_FOR_WOOCOMMERCE_PLUGIN_URL . 'assets/vendor/leaflet/leaflet.css';
		$leaflet_js_url  = KITGENIX_ORDER_TRACKING_FOR_WOOCOMMERCE_PLUGIN_URL . 'assets/vendor/leaflet/leaflet.js';

		$has_leaflet = file_exists( $leaflet_css_file ) && file_exists( $leaflet_js_file );

		// No remote CDN fallback to comply with .org guidelines.

		if ( $has_leaflet ) {
			if ( file_exists( $leaflet_css_file ) && file_exists( $leaflet_js_file ) ) {
				$css_version = filemtime( $leaflet_css_file ) ?: null;
				$js_version  = filemtime( $leaflet_js_file ) ?: null;
			} else {
				$css_version = null;
				$js_version  = null;
			}

			wp_enqueue_style( 'kitgenix-leaflet', $leaflet_css_url, [], $css_version );
			wp_enqueue_script( 'kitgenix-leaflet', $leaflet_js_url, [], $js_version, true );
			$frontend_deps = [ 'kitgenix-leaflet' ];
		} else {
			$frontend_deps = [];
		}

		wp_register_script( 'kitgenix-order-tracking-frontend', '', $frontend_deps, ( defined( 'KITGENIX_ORDER_TRACKING_FOR_WOOCOMMERCE_VERSION' ) ? (string) KITGENIX_ORDER_TRACKING_FOR_WOOCOMMERCE_VERSION : '1.0.5' ), true );
		wp_enqueue_script( 'kitgenix-order-tracking-frontend' );

		// Localised settings for Nominatim + map behaviour.
		$nominatim_email = (string) apply_filters( 'kitgenix_order_tracking_for_woocommerce_nominatim_email', '' );

		$settings = [
			'nominatimEndpoint' => apply_filters(
				'kitgenix_order_tracking_for_woocommerce_nominatim_endpoint',
				'https://nominatim.openstreetmap.org/search'
			),
			'nominatimEmail'    => $nominatim_email,
			'mapDefaults'       => [
				'lat'  => self::DEFAULT_MAP_LAT,
				'lng'  => self::DEFAULT_MAP_LNG,
				'zoom' => self::DEFAULT_MAP_ZOOM,
			],
			'i18n'              => [
				'routeNotFound'            => esc_html__( 'We could not determine a route for this order.', 'kitgenix-order-tracking-for-woocommerce' ),
				'originOnly'               => esc_html__( 'Unable to locate the customer address, showing store location only.', 'kitgenix-order-tracking-for-woocommerce' ),
				'destinationOnly'          => esc_html__( 'Unable to locate the store address, showing customer location only.', 'kitgenix-order-tracking-for-woocommerce' ),
				'noAddressesAvailable'     => esc_html__( 'We could not determine map locations for this order.', 'kitgenix-order-tracking-for-woocommerce' ),
				'storeMarkerLabel'         => esc_html__( 'Store', 'kitgenix-order-tracking-for-woocommerce' ),
				'customerMarkerLabel'      => esc_html__( 'Customer', 'kitgenix-order-tracking-for-woocommerce' ),
				'tilesAttribution'         => esc_html__( '&copy; OpenStreetMap contributors', 'kitgenix-order-tracking-for-woocommerce' ),
				'geocodeErrorGeneric'      => esc_html__( 'There was a problem loading the map for this order.', 'kitgenix-order-tracking-for-woocommerce' ),
				'invalidCoordinatesNotice' => esc_html__( 'We could not determine a valid location for this order.', 'kitgenix-order-tracking-for-woocommerce' ),
			],
		];

		wp_localize_script( 'kitgenix-order-tracking-frontend', 'KitgenixOTWSettings', $settings );

		// Frontend JS: robust, polite geocoding and a straight polyline between origin/destination.
		$frontend_script = "(function() {\n\t'use strict';\n\n\t/**\n\t * Toggle form sections based on tracking mode.\n\t */\n\tfunction initTrackingModeToggle() {\n\t\tvar form = document.querySelector('.kitgenix-tracking-form');\n\t\tif (!form) {\n\t\t\treturn;\n\t\t}\n\n\t\tvar modeInputs = form.querySelectorAll('input[name=\"kitgenix_tracking_mode\"]');\n\t\tvar orderFields = form.querySelector('.kitgenix-tracking-mode-order');\n\t\tvar trackingFields = form.querySelector('.kitgenix-tracking-mode-tracking');\n\n\t\tif (!modeInputs.length || !orderFields || !trackingFields) {\n\t\t\treturn;\n\t\t}\n\n\t\tfunction updateMode() {\n\t\t\tvar mode = 'order';\n\t\t\tfor (var i = 0; i < modeInputs.length; i++) {\n\t\t\t\tif (modeInputs[i].checked) {\n\t\t\t\t\tmode = modeInputs[i].value;\n\t\t\t\t\tbreak;\n\t\t\t\t}\n\t\t\t}\n\n\t\t\tif (mode === 'tracking') {\n\t\t\t\torderFields.style.display = 'none';\n\t\t\t\ttrackingFields.style.display = '';\n\t\t\t} else {\n\t\t\t\torderFields.style.display = '';\n\t\t\t\ttrackingFields.style.display = 'none';\n\t\t\t}\n\t\t}\n\n\t\tfor (var i = 0; i < modeInputs.length; i++) {\n\t\t\tmodeInputs[i].addEventListener('change', updateMode);\n\t\t}\n\n\t\tupdateMode();\n\t}\n\n\t/**\n\t * Build a small set of geocoding queries from a postal address.\n\t * Strategy:\n\t * 1. Full address.\n\t * 2. Last segment that contains digits (likely a postcode).\n\t * 3. Last segment without digits (likely town/city).\n\t *\n\t * @param {string} address\n\t * @returns {string[]}\n\t */\n\tfunction buildGeocodeQueries(address) {\n\t\tvar queries = [];\n\t\tif (!address) {\n\t\t\treturn queries;\n\t\t}\n\n\t\tvar trimmed = String(address).trim();\n\t\tif (!trimmed) {\n\t\t\treturn queries;\n\t\t}\n\n\t\tqueries.push(trimmed);\n\n\t\tvar parts = trimmed.split(',').map(function(part) {\n\t\t\treturn part.trim();\n\t\t}).filter(function(part) {\n\t\t\treturn part.length > 0;\n\t\t});\n\n\t\tif (!parts.length) {\n\t\t\treturn queries;\n\t\t}\n\n\t\t// Candidate postcode: last segment with a digit.\n\t\tvar postcodeCandidate = '';\n\t\tfor (var i = parts.length - 1; i >= 0; i--) {\n\t\t\tif (/\\d/.test(parts[i])) {\n\t\t\t\tpostcodeCandidate = parts[i];\n\t\t\t\tbreak;\n\t\t\t}\n\t\t}\n\t\tif (postcodeCandidate && queries.indexOf(postcodeCandidate) === -1) {\n\t\t\tqueries.push(postcodeCandidate);\n\t\t}\n\n\t\t// Candidate locality: last segment without digits.\n\t\tvar localityCandidate = '';\n\t\tfor (var j = parts.length - 1; j >= 0; j--) {\n\t\t\tif (!/\\d/.test(parts[j])) {\n\t\t\t\tlocalityCandidate = parts[j];\n\t\t\t\tbreak;\n\t\t\t}\n\t\t}\n\t\tif (localityCandidate && queries.indexOf(localityCandidate) === -1) {\n\t\t\tqueries.push(localityCandidate);\n\t\t}\n\n\t\t// Cap to 3 queries total to stay polite with the geocoder.\n\t\tif (queries.length > 3) {\n\t\t\tqueries = queries.slice(0, 3);\n\t\t}\n\n\t\treturn queries;\n\t}\n\n\t/**\n\t * Geocode an address via Nominatim with multi-step lookups.\n\t *\n\t * Steps:\n\t * - Try up to three queries (full → postcode → city) with countrycodes.\n\t * - If all fail, return null (NO country-centroid fallback).\n\t *\n\t * @param {string} address\n\t * @param {string} countryCode\n\t * @returns {Promise<{lat:number,lng:number}|null>}\n\t */\n\tfunction geocodeAddress(address, countryCode) {\n\t\tvar settings = window.KitgenixOTWSettings || {};\n\t\tvar endpoint = settings.nominatimEndpoint || 'https://nominatim.openstreetmap.org/search';\n\t\tvar email = settings.nominatimEmail || '';\n\t\tvar country = (countryCode || '').toLowerCase();\n\t\tvar queries = buildGeocodeQueries(address);\n\n\t\tif (!queries.length) {\n\t\t\treturn Promise.resolve(null);\n\t\t}\n\n\t\tfunction buildUrl(query) {\n\t\t\tvar url = endpoint + '?format=jsonv2&limit=1';\n\t\t\tif (query) {\n\t\t\t\turl += '&q=' + encodeURIComponent(query);\n\t\t\t}\n\t\t\tif (country) {\n\t\t\t\turl += '&countrycodes=' + encodeURIComponent(country);\n\t\t\t}\n\t\t\tif (email) {\n\t\t\t\turl += '&email=' + encodeURIComponent(email);\n\t\t\t}\n\t\t\treturn url;\n\t\t}\n\n\t\tfunction fetchJson(url) {\n\t\t\treturn fetch(url, {\n\t\t\t\tmethod: 'GET'\n\t\t\t}).then(function(response) {\n\t\t\t\tif (!response.ok) {\n\t\t\t\t\treturn [];\n\t\t\t\t}\n\t\t\t\treturn response.json();\n\t\t\t});\n\t\t}\n\n\t\tvar index = 0;\n\n\t\tfunction tryNextQuery() {\n\t\t\tif (index >= queries.length) {\n\t\t\t\t// We tried everything we consider reasonable; fail cleanly.\n\t\t\t\treturn Promise.resolve(null);\n\t\t\t}\n\n\t\t\tvar query = queries[index++];\n\t\t\treturn fetchJson(buildUrl(query)).then(function(data) {\n\t\t\t\tif (data && data.length && data[0].lat && data[0].lon) {\n\t\t\t\t\treturn {\n\t\t\t\t\t\tlat: parseFloat(data[0].lat),\n\t\t\t\t\t\tlng: parseFloat(data[0].lon)\n\t\t\t\t\t};\n\t\t\t\t}\n\t\t\t\treturn tryNextQuery();\n\t\t\t}).catch(function() {\n\t\t\t\treturn tryNextQuery();\n\t\t\t});\n\t\t}\n\n\t\treturn tryNextQuery();\n\t}\n\n\t/**\n\t * Initialise the Leaflet map, geocode both endpoints, and draw markers + straight polyline.\n\t */\n\tfunction initMap() {\n\t\tif (typeof window.L === 'undefined') {\n\t\t\treturn;\n\t\t}\n\n\t\tvar container = document.getElementById('kitgenix-order-map');\n\t\tif (!container) {\n\t\t\treturn;\n\t\t}\n\n\t\tvar settings = window.KitgenixOTWSettings || {};\n\t\tvar defaults = settings.mapDefaults || {};\n\t\tvar i18n = settings.i18n || {};\n\n\t\tvar defaultLat = typeof defaults.lat === 'number' ? defaults.lat : 54.0;\n\t\tvar defaultLng = typeof defaults.lng === 'number' ? defaults.lng : -2.0;\n\t\tvar defaultZoom = typeof defaults.zoom === 'number' ? defaults.zoom : 5;\n\n\t\tvar originAddress = container.getAttribute('data-origin') || '';\n\t\tvar originCountry = container.getAttribute('data-origin-country') || '';\n\t\tvar destAddress = container.getAttribute('data-destination') || '';\n\t\tvar destCountry = container.getAttribute('data-destination-country') || '';\n\n\t\tvar messageEl = document.getElementById('kitgenix-order-map-message');\n\n\t\t// Initialise Leaflet map with OSM tiles.\n\t\tvar map = L.map(container).setView([defaultLat, defaultLng], defaultZoom);\n\n\t\tL.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {\n\t\t\tmaxZoom: 19,\n\t\t\tattribution: i18n.tilesAttribution || '&copy; OpenStreetMap contributors'\n\t\t}).addTo(map);\n\n\t\t// If we have no usable addresses at all, just keep the default view and show a small notice.\n\t\tif (!originAddress && !destAddress) {\n\t\t\tif (messageEl) {\n\t\t\t\tmessageEl.textContent = i18n.noAddressesAvailable || 'We could not determine map locations for this order.';\n\t\t\t}\n\t\t\treturn;\n\t\t}\n\n\t\tvar originPromise = originAddress\n\t\t\t? geocodeAddress(originAddress, originCountry)\n\t\t\t: Promise.resolve(null);\n\n\t\tvar destPromise = destAddress\n\t\t\t? geocodeAddress(destAddress, destCountry)\n\t\t\t: Promise.resolve(null);\n\n\t\tPromise.all([originPromise, destPromise]).then(function(results) {\n\t\t\tvar originPoint = results[0];\n\t\t\tvar destPoint = results[1];\n\n\t\t\tvar markers = [];\n\n\t\t\tif (originPoint && isFinite(originPoint.lat) && isFinite(originPoint.lng)) {\n\t\t\t\tvar originMarker = L.marker([originPoint.lat, originPoint.lng]).addTo(map);\n\t\t\t\toriginMarker.bindPopup(i18n.storeMarkerLabel || 'Store');\n\t\t\t\tmarkers.push(originMarker);\n\t\t\t}\n\n\t\t\tif (destPoint && isFinite(destPoint.lat) && isFinite(destPoint.lng)) {\n\t\t\t\tvar destMarker = L.marker([destPoint.lat, destPoint.lng]).addTo(map);\n\t\t\t\tdestMarker.bindPopup(i18n.customerMarkerLabel || 'Customer');\n\t\t\t\tmarkers.push(destMarker);\n\t\t\t}\n\n\t\t\t// Draw a straight line between the two points if we have both.\n\t\t\tif (originPoint && destPoint) {\n\t\t\t\tvar polyline = L.polyline(\n\t\t\t\t\t[\n\t\t\t\t\t\t[originPoint.lat, originPoint.lng],\n\t\t\t\t\t\t[destPoint.lat, destPoint.lng]\n\t\t\t\t\t],\n\t\t\t\t\t{\n\t\t\t\t\t\tweight: 3,\n\t\t\t\t\t\topacity: 0.85\n\t\t\t\t\t}\n\t\t\t\t).addTo(map);\n\n\t\t\t\tmap.fitBounds(polyline.getBounds(), {\n\t\t\t\t\tpadding: [30, 30]\n\t\t\t\t});\n\n\t\t\t\tif (messageEl) {\n\t\t\t\t\tmessageEl.textContent = '';\n\t\t\t\t}\n\t\t\t} else if (markers.length === 1) {\n\t\t\t\tmap.setView(markers[0].getLatLng(), 10);\n\n\t\t\t\tif (messageEl) {\n\t\t\t\t\tif (originPoint && !destPoint) {\n\t\t\t\t\t\tmessageEl.textContent = i18n.originOnly || 'Unable to locate the customer address, showing store location only.';\n\t\t\t\t\t} else if (!originPoint && destPoint) {\n\t\t\t\t\t\tmessageEl.textContent = i18n.destinationOnly || 'Unable to locate the store address, showing customer location only.';\n\t\t\t\t\t} else {\n\t\t\t\t\t\tmessageEl.textContent = i18n.invalidCoordinatesNotice || 'We could not determine a valid location for this order.';\n\t\t\t\t\t}\n\t\t\t\t}\n\t\t\t} else {\n\t\t\t\t// Nothing sensible to show – hide the map canvas, show a clean message.\n\t\t\t\tif (messageEl) {\n\t\t\t\t\tmessageEl.textContent = i18n.routeNotFound || 'We could not determine a route for this order.';\n\t\t\t\t}\n\n\t\t\t\tvar card = container.closest('.kitgenix-card-map');\n\t\t\t\tif (card) {\n\t\t\t\t\tvar mapEmbed = card.querySelector('.kitgenix-map-embed');\n\t\t\t\t\tif (mapEmbed) {\n\t\t\t\t\t\tmapEmbed.style.display = 'none';\n\t\t\t\t\t}\n\t\t\t\t}\n\n\t\t\t\tmap.remove();\n\t\t\t}\n\t\t}).catch(function() {\n\t\t\tmap.setView([defaultLat, defaultLng], defaultZoom);\n\t\t\tif (messageEl) {\n\t\t\t\tmessageEl.textContent = i18n.geocodeErrorGeneric || 'There was a problem loading the map for this order.';\n\t\t\t}\n\t\t});\n\t}\n\n\tfunction onReady(fn) {\n\t\tif (document.readyState === 'loading') {\n\t\t\tdocument.addEventListener('DOMContentLoaded', fn);\n\t\t} else {\n\t\t\tfn();\n\t\t}\n\t}\n\n\tonReady(function() {\n\t\tinitTrackingModeToggle();\n\t\tinitMap();\n\t});\n\n\n})();";

		wp_add_inline_script( 'kitgenix-order-tracking-frontend', $frontend_script );

		return $output;
	}

	/**
	 * Count genuinely successful tracking-page lookups.
	 */
	private static function increment_successful_lookup_count(): void {
		$lookups = (int) get_option( 'kitgenix_order_tracking_for_woocommerce_lookup_count', 0 );
		update_option( 'kitgenix_order_tracking_for_woocommerce_lookup_count', $lookups + 1, false );
	}

	/**
	 * Best-effort caller IP for throttling only (never used for auth decisions).
	 */
	private static function get_remote_ip(): string {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- read-only, used only as a rate-limit bucket key (hashed before storage), never echoed or used in a query.
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : '';

		return '' !== $ip ? $ip : 'unknown';
	}

	/** Filterable per-IP failure threshold (default 20 per 15 minutes). */
	private static function max_failed_lookups_per_ip(): int {
		$max = (int) apply_filters( 'kitgenix_order_tracking_for_woocommerce_max_failed_lookups_per_ip', self::MAX_FAILED_LOOKUPS_PER_IP );

		return max( 1, $max );
	}

	/** Filterable per-order failure threshold (default 5). */
	private static function max_failed_lookups_per_order(): int {
		$max = (int) apply_filters( 'kitgenix_order_tracking_for_woocommerce_max_failed_lookups_per_order', self::MAX_FAILED_LOOKUPS_PER_ORDER );

		return max( 1, $max );
	}

	/** True if this IP is currently locked out due to repeated failed lookups. */
	private static function is_ip_rate_limited( string $ip ): bool {
		return (bool) get_transient( 'kitgenix_otw_ip_lockout_' . md5( $ip ) );
	}

	/** Record one failed lookup for this IP; lock it out if the threshold is hit. */
	private static function record_ip_failure( string $ip ): void {
		$key   = 'kitgenix_otw_ip_fails_' . md5( $ip );
		$count = (int) get_transient( $key ) + 1;
		set_transient( $key, $count, self::IP_FAILURE_WINDOW );

		if ( $count >= self::max_failed_lookups_per_ip() ) {
			set_transient( 'kitgenix_otw_ip_lockout_' . md5( $ip ), 1, self::IP_LOCKOUT_DURATION );
		}
	}

	/** True if this specific order is currently locked out due to repeated wrong email/postcode attempts. */
	private static function is_order_locked( int $order_id ): bool {
		return $order_id > 0 && (bool) get_transient( 'kitgenix_otw_order_lockout_' . $order_id );
	}

	/** Record one failed email/postcode attempt against this order; lock it out if the threshold is hit. */
	private static function record_order_failure( int $order_id ): void {
		if ( $order_id <= 0 ) {
			return;
		}

		$key   = 'kitgenix_otw_order_fails_' . $order_id;
		$count = (int) get_transient( $key ) + 1;
		set_transient( $key, $count, self::ORDER_FAILURE_WINDOW );

		if ( $count >= self::max_failed_lookups_per_order() ) {
			set_transient( 'kitgenix_otw_order_lockout_' . $order_id, 1, self::ORDER_LOCKOUT_DURATION );
		}
	}

	/** Clear the failure counter/lockout for this order after a genuinely verified lookup. */
	private static function clear_order_failures( int $order_id ): void {
		if ( $order_id <= 0 ) {
			return;
		}

		delete_transient( 'kitgenix_otw_order_fails_' . $order_id );
		delete_transient( 'kitgenix_otw_order_lockout_' . $order_id );
	}

	/**
	 * Compare postal codes using country-aware normalisation rules.
	 */
	private static function postal_codes_match( string $provided_postcode, string $stored_postcode, string $country_code ): bool {
		$provided_variants = self::normalize_postcode_variants( $provided_postcode, $country_code );
		$stored_variants   = self::normalize_postcode_variants( $stored_postcode, $country_code );

		if ( empty( $provided_variants ) || empty( $stored_variants ) ) {
			return false;
		}

		foreach ( array_keys( $provided_variants ) as $variant ) {
			if ( isset( $stored_variants[ $variant ] ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Build a set of acceptable postal-code variants for comparison.
	 *
	 * @param string $postcode     Raw postal code.
	 * @param string $country_code ISO country code.
	 *
	 * @return array<string,true>
	 */
	private static function normalize_postcode_variants( string $postcode, string $country_code ): array {
		$postcode = strtoupper( trim( $postcode ) );
		if ( '' === $postcode ) {
			return [];
		}

		$country_code = strtoupper( trim( $country_code ) );
		$normalized   = preg_replace( '/[^A-Z0-9]/', '', $postcode );
		if ( ! is_string( $normalized ) || '' === $normalized ) {
			return [];
		}

		$variants = [ $normalized => true ];

		if ( '' !== $country_code && str_starts_with( $normalized, $country_code ) && strlen( $normalized ) > strlen( $country_code ) + 2 ) {
			$variants[ substr( $normalized, strlen( $country_code ) ) ] = true;
		}

		switch ( $country_code ) {
			case 'US':
			case 'PR':
				foreach ( array_keys( $variants ) as $candidate ) {
					if ( preg_match( '/^(\d{5})\d{4}$/', $candidate, $matches ) ) {
						$variants[ $matches[1] ] = true;
					}
				}
				break;

			case 'BR':
				foreach ( array_keys( $variants ) as $candidate ) {
					if ( preg_match( '/^(\d{5})(\d{3})$/', $candidate, $matches ) ) {
						$variants[ $matches[1] . $matches[2] ] = true;
					}
				}
				break;
		}

		return $variants;
	}

	/**
	 * Get the appropriate postal-code label for the current country context.
	 */
	private static function get_postal_code_label( string $country_code ): string {
		$country_code = strtoupper( trim( $country_code ) );

		switch ( $country_code ) {
			case 'US':
			case 'PR':
				return __( 'ZIP code (Optional)', 'kitgenix-order-tracking-for-woocommerce' );

			case 'GB':
			case 'GG':
			case 'IM':
			case 'IE':
			case 'JE':
			case 'AU':
			case 'NZ':
				return __( 'Postcode (Optional)', 'kitgenix-order-tracking-for-woocommerce' );

			case 'CA':
				return __( 'Postal code (Optional)', 'kitgenix-order-tracking-for-woocommerce' );

			default:
				return __( 'Postal code / ZIP code (Optional)', 'kitgenix-order-tracking-for-woocommerce' );
		}
	}

	/**
	 * Get help text for postal-code verification.
	 */
	private static function get_postal_code_help_text( string $country_code ): string {
		$country_code = strtoupper( trim( $country_code ) );

		switch ( $country_code ) {
			case 'US':
			case 'PR':
				return __( 'Enter the billing or shipping ZIP code used on your order to view full addresses, the route map, and item details.', 'kitgenix-order-tracking-for-woocommerce' );

			case 'GB':
			case 'GG':
			case 'IM':
			case 'IE':
			case 'JE':
			case 'AU':
			case 'NZ':
				return __( 'Enter the billing or shipping postcode used on your order to view full addresses, the route map, and item details.', 'kitgenix-order-tracking-for-woocommerce' );

			case 'CA':
				return __( 'Enter the billing or shipping postal code used on your order to view full addresses, the route map, and item details.', 'kitgenix-order-tracking-for-woocommerce' );

			default:
				return __( 'Enter the billing or shipping postal code or ZIP code used on your order to view full addresses, the route map, and item details.', 'kitgenix-order-tracking-for-woocommerce' );
		}
	}

	/**
	 * Get the WooCommerce store location (address + country code).
	 *
	 * @return array{address:string,country:string}
	 */
	private static function get_store_location(): array {
		if ( ! function_exists( 'wc_get_base_location' ) ) {
			return [
				'address' => '',
				'country' => '',
			];
		}

		$base_location = wc_get_base_location();
		$country_code  = isset( $base_location['country'] ) ? strtoupper( (string) $base_location['country'] ) : '';

		$address_1 = get_option( 'woocommerce_store_address', '' );
		$address_2 = get_option( 'woocommerce_store_address_2', '' );
		$city      = get_option( 'woocommerce_store_city', '' );
		$postcode  = get_option( 'woocommerce_store_postcode', '' );
		$state     = $base_location['state'] ?? '';

		$country_name = '';
		if ( function_exists( 'WC' ) && WC()->countries instanceof \WC_Countries ) {
			$countries = WC()->countries->get_countries();
			if ( isset( $countries[ $country_code ] ) ) {
				$country_name = $countries[ $country_code ];
			}
		}
		if ( '' === $country_name ) {
			$country_name = $country_code;
		}

		$parts = array_filter(
			[
				$address_1,
				$address_2,
				$city,
				$state,
				$postcode,
				$country_name,
			]
		);

		return [
			'address' => implode( ', ', $parts ),
			'country' => $country_code,
		];
	}

	/**
	 * Get the order shipping (or billing) location (address + country code).
	 *
	 * @param WC_Order $order Order object.
	 *
	 * @return array{address:string,country:string}
	 */
	private static function get_order_location( WC_Order $order ): array {
		$address_1 = $order->get_shipping_address_1();
		$address_2 = $order->get_shipping_address_2();
		$city      = $order->get_shipping_city();
		$state     = $order->get_shipping_state();
		$postcode  = $order->get_shipping_postcode();
		$country   = $order->get_shipping_country();

		// Fallback to billing if shipping is mostly empty.
		if ( ! $address_1 && ! $city && ! $postcode ) {
			$address_1 = $order->get_billing_address_1();
			$address_2 = $order->get_billing_address_2();
			$city      = $order->get_billing_city();
			$state     = $order->get_billing_state();
			$postcode  = $order->get_billing_postcode();
			$country   = $order->get_billing_country();
		}

		$country_code = strtoupper( (string) $country );

		$country_name = '';
		if ( function_exists( 'WC' ) && WC()->countries instanceof \WC_Countries ) {
			$countries = WC()->countries->get_countries();
			if ( isset( $countries[ $country_code ] ) ) {
				$country_name = $countries[ $country_code ];
			}
		}
		if ( '' === $country_name ) {
			$country_name = $country_code;
		}

		$parts = array_filter(
			[
				$address_1,
				$address_2,
				$city,
				$state,
				$postcode,
				$country_name,
			]
		);

		return [
			'address' => implode( ', ', $parts ),
			'country' => $country_code,
		];
	}
}
