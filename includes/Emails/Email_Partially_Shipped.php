<?php
/**
 * Custom WooCommerce email: Partially Shipped.
 *
 * @package Kitgenix\OrderTracking
 */

declare(strict_types=1);

namespace Kitgenix\OrderTracking\Emails;

defined('ABSPATH') || exit;

use WC_Email;
use WC_Order;

class Email_Partially_Shipped extends WC_Email {

    /**
     * Constructor.
     */
    public function __construct() {

        $this->id          = 'kitgenix_partially_shipped';
        $this->title       = __( 'Kitgenix Partially Shipped', 'kitgenix-order-tracking-for-woocommerce' );
        $this->description = __( 'Sent to customers when their order status is changed to Partially Shipped.', 'kitgenix-order-tracking-for-woocommerce' );

        /* translators: %s: order number */
        $this->heading = __( 'Your order has been partially shipped', 'kitgenix-order-tracking-for-woocommerce' );

        /* translators: 1: site title 2: order number */
        $this->subject = __( 'Your {site_title} order {order_number} is partially shipped', 'kitgenix-order-tracking-for-woocommerce' );

        $this->customer_email  = true;
        $this->template_html   = 'emails/partially-shipped.php';
        $this->template_plain  = 'emails/plain/partially-shipped.php';

        parent::__construct();

        // Point WooCommerce to this plugin’s templates folder.
        $this->template_base = trailingslashit( dirname( KITGENIX_ORDER_TRACKING_FOR_WOOCOMMERCE_PLUGIN_FILE ) ) . 'templates/';
    }

    /**
     * Trigger the email.
     *
     * @param int            $order_id Order ID.
     * @param WC_Order|null  $order    Optional order object.
     *
     * @return void
     */
    public function trigger( $order_id, $order = null ): void {

        if ( $order_id && ! ( $order instanceof WC_Order ) ) {
            $order = wc_get_order( $order_id );
        }

        if ( ! $order instanceof WC_Order ) {
            return;
        }

        $this->object    = $order;
        $this->recipient = $order->get_billing_email();

        if ( ! $this->is_enabled() || ! $this->get_recipient() ) {
            return;
        }

        $this->placeholders = array_merge(
            $this->placeholders,
            [
                '{order_date}'   => wc_format_datetime( $order->get_date_created() ),
                '{order_number}' => $order->get_order_number(),
            ]
        );

        $this->send(
            $this->get_recipient(),
            $this->get_subject(),
            $this->get_content(),
            $this->get_headers(),
            $this->get_attachments()
        );
    }

    /**
     * Get HTML email content.
     *
     * @return string
     */
    public function get_content_html(): string {

        return wc_get_template_html(
            $this->template_html,
            [
                'order'         => $this->object,
                'email_heading' => $this->get_heading(),
                'sent_to_admin' => false,
                'plain_text'    => false,
                'email'         => $this,
            ],
            '',                 // Allow theme overrides (if user creates them)
            $this->template_base
        );
    }

    /**
     * Get plain-text email content.
     *
     * @return string
     */
    public function get_content_plain(): string {

        return wc_get_template_html(
            $this->template_plain,
            [
                'order'         => $this->object,
                'email_heading' => $this->get_heading(),
                'sent_to_admin' => false,
                'plain_text'    => true,
                'email'         => $this,
            ],
            '',
            $this->template_base
        );
    }
}

