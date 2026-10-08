<?php
/**
 * Visit state, kept entirely in one small first-party cookie (no DB):
 *
 *   zhf_v = Ymd.bitmask.device.source     e.g. 20261008.65.m.torob
 *
 * The bitmask records which steps this visit already reached today, so a
 * step counts as a "unique" once and later repeats only bump `hits`.
 *
 * @package Zhayon\Funnel
 */

namespace Zhayon\Funnel;

defined( 'ABSPATH' ) || exit;

final class Visit {

	const PATTERN  = '/^(\d{8})\.(\d{1,5})\.([md])\.([a-z0-9_-]{1,16})$/';
	const MAX_BITS = 65535;

	/** @var string Ymd */
	public $day;
	/** @var int */
	public $bits;
	/** @var string 'm'|'d' */
	public $device;
	/** @var string Source bucket. */
	public $source;

	/** @var bool Cookie needs (re)sending. */
	private $dirty = false;

	/** @var Visit|null */
	private static $current = null;

	private function __construct( string $day, int $bits, string $device, string $source ) {
		$this->day    = $day;
		$this->bits   = $bits;
		$this->device = $device;
		$this->source = $source;
	}

	/**
	 * Today's visit. A missing, tampered or yesterday's cookie starts a new
	 * visit — unless $create is false, in which case only a valid cookie
	 * (from any day) qualifies.
	 */
	public static function current( bool $create = true ): ?self {
		if ( self::$current ) {
			return self::$current;
		}

		$today  = Plugin::now()->format( 'Ymd' );
		$stored = self::parse( isset( $_COOKIE[ ZHF_COOKIE ] ) ? (string) wp_unslash( $_COOKIE[ ZHF_COOKIE ] ) : '' );

		if ( $stored && $stored->day === $today ) {
			return self::$current = $stored;
		}
		if ( ! $stored && ! $create ) {
			return null;
		}

		// New visit (or a new day: the bitmask resets, source is re-read).
		$visit        = new self( $today, 0, wp_is_mobile() ? 'm' : 'd', Sources::resolve() );
		$visit->dirty = true;
		return self::$current = $visit;
	}

	public static function parse( string $value ): ?self {
		if ( ! preg_match( self::PATTERN, $value, $m ) ) {
			return null;
		}
		$bits = (int) $m[2];
		$year = (int) substr( $m[1], 0, 4 );
		if ( $bits > self::MAX_BITS || $year < 2000 || $year > 2100 || ! checkdate( (int) substr( $m[1], 4, 2 ), (int) substr( $m[1], 6, 2 ), (int) substr( $m[1], 0, 4 ) ) ) {
			return null;
		}
		return new self( $m[1], $bits, $m[3], $m[4] );
	}

	public function has( int $bit ): bool {
		return 0 !== ( $this->bits & ( 1 << $bit ) );
	}

	public function mark( int $bit ): void {
		if ( ! $this->has( $bit ) ) {
			$this->bits |= ( 1 << $bit );
			$this->dirty = true;
		}
	}

	public function value(): string {
		return $this->day . '.' . $this->bits . '.' . $this->device . '.' . $this->source;
	}

	/** Send the cookie if it changed. Expires at the end of the next day. */
	public function save(): void {
		if ( ! $this->dirty ) {
			return;
		}
		$this->dirty            = false;
		$_COOKIE[ ZHF_COOKIE ] = $this->value();

		if ( headers_sent() ) {
			return;
		}
		$expires = ( new \DateTimeImmutable( 'today', wp_timezone() ) )->modify( '+2 days' )->getTimestamp();
		setcookie(
			ZHF_COOKIE,
			$this->value(),
			array(
				'expires'  => $expires,
				'path'     => '/',
				'domain'   => defined( 'COOKIE_DOMAIN' ) && COOKIE_DOMAIN ? COOKIE_DOMAIN : '',
				'secure'   => is_ssl(),
				'httponly' => true,
				'samesite' => 'Lax',
			)
		);
	}
}
