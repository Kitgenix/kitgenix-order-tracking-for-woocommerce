<?php
declare(strict_types=1);

namespace Kitgenix\OrderTracking\Admin;

defined( 'ABSPATH' ) || exit;

use WC_Order;
use Kitgenix\OrderTracking\Orders\Order_Tracking;
use Kitgenix\OrderTracking\Support\Carriers;

final class Order_Meta_Box {

	/**
	 * Bootstrap admin hooks.
	 */
	public static function init(): void {
		// HPOS-aware meta box.
		add_action( 'add_meta_boxes', [ self::class, 'add_meta_box' ] );

		// HPOS-safe save hook for orders.
		add_action( 'woocommerce_process_shop_order_meta', [ self::class, 'save_meta_box' ], 10, 2 );

		add_action( 'admin_enqueue_scripts', [ self::class, 'enqueue_assets' ] );
		add_action( 'admin_footer', [ self::class, 'render_modal_template' ] );

		// AJAX endpoint for loading order items into the modal.
		add_action( 'wp_ajax_kitgenix_order_tracking_for_woocommerce_get_order_items', [ self::class, 'ajax_get_order_items' ] );
	}

	/**
	 * Register the Kitgenix Order Tracking meta box.
	 *
	 * This uses the HPOS-aware screen ID when custom order tables are enabled,
	 * otherwise falls back to the classic 'shop_order' post type screen.
	 */
	public static function add_meta_box(): void {
		$screen_id = 'shop_order';

		if ( function_exists( 'wc_get_container' ) ) {
			try {
				$container  = \wc_get_container();
				$controller = $container->get(
					\Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController::class
				);

				if ( $controller && $controller->custom_orders_table_usage_is_enabled() ) {
					if ( function_exists( 'wc_get_page_screen_id' ) ) {
						$screen_id = \wc_get_page_screen_id( 'shop-order' );
					}
				}
			} catch ( \Throwable $e ) {
				// If anything goes wrong, fall back to legacy post type screen.
			}
		}

		add_meta_box(
			'kitgenix-order-tracking-for-woocommerce',
			__( 'Kitgenix Order Tracking', 'kitgenix-order-tracking-for-woocommerce' ),
			[ self::class, 'render_meta_box' ],
			$screen_id,
			'side',
			'default'
		);
	}

	/**
	 * Enqueue admin assets on both classic and HPOS order screens.
	 *
	 * @param string $hook Current admin hook.
	 *
	 * @return void
	 */
	public static function enqueue_assets( string $hook ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
		$screen = get_current_screen();
		if ( ! $screen ) {
			return;
		}

		$allowed_screens = [ 'shop_order' ];

		if ( function_exists( 'wc_get_page_screen_id' ) ) {
			$allowed_screens[] = \wc_get_page_screen_id( 'shop-order' );
		}

		if ( ! in_array( $screen->id, $allowed_screens, true ) ) {
			return;
		}

		$admin_js_path = trailingslashit( KITGENIX_ORDER_TRACKING_FOR_WOOCOMMERCE_PLUGIN_DIR ) . 'assets/js/admin-order-tracking.js';
		$admin_js_ver  = file_exists( $admin_js_path ) ? (string) filemtime( $admin_js_path ) : '1.2.0';

		// Enqueue admin CSS for modal and meta box UI.
		$admin_css_path = trailingslashit( KITGENIX_ORDER_TRACKING_FOR_WOOCOMMERCE_PLUGIN_DIR ) . 'assets/css/admin.css';
		$admin_css_ver  = file_exists( $admin_css_path ) ? (string) filemtime( $admin_css_path ) : ( defined( 'KITGENIX_ORDER_TRACKING_FOR_WOOCOMMERCE_VERSION' ) ? (string) KITGENIX_ORDER_TRACKING_FOR_WOOCOMMERCE_VERSION : '1.0.5' );

		wp_enqueue_style( 'kitgenix-admin-ui' );

		wp_enqueue_style(
			'kitgenix-order-tracking-admin',
			KITGENIX_ORDER_TRACKING_FOR_WOOCOMMERCE_PLUGIN_URL . 'assets/css/admin.css',
			[ 'kitgenix-admin-ui' ],
			$admin_css_ver
		);

		wp_enqueue_script(
			'kitgenix-order-tracking-admin',
			KITGENIX_ORDER_TRACKING_FOR_WOOCOMMERCE_PLUGIN_URL . 'assets/js/admin-order-tracking.js',
			[ 'jquery' ],
			$admin_js_ver,
			true
		);

		// Try to detect current order ID (classic + HPOS URLs).
		// Note: This derives the ID from the URL solely to localize the script
		// and does not perform any privileged action, so nonce verification is
		// not strictly required here.
		$order_id = 0;
		// Classic editor: post.php?post=123&action=edit.
		$get_post_id = filter_input( INPUT_GET, 'post', FILTER_VALIDATE_INT );
		if ( $get_post_id ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$order_id = absint( $get_post_id );
		} else {
			// HPOS: admin.php?page=wc-orders&action=edit&id=123.
			$get_hpos_id = filter_input( INPUT_GET, 'id', FILTER_VALIDATE_INT );
			if ( $get_hpos_id ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				$order_id = absint( $get_hpos_id );
			}
		}

		wp_localize_script(
			'kitgenix-order-tracking-admin',
			'KitgenixOrderTracking',
			[
				'nonce'    => wp_create_nonce( 'kitgenix_order_tracking' ),
				'ajax_url' => admin_url( 'admin-ajax.php' ),
				'order_id' => $order_id,
				'i18n'     => [
					'add_shipment'            => __( 'Add tracking', 'kitgenix-order-tracking-for-woocommerce' ),
					'remove'                  => __( 'Remove shipment', 'kitgenix-order-tracking-for-woocommerce' ),
					'missing_tracking_number' => __( 'Please enter a tracking number.', 'kitgenix-order-tracking-for-woocommerce' ),
					'no_shipments'            => __( 'No tracking information added yet.', 'kitgenix-order-tracking-for-woocommerce' ),
					'carrier_label'           => __( 'Carrier:', 'kitgenix-order-tracking-for-woocommerce' ),
					'tracking_label'          => __( 'Tracking number:', 'kitgenix-order-tracking-for-woocommerce' ),
					'date_shipped_label'      => __( 'Date shipped:', 'kitgenix-order-tracking-for-woocommerce' ),
					'unknown_carrier'         => __( 'Unknown', 'kitgenix-order-tracking-for-woocommerce' ),
					'items_label'             => __( 'Items in this shipment:', 'kitgenix-order-tracking-for-woocommerce' ),
					'items_section_title'     => __( 'Select which items (and quantities) are included in this shipment.', 'kitgenix-order-tracking-for-woocommerce' ),
				],
			]
		);
	}

	/**
	 * Render the sidebar meta box.
	 *
	 * In HPOS, WooCommerce passes an order object; in legacy mode, a WP_Post.
	 *
	 * @param mixed $post_or_order \WP_Post|WC_Order|mixed
	 */
	public static function render_meta_box( $post_or_order ): void {
		$order = null;

		if ( $post_or_order instanceof WC_Order ) {
			$order = $post_or_order;
		} elseif ( $post_or_order instanceof \WP_Post ) {
			$order = wc_get_order( $post_or_order->ID );
		} elseif ( is_numeric( $post_or_order ) ) {
			$order = wc_get_order( (int) $post_or_order );
		}

		if ( ! $order instanceof WC_Order ) {
			echo esc_html__( 'Order not found.', 'kitgenix-order-tracking-for-woocommerce' );
			return;
		}

		$shipments = Order_Tracking::get_shipments( $order );

		wp_nonce_field( 'kitgenix_order_tracking_save', 'kitgenix_order_tracking_nonce' );
		?>
		<div id="kitgenix-order-tracking-for-woocommerce-wrap" class="kitgenix-metabox">
			<p>
				<?php esc_html_e( 'Add one or more tracking numbers for this order. These will appear in customer emails and on the tracking page.', 'kitgenix-order-tracking-for-woocommerce' ); ?>
			</p>

			<div class="kitgenix-shipments-list">
				<?php
				if ( ! empty( $shipments ) ) :
					foreach ( $shipments as $index => $shipment ) :
						self::render_shipment_row( (int) $index, $shipment );
					endforeach;
				else :
					?>
					<p class="kitgenix-no-shipments">
						<?php esc_html_e( 'No tracking information added yet.', 'kitgenix-order-tracking-for-woocommerce' ); ?>
					</p>
					<?php
				endif;
				?>
			</div>

			<p class="kitgenix-order-tracking-for-woocommerce-actions">
				<button type="button" class="button button-secondary kitgenix-add-shipment-modal">
					<?php esc_html_e( 'Add tracking', 'kitgenix-order-tracking-for-woocommerce' ); ?>
				</button>

				<button type="button" class="button button-primary kitgenix-save-shipments">
					<?php esc_html_e( 'Save tracking', 'kitgenix-order-tracking-for-woocommerce' ); ?>
				</button>
			</p>
		</div>
		<?php
	}

	/**
	 * Render a single shipment row (existing data).
	 *
	 * @param int                 $index    Index.
	 * @param array<string,mixed> $shipment Shipment data.
	 */
	private static function render_shipment_row( int $index, array $shipment ): void {
		$id              = isset( $shipment['id'] ) ? (string) $shipment['id'] : '';
		$carrier         = isset( $shipment['carrier'] ) ? (string) $shipment['carrier'] : '';
		$tracking_number = isset( $shipment['tracking_number'] ) ? (string) $shipment['tracking_number'] : '';
		$date_shipped    = isset( $shipment['created_at'] ) ? (string) $shipment['created_at'] : ( $shipment['date_shipped'] ?? '' );
		$items           = isset( $shipment['items'] ) && is_array( $shipment['items'] ) ? $shipment['items'] : [];

		$carriers = Carriers::registry();
		$label    = $carrier && isset( $carriers[ $carrier ] ) ? $carriers[ $carrier ]['label'] : $carrier;

		$items_json = '';
		if ( ! empty( $items ) ) {
			$items_json = wp_json_encode( $items );
		}
		?>
		<div class="kitgenix-shipment-row">
			<input type="hidden"
				   name="kitgenix_shipments[<?php echo esc_attr( (string) $index ); ?>][id]"
				   value="<?php echo esc_attr( $id ); ?>" />

			<input type="hidden"
				   name="kitgenix_shipments[<?php echo esc_attr( (string) $index ); ?>][carrier]"
				   value="<?php echo esc_attr( $carrier ); ?>" />

			<input type="hidden"
				   name="kitgenix_shipments[<?php echo esc_attr( (string) $index ); ?>][tracking_number]"
				   value="<?php echo esc_attr( $tracking_number ); ?>" />

			<input type="hidden"
				   name="kitgenix_shipments[<?php echo esc_attr( (string) $index ); ?>][date_shipped]"
				   value="<?php echo esc_attr( $date_shipped ); ?>" />

			<?php if ( ! empty( $items ) ) : ?>
				<?php foreach ( $items as $it ) : ?>
					<?php
					$item_id = isset( $it['item_id'] ) ? (int) $it['item_id'] : 0;
					$qty     = isset( $it['qty'] ) ? (float) $it['qty'] : 0.0;
					if ( $item_id && $qty > 0 ) :
					?>
						<input type="hidden"
						       name="kitgenix_shipments[<?php echo esc_attr( (string) $index ); ?>][items][<?php echo esc_attr( (string) $item_id ); ?>][qty]"
						       value="<?php echo esc_attr( (string) $qty ); ?>" />
					<?php endif; ?>
				<?php endforeach; ?>
			<?php endif; ?>

			<p>
				<strong><?php esc_html_e( 'Carrier:', 'kitgenix-order-tracking-for-woocommerce' ); ?></strong>
				<?php echo esc_html( $label ?: __( 'Unknown', 'kitgenix-order-tracking-for-woocommerce' ) ); ?><br>
				<strong><?php esc_html_e( 'Tracking number:', 'kitgenix-order-tracking-for-woocommerce' ); ?></strong>
				<?php echo esc_html( $tracking_number ); ?>
				<?php if ( $date_shipped ) : ?>
					<br><strong><?php esc_html_e( 'Date shipped:', 'kitgenix-order-tracking-for-woocommerce' ); ?></strong>
					<?php echo esc_html( $date_shipped ); ?>
				<?php endif; ?>
			</p>

			<?php if ( ! empty( $items ) ) : ?>
				<p class="kitgenix-shipment-items">
					<strong><?php esc_html_e( 'Items in this shipment:', 'kitgenix-order-tracking-for-woocommerce' ); ?></strong><br>
					<?php
					foreach ( $items as $i => $item ) {
						$item_id = isset( $item['item_id'] ) ? (int) $item['item_id'] : 0;
						$qty     = isset( $item['qty'] ) ? (float) $item['qty'] : 0.0;
						if ( $item_id && $qty > 0 ) {
							echo esc_html( '#' . $item_id . ' × ' . $qty );
							if ( $i < ( count( $items ) - 1 ) ) {
								echo '<br>';
							}
						}
					}
					?>
				</p>
			<?php endif; ?>

			<p>
				<button type="button" class="button-link-delete kitgenix-remove-shipment">
					<?php esc_html_e( 'Remove shipment', 'kitgenix-order-tracking-for-woocommerce' ); ?>
				</button>
			</p>

			<hr/>
		</div>
		<?php
	}

	/**
	 * Save tracking meta when an order is saved (HPOS & legacy).
	 *
	 * @param int        $order_id      Order ID.
	 * @param mixed|null $post_or_order Not used directly; kept for signature compatibility.
	 */
	public static function save_meta_box( int $order_id, $post_or_order ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
		if (
			! isset( $_POST['kitgenix_order_tracking_nonce'] ) ||
			! wp_verify_nonce(
				sanitize_text_field( wp_unslash( $_POST['kitgenix_order_tracking_nonce'] ?? '' ) ),
				'kitgenix_order_tracking_save'
			)
		) {
			return;
		}

		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		// In classic mode we get a WP_Post here, in HPOS it may be something else.
		if ( $post_or_order instanceof \WP_Post && 'shop_order' !== $post_or_order->post_type ) {
			return;
		}

		if ( ! current_user_can( 'edit_shop_order', $order_id ) ) {
			return;
		}

		$order = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order ) {
			return;
		}

		// Retrieve shipments array from POST (nonce already verified above) and sanitize later per-field.
		$raw_shipments = [];
		if ( isset( $_POST['kitgenix_shipments'] ) && is_array( $_POST['kitgenix_shipments'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			// Sanitize the incoming array recursively at the top-level to satisfy WPCS,
			// then perform stricter per-field whitelisting below.
			$raw_shipments = wc_clean( wp_unslash( $_POST['kitgenix_shipments'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		}

		$shipments = [];

		foreach ( $raw_shipments as $shipment ) {
			if ( empty( $shipment['tracking_number'] ) ) {
				continue;
			}

			// Decode/normalise items (JSON string, array, or mapping) and validate.
			$items = [];
			if ( isset( $shipment['items'] ) ) {
				$raw_items = $shipment['items'];
				if ( is_string( $raw_items ) ) {
					$decoded = json_decode( wp_unslash( $raw_items ), true );
					if ( is_array( $decoded ) ) {
						$raw_items = $decoded;
					}
				}
				if ( is_array( $raw_items ) ) {
					$looks_like_array_of_objects = ! empty( $raw_items ) && isset( $raw_items[0] ) && is_array( $raw_items[0] );
					if ( $looks_like_array_of_objects ) {
						foreach ( $raw_items as $item ) {
							$item_id = isset( $item['item_id'] ) ? (int) $item['item_id'] : 0;
							$qty     = isset( $item['qty'] ) ? (float) $item['qty'] : 0.0;
							if ( $item_id > 0 && $qty > 0 ) {
								$items[] = [ 'item_id' => $item_id, 'qty' => $qty ];
							}
						}
					} else {
						foreach ( $raw_items as $k => $v ) {
							$item_id = (int) $k;
							if ( is_array( $v ) ) {
								$qty = isset( $v['qty'] ) ? (float) $v['qty'] : 0.0;
							} else {
								$qty = (float) $v;
							}
							if ( $item_id > 0 && $qty > 0 ) {
								$items[] = [ 'item_id' => $item_id, 'qty' => $qty ];
							}
						}
					}
				}
			}

			// Whitelist carrier if known, otherwise set to empty or 'other'.
			$carrier_raw = isset( $shipment[ 'carrier' ] ) ? sanitize_text_field( (string) $shipment['carrier'] ) : '';
			$allowed_carriers = Carriers::keys_all();
			$carrier = in_array( $carrier_raw, $allowed_carriers, true ) || 'other' === $carrier_raw ? $carrier_raw : '';

			$shipments[] = [
				'id'              => isset( $shipment['id'] ) ? sanitize_text_field( (string) $shipment['id'] ) : '',
				'carrier'         => $carrier,
				'tracking_number' => sanitize_text_field( (string) $shipment['tracking_number'] ),
				'date_shipped'    => isset( $shipment['date_shipped'] )
					? sanitize_text_field( (string) $shipment['date_shipped'] )
					: '',
				'items'           => $items,
			];
		}

		// Optional: debug what was posted vs normalized. Enable via filter returning true.
		if ( apply_filters( 'kitgenix_order_tracking_for_woocommerce_debug_save', false, $order_id ) ) {
			$debug_raw        = wp_json_encode( $raw_shipments );
			$debug_normalized = wp_json_encode( $shipments );
			$order->add_order_note( "Kitgenix OTW debug:\nRaw shipments (json):\n$debug_raw\nNormalized (json):\n$debug_normalized" );
		}

		Order_Tracking::save_shipments( $order, $shipments );

		// Store the last chosen action (for potential future use / debugging).
		$status_action = '';
		if ( isset( $_POST['kitgenix_order_status_action'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$status_action = sanitize_key( (string) wp_unslash( $_POST['kitgenix_order_status_action'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		}

		$allowed_actions = [ 'no_change', 'partial', 'completed' ];
		if ( in_array( $status_action, $allowed_actions, true ) ) {
			$order->update_meta_data( 'kitgenix_order_status_action', $status_action );
		}

		$order->save();
	}

	/**
	 * Render the modal HTML in admin footer (only on order screens).
	 */
	public static function render_modal_template(): void {
		$screen = get_current_screen();
		if ( ! $screen ) {
			return;
		}

		$allowed_screens = [ 'shop_order' ];
		if ( function_exists( 'wc_get_page_screen_id' ) ) {
			$allowed_screens[] = \wc_get_page_screen_id( 'shop-order' );
		}

		if ( ! in_array( $screen->id, $allowed_screens, true ) ) {
			return;
		}

		$carriers = Carriers::selectable();
		?>
		<div id="kitgenix-order-tracking-for-woocommerce-modal" class="kitgenix-modal kitgenix-modal--wide" aria-hidden="true" role="dialog" aria-modal="true">
			<div class="kitgenix-modal__backdrop" data-kitgenix-modal-close="1"></div>
			<div class="kitgenix-modal__dialog" role="document">
				<div class="kitgenix-modal__header">
					<h2 class="kitgenix-modal__title"><?php esc_html_e( 'Add tracking', 'kitgenix-order-tracking-for-woocommerce' ); ?></h2>
					<button type="button" class="button kitgenix-modal__close" data-kitgenix-modal-close="1"><?php esc_html_e( 'Close', 'kitgenix-order-tracking-for-woocommerce' ); ?></button>
				</div>

				<div class="kitgenix-modal__body">
					<p class="description">
						<?php esc_html_e( 'Add tracking information for this order. This will be shown in customer emails and on the tracking page.', 'kitgenix-order-tracking-for-woocommerce' ); ?>
					</p>

					<div class="kitgenix-order-tracking-for-woocommerce-items" data-loaded="0">
						<!-- Order items for this shipment will be injected here by JS. -->
					</div>

					<p>
						<label>
							<span class="kitgenix-label"><?php esc_html_e( 'Tracking number', 'kitgenix-order-tracking-for-woocommerce' ); ?></span>
							<input type="text" class="widefat kitgenix-order-tracking-for-woocommerce-field-number" placeholder="<?php esc_attr_e( 'e.g. 12121212', 'kitgenix-order-tracking-for-woocommerce' ); ?>">
						</label>
					</p>

					<p>
						<label>
							<span class="kitgenix-label"><?php esc_html_e( 'Carrier (optional)', 'kitgenix-order-tracking-for-woocommerce' ); ?></span>
							<select class="widefat kitgenix-order-tracking-for-woocommerce-field-carrier">
								<option value=""><?php esc_html_e( 'Automatic Matching', 'kitgenix-order-tracking-for-woocommerce' ); ?></option>
								<?php foreach ( $carriers as $key => $carrier ) : ?>
									<option value="<?php echo esc_attr( $key ); ?>">
										<?php echo esc_html( $carrier['label'] ); ?>
									</option>
								<?php endforeach; ?>
							</select>
						</label>
					</p>

					<p>
						<label>
							<span class="kitgenix-label"><?php esc_html_e( 'Date shipped (optional)', 'kitgenix-order-tracking-for-woocommerce' ); ?></span>
							<input type="date" class="kitgenix-order-tracking-for-woocommerce-field-date" value="<?php echo esc_attr( current_time( 'Y-m-d' ) ); ?>">
						</label>
					</p>

					<fieldset class="kitgenix-order-tracking-for-woocommerce-field-status">
						<legend class="kitgenix-label"><?php esc_html_e( 'Update order status (optional)', 'kitgenix-order-tracking-for-woocommerce' ); ?></legend>
						<label>
							<input type="radio" name="kitgenix_order_tracking_for_woocommerce_status" value="no_change" checked>
							<?php esc_html_e( "Don't change", 'kitgenix-order-tracking-for-woocommerce' ); ?>
						</label><br>
						<label>
							<input type="radio" name="kitgenix_order_tracking_for_woocommerce_status" value="partial">
							<?php esc_html_e( 'Partially shipped (custom workflow)', 'kitgenix-order-tracking-for-woocommerce' ); ?>
						</label><br>
						<label>
							<input type="radio" name="kitgenix_order_tracking_for_woocommerce_status" value="completed">
							<?php esc_html_e( 'Completed', 'kitgenix-order-tracking-for-woocommerce' ); ?>
						</label>
					</fieldset>
				</div>

					<div class="kitgenix-modal__actions">
						<button type="button" class="button" data-kitgenix-modal-close="1">
						<?php esc_html_e( 'Cancel', 'kitgenix-order-tracking-for-woocommerce' ); ?>
					</button>
						<button type="button" class="button button-primary" data-kitgenix-modal-confirm="1">
						<?php esc_html_e( 'Confirm', 'kitgenix-order-tracking-for-woocommerce' ); ?>
					</button>
				</div>
			</div>
		</div>

		<?php // Styles moved to enqueued admin stylesheet: assets/css/admin.css ?>
		<?php
	}

	/**
	 * AJAX: return order items for the current order so the modal can render them.
	 *
	 * @return void
	 */
	public static function ajax_get_order_items(): void {
		if (
			! isset( $_POST['nonce'] ) ||
			! wp_verify_nonce(
				sanitize_text_field( wp_unslash( $_POST['nonce'] ?? '' ) ),
				'kitgenix_order_tracking'
			)
		) {
			wp_send_json_error(
				[
					'message' => 'Invalid nonce.',
				]
			);
		}

		$order_id = isset( $_POST['order_id'] ) ? absint( wp_unslash( $_POST['order_id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing

		if ( ! $order_id ) {
			wp_send_json_error(
				[
					'message' => 'Missing order ID.',
				]
			);
		}

		if ( ! current_user_can( 'edit_shop_order', $order_id ) ) {
			wp_send_json_error(
				[
					'message' => 'Insufficient permissions.',
				]
			);
		}

		$order = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order ) {
			wp_send_json_error(
				[
					'message' => 'Order not found.',
				]
			);
		}

		// Calculate already shipped quantities per item to limit remaining selectable qty.
		$existing_shipments = Order_Tracking::get_shipments( $order );
		$already_shipped    = [];
		foreach ( $existing_shipments as $shipment ) {
			if ( empty( $shipment['items'] ) || ! is_array( $shipment['items'] ) ) {
				continue;
			}
			foreach ( $shipment['items'] as $s_item ) {
				$sid = isset( $s_item['item_id'] ) ? (int) $s_item['item_id'] : 0;
				$sq  = isset( $s_item['qty'] ) ? (float) $s_item['qty'] : 0.0;
				if ( $sid && $sq > 0 ) {
					$already_shipped[ $sid ] = ( $already_shipped[ $sid ] ?? 0 ) + $sq;
				}
			}
		}

		$items = [];

		foreach ( $order->get_items() as $item_id => $item ) {
			$product        = $item->get_product();
			$ordered_qty    = (float) $item->get_quantity();
			$shipped_qty    = isset( $already_shipped[ (int) $item_id ] ) ? (float) $already_shipped[ (int) $item_id ] : 0.0;
			$max_qty        = max( 0.0, $ordered_qty - $shipped_qty );
			$product_name   = $item->get_name();
			$sku            = $product ? $product->get_sku() : '';
			$thumbnail_html = $product ? $product->get_image( 'thumbnail', [ 'style' => 'width:40px;height:40px;object-fit:cover;border-radius:3px;' ] ) : '';

			$items[] = [
				'item_id'    => (int) $item_id,
				'name'       => $product_name,
				'sku'        => $sku,
				'qty'        => (float) $max_qty,
				'image_html' => $thumbnail_html,
			];
		}

		wp_send_json_success(
			[
				'items' => $items,
			]
		);
	}
}
