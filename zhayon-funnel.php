<?php
/**
 * Plugin Name:       Zhayon Funnel
 * Plugin URI:        https://zhayon.com
 * Description:       قیف خرید سبک برای ووکامرس: نشان می‌دهد خریداران در کدام مرحله (سبد، ورود، تسویه، سفارش، پرداخت) از مسیر خرید خارج می‌شوند؛ به تفکیک دستگاه، منبع ورودی و درگاه پرداخت.
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Requires Plugins:  woocommerce
 * Author:            Saeed Hassani | Zhayon
 * Author URI:        https://zhayon.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       zhayon-funnel
 * Domain Path:       /languages
 * WC requires at least: 8.0
 * WC tested up to:   10.5
 *
 * @package Zhayon\Funnel
 */

defined( 'ABSPATH' ) || exit;

define( 'ZHF_VERSION', '1.0.0' );
define( 'ZHF_FILE', __FILE__ );
define( 'ZHF_DIR', plugin_dir_path( __FILE__ ) );
define( 'ZHF_URL', plugin_dir_url( __FILE__ ) );

// The visit cookie name lives in a constant (not on the Visit class) so the
// request gates can test for it without autoloading anything.
define( 'ZHF_COOKIE', 'zhf_v' );

require_once ZHF_DIR . 'includes/Plugin.php';

\Zhayon\Funnel\Plugin::boot();
