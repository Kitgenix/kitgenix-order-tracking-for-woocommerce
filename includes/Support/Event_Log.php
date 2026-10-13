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
 *   [ 'time' => int, 'context' => string, 'outcome' => string, 'note' => string, 'code' => string ]
 */
final class Event_Log {

    private const OPTION_KEY  = 'kitgenix_order_tracking_event_log';
    private const MAX_ENTRIES = 100;

    private const RETENTION_OPTION_KEY   = 'kitgenix_order_tracking_log_retention_days';
    private const DEFAULT_RETENTION_DAYS = 30;
    private const ALLOWED_RETENTION_DAYS = [ 7, 14, 30 ];

    /**
     * Record an event to the log.
     *
     * @param string $context  Short slug (e.g. 'carriers-saved', 'tracking-updated').
     * @param string $outcome  'success', 'error', or any short outcome label.
     * @param string $note     Optional plain-English detail.
     * @param string $code     Optional internal event code (see get_category_and_note()).
     */
    public static function record( string $context, string $outcome, string $note = '', string $code = '' ): void {
        $log   = self::get_raw_log();
        $log[] = [
            'time'    => time(),
            'context' => sanitize_text_field( $context ),
            'outcome' => sanitize_text_field( $outcome ),
            'note'    => sanitize_text_field( $note ),
            'code'    => sanitize_text_field( $code ),
        ];

        $log = self::prune_expired( $log );

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
     * Remove stored entries older than the configured retention period.
     *
     * Shared by the on-write path in record() and the daily cron housekeeping
     * hook, so both honour the same retention setting.
     */
    public static function prune(): void {
        $log    = self::get_raw_log();
        $pruned = self::prune_expired( $log );

        if ( count( $pruned ) !== count( $log ) ) {
            update_option( self::OPTION_KEY, $pruned, false );
        }
    }

    /**
     * Filter out entries older than the configured retention period.
     *
     * @param array<int,array<string,mixed>> $log Entries to filter.
     *
     * @return array<int,array<string,mixed>>
     */
    private static function prune_expired( array $log ): array {
        $cutoff = time() - ( self::get_retention_days() * DAY_IN_SECONDS );

        return array_values(
            array_filter(
                $log,
                static function ( $entry ) use ( $cutoff ) {
                    return isset( $entry['time'] ) && (int) $entry['time'] >= $cutoff;
                }
            )
        );
    }

    /**
     * Return the configured log retention period in days (7, 14, or 30).
     */
    public static function get_retention_days(): int {
        $days = (int) get_option( self::RETENTION_OPTION_KEY, self::DEFAULT_RETENTION_DAYS );
        return in_array( $days, self::ALLOWED_RETENTION_DAYS, true ) ? $days : self::DEFAULT_RETENTION_DAYS;
    }

    /**
     * Save the log retention period in days, clamped to an allowed value.
     */
    public static function set_retention_days( int $days ): void {
        $days = in_array( $days, self::ALLOWED_RETENTION_DAYS, true ) ? $days : self::DEFAULT_RETENTION_DAYS;
        update_option( self::RETENTION_OPTION_KEY, $days, false );
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

    /**
     * Map an internal event code to a plain-English category, explanation, and
     * recommended action for a non-technical site owner.
     *
     * Distinguishes genuine problems (need action) from likely false positives
     * (non-blocking, or a routine malformed CSV row) so the Log tab can be read
     * at a glance.
     *
     * @return array{category:string,note:string,action:string}
     */
    public static function get_category_and_note( string $code ): array {
        $map = self::code_reference();

        return $map[ $code ] ?? [
            'category' => $code !== '' ? $code : __( 'Unrecognised', 'kitgenix-order-tracking-for-woocommerce' ),
            'note'     => __( 'No further detail is available for this event code.', 'kitgenix-order-tracking-for-woocommerce' ),
            'action'   => __( 'None.', 'kitgenix-order-tracking-for-woocommerce' ),
        ];
    }

    /**
     * Ordered list of all known event codes, for the settings-page reference table.
     *
     * @return array<int,string>
     */
    public static function get_known_codes(): array {
        return array_keys( self::code_reference() );
    }

    /**
     * @return array<string,array{category:string,note:string,action:string}>
     */
    private static function code_reference(): array {
        return [
            'carriers-saved'              => [
                'category' => __( 'Settings saved', 'kitgenix-order-tracking-for-woocommerce' ),
                'note'     => __( 'Carrier settings were saved from the admin settings page.', 'kitgenix-order-tracking-for-woocommerce' ),
                'action'   => __( 'None.', 'kitgenix-order-tracking-for-woocommerce' ),
            ],
            'file_unreadable'             => [
                'category' => __( 'Import file error', 'kitgenix-order-tracking-for-woocommerce' ),
                'note'     => __( 'The uploaded CSV file could not be read from the server – usually a broken upload or a file permissions issue.', 'kitgenix-order-tracking-for-woocommerce' ),
                'action'   => __( 'Likely not a system problem. Ask the user to re-upload the file; if it keeps happening, check server file permissions and disk space.', 'kitgenix-order-tracking-for-woocommerce' ),
            ],
            'file_open_failed'            => [
                'category' => __( 'Import file error', 'kitgenix-order-tracking-for-woocommerce' ),
                'note'     => __( 'The CSV file exists but could not be opened for reading.', 'kitgenix-order-tracking-for-woocommerce' ),
                'action'   => __( 'Likely a corrupted or unusually-encoded file, not a system problem. Ask for a fresh CSV export and try again.', 'kitgenix-order-tracking-for-woocommerce' ),
            ],
            'missing_headers'             => [
                'category' => __( 'Malformed CSV', 'kitgenix-order-tracking-for-woocommerce' ),
                'note'     => __( 'The CSV file has no header row, or the first row was empty.', 'kitgenix-order-tracking-for-woocommerce' ),
                'action'   => __( 'Likely a malformed CSV row, not a system problem. Add a header row (order_id, tracking_number, carrier, …) and re-upload.', 'kitgenix-order-tracking-for-woocommerce' ),
            ],
            'invalid_headers'             => [
                'category' => __( 'Malformed CSV', 'kitgenix-order-tracking-for-woocommerce' ),
                'note'     => __( 'The CSV header row is missing the required order_id and/or tracking_number columns.', 'kitgenix-order-tracking-for-woocommerce' ),
                'action'   => __( 'Likely a malformed CSV row, not a system problem. Fix the header row to include order_id and tracking_number, then re-upload.', 'kitgenix-order-tracking-for-woocommerce' ),
            ],
            'import_unexpected_exception' => [
                'category' => __( 'Unexpected import error', 'kitgenix-order-tracking-for-woocommerce' ),
                'note'     => __( 'Something beyond a malformed CSV went wrong while importing shipments – not one of the recognised file or header problems.', 'kitgenix-order-tracking-for-woocommerce' ),
                'action'   => __( 'More concerning than a malformed CSV. Check the site\'s PHP error log; if it repeats, this may be a real bug worth reporting.', 'kitgenix-order-tracking-for-woocommerce' ),
            ],
            'date_parse_failed'           => [
                'category' => __( 'Date parse skipped', 'kitgenix-order-tracking-for-woocommerce' ),
                'note'     => __( 'A shipment\'s stored date could not be parsed while building analytics charts.', 'kitgenix-order-tracking-for-woocommerce' ),
                'action'   => __( 'None – non-blocking. The shipment is still counted everywhere except date-based charts.', 'kitgenix-order-tracking-for-woocommerce' ),
            ],
            'tracking_count_skipped'      => [
                'category' => __( 'Tracking count skipped', 'kitgenix-order-tracking-for-woocommerce' ),
                'note'     => __( 'Existing tracking numbers could not be counted before a save, so the "new tracking numbers added" total was skipped for this save.', 'kitgenix-order-tracking-for-woocommerce' ),
                'action'   => __( 'None – non-blocking, the shipment save itself still succeeded.', 'kitgenix-order-tracking-for-woocommerce' ),
            ],
            'hpos_lookup_failed'          => [
                'category' => __( 'HPOS lookup fallback', 'kitgenix-order-tracking-for-woocommerce' ),
                'note'     => __( 'The plugin could not confirm whether High-Performance Order Storage is enabled, so it used the classic order-edit screen instead.', 'kitgenix-order-tracking-for-woocommerce' ),
                'action'   => __( 'None – non-blocking, the order tracking meta box still displays correctly.', 'kitgenix-order-tracking-for-woocommerce' ),
            ],
        ];
    }
}
