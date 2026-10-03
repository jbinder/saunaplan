<?php
namespace Saunaplan\Core;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Booking, joining, cancelling, leaving and the waiting list.
 *
 * Writes are serialized with one database lock, so concurrent requests
 * can't double-book a slot or slip past the monthly/consecutive limits.
 */
class Booking_Service {

	const LOCK = 'saunaplan_booking';

	/**
	 * @return object|WP_Error The new booking.
	 */
	public static function book( $resident, string $date, string $time, string $mode, $counts, $attributes ) {
		return self::locked(
			function () use ( $resident, $date, $time, $mode, $counts, $attributes ) {
				$draft             = self::draft( 'book', $resident, $date, $time );
				$draft->mode       = $mode;
				$draft->counts     = Room::sanitize_counts( $counts );
				$draft->attributes = Room::sanitize_attributes( $attributes, $mode );
				$error             = Rules::check( $draft );
				if ( $error ) {
					return $error;
				}

				$id = Repository::insert_booking(
					array(
						'slot_date'    => $date,
						'slot_time'    => $time,
						'duration_min' => (int) $draft->slot['duration'],
						'mode'         => $mode,
						'attributes'   => $draft->attributes,
						'owner_id'     => (int) $resident->id,
						'unit_id'      => (int) $resident->unit_id,
					)
				);
				if ( ! $id ) {
					return self::db_error();
				}
				Repository::insert_participant( $id, (int) $resident->id, (int) $resident->unit_id, $draft->counts );
				Repository::remove_waitlist( $date, $time, (int) $resident->id );

				$booking = Repository::booking( $id );
				do_action( 'saunaplan_booked', $booking, $resident );
				return $booking;
			}
		);
	}

	/**
	 * Join an open session (also a recurring one that nobody joined yet).
	 *
	 * @return object|WP_Error The booking joined.
	 */
	public static function join( $resident, string $date, string $time, $counts ) {
		return self::locked(
			function () use ( $resident, $date, $time, $counts ) {
				$draft         = self::draft( 'join', $resident, $date, $time );
				$occupancy     = $draft->slot ? Schedule::occupancy( $draft->slot ) : null;
				$draft->mode   = $occupancy['mode'] ?? '';
				$draft->counts = Room::sanitize_counts( $counts );
				if ( ! $occupancy ) {
					return Rules::error( 'free', __( 'This time slot is free – you can book it.', 'saunaplan' ) );
				}
				$error = Rules::check( $draft );
				if ( $error ) {
					return $error;
				}

				$booking = Schedule::materialize( $draft->slot );
				if ( ! $booking ) {
					return self::db_error();
				}
				Repository::insert_participant( $booking->id, (int) $resident->id, (int) $resident->unit_id, $draft->counts );
				Repository::remove_waitlist( $date, $time, (int) $resident->id );
				do_action( 'saunaplan_joined', $booking, $resident );
				return $booking;
			}
		);
	}

	/**
	 * Cancel a booking of the resident's flat.
	 *
	 * @return true|WP_Error
	 */
	public static function cancel( $resident, int $booking_id ) {
		$booking = Repository::booking( $booking_id );
		if ( ! $booking || null !== $booking->cancelled_at || ! $booking->owner_id || $booking->unit_id !== (int) $resident->unit_id ) {
			return Rules::error( 'not_found', __( 'This booking was not found. Maybe it was already cancelled.', 'saunaplan' ), 404 );
		}
		if ( Time::start( $booking ) <= Time::now() ) {
			return Rules::error( 'past', __( 'This session has already started.', 'saunaplan' ) );
		}
		self::do_cancel( $booking, $resident );
		return true;
	}

	/** Cancel by an administrator (also recurring sessions). */
	public static function admin_cancel( int $booking_id ): bool {
		$booking = Repository::booking( $booking_id );
		if ( ! $booking || null !== $booking->cancelled_at ) {
			return false;
		}
		self::do_cancel( $booking, null );
		return true;
	}

	private static function do_cancel( $booking, $by ): void {
		$participants = Repository::participants( $booking->id );
		Repository::update_booking( $booking->id, array( 'cancelled_at' => Repository::now_utc() ) );
		do_action( 'saunaplan_cancelled', $booking, $participants, $by );
		self::slot_freed( $booking );
	}

	/**
	 * Leave a session the resident's flat joined.
	 *
	 * @return true|WP_Error
	 */
	public static function leave( $resident, int $booking_id ) {
		$booking = Repository::booking( $booking_id );
		if ( ! $booking || null !== $booking->cancelled_at ) {
			return Rules::error( 'not_found', __( 'This booking was not found. Maybe it was already cancelled.', 'saunaplan' ), 404 );
		}
		if ( Time::start( $booking ) <= Time::now() ) {
			return Rules::error( 'past', __( 'This session has already started.', 'saunaplan' ) );
		}
		if ( $booking->owner_id && $booking->unit_id === (int) $resident->unit_id ) {
			return self::cancel( $resident, $booking_id );
		}
		$left = false;
		foreach ( Repository::participants( $booking->id ) as $p ) {
			if ( $p->unit_id === (int) $resident->unit_id ) {
				Repository::delete_participant( $p->id );
				$left = true;
			}
		}
		if ( ! $left ) {
			return Rules::error( 'not_found', __( 'Your flat is not part of this session.', 'saunaplan' ), 404 );
		}
		do_action( 'saunaplan_left', $booking, $resident );
		self::slot_freed( $booking );
		return true;
	}

	/**
	 * Put the resident on the waiting list of a taken slot.
	 *
	 * @return true|WP_Error
	 */
	public static function wait( $resident, string $date, string $time ) {
		$slot = Time::is_date( $date ) && Time::is_time( $time ) ? Schedule::slot( $date, $time ) : null;
		if ( ! $slot || Time::at( $date, $time ) <= Time::now() ) {
			return Rules::error( 'slot', __( 'This time slot does not exist.', 'saunaplan' ), 404 );
		}
		$occupancy = Schedule::occupancy( $slot );
		if ( ! $occupancy ) {
			return Rules::error( 'free', __( 'This time slot is free – you can book it.', 'saunaplan' ) );
		}
		if ( Room::BLOCKED === $occupancy['mode'] ) {
			return Rules::error( 'blocked', __( 'This time slot is not available.', 'saunaplan' ) );
		}
		Repository::add_waitlist( $date, $time, (int) $resident->id, (int) $resident->unit_id );
		return true;
	}

	public static function unwait( $resident, string $date, string $time ): bool {
		if ( Time::is_date( $date ) && Time::is_time( $time ) ) {
			Repository::remove_waitlist( $date, $time, (int) $resident->id );
		}
		return true;
	}

	/** A place became free (cancelled or someone left): tell the waiting list. */
	private static function slot_freed( $booking ): void {
		if ( Time::start( $booking ) > Time::now() ) {
			do_action( 'saunaplan_slot_freed', $booking->slot_date, $booking->slot_time );
		}
	}

	/* --------------------------------------------------------------- Admin */

	/** Skip one date of a recurring session (stores a cancelled row). */
	public static function skip_series_slot( int $series_id, string $date, string $time ): void {
		$slot = Schedule::slot( $date, $time );
		if ( $slot && $slot['booking'] && $slot['booking']->series_id === $series_id ) {
			self::admin_cancel( $slot['booking']->id );
			return;
		}
		if ( $slot && $slot['series'] && (int) $slot['series']->id === $series_id ) {
			$booking = Schedule::materialize( $slot );
			if ( $booking ) {
				Repository::update_booking( $booking->id, array( 'cancelled_at' => Repository::now_utc() ) );
				do_action( 'saunaplan_slot_freed', $date, $time );
			}
		}
	}

	/* ------------------------------------------------------------- Helpers */

	private static function draft( string $action, $resident, string $date, string $time ): Draft {
		$draft           = new Draft();
		$draft->action   = $action;
		$draft->date     = $date;
		$draft->time     = $time;
		$draft->resident = $resident;
		$draft->unit_id  = (int) $resident->unit_id;
		$draft->now      = Time::now();
		$draft->slot     = Time::is_date( $date ) && Time::is_time( $time ) ? Schedule::slot( $date, $time ) : null;
		return $draft;
	}

	/** @return mixed */
	private static function locked( callable $callback ) {
		global $wpdb;
		$lock = self::LOCK . '_' . $wpdb->prefix;
		if ( ! $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 10)', $lock ) ) ) {
			return new WP_Error( 'saunaplan_busy', __( 'The server is busy. Please try again.', 'saunaplan' ), array( 'status' => 503 ) );
		}
		try {
			return $callback();
		} finally {
			$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
		}
	}

	private static function db_error(): WP_Error {
		return new WP_Error( 'saunaplan_db', __( 'Your booking could not be saved. Please try again.', 'saunaplan' ), array( 'status' => 500 ) );
	}
}
