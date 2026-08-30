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

        wp_enqueue_style( 'kitgenix-order-tracking-for-woocommerce-admin-ui' );

        $admin_css_path = trailingslashit(KITGENIX_ORDER_TRACKING_FOR_WOOCOMMERCE_PLUGIN_DIR) . 'assets/css/admin.css';
		$admin_css_ver  = file_exists($admin_css_path) ? (string) filemtime($admin_css_path) : ( defined( 'KITGENIX_ORDER_TRACKING_FOR_WOOCOMMERCE_VERSION' ) ? (string) KITGENIX_ORDER_TRACKING_FOR_WOOCOMMERCE_VERSION : '1.0.5' );
        wp_enqueue_style(
            'kitgenix-order-tracking-admin-analytics',
            KITGENIX_ORDER_TRACKING_FOR_WOOCOMMERCE_PLUGIN_URL . 'assets/css/admin.css',
            [ 'kitgenix-order-tracking-for-woocommerce-admin-ui' ],
            $admin_css_ver
        );

        $admin_js_path = trailingslashit(KITGENIX_ORDER_TRACKING_FOR_WOOCOMMERCE_PLUGIN_DIR) . 'assets/js/admin-analytics.js';
        $admin_js_ver  = file_exists($admin_js_path) ? (string) filemtime($admin_js_path) : ( defined( 'KITGENIX_ORDER_TRACKING_FOR_WOOCOMMERCE_VERSION' ) ? (string) KITGENIX_ORDER_TRACKING_FOR_WOOCOMMERCE_VERSION : '1.0.5' );

        wp_enqueue_script(
            'kitgenix-order-tracking-admin-analytics',
            KITGENIX_ORDER_TRACKING_FOR_WOOCOMMERCE_PLUGIN_URL . 'assets/js/admin-analytics.js',
            [ 'kitgenix-admin-components' ],
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

        $components_js_path = trailingslashit(KITGENIX_ORDER_TRACKING_FOR_WOOCOMMERCE_PLUGIN_DIR) . 'assets/js/kitgenix-admin-components.js';
        $components_js_ver  = file_exists($components_js_path) ? (string) filemtime($components_js_path) : $admin_js_ver;
        wp_enqueue_script(
            'kitgenix-admin-components',
            KITGENIX_ORDER_TRACKING_FOR_WOOCOMMERCE_PLUGIN_URL . 'assets/js/kitgenix-admin-components.js',
            [],
            $components_js_ver,
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

        self::render_header($tab, $range_days);

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
        self::render_support($data);
        echo '</div>';

        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $shown/$hidden are internal controlled attribute strings.
        echo '<div id="kitgenix-tab-log" data-kitgenix-tab-panel="log"' . ($tab === 'log' ? $shown : $hidden) . '>';
        self::render_log();
        echo '</div>';

        echo '</div>';
        echo '</div>';
        self::render_sidebar( $tab );
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
        $date_parse_failures       = 0;

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
                        $date_parse_failures++;
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

        if ($date_parse_failures > 0) {
            Event_Log::record(
                'analytics-date-parse',
                'skipped',
                Event_Log::get_category_and_note('date_parse_failed')['note'],
                'date_parse_failed'
            );
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

    private static function render_header(string $active, int $range_days): void
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

        $analytics_keys = [ 'overview', 'breakdown', 'recent' ];

        $flat_tabs = [
            'carriers' => esc_html__('Carriers', 'kitgenix-order-tracking-for-woocommerce'),
            'bulk'     => esc_html__('Bulk Tools', 'kitgenix-order-tracking-for-woocommerce'),
            'log'      => esc_html__('Log', 'kitgenix-order-tracking-for-woocommerce'),
            'support'  => esc_html__('Support', 'kitgenix-order-tracking-for-woocommerce'),
        ];

        $tab_url = static function (string $key) use ($range_days): string {
            $url = add_query_arg([
                'page'  => self::PAGE_SLUG,
                'range' => (string) $range_days,
                'tab'   => $key,
            ], admin_url('admin.php'));

            return $url . '#kitgenix-tab-' . $key;
        };

        $logo_url = KITGENIX_ORDER_TRACKING_FOR_WOOCOMMERCE_PLUGIN_URL . 'assets/images/logos/kitgenix-primary-favicon.svg';

        echo '<div class="kitgenix-topbar">'
            . '<div class="kitgenix-topbar-left">'
            . '<a class="kitgenix-topbar-brand" href="' . esc_url( admin_url( 'admin.php?page=kitgenix' ) ) . '" title="Kitgenix">'
            . '<img class="kitgenix-topbar-logo" src="' . esc_url( $logo_url ) . '" alt="Kitgenix" width="28" height="28" />'
            . '</a>'
            . '<span class="kitgenix-topbar-divider" aria-hidden="true"></span>'
            . '<div class="kitgenix-topbar-plugin-info">'
            . '<span class="kitgenix-topbar-title">' . esc_html__( 'Order Tracking for WooCommerce', 'kitgenix-order-tracking-for-woocommerce' ) . '</span>'
            . '<span class="kitgenix-topbar-version">v' . esc_html( $plugin_version ) . '</span>'
            . '</div>'
            . '</div>'
            . '<div class="kitgenix-topbar-center">'
            . '<ul class="kitgenix-topbar-menu" role="tablist" aria-label="' . esc_attr__( 'Navigation', 'kitgenix-order-tracking-for-woocommerce' ) . '">';

        $analytics_active = in_array($active, $analytics_keys, true);
        echo '<li class="kitgenix-menu-item kitgenix-has-dropdown' . ( $analytics_active ? ' kitgenix-active' : '' ) . '">'
            . '<button type="button" class="kitgenix-menu-link kitgenix-menu-toggle" aria-haspopup="true" aria-expanded="false">' . esc_html__( 'Analytics', 'kitgenix-order-tracking-for-woocommerce' ) . '<span class="kitgenix-menu-caret"><svg width="12" height="12" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M9.99999 10.879L13.7125 7.1665L14.773 8.227L9.99999 13L5.22699 8.227L6.28749 7.1665L9.99999 10.879Z" fill="currentColor"></path></svg></span></button>'
            . '<div class="kitgenix-submenu">'
            . '<a class="kitgenix-submenu-item kitgenix-tab-trigger" href="' . esc_url( $tab_url('overview') ) . '" data-kitgenix-tab="overview"><span class="kitgenix-submenu-icon"><svg width="18" height="18" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M3.5 16.5V11M8 16.5V7M12.5 16.5V9.5M17 16.5V4" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"></path></svg></span><span class="kitgenix-submenu-text"><span class="kitgenix-submenu-title">' . esc_html__( 'Overview', 'kitgenix-order-tracking-for-woocommerce' ) . '</span><span class="kitgenix-submenu-desc">' . esc_html__( 'Key delivery metrics at a glance', 'kitgenix-order-tracking-for-woocommerce' ) . '</span></span></a>'
            . '<a class="kitgenix-submenu-item kitgenix-tab-trigger" href="' . esc_url( $tab_url('breakdown') ) . '" data-kitgenix-tab="breakdown"><span class="kitgenix-submenu-icon"><svg width="18" height="18" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M10 10.5V2.75A7.25 7.25 0 1 1 2.87 12" stroke="currentColor" stroke-width="1.3" stroke-linecap="round" stroke-linejoin="round"></path><path d="M17.25 10.5H10" stroke="currentColor" stroke-width="1.3" stroke-linecap="round"></path></svg></span><span class="kitgenix-submenu-text"><span class="kitgenix-submenu-title">' . esc_html__( 'Breakdown', 'kitgenix-order-tracking-for-woocommerce' ) . '</span><span class="kitgenix-submenu-desc">' . esc_html__( 'Shipments by carrier, status and exception rate', 'kitgenix-order-tracking-for-woocommerce' ) . '</span></span></a>'
            . '<a class="kitgenix-submenu-item kitgenix-tab-trigger" href="' . esc_url( $tab_url('recent') ) . '" data-kitgenix-tab="recent"><span class="kitgenix-submenu-icon"><svg width="18" height="18" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M10 5.5V10l3 1.8" stroke="currentColor" stroke-width="1.3" stroke-linecap="round" stroke-linejoin="round"></path><path d="M4.2 8A6 6 0 1 1 4 11.5" stroke="currentColor" stroke-width="1.3" stroke-linecap="round"></path><path d="M2.5 6v3h3" stroke="currentColor" stroke-width="1.3" stroke-linecap="round" stroke-linejoin="round"></path></svg></span><span class="kitgenix-submenu-text"><span class="kitgenix-submenu-title">' . esc_html__( 'Recent', 'kitgenix-order-tracking-for-woocommerce' ) . '</span><span class="kitgenix-submenu-desc">' . esc_html__( 'Latest tracked shipments as they update', 'kitgenix-order-tracking-for-woocommerce' ) . '</span></span></a>'
            . '</div>'
            . '</li>';

        foreach ($flat_tabs as $key => $label) {
            $is_active = ($key === $active);
            $class = 'kitgenix-menu-link kitgenix-tab-trigger' . ($is_active ? ' kitgenix-active' : '');
            echo '<li class="kitgenix-menu-item ' . ($is_active ? 'kitgenix-active' : '') . '"><a class="' . esc_attr($class) . '" data-kitgenix-tab="' . esc_attr($key) . '" href="' . esc_url($tab_url($key)) . '"' . ($is_active ? ' aria-current="page"' : '') . '>' . esc_html($label) . '</a></li>';
        }

        echo '</ul>'
            . '</div>'
            . '<div class="kitgenix-topbar-right" aria-label="Topbar actions">'
            . '<div class="kitgenix-topbar-search">'
            . '<button type="button" class="kitgenix-topbar-icon-btn kitgenix-search-toggle" aria-label="' . esc_attr__( 'Search settings', 'kitgenix-order-tracking-for-woocommerce' ) . '" aria-expanded="false"><svg width="16" height="16" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M14.5232 13.4627L17.7355 16.6742L16.6742 17.7355L13.4627 14.5232C12.2678 15.4812 10.7815 16.0022 9.25 16C5.524 16 2.5 12.976 2.5 9.25C2.5 5.524 5.524 2.5 9.25 2.5C12.976 2.5 16 5.524 16 9.25C16.0022 10.7815 15.4812 12.2678 14.5232 13.4627ZM13.0187 12.9062C13.9706 11.9274 14.5021 10.6153 14.5 9.25C14.5 6.349 12.1502 4 9.25 4C6.349 4 4 6.349 4 9.25C4 12.1502 6.349 14.5 9.25 14.5C10.6153 14.5021 11.9274 13.9706 12.9062 13.0187L13.0187 12.9062V12.9062Z" fill="currentColor"></path></svg></button>'
            . '<div class="kitgenix-search-panel">'
            . '<span class="kitgenix-search-icon" aria-hidden="true"><svg width="15" height="15" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M14.5232 13.4627L17.7355 16.6742L16.6742 17.7355L13.4627 14.5232C12.2678 15.4812 10.7815 16.0022 9.25 16C5.524 16 2.5 12.976 2.5 9.25C2.5 5.524 5.524 2.5 9.25 2.5C12.976 2.5 16 5.524 16 9.25C16.0022 10.7815 15.4812 12.2678 14.5232 13.4627ZM13.0187 12.9062C13.9706 11.9274 14.5021 10.6153 14.5 9.25C14.5 6.349 12.1502 4 9.25 4C6.349 4 4 6.349 4 9.25C4 12.1502 6.349 14.5 9.25 14.5C10.6153 14.5021 11.9274 13.9706 12.9062 13.0187L13.0187 12.9062V12.9062Z" fill="currentColor"></path></svg></span>'
            . '<input type="search" class="kitgenix-topbar-search-input" placeholder="' . esc_attr__( 'Search settings…', 'kitgenix-order-tracking-for-woocommerce' ) . '" aria-label="' . esc_attr__( 'Search settings', 'kitgenix-order-tracking-for-woocommerce' ) . '" autocomplete="off" />'
            . '<kbd class="kitgenix-search-kbd" title="Press / or ⌘K to search">/</kbd>'
            . '<button type="button" class="kitgenix-search-clear" aria-label="' . esc_attr__( 'Clear search', 'kitgenix-order-tracking-for-woocommerce' ) . '" style="display:none;">&times;</button>'
            . '</div>'
            . '</div>'
            . '<button type="button" class="kitgenix-topbar-icon-btn kitgenix-theme-toggle" aria-label="' . esc_attr__( 'Toggle color theme', 'kitgenix-order-tracking-for-woocommerce' ) . '" title="' . esc_attr__( 'Toggle color theme', 'kitgenix-order-tracking-for-woocommerce' ) . '">'
            . '<svg class="kitgenix-theme-icon-light" width="16" height="16" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg"><circle cx="10" cy="10" r="3.25" stroke="currentColor" stroke-width="1.3"></circle><path d="M10 2.5v2M10 15.5v2M17.5 10h-2M4.5 10h-2M15.3 4.7l-1.4 1.4M6.1 13.9l-1.4 1.4M15.3 15.3l-1.4-1.4M6.1 6.1 4.7 4.7" stroke="currentColor" stroke-width="1.3" stroke-linecap="round"></path></svg>'
            . '<svg class="kitgenix-theme-icon-dark" width="16" height="16" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M16.5 12.3A6.5 6.5 0 0 1 7.7 3.5a6.5 6.5 0 1 0 8.8 8.8Z" fill="currentColor"></path></svg>'
            . '</button>'
            . '<a class="kitgenix-topbar-icon-btn" href="' . esc_url( admin_url( 'admin.php?page=kitgenix' ) ) . '" title="' . esc_attr__( 'Kitgenix Hub', 'kitgenix-order-tracking-for-woocommerce' ) . '" aria-label="Kitgenix Hub"><svg width="15" height="15" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M3.25 3.25H9.25V9.25H3.25V3.25ZM10.75 3.25H16.75V9.25H10.75V3.25ZM3.25 10.75H9.25V16.75H3.25V10.75ZM10.75 10.75H16.75V16.75H10.75V10.75Z" fill="currentColor"></path></svg></a>'
            . '<button type="button" class="kitgenix-topbar-hamburger" aria-label="' . esc_attr__( 'Toggle navigation', 'kitgenix-order-tracking-for-woocommerce' ) . '"><span></span><span></span><span></span></button>'
            . '</div>'
            . '</div>';
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
        echo '<div class="kitgenix-analytics-two-column">';

        // Export card.
        echo '<div class="kitgenix-card">';
        echo '<div class="kitgenix-card-head">';
        echo '<div class="kitgenix-card-head-main">';
        echo '<span class="kitgenix-card-icon" aria-hidden="true"><svg width="18" height="18" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M10 2.5v10m0 0 3.5-3.5M10 12.5 6.5 9M4 14.5v1.75c0 .69.56 1.25 1.25 1.25h9.5c.69 0 1.25-.56 1.25-1.25V14.5" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"/></svg></span>';
        echo '<div class="kitgenix-card-head-text">';
        echo '<h2>' . esc_html__( 'Bulk shipment export', 'kitgenix-order-tracking-for-woocommerce' ) . '</h2>';
        echo '<p>' . esc_html__( 'Download a CSV of every tracked shipment in WooCommerce.', 'kitgenix-order-tracking-for-woocommerce' ) . '</p>';
        echo '</div></div></div>';

        echo '<div class="kitgenix-card-body">';
        echo '<p>' . esc_html__( 'The export includes order IDs, tracking numbers, carrier keys, shipment dates, statuses, and any per-item shipment allocations.', 'kitgenix-order-tracking-for-woocommerce' ) . '</p>';
        echo '<p><strong>' . esc_html__( 'CSV columns:', 'kitgenix-order-tracking-for-woocommerce' ) . '</strong> <code>' . esc_html( implode( ', ', $csv_columns ) ) . '</code></p>';
        echo '<p class="description">' . esc_html__( 'Each row represents one shipment. The item_quantities column uses the format item_id:qty|item_id:qty so exported files can be edited and imported again.', 'kitgenix-order-tracking-for-woocommerce' ) . '</p>';
        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
        echo '<input type="hidden" name="action" value="kitgenix_order_tracking_export_shipments" />';
        wp_nonce_field( 'kitgenix_order_tracking_export_shipments' );
        echo '<div class="kitgenix-button-group"><button type="submit" class="button button-primary">' . esc_html__( 'Export shipments CSV', 'kitgenix-order-tracking-for-woocommerce' ) . '</button></div>';
        echo '</form>';
        echo '</div>'; // card-body
        echo '</div>'; // card

        // Import card.
        echo '<div class="kitgenix-card">';
        echo '<div class="kitgenix-card-head">';
        echo '<div class="kitgenix-card-head-main">';
        echo '<span class="kitgenix-card-icon" aria-hidden="true"><svg width="18" height="18" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M10 17.5v-10m0 0L6.5 11M10 7.5 13.5 11M4 5.5V3.75c0-.69.56-1.25 1.25-1.25h9.5c.69 0 1.25.56 1.25 1.25V5.5" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"/></svg></span>';
        echo '<div class="kitgenix-card-head-text">';
        echo '<h2>' . esc_html__( 'Bulk shipment import', 'kitgenix-order-tracking-for-woocommerce' ) . '</h2>';
        echo '<p>' . esc_html__( 'Add or replace shipment data on multiple orders at once.', 'kitgenix-order-tracking-for-woocommerce' ) . '</p>';
        echo '</div></div></div>';

        echo '<div class="kitgenix-card-body">';
        echo '<p class="description">' . esc_html__( 'Designed for spreadsheet-based fulfilment workflows and works best when you start from the plugin’s export file.', 'kitgenix-order-tracking-for-woocommerce' ) . '</p>';
        echo '<ul class="ul-disc">';
        echo '<li>' . esc_html__( 'order_id and tracking_number are required.', 'kitgenix-order-tracking-for-woocommerce' ) . '</li>';
        echo '<li>' . esc_html__( 'Use append mode to add new shipments without touching existing ones.', 'kitgenix-order-tracking-for-woocommerce' ) . '</li>';
        echo '<li>' . esc_html__( 'Use replace mode to overwrite all shipments on each order included in the CSV.', 'kitgenix-order-tracking-for-woocommerce' ) . '</li>';
        echo '<li>' . esc_html__( 'item_quantities is optional and should use item_id:qty|item_id:qty.', 'kitgenix-order-tracking-for-woocommerce' ) . '</li>';
        echo '</ul>';
        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" enctype="multipart/form-data">';
        echo '<input type="hidden" name="action" value="kitgenix_order_tracking_import_shipments" />';
        wp_nonce_field( 'kitgenix_order_tracking_import_shipments' );

        echo '<div class="kitgenix-settings-group">';

        echo '<div class="kitgenix-setting-row kitgenix-setting-row-stacked">';
        echo '<div class="kitgenix-setting-row-label"><label for="kitgenix-order-tracking-import-file">' . esc_html__( 'CSV file', 'kitgenix-order-tracking-for-woocommerce' ) . '</label></div>';
        echo '<div class="kitgenix-setting-row-control">';
        echo '<label class="kitgenix-dropzone" for="kitgenix-order-tracking-import-file">';
        echo '<input id="kitgenix-order-tracking-import-file" type="file" name="shipments_csv" accept=".csv,text/csv" class="kitgenix-dropzone-input" required />';
        echo '<span class="kitgenix-dropzone-icon" aria-hidden="true"><svg width="18" height="18" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M10 13.5V5m0 0L6.5 8.5M10 5l3.5 3.5" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"/><path d="M4 13.5v1.75c0 .69.56 1.25 1.25 1.25h9.5c.69 0 1.25-.56 1.25-1.25V13.5" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"/></svg></span>';
        echo '<span class="kitgenix-dropzone-title">' . esc_html__( 'Click to choose a CSV file', 'kitgenix-order-tracking-for-woocommerce' ) . '</span>';
        echo '<span class="kitgenix-dropzone-desc">' . esc_html__( '.csv files only', 'kitgenix-order-tracking-for-woocommerce' ) . '</span>';
        echo '</label>';
        echo '</div></div>';

        echo '<div class="kitgenix-setting-row">';
        echo '<div class="kitgenix-setting-row-label">';
        echo '<label for="kitgenix-order-tracking-import-mode">' . esc_html__( 'Import mode', 'kitgenix-order-tracking-for-woocommerce' ) . '</label>';
        echo '<p class="kitgenix-setting-row-desc">' . esc_html__( 'Append skips tracking numbers already stored on the order. Replace overwrites the matched orders with the shipments present in the CSV.', 'kitgenix-order-tracking-for-woocommerce' ) . '</p>';
        echo '</div>';
        echo '<div class="kitgenix-setting-row-control">';
        echo '<select id="kitgenix-order-tracking-import-mode" name="mode">';
        echo '<option value="append">' . esc_html__( 'Append new shipments', 'kitgenix-order-tracking-for-woocommerce' ) . '</option>';
        echo '<option value="replace">' . esc_html__( 'Replace shipments on matched orders', 'kitgenix-order-tracking-for-woocommerce' ) . '</option>';
        echo '</select>';
        echo '</div></div>';

        echo '</div>'; // settings-group

        echo '<div class="kitgenix-button-group kitgenix-analytics-mt-14"><button type="submit" class="button button-primary">' . esc_html__( 'Import shipments CSV', 'kitgenix-order-tracking-for-woocommerce' ) . '</button></div>';
        echo '</form>';
        echo '</div>'; // card-body
        echo '</div>'; // card

        echo '</div>'; // two-column
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
            $import_code = '' !== $exception->getMessage() ? $exception->getMessage() : 'invalid_headers';
            Event_Log::record( 'bulk-import', 'error', Event_Log::get_category_and_note( $import_code )['note'], $import_code );
            $redirect_url = add_query_arg( [ 'bulk_notice' => 'import-invalid' ], $redirect_url );
        } catch ( \Throwable $exception ) {
            // A RuntimeException we threw ourselves carries a known code; anything else is unexpected.
            $import_code = $exception instanceof \RuntimeException && '' !== $exception->getMessage()
                ? $exception->getMessage()
                : 'import_unexpected_exception';
            Event_Log::record( 'bulk-import', 'error', Event_Log::get_category_and_note( $import_code )['note'], $import_code );
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

        echo '<div class="kitgenix-card kitgenix-analytics-filters-card">';
        echo '<form method="get" class="kitgenix-analytics-filters">';
        echo '<input type="hidden" name="page" value="' . esc_attr(self::PAGE_SLUG) . '" />';
        echo '<input type="hidden" name="tab" value="' . esc_attr($tab) . '" />';

        echo '<div class="kitgenix-analytics-filters-row">';

        echo '<label class="kitgenix-analytics-filters-field">';
        echo '<span>' . esc_html__('Date range (days):', 'kitgenix-order-tracking-for-woocommerce') . '</span>';
        echo '<input type="number" name="range" min="7" max="365" value="' . esc_attr((string) $range_days) . '" class="small-text" />';
        echo '</label>';

        echo '<button class="button button-primary" type="submit">' . esc_html__('Apply', 'kitgenix-order-tracking-for-woocommerce') . '</button>';

        $presets = [ 7, 30, 90, 180, 365 ];
        echo '<span class="kitgenix-analytics-presets">';
        echo '<span class="kitgenix-analytics-presets-label">' . esc_html__('Quick ranges:', 'kitgenix-order-tracking-for-woocommerce') . '</span>';

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

        echo '</div>'; // filters-row

        echo '<div class="kitgenix-analytics-filters-meta">';
        echo '<span>' . esc_html( sprintf(
            /* translators: 1: days, 2: from date, 3: to date */
            __( 'Showing last %1$d days (%2$s – %3$s).', 'kitgenix-order-tracking-for-woocommerce' ),
            $range_days,
            $from->format( 'M j' ),
            $now->format( 'M j' )
        ) ) . '</span>';
        echo '<a class="button" href="' . esc_url( $refresh_url ) . '">' . esc_html__( 'Refresh', 'kitgenix-order-tracking-for-woocommerce' ) . '</a>';
        echo '</div>';

        echo '</form>';
        echo '</div>';
    }

    /**
     * @param array<string,mixed> $data
     */
    private static function render_support( array $data ): void
    {
        $donate_url       = 'https://www.paypal.com/donate/?hosted_button_id=KALF36K6JJ9B2';
        $plugin_page_url  = 'https://kitgenix.com/plugins/kitgenix-order-tracking-for-woocommerce/';
        $docs_url         = 'https://kitgenix.com/plugins/kitgenix-order-tracking-for-woocommerce/documentation/';
        $review_url       = 'https://wordpress.org/support/plugin/kitgenix-order-tracking-for-woocommerce/reviews/#new-post';
        $copy_onclick     = "if(window.navigator&&navigator.clipboard&&window.isSecureContext){navigator.clipboard.writeText(" . wp_json_encode( $plugin_page_url ) . ");}else{window.prompt(" . wp_json_encode( __( 'Copy plugin link:', 'kitgenix-order-tracking-for-woocommerce' ) ) . ", " . wp_json_encode( $plugin_page_url ) . ");}return false;";

        echo '<div class="kitgenix-analytics-mt-14 kitgenix-support-page">';

        // One hero card: icon badge, eyebrow, heading, body copy, donate button,
        // a reassurance caption, and a slim centered row of free ways to help.
        echo '<div class="kitgenix-card kitgenix-support-hero">';
        echo '<div class="kitgenix-support-hero-inner">';
        echo '<span class="kitgenix-support-hero-icon" aria-hidden="true">' . self::icon( 'heart' ) . '</span>';  // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-escaped, static trusted SVG markup from self::icon().
        echo '<p class="kitgenix-support-hero-eyebrow">' . esc_html__( 'Support Kitgenix', 'kitgenix-order-tracking-for-woocommerce' ) . '</p>';
        echo '<h2>' . esc_html__( 'Help us keep building', 'kitgenix-order-tracking-for-woocommerce' ) . '</h2>';
        echo '<p class="kitgenix-support-hero-body">' . esc_html__( 'This plugin turns every WooCommerce order into a multi-carrier tracking timeline and a branded self-serve tracking page, so customers check on their parcel instead of emailing you. It is maintained independently and funded entirely by donations from stores that rely on it.', 'kitgenix-order-tracking-for-woocommerce' ) . '</p>';
        echo '<a class="kitgenix-support-hero-button" href="' . esc_url( $donate_url ) . '" target="_blank" rel="noopener noreferrer">' . self::icon( 'paypal' ) . esc_html__( 'Donate with PayPal', 'kitgenix-order-tracking-for-woocommerce' ) . '</a>';  // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-escaped, static trusted SVG markup from self::icon().
        echo '<p class="kitgenix-support-hero-caption">' . self::icon( 'shield' ) . esc_html__( 'Donations are entirely optional. Kitgenix plugins keep working whether you donate or not.', 'kitgenix-order-tracking-for-woocommerce' ) . '</p>';  // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-escaped, static trusted SVG markup from self::icon().
        echo '</div>'; // support-hero-inner

        echo '<div class="kitgenix-support-links">';
        echo '<a class="kitgenix-support-link" href="' . esc_url( $review_url ) . '" target="_blank" rel="noopener noreferrer">' . self::icon( 'star' ) . esc_html__( 'Leave a review', 'kitgenix-order-tracking-for-woocommerce' ) . '</a>';  // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-escaped, static trusted SVG markup from self::icon().
        echo '<a class="kitgenix-support-link" href="' . esc_url( $docs_url ) . '" target="_blank" rel="noopener noreferrer">' . self::icon( 'log' ) . esc_html__( 'Read the docs', 'kitgenix-order-tracking-for-woocommerce' ) . '</a>';  // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-escaped, static trusted SVG markup from self::icon().
        echo '<button type="button" class="kitgenix-support-link" onclick="' . esc_attr( $copy_onclick ) . '">' . self::icon( 'copy' ) . esc_html__( 'Copy plugin link', 'kitgenix-order-tracking-for-woocommerce' ) . '</button>';  // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-escaped, static trusted SVG markup from self::icon().
        echo '</div>'; // support-links

        echo '</div>'; // card kitgenix-support-hero

        echo '</div>'; // support-page
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

        echo '<div class="kitgenix-analytics-mt-14 kitgenix-carriers-settings">';

        echo '<div class="kitgenix-card">';
        echo '<div class="kitgenix-card-head"><div class="kitgenix-card-head-main">';
        echo '<span class="kitgenix-card-icon" aria-hidden="true">' . self::icon('globe') . '</span>';  // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-escaped, static trusted SVG markup from self::icon().
        echo '<div class="kitgenix-card-head-text"><h2>' . esc_html__( 'International carrier packs', 'kitgenix-order-tracking-for-woocommerce' ) . '</h2>';
        echo '<p>' . esc_html__( 'Apply a regional carrier pack to enable a recommended set of built-in carriers and priorities for that market.', 'kitgenix-order-tracking-for-woocommerce' ) . '</p>';
        echo '</div></div></div>';

        echo '<div class="kitgenix-card-body">';

        if ( isset( $pack_options[ $recommended_pack ] ) && $recommended_pack !== $current_pack ) {
            echo '<div class="kitgenix-notice kitgenix-notice-info">';
            echo '<span class="kitgenix-notice-icon" aria-hidden="true">' . self::icon('globe') . '</span>';  // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-escaped, static trusted SVG markup from self::icon().
            echo '<div class="kitgenix-notice-body"><p class="kitgenix-notice-text">' . esc_html( sprintf(
                /* translators: %s: carrier pack label */
                __( 'Based on your WooCommerce store country, we recommend the %s pack.', 'kitgenix-order-tracking-for-woocommerce' ),
                (string) $pack_options[ $recommended_pack ]['label']
            ) ) . '</p></div>';
            echo '</div>';
        }

        echo '<form method="post">';
        wp_nonce_field( 'kitgenix_order_tracking_for_woocommerce_save_carriers', 'kitgenix_order_tracking_for_woocommerce_carriers_nonce' );
        echo '<input type="hidden" name="kitgenix_order_tracking_for_woocommerce_carriers_form" value="pack" />';

        echo '<div class="kitgenix-settings-group">';
        echo '<div class="kitgenix-setting-row">';
        echo '<div class="kitgenix-setting-row-label">';
        echo '<label for="kitgenix-order-tracking-carrier-pack">' . esc_html__( 'Carrier pack', 'kitgenix-order-tracking-for-woocommerce' ) . '</label>';
        echo '</div>';
        echo '<div class="kitgenix-setting-row-control">';
        echo '<select id="kitgenix-order-tracking-carrier-pack" name="kitgenix_order_tracking_for_woocommerce_carrier_pack">';
        foreach ( $pack_options as $pack_key => $option ) {
            echo '<option value="' . esc_attr( $pack_key ) . '" ' . selected( $current_pack, $pack_key, false ) . '>' . esc_html( $option['label'] ) . '</option>';
        }
        echo '</select>';
        echo '</div>';
        echo '</div>'; // setting-row
        echo '</div>'; // settings-group

        echo '<div class="kitgenix-button-group kitgenix-analytics-mt-14"><button class="button button-primary" type="submit">' . esc_html__( 'Apply carrier pack', 'kitgenix-order-tracking-for-woocommerce' ) . '</button></div>';
        echo '</form>';
        echo '</div>'; // card-body
        echo '</div>'; // card

        // Built-in carriers card.
        echo '<div class="kitgenix-card">';
        echo '<div class="kitgenix-card-head"><div class="kitgenix-card-head-main">';
        echo '<span class="kitgenix-card-icon" aria-hidden="true">' . self::icon('truck') . '</span>';  // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-escaped, static trusted SVG markup from self::icon().
        echo '<div class="kitgenix-card-head-text"><h2>' . esc_html__( 'Built-in carriers', 'kitgenix-order-tracking-for-woocommerce' ) . '</h2>';
        echo '<p>' . esc_html__( 'Enable or disable built-in carriers and set their priority.', 'kitgenix-order-tracking-for-woocommerce' ) . '</p>';
        echo '</div></div></div>';

        echo '<div class="kitgenix-card-body">';
        echo '<form method="post">';
        wp_nonce_field( 'kitgenix_order_tracking_for_woocommerce_save_carriers', 'kitgenix_order_tracking_for_woocommerce_carriers_nonce' );
        echo '<input type="hidden" name="kitgenix_order_tracking_for_woocommerce_carriers_form" value="builtin" />';

        echo '<div class="kitgenix-table-wrap">';
        echo '<table class="kitgenix-table kitgenix-order-tracking-for-woocommerce-carriers-table">';
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

            echo '<tr data-kitgenix-table-row>';
            echo '<td>';
            if ( $key === 'other' ) {
                echo '<label class="kitgenix-toggle"><input type="checkbox" class="kitgenix-toggle-input" checked disabled /><span class="kitgenix-toggle-track"><span class="kitgenix-toggle-thumb"></span></span></label>';
                echo '<input type="hidden" name="kitgenix_order_tracking_for_woocommerce_builtin_enabled[' . esc_attr( $key ) . ']" value="1" />';
            } else {
                echo '<label class="kitgenix-toggle">';
                echo '<input type="checkbox" class="kitgenix-toggle-input" name="kitgenix_order_tracking_for_woocommerce_builtin_enabled[' . esc_attr( $key ) . ']" value="1" ' . ( $is_enabled ? 'checked' : '' ) . ' />';
                echo '<span class="kitgenix-toggle-track"><span class="kitgenix-toggle-thumb"></span></span>';
                echo '<span class="kitgenix-sr-only">' . esc_html( $label ) . '</span>';
                echo '</label>';
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
        echo '</div>'; // table-wrap

        echo '<div class="kitgenix-button-group kitgenix-analytics-mt-14"><button class="button button-primary" type="submit">' . esc_html__( 'Save changes', 'kitgenix-order-tracking-for-woocommerce' ) . '</button></div>';
        echo '</form>';
        echo '</div>'; // card-body
        echo '</div>'; // card

        // Custom carriers card.
        self::render_custom_carriers_card( $settings );

        echo '</div>';
    }

    private static function render_custom_carriers_card( array $settings ): void
    {
        $rows = isset( $settings['custom'] ) && is_array( $settings['custom'] ) ? $settings['custom'] : [];

        echo '<div class="kitgenix-card">';
        echo '<div class="kitgenix-card-head"><div class="kitgenix-card-head-main">';
        echo '<span class="kitgenix-card-icon" aria-hidden="true">' . self::icon('plus') . '</span>';  // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-escaped, static trusted SVG markup from self::icon().
        echo '<div class="kitgenix-card-head-text"><h2>' . esc_html__( 'Custom carriers', 'kitgenix-order-tracking-for-woocommerce' ) . '</h2>';
        echo '<p>' . esc_html__( 'Add your own carriers here. Custom tracking URLs may include the placeholder {tracking_number}.', 'kitgenix-order-tracking-for-woocommerce' ) . '</p>';
        echo '</div></div></div>';

        echo '<div class="kitgenix-card-body">';
        echo '<div class="kitgenix-analytics-two-column">';

        // Quick add form.
        echo '<div class="kitgenix-card kitgenix-carriers-subcard">';
        echo '<div class="kitgenix-card-head"><div class="kitgenix-card-head-main"><div class="kitgenix-card-head-text"><h2>' . esc_html__( 'Add shipper', 'kitgenix-order-tracking-for-woocommerce' ) . '</h2></div></div></div>';
        echo '<div class="kitgenix-card-body">';
        echo '<form method="post">';
        wp_nonce_field( 'kitgenix_order_tracking_for_woocommerce_save_carriers', 'kitgenix_order_tracking_for_woocommerce_carriers_nonce' );
        echo '<input type="hidden" name="kitgenix_order_tracking_for_woocommerce_carriers_form" value="custom" />';
        echo '<input type="hidden" name="kitgenix_order_tracking_for_woocommerce_custom_action" value="add" />';

        echo '<div class="kitgenix-settings-group">';

        echo '<div class="kitgenix-setting-row">';
        echo '<div class="kitgenix-setting-row-label"><label>' . esc_html__( 'Enabled', 'kitgenix-order-tracking-for-woocommerce' ) . '</label></div>';
        echo '<div class="kitgenix-setting-row-control">';
        echo '<label class="kitgenix-toggle"><input type="checkbox" class="kitgenix-toggle-input" name="kitgenix_order_tracking_for_woocommerce_custom_carrier[enabled]" value="1" checked /><span class="kitgenix-toggle-track"><span class="kitgenix-toggle-thumb"></span></span></label>';
        echo '</div></div>';

        echo '<div class="kitgenix-setting-row">';
        echo '<div class="kitgenix-setting-row-label"><label for="kitgenix_order_tracking_custom_priority">' . esc_html__( 'Priority', 'kitgenix-order-tracking-for-woocommerce' ) . '</label></div>';
        echo '<div class="kitgenix-setting-row-control"><input type="number" id="kitgenix_order_tracking_custom_priority" class="small-text" name="kitgenix_order_tracking_for_woocommerce_custom_carrier[priority]" value="0" placeholder="0" /></div>';
        echo '</div>';

        echo '<div class="kitgenix-setting-row">';
        echo '<div class="kitgenix-setting-row-label"><label for="kitgenix_order_tracking_custom_label">' . esc_html__( 'Name', 'kitgenix-order-tracking-for-woocommerce' ) . '</label></div>';
        echo '<div class="kitgenix-setting-row-control"><input type="text" id="kitgenix_order_tracking_custom_label" class="regular-text" name="kitgenix_order_tracking_for_woocommerce_custom_carrier[label]" value="" required /></div>';
        echo '</div>';

        echo '<div class="kitgenix-setting-row kitgenix-setting-row-stacked">';
        echo '<div class="kitgenix-setting-row-label"><label for="kitgenix_order_tracking_custom_url_pattern">' . esc_html__( 'Tracking URL pattern', 'kitgenix-order-tracking-for-woocommerce' ) . '</label></div>';
        echo '<div class="kitgenix-setting-row-control"><input type="text" id="kitgenix_order_tracking_custom_url_pattern" class="regular-text" name="kitgenix_order_tracking_for_woocommerce_custom_carrier[url_pattern]" value="" placeholder="https://example.com/track/{tracking_number}" /></div>';
        echo '</div>';

        echo '<div class="kitgenix-setting-row kitgenix-setting-row-stacked">';
        echo '<div class="kitgenix-setting-row-label"><label for="kitgenix_order_tracking_custom_icon">' . esc_html__( 'Icon URL (optional)', 'kitgenix-order-tracking-for-woocommerce' ) . '</label></div>';
        echo '<div class="kitgenix-setting-row-control kitgenix-carriers-icon-field">';
        echo '<input type="url" id="kitgenix_order_tracking_custom_icon" class="regular-text" name="kitgenix_order_tracking_for_woocommerce_custom_carrier[icon]" value="" placeholder="https://example.com/icon.svg" />';
        echo '<button type="button" class="button kitgenix-order-tracking-for-woocommerce-icon-upload">' . esc_html__( 'Upload', 'kitgenix-order-tracking-for-woocommerce' ) . '</button>';
        echo '</div></div>';

        echo '</div>'; // settings-group

        echo '<div class="kitgenix-button-group kitgenix-analytics-mt-14">';
        submit_button( __( 'Add shipper', 'kitgenix-order-tracking-for-woocommerce' ), 'primary', 'submit', false );
        echo '</div>';
        echo '</form>';
        echo '</div>'; // card-body
        echo '</div>'; // sub-card

        // List table.
        echo '<div class="kitgenix-card kitgenix-carriers-subcard">';
        echo '<div class="kitgenix-card-head"><div class="kitgenix-card-head-main"><div class="kitgenix-card-head-text"><h2>' . esc_html__( 'All custom shippers', 'kitgenix-order-tracking-for-woocommerce' ) . '</h2></div></div></div>';
        echo '<div class="kitgenix-card-body">';

        if ( empty( $rows ) ) {
            echo '<div class="kitgenix-empty-state">';
            echo '<span class="kitgenix-empty-state-icon" aria-hidden="true">' . self::icon('plus') . '</span>';  // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-escaped, static trusted SVG markup from self::icon().
            echo '<h3 class="kitgenix-empty-state-title">' . esc_html__( 'No custom shippers yet', 'kitgenix-order-tracking-for-woocommerce' ) . '</h3>';
            echo '<p class="kitgenix-empty-state-desc">' . esc_html__( 'Add your first one on the left.', 'kitgenix-order-tracking-for-woocommerce' ) . '</p>';
            echo '</div>';
        } else {
            echo '<div class="kitgenix-table-wrap">';
            echo '<table class="kitgenix-table" id="kitgenix-order-tracking-custom-carriers-table">';
            echo '<thead><tr>';
            echo '<th>' . esc_html__( 'Name', 'kitgenix-order-tracking-for-woocommerce' ) . '</th>';
            echo '<th style="width:100px">' . esc_html__( 'Enabled', 'kitgenix-order-tracking-for-woocommerce' ) . '</th>';
            echo '<th style="width:110px">' . esc_html__( 'Priority', 'kitgenix-order-tracking-for-woocommerce' ) . '</th>';
            echo '<th>' . esc_html__( 'Tracking URL pattern', 'kitgenix-order-tracking-for-woocommerce' ) . '</th>';
            echo '<th>' . esc_html__( 'Icon', 'kitgenix-order-tracking-for-woocommerce' ) . '</th>';
            echo '<th class="kitgenix-table-actions-col">' . esc_html__( 'Actions', 'kitgenix-order-tracking-for-woocommerce' ) . '</th>';
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

                echo '<tr data-kitgenix-table-row' . ( $enabled ? '' : ' class="kitgenix-table-row-muted"' ) . '>';
                echo '<td><strong>' . esc_html( $label ) . '</strong></td>';
                echo '<td>' . ( $enabled
                    ? '<span class="kitgenix-badge success kitgenix-badge-dot">' . esc_html__( 'Yes', 'kitgenix-order-tracking-for-woocommerce' ) . '</span>'
                    : '<span class="kitgenix-badge neutral">' . esc_html__( 'No', 'kitgenix-order-tracking-for-woocommerce' ) . '</span>' ) . '</td>';
                echo '<td>' . esc_html( (string) $priority ) . '</td>';
                echo '<td><code>' . esc_html( $pattern ) . '</code></td>';
                if ( $icon !== '' ) {
                    echo '<td><a href="' . esc_url( $icon ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'View', 'kitgenix-order-tracking-for-woocommerce' ) . '</a></td>';
                } else {
                    echo '<td>&mdash;</td>';
                }

                echo '<td class="kitgenix-table-actions-col"><div class="kitgenix-table-actions">'
                    . '<a href="' . esc_url( $edit_href ) . '"'
                    . ' class="kitgenix-table-action-btn"'
                    . ' title="' . esc_attr__( 'Edit', 'kitgenix-order-tracking-for-woocommerce' ) . '"'
                    . ' data-kitgenix-custom-carrier-edit="1"'
                    . ' data-key="' . esc_attr( $key ) . '"'
                    . ' data-label="' . esc_attr( $label ) . '"'
                    . ' data-enabled="' . esc_attr( $enabled ? '1' : '0' ) . '"'
                    . ' data-priority="' . esc_attr( (string) $priority ) . '"'
                    . ' data-url-pattern="' . esc_attr( $pattern ) . '"'
                    . ' data-icon="' . esc_attr( $icon ) . '"'
                    . '>' . self::icon('list') . '<span class="kitgenix-sr-only">' . esc_html__( 'Edit', 'kitgenix-order-tracking-for-woocommerce' ) . '</span></a>'  // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-escaped, static trusted SVG markup from self::icon().
                    . '<a href="#" class="kitgenix-table-action-btn kitgenix-table-action-danger" title="' . esc_attr__( 'Delete', 'kitgenix-order-tracking-for-woocommerce' ) . '" data-kitgenix-custom-carrier-delete="1" data-key="' . esc_attr( $key ) . '">' . self::icon('alert') . '<span class="kitgenix-sr-only">' . esc_html__( 'Delete', 'kitgenix-order-tracking-for-woocommerce' ) . '</span></a>'  // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-escaped, static trusted SVG markup from self::icon().
                    . '</div></td>';

                echo '</tr>';
            }

            echo '</tbody></table></div>';
        }

        echo '</div>'; // card-body
        echo '</div>'; // sub-card

        echo '</div>'; // two-column
        echo '</div>'; // card-body
        echo '</div>'; // card

        // Hidden delete form (used by row Delete links and modal Delete button).
        echo '<form method="post" id="kitgenix-order-tracking-custom-carrier-delete-form" style="display:none">';
        wp_nonce_field( 'kitgenix_order_tracking_for_woocommerce_save_carriers', 'kitgenix_order_tracking_for_woocommerce_carriers_nonce' );
        echo '<input type="hidden" name="kitgenix_order_tracking_for_woocommerce_carriers_form" value="custom" />';
        echo '<input type="hidden" name="kitgenix_order_tracking_for_woocommerce_custom_action" value="delete" />';
        echo '<input type="hidden" name="kitgenix_order_tracking_for_woocommerce_custom_carrier[key]" value="" />';
        echo '</form>';

        // Modal popup editor.
        echo '<div class="kitgenix-modal" id="kitgenix-order-tracking-custom-carrier-modal" hidden role="dialog" aria-modal="true" aria-labelledby="kitgenix-order-tracking-custom-carrier-modal-title">'
            . '<div class="kitgenix-modal-backdrop" data-kitgenix-modal-close="1"></div>'
            . '<div class="kitgenix-modal-dialog" role="document">'
            . '<div class="kitgenix-modal-header">'
            . '<h2 class="kitgenix-modal-title" id="kitgenix-order-tracking-custom-carrier-modal-title">' . esc_html__( 'Edit shipper', 'kitgenix-order-tracking-for-woocommerce' ) . '</h2>'
            . '<button type="button" class="kitgenix-modal-close" data-kitgenix-modal-close="1" aria-label="' . esc_attr__( 'Close', 'kitgenix-order-tracking-for-woocommerce' ) . '">&times;</button>'
            . '</div>'
            . '<form method="post" id="kitgenix-order-tracking-custom-carrier-edit-form">'
            . '<div class="kitgenix-modal-body">';

        wp_nonce_field( 'kitgenix_order_tracking_for_woocommerce_save_carriers', 'kitgenix_order_tracking_for_woocommerce_carriers_nonce' );
        echo '<input type="hidden" name="kitgenix_order_tracking_for_woocommerce_carriers_form" value="custom" />';
        echo '<input type="hidden" name="kitgenix_order_tracking_for_woocommerce_custom_action" value="edit" />';
        echo '<input type="hidden" name="kitgenix_order_tracking_for_woocommerce_custom_carrier[key]" value="" />';

        echo '<div class="kitgenix-settings-group">';

        echo '<div class="kitgenix-setting-row">';
        echo '<div class="kitgenix-setting-row-label"><label>' . esc_html__( 'Enabled', 'kitgenix-order-tracking-for-woocommerce' ) . '</label></div>';
        echo '<div class="kitgenix-setting-row-control">';
        echo '<label class="kitgenix-toggle"><input type="checkbox" class="kitgenix-toggle-input" name="kitgenix_order_tracking_for_woocommerce_custom_carrier[enabled]" value="1" /><span class="kitgenix-toggle-track"><span class="kitgenix-toggle-thumb"></span></span></label>';
        echo '</div></div>';

        echo '<div class="kitgenix-setting-row">';
        echo '<div class="kitgenix-setting-row-label"><label for="kitgenix_order_tracking_modal_priority">' . esc_html__( 'Priority', 'kitgenix-order-tracking-for-woocommerce' ) . '</label></div>';
        echo '<div class="kitgenix-setting-row-control"><input type="number" id="kitgenix_order_tracking_modal_priority" class="small-text" name="kitgenix_order_tracking_for_woocommerce_custom_carrier[priority]" value="0" placeholder="0" /></div>';
        echo '</div>';

        echo '<div class="kitgenix-setting-row">';
        echo '<div class="kitgenix-setting-row-label"><label for="kitgenix_order_tracking_modal_label">' . esc_html__( 'Name', 'kitgenix-order-tracking-for-woocommerce' ) . '</label></div>';
        echo '<div class="kitgenix-setting-row-control"><input type="text" id="kitgenix_order_tracking_modal_label" class="regular-text" name="kitgenix_order_tracking_for_woocommerce_custom_carrier[label]" value="" required autofocus /></div>';
        echo '</div>';

        echo '<div class="kitgenix-setting-row kitgenix-setting-row-stacked">';
        echo '<div class="kitgenix-setting-row-label"><label for="kitgenix_order_tracking_modal_url_pattern">' . esc_html__( 'Tracking URL pattern', 'kitgenix-order-tracking-for-woocommerce' ) . '</label></div>';
        echo '<div class="kitgenix-setting-row-control"><input type="text" id="kitgenix_order_tracking_modal_url_pattern" class="regular-text" name="kitgenix_order_tracking_for_woocommerce_custom_carrier[url_pattern]" value="" placeholder="https://example.com/track/{tracking_number}" /></div>';
        echo '</div>';

        echo '<div class="kitgenix-setting-row kitgenix-setting-row-stacked">';
        echo '<div class="kitgenix-setting-row-label"><label for="kitgenix_order_tracking_modal_icon">' . esc_html__( 'Icon URL (optional)', 'kitgenix-order-tracking-for-woocommerce' ) . '</label></div>';
        echo '<div class="kitgenix-setting-row-control kitgenix-carriers-icon-field">';
        echo '<input type="url" id="kitgenix_order_tracking_modal_icon" class="regular-text" name="kitgenix_order_tracking_for_woocommerce_custom_carrier[icon]" value="" placeholder="https://example.com/icon.svg" />';
        echo '<button type="button" class="button kitgenix-order-tracking-for-woocommerce-icon-upload">' . esc_html__( 'Upload', 'kitgenix-order-tracking-for-woocommerce' ) . '</button>';
        echo '</div></div>';

        echo '</div>'; // settings-group

        echo '</div>'; // modal-body

        echo '<div class="kitgenix-modal-footer">';
        echo '<a href="#" class="button button-link-delete" id="kitgenix-order-tracking-custom-carrier-modal-delete">' . esc_html__( 'Delete', 'kitgenix-order-tracking-for-woocommerce' ) . '</a>';
        echo '<a href="#" class="button" data-kitgenix-modal-close="1">' . esc_html__( 'Cancel', 'kitgenix-order-tracking-for-woocommerce' ) . '</a>';
        submit_button( __( 'Save Changes', 'kitgenix-order-tracking-for-woocommerce' ), 'primary', 'submit', false );
        echo '</div>'; // modal-footer

        echo '</form>'
            . '</div></div>'; // dialog, modal
    }

    /**
     * Overview tab (dashboard feel).
     *
     * @param array<string,mixed> $data
     */
    private static function render_overview(array $data, int $range_days, DateTimeImmutable $from, DateTimeImmutable $now): void
    {
        echo '<div class="kitgenix-analytics-mt-14">';

        echo '<div class="kitgenix-stat-grid">';

        self::kpi_card(
            esc_html__('Orders in range', 'kitgenix-order-tracking-for-woocommerce'),
            number_format_i18n((int) $data['total_orders']),
            esc_html__('Total WooCommerce orders created in the selected period.', 'kitgenix-order-tracking-for-woocommerce'),
            'orders'
        );

        self::kpi_card(
            esc_html__('Shipment coverage', 'kitgenix-order-tracking-for-woocommerce'),
            esc_html((string) $data['coverage_percent']) . '%',
            esc_html(sprintf(
                /* translators: 1: number */
                __('%1$s orders have at least one shipment.', 'kitgenix-order-tracking-for-woocommerce'),
                number_format_i18n((int) $data['orders_with_shipments'])
            )),
            'coverage'
        );

        self::kpi_card(
            esc_html__('Avg time to ship', 'kitgenix-order-tracking-for-woocommerce'),
            esc_html((string) $data['avg_time_to_ship']) . ' ' . esc_html__('days', 'kitgenix-order-tracking-for-woocommerce'),
            esc_html__('From order created → earliest shipment date.', 'kitgenix-order-tracking-for-woocommerce'),
            'clock'
        );

        self::kpi_card(
            esc_html__('Median time to ship', 'kitgenix-order-tracking-for-woocommerce'),
            esc_html((string) $data['median_time_to_ship']) . ' ' . esc_html__('days', 'kitgenix-order-tracking-for-woocommerce'),
            esc_html__('Less affected by outliers than average.', 'kitgenix-order-tracking-for-woocommerce'),
            'clock'
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
                : esc_html__('No shipments found.', 'kitgenix-order-tracking-for-woocommerce'),
            'shipments'
        );

        self::kpi_card(
            esc_html__('Exception rate', 'kitgenix-order-tracking-for-woocommerce'),
            esc_html((string) $data['exception_order_rate']) . '%',
            esc_html(sprintf(
                /* translators: 1: number */
                __('%1$s orders have at least one exception shipment.', 'kitgenix-order-tracking-for-woocommerce'),
                number_format_i18n((int) $data['orders_with_exceptions'])
            )),
            'alert'
        );

        self::kpi_card(
            esc_html__('Tracking lookups', 'kitgenix-order-tracking-for-woocommerce'),
            number_format_i18n((int) $data['lookups_all_time']),
            esc_html__('All-time customer tracking page lookups.', 'kitgenix-order-tracking-for-woocommerce'),
            'eye'
        );

        self::kpi_card(
            esc_html__('Orders without shipments', 'kitgenix-order-tracking-for-woocommerce'),
            number_format_i18n((int) $data['orders_without_shipments']),
            esc_html__('Helpful for spotting unfulfilled/unstaged workflows.', 'kitgenix-order-tracking-for-woocommerce'),
            'inbox'
        );

        echo '</div>'; // stat-grid

        echo '<div class="kitgenix-analytics-two-column kitgenix-analytics-mt-10">';

        // Trend panel.
        echo '<div class="kitgenix-card">';
        echo '<div class="kitgenix-card-head"><div class="kitgenix-card-head-main"><div class="kitgenix-card-head-text"><h2>' . esc_html__('Shipments over time', 'kitgenix-order-tracking-for-woocommerce') . '</h2></div></div></div>';
        echo '<div class="kitgenix-card-body">';
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
                    'style' => true,
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
        echo '</div>'; // card-body
        echo '</div>'; // card

        // Insight panel (fast “at a glance”).
        echo '<div class="kitgenix-card">';
        echo '<div class="kitgenix-card-head"><div class="kitgenix-card-head-main"><div class="kitgenix-card-head-text"><h2>' . esc_html__('Insights', 'kitgenix-order-tracking-for-woocommerce') . '</h2></div></div></div>';
        echo '<div class="kitgenix-card-body">';

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

        echo '<div class="kitgenix-button-group kitgenix-analytics-mt-12">';
        echo '<a class="button button-secondary" href="' . esc_url($breakdown_url) . '">' . esc_html__('View breakdown →', 'kitgenix-order-tracking-for-woocommerce') . '</a>';
        echo '</div>';

        echo '</div>'; // card-body
        echo '</div>'; // card

        echo '</div>'; // two column

        echo '</div>';
    }

    /**
     * Small inline SVG icon set reused across stat tiles and card headers.
     */
    private static function icon(string $name): string
    {
        $paths = [
            'orders'    => '<path d="M3.5 6.5 10 3l6.5 3.5-6.5 3.5-6.5-3.5Zm0 0v7L10 17m6.5-10.5v7L10 17" stroke="currentColor" stroke-width="1.3" stroke-linecap="round" stroke-linejoin="round"/>',
            'coverage'  => '<path d="M4 10.5 8 14.5 16 6" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/>',
            'clock'     => '<circle cx="10" cy="10" r="6.5" stroke="currentColor" stroke-width="1.3"/><path d="M10 6.5V10l2.5 1.5" stroke="currentColor" stroke-width="1.3" stroke-linecap="round" stroke-linejoin="round"/>',
            'shipments' => '<path d="M3 5.5h9l4.5 4.5v4.5h-2M3 5.5v9h2M3 5.5 5 3h5l2 2.5M14 14.5H8" stroke="currentColor" stroke-width="1.3" stroke-linecap="round" stroke-linejoin="round"/><circle cx="6.5" cy="14.5" r="1.5" stroke="currentColor" stroke-width="1.3"/><circle cx="14.5" cy="14.5" r="1.5" stroke="currentColor" stroke-width="1.3"/>',
            'alert'     => '<path d="M10 3.5 17 16H3L10 3.5Z" stroke="currentColor" stroke-width="1.3" stroke-linejoin="round"/><path d="M10 8v4" stroke="currentColor" stroke-width="1.3" stroke-linecap="round"/><circle cx="10" cy="14" r="0.9" fill="currentColor"/>',
            'eye'       => '<path d="M2.5 10S5.5 4.5 10 4.5 17.5 10 17.5 10 14.5 15.5 10 15.5 2.5 10 2.5 10Z" stroke="currentColor" stroke-width="1.3" stroke-linejoin="round"/><circle cx="10" cy="10" r="2.25" stroke="currentColor" stroke-width="1.3"/>',
            'inbox'     => '<path d="M3 10.5h4l1.5 2h3l1.5-2h4M3 10.5 4.5 4h11L17 10.5m0 0V16a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1v-5.5" stroke="currentColor" stroke-width="1.3" stroke-linecap="round" stroke-linejoin="round"/>',
            'truck'     => '<path d="M3 5.5h6.5v7H3v-7Zm6.5 2h2.5L14.5 10v2.5h-5v-5Z" stroke="currentColor" stroke-width="1.3" stroke-linejoin="round"/><circle cx="6" cy="14.5" r="1.4" stroke="currentColor" stroke-width="1.3"/><circle cx="12.5" cy="14.5" r="1.4" stroke="currentColor" stroke-width="1.3"/>',
            'globe'     => '<circle cx="10" cy="10" r="6.5" stroke="currentColor" stroke-width="1.3"/><path d="M3.5 10h13M10 3.5c1.8 1.9 2.8 4.1 2.8 6.5S11.8 14.6 10 16.5C8.2 14.6 7.2 12.4 7.2 10S8.2 5.4 10 3.5Z" stroke="currentColor" stroke-width="1.3"/>',
            'plus'      => '<path d="M10 4.5v11M4.5 10h11" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>',
            'list'      => '<path d="M7 6h9M7 10h9M7 14h9M4 6h.01M4 10h.01M4 14h.01" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/>',
            'log'       => '<path d="M5 3.5h10v13H5v-13Z" stroke="currentColor" stroke-width="1.3" stroke-linejoin="round"/><path d="M7.5 7h5M7.5 10h5M7.5 13h3" stroke="currentColor" stroke-width="1.3" stroke-linecap="round"/>',
            'heart'     => '<path d="M10 16.5s-6-3.6-6-8.2a3.8 3.8 0 0 1 6-3.1 3.8 3.8 0 0 1 6 3.1c0 4.6-6 8.2-6 8.2Z" stroke="currentColor" stroke-width="1.3" stroke-linejoin="round"/>',
            'chat'      => '<path d="M3.5 5.5a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v6a2 2 0 0 1-2 2H9l-3.5 3v-3a2 2 0 0 1-2-2v-6Z" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/>',
            'star'      => '<path d="M10 3.2l2.02 4.1 4.53.66-3.28 3.2.78 4.52L10 13.5l-4.05 2.13.78-4.52-3.28-3.2 4.53-.66L10 3.2Z" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/>',
            'copy'      => '<rect x="7" y="7" width="9.5" height="9.5" rx="1.5" stroke="currentColor" stroke-width="1.5"/><path d="M13 7V4.5A1.5 1.5 0 0 0 11.5 3h-7A1.5 1.5 0 0 0 3 4.5v7A1.5 1.5 0 0 0 4.5 13H7" stroke="currentColor" stroke-width="1.5"/>',
            'users'     => '<circle cx="7" cy="7" r="2.5" stroke="currentColor" stroke-width="1.5"/><path d="M2.5 16c.5-3 2.2-4.5 4.5-4.5s4 1.5 4.5 4.5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/><circle cx="14" cy="7.5" r="2" stroke="currentColor" stroke-width="1.5"/><path d="M12.5 11.3c1.8.2 3 1.5 3.5 4.2" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>',
            'audit'     => '<path d="M5 3.5h7l3 3V16a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V4.5a1 1 0 0 1 1-1Z" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/><path d="M7 9h6M7 12h6" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>',
            'sync'      => '<path d="M16 10a6 6 0 0 1-10.6 3.8M4 10a6 6 0 0 1 10.6-3.8" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/><path d="M4 13.8V17h3.2M16 6.2V3h-3.2" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>',
            'tools'     => '<path d="M12.5 4.5a3 3 0 0 0-3.9 3.9l-6.1 6.1 2 2 6.1-6.1a3 3 0 0 0 3.9-3.9l-2.1 2.1-2-2 2.1-2.1Z" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/>',
            'search'    => '<circle cx="9" cy="9" r="5.5" stroke="currentColor" stroke-width="1.5"/><path d="M17 17l-3.5-3.5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>',
            'shield'    => '<path d="M10 2.5l6 2.2v4.4c0 4.2-2.6 7.4-6 8.4-3.4-1-6-4.2-6-8.4V4.7l6-2.2Z" stroke="currentColor" stroke-width="1.3" stroke-linejoin="round"/><path d="M7.3 10.2l1.9 1.9 3.5-3.9" stroke="currentColor" stroke-width="1.3" stroke-linecap="round" stroke-linejoin="round"/>',
            'paypal'    => '<path d="M6.7 15.5H4.9a.3.3 0 0 1-.3-.35L6.5 3.8a.5.5 0 0 1 .5-.4h4.4c2 0 3.4 1.2 3.1 3.2-.3 2.4-2.1 3.7-4.3 3.7H8.6a.5.5 0 0 0-.5.4l-.7 3.9a.5.5 0 0 1-.5.4Z" fill="currentColor" opacity=".55"/><path d="M9.2 15.5H7.4a.3.3 0 0 1-.3-.35L9 3.8a.5.5 0 0 1 .5-.4h4.4c2 0 3.4 1.2 3.1 3.2-.3 2.4-2.1 3.7-4.3 3.7h-1.6a.5.5 0 0 0-.5.4l-.7 3.9a.5.5 0 0 1-.5.4Z" fill="currentColor"/>',
        ];

        $path = $paths[ $name ] ?? $paths['orders'];

        return '<svg width="16" height="16" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">' . $path . '</svg>';
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
        echo '<div class="kitgenix-card">';
        echo '<div class="kitgenix-card-head"><div class="kitgenix-card-head-main"><div class="kitgenix-card-head-text"><h2>' . esc_html__('Recent shipments', 'kitgenix-order-tracking-for-woocommerce') . '</h2><p>' . esc_html__('The most recently recorded shipments in this date range.', 'kitgenix-order-tracking-for-woocommerce') . '</p></div></div></div>';
        echo '<div class="kitgenix-card-body">';

        if (empty($recent)) {
            echo '<div class="kitgenix-empty-state">';
            echo '<span class="kitgenix-empty-state-icon" aria-hidden="true">' . self::icon('truck') . '</span>';  // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-escaped, static trusted SVG markup from self::icon().
            echo '<h3 class="kitgenix-empty-state-title">' . esc_html__('No recent shipments', 'kitgenix-order-tracking-for-woocommerce') . '</h3>';
            echo '<p class="kitgenix-empty-state-desc">' . esc_html__('No recent shipments found in this date range.', 'kitgenix-order-tracking-for-woocommerce') . '</p>';
            echo '</div>';
            echo '</div>'; // card-body
            echo '</div>'; // card
            echo '</div>';
            return;
        }

        echo '<div class="kitgenix-table-wrap">';
        echo '<table class="kitgenix-table">';
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
            $status_variant  = self::status_badge_variant((string) $row['status']);

            echo '<tr data-kitgenix-table-row>';
            echo '<td>' . esc_html((string) $row['date']) . '</td>';
            echo '<td><a href="' . esc_attr($order_edit_link) . '">#' . esc_html((string) $row['order_number']) . '</a></td>';
            echo '<td class="truncate">' . esc_html((string) $row['carrier']) . '</td>';

            $tn = (string) $row['tracking_number'];
            echo '<td class="truncate" title="' . esc_attr($tn) . '">' . esc_html($tn !== '' ? $tn : '–') . '</td>';

            echo '<td><span class="kitgenix-badge ' . esc_attr($status_variant) . ' kitgenix-badge-dot">' . esc_html($status_label) . '</span></td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
        echo '</div>'; // table-wrap
        echo '</div>'; // card-body
        echo '</div>'; // card
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

    private static function kpi_card(string $title, string $value, string $sub = '', string $icon = 'orders'): void
    {
        echo '<div class="kitgenix-stat-tile">';
        echo '<div class="kitgenix-stat-tile-head">';
        echo '<span class="kitgenix-stat-tile-label">' . esc_html($title) . '</span>';
        echo '<span class="kitgenix-stat-tile-icon" aria-hidden="true">' . self::icon($icon) . '</span>';  // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-escaped, static trusted SVG markup from self::icon().
        echo '</div>';
        echo '<div class="kitgenix-stat-tile-value">' . esc_html($value) . '</div>';
        if ($sub !== '') {
            echo '<p class="kitgenix-stat-tile-sub">' . esc_html($sub) . '</p>';
        }
        echo '</div>';
    }

    /**
     * Ranked list panel with percentage bars (more “dashboard” than a plain table).
     *
     * @param array<string,int> $items
     */
    private static function render_ranked_panel(string $title, array $items, int $total, string $label, bool $status_pills = false): void
    {
        echo '<div class="kitgenix-card">';
        echo '<div class="kitgenix-card-head"><div class="kitgenix-card-head-main"><div class="kitgenix-card-head-text"><h2>' . esc_html($title) . '</h2></div></div></div>';
        echo '<div class="kitgenix-card-body">';

        if ($total === 0 || empty($items)) {
            echo '<div class="kitgenix-empty-state">';
            echo '<span class="kitgenix-empty-state-icon" aria-hidden="true">' . self::icon('shipments') . '</span>';  // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-escaped, static trusted SVG markup from self::icon().
            echo '<h3 class="kitgenix-empty-state-title">' . esc_html__('No shipments found', 'kitgenix-order-tracking-for-woocommerce') . '</h3>';
            echo '<p class="kitgenix-empty-state-desc">' . esc_html__('No shipments found in this date range.', 'kitgenix-order-tracking-for-woocommerce') . '</p>';
            echo '</div>';
            echo '</div>'; // card-body
            echo '</div>'; // card
            return;
        }

        $top = array_slice($items, 0, 10, true);

        echo '<div class="kitgenix-analytics-ranklist">';
        foreach ($top as $k => $count) {
            $percent = $total > 0 ? round(($count / $total) * 100, 1) : 0.0;

            echo '<div class="kitgenix-analytics-ranklist__row">';
            echo '<div class="kitgenix-analytics-ranklist__left">';
            if ($status_pills) {
                echo '<span class="kitgenix-badge ' . esc_attr(self::status_badge_variant((string) $k)) . ' kitgenix-badge-dot">' . esc_html(self::status_label((string) $k)) . '</span>';
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
        echo '</div>'; // ranklist
        echo '</div>'; // card-body
        echo '</div>'; // card
    }

    /**
     * Maps a shipment status key to a .kitgenix-badge color variant.
     */
    private static function status_badge_variant(string $key): string
    {
        $key = sanitize_key($key);

        $success = [ 'shipped', 'delivered', 'completed' ];
        $warning = [ 'exception', 'failed', 'returned' ];
        $danger  = [ 'error', 'cancelled', 'refunded' ];

        if (in_array($key, $success, true)) {
            return 'success';
        }
        if (in_array($key, $warning, true)) {
            return 'warning';
        }
        if (in_array($key, $danger, true)) {
            return 'danger';
        }

        return 'neutral';
    }

    private static function render_sidebar( string $active_tab = '' ): void
    {
        $social_base = KITGENIX_ORDER_TRACKING_FOR_WOOCOMMERCE_PLUGIN_URL . 'assets/images/social-media/';

        echo '<aside class="kitgenix-settings-sidebar" aria-label="' . esc_attr__( 'Help and links', 'kitgenix-order-tracking-for-woocommerce' ) . '">';

        echo '<div class="kitgenix-card">';
        echo '<div class="kitgenix-card-body">';
        echo '<h2>' . esc_html__( 'Need Help?', 'kitgenix-order-tracking-for-woocommerce' ) . '</h2>';
        echo '<p>' . esc_html__( 'Open the documentation for setup guidance or send us a support request if you need help configuring the plugin.', 'kitgenix-order-tracking-for-woocommerce' ) . '</p>';
        echo '<div class="kitgenix-button-group kitgenix-button-group-stack">';
        echo '<a class="button button-secondary" href="' . esc_url( 'https://kitgenix.com/plugins/kitgenix-order-tracking-for-woocommerce/documentation/' ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Documentation', 'kitgenix-order-tracking-for-woocommerce' ) . '</a>';
        echo '<a class="button button-primary" href="' . esc_url( 'https://kitgenix.com/plugins/kitgenix-order-tracking-for-woocommerce/support' ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Request Support', 'kitgenix-order-tracking-for-woocommerce' ) . '</a>';
        echo '</div>';
        echo '</div></div>';

        echo '<div class="kitgenix-card">';
        echo '<div class="kitgenix-card-body">';
        echo '<h2>' . esc_html__( 'Visit Our Official Facebook Group', 'kitgenix-order-tracking-for-woocommerce' ) . '</h2>';
        echo '<p>' . esc_html__( 'Join the Kitgenix community to ask questions, share feedback, and keep up with product updates.', 'kitgenix-order-tracking-for-woocommerce' ) . '</p>';
        echo '<div class="kitgenix-button-group kitgenix-button-group-stack">';
        echo '<a class="button button-secondary" href="' . esc_url( 'https://www.facebook.com/groups/kitgenix' ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Join Group', 'kitgenix-order-tracking-for-woocommerce' ) . '</a>';
        echo '</div>';
        echo '</div></div>';

        echo '<div class="kitgenix-card" aria-hidden="false">';
        echo '<div class="kitgenix-card-body">';
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
        echo '</div></div>';

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

        $hasData = $max !== $min;

        $points = [];
        for ($i = 0; $i < $count; $i++) {
            $x = $pad + ($i / max(1, $count - 1)) * $innerW;
            if (!$hasData) {
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

        $svg = '<svg width="' . (int) $w . '" height="' . (int) $h . '" viewBox="0 0 ' . (int) $w . ' ' . (int) $h . '" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">';

        if ($hasData) {
            // Real trend – full brand weight plus the tinted area fill beneath it.
            $fillPath = $path . ' L ' . ($pad + $innerW) . ' ' . ($pad + $innerH) . ' L ' . $pad . ' ' . ($pad + $innerH) . ' Z';
            $svg .= '<path d="' . esc_attr($fillPath) . '" style="fill:var(--kitgenix-brand-light,rgba(33,75,255,0.1));stroke:none"/>';
            $svg .= '<path d="' . esc_attr($path) . '" style="fill:none;stroke:var(--kitgenix-brand,#214BFF);stroke-width:2px;stroke-linejoin:round;stroke-linecap:round"/>';
        } else {
            // Nothing recorded yet – a thinner, muted-brand baseline instead of a
            // bold flat line that could be mistaken for a real (zero-change) trend.
            $svg .= '<path d="' . esc_attr($path) . '" style="fill:none;stroke:var(--kitgenix-brand-light,rgba(33,75,255,0.35));stroke-width:1.25px;stroke-linejoin:round;stroke-linecap:round"/>';
        }

        $svg .= '</svg>';

        return $svg;
    }

    private static function render_log(): void
    {
        echo '<div class="kitgenix-analytics-mt-14">';

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if ( isset( $_GET['kitgenix_log_cleared'] ) ) {
            echo '<div class="kitgenix-notice kitgenix-notice-success">';
            echo '<span class="kitgenix-notice-icon" aria-hidden="true">' . self::icon('coverage') . '</span>';  // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-escaped, static trusted SVG markup from self::icon().
            echo '<div class="kitgenix-notice-body"><p class="kitgenix-notice-text">' . esc_html__( 'Event log cleared.', 'kitgenix-order-tracking-for-woocommerce' ) . '</p></div>';
            echo '</div>';
        }

        $clear_url = \wp_nonce_url(
            admin_url( 'admin-post.php?action=kitgenix_order_tracking_clear_event_log' ),
            'kitgenix_order_tracking_clear_event_log'
        );

        $log_entries = Event_Log::get_raw_log();
        $table_id    = 'kitgenix-order-tracking-event-log-table';

        echo '<div class="kitgenix-card">';
        echo '<div class="kitgenix-card-head"><div>';
        echo '<h2 class="kitgenix-support-subheading">' . esc_html__( 'Activity Log', 'kitgenix-order-tracking-for-woocommerce' ) . '</h2>';
        echo '<p class="description">' . esc_html__( 'A record of recent plugin events. Entries show the timestamp, context, outcome, and a plain-English note. IP addresses and sensitive data are never stored here.', 'kitgenix-order-tracking-for-woocommerce' ) . '</p>';
        echo '</div>';
        echo '<div class="kitgenix-card-head-actions">';
        echo '<a href="' . esc_url( $clear_url ) . '" class="button button-secondary" onclick="return confirm(\'' . esc_js( __( 'Clear all log entries?', 'kitgenix-order-tracking-for-woocommerce' ) ) . '\')">' . esc_html__( 'Clear log', 'kitgenix-order-tracking-for-woocommerce' ) . '</a>';
        echo '</div>';
        echo '</div>'; // card-head

        echo '<div class="kitgenix-card-body">';

        if ( empty( $log_entries ) ) {
            echo '<div class="kitgenix-empty-state">';
            echo '<span class="kitgenix-empty-state-icon" aria-hidden="true">' . self::icon('log') . '</span>';  // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-escaped, static trusted SVG markup from self::icon().
            echo '<h3 class="kitgenix-empty-state-title">' . esc_html__( 'No events logged yet', 'kitgenix-order-tracking-for-woocommerce' ) . '</h3>';
            echo '<p class="kitgenix-empty-state-desc">' . esc_html__( 'Activity will appear here as it happens.', 'kitgenix-order-tracking-for-woocommerce' ) . '</p>';
            echo '</div>';
        } else {
            $format = (string) get_option( 'date_format' ) . ' ' . (string) get_option( 'time_format' );

            echo '<div class="kitgenix-search-bar">';
            echo '<div class="kitgenix-search-bar-input">';
            echo '<span class="kitgenix-search-bar-icon" aria-hidden="true">' . self::icon('search') . '</span>';  // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-escaped, static trusted SVG markup from self::icon().
            echo '<input type="search" placeholder="' . esc_attr__( 'Search log…', 'kitgenix-order-tracking-for-woocommerce' ) . '" data-kitgenix-table-search data-kitgenix-table-search-target="#' . esc_attr( $table_id ) . '" />';
            echo '</div>';
            echo '</div>';

            echo '<div class="kitgenix-table-wrap" id="' . esc_attr( $table_id ) . '" data-kitgenix-table-paginate="25">';
            echo '<table class="kitgenix-table">';
            echo '<thead><tr>';
            echo '<th>' . esc_html__( 'Time', 'kitgenix-order-tracking-for-woocommerce' ) . '</th>';
            echo '<th>' . esc_html__( 'Context', 'kitgenix-order-tracking-for-woocommerce' ) . '</th>';
            echo '<th>' . esc_html__( 'Outcome', 'kitgenix-order-tracking-for-woocommerce' ) . '</th>';
            echo '<th>' . esc_html__( 'Note', 'kitgenix-order-tracking-for-woocommerce' ) . '</th>';
            echo '</tr></thead><tbody>';

            foreach ( array_reverse( $log_entries ) as $entry ) {
                $time = isset( $entry['time'] ) ? (int) $entry['time'] : 0;
                if ( function_exists( 'wp_date' ) ) {
                    $when = $time ? (string) wp_date( $format, $time ) : '';
                } else {
                    $when = $time ? (string) date_i18n( $format, $time ) : '';
                }
                $when = $when !== '' ? $when : __( 'Unknown time', 'kitgenix-order-tracking-for-woocommerce' );

                $context = (string) ( $entry['context'] ?? '' );
                $outcome = (string) ( $entry['outcome'] ?? '' );
                $note    = (string) ( $entry['note'] ?? '' );

                $outcome_l = strtolower( $outcome );
                if ( in_array( $outcome_l, [ 'success', 'ok', 'completed' ], true ) ) {
                    $badge_variant = 'success';
                } elseif ( in_array( $outcome_l, [ 'error', 'failed', 'failure' ], true ) || strpos( $outcome_l, 'error' ) === 0 ) {
                    $badge_variant = 'danger';
                } else {
                    $badge_variant = 'neutral';
                }

                echo '<tr data-kitgenix-table-row>';
                echo '<td>' . esc_html( $when ) . '</td>';
                echo '<td><code>' . esc_html( $context ) . '</code></td>';
                echo '<td><span class="kitgenix-badge ' . esc_attr( $badge_variant ) . '">' . esc_html( $outcome ) . '</span></td>';
                echo '<td>' . esc_html( $note ) . '</td>';
                echo '</tr>';
            }

            echo '</tbody></table>';
            echo '<div class="kitgenix-empty-state" data-kitgenix-table-empty style="display:none;">';
            echo '<p class="kitgenix-empty-state-title">' . esc_html__( 'No matching log entries', 'kitgenix-order-tracking-for-woocommerce' ) . '</p>';
            echo '</div>';
            echo '</div>'; // table-wrap
        }

        echo '<div class="kitgenix-table-wrap kitgenix-analytics-mt-14">';
        echo '<table class="kitgenix-table">';
        echo '<thead><tr>';
        echo '<th>' . esc_html__( 'Category', 'kitgenix-order-tracking-for-woocommerce' ) . '</th>';
        echo '<th>' . esc_html__( 'What it means', 'kitgenix-order-tracking-for-woocommerce' ) . '</th>';
        echo '<th>' . esc_html__( 'Action needed?', 'kitgenix-order-tracking-for-woocommerce' ) . '</th>';
        echo '</tr></thead><tbody>';

        foreach ( Event_Log::get_known_codes() as $known_code ) {
            $reference = Event_Log::get_category_and_note( $known_code );
            echo '<tr>';
            echo '<td><code>' . esc_html( $reference['category'] ) . '</code></td>';
            echo '<td>' . esc_html( $reference['note'] ) . '</td>';
            echo '<td>' . esc_html( $reference['action'] ) . '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
        echo '</div>'; // table-wrap

        echo '</div>'; // card-body
        echo '</div>'; // card
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
        Event_Log::record( 'carriers-saved', 'success', __( 'Carrier settings were saved via the admin settings page.', 'kitgenix-order-tracking-for-woocommerce' ), 'carriers-saved' );
    }
}
