<?php
/**
 * Recurring sessions: list and form.
 *
 * @var object|null $editing
 * @var bool        $new
 * @var array       $list
 */

namespace Saunaplan\Admin;

use Saunaplan\Core\Room;
use Saunaplan\Core\Settings;
use Saunaplan\Core\Time;

defined( 'ABSPATH' ) || exit;

$post_url = admin_url( 'admin-post.php' );
$show     = $editing || $new;
$values   = $editing ? (array) $editing : array(
	'id'         => 0,
	'title'      => '',
	'mode'       => '',
	'attributes' => array(),
	'weekdays'   => array(),
	'interval_n' => 1,
	'start_date' => Time::today(),
	'end_date'   => null,
	'start_time' => '18:00',
	'slots'      => 2,
	'active'     => true,
);
$minutes  = (int) Settings::get( 'slot_minutes' );
$times    = array();
for ( $m = 0; $m < 1440; $m += $minutes ) {
	$times[] = Time::hhmm( $m );
}
?>
<div class="wrap saunaplan-admin">
	<h1 class="wp-heading-inline"><?php esc_html_e( 'Recurring sessions', 'saunaplan' ); ?></h1>
	<?php if ( ! $show ) : ?>
		<a class="page-title-action" href="<?php echo esc_url( Admin::url( 'saunaplan-series', array( 'new' => 1 ) ) ); ?>"><?php esc_html_e( 'Add recurring session', 'saunaplan' ); ?></a>
	<?php endif; ?>
	<hr class="wp-header-end">

	<p class="saunaplan-intro"><?php esc_html_e( 'Predefined bookings that repeat every week, e.g. an open session for women on Tuesday evenings, or "not available" for the weekly cleaning. They do not count toward any flat\'s limit. Open sessions can be joined by residents.', 'saunaplan' ); ?></p>

	<?php if ( $show ) : ?>
		<form method="post" action="<?php echo esc_url( $post_url ); ?>" class="saunaplan-series-form">
			<?php Admin::form_fields( 'saunaplan_save_series' ); ?>
			<input type="hidden" name="id" value="<?php echo esc_attr( $values['id'] ); ?>">
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="sp-title"><?php esc_html_e( 'Title (optional)', 'saunaplan' ); ?></label></th>
					<td><input id="sp-title" name="title" class="regular-text" maxlength="100" value="<?php echo esc_attr( $values['title'] ); ?>">
						<p class="description"><?php esc_html_e( 'Shown to residents, e.g. "Ladies\' evening" or "Cleaning".', 'saunaplan' ); ?></p></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Kind of session', 'saunaplan' ); ?></th>
					<td>
						<?php foreach ( Room::modes() as $key => $mode ) : ?>
							<label class="saunaplan-radio"><input type="radio" name="mode" value="<?php echo esc_attr( $key ); ?>" <?php checked( $values['mode'], $key ); ?> required> <?php echo esc_html( trim( $mode['icon'] . ' ' . $mode['label'] ) ); ?> <span class="description"><?php echo esc_html( $mode['description'] ); ?></span></label><br>
						<?php endforeach; ?>
					</td>
				</tr>
				<?php if ( Room::profile()->fields() ) : ?>
					<tr>
						<th scope="row"><?php esc_html_e( 'Options', 'saunaplan' ); ?></th>
						<td>
							<?php foreach ( Room::profile()->fields() as $key => $field ) : ?>
								<label data-modes="<?php echo esc_attr( implode( ' ', $field['modes'] ?? array() ) ); ?>" class="saunaplan-field-option"><input type="checkbox" name="attributes[<?php echo esc_attr( $key ); ?>]" value="1" <?php checked( ! empty( $values['attributes'][ $key ] ) ); ?>> <?php echo esc_html( trim( ( $field['icon'] ?? '' ) . ' ' . $field['label'] ) ); ?></label><br>
							<?php endforeach; ?>
							<p class="description"><?php esc_html_e( 'Options that do not fit the chosen kind of session are ignored.', 'saunaplan' ); ?></p>
						</td>
					</tr>
				<?php endif; ?>
				<tr>
					<th scope="row"><?php esc_html_e( 'Weekdays', 'saunaplan' ); ?></th>
					<td>
						<?php foreach ( Time::weekday_names() as $num => $name ) : ?>
							<label class="saunaplan-weekday"><input type="checkbox" name="weekdays[]" value="<?php echo esc_attr( $num ); ?>" <?php checked( in_array( $num, (array) $values['weekdays'], true ) ); ?>> <?php echo esc_html( $name ); ?></label>
						<?php endforeach; ?>
						<p>
							<label><?php esc_html_e( 'Every', 'saunaplan' ); ?> <input type="number" name="interval_n" min="1" max="52" class="small-text" value="<?php echo esc_attr( $values['interval_n'] ); ?>"> <?php esc_html_e( 'week(s)', 'saunaplan' ); ?></label>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="sp-start-time"><?php esc_html_e( 'Time', 'saunaplan' ); ?></label></th>
					<td>
						<select id="sp-start-time" name="start_time">
							<?php foreach ( $times as $time ) : ?>
								<option value="<?php echo esc_attr( $time ); ?>" <?php selected( $values['start_time'], $time ); ?>><?php echo esc_html( $time ); ?></option>
							<?php endforeach; ?>
						</select>
						<label><?php esc_html_e( 'for', 'saunaplan' ); ?> <input type="number" name="slots" min="1" max="24" class="small-text" value="<?php echo esc_attr( $values['slots'] ); ?>">
							<?php
							/* translators: %d: slot length in minutes */
							echo esc_html( sprintf( __( 'slot(s) of %d minutes', 'saunaplan' ), $minutes ) );
							?>
						</label>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="sp-start-date"><?php esc_html_e( 'Period', 'saunaplan' ); ?></label></th>
					<td>
						<label><?php esc_html_e( 'from', 'saunaplan' ); ?> <input type="date" id="sp-start-date" name="start_date" value="<?php echo esc_attr( $values['start_date'] ); ?>" required></label>
						<label><?php esc_html_e( 'until (optional)', 'saunaplan' ); ?> <input type="date" name="end_date" value="<?php echo esc_attr( (string) $values['end_date'] ); ?>"></label>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Active', 'saunaplan' ); ?></th>
					<td><label><input type="checkbox" name="active" value="1" <?php checked( ! empty( $values['active'] ) ); ?>> <?php esc_html_e( 'Show this recurring session', 'saunaplan' ); ?></label></td>
				</tr>
			</table>
			<p class="submit">
				<button class="button button-primary"><?php esc_html_e( 'Save', 'saunaplan' ); ?></button>
				<a class="button" href="<?php echo esc_url( Admin::url( 'saunaplan-series' ) ); ?>"><?php esc_html_e( 'Cancel', 'saunaplan' ); ?></a>
			</p>
		</form>
	<?php endif; ?>

	<?php if ( ! $show ) : ?>
		<?php if ( ! $list ) : ?>
			<p><?php esc_html_e( 'No recurring sessions yet.', 'saunaplan' ); ?></p>
		<?php else : ?>
			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Session', 'saunaplan' ); ?></th>
						<th><?php esc_html_e( 'When', 'saunaplan' ); ?></th>
						<th><?php esc_html_e( 'Actions', 'saunaplan' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $list as $series ) : ?>
						<tr class="<?php echo $series->active ? '' : 'is-inactive'; ?>">
							<td>
								<strong><?php echo esc_html( Admin::mode_label( $series->mode ) ); ?></strong>
								<?php if ( $series->title ) : ?>
									– <?php echo esc_html( $series->title ); ?>
								<?php endif; ?>
								<?php foreach ( Room::profile()->badges( array( 'attributes' => $series->attributes ) ) as $badge ) : ?>
									<span class="saunaplan-badge"><?php echo esc_html( $badge['icon'] . ' ' . $badge['label'] ); ?></span>
								<?php endforeach; ?>
								<?php if ( ! $series->active ) : ?>
									<span class="saunaplan-tag"><?php esc_html_e( 'inactive', 'saunaplan' ); ?></span>
								<?php endif; ?>
							</td>
							<td><?php echo esc_html( Admin::describe_series( $series ) ); ?></td>
							<td class="saunaplan-actions">
								<a class="button button-small" href="<?php echo esc_url( Admin::url( 'saunaplan-series', array( 'id' => $series->id ) ) ); ?>"><?php esc_html_e( 'Edit', 'saunaplan' ); ?></a>
								<form method="post" action="<?php echo esc_url( $post_url ); ?>">
									<?php Admin::form_fields( 'saunaplan_delete_series' ); ?>
									<input type="hidden" name="id" value="<?php echo esc_attr( $series->id ); ?>">
									<button class="button button-small button-link-delete saunaplan-confirm" data-confirm="<?php esc_attr_e( 'Delete this recurring session? Upcoming dates are removed; people who joined them are not notified automatically.', 'saunaplan' ); ?>"><?php esc_html_e( 'Delete', 'saunaplan' ); ?></button>
								</form>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
	<?php endif; ?>
</div>
