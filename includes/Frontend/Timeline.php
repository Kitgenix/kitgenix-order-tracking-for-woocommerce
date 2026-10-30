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
 *
 * Stores are not expected to manually walk a shipment through "in transit"
 * before marking it delivered, so there is no separate pending "In transit"
 * step that would otherwise sit greyed-out forever. Deliberately, no step
 * ever claims the parcel is currently "on its way"/"in transit" unless a
 * human actually set that status – that kind of claim goes stale (and
 * becomes false) the moment the parcel is delivered but nobody updates it.
 * By default the last step is simply "Shipped" – a fact that stays true
 * forever – shown with a pulsing/"live" treatment so it still reads as
 * active rather than stalled, without asserting anything about where the
 * parcel currently is.
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

		$terminal_steps = [
			Shipment::STATUS_DELIVERED => [
				'label'   => __( 'Delivered', 'kitgenix-order-tracking-for-woocommerce' ),
				'variant' => 'default',
			],
			Shipment::STATUS_EXCEPTION => [
				'label'   => __( 'Delivery exception', 'kitgenix-order-tracking-for-woocommerce' ),
				'variant' => 'warning',
			],
			Shipment::STATUS_RETURNED => [
				'label'   => __( 'Returned to sender', 'kitgenix-order-tracking-for-woocommerce' ),
				'variant' => 'warning',
			],
			Shipment::STATUS_CANCELLED => [
				'label'   => __( 'Cancelled', 'kitgenix-order-tracking-for-woocommerce' ),
				'variant' => 'warning',
			],
		];

		$is_terminal = isset( $terminal_steps[ $status ] );
		// Only treat "Shipped" as the live/current step while nothing more
		// specific has been recorded. Once staff explicitly set "in transit",
		// that becomes its own step instead – a status a human actually chose
		// is safe to show as current, unlike a guess this class would invent.
		$shipped_is_current = ! $is_terminal && Shipment::STATUS_IN_TRANSIT !== $status;

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
				'variant' => $shipped_is_current ? 'current' : 'default',
			],
		];

		if ( $is_terminal ) {
			$steps[] = [
				'label'   => $terminal_steps[ $status ]['label'],
				'date'    => '',
				'reached' => true,
				'variant' => $terminal_steps[ $status ]['variant'],
			];
		} elseif ( Shipment::STATUS_IN_TRANSIT === $status ) {
			$steps[] = [
				'label'   => __( 'In transit', 'kitgenix-order-tracking-for-woocommerce' ),
				'date'    => '',
				'reached' => true,
				'variant' => 'current',
			];
		}

		$html = '<ol class="kitgenix-timeline">';
		foreach ( $steps as $step ) {
			$classes = [ 'kitgenix-timeline-step' ];
			$classes[] = ! empty( $step['reached'] ) ? 'is-reached' : 'is-pending';
			if ( ! empty( $step['variant'] ) && 'default' !== $step['variant'] ) {
				$classes[] = 'is-' . $step['variant'];
			}

			$html .= '<li class="' . esc_attr( implode( ' ', $classes ) ) . '">';
			$html .= '<span class="kitgenix-timeline-dot" aria-hidden="true">';
			if ( ! empty( $step['variant'] ) && 'current' === $step['variant'] ) {
				$html .= '<span class="kitgenix-timeline-pulse" aria-hidden="true"></span>';
			}
			$html .= '</span>';
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
