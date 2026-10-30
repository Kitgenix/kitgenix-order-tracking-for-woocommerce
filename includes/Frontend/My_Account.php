<?php
/**
 * Branded tracking panel for WooCommerce My Account order views.
 *
 * @package Kitgenix\OrderTracking
 */

declare(strict_types=1);

namespace Kitgenix\OrderTracking\Frontend;

defined( 'ABSPATH' ) || exit;

use WC_Order;
use Kitgenix\OrderTracking\Orders\Order_Tracking;
use Kitgenix\OrderTracking\Orders\Shipment;
use Kitgenix\OrderTracking\Support\Carriers;

/**
 * Shows the order's shipments, their status and a timeline directly on the
 * My Account > Orders > View order screen, so logged-in customers don't need
 * to use the public tracking form for their own orders.
 */
final class My_Account {

	/**
	 * Default panel accent colour.
	 *
	 * @var string
	 */
	private const DEFAULT_ACCENT = '#214BFF';

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function init(): void {
		// Priority 20 places the panel below WooCommerce's order details table (priority 10).
		add_action( 'woocommerce_view_order', [ self::class, 'render_panel' ], 20 );
	}

	/**
	 * Render the tracking panel for the order being viewed.
	 *
	 * @param int|string $order_id Order ID.
	 *
	 * @return void
	 */
	public static function render_panel( $order_id ): void {
		$order_id = absint( $order_id );
		$order    = $order_id ? wc_get_order( $order_id ) : false;

		if ( ! ( $order instanceof WC_Order ) ) {
			return;
		}

		// WooCommerce already checks ownership before firing this hook; re-check defensively.
		if ( ! current_user_can( 'view_order', $order_id ) ) {
			return;
		}

		/**
		 * Filter whether the tracking panel is shown on the My Account order view.
		 *
		 * @param bool     $show  Whether to show the panel.
		 * @param WC_Order $order Order being viewed.
		 */
		if ( ! apply_filters( 'kitgenix_order_tracking_for_woocommerce_show_my_account_panel', true, $order ) ) {
			return;
		}

		$shipments = Order_Tracking::get_shipments( $order );

		$accent = sanitize_hex_color(
			(string) apply_filters( 'kitgenix_order_tracking_for_woocommerce_my_account_accent_color', self::DEFAULT_ACCENT, $order )
		);
		if ( ! $accent ) {
			$accent = self::DEFAULT_ACCENT;
		}

		$order_items_index = [];
		foreach ( $order->get_items() as $item_id => $item ) {
			$order_items_index[ (int) $item_id ] = $item;
		}

		$statuses = Shipment::known_statuses();
		?>
		<section id="kitgenix-tracking" class="kitgenix-account-tracking" style="<?php echo esc_attr( '--kitgenix-ot-accent:' . $accent ); ?>">
			<header class="kitgenix-account-tracking-header">
				<h2 class="kitgenix-account-tracking-title"><?php esc_html_e( 'Shipment tracking', 'kitgenix-order-tracking-for-woocommerce' ); ?></h2>
				<span class="kitgenix-status-badge kitgenix-status-badge--order">
					<?php echo esc_html( wc_get_order_status_name( $order->get_status() ) ); ?>
				</span>
			</header>

			<?php if ( empty( $shipments ) ) : ?>
				<p class="kitgenix-tracking-no-shipments">
					<?php esc_html_e( 'This order has not been dispatched yet. Tracking details will appear here as soon as your items are shipped.', 'kitgenix-order-tracking-for-woocommerce' ); ?>
				</p>
			<?php else : ?>
				<p class="kitgenix-account-tracking-count">
					<?php
					printf(
						/* translators: %s: number of shipments */
						esc_html( _n( '%s shipment for this order', '%s shipments for this order', count( $shipments ), 'kitgenix-order-tracking-for-woocommerce' ) ),
						esc_html( number_format_i18n( count( $shipments ) ) )
					);
					?>
				</p>

				<ul class="kitgenix-account-shipments">
					<?php foreach ( $shipments as $shipment ) : ?>
						<?php
						$carrier       = (string) ( $shipment['carrier'] ?? '' );
						$number        = (string) ( $shipment['tracking_number'] ?? '' );
						$url           = (string) ( $shipment['tracking_url'] ?? '' );
						$status        = '' !== (string) ( $shipment['status'] ?? '' ) ? (string) $shipment['status'] : Shipment::STATUS_SHIPPED;
						$status_label  = $statuses[ $status ] ?? ucwords( str_replace( [ '_', '-' ], ' ', $status ) );
						$carrier_label = Carriers::get_label( $carrier );
						$icon          = Carriers::get_icon( $carrier );
						$note          = (string) ( $shipment['note'] ?? '' );
						$shipment_items = isset( $shipment['items'] ) && is_array( $shipment['items'] ) ? $shipment['items'] : [];
						?>
						<li class="kitgenix-account-shipment">
							<div class="kitgenix-account-shipment-head">
								<span class="kitgenix-tracking-header">
									<?php if ( $icon ) : ?>
										<span class="kitgenix-carrier-icon">
											<img src="<?php echo esc_url( $icon ); ?>" alt="" loading="lazy" />
										</span>
									<?php endif; ?>
									<span class="kitgenix-carrier-name"><?php echo esc_html( $carrier_label ); ?></span>
								</span>
								<span class="<?php echo esc_attr( 'kitgenix-status-badge kitgenix-status-badge--' . sanitize_html_class( $status ) ); ?>">
									<?php echo esc_html( $status_label ); ?>
								</span>
							</div>

							<p class="kitgenix-tracking-number">
								<span class="kitgenix-account-label"><?php esc_html_e( 'Tracking number:', 'kitgenix-order-tracking-for-woocommerce' ); ?></span>
								<code class="kitgenix-value"><?php echo esc_html( $number ); ?></code>
							</p>

							<?php echo Timeline::render( $order, $shipment ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Timeline::render() escapes its own output. ?>

							<?php if ( '' !== $note ) : ?>
								<p class="kitgenix-account-shipment-note"><?php echo esc_html( $note ); ?></p>
							<?php endif; ?>

							<?php if ( ! empty( $shipment_items ) ) : ?>
								<div class="kitgenix-shipment-items">
									<span class="kitgenix-account-label"><?php esc_html_e( 'Items in this shipment:', 'kitgenix-order-tracking-for-woocommerce' ); ?></span>
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
											$name = $order_item ? $order_item->get_name() : sprintf( __( 'Item #%d', 'kitgenix-order-tracking-for-woocommerce' ), $s_item_id );
											?>
											<li class="kitgenix-item">
												<span class="kitgenix-item-name"><?php echo esc_html( $name ); ?></span>
												<span class="kitgenix-item-qty">
													<?php
													printf(
														/* translators: %s: quantity shipped */
														esc_html__( '× %s', 'kitgenix-order-tracking-for-woocommerce' ),
														esc_html( wc_format_decimal( $s_qty, 0 ) )
													);
													?>
												</span>
											</li>
										<?php endforeach; ?>
									</ul>
								</div>
							<?php endif; ?>

							<?php if ( $url ) : ?>
								<p class="kitgenix-account-shipment-actions">
									<a class="button kitgenix-account-track-button" href="<?php echo esc_url( $url ); ?>" target="_blank" rel="noopener noreferrer">
										<?php esc_html_e( 'Track with carrier', 'kitgenix-order-tracking-for-woocommerce' ); ?>
									</a>
								</p>
							<?php else : ?>
								<p class="kitgenix-tracking-link kitgenix-tracking-link-muted">
									<?php esc_html_e( 'Online tracking is not available for this carrier.', 'kitgenix-order-tracking-for-woocommerce' ); ?>
								</p>
							<?php endif; ?>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
		</section>
		<?php
	}
}
