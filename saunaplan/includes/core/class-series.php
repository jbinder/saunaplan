<?php
namespace Saunaplan\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Recurring predefined bookings (e.g. "open for women, Tuesdays 18–20").
 * They are virtual until someone joins or an admin skips a date; then a
 * booking row with series_id is stored for that slot.
 */
class Series {

	/** Whether a series takes place on a date (weekly on chosen weekdays, every N weeks). */
	public static function occurs_on( $series, string $date ): bool {
		if ( ! $series->active || $date < $series->start_date || ( $series->end_date && $date > $series->end_date ) ) {
			return false;
		}
		if ( ! in_array( Time::weekday( $date ), $series->weekdays, true ) ) {
			return false;
		}
		if ( $series->interval_n <= 1 ) {
			return true;
		}
		// Weeks are counted from the week of the first occurrence, so a start
		// date after this week's weekday doesn't skip the first session.
		$utc   = new \DateTimeZone( 'UTC' );
		$first = new \DateTimeImmutable( $series->start_date, $utc );
		for ( $i = 0; $i < 7 && ! in_array( (int) $first->format( 'N' ), $series->weekdays, true ); $i++ ) {
			$first = $first->modify( '+1 day' );
		}
		$monday = $first->modify( '-' . ( (int) $first->format( 'N' ) - 1 ) . ' days' );
		$weeks  = intdiv( (int) $monday->diff( new \DateTimeImmutable( $date, $utc ) )->days, 7 );
		return 0 === $weeks % $series->interval_n;
	}

	/**
	 * Slot start times a series covers on a date.
	 *
	 * @return string[]
	 */
	public static function times( $series, int $minutes ): array {
		$times = array();
		$start = Time::minutes( $series->start_time );
		for ( $i = 0; $i < $series->slots; $i++ ) {
			$m = $start + $i * $minutes;
			if ( $m >= 1440 ) {
				break;
			}
			$times[] = Time::hhmm( $m );
		}
		return $times;
	}
}
