<?php
namespace Saunaplan\Core;

defined( 'ABSPATH' ) || exit;

/**
 * WP-Cron: reminders, rating requests and data cleanup.
 */
class Scheduler {

	const HOOK         = 'saunaplan_tick';
	const RECURRENCE   = 'saunaplan_15min';
	const CLEANUP_OPT  = 'saunaplan_last_cleanup';

	public static function init() {
		add_filter( 'cron_schedules', array( self::class, 'schedules' ) );
		add_action( self::HOOK, array( self::class, 'tick' ) );
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			self::schedule();
		}
	}

	public static function schedules( $schedules ) {
		$schedules[ self::RECURRENCE ] = array(
			'interval' => 15 * MINUTE_IN_SECONDS,
			'display'  => 'Every 15 minutes (Saunaplan)',
		);
		return $schedules;
	}

	public static function schedule() {
		// Also needed during activation, before init() ran.
		add_filter( 'cron_schedules', array( self::class, 'schedules' ) );
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + MINUTE_IN_SECONDS, self::RECURRENCE, self::HOOK );
		}
	}

	public static function unschedule() {
		wp_clear_scheduled_hook( self::HOOK );
	}

	public static function tick() {
		self::send_reminders();
		self::send_rating_requests();
		self::maybe_cleanup();
	}

	/** Residents (with confirmed email) of the flats in a booking. */
	private static function recipients( $booking, array $skip_units = array() ): array {
		$ids = array();
		foreach ( Repository::participants( $booking->id ) as $p ) {
			if ( ! in_array( $p->unit_id, $skip_units, true ) ) {
				$ids[] = $p->resident_id;
			}
		}
		return array_filter(
			Repository::residents_by_ids( $ids ),
			static function ( $r ) {
				return '' !== (string) $r->email;
			}
		);
	}

	/** @return int Number of mails sent. */
	public static function send_reminders( ?\DateTimeImmutable $now = null ): int {
		$hours = (int) Settings::get( 'reminder_hours' );
		if ( $hours < 1 ) {
			return 0;
		}
		$now   = $now ?: Time::now();
		$today = $now->format( 'Y-m-d' );
		$sent  = 0;
		foreach ( Repository::bookings_needing_reminder( $today, Time::add_days( $today, intdiv( $hours, 24 ) + 1 ) ) as $booking ) {
			$start = Time::start( $booking );
			if ( $now < $start->modify( '-' . $hours . ' hours' ) ) {
				continue;
			}
			if ( $now < $start ) {
				$vars = Mailer::booking_vars( $booking, 'reminder' );
				foreach ( self::recipients( $booking ) as $resident ) {
					if ( Mailer::send_template( 'reminder', $resident->email, $vars ) ) {
						$sent++;
					}
				}
			}
			Repository::update_booking( $booking->id, array( 'reminder_sent_at' => Repository::now_utc() ) );
		}
		return $sent;
	}

	/** @return int Number of mails sent. */
	public static function send_rating_requests( ?\DateTimeImmutable $now = null ): int {
		$now   = $now ?: Time::now();
		$today = $now->format( 'Y-m-d' );
		$sent  = 0;
		foreach ( Repository::bookings_needing_rating_request( Time::add_days( $today, -2 ), $today ) as $booking ) {
			$end = Time::end( $booking );
			if ( $now < $end ) {
				continue;
			}
			if ( $now < $end->modify( '+1 day' ) ) {
				$rated = array();
				foreach ( Repository::participants( $booking->id ) as $p ) {
					if ( Repository::rating_exists( $booking->id, $p->unit_id ) ) {
						$rated[] = $p->unit_id;
					}
				}
				$vars = Mailer::booking_vars( $booking, 'rating' );
				foreach ( self::recipients( $booking, $rated ) as $resident ) {
					$vars['rate_url'] = Ratings::url( $booking->id, (int) $resident->id );
					if ( Mailer::send_template( 'rating', $resident->email, $vars ) ) {
						$sent++;
					}
				}
			}
			Repository::update_booking( $booking->id, array( 'rating_request_sent_at' => Repository::now_utc() ) );
		}
		return $sent;
	}

	public static function maybe_cleanup(): void {
		$today = Time::today();
		if ( get_option( self::CLEANUP_OPT ) === $today ) {
			return;
		}
		update_option( self::CLEANUP_OPT, $today, false );
		self::cleanup();
	}

	public static function cleanup(): void {
		Repository::purge_before( Time::add_days( Time::today(), -(int) Settings::get( 'retention_days' ) ) );
		Repository::purge_sessions();
		Repository::purge_idle_residents( gmdate( 'Y-m-d H:i:s', time() - (int) Settings::get( 'session_days' ) * DAY_IN_SECONDS ) );
	}
}
