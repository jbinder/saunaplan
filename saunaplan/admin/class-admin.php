<?php
namespace Saunaplan\Admin;

use Saunaplan\Core\Booking_Service;
use Saunaplan\Core\Ratings;
use Saunaplan\Core\Repository;
use Saunaplan\Core\Room;
use Saunaplan\Core\Schedule;
use Saunaplan\Core\Series;
use Saunaplan\Core\Settings;
use Saunaplan\Core\Time;
use Saunaplan\Core\Units;

defined( 'ABSPATH' ) || exit;

/**
 * Admin screens: Schedule, Flats (+ code letters), Recurring sessions, Ratings, Settings.
 */
class Admin {

	const CAP = 'manage_options';

	public static function init() {
		add_action( 'admin_menu', array( self::class, 'menu' ) );
		add_action( 'admin_init', array( self::class, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( self::class, 'assets' ) );
		add_action( 'admin_notices', array( self::class, 'notices' ) );
		add_filter( 'display_post_states', array( self::class, 'post_states' ), 10, 2 );

		foreach ( array( 'import_units', 'unit_action', 'bulk_codes', 'save_series', 'delete_series', 'cancel_booking', 'skip_series' ) as $action ) {
			add_action( "admin_post_saunaplan_{$action}", array( self::class, "handle_{$action}" ) );
		}
	}

	public static function menu() {
		$room = Room::profile()->label();
		add_menu_page( $room, $room, self::CAP, 'saunaplan', array( self::class, 'page_schedule' ), 'dashicons-calendar-alt', 26 );
		add_submenu_page( 'saunaplan', __( 'Schedule', 'saunaplan' ), __( 'Schedule', 'saunaplan' ), self::CAP, 'saunaplan', array( self::class, 'page_schedule' ) );
		add_submenu_page( 'saunaplan', __( 'Flats', 'saunaplan' ), __( 'Flats', 'saunaplan' ), self::CAP, 'saunaplan-units', array( self::class, 'page_units' ) );
		add_submenu_page( 'saunaplan', __( 'Recurring sessions', 'saunaplan' ), __( 'Recurring sessions', 'saunaplan' ), self::CAP, 'saunaplan-series', array( self::class, 'page_series' ) );
		add_submenu_page( 'saunaplan', __( 'Ratings', 'saunaplan' ), __( 'Ratings', 'saunaplan' ), self::CAP, 'saunaplan-ratings', array( self::class, 'page_ratings' ) );
		add_submenu_page( 'saunaplan', __( 'Settings', 'saunaplan' ), __( 'Settings', 'saunaplan' ), self::CAP, 'saunaplan-settings', array( self::class, 'page_settings' ) );
		// Printable code letters: reachable only from the Flats screen, so its menu entry is removed again.
		add_submenu_page( 'saunaplan', __( 'Code letters', 'saunaplan' ), __( 'Code letters', 'saunaplan' ), self::CAP, 'saunaplan-letters', array( self::class, 'page_letters' ) );
		add_action(
			'admin_head',
			static function () {
				remove_submenu_page( 'saunaplan', 'saunaplan-letters' );
			}
		);
	}

	public static function register_settings() {
		register_setting( 'saunaplan_settings_group', Settings::OPTION, array( 'sanitize_callback' => array( Settings::class, 'sanitize' ) ) );
	}

	public static function assets( $hook ) {
		if ( false === strpos( (string) $hook, 'saunaplan' ) ) {
			return;
		}
		$deps = array();
		if ( false !== strpos( (string) $hook, 'saunaplan-letters' ) ) {
			wp_register_script( 'saunaplan-qrcode', SAUNAPLAN_URL . 'assets/vendor/qrcode.min.js', array(), '1.4.4', true );
			$deps[] = 'saunaplan-qrcode';
		}
		wp_enqueue_style( 'saunaplan-admin', SAUNAPLAN_URL . 'assets/css/admin.css', array(), \saunaplan_asset_version( 'assets/css/admin.css' ) );
		wp_enqueue_script( 'saunaplan-admin', SAUNAPLAN_URL . 'assets/js/admin.js', $deps, \saunaplan_asset_version( 'assets/js/admin.js' ), true );
	}

	/* ----------------------------------------------------------------- Pages */

	public static function page_schedule() {
		$from = isset( $_GET['from'] ) && Time::is_date( wp_unslash( $_GET['from'] ) ) ? wp_unslash( $_GET['from'] ) : Time::today();
		$days = isset( $_GET['days'] ) ? absint( $_GET['days'] ) : 7;
		$days = in_array( $days, array( 1, 7, 14, 28 ), true ) ? $days : 7;
		$to   = Time::add_days( $from, $days - 1 );

		$slots = Schedule::slots( $from, $to );
		$units = Repository::units();
		include SAUNAPLAN_DIR . 'admin/views/schedule.php';
	}

	public static function page_units() {
		$units   = Repository::units();
		$devices = Repository::signed_in_counts();
		$emails  = array();
		foreach ( $units as $unit ) {
			$emails[ $unit->id ] = count(
				array_filter(
					Repository::residents_of_unit( (int) $unit->id ),
					static function ( $r ) {
						return '' !== $r->email;
					}
				)
			);
		}
		include SAUNAPLAN_DIR . 'admin/views/units.php';
	}

	public static function page_letters() {
		$batch = isset( $_GET['batch'] ) ? sanitize_key( wp_unslash( $_GET['batch'] ) ) : '';
		$codes = $batch ? get_transient( self::letters_key( $batch ) ) : false;
		$units = Repository::units();
		include SAUNAPLAN_DIR . 'admin/views/letters.php';
	}

	public static function page_series() {
		$id      = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
		$editing = $id ? Repository::series( $id ) : null;
		$new     = isset( $_GET['new'] );
		$list    = Repository::all_series();
		include SAUNAPLAN_DIR . 'admin/views/series.php';
	}

	public static function page_ratings() {
		$days    = isset( $_GET['days'] ) ? absint( $_GET['days'] ) : 90;
		$days    = in_array( $days, array( 30, 90, 365 ), true ) ? $days : 90;
		$ratings = Repository::ratings_since( Time::add_days( Time::today(), -$days ) );
		$summary = Ratings::summary( $ratings );
		$units   = Repository::units();
		include SAUNAPLAN_DIR . 'admin/views/ratings.php';
	}

	public static function page_settings() {
		$settings = Settings::all();
		include SAUNAPLAN_DIR . 'admin/views/settings.php';
	}

	/* -------------------------------------------------------------- Handlers */

	public static function handle_import_units() {
		self::verify( 'saunaplan_import_units' );
		$text = (string) wp_unslash( $_POST['units'] ?? '' );
		if ( ! empty( $_FILES['file']['tmp_name'] ) && is_uploaded_file( $_FILES['file']['tmp_name'] ) ) {
			$text .= "\n" . (string) file_get_contents( $_FILES['file']['tmp_name'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}
		$parsed = Units::parse_import( $text );
		$ids    = Units::import( $parsed['units'] );
		$back   = self::url( 'saunaplan-units' );

		$message = sprintf(
			/* translators: 1: new flats, 2: flats in the list */
			__( '%1$d new flats added (%2$d in the list, existing ones were kept).', 'saunaplan' ),
			count( $ids ),
			count( $parsed['units'] )
		);
		if ( $parsed['invalid'] ) {
			/* translators: %s: line numbers */
			$message .= ' ' . sprintf( __( 'These lines could not be read: %s.', 'saunaplan' ), implode( ', ', $parsed['invalid'] ) );
		}
		if ( $ids && ! empty( $_POST['codes'] ) ) {
			self::redirect( self::letters_url( Units::issue_codes( $ids ) ), 'success', $message );
		}
		self::redirect( $back, $parsed['invalid'] ? 'error' : 'success', $message );
	}

	public static function handle_unit_action() {
		self::verify( 'saunaplan_unit_action' );
		$id   = absint( $_POST['unit_id'] ?? 0 );
		$unit = $id ? Repository::unit( $id ) : null;
		$back = self::url( 'saunaplan-units' );
		if ( ! $unit ) {
			self::redirect( $back );
		}
		$label = Units::short_label( $unit );
		switch ( sanitize_key( wp_unslash( $_POST['do'] ?? '' ) ) ) {
			case 'code':
				if ( ! empty( $_POST['signout'] ) ) {
					Repository::delete_sessions_of_unit( $id );
				}
				self::redirect( self::letters_url( Units::issue_codes( array( $id ) ) ) );
				break;
			case 'signout':
				$n = Repository::delete_sessions_of_unit( $id );
				/* translators: 1: flat, 2: number of devices */
				self::redirect( $back, 'success', sprintf( __( 'Flat %1$s: %2$d devices signed out.', 'saunaplan' ), $label, $n ) );
				break;
			case 'toggle':
				Repository::update_unit( $id, array( 'active' => $unit->active ? 0 : 1 ) );
				self::redirect(
					$back,
					'success',
					$unit->active
						/* translators: %s: flat */
						? sprintf( __( 'Flat %s deactivated – it can no longer sign in or book.', 'saunaplan' ), $label )
						/* translators: %s: flat */
						: sprintf( __( 'Flat %s activated.', 'saunaplan' ), $label )
				);
				break;
			case 'delete':
				Repository::delete_unit( $id );
				/* translators: %s: flat */
				self::redirect( $back, 'success', sprintf( __( 'Flat %s deleted.', 'saunaplan' ), $label ) );
				break;
		}
		self::redirect( $back );
	}

	public static function handle_bulk_codes() {
		self::verify( 'saunaplan_bulk_codes' );
		$which = sanitize_key( wp_unslash( $_POST['which'] ?? 'missing' ) );
		$ids   = array();
		foreach ( Repository::units( true ) as $unit ) {
			if ( 'all' === $which || empty( $unit->code_hash ) ) {
				$ids[] = (int) $unit->id;
			}
		}
		if ( ! $ids ) {
			self::redirect( self::url( 'saunaplan-units' ), 'error', __( 'All active flats already have a code.', 'saunaplan' ) );
		}
		if ( 'all' === $which && ! empty( $_POST['signout'] ) ) {
			foreach ( $ids as $id ) {
				Repository::delete_sessions_of_unit( $id );
			}
		}
		self::redirect( self::letters_url( Units::issue_codes( $ids ) ) );
	}

	public static function handle_save_series() {
		self::verify( 'saunaplan_save_series' );
		$in   = wp_unslash( $_POST );
		$id   = absint( $in['id'] ?? 0 );
		$mode = (string) ( $in['mode'] ?? '' );

		$weekdays = array_values( array_unique( array_filter( array_map( 'intval', (array) ( $in['weekdays'] ?? array() ) ), static function ( $d ) {
			return $d >= 1 && $d <= 7;
		} ) ) );
		sort( $weekdays );

		$data = array(
			'title'      => mb_substr( sanitize_text_field( $in['title'] ?? '' ), 0, 100 ),
			'mode'       => Room::mode( $mode ) ? $mode : '',
			'attributes' => Room::sanitize_attributes( (array) ( $in['attributes'] ?? array() ), $mode ),
			'weekdays'   => $weekdays,
			'interval_n' => max( 1, min( 52, absint( $in['interval_n'] ?? 1 ) ) ),
			'start_date' => (string) ( $in['start_date'] ?? '' ),
			'end_date'   => (string) ( $in['end_date'] ?? '' ),
			'start_time' => Settings::sanitize_time( $in['start_time'] ?? '', '' ),
			'slots'      => max( 1, min( 24, absint( $in['slots'] ?? 1 ) ) ),
			'active'     => empty( $in['active'] ) ? 0 : 1,
		);

		$errors = array();
		if ( '' === $data['mode'] ) {
			$errors[] = __( 'Please choose a kind of session.', 'saunaplan' );
		}
		if ( ! $weekdays ) {
			$errors[] = __( 'Please select at least one weekday.', 'saunaplan' );
		}
		if ( ! Time::is_date( $data['start_date'] ) ) {
			$errors[] = __( 'Please enter a valid start date.', 'saunaplan' );
		}
		if ( '' !== $data['end_date'] && ( ! Time::is_date( $data['end_date'] ) || $data['end_date'] < $data['start_date'] ) ) {
			$errors[] = __( 'The end date must be a valid date after the start date.', 'saunaplan' );
		}
		if ( '' === $data['start_time'] ) {
			$errors[] = __( 'Please enter a start time.', 'saunaplan' );
		}
		$back = self::url( 'saunaplan-series', $id ? array( 'id' => $id ) : array( 'new' => 1 ) );
		if ( $errors ) {
			self::redirect( $back, 'error', implode( ' ', $errors ) );
		}
		$data['end_date']   = '' === $data['end_date'] ? null : $data['end_date'];
		$data['start_time'] = $data['start_time'] . ':00';
		$saved     = Repository::save_series( $data, $id );
		$conflicts = self::series_conflicts( Repository::series( $saved ) );
		$message   = __( 'Recurring session saved.', 'saunaplan' );
		if ( $conflicts ) {
			/* translators: %s: list of dates/times */
			$message .= ' ' . sprintf( __( 'Already booked by residents (these bookings stay, the recurring session is not shown there): %s', 'saunaplan' ), implode( ', ', $conflicts ) );
		}
		self::redirect( self::url( 'saunaplan-series' ), $conflicts ? 'error' : 'success', $message );
	}

	/** Upcoming resident bookings that overlap a series (within the booking horizon). */
	private static function series_conflicts( $series ): array {
		if ( ! $series ) {
			return array();
		}
		$out     = array();
		$from    = Time::today();
		$to      = Time::add_days( $from, max( 28, (int) Settings::get( 'horizon_days' ) ) );
		$minutes = (int) Settings::get( 'slot_minutes' );
		foreach ( Repository::bookings_between( $from, $to, false ) as $booking ) {
			if ( $booking->series_id || ! Series::occurs_on( $series, $booking->slot_date ) ) {
				continue;
			}
			if ( in_array( $booking->slot_time, Series::times( $series, $minutes ), true ) ) {
				$out[] = Time::format_date( $booking->slot_date ) . ' ' . $booking->slot_time;
			}
		}
		return $out;
	}

	public static function handle_delete_series() {
		self::verify( 'saunaplan_delete_series' );
		$id = absint( $_POST['id'] ?? 0 );
		if ( $id ) {
			Repository::delete_series( $id );
		}
		self::redirect( self::url( 'saunaplan-series' ), 'success', __( 'Recurring session deleted.', 'saunaplan' ) );
	}

	public static function handle_cancel_booking() {
		self::verify( 'saunaplan_cancel_booking' );
		$ok = Booking_Service::admin_cancel( absint( $_POST['booking_id'] ?? 0 ) );
		self::redirect( self::back(), $ok ? 'success' : 'error', $ok ? __( 'Booking cancelled. People who joined and the waiting list were notified.', 'saunaplan' ) : __( 'Booking not found.', 'saunaplan' ) );
	}

	public static function handle_skip_series() {
		self::verify( 'saunaplan_skip_series' );
		$date = (string) wp_unslash( $_POST['date'] ?? '' );
		$time = (string) wp_unslash( $_POST['time'] ?? '' );
		if ( Time::is_date( $date ) && Time::is_time( $time ) ) {
			Booking_Service::skip_series_slot( absint( $_POST['series_id'] ?? 0 ), $date, $time );
		}
		self::redirect( self::back(), 'success', __( 'This date of the recurring session was cancelled.', 'saunaplan' ) );
	}

	/* --------------------------------------------------------------- Helpers */

	public static function url( string $page, array $args = array() ): string {
		return add_query_arg( array_merge( array( 'page' => $page ), $args ), admin_url( 'admin.php' ) );
	}

	/** Keep freshly generated codes for one hour, only for printing. */
	private static function letters_url( array $codes ): string {
		$batch = strtolower( wp_generate_password( 12, false ) );
		set_transient( self::letters_key( $batch ), $codes, HOUR_IN_SECONDS );
		return self::url( 'saunaplan-letters', array( 'batch' => $batch ) );
	}

	private static function letters_key( string $batch ): string {
		return 'saunaplan_letters_' . get_current_user_id() . '_' . $batch;
	}

	/** Hidden fields for an admin-post form (no duplicate IDs, unlike wp_nonce_field). */
	public static function form_fields( string $action ): void {
		printf( '<input type="hidden" name="action" value="%s">', esc_attr( $action ) );
		printf( '<input type="hidden" name="_wpnonce" value="%s">', esc_attr( wp_create_nonce( $action ) ) );
		printf( '<input type="hidden" name="_wp_http_referer" value="%s">', esc_attr( wp_unslash( $_SERVER['REQUEST_URI'] ?? '' ) ) );
	}

	public static function mode_label( string $mode ): string {
		$def = Room::mode( $mode );
		return $def ? trim( $def['icon'] . ' ' . $def['label'] ) : $mode;
	}

	public static function describe_series( $series ): string {
		$names = Time::weekday_names( true );
		$days  = implode( ', ', array_map( static function ( $d ) use ( $names ) {
			return $names[ $d ] ?? '';
		}, $series->weekdays ) );
		$n     = (int) $series->interval_n;
		/* translators: 1: weekdays, 2: interval in weeks */
		$text  = 1 === $n ? sprintf( __( 'Weekly on %1$s', 'saunaplan' ), $days ) : sprintf( __( 'Every %2$d weeks on %1$s', 'saunaplan' ), $days, $n );
		$end   = Time::minutes( $series->start_time ) + $series->slots * (int) Settings::get( 'slot_minutes' );
		$text .= ', ' . $series->start_time . '–' . Time::hhmm( min( 1440, $end ) );
		/* translators: %s: date */
		$text .= ' · ' . sprintf( __( 'from %s', 'saunaplan' ), Time::format_date( $series->start_date ) );
		if ( $series->end_date ) {
			/* translators: %s: date */
			$text .= ' ' . sprintf( __( 'until %s', 'saunaplan' ), Time::format_date( $series->end_date ) );
		}
		return $text;
	}

	/** Where the booking page lives – important because the page is unlisted. */
	public static function page_link(): void {
		$id   = (int) Settings::get( 'page_id' );
		$page = $id ? get_post( $id ) : null;

		echo '<div class="saunaplan-page-link">';
		if ( ! $page || 'trash' === $page->post_status ) {
			printf(
				/* translators: 1: shortcode, 2: settings link */
				wp_kses_post( __( 'Booking page: <strong>no page selected.</strong> Create a page with %1$s and choose it in the <a href="%2$s">settings</a>.', 'saunaplan' ) ),
				'<code>[saunaplan]</code>',
				esc_url( self::url( 'saunaplan-settings' ) )
			);
			echo '</div>';
			return;
		}

		$url = get_permalink( $page );
		echo '<span class="dashicons dashicons-admin-links" aria-hidden="true"></span> ';
		esc_html_e( 'Booking page:', 'saunaplan' );
		printf( ' <a href="%1$s" target="_blank" rel="noopener"><code>%2$s</code></a>', esc_url( $url ), esc_html( $url ) );
		printf(
			' <button type="button" class="button button-small saunaplan-copy" data-copy="%1$s" data-done="%2$s">%3$s</button>',
			esc_attr( $url ),
			esc_attr__( 'Copied!', 'saunaplan' ),
			esc_html__( 'Copy link', 'saunaplan' )
		);
		if ( Settings::get( 'unlisted' ) ) {
			echo ' <span class="saunaplan-tag">' . esc_html__( 'Unlisted – only reachable via this link', 'saunaplan' ) . '</span>';
		}
		$notes = array();
		if ( 'publish' !== $page->post_status ) {
			$notes[] = __( 'The page is not published yet, so residents cannot open it.', 'saunaplan' );
		}
		if ( ! has_shortcode( $page->post_content, 'saunaplan' ) && false === strpos( (string) get_post_meta( $page->ID, '_elementor_data', true ), '[saunaplan' ) ) {
			$notes[] = __( 'The [saunaplan] shortcode was not found on this page. Add it there, e.g. with a Shortcode block.', 'saunaplan' );
		}
		foreach ( $notes as $note ) {
			echo '<br><span class="saunaplan-warning">' . esc_html( $note ) . '</span>';
		}
		echo '</div>';
	}

	public static function post_states( $states, $post ) {
		if ( (int) $post->ID === (int) Settings::get( 'page_id' ) ) {
			/* translators: %s: room name */
			$states['saunaplan'] = sprintf( __( '%s booking page', 'saunaplan' ), Room::profile()->label() ) . ( Settings::get( 'unlisted' ) ? ' (' . __( 'unlisted', 'saunaplan' ) . ')' : '' );
		}
		return $states;
	}

	public static function notices() {
		$screen = get_current_screen();
		if ( ! $screen || false === strpos( $screen->id, 'saunaplan' ) ) {
			return;
		}
		$notice = get_transient( self::notice_key() );
		if ( ! is_array( $notice ) ) {
			return;
		}
		delete_transient( self::notice_key() );
		printf(
			'<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
			esc_attr( 'error' === $notice['type'] ? 'error' : 'success' ),
			esc_html( $notice['text'] )
		);
	}

	private static function verify( string $action ): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'saunaplan' ), 403 );
		}
		check_admin_referer( $action );
	}

	private static function back(): string {
		return wp_get_referer() ?: self::url( 'saunaplan' );
	}

	/** @return never */
	private static function redirect( string $url, string $type = '', string $text = '' ) {
		if ( $text ) {
			set_transient( self::notice_key(), array( 'type' => $type, 'text' => $text ), MINUTE_IN_SECONDS );
		}
		wp_safe_redirect( $url );
		exit;
	}

	private static function notice_key(): string {
		return 'saunaplan_notice_' . get_current_user_id();
	}
}
