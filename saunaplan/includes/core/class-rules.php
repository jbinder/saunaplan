<?php
namespace Saunaplan\Core;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Rule engine: core rules first, then the room profile's own rules.
 */
class Rules {

	/** @return Rule[] */
	public static function all(): array {
		$rules = array(
			new Rule_Slot_Open(),
			new Rule_Mode(),
			new Rule_People(),
			new Rule_Capacity(),
			new Rule_Already_In(),
			new Rule_Monthly_Limit(),
			new Rule_Consecutive(),
			new Rule_Rating_Gate(),
		);
		return (array) apply_filters( 'saunaplan_rules', array_merge( $rules, Room::profile()->rules() ) );
	}

	/** @return WP_Error|null The first violated rule. */
	public static function check( Draft $draft ) {
		foreach ( self::all() as $rule ) {
			$error = $rule->check( $draft );
			if ( $error instanceof WP_Error ) {
				return $error;
			}
		}
		return null;
	}

	/** Mode IDs whose bookings count toward the monthly limit. */
	public static function limit_modes(): array {
		return array_keys(
			array_filter(
				Room::modes(),
				static function ( $mode ) {
					return ! empty( $mode['counts_toward_limit'] );
				}
			)
		);
	}

	public static function error( string $code, string $message, int $status = 409, array $data = array() ): WP_Error {
		return new WP_Error( 'saunaplan_' . $code, $message, array( 'status' => $status ) + $data );
	}
}

/** The slot exists, hasn't started and lies within the booking horizon. */
class Rule_Slot_Open implements Rule {
	public function check( Draft $d ) {
		if ( ! $d->slot ) {
			return Rules::error( 'slot', __( 'This time slot does not exist.', 'saunaplan' ), 404 );
		}
		if ( $d->start() <= $d->now ) {
			return Rules::error( 'past', __( 'This time slot has already started.', 'saunaplan' ) );
		}
		if ( 'book' === $d->action && $d->date > Time::add_days( $d->now->format( 'Y-m-d' ), (int) Settings::get( 'horizon_days' ) ) ) {
			/* translators: %d: number of days */
			return Rules::error( 'horizon', sprintf( __( 'You can book up to %d days in advance.', 'saunaplan' ), (int) Settings::get( 'horizon_days' ) ) );
		}
		return null;
	}
}

/** Booking: the slot is free and the mode can be chosen. Joining: the session is open to others. */
class Rule_Mode implements Rule {
	public function check( Draft $d ) {
		$mode = $d->mode_def();
		if ( 'book' === $d->action ) {
			if ( Schedule::occupancy( $d->slot ) ) {
				return Rules::error( 'taken', __( 'Sorry, someone else just booked this time slot.', 'saunaplan' ) );
			}
			if ( ! $mode || ! empty( $mode['internal'] ) ) {
				return Rules::error( 'mode', __( 'Please choose how you want to book.', 'saunaplan' ), 400 );
			}
			return null;
		}
		if ( ! $mode || empty( $mode['joinable'] ) ) {
			return Rules::error( 'private', __( 'This session is private. You can join the waiting list instead.', 'saunaplan' ) );
		}
		return null;
	}
}

/** A number of people where needed, only in categories the mode allows. */
class Rule_People implements Rule {
	public function check( Draft $d ) {
		$mode = $d->mode_def();
		if ( ! $mode ) {
			return null;
		}
		if ( ( 'join' === $d->action || ! empty( $mode['requires_people'] ) ) && $d->total() < 1 ) {
			return Rules::error( 'people', __( 'Please tell us how many people you will be.', 'saunaplan' ), 400 );
		}
		$allowed = (array) $mode['categories'];
		foreach ( array_keys( $d->counts ) as $category ) {
			if ( ! in_array( $category, $allowed, true ) ) {
				$labels = array();
				foreach ( Room::profile()->categories() as $key => $cat ) {
					if ( in_array( $key, $allowed, true ) ) {
						$labels[] = $cat['label'];
					}
				}
				/* translators: %s: allowed people categories, e.g. "Women" */
				return Rules::error( 'category', sprintf( __( 'This session is only for: %s.', 'saunaplan' ), implode( ', ', $labels ) ), 400 );
			}
		}
		return null;
	}
}

class Rule_Capacity implements Rule {
	public function check( Draft $d ) {
		$capacity = (int) Settings::get( 'capacity' );
		$free     = $capacity - ( 'join' === $d->action ? $d->current_total() : 0 );
		if ( $d->total() > $free ) {
			if ( $free <= 0 ) {
				return Rules::error( 'full', __( 'Sorry, this session is full. You can join the waiting list.', 'saunaplan' ) );
			}
			/* translators: %d: free places */
			return Rules::error( 'capacity', sprintf( _n( 'Only %d place is left.', 'Only %d places are left.', $free, 'saunaplan' ), $free ) );
		}
		return null;
	}
}

/** One flat takes part in a session only once (change the booking instead). */
class Rule_Already_In implements Rule {
	public function check( Draft $d ) {
		if ( 'join' !== $d->action ) {
			return null;
		}
		foreach ( $d->slot['participants'] as $p ) {
			if ( $p->unit_id === $d->unit_id ) {
				return Rules::error( 'already_in', __( 'Your flat is already part of this session.', 'saunaplan' ) );
			}
		}
		if ( $d->slot['booking'] && $d->slot['booking']->owner_id && $d->slot['booking']->unit_id === $d->unit_id ) {
			return Rules::error( 'already_in', __( 'Your flat is already part of this session.', 'saunaplan' ) );
		}
		return null;
	}
}

class Rule_Monthly_Limit implements Rule {
	public function check( Draft $d ) {
		$limit = (int) Settings::get( 'monthly_limit' );
		$mode  = $d->mode_def();
		if ( 'book' !== $d->action || $limit < 1 || ! $mode || empty( $mode['counts_toward_limit'] ) ) {
			return null;
		}
		$month = substr( $d->date, 0, 7 );
		if ( Repository::count_unit_bookings( $d->unit_id, $month, Rules::limit_modes() ) >= $limit ) {
			return Rules::error(
				'limit',
				sprintf(
					/* translators: 1: limit, 2: mode name, 3: month name */
					_n( 'Your flat has already used its %1$d booking "%2$s" in %3$s. Open sessions are still possible.', 'Your flat has already used its %1$d bookings "%2$s" in %3$s. Open sessions are still possible.', $limit, 'saunaplan' ),
					$limit,
					$mode['label'],
					I18n::month( (int) substr( $month, 5, 2 ) )
				)
			);
		}
		return null;
	}
}

/** Not more than N directly adjacent slots per flat. */
class Rule_Consecutive implements Rule {
	public function check( Draft $d ) {
		$max = (int) Settings::get( 'max_consecutive' );
		if ( $max < 1 ) {
			return null;
		}
		$taken = array();
		foreach ( Repository::unit_bookings_on( $d->unit_id, $d->date ) as $booking ) {
			$taken[] = $booking->slot_time;
		}
		if ( Slots::run_length( $taken, $d->time, (int) $d->slot['duration'] ) > $max ) {
			/* translators: %d: maximum number of slots in a row */
			return Rules::error( 'consecutive', sprintf( _n( 'To keep it fair, a flat can book at most %d slot in a row.', 'To keep it fair, a flat can book at most %d slots in a row.', $max, 'saunaplan' ), $max ) );
		}
		return null;
	}
}

/** The flat's last finished session must be rated before booking again. */
class Rule_Rating_Gate implements Rule {
	public function check( Draft $d ) {
		if ( ! Settings::get( 'rating_gate' ) ) {
			return null;
		}
		$pending = Ratings::pending_for_unit( $d->unit_id, $d->now );
		if ( $pending ) {
			return Rules::error(
				'rating_required',
				__( 'Please quickly rate your last session first.', 'saunaplan' ),
				409,
				array( 'booking_id' => $pending->id )
			);
		}
		return null;
	}
}
