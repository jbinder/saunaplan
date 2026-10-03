<?php
namespace Saunaplan\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Sends the mails that belong to booking events. Only residents who
 * confirmed an email address get mail; everything is also visible on the page.
 */
class Notifications {

	public static function init() {
		add_action( 'saunaplan_booked', array( self::class, 'confirmation' ), 10, 2 );
		add_action( 'saunaplan_joined', array( self::class, 'confirmation' ), 10, 2 );
		add_action( 'saunaplan_cancelled', array( self::class, 'cancelled' ), 10, 3 );
		add_action( 'saunaplan_slot_freed', array( self::class, 'waitlist' ), 10, 2 );
		add_action( 'saunaplan_rated', array( self::class, 'issue' ), 10, 5 );
	}

	public static function confirmation( $booking, $resident ) {
		if ( ! empty( $resident->email ) ) {
			Mailer::send_template( 'confirmation', $resident->email, Mailer::booking_vars( $booking, 'confirmation' ) );
		}
	}

	/** Tell the other people in a cancelled session. */
	public static function cancelled( $booking, array $participants, $by ) {
		$skip_unit = $by ? (int) $by->unit_id : 0;
		$ids       = array();
		foreach ( $participants as $p ) {
			if ( $p->unit_id !== $skip_unit ) {
				$ids[] = $p->resident_id;
			}
		}
		$vars = Mailer::booking_vars( $booking, 'cancelled' );
		foreach ( Repository::residents_by_ids( $ids ) as $resident ) {
			if ( $resident->email ) {
				Mailer::send_template( 'cancelled', $resident->email, $vars );
			}
		}
	}

	/** Everyone on the waiting list at once; first come, first served. */
	public static function waitlist( string $date, string $time ) {
		$entries = Repository::waitlist_at( $date, $time );
		if ( ! $entries ) {
			return;
		}
		$slot    = Schedule::slot( $date, $time );
		$booking = (object) array(
			'slot_date'    => $date,
			'slot_time'    => $time,
			'duration_min' => $slot ? (int) $slot['duration'] : (int) Settings::get( 'slot_minutes' ),
		);
		$vars      = array(
			'date' => Time::format_long_date( $date ),
			'time' => Time::format_range( $booking ),
		);
		$residents = Repository::residents_by_ids( wp_list_pluck( $entries, 'resident_id' ) );
		foreach ( $residents as $resident ) {
			if ( $resident->email ) {
				Mailer::send_template( 'waitlist', $resident->email, $vars );
			}
		}
		Repository::mark_waitlist_notified( wp_list_pluck( $entries, 'id' ) );
	}

	/** Ratings with issues or a comment go to the administrators. */
	public static function issue( $booking, int $unit_id, array $answers, array $issues, string $comment ) {
		$recipients = Settings::admin_recipients();
		if ( ! $recipients || ( ! $issues && '' === $comment ) ) {
			return;
		}
		Mailer::send_template( 'issue', $recipients, self::issue_vars( $booking, $unit_id, $answers, $issues, $comment ) );
	}

	public static function issue_vars( $booking, int $unit_id, array $answers, array $issues, string $comment ): array {
		$questions = Room::profile()->rating_questions();
		$faces     = array( 1 => '☹', 2 => '😐', 3 => '☺' );
		$lines     = array();
		foreach ( $answers as $key => $value ) {
			$lines[] = '- ' . ( $questions['scales'][ $key ] ?? $key ) . ': ' . ( $faces[ $value ] ?? $value ) . ' (' . $value . '/3)';
		}
		foreach ( $issues as $issue ) {
			$lines[] = '- ⚠ ' . ( $questions['issues'][ $issue ] ?? $issue );
		}
		return array(
			'date'      => Time::format_long_date( $booking->slot_date ),
			'time'      => Time::format_range( $booking ),
			'unit'      => Units::short_label( Repository::unit( $unit_id ) ),
			'answers'   => implode( "\n", $lines ),
			'comment'   => '' !== $comment ? $comment : '–',
			'admin_url' => admin_url( 'admin.php?page=saunaplan-ratings' ),
		);
	}

	/* ---------------------------------------------------- Email confirmation */

	public static function email_key( $resident ): string {
		return substr( hash_hmac( 'sha256', 'email|' . $resident->id . '|' . strtolower( $resident->email_pending ), wp_salt( 'auth' ) ), 0, 32 );
	}

	public static function request_email_confirmation( $resident ): bool {
		$url = add_query_arg(
			array(
				'saunaplan_email' => (int) $resident->id,
				'key'             => self::email_key( $resident ),
			),
			home_url( '/' )
		);
		return Mailer::send_template( 'email_confirm', $resident->email_pending, array( 'confirm_url' => $url ) );
	}
}
