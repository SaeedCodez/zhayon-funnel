<?php
/**
 * Traffic source → bucket, resolved once at a visit's first step.
 *
 * Reads WooCommerce Order Attribution's sourcebuster cookie (sbjs_current)
 * and falls back to the HTTP referer host, then maps the result through the
 * "domain contains → bucket" table from the settings.
 *
 * @package Zhayon\Funnel
 */

namespace Zhayon\Funnel;

defined( 'ABSPATH' ) || exit;

final class Sources {

	public static function resolve(): string {
		$haystack = self::from_sourcebuster();

		// sbjs says "typed in" (or is missing) but this request carries an
		// external referer: partner links that redirect straight to the cart
		// never run the attribution script, so the referer is the better signal.
		if ( '' === $haystack ) {
			$haystack = self::external_referer_host();
		}
		if ( '' === $haystack ) {
			return 'direct';
		}

		foreach ( (array) Settings::get( 'sources' ) as $row ) {
			if ( isset( $row['match'], $row['bucket'] ) && '' !== $row['match'] && false !== strpos( $haystack, $row['match'] ) ) {
				return Settings::sanitize_bucket( $row['bucket'] );
			}
		}
		return 'other';
	}

	/** The sbjs_current source (domain or utm_source), '' for direct / unknown. */
	private static function from_sourcebuster(): string {
		if ( empty( $_COOKIE['sbjs_current'] ) ) {
			return '';
		}
		$fields = array();
		foreach ( explode( '|||', (string) wp_unslash( $_COOKIE['sbjs_current'] ) ) as $pair ) {
			$kv = explode( '=', $pair, 2 );
			if ( 2 === count( $kv ) ) {
				$fields[ $kv[0] ] = strtolower( trim( $kv[1] ) );
			}
		}
		$typ = $fields['typ'] ?? '';
		$src = $fields['src'] ?? '';
		if ( in_array( $typ, array( 'typein', 'admin' ), true ) || in_array( $src, array( '', '(direct)', '(none)' ), true ) ) {
			return '';
		}
		return substr( $src, 0, 255 );
	}

	private static function external_referer_host(): string {
		if ( empty( $_SERVER['HTTP_REFERER'] ) ) {
			return '';
		}
		$host = wp_parse_url( (string) wp_unslash( $_SERVER['HTTP_REFERER'] ), PHP_URL_HOST );
		if ( ! is_string( $host ) || '' === $host ) {
			return '';
		}
		$host = strtolower( $host );
		$own  = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		return ( $host === $own || 'www.' . $host === $own || $host === 'www.' . $own ) ? '' : $host;
	}
}
