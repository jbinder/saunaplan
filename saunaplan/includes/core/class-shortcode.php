<?php
namespace Saunaplan\Core;

defined( 'ABSPATH' ) || exit;

/**
 * [saunaplan] – the booking page. The HTML contains no booking data; the
 * script loads it only for signed-in devices.
 */
class Shortcode {

	public static function init() {
		add_shortcode( 'saunaplan', array( self::class, 'render' ) );
	}

	public static function render(): string {
		self::enqueue();
		return sprintf(
			'<div class="saunaplan" data-saunaplan><p class="sp-loading">%1$s</p><noscript>%2$s</noscript></div>',
			esc_html__( 'Loading…', 'saunaplan' ),
			esc_html__( 'Please enable JavaScript to use the booking page.', 'saunaplan' )
		);
	}

	private static function enqueue() {
		static $done = false;
		if ( $done ) {
			return;
		}
		$done = true;

		wp_enqueue_style( 'saunaplan', SAUNAPLAN_URL . 'assets/css/core.css', array(), SAUNAPLAN_VERSION );
		wp_enqueue_script( 'saunaplan', SAUNAPLAN_URL . 'assets/js/core-app.js', array(), SAUNAPLAN_VERSION, true );
		// Room profiles add their look here.
		do_action( 'saunaplan_enqueue' );

		$today = Time::today();
		$data  = array(
			'restUrl'  => esc_url_raw( rest_url( Rest_Controller::NS . '/' ) ),
			'locale'   => I18n::js_locale(),
			'today'    => $today,
			'tomorrow' => Time::add_days( $today, 1 ),
			'i18n'     => self::strings(),
		);
		wp_add_inline_script( 'saunaplan', 'window.Saunaplan = ' . wp_json_encode( $data ) . ';', 'before' );
	}

	public static function strings(): array {
		return array(
			'loadError'        => __( 'The booking page could not be loaded. Please try again later.', 'saunaplan' ),
			'genericError'     => __( 'Something went wrong. Please try again.', 'saunaplan' ),
			'signInTitle'      => __( 'Sign in', 'saunaplan' ),
			'signInText'       => __( 'Your flat has received a letter with a personal code. Enter it once – this device will then stay signed in.', 'saunaplan' ),
			'codeLabel'        => __( 'Code from your letter', 'saunaplan' ),
			'signIn'           => __( 'Sign in', 'saunaplan' ),
			'signingIn'        => __( 'Signing in…', 'saunaplan' ),
			'welcome'          => __( 'Welcome! You are signed in.', 'saunaplan' ),
			'lostCode'         => __( 'Lost your letter? Please ask the administrators for a new code.', 'saunaplan' ),
			'privacyNote'      => __( 'Bookings are anonymous: others only see the kind of session and the number of people – never which flat booked.', 'saunaplan' ),
			'signOut'          => __( 'Sign out', 'saunaplan' ),
			'signOutConfirm'   => __( 'Sign out on this device? You will need the code from your letter to sign in again.', 'saunaplan' ),
			'reminders'        => __( 'Email reminders', 'saunaplan' ),
			'emailTitle'       => __( 'Email reminders', 'saunaplan' ),
			'emailText'        => __( 'Optional: get a confirmation, a reminder before your session (with what to do when you leave), news from the waiting list and a short rating request.', 'saunaplan' ),
			/* translators: %s: email address */
			'emailActive'      => __( 'Mails go to %s.', 'saunaplan' ),
			'emailNone'        => __( 'No email address yet – you will only see everything here on the page.', 'saunaplan' ),
			/* translators: %s: email address */
			'emailPendingNote' => __( 'Waiting for confirmation of %s – please click the link in the email.', 'saunaplan' ),
			'emailLabel'       => __( 'Your email address', 'saunaplan' ),
			'emailSave'        => __( 'Save', 'saunaplan' ),
			'emailRemove'      => __( 'Remove my email address', 'saunaplan' ),
			'emailPrivacy'     => __( 'Your address is only used for these mails and is never shown to anyone.', 'saunaplan' ),
			'close'            => __( 'Close', 'saunaplan' ),
			'today'            => __( 'Today', 'saunaplan' ),
			'tomorrow'         => __( 'Tomorrow', 'saunaplan' ),
			'myBookings'       => __( 'My bookings', 'saunaplan' ),
			'noBookings'       => __( 'You have no bookings yet. Tap a free time below to book.', 'saunaplan' ),
			'timetable'        => __( 'Book a time', 'saunaplan' ),
			'noSlots'          => __( 'There are no bookable times in the coming days.', 'saunaplan' ),
			'legend'           => __( 'Legend', 'saunaplan' ),
			'legendFree'       => __( 'Free', 'saunaplan' ),
			'legendOpen'       => __( 'Open – you can join', 'saunaplan' ),
			'legendPrivate'    => __( 'Taken', 'saunaplan' ),
			'free'             => __( 'Free', 'saunaplan' ),
			'full'             => __( 'full', 'saunaplan' ),
			'notAvailable'     => __( 'This time is not available.', 'saunaplan' ),
			'beyondHorizon'    => __( 'This time can not be booked yet.', 'saunaplan' ),
			'started'          => __( 'In progress', 'saunaplan' ),
			'yours'            => __( 'Yours', 'saunaplan' ),
			'joined'           => __( 'Joined', 'saunaplan' ),
			'onWaitlistShort'  => __( 'Waiting', 'saunaplan' ),
			'onWaitlist'       => __( 'You are on the waiting list.', 'saunaplan' ),
			'freeNow'          => __( 'Good news: this time is free now – book it before someone else does!', 'saunaplan' ),
			'actionBook'       => __( 'Book', 'saunaplan' ),
			'actionJoin'       => __( 'Join', 'saunaplan' ),
			'actionWait'       => __( 'Wait', 'saunaplan' ),
			/* translators: 1: people, 2: capacity */
			'placesTaken'      => __( '%1$s of %2$s places', 'saunaplan' ),
			/* translators: %s: free places */
			'placesFree'       => __( 'Places left: %s', 'saunaplan' ),
			'oneWaiting'       => __( '1 waiting', 'saunaplan' ),
			/* translators: %s: number of people on the waiting list */
			'waiting'          => __( '%s waiting', 'saunaplan' ),
			'bookTitle'        => __( 'Book', 'saunaplan' ),
			'chooseMode'       => __( 'How do you want to book?', 'saunaplan' ),
			'people'           => __( 'How many of you?', 'saunaplan' ),
			'peopleOptional'   => __( 'How many of you? (optional)', 'saunaplan' ),
			/* translators: %s: people category, e.g. "Women" */
			'less'             => __( 'One less: %s', 'saunaplan' ),
			/* translators: %s: people category, e.g. "Women" */
			'more'             => __( 'One more: %s', 'saunaplan' ),
			'bookNow'          => __( 'Book now', 'saunaplan' ),
			'book'             => __( 'Book', 'saunaplan' ),
			'join'             => __( 'Join', 'saunaplan' ),
			'joinTitle'        => __( 'Join this session', 'saunaplan' ),
			'joinNow'          => __( 'Join now', 'saunaplan' ),
			'or'               => __( 'or', 'saunaplan' ),
			'waitlist'         => __( 'Join the waiting list', 'saunaplan' ),
			'unwait'           => __( 'Leave the waiting list', 'saunaplan' ),
			'cancelBooking'    => __( 'Cancel booking', 'saunaplan' ),
			'cancelConfirm'    => __( 'Really cancel this booking?', 'saunaplan' ),
			'leave'            => __( 'Leave session', 'saunaplan' ),
			'leaveConfirm'     => __( 'Really leave this session?', 'saunaplan' ),
			'sending'          => __( 'One moment…', 'saunaplan' ),
			'rateTitle'        => __( 'How was it?', 'saunaplan' ),
			'ratePrompt'       => __( 'How was your last session?', 'saunaplan' ),
			'rateNow'          => __( 'Rate now (10 seconds)', 'saunaplan' ),
			'rateQuick'        => __( 'Everything was fine', 'saunaplan' ),
			'rateOr'           => __( 'or tell us more:', 'saunaplan' ),
			'faceBad'          => __( 'bad', 'saunaplan' ),
			'faceOkay'         => __( 'okay', 'saunaplan' ),
			'faceGood'         => __( 'good', 'saunaplan' ),
			'issuesLabel'      => __( 'Anything wrong?', 'saunaplan' ),
			'commentLabel'     => __( 'Comment (optional)', 'saunaplan' ),
			'send'             => __( 'Send', 'saunaplan' ),
			'thanks'           => __( 'Thank you!', 'saunaplan' ),
			'thanksText'       => __( 'Your feedback has been saved.', 'saunaplan' ),
			'alreadyRated'     => __( 'This session has already been rated by your flat. Thank you!', 'saunaplan' ),
			/* translators: %s: names of the modes that count toward the monthly limit */
			'usageLabel'       => __( 'Your flat\'s bookings (%s):', 'saunaplan' ),
			/* translators: 1: month, 2: used, 3: limit */
			'usage'            => __( '%1$s %2$s of %3$s', 'saunaplan' ),
			/* translators: 1: limit per month, 2: mode names */
			'ruleLimit'        => __( 'Max. %1$s bookings "%2$s" per flat and month', 'saunaplan' ),
			/* translators: %s: slots in a row */
			'ruleConsecutive'  => __( 'max. %s slots in a row', 'saunaplan' ),
			/* translators: %s: days */
			'ruleHorizon'      => __( 'bookable %s days ahead', 'saunaplan' ),
			/* translators: %s: people */
			'ruleCapacity'     => __( 'up to %s people', 'saunaplan' ),
		);
	}
}
