<?php
/**
 * Step registry and the single write path.
 *
 * Loaded only after a request gate (see Plugin) has decided the request is a
 * funnel step and tracking is enabled.
 *
 * @package Zhayon\Funnel
 */

namespace Zhayon\Funnel;

defined( 'ABSPATH' ) || exit;

final class Tracker {

	/**
	 * Visit-bound steps.
	 *
	 * bit       Position in the visit cookie's bitmask.
	 * fail_bit  Separate bit for failed outcomes, so an early failure does not
	 *           swallow the later success's "unique" (and vice versa).
	 * ok        Details that count as success (everything else → fail_bit).
	 * detail    'none' (always ''), 'slug' (sanitized ≤48), or a whitelist.
	 * existing  Only count when the visit cookie already exists (came from cart/checkout).
	 */
	const STEPS = array(
		'cart_view'      => array( 'bit' => 0, 'detail' => 'none' ),
		'checkout_gate'  => array( 'bit' => 1, 'detail' => 'none' ),
		'otp_sent'       => array( 'bit' => 2, 'fail_bit' => 3, 'ok' => array( 'ok' ), 'detail' => 'slug', 'existing' => true ),
		'login'          => array( 'bit' => 4, 'fail_bit' => 5, 'ok' => array( 'login', 'register' ), 'detail' => 'slug', 'existing' => true ),
		'checkout_view'  => array( 'bit' => 6, 'detail' => 'none' ),
		'place_order'    => array( 'bit' => 7, 'detail' => 'slug' ),
		'checkout_error' => array( 'bit' => 8, 'detail' => 'slug' ),
		'order_created'  => array( 'bit' => 9, 'detail' => 'slug' ),
		'coupon_apply'   => array( 'bit' => 10, 'fail_bit' => 11, 'ok' => array( 'ok' ), 'detail' => array( 'ok', 'invalid' ) ),
	);

	/** Order-bound steps: counted from order meta, never from a cookie. */
	const ORDER_STEPS = array( 'paid', 'payment_failed' );

	/** Bits already handled in this request (a step counts once per request). */
	private $seen = array();

	/**
	 * Record a visit-bound step.
	 *
	 * @return Visit|null The visit it was counted against, or null when skipped.
	 */
	public function track( string $step, string $detail = '', array $context = array() ): ?Visit {
		if ( ! isset( self::STEPS[ $step ] ) ) {
			return null;
		}
		$def    = self::STEPS[ $step ];
		$detail = self::clean_detail( $def['detail'], $detail );
		$bit    = ( isset( $def['fail_bit'] ) && ! in_array( $detail, $def['ok'], true ) ) ? $def['fail_bit'] : $def['bit'];

		if ( isset( $this->seen[ $bit ] ) ) {
			return null;
		}
		$this->seen[ $bit ] = true;

		if ( ! $this->should_track( $step, $context ) ) {
			return null;
		}

		$visit = Visit::current( empty( $def['existing'] ) );
		if ( ! $visit ) {
			return null;
		}

		$unique = ! $visit->has( $bit );
		if ( $unique || apply_filters( 'zhf_count_repeat_hits', true, $step ) ) {
			$written = Store::bump( $visit->day, $step, $visit->device, $visit->source, $detail, $unique ? 1 : 0 );
			if ( $written && $unique ) {
				$visit->mark( $bit );
				$visit->save();
			}
		}
		return $visit;
	}

	/** order_created + remember the visit on the order for paid / payment_failed. */
	public function order_created( \WC_Order $order ): void {
		$visit = $this->track( 'order_created', (string) $order->get_payment_method() );
		if ( ! $visit || '' !== (string) $order->get_meta( Plugin::META_CTX ) ) {
			return; // Not tracked, or a retry of an order that already has its visit.
		}
		$order->update_meta_data( Plugin::META_CTX, $visit->day . '|' . $visit->device . '|' . $visit->source );
		$order->save_meta_data();
	}

	/**
	 * paid / payment_failed for an order that carries a visit context.
	 * Each order counts once; a paid after a failed attempt still counts as paid.
	 */
	public function order_outcome( \WC_Order $order, string $step ): void {
		if ( ! in_array( $step, self::ORDER_STEPS, true ) ) {
			return;
		}
		$counted = (string) $order->get_meta( Plugin::META_COUNTED );
		if ( 'paid' === $counted || ( 'payment_failed' === $step && 'failed' === $counted ) ) {
			return;
		}
		$ctx = explode( '|', (string) $order->get_meta( Plugin::META_CTX ) );
		if ( 3 !== count( $ctx ) || ! preg_match( '/^\d{8}$/', $ctx[0] ) || ! in_array( $ctx[1], array( 'm', 'd' ), true ) ) {
			return;
		}
		$context = array( 'order' => $order );
		if ( ! apply_filters( 'zhf_should_track', true, $step, $context ) ) {
			return;
		}

		$detail = self::clean_detail( 'slug', (string) $order->get_payment_method() );
		if ( Store::bump( $ctx[0], $step, $ctx[1], Settings::sanitize_bucket( $ctx[2] ), $detail, 1 ) ) {
			$order->update_meta_data( Plugin::META_COUNTED, 'paid' === $step ? 'paid' : 'failed' );
			$order->save_meta_data();
		}
	}

	/**
	 * Every exclusion rule, behind one filter.
	 *
	 * Visit steps skip: cron/CLI, HEAD, prefetch/prerender, empty or bot-like
	 * user agents, and users who can manage_woocommerce (unless "track
	 * administrators" is on). Order-bound steps are gated by the visit stored
	 * on the order instead and pass `$context['order']`.
	 */
	public function should_track( string $step, array $context = array() ): bool {
		return (bool) apply_filters( 'zhf_should_track', self::request_is_trackable( $context ), $step, $context );
	}

	private static function request_is_trackable( array $context ): bool {
		if ( wp_doing_cron() || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			return false;
		}

		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( (string) $_SERVER['REQUEST_METHOD'] ) : 'GET';
		if ( 'HEAD' === $method ) {
			return false;
		}

		$purpose = '';
		foreach ( array( 'HTTP_SEC_PURPOSE', 'HTTP_PURPOSE', 'HTTP_X_PURPOSE', 'HTTP_X_MOZ' ) as $header ) {
			if ( ! empty( $_SERVER[ $header ] ) ) {
				$purpose .= ' ' . strtolower( (string) $_SERVER[ $header ] );
			}
		}
		if ( '' !== $purpose && preg_match( '/prefetch|prerender|preview/', $purpose ) ) {
			return false;
		}

		$ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? trim( (string) $_SERVER['HTTP_USER_AGENT'] ) : '';
		if ( '' === $ua || self::is_bot( $ua ) ) {
			return false;
		}

		$user_id = isset( $context['user_id'] ) ? (int) $context['user_id'] : get_current_user_id();
		if ( $user_id && ! Settings::get( 'track_admins' ) && user_can( $user_id, 'manage_woocommerce' ) ) {
			return false;
		}
		return true;
	}

	private static function is_bot( string $ua ): bool {
		return (bool) preg_match(
			'~(?<!cu)bot\b|bot/|crawl|spider|slurp|mediapartners|facebookexternalhit|embedly|preview|headless|lighthouse|pagespeed|gtmetrix|pingdom|uptime|monitor|phantomjs|selenium|puppeteer|playwright|scrapy|python|curl|wget|httpclient|okhttp|java/|go-http|axios|node-fetch|guzzle|^mozilla/4\.0$~i',
			$ua
		);
	}

	/**
	 * @param string|array $rule 'none', 'slug' or a whitelist.
	 */
	public static function clean_detail( $rule, string $detail ): string {
		if ( 'none' === $rule ) {
			return '';
		}
		if ( is_array( $rule ) ) {
			return in_array( $detail, $rule, true ) ? $detail : 'other';
		}
		$slug = substr( sanitize_key( $detail ), 0, 48 );
		return '' === $slug ? 'other' : $slug;
	}
}
