<?php
namespace Saunaplan\Core;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Quick ratings after a session (scales 1–3, issues, optional comment).
 */
class Ratings {

	/** Sessions older than this are not asked for anymore. */
	const ASK_DAYS = 14;

	/** The flat's last finished, unrated session (null = nothing to rate). */
	public static function pending_for_unit( int $unit_id, ?\DateTimeImmutable $now = null ) {
		$now = $now ?: Time::now();
		return Repository::unrated_booking( $unit_id, Time::add_days( $now->format( 'Y-m-d' ), -self::ASK_DAYS ), $now );
	}

	/**
	 * Store a rating of a flat for a booking.
	 *
	 * @param array $input scales (key => 1..3), issues (keys), comment, quick (bool: "all fine").
	 * @return true|WP_Error
	 */
	public static function save( $booking, int $unit_id, int $resident_id, array $input ) {
		if ( ! $booking || null !== $booking->cancelled_at || Time::end( $booking ) > Time::now() ) {
			return new WP_Error( 'saunaplan_rating', __( 'This session can not be rated (yet).', 'saunaplan' ), array( 'status' => 400 ) );
		}
		$in_booking = false;
		foreach ( Repository::participants( $booking->id ) as $p ) {
			if ( $p->unit_id === $unit_id ) {
				$in_booking = true;
			}
		}
		if ( ! $in_booking ) {
			return new WP_Error( 'saunaplan_rating', __( 'Your flat was not part of this session.', 'saunaplan' ), array( 'status' => 403 ) );
		}
		if ( Repository::rating_exists( $booking->id, $unit_id ) ) {
			return true; // Someone else of the flat was quicker – fine.
		}

		$questions = Room::profile()->rating_questions();
		$quick     = ! empty( $input['quick'] );
		$answers   = array();
		foreach ( array_keys( $questions['scales'] ?? array() ) as $key ) {
			$value = $quick ? 3 : absint( $input['scales'][ $key ] ?? 0 );
			if ( $value >= 1 && $value <= 3 ) {
				$answers[ $key ] = $value;
			}
		}
		$issues = array();
		if ( ! $quick ) {
			foreach ( (array) ( $input['issues'] ?? array() ) as $issue ) {
				if ( isset( $questions['issues'][ $issue ] ) ) {
					$issues[] = $issue;
				}
			}
		}
		$comment = $quick ? '' : mb_substr( sanitize_textarea_field( (string) ( $input['comment'] ?? '' ) ), 0, 1000 );

		if ( ! $quick && ! $answers && ! $issues && '' === $comment ) {
			return new WP_Error( 'saunaplan_rating', __( 'Please tap at least one answer, or "Everything was fine".', 'saunaplan' ), array( 'status' => 400 ) );
		}

		$id = Repository::insert_rating(
			array(
				'booking_id'  => $booking->id,
				'unit_id'     => $unit_id,
				'resident_id' => $resident_id,
				'slot_date'   => $booking->slot_date,
				'answers'     => $answers,
				'issues'      => array_unique( $issues ),
				'comment'     => $comment,
				'quick'       => $quick ? 1 : 0,
			)
		);
		if ( ! $id ) {
			return new WP_Error( 'saunaplan_db', __( 'Something went wrong. Please try again.', 'saunaplan' ), array( 'status' => 500 ) );
		}
		do_action( 'saunaplan_rated', $booking, $unit_id, $answers, $issues, $comment );
		return true;
	}

	/** Key for rating links in emails (no login needed for that one rating). */
	public static function key( int $booking_id, int $resident_id ): string {
		return substr( hash_hmac( 'sha256', 'rate|' . $booking_id . '|' . $resident_id, wp_salt( 'auth' ) ), 0, 32 );
	}

	public static function verify_key( int $booking_id, int $resident_id, string $key ): bool {
		return hash_equals( self::key( $booking_id, $resident_id ), $key );
	}

	public static function url( int $booking_id, int $resident_id ): string {
		return add_query_arg(
			array(
				'rate' => $booking_id,
				'r'    => $resident_id,
				'key'  => self::key( $booking_id, $resident_id ),
			),
			Settings::page_url()
		);
	}

	/**
	 * Averages and issue counts for the admin overview.
	 *
	 * @return array{count:int,scales:array<string,float>,issues:array<string,int>}
	 */
	public static function summary( array $ratings ): array {
		$sums   = array();
		$counts = array();
		$issues = array();
		foreach ( $ratings as $rating ) {
			foreach ( $rating->answers as $key => $value ) {
				$sums[ $key ]   = ( $sums[ $key ] ?? 0 ) + (int) $value;
				$counts[ $key ] = ( $counts[ $key ] ?? 0 ) + 1;
			}
			foreach ( $rating->issues as $issue ) {
				$issues[ $issue ] = ( $issues[ $issue ] ?? 0 ) + 1;
			}
		}
		$scales = array();
		foreach ( $sums as $key => $sum ) {
			$scales[ $key ] = round( $sum / $counts[ $key ], 1 );
		}
		arsort( $issues );
		return array( 'count' => count( $ratings ), 'scales' => $scales, 'issues' => $issues );
	}
}
