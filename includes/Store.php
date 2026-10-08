<?php
/**
 * The one table: pre-aggregated daily counters, no event log.
 *
 * Row key = (day, step, device, source, detail). A tracked request performs
 * at most one indexed upsert; nothing on the storefront ever reads it.
 *
 * @package Zhayon\Funnel
 */

namespace Zhayon\Funnel;

defined( 'ABSPATH' ) || exit;

final class Store {

	const DB_VERSION = '1';
	const DB_OPTION  = 'zhf_db_version';

	/** Set when a write fails (e.g. table missing) so the rest of the request skips quietly. */
	private static $broken = false;

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'zhf_funnel_daily';
	}

	public static function install(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = self::table();
		$collate = $wpdb->get_charset_collate();

		dbDelta(
			"CREATE TABLE {$table} (
  day date NOT NULL,
  step varchar(24) NOT NULL,
  device char(1) NOT NULL,
  source varchar(16) NOT NULL,
  detail varchar(48) NOT NULL DEFAULT '',
  uniques int(10) unsigned NOT NULL DEFAULT 0,
  hits int(10) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY  (day,step,device,source,detail)
) {$collate};"
		);

		update_option( self::DB_OPTION, self::DB_VERSION, false );
	}

	/** Runs on the plugin's own admin page only (covers updates and network activation). */
	public static function maybe_install(): void {
		if ( self::DB_VERSION !== get_option( self::DB_OPTION ) ) {
			self::install();
		}
	}

	/**
	 * Add one occurrence to a counter row, creating it when needed.
	 *
	 * @param string $day    Ymd, site timezone.
	 * @param int    $unique 1 for the first time this visit reached the step today, else 0.
	 */
	public static function bump( string $day, string $step, string $device, string $source, string $detail, int $unique ): bool {
		global $wpdb;
		if ( self::$broken || ! preg_match( '/^\d{8}$/', $day ) ) {
			return false;
		}

		$suppress = $wpdb->suppress_errors( true );
		try {
			$table  = self::table();
			$result = $wpdb->query(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is internal.
					"INSERT INTO {$table} (day, step, device, source, detail, uniques, hits) VALUES (%s, %s, %s, %s, %s, %d, 1)
					ON DUPLICATE KEY UPDATE uniques = uniques + VALUES(uniques), hits = hits + 1",
					substr( $day, 0, 4 ) . '-' . substr( $day, 4, 2 ) . '-' . substr( $day, 6, 2 ),
					$step,
					$device,
					$source,
					$detail,
					$unique ? 1 : 0
				)
			);
		} catch ( \Throwable $e ) {
			$result = false;
		}
		$wpdb->suppress_errors( $suppress );

		if ( false === $result ) {
			self::$broken = true;
			return false;
		}
		return true;
	}

	/** Daily cron: drop rows older than the retention window. */
	public static function cleanup(): void {
		global $wpdb;
		$suppress = $wpdb->suppress_errors( true );
		try {
			$days   = max( 7, (int) Settings::get( 'retention' ) );
			$cutoff = Plugin::now()->modify( '-' . $days . ' days' )->format( 'Y-m-d' );
			$table  = self::table();
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE day < %s", $cutoff ) );
		} catch ( \Throwable $e ) {
			// Nothing to do; the next run retries.
		}
		$wpdb->suppress_errors( $suppress );
	}
}
