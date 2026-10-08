<?php
/**
 * Human labels for steps, sources, devices and detail codes.
 *
 * @package Zhayon\Funnel
 */

namespace Zhayon\Funnel\Admin;

defined( 'ABSPATH' ) || exit;

final class Labels {

	public static function step( string $step ): string {
		$labels = array(
			'cart_view'      => __( 'مشاهدهٔ سبد خرید', 'zhayon-funnel' ),
			'checkout_gate'  => __( 'درخواست ورود', 'zhayon-funnel' ),
			'otp_sent'       => __( 'ارسال کد تأیید', 'zhayon-funnel' ),
			'login'          => __( 'ورود / ثبت‌نام', 'zhayon-funnel' ),
			'checkout_view'  => __( 'مشاهدهٔ تسویه حساب', 'zhayon-funnel' ),
			'place_order'    => __( 'زدن «ثبت سفارش»', 'zhayon-funnel' ),
			'checkout_error' => __( 'خطای تسویه حساب', 'zhayon-funnel' ),
			'order_created'  => __( 'ایجاد سفارش', 'zhayon-funnel' ),
			'paid'           => __( 'پرداخت موفق', 'zhayon-funnel' ),
			'payment_failed' => __( 'پرداخت ناموفق', 'zhayon-funnel' ),
			'coupon_apply'   => __( 'اعمال کد تخفیف', 'zhayon-funnel' ),
		);
		return $labels[ $step ] ?? $step;
	}

	public static function source( string $source ): string {
		$labels = array(
			'torob'  => __( 'ترب', 'zhayon-funnel' ),
			'snapp'  => __( 'اسنپ', 'zhayon-funnel' ),
			'emalls' => __( 'ایمالز', 'zhayon-funnel' ),
			'google' => __( 'گوگل', 'zhayon-funnel' ),
			'direct' => __( 'مستقیم', 'zhayon-funnel' ),
			'other'  => __( 'سایر', 'zhayon-funnel' ),
			'test'   => __( 'آزمایشی', 'zhayon-funnel' ),
		);
		return $labels[ $source ] ?? $source;
	}

	public static function device( string $device ): string {
		return 'm' === $device ? __( 'موبایل', 'zhayon-funnel' ) : __( 'دسکتاپ', 'zhayon-funnel' );
	}

	/** Outcome detail for otp_sent / login / coupon_apply. */
	public static function outcome( string $detail ): string {
		$labels = array(
			'ok'       => __( 'موفق', 'zhayon-funnel' ),
			'login'    => __( 'ورود', 'zhayon-funnel' ),
			'register' => __( 'ثبت‌نام', 'zhayon-funnel' ),
			'invalid'  => __( 'نامعتبر', 'zhayon-funnel' ),
			'other'    => __( 'سایر', 'zhayon-funnel' ),
		);
		return $labels[ $detail ] ?? $detail;
	}

	/**
	 * Checkout error code → readable label. WooCommerce field errors are
	 * "<field>_required" / "<field>_validation"; the field label comes from
	 * the store's address fields.
	 */
	public static function checkout_error( string $code ): string {
		static $fields = null;
		$known = array(
			'shipping' => __( 'روش ارسال انتخاب نشده', 'zhayon-funnel' ),
			'payment'  => __( 'روش پرداخت نامعتبر', 'zhayon-funnel' ),
			'terms'    => __( 'پذیرش قوانین', 'zhayon-funnel' ),
			'notice'   => __( 'پیام خطای افزونه / قالب', 'zhayon-funnel' ),
			'other'    => __( 'سایر', 'zhayon-funnel' ),
		);
		if ( isset( $known[ $code ] ) ) {
			return $known[ $code ];
		}

		if ( null === $fields ) {
			$fields = array();
			try {
				if ( function_exists( 'WC' ) && WC()->countries ) {
					$country = WC()->countries->get_base_country();
					foreach ( array( 'billing_', 'shipping_' ) as $prefix ) {
						foreach ( WC()->countries->get_address_fields( $country, $prefix ) as $key => $field ) {
							if ( ! empty( $field['label'] ) ) {
								$fields[ $key ] = wp_strip_all_tags( (string) $field['label'] );
							}
						}
					}
				}
			} catch ( \Throwable $e ) {
				$fields = array();
			}
		}

		if ( preg_match( '/^(.+)_(required|validation)$/', $code, $m ) ) {
			$field = $fields[ $m[1] ] ?? $m[1];
			return 'required' === $m[2]
				/* translators: %s: checkout field label */
				? sprintf( __( '%s خالی است', 'zhayon-funnel' ), $field )
				/* translators: %s: checkout field label */
				: sprintf( __( '%s نامعتبر است', 'zhayon-funnel' ), $field );
		}
		return $fields[ $code ] ?? $code;
	}
}
