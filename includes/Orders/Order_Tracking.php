<?php
/**
 * Order tracking logic (saving, retrieving, searching).
 *
 * @package Kitgenix\OrderTracking
 */

declare(strict_types=1);

namespace Kitgenix\OrderTracking\Orders;

defined( 'ABSPATH' ) || exit;

use WC_Order;
use Kitgenix\OrderTracking\Support\Carriers;

/**
 * Core tracking functionality.
 */
final class Order_Tracking {

	/**
	 * Meta key used to store the array of shipments.
	 *
	 * @var string
	 */
	public const META_KEY = '_kitgenix_tracking_shipments';

	/**
	 * Meta key used to index individual tracking numbers for fast lookups.
	 *
	 * @var string
	 */
	public const META_TRACKING_KEY = '_kitgenix_tracking_number';

	/**
	 * Bootstrap (reserved for future hooks).
	 *
	 * @return void
	 */
	public static function init(): void {
		// Nothing yet.
	}

	/**
	 * Retrieve all shipments for an order.
	 *
	 * @param WC_Order $order Order instance.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function get_shipments( WC_Order $order ): array {
		$raw = $order->get_meta( self::META_KEY, true );

		if ( ! is_array( $raw ) ) {
			return [];
		}

		$shipments = [];

		foreach ( $raw as $shipment ) {
			if ( ! is_array( $shipment ) ) {
				continue;
			}

			$carrier         = isset( $shipment['carrier'] ) ? (string) $shipment['carrier'] : '';
			$tracking_number = isset( $shipment['tracking_number'] ) ? (string) $shipment['tracking_number'] : '';

			// Tracking number is mandatory; carrier is optional.
			if ( '' === $tracking_number ) {
				continue;
			}

			// Normalise items payload (if present) – accept array of objects, mapping, or JSON string.
			$items = [];
			if ( isset( $shipment['items'] ) ) {
				$raw_items = $shipment['items'];

				if ( is_string( $raw_items ) ) {
					$decoded = json_decode( $raw_items, true );
					if ( is_array( $decoded ) ) {
						$raw_items = $decoded;
					}
				}

				if ( is_array( $raw_items ) ) {
					// Case 1: array of [ [item_id=>.., qty=>..], ... ]
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
						// Case 2: mapping of item_id => qty OR mapping of item_id => ['qty'=>..].
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

			// Use created_at as "date shipped", but gracefully accept legacy date_shipped.
			$created_at = '';
			if ( isset( $shipment['created_at'] ) && '' !== (string) $shipment['created_at'] ) {
				$created_at = (string) $shipment['created_at'];
			} elseif ( isset( $shipment['date_shipped'] ) && '' !== (string) $shipment['date_shipped'] ) {
				$created_at = (string) $shipment['date_shipped'];
			}

			$status = isset( $shipment['status'] ) ? (string) $shipment['status'] : 'shipped';

			$shipments[] = [
				'id'              => isset( $shipment['id'] ) ? (string) $shipment['id'] : wp_generate_uuid4(),
				'carrier'         => $carrier,
				'tracking_number' => $tracking_number,
				'tracking_url'    => Carriers::build_tracking_url( $carrier, $tracking_number ),
				'created_at'      => $created_at ? $created_at : current_time( 'mysql' ),
				'status'          => $status,
				'items'           => $items,
			];
		}

		return $shipments;
	}

	/**
	 * Save shipments on an order.
	 *
	 * @param WC_Order $order     The WC order object.
	 * @param array    $shipments Array of shipments.
	 *
	 * @return void
	 */
	public static function save_shipments( WC_Order $order, array $shipments ): void {
		// Count newly-added tracking numbers (privacy-safe: totals only).
		$previous_numbers = [];
		try {
			$previous = self::get_shipments( $order );
			foreach ( $previous as $s ) {
				if ( isset( $s['tracking_number'] ) && '' !== (string) $s['tracking_number'] ) {
					$previous_numbers[ (string) $s['tracking_number'] ] = true;
				}
			}
		} catch ( \Throwable $e ) {
			// If anything goes wrong, skip counting (do not block save).
			$previous_numbers = [];
		}

		$clean = [];

		foreach ( $shipments as $shipment ) {
			$carrier         = isset( $shipment['carrier'] ) ? sanitize_text_field( (string) $shipment['carrier'] ) : '';
			$tracking_number = isset( $shipment['tracking_number'] ) ? sanitize_text_field( (string) $shipment['tracking_number'] ) : '';

			// Require tracking number; allow empty carrier (automatic/unknown).
			if ( '' === $tracking_number ) {
				continue;
			}

			// Normalise items payload coming from save_meta_box() – accept array, mapping, or JSON string.
			$items = [];
			if ( isset( $shipment['items'] ) ) {
				$raw_items = $shipment['items'];
				if ( is_string( $raw_items ) ) {
					$decoded = json_decode( $raw_items, true );
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

			// Accept either date_shipped (from UI) or created_at (legacy).
			$date_raw = '';
			if ( isset( $shipment['date_shipped'] ) && '' !== (string) $shipment['date_shipped'] ) {
				$date_raw = (string) $shipment['date_shipped'];
			} elseif ( isset( $shipment['created_at'] ) && '' !== (string) $shipment['created_at'] ) {
				$date_raw = (string) $shipment['created_at'];
			}

			$clean[] = [
				'id'              => ! empty( $shipment['id'] ) ? sanitize_text_field( (string) $shipment['id'] ) : wp_generate_uuid4(),
				'carrier'         => $carrier,
				'tracking_number' => $tracking_number,
				'tracking_url'    => Carriers::build_tracking_url( $carrier, $tracking_number ),
				'created_at'      => $date_raw ? sanitize_text_field( $date_raw ) : current_time( 'mysql' ),
				'status'          => isset( $shipment['status'] ) ? sanitize_text_field( (string) $shipment['status'] ) : 'shipped',
				'items'           => $items,
			];
		}

		// Increment metric by number of *new* unique tracking numbers.
		$new_numbers = [];
		foreach ( $clean as $shipment ) {
			if ( ! empty( $shipment['tracking_number'] ) ) {
				$new_numbers[ (string) $shipment['tracking_number'] ] = true;
			}
		}
		$delta = 0;
		foreach ( $new_numbers as $tn => $_true ) {
			if ( ! isset( $previous_numbers[ $tn ] ) ) {
				$delta++;
			}
		}
		if ( $delta > 0 ) {
			$existing = (int) get_option( 'kitgenix_order_tracking_for_woocommerce_tracking_numbers_added', 0 );
			update_option( 'kitgenix_order_tracking_for_woocommerce_tracking_numbers_added', $existing + $delta, false );
		}

		// Save array of shipments.
		$order->update_meta_data( self::META_KEY, $clean );
		$order->save();

		// Index tracking numbers for fast lookup.
		$post_id = $order->get_id();

		if ( $post_id ) {
			delete_post_meta( $post_id, self::META_TRACKING_KEY );

			foreach ( $clean as $shipment ) {
				if ( ! empty( $shipment['tracking_number'] ) ) {
					add_post_meta(
						$post_id,
						self::META_TRACKING_KEY,
						(string) $shipment['tracking_number'],
						false
					);
				}
			}
		}
	}

	/**
	 * Find an order by tracking number (fast indexed search, slow fallback).
	 *
	 * Note: this function contains controlled queries that may trigger static
	 * analyzers for slow meta queries. We intentionally use an exact indexed
	 * meta lookup and a bounded PHP-side fallback to avoid database-side LIKE
	 * meta_query usage. The rules are disabled for this function.
	 *
	 * phpcs:disable WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value, WordPress.DB.SlowDBQuery.slow_db_query_meta_query
	 *
	 * @param string $tracking_number Raw tracking number.
	 *
	 * @return WC_Order|null
	 */
	public static function find_order_by_tracking_number( string $tracking_number ): ?WC_Order {
		$tracking_number = trim( $tracking_number );

		if ( '' === $tracking_number ) {
			return null;
		}

		// FAST-query: exact match against an indexed meta key using `meta_key`/`meta_value`.
		// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value
		// Reason: this is an exact lookup against a deliberately indexed meta key
		// created by the plugin for fast searches; it is expected to be performant.
		$orders = wc_get_orders(
			[
				'limit'      => 1,
				'meta_key'   => self::META_TRACKING_KEY,
				'meta_value' => $tracking_number,
				'orderby'    => 'date',
				'order'      => 'DESC',
			]
		);

		// If no indexed match, use slower fallback (backwards compatibility).
		if ( empty( $orders ) ) {
			// FALLBACK: legacy data may store tracking numbers serialized inside the
			// shipments meta. Fetch a bounded set of recent orders that have the
			// shipments meta, then inspect the meta in PHP. This avoids a slow
			// `meta_query` LIKE against the DB.
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			// Reason: bounded `get_posts` used to fetch a limited set of recent
			// orders that contain the shipments meta; introspection is then
			// performed in PHP to avoid a slow DB-side LIKE meta_query.
			$candidate_ids = get_posts(
				[
					'post_type'   => 'shop_order',
					'numberposts' => 200,
					'meta_key'    => self::META_KEY,
					'orderby'     => 'date',
					'order'       => 'DESC',
					'fields'      => 'ids',
				]
			);

			if ( ! empty( $candidate_ids ) ) {
				foreach ( $candidate_ids as $pid ) {
					$meta = get_post_meta( (int) $pid, self::META_KEY, true );
					if ( ! is_array( $meta ) ) {
						continue;
					}
					foreach ( $meta as $shipment ) {
						if ( ! is_array( $shipment ) ) {
							continue;
						}
						$tn = isset( $shipment['tracking_number'] ) ? (string) $shipment['tracking_number'] : '';
						if ( $tn === $tracking_number ) {
							$orders = wc_get_orders(
								[
									'limit'   => 1,
									'include' => [ (int) $pid ],
								]
							);
							break 2;
						}
					}
				}
			}
		}

		if ( empty( $orders ) ) {
			return null;
		}

		$order = $orders[0];

		return ( $order instanceof WC_Order ) ? $order : null;
	}

	/* phpcs:enable WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value, WordPress.DB.SlowDBQuery.slow_db_query_meta_query */
}
