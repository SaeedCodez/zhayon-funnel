<?php
/**
 * Bootstrap: autoloader, lifecycle hooks and the cheap request gates.
 *
 * Only this class is loaded on every request. The gates below decide from
 * the request shape alone (method, cookie, WooCommerce conditionals) whether
 * a request can be a funnel step; Tracker, Store, Visit, Sources and Settings
 * are autoloaded only after a gate says yes. Pages that are not cart or
 * checkout (home, product, shop, ...) run no plugin query at all.
 *
 * @package Zhayon\Funnel
 */

namespace Zhayon\Funnel;

defined( 'ABSPATH' ) || exit;

final class Plugin {

	/** Daily retention-cleanup cron hook. */
	const CRON_HOOK = 'zhf_cleanup';

	/** Order meta: the visit an order came from (Ymd|device|source), and the counted outcome. */
	const META_CTX     = '_zhf_ctx';
	const META_COUNTED = '_zhf_counted';

	/** Order statuses a payment leaves from / lands on. */
	const PAY_FROM  = array( 'pending', 'failed', 'checkout-draft' );
	const PAY_TO    = array( 'processing', 'completed', 'on-hold' );
	const FAIL_FROM = array( 'pending', 'checkout-draft' );
	const FAIL_TO   = array( 'failed', 'cancelled' );

	/** @var Tracker|null|false false = not resolved yet this request. */
	private static $tracker = false;

	/** Guest checkout outcome is decided once per request (redirect vs render). */
	private static $guest_checkout_pending = false;

	public static function boot(): void {
		spl_autoload_register( array( __CLASS__, 'autoload' ) );

		register_activation_hook( ZHF_FILE, array( __CLASS__, 'activate' ) );
		register_deactivation_hook( ZHF_FILE, array( __CLASS__, 'deactivate' ) );

		add_action( 'before_woocommerce_init', array( __CLASS__, 'declare_compatibility' ) );
		add_action( self::CRON_HOOK, array( __CLASS__, 'run_cleanup' ) );

		if ( is_admin() && ! wp_doing_ajax() ) {
			Admin\Admin::init();
		}

		self::attach_gates();
	}

	/**
	 * PSR-4-ish autoloader: Zhayon\Funnel\Foo → includes/Foo.php,
	 * Zhayon\Funnel\Admin\Foo → admin/Foo.php.
	 */
	public static function autoload( string $class ): void {
		$prefix = __NAMESPACE__ . '\\';
		if ( 0 !== strpos( $class, $prefix ) ) {
			return;
		}
		$relative = substr( $class, strlen( $prefix ) );
		if ( 0 === strpos( $relative, 'Admin\\' ) ) {
			$file = ZHF_DIR . 'admin/' . substr( $relative, 6 ) . '.php';
		} else {
			$file = ZHF_DIR . 'includes/' . str_replace( '\\', '/', $relative ) . '.php';
		}
		if ( is_readable( $file ) ) {
			require_once $file;
		}
	}

	/* ------------------------------------------------------------------ *
	 * Lifecycle
	 * ------------------------------------------------------------------ */

	public static function activate(): void {
		Store::install();
		Settings::ensure_option();
		self::schedule_cleanup();
	}

	/** Deactivation keeps the data; it only stops the cron. */
	public static function deactivate(): void {
		wp_clear_scheduled_hook( self::CRON_HOOK );
	}

	public static function schedule_cleanup(): void {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			$first = ( new \DateTimeImmutable( 'tomorrow 03:30', wp_timezone() ) )->getTimestamp();
			wp_schedule_event( $first, 'daily', self::CRON_HOOK );
		}
	}

	public static function run_cleanup(): void {
		Store::cleanup();
	}

	/**
	 * Now in the site timezone, Gregorian.
	 *
	 * Not wp_date(): Persian date plugins filter it into Jalali, which would
	 * land in the DATE column as e.g. 1405-07-16.
	 */
	public static function now(): \DateTimeImmutable {
		return new \DateTimeImmutable( 'now', wp_timezone() );
	}

	public static function declare_compatibility(): void {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', ZHF_FILE, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', ZHF_FILE, true );
		}
	}

	/* ------------------------------------------------------------------ *
	 * Request gates
	 * ------------------------------------------------------------------ */

	/**
	 * Attach only the listeners this request can possibly need.
	 *
	 * Settings live in a non-autoloaded option, so they are read lazily by
	 * tracker() once a listener actually fires — never on unrelated pages.
	 */
	private static function attach_gates(): void {
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return;
		}

		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( (string) $_SERVER['REQUEST_METHOD'] ) : 'GET';

		// Order-bound steps run in gateway callbacks (often GET), admin and
		// cron; the status pair check in the listener is the gate.
		add_action( 'woocommerce_order_status_changed', array( __CLASS__, 'on_order_status' ), 10, 4 );

		// Public API for themes / other OTP or login plugins.
		add_action( 'zhf_track', array( __CLASS__, 'on_public_track' ), 10, 2 );

		if ( 'GET' === $method ) {
			if ( ! is_admin() ) {
				add_action( 'template_redirect', array( __CLASS__, 'on_template_redirect' ), 1 );
			}
		} elseif ( 'POST' === $method ) {
			add_action( 'woocommerce_checkout_process', array( __CLASS__, 'on_checkout_process' ) );
			add_action( 'woocommerce_after_checkout_validation', array( __CLASS__, 'on_checkout_validation' ), 9999, 2 );
			add_action( 'woocommerce_checkout_order_processed', array( __CLASS__, 'on_order_processed' ), 10, 3 );
			add_action( 'woocommerce_store_api_checkout_order_processed', array( __CLASS__, 'on_order_processed' ), 10, 1 );
			add_filter( 'rest_post_dispatch', array( __CLASS__, 'on_rest_post_dispatch' ), 10, 3 );
			add_action( 'woocommerce_applied_coupon', array( __CLASS__, 'on_coupon_applied' ) );
			add_filter( 'woocommerce_coupon_error', array( __CLASS__, 'on_coupon_error' ), 10, 2 );
		}

		// Login only counts for visits that came through cart/checkout.
		if ( isset( $_COOKIE[ ZHF_COOKIE ] ) ) {
			add_action( 'set_logged_in_cookie', array( __CLASS__, 'on_logged_in_cookie' ), 10, 4 );
			add_action( 'user_register', array( __CLASS__, 'on_user_register' ) );

			if ( 'POST' === $method ) {
				add_action( 'plugins_loaded', array( __CLASS__, 'load_integrations' ), 20 );
			}
		}
	}

	/**
	 * Optional integrations, each loaded only when its plugin is active.
	 * They report through the public `zhf_track` action.
	 */
	public static function load_integrations(): void {
		$integrations = array(
			'zhiva-panel' => defined( 'ZHIVA_VERSION' ) || class_exists( '\ZhivaPanel\ZhivaPanel', false ),
		);
		foreach ( $integrations as $slug => $active ) {
			if ( $active ) {
				require_once ZHF_DIR . 'includes/integrations/' . $slug . '.php';
			}
		}
	}

	/** The tracker, or null when tracking is off. Resolved once per request. */
	private static function tracker(): ?Tracker {
		if ( false === self::$tracker ) {
			try {
				self::$tracker = ( function_exists( 'WC' ) && Settings::get( 'enabled' ) ) ? new Tracker() : null;
			} catch ( \Throwable $e ) {
				self::$tracker = null;
			}
		}
		return self::$tracker;
	}

	/* ------------------------------------------------------------------ *
	 * Listeners — every one is wrapped so tracking can never break a page.
	 * ------------------------------------------------------------------ */

	/** cart_view / checkout_gate / checkout_view. */
	public static function on_template_redirect(): void {
		try {
			if ( ! function_exists( 'is_cart' ) ) {
				return;
			}
			$is_cart = is_cart();
			if ( ! $is_cart && ! is_checkout() ) {
				return; // Every other page leaves here: no query, no class loaded.
			}
			if ( ! WC()->cart || WC()->cart->is_empty() ) {
				return;
			}

			if ( $is_cart ) {
				self::track( 'cart_view' );
				return;
			}

			if ( is_wc_endpoint_url() ) {
				return; // order-received, order-pay, ...
			}

			if ( is_user_logged_in() ) {
				self::track( 'checkout_view' );
				return;
			}

			// Guests: let the rest of template_redirect play out. A redirect
			// (a login plugin bouncing them) means gate; a rendered page is
			// decided in on_guest_checkout_render(). Deciding late also means
			// the redirect case reads no WooCommerce options at all.
			self::$guest_checkout_pending = true;
			add_filter( 'wp_redirect', array( __CLASS__, 'on_guest_checkout_redirect' ), PHP_INT_MAX );
			add_filter( 'template_include', array( __CLASS__, 'on_guest_checkout_render' ), PHP_INT_MAX );
		} catch ( \Throwable $e ) {
			return;
		}
	}

	/** @param string $location */
	public static function on_guest_checkout_redirect( $location ) {
		if ( self::$guest_checkout_pending ) {
			try {
				// A redirect back to checkout itself (canonical slash, https) is
				// not a login wall; the follow-up request decides.
				$target   = untrailingslashit( (string) wp_parse_url( (string) $location, PHP_URL_PATH ) );
				$checkout = untrailingslashit( (string) wp_parse_url( wc_get_checkout_url(), PHP_URL_PATH ) );
				if ( $target === $checkout ) {
					return $location;
				}
			} catch ( \Throwable $e ) {
				return $location;
			}
			self::$guest_checkout_pending = false;
			self::track( 'checkout_gate' );
		}
		return $location;
	}

	/**
	 * The checkout renders for a guest: a gate when WooCommerce itself demands
	 * login (guest checkout off and no sign-up on the form), else a view.
	 *
	 * @param string $template
	 */
	public static function on_guest_checkout_render( $template ) {
		if ( self::$guest_checkout_pending ) {
			self::$guest_checkout_pending = false;
			try {
				$checkout = WC()->checkout();
				$gated    = $checkout->is_registration_required() && ! $checkout->is_registration_enabled();
				self::track( $gated ? 'checkout_gate' : 'checkout_view' );
			} catch ( \Throwable $e ) {
				return $template;
			}
		}
		return $template;
	}

	/** Classic checkout submit (form POST or ?wc-ajax=checkout). */
	public static function on_checkout_process(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- WooCommerce verified the nonce already.
		if ( ! empty( $_POST['woocommerce_checkout_update_totals'] ) ) {
			return;
		}
		$gateway = isset( $_POST['payment_method'] ) ? sanitize_text_field( wp_unslash( $_POST['payment_method'] ) ) : '';
		// phpcs:enable
		self::track( 'place_order', $gateway );
	}

	/**
	 * Classic checkout validation result.
	 *
	 * @param array     $data   Posted data.
	 * @param \WP_Error $errors Validation errors.
	 */
	public static function on_checkout_validation( $data, $errors ): void {
		try {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing
			if ( ! empty( $_POST['woocommerce_checkout_update_totals'] ) ) {
				return;
			}
			$code = '';
			if ( $errors instanceof \WP_Error && $errors->has_errors() ) {
				$code = (string) $errors->get_error_code();
			} elseif ( function_exists( 'wc_notice_count' ) && wc_notice_count( 'error' ) > 0 ) {
				// Errors added with wc_add_notice() in woocommerce_checkout_process.
				$notices = wc_get_notices( 'error' );
				$first   = is_array( $notices ) ? reset( $notices ) : null;
				$code    = ( is_array( $first ) && ! empty( $first['data']['id'] ) ) ? (string) $first['data']['id'] : 'notice';
			}
			if ( '' !== $code ) {
				self::track( 'checkout_error', $code );
			}
		} catch ( \Throwable $e ) {
			return;
		}
	}

	/**
	 * Store API (Blocks) checkout: place_order + checkout_error.
	 *
	 * @param \WP_HTTP_Response|\WP_Error $result
	 * @param \WP_REST_Server             $server
	 * @param \WP_REST_Request            $request
	 */
	public static function on_rest_post_dispatch( $result, $server, $request ) {
		try {
			if ( ! $request instanceof \WP_REST_Request || 'POST' !== $request->get_method() ) {
				return $result;
			}
			if ( ! preg_match( '#^/wc/store(?:/v\d+)?/checkout/?$#i', (string) $request->get_route() ) ) {
				return $result;
			}
			self::track( 'place_order', (string) $request->get_param( 'payment_method' ) );

			$is_error = is_wp_error( $result ) || ( $result instanceof \WP_HTTP_Response && $result->get_status() >= 400 );
			if ( $is_error ) {
				self::track( 'checkout_error', self::rest_error_code( $result ) );
			}
		} catch ( \Throwable $e ) {
			return $result;
		}
		return $result;
	}

	/**
	 * First field key of a Store API validation error, else its error code.
	 *
	 * @param \WP_HTTP_Response|\WP_Error $result
	 */
	private static function rest_error_code( $result ): string {
		if ( is_wp_error( $result ) ) {
			return (string) $result->get_error_code();
		}
		$data = $result->get_data();
		if ( ! is_array( $data ) ) {
			return 'other';
		}
		$code = isset( $data['code'] ) ? (string) $data['code'] : 'other';
		if ( 'rest_invalid_param' === $code && ! empty( $data['data']['params'] ) && is_array( $data['data']['params'] ) ) {
			return (string) array_key_first( $data['data']['params'] );
		}
		return preg_replace( '/^woocommerce_rest_/', '', $code );
	}

	/**
	 * Classic (order_id, posted_data, order) and Store API (order) signatures.
	 *
	 * @param mixed ...$args
	 */
	public static function on_order_processed( ...$args ): void {
		try {
			$order = null;
			foreach ( $args as $arg ) {
				if ( $arg instanceof \WC_Order ) {
					$order = $arg;
					break;
				}
			}
			if ( ! $order && isset( $args[0] ) && is_numeric( $args[0] ) ) {
				$order = wc_get_order( (int) $args[0] );
			}
			$tracker = $order ? self::tracker() : null;
			if ( $tracker ) {
				$tracker->order_created( $order );
			}
		} catch ( \Throwable $e ) {
			return;
		}
	}

	/**
	 * paid / payment_failed, counted against the visit stored on the order.
	 *
	 * @param int            $order_id
	 * @param string         $from
	 * @param string         $to
	 * @param \WC_Order|null $order
	 */
	public static function on_order_status( $order_id, $from, $to, $order = null ): void {
		$paid   = in_array( $to, self::PAY_TO, true ) && in_array( $from, self::PAY_FROM, true );
		$failed = ! $paid && in_array( $to, self::FAIL_TO, true ) && in_array( $from, self::FAIL_FROM, true );
		if ( ! $paid && ! $failed ) {
			return;
		}
		try {
			if ( ! $order instanceof \WC_Order ) {
				$order = wc_get_order( $order_id );
			}
			// Orders without a saved visit were never tracked: stop before loading anything.
			if ( ! $order || '' === (string) $order->get_meta( self::META_CTX ) ) {
				return;
			}
			$tracker = self::tracker();
			if ( $tracker ) {
				$tracker->order_outcome( $order, $paid ? 'paid' : 'payment_failed' );
			}
		} catch ( \Throwable $e ) {
			return;
		}
	}

	/** @param string $code */
	public static function on_coupon_applied( $code ): void {
		self::track( 'coupon_apply', 'ok' );
	}

	/**
	 * Count a failed apply attempt; the message is returned untouched.
	 *
	 * @param string $err
	 * @param int    $err_code
	 */
	public static function on_coupon_error( $err, $err_code = 0 ) {
		try {
			// 100–102 = an already-applied coupon being dropped during cart
			// re-validation, not a shopper's apply attempt.
			$removal = in_array( (int) $err_code, array( 100, 101, 102 ), true );
			$revalidating = doing_action( 'woocommerce_check_cart_items' )
				|| doing_action( 'woocommerce_checkout_process' )
				|| doing_action( 'woocommerce_after_checkout_validation' );
			if ( ! $removal && ! $revalidating ) {
				self::track( 'coupon_apply', 'invalid' );
			}
		} catch ( \Throwable $e ) {
			return $err;
		}
		return $err;
	}

	/**
	 * @param string $logged_in_cookie
	 * @param int    $expire
	 * @param int    $expiration
	 * @param int    $user_id
	 */
	public static function on_logged_in_cookie( $logged_in_cookie, $expire, $expiration, $user_id ): void {
		self::track( 'login', 'login', array( 'user_id' => (int) $user_id ) );
	}

	/** @param int $user_id */
	public static function on_user_register( $user_id ): void {
		if ( is_admin() && ! wp_doing_ajax() ) {
			return; // Created from wp-admin → Users, not a shopper signing up.
		}
		$actor = get_current_user_id();
		self::track( 'login', 'register', array( 'user_id' => $actor ? $actor : (int) $user_id ) );
	}

	/**
	 * do_action( 'zhf_track', $step, $detail ) — unknown steps are ignored.
	 *
	 * @param string $step
	 * @param string $detail
	 */
	public static function on_public_track( $step, $detail = '' ): void {
		if ( is_string( $step ) && '' !== $step ) {
			self::track( $step, is_scalar( $detail ) ? (string) $detail : '' );
		}
	}

	private static function track( string $step, string $detail = '', array $context = array() ): void {
		try {
			$tracker = self::tracker();
			if ( $tracker ) {
				$tracker->track( $step, $detail, $context );
			}
		} catch ( \Throwable $e ) {
			return;
		}
	}
}
