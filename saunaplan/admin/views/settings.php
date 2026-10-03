<?php
/**
 * Settings screen.
 *
 * @var array $settings
 */

namespace Saunaplan\Admin;

use Saunaplan\Core\I18n;
use Saunaplan\Core\Mailer;
use Saunaplan\Core\Room;
use Saunaplan\Core\Scheduler;
use Saunaplan\Core\Settings;
use Saunaplan\Core\Time;

defined( 'ABSPATH' ) || exit;

$opt   = Settings::OPTION;
$field = static function ( string $key ) use ( $opt ) {
	return $opt . '[' . $key . ']';
};
$number = static function ( string $key, int $min, int $max, string $suffix = '' ) use ( $field, $settings ) {
	printf(
		'<input type="number" id="sp-%1$s" name="%2$s" min="%3$d" max="%4$d" class="small-text" value="%5$s"> %6$s',
		esc_attr( $key ),
		esc_attr( $field( $key ) ),
		(int) $min,
		(int) $max,
		esc_attr( (string) $settings[ $key ] ),
		esc_html( $suffix )
	);
};
$next_run = wp_next_scheduled( Scheduler::HOOK );
?>
<div class="wrap saunaplan-admin">
	<h1><?php esc_html_e( 'Settings', 'saunaplan' ); ?></h1>
	<?php Admin::page_link(); ?>

	<form method="post" action="options.php">
		<?php settings_fields( 'saunaplan_settings_group' ); ?>

		<h2 class="title"><?php esc_html_e( 'General', 'saunaplan' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="sp-language"><?php esc_html_e( 'Language', 'saunaplan' ); ?></label></th>
				<td>
					<select id="sp-language" name="<?php echo esc_attr( $field( 'language' ) ); ?>">
						<option value="" <?php selected( $settings['language'], '' ); ?>>
							<?php
							/* translators: %s: language name, e.g. "English" */
							printf( esc_html__( 'Same as WordPress (currently %s)', 'saunaplan' ), esc_html( I18n::languages()[ I18n::closest( get_locale() ) ] ) );
							?>
						</option>
						<?php foreach ( I18n::languages() as $locale => $name ) : ?>
							<option value="<?php echo esc_attr( $locale ); ?>" <?php selected( $settings['language'], $locale ); ?>><?php echo esc_html( $name ); ?></option>
						<?php endforeach; ?>
					</select>
					<p class="description"><?php esc_html_e( 'Language of the booking page, the emails and these admin screens. Email texts you have not changed switch along.', 'saunaplan' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="sp-page"><?php esc_html_e( 'Booking page', 'saunaplan' ); ?></label></th>
				<td>
					<?php
					wp_dropdown_pages(
						array(
							'name'              => esc_attr( $field( 'page_id' ) ),
							'id'                => 'sp-page',
							'selected'          => (int) $settings['page_id'],
							'show_option_none'  => esc_html__( '— Select —', 'saunaplan' ),
							'option_none_value' => 0,
							'post_status'       => array( 'publish', 'draft', 'private' ),
						)
					);
					?>
					<p class="description">
						<?php
						/* translators: %s: shortcode */
						printf( esc_html__( 'The page that contains the shortcode %s. Its address can be hard to guess, e.g. /sauna-k7q2/.', 'saunaplan' ), '<code>[saunaplan]</code>' );
						?>
					</p>
					<p><label><input type="checkbox" name="<?php echo esc_attr( $field( 'unlisted' ) ); ?>" value="1" <?php checked( $settings['unlisted'], 1 ); ?>> <?php esc_html_e( 'Keep the booking page unlisted (not in menus, site search, sitemap; noindex for search engines)', 'saunaplan' ); ?></label></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="sp-from-name"><?php esc_html_e( 'Email sender', 'saunaplan' ); ?></label></th>
				<td>
					<input id="sp-from-name" name="<?php echo esc_attr( $field( 'from_name' ) ); ?>" class="regular-text" value="<?php echo esc_attr( $settings['from_name'] ); ?>" placeholder="<?php esc_attr_e( 'Name', 'saunaplan' ); ?>">
					<input name="<?php echo esc_attr( $field( 'from_email' ) ); ?>" type="email" class="regular-text" value="<?php echo esc_attr( $settings['from_email'] ); ?>" placeholder="<?php esc_attr_e( 'Address', 'saunaplan' ); ?>" aria-label="<?php esc_attr_e( 'Sender address', 'saunaplan' ); ?>">
					<p class="description"><?php esc_html_e( 'Leave empty to use the WordPress defaults.', 'saunaplan' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="sp-admins"><?php esc_html_e( 'Feedback recipients', 'saunaplan' ); ?></label></th>
				<td>
					<textarea id="sp-admins" name="<?php echo esc_attr( $field( 'admin_recipients' ) ); ?>" rows="2" class="large-text"><?php echo esc_textarea( $settings['admin_recipients'] ); ?></textarea>
					<p class="description"><?php esc_html_e( 'Email addresses that receive ratings with problems or comments (one per line).', 'saunaplan' ); ?></p>
				</td>
			</tr>
		</table>

		<h2 class="title"><?php esc_html_e( 'Opening hours and slots', 'saunaplan' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="sp-slot-minutes"><?php esc_html_e( 'Slot length', 'saunaplan' ); ?></label></th>
				<td>
					<select id="sp-slot-minutes" name="<?php echo esc_attr( $field( 'slot_minutes' ) ); ?>">
						<?php foreach ( array( 30, 45, 60, 90, 120 ) as $m ) : ?>
							<option value="<?php echo esc_attr( $m ); ?>" <?php selected( (int) $settings['slot_minutes'], $m ); ?>>
								<?php
								/* translators: %d: minutes */
								echo esc_html( sprintf( __( '%d minutes', 'saunaplan' ), $m ) );
								?>
							</option>
						<?php endforeach; ?>
					</select>
					<p class="description"><?php esc_html_e( 'Existing bookings keep their length when you change this.', 'saunaplan' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Bookable hours', 'saunaplan' ); ?></th>
				<td>
					<table class="saunaplan-hours">
						<?php foreach ( Time::weekday_names() as $num => $name ) : ?>
							<tr>
								<th scope="row"><?php echo esc_html( $name ); ?></th>
								<td>
									<input type="time" name="<?php echo esc_attr( $field( 'hours' ) . '[' . $num . '][from]' ); ?>" value="<?php echo esc_attr( $settings['hours'][ $num ]['from'] ?? '' ); ?>" aria-label="<?php echo esc_attr( $name . ' – ' . __( 'from', 'saunaplan' ) ); ?>">
									–
									<input type="text" pattern="([01]\d|2[0-3]):[0-5]\d|24:00" placeholder="23:00" size="5" name="<?php echo esc_attr( $field( 'hours' ) . '[' . $num . '][to]' ); ?>" value="<?php echo esc_attr( $settings['hours'][ $num ]['to'] ?? '' ); ?>" aria-label="<?php echo esc_attr( $name . ' – ' . __( 'until', 'saunaplan' ) ); ?>">
								</td>
							</tr>
						<?php endforeach; ?>
					</table>
					<p class="description"><?php esc_html_e( 'E.g. 08:00 – 23:00 gives hourly slots from 8:00 to 23:00 (last slot 22:00–23:00). Use 24:00 for midnight. Leave a day empty to close it.', 'saunaplan' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="sp-closed"><?php esc_html_e( 'Closed dates', 'saunaplan' ); ?></label></th>
				<td>
					<textarea id="sp-closed" name="<?php echo esc_attr( $field( 'closed_dates' ) ); ?>" rows="3" class="regular-text code" placeholder="2026-12-24"><?php echo esc_textarea( $settings['closed_dates'] ); ?></textarea>
					<p class="description"><?php esc_html_e( 'One date per line (YYYY-MM-DD), e.g. for maintenance. For regular blocks use a recurring session "Not available".', 'saunaplan' ); ?></p>
				</td>
			</tr>
		</table>

		<h2 class="title"><?php esc_html_e( 'Fair-use rules', 'saunaplan' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="sp-capacity"><?php esc_html_e( 'Maximum people at once', 'saunaplan' ); ?></label></th>
				<td><?php $number( 'capacity', 1, 50 ); ?></td>
			</tr>
			<tr>
				<th scope="row"><label for="sp-horizon_days"><?php esc_html_e( 'Book ahead', 'saunaplan' ); ?></label></th>
				<td><?php $number( 'horizon_days', 1, 60, __( 'days', 'saunaplan' ) ); ?></td>
			</tr>
			<tr>
				<th scope="row"><label for="sp-monthly_limit"><?php esc_html_e( 'Monthly limit per flat', 'saunaplan' ); ?></label></th>
				<td>
					<?php $number( 'monthly_limit', 0, 100 ); ?>
					<p class="description">
						<?php
						$limited = array();
						foreach ( Room::modes() as $mode ) {
							if ( ! empty( $mode['counts_toward_limit'] ) ) {
								$limited[] = $mode['label'];
							}
						}
						/* translators: %s: names of booking kinds */
						echo esc_html( sprintf( __( 'Counts these bookings: %s. Joining or opening a session for others does not count. 0 = no limit.', 'saunaplan' ), implode( ', ', $limited ) ) );
						?>
					</p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="sp-max_consecutive"><?php esc_html_e( 'Slots in a row', 'saunaplan' ); ?></label></th>
				<td>
					<?php $number( 'max_consecutive', 0, 24 ); ?>
					<p class="description"><?php esc_html_e( 'How many directly adjacent slots one flat may book or join. 0 = no limit.', 'saunaplan' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Rating', 'saunaplan' ); ?></th>
				<td><label><input type="checkbox" name="<?php echo esc_attr( $field( 'rating_gate' ) ); ?>" value="1" <?php checked( $settings['rating_gate'], 1 ); ?>> <?php esc_html_e( 'Ask for a rating of the last session before the next booking (one tap "everything was fine" is enough)', 'saunaplan' ); ?></label></td>
			</tr>
		</table>

		<h2 class="title"><?php esc_html_e( 'Reminders and instructions', 'saunaplan' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="sp-reminder_hours"><?php esc_html_e( 'Reminder', 'saunaplan' ); ?></label></th>
				<td>
					<?php $number( 'reminder_hours', 0, 72, __( 'hours before the session (0 = no reminder)', 'saunaplan' ) ); ?>
				</td>
			</tr>
			<?php Room::profile()->render_settings( $settings, $opt ); ?>
		</table>

		<h2 class="title"><?php esc_html_e( 'Privacy', 'saunaplan' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="sp-retention_days"><?php esc_html_e( 'Delete old bookings after', 'saunaplan' ); ?></label></th>
				<td>
					<?php $number( 'retention_days', 7, 3650, __( 'days', 'saunaplan' ) ); ?>
					<p class="description"><?php esc_html_e( 'Bookings and waiting lists are deleted after this time; ratings are kept without the flat.', 'saunaplan' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="sp-session_days"><?php esc_html_e( 'Stay signed in for', 'saunaplan' ); ?></label></th>
				<td><?php $number( 'session_days', 1, 730, __( 'days (extended with every visit)', 'saunaplan' ) ); ?></td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Uninstall', 'saunaplan' ); ?></th>
				<td><label><input type="checkbox" name="<?php echo esc_attr( $field( 'delete_data' ) ); ?>" value="1" <?php checked( $settings['delete_data'], 1 ); ?>> <?php esc_html_e( 'Delete all data when the plugin is deleted', 'saunaplan' ); ?></label></td>
			</tr>
		</table>

		<h2 class="title"><?php esc_html_e( 'Email texts', 'saunaplan' ); ?></h2>
		<p><?php esc_html_e( 'Placeholders:', 'saunaplan' ); ?></p>
		<ul class="saunaplan-placeholders">
			<?php foreach ( Mailer::placeholders() as $ph => $desc ) : ?>
				<li><code><?php echo esc_html( $ph ); ?></code> – <?php echo esc_html( $desc ); ?></li>
			<?php endforeach; ?>
		</ul>
		<?php foreach ( Mailer::template_labels() as $key => $label ) : ?>
			<details class="saunaplan-template">
				<summary><?php echo esc_html( $label ); ?></summary>
				<p><label><?php esc_html_e( 'Subject', 'saunaplan' ); ?><br><input class="large-text" name="<?php echo esc_attr( $field( "tpl_{$key}_subject" ) ); ?>" value="<?php echo esc_attr( $settings[ "tpl_{$key}_subject" ] ); ?>"></label></p>
				<p><label><?php esc_html_e( 'Text', 'saunaplan' ); ?><br><textarea class="large-text" rows="9" name="<?php echo esc_attr( $field( "tpl_{$key}_body" ) ); ?>"><?php echo esc_textarea( $settings[ "tpl_{$key}_body" ] ); ?></textarea></label></p>
			</details>
		<?php endforeach; ?>

		<?php submit_button(); ?>
	</form>

	<p class="description">
		<?php
		echo esc_html(
			$next_run
				/* translators: %s: date and time */
				? sprintf( __( 'Background jobs (reminders, rating requests, cleanup) run every 15 minutes. Next run: %s.', 'saunaplan' ), Time::format_datetime( $next_run ) )
				: __( 'Background jobs are not scheduled – deactivate and activate the plugin.', 'saunaplan' )
		);
		?>
	</p>
</div>
