<?php
/**
 * Global rate limiter for the tools execute endpoint.
 *
 * Uses time-bucketed WP transients. Each client IP gets its own
 * counter that auto-expires when the window rolls over.
 *
 * @package AgentFriendlyWP
 */

namespace AgentFriendlyWP;

defined( 'ABSPATH' ) || exit;

class Rate_Limiter {

	const OPTION_MAX    = 'afwp_rate_limit_max';
	const OPTION_WINDOW = 'afwp_rate_limit_window';

	public static function get_max(): int {
		return (int) get_option( self::OPTION_MAX, 120 );
	}

	public static function get_window(): int {
		return max( 1, (int) get_option( self::OPTION_WINDOW, 60 ) );
	}

	private static function bucket_key(): string {
		$window = self::get_window();
		$client = md5( self::get_client_ip() );
		$bucket = (int) floor( time() / $window );
		return 'afwp_rl_' . $client . '_' . $bucket;
	}

	public static function check(): bool {
		$key   = self::bucket_key();
		$count = (int) get_transient( $key );
		return $count < self::get_max();
	}

	public static function increment(): void {
		$key    = self::bucket_key();
		$window = self::get_window();
		$count  = (int) get_transient( $key );
		set_transient( $key, $count + 1, $window * 2 );
	}

	/** @return array<string, string|int> */
	public static function get_headers(): array {
		$max    = self::get_max();
		$window = self::get_window();
		$key    = self::bucket_key();
		$count  = (int) get_transient( $key );
		$reset  = ( (int) floor( time() / $window ) + 1 ) * $window;

		$headers = [
			'X-RateLimit-Limit'     => $max,
			'X-RateLimit-Remaining' => max( 0, $max - $count ),
			'X-RateLimit-Reset'     => $reset,
		];

		if ( $count >= $max ) {
			$headers['Retry-After'] = $reset - time();
		}

		return $headers;
	}

	private static function get_client_ip(): string {
		$ip = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1' ) );
		return apply_filters( 'afwp_rate_limiter_ip', $ip );
	}
}
