<?php
/**
 * zhiva-panel integration: OTP sends and failed logins from its login SPA.
 *
 * Loaded only when zhiva-panel is active, on POST requests that already carry
 * a visit cookie. Successful logins need nothing here — they end in
 * wp_set_auth_cookie(), which the core `login` step already counts.
 *
 * Routes (namespace `zhiva-panel`, generated from zhiva-panel/routes/v1/):
 *   POST /zhiva-panel/v1/EveryClient/OTP/sms        → otp_sent  ok | <error code>
 *   POST /zhiva-panel/v1/Auth/login-or-register     → login     <error code> (failures only)
 * WordPress matches REST routes case-insensitively, so they are compared lowercased.
 *
 * @package Zhayon\Funnel
 */

namespace Zhayon\Funnel\Integrations;

defined( 'ABSPATH' ) || exit;

add_filter( 'rest_post_dispatch', __NAMESPACE__ . '\\zhiva_rest_post_dispatch', 10, 3 );

/**
 * @param \WP_HTTP_Response|\WP_Error $result
 * @param \WP_REST_Server             $server
 * @param \WP_REST_Request            $request
 */
function zhiva_rest_post_dispatch( $result, $server, $request ) {
	try {
		if ( ! $request instanceof \WP_REST_Request || 'POST' !== $request->get_method() ) {
			return $result;
		}
		$routes = apply_filters(
			'zhf_zhiva_routes',
			array(
				'otp'   => '/zhiva-panel/v1/everyclient/otp/sms',
				'login' => '/zhiva-panel/v1/auth/login-or-register',
			)
		);
		$route = strtolower( untrailingslashit( (string) $request->get_route() ) );

		if ( $route === $routes['otp'] ) {
			$code = zhiva_error_code( $result );
			do_action( 'zhf_track', 'otp_sent', '' === $code ? 'ok' : $code );
		} elseif ( $route === $routes['login'] ) {
			$code = zhiva_error_code( $result );
			if ( '' !== $code ) {
				do_action( 'zhf_track', 'login', $code );
			}
		}
	} catch ( \Throwable $e ) {
		return $result;
	}
	return $result;
}

/**
 * '' on success, otherwise the WP_Error code from the response.
 *
 * @param \WP_HTTP_Response|\WP_Error $result
 */
function zhiva_error_code( $result ): string {
	if ( is_wp_error( $result ) ) {
		return (string) $result->get_error_code();
	}
	if ( ! $result instanceof \WP_HTTP_Response ) {
		return 'unknown';
	}
	$data = $result->get_data();
	if ( $result->get_status() >= 400 ) {
		return ( is_array( $data ) && ! empty( $data['code'] ) ) ? (string) $data['code'] : 'http_' . (int) $result->get_status();
	}
	// zhiva success payloads look like { success: true, ... }.
	if ( is_array( $data ) && isset( $data['success'] ) && false === $data['success'] ) {
		return ! empty( $data['code'] ) && is_string( $data['code'] ) ? $data['code'] : 'failed';
	}
	return '';
}
