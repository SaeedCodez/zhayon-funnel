<?php
/**
 * Page shell: title, tabs, then the active tab.
 *
 * @package Zhayon\Funnel
 * @var string $tab 'report' | 'settings'
 */

use Zhayon\Funnel\Admin\Admin;
use Zhayon\Funnel\Settings;

defined( 'ABSPATH' ) || exit;
?>
<div class="wrap zhf" dir="rtl">
	<header class="zhf-head">
		<div class="zhf-head-text">
			<h1 class="zhf-title"><?php esc_html_e( 'قیف خرید', 'zhayon-funnel' ); ?></h1>
			<p class="zhf-sub"><?php esc_html_e( 'خریداران در کدام مرحله از مسیر خرید خارج می‌شوند.', 'zhayon-funnel' ); ?></p>
		</div>
		<?php if ( Settings::get( 'track_admins' ) ) : ?>
			<span class="zhf-badge zhf-badge-warn" title="<?php esc_attr_e( 'بازدیدهای مدیران فروشگاه هم شمرده می‌شود. پس از آزمایش خاموشش کنید.', 'zhayon-funnel' ); ?>">
				<?php esc_html_e( 'ردیابی مدیران روشن است', 'zhayon-funnel' ); ?>
			</span>
		<?php endif; ?>
		<?php if ( ! Settings::get( 'enabled' ) ) : ?>
			<span class="zhf-badge zhf-badge-muted"><?php esc_html_e( 'ردیابی خاموش است', 'zhayon-funnel' ); ?></span>
		<?php endif; ?>
	</header>

	<nav class="zhf-tabs" aria-label="<?php esc_attr_e( 'بخش‌ها', 'zhayon-funnel' ); ?>">
		<a href="<?php echo esc_url( Admin::url() ); ?>" class="zhf-tab<?php echo 'report' === $tab ? ' is-active' : ''; ?>" <?php echo 'report' === $tab ? 'aria-current="page"' : ''; ?>><?php esc_html_e( 'گزارش', 'zhayon-funnel' ); ?></a>
		<a href="<?php echo esc_url( Admin::url( array( 'tab' => 'settings' ) ) ); ?>" class="zhf-tab<?php echo 'settings' === $tab ? ' is-active' : ''; ?>" <?php echo 'settings' === $tab ? 'aria-current="page"' : ''; ?>><?php esc_html_e( 'تنظیمات', 'zhayon-funnel' ); ?></a>
	</nav>
	<hr class="wp-header-end">

	<?php
	if ( 'settings' === $tab ) {
		include __DIR__ . '/settings.php';
	} else {
		include __DIR__ . '/report.php';
	}
	?>
</div>
