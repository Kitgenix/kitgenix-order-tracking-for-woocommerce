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
use Kitgenix\OrderTracking\Support\Event_Log;

/**
 * Core tracking functionality.
 *
 * Documented action hooks (see readme.txt "Hooks / filters" for full docs):
 * - kitgenix_order_tracking_shipment_created( int $order_id, array $shipment )
 * - kitgenix_order_tracking_shipment_updated( int $order_id, array $shipment, array $previous_shipment )
 * - kitgenix_order_tracking_shipment_deleted( int $order_id, array $shipment )
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

			$normalised = Shipment::from_stored( $shipment );
			if ( null !== $normalised ) {
				$shipments[] = $normalised;
			}
		}

		return $shipments;
	}

	/**
	 * Remaining (not yet allocated) quantity per order item, across all
	 * existing shipments. Used to keep partial-shipment quantities from ever
	 * exceeding what was actually ordered.
	 *
	 * @param WC_Order  $order          Order instance.
	 * @param string    $exclude_shipment_id Optional shipment id to exclude from the "already shipped" tally (e.g. the one currently being edited).
	 *
	 * @return array<int,float> item_id => remaining quantity.
	 */
	public static function get_remaining_quantities( WC_Order $order, string $exclude_shipment_id = '' ): array {
		$remaining = [];
		foreach ( $order->get_items() as $item_id => $item ) {
			$remaining[ (int) $item_id ] = (float) $item->get_quantity();
		}

		foreach ( self::get_shipments( $order ) as $shipment ) {
			if ( '' !== $exclude_shipment_id && isset( $shipment['id'] ) && (string) $shipment['id'] === $exclude_shipment_id ) {
				continue;
			}

			foreach ( $shipment['items'] as $item ) {
				$item_id = (int) $item['item_id'];
				if ( isset( $remaining[ $item_id ] ) ) {
					$remaining[ $item_id ] = max( 0.0, $remaining[ $item_id ] - (float) $item['qty'] );
				}
			}
		}

		return $remaining;
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
		// Capture previous state for the added-tracking-numbers metric and for
		// diffing created/updated/deleted shipments (used to fire hooks below).
		$previous_numbers = [];
		$previous_by_id   = [];
		try {
			$previous = self::get_shipments( $order );
			foreach ( $previous as $s ) {
				if ( isset( $s['tracking_number'] ) && '' !== (string) $s['tracking_number'] ) {
					$previous_numbers[ (string) $s['tracking_number'] ] = true;
				}
				if ( isset( $s['id'] ) && '' !== (string) $s['id'] ) {
					$previous_by_id[ (string) $s['id'] ] = $s;
				}
			}
		} catch ( \Throwable $e ) {
			// If anything goes wrong, skip counting (do not block save).
			$previous_numbers = [];
			$previous_by_id   = [];
			Event_Log::record(
				'tracking-save',
				'skipped',
				Event_Log::get_category_and_note( 'tracking_count_skipped' )['note'],
				'tracking_count_skipped'
			);
		}

		$clean = [];
		foreach ( $shipments as $shipment ) {
			if ( ! is_array( $shipment ) ) {
				continue;
			}
			$normalised = Shipment::from_input( $shipment );
			if ( null !== $normalised ) {
				$clean[] = $normalised;
			}
		}

		$clean = self::clamp_item_quantities( $order, $clean );

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

		// Save array of shipments (only the fields actually needed in storage;
		// 'carrier_detected' is a read-time hint and never persisted).
		$to_store = array_map(
			static function ( array $s ): array {
				unset( $s['carrier_detected'] );
				return $s;
			},
			$clean
		);
		$order->update_meta_data( self::META_KEY, $to_store );

		// Index tracking numbers for fast lookup. Uses the order's own CRUD meta
		// API (not add_post_meta()/delete_post_meta()) so the index is written to
		// wherever this order actually lives – the custom order tables under HPOS,
		// or wp_postmeta in legacy mode. Writing directly to wp_postmeta here would
		// make the index invisible to wc_get_orders() meta lookups once HPOS is
		// enabled, silently forcing every lookup onto the slow fallback path.
		$order->delete_meta_data( self::META_TRACKING_KEY );
		foreach ( $clean as $shipment ) {
			if ( ! empty( $shipment['tracking_number'] ) ) {
				$order->add_meta_data( self::META_TRACKING_KEY, (string) $shipment['tracking_number'], false );
			}
		}

		$order->save();

		self::fire_change_hooks( $order->get_id(), $previous_by_id, $clean );
	}

	/**
	 * Ensure the sum of item quantities allocated across all shipments never
	 * exceeds what was actually ordered. Processes shipments in order and
	 * clamps (never rejects outright) any quantity that would overshoot, so a
	 * bad row can't silently overstate what has shipped. Clamped shipments are
	 * logged for visibility.
	 *
	 * @param WC_Order                    $order     Order instance.
	 * @param array<int,array<string,mixed>> $shipments Clean shipment records.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private static function clamp_item_quantities( WC_Order $order, array $shipments ): array {
		$ordered = [];
		foreach ( $order->get_items() as $item_id => $item ) {
			$ordered[ (int) $item_id ] = (float) $item->get_quantity();
		}

		if ( empty( $ordered ) ) {
			return $shipments;
		}

		$allocated = [];
		$clamped   = false;

		foreach ( $shipments as &$shipment ) {
			$new_items = [];
			foreach ( $shipment['items'] as $item ) {
				$item_id = (int) $item['item_id'];
				$qty     = (float) $item['qty'];

				if ( ! isset( $ordered[ $item_id ] ) ) {
					// Item doesn't belong to this order (or was removed) – drop it.
					$clamped = true;
					continue;
				}

				$already   = $allocated[ $item_id ] ?? 0.0;
				$available = max( 0.0, $ordered[ $item_id ] - $already );

				if ( $qty > $available ) {
					$qty     = $available;
					$clamped = true;
				}

				if ( $qty > 0 ) {
					$allocated[ $item_id ] = $already + $qty;
					$new_items[]           = [ 'item_id' => $item_id, 'qty' => $qty ];
				}
			}
			$shipment['items'] = $new_items;
		}
		unset( $shipment );

		if ( $clamped ) {
			Event_Log::record(
				'tracking-save',
				'adjusted',
				__( 'One or more shipment item quantities were reduced because they exceeded the quantity ordered.', 'kitgenix-order-tracking-for-woocommerce' ),
				'quantity_clamped'
			);
		}

		return $shipments;
	}

	/**
	 * Diff previous vs. new shipments (by id) and fire the documented
	 * created/updated/deleted action hooks.
	 *
	 * @param int                              $order_id     Order ID.
	 * @param array<string,array<string,mixed>> $previous_by_id Previous shipments keyed by id.
	 * @param array<int,array<string,mixed>>    $new_shipments New shipment list.
	 */
	private static function fire_change_hooks( int $order_id, array $previous_by_id, array $new_shipments ): void {
		$seen_ids = [];

		foreach ( $new_shipments as $shipment ) {
			$id         = (string) ( $shipment['id'] ?? '' );
			$seen_ids[ $id ] = true;

			if ( ! isset( $previous_by_id[ $id ] ) ) {
				/**
				 * Fires after a new shipment is created on an order.
				 *
				 * @param int                  $order_id Order ID.
				 * @param array<string,mixed>  $shipment The new shipment record.
				 */
				do_action( 'kitgenix_order_tracking_shipment_created', $order_id, $shipment );
				continue;
			}

			if ( $previous_by_id[ $id ] !== $shipment ) {
				/**
				 * Fires after an existing shipment is updated.
				 *
				 * @param int                 $order_id         Order ID.
				 * @param array<string,mixed> $shipment         The updated shipment record.
				 * @param array<string,mixed> $previous_shipment The shipment record before the update.
				 */
				do_action( 'kitgenix_order_tracking_shipment_updated', $order_id, $shipment, $previous_by_id[ $id ] );
			}
		}

		foreach ( $previous_by_id as $id => $shipment ) {
			if ( ! isset( $seen_ids[ $id ] ) ) {
				/**
				 * Fires after a shipment is removed from an order.
				 *
				 * @param int                 $order_id Order ID.
				 * @param array<string,mixed> $shipment The removed shipment record.
				 */
				do_action( 'kitgenix_order_tracking_shipment_deleted', $order_id, $shipment );
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
			// shipments meta only (pre-dating the indexed meta key). Fetch a bounded
			// set of recent orders that have the shipments meta via wc_get_orders()
			// (HPOS-safe: works against the custom order tables as well as the
			// legacy posts table, unlike a direct get_posts( post_type => shop_order )
			// query, which only ever sees legacy post-based orders), then inspect
			// the meta in PHP. This avoids a slow DB-side LIKE meta_query.
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			$candidate_orders = wc_get_orders(
				[
					'limit'    => 200,
					'type'     => 'shop_order',
					'meta_key' => self::META_KEY,
					'orderby'  => 'date',
					'order'    => 'DESC',
					'return'   => 'objects',
				]
			);

			foreach ( $candidate_orders as $candidate ) {
				if ( ! $candidate instanceof WC_Order ) {
					continue;
				}

				$meta = $candidate->get_meta( self::META_KEY, true );
				if ( ! is_array( $meta ) ) {
					continue;
				}

				foreach ( $meta as $shipment ) {
					if ( ! is_array( $shipment ) ) {
						continue;
					}
					$tn = isset( $shipment['tracking_number'] ) ? (string) $shipment['tracking_number'] : '';
					if ( $tn === $tracking_number ) {
						$orders = [ $candidate ];
						break 2;
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
