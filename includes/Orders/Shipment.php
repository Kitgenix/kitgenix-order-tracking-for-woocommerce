<?php
/**
 * Shipment value object: normalises and validates a single shipment record.
 *
 * @package Kitgenix\OrderTracking
 */

declare(strict_types=1);

namespace Kitgenix\OrderTracking\Orders;

defined( 'ABSPATH' ) || exit;

use Kitgenix\OrderTracking\Support\Carriers;

/**
 * Formal shipment record used by the admin UI, REST API, CSV import/export,
 * and the schema migration. Shipments are still persisted as plain arrays
 * inside order meta (no new database tables), but every read/write path now
 * goes through this class so the shape stays consistent everywhere.
 */
final class Shipment {

	public const STATUS_SHIPPED    = 'shipped';
	public const STATUS_IN_TRANSIT = 'in_transit';
	public const STATUS_DELIVERED  = 'delivered';
	public const STATUS_EXCEPTION  = 'exception';
	public const STATUS_RETURNED   = 'returned';
	public const STATUS_CANCELLED  = 'cancelled';

	/**
	 * Known statuses offered in the admin UI. Any other string already stored
	 * on legacy data is preserved (never discarded), just not offered as a choice.
	 *
	 * @return array<string,string> status key => human label
	 */
	public static function known_statuses(): array {
		return [
			self::STATUS_SHIPPED    => __( 'Shipped', 'kitgenix-order-tracking-for-woocommerce' ),
			self::STATUS_IN_TRANSIT => __( 'In transit', 'kitgenix-order-tracking-for-woocommerce' ),
			self::STATUS_DELIVERED  => __( 'Delivered', 'kitgenix-order-tracking-for-woocommerce' ),
			self::STATUS_EXCEPTION  => __( 'Exception', 'kitgenix-order-tracking-for-woocommerce' ),
			self::STATUS_RETURNED   => __( 'Returned', 'kitgenix-order-tracking-for-woocommerce' ),
			self::STATUS_CANCELLED  => __( 'Cancelled', 'kitgenix-order-tracking-for-woocommerce' ),
		];
	}

	/**
	 * Normalise a shipment coming from *storage* (order meta) into the full,
	 * consistent shape the rest of the plugin can rely on. Tolerant of legacy
	 * records that predate delivery_estimate/note, or that used date_shipped
	 * instead of created_at.
	 *
	 * @param array<string,mixed> $raw Raw shipment array from meta.
	 *
	 * @return array<string,mixed>|null Null when the record has no tracking number.
	 */
	public static function from_stored( array $raw ): ?array {
		$tracking_number = isset( $raw['tracking_number'] ) ? (string) $raw['tracking_number'] : '';
		if ( '' === $tracking_number ) {
			return null;
		}

		$carrier = isset( $raw['carrier'] ) ? (string) $raw['carrier'] : '';

		$created_at = '';
		if ( isset( $raw['created_at'] ) && '' !== (string) $raw['created_at'] ) {
			$created_at = (string) $raw['created_at'];
		} elseif ( isset( $raw['date_shipped'] ) && '' !== (string) $raw['date_shipped'] ) {
			$created_at = (string) $raw['date_shipped'];
		}

		$status           = isset( $raw['status'] ) && '' !== (string) $raw['status'] ? (string) $raw['status'] : self::STATUS_SHIPPED;
		$delivery_estimate = isset( $raw['delivery_estimate'] ) ? (string) $raw['delivery_estimate'] : '';
		$note             = isset( $raw['note'] ) ? (string) $raw['note'] : '';

		$shipment = [
			'id'                => isset( $raw['id'] ) && '' !== (string) $raw['id'] ? (string) $raw['id'] : wp_generate_uuid4(),
			'carrier'           => $carrier,
			'tracking_number'   => $tracking_number,
			'tracking_url'      => Carriers::build_tracking_url( $carrier, $tracking_number ),
			'created_at'        => $created_at ? $created_at : current_time( 'mysql' ),
			'delivery_estimate' => $delivery_estimate,
			'status'            => $status,
			'note'              => $note,
			'items'             => self::normalise_items( $raw['items'] ?? [] ),
		];

		if ( '' === $carrier ) {
			$detected = Carriers::detect( $tracking_number );
			if ( null !== $detected ) {
				$shipment['carrier_detected'] = $detected;
			}
		}

		return $shipment;
	}

	/**
	 * Normalise + sanitise a shipment coming from *user input* (admin form,
	 * REST API, or CSV import) ready to be persisted.
	 *
	 * @param array<string,mixed> $raw Raw input array.
	 *
	 * @return array<string,mixed>|null Null when required fields are missing.
	 */
	public static function from_input( array $raw ): ?array {
		$tracking_number = isset( $raw['tracking_number'] ) ? sanitize_text_field( (string) $raw['tracking_number'] ) : '';
		if ( '' === $tracking_number ) {
			return null;
		}

		$carrier_raw      = isset( $raw['carrier'] ) ? sanitize_text_field( (string) $raw['carrier'] ) : '';
		$allowed_carriers = Carriers::keys_all();
		$carrier          = ( '' === $carrier_raw || in_array( $carrier_raw, $allowed_carriers, true ) ) ? $carrier_raw : '';

		$date_raw = '';
		if ( isset( $raw['date_shipped'] ) && '' !== (string) $raw['date_shipped'] ) {
			$date_raw = (string) $raw['date_shipped'];
		} elseif ( isset( $raw['created_at'] ) && '' !== (string) $raw['created_at'] ) {
			$date_raw = (string) $raw['created_at'];
		}

		$delivery_estimate = isset( $raw['delivery_estimate'] ) ? sanitize_text_field( (string) $raw['delivery_estimate'] ) : '';
		$status_raw        = isset( $raw['status'] ) ? sanitize_key( (string) $raw['status'] ) : self::STATUS_SHIPPED;
		$status            = '' !== $status_raw ? $status_raw : self::STATUS_SHIPPED;
		$note              = isset( $raw['note'] ) ? sanitize_textarea_field( (string) $raw['note'] ) : '';

		return [
			'id'                => ! empty( $raw['id'] ) ? sanitize_text_field( (string) $raw['id'] ) : wp_generate_uuid4(),
			'carrier'           => $carrier,
			'tracking_number'   => $tracking_number,
			'tracking_url'      => Carriers::build_tracking_url( $carrier, $tracking_number ),
			'created_at'        => $date_raw ? sanitize_text_field( $date_raw ) : current_time( 'mysql' ),
			'delivery_estimate' => $delivery_estimate,
			'status'            => $status,
			'note'              => $note,
			'items'             => self::normalise_items( $raw['items'] ?? [] ),
		];
	}

	/**
	 * Accept an items payload in any of the shapes the UI/CSV/REST have used
	 * historically: array of objects, a mapping of item_id => qty, a mapping
	 * of item_id => ['qty' => ..], or a JSON-encoded string of any of those.
	 *
	 * @param mixed $raw_items Raw items payload.
	 *
	 * @return array<int,array{item_id:int,qty:float}>
	 */
	public static function normalise_items( $raw_items ): array {
		if ( is_string( $raw_items ) ) {
			$decoded = json_decode( $raw_items, true );
			if ( is_array( $decoded ) ) {
				$raw_items = $decoded;
			}
		}

		if ( ! is_array( $raw_items ) || empty( $raw_items ) ) {
			return [];
		}

		$items                        = [];
		$looks_like_array_of_objects = isset( $raw_items[0] ) && is_array( $raw_items[0] );

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
				$qty     = is_array( $v ) ? ( isset( $v['qty'] ) ? (float) $v['qty'] : 0.0 ) : (float) $v;
				if ( $item_id > 0 && $qty > 0 ) {
					$items[] = [ 'item_id' => $item_id, 'qty' => $qty ];
				}
			}
		}

		return $items;
	}
}
