<?php
/**
 * «گزارش» tab.
 *
 * @package Zhayon\Funnel
 */

use Zhayon\Funnel\Admin\Admin;
use Zhayon\Funnel\Admin\Format;
use Zhayon\Funnel\Admin\Labels;
use Zhayon\Funnel\Admin\Report;
use Zhayon\Funnel\Settings;

defined( 'ABSPATH' ) || exit;

$f    = Report::filters();
$data = Report::data( $f );

/** Change pill: green up, red down. $points = percentage-point change. */
$zhf_pill = static function ( ?float $change, bool $points = false ): string {
	if ( null === $change ) {
		return '<span class="zhf-pill is-flat" title="' . esc_attr__( 'دورهٔ قبل داده‌ای ندارد', 'zhayon-funnel' ) . '">—</span>';
	}
	$class = $change > 0.00049 ? 'is-up' : ( $change < -0.00049 ? 'is-down' : 'is-flat' );
	$text  = $points ? Format::delta( $change, 1, ' ' . __( 'واحد', 'zhayon-funnel' ) ) : Format::delta( $change );
	$title = $points ? __( 'تغییر به واحد درصد نسبت به دورهٔ قبل', 'zhayon-funnel' ) : __( 'تغییر نسبت به دورهٔ قبل', 'zhayon-funnel' );
	return '<span class="zhf-pill ' . $class . '" title="' . esc_attr( $title ) . '">' . esc_html( $text ) . '</span>';
};

/** Compact table. $cols = [label, is_numeric]; cells are pre-escaped HTML. */
$zhf_table = static function ( array $cols, array $rows, string $empty ) {
	if ( ! $rows ) {
		echo '<p class="zhf-empty-inline">' . esc_html( $empty ) . '</p>';
		return;
	}
	echo '<div class="zhf-table-wrap"><table class="zhf-table"><thead><tr>';
	foreach ( $cols as $col ) {
		echo '<th scope="col"' . ( $col[1] ? ' class="num"' : '' ) . '>' . esc_html( $col[0] ) . '</th>';
	}
	echo '</tr></thead><tbody>';
	foreach ( $rows as $row ) {
		echo '<tr>';
		foreach ( $row as $i => $cell ) {
			echo '<td' . ( $cols[ $i ][1] ? ' class="num"' : '' ) . '>' . $cell . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- cells are escaped when built.
		}
		echo '</tr>';
	}
	echo '</tbody></table></div>';
};

/** Label plus its raw code; a code with no human label is shown as code only. */
$zhf_code = static function ( string $label, string $code ): string {
	if ( $label === $code ) {
		return '<code class="zhf-code">' . esc_html( $code ) . '</code>';
	}
	return esc_html( $label ) . ' <code class="zhf-code">' . esc_html( $code ) . '</code>';
};

$export_url = wp_nonce_url(
	add_query_arg( array_merge( array( 'action' => 'zhf_export' ), Report::query_args( $f, array( 'page' => null ) ) ), admin_url( 'admin-post.php' ) ),
	'zhf_export'
);
$sources    = array_unique( array_merge( Settings::buckets(), array_keys( $data['cur']['by_source'] ), 'all' !== $f['source'] ? array( $f['source'] ) : array() ) );
?>

<form class="zhf-filters" method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" data-zhf-filters>
	<input type="hidden" name="page" value="<?php echo esc_attr( Admin::SLUG ); ?>">
	<input type="hidden" name="range" value="<?php echo esc_attr( $f['range'] ); ?>">

	<div class="zhf-seg" role="group" aria-label="<?php esc_attr_e( 'بازهٔ زمانی', 'zhayon-funnel' ); ?>">
		<?php foreach ( Report::PRESETS as $preset ) : ?>
			<a class="zhf-seg-item<?php echo (string) $preset === $f['range'] ? ' is-active' : ''; ?>"
				href="<?php echo esc_url( add_query_arg( Report::query_args( $f, array( 'range' => (string) $preset, 'from' => null, 'to' => null ) ), admin_url( 'admin.php' ) ) ); ?>"
				<?php echo (string) $preset === $f['range'] ? 'aria-current="true"' : ''; ?>>
				<?php
				/* translators: %s: number of days */
				echo esc_html( sprintf( __( '%s روز', 'zhayon-funnel' ), Format::num( $preset ) ) );
				?>
			</a>
		<?php endforeach; ?>
		<button type="button" class="zhf-seg-item<?php echo 'custom' === $f['range'] ? ' is-active' : ''; ?>" data-zhf-custom-toggle aria-expanded="<?php echo 'custom' === $f['range'] ? 'true' : 'false'; ?>"><?php esc_html_e( 'سفارشی', 'zhayon-funnel' ); ?></button>
	</div>

	<div class="zhf-custom" data-zhf-custom <?php echo 'custom' === $f['range'] ? '' : 'hidden'; ?>>
		<label class="zhf-field-inline">
			<span><?php esc_html_e( 'از', 'zhayon-funnel' ); ?></span>
			<input class="zhf-input zhf-input-date" type="text" name="from" inputmode="numeric" placeholder="۱۴۰۵/۰۷/۰۱" pattern="[0-9۰-۹٠-٩]{4}[/\-.][0-9۰-۹٠-٩]{1,2}[/\-.][0-9۰-۹٠-٩]{1,2}" value="<?php echo esc_attr( Format::digits( Format::jdate_input( $f['from'] ) ) ); ?>" <?php echo 'custom' === $f['range'] ? '' : 'disabled'; ?> required>
		</label>
		<label class="zhf-field-inline">
			<span><?php esc_html_e( 'تا', 'zhayon-funnel' ); ?></span>
			<input class="zhf-input zhf-input-date" type="text" name="to" inputmode="numeric" placeholder="۱۴۰۵/۰۷/۱۶" pattern="[0-9۰-۹٠-٩]{4}[/\-.][0-9۰-۹٠-٩]{1,2}[/\-.][0-9۰-۹٠-٩]{1,2}" value="<?php echo esc_attr( Format::digits( Format::jdate_input( $f['to'] ) ) ); ?>" <?php echo 'custom' === $f['range'] ? '' : 'disabled'; ?> required>
		</label>
		<button type="submit" class="zhf-btn zhf-btn-primary" name="range" value="custom"><?php esc_html_e( 'اعمال', 'zhayon-funnel' ); ?></button>
	</div>

	<label class="zhf-field-inline">
		<span class="screen-reader-text"><?php esc_html_e( 'دستگاه', 'zhayon-funnel' ); ?></span>
		<select class="zhf-select" name="device" data-zhf-autosubmit>
			<option value="all" <?php selected( $f['device'], 'all' ); ?>><?php esc_html_e( 'همهٔ دستگاه‌ها', 'zhayon-funnel' ); ?></option>
			<option value="m" <?php selected( $f['device'], 'm' ); ?>><?php esc_html_e( 'موبایل', 'zhayon-funnel' ); ?></option>
			<option value="d" <?php selected( $f['device'], 'd' ); ?>><?php esc_html_e( 'دسکتاپ', 'zhayon-funnel' ); ?></option>
		</select>
	</label>

	<label class="zhf-field-inline">
		<span class="screen-reader-text"><?php esc_html_e( 'منبع ورودی', 'zhayon-funnel' ); ?></span>
		<select class="zhf-select" name="source" data-zhf-autosubmit>
			<option value="all" <?php selected( $f['source'], 'all' ); ?>><?php esc_html_e( 'همهٔ منابع', 'zhayon-funnel' ); ?></option>
			<?php foreach ( $sources as $bucket ) : ?>
				<option value="<?php echo esc_attr( $bucket ); ?>" <?php selected( $f['source'], $bucket ); ?>><?php echo esc_html( Labels::source( $bucket ) ); ?></option>
			<?php endforeach; ?>
		</select>
	</label>

	<label class="zhf-check">
		<input type="hidden" name="compare" value="0">
		<input type="checkbox" name="compare" value="1" <?php checked( $f['compare'] ); ?> data-zhf-autosubmit>
		<span><?php esc_html_e( 'مقایسه با دورهٔ قبل', 'zhayon-funnel' ); ?></span>
	</label>

	<noscript><button type="submit" class="zhf-btn zhf-btn-secondary"><?php esc_html_e( 'اعمال فیلترها', 'zhayon-funnel' ); ?></button></noscript>

	<a class="zhf-btn zhf-btn-secondary zhf-filters-end" href="<?php echo esc_url( $export_url ); ?>"><?php esc_html_e( 'خروجی CSV', 'zhayon-funnel' ); ?></a>
</form>

<p class="zhf-meta">
	<?php
	echo esc_html(
		sprintf(
			/* translators: 1: start date, 2: end date */
			__( 'بازه: %1$s تا %2$s', 'zhayon-funnel' ),
			Format::jdate( $f['from'] ),
			Format::jdate( $f['to'] )
		)
	);
	if ( $f['compare'] ) {
		echo ' · ' . esc_html(
			sprintf(
				/* translators: 1: start date, 2: end date */
				__( 'مقایسه با %1$s تا %2$s', 'zhayon-funnel' ),
				Format::jdate( $f['prev_from'] ),
				Format::jdate( $f['prev_to'] )
			)
		);
	}
	if ( $data['first_day'] ) {
		/* translators: %s: date of the first recorded day */
		echo ' · ' . esc_html( sprintf( __( 'داده از %s', 'zhayon-funnel' ), Format::jdate( $data['first_day'] ) ) );
	}
	?>
</p>

<?php if ( ! $data['first_day'] ) : ?>

	<section class="zhf-card zhf-empty">
		<h2 class="zhf-empty-title"><?php esc_html_e( 'هنوز داده‌ای ثبت نشده است', 'zhayon-funnel' ); ?></h2>
		<p><?php esc_html_e( 'به‌محض اینکه خریداری سبد خرید یا صفحهٔ تسویه حساب را ببیند، شمارش از همان روز شروع می‌شود. بازدید مدیران فروشگاه شمرده نمی‌شود، مگر اینکه «ردیابی مدیران» را در تنظیمات روشن کنید.', 'zhayon-funnel' ); ?></p>
	</section>

<?php elseif ( ! $data['has_rows'] ) : ?>

	<section class="zhf-card zhf-empty">
		<h2 class="zhf-empty-title"><?php esc_html_e( 'در این بازه داده‌ای نیست', 'zhayon-funnel' ); ?></h2>
		<p>
			<?php
			/* translators: %s: date of the first recorded day */
			echo esc_html( sprintf( __( 'اولین داده مربوط به %s است. بازهٔ دیگری انتخاب کنید یا فیلترها را بردارید.', 'zhayon-funnel' ), Format::jdate( $data['first_day'] ) ) );
			?>
		</p>
		<a class="zhf-btn zhf-btn-secondary" href="<?php echo esc_url( Admin::url( array( 'range' => '30' ) ) ); ?>"><?php esc_html_e( '۳۰ روز اخیر، بدون فیلتر', 'zhayon-funnel' ); ?></a>
	</section>

	<?php
else :
	$kpis   = Report::kpis( $data );
	$funnel = Report::funnel( $data );
	$max    = max( 1, max( array_column( $funnel, 'value' ) ) );
	?>

	<section class="zhf-kpis" aria-label="<?php esc_attr_e( 'شاخص‌ها', 'zhayon-funnel' ); ?>">
		<?php
		$kpi_cards = array(
			'cart_view'     => __( 'بازدید سبد خرید', 'zhayon-funnel' ),
			'checkout_view' => __( 'بازدید تسویه حساب', 'zhayon-funnel' ),
			'order_created' => __( 'سفارش ایجادشده', 'zhayon-funnel' ),
			'paid'          => __( 'سفارش پرداخت‌شده', 'zhayon-funnel' ),
		);
		foreach ( $kpi_cards as $step => $label ) :
			$kpi = $kpis[ $step ];
			?>
			<div class="zhf-card zhf-kpi">
				<div class="zhf-kpi-label"><?php echo esc_html( $label ); ?></div>
				<div class="zhf-kpi-value"><?php echo esc_html( Format::num( $kpi['value'] ) ); ?></div>
				<?php if ( $f['compare'] ) : ?>
					<div class="zhf-kpi-foot">
						<?php echo $zhf_pill( $kpi['change'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						<span class="zhf-muted">
							<?php
							/* translators: %s: previous period value */
							echo esc_html( sprintf( __( 'قبل: %s', 'zhayon-funnel' ), Format::num( (int) $kpi['prev'] ) ) );
							?>
						</span>
					</div>
				<?php endif; ?>
			</div>
		<?php endforeach; ?>
		<div class="zhf-card zhf-kpi">
			<div class="zhf-kpi-label"><?php esc_html_e( 'نرخ تبدیل (پرداخت ÷ سبد)', 'zhayon-funnel' ); ?></div>
			<div class="zhf-kpi-value"><?php echo esc_html( Format::pct( $kpis['conversion']['value'] ) ); ?></div>
			<?php if ( $f['compare'] ) : ?>
				<div class="zhf-kpi-foot">
					<?php echo $zhf_pill( $kpis['conversion']['change'], true ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					<span class="zhf-muted">
						<?php
						/* translators: %s: previous period value */
						echo esc_html( sprintf( __( 'قبل: %s', 'zhayon-funnel' ), Format::pct( $kpis['conversion']['prev'] ) ) );
						?>
					</span>
				</div>
			<?php endif; ?>
		</div>
	</section>

	<section class="zhf-card">
		<div class="zhf-card-head">
			<h2 class="zhf-card-title"><?php esc_html_e( 'قیف', 'zhayon-funnel' ); ?></h2>
			<span class="zhf-muted"><?php esc_html_e( 'بازدید یکتا · درصد از سبد · ریزش از مرحلهٔ قبل', 'zhayon-funnel' ); ?></span>
		</div>
		<ol class="zhf-funnel">
			<?php
			foreach ( $funnel as $row ) :
				$tip = sprintf(
					/* translators: 1: step label, 2: count */
					__( '%1$s: %2$s بازدید یکتا', 'zhayon-funnel' ),
					Labels::step( $row['step'] ),
					Format::num( $row['value'] )
				);
				if ( null !== $row['prev'] ) {
					/* translators: %s: previous period value */
					$tip .= ' · ' . sprintf( __( 'دورهٔ قبل: %s', 'zhayon-funnel' ), Format::num( $row['prev'] ) );
				}
				?>
				<li class="zhf-step<?php echo 'guest' === $row['path'] ? ' is-guest' : ''; ?><?php echo $row['worst'] ? ' is-worst' : ''; ?>">
					<div class="zhf-step-label">
						<span><?php echo esc_html( Labels::step( $row['step'] ) ); ?></span>
						<?php if ( 'guest' === $row['path'] ) : ?>
							<span class="zhf-tag"><?php esc_html_e( 'مهمان', 'zhayon-funnel' ); ?></span>
						<?php endif; ?>
						<?php if ( $row['worst'] ) : ?>
							<span class="zhf-tag zhf-tag-primary"><?php esc_html_e( 'بیشترین ریزش', 'zhayon-funnel' ); ?></span>
						<?php endif; ?>
					</div>
					<div class="zhf-step-bar" data-zhf-tip="<?php echo esc_attr( $tip ); ?>" tabindex="0" aria-label="<?php echo esc_attr( $tip ); ?>">
						<span<?php echo 0 === $row['value'] ? ' class="is-zero"' : ''; ?> style="width: <?php echo esc_attr( (string) round( $row['value'] / $max * 100, 2 ) ); ?>%"></span>
					</div>
					<div class="zhf-step-nums">
						<strong><?php echo esc_html( Format::num( $row['value'] ) ); ?></strong>
						<span class="zhf-muted"><?php echo esc_html( Format::pct( $row['share'] ) ); ?></span>
						<span class="zhf-drop<?php echo ( null !== $row['drop'] && $row['drop'] > 0 ) ? ' is-loss' : ''; ?>">
							<?php echo null === $row['drop'] ? '' : esc_html( Format::delta( -$row['drop'], 1 ) ); ?>
						</span>
					</div>
				</li>
			<?php endforeach; ?>
		</ol>
		<p class="zhf-note">
			<?php esc_html_e( 'قیف باز است: «درخواست ورود»، «ارسال کد تأیید» و «ورود / ثبت‌نام» فقط برای مهمان‌ها رخ می‌دهد و ریزششان درون همین شاخه حساب می‌شود؛ مرحله‌های اصلی با مرحلهٔ اصلی قبلی مقایسه می‌شوند. هر عدد، بازدید یکتای روزانه است.', 'zhayon-funnel' ); ?>
		</p>
	</section>

	<section class="zhf-card">
		<div class="zhf-card-head">
			<h2 class="zhf-card-title"><?php esc_html_e( 'روند روزانه', 'zhayon-funnel' ); ?></h2>
			<span class="zhf-muted"><?php esc_html_e( 'بازدید سبد و پرداخت موفق، هرکدام با محور خودش', 'zhayon-funnel' ); ?></span>
		</div>
		<?php
		$trend = array(
			'labels' => array(
				'cart' => __( 'بازدید سبد خرید', 'zhayon-funnel' ),
				'paid' => __( 'پرداخت موفق', 'zhayon-funnel' ),
			),
			'days'   => array(),
		);
		foreach ( $data['daily'] as $day => $values ) {
			$trend['days'][] = array(
				'label' => Format::jdate( $day, false ),
				'full'  => Format::jdate( $day ),
				'cart'  => (int) $values['cart_view'],
				'paid'  => (int) $values['paid'],
			);
		}
		?>
		<div class="zhf-trend" data-zhf-trend="<?php echo esc_attr( wp_json_encode( $trend ) ); ?>">
			<div class="zhf-trend-panel" data-series="cart"><div class="zhf-trend-title"><i class="zhf-key is-primary"></i><?php echo esc_html( $trend['labels']['cart'] ); ?></div><svg role="img" aria-label="<?php echo esc_attr( $trend['labels']['cart'] ); ?>"></svg></div>
			<div class="zhf-trend-panel" data-series="paid"><div class="zhf-trend-title"><i class="zhf-key"></i><?php echo esc_html( $trend['labels']['paid'] ); ?></div><svg role="img" aria-label="<?php echo esc_attr( $trend['labels']['paid'] ); ?>"></svg></div>
			<div class="zhf-tooltip" role="status" hidden></div>
		</div>
		<details class="zhf-details">
			<summary><?php esc_html_e( 'نمایش جدول روزانه', 'zhayon-funnel' ); ?></summary>
			<?php
			$daily_rows = array();
			foreach ( array_reverse( $trend['days'] ) as $d ) {
				$daily_rows[] = array( esc_html( $d['full'] ), esc_html( Format::num( $d['cart'] ) ), esc_html( Format::num( $d['paid'] ) ) );
			}
			$zhf_table(
				array( array( __( 'روز', 'zhayon-funnel' ), false ), array( $trend['labels']['cart'], true ), array( $trend['labels']['paid'], true ) ),
				$daily_rows,
				''
			);
			?>
		</details>
	</section>

	<div class="zhf-grid">

		<section class="zhf-card">
			<div class="zhf-card-head"><h2 class="zhf-card-title"><?php esc_html_e( 'درگاه پرداخت', 'zhayon-funnel' ); ?></h2></div>
			<?php
			$rows = array();
			foreach ( Report::gateways( $data ) as $g ) {
				$rows[] = array(
					'' !== $g['title'] ? $zhf_code( $g['title'], $g['id'] ) : '<code class="zhf-code">' . esc_html( $g['id'] ) . '</code>',
					esc_html( Format::num( $g['place'] ) ),
					esc_html( Format::num( $g['created'] ) ),
					esc_html( Format::num( $g['paid'] ) ),
					esc_html( Format::num( $g['failed'] ) ),
					esc_html( Format::pct( $g['success'] ) ),
				);
			}
			$zhf_table(
				array(
					array( __( 'درگاه', 'zhayon-funnel' ), false ),
					array( __( 'ثبت سفارش', 'zhayon-funnel' ), true ),
					array( __( 'ایجادشده', 'zhayon-funnel' ), true ),
					array( __( 'پرداخت‌شده', 'zhayon-funnel' ), true ),
					array( __( 'ناموفق', 'zhayon-funnel' ), true ),
					array( __( 'موفقیت', 'zhayon-funnel' ), true ),
				),
				$rows,
				__( 'هنوز سفارشی ثبت نشده است.', 'zhayon-funnel' )
			);
			?>
		</section>

		<section class="zhf-card">
			<div class="zhf-card-head"><h2 class="zhf-card-title"><?php esc_html_e( 'خطاهای پرتکرار تسویه حساب', 'zhayon-funnel' ); ?></h2></div>
			<?php
			$rows = array();
			foreach ( Report::details( $data, 'checkout_error', 10 ) as $code => $counts ) {
				$rows[] = array( $zhf_code( Labels::checkout_error( (string) $code ), (string) $code ), esc_html( Format::num( $counts[0] ) ), esc_html( Format::num( $counts[1] ) ) );
			}
			$zhf_table(
				array( array( __( 'خطا', 'zhayon-funnel' ), false ), array( __( 'یکتا', 'zhayon-funnel' ), true ), array( __( 'دفعات', 'zhayon-funnel' ), true ) ),
				$rows,
				__( 'خطایی ثبت نشده است.', 'zhayon-funnel' )
			);
			?>
		</section>

		<section class="zhf-card">
			<div class="zhf-card-head"><h2 class="zhf-card-title"><?php esc_html_e( 'کد تأیید و ورود', 'zhayon-funnel' ); ?></h2></div>
			<?php
			$rows = array();
			foreach ( array( 'otp_sent', 'login' ) as $step ) {
				foreach ( Report::details( $data, $step ) as $detail => $counts ) {
					$rows[] = array(
						esc_html( Labels::step( $step ) ),
						$zhf_code( Labels::outcome( (string) $detail ), (string) $detail ),
						esc_html( Format::num( $counts[0] ) ),
						esc_html( Format::num( $counts[1] ) ),
					);
				}
			}
			$zhf_table(
				array( array( __( 'مرحله', 'zhayon-funnel' ), false ), array( __( 'نتیجه', 'zhayon-funnel' ), false ), array( __( 'یکتا', 'zhayon-funnel' ), true ), array( __( 'دفعات', 'zhayon-funnel' ), true ) ),
				$rows,
				__( 'رویداد ورودی ثبت نشده است.', 'zhayon-funnel' )
			);
			?>
		</section>

		<section class="zhf-card">
			<div class="zhf-card-head"><h2 class="zhf-card-title"><?php esc_html_e( 'منبع ورودی', 'zhayon-funnel' ); ?></h2></div>
			<?php
			$rows = array();
			foreach ( Report::segments( $data, 'by_source' ) as $s ) {
				$rows[] = array( esc_html( Labels::source( $s['key'] ) ), esc_html( Format::num( $s['cart'] ) ), esc_html( Format::num( $s['paid'] ) ), esc_html( Format::pct( $s['conversion'] ) ) );
			}
			$zhf_table(
				array( array( __( 'منبع', 'zhayon-funnel' ), false ), array( __( 'بازدید سبد', 'zhayon-funnel' ), true ), array( __( 'پرداخت‌شده', 'zhayon-funnel' ), true ), array( __( 'نرخ تبدیل', 'zhayon-funnel' ), true ) ),
				$rows,
				__( 'داده‌ای نیست.', 'zhayon-funnel' )
			);
			?>
		</section>

		<section class="zhf-card">
			<div class="zhf-card-head"><h2 class="zhf-card-title"><?php esc_html_e( 'دستگاه', 'zhayon-funnel' ); ?></h2></div>
			<?php
			$rows = array();
			foreach ( Report::segments( $data, 'by_device' ) as $s ) {
				$rows[] = array( esc_html( Labels::device( $s['key'] ) ), esc_html( Format::num( $s['cart'] ) ), esc_html( Format::num( $s['checkout'] ) ), esc_html( Format::num( $s['paid'] ) ), esc_html( Format::pct( $s['conversion'] ) ) );
			}
			$zhf_table(
				array( array( __( 'دستگاه', 'zhayon-funnel' ), false ), array( __( 'بازدید سبد', 'zhayon-funnel' ), true ), array( __( 'تسویه حساب', 'zhayon-funnel' ), true ), array( __( 'پرداخت‌شده', 'zhayon-funnel' ), true ), array( __( 'نرخ تبدیل', 'zhayon-funnel' ), true ) ),
				$rows,
				__( 'داده‌ای نیست.', 'zhayon-funnel' )
			);
			?>
		</section>

		<section class="zhf-card">
			<div class="zhf-card-head"><h2 class="zhf-card-title"><?php esc_html_e( 'کد تخفیف', 'zhayon-funnel' ); ?></h2></div>
			<?php
			$rows = array();
			foreach ( Report::details( $data, 'coupon_apply' ) as $detail => $counts ) {
				$rows[] = array( esc_html( Labels::outcome( (string) $detail ) ), esc_html( Format::num( $counts[0] ) ), esc_html( Format::num( $counts[1] ) ) );
			}
			$zhf_table(
				array( array( __( 'نتیجه', 'zhayon-funnel' ), false ), array( __( 'یکتا', 'zhayon-funnel' ), true ), array( __( 'دفعات', 'zhayon-funnel' ), true ) ),
				$rows,
				__( 'کد تخفیفی اعمال نشده است.', 'zhayon-funnel' )
			);
			?>
		</section>

	</div>

<?php endif; ?>
