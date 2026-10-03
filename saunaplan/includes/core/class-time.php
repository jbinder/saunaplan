<?php
namespace Saunaplan\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Date/time helpers, always in the site timezone.
 */
class Time {

	/** @var \DateTimeImmutable|null Fixed "now" for tests. */
	public static $now = null;

	public static function tz(): \DateTimeZone {
		return wp_timezone();
	}

	public static function now(): \DateTimeImmutable {
		return self::$now ?: new \DateTimeImmutable( 'now', self::tz() );
	}

	public static function today(): string {
		return self::now()->format( 'Y-m-d' );
	}

	public static function is_date( $value ): bool {
		if ( ! is_string( $value ) || ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m ) ) {
			return false;
		}
		return checkdate( (int) $m[2], (int) $m[3], (int) $m[1] );
	}

	public static function is_time( $value ): bool {
		return is_string( $value ) && (bool) preg_match( '/^([01]\d|2[0-3]):[0-5]\d$/', $value );
	}

	/** Add days to a Y-m-d date string. */
	public static function add_days( string $date, int $days ): string {
		$d = new \DateTimeImmutable( $date, new \DateTimeZone( 'UTC' ) );
		return $d->modify( ( $days >= 0 ? '+' : '' ) . $days . ' days' )->format( 'Y-m-d' );
	}

	/** ISO weekday of a date, 1 = Monday … 7 = Sunday. */
	public static function weekday( string $date ): int {
		return (int) ( new \DateTimeImmutable( $date, new \DateTimeZone( 'UTC' ) ) )->format( 'N' );
	}

	/** "HH:MM" → minutes since midnight ("24:00" → 1440). */
	public static function minutes( string $time ): int {
		list( $h, $m ) = array_map( 'intval', explode( ':', substr( $time, 0, 5 ) ) );
		return $h * 60 + $m;
	}

	/** Minutes since midnight → "HH:MM". */
	public static function hhmm( int $minutes ): string {
		return sprintf( '%02d:%02d', intdiv( $minutes, 60 ), $minutes % 60 );
	}

	public static function at( string $date, string $time ): \DateTimeImmutable {
		return new \DateTimeImmutable( $date . ' ' . substr( $time, 0, 5 ) . ':00', self::tz() );
	}

	/** Start of a booking row. */
	public static function start( $booking ): \DateTimeImmutable {
		return self::at( $booking->slot_date, $booking->slot_time );
	}

	public static function end( $booking ): \DateTimeImmutable {
		return self::start( $booking )->modify( '+' . (int) $booking->duration_min . ' minutes' );
	}

	public static function format_date( string $date, string $format = '' ): string {
		$ts = ( new \DateTimeImmutable( $date . ' 12:00:00', self::tz() ) )->getTimestamp();
		return I18n::date( $format ?: I18n::date_format(), $ts );
	}

	/** Date with weekday, e.g. "Tuesday, October 6, 2026". */
	public static function format_long_date( string $date ): string {
		return self::format_date( $date, I18n::long_date_format() );
	}

	public static function format_time( string $date, string $time ): string {
		return I18n::date( I18n::time_format(), self::at( $date, $time )->getTimestamp() );
	}

	public static function format_range( $booking ): string {
		return self::format_time( $booking->slot_date, $booking->slot_time )
			. ' – '
			. I18n::date( I18n::time_format(), self::end( $booking )->getTimestamp() );
	}

	/** Date and time of a timestamp. */
	public static function format_datetime( int $timestamp ): string {
		return I18n::date( I18n::date_format() . ' ' . I18n::time_format(), $timestamp );
	}

	/** @return array<int,string> ISO weekday (1 = Monday) => localized name. */
	public static function weekday_names( bool $short = false ): array {
		$names = array();
		for ( $i = 1; $i <= 7; $i++ ) {
			$names[ $i ] = I18n::weekday( $i, $short );
		}
		return $names;
	}
}
