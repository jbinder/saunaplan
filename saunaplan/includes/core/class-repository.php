<?php
namespace Saunaplan\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Database access. All times in UTC (current_time( 'mysql', true )), slot
 * dates/times in the site timezone.
 */
class Repository {

	public static function table( string $name ): string {
		global $wpdb;
		return $wpdb->prefix . 'saunaplan_' . $name;
	}

	public static function now_utc(): string {
		return current_time( 'mysql', true );
	}

	/* ---------------------------------------------------------------- Units */

	public static function units( bool $active_only = false ): array {
		global $wpdb;
		$where = $active_only ? ' WHERE active = 1' : '';
		$rows  = $wpdb->get_results( 'SELECT * FROM ' . self::table( 'units' ) . $where );
		$units = array();
		foreach ( $rows as $row ) {
			$units[ (int) $row->id ] = $row;
		}
		uasort( $units, array( Units::class, 'compare' ) );
		return $units;
	}

	public static function unit( int $id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table( 'units' ) . ' WHERE id = %d', $id ) );
	}

	public static function unit_by_code_hash( string $hash ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table( 'units' ) . ' WHERE code_hash = %s AND active = 1', $hash ) );
	}

	public static function find_unit( string $stair, string $door ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table( 'units' ) . ' WHERE stair = %s AND door = %s', $stair, $door ) );
	}

	public static function insert_unit( string $stair, string $door ): int {
		global $wpdb;
		$wpdb->insert(
			self::table( 'units' ),
			array(
				'stair'      => $stair,
				'door'       => $door,
				'active'     => 1,
				'created_at' => self::now_utc(),
			)
		);
		return (int) $wpdb->insert_id;
	}

	public static function update_unit( int $id, array $data ): void {
		global $wpdb;
		$wpdb->update( self::table( 'units' ), $data, array( 'id' => $id ) );
	}

	/** Deletes a unit with its residents and sessions. Bookings stay (anonymous) until retention removes them. */
	public static function delete_unit( int $id ): void {
		global $wpdb;
		foreach ( self::residents_of_unit( $id ) as $resident ) {
			self::delete_resident( (int) $resident->id );
		}
		$wpdb->delete( self::table( 'units' ), array( 'id' => $id ) );
	}

	/* ------------------------------------------------------------ Residents */

	public static function resident( int $id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table( 'residents' ) . ' WHERE id = %d', $id ) );
	}

	public static function resident_by_external( string $provider, string $external_id ) {
		global $wpdb;
		return $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM ' . self::table( 'residents' ) . ' WHERE provider = %s AND external_id = %s', $provider, $external_id )
		);
	}

	public static function residents_of_unit( int $unit_id ): array {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::table( 'residents' ) . ' WHERE unit_id = %d', $unit_id ) );
	}

	public static function residents_by_email( string $email ): array {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::table( 'residents' ) . ' WHERE email = %s', strtolower( $email ) ) );
	}

	/** @return array<int,object> */
	public static function residents_by_ids( array $ids ): array {
		global $wpdb;
		$ids = array_filter( array_map( 'intval', $ids ) );
		if ( ! $ids ) {
			return array();
		}
		$out = array();
		foreach ( $wpdb->get_results( 'SELECT * FROM ' . self::table( 'residents' ) . ' WHERE id IN (' . implode( ',', $ids ) . ')' ) as $row ) {
			$out[ (int) $row->id ] = $row;
		}
		return $out;
	}

	public static function insert_resident( int $unit_id, string $provider, string $external_id ): int {
		global $wpdb;
		$now = self::now_utc();
		$wpdb->insert(
			self::table( 'residents' ),
			array(
				'unit_id'      => $unit_id,
				'provider'     => $provider,
				'external_id'  => $external_id,
				'created_at'   => $now,
				'last_seen_at' => $now,
			)
		);
		return (int) $wpdb->insert_id;
	}

	public static function update_resident( int $id, array $data ): void {
		global $wpdb;
		$wpdb->update( self::table( 'residents' ), $data, array( 'id' => $id ) );
	}

	public static function delete_resident( int $id ): void {
		global $wpdb;
		$wpdb->delete( self::table( 'sessions' ), array( 'resident_id' => $id ) );
		$wpdb->delete( self::table( 'waitlist' ), array( 'resident_id' => $id ) );
		$wpdb->delete( self::table( 'residents' ), array( 'id' => $id ) );
	}

	/** Count of residents with an active session, per unit. @return array<int,int> */
	public static function signed_in_counts(): array {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT r.unit_id, COUNT(DISTINCT r.id) AS n FROM ' . self::table( 'residents' ) . ' r INNER JOIN ' . self::table( 'sessions' ) . ' s ON s.resident_id = r.id WHERE s.expires_at > %s GROUP BY r.unit_id',
				self::now_utc()
			)
		);
		$out = array();
		foreach ( $rows as $row ) {
			$out[ (int) $row->unit_id ] = (int) $row->n;
		}
		return $out;
	}

	/* ------------------------------------------------------------- Sessions */

	public static function insert_session( int $resident_id, string $token_hash, string $expires_at ): void {
		global $wpdb;
		$now = self::now_utc();
		$wpdb->insert(
			self::table( 'sessions' ),
			array(
				'resident_id'  => $resident_id,
				'token_hash'   => $token_hash,
				'created_at'   => $now,
				'last_seen_at' => $now,
				'expires_at'   => $expires_at,
			)
		);
	}

	public static function session_by_hash( string $hash ) {
		global $wpdb;
		return $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM ' . self::table( 'sessions' ) . ' WHERE token_hash = %s AND expires_at > %s', $hash, self::now_utc() )
		);
	}

	public static function touch_session( int $id, string $expires_at ): void {
		global $wpdb;
		$wpdb->update( self::table( 'sessions' ), array( 'last_seen_at' => self::now_utc(), 'expires_at' => $expires_at ), array( 'id' => $id ) );
	}

	public static function delete_session_by_hash( string $hash ): void {
		global $wpdb;
		$wpdb->delete( self::table( 'sessions' ), array( 'token_hash' => $hash ) );
	}

	public static function delete_sessions_of_unit( int $unit_id ): int {
		global $wpdb;
		return (int) $wpdb->query(
			$wpdb->prepare(
				'DELETE s FROM ' . self::table( 'sessions' ) . ' s INNER JOIN ' . self::table( 'residents' ) . ' r ON r.id = s.resident_id WHERE r.unit_id = %d',
				$unit_id
			)
		);
	}

	/* ------------------------------------------------------------- Bookings */

	public static function normalize_booking( $row ) {
		if ( ! $row ) {
			return null;
		}
		$row->id           = (int) $row->id;
		$row->slot_time    = substr( (string) $row->slot_time, 0, 5 );
		$row->duration_min = (int) $row->duration_min;
		$row->owner_id     = (int) $row->owner_id;
		$row->unit_id      = (int) $row->unit_id;
		$row->series_id    = (int) $row->series_id;
		$attributes        = json_decode( (string) $row->attributes, true );
		$row->attributes   = is_array( $attributes ) ? $attributes : array();
		return $row;
	}

	public static function booking( int $id ) {
		global $wpdb;
		return self::normalize_booking( $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table( 'bookings' ) . ' WHERE id = %d', $id ) ) );
	}

	/** The active (not cancelled) booking of a slot. */
	public static function active_booking_at( string $date, string $time ) {
		global $wpdb;
		return self::normalize_booking(
			$wpdb->get_row(
				$wpdb->prepare(
					'SELECT * FROM ' . self::table( 'bookings' ) . ' WHERE slot_date = %s AND slot_time = %s AND cancelled_at IS NULL ORDER BY id DESC LIMIT 1',
					$date,
					$time . ':00'
				)
			)
		);
	}

	/** All booking rows in a date range, including cancelled ones (they mark skipped series dates). */
	public static function bookings_between( string $from, string $to, bool $with_cancelled = true ): array {
		global $wpdb;
		$sql = 'SELECT * FROM ' . self::table( 'bookings' ) . ' WHERE slot_date BETWEEN %s AND %s';
		if ( ! $with_cancelled ) {
			$sql .= ' AND cancelled_at IS NULL';
		}
		return array_map( array( self::class, 'normalize_booking' ), $wpdb->get_results( $wpdb->prepare( $sql . ' ORDER BY slot_date, slot_time, id', $from, $to ) ) );
	}

	public static function insert_booking( array $data ): int {
		global $wpdb;
		$data['attributes'] = wp_json_encode( (object) ( $data['attributes'] ?? array() ) );
		$data['slot_time']  = substr( $data['slot_time'], 0, 5 ) . ':00';
		$data['created_at'] = self::now_utc();
		$ok                 = $wpdb->insert( self::table( 'bookings' ), $data );
		return $ok ? (int) $wpdb->insert_id : 0;
	}

	public static function update_booking( int $id, array $data ): void {
		global $wpdb;
		if ( isset( $data['attributes'] ) ) {
			$data['attributes'] = wp_json_encode( (object) $data['attributes'] );
		}
		$wpdb->update( self::table( 'bookings' ), $data, array( 'id' => $id ) );
	}

	/** Bookings of a unit in a month whose mode counts toward the monthly limit. */
	public static function count_unit_bookings( int $unit_id, string $month, array $modes ): int {
		global $wpdb;
		if ( ! $modes ) {
			return 0;
		}
		$in = implode( ',', array_map( static function ( $m ) use ( $wpdb ) {
			return $wpdb->prepare( '%s', $m );
		}, $modes ) );
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM ' . self::table( 'bookings' ) . " WHERE unit_id = %d AND cancelled_at IS NULL AND mode IN ({$in}) AND slot_date BETWEEN %s AND %s",
				$unit_id,
				$month . '-01',
				$month . '-31'
			)
		);
	}

	/** Active bookings a unit takes part in on one date (as owner or participant). */
	public static function unit_bookings_on( int $unit_id, string $date ): array {
		global $wpdb;
		$b = self::table( 'bookings' );
		$p = self::table( 'participants' );
		return array_map(
			array( self::class, 'normalize_booking' ),
			$wpdb->get_results(
				$wpdb->prepare(
					"SELECT DISTINCT b.* FROM {$b} b LEFT JOIN {$p} p ON p.booking_id = b.id
					 WHERE b.slot_date = %s AND b.cancelled_at IS NULL AND ( b.unit_id = %d OR p.unit_id = %d )",
					$date,
					$unit_id,
					$unit_id
				)
			)
		);
	}

	/** Active bookings a unit takes part in from a date on. */
	public static function unit_bookings_from( int $unit_id, string $from ): array {
		global $wpdb;
		$b = self::table( 'bookings' );
		$p = self::table( 'participants' );
		return array_map(
			array( self::class, 'normalize_booking' ),
			$wpdb->get_results(
				$wpdb->prepare(
					"SELECT DISTINCT b.* FROM {$b} b LEFT JOIN {$p} p ON p.booking_id = b.id
					 WHERE b.slot_date >= %s AND b.cancelled_at IS NULL AND ( b.unit_id = %d OR p.unit_id = %d )
					 ORDER BY b.slot_date, b.slot_time",
					$from,
					$unit_id,
					$unit_id
				)
			)
		);
	}

	/** Upcoming bookings without reminder, starting within the next $hours. */
	public static function bookings_needing_reminder( string $from_date, string $to_date ): array {
		global $wpdb;
		return array_map(
			array( self::class, 'normalize_booking' ),
			$wpdb->get_results(
				$wpdb->prepare(
					'SELECT * FROM ' . self::table( 'bookings' ) . ' WHERE cancelled_at IS NULL AND reminder_sent_at IS NULL AND mode <> %s AND slot_date BETWEEN %s AND %s',
					Room::BLOCKED,
					$from_date,
					$to_date
				)
			)
		);
	}

	public static function bookings_needing_rating_request( string $from_date, string $to_date ): array {
		global $wpdb;
		return array_map(
			array( self::class, 'normalize_booking' ),
			$wpdb->get_results(
				$wpdb->prepare(
					'SELECT * FROM ' . self::table( 'bookings' ) . ' WHERE cancelled_at IS NULL AND rating_request_sent_at IS NULL AND mode <> %s AND slot_date BETWEEN %s AND %s',
					Room::BLOCKED,
					$from_date,
					$to_date
				)
			)
		);
	}

	/* --------------------------------------------------------- Participants */

	public static function normalize_participant( $row ) {
		$row->id          = (int) $row->id;
		$row->booking_id  = (int) $row->booking_id;
		$row->resident_id = (int) $row->resident_id;
		$row->unit_id     = (int) $row->unit_id;
		$counts           = json_decode( (string) $row->counts, true );
		$row->counts      = is_array( $counts ) ? $counts : array();
		$row->total       = (int) $row->total;
		return $row;
	}

	public static function participants( int $booking_id ): array {
		global $wpdb;
		return array_map(
			array( self::class, 'normalize_participant' ),
			$wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::table( 'participants' ) . ' WHERE booking_id = %d ORDER BY id', $booking_id ) )
		);
	}

	/** @return array<int,object[]> booking_id => participants */
	public static function participants_for( array $booking_ids ): array {
		global $wpdb;
		$ids = array_filter( array_map( 'intval', $booking_ids ) );
		if ( ! $ids ) {
			return array();
		}
		$out = array();
		foreach ( $wpdb->get_results( 'SELECT * FROM ' . self::table( 'participants' ) . ' WHERE booking_id IN (' . implode( ',', $ids ) . ') ORDER BY id' ) as $row ) {
			$row                            = self::normalize_participant( $row );
			$out[ $row->booking_id ][]      = $row;
		}
		return $out;
	}

	public static function insert_participant( int $booking_id, int $resident_id, int $unit_id, array $counts ): int {
		global $wpdb;
		$ok = $wpdb->insert(
			self::table( 'participants' ),
			array(
				'booking_id'  => $booking_id,
				'resident_id' => $resident_id,
				'unit_id'     => $unit_id,
				'counts'      => wp_json_encode( (object) $counts ),
				'total'       => array_sum( $counts ),
				'created_at'  => self::now_utc(),
			)
		);
		return $ok ? (int) $wpdb->insert_id : 0;
	}

	public static function delete_participant( int $id ): void {
		global $wpdb;
		$wpdb->delete( self::table( 'participants' ), array( 'id' => $id ) );
	}

	public static function participations_by_resident( int $resident_id ): array {
		global $wpdb;
		return array_map(
			array( self::class, 'normalize_participant' ),
			$wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::table( 'participants' ) . ' WHERE resident_id = %d', $resident_id ) )
		);
	}

	/* ------------------------------------------------------------- Waitlist */

	public static function waitlist_at( string $date, string $time ): array {
		global $wpdb;
		return $wpdb->get_results(
			$wpdb->prepare( 'SELECT * FROM ' . self::table( 'waitlist' ) . ' WHERE slot_date = %s AND slot_time = %s ORDER BY id', $date, $time . ':00' )
		);
	}

	public static function waitlist_between( string $from, string $to ): array {
		global $wpdb;
		return $wpdb->get_results(
			$wpdb->prepare( 'SELECT * FROM ' . self::table( 'waitlist' ) . ' WHERE slot_date BETWEEN %s AND %s', $from, $to )
		);
	}

	public static function add_waitlist( string $date, string $time, int $resident_id, int $unit_id ): void {
		global $wpdb;
		$wpdb->query(
			$wpdb->prepare(
				'INSERT IGNORE INTO ' . self::table( 'waitlist' ) . ' (slot_date, slot_time, resident_id, unit_id, created_at) VALUES (%s, %s, %d, %d, %s)',
				$date,
				$time . ':00',
				$resident_id,
				$unit_id,
				self::now_utc()
			)
		);
	}

	public static function remove_waitlist( string $date, string $time, int $resident_id ): void {
		global $wpdb;
		$wpdb->delete(
			self::table( 'waitlist' ),
			array(
				'slot_date'   => $date,
				'slot_time'   => $time . ':00',
				'resident_id' => $resident_id,
			)
		);
	}

	public static function mark_waitlist_notified( array $ids ): void {
		global $wpdb;
		$ids = array_filter( array_map( 'intval', $ids ) );
		if ( $ids ) {
			$wpdb->query( $wpdb->prepare( 'UPDATE ' . self::table( 'waitlist' ) . ' SET notified_at = %s WHERE id IN (' . implode( ',', $ids ) . ')', self::now_utc() ) );
		}
	}

	/* -------------------------------------------------------------- Ratings */

	public static function normalize_rating( $row ) {
		if ( ! $row ) {
			return null;
		}
		$answers      = json_decode( (string) $row->answers, true );
		$row->answers = is_array( $answers ) ? $answers : array();
		$row->issues  = array_values( array_filter( explode( ',', (string) $row->issues ) ) );
		return $row;
	}

	public static function rating_exists( int $booking_id, int $unit_id ): bool {
		global $wpdb;
		return (bool) $wpdb->get_var(
			$wpdb->prepare( 'SELECT 1 FROM ' . self::table( 'ratings' ) . ' WHERE booking_id = %d AND unit_id = %d', $booking_id, $unit_id )
		);
	}

	public static function insert_rating( array $data ): int {
		global $wpdb;
		$data['answers']    = wp_json_encode( (object) $data['answers'] );
		$data['issues']     = implode( ',', $data['issues'] );
		$data['created_at'] = self::now_utc();
		$ok                 = $wpdb->insert( self::table( 'ratings' ), $data );
		return $ok ? (int) $wpdb->insert_id : 0;
	}

	public static function ratings_since( string $date, int $limit = 200 ): array {
		global $wpdb;
		return array_map(
			array( self::class, 'normalize_rating' ),
			$wpdb->get_results(
				$wpdb->prepare( 'SELECT * FROM ' . self::table( 'ratings' ) . ' WHERE slot_date >= %s ORDER BY slot_date DESC, id DESC LIMIT %d', $date, $limit )
			)
		);
	}

	/**
	 * The most recent finished booking of a unit (since $since) that the unit
	 * has not rated yet.
	 */
	public static function unrated_booking( int $unit_id, string $since, \DateTimeImmutable $now ) {
		global $wpdb;
		$b    = self::table( 'bookings' );
		$p    = self::table( 'participants' );
		$r    = self::table( 'ratings' );
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT DISTINCT b.* FROM {$b} b INNER JOIN {$p} p ON p.booking_id = b.id AND p.unit_id = %d
				 LEFT JOIN {$r} r ON r.booking_id = b.id AND r.unit_id = %d
				 WHERE b.cancelled_at IS NULL AND b.slot_date BETWEEN %s AND %s AND r.id IS NULL
				 ORDER BY b.slot_date DESC, b.slot_time DESC LIMIT 10",
				$unit_id,
				$unit_id,
				$since,
				$now->format( 'Y-m-d' )
			)
		);
		foreach ( $rows as $row ) {
			$booking = self::normalize_booking( $row );
			if ( Time::end( $booking ) <= $now ) {
				return $booking;
			}
		}
		return null;
	}

	/* --------------------------------------------------------------- Series */

	public static function normalize_series( $row ) {
		if ( ! $row ) {
			return null;
		}
		$row->id         = (int) $row->id;
		$row->weekdays   = array_values( array_filter( array_map( 'intval', explode( ',', (string) $row->weekdays ) ) ) );
		$row->interval_n = max( 1, (int) $row->interval_n );
		$row->end_date   = ( $row->end_date && '0000-00-00' !== $row->end_date ) ? $row->end_date : null;
		$row->start_time = substr( (string) $row->start_time, 0, 5 );
		$row->slots      = max( 1, (int) $row->slots );
		$row->active     = (bool) $row->active;
		$attributes      = json_decode( (string) $row->attributes, true );
		$row->attributes = is_array( $attributes ) ? $attributes : array();
		return $row;
	}

	/** @return array<int,object> */
	public static function all_series( bool $active_only = false ): array {
		global $wpdb;
		$where = $active_only ? ' WHERE active = 1' : '';
		$out   = array();
		foreach ( $wpdb->get_results( 'SELECT * FROM ' . self::table( 'series' ) . $where . ' ORDER BY title, id' ) as $row ) {
			$out[ (int) $row->id ] = self::normalize_series( $row );
		}
		return $out;
	}

	public static function series( int $id ) {
		global $wpdb;
		return self::normalize_series( $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table( 'series' ) . ' WHERE id = %d', $id ) ) );
	}

	public static function save_series( array $data, int $id = 0 ): int {
		global $wpdb;
		$data['weekdays']   = implode( ',', $data['weekdays'] ?? array() );
		$data['attributes'] = wp_json_encode( (object) ( $data['attributes'] ?? array() ) );
		if ( $id ) {
			$wpdb->update( self::table( 'series' ), $data, array( 'id' => $id ) );
			return $id;
		}
		$data['created_at'] = self::now_utc();
		$wpdb->insert( self::table( 'series' ), $data );
		return (int) $wpdb->insert_id;
	}

	/** Deletes a series; its future materialized bookings are cancelled. */
	public static function delete_series( int $id ): void {
		global $wpdb;
		$wpdb->query(
			$wpdb->prepare(
				'UPDATE ' . self::table( 'bookings' ) . ' SET cancelled_at = %s WHERE series_id = %d AND cancelled_at IS NULL AND slot_date >= %s',
				self::now_utc(),
				$id,
				Time::today()
			)
		);
		$wpdb->delete( self::table( 'series' ), array( 'id' => $id ) );
	}

	/* ------------------------------------------------------------ Retention */

	/** Deletes bookings, participants and waitlist entries before $date. */
	public static function purge_before( string $date ): void {
		global $wpdb;
		$b = self::table( 'bookings' );
		$p = self::table( 'participants' );
		$wpdb->query( $wpdb->prepare( "DELETE p FROM {$p} p INNER JOIN {$b} b ON b.id = p.booking_id WHERE b.slot_date < %s", $date ) );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$b} WHERE slot_date < %s", $date ) );
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . self::table( 'waitlist' ) . ' WHERE slot_date < %s', $date ) );
		// Ratings stay for statistics, but without any link to a flat or person.
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . self::table( 'ratings' ) . ' SET booking_id = NULL, unit_id = 0, resident_id = 0 WHERE slot_date < %s AND unit_id <> 0', $date ) );
	}

	public static function purge_sessions(): void {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . self::table( 'sessions' ) . ' WHERE expires_at < %s', self::now_utc() ) );
	}

	/** Residents without session, email or upcoming activity, last seen before $before (UTC). */
	public static function purge_idle_residents( string $before ): void {
		global $wpdb;
		$r   = self::table( 'residents' );
		$s   = self::table( 'sessions' );
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT r.id FROM {$r} r LEFT JOIN {$s} s ON s.resident_id = r.id
				 WHERE s.id IS NULL AND r.email = '' AND r.last_seen_at < %s",
				$before
			)
		);
		foreach ( $ids as $id ) {
			self::delete_resident( (int) $id );
		}
	}
}
