<?php
/**
 * Main plugin bootstrap.
 *
 * @package Kitgenix\OrderTracking
 */

declare(strict_types=1);

namespace Kitgenix\OrderTracking;

defined('ABSPATH') || exit;

use Kitgenix\OrderTracking\Admin\Admin;
use Kitgenix\OrderTracking\Frontend\Shortcodes;
use Kitgenix\OrderTracking\Frontend\My_Account;
use Kitgenix\OrderTracking\Emails\Email_Hooks;
use Kitgenix\OrderTracking\Emails\Email_Partially_Shipped;
use Kitgenix\OrderTracking\Orders\Order_Tracking;

/**
 * Main plugin loader and register.
 */
final class Plugin {

    /**
     * Initialise plugin components.
     */
    public static function init(): void {

        add_action( 'before_woocommerce_init', static function () {
            if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
                \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
                    'custom_order_tables',
                    KITGENIX_ORDER_TRACKING_FOR_WOOCOMMERCE_PLUGIN_FILE,
                    true
                );
            }
        });

        // Core order-tracking logic.
        Order_Tracking::init();

        // Admin screens.
        if ( is_admin() ) {
            Admin::init();
        }

        // Frontend.
        Shortcodes::init();
        My_Account::init();

        // Email hooks.
        Email_Hooks::init();

        // Register custom order status + email classes.
        add_action( 'init', [ self::class, 'register_order_status' ] );
        add_filter( 'wc_order_statuses', [ self::class, 'add_order_status_to_list' ] );
        add_filter( 'woocommerce_email_classes', [ self::class, 'register_emails' ] );
    }

    /**
     * Register the custom order status.
     */
    public static function register_order_status(): void {

        register_post_status(
            'wc-partially-shipped',
            [
                'label'                     => \_x(
                    'Partially shipped',
                    'Order status',
                    'kitgenix-order-tracking-for-woocommerce'
                ),
                'public'                    => true,
                'exclude_from_search'       => false,
                'show_in_admin_all_list'    => true,
                'show_in_admin_status_list' => true,
                /* translators: %s: number of orders */
                'label_count'               => \_n_noop(
                    'Partially shipped <span class="count">(%s)</span>',
                    'Partially shipped <span class="count">(%s)</span>',
                    'kitgenix-order-tracking-for-woocommerce'
                ),
            ]
        );
    }

    /**
     * Add the custom order status into the status dropdown.
     *
     * @param array<string,string> $statuses Existing WooCommerce statuses.
     *
     * @return array<string,string>
     */
    public static function add_order_status_to_list( array $statuses ): array {

        $new_statuses = [];

        foreach ( $statuses as $key => $label ) {
            $new_statuses[ $key ] = $label;

            // Insert after "Processing".
            if ( 'wc-processing' === $key ) {
                $new_statuses['wc-partially-shipped'] = \_x(
                    'Partially shipped',
                    'Order status',
                    'kitgenix-order-tracking-for-woocommerce'
                );
            }
        }

        return $new_statuses;
    }

    /**
     * Register the custom WC email class.
     *
     * @param array<string,\WC_Email> $emails Existing email classes.
     *
     * @return array<string,\WC_Email>
     */
    public static function register_emails( array $emails ): array {

        if ( ! isset( $emails['kitgenix_partially_shipped'] ) ) {
            $emails['kitgenix_partially_shipped'] = new Email_Partially_Shipped();
        }

        return $emails;
    }
}
