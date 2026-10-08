<?php
/**
 * Display helpers for the report: Persian digits, numbers, percents and
 * Jalali (Solar Hijri) dates. Admin-only.
 *
 * @package Zhayon\Funnel
 */

namespace Zhayon\Funnel\Admin;

defined( 'ABSPATH' ) || exit;

final class Format {

	const FA_DIGITS = array( '۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹' );
	const AR_DIGITS = array( '٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩' );
	const LATIN     = array( '0', '1', '2', '3', '4', '5', '6', '7', '8', '9' );

	/**
	 * Arabic Letter Mark. Prefixed to percents and signed changes so they read
	 * in Persian order everywhere (sign first, ٪ after the number), whether or
	 * not Persian text precedes them in the same line.
	 */
	const ALM = "\u{061C}";

	const MONTHS = array( 'فروردین', 'اردیبهشت', 'خرداد', 'تیر', 'مرداد', 'شهریور', 'مهر', 'آبان', 'آذر', 'دی', 'بهمن', 'اسفند' );

	public static function digits( string $value ): string {
		return str_replace( self::LATIN, self::FA_DIGITS, $value );
	}

	public static function latin_digits( string $value ): string {
		return str_replace( self::AR_DIGITS, self::LATIN, str_replace( self::FA_DIGITS, self::LATIN, $value ) );
	}

	/** 1234567 → ۱٬۲۳۴٬۵۶۷ */
	public static function num( $value, int $decimals = 0 ): string {
		return self::digits( number_format( (float) $value, $decimals, '٫', '٬' ) );
	}

	/** 0.1234 → ۱۲٫۳٪ ; null → — */
	public static function pct( ?float $ratio, int $decimals = 1 ): string {
		if ( null === $ratio ) {
			return '—';
		}
		$text = number_format( $ratio * 100, $decimals, '٫', '٬' );
		$text = preg_replace( '/٫0+$/', '', $text );
		return self::ALM . self::digits( $text ) . '٪';
	}

	/** Signed change, e.g. +۱۲٪ / −۴٫۵٪. */
	public static function delta( ?float $ratio, int $decimals = 0, string $unit = '٪' ): string {
		if ( null === $ratio ) {
			return '—';
		}
		$value = round( $ratio * 100, $decimals );
		$sign  = $value > 0 ? '+' : ( $value < 0 ? '−' : '' );
		$text  = preg_replace( '/٫0+$/', '', number_format( abs( $value ), $decimals, '٫', '٬' ) );
		return self::ALM . $sign . self::digits( $text ) . $unit;
	}

	/** Y-m-d → "۱۶ مهر ۱۴۰۵" (or without the year). */
	public static function jdate( string $ymd, bool $with_year = true ): string {
		$parts = array_map( 'intval', explode( '-', $ymd ) );
		if ( 3 !== count( $parts ) ) {
			return $ymd;
		}
		list( $jy, $jm, $jd ) = self::to_jalali( $parts[0], $parts[1], $parts[2] );
		$text = $jd . ' ' . self::MONTHS[ $jm - 1 ] . ( $with_year ? ' ' . $jy : '' );
		return self::digits( (string) $text );
	}

	/** Y-m-d → "1405/07/16" (Latin digits, for form values). */
	public static function jdate_input( string $ymd ): string {
		$parts = array_map( 'intval', explode( '-', $ymd ) );
		if ( 3 !== count( $parts ) ) {
			return '';
		}
		list( $jy, $jm, $jd ) = self::to_jalali( $parts[0], $parts[1], $parts[2] );
		return sprintf( '%04d/%02d/%02d', $jy, $jm, $jd );
	}

	/**
	 * Parse a user date — Jalali (1405/07/16) or Gregorian (2026-10-08), any
	 * digits — into Y-m-d. Null when invalid.
	 */
	public static function parse_date( string $input ): ?string {
		$input = trim( self::latin_digits( $input ) );
		if ( ! preg_match( '#^(\d{4})[/\-.](\d{1,2})[/\-.](\d{1,2})$#', $input, $m ) ) {
			return null;
		}
		list( $y, $mo, $d ) = array( (int) $m[1], (int) $m[2], (int) $m[3] );
		if ( $y < 1700 ) {
			if ( $mo < 1 || $mo > 12 || $d < 1 || $d > ( $mo <= 6 ? 31 : 30 ) ) {
				return null;
			}
			list( $y, $mo, $d ) = self::to_gregorian( $y, $mo, $d );
		}
		return checkdate( $mo, $d, $y ) ? sprintf( '%04d-%02d-%02d', $y, $mo, $d ) : null;
	}

	/** Gregorian → Jalali (arithmetic algorithm, valid for 1900–2100). */
	public static function to_jalali( int $gy, int $gm, int $gd ): array {
		$g_d_m = array( 0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334 );
		$gy2   = ( $gm > 2 ) ? ( $gy + 1 ) : $gy;
		$days  = 355666 + ( 365 * $gy ) + intdiv( $gy2 + 3, 4 ) - intdiv( $gy2 + 99, 100 ) + intdiv( $gy2 + 399, 400 ) + $gd + $g_d_m[ $gm - 1 ];
		$jy    = -1595 + ( 33 * intdiv( $days, 12053 ) );
		$days %= 12053;
		$jy   += 4 * intdiv( $days, 1461 );
		$days %= 1461;
		if ( $days > 365 ) {
			$jy  += intdiv( $days - 1, 365 );
			$days = ( $days - 1 ) % 365;
		}
		if ( $days < 186 ) {
			$jm = 1 + intdiv( $days, 31 );
			$jd = 1 + ( $days % 31 );
		} else {
			$jm = 7 + intdiv( $days - 186, 30 );
			$jd = 1 + ( ( $days - 186 ) % 30 );
		}
		return array( $jy, $jm, $jd );
	}

	/** Jalali → Gregorian. */
	public static function to_gregorian( int $jy, int $jm, int $jd ): array {
		$jy  += 1595;
		$days = -355668 + ( 365 * $jy ) + ( intdiv( $jy, 33 ) * 8 ) + intdiv( ( $jy % 33 ) + 3, 4 ) + $jd + ( ( $jm < 7 ) ? ( $jm - 1 ) * 31 : ( ( $jm - 7 ) * 30 ) + 186 );
		$gy   = 400 * intdiv( $days, 146097 );
		$days %= 146097;
		if ( $days > 36524 ) {
			$gy  += 100 * intdiv( --$days, 36524 );
			$days %= 36524;
			if ( $days >= 365 ) {
				++$days;
			}
		}
		$gy   += 4 * intdiv( $days, 1461 );
		$days %= 1461;
		if ( $days > 365 ) {
			$gy  += intdiv( $days - 1, 365 );
			$days = ( $days - 1 ) % 365;
		}
		$gd    = $days + 1;
		$leap  = ( 0 === $gy % 4 && 0 !== $gy % 100 ) || 0 === $gy % 400;
		$month = array( 0, 31, $leap ? 29 : 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31 );
		for ( $gm = 0; $gm < 13 && $gd > $month[ $gm ]; $gm++ ) {
			$gd -= $month[ $gm ];
		}
		return array( $gy, $gm, $gd );
	}
}
