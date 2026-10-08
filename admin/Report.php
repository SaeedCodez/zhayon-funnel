<?php
/**
 * Report data: filters from the query string, aggregate queries, caching.
 *
 * Every query filters on the primary-key prefix (day BETWEEN …), and each
 * filter combination is cached in a 5-minute transient.
 *
 * @package Zhayon\Funnel
 */

namespace Zhayon\Funnel\Admin;

use Zhayon\Funnel\Plugin;
use Zhayon\Funnel\Settings;
use Zhayon\Funnel\Store;

defined( 'ABSPATH' ) || exit;

final class Report {

	const PRESETS   = array( 7, 14, 30, 90 );
	const MAX_DAYS  = 400;
	const CACHE_TTL = 300;

	/**
	 * Funnel order. 'guest' steps only happen for logged-out shoppers, so the
	 * funnel is open: their drop is measured inside the guest branch, and core
	 * steps are measured against the previous core step.
	 */
	const FUNNEL = array(
		'cart_view'     => 'core',
		'checkout_gate' => 'guest',
		'otp_sent'      => 'guest',
		'login'         => 'guest',
		'checkout_view' => 'core',
		'place_order'   => 'core',
		'order_created' => 'core',
		'paid'          => 'core',
	);

	/** Details that count as a success for steps that also log failures. */
	const OK_DETAILS = array(
		'otp_sent' => array( 'ok' ),
		'login'    => array( 'login', 'register' ),
	);

	/* ------------------------------------------------------------------ *
	 * Filters
	 * ------------------------------------------------------------------ */

	/** Normalised filters from $_GET. */
	public static function filters(): array {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only report filters.
		$get   = wp_unslash( $_GET );
		$today = Plugin::now()->format( 'Y-m-d' );

		$range = isset( $get['range'] ) ? sanitize_key( (string) $get['range'] ) : '30';
		$from  = null;
		$to    = null;

		if ( 'custom' === $range ) {
			$from = isset( $get['from'] ) ? Format::parse_date( (string) $get['from'] ) : null;
			$to   = isset( $get['to'] ) ? Format::parse_date( (string) $get['to'] ) : null;
			if ( ! $from || ! $to ) {
				$range = '30';
			} else {
				if ( $from > $to ) {
					list( $from, $to ) = array( $to, $from );
				}
				if ( self::days_between( $from, $to ) > self::MAX_DAYS ) {
					$from = self::shift( $to, -( self::MAX_DAYS - 1 ) );
				}
			}
		}
		if ( 'custom' !== $range ) {
			$days  = in_array( (int) $range, self::PRESETS, true ) ? (int) $range : 30;
			$range = (string) $days;
			$to    = $today;
			$from  = self::shift( $today, -( $days - 1 ) );
		}

		$device = isset( $get['device'] ) && in_array( $get['device'], array( 'm', 'd' ), true ) ? $get['device'] : 'all';
		$source = isset( $get['source'] ) ? Settings::sanitize_bucket( (string) $get['source'] ) : '';
		$source = '' === $source ? 'all' : $source;

		$compare = ! isset( $get['compare'] ) || '0' !== (string) $get['compare'];
		// phpcs:enable

		$length = self::days_between( $from, $to );

		return array(
			'range'     => $range,
			'from'      => $from,
			'to'        => $to,
			'days'      => $length,
			'prev_from' => self::shift( $from, -$length ),
			'prev_to'   => self::shift( $from, -1 ),
			'device'    => $device,
			'source'    => $source,
			'compare'   => $compare,
		);
	}

	/** Query args to rebuild a URL with these filters (optionally overridden). */
	public static function query_args( array $f, array $override = array() ): array {
		$args = array(
			'page'    => 'zhayon-funnel',
			'range'   => $f['range'],
			'device'  => 'all' === $f['device'] ? null : $f['device'],
			'source'  => 'all' === $f['source'] ? null : $f['source'],
			'compare' => $f['compare'] ? null : '0',
		);
		if ( 'custom' === $f['range'] ) {
			$args['from'] = $f['from'];
			$args['to']   = $f['to'];
		}
		return array_filter(
			array_merge( $args, $override ),
			static function ( $v ) {
				return null !== $v;
			}
		);
	}

	public static function days_between( string $from, string $to ): int {
		return (int) round( ( strtotime( $to . ' 12:00:00 UTC' ) - strtotime( $from . ' 12:00:00 UTC' ) ) / DAY_IN_SECONDS ) + 1;
	}

	public static function shift( string $ymd, int $days ): string {
		return gmdate( 'Y-m-d', strtotime( $ymd . ' 12:00:00 UTC' ) + $days * DAY_IN_SECONDS );
	}

	/* ------------------------------------------------------------------ *
	 * Data
	 * ------------------------------------------------------------------ */

	public static function data( array $f ): array {
		$key    = 'zhf_r_' . md5( wp_json_encode( array( $f, ZHF_VERSION, Store::DB_VERSION, (int) get_option( 'zhf_cache_gen', 0 ) ) ) );
		$cached = get_transient( $key );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		$data = self::build( $f );
		set_transient( $key, $data, self::CACHE_TTL );
		return $data;
	}

	private static function build( array $f ): array {
		global $wpdb;
		$table = Store::table();

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- table name is internal; values are prepared.
		$first_day = $wpdb->get_var( "SELECT MIN(day) FROM {$table}" );

		list( $where, $params ) = self::where( $f['from'], $f['to'], $f );
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT step, detail, device, source, SUM(uniques) AS u, SUM(hits) AS h FROM {$table} WHERE {$where} GROUP BY step, detail, device, source",
				$params
			),
			ARRAY_A
		);
		$daily_rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT day, step, SUM(uniques) AS u FROM {$table} WHERE {$where} AND step IN ('cart_view', 'paid') GROUP BY day, step",
				$params
			),
			ARRAY_A
		);

		$prev_rows = array();
		if ( $f['compare'] ) {
			list( $pwhere, $pparams ) = self::where( $f['prev_from'], $f['prev_to'], $f );
			$prev_rows                = $wpdb->get_results(
				$wpdb->prepare( "SELECT step, detail, SUM(uniques) AS u, SUM(hits) AS h FROM {$table} WHERE {$pwhere} GROUP BY step, detail", $pparams ),
				ARRAY_A
			);
		}
		// phpcs:enable

		$cur  = self::index( (array) $rows );
		$prev = self::index( (array) $prev_rows );

		// Daily series, every day present (zeros included).
		$daily = array();
		for ( $d = $f['from']; $d <= $f['to']; $d = self::shift( $d, 1 ) ) {
			$daily[ $d ] = array( 'cart_view' => 0, 'paid' => 0 );
		}
		foreach ( (array) $daily_rows as $r ) {
			if ( isset( $daily[ $r['day'] ] ) ) {
				$daily[ $r['day'] ][ $r['step'] ] = (int) $r['u'];
			}
		}

		return array(
			'first_day' => $first_day ? (string) $first_day : null,
			'has_rows'  => ! empty( $rows ),
			'cur'       => $cur,
			'prev'      => $f['compare'] ? $prev : null,
			'daily'     => $daily,
		);
	}

	/** WHERE clause on the PK prefix + optional device/source. */
	private static function where( string $from, string $to, array $f ): array {
		$where  = 'day BETWEEN %s AND %s';
		$params = array( $from, $to );
		if ( 'all' !== $f['device'] ) {
			$where   .= ' AND device = %s';
			$params[] = $f['device'];
		}
		if ( 'all' !== $f['source'] ) {
			$where   .= ' AND source = %s';
			$params[] = $f['source'];
		}
		return array( $where, $params );
	}

	/**
	 * Fold rows into lookups:
	 *   details[step][detail] = [u, h]
	 *   by_source[source][step] / by_device[device][step] = successful uniques
	 *   by_gateway[gateway][step] = uniques (order steps only)
	 */
	private static function index( array $rows ): array {
		$out = array(
			'details'    => array(),
			'by_source'  => array(),
			'by_device'  => array(),
			'by_gateway' => array(),
		);
		foreach ( $rows as $r ) {
			$step   = $r['step'];
			$detail = $r['detail'];
			$u      = (int) $r['u'];
			$h      = (int) $r['h'];

			if ( ! isset( $out['details'][ $step ][ $detail ] ) ) {
				$out['details'][ $step ][ $detail ] = array( 0, 0 );
			}
			$out['details'][ $step ][ $detail ][0] += $u;
			$out['details'][ $step ][ $detail ][1] += $h;

			$ok = isset( self::OK_DETAILS[ $step ] ) ? in_array( $detail, self::OK_DETAILS[ $step ], true ) : true;
			if ( $ok && isset( $r['source'] ) ) {
				$out['by_source'][ $r['source'] ][ $step ] = ( $out['by_source'][ $r['source'] ][ $step ] ?? 0 ) + $u;
				$out['by_device'][ $r['device'] ][ $step ] = ( $out['by_device'][ $r['device'] ][ $step ] ?? 0 ) + $u;
			}
			if ( in_array( $step, array( 'place_order', 'order_created', 'paid', 'payment_failed' ), true ) ) {
				$out['by_gateway'][ $detail ][ $step ] = ( $out['by_gateway'][ $detail ][ $step ] ?? 0 ) + $u;
			}
		}
		return $out;
	}

	/* ------------------------------------------------------------------ *
	 * Derived numbers for the views
	 * ------------------------------------------------------------------ */

	/** Successful uniques for a step. */
	public static function value( ?array $set, string $step ): int {
		if ( ! $set || empty( $set['details'][ $step ] ) ) {
			return 0;
		}
		$total = 0;
		foreach ( $set['details'][ $step ] as $detail => $counts ) {
			if ( ! isset( self::OK_DETAILS[ $step ] ) || in_array( (string) $detail, self::OK_DETAILS[ $step ], true ) ) {
				$total += $counts[0];
			}
		}
		return $total;
	}

	public static function ratio( int $part, int $whole ): ?float {
		return $whole > 0 ? $part / $whole : null;
	}

	/** Relative change, null when there is no previous value to compare to. */
	public static function change( int $now, ?int $before ): ?float {
		return ( null === $before || 0 === $before ) ? null : ( $now - $before ) / $before;
	}

	public static function kpis( array $data ): array {
		$cur  = $data['cur'];
		$prev = $data['prev'];
		$out  = array();
		foreach ( array( 'cart_view', 'checkout_view', 'order_created', 'paid' ) as $step ) {
			$now          = self::value( $cur, $step );
			$before       = null === $prev ? null : self::value( $prev, $step );
			$out[ $step ] = array(
				'value'  => $now,
				'prev'   => $before,
				'change' => self::change( $now, $before ),
			);
		}
		$conv      = self::ratio( $out['paid']['value'], $out['cart_view']['value'] );
		$prev_conv = null === $prev ? null : self::ratio( (int) $out['paid']['prev'], (int) $out['cart_view']['prev'] );

		$out['conversion'] = array(
			'value'  => $conv,
			'prev'   => $prev_conv,
			// Percentage points, not a relative change.
			'change' => ( null === $conv || null === $prev_conv ) ? null : $conv - $prev_conv,
		);
		return $out;
	}

	/** Funnel rows with share-of-first, drop from the previous step in its path, and the worst drop. */
	public static function funnel( array $data ): array {
		$cur   = $data['cur'];
		$prev  = $data['prev'];
		$rows  = array();
		$first = self::value( $cur, 'cart_view' );

		foreach ( self::FUNNEL as $step => $path ) {
			$value  = self::value( $cur, $step );
			$before = null === $prev ? null : self::value( $prev, $step );
			// Optional (guest) steps hide when this site never records them.
			if ( 'guest' === $path && 0 === $value && empty( $before ) ) {
				continue;
			}
			$rows[ $step ] = array(
				'step'   => $step,
				'path'   => $path,
				'value'  => $value,
				'prev'   => $before,
				'change' => self::change( $value, $before ),
				'share'  => self::ratio( $value, $first ),
				'drop'   => null,
				'worst'  => false,
			);
		}

		$last_core  = null;
		$last_guest = null;
		$worst      = null;
		foreach ( $rows as $step => &$row ) {
			$base = null;
			if ( 'core' === $row['path'] ) {
				$base      = $last_core;
				$last_core = $step;
			} else {
				$base       = $last_guest; // The guest branch's entry has no "previous".
				$last_guest = $step;
			}
			if ( null !== $base && $rows[ $base ]['value'] > 0 ) {
				$row['drop'] = 1 - $row['value'] / $rows[ $base ]['value'];
				if ( 'core' === $row['path'] && $row['drop'] > 0 && ( null === $worst || $row['drop'] > $rows[ $worst ]['drop'] ) ) {
					$worst = $step;
				}
			}
		}
		unset( $row );
		if ( null !== $worst ) {
			$rows[ $worst ]['worst'] = true;
		}
		return array_values( $rows );
	}

	/** Per-gateway order outcomes. */
	public static function gateways( array $data ): array {
		$titles = array();
		if ( function_exists( 'WC' ) && WC()->payment_gateways() ) {
			foreach ( WC()->payment_gateways()->payment_gateways() as $id => $gateway ) {
				$title          = $gateway->get_title();
				$titles[ $id ] = '' !== trim( wp_strip_all_tags( (string) $title ) ) ? wp_strip_all_tags( (string) $title ) : $gateway->get_method_title();
			}
		}
		$out = array();
		foreach ( $data['cur']['by_gateway'] as $id => $steps ) {
			$created = (int) ( $steps['order_created'] ?? 0 );
			$paid    = (int) ( $steps['paid'] ?? 0 );
			$out[]   = array(
				'id'      => (string) $id,
				'title'   => $titles[ $id ] ?? '',
				'place'   => (int) ( $steps['place_order'] ?? 0 ),
				'created' => $created,
				'paid'    => $paid,
				'failed'  => (int) ( $steps['payment_failed'] ?? 0 ),
				'success' => self::ratio( $paid, $created ),
			);
		}
		usort(
			$out,
			static function ( $a, $b ) {
				return ( $b['created'] + $b['place'] ) <=> ( $a['created'] + $a['place'] );
			}
		);
		return $out;
	}

	/** detail => [u, h] for a step, most frequent first. */
	public static function details( array $data, string $step, int $limit = 0 ): array {
		$rows = $data['cur']['details'][ $step ] ?? array();
		uasort(
			$rows,
			static function ( $a, $b ) {
				return array( $b[0], $b[1] ) <=> array( $a[0], $a[1] );
			}
		);
		return $limit ? array_slice( $rows, 0, $limit, true ) : $rows;
	}

	/** Cart views → paid per source or device. */
	public static function segments( array $data, string $key ): array {
		$out = array();
		foreach ( $data['cur'][ $key ] as $segment => $steps ) {
			$cart  = (int) ( $steps['cart_view'] ?? 0 );
			$paid  = (int) ( $steps['paid'] ?? 0 );
			if ( 0 === $cart && 0 === $paid ) {
				continue;
			}
			$out[] = array(
				'key'        => (string) $segment,
				'cart'       => $cart,
				'checkout'   => (int) ( $steps['checkout_view'] ?? 0 ),
				'paid'       => $paid,
				'conversion' => self::ratio( $paid, $cart ),
			);
		}
		usort(
			$out,
			static function ( $a, $b ) {
				return $b['cart'] <=> $a['cart'];
			}
		);
		return $out;
	}

	/* ------------------------------------------------------------------ *
	 * CSV export
	 * ------------------------------------------------------------------ */

	public static function export_rows( array $f ): array {
		global $wpdb;
		$table                  = Store::table();
		list( $where, $params ) = self::where( $f['from'], $f['to'], $f );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$sql = $wpdb->prepare( "SELECT day, step, device, source, detail, uniques, hits FROM {$table} WHERE {$where} ORDER BY day, step, device, source, detail", $params );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return (array) $wpdb->get_results( $sql, ARRAY_N );
	}
}
