<?php
/**
 * Plugin settings: one non-autoloaded option, read lazily and cached for the request.
 *
 * @package Zhayon\Funnel
 */

namespace Zhayon\Funnel;

defined( 'ABSPATH' ) || exit;

final class Settings {

	const OPTION = 'zhf_settings';
	const GROUP  = 'zhf_settings_group';

	/** Buckets every site has; they cannot be mapped from a domain. */
	const BUILTIN_BUCKETS = array( 'direct', 'other' );

	/** @var array|null */
	private static $cache = null;

	public static function defaults(): array {
		return array(
			'enabled'      => true,
			'retention'    => 120,
			'track_admins' => false,
			'color'        => '#0166ff',
			'sources'      => array(
				array( 'match' => 'torob', 'bucket' => 'torob' ),
				array( 'match' => 'api.torob.com', 'bucket' => 'torob' ),
				array( 'match' => 'pl.snapp.ir', 'bucket' => 'snapp' ),
				array( 'match' => 'snapppay', 'bucket' => 'snapp' ),
				array( 'match' => 'emalls', 'bucket' => 'emalls' ),
				array( 'match' => 'google', 'bucket' => 'google' ),
			),
		);
	}

	public static function all(): array {
		if ( null === self::$cache ) {
			$stored      = get_option( self::OPTION, array() );
			self::$cache = array_merge( self::defaults(), is_array( $stored ) ? $stored : array() );
		}
		return self::$cache;
	}

	/** @return mixed */
	public static function get( string $key ) {
		$all = self::all();
		return $all[ $key ] ?? null;
	}

	public static function flush(): void {
		self::$cache = null;
	}

	/** Create the option up-front so WordPress never autoloads it. */
	public static function ensure_option(): void {
		if ( false === get_option( self::OPTION, false ) ) {
			add_option( self::OPTION, self::defaults(), '', false );
		}
	}

	/** Every bucket a visit can land in (for the report's source filter). */
	public static function buckets(): array {
		$buckets = array();
		foreach ( (array) self::get( 'sources' ) as $row ) {
			$buckets[ $row['bucket'] ] = true;
		}
		foreach ( self::BUILTIN_BUCKETS as $bucket ) {
			$buckets[ $bucket ] = true;
		}
		return array_keys( $buckets );
	}

	public static function sanitize_bucket( string $value ): string {
		return substr( sanitize_key( $value ), 0, 16 );
	}

	/**
	 * Settings API sanitize callback. Invalid fields keep their previous value
	 * and raise a settings error.
	 *
	 * @param mixed $input
	 */
	public static function sanitize( $input ): array {
		$previous = self::all();
		$input    = is_array( $input ) ? $input : array();
		$clean    = array();

		$clean['enabled']      = ! empty( $input['enabled'] );
		$clean['track_admins'] = ! empty( $input['track_admins'] );

		$retention = isset( $input['retention'] ) ? (int) $input['retention'] : 0;
		if ( $retention < 7 || $retention > 3650 ) {
			add_settings_error( self::OPTION, 'zhf_retention', __( 'مدت نگهداری باید بین ۷ تا ۳۶۵۰ روز باشد؛ مقدار قبلی حفظ شد.', 'zhayon-funnel' ) );
			$retention = (int) $previous['retention'];
		}
		$clean['retention'] = $retention;

		$color = isset( $input['color'] ) ? sanitize_hex_color( strtolower( trim( (string) $input['color'] ) ) ) : '';
		if ( ! $color || 7 !== strlen( $color ) ) {
			add_settings_error( self::OPTION, 'zhf_color', __( 'رنگ اصلی باید کد شش‌رقمی هگز باشد، مثل ‎#0166ff؛ مقدار قبلی حفظ شد.', 'zhayon-funnel' ) );
			$color = $previous['color'];
		}
		$clean['color'] = $color;

		$sources = array();
		$seen    = array();
		$rows    = ( isset( $input['sources'] ) && is_array( $input['sources'] ) ) ? $input['sources'] : array();
		$dropped = 0;
		foreach ( $rows as $row ) {
			$match  = isset( $row['match'] ) ? strtolower( trim( sanitize_text_field( (string) $row['match'] ) ) ) : '';
			$bucket = isset( $row['bucket'] ) ? self::sanitize_bucket( (string) $row['bucket'] ) : '';
			if ( '' === $match && '' === $bucket ) {
				continue; // Empty row.
			}
			if ( '' === $match || '' === $bucket || isset( $seen[ $match ] ) || in_array( $bucket, self::BUILTIN_BUCKETS, true ) ) {
				++$dropped;
				continue;
			}
			$seen[ $match ] = true;
			$sources[]      = array(
				'match'  => substr( $match, 0, 64 ),
				'bucket' => $bucket,
			);
		}
		if ( $dropped ) {
			add_settings_error(
				self::OPTION,
				'zhf_sources',
				sprintf(
					/* translators: %s: number of ignored rows */
					__( '%s ردیف منبع نادیده گرفته شد (خالی، تکراری، یا با نام رزرو‌شدهٔ direct/other).', 'zhayon-funnel' ),
					str_replace( range( 0, 9 ), array( '۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹' ), (string) $dropped )
				),
				'warning'
			);
		}
		$clean['sources'] = $sources;

		self::flush();
		return $clean;
	}
}
