<?php
namespace Kitgenix\OrderTracking\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Simple per-plugin activity log for Kitgenix Order Tracking for WooCommerce.
 *
 * Records admin actions (settings saves, carrier updates, tracking number
 * changes, email sends, bulk imports/exports) to a capped WP option visible
 * in the admin Log tab.
 *
 * Storage format (per entry):
 *   [ 'time' => int, 'context' => string, 'outcome' => string, 'note' => string ]
 */
final class Event_Log {

    private const OPTION_KEY  = 'kitgenix_order_tracking_event_log';
    private const MAX_ENTRIES = 100;

    /**
     * Record an event to the log.
     *
     * @param string $context  Short slug (e.g. 'carriers-saved', 'tracking-updated').
     * @param string $outcome  'success', 'error', or any short outcome label.
     * @param string $note     Optional plain-English detail.
     */
    public static function record( string $context, string $outcome, string $note = '' ): void {
        $log   = self::get_raw_log();
        $log[] = [
            'time'    => time(),
            'context' => sanitize_text_field( $context ),
            'outcome' => sanitize_text_field( $outcome ),
            'note'    => sanitize_text_field( $note ),
        ];

        if ( count( $log ) > self::MAX_ENTRIES ) {
            $log = array_slice( $log, -self::MAX_ENTRIES );
        }

        update_option( self::OPTION_KEY, $log, false );
    }

    /**
     * Return all stored entries (oldest first).
     *
     * @return array<int,array<string,mixed>>
     */
    public static function get_raw_log(): array {
        $log = get_option( self::OPTION_KEY, [] );
        return is_array( $log ) ? $log : [];
    }

    /** Delete all stored entries. */
    public static function clear(): void {
        delete_option( self::OPTION_KEY );
    }

    /**
     * Return the log as a formatted multi-line string for the admin textarea.
     */
    public static function get_log_text(): string {
        $entries = self::get_raw_log();
        if ( empty( $entries ) ) {
            return __( 'No recent events recorded yet.', 'kitgenix-order-tracking-for-woocommerce' );
        }

        $format = (string) get_option( 'date_format' ) . ' ' . (string) get_option( 'time_format' );
        $lines  = [ '# Columns: timestamp | context | outcome | note' ];

        foreach ( array_reverse( $entries ) as $entry ) {
            $time = isset( $entry['time'] ) ? (int) $entry['time'] : 0;
            if ( function_exists( 'wp_date' ) ) {
                $when = $time ? (string) wp_date( $format, $time ) : '';
            } else {
                $when = $time ? (string) date_i18n( $format, $time ) : '';
            }

            $lines[] = sprintf(
                '%1$s | %2$s | %3$s | %4$s',
                $when ?: __( 'Unknown time', 'kitgenix-order-tracking-for-woocommerce' ),
                (string) ( $entry['context'] ?? '' ),
                (string) ( $entry['outcome'] ?? '' ),
                (string) ( $entry['note']    ?? '' )
            );
        }

        return implode( "\n", $lines );
    }
}
