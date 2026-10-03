<?php
namespace Saunaplan\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Remembers a signed-in device with a random token in a cookie.
 * Only a hash of the token is stored, so a database leak can't be used to log in.
 */
class Sessions {

	const COOKIE = 'saunaplan_session';

	/** @var object|null|false Resident of the current request (false = not looked up yet). */
	private static $current = false;

	public static function provider(): Identity_Provider {
		$provider = apply_filters( 'saunaplan_identity_provider', null );
		return $provider instanceof Identity_Provider ? $provider : new Unit_Code_Provider();
	}

	/**
	 * Verify login input with the identity provider and start a session.
	 *
	 * @return object|\WP_Error The resident.
	 */
	public static function login( array $input ) {
		$provider = self::provider();
		$identity = $provider->verify( $input );
		if ( is_wp_error( $identity ) ) {
			return $identity;
		}

		$resident = null;
		if ( '' !== (string) $identity['external_id'] ) {
			$resident = Repository::resident_by_external( $provider->id(), (string) $identity['external_id'] );
		}
		if ( ! $resident ) {
			$id       = Repository::insert_resident( (int) $identity['unit_id'], $provider->id(), (string) $identity['external_id'] );
			$resident = Repository::resident( $id );
		} elseif ( (int) $resident->unit_id !== (int) $identity['unit_id'] ) {
			Repository::update_resident( (int) $resident->id, array( 'unit_id' => (int) $identity['unit_id'] ) );
			$resident = Repository::resident( (int) $resident->id );
		}
		if ( ! $resident ) {
			return new \WP_Error( 'saunaplan_db', __( 'Something went wrong. Please try again.', 'saunaplan' ), array( 'status' => 500 ) );
		}

		$token = bin2hex( random_bytes( 32 ) );
		Repository::insert_session( (int) $resident->id, self::hash( $token ), self::expiry() );
		self::set_cookie( $token );
		self::$current = $resident;
		return $resident;
	}

	public static function logout(): void {
		$token = self::token();
		if ( $token ) {
			Repository::delete_session_by_hash( self::hash( $token ) );
		}
		self::set_cookie( '', time() - YEAR_IN_SECONDS );
		self::$current = null;
	}

	/** The signed-in resident of this request, or null. */
	public static function current() {
		if ( false !== self::$current ) {
			return self::$current;
		}
		self::$current = null;
		$token         = self::token();
		if ( ! $token ) {
			return null;
		}
		$session = Repository::session_by_hash( self::hash( $token ) );
		if ( ! $session ) {
			return null;
		}
		$resident = Repository::resident( (int) $session->resident_id );
		$unit     = $resident ? Repository::unit( (int) $resident->unit_id ) : null;
		if ( ! $resident || ! $unit || ! $unit->active ) {
			return null;
		}
		// Sliding expiry, refreshed at most once a day.
		if ( strtotime( $session->last_seen_at . ' UTC' ) < time() - DAY_IN_SECONDS ) {
			Repository::touch_session( (int) $session->id, self::expiry() );
			Repository::update_resident( (int) $resident->id, array( 'last_seen_at' => Repository::now_utc() ) );
			self::set_cookie( $token );
		}
		self::$current = $resident;
		return $resident;
	}

	/** Forget the cached resident (tests, after logout of a unit). */
	public static function reset(): void {
		self::$current = false;
	}

	private static function token(): string {
		$token = isset( $_COOKIE[ self::COOKIE ] ) ? (string) wp_unslash( $_COOKIE[ self::COOKIE ] ) : '';
		return preg_match( '/^[a-f0-9]{64}$/', $token ) ? $token : '';
	}

	private static function hash( string $token ): string {
		return hash( 'sha256', $token );
	}

	private static function days(): int {
		return max( 1, (int) Settings::get( 'session_days' ) );
	}

	private static function expiry(): string {
		return gmdate( 'Y-m-d H:i:s', time() + self::days() * DAY_IN_SECONDS );
	}

	private static function set_cookie( string $token, int $expires = 0 ): void {
		if ( headers_sent() ) {
			return;
		}
		setcookie(
			self::COOKIE,
			$token,
			array(
				'expires'  => $expires ?: time() + self::days() * DAY_IN_SECONDS,
				'path'     => COOKIEPATH ?: '/',
				'domain'   => COOKIE_DOMAIN ?: '',
				'secure'   => is_ssl(),
				'httponly' => true,
				'samesite' => 'Lax',
			)
		);
		if ( '' === $token ) {
			unset( $_COOKIE[ self::COOKIE ] );
		} else {
			$_COOKIE[ self::COOKIE ] = $token;
		}
	}
}
