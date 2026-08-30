<?php
/**
 * Customer Partially Shipped Order email (HTML).
 *
 * This template can be overridden by copying it to:
 * yourtheme/woocommerce/emails/partially-shipped.php
 *
 * @package Kitgenix\OrderTracking
 * @version 1.0.5
 */

defined( 'ABSPATH' ) || exit;
use Automattic\WooCommerce\Utilities\FeaturesUtil;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
// -- woocommerce_email_header/_order_details/_order_meta/_customer_details/_footer are
// WooCommerce core's own mandatory email-template hooks, not hooks this plugin defines;
// this is the standard structure required for a custom WooCommerce email template.

/**
 * Email data passed in from WooCommerce.
 *
 * @var \WC_Order $order         Order object.
 * @var \WC_Email $email         Email object.
 * @var string    $email_heading Email heading text.
 */

/*
 * Follow WooCommerce email improvements structure so the email
 * matches the new core templates and uses the site branding
 * provided by the header/footer templates.
 */

$kitgenix_email_improvements_enabled = FeaturesUtil::feature_is_enabled( 'email_improvements' );

/*
 * @hooked WC_Emails::email_header() Output the email header
 */
do_action( 'woocommerce_email_header', $email_heading, $email ); ?>

<?php echo $kitgenix_email_improvements_enabled ? '<div class="email-introduction">' : ''; ?>
<p>
<?php
if ( ! empty( $order->get_billing_first_name() ) ) {
	/* translators: %s: Customer first name */
	printf( esc_html__( 'Hi %s,', 'kitgenix-order-tracking-for-woocommerce' ), esc_html( $order->get_billing_first_name() ) );
} else {
	printf( esc_html__( 'Hi,', 'kitgenix-order-tracking-for-woocommerce' ) );
}
?>
</p>
<p><?php esc_html_e( 'Good news – part of your order has been shipped. The remaining items will be dispatched as soon as they are available.', 'kitgenix-order-tracking-for-woocommerce' ); ?></p>
<?php if ( $kitgenix_email_improvements_enabled ) : ?>
	<p><?php esc_html_e( 'Here’s a reminder of what you’ve ordered:', 'kitgenix-order-tracking-for-woocommerce' ); ?></p>
<?php endif; ?>
<?php echo $kitgenix_email_improvements_enabled ? '</div>' : ''; ?>

<?php
/*
 * @hooked WC_Emails::order_details() Shows the order details table.
 * @hooked WC_Structured_Data::generate_order_data() Generates structured data.
 * @hooked WC_Structured_Data::output_structured_data() Outputs structured data.
 * @since 2.5.0
 */
do_action( 'woocommerce_email_order_details', $order, false, false, $email );

/*
 * @hooked WC_Emails::order_meta() Shows order meta data.
 */
// Render shipment tracking above the order summary. Prevent duplicate rendering
// by removing the plugin's hooked output before calling the renderer directly.
if ( class_exists( 'Kitgenix\\OrderTracking\\Emails\\Email_Hooks' ) ) {
	remove_action(
		'woocommerce_email_after_order_table',
		array( 'Kitgenix\\OrderTracking\\Emails\\Email_Hooks', 'append_tracking_to_email' ),
		10
	);

	\Kitgenix\OrderTracking\Emails\Email_Hooks::append_tracking_to_email( $order, false, false, $email );
}

do_action( 'woocommerce_email_order_meta', $order, false, false, $email );

/*
 * @hooked WC_Emails::customer_details() Shows customer details
 * @hooked WC_Emails::email_address() Shows email address
 */
do_action( 'woocommerce_email_customer_details', $order, false, false, $email );

/**
 * Show user-defined additional content - this is set in each email's settings.
 */
if ( isset( $additional_content ) && $additional_content ) {
	echo $kitgenix_email_improvements_enabled ? '<table border="0" cellpadding="0" cellspacing="0" width="100%" role="presentation"><tr><td class="email-additional-content">' : '';
	echo wp_kses_post( wpautop( wptexturize( $additional_content ) ) );
	echo $kitgenix_email_improvements_enabled ? '</td></tr></table>' : '';
}

/*
 * @hooked WC_Emails::email_footer() Output the email footer
 */
do_action( 'woocommerce_email_footer', $email );
