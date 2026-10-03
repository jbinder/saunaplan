<?php
/**
 * Printable code letters (one per page).
 *
 * @var array|false $codes unit ID => plain code
 * @var array       $units
 */

namespace Saunaplan\Admin;

use Saunaplan\Core\Room;
use Saunaplan\Core\Settings;
use Saunaplan\Core\Units;

defined( 'ABSPATH' ) || exit;

$room = Room::profile()->label();
$site = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
?>
<div class="wrap saunaplan-admin saunaplan-letters-wrap">
	<div class="saunaplan-noprint">
		<h1><?php esc_html_e( 'Code letters', 'saunaplan' ); ?></h1>
		<?php if ( ! is_array( $codes ) ) : ?>
			<div class="notice notice-error"><p><?php esc_html_e( 'These letters are no longer available. For security reasons, codes can only be printed within one hour after creating them. Please create new codes on the Flats screen.', 'saunaplan' ); ?></p></div>
			<p><a class="button" href="<?php echo esc_url( Admin::url( 'saunaplan-units' ) ); ?>"><?php esc_html_e( 'Back to the flats', 'saunaplan' ); ?></a></p>
		<?php else : ?>
			<p>
				<?php
				/* translators: %d: number of letters */
				echo esc_html( sprintf( _n( '%d letter is ready. Print it now and put it into the mailbox – the code can not be shown again later.', '%d letters are ready. Print them now and put them into the mailboxes – the codes can not be shown again later.', count( $codes ), 'saunaplan' ), count( $codes ) ) );
				?>
			</p>
			<p>
				<button type="button" class="button button-primary" onclick="window.print()"><?php esc_html_e( 'Print letters', 'saunaplan' ); ?></button>
				<a class="button" href="<?php echo esc_url( Admin::url( 'saunaplan-units' ) ); ?>"><?php esc_html_e( 'Back to the flats', 'saunaplan' ); ?></a>
			</p>
		<?php endif; ?>
	</div>

	<?php if ( is_array( $codes ) ) : ?>
		<div class="saunaplan-letters">
			<?php
			foreach ( $codes as $unit_id => $code ) :
				$unit = $units[ $unit_id ] ?? null;
				$url  = Units::login_url( $code );
				?>
				<section class="saunaplan-letter">
					<p class="saunaplan-letter-site"><?php echo esc_html( $site ); ?></p>
					<p class="saunaplan-letter-to"><?php echo esc_html( Units::label( $unit ) ); ?></p>
					<h2>
						<?php
						/* translators: %s: room name */
						echo esc_html( sprintf( __( 'Your access to the %s booking', 'saunaplan' ), $room ) );
						?>
					</h2>
					<p><?php esc_html_e( 'With this code, everyone in your flat can book online. Please keep this letter safe and do not share the code outside your flat.', 'saunaplan' ); ?></p>
					<div class="saunaplan-letter-code">
						<div class="saunaplan-qr" data-qr="<?php echo esc_attr( $url ); ?>"></div>
						<div>
							<p><?php esc_html_e( 'Scan the QR code with your phone camera – or open this address and enter the code:', 'saunaplan' ); ?></p>
							<p class="saunaplan-letter-url"><?php echo esc_html( Settings::page_url() ); ?></p>
							<p class="saunaplan-letter-codeline"><?php echo esc_html( Units::format_code( $code ) ); ?></p>
						</div>
					</div>
					<ol>
						<li><?php esc_html_e( 'Sign in once with the code – your phone or computer stays signed in.', 'saunaplan' ); ?></li>
						<li><?php esc_html_e( 'Tap a free time to book. Choose "private" or open the session for neighbours.', 'saunaplan' ); ?></li>
						<li><?php esc_html_e( 'Optional: add your email address to get reminders.', 'saunaplan' ); ?></li>
					</ol>
					<p class="saunaplan-letter-small"><?php esc_html_e( 'Privacy: bookings are anonymous. Other residents only see the kind of session and the number of people, never which flat booked.', 'saunaplan' ); ?></p>
				</section>
			<?php endforeach; ?>
		</div>
	<?php endif; ?>
</div>
