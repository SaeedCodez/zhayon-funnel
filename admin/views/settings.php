<?php
/**
 * «تنظیمات» tab (Settings API → options.php).
 *
 * @package Zhayon\Funnel
 */

use Zhayon\Funnel\Admin\Format;
use Zhayon\Funnel\Settings;
use Zhayon\Funnel\Store;

defined( 'ABSPATH' ) || exit;

$s    = Settings::all();
$name = Settings::OPTION;

// "Settings saved." plus our validation errors, as in-page alerts: WordPress
// notices are suppressed on this page (see Admin::suppress_notices()).
$alerts = get_settings_errors();

// Result of a manual data purge (Admin::purge_data()).
// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
$purge = isset( $_GET['zhf_purge'] ) ? sanitize_key( wp_unslash( $_GET['zhf_purge'] ) ) : '';
if ( 'unconfirmed' === $purge ) {
	$alerts[] = array( 'type' => 'error', 'message' => __( 'داده‌ای حذف نشد: عبارت تأیید درست وارد نشده بود.', 'zhayon-funnel' ) );
} elseif ( 'failed' === $purge ) {
	$alerts[] = array( 'type' => 'error', 'message' => __( 'حذف داده‌ها انجام نشد (خطای پایگاه داده).', 'zhayon-funnel' ) );
} elseif ( '' !== $purge && ctype_digit( $purge ) ) {
	$alerts[] = array(
		'type'    => 'success',
		/* translators: %s: number of deleted rows */
		'message' => sprintf( __( 'همهٔ داده‌های قیف حذف شد (%s ردیف). تنظیمات دست‌نخورده ماند.', 'zhayon-funnel' ), Format::num( (int) $purge ) ),
	);
}

if ( $alerts ) :
	?>
	<div class="zhf-alerts">
		<?php
		foreach ( $alerts as $alert ) :
			$type = in_array( $alert['type'], array( 'error', 'warning' ), true ) ? $alert['type'] : 'success';
			?>
			<div class="zhf-alert zhf-alert-<?php echo esc_attr( $type ); ?>" role="<?php echo 'error' === $type ? 'alert' : 'status'; ?>">
				<?php echo wp_kses_post( $alert['message'] ); ?>
			</div>
		<?php endforeach; ?>
	</div>
	<?php
endif;
?>
<form method="post" action="<?php echo esc_url( admin_url( 'options.php' ) ); ?>" class="zhf-settings" data-zhf-settings>
	<?php settings_fields( Settings::GROUP ); ?>

	<section class="zhf-card">
		<div class="zhf-card-head"><h2 class="zhf-card-title"><?php esc_html_e( 'ردیابی', 'zhayon-funnel' ); ?></h2></div>

		<div class="zhf-row">
			<div class="zhf-row-text">
				<label class="zhf-row-title" for="zhf-enabled"><?php esc_html_e( 'ردیابی فعال باشد', 'zhayon-funnel' ); ?></label>
				<p class="zhf-help"><?php esc_html_e( 'وقتی خاموش است هیچ مرحله‌ای ثبت نمی‌شود؛ داده‌های قبلی می‌مانند.', 'zhayon-funnel' ); ?></p>
			</div>
			<input type="hidden" name="<?php echo esc_attr( $name ); ?>[enabled]" value="0">
			<input class="zhf-switch" type="checkbox" role="switch" id="zhf-enabled" name="<?php echo esc_attr( $name ); ?>[enabled]" value="1" <?php checked( ! empty( $s['enabled'] ) ); ?>>
		</div>

		<div class="zhf-row">
			<div class="zhf-row-text">
				<label class="zhf-row-title" for="zhf-track-admins"><?php esc_html_e( 'ردیابی مدیران', 'zhayon-funnel' ); ?></label>
				<p class="zhf-help"><?php esc_html_e( 'فقط برای آزمایش: بازدید کاربرانی که دسترسی مدیریت ووکامرس دارند هم شمرده می‌شود. بعد از آزمایش خاموشش کنید.', 'zhayon-funnel' ); ?></p>
			</div>
			<input type="hidden" name="<?php echo esc_attr( $name ); ?>[track_admins]" value="0">
			<input class="zhf-switch" type="checkbox" role="switch" id="zhf-track-admins" name="<?php echo esc_attr( $name ); ?>[track_admins]" value="1" <?php checked( ! empty( $s['track_admins'] ) ); ?>>
		</div>

		<div class="zhf-row">
			<div class="zhf-row-text">
				<label class="zhf-row-title" for="zhf-retention"><?php esc_html_e( 'مدت نگهداری داده', 'zhayon-funnel' ); ?></label>
				<p class="zhf-help"><?php esc_html_e( 'ردیف‌های قدیمی‌تر هر شب پاک می‌شوند. بین ۷ تا ۳۶۵۰ روز.', 'zhayon-funnel' ); ?></p>
			</div>
			<div class="zhf-input-group">
				<input class="zhf-input zhf-input-num" type="number" id="zhf-retention" name="<?php echo esc_attr( $name ); ?>[retention]" value="<?php echo esc_attr( (string) (int) $s['retention'] ); ?>" min="7" max="3650" step="1" required dir="ltr">
				<span class="zhf-input-suffix"><?php esc_html_e( 'روز', 'zhayon-funnel' ); ?></span>
			</div>
		</div>
	</section>

	<section class="zhf-card">
		<div class="zhf-card-head"><h2 class="zhf-card-title"><?php esc_html_e( 'ظاهر', 'zhayon-funnel' ); ?></h2></div>
		<div class="zhf-row">
			<div class="zhf-row-text">
				<label class="zhf-row-title" for="zhf-color-text"><?php esc_html_e( 'رنگ اصلی', 'zhayon-funnel' ); ?></label>
				<p class="zhf-help"><?php esc_html_e( 'برای دکمهٔ اصلی، گزینهٔ فعال، لینک‌ها، مرحلهٔ پررنگ قیف و خط اصلی نمودار.', 'zhayon-funnel' ); ?></p>
			</div>
			<div class="zhf-color">
				<input type="color" class="zhf-color-swatch" value="<?php echo esc_attr( $s['color'] ); ?>" aria-label="<?php esc_attr_e( 'انتخاب رنگ', 'zhayon-funnel' ); ?>" data-zhf-color-picker>
				<input class="zhf-input zhf-input-hex" type="text" id="zhf-color-text" name="<?php echo esc_attr( $name ); ?>[color]" value="<?php echo esc_attr( $s['color'] ); ?>" maxlength="7" dir="ltr" spellcheck="false" data-zhf-color-text>
			</div>
		</div>
	</section>

	<section class="zhf-card">
		<div class="zhf-card-head">
			<h2 class="zhf-card-title"><?php esc_html_e( 'منبع‌های ورودی', 'zhayon-funnel' ); ?></h2>
			<span class="zhf-muted"><?php esc_html_e( 'اولین ردیفی که دامنه‌اش در منبع بازدید باشد برنده است.', 'zhayon-funnel' ); ?></span>
		</div>
		<p class="zhf-help zhf-help-block">
			<?php esc_html_e( 'منبع از کوکی منبع ترافیک ووکامرس (sbjs_current) یا هدر Referer خوانده می‌شود. بازدید بدون ارجاع‌دهنده «مستقیم» و هر چیز دیگری «سایر» است. نام دسته حداکثر ۱۶ حرف لاتین کوچک، عدد، - یا _ است.', 'zhayon-funnel' ); ?>
		</p>
		<div class="zhf-table-wrap">
			<table class="zhf-table zhf-sources">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'دامنه شامل', 'zhayon-funnel' ); ?></th>
						<th scope="col"><?php esc_html_e( 'دسته', 'zhayon-funnel' ); ?></th>
						<th scope="col" class="zhf-col-action"><span class="screen-reader-text"><?php esc_html_e( 'حذف', 'zhayon-funnel' ); ?></span></th>
					</tr>
				</thead>
				<tbody data-zhf-source-rows>
					<?php foreach ( array_values( (array) $s['sources'] ) as $i => $row ) : ?>
						<tr>
							<td><input class="zhf-input" type="text" name="<?php echo esc_attr( $name . '[sources][' . $i . '][match]' ); ?>" value="<?php echo esc_attr( $row['match'] ); ?>" dir="ltr" aria-label="<?php esc_attr_e( 'دامنه شامل', 'zhayon-funnel' ); ?>"></td>
							<td><input class="zhf-input" type="text" name="<?php echo esc_attr( $name . '[sources][' . $i . '][bucket]' ); ?>" value="<?php echo esc_attr( $row['bucket'] ); ?>" dir="ltr" maxlength="16" pattern="[a-z0-9_\-]{1,16}" aria-label="<?php esc_attr_e( 'دسته', 'zhayon-funnel' ); ?>"></td>
							<td class="zhf-col-action"><button type="button" class="zhf-btn zhf-btn-ghost" data-zhf-remove-row><?php esc_html_e( 'حذف', 'zhayon-funnel' ); ?></button></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<template data-zhf-source-template>
			<tr>
				<td><input class="zhf-input" type="text" name="<?php echo esc_attr( $name ); ?>[sources][__i__][match]" value="" dir="ltr" placeholder="example.com" aria-label="<?php esc_attr_e( 'دامنه شامل', 'zhayon-funnel' ); ?>"></td>
				<td><input class="zhf-input" type="text" name="<?php echo esc_attr( $name ); ?>[sources][__i__][bucket]" value="" dir="ltr" maxlength="16" pattern="[a-z0-9_\-]{1,16}" placeholder="example" aria-label="<?php esc_attr_e( 'دسته', 'zhayon-funnel' ); ?>"></td>
				<td class="zhf-col-action"><button type="button" class="zhf-btn zhf-btn-ghost" data-zhf-remove-row><?php esc_html_e( 'حذف', 'zhayon-funnel' ); ?></button></td>
			</tr>
		</template>
		<div class="zhf-card-foot">
			<button type="button" class="zhf-btn zhf-btn-secondary" data-zhf-add-row><?php esc_html_e( 'افزودن ردیف', 'zhayon-funnel' ); ?></button>
		</div>
	</section>

	<div class="zhf-actions">
		<button type="submit" class="zhf-btn zhf-btn-primary"><?php esc_html_e( 'ذخیرهٔ تنظیمات', 'zhayon-funnel' ); ?></button>
	</div>
</form>

<?php
global $wpdb;
$zhf_table = Store::table();
$zhf_rows  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$zhf_table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
$zhf_first = $zhf_rows ? (string) $wpdb->get_var( "SELECT MIN(day) FROM {$zhf_table}" ) : ''; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
?>
<section class="zhf-card zhf-danger" id="zhf-danger">
	<div class="zhf-card-head"><h2 class="zhf-card-title"><?php esc_html_e( 'حذف داده‌ها', 'zhayon-funnel' ); ?></h2></div>
	<p class="zhf-help zhf-help-block">
		<?php
		if ( $zhf_rows ) {
			echo esc_html(
				sprintf(
					/* translators: 1: number of rows, 2: date of the first day */
					__( 'الان %1$s ردیف شمارنده از %2$s به بعد ذخیره شده است.', 'zhayon-funnel' ),
					Format::num( $zhf_rows ),
					Format::jdate( $zhf_first )
				)
			);
			echo ' ';
		}
		esc_html_e( 'با حذف یا غیرفعال کردن افزونه داده‌ای پاک نمی‌شود. این دکمه همهٔ آمار قیف را برای همیشه پاک می‌کند (تنظیمات می‌ماند) و برگشت‌پذیر نیست؛ اگر لازم دارید، اول از گزارش خروجی CSV بگیرید.', 'zhayon-funnel' );
		?>
	</p>
	<?php if ( $zhf_rows ) : ?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="zhf-danger-form" data-zhf-purge>
			<input type="hidden" name="action" value="zhf_purge">
			<?php wp_nonce_field( 'zhf_purge' ); ?>
			<label class="zhf-field-inline" for="zhf-confirm">
				<span><?php esc_html_e( 'برای تأیید، کلمهٔ «حذف» را بنویسید:', 'zhayon-funnel' ); ?></span>
				<input class="zhf-input" type="text" id="zhf-confirm" name="zhf_confirm" autocomplete="off" spellcheck="false" data-zhf-purge-input>
			</label>
			<button type="submit" class="zhf-btn zhf-btn-danger" disabled data-zhf-purge-button><?php esc_html_e( 'حذف همهٔ داده‌ها', 'zhayon-funnel' ); ?></button>
		</form>
	<?php else : ?>
		<p class="zhf-empty-inline"><?php esc_html_e( 'داده‌ای برای حذف وجود ندارد.', 'zhayon-funnel' ); ?></p>
	<?php endif; ?>
</section>

<section class="zhf-card zhf-card-quiet">
	<div class="zhf-card-head"><h2 class="zhf-card-title"><?php esc_html_e( 'برای توسعه‌دهنده‌ها', 'zhayon-funnel' ); ?></h2></div>
	<p class="zhf-help zhf-help-block"><?php esc_html_e( 'افزونه‌های ورود یا ارسال کد دیگر می‌توانند یک مرحله را خودشان گزارش کنند:', 'zhayon-funnel' ); ?></p>
	<pre class="zhf-pre" dir="ltr"><code>do_action( 'zhf_track', 'otp_sent', 'ok' );          // or an error code
do_action( 'zhf_track', 'login', 'invalid_otp' );    // failed login</code></pre>
</section>
