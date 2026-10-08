<?php
/**
 * Uninstall: data is KEPT on purpose.
 *
 * Deleting the plugin only removes its cron event and report caches. The
 * counter table and settings stay, so reinstalling picks up where it left
 * off. To erase the funnel data, use «تنظیمات» → «حذف داده‌ها» in the plugin
 * (manual, confirmed) before deleting it.
 *
 * @package Zhayon\Funnel
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/** Remove only non-data leftovers from the current site. */
function zhf_uninstall_site() {
	global $wpdb;

	wp_clear_scheduled_hook( 'zhf_cleanup' );
	delete_option( 'zhf_cache_gen' );

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
