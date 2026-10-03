<?php
namespace Saunaplan\Core;

use WP_Error;
use WP_REST_Request;

defined( 'ABSPATH' ) || exit;

/**
 * REST API for the booking page.
 *
 * Everything except login (and rating via an emailed link) needs a signed-in
 * device. Write requests must carry the X-Saunaplan header: browsers don't
 * send custom headers cross-site without CORS approval, which (together with
 * the SameSite cookie) protects against cross-site request forgery.
 */
class Rest_Controller {

	const NS = 'saunaplan/v1';

	public static function init() {
		add_action( 'rest_api_init', array( self::class, 'routes' ) );
	}

	public static function routes() {
		$date = array(
			'required'          => true,
			'type'              => 'string',
			'validate_callback' => static function ( $value ) {
				return Time::is_date( $value );
			},
		);
		$time = array(
			'required'          => true,
			'type'              => 'string',
			'validate_callback' => static function ( $value ) {
				return Time::is_time( $value );
			},
		);
		$id   = array( 'required' => true, 'type' => 'integer' );

		$routes = array(
			'/login'  => array( 'POST', 'login', array( 'code' => array( 'type' => 'string', 'default' => '' ) ), 'write' ),
			'/logout' => array( 'POST', 'logout', array(), 'write' ),
			'/state'  => array( 'GET', 'state', array(), 'resident' ),
			'/book'   => array(
				'POST',
				'book',
				array(
					'date'       => $date,
					'time'       => $time,
					'mode'       => array( 'required' => true, 'type' => 'string' ),
					'counts'     => array( 'type' => 'object', 'default' => array() ),
					'attributes' => array( 'type' => 'object', 'default' => array() ),
				),
				'resident_write',
			),
			'/join'   => array(
				'POST',
				'join',
				array(
					'date'   => $date,
					'time'   => $time,
					'counts' => array( 'type' => 'object', 'default' => array() ),
				),
				'resident_write',
			),
			'/cancel' => array( 'POST', 'cancel', array( 'id' => $id ), 'resident_write' ),
			'/leave'  => array( 'POST', 'leave', array( 'id' => $id ), 'resident_write' ),
			'/wait'   => array( 'POST', 'wait', array( 'date' => $date, 'time' => $time ), 'resident_write' ),
			'/unwait' => array( 'POST', 'unwait', array( 'date' => $date, 'time' => $time ), 'resident_write' ),
			'/email'  => array( 'POST', 'email', array( 'email' => array( 'type' => 'string', 'default' => '' ) ), 'resident_write' ),
			'/rating' => array(
				'GET',
				'rating_info',
				array(
					'id'  => $id,
					'r'   => array( 'type' => 'integer', 'default' => 0 ),
					'key' => array( 'type' => 'string', 'default' => '' ),
				),
				'public',
			),
			'/rate'   => array(
				'POST',
				'rate',
				array(
					'id'      => $id,
					'r'       => array( 'type' => 'integer', 'default' => 0 ),
					'key'     => array( 'type' => 'string', 'default' => '' ),
					'scales'  => array( 'type' => 'object', 'default' => array() ),
					'issues'  => array( 'type' => 'array', 'default' => array() ),
					'comment' => array( 'type' => 'string', 'default' => '' ),
					'quick'   => array( 'type' => 'boolean', 'default' => false ),
				),
				'write',
			),
		);

		foreach ( $routes as $path => $route ) {
			list( $method, $callback, $args, $permission ) = $route;
			register_rest_route(
				self::NS,
				$path,
				array(
					'methods'             => $method,
					'callback'            => array( self::class, $callback ),
					'permission_callback' => array( self::class, 'permission_' . $permission ),
					'args'                => $args,
				)
			);
		}
	}

	/* ---------------------------------------------------------- Permissions */

	public static function permission_public() {
		return true;
	}

	public static function permission_write( WP_REST_Request $request ) {
		if ( '1' !== $request->get_header( 'x_saunaplan' ) ) {
			return new WP_Error( 'saunaplan_forbidden', 'Missing request header.', array( 'status' => 403 ) );
		}
		return true;
	}

	public static function permission_resident() {
		if ( ! Sessions::current() ) {
			return new WP_Error( 'saunaplan_signed_out', __( 'Please sign in again.', 'saunaplan' ), array( 'status' => 401 ) );
		}
		return true;
	}

	public static function permission_resident_write( WP_REST_Request $request ) {
		$write = self::permission_write( $request );
		return true === $write ? self::permission_resident() : $write;
	}

	/* ------------------------------------------------------------ Callbacks */

	private static function respond( $data ) {
		$response = rest_ensure_response( $data );
		$response->header( 'Cache-Control', 'no-store, max-age=0' );
		return $response;
	}

	public static function login( WP_REST_Request $request ) {
		if ( self::rate_limited( 'login', 10 ) ) {
			return new WP_Error( 'saunaplan_rate_limit', __( 'Too many attempts. Please wait a few minutes and try again.', 'saunaplan' ), array( 'status' => 429 ) );
		}
		$resident = Sessions::login( $request->get_params() );
		if ( is_wp_error( $resident ) ) {
			return $resident;
		}
		return self::respond( self::state_for( $resident ) );
	}

	public static function logout() {
		Sessions::logout();
		return self::respond( array( 'signedOut' => true ) );
	}

	public static function state() {
		return self::respond( self::state_for( Sessions::current() ) );
	}

	/** Full page state: who am I, what can be chosen, the slots. */
	public static function state_for( $resident ): array {
		$unit    = Repository::unit( (int) $resident->unit_id );
		$pending = Settings::get( 'rating_gate' ) ? Ratings::pending_for_unit( (int) $resident->unit_id ) : null;
		return array(
			'me'       => array(
				'unit'         => Units::label( $unit ),
				'email'        => (string) $resident->email,
				'emailPending' => (string) $resident->email_pending,
			),
			'schema'   => Room::schema(),
			'settings' => array(
				'capacity'     => (int) Settings::get( 'capacity' ),
				'horizon'      => (int) Settings::get( 'horizon_days' ),
				'limit'        => (int) Settings::get( 'monthly_limit' ),
				'consecutive'  => (int) Settings::get( 'max_consecutive' ),
				'ratingGate'   => (bool) Settings::get( 'rating_gate' ),
				'reminderHours' => (int) Settings::get( 'reminder_hours' ),
			),
			'pendingRating' => $pending ? self::rating_target( $pending ) : null,
		) + Schedule::view( $resident );
	}

	private static function rating_target( $booking ): array {
		return array(
			'id'    => $booking->id,
			'label' => Time::format_long_date( $booking->slot_date ) . ', ' . Time::format_range( $booking ),
		);
	}

	public static function book( WP_REST_Request $request ) {
		$resident = Sessions::current();
		$result   = Booking_Service::book( $resident, $request['date'], $request['time'], (string) $request['mode'], (array) $request['counts'], (array) $request['attributes'] );
		return self::after_change( $result, $resident, __( 'Booked! Enjoy your session.', 'saunaplan' ) );
	}

	public static function join( WP_REST_Request $request ) {
		$resident = Sessions::current();
		$result   = Booking_Service::join( $resident, $request['date'], $request['time'], (array) $request['counts'] );
		return self::after_change( $result, $resident, __( 'You have joined the session.', 'saunaplan' ) );
	}

	public static function cancel( WP_REST_Request $request ) {
		$resident = Sessions::current();
		return self::after_change( Booking_Service::cancel( $resident, (int) $request['id'] ), $resident, __( 'Your booking has been cancelled.', 'saunaplan' ) );
	}

	public static function leave( WP_REST_Request $request ) {
		$resident = Sessions::current();
		return self::after_change( Booking_Service::leave( $resident, (int) $request['id'] ), $resident, __( 'You have left the session.', 'saunaplan' ) );
	}

	public static function wait( WP_REST_Request $request ) {
		$resident = Sessions::current();
		$message  = $resident->email
			? __( 'You are on the waiting list. We will email you if a place becomes free.', 'saunaplan' )
			: __( 'You are on the waiting list. Add your email address to be notified when a place becomes free.', 'saunaplan' );
		return self::after_change( Booking_Service::wait( $resident, $request['date'], $request['time'] ), $resident, $message );
	}

	public static function unwait( WP_REST_Request $request ) {
		$resident = Sessions::current();
		return self::after_change( Booking_Service::unwait( $resident, $request['date'], $request['time'] ), $resident, __( 'You have left the waiting list.', 'saunaplan' ) );
	}

	private static function after_change( $result, $resident, string $message ) {
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return self::respond( array( 'message' => $message ) + self::state_for( $resident ) );
	}

	/** Set (or remove) the email address for mails; a new address must be confirmed first. */
	public static function email( WP_REST_Request $request ) {
		$resident = Sessions::current();
		$email    = strtolower( trim( sanitize_email( (string) $request['email'] ) ) );
		if ( '' === trim( (string) $request['email'] ) ) {
			Repository::update_resident( (int) $resident->id, array( 'email' => '', 'email_pending' => '' ) );
			return self::respond( array( 'message' => __( 'Your email address has been removed.', 'saunaplan' ) ) + self::state_for( Repository::resident( (int) $resident->id ) ) );
		}
		if ( ! is_email( $email ) ) {
			return new WP_Error( 'saunaplan_email', __( 'Please enter a valid email address.', 'saunaplan' ), array( 'status' => 400 ) );
		}
		if ( self::rate_limited( 'email', 5 ) ) {
			return new WP_Error( 'saunaplan_rate_limit', __( 'Too many attempts. Please wait a few minutes and try again.', 'saunaplan' ), array( 'status' => 429 ) );
		}
		Repository::update_resident( (int) $resident->id, array( 'email_pending' => $email ) );
		$resident = Repository::resident( (int) $resident->id );
		Notifications::request_email_confirmation( $resident );
		return self::respond(
			array(
				/* translators: %s: email address */
				'message' => sprintf( __( 'Almost done: please click the link we just sent to %s.', 'saunaplan' ), $email ),
			) + self::state_for( $resident )
		);
	}

	/**
	 * Resolve who rates: the signed-in device, or the emailed link (booking + resident + key).
	 *
	 * @return array{0:object,1:object}|WP_Error booking, resident
	 */
	private static function rating_context( WP_REST_Request $request ) {
		$booking  = Repository::booking( (int) $request['id'] );
		$resident = null;
		if ( $request['key'] && $request['r'] && Ratings::verify_key( (int) $request['id'], (int) $request['r'], (string) $request['key'] ) ) {
			$resident = Repository::resident( (int) $request['r'] );
		}
		if ( ! $resident ) {
			$resident = Sessions::current();
		}
		if ( ! $resident ) {
			return new WP_Error( 'saunaplan_signed_out', __( 'Please sign in again.', 'saunaplan' ), array( 'status' => 401 ) );
		}
		if ( ! $booking ) {
			return new WP_Error( 'saunaplan_rating', __( 'This session was not found.', 'saunaplan' ), array( 'status' => 404 ) );
		}
		return array( $booking, $resident );
	}

	public static function rating_info( WP_REST_Request $request ) {
		$context = self::rating_context( $request );
		if ( is_wp_error( $context ) ) {
			return $context;
		}
		list( $booking, $resident ) = $context;
		return self::respond(
			array(
				'target' => self::rating_target( $booking ),
				'rated'  => Repository::rating_exists( $booking->id, (int) $resident->unit_id ),
				'schema' => Room::schema(),
			)
		);
	}

	public static function rate( WP_REST_Request $request ) {
		$context = self::rating_context( $request );
		if ( is_wp_error( $context ) ) {
			return $context;
		}
		list( $booking, $resident ) = $context;
		$result = Ratings::save( $booking, (int) $resident->unit_id, (int) $resident->id, $request->get_params() );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$data = array( 'message' => __( 'Thank you for your feedback!', 'saunaplan' ) );
		if ( Sessions::current() ) {
			$data += self::state_for( Sessions::current() );
		}
		return self::respond( $data );
	}

	/** Simple per-IP throttle. */
	private static function rate_limited( string $bucket, int $limit ): bool {
		$limit  = (int) apply_filters( 'saunaplan_rate_limit', $limit, $bucket );
		$window = 10 * MINUTE_IN_SECONDS;
		$ip     = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : 'unknown';
		$key    = 'saunaplan_rl_' . $bucket . '_' . md5( $ip );
		$hits   = (int) get_transient( $key );
		if ( $limit > 0 && $hits >= $limit ) {
			return true;
		}
		set_transient( $key, $hits + 1, $window );
		return false;
	}
}
