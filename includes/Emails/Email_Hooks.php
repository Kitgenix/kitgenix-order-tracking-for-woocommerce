<?php
/**
 * Email hooks for Kitgenix Order Tracking.
 *
 * @package Kitgenix\OrderTracking
 */

declare(strict_types=1);

namespace Kitgenix\OrderTracking\Emails;

defined('ABSPATH') || exit;

use WC_Order;
use Kitgenix\OrderTracking\Orders\Order_Tracking;
use Kitgenix\OrderTracking\Support\Carriers;

final class Email_Hooks {

    /**
     * Initialise hooks.
     *
     * @return void
     */
    public static function init(): void {
        add_action(
            'woocommerce_email_after_order_table',
            [ self::class, 'append_tracking_to_email' ],
            10,
            4
        );

        // Also hook before the order table so we can render tracking
        // above the order summary for certain emails (e.g. completed).
        add_action(
            'woocommerce_email_before_order_table',
            [ self::class, 'append_tracking_to_email' ],
            10,
            4
        );

        add_action(
            'woocommerce_order_status_partially-shipped',
            [ self::class, 'trigger_partially_shipped_email' ],
            10,
            2
        );
    }

    /**
     * Append tracking details to WooCommerce emails.
     *
     * @param WC_Order       $order         The order object.
     * @param bool           $sent_to_admin Whether the email is sent to the admin.
     * @param bool           $plain_text    Whether the email is plain text.
     * @param \WC_Email|null $email         The email object.
     *
     * @return void
     */
    public static function append_tracking_to_email( $order, $sent_to_admin, $plain_text, $email ): void {

        if ( ! ( $order instanceof WC_Order ) || ! $email ) {
            return;
        }

        $allowed_email_ids = [
            'customer_completed_order',
            'kitgenix_partially_shipped',
        ];

        if ( ! in_array( $email->id, $allowed_email_ids, true ) ) {
            return;
        }

        $shipments = Order_Tracking::get_shipments( $order );

        if ( empty( $shipments ) ) {
            return;
        }

        // Avoid duplicate output when this method is attached to both
        // before/after hooks. Track printing per email+order.
        $hook = function_exists( 'current_filter' ) ? current_filter() : '';
        static $printed = [];
        $order_id = ( method_exists( $order, 'get_id' ) ) ? (int) $order->get_id() : 0;
        $printed_key = $email->id . '|' . $order_id;
        if ( isset( $printed[ $printed_key ] ) ) {
            return;
        }

        // For completed-order emails we prefer tracking above the order summary,
        // so when called via hooks ensure it runs on the 'before' hook.
        // For other emails prefer the 'after' hook. If this method is invoked
        // directly from a template (i.e. current_filter is not one of the
        // standard hooks), allow execution so templates can place the block
        // precisely where they need it.
        $known_hooks = [ 'woocommerce_email_before_order_table', 'woocommerce_email_after_order_table' ];
        if ( in_array( $hook, $known_hooks, true ) ) {
            if ( 'customer_completed_order' === $email->id || 'kitgenix_partially_shipped' === $email->id ) {
                if ( 'woocommerce_email_before_order_table' !== $hook ) {
                    return;
                }
            } else {
                if ( 'woocommerce_email_after_order_table' !== $hook ) {
                    return;
                }
            }
        }

        // Mark as printed to prevent duplicates if both hooks run.
        $printed[ $printed_key ] = true;

        // Build lookup of order items for item names + ordered qty.
        $items_lookup = [];
        foreach ( $order->get_items() as $item_id => $item ) {
            $items_lookup[ (int) $item_id ] = [
                'name'        => $item->get_name(),
                'ordered_qty' => (float) $item->get_quantity(),
            ];
        }

        // -----------------------------------------
        // Plain text email formatting
        // -----------------------------------------
        if ( $plain_text ) {

            echo "\n" . esc_html__( 'Shipment Tracking', 'kitgenix-order-tracking-for-woocommerce' ) . "\n";
            echo "-------------------------\n";

            foreach ( $shipments as $shipment ) {

                $carrier           = $shipment['carrier'] ?? '';
                $number            = $shipment['tracking_number'] ?? '';
                $url               = $shipment['tracking_url'] ?? '';
                $delivery_estimate = $shipment['delivery_estimate'] ?? '';

                printf(
                    /* translators: 1: Carrier name, 2: Tracking number */
                    "%s: %s\n",
                    esc_html( Carriers::get_label( $carrier ) ),
                    esc_html( $number )
                );

                if ( $delivery_estimate ) {
                    printf(
                        /* translators: %s: estimated delivery date */
                        "%s: %s\n",
                        esc_html__( 'Estimated delivery', 'kitgenix-order-tracking-for-woocommerce' ),
                        esc_html( $delivery_estimate )
                    );
                }

                if ( $url ) {
                    printf(
                        /* translators: %s: tracking URL */
                        "%s: %s\n",
                        esc_html__( 'Track your order', 'kitgenix-order-tracking-for-woocommerce' ),
                        esc_url( $url )
                    );
                }

                // Items per shipment.
                if ( ! empty( $shipment['items'] ) && is_array( $shipment['items'] ) && ! empty( $items_lookup ) ) {
                    echo esc_html__( 'Items in this shipment:', 'kitgenix-order-tracking-for-woocommerce' ) . "\n";

                    foreach ( $shipment['items'] as $s_item ) {
                        $s_item_id = isset( $s_item['item_id'] ) ? (int) $s_item['item_id'] : 0;
                        $s_qty     = isset( $s_item['qty'] ) ? (float) $s_item['qty'] : 0.0;

                        if ( $s_qty <= 0 || ! isset( $items_lookup[ $s_item_id ] ) ) {
                            continue;
                        }

                        $item_name   = $items_lookup[ $s_item_id ]['name'];
                        $ordered_qty = $items_lookup[ $s_item_id ]['ordered_qty'];

                        printf(
                            " - %s: %s / %s\n",
                            esc_html( $item_name ),
                            esc_html( wc_format_decimal( $s_qty, 0 ) ),
                            esc_html( wc_format_decimal( $ordered_qty, 0 ) )
                        );
                    }
                }

                echo "\n";
            }

            // Compute and print not-yet-shipped items (optional section).
            $remaining = [];
            if ( ! empty( $items_lookup ) ) {
                // Sum shipped qty per item across all shipments.
                $shipped_map = [];
                foreach ( $shipments as $shipment ) {
                    if ( empty( $shipment['items'] ) || ! is_array( $shipment['items'] ) ) {
                        continue;
                    }
                    foreach ( $shipment['items'] as $s_item ) {
                        $sid = isset( $s_item['item_id'] ) ? (int) $s_item['item_id'] : 0;
                        $sq  = isset( $s_item['qty'] ) ? (float) $s_item['qty'] : 0.0;
                        if ( $sid && $sq > 0 ) {
                            $shipped_map[ $sid ] = ( $shipped_map[ $sid ] ?? 0 ) + $sq;
                        }
                    }
                }

                foreach ( $items_lookup as $item_id => $info ) {
                    $ordered = (float) $info['ordered_qty'];
                    $shipped = isset( $shipped_map[ $item_id ] ) ? (float) $shipped_map[ $item_id ] : 0.0;
                    $left    = max( 0.0, $ordered - $shipped );
                    if ( $left > 0 ) {
                        $remaining[] = [ 'name' => $info['name'], 'qty' => $left, 'ordered' => $ordered ];
                    }
                }
            }

            if ( ! empty( $remaining ) ) {
                echo esc_html__( 'Not yet shipped items:', 'kitgenix-order-tracking-for-woocommerce' ) . "\n";
                foreach ( $remaining as $r ) {
                    printf(
                        " - %s: %s of %s\n",
                        esc_html( $r['name'] ),
                        esc_html( wc_format_decimal( $r['qty'], 0 ) ),
                        esc_html( wc_format_decimal( $r['ordered'], 0 ) )
                    );
                }
                echo "\n";
            }

            return;
        }

        // -----------------------------------------
        // HTML email formatting
        // -----------------------------------------
        // Match WooCommerce core email templates: rely on email-styles.php
        // (which Woo inlines into the email HTML) instead of hard-coded inline styles.

        echo '<h2>' . esc_html__( 'Shipment Tracking', 'kitgenix-order-tracking-for-woocommerce' ) . '</h2>';

        // Use the same class name as order tables so Woo's email CSS applies.
        echo '<table class="email-order-details" cellspacing="0" cellpadding="6" border="0" width="100%">';
        echo '<thead><tr>';
        echo '<th class="td" scope="col" align="left">' . esc_html__( 'Carrier', 'kitgenix-order-tracking-for-woocommerce' ) . '</th>';
        echo '<th class="td" scope="col" align="left">' . esc_html__( 'Tracking', 'kitgenix-order-tracking-for-woocommerce' ) . '</th>';
        echo '<th class="td" scope="col" align="left">' . esc_html__( 'Estimated delivery', 'kitgenix-order-tracking-for-woocommerce' ) . '</th>';
        echo '<th class="td" scope="col" align="left">' . esc_html__( 'Track', 'kitgenix-order-tracking-for-woocommerce' ) . '</th>';
        echo '</tr></thead>';
        echo '<tbody>';

        foreach ( $shipments as $shipment ) {

            $carrier           = $shipment['carrier'] ?? '';
            $number            = $shipment['tracking_number'] ?? '';
            $url               = $shipment['tracking_url'] ?? '';
            $delivery_estimate = $shipment['delivery_estimate'] ?? '';

            echo '<tr>';
            echo '<td class="td" align="left">' . esc_html( Carriers::get_label( $carrier ) ) . '</td>';
            echo '<td class="td" align="left">' . esc_html( $number );

            // If this shipment includes item references, show a compact items line beneath the tracking number.
            if ( ! empty( $shipment['items'] ) && is_array( $shipment['items'] ) && ! empty( $items_lookup ) ) {
                $parts = [];
                foreach ( $shipment['items'] as $s_item ) {
                    $s_item_id = isset( $s_item['item_id'] ) ? (int) $s_item['item_id'] : 0;
                    $s_qty     = isset( $s_item['qty'] ) ? (float) $s_item['qty'] : 0.0;

                    if ( $s_qty <= 0 || ! isset( $items_lookup[ $s_item_id ] ) ) {
                        continue;
                    }

                    $item_name   = $items_lookup[ $s_item_id ]['name'];
                    $ordered_qty = $items_lookup[ $s_item_id ]['ordered_qty'];

                    $parts[] = sprintf(
                        /* translators: 1: item name, 2: shipped qty, 3: ordered qty. */
                        '%1$s × %2$s/%3$s',
                        wp_strip_all_tags( $item_name ),
                        esc_html( wc_format_decimal( $s_qty, 0 ) ),
                        esc_html( wc_format_decimal( $ordered_qty, 0 ) )
                    );
                }

                if ( ! empty( $parts ) ) {
                    echo '<div class="email-order-item-meta">' . esc_html__( 'Items:', 'kitgenix-order-tracking-for-woocommerce' ) . ' ' . esc_html( implode( ', ', $parts ) ) . '</div>';
                }
            }

            echo '</td>';

            echo '<td class="td" align="left">' . esc_html( $delivery_estimate ?: '–' ) . '</td>';

            echo '<td class="td" align="left">';
            if ( $url ) {
                echo '<a href="' . esc_url( $url ) . '" class="link">' . esc_html__( 'Track your order', 'kitgenix-order-tracking-for-woocommerce' ) . '</a>';
            }
            echo '</td>';

            echo '</tr>';
        }

        echo '</tbody></table>';

        // Optional: Not yet shipped section in HTML email.
        $items_lookup_names = $items_lookup; // reuse
        $remaining = [];
        if ( ! empty( $items_lookup_names ) ) {
            $shipped_map = [];
            foreach ( $shipments as $shipment ) {
                if ( empty( $shipment['items'] ) || ! is_array( $shipment['items'] ) ) {
                    continue;
                }
                foreach ( $shipment['items'] as $s_item ) {
                    $sid = isset( $s_item['item_id'] ) ? (int) $s_item['item_id'] : 0;
                    $sq  = isset( $s_item['qty'] ) ? (float) $s_item['qty'] : 0.0;
                    if ( $sid && $sq > 0 ) {
                        $shipped_map[ $sid ] = ( $shipped_map[ $sid ] ?? 0 ) + $sq;
                    }
                }
            }

            foreach ( $items_lookup_names as $item_id => $info ) {
                $ordered = (float) $info['ordered_qty'];
                $shipped = isset( $shipped_map[ $item_id ] ) ? (float) $shipped_map[ $item_id ] : 0.0;
                $left    = max( 0.0, $ordered - $shipped );
                if ( $left > 0 ) {
                    $remaining[] = [ 'name' => $info['name'], 'qty' => $left, 'ordered' => $ordered ];
                }
            }
        }

        if ( ! empty( $remaining ) ) {
            echo '<h3>' . esc_html__( 'Not yet shipped items', 'kitgenix-order-tracking-for-woocommerce' ) . '</h3>';
            echo '<ul>';
            foreach ( $remaining as $r ) {
                echo '<li>';
                printf(
                    /* translators: 1: item name, 2: remaining qty, 3: ordered qty. */
                    esc_html__( '%1$s × %2$s of %3$s ordered', 'kitgenix-order-tracking-for-woocommerce' ),
                    esc_html( $r['name'] ),
                    esc_html( wc_format_decimal( $r['qty'], 0 ) ),
                    esc_html( wc_format_decimal( $r['ordered'], 0 ) )
                );
                echo '</li>';
            }
            echo '</ul>';
        }
    }

    /**
     * Trigger "Partially Shipped" email.
     *
     * @param int      $order_id Order ID.
     * @param WC_Order $order    The order object (optional).
     *
     * @return void
     */
    public static function trigger_partially_shipped_email( int $order_id, $order ): void {

        if ( ! ( $order instanceof WC_Order ) ) {
            $order = wc_get_order( $order_id );
        }

        if ( ! $order instanceof WC_Order ) {
            return;
        }

        $mailer = WC()->mailer();
        $emails = $mailer->get_emails();

        if ( isset( $emails['kitgenix_partially_shipped'] ) ) {
            $emails['kitgenix_partially_shipped']->trigger( $order_id, $order );
        }
    }
}
