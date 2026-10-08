<?php
/**
 * Uninstall: drop the counter table and delete the plugin's options,
 * transients and cron event (every site on multisite).
 *
 * Order meta (_zhf_ctx, _zhf_counted) is left on orders on purpose: it is a
 * few bytes per order and bulk-deleting order meta on a large store is
 * slower and riskier than leaving it.
 *
 * @package Zhayon\Funnel
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/** Remove this plugin's data from the current site. */
function zhf_uninstall_site() {
	global $wpdb;

	$table = $wpdb->prefix . 'zhf_funnel_daily';
	$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange

	delete_option( 'zhf_settings' );
	delete_option( 'zhf_db_version' );
	wp_clear_scheduled_hook( 'zhf_cleanup' );

	// Report caches (5-minute transients).
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
			$wpdb->esc_like( '_transient_zhf_r_' ) . '%',
			$wpdb->esc_like( '_transient_timeout_zhf_r_' ) . '%'
		)
	);
}

if ( is_multisite() ) {
	foreach ( get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) as $zhf_site_id ) {
		switch_to_blog( $zhf_site_id );
		zhf_uninstall_site();
		restore_current_blog();
	}
} else {
	zhf_uninstall_site();
}
