<?php
namespace Saunaplan\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Bookable slots of a day, from the opening hours and the slot length.
 */
class Slots {

	/**
	 * Slot start times of a date with the current settings.
	 *
	 * @return string[] "HH:MM"
	 */
	public static function for_date( string $date ): array {
		return self::compute( $date, (array) Settings::get( 'hours' ), (int) Settings::get( 'slot_minutes' ), Settings::closed_dates() );
	}

	/**
	 * @param array    $hours   ISO weekday => [from, to] ("HH:MM", to may be "24:00"; empty = closed).
	 * @param string[] $closed  Closed dates.
	 * @return string[] "HH:MM"
	 */
	public static function compute( string $date, array $hours, int $minutes, array $closed = array() ): array {
		if ( in_array( $date, $closed, true ) || $minutes < 1 ) {
			return array();
		}
		$day  = $hours[ Time::weekday( $date ) ] ?? array();
		$from = (string) ( $day['from'] ?? '' );
		$to   = (string) ( $day['to'] ?? '' );
		if ( '' === $from || '' === $to ) {
			return array();
		}
		$slots = array();
		$end   = Time::minutes( $to );
		for ( $m = Time::minutes( $from ); $m + $minutes <= $end; $m += $minutes ) {
			$slots[] = Time::hhmm( $m );
		}
		return $slots;
	}

	/**
	 * Longest run of directly adjacent slots containing $time.
	 *
	 * @param string[] $taken Start times already used on that day.
	 */
	public static function run_length( array $taken, string $time, int $minutes ): int {
		$set = array_flip( array_map( array( Time::class, 'minutes' ), $taken ) );
		$t   = Time::minutes( $time );
		$set[ $t ] = true;
		$run = 1;
		for ( $m = $t - $minutes; isset( $set[ $m ] ); $m -= $minutes ) {
			$run++;
		}
		for ( $m = $t + $minutes; isset( $set[ $m ] ); $m += $minutes ) {
			$run++;
		}
		return $run;
	}
}
