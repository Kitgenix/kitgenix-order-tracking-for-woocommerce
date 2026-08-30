<?php
declare(strict_types=1);

namespace Kitgenix\OrderTracking\Admin;

defined( 'ABSPATH' ) || exit;

use WC_Order;
use Kitgenix\OrderTracking\Orders\Order_Tracking;
use Kitgenix\OrderTracking\Orders\Shipment;
use Kitgenix\OrderTracking\Support\Carriers;
use Kitgenix\OrderTracking\Support\Event_Log;

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
				Event_Log::record(
					'hpos-lookup',
					'skipped',
					Event_Log::get_category_and_note( 'hpos_lookup_failed' )['note'],
					'hpos_lookup_failed'
				);
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

		wp_enqueue_style( 'kitgenix-order-tracking-for-woocommerce-admin-ui' );

		wp_enqueue_style(
			'kitgenix-order-tracking-admin',
			KITGENIX_ORDER_TRACKING_FOR_WOOCOMMERCE_PLUGIN_URL . 'assets/css/admin.css',
			[ 'kitgenix-order-tracking-for-woocommerce-admin-ui' ],
			$admin_css_ver
		);

		$components_js_path = trailingslashit( KITGENIX_ORDER_TRACKING_FOR_WOOCOMMERCE_PLUGIN_DIR ) . 'assets/js/kitgenix-admin-components.js';
		$components_js_ver  = file_exists( $components_js_path ) ? (string) filemtime( $components_js_path ) : $admin_js_ver;
		wp_enqueue_script(
			'kitgenix-admin-components',
			KITGENIX_ORDER_TRACKING_FOR_WOOCOMMERCE_PLUGIN_URL . 'assets/js/kitgenix-admin-components.js',
			[],
			$components_js_ver,
			true
		);

		wp_enqueue_script(
			'kitgenix-order-tracking-admin',
			KITGENIX_ORDER_TRACKING_FOR_WOOCOMMERCE_PLUGIN_URL . 'assets/js/admin-order-tracking.js',
			[ 'jquery', 'kitgenix-admin-components' ],
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
					'loading_items'           => __( 'Loading order items…', 'kitgenix-order-tracking-for-woocommerce' ),
					'delivery_estimate_label' => __( 'Estimated delivery:', 'kitgenix-order-tracking-for-woocommerce' ),
					'status_label'            => __( 'Status:', 'kitgenix-order-tracking-for-woocommerce' ),
					'note_label'              => __( 'Internal note:', 'kitgenix-order-tracking-for-woocommerce' ),
					'edit_shipment'           => __( 'Edit shipment', 'kitgenix-order-tracking-for-woocommerce' ),
					'edit_title'              => __( 'Edit tracking', 'kitgenix-order-tracking-for-woocommerce' ),
					'add_title'               => __( 'Add tracking', 'kitgenix-order-tracking-for-woocommerce' ),
					'save'                    => __( 'Save changes', 'kitgenix-order-tracking-for-woocommerce' ),
					'confirm'                 => __( 'Confirm', 'kitgenix-order-tracking-for-woocommerce' ),
				],
				'statuses' => Shipment::known_statuses(),
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
		$id                = isset( $shipment['id'] ) ? (string) $shipment['id'] : '';
		$carrier           = isset( $shipment['carrier'] ) ? (string) $shipment['carrier'] : '';
		$tracking_number   = isset( $shipment['tracking_number'] ) ? (string) $shipment['tracking_number'] : '';
		$date_shipped      = isset( $shipment['created_at'] ) ? (string) $shipment['created_at'] : ( $shipment['date_shipped'] ?? '' );
		$delivery_estimate = isset( $shipment['delivery_estimate'] ) ? (string) $shipment['delivery_estimate'] : '';
		$status            = isset( $shipment['status'] ) && '' !== (string) $shipment['status'] ? (string) $shipment['status'] : Shipment::STATUS_SHIPPED;
		$note              = isset( $shipment['note'] ) ? (string) $shipment['note'] : '';
		$items             = isset( $shipment['items'] ) && is_array( $shipment['items'] ) ? $shipment['items'] : [];
		$carrier_detected  = isset( $shipment['carrier_detected'] ) ? (string) $shipment['carrier_detected'] : '';

		$carriers = Carriers::registry();
		$label    = $carrier && isset( $carriers[ $carrier ] ) ? $carriers[ $carrier ]['label'] : $carrier;
		$statuses = Shipment::known_statuses();
		$status_label = $statuses[ $status ] ?? ucfirst( str_replace( '_', ' ', $status ) );

		$items_json = ! empty( $items ) ? (string) wp_json_encode( $items ) : '[]';
		?>
		<div class="kitgenix-shipment-row" data-index="<?php echo esc_attr( (string) $index ); ?>">
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

			<input type="hidden"
				   name="kitgenix_shipments[<?php echo esc_attr( (string) $index ); ?>][delivery_estimate]"
				   value="<?php echo esc_attr( $delivery_estimate ); ?>" />

			<input type="hidden"
				   name="kitgenix_shipments[<?php echo esc_attr( (string) $index ); ?>][status]"
				   value="<?php echo esc_attr( $status ); ?>" />

			<input type="hidden"
				   name="kitgenix_shipments[<?php echo esc_attr( (string) $index ); ?>][note]"
				   value="<?php echo esc_attr( $note ); ?>" />

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
				<?php echo esc_html( $label ?: __( 'Unknown', 'kitgenix-order-tracking-for-woocommerce' ) ); ?>
				<?php if ( '' === $carrier && '' !== $carrier_detected ) : ?>
					<span class="kitgenix-badge-detected">
						<?php
						/* translators: %s: detected carrier label */
						printf( esc_html__( 'Detected: %s (unconfirmed)', 'kitgenix-order-tracking-for-woocommerce' ), esc_html( Carriers::get_label( $carrier_detected ) ) );
						?>
					</span>
				<?php endif; ?>
				<br>
				<strong><?php esc_html_e( 'Tracking number:', 'kitgenix-order-tracking-for-woocommerce' ); ?></strong>
				<?php echo esc_html( $tracking_number ); ?><br>
				<strong><?php esc_html_e( 'Status:', 'kitgenix-order-tracking-for-woocommerce' ); ?></strong>
				<?php echo esc_html( $status_label ); ?>
				<?php if ( $date_shipped ) : ?>
					<br><strong><?php esc_html_e( 'Date shipped:', 'kitgenix-order-tracking-for-woocommerce' ); ?></strong>
					<?php echo esc_html( $date_shipped ); ?>
				<?php endif; ?>
				<?php if ( $delivery_estimate ) : ?>
					<br><strong><?php esc_html_e( 'Estimated delivery:', 'kitgenix-order-tracking-for-woocommerce' ); ?></strong>
					<?php echo esc_html( $delivery_estimate ); ?>
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

			<?php if ( '' !== $note ) : ?>
				<p class="kitgenix-shipment-note">
					<strong><?php esc_html_e( 'Internal note (staff only):', 'kitgenix-order-tracking-for-woocommerce' ); ?></strong><br>
					<?php echo esc_html( $note ); ?>
				</p>
			<?php endif; ?>

			<p>
				<button type="button"
						class="button-link kitgenix-edit-shipment"
						data-id="<?php echo esc_attr( $id ); ?>"
						data-carrier="<?php echo esc_attr( $carrier ); ?>"
						data-tracking-number="<?php echo esc_attr( $tracking_number ); ?>"
						data-date-shipped="<?php echo esc_attr( $date_shipped ); ?>"
						data-delivery-estimate="<?php echo esc_attr( $delivery_estimate ); ?>"
						data-status="<?php echo esc_attr( $status ); ?>"
						data-note="<?php echo esc_attr( $note ); ?>"
						data-items="<?php echo esc_attr( $items_json ); ?>">
					<?php esc_html_e( 'Edit shipment', 'kitgenix-order-tracking-for-woocommerce' ); ?>
				</button>
				&nbsp;
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

		// Retrieve shipments array from POST (nonce already verified above). Only
		// wp_unslash() here – every field is properly sanitised per-type by
		// Shipment::from_input() below (which Order_Tracking::save_shipments()
		// calls), so re-running sanitize_text_field() over the whole nested
		// array first would strip newlines out of the internal note textarea.
		$raw_shipments = [];
		if ( isset( $_POST['kitgenix_shipments'] ) && is_array( $_POST['kitgenix_shipments'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$raw_shipments = wp_unslash( $_POST['kitgenix_shipments'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		}

		$shipments = [];

		foreach ( $raw_shipments as $shipment ) {
			if ( ! is_array( $shipment ) || empty( $shipment['tracking_number'] ) ) {
				continue;
			}

			$shipments[] = $shipment;
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
		<div id="kitgenix-order-tracking-for-woocommerce-modal" class="kitgenix-modal" hidden role="dialog" aria-modal="true" aria-labelledby="kitgenix-order-tracking-for-woocommerce-modal-title">
			<div class="kitgenix-modal-backdrop" data-kitgenix-modal-close="1"></div>
			<div class="kitgenix-modal-dialog kitgenix-modal-dialog-lg" role="document">
				<div class="kitgenix-modal-header">
					<h2 class="kitgenix-modal-title" id="kitgenix-order-tracking-for-woocommerce-modal-title"><?php esc_html_e( 'Add tracking', 'kitgenix-order-tracking-for-woocommerce' ); ?></h2>
					<button type="button" class="kitgenix-modal-close" data-kitgenix-modal-close="1" aria-label="<?php esc_attr_e( 'Close', 'kitgenix-order-tracking-for-woocommerce' ); ?>">&times;</button>
				</div>

				<div class="kitgenix-modal-body">
					<p class="description">
						<?php esc_html_e( 'Add tracking information for this order. This will be shown in customer emails and on the tracking page.', 'kitgenix-order-tracking-for-woocommerce' ); ?>
					</p>

					<input type="hidden" class="kitgenix-order-tracking-for-woocommerce-field-editing-id" value="" />

					<div class="kitgenix-order-tracking-for-woocommerce-items" data-loaded="0">
						<!-- Order items for this shipment will be injected here by JS. -->
					</div>

					<div class="kitgenix-settings-group">
						<div class="kitgenix-setting-row kitgenix-setting-row-stacked">
							<div class="kitgenix-setting-row-label"><label class="kitgenix-label"><?php esc_html_e( 'Tracking number', 'kitgenix-order-tracking-for-woocommerce' ); ?></label></div>
							<div class="kitgenix-setting-row-control">
								<input type="text" class="widefat kitgenix-order-tracking-for-woocommerce-field-number" placeholder="<?php esc_attr_e( 'e.g. 12121212', 'kitgenix-order-tracking-for-woocommerce' ); ?>">
							</div>
						</div>

						<div class="kitgenix-setting-row kitgenix-setting-row-stacked">
							<div class="kitgenix-setting-row-label"><label class="kitgenix-label"><?php esc_html_e( 'Carrier (optional)', 'kitgenix-order-tracking-for-woocommerce' ); ?></label></div>
							<div class="kitgenix-setting-row-control">
								<select class="widefat kitgenix-order-tracking-for-woocommerce-field-carrier">
									<option value=""><?php esc_html_e( 'Automatic Matching', 'kitgenix-order-tracking-for-woocommerce' ); ?></option>
									<?php foreach ( $carriers as $key => $carrier ) : ?>
										<option value="<?php echo esc_attr( $key ); ?>">
											<?php echo esc_html( $carrier['label'] ); ?>
										</option>
									<?php endforeach; ?>
								</select>
							</div>
						</div>

						<div class="kitgenix-setting-row kitgenix-setting-row-stacked">
							<div class="kitgenix-setting-row-label"><label class="kitgenix-label"><?php esc_html_e( 'Date shipped (optional)', 'kitgenix-order-tracking-for-woocommerce' ); ?></label></div>
							<div class="kitgenix-setting-row-control">
								<input type="date" class="kitgenix-order-tracking-for-woocommerce-field-date" value="<?php echo esc_attr( current_time( 'Y-m-d' ) ); ?>">
							</div>
						</div>

						<div class="kitgenix-setting-row kitgenix-setting-row-stacked">
							<div class="kitgenix-setting-row-label"><label class="kitgenix-label"><?php esc_html_e( 'Estimated delivery (optional)', 'kitgenix-order-tracking-for-woocommerce' ); ?></label></div>
							<div class="kitgenix-setting-row-control">
								<input type="date" class="kitgenix-order-tracking-for-woocommerce-field-delivery-estimate" value="">
							</div>
						</div>

						<div class="kitgenix-setting-row kitgenix-setting-row-stacked">
							<div class="kitgenix-setting-row-label"><label class="kitgenix-label"><?php esc_html_e( 'Shipment status', 'kitgenix-order-tracking-for-woocommerce' ); ?></label></div>
							<div class="kitgenix-setting-row-control">
								<select class="widefat kitgenix-order-tracking-for-woocommerce-field-shipment-status">
									<?php foreach ( Shipment::known_statuses() as $status_key => $status_label ) : ?>
										<option value="<?php echo esc_attr( $status_key ); ?>" <?php selected( $status_key, Shipment::STATUS_SHIPPED ); ?>>
											<?php echo esc_html( $status_label ); ?>
										</option>
									<?php endforeach; ?>
								</select>
								<p class="description"><?php esc_html_e( 'Shown to the customer in the tracking timeline. This never fabricates live carrier events – it only reflects what you set here.', 'kitgenix-order-tracking-for-woocommerce' ); ?></p>
							</div>
						</div>

						<div class="kitgenix-setting-row kitgenix-setting-row-stacked">
							<div class="kitgenix-setting-row-label"><label class="kitgenix-label"><?php esc_html_e( 'Internal note (optional, staff only)', 'kitgenix-order-tracking-for-woocommerce' ); ?></label></div>
							<div class="kitgenix-setting-row-control">
								<textarea class="widefat kitgenix-order-tracking-for-woocommerce-field-note" rows="2"></textarea>
								<p class="description"><?php esc_html_e( 'Never shown to the customer or included in emails.', 'kitgenix-order-tracking-for-woocommerce' ); ?></p>
							</div>
						</div>

						<div class="kitgenix-setting-row kitgenix-setting-row-stacked">
							<div class="kitgenix-setting-row-label"><span class="kitgenix-label"><?php esc_html_e( 'Update order status (optional)', 'kitgenix-order-tracking-for-woocommerce' ); ?></span></div>
							<div class="kitgenix-setting-row-control">
								<fieldset class="kitgenix-order-tracking-for-woocommerce-field-status">
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
						</div>
					</div>
				</div>

				<div class="kitgenix-modal-footer">
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

		// Limit remaining selectable qty using the shared remaining-quantity helper
		// (excludes the shipment currently being edited, if any, so its own
		// already-allocated quantities don't count against themselves).
		$exclude_shipment_id = isset( $_POST['shipment_id'] ) ? sanitize_text_field( (string) wp_unslash( $_POST['shipment_id'] ) ) : '';
		$remaining           = Order_Tracking::get_remaining_quantities( $order, $exclude_shipment_id );

		$items = [];

		foreach ( $order->get_items() as $item_id => $item ) {
			$product        = $item->get_product();
			$max_qty        = $remaining[ (int) $item_id ] ?? 0.0;
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
