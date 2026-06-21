<?php
/**
 * Uninstall handler for Kitgenix Order Tracking for WooCommerce.
 *
 * Removes plugin counters/settings stored in the database.
 * Does not remove order meta, shipments, or other order history.
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/* phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching */
// Reason: uninstall script requires locating transient option names by prefix
// in order to safely delete them via the WP API. There is no WP function to
// list option names by LIKE; we only SELECT the option_name column and then
// remove entries through `delete_transient` / `delete_option` which clears
// cache properly. This is executed only during uninstall.

/**
 * Delete all transients (and their timeout rows) with a given key prefix.
 */
function kitgenix_order_tracking_for_woocommerce_delete_transients_by_prefix( string $prefix ): void {
	global $wpdb;
	if ( ! isset( $wpdb ) || ! is_object( $wpdb ) ) {
		return;
	}

	$transient_like = $wpdb->esc_like( '_transient_' . $prefix ) . '%';
	$timeout_like   = $wpdb->esc_like( '_transient_timeout_' . $prefix ) . '%';

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	// Reason: there is no WP API to list option names matching a LIKE prefix. We
	// SELECT the `option_name` column only and then delete each entry via the
	// WP API (`delete_transient` / `delete_option`) to ensure caches are cleared.
	$option_names = $wpdb->get_col(
		$wpdb->prepare(
			"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
			$transient_like,
			$timeout_like
		)
	);

	if ( ! empty( $option_names ) ) {
		foreach ( $option_names as $option_name ) {
			if ( strpos( $option_name, '_transient_timeout_' ) === 0 ) {
				$transient = substr( $option_name, strlen( '_transient_timeout_' ) );
			} elseif ( strpos( $option_name, '_transient_' ) === 0 ) {
				$transient = substr( $option_name, strlen( '_transient_' ) );
			} else {
				delete_option( $option_name );
				continue;
			}

			delete_transient( $transient );
		}
	}
}

/* phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching */

// Plugin counters/options.
delete_option( 'kitgenix_order_tracking_for_woocommerce_lookup_count' );
delete_option( 'kitgenix_order_tracking_for_woocommerce_tracking_numbers_added' );
delete_option( 'kitgenix_order_tracking_for_woocommerce_carriers_settings' );
delete_option( 'kitgenix_order_tracking_event_log' );

delete_site_option( 'kitgenix_order_tracking_for_woocommerce_lookup_count' );
delete_site_option( 'kitgenix_order_tracking_for_woocommerce_tracking_numbers_added' );
delete_site_option( 'kitgenix_order_tracking_for_woocommerce_carriers_settings' );

// Plugin-only transients.
kitgenix_order_tracking_for_woocommerce_delete_transients_by_prefix( 'kitgenix_order_tracking_for_woocommerce_' );

// Multisite: also remove per-site options.
	if ( is_multisite() && function_exists( 'get_sites' ) ) {
	$kitgenix_sites = get_sites( [ 'fields' => 'ids' ] );
	foreach ( (array) $kitgenix_sites as $kitgenix_site_id ) {
		switch_to_blog( (int) $kitgenix_site_id );
		delete_option( 'kitgenix_order_tracking_for_woocommerce_lookup_count' );
		delete_option( 'kitgenix_order_tracking_for_woocommerce_tracking_numbers_added' );
		delete_option( 'kitgenix_order_tracking_for_woocommerce_carriers_settings' );
		delete_option( 'kitgenix_order_tracking_event_log' );
		kitgenix_order_tracking_for_woocommerce_delete_transients_by_prefix( 'kitgenix_order_tracking_for_woocommerce_' );
		restore_current_blog();
	}
}
