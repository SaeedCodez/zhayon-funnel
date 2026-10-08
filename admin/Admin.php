<?php
/**
 * WooCommerce → «قیف خرید»: menu, settings registration, assets, CSV export.
 *
 * @package Zhayon\Funnel
 */

namespace Zhayon\Funnel\Admin;

use Zhayon\Funnel\Plugin;
use Zhayon\Funnel\Settings;
use Zhayon\Funnel\Store;

defined( 'ABSPATH' ) || exit;

final class Admin {

	const SLUG       = 'zhayon-funnel';
	const CAPABILITY = 'manage_woocommerce';

	/** @var string Page hook suffix. */
	private static $hook = '';

	public static function init(): void {
		add_action( 'init', array( __CLASS__, 'load_textdomain' ) );
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 60 );
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		add_action( 'in_admin_header', array( __CLASS__, 'suppress_notices' ), PHP_INT_MAX );
		add_action( 'admin_post_zhf_export', array( __CLASS__, 'export_csv' ) );
		add_action( 'admin_post_zhf_purge', array( __CLASS__, 'purge_data' ) );
		add_filter( 'option_page_capability_' . Settings::GROUP, array( __CLASS__, 'capability' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( ZHF_FILE ), array( __CLASS__, 'action_links' ) );
	}

	public static function load_textdomain(): void {
		load_plugin_textdomain( 'zhayon-funnel', false, dirname( plugin_basename( ZHF_FILE ) ) . '/languages' );
	}

	public static function capability(): string {
		return self::CAPABILITY;
	}

	public static function menu(): void {
		self::$hook = (string) add_submenu_page(
			'woocommerce',
			__( 'قیف خرید', 'zhayon-funnel' ),
			__( 'قیف خرید', 'zhayon-funnel' ),
			self::CAPABILITY,
			self::SLUG,
			array( __CLASS__, 'render' )
		);
	}

	public static function url( array $args = array() ): string {
		return add_query_arg( array_merge( array( 'page' => self::SLUG ), $args ), admin_url( 'admin.php' ) );
	}

	/** @param string[] $links */
	public static function action_links( $links ): array {
		array_unshift(
			$links,
			sprintf( '<a href="%s">%s</a>', esc_url( self::url() ), esc_html__( 'گزارش', 'zhayon-funnel' ) ),
			sprintf( '<a href="%s">%s</a>', esc_url( self::url( array( 'tab' => 'settings' ) ) ), esc_html__( 'تنظیمات', 'zhayon-funnel' ) )
		);
		return $links;
	}

	public static function register_settings(): void {
		global $pagenow;
		// Saving through options.php must never create the option as autoloaded.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- options.php verifies the nonce.
		if ( 'options.php' === $pagenow && isset( $_POST['option_page'] ) && Settings::GROUP === $_POST['option_page'] ) {
			Settings::ensure_option();
		}
		register_setting(
			Settings::GROUP,
			Settings::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( Settings::class, 'sanitize' ),
				'show_in_rest'      => false,
			)
		);
	}

	/**
	 * Other plugins' and core admin notices break the page layout, so none are
	 * printed here; our own settings messages render in the page itself.
	 * in_admin_header is the last hook before WordPress prints notices.
	 */
	public static function suppress_notices(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || '' === self::$hook || $screen->id !== self::$hook ) {
			return;
		}
		if ( ! apply_filters( 'zhf_hide_admin_notices', true ) ) {
			return;
		}
		foreach ( array( 'admin_notices', 'all_admin_notices', 'network_admin_notices', 'user_admin_notices' ) as $notices_hook ) {
			remove_all_actions( $notices_hook );
		}
	}

	public static function enqueue( string $hook ): void {
		if ( '' === self::$hook || $hook !== self::$hook ) {
			return;
		}

		$deps = array();
		/**
		 * Extra font stylesheet for the report (e.g. a theme's IRANYekanX fonts.css).
		 * Return a URL to enqueue it; the report's font stack prefers IRANYekanX.
		 */
		$font_css = apply_filters( 'zhf_admin_font_css_url', '' );
		if ( is_string( $font_css ) && '' !== $font_css ) {
			wp_enqueue_style( 'zhf-font-extra', esc_url_raw( $font_css ), array(), null ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion
			$deps[] = 'zhf-font-extra';
		}

		wp_enqueue_style( 'zhf-report', ZHF_URL . 'assets/admin/report.css', $deps, ZHF_VERSION );
		wp_add_inline_style( 'zhf-report', self::brand_css( (string) Settings::get( 'color' ) ) );
		if ( apply_filters( 'zhf_hide_admin_notices', true ) ) {
			// Fallback for notices printed outside the notice hooks or injected by JS.
			wp_add_inline_style( 'zhf-report', '#wpbody-content .notice,#wpbody-content .update-nag,#wpbody-content div.updated,#wpbody-content div.error{display:none!important}' );
		}
		wp_enqueue_script( 'zhf-report', ZHF_URL . 'assets/admin/report.js', array(), ZHF_VERSION, true );
	}

	/** --zhf-primary and its derived shades (hover ≈ 8% darker, tints on white). */
	public static function brand_css( string $hex ): string {
		if ( ! preg_match( '/^#[0-9a-f]{6}$/i', $hex ) ) {
			$hex = Settings::defaults()['color'];
		}
		$rgb = array_map( 'hexdec', str_split( substr( $hex, 1 ), 2 ) );
		$mix = static function ( array $rgb, array $with, float $amount ): string {
			$out = '#';
			foreach ( $rgb as $i => $channel ) {
				$out .= sprintf( '%02x', (int) round( $channel * ( 1 - $amount ) + $with[ $i ] * $amount ) );
			}
			return $out;
		};
		return sprintf(
			'.zhf{--zhf-primary:%1$s;--zhf-primary-hover:%2$s;--zhf-primary-tint:%3$s;--zhf-primary-tint-border:%4$s;--zhf-primary-ring:%5$s}',
			strtolower( $hex ),
			$mix( $rgb, array( 0, 0, 0 ), 0.08 ),
			$mix( $rgb, array( 255, 255, 255 ), 0.91 ),
			$mix( $rgb, array( 255, 255, 255 ), 0.80 ),
			'rgba(' . implode( ',', $rgb ) . ',.35)'
		);
	}

	public static function render(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}
		Store::maybe_install();
		Settings::ensure_option();
		Plugin::schedule_cleanup();

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$tab = ( isset( $_GET['tab'] ) && 'settings' === $_GET['tab'] ) ? 'settings' : 'report';

		include ZHF_DIR . 'admin/views/page.php';
	}

	/** Words that confirm a purge (typed by the admin; checked here, not only in JS). */
	const PURGE_WORDS = array( 'حذف', 'delete' );

	/**
	 * admin-post.php?action=zhf_purge — delete all funnel data, only when an
	 * admin asked for it explicitly and typed the confirmation word.
	 */
	public static function purge_data(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'دسترسی ندارید.', 'zhayon-funnel' ), 403 );
		}
		check_admin_referer( 'zhf_purge' );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified above.
		$typed = isset( $_POST['zhf_confirm'] ) ? strtolower( trim( sanitize_text_field( wp_unslash( $_POST['zhf_confirm'] ) ) ) ) : '';
		if ( ! in_array( $typed, self::PURGE_WORDS, true ) ) {
			wp_safe_redirect( self::url( array( 'tab' => 'settings', 'zhf_purge' => 'unconfirmed' ) ) . '#zhf-danger' );
			exit;
		}

		$deleted = Store::purge();
		wp_safe_redirect( self::url( array( 'tab' => 'settings', 'zhf_purge' => $deleted < 0 ? 'failed' : (string) $deleted ) ) . '#zhf-danger' );
		exit;
	}

	/** admin-post.php?action=zhf_export — the filtered daily rows as CSV. */
	public static function export_csv(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'دسترسی ندارید.', 'zhayon-funnel' ), 403 );
		}
		check_admin_referer( 'zhf_export' );

		$f    = Report::filters();
		$rows = Report::export_rows( $f );

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="zhayon-funnel-' . $f['from'] . '_' . $f['to'] . '.csv"' );

		$out = fopen( 'php://output', 'w' );
		fwrite( $out, "\xEF\xBB\xBF" ); // BOM so Excel opens UTF-8 correctly.
		fputcsv( $out, array( 'day', 'step', 'device', 'source', 'detail', 'uniques', 'hits' ) );
		foreach ( $rows as $row ) {
			fputcsv( $out, $row );
		}
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		exit;
	}
}
