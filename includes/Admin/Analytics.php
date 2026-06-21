<?php
declare(strict_types=1);

namespace Kitgenix\OrderTracking\Admin;

defined('ABSPATH') || exit;

use DateTimeImmutable;
use DateTimeZone;
use Kitgenix\OrderTracking\Orders\Order_Tracking;
use Kitgenix\OrderTracking\Support\Carriers;
use Kitgenix\OrderTracking\Support\Event_Log;

use function add_action;
use function add_submenu_page;
use function add_query_arg;
use function admin_url;
use function current_user_can;
use function esc_attr;
use function esc_html;
use function esc_html__;
use function esc_url;
use function get_file_data;
use function get_option;
use function number_format_i18n;
use function sanitize_key;
use function sanitize_text_field;
use function wc_get_order_statuses;
use function wc_get_orders;
use function wp_nonce_field;
use function wp_safe_redirect;
use function wp_verify_nonce;
use function wp_date;
use function wp_enqueue_style;
use function wp_timezone;
use function set_transient;
use function get_transient;
use function wp_parse_args;
use function wp_unslash;
use function absint;
use function wc_get_order;
use function wp_die;

final class Analytics
{
    private const PAGE_SLUG = 'kitgenix-order-tracking-for-woocommerce-analytics';
    private const CAPABILITY = 'manage_woocommerce';

    /** @var string|null */
    private static $page_hook = null;

    public static function init(): void
    {
        add_action('admin_menu', [ self::class, 'register_menu' ]);
        add_action('admin_enqueue_scripts', [ self::class, 'enqueue_assets' ]);
        add_action('admin_notices', [ self::class, 'render_admin_notices' ]);
        add_action('admin_post_kitgenix_order_tracking_export_shipments', [ self::class, 'handle_export_shipments' ]);
        add_action('admin_post_kitgenix_order_tracking_import_shipments', [ self::class, 'handle_import_shipments' ]);
        add_action('admin_post_kitgenix_order_tracking_clear_event_log', [ self::class, 'handle_clear_event_log' ]);
        add_action('update_option_kitgenix_order_tracking_for_woocommerce_carriers_settings', [ self::class, 'on_carriers_saved' ], 10, 3);
    }

    public static function render_admin_notices(): void
    {
        // Only render on our analytics screen.
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $page = isset($_GET['page']) ? sanitize_key((string) wp_unslash($_GET['page'])) : '';
        if ($page !== self::PAGE_SLUG) {
            return;
        }

        if ( ! current_user_can( self::get_menu_capability() ) ) {
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $saved = isset($_GET['saved']) ? sanitize_key((string) wp_unslash($_GET['saved'])) : '';
        if ($saved === '1') {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Carrier settings saved.', 'kitgenix-order-tracking-for-woocommerce' ) . '</p></div>';
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $bulk_notice = isset($_GET['bulk_notice']) ? sanitize_key((string) wp_unslash($_GET['bulk_notice'])) : '';
        if ( '' === $bulk_notice ) {
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $orders_updated = isset($_GET['orders_updated']) ? absint( wp_unslash( $_GET['orders_updated'] ) ) : 0;
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $shipments_added = isset($_GET['shipments_added']) ? absint( wp_unslash( $_GET['shipments_added'] ) ) : 0;
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $rows_skipped = isset($_GET['rows_skipped']) ? absint( wp_unslash( $_GET['rows_skipped'] ) ) : 0;

        if ( 'imported' === $bulk_notice ) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( sprintf(
                /* translators: 1: orders updated, 2: shipments added, 3: rows skipped */
                __( 'Bulk shipment import complete. Updated %1$d orders, added %2$d shipments, and skipped %3$d rows.', 'kitgenix-order-tracking-for-woocommerce' ),
                $orders_updated,
                $shipments_added,
                $rows_skipped
            ) ) . '</p></div>';
            return;
        }

        $message = '';
        $class   = 'notice notice-error is-dismissible';

        switch ( $bulk_notice ) {
            case 'import-empty':
                $class   = 'notice notice-warning is-dismissible';
                $message = __( 'No valid shipment rows were imported. Please check that the CSV contains order_id and tracking_number values.', 'kitgenix-order-tracking-for-woocommerce' );
                break;

            case 'import-file':
                $message = __( 'Please upload a CSV file before running the bulk shipment import.', 'kitgenix-order-tracking-for-woocommerce' );
                break;

            case 'import-invalid':
                $message = __( 'The CSV file is missing required headers. Include at least order_id and tracking_number.', 'kitgenix-order-tracking-for-woocommerce' );
                break;

            case 'import-failed':
                $message = __( 'The bulk shipment import could not be completed. Please verify the CSV format and try again.', 'kitgenix-order-tracking-for-woocommerce' );
                break;
        }

        if ( '' !== $message ) {
            echo '<div class="' . esc_attr( $class ) . '"><p>' . esc_html( $message ) . '</p></div>';
        }
    }

    public static function register_menu(): void
    {
        if (function_exists('\kitgenix_ensure_admin_menu')) {
            \kitgenix_ensure_admin_menu();
        }

        $cap = self::get_menu_capability();

        self::$page_hook = add_submenu_page(
            'kitgenix',
            __( 'Order Tracking', 'kitgenix-order-tracking-for-woocommerce' ),
            __( 'Order Tracking', 'kitgenix-order-tracking-for-woocommerce' ),
            $cap,
            self::PAGE_SLUG,
            [ self::class, 'render_page' ]
        );
    }

    private static function get_menu_capability(): string
    {
        // Use WooCommerce capability when available, fall back to manage_options.
        return ( class_exists( 'WooCommerce' ) || ( function_exists( 'is_plugin_active' ) && \is_plugin_active( 'woocommerce/woocommerce.php' ) ) )
            ? 'manage_woocommerce'
            : 'manage_options';
    }

    /**
     * Enqueue admin assets for the analytics page.
     *
     * @param string $hook Current admin hook
     */
    public static function enqueue_assets(string $hook = ''): void
    {
        // Prefer checking the `page` query arg (robust across environments).
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $page = isset($_GET['page']) ? sanitize_key((string) wp_unslash($_GET['page'])) : '';
        if ($page !== self::PAGE_SLUG) {
            // If no explicit page param, allow the registered hook match as a fallback.
            if (!(self::$page_hook && $hook === self::$page_hook)) {
                return;
            }
        }

        wp_enqueue_style( 'kitgenix-admin-ui' );

        $admin_css_path = trailingslashit(KITGENIX_ORDER_TRACKING_FOR_WOOCOMMERCE_PLUGIN_DIR) . 'assets/css/admin.css';
		$admin_css_ver  = file_exists($admin_css_path) ? (string) filemtime($admin_css_path) : ( defined( 'KITGENIX_ORDER_TRACKING_FOR_WOOCOMMERCE_VERSION' ) ? (string) KITGENIX_ORDER_TRACKING_FOR_WOOCOMMERCE_VERSION : '1.0.5' );
        wp_enqueue_style(
            'kitgenix-order-tracking-admin-analytics',
            KITGENIX_ORDER_TRACKING_FOR_WOOCOMMERCE_PLUGIN_URL . 'assets/css/admin.css',
            [ 'kitgenix-admin-ui' ],
            $admin_css_ver
        );

        $admin_js_path = trailingslashit(KITGENIX_ORDER_TRACKING_FOR_WOOCOMMERCE_PLUGIN_DIR) . 'assets/js/admin-analytics.js';
        $admin_js_ver  = file_exists($admin_js_path) ? (string) filemtime($admin_js_path) : ( defined( 'KITGENIX_ORDER_TRACKING_FOR_WOOCOMMERCE_VERSION' ) ? (string) KITGENIX_ORDER_TRACKING_FOR_WOOCOMMERCE_VERSION : '1.0.5' );

        wp_enqueue_script(
            'kitgenix-order-tracking-admin-analytics',
            KITGENIX_ORDER_TRACKING_FOR_WOOCOMMERCE_PLUGIN_URL . 'assets/js/admin-analytics.js',
            [],
            $admin_js_ver,
            true
        );

        if ( function_exists( 'wp_enqueue_media' ) ) {
            wp_enqueue_media();
        }

        wp_localize_script(
            'kitgenix-order-tracking-admin-analytics',
            'kitgenixOrderTrackingAnalytics',
            [
                'labels' => [
                    'remove'       => esc_html__( 'Remove', 'kitgenix-order-tracking-for-woocommerce' ),
                    'upload'       => esc_html__( 'Upload', 'kitgenix-order-tracking-for-woocommerce' ),
                    'upload_title' => esc_html__( 'Select icon', 'kitgenix-order-tracking-for-woocommerce' ),
                    'upload_use'   => esc_html__( 'Use this icon', 'kitgenix-order-tracking-for-woocommerce' ),
                    'confirm_delete_custom_carrier' => esc_html__( 'Delete this custom shipper?', 'kitgenix-order-tracking-for-woocommerce' ),
                ],
            ]
        );

        $ui_js_path = trailingslashit(KITGENIX_ORDER_TRACKING_FOR_WOOCOMMERCE_PLUGIN_DIR) . 'assets/js/kitgenix-admin-tabs.js';
        $ui_js_ver  = file_exists($ui_js_path) ? (string) filemtime($ui_js_path) : $admin_js_ver;
        wp_enqueue_script(
            'kitgenix-admin-tabs',
            KITGENIX_ORDER_TRACKING_FOR_WOOCOMMERCE_PLUGIN_URL . 'assets/js/kitgenix-admin-tabs.js',
            [],
            $ui_js_ver,
            true
        );
    }

    public static function render_page(): void
    {
        $range_days = self::get_range_days();
        $tab        = self::get_tab();

        if ( $tab === 'carriers' ) {
            self::maybe_handle_carriers_save( $range_days );
        }

        [$from, $now] = self::get_range_dates($range_days);

        // Fetch + compute (cached). We render all panels so tabs can switch client-side.
        $data = self::get_analytics_data($from, $now, $range_days);

        echo '<div class="wrap kitgenix-admin-app kitgenix-order-tracking-for-woocommerce-admin kitgenix-order-tracking-for-woocommerce-use-top-tabs kitgenix-analytics-wrap" data-kitgenix-tabs data-kitgenix-default-tab="' . esc_attr( $tab ) . '" id="kitgenix-order-tracking-for-woocommerce-admin-app">';

        self::render_header($range_days, $from, $now);
        self::render_tabs($tab, $range_days);

        echo '<div class="kitgenix-settings-layout"><div class="kitgenix-settings-content" id="kitgenix-settings-content" tabindex="-1">';

        $hidden = ' hidden="hidden" style="display:none" aria-hidden="true"';
        $shown  = ' aria-hidden="false"';

        echo '<div class="kitgenix-analytics-tab-panels">';

        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $shown/$hidden are internal controlled attribute strings.
        echo '<div id="kitgenix-tab-overview" data-kitgenix-tab-panel="overview"' . ($tab === 'overview' ? $shown : $hidden) . '>';
        self::render_filters($range_days, 'overview', $from, $now);
        self::render_overview($data, $range_days, $from, $now);
        echo '</div>';

        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $shown/$hidden are internal controlled attribute strings.
        echo '<div id="kitgenix-tab-breakdown" data-kitgenix-tab-panel="breakdown"' . ($tab === 'breakdown' ? $shown : $hidden) . '>';
        self::render_filters($range_days, 'breakdown', $from, $now);
        self::render_breakdowns($data);
        echo '</div>';

        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $shown/$hidden are internal controlled attribute strings.
        echo '<div id="kitgenix-tab-recent" data-kitgenix-tab-panel="recent"' . ($tab === 'recent' ? $shown : $hidden) . '>';
        self::render_filters($range_days, 'recent', $from, $now);
        self::render_recent_shipments($data);
        echo '</div>';

        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $shown/$hidden are internal controlled attribute strings.
        echo '<div id="kitgenix-tab-carriers" data-kitgenix-tab-panel="carriers"' . ($tab === 'carriers' ? $shown : $hidden) . '>';
        self::render_carriers();
        echo '</div>';

        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $shown/$hidden are internal controlled attribute strings.
        echo '<div id="kitgenix-tab-bulk" data-kitgenix-tab-panel="bulk"' . ($tab === 'bulk' ? $shown : $hidden) . '>';
        self::render_bulk_tools();
        echo '</div>';

        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $shown/$hidden are internal controlled attribute strings.
        echo '<div id="kitgenix-tab-support" data-kitgenix-tab-panel="support"' . ($tab === 'support' ? $shown : $hidden) . '>';
        self::render_support();
        echo '</div>';

        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $shown/$hidden are internal controlled attribute strings.
        echo '<div id="kitgenix-tab-log" data-kitgenix-tab-panel="log"' . ($tab === 'log' ? $shown : $hidden) . '>';
        self::render_log();
        echo '</div>';

        echo '</div>';
        echo '</div>';
        self::render_sidebar();
        echo '</div>';

        echo '</div>';
    }

    private static function get_range_days(): int
    {
        // Normalize and bound the incoming `range` query arg.
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nonces are not required for harmless UI filters.
        $raw = isset($_GET['range']) ? absint( wp_unslash( $_GET['range'] ) ) : 30;
        $raw = max(7, $raw);
        return min(365, $raw);
    }

    private static function get_tab(): string
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $tab = isset($_GET['tab']) ? sanitize_key((string) wp_unslash($_GET['tab'])) : 'overview';

        // Backwards compatibility: previously explored splitting these into tabs.
        if ( $tab === 'custom-carriers' ) {
            return 'carriers';
        }

        return in_array($tab, [ 'overview', 'breakdown', 'recent', 'support', 'carriers', 'bulk', 'log' ], true) ? $tab : 'overview';
    }

    /**
     * @return array{0:DateTimeImmutable,1:DateTimeImmutable}
     */
    private static function get_range_dates(int $range_days): array
    {
        $tz = wp_timezone();
        $now  = new DateTimeImmutable('now', $tz);
        $from = $now->modify('-' . $range_days . ' days');

        return [ $from, $now ];
    }

    /**
     * Cached analytics data to keep page snappy.
     *
     * @return array<string,mixed>
     */
    private static function get_analytics_data(DateTimeImmutable $from, DateTimeImmutable $now, int $range_days): array
    {
        $cache_key = 'kitgenix_order_tracking_for_woocommerce_analytics_' . md5($from->format('Y-m-d') . '|' . $now->format('Y-m-d') . '|' . $range_days);
        $cached = get_transient($cache_key);
        if (is_array($cached)) {
            return $cached;
        }

        $orders = self::fetch_orders_batched($from);

        $data = self::compute_metrics($orders, $from, $now, $range_days);

        // 10 minutes is enough to feel “live” while preventing heavy recomputes.
        set_transient($cache_key, $data, 10 * MINUTE_IN_SECONDS);

        return $data;
    }

    /**
     * Fetch orders in batches (avoids limit=-1).
     *
     * @return array<int,\WC_Order>
     */
    private static function fetch_orders_batched(DateTimeImmutable $from): array
    {
        $statuses = array_keys(wc_get_order_statuses());

        $limit = 200;
        $page  = 1;
        $out   = [];

        while (true) {
            $args = [
                'limit'        => $limit,
                'page'         => $page,
                'type'         => 'shop_order',
                'date_created' => '>' . $from->format('Y-m-d H:i:s'),
                'status'       => $statuses,
                'return'       => 'objects',
            ];

            $batch = wc_get_orders($args);

            if (empty($batch)) {
                break;
            }

            foreach ($batch as $order) {
                $out[] = $order;
            }

            if (count($batch) < $limit) {
                break;
            }

            $page++;
            if ($page > 50) {
                // Hard safety-stop (10k orders in range is already very heavy).
                break;
            }
        }

        return $out;
    }

    /**
     * @param array<int,\WC_Order> $orders
     * @return array<string,mixed>
     */
    private static function compute_metrics(array $orders, DateTimeImmutable $from, DateTimeImmutable $now, int $range_days): array
    {
        $total_orders              = count($orders);
        $orders_with_shipments     = 0;
        $orders_without_shipments  = 0;

        $exceptions_shipments      = 0;
        $orders_with_exceptions    = [];

        $lookups = (int) get_option('kitgenix_order_tracking_for_woocommerce_lookup_count', 0);

        $total_time_to_ship_days   = 0.0;
        $time_to_ship_days         = [];

        $total_shipments           = 0;
        $shipments_by_carrier      = [];
        $shipments_by_status       = [];
        $counts_by_day             = [];
        $recent_shipments          = [];

        foreach ($orders as $order) {
            $shipments = Order_Tracking::get_shipments($order);

            if (!empty($shipments)) {
                $orders_with_shipments++;
            } else {
                $orders_without_shipments++;
            }

            $created = $order->get_date_created();
            $earliest_shipment_date = null;

            foreach ($shipments as $s) {
                $total_shipments++;

                $carrier = isset($s['carrier']) && $s['carrier'] !== ''
                    ? (string) $s['carrier']
                    : esc_html__('Unknown carrier', 'kitgenix-order-tracking-for-woocommerce');

                $status = isset($s['status']) && $s['status'] !== ''
                    ? (string) $s['status']
                    : 'shipped';

                $shipments_by_carrier[$carrier] = ($shipments_by_carrier[$carrier] ?? 0) + 1;
                $shipments_by_status[$status]   = ($shipments_by_status[$status] ?? 0) + 1;

                if (!empty($s['exception'])) {
                    $exceptions_shipments++;
                    $orders_with_exceptions[$order->get_id()] = true;
                }

                $ds = $s['created_at'] ?? ($s['date_shipped'] ?? '');
                if ($ds) {
                    try {
                        $d = new DateTimeImmutable((string) $ds, wp_timezone());

                        // Day series.
                        $date_key = $d->format('Y-m-d');
                        $counts_by_day[$date_key] = ($counts_by_day[$date_key] ?? 0) + 1;

                        if (!$earliest_shipment_date || $d < $earliest_shipment_date) {
                            $earliest_shipment_date = $d;
                        }

                        // Keep more, then slice later.
                        $recent_shipments[] = [
                            'timestamp'       => $d->getTimestamp(),
                            'date'            => $d->format('Y-m-d H:i'),
                            'order_id'        => (int) $order->get_id(),
                            'order_number'    => (string) $order->get_order_number(),
                            'carrier'         => $carrier,
                            'tracking_number' => isset($s['tracking_number']) ? (string) $s['tracking_number'] : '',
                            'status'          => $status,
                        ];
                    } catch (\Throwable $e) {
                        // ignore parse errors
                    }
                }
            }

            if ($created && $earliest_shipment_date) {
                $diff = $earliest_shipment_date->getTimestamp() - $created->getTimestamp();
                if ($diff > 0) {
                    $days = $diff / 86400.0;
                    $total_time_to_ship_days += $days;
                    $time_to_ship_days[] = $days;
                }
            }
        }

        $avg_time_to_ship = !empty($time_to_ship_days)
            ? round($total_time_to_ship_days / count($time_to_ship_days), 2)
            : 0.0;

        $median_time_to_ship = 0.0;
        if (!empty($time_to_ship_days)) {
            sort($time_to_ship_days);
            $mid = (int) floor((count($time_to_ship_days) - 1) / 2);
            if (count($time_to_ship_days) % 2) {
                $median_time_to_ship = round($time_to_ship_days[$mid], 2);
            } else {
                $median_time_to_ship = round(($time_to_ship_days[$mid] + $time_to_ship_days[$mid + 1]) / 2, 2);
            }
        }

        // Recent shipments (latest first).
        if (!empty($recent_shipments)) {
            usort($recent_shipments, static fn(array $a, array $b): int => $b['timestamp'] <=> $a['timestamp']);
            $recent_shipments = array_slice($recent_shipments, 0, 20);
        }

        // Build day series for the selected range.
        $series = [];
        $cursor = $from;
        while ($cursor <= $now) {
            $key = $cursor->format('Y-m-d');
            $series[] = (int) ($counts_by_day[$key] ?? 0);
            $cursor = $cursor->modify('+1 day');
        }

        // Sort breakdowns (desc).
        arsort($shipments_by_carrier);
        arsort($shipments_by_status);

        $orders_with_exceptions_count = count($orders_with_exceptions);

        // Derived KPIs.
        $coverage = $total_orders > 0 ? round(($orders_with_shipments / $total_orders) * 100, 1) : 0.0;
        $exception_order_rate = $total_orders > 0 ? round(($orders_with_exceptions_count / $total_orders) * 100, 1) : 0.0;

        $top_carrier = '';
        if (!empty($shipments_by_carrier)) {
            $top_carrier = (string) array_key_first($shipments_by_carrier);
        }

        return [
            'range_days'                 => $range_days,
            'total_orders'               => $total_orders,
            'orders_with_shipments'      => $orders_with_shipments,
            'orders_without_shipments'   => $orders_without_shipments,
            'coverage_percent'           => $coverage,

            'avg_time_to_ship'           => $avg_time_to_ship,
            'median_time_to_ship'        => $median_time_to_ship,

            'total_shipments'            => $total_shipments,
            'shipments_by_carrier'       => $shipments_by_carrier,
            'shipments_by_status'        => $shipments_by_status,

            'exceptions_shipments'       => $exceptions_shipments,
            'orders_with_exceptions'     => $orders_with_exceptions_count,
            'exception_order_rate'       => $exception_order_rate,

            'lookups_all_time'           => $lookups,

            'series'                     => $series,
            'recent_shipments'           => $recent_shipments,
            'top_carrier'                => $top_carrier,
        ];
    }

    private static function render_header(int $range_days, DateTimeImmutable $from, DateTimeImmutable $now): void
    {
        $plugin_version = '';
        if (defined('KITGENIX_ORDER_TRACKING_FOR_WOOCOMMERCE_PLUGIN_FILE') && function_exists('get_file_data')) {
            $data = get_file_data((string) KITGENIX_ORDER_TRACKING_FOR_WOOCOMMERCE_PLUGIN_FILE, [ 'Version' => 'Version' ], 'plugin');
            if (is_array($data) && ! empty($data['Version'])) {
                $plugin_version = (string) $data['Version'];
            }
        }
        if ($plugin_version === '') {
            $plugin_version = defined( 'KITGENIX_ORDER_TRACKING_FOR_WOOCOMMERCE_VERSION' ) ? (string) KITGENIX_ORDER_TRACKING_FOR_WOOCOMMERCE_VERSION : '1.1.1';
        }

        echo '<div class="kitgenix-order-tracking-for-woocommerce-settings-intro kitgenix-settings-header">';
        $social_base = KITGENIX_ORDER_TRACKING_FOR_WOOCOMMERCE_PLUGIN_URL . 'assets/images/social-media/';
        echo '<div class="kitgenix-settings-header-row">';

        echo '<div class="kitgenix-settings-header-main">';
        echo '<div class="kitgenix-settings-brand">';
        echo '<img class="kitgenix-settings-logo" src="' . esc_url( KITGENIX_ORDER_TRACKING_FOR_WOOCOMMERCE_PLUGIN_URL . 'assets/images/logos/kitgenix-favicon-purple.svg' ) . '" alt="' . esc_attr__( 'Kitgenix', 'kitgenix-order-tracking-for-woocommerce' ) . '" />';
        echo '<h1 class="kitgenix-order-tracking-for-woocommerce-admin-title">' . esc_html__( 'Kitgenix Order Tracking for WooCommerce', 'kitgenix-order-tracking-for-woocommerce' ) . '</h1>';
        echo '</div>';
        echo '<p>' . esc_html__( 'Add multiple tracking numbers and carriers to WooCommerce orders, show a tracking page, and include tracking in emails.', 'kitgenix-order-tracking-for-woocommerce' ) . '</p>';
        echo '<div class="kitgenix-settings-meta">';
        echo '<span class="kitgenix-settings-version" aria-label="Plugin version">v' . esc_html( $plugin_version ) . '</span>';
        echo '</div>';
        echo '</div>';

        echo '<div class="kitgenix-settings-header-actions">';
        echo '<div class="kitgenix-intro-links kitgenix-order-tracking-for-woocommerce-intro-links">';
        echo '<a href="' . esc_url( 'https://kitgenix.com/plugins/kitgenix-order-tracking-for-woocommerce/documentation/' ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Documentation', 'kitgenix-order-tracking-for-woocommerce' ) . '</a>';
        echo '<a href="' . esc_url( 'https://wordpress.org/support/plugin/kitgenix-order-tracking-for-woocommerce/reviews/#new-post' ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Review Plugin', 'kitgenix-order-tracking-for-woocommerce' ) . '</a>';
        echo '<a href="' . esc_url( 'https://wordpress.org/support/plugin/kitgenix-order-tracking-for-woocommerce/' ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Support Request', 'kitgenix-order-tracking-for-woocommerce' ) . '</a>';
        echo '<a href="' . esc_url( 'https://buymeacoffee.com/kitgenix' ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Support Kitgenix', 'kitgenix-order-tracking-for-woocommerce' ) . '</a>';
        echo '</div>';

        if ( $social_base ) :
            echo '<div class="kitgenix-social-links kitgenix-social-links--icons">';
            echo '<a href="https://kitgenix.com" target="_blank" rel="noopener noreferrer" aria-label="Website" title="Website"><img src="' . esc_url( $social_base . 'globe-solid.svg' ) . '" alt="" width="13" height="13" aria-hidden="true" /><span class="screen-reader-text">Website</span></a>';
            echo '<a href="https://www.facebook.com/groups/kitgenix" target="_blank" rel="noopener noreferrer" aria-label="Facebook Community" title="Facebook Community"><img src="' . esc_url( $social_base . 'facebook-solid.svg' ) . '" alt="" width="13" height="13" aria-hidden="true" /><span class="screen-reader-text">Facebook Community</span></a>';
            echo '<a href="https://www.facebook.com/kitgenix" target="_blank" rel="noopener noreferrer" aria-label="Facebook" title="Facebook"><img src="' . esc_url( $social_base . 'facebook-solid.svg' ) . '" alt="" width="13" height="13" aria-hidden="true" /><span class="screen-reader-text">Facebook</span></a>';
            echo '<a href="https://www.instagram.com/kitgenix/" target="_blank" rel="noopener noreferrer" aria-label="Instagram" title="Instagram"><img src="' . esc_url( $social_base . 'instagram-solid.svg' ) . '" alt="" width="13" height="13" aria-hidden="true" /><span class="screen-reader-text">Instagram</span></a>';
            echo '<a href="https://www.youtube.com/@Kitgenix" target="_blank" rel="noopener noreferrer" aria-label="YouTube" title="YouTube"><img src="' . esc_url( $social_base . 'youtube-solid.svg' ) . '" alt="" width="13" height="13" aria-hidden="true" /><span class="screen-reader-text">YouTube</span></a>';
            echo '<a href="https://www.reddit.com/r/Kitgenix/" target="_blank" rel="noopener noreferrer" aria-label="Reddit" title="Reddit"><img src="' . esc_url( $social_base . 'reddit-solid.svg' ) . '" alt="" width="13" height="13" aria-hidden="true" /><span class="screen-reader-text">Reddit</span></a>';
            echo '<a href="https://www.linkedin.com/company/kitgenix" target="_blank" rel="noopener noreferrer" aria-label="LinkedIn" title="LinkedIn"><img src="' . esc_url( $social_base . 'linkedin-solid.svg' ) . '" alt="" width="13" height="13" aria-hidden="true" /><span class="screen-reader-text">LinkedIn</span></a>';
            echo '<a href="https://x.com/kitgenix" target="_blank" rel="noopener noreferrer" aria-label="X" title="X"><img src="' . esc_url( $social_base . 'x-solid.svg' ) . '" alt="" width="13" height="13" aria-hidden="true" /><span class="screen-reader-text">X</span></a>';
            echo '<a href="https://www.tiktok.com/@kitgenix" target="_blank" rel="noopener noreferrer" aria-label="TikTok" title="TikTok"><img src="' . esc_url( $social_base . 'tiktok-solid.svg' ) . '" alt="" width="13" height="13" aria-hidden="true" /><span class="screen-reader-text">TikTok</span></a>';
            echo '<a href="https://github.com/kitgenix" target="_blank" rel="noopener noreferrer" aria-label="GitHub" title="GitHub"><img src="' . esc_url( $social_base . 'github-solid.svg' ) . '" alt="" width="13" height="13" aria-hidden="true" /><span class="screen-reader-text">GitHub</span></a>';
            echo '</div>';
        endif;

        echo '</div>';
        echo '</div>';
        echo '</div>';
    }

    private static function render_tabs(string $active, int $range_days): void
    {
        $tabs = [
            'overview'  => esc_html__('Overview', 'kitgenix-order-tracking-for-woocommerce'),
            'breakdown' => esc_html__('Breakdown', 'kitgenix-order-tracking-for-woocommerce'),
            'recent'    => esc_html__('Recent', 'kitgenix-order-tracking-for-woocommerce'),
            'carriers'  => esc_html__('Carriers', 'kitgenix-order-tracking-for-woocommerce'),
            'bulk'      => esc_html__('Bulk Tools', 'kitgenix-order-tracking-for-woocommerce'),
            'support'   => esc_html__('Support', 'kitgenix-order-tracking-for-woocommerce'),
            'log'       => esc_html__('Log', 'kitgenix-order-tracking-for-woocommerce'),
        ];

        echo '<h2 class="nav-tab-wrapper kitgenix-nav-tabs" aria-label="Settings navigation">';
        foreach ($tabs as $key => $label) {
            $url = add_query_arg([
                'page'  => self::PAGE_SLUG,
                'range' => (string) $range_days,
                'tab'   => $key,
            ], admin_url('admin.php'));

            $url  .= '#kitgenix-tab-' . $key;
            $class = 'nav-tab kitgenix-tab-trigger' . ($key === $active ? ' nav-tab-active' : '');
            echo '<a class="' . esc_attr($class) . '" data-kitgenix-tab="' . esc_attr($key) . '" href="' . esc_url($url) . '"' . ($key === $active ? ' aria-current="page"' : '') . '>' . esc_html($label) . '</a>';
        }
        echo '</h2>';
    }

    private static function render_bulk_tools(): void
    {
        $csv_columns = [
            'order_id',
            'order_number',
            'shipment_id',
            'carrier',
            'tracking_number',
            'date_shipped',
            'status',
            'item_quantities',
        ];

        echo '<div class="kitgenix-analytics-mt-14">';

        echo '<div class="kitgenix-analytics-panel">';
        echo '<h3>' . esc_html__( 'Bulk shipment export', 'kitgenix-order-tracking-for-woocommerce' ) . '</h3>';
        echo '<p class="description">' . esc_html__( 'Download a CSV of every tracked shipment in WooCommerce. The export includes order IDs, tracking numbers, carrier keys, shipment dates, statuses, and any per-item shipment allocations.', 'kitgenix-order-tracking-for-woocommerce' ) . '</p>';
        echo '<p><strong>' . esc_html__( 'CSV columns:', 'kitgenix-order-tracking-for-woocommerce' ) . '</strong> <code>' . esc_html( implode( ', ', $csv_columns ) ) . '</code></p>';
        echo '<p class="description">' . esc_html__( 'Each row represents one shipment. The item_quantities column uses the format item_id:qty|item_id:qty so exported files can be edited and imported again.', 'kitgenix-order-tracking-for-woocommerce' ) . '</p>';
        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
        echo '<input type="hidden" name="action" value="kitgenix_order_tracking_export_shipments" />';
        wp_nonce_field( 'kitgenix_order_tracking_export_shipments' );
        echo '<p><button type="submit" class="button button-primary">' . esc_html__( 'Export shipments CSV', 'kitgenix-order-tracking-for-woocommerce' ) . '</button></p>';
        echo '</form>';
        echo '</div>';

        echo '<div class="kitgenix-analytics-panel">';
        echo '<h3>' . esc_html__( 'Bulk shipment import', 'kitgenix-order-tracking-for-woocommerce' ) . '</h3>';
        echo '<p class="description">' . esc_html__( 'Upload a CSV to add or replace shipment data on multiple orders at once. This is designed for spreadsheet-based fulfilment workflows and works best when you start from the plugin’s export file.', 'kitgenix-order-tracking-for-woocommerce' ) . '</p>';
        echo '<ul class="ul-disc">';
        echo '<li>' . esc_html__( 'order_id and tracking_number are required.', 'kitgenix-order-tracking-for-woocommerce' ) . '</li>';
        echo '<li>' . esc_html__( 'Use append mode to add new shipments without touching existing ones.', 'kitgenix-order-tracking-for-woocommerce' ) . '</li>';
        echo '<li>' . esc_html__( 'Use replace mode to overwrite all shipments on each order included in the CSV.', 'kitgenix-order-tracking-for-woocommerce' ) . '</li>';
        echo '<li>' . esc_html__( 'item_quantities is optional and should use item_id:qty|item_id:qty.', 'kitgenix-order-tracking-for-woocommerce' ) . '</li>';
        echo '</ul>';
        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" enctype="multipart/form-data">';
        echo '<input type="hidden" name="action" value="kitgenix_order_tracking_import_shipments" />';
        wp_nonce_field( 'kitgenix_order_tracking_import_shipments' );
        echo '<table class="form-table" role="presentation"><tbody>';
        echo '<tr>';
        echo '<th scope="row"><label for="kitgenix-order-tracking-import-file">' . esc_html__( 'CSV file', 'kitgenix-order-tracking-for-woocommerce' ) . '</label></th>';
        echo '<td><input id="kitgenix-order-tracking-import-file" type="file" name="shipments_csv" accept=".csv,text/csv" required /></td>';
        echo '</tr>';
        echo '<tr>';
        echo '<th scope="row"><label for="kitgenix-order-tracking-import-mode">' . esc_html__( 'Import mode', 'kitgenix-order-tracking-for-woocommerce' ) . '</label></th>';
        echo '<td>';
        echo '<select id="kitgenix-order-tracking-import-mode" name="mode">';
        echo '<option value="append">' . esc_html__( 'Append new shipments', 'kitgenix-order-tracking-for-woocommerce' ) . '</option>';
        echo '<option value="replace">' . esc_html__( 'Replace shipments on matched orders', 'kitgenix-order-tracking-for-woocommerce' ) . '</option>';
        echo '</select>';
        echo '<p class="description">' . esc_html__( 'Append skips tracking numbers already stored on the order. Replace overwrites the matched orders with the shipments present in the CSV.', 'kitgenix-order-tracking-for-woocommerce' ) . '</p>';
        echo '</td>';
        echo '</tr>';
        echo '</tbody></table>';
        echo '<p><button type="submit" class="button button-primary">' . esc_html__( 'Import shipments CSV', 'kitgenix-order-tracking-for-woocommerce' ) . '</button></p>';
        echo '</form>';
        echo '</div>';

        echo '</div>';
    }

    public static function handle_export_shipments(): void
    {
        if ( ! current_user_can( self::get_menu_capability() ) ) {
            wp_die( esc_html__( 'You do not have permission to export shipment data.', 'kitgenix-order-tracking-for-woocommerce' ) );
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified immediately below.
        $nonce = isset( $_POST['_wpnonce'] ) ? sanitize_text_field( (string) wp_unslash( $_POST['_wpnonce'] ) ) : '';
        if ( ! wp_verify_nonce( $nonce, 'kitgenix_order_tracking_export_shipments' ) ) {
            wp_die( esc_html__( 'The export request could not be verified. Please try again.', 'kitgenix-order-tracking-for-woocommerce' ) );
        }

        try {
            $handle = new \SplFileObject( 'php://output', 'w' );
        } catch ( \RuntimeException $exception ) {
            wp_die( esc_html__( 'Unable to open the export stream.', 'kitgenix-order-tracking-for-woocommerce' ) );
        }

        nocache_headers();
        header( 'Content-Type: text/csv; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename=' . self::get_bulk_export_filename() );

        echo "\xEF\xBB\xBF";

        $handle->fputcsv(
            [
                'order_id',
                'order_number',
                'shipment_id',
                'carrier',
                'carrier_label',
                'tracking_number',
                'tracking_url',
                'date_shipped',
                'status',
                'item_quantities',
            ]
        );

        foreach ( self::fetch_orders_for_bulk_export() as $order ) {
            $shipments = Order_Tracking::get_shipments( $order );
            if ( empty( $shipments ) ) {
                continue;
            }

            foreach ( $shipments as $shipment ) {
                $carrier = isset( $shipment['carrier'] ) ? (string) $shipment['carrier'] : '';

                $handle->fputcsv(
                    [
                        (string) $order->get_id(),
                        (string) $order->get_order_number(),
                        isset( $shipment['id'] ) ? (string) $shipment['id'] : '',
                        $carrier,
                        $carrier !== '' ? Carriers::get_label( $carrier ) : '',
                        isset( $shipment['tracking_number'] ) ? (string) $shipment['tracking_number'] : '',
                        isset( $shipment['tracking_url'] ) ? (string) $shipment['tracking_url'] : '',
                        isset( $shipment['created_at'] ) ? (string) $shipment['created_at'] : '',
                        isset( $shipment['status'] ) ? (string) $shipment['status'] : 'shipped',
                        self::format_bulk_item_quantities( isset( $shipment['items'] ) && is_array( $shipment['items'] ) ? $shipment['items'] : [] ),
                    ]
                );
            }
        }

        exit;
    }

    public static function handle_import_shipments(): void
    {
        if ( ! current_user_can( self::get_menu_capability() ) ) {
            wp_die( esc_html__( 'You do not have permission to import shipment data.', 'kitgenix-order-tracking-for-woocommerce' ) );
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified immediately below.
        $nonce = isset( $_POST['_wpnonce'] ) ? sanitize_text_field( (string) wp_unslash( $_POST['_wpnonce'] ) ) : '';
        if ( ! wp_verify_nonce( $nonce, 'kitgenix_order_tracking_import_shipments' ) ) {
            wp_die( esc_html__( 'The import request could not be verified. Please try again.', 'kitgenix-order-tracking-for-woocommerce' ) );
        }

        $redirect_url = self::get_bulk_tools_url();

        // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- File upload payload is validated via upload error and tmp_name checks below.
        $file = $_FILES['shipments_csv'] ?? null;
        if ( ! is_array( $file ) || ! isset( $file['error'] ) ) {
            wp_safe_redirect( add_query_arg( [ 'bulk_notice' => 'import-file' ], $redirect_url ) );
            exit;
        }

        $upload_error = (int) $file['error'];
        if ( $upload_error !== UPLOAD_ERR_OK || empty( $file['tmp_name'] ) ) {
            $notice = $upload_error === UPLOAD_ERR_NO_FILE ? 'import-file' : 'import-failed';
            wp_safe_redirect( add_query_arg( [ 'bulk_notice' => $notice ], $redirect_url ) );
            exit;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- sanitized immediately below.
        $mode = isset( $_POST['mode'] ) ? sanitize_key( (string) wp_unslash( $_POST['mode'] ) ) : 'append';
        if ( ! in_array( $mode, [ 'append', 'replace' ], true ) ) {
            $mode = 'append';
        }

        try {
            $result = self::import_shipments_from_csv( (string) $file['tmp_name'], $mode );

            $notice = ( $result['orders_updated'] > 0 || $result['shipments_added'] > 0 ) ? 'imported' : 'import-empty';
            $redirect_url = add_query_arg(
                [
                    'bulk_notice'     => $notice,
                    'orders_updated'  => (string) $result['orders_updated'],
                    'shipments_added' => (string) $result['shipments_added'],
                    'rows_skipped'    => (string) $result['rows_skipped'],
                ],
                $redirect_url
            );
        } catch ( \InvalidArgumentException $exception ) {
            $redirect_url = add_query_arg( [ 'bulk_notice' => 'import-invalid' ], $redirect_url );
        } catch ( \Throwable $exception ) {
            $redirect_url = add_query_arg( [ 'bulk_notice' => 'import-failed' ], $redirect_url );
        }

        wp_safe_redirect( $redirect_url );
        exit;
    }

    /**
     * @return array{orders_updated:int,shipments_added:int,rows_skipped:int}
     */
    private static function import_shipments_from_csv( string $file_path, string $mode ): array
    {
        if ( ! is_readable( $file_path ) ) {
            throw new \RuntimeException( 'file_unreadable' );
        }

        $delimiter = self::detect_csv_delimiter( $file_path );
        try {
            $handle = new \SplFileObject( $file_path, 'r' );
        } catch ( \RuntimeException $exception ) {
            throw new \RuntimeException( 'file_open_failed' );
        }

        $handle->setCsvControl( $delimiter );
        $handle->setFlags( \SplFileObject::READ_CSV | \SplFileObject::SKIP_EMPTY );

        $header_row = $handle->fgetcsv();
        if ( ! is_array( $header_row ) || empty( $header_row ) ) {
            throw new \InvalidArgumentException( 'missing_headers' );
        }

        $headers = array_map( [ self::class, 'normalize_bulk_csv_header' ], $header_row );
        if ( ! in_array( 'order_id', $headers, true ) || ! in_array( 'tracking_number', $headers, true ) ) {
            throw new \InvalidArgumentException( 'invalid_headers' );
        }

        $orders_by_id    = [];
        $imports_by_order = [];
        $seen_rows       = [];
        $rows_skipped    = 0;

        while ( ! $handle->eof() ) {
            $row = $handle->fgetcsv();
            if ( false === $row || [ null ] === $row ) {
                continue;
            }

            if ( self::is_bulk_csv_row_empty( $row ) ) {
                continue;
            }

            $record = self::combine_bulk_csv_row( $headers, $row );
            $order  = self::resolve_bulk_import_order( $record );
            if ( ! $order instanceof \WC_Order ) {
                $rows_skipped++;
                continue;
            }

            $shipment = self::build_bulk_import_shipment( $record );
            if ( null === $shipment ) {
                $rows_skipped++;
                continue;
            }

            $order_id     = (int) $order->get_id();
            $tracking_key = strtolower( (string) $shipment['tracking_number'] );
            if ( isset( $seen_rows[ $order_id ][ $tracking_key ] ) ) {
                $rows_skipped++;
                continue;
            }

            $seen_rows[ $order_id ][ $tracking_key ] = true;
            $orders_by_id[ $order_id ]               = $order;
            $imports_by_order[ $order_id ][]         = $shipment;
        }

        $orders_updated  = 0;
        $shipments_added = 0;

        foreach ( $imports_by_order as $order_id => $incoming_shipments ) {
            $order = $orders_by_id[ $order_id ] ?? null;
            if ( ! $order instanceof \WC_Order ) {
                $rows_skipped += count( $incoming_shipments );
                continue;
            }

            if ( 'replace' === $mode ) {
                Order_Tracking::save_shipments( $order, $incoming_shipments );
                $orders_updated++;
                $shipments_added += count( $incoming_shipments );
                continue;
            }

            $existing_shipments = Order_Tracking::get_shipments( $order );
            $existing_numbers   = [];

            foreach ( $existing_shipments as $existing_shipment ) {
                if ( ! empty( $existing_shipment['tracking_number'] ) ) {
                    $existing_numbers[ strtolower( (string) $existing_shipment['tracking_number'] ) ] = true;
                }
            }

            $shipments_to_save = $existing_shipments;
            $added_for_order   = 0;

            foreach ( $incoming_shipments as $incoming_shipment ) {
                $tracking_key = strtolower( (string) $incoming_shipment['tracking_number'] );
                if ( isset( $existing_numbers[ $tracking_key ] ) ) {
                    $rows_skipped++;
                    continue;
                }

                $shipments_to_save[]               = $incoming_shipment;
                $existing_numbers[ $tracking_key ] = true;
                $added_for_order++;
            }

            if ( $added_for_order < 1 ) {
                continue;
            }

            Order_Tracking::save_shipments( $order, $shipments_to_save );
            $orders_updated++;
            $shipments_added += $added_for_order;
        }

        return [
            'orders_updated'  => $orders_updated,
            'shipments_added' => $shipments_added,
            'rows_skipped'    => $rows_skipped,
        ];
    }

    /**
     * @param array<string,string> $record
     */
    private static function resolve_bulk_import_order( array $record ): ?\WC_Order
    {
        $order_id = isset( $record['order_id'] ) ? absint( $record['order_id'] ) : 0;
        if ( $order_id > 0 ) {
            $order = wc_get_order( $order_id );
            return $order instanceof \WC_Order ? $order : null;
        }

        $order_number = isset( $record['order_number'] ) ? trim( (string) $record['order_number'] ) : '';
        if ( '' === $order_number ) {
            return null;
        }

        $order_number = ltrim( $order_number, "# \t\n\r\0\x0B" );
        if ( ! ctype_digit( $order_number ) ) {
            return null;
        }

        $order = wc_get_order( absint( $order_number ) );
        return $order instanceof \WC_Order ? $order : null;
    }

    /**
     * @param array<string,string> $record
     * @return array<string,mixed>|null
     */
    private static function build_bulk_import_shipment( array $record ): ?array
    {
        $tracking_number = isset( $record['tracking_number'] ) ? sanitize_text_field( (string) $record['tracking_number'] ) : '';
        if ( '' === $tracking_number ) {
            return null;
        }

        $date_shipped = '';
        if ( isset( $record['date_shipped'] ) ) {
            $date_shipped = sanitize_text_field( (string) $record['date_shipped'] );
        } elseif ( isset( $record['created_at'] ) ) {
            $date_shipped = sanitize_text_field( (string) $record['created_at'] );
        }

        return [
            'id'              => ! empty( $record['shipment_id'] ) ? sanitize_text_field( (string) $record['shipment_id'] ) : wp_generate_uuid4(),
            'carrier'         => isset( $record['carrier'] ) ? sanitize_text_field( (string) $record['carrier'] ) : '',
            'tracking_number' => $tracking_number,
            'date_shipped'    => $date_shipped,
            'status'          => ! empty( $record['status'] ) ? sanitize_text_field( (string) $record['status'] ) : 'shipped',
            'items'           => self::parse_bulk_item_quantities( isset( $record['item_quantities'] ) ? (string) $record['item_quantities'] : '' ),
        ];
    }

    /**
     * @param array<int,string|null> $row
     * @return array<string,string>
     */
    private static function combine_bulk_csv_row( array $headers, array $row ): array
    {
        $values = array_pad( $row, count( $headers ), '' );
        $record = [];

        foreach ( $headers as $index => $header ) {
            $record[ $header ] = isset( $values[ $index ] ) ? trim( (string) $values[ $index ] ) : '';
        }

        return $record;
    }

    /**
     * @param array<int,string|null> $row
     */
    private static function is_bulk_csv_row_empty( array $row ): bool
    {
        foreach ( $row as $value ) {
            if ( trim( (string) $value ) !== '' ) {
                return false;
            }
        }

        return true;
    }

    private static function normalize_bulk_csv_header( string $header ): string
    {
        $header = preg_replace( '/^\xEF\xBB\xBF/', '', trim( $header ) ) ?: trim( $header );
        $header = strtolower( $header );
        $header = str_replace( [ ' ', '-' ], '_', $header );

        return trim( $header, '_' );
    }

    private static function detect_csv_delimiter( string $file_path ): string
    {
        try {
            $handle = new \SplFileObject( $file_path, 'r' );
        } catch ( \RuntimeException $exception ) {
            return ',';
        }

        $line = $handle->fgets();

        if ( ! is_string( $line ) || '' === $line ) {
            return ',';
        }

        $delimiters = [ ',', ';', "\t" ];
        $best       = ',';
        $best_count = -1;

        foreach ( $delimiters as $delimiter ) {
            $count = substr_count( $line, $delimiter );
            if ( $count > $best_count ) {
                $best       = $delimiter;
                $best_count = $count;
            }
        }

        return $best;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private static function parse_bulk_item_quantities( string $value ): array
    {
        $value = trim( $value );
        if ( '' === $value ) {
            return [];
        }

        $decoded = json_decode( $value, true );
        if ( is_array( $decoded ) ) {
            $items = [];

            foreach ( $decoded as $key => $item ) {
                if ( is_array( $item ) ) {
                    $item_id = isset( $item['item_id'] ) ? absint( $item['item_id'] ) : absint( $key );
                    $qty     = isset( $item['qty'] ) ? (float) $item['qty'] : 0.0;
                } else {
                    $item_id = absint( $key );
                    $qty     = (float) $item;
                }

                if ( $item_id > 0 && $qty > 0 ) {
                    $items[] = [
                        'item_id' => $item_id,
                        'qty'     => $qty,
                    ];
                }
            }

            return $items;
        }

        $items = [];
        foreach ( explode( '|', $value ) as $segment ) {
            $segment = trim( $segment );
            if ( '' === $segment || strpos( $segment, ':' ) === false ) {
                continue;
            }

            [ $item_id_raw, $qty_raw ] = array_map( 'trim', explode( ':', $segment, 2 ) );
            $item_id = absint( $item_id_raw );
            $qty     = (float) $qty_raw;

            if ( $item_id > 0 && $qty > 0 ) {
                $items[] = [
                    'item_id' => $item_id,
                    'qty'     => $qty,
                ];
            }
        }

        return $items;
    }

    /**
     * @param array<int,array<string,mixed>> $items
     */
    private static function format_bulk_item_quantities( array $items ): string
    {
        $parts = [];

        foreach ( $items as $item ) {
            $item_id = isset( $item['item_id'] ) ? absint( $item['item_id'] ) : 0;
            $qty     = isset( $item['qty'] ) ? (float) $item['qty'] : 0.0;

            if ( $item_id < 1 || $qty <= 0 ) {
                continue;
            }

            $parts[] = $item_id . ':' . self::format_bulk_quantity( $qty );
        }

        return implode( '|', $parts );
    }

    private static function format_bulk_quantity( float $qty ): string
    {
        if ( (float) (int) $qty === $qty ) {
            return (string) (int) $qty;
        }

        $formatted = rtrim( rtrim( sprintf( '%.4F', $qty ), '0' ), '.' );

        return $formatted !== '' ? $formatted : '0';
    }

    /**
     * @return array<int,\WC_Order>
     */
    private static function fetch_orders_for_bulk_export(): array
    {
        $statuses = array_keys( wc_get_order_statuses() );
        $limit    = 200;
        $page     = 1;
        $orders   = [];

        while ( true ) {
            $batch = wc_get_orders(
                [
                    'limit'  => $limit,
                    'page'   => $page,
                    'type'   => 'shop_order',
                    'status' => $statuses,
                    'return' => 'objects',
                ]
            );

            if ( empty( $batch ) ) {
                break;
            }

            foreach ( $batch as $order ) {
                $orders[] = $order;
            }

            if ( count( $batch ) < $limit || $page >= 100 ) {
                break;
            }

            $page++;
        }

        return $orders;
    }

    private static function get_bulk_export_filename(): string
    {
        return 'kitgenix-order-tracking-shipments-' . gmdate( 'Y-m-d-His' ) . '.csv';
    }

    private static function get_bulk_tools_url(): string
    {
        return add_query_arg(
            [
                'page' => self::PAGE_SLUG,
                'tab'  => 'bulk',
            ],
            admin_url( 'admin.php' )
        );
    }

    private static function render_filters(int $range_days, string $tab, DateTimeImmutable $from, DateTimeImmutable $now): void
    {
        $refresh_url = add_query_arg([
            'page'  => self::PAGE_SLUG,
            'range' => (string) $range_days,
            'tab'   => $tab,
        ], admin_url('admin.php'));

        echo '<form method="get" class="kitgenix-analytics-filters">';
        echo '<input type="hidden" name="page" value="' . esc_attr(self::PAGE_SLUG) . '" />';
        echo '<input type="hidden" name="tab" value="' . esc_attr($tab) . '" />';

        echo '<label>';
        echo '<span>' . esc_html__('Date range (days):', 'kitgenix-order-tracking-for-woocommerce') . '</span>';
        echo '<input type="number" name="range" min="7" max="365" value="' . esc_attr((string) $range_days) . '" class="small-text" />';
        echo '</label>';

        echo '<button class="button button-primary" type="submit">' . esc_html__('Apply', 'kitgenix-order-tracking-for-woocommerce') . '</button>';

        $presets = [ 7, 30, 90, 180, 365 ];
        echo '<span class="kitgenix-analytics-presets">';
        echo '<span>' . esc_html__('Quick ranges:', 'kitgenix-order-tracking-for-woocommerce') . '</span>';

        foreach ($presets as $preset) {
            $class = ($preset === $range_days) ? 'button button-primary' : 'button button-secondary';
            $url = add_query_arg([
                'page'  => self::PAGE_SLUG,
                'tab'   => $tab,
                'range' => (string) $preset,
            ], admin_url('admin.php'));

            echo '<a class="' . esc_attr($class) . '" href="' . esc_url($url) . '">';
            /* translators: %d: number of days in the preset range */
            echo esc_html(sprintf(_n('%d day', '%d days', $preset, 'kitgenix-order-tracking-for-woocommerce'), $preset));
            echo '</a>';
        }

        echo '</span>';

        echo '<div class="kitgenix-settings-meta kitgenix-analytics-filters-meta">';
        echo '<span class="kitgenix-analytics-header__meta">' . esc_html( sprintf(
            /* translators: 1: days, 2: from date, 3: to date */
            __( 'Showing last %1$d days (%2$s – %3$s).', 'kitgenix-order-tracking-for-woocommerce' ),
            $range_days,
            $from->format( 'M j' ),
            $now->format( 'M j' )
        ) ) . '</span>';
        echo '<a class="button" href="' . esc_url( $refresh_url ) . '">' . esc_html__( 'Refresh', 'kitgenix-order-tracking-for-woocommerce' ) . '</a>';
        echo '</div>';

        echo '</form>';
    }

    private static function render_support(): void
    {
        $social_base = defined( 'KITGENIX_ORDER_TRACKING_FOR_WOOCOMMERCE_PLUGIN_URL' )
            ? (string) KITGENIX_ORDER_TRACKING_FOR_WOOCOMMERCE_PLUGIN_URL . 'assets/images/social-media/'
            : '';

        $lookups = (int) get_option('kitgenix_order_tracking_for_woocommerce_lookup_count', 0);
        $added_tracking_numbers = (int) get_option('kitgenix_order_tracking_for_woocommerce_tracking_numbers_added', 0);
        $donate_once_url = 'https://buymeacoffee.com/kitgenix';
        $monthly_support_url = 'https://buymeacoffee.com/kitgenix/membership';
        $plugin_page_url = 'https://kitgenix.com/plugins/kitgenix-order-tracking-for-woocommerce/';
        $review_url = 'https://wordpress.org/support/plugin/kitgenix-order-tracking-for-woocommerce/reviews/#new-post';
        $support_request_url = 'https://kitgenix.com/plugins/kitgenix-order-tracking-for-woocommerce/support';
        $copy_onclick = "if(window.navigator&&navigator.clipboard&&window.isSecureContext){navigator.clipboard.writeText(" . wp_json_encode( $plugin_page_url ) . ");}else{window.prompt(" . wp_json_encode( __( 'Copy plugin link:', 'kitgenix-order-tracking-for-woocommerce' ) ) . ", " . wp_json_encode( $plugin_page_url ) . ");}return false;";
        $monthly_options = [
            [ 'label' => __( 'Kitgenix Supporter (£4/month)', 'kitgenix-order-tracking-for-woocommerce' ), 'url' => 'https://buymeacoffee.com/kitgenix/membership' ],
            [ 'label' => __( 'Kitgenix Plus (£8/month)', 'kitgenix-order-tracking-for-woocommerce' ), 'url' => 'https://buymeacoffee.com/kitgenix/membership' ],
            [ 'label' => __( 'Kitgenix Pro Supporter (£19/month)', 'kitgenix-order-tracking-for-woocommerce' ), 'url' => 'https://buymeacoffee.com/kitgenix/membership' ],
            [ 'label' => __( 'Kitgenix Agency (£37/month)', 'kitgenix-order-tracking-for-woocommerce' ), 'url' => 'https://buymeacoffee.com/kitgenix/membership' ],
            [ 'label' => __( 'Kitgenix Partner (£75/month)', 'kitgenix-order-tracking-for-woocommerce' ), 'url' => 'https://buymeacoffee.com/kitgenix/membership' ],
            [ 'label' => __( 'Kitgenix YouTube Sponsor (£730/month)', 'kitgenix-order-tracking-for-woocommerce' ), 'url' => 'https://buymeacoffee.com/kitgenix/membership' ],
        ];

        $impact_cards = [
            [
                'label' => __( 'Tracking numbers added', 'kitgenix-order-tracking-for-woocommerce' ),
                'value' => number_format_i18n( $added_tracking_numbers ),
                'meta'  => __( 'Orders already enriched with shipment tracking data.', 'kitgenix-order-tracking-for-woocommerce' ),
            ],
            [
                'label' => __( 'Customer lookups', 'kitgenix-order-tracking-for-woocommerce' ),
                'value' => number_format_i18n( $lookups ),
                'meta'  => __( 'Self-serve tracking checks completed by customers.', 'kitgenix-order-tracking-for-woocommerce' ),
            ],
        ];
        $meaning_points = [
            __( 'Tracking numbers are already being attached to orders and used by customers after checkout.', 'kitgenix-order-tracking-for-woocommerce' ),
            __( 'Lookup totals show the plugin is actively reducing manual tracking requests.', 'kitgenix-order-tracking-for-woocommerce' ),
            __( 'Each successful self-serve lookup can mean fewer support tickets for your store.', 'kitgenix-order-tracking-for-woocommerce' ),
        ];
        $support_points = [
            __( 'Compatibility updates for new WordPress / WooCommerce releases', 'kitgenix-order-tracking-for-woocommerce' ),
            __( 'Bug fixes, edge-case testing, and better carrier and tracking coverage', 'kitgenix-order-tracking-for-woocommerce' ),
            __( 'Security hardening and ongoing performance improvements', 'kitgenix-order-tracking-for-woocommerce' ),
            __( 'Documentation upgrades and faster, clearer support responses', 'kitgenix-order-tracking-for-woocommerce' ),
        ];
        $trust_points = [
            __( 'No paid features locked behind donations', 'kitgenix-order-tracking-for-woocommerce' ),
            __( 'No tracking or invasive upsells', 'kitgenix-order-tracking-for-woocommerce' ),
            __( 'Support is always optional, and genuinely appreciated.', 'kitgenix-order-tracking-for-woocommerce' ),
        ];

        echo '<div class="kitgenix-analytics-mt-14">';
        echo '<div class="kitgenix-analytics-panel kitgenix-order-tracking-for-woocommerce-support-page kitgenix-support-page">';
        ?>
        <div class="kitgenix-support-shell">
            <section class="kitgenix-support-hero">
                <div class="kitgenix-support-hero__copy">
                    <span class="kitgenix-support-eyebrow"><?php echo esc_html__( 'Help keep Kitgenix independent', 'kitgenix-order-tracking-for-woocommerce' ); ?></span>
                    <h2 class="kitgenix-support-heading"><?php echo esc_html__( 'Support Kitgenix', 'kitgenix-order-tracking-for-woocommerce' ); ?></h2>
                    <p class="description kitgenix-support-intro"><?php echo esc_html__( 'We try to keep Kitgenix plugins lightweight, privacy-friendly, and free for everyone. If Order Tracking for WooCommerce saves you admin time or helps prevent "Where is my order?" support tickets, please consider supporting Kitgenix. Your support directly funds ongoing development, testing, and maintenance so we can keep features open and updates frequent.', 'kitgenix-order-tracking-for-woocommerce' ); ?></p>
                </div>
                <div class="kitgenix-support-hero__aside">
                    <p class="kitgenix-support-kicker"><?php echo esc_html__( 'Support this plugin', 'kitgenix-order-tracking-for-woocommerce' ); ?></p>
                    <div class="kitgenix-support-actions">
                        <a class="button button-primary" href="<?php echo esc_url( $donate_once_url ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html__( 'Buy Me a Coffee', 'kitgenix-order-tracking-for-woocommerce' ); ?></a>
                        <a class="button button-secondary" href="<?php echo esc_url( $monthly_support_url ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html__( 'Become a member', 'kitgenix-order-tracking-for-woocommerce' ); ?></a>
                    </div>
                    <p class="kitgenix-support-note"><?php echo esc_html__( 'Via Buy Me a Coffee. Cancel anytime.', 'kitgenix-order-tracking-for-woocommerce' ); ?></p>
                </div>
            </section>

            <section class="kitgenix-support-section kitgenix-support-section--feature">
                <div class="kitgenix-support-section__header">
                    <h3 class="kitgenix-support-subheading"><?php echo esc_html__( 'Your site impact', 'kitgenix-order-tracking-for-woocommerce' ); ?></h3>
                    <p class="description"><?php echo esc_html__( 'These stats show how Order Tracking for WooCommerce is currently working on your site:', 'kitgenix-order-tracking-for-woocommerce' ); ?></p>
                </div>
                <div class="kitgenix-support-metric-grid">
                    <?php foreach ( $impact_cards as $impact_card ) : ?>
                        <div class="kitgenix-support-stat">
                            <span class="kitgenix-support-stat__label"><?php echo esc_html( $impact_card['label'] ); ?></span>
                            <strong class="kitgenix-support-stat__value"><?php echo esc_html( $impact_card['value'] ); ?></strong>
                            <span class="kitgenix-support-stat__meta"><?php echo esc_html( $impact_card['meta'] ); ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            </section>

            <div class="kitgenix-support-grid">
                <section class="kitgenix-support-section">
                    <h3 class="kitgenix-support-subheading"><?php echo esc_html__( 'Support options', 'kitgenix-order-tracking-for-woocommerce' ); ?></h3>
                    <p class="description"><?php echo esc_html__( 'Buy Me a Coffee: A quick way to say thanks and help fund the next round of improvements.', 'kitgenix-order-tracking-for-woocommerce' ); ?></p>
                    <p class="description"><?php echo esc_html__( 'A membership helps keep development consistent if this plugin is part of your day-to-day order workflow.', 'kitgenix-order-tracking-for-woocommerce' ); ?></p>
                    <div class="kitgenix-support-chip-list">
                        <?php foreach ( $monthly_options as $monthly_option ) : ?>
                            <?php
                            $monthly_label = (string) $monthly_option['label'];
                            $monthly_name  = $monthly_label;
                            $monthly_price = '';
                            if ( preg_match( '/^(.*)\(([^)]+)\)$/u', $monthly_label, $monthly_parts ) ) {
                                $monthly_name  = trim( $monthly_parts[1] );
                                $monthly_price = trim( $monthly_parts[2] );
                            }
                            ?>
                            <a class="kitgenix-support-chip" href="<?php echo esc_url( $monthly_option['url'] ); ?>" target="_blank" rel="noopener noreferrer">
                                <span class="kitgenix-support-chip__name"><?php echo esc_html( $monthly_name ); ?></span>
                                <?php if ( '' !== $monthly_price ) : ?>
                                    <span class="kitgenix-support-chip__price"><?php echo esc_html( $monthly_price ); ?></span>
                                <?php endif; ?>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </section>

                <section class="kitgenix-support-section">
                    <h3 class="kitgenix-support-subheading"><?php echo esc_html__( 'What this means', 'kitgenix-order-tracking-for-woocommerce' ); ?></h3>
                    <ul class="kitgenix-support-list">
                        <?php foreach ( $meaning_points as $meaning_point ) : ?>
                            <li><?php echo esc_html( $meaning_point ); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </section>

                <section class="kitgenix-support-section kitgenix-support-section--soft">
                    <h3 class="kitgenix-support-subheading"><?php echo esc_html__( 'What your support helps with', 'kitgenix-order-tracking-for-woocommerce' ); ?></h3>
                    <ul class="kitgenix-support-list">
                        <?php foreach ( $support_points as $support_point ) : ?>
                            <li><?php echo esc_html( $support_point ); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </section>

                <section class="kitgenix-support-section">
                    <h3 class="kitgenix-support-subheading"><?php echo esc_html__( 'Not in a position to donate?', 'kitgenix-order-tracking-for-woocommerce' ); ?></h3>
                    <p class="description"><?php echo esc_html__( 'No worries - you can still massively help:', 'kitgenix-order-tracking-for-woocommerce' ); ?></p>
                    <p class="description"><?php echo esc_html__( 'Reviews help others discover the plugin and keep the project sustainable. Sharing the plugin with stores that want fewer tracking-related support tickets, and sending clear feature requests, both help improve coverage faster.', 'kitgenix-order-tracking-for-woocommerce' ); ?></p>
                    <div class="kitgenix-support-actions">
                        <a class="button button-secondary" href="<?php echo esc_url( $review_url ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html__( 'Leave a WordPress.org review', 'kitgenix-order-tracking-for-woocommerce' ); ?></a>
                        <button type="button" class="button button-secondary" onclick="<?php echo esc_attr( $copy_onclick ); ?>"><?php echo esc_html__( 'Copy plugin link', 'kitgenix-order-tracking-for-woocommerce' ); ?></button>
                        <a class="button button-secondary" href="<?php echo esc_url( $support_request_url ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html__( 'Open support / feature request', 'kitgenix-order-tracking-for-woocommerce' ); ?></a>
                    </div>
                </section>

                <section class="kitgenix-support-section kitgenix-support-section--full">
                    <h3 class="kitgenix-support-subheading"><?php echo esc_html__( 'A small note on trust & privacy', 'kitgenix-order-tracking-for-woocommerce' ); ?></h3>
                    <ul class="kitgenix-support-list">
                        <?php foreach ( $trust_points as $trust_point ) : ?>
                            <li><?php echo esc_html( $trust_point ); ?></li>
                        <?php endforeach; ?>
                    </ul>
                    <p class="kitgenix-support-footer-note"><?php echo esc_html__( 'Thank you for supporting Kitgenix.', 'kitgenix-order-tracking-for-woocommerce' ); ?></p>
                </section>
            </div>
            <?php if ( $social_base ) : ?>
                <?php
                $social_icons = [
                    [ 'https://www.facebook.com/kitgenix', 'facebook-solid.svg', 'Facebook' ],
                    [ 'https://github.com/kitgenix', 'github-solid.svg', 'GitHub' ],
                    [ 'https://www.instagram.com/kitgenix/', 'instagram-solid.svg', 'Instagram' ],
                    [ 'https://www.linkedin.com/company/kitgenix', 'linkedin-solid.svg', 'LinkedIn' ],
                    [ 'https://www.reddit.com/r/Kitgenix/', 'reddit-solid.svg', 'Reddit' ],
                    [ 'https://x.com/kitgenix', 'x-solid.svg', 'X' ],
                    [ 'https://www.tiktok.com/@kitgenix', 'tiktok-solid.svg', 'TikTok' ],
                    [ 'https://www.youtube.com/@Kitgenix', 'youtube-solid.svg', 'YouTube' ],
                ];
                ?>
                <div class="kitgenix-social-links kitgenix-social-links--icons">
                    <?php foreach ( $social_icons as $social_icon ) : ?>
                        <a href="<?php echo esc_url( $social_icon[0] ); ?>" target="_blank" rel="noopener noreferrer" aria-label="<?php echo esc_attr( $social_icon[2] ); ?>"><img src="<?php echo esc_url( $social_base . $social_icon[1] ); ?>" alt="<?php echo esc_attr( $social_icon[2] ); ?>" width="22" height="22" /></a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
        <?php
        echo '</div>';
        echo '</div>';
    }

    private static function maybe_handle_carriers_save( int $range_days ): void
    {
        $method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( (string) $_SERVER['REQUEST_METHOD'] ) ) ) : 'GET';
        if ( $method !== 'POST' ) {
            return;
        }

        if ( ! current_user_can( self::get_menu_capability() ) ) {
            return;
        }

        $nonce = isset( $_POST['kitgenix_order_tracking_for_woocommerce_carriers_nonce'] )
            ? sanitize_text_field( (string) wp_unslash( $_POST['kitgenix_order_tracking_for_woocommerce_carriers_nonce'] ) )
            : '';
        if ( '' === $nonce ) {
            return;
        }
        if ( ! wp_verify_nonce( $nonce, 'kitgenix_order_tracking_for_woocommerce_save_carriers' ) ) {
            return;
        }

        $builtin = Carriers::builtin();
        $existing_settings = Carriers::get_settings();
        $existing_custom = isset( $existing_settings['custom'] ) && is_array( $existing_settings['custom'] ) ? $existing_settings['custom'] : [];
        $existing_builtin_disabled = isset( $existing_settings['builtin_disabled'] ) && is_array( $existing_settings['builtin_disabled'] ) ? $existing_settings['builtin_disabled'] : [];
        $existing_builtin_priority = isset( $existing_settings['builtin_priority'] ) && is_array( $existing_settings['builtin_priority'] ) ? $existing_settings['builtin_priority'] : [];
        $existing_carrier_pack = isset( $existing_settings['carrier_pack'] ) ? (string) $existing_settings['carrier_pack'] : Carriers::recommended_pack();

        // Determine which form section was submitted so we can avoid wiping settings.
        $form_section = isset( $_POST['kitgenix_order_tracking_for_woocommerce_carriers_form'] )
            ? sanitize_key( (string) wp_unslash( $_POST['kitgenix_order_tracking_for_woocommerce_carriers_form'] ) )
            : '';
        $builtin_disabled = $existing_builtin_disabled;
        $builtin_priority = $existing_builtin_priority;
        $carrier_pack     = $existing_carrier_pack;

        if ( $form_section === 'pack' ) {
            $requested_pack = isset( $_POST['kitgenix_order_tracking_for_woocommerce_carrier_pack'] )
                ? sanitize_key( (string) wp_unslash( $_POST['kitgenix_order_tracking_for_woocommerce_carrier_pack'] ) )
                : $existing_carrier_pack;

            $pack_settings    = Carriers::apply_pack_settings(
                $requested_pack,
                [
                    'custom' => $existing_custom,
                ]
            );
            $builtin_disabled = $pack_settings['builtin_disabled'];
            $builtin_priority = $pack_settings['builtin_priority'];
            $carrier_pack     = $pack_settings['carrier_pack'];
        }

        if ( $form_section === 'builtin' ) {
            $builtin_enabled = [];
            if ( isset( $_POST['kitgenix_order_tracking_for_woocommerce_builtin_enabled'] ) && is_array( $_POST['kitgenix_order_tracking_for_woocommerce_builtin_enabled'] ) ) {
                // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce checked above
                // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- values are unslashed here and sanitized per-item below.
                $builtin_enabled = (array) wp_unslash( $_POST['kitgenix_order_tracking_for_woocommerce_builtin_enabled'] );
            }

            $builtin_disabled = [];
            foreach ( array_keys( $builtin ) as $key ) {
                if ( $key === 'other' ) {
                    continue;
                }
                if ( empty( $builtin_enabled[ $key ] ) ) {
                    $builtin_disabled[] = $key;
                }
            }

            $builtin_priority_rows = [];
            if ( isset( $_POST['kitgenix_order_tracking_for_woocommerce_builtin_priority'] ) && is_array( $_POST['kitgenix_order_tracking_for_woocommerce_builtin_priority'] ) ) {
                // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce checked above
                // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- values are unslashed here and sanitized per-item below.
                $builtin_priority_rows = (array) wp_unslash( $_POST['kitgenix_order_tracking_for_woocommerce_builtin_priority'] );
            }

            $builtin_priority = [];
            foreach ( array_keys( $builtin ) as $key ) {
                if ( ! array_key_exists( $key, $builtin_priority_rows ) ) {
                    continue;
                }

                $raw_value = $builtin_priority_rows[ $key ];
                if ( is_array( $raw_value ) ) {
                    continue;
                }

                $raw_value = trim( (string) $raw_value );
                if ( $raw_value == '' ) {
                    continue;
                }

                $builtin_priority[ $key ] = (int) $raw_value;
            }
        }

        $custom = $existing_custom;
        if ( $form_section === 'custom' ) {
            // New UI: add/edit/delete actions.
            $custom_action = isset( $_POST['kitgenix_order_tracking_for_woocommerce_custom_action'] )
                ? sanitize_key( (string) wp_unslash( $_POST['kitgenix_order_tracking_for_woocommerce_custom_action'] ) )
                : '';

            if ( in_array( $custom_action, [ 'add', 'edit', 'delete' ], true ) ) {
                $carrier = [];
                if ( isset( $_POST['kitgenix_order_tracking_for_woocommerce_custom_carrier'] ) && is_array( $_POST['kitgenix_order_tracking_for_woocommerce_custom_carrier'] ) ) {
                    // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce checked above
                    // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- values are unslashed here and sanitized below.
                    $carrier = (array) wp_unslash( $_POST['kitgenix_order_tracking_for_woocommerce_custom_carrier'] );
                }

                $incoming_key = isset( $carrier['key'] ) ? sanitize_key( (string) $carrier['key'] ) : '';
                $incoming_label = isset( $carrier['label'] ) ? sanitize_text_field( (string) $carrier['label'] ) : '';
                $incoming_url_pattern = isset( $carrier['url_pattern'] ) ? esc_url_raw( (string) $carrier['url_pattern'] ) : '';
                $incoming_icon = isset( $carrier['icon'] ) ? esc_url_raw( (string) $carrier['icon'] ) : '';
                $incoming_enabled = ! empty( $carrier['enabled'] );
                $incoming_priority = isset( $carrier['priority'] ) ? (int) $carrier['priority'] : 0;

                $used_keys = array_fill_keys( array_keys( $builtin ), true );
                foreach ( $existing_custom as $row ) {
                    if ( is_array( $row ) && ! empty( $row['key'] ) ) {
                        $used_keys[ (string) $row['key'] ] = true;
                    }
                }

                if ( $custom_action === 'delete' ) {
                    if ( $incoming_key !== '' ) {
                        $custom = array_values( array_filter( $existing_custom, static function ( $row ) use ( $incoming_key ) {
                            return ! ( is_array( $row ) && isset( $row['key'] ) && (string) $row['key'] === $incoming_key );
                        } ) );
                    }
                } elseif ( $custom_action === 'add' ) {
                    if ( $incoming_label !== '' ) {
                        $new_key = $incoming_key !== '' ? $incoming_key : sanitize_key( $incoming_label );
                        if ( $new_key === '' ) {
                            $new_key = 'carrier';
                        }
                        if ( isset( $used_keys[ $new_key ] ) ) {
                            $base = $new_key;
                            $i = 2;
                            while ( isset( $used_keys[ $base . '_' . $i ] ) ) {
                                $i++;
                            }
                            $new_key = $base . '_' . $i;
                        }

                        $custom = $existing_custom;
                        $custom[] = [
                            'key'         => $new_key,
                            'label'       => $incoming_label,
                            'url_pattern' => $incoming_url_pattern,
                            'icon'        => $incoming_icon,
                            'enabled'     => $incoming_enabled,
                            'priority'    => $incoming_priority,
                        ];
                    }
                } elseif ( $custom_action === 'edit' ) {
                    if ( $incoming_key !== '' && $incoming_label !== '' ) {
                        $custom = $existing_custom;
                        foreach ( $custom as $i => $row ) {
                            if ( ! is_array( $row ) || ! isset( $row['key'] ) ) {
                                continue;
                            }
                            if ( (string) $row['key'] !== $incoming_key ) {
                                continue;
                            }

                            $custom[ $i ] = [
                                'key'         => $incoming_key,
                                'label'       => $incoming_label,
                                'url_pattern' => $incoming_url_pattern,
                                'icon'        => $incoming_icon,
                                'enabled'     => $incoming_enabled,
                                'priority'    => $incoming_priority,
                            ];
                            break;
                        }
                    }
                }
            } else {
                // Back-compat: legacy inline table editor.
                $custom_rows = [];
                if ( isset( $_POST['kitgenix_order_tracking_for_woocommerce_custom'] ) && is_array( $_POST['kitgenix_order_tracking_for_woocommerce_custom'] ) ) {
                    // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce checked above
                    // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- values are unslashed here and sanitized per-item below.
                    $custom_rows = (array) wp_unslash( $_POST['kitgenix_order_tracking_for_woocommerce_custom'] );
                }

                $custom = [];
                $used_keys = array_fill_keys( array_keys( $builtin ), true );
                foreach ( $custom_rows as $row ) {
                    if ( ! is_array( $row ) ) {
                        continue;
                    }

                    $label = isset( $row['label'] ) ? sanitize_text_field( (string) $row['label'] ) : '';
                    $icon = isset( $row['icon'] ) ? esc_url_raw( (string) $row['icon'] ) : '';
                    $url_pattern = isset( $row['url_pattern'] ) ? esc_url_raw( (string) $row['url_pattern'] ) : '';
                    $enabled = ! empty( $row['enabled'] );
                    $key = isset( $row['key'] ) ? sanitize_key( (string) $row['key'] ) : '';
                    $priority = isset( $row['priority'] ) ? (int) $row['priority'] : 0;

                    if ( $label === '' ) {
                        continue;
                    }

                    if ( $key === '' ) {
                        $key = sanitize_key( $label );
                    }
                    if ( $key === '' ) {
                        continue;
                    }
                    if ( isset( $used_keys[ $key ] ) ) {
                        $base = $key;
                        $i = 2;
                        while ( isset( $used_keys[ $base . '_' . $i ] ) ) {
                            $i++;
                        }
                        $key = $base . '_' . $i;
                    }
                    $used_keys[ $key ] = true;

                    $custom[] = [
                        'key'         => $key,
                        'label'       => $label,
                        'url_pattern' => $url_pattern,
                        'icon'        => $icon,
                        'enabled'     => $enabled,
                        'priority'    => $priority,
                    ];
                }
            }
        }

        Carriers::update_settings( [
            'builtin_disabled' => $builtin_disabled,
            'builtin_priority' => $builtin_priority,
            'custom'           => $custom,
            'carrier_pack'     => $carrier_pack,
        ] );

        $redirect = add_query_arg(
            [
                'page'  => self::PAGE_SLUG,
                'range' => (string) $range_days,
                'tab'   => 'carriers',
                'saved' => '1',
            ],
            admin_url( 'admin.php' )
        );

        wp_safe_redirect( $redirect );
        exit;
    }

    private static function render_carriers(): void
    {
        $builtin          = Carriers::builtin();
        $settings         = Carriers::get_settings();
        $disabled         = array_fill_keys( $settings['builtin_disabled'], true );
        $builtin_priority = $settings['builtin_priority'] ?? [];
        $pack_options     = Carriers::pack_options();
        $current_pack     = isset( $settings['carrier_pack'] ) ? (string) $settings['carrier_pack'] : Carriers::recommended_pack();
        $recommended_pack = Carriers::recommended_pack();
        $pack_data        = $pack_options[ $current_pack ] ?? $pack_options['global'];

        $pack_carrier_labels = [];
        foreach ( $pack_data['carriers'] as $carrier_key ) {
            if ( isset( $builtin[ $carrier_key ]['label'] ) ) {
                $pack_carrier_labels[] = (string) $builtin[ $carrier_key ]['label'];
            }
        }

        echo '<div class="kitgenix-analytics-mt-14 kitgenix-carriers-settings">';

        echo '<div class="kitgenix-analytics-panel">';
        echo '<h2>' . esc_html__( 'International carrier packs', 'kitgenix-order-tracking-for-woocommerce' ) . '</h2>';
        echo '<p class="description">' . esc_html__( 'Apply a regional carrier pack to enable a recommended set of built-in carriers and priorities for that market. You can fine-tune the list below afterwards.', 'kitgenix-order-tracking-for-woocommerce' ) . '</p>';

        if ( isset( $pack_options[ $recommended_pack ] ) && $recommended_pack !== $current_pack ) {
            echo '<p class="description">' . esc_html( sprintf(
                /* translators: %s: carrier pack label */
                __( 'Based on your WooCommerce store country, we recommend the %s pack.', 'kitgenix-order-tracking-for-woocommerce' ),
                (string) $pack_options[ $recommended_pack ]['label']
            ) ) . '</p>';
        }

        echo '<form method="post">';
        wp_nonce_field( 'kitgenix_order_tracking_for_woocommerce_save_carriers', 'kitgenix_order_tracking_for_woocommerce_carriers_nonce' );
        echo '<input type="hidden" name="kitgenix_order_tracking_for_woocommerce_carriers_form" value="pack" />';
        echo '<table class="form-table" role="presentation"><tbody>';
        echo '<tr>';
        echo '<th scope="row"><label for="kitgenix-order-tracking-carrier-pack">' . esc_html__( 'Carrier pack', 'kitgenix-order-tracking-for-woocommerce' ) . '</label></th>';
        echo '<td>';
        echo '<select id="kitgenix-order-tracking-carrier-pack" name="kitgenix_order_tracking_for_woocommerce_carrier_pack">';
        foreach ( $pack_options as $pack_key => $option ) {
            echo '<option value="' . esc_attr( $pack_key ) . '" ' . selected( $current_pack, $pack_key, false ) . '>' . esc_html( $option['label'] ) . '</option>';
        }
        echo '</select>';
        echo '<p class="description">' . esc_html( $pack_data['description'] ) . '</p>';
        if ( ! empty( $pack_carrier_labels ) ) {
            echo '<p class="description"><strong>' . esc_html__( 'Included built-in carriers:', 'kitgenix-order-tracking-for-woocommerce' ) . '</strong> ' . esc_html( implode( ', ', $pack_carrier_labels ) ) . '</p>';
        }
        echo '</td>';
        echo '</tr>';
        echo '</tbody></table>';
        echo '<p><button class="button button-primary" type="submit">' . esc_html__( 'Apply carrier pack', 'kitgenix-order-tracking-for-woocommerce' ) . '</button></p>';
        echo '</form>';
        echo '</div>';

        // Built-in carriers card.
        echo '<div class="kitgenix-analytics-panel">';
        echo '<h2>' . esc_html__( 'Carriers', 'kitgenix-order-tracking-for-woocommerce' ) . '</h2>';
        echo '<p class="description">' . esc_html__( 'Enable or disable built-in carriers and set their priority. Use this table for manual overrides after applying a recommended carrier pack.', 'kitgenix-order-tracking-for-woocommerce' ) . '</p>';

        echo '<form method="post">';
        wp_nonce_field( 'kitgenix_order_tracking_for_woocommerce_save_carriers', 'kitgenix_order_tracking_for_woocommerce_carriers_nonce' );
        echo '<input type="hidden" name="kitgenix_order_tracking_for_woocommerce_carriers_form" value="builtin" />';

        echo '<h3>' . esc_html__( 'Built-in carriers', 'kitgenix-order-tracking-for-woocommerce' ) . '</h3>';
        echo '<table class="widefat striped kitgenix-order-tracking-for-woocommerce-carriers-table">';
        echo '<thead><tr>';
        echo '<th style="width:90px">' . esc_html__( 'Enabled', 'kitgenix-order-tracking-for-woocommerce' ) . '</th>';
        echo '<th style="width:110px">' . esc_html__( 'Priority', 'kitgenix-order-tracking-for-woocommerce' ) . '</th>';
        echo '<th>' . esc_html__( 'Shipper', 'kitgenix-order-tracking-for-woocommerce' ) . '</th>';
        echo '<th>' . esc_html__( 'Tracking URL pattern', 'kitgenix-order-tracking-for-woocommerce' ) . '</th>';
        echo '</tr></thead>';
        echo '<tbody>';
        $other_carrier = null;
        if ( isset( $builtin['other'] ) ) {
            $other_carrier = $builtin['other'];
            unset( $builtin['other'] );
        }

        $builtin_for_display = $builtin;
        if ( is_array( $other_carrier ) ) {
            $builtin_for_display['other'] = $other_carrier;
        }

        foreach ( $builtin_for_display as $key => $carrier ) {
            $is_enabled = $key === 'other' ? true : ! isset( $disabled[ $key ] );
            $label = $carrier['label'] ?? $key;
            $pattern = $carrier['url_pattern'] ?? '';

            echo '<tr>';
            echo '<td>';
            if ( $key === 'other' ) {
                echo '<input type="checkbox" checked disabled />';
                echo '<input type="hidden" name="kitgenix_order_tracking_for_woocommerce_builtin_enabled[' . esc_attr( $key ) . ']" value="1" />';
            } else {
                echo '<label><input type="checkbox" name="kitgenix_order_tracking_for_woocommerce_builtin_enabled[' . esc_attr( $key ) . ']" value="1" ' . ( $is_enabled ? 'checked' : '' ) . ' /> ';
                echo '<span class="screen-reader-text">' . esc_html( $label ) . '</span></label>';
            }
            echo '</td>';

            $priority_value = '';
            if ( isset( $builtin_priority[ $key ] ) ) {
                $priority_value = (string) (int) $builtin_priority[ $key ];
            }
            $priority_placeholder = '1';
            echo '<td><input type="number" class="small-text" name="kitgenix_order_tracking_for_woocommerce_builtin_priority[' . esc_attr( $key ) . ']" value="' . esc_attr( $priority_value ) . '" placeholder="' . esc_attr( $priority_placeholder ) . '" /></td>';

            echo '<td><strong>' . esc_html( $label ) . '</strong></td>';
            echo '<td><code>' . esc_html( $pattern ) . '</code></td>';
            echo '</tr>';
        }
        echo '</tbody>';
        echo '</table>';
        echo '<p><button class="button button-primary" type="submit">' . esc_html__( 'Save changes', 'kitgenix-order-tracking-for-woocommerce' ) . '</button></p>';
        echo '</form>';
        echo '</div>';
        
        // Custom carriers card.
        self::render_custom_carriers_card( $settings );

        echo '</div>';
    }

    private static function render_custom_carriers_card( array $settings ): void
    {
        $rows = isset( $settings['custom'] ) && is_array( $settings['custom'] ) ? $settings['custom'] : [];

        echo '<div class="kitgenix-analytics-panel">';
        echo '<h2>' . esc_html__( 'Custom carriers', 'kitgenix-order-tracking-for-woocommerce' ) . '</h2>';
        echo '<p class="description">' . esc_html__( 'Add your own carriers here. If enabled, they will appear in the order tracking carrier dropdown. Custom tracking URLs may include the placeholder {tracking_number}.', 'kitgenix-order-tracking-for-woocommerce' ) . '</p>';

        echo '<div class="kitgenix-grid kitgenix-grid--2">';

        // Quick add form.
        echo '<div class="kitgenix-card">';
        echo '<h3>' . esc_html__( 'Add shipper', 'kitgenix-order-tracking-for-woocommerce' ) . '</h3>';
        echo '<form method="post">';
        wp_nonce_field( 'kitgenix_order_tracking_for_woocommerce_save_carriers', 'kitgenix_order_tracking_for_woocommerce_carriers_nonce' );
        echo '<input type="hidden" name="kitgenix_order_tracking_for_woocommerce_carriers_form" value="custom" />';
        echo '<input type="hidden" name="kitgenix_order_tracking_for_woocommerce_custom_action" value="add" />';

        echo '<div class="kitgenix-field">'
            . '<label><input type="checkbox" name="kitgenix_order_tracking_for_woocommerce_custom_carrier[enabled]" value="1" checked /> '
            . esc_html__( 'Enabled', 'kitgenix-order-tracking-for-woocommerce' )
            . '</label>'
            . '</div>';

        echo '<div class="kitgenix-field">'
            . '<label for="kitgenix_order_tracking_custom_priority">' . esc_html__( 'Priority', 'kitgenix-order-tracking-for-woocommerce' ) . '</label>'
            . '<input type="number" id="kitgenix_order_tracking_custom_priority" class="small-text" name="kitgenix_order_tracking_for_woocommerce_custom_carrier[priority]" value="0" placeholder="0" />'
            . '</div>';

        echo '<div class="kitgenix-field">'
            . '<label for="kitgenix_order_tracking_custom_label">' . esc_html__( 'Name', 'kitgenix-order-tracking-for-woocommerce' ) . '</label>'
            . '<input type="text" id="kitgenix_order_tracking_custom_label" class="regular-text" name="kitgenix_order_tracking_for_woocommerce_custom_carrier[label]" value="" required />'
            . '</div>';

        echo '<div class="kitgenix-field">'
            . '<label for="kitgenix_order_tracking_custom_url_pattern">' . esc_html__( 'Tracking URL pattern', 'kitgenix-order-tracking-for-woocommerce' ) . '</label>'
            . '<input type="text" id="kitgenix_order_tracking_custom_url_pattern" class="regular-text" name="kitgenix_order_tracking_for_woocommerce_custom_carrier[url_pattern]" value="" placeholder="https://example.com/track/{tracking_number}" />'
            . '</div>';

        echo '<div class="kitgenix-field">'
            . '<label for="kitgenix_order_tracking_custom_icon">' . esc_html__( 'Icon URL (optional)', 'kitgenix-order-tracking-for-woocommerce' ) . '</label>'
            . '<input type="url" id="kitgenix_order_tracking_custom_icon" class="regular-text" name="kitgenix_order_tracking_for_woocommerce_custom_carrier[icon]" value="" placeholder="https://example.com/icon.svg" /> '
            . '<button type="button" class="button kitgenix-order-tracking-for-woocommerce-icon-upload">' . esc_html__( 'Upload', 'kitgenix-order-tracking-for-woocommerce' ) . '</button>'
            . '</div>';

        echo '<p>';
        submit_button( __( 'Add shipper', 'kitgenix-order-tracking-for-woocommerce' ), 'primary', 'submit', false );
        echo '</p>';
        echo '</form>';
        echo '</div>';

        // List table.
        echo '<div class="kitgenix-card">';
        echo '<h3 class="kitgenix-card-title">' . esc_html__( 'All custom shippers', 'kitgenix-order-tracking-for-woocommerce' ) . '</h3>';

        if ( empty( $rows ) ) {
            echo '<p class="kitgenix-muted">' . esc_html__( 'No custom shippers yet. Add your first one on the left.', 'kitgenix-order-tracking-for-woocommerce' ) . '</p>';
        } else {
            echo '<div class="kitgenix-table-wrap">';
            echo '<table class="kitgenix-table" id="kitgenix-order-tracking-custom-carriers-table">';
            echo '<thead><tr>';
            echo '<th>' . esc_html__( 'Name', 'kitgenix-order-tracking-for-woocommerce' ) . '</th>';
            echo '<th style="width:100px">' . esc_html__( 'Enabled', 'kitgenix-order-tracking-for-woocommerce' ) . '</th>';
            echo '<th style="width:110px">' . esc_html__( 'Priority', 'kitgenix-order-tracking-for-woocommerce' ) . '</th>';
            echo '<th>' . esc_html__( 'Tracking URL pattern', 'kitgenix-order-tracking-for-woocommerce' ) . '</th>';
            echo '<th>' . esc_html__( 'Icon', 'kitgenix-order-tracking-for-woocommerce' ) . '</th>';
            echo '<th style="width:120px">' . esc_html__( 'Actions', 'kitgenix-order-tracking-for-woocommerce' ) . '</th>';
            echo '</tr></thead><tbody>';

            foreach ( $rows as $row ) {
                if ( ! is_array( $row ) ) {
                    continue;
                }

                $key = isset( $row['key'] ) ? (string) $row['key'] : '';
                $label = isset( $row['label'] ) ? (string) $row['label'] : '';
                $pattern = isset( $row['url_pattern'] ) ? (string) $row['url_pattern'] : '';
                $icon = isset( $row['icon'] ) ? (string) $row['icon'] : '';
                $enabled = isset( $row['enabled'] ) ? (bool) $row['enabled'] : true;
                $priority = isset( $row['priority'] ) ? (int) $row['priority'] : 0;

                $edit_href = add_query_arg( [ 'page' => self::PAGE_SLUG, 'tab' => 'carriers' ], admin_url( 'admin.php' ) );

                echo '<tr>';
                echo '<td><strong>' . esc_html( $label ) . '</strong></td>';
                echo '<td>' . ( $enabled ? esc_html__( 'Yes', 'kitgenix-order-tracking-for-woocommerce' ) : esc_html__( 'No', 'kitgenix-order-tracking-for-woocommerce' ) ) . '</td>';
                echo '<td>' . esc_html( (string) $priority ) . '</td>';
                echo '<td><span class="kitgenix-code">' . esc_html( $pattern ) . '</span></td>';
                if ( $icon !== '' ) {
                    echo '<td><a href="' . esc_url( $icon ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'View', 'kitgenix-order-tracking-for-woocommerce' ) . '</a></td>';
                } else {
                    echo '<td><span class="kitgenix-muted">' . esc_html__( '—', 'kitgenix-order-tracking-for-woocommerce' ) . '</span></td>';
                }

                echo '<td class="kitgenix-actions">'
                    . '<a href="' . esc_url( $edit_href ) . '"'
                    . ' data-kitgenix-custom-carrier-edit="1"'
                    . ' data-key="' . esc_attr( $key ) . '"'
                    . ' data-label="' . esc_attr( $label ) . '"'
                    . ' data-enabled="' . esc_attr( $enabled ? '1' : '0' ) . '"'
                    . ' data-priority="' . esc_attr( (string) $priority ) . '"'
                    . ' data-url-pattern="' . esc_attr( $pattern ) . '"'
                    . ' data-icon="' . esc_attr( $icon ) . '"'
                    . '>' . esc_html__( 'Edit', 'kitgenix-order-tracking-for-woocommerce' ) . '</a>'
                    . '<a href="#" data-kitgenix-custom-carrier-delete="1" data-key="' . esc_attr( $key ) . '">' . esc_html__( 'Delete', 'kitgenix-order-tracking-for-woocommerce' ) . '</a>'
                    . '</td>';

                echo '</tr>';
            }

            echo '</tbody></table></div>';
        }

        echo '</div>'; // card

        echo '</div>'; // grid

        // Hidden delete form (used by row Delete links and modal Delete button).
        echo '<form method="post" id="kitgenix-order-tracking-custom-carrier-delete-form" style="display:none">';
        wp_nonce_field( 'kitgenix_order_tracking_for_woocommerce_save_carriers', 'kitgenix_order_tracking_for_woocommerce_carriers_nonce' );
        echo '<input type="hidden" name="kitgenix_order_tracking_for_woocommerce_carriers_form" value="custom" />';
        echo '<input type="hidden" name="kitgenix_order_tracking_for_woocommerce_custom_action" value="delete" />';
        echo '<input type="hidden" name="kitgenix_order_tracking_for_woocommerce_custom_carrier[key]" value="" />';
        echo '</form>';

        // Modal popup editor.
        echo '<div class="kitgenix-modal" id="kitgenix-order-tracking-custom-carrier-modal" aria-hidden="true" role="dialog" aria-modal="true">'
            . '<div class="kitgenix-modal__backdrop" data-kitgenix-modal-close="1"></div>'
            . '<div class="kitgenix-modal__dialog" role="document">'
            . '<div class="kitgenix-modal__header">'
            . '<h2 class="kitgenix-modal__title">' . esc_html__( 'Edit shipper', 'kitgenix-order-tracking-for-woocommerce' ) . '</h2>'
            . '<button type="button" class="button kitgenix-modal__close" data-kitgenix-modal-close="1">' . esc_html__( 'Close', 'kitgenix-order-tracking-for-woocommerce' ) . '</button>'
            . '</div>'
            . '<div class="kitgenix-modal__body">'
            . '<form method="post" id="kitgenix-order-tracking-custom-carrier-edit-form">';

        wp_nonce_field( 'kitgenix_order_tracking_for_woocommerce_save_carriers', 'kitgenix_order_tracking_for_woocommerce_carriers_nonce' );
        echo '<input type="hidden" name="kitgenix_order_tracking_for_woocommerce_carriers_form" value="custom" />';
        echo '<input type="hidden" name="kitgenix_order_tracking_for_woocommerce_custom_action" value="edit" />';
        echo '<input type="hidden" name="kitgenix_order_tracking_for_woocommerce_custom_carrier[key]" value="" />';

        echo '<div class="kitgenix-field">'
            . '<label><input type="checkbox" name="kitgenix_order_tracking_for_woocommerce_custom_carrier[enabled]" value="1" /> '
            . esc_html__( 'Enabled', 'kitgenix-order-tracking-for-woocommerce' )
            . '</label>'
            . '</div>';

        echo '<div class="kitgenix-field">'
            . '<label for="kitgenix_order_tracking_modal_priority">' . esc_html__( 'Priority', 'kitgenix-order-tracking-for-woocommerce' ) . '</label>'
            . '<input type="number" id="kitgenix_order_tracking_modal_priority" class="small-text" name="kitgenix_order_tracking_for_woocommerce_custom_carrier[priority]" value="0" placeholder="0" />'
            . '</div>';

        echo '<div class="kitgenix-field">'
            . '<label for="kitgenix_order_tracking_modal_label">' . esc_html__( 'Name', 'kitgenix-order-tracking-for-woocommerce' ) . '</label>'
            . '<input type="text" id="kitgenix_order_tracking_modal_label" class="regular-text" name="kitgenix_order_tracking_for_woocommerce_custom_carrier[label]" value="" required />'
            . '</div>';

        echo '<div class="kitgenix-field">'
            . '<label for="kitgenix_order_tracking_modal_url_pattern">' . esc_html__( 'Tracking URL pattern', 'kitgenix-order-tracking-for-woocommerce' ) . '</label>'
            . '<input type="text" id="kitgenix_order_tracking_modal_url_pattern" class="regular-text" name="kitgenix_order_tracking_for_woocommerce_custom_carrier[url_pattern]" value="" placeholder="https://example.com/track/{tracking_number}" />'
            . '</div>';

        echo '<div class="kitgenix-field">'
            . '<label for="kitgenix_order_tracking_modal_icon">' . esc_html__( 'Icon URL (optional)', 'kitgenix-order-tracking-for-woocommerce' ) . '</label>'
            . '<input type="url" id="kitgenix_order_tracking_modal_icon" class="regular-text" name="kitgenix_order_tracking_for_woocommerce_custom_carrier[icon]" value="" placeholder="https://example.com/icon.svg" /> '
            . '<button type="button" class="button kitgenix-order-tracking-for-woocommerce-icon-upload">' . esc_html__( 'Upload', 'kitgenix-order-tracking-for-woocommerce' ) . '</button>'
            . '</div>';

        echo '<div class="kitgenix-modal__actions">';
        echo '<a href="#" class="button kitgenix-modal__cancel" data-kitgenix-modal-close="1">' . esc_html__( 'Cancel', 'kitgenix-order-tracking-for-woocommerce' ) . '</a>';
        echo ' <a href="#" class="button button-link-delete" id="kitgenix-order-tracking-custom-carrier-modal-delete">' . esc_html__( 'Delete', 'kitgenix-order-tracking-for-woocommerce' ) . '</a>';
        echo ' ';
        submit_button( __( 'Save Changes', 'kitgenix-order-tracking-for-woocommerce' ), 'primary', 'submit', false );
        echo '</div>';

        echo '</form></div></div></div>';

        echo '</div>'; // analytics-panel
    }

    /**
     * Overview tab (dashboard feel).
     *
     * @param array<string,mixed> $data
     */
    private static function render_overview(array $data, int $range_days, DateTimeImmutable $from, DateTimeImmutable $now): void
    {
        echo '<div class="kitgenix-analytics-mt-14">';

        echo '<div class="kitgenix-analytics-grid">';

        self::kpi_card(
            esc_html__('Orders in range', 'kitgenix-order-tracking-for-woocommerce'),
            number_format_i18n((int) $data['total_orders']),
            esc_html__('Total WooCommerce orders created in the selected period.', 'kitgenix-order-tracking-for-woocommerce')
        );

        self::kpi_card(
            esc_html__('Shipment coverage', 'kitgenix-order-tracking-for-woocommerce'),
            esc_html((string) $data['coverage_percent']) . '%',
            esc_html(sprintf(
                /* translators: 1: number */
                __('%1$s orders have at least one shipment.', 'kitgenix-order-tracking-for-woocommerce'),
                number_format_i18n((int) $data['orders_with_shipments'])
            ))
        );

        self::kpi_card(
            esc_html__('Avg time to ship', 'kitgenix-order-tracking-for-woocommerce'),
            esc_html((string) $data['avg_time_to_ship']) . ' ' . esc_html__('days', 'kitgenix-order-tracking-for-woocommerce'),
            esc_html__('From order created → earliest shipment date.', 'kitgenix-order-tracking-for-woocommerce')
        );

        self::kpi_card(
            esc_html__('Median time to ship', 'kitgenix-order-tracking-for-woocommerce'),
            esc_html((string) $data['median_time_to_ship']) . ' ' . esc_html__('days', 'kitgenix-order-tracking-for-woocommerce'),
            esc_html__('Less affected by outliers than average.', 'kitgenix-order-tracking-for-woocommerce')
        );

        self::kpi_card(
            esc_html__('Shipments in range', 'kitgenix-order-tracking-for-woocommerce'),
            number_format_i18n((int) $data['total_shipments']),
            !empty($data['top_carrier'])
                ? esc_html(sprintf(
                    /* translators: 1: carrier name */
                    __('Top carrier: %1$s', 'kitgenix-order-tracking-for-woocommerce'),
                    (string) $data['top_carrier']
                ))
                : esc_html__('No shipments found.', 'kitgenix-order-tracking-for-woocommerce')
        );

        self::kpi_card(
            esc_html__('Exception rate', 'kitgenix-order-tracking-for-woocommerce'),
            esc_html((string) $data['exception_order_rate']) . '%',
            esc_html(sprintf(
                /* translators: 1: number */
                __('%1$s orders have at least one exception shipment.', 'kitgenix-order-tracking-for-woocommerce'),
                number_format_i18n((int) $data['orders_with_exceptions'])
            ))
        );

        self::kpi_card(
            esc_html__('Tracking lookups', 'kitgenix-order-tracking-for-woocommerce'),
            number_format_i18n((int) $data['lookups_all_time']),
            esc_html__('All-time customer tracking page lookups.', 'kitgenix-order-tracking-for-woocommerce')
        );

        self::kpi_card(
            esc_html__('Orders without shipments', 'kitgenix-order-tracking-for-woocommerce'),
            number_format_i18n((int) $data['orders_without_shipments']),
            esc_html__('Helpful for spotting unfulfilled/unstaged workflows.', 'kitgenix-order-tracking-for-woocommerce')
        );

        echo '</div>'; // grid

        echo '<div class="kitgenix-analytics-two-column kitgenix-analytics-mt-10">';

        // Trend panel.
        echo '<div class="kitgenix-analytics-panel">';
        echo '<h3>' . esc_html__('Shipments over time', 'kitgenix-order-tracking-for-woocommerce') . '</h3>';
            $svg = self::sparkline((array) $data['series'], 520, 78);
            $allowed_svg = [
                'svg' => [
                    'width' => true,
                    'height' => true,
                    'viewBox' => true,
                    'xmlns' => true,
                    'aria-hidden' => true,
                ],
                'path' => [
                    'd' => true,
                    'fill' => true,
                    'stroke' => true,
                    'stroke-width' => true,
                    'stroke-linejoin' => true,
                    'stroke-linecap' => true,
                ],
            ];
            echo '<div class="kitgenix-analytics-sparkline">' . wp_kses($svg, $allowed_svg) . '</div>';
        echo '<p class="kitgenix-analytics-muted kitgenix-analytics-mt-10">' . esc_html(sprintf(
            /* translators: 1: number, 2: days */
            __('%1$s shipments recorded across %2$d days.', 'kitgenix-order-tracking-for-woocommerce'),
            number_format_i18n((int) $data['total_shipments']),
            $range_days
        )) . '</p>';
        echo '</div>';

        // Insight panel (fast “at a glance”).
        echo '<div class="kitgenix-analytics-panel">';
        echo '<h3>' . esc_html__('Insights', 'kitgenix-order-tracking-for-woocommerce') . '</h3>';

        $coverage = (float) $data['coverage_percent'];
        $exception_rate = (float) $data['exception_order_rate'];

        echo '<ul class="kitgenix-analytics-list">';
        echo '<li>' . esc_html(sprintf(
            /* translators: 1: percent */
            __('Shipment coverage: %1$s%%', 'kitgenix-order-tracking-for-woocommerce'),
            (string) $coverage
        )) . '</li>';

        echo '<li>' . esc_html(sprintf(
            /* translators: 1: percent */
            __('Exception rate: %1$s%%', 'kitgenix-order-tracking-for-woocommerce'),
            (string) $exception_rate
        )) . '</li>';

        echo '<li>' . esc_html(sprintf(
            /* translators: 1: number */
            __('Exceptions (shipments): %1$s', 'kitgenix-order-tracking-for-woocommerce'),
            number_format_i18n((int) $data['exceptions_shipments'])
        )) . '</li>';

        if (!empty($data['top_carrier'])) {
            echo '<li>' . esc_html(sprintf(
                /* translators: 1: carrier */
                __('Top carrier: %1$s', 'kitgenix-order-tracking-for-woocommerce'),
                (string) $data['top_carrier']
            )) . '</li>';
        }

        echo '</ul>';

        $breakdown_url = add_query_arg([
            'page'  => self::PAGE_SLUG,
            'range' => (string) $range_days,
            'tab'   => 'breakdown',
        ], admin_url('admin.php'));

        echo '<p class="kitgenix-analytics-mt-12">';
        echo '<a class="button button-secondary" href="' . esc_url($breakdown_url) . '">' . esc_html__('View breakdown →', 'kitgenix-order-tracking-for-woocommerce') . '</a>';
        echo '</p>';

        echo '</div>';

        echo '</div>'; // two column

        echo '</div>';
    }

    /**
     * Breakdown tab (ranked lists with bars + tables).
     *
     * @param array<string,mixed> $data
     */
    private static function render_breakdowns(array $data): void
    {
        echo '<div class="kitgenix-analytics-two-column kitgenix-analytics-mt-14">';

        self::render_ranked_panel(
            esc_html__('Shipments by carrier', 'kitgenix-order-tracking-for-woocommerce'),
            (array) $data['shipments_by_carrier'],
            (int) $data['total_shipments'],
            esc_html__('Carrier', 'kitgenix-order-tracking-for-woocommerce')
        );

        self::render_ranked_panel(
            esc_html__('Shipments by status', 'kitgenix-order-tracking-for-woocommerce'),
            (array) $data['shipments_by_status'],
            (int) $data['total_shipments'],
            esc_html__('Status', 'kitgenix-order-tracking-for-woocommerce'),
            true
        );

        echo '</div>';
    }

    /**
     * Recent tab.
     *
     * @param array<string,mixed> $data
     */
    private static function render_recent_shipments(array $data): void
    {
        $recent = (array) $data['recent_shipments'];

        echo '<div class="kitgenix-analytics-mt-14">';
        echo '<h2 class="kitgenix-analytics-mt-0">' . esc_html__('Recent shipments', 'kitgenix-order-tracking-for-woocommerce') . '</h2>';

        if (empty($recent)) {
            echo '<p>' . esc_html__('No recent shipments found in this date range.', 'kitgenix-order-tracking-for-woocommerce') . '</p>';
            echo '</div>';
            return;
        }

        echo '<div class="kitgenix-analytics-panel">';
        echo '<table class="widefat striped kitgenix-analytics-table">';
        echo '<thead><tr>';
        echo '<th>' . esc_html__('Date shipped', 'kitgenix-order-tracking-for-woocommerce') . '</th>';
        echo '<th>' . esc_html__('Order', 'kitgenix-order-tracking-for-woocommerce') . '</th>';
        echo '<th>' . esc_html__('Carrier', 'kitgenix-order-tracking-for-woocommerce') . '</th>';
        echo '<th>' . esc_html__('Tracking number', 'kitgenix-order-tracking-for-woocommerce') . '</th>';
        echo '<th>' . esc_html__('Status', 'kitgenix-order-tracking-for-woocommerce') . '</th>';
        echo '</tr></thead><tbody>';

        foreach ($recent as $row) {
            $order_edit_link = admin_url('post.php?post=' . (int) $row['order_id'] . '&action=edit');
            $status_label    = self::status_label((string) $row['status']);
            $status_class    = sanitize_key((string) $row['status']);

            echo '<tr>';
            echo '<td>' . esc_html((string) $row['date']) . '</td>';
            echo '<td><a href="' . esc_attr($order_edit_link) . '">#' . esc_html((string) $row['order_number']) . '</a></td>';
            echo '<td class="truncate">' . esc_html((string) $row['carrier']) . '</td>';

            $tn = (string) $row['tracking_number'];
            echo '<td class="truncate" title="' . esc_attr($tn) . '">' . esc_html($tn !== '' ? $tn : '—') . '</td>';

            echo '<td><span class="kitgenix-analytics-status-pill kitgenix-analytics-status-' . esc_attr($status_class) . '">' . esc_html($status_label) . '</span></td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
        echo '</div>';
        echo '</div>';
    }

    private static function status_label(string $key): string
    {
        $key = trim($key);
        if ($key === '') {
            return esc_html__('Shipped', 'kitgenix-order-tracking-for-woocommerce');
        }
        return ucwords(str_replace('_', ' ', $key));
    }

    private static function kpi_card(string $title, string $value, string $sub = ''): void
    {
        echo '<div class="kitgenix-analytics-card">';
        echo '<div class="kitgenix-analytics-card__body">';
        echo '<div class="kitgenix-analytics-card__title">' . esc_html($title) . '</div>';
        echo '<div class="kitgenix-analytics-card__value">' . esc_html($value) . '</div>';
        if ($sub !== '') {
            echo '<div class="kitgenix-analytics-card__sub">' . esc_html($sub) . '</div>';
        }
        echo '</div>';
        echo '</div>';
    }

    /**
     * Ranked list panel with percentage bars (more “dashboard” than a plain table).
     *
     * @param array<string,int> $items
     */
    private static function render_ranked_panel(string $title, array $items, int $total, string $label, bool $status_pills = false): void
    {
        echo '<div class="kitgenix-analytics-panel">';
        echo '<h3>' . esc_html($title) . '</h3>';

        if ($total === 0 || empty($items)) {
            echo '<p>' . esc_html__('No shipments found in this date range.', 'kitgenix-order-tracking-for-woocommerce') . '</p>';
            echo '</div>';
            return;
        }

        $top = array_slice($items, 0, 10, true);

        echo '<div class="kitgenix-analytics-ranklist">';
        foreach ($top as $k => $count) {
            $percent = $total > 0 ? round(($count / $total) * 100, 1) : 0.0;

            echo '<div class="kitgenix-analytics-ranklist__row">';
            echo '<div class="kitgenix-analytics-ranklist__left">';
            if ($status_pills) {
                echo '<span class="kitgenix-analytics-status-pill kitgenix-analytics-status-' . esc_attr(sanitize_key((string) $k)) . '">' . esc_html(self::status_label((string) $k)) . '</span>';
            } else {
                echo '<span class="kitgenix-analytics-ranklist__label" title="' . esc_attr((string) $k) . '">' . esc_html((string) $k) . '</span>';
            }
            echo '</div>';
            echo '<div class="kitgenix-analytics-ranklist__bar" aria-hidden="true"><span data-kitgenix-order-tracking-for-woocommercewidth="' . esc_attr((string) $percent) . '"></span></div>';
            echo '<div class="kitgenix-analytics-ranklist__right">';
            echo '<strong>' . esc_html(number_format_i18n((int) $count)) . '</strong>';
            echo '<span class="kitgenix-analytics-ranklist__pct">' . esc_html((string) $percent) . '%</span>';
            echo '</div>';
            echo '</div>';
        }
        echo '</div>';
        echo '</div>';
    }

    private static function render_sidebar(): void
    {
        $social_base = KITGENIX_ORDER_TRACKING_FOR_WOOCOMMERCE_PLUGIN_URL . 'assets/images/social-media/';

        echo '<aside class="kitgenix-settings-sidebar" aria-label="' . esc_attr__( 'Help and links', 'kitgenix-order-tracking-for-woocommerce' ) . '">';

        echo '<div class="kitgenix-sidebar-card">';
        echo '<h2>' . esc_html__( 'Need Help?', 'kitgenix-order-tracking-for-woocommerce' ) . '</h2>';
        echo '<p>' . esc_html__( 'Open the documentation for setup guidance or send us a support request if you need help configuring the plugin.', 'kitgenix-order-tracking-for-woocommerce' ) . '</p>';
        echo '<div class="kitgenix-sidebar-actions">';
        echo '<a class="button button-secondary" href="' . esc_url( 'https://kitgenix.com/plugins/kitgenix-order-tracking-for-woocommerce/documentation/' ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Documentation', 'kitgenix-order-tracking-for-woocommerce' ) . '</a>';
        echo '<a class="button button-primary" href="' . esc_url( 'https://kitgenix.com/plugins/kitgenix-order-tracking-for-woocommerce/support' ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Request Support', 'kitgenix-order-tracking-for-woocommerce' ) . '</a>';
        echo '</div>';
        echo '</div>';

        echo '<div class="kitgenix-sidebar-card">';
        echo '<h2>' . esc_html__( 'Visit Our Official Facebook Group', 'kitgenix-order-tracking-for-woocommerce' ) . '</h2>';
        echo '<p>' . esc_html__( 'Join the Kitgenix community to ask questions, share feedback, and keep up with product updates.', 'kitgenix-order-tracking-for-woocommerce' ) . '</p>';
        echo '<div class="kitgenix-sidebar-actions">';
        echo '<a class="button button-secondary" href="' . esc_url( 'https://www.facebook.com/groups/kitgenix' ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Join Group', 'kitgenix-order-tracking-for-woocommerce' ) . '</a>';
        echo '</div>';
        echo '</div>';

        echo '<div class="kitgenix-sidebar-card">';
        echo '<h2>' . esc_html__( 'Follow Us', 'kitgenix-order-tracking-for-woocommerce' ) . '</h2>';
        echo '<p>' . esc_html__( 'Keep up with new releases, tutorials, and product news across our channels.', 'kitgenix-order-tracking-for-woocommerce' ) . '</p>';
        echo '<div class="kitgenix-sidebar-social-grid">';
        echo '<a class="kitgenix-sidebar-social-link" href="https://kitgenix.com" target="_blank" rel="noopener noreferrer" aria-label="Website" title="Website"><img src="' . esc_url( $social_base . 'globe-solid.svg' ) . '" alt="" width="16" height="16" aria-hidden="true" /></a>';
        echo '<a class="kitgenix-sidebar-social-link" href="https://www.facebook.com/kitgenix" target="_blank" rel="noopener noreferrer" aria-label="Facebook" title="Facebook"><img src="' . esc_url( $social_base . 'facebook-solid.svg' ) . '" alt="" width="16" height="16" aria-hidden="true" /></a>';
        echo '<a class="kitgenix-sidebar-social-link" href="https://www.instagram.com/kitgenix/" target="_blank" rel="noopener noreferrer" aria-label="Instagram" title="Instagram"><img src="' . esc_url( $social_base . 'instagram-solid.svg' ) . '" alt="" width="16" height="16" aria-hidden="true" /></a>';
        echo '<a class="kitgenix-sidebar-social-link" href="https://www.youtube.com/@Kitgenix" target="_blank" rel="noopener noreferrer" aria-label="YouTube" title="YouTube"><img src="' . esc_url( $social_base . 'youtube-solid.svg' ) . '" alt="" width="16" height="16" aria-hidden="true" /></a>';
        echo '<a class="kitgenix-sidebar-social-link" href="https://www.reddit.com/r/Kitgenix/" target="_blank" rel="noopener noreferrer" aria-label="Reddit" title="Reddit"><img src="' . esc_url( $social_base . 'reddit-solid.svg' ) . '" alt="" width="16" height="16" aria-hidden="true" /></a>';
        echo '<a class="kitgenix-sidebar-social-link" href="https://www.linkedin.com/company/kitgenix" target="_blank" rel="noopener noreferrer" aria-label="LinkedIn" title="LinkedIn"><img src="' . esc_url( $social_base . 'linkedin-solid.svg' ) . '" alt="" width="16" height="16" aria-hidden="true" /></a>';
        echo '<a class="kitgenix-sidebar-social-link" href="https://x.com/kitgenix" target="_blank" rel="noopener noreferrer" aria-label="X" title="X"><img src="' . esc_url( $social_base . 'x-solid.svg' ) . '" alt="" width="16" height="16" aria-hidden="true" /></a>';
        echo '<a class="kitgenix-sidebar-social-link" href="https://www.tiktok.com/@kitgenix" target="_blank" rel="noopener noreferrer" aria-label="TikTok" title="TikTok"><img src="' . esc_url( $social_base . 'tiktok-solid.svg' ) . '" alt="" width="16" height="16" aria-hidden="true" /></a>';
        echo '<a class="kitgenix-sidebar-social-link" href="https://github.com/kitgenix" target="_blank" rel="noopener noreferrer" aria-label="GitHub" title="GitHub"><img src="' . esc_url( $social_base . 'github-solid.svg' ) . '" alt="" width="16" height="16" aria-hidden="true" /></a>';
        echo '</div>';
        echo '</div>';

        echo '</aside>';
    }

    private static function sparkline(array $values, int $w = 520, int $h = 48): string
    {
        $count = empty($values) ? 0 : count($values);
        if ($count === 0) {
            return '<svg width="160" height="48" xmlns="http://www.w3.org/2000/svg"></svg>';
        }

        $max = max($values);
        $min = min($values);
        $pad = 6;
        $innerW = max(10, $w - ($pad * 2));
        $innerH = max(8, $h - ($pad * 2));

        $points = [];
        for ($i = 0; $i < $count; $i++) {
            $x = $pad + ($i / max(1, $count - 1)) * $innerW;
            if ($max === $min) {
                $y = $pad + $innerH * 0.6;
            } else {
                $norm = ((float) $values[$i] - (float) $min) / ((float) $max - (float) $min);
                $y = $pad + ($innerH - ($norm * $innerH));
            }
            $points[] = $x . ',' . $y;
        }

        $path = '';
        if (!empty($points)) {
            $first = explode(',', $points[0]);
            $path = 'M' . $first[0] . ' ' . $first[1];
            for ($i = 1; $i < count($points); $i++) {
                [$px, $py] = explode(',', $points[$i]);
                $path .= ' L' . $px . ' ' . $py;
            }
        }

        $fillPath = $path . ' L ' . ($pad + $innerW) . ' ' . ($pad + $innerH) . ' L ' . $pad . ' ' . ($pad + $innerH) . ' Z';

        $svg  = '<svg width="' . (int) $w . '" height="' . (int) $h . '" viewBox="0 0 ' . (int) $w . ' ' . (int) $h . '" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">';
        $svg .= '<path d="' . esc_attr($fillPath) . '" fill="rgba(79,42,154,0.08)" stroke="none"/>';
        $svg .= '<path d="' . esc_attr($path) . '" fill="none" stroke="#4f2a9a" stroke-width="2" stroke-linejoin="round" stroke-linecap="round"/>';
        $svg .= '</svg>';

        return $svg;
    }

    private static function render_log(): void
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if ( isset( $_GET['kitgenix_log_cleared'] ) ) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Event log cleared.', 'kitgenix-order-tracking-for-woocommerce' ) . '</p></div>';
        }

        $clear_url = \wp_nonce_url(
            admin_url( 'admin-post.php?action=kitgenix_order_tracking_clear_event_log' ),
            'kitgenix_order_tracking_clear_event_log'
        );

        echo '<div class="kitgenix-settings-section">';
        echo '<h2>' . esc_html__( 'Activity Log', 'kitgenix-order-tracking-for-woocommerce' ) . '</h2>';
        echo '<p class="description">' . esc_html__( 'A record of recent plugin events. Entries show the timestamp, context, outcome, and a plain-English note. IP addresses and sensitive data are never stored here.', 'kitgenix-order-tracking-for-woocommerce' ) . '</p>';
        echo '<textarea class="large-text code" rows="20" readonly>' . esc_textarea( Event_Log::get_log_text() ) . '</textarea>';
        echo '<p>';
        echo '<a href="' . esc_url( $clear_url ) . '" class="button button-secondary" onclick="return confirm(\'' . esc_js( __( 'Clear all log entries?', 'kitgenix-order-tracking-for-woocommerce' ) ) . '\')">' . esc_html__( 'Clear log', 'kitgenix-order-tracking-for-woocommerce' ) . '</a>';
        echo '</p>';
        echo '</div>';
    }

    public static function handle_clear_event_log(): void
    {
        if ( ! current_user_can( 'manage_options' ) && ! current_user_can( 'manage_woocommerce' ) ) {
            \wp_die( esc_html__( 'Permission denied.', 'kitgenix-order-tracking-for-woocommerce' ), 403 );
        }
        \check_admin_referer( 'kitgenix_order_tracking_clear_event_log' );
        Event_Log::clear();
        \wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&tab=log&kitgenix_log_cleared=1' ) );
        exit;
    }

    public static function on_carriers_saved( mixed $old_value, mixed $new_value, string $option ): void
    {
        Event_Log::record( 'carriers-saved', 'success', __( 'Carrier settings were saved via the admin settings page.', 'kitgenix-order-tracking-for-woocommerce' ) );
    }
}
