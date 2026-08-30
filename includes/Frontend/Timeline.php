<?php
/**
 * Customer-facing shipment timeline renderer.
 *
 * @package Kitgenix\OrderTracking
 */

declare(strict_types=1);

namespace Kitgenix\OrderTracking\Frontend;

defined( 'ABSPATH' ) || exit;

use WC_Order;
use Kitgenix\OrderTracking\Orders\Shipment;

/**
 * Renders a simple, honest shipment timeline: only steps the store has
 * actually recorded (order placed, shipped, and the shipment's current
 * status) are ever shown. No live carrier scan events are fabricated –
 * if a real carrier-events integration is added in future, it should feed
 * additional steps in here rather than this class inventing any.
 */
final class Timeline {

	/**
	 * Render the timeline markup for a single shipment.
	 *
	 * @param WC_Order            $order    Order instance (for the "order placed" date).
	 * @param array<string,mixed> $shipment Normalised shipment record.
	 *
	 * @return string Escaped HTML.
	 */
	public static function render( WC_Order $order, array $shipment ): string {
		$status = isset( $shipment['status'] ) && '' !== (string) $shipment['status']
			? (string) $shipment['status']
			: Shipment::STATUS_SHIPPED;

		$placed_date = $order->get_date_created() ? $order->get_date_created()->date_i18n( get_option( 'date_format' ) ) : '';
		$shipped_date = isset( $shipment['created_at'] ) && '' !== (string) $shipment['created_at']
			? self::format_date( (string) $shipment['created_at'] )
			: '';

		$steps = [
			[
				'label'   => __( 'Order placed', 'kitgenix-order-tracking-for-woocommerce' ),
				'date'    => $placed_date,
				'reached' => true,
			],
			[
				'label'   => __( 'Shipped', 'kitgenix-order-tracking-for-woocommerce' ),
				'date'    => $shipped_date,
				'reached' => true,
			],
			[
				'label'   => __( 'In transit', 'kitgenix-order-tracking-for-woocommerce' ),
				'date'    => '',
				'reached' => in_array( $status, [ Shipment::STATUS_IN_TRANSIT, Shipment::STATUS_DELIVERED ], true ),
			],
		];

		$final_label   = __( 'Delivered', 'kitgenix-order-tracking-for-woocommerce' );
		$final_reached = Shipment::STATUS_DELIVERED === $status;
		$final_variant = 'default';

		if ( Shipment::STATUS_EXCEPTION === $status ) {
			$final_label   = __( 'Delivery exception', 'kitgenix-order-tracking-for-woocommerce' );
			$final_reached = true;
			$final_variant = 'warning';
		} elseif ( Shipment::STATUS_RETURNED === $status ) {
			$final_label   = __( 'Returned to sender', 'kitgenix-order-tracking-for-woocommerce' );
			$final_reached = true;
			$final_variant = 'warning';
		} elseif ( Shipment::STATUS_CANCELLED === $status ) {
			$final_label   = __( 'Cancelled', 'kitgenix-order-tracking-for-woocommerce' );
			$final_reached = true;
			$final_variant = 'warning';
		}

		$steps[] = [
			'label'   => $final_label,
			'date'    => '',
			'reached' => $final_reached,
			'variant' => $final_variant,
		];

		$html = '<ol class="kitgenix-timeline">';
		foreach ( $steps as $step ) {
			$classes = [ 'kitgenix-timeline-step' ];
			$classes[] = ! empty( $step['reached'] ) ? 'is-reached' : 'is-pending';
			if ( ! empty( $step['variant'] ) && 'default' !== $step['variant'] ) {
				$classes[] = 'is-' . $step['variant'];
			}

			$html .= '<li class="' . esc_attr( implode( ' ', $classes ) ) . '">';
			$html .= '<span class="kitgenix-timeline-dot" aria-hidden="true"></span>';
			$html .= '<span class="kitgenix-timeline-label">' . esc_html( (string) $step['label'] ) . '</span>';
			if ( ! empty( $step['date'] ) ) {
				$html .= '<span class="kitgenix-timeline-date">' . esc_html( (string) $step['date'] ) . '</span>';
			}
			$html .= '</li>';
		}
		$html .= '</ol>';

		if ( ! empty( $shipment['delivery_estimate'] ) && Shipment::STATUS_DELIVERED !== $status ) {
			$html .= '<p class="kitgenix-timeline-estimate">' . sprintf(
				/* translators: %s: estimated delivery date */
				esc_html__( 'Estimated delivery: %s', 'kitgenix-order-tracking-for-woocommerce' ),
				esc_html( self::format_date( (string) $shipment['delivery_estimate'] ) )
			) . '</p>';
		}

		return $html;
	}

	/**
	 * Format a stored date string using the site's date format, falling back
	 * to the raw string when it can't be parsed.
	 */
	private static function format_date( string $raw ): string {
		if ( '' === $raw ) {
			return '';
		}

		try {
			$date = new \DateTimeImmutable( $raw, wp_timezone() );
			return $date->format( get_option( 'date_format' ) );
		} catch ( \Throwable $e ) {
			return $raw;
		}
	}
}
