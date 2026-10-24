<?php
/**
 * Signed profiling cookie.
 *
 * The cookie is checked when the plugin file loads, before WordPress has
 * worked out who the current user is, so it carries its own HMAC signature.
 * The collector re-checks the real logged-in user and capability before it
 * records anything.
 */

defined( 'ABSPATH' ) || exit;

class SQA_Session {

	const SECRET_OPTION = 'sqa_secret';

	public static function cookie_name() {
		return 'sqa_profile_' . ( defined( 'COOKIEHASH' ) ? COOKIEHASH : md5( site_url() ) );
	}

	public static function ensure_secret() {
		if ( ! get_option( self::SECRET_OPTION ) ) {
			// Not autoloaded: it is only read when a profiling cookie is present.
			add_option( self::SECRET_OPTION, wp_generate_password( 64, true, true ), '', 'no' );
		}
	}

	private static function secret() {
		$secret = get_option( self::SECRET_OPTION );
		if ( ! $secret ) {
			return '';
		}
		return $secret . ( defined( 'AUTH_KEY' ) ? AUTH_KEY : '' ) . ( defined( 'AUTH_SALT' ) ? AUTH_SALT : '' );
	}

	private static function sign( $user_id, $expires ) {
		return hash_hmac( 'sha256', $user_id . '|' . $expires, self::secret() );
	}

	/**
	 * @return array{user_id:int,expires:int}|null Cookie data, or null when absent, invalid or expired.
	 */
	public static function get_cookie() {
		$name = self::cookie_name();
		if ( empty( $_COOKIE[ $name ] ) || ! is_string( $_COOKIE[ $name ] ) ) {
			return null;
		}

		$parts = explode( '|', $_COOKIE[ $name ] );
		if ( 3 !== count( $parts ) ) {
			return null;
		}

		list( $user_id, $expires, $signature ) = $parts;
		if ( ! ctype_digit( $user_id ) || ! ctype_digit( $expires ) || (int) $expires < time() ) {
			return null;
		}

		$secret = self::secret();
		if ( '' === $secret || ! hash_equals( self::sign( $user_id, $expires ), $signature ) ) {
			return null;
		}

		return array(
			'user_id' => (int) $user_id,
			'expires' => (int) $expires,
		);
	}

	public static function get_cookie_user_id() {
		$cookie = self::get_cookie();
		return $cookie ? $cookie['user_id'] : 0;
	}

	public static function start( $user_id, $minutes ) {
		self::ensure_secret();

		$expires = time() + max( 1, (int) $minutes ) * MINUTE_IN_SECONDS;
		$value   = (int) $user_id . '|' . $expires . '|' . self::sign( (int) $user_id, $expires );

		self::set( $value, $expires );
		$_COOKIE[ self::cookie_name() ] = $value;
	}

	public static function clear_cookie() {
		self::set( '', time() - YEAR_IN_SECONDS );
		unset( $_COOKIE[ self::cookie_name() ] );
	}

	private static function set( $value, $expires ) {
		if ( headers_sent() ) {
			return;
		}

		// Same paths WordPress uses for its logged-in cookie, so the cookie
		// reaches both the front end and wp-admin (also for subdirectory installs).
		$paths = array_unique( array( COOKIEPATH, SITECOOKIEPATH ) );
		foreach ( $paths as $path ) {
			setcookie(
				self::cookie_name(),
				$value,
				array(
					'expires'  => $expires,
					'path'     => $path,
					'domain'   => COOKIE_DOMAIN,
					'secure'   => is_ssl(),
					'httponly' => true,
					'samesite' => 'Lax',
				)
			);
		}
	}
}
