<?php
/**
 * Customer Partially Shipped Order email (plain text).
 *
 * @package Kitgenix\OrderTracking
 * @version 1.0.5
 */

defined( 'ABSPATH' ) || exit;

/**
 * Email data passed in from WooCommerce.
 *
 * @var \WC_Order $order         Order object.
 * @var \WC_Email $email         Email object.
 * @var string    $email_heading Email heading text.
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
// -- woocommerce_email_order_details/_order_meta/_customer_details are WooCommerce core's
// own mandatory email-template hooks, not hooks this plugin defines; this is the standard
// structure required for a custom WooCommerce email template.

// Ensure heading is safe plain text and escaped.
echo esc_html( wp_strip_all_tags( (string) $email_heading ) ) . "\n\n";

if ( ! empty( $order->get_billing_first_name() ) ) {
	printf(
		/* translators: %s: customer first name */
		esc_html__( 'Hi %s,', 'kitgenix-order-tracking-for-woocommerce' ),
		esc_html( $order->get_billing_first_name() )
	);
} else {
	esc_html_e( 'Hi,', 'kitgenix-order-tracking-for-woocommerce' );
}

echo "\n\n";

esc_html_e( 'Good news – part of your order has been shipped. The remaining items will be dispatched as soon as they are available.', 'kitgenix-order-tracking-for-woocommerce' );

echo "\n\n";

/*
 * Standard WooCommerce email sections.
 * Tracking is appended by Email_Hooks::append_tracking_to_email(),
 * which runs on woocommerce_email_after_order_table.
 */
// Render shipment tracking above the order summary for plain emails.
if ( class_exists( 'Kitgenix\\OrderTracking\\Emails\\Email_Hooks' ) ) {
	remove_action(
		'woocommerce_email_after_order_table',
		array( 'Kitgenix\\OrderTracking\\Emails\\Email_Hooks', 'append_tracking_to_email' ),
		10
	);

	\Kitgenix\OrderTracking\Emails\Email_Hooks::append_tracking_to_email( $order, false, true, $email );
}

do_action( 'woocommerce_email_order_details', $order, false, true, $email );
do_action( 'woocommerce_email_order_meta', $order, false, true, $email );
do_action( 'woocommerce_email_customer_details', $order, false, true, $email );

echo "\n";

if ( isset( $additional_content ) && $additional_content ) {
	echo esc_html( wp_strip_all_tags( wptexturize( $additional_content ) ) ) . "\n\n";
}

esc_html_e( 'Thank you for shopping with us.', 'kitgenix-order-tracking-for-woocommerce' );
echo "\n";
