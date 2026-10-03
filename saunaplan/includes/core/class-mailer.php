<?php
namespace Saunaplan\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Plain-text emails built from editable templates with {placeholders}.
 */
class Mailer {

	/** Core defaults; the room profile may override texts (Room_Profile::default_templates). */
	public static function core_templates(): array {
		return array(
			'tpl_confirmation_subject'  => __( '{room}: booked for {date}, {time}', 'saunaplan' ),
			'tpl_confirmation_body'     => __(
				"Hello,\n\nyou are booked:\n\n{date}\n{time}\n{details}\n\nCan't make it? Please cancel on the booking page so others can use the slot:\n{page_url}",
				'saunaplan'
			),
			'tpl_reminder_subject'      => __( 'Reminder – {room} on {date}, {time}', 'saunaplan' ),
			'tpl_reminder_body'         => __(
				"Hello,\n\na friendly reminder of your booking:\n\n{date}\n{time}\n{details}\n\n{instructions}\n\nBooking page: {page_url}",
				'saunaplan'
			),
			'tpl_cancelled_subject'     => __( 'Cancelled – {room} on {date}, {time}', 'saunaplan' ),
			'tpl_cancelled_body'        => __(
				"Hello,\n\nthe session on {date}, {time} has been cancelled. Your place in it has been removed – there is nothing you need to do.\n\nBooking page: {page_url}",
				'saunaplan'
			),
			'tpl_waitlist_subject'      => __( 'A place is free – {room} on {date}, {time}', 'saunaplan' ),
			'tpl_waitlist_body'         => __(
				"Hello,\n\ngood news: the slot you were waiting for ({date}, {time}) has become available.\nEveryone on the waiting list gets this message – whoever books first gets it.\n\nBook now: {page_url}",
				'saunaplan'
			),
			'tpl_rating_subject'        => __( 'How was it? – {room} on {date}', 'saunaplan' ),
			'tpl_rating_body'           => __(
				"Hello,\n\nhow was your session on {date}, {time}? Rating takes about ten seconds and helps everyone:\n{rate_url}",
				'saunaplan'
			),
			'tpl_email_confirm_subject' => __( 'Please confirm your email address', 'saunaplan' ),
			'tpl_email_confirm_body'    => __(
				"Hello,\n\nplease confirm that you want booking confirmations and reminders for the {room} at this address:\n{confirm_url}\n\nIf you did not ask for this, just ignore this message.",
				'saunaplan'
			),
			'tpl_issue_subject'         => __( '{room}: feedback for {date}, {time}', 'saunaplan' ),
			'tpl_issue_body'            => __(
				"Hello,\n\nfeedback for the session on {date}, {time} (flat {unit}):\n\n{answers}\n\nComment: {comment}\n\nAll ratings: {admin_url}",
				'saunaplan'
			),
		);
	}

	public static function default_templates(): array {
		return array_merge( self::core_templates(), array_intersect_key( Room::profile()->default_templates(), self::core_templates() ) );
	}

	/** Template groups for the settings screen: key => label. */
	public static function template_labels(): array {
		return array(
			'confirmation'  => __( 'Booking confirmation', 'saunaplan' ),
			'reminder'      => __( 'Reminder before the session', 'saunaplan' ),
			'cancelled'     => __( 'Session cancelled (to people who joined)', 'saunaplan' ),
			'waitlist'      => __( 'Waiting list: a place is free', 'saunaplan' ),
			'rating'        => __( 'Rating request after the session', 'saunaplan' ),
			'email_confirm' => __( 'Confirm email address', 'saunaplan' ),
			'issue'         => __( 'Feedback with issues (to the administrators)', 'saunaplan' ),
		);
	}

	/** @return array<string,string> placeholder => description, for the settings screen. */
	public static function placeholders(): array {
		return array(
			'{room}'         => __( 'Name of the room', 'saunaplan' ),
			'{date}'         => __( 'Date with weekday', 'saunaplan' ),
			'{time}'         => __( 'Time range', 'saunaplan' ),
			'{details}'      => __( 'Kind of booking and number of people', 'saunaplan' ),
			'{instructions}' => __( 'Reminder only: what to do when leaving (depends on the next booking)', 'saunaplan' ),
			'{page_url}'     => __( 'Link to the booking page', 'saunaplan' ),
			'{rate_url}'     => __( 'Rating request only: personal rating link', 'saunaplan' ),
			'{confirm_url}'  => __( 'Email confirmation only: confirmation link', 'saunaplan' ),
			'{site}'         => __( 'Site title', 'saunaplan' ),
			'{unit}'         => __( 'Feedback mail only: the flat', 'saunaplan' ),
			'{answers}'      => __( 'Feedback mail only: the answers', 'saunaplan' ),
			'{comment}'      => __( 'Feedback mail only: the comment', 'saunaplan' ),
			'{admin_url}'    => __( 'Feedback mail only: link to the ratings screen', 'saunaplan' ),
		);
	}

	public static function render( string $template, array $vars ): string {
		$map = array();
		foreach ( $vars as $key => $value ) {
			$map[ '{' . $key . '}' ] = (string) $value;
		}
		return strtr( $template, $map );
	}

	/**
	 * @param string|string[] $to
	 */
	public static function send_template( string $key, $to, array $vars ): bool {
		$settings = Settings::all();
		$vars    += array(
			'site'     => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
			'room'     => Room::profile()->label(),
			'page_url' => Settings::page_url(),
		);
		$subject = self::render( $settings[ "tpl_{$key}_subject" ], $vars );
		$body    = self::render( $settings[ "tpl_{$key}_body" ], $vars );
		// Placeholders without a value (e.g. empty instructions) shouldn't leave big gaps.
		$body = trim( preg_replace( "/\n{3,}/", "\n\n", $body ) );
		return self::send( $to, $subject, $body );
	}

	/** Placeholders for a booking mail. */
	public static function booking_vars( $booking, string $template ): array {
		return array_merge(
			array(
				'date'         => Time::format_long_date( $booking->slot_date ),
				'time'         => Time::format_range( $booking ),
				'details'      => '',
				'instructions' => '',
			),
			Room::profile()->mail_vars( $booking, $template )
		);
	}

	/**
	 * @param string|string[] $to
	 */
	public static function send( $to, string $subject, string $body ): bool {
		$from_name  = Settings::get( 'from_name' );
		$from_email = Settings::get( 'from_email' );
		$name_cb    = static function () use ( $from_name ) {
			return $from_name;
		};
		$email_cb   = static function () use ( $from_email ) {
			return $from_email;
		};

		if ( $from_name ) {
			add_filter( 'wp_mail_from_name', $name_cb );
		}
		if ( $from_email ) {
			add_filter( 'wp_mail_from', $email_cb );
		}

		$sent = wp_mail( $to, $subject, $body, array( 'Content-Type: text/plain; charset=UTF-8' ) );

		remove_filter( 'wp_mail_from_name', $name_cb );
		remove_filter( 'wp_mail_from', $email_cb );
		return (bool) $sent;
	}
}
