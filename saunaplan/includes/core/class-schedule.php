<?php
namespace Saunaplan\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Combines opening hours, bookings and recurring series into slots, and
 * builds the (anonymous) view residents get.
 */
class Schedule {

	/**
	 * All slots in a date range.
	 *
	 * @return array<string,array<string,array>> date => time => slot
	 *         (date, time, duration, booking|null, series|null, participants, waitlist).
	 */
	public static function slots( string $from, string $to ): array {
		$minutes = (int) Settings::get( 'slot_minutes' );
		$series  = Repository::all_series( true );
		$rows    = Repository::bookings_between( $from, $to );

		$active  = array();
		$skipped = array();
		foreach ( $rows as $row ) {
			if ( null === $row->cancelled_at ) {
				$active[ $row->slot_date ][ $row->slot_time ] = $row;
			}
			if ( $row->series_id ) {
				// Any stored row (joined or skipped) replaces the virtual series entry.
				$skipped[ $row->series_id ][ $row->slot_date ][ $row->slot_time ] = true;
			}
		}

		$participants = Repository::participants_for( array_map( static function ( $b ) {
			return $b->id;
		}, array_merge( array(), ...array_map( 'array_values', array_values( $active ) ) ) ) );

		$waitlist = array();
		foreach ( Repository::waitlist_between( $from, $to ) as $w ) {
			$waitlist[ $w->slot_date ][ substr( $w->slot_time, 0, 5 ) ][] = $w;
		}

		$out = array();
		for ( $date = $from; $date <= $to; $date = Time::add_days( $date, 1 ) ) {
			$day = array();
			foreach ( Slots::for_date( $date ) as $time ) {
				$day[ $time ] = null;
			}
			$virtual = array();
			foreach ( $series as $s ) {
				if ( ! Series::occurs_on( $s, $date ) ) {
					continue;
				}
				foreach ( Series::times( $s, $minutes ) as $time ) {
					if ( empty( $skipped[ $s->id ][ $date ][ $time ] ) && ! isset( $virtual[ $time ] ) ) {
						$virtual[ $time ] = $s;
						$day[ $time ]     = null;
					}
				}
			}
			foreach ( array_keys( $active[ $date ] ?? array() ) as $time ) {
				$day[ $time ] = null;
			}
			ksort( $day );

			foreach ( array_keys( $day ) as $time ) {
				$time    = (string) $time;
				$booking = $active[ $date ][ $time ] ?? null;
				$day[ $time ] = array(
					'date'         => $date,
					'time'         => $time,
					'duration'     => $booking ? $booking->duration_min : $minutes,
					'booking'      => $booking,
					'series'       => $booking ? null : ( $virtual[ $time ] ?? null ),
					'participants' => $booking ? ( $participants[ $booking->id ] ?? array() ) : array(),
					'waitlist'     => $waitlist[ $date ][ $time ] ?? array(),
				);
			}
			if ( $day ) {
				$out[ $date ] = $day;
			}
		}
		return $out;
	}

	/** One slot, or null if there is no such slot. */
	public static function slot( string $date, string $time ): ?array {
		$day = self::slots( $date, $date )[ $date ] ?? array();
		return $day[ $time ] ?? null;
	}

	/** Whether a slot is part of the regular grid (opening hours). */
	public static function in_grid( string $date, string $time ): bool {
		return in_array( $time, Slots::for_date( $date ), true );
	}

	/** Mode, title and attributes of a slot's booking or series (null when free). */
	public static function occupancy( array $slot ): ?array {
		if ( $slot['booking'] ) {
			return array(
				'mode'       => $slot['booking']->mode,
				'title'      => $slot['booking']->title,
				'attributes' => $slot['booking']->attributes,
			);
		}
		if ( $slot['series'] ) {
			return array(
				'mode'       => $slot['series']->mode,
				'title'      => $slot['series']->title,
				'attributes' => $slot['series']->attributes,
			);
		}
		return null;
	}

	/**
	 * Store a virtual series slot as a booking row, so people can join it.
	 *
	 * @return object|null The booking.
	 */
	public static function materialize( array $slot ) {
		if ( $slot['booking'] ) {
			return $slot['booking'];
		}
		if ( ! $slot['series'] ) {
			return null;
		}
		$id = Repository::insert_booking(
			array(
				'slot_date'    => $slot['date'],
				'slot_time'    => $slot['time'],
				'duration_min' => $slot['duration'],
				'mode'         => $slot['series']->mode,
				'attributes'   => $slot['series']->attributes,
				'title'        => $slot['series']->title,
				'series_id'    => $slot['series']->id,
			)
		);
		return $id ? Repository::booking( $id ) : null;
	}

	/** @return array<string,int> */
	public static function sum_counts( array $participants ): array {
		$sum = array();
		foreach ( $participants as $p ) {
			foreach ( $p->counts as $key => $n ) {
				$sum[ $key ] = ( $sum[ $key ] ?? 0 ) + (int) $n;
			}
		}
		return $sum;
	}

	public static function total( array $participants ): int {
		return array_sum( array_map( static function ( $p ) {
			return $p->total;
		}, $participants ) );
	}

	/* ----------------------------------------------------------- Public view */

	/**
	 * Anonymous view of a slot for one resident: no flats, no names, no other
	 * people's details – only mode, head counts and waiting list length.
	 */
	public static function view_slot( array $slot, $resident, \DateTimeImmutable $now ): array {
		$unit_id  = (int) $resident->unit_id;
		$start    = Time::at( $slot['date'], $slot['time'] );
		$end      = $start->modify( '+' . (int) $slot['duration'] . ' minutes' );
		$occ      = self::occupancy( $slot );
		$booking  = $slot['booking'];
		$mode     = $occ ? Room::mode( $occ['mode'] ) : null;
		$counts   = self::sum_counts( $slot['participants'] );
		$total    = self::total( $slot['participants'] );
		$capacity = (int) Settings::get( 'capacity' );
		$horizon  = Time::add_days( Time::today(), (int) Settings::get( 'horizon_days' ) );

		$mine   = $booking && $booking->owner_id && $booking->unit_id === $unit_id;
		$joined = false;
		foreach ( $slot['participants'] as $p ) {
			if ( $p->unit_id === $unit_id && ! $mine ) {
				$joined = true;
			}
		}
		$waiting = false;
		foreach ( $slot['waitlist'] as $w ) {
			if ( (int) $w->resident_id === (int) $resident->id ) {
				$waiting = true;
			}
		}

		$state = 'free';
		if ( $occ ) {
			$state = Room::BLOCKED === $occ['mode'] ? 'blocked' : 'booked';
		}
		$started = $start <= $now;

		$actions = array();
		if ( ! $started ) {
			if ( 'free' === $state && $slot['date'] <= $horizon ) {
				$actions[] = 'book';
			} elseif ( 'booked' === $state && ! $mine && ! $joined ) {
				if ( $mode && $mode['joinable'] && $total < $capacity ) {
					$actions[] = 'join';
				}
				// The waiting list is for sessions you can't join (private or full).
				if ( $waiting ) {
					$actions[] = 'unwait';
				} elseif ( ! in_array( 'join', $actions, true ) ) {
					$actions[] = 'wait';
				}
			}
			if ( $mine ) {
				$actions[] = 'cancel';
			}
			if ( $joined ) {
				$actions[] = 'leave';
			}
		}

		$public = array(
			'mode'       => $occ['mode'] ?? '',
			'title'      => $occ['title'] ?? '',
			'attributes' => $occ['attributes'] ?? array(),
		);

		$view = array(
			'id'         => $booking ? $booking->id : null,
			'date'       => $slot['date'],
			'time'       => $slot['time'],
			'end'        => $end->format( 'H:i' ),
			'state'      => $state,
			'started'    => $started,
			'series'     => (bool) ( $slot['series'] || ( $booking && $booking->series_id ) ),
			'title'      => $public['title'],
			'mode'       => $public['mode'],
			'attributes' => (object) $public['attributes'],
			'badges'     => $occ ? Room::profile()->badges( $public ) : array(),
			'counts'     => (object) $counts,
			'total'      => $total,
			'capacity'   => $capacity,
			'waiting'    => count( $slot['waitlist'] ),
			'mine'       => $mine,
			'joined'     => $joined,
			'onWaitlist' => $waiting,
			'actions'    => $actions,
			'notes'      => array(),
		);
		if ( ( $mine || $joined ) && $booking ) {
			$view['notes'] = Room::profile()->notes_for_participants( $booking );
			foreach ( $slot['participants'] as $p ) {
				if ( $p->unit_id === $unit_id ) {
					$view['myCounts'] = (object) $p->counts;
				}
			}
		}
		return $view;
	}

	/**
	 * Everything the page shows for a resident: days with slots, own bookings,
	 * monthly usage.
	 */
	public static function view( $resident ): array {
		$now     = Time::now();
		$from    = $now->format( 'Y-m-d' );
		$to      = Time::add_days( $from, (int) Settings::get( 'horizon_days' ) );
		$days    = array();
		$mine    = array();
		$waiting = array();

		foreach ( self::slots( $from, $to ) as $date => $slots ) {
			$items = array();
			foreach ( $slots as $slot ) {
				$end = Time::at( $slot['date'], $slot['time'] )->modify( '+' . (int) $slot['duration'] . ' minutes' );
				if ( $end <= $now ) {
					continue;
				}
				$view    = self::view_slot( $slot, $resident, $now );
				$items[] = $view;
				if ( $view['mine'] || $view['joined'] ) {
					$mine[] = $view;
				} elseif ( $view['onWaitlist'] ) {
					$waiting[] = $view;
				}
			}
			if ( $items ) {
				$days[] = array(
					'date'  => $date,
					'label' => Time::format_long_date( $date ),
					'slots' => $items,
				);
			}
		}

		// Bookings beyond the horizon (e.g. after the horizon was shortened).
		foreach ( Repository::unit_bookings_from( (int) $resident->unit_id, Time::add_days( $to, 1 ) ) as $booking ) {
			$slot = self::slot( $booking->slot_date, $booking->slot_time );
			if ( $slot ) {
				$mine[] = self::view_slot( $slot, $resident, $now );
			}
		}

		return array(
			'days'    => $days,
			'mine'    => $mine,
			'waiting' => $waiting,
			'usage'   => self::usage( (int) $resident->unit_id, $from, $to ),
		);
	}

	/**
	 * Limit-counting bookings per month for the months in the range.
	 *
	 * @return array<int,array{month:string,label:string,used:int,limit:int}>
	 */
	public static function usage( int $unit_id, string $from, string $to ): array {
		$limit = (int) Settings::get( 'monthly_limit' );
		if ( $limit < 1 ) {
			return array();
		}
		$modes = Rules::limit_modes();
		$out   = array();
		foreach ( array_unique( array( substr( $from, 0, 7 ), substr( $to, 0, 7 ) ) ) as $month ) {
			$out[] = array(
				'month' => $month,
				'label' => I18n::month( (int) substr( $month, 5, 2 ) ),
				'used'  => Repository::count_unit_bookings( $unit_id, $month, $modes ),
				'limit' => $limit,
			);
		}
		return $out;
	}
}
