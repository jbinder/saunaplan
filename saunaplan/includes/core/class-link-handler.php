<?php
namespace Saunaplan\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Handles ?saunaplan_email=<resident>&key=<hmac> links (confirm an email address).
 *
 * GET only shows a confirmation button; the change needs a POST, so link
 * scanners in mail clients can't confirm anything by accident.
 */
class Link_Handler {

	public static function init() {
		add_action( 'template_redirect', array( self::class, 'handle' ) );
	}

	public static function handle() {
		if ( ! isset( $_GET['saunaplan_email'] ) ) {
			return;
		}
		$id       = absint( $_GET['saunaplan_email'] );
		$key      = isset( $_GET['key'] ) ? sanitize_text_field( wp_unslash( $_GET['key'] ) ) : '';
		$resident = $id ? Repository::resident( $id ) : null;
		$title    = __( 'Confirm email address', 'saunaplan' );
		$back     = sprintf( '<p><a href="%s">%s</a></p>', esc_url( Settings::page_url() ), esc_html__( 'To the booking page', 'saunaplan' ) );

		if ( ! $resident || '' === $resident->email_pending || ! hash_equals( Notifications::email_key( $resident ), $key ) ) {
			self::page( $title, '<p>' . esc_html__( 'This link is no longer valid. Maybe the address was already confirmed or changed.', 'saunaplan' ) . '</p>' . $back, 404 );
		}

		if ( 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) && ! empty( $_POST['saunaplan_confirm'] ) ) {
			Repository::update_resident( $id, array( 'email' => strtolower( $resident->email_pending ), 'email_pending' => '' ) );
			self::page(
				__( 'Email address confirmed', 'saunaplan' ),
				'<p>' . esc_html(
					/* translators: %s: email address */
					sprintf( __( 'Thank you! From now on, booking mails go to %s.', 'saunaplan' ), $resident->email_pending )
				) . '</p>' . $back
			);
		}

		$form = '<form method="post"><input type="hidden" name="saunaplan_confirm" value="1">'
			. '<p><button type="submit" class="button button-large">' . esc_html__( 'Yes, confirm this address', 'saunaplan' ) . '</button></p></form>';
		self::page(
			$title,
			'<p>' . esc_html(
				/* translators: %s: email address */
				sprintf( __( 'Send booking confirmations and reminders to %s?', 'saunaplan' ), $resident->email_pending )
			) . '</p>' . $form . $back
		);
	}

	private static function page( string $title, string $html, int $status = 200 ) {
		nocache_headers();
		wp_die( '<h1>' . esc_html( $title ) . '</h1>' . $html, esc_html( $title ), array( 'response' => $status ) ); // phpcs:ignore WordPress.Security.EscapeOutput
	}
}
