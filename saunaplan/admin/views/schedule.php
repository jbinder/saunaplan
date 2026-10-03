<?php
/**
 * Schedule screen: all slots with flats (only admins see who booked).
 *
 * @var string $from
 * @var string $to
 * @var int    $days
 * @var array  $slots date => time => slot
 * @var array  $units
 */

namespace Saunaplan\Admin;

use Saunaplan\Core\Room;
use Saunaplan\Core\Schedule;
use Saunaplan\Core\Time;
use Saunaplan\Core\Units;

defined( 'ABSPATH' ) || exit;

$unit_label = static function ( int $id ) use ( $units ) {
	return Units::short_label( $units[ $id ] ?? null );
};
?>
<div class="wrap saunaplan-admin">
	<h1><?php esc_html_e( 'Schedule', 'saunaplan' ); ?></h1>
	<?php Admin::page_link(); ?>

	<div class="saunaplan-toolbar">
		<div class="saunaplan-nav">
			<a class="button" href="<?php echo esc_url( Admin::url( 'saunaplan', array( 'from' => Time::add_days( $from, -$days ), 'days' => $days ) ) ); ?>">&larr; <?php esc_html_e( 'Earlier', 'saunaplan' ); ?></a>
			<a class="button" href="<?php echo esc_url( Admin::url( 'saunaplan', array( 'days' => $days ) ) ); ?>"><?php esc_html_e( 'Today', 'saunaplan' ); ?></a>
			<a class="button" href="<?php echo esc_url( Admin::url( 'saunaplan', array( 'from' => Time::add_days( $from, $days ), 'days' => $days ) ) ); ?>"><?php esc_html_e( 'Later', 'saunaplan' ); ?> &rarr;</a>
			<strong class="saunaplan-period"><?php echo esc_html( Time::format_date( $from ) . ( $days > 1 ? ' – ' . Time::format_date( $to ) : '' ) ); ?></strong>
		</div>
		<form method="get" class="saunaplan-filter">
			<input type="hidden" name="page" value="saunaplan">
			<input type="date" name="from" value="<?php echo esc_attr( $from ); ?>" aria-label="<?php esc_attr_e( 'From', 'saunaplan' ); ?>">
			<select name="days" aria-label="<?php esc_attr_e( 'Period', 'saunaplan' ); ?>">
				<?php foreach ( array( 1, 7, 14, 28 ) as $n ) : ?>
					<option value="<?php echo esc_attr( $n ); ?>" <?php selected( $days, $n ); ?>>
						<?php
						/* translators: %d: number of days */
						echo esc_html( sprintf( _n( '%d day', '%d days', $n, 'saunaplan' ), $n ) );
						?>
					</option>
				<?php endforeach; ?>
			</select>
			<button class="button"><?php esc_html_e( 'Show', 'saunaplan' ); ?></button>
		</form>
	</div>

	<?php if ( ! $slots ) : ?>
		<p><?php esc_html_e( 'No opening hours in this period.', 'saunaplan' ); ?></p>
	<?php endif; ?>

	<?php foreach ( $slots as $date => $day ) : ?>
		<h2 class="saunaplan-day"><?php echo esc_html( Time::format_long_date( $date ) ); ?></h2>
		<table class="widefat striped saunaplan-schedule">
			<thead>
				<tr>
					<th class="column-time"><?php esc_html_e( 'Time', 'saunaplan' ); ?></th>
					<th><?php esc_html_e( 'Booking', 'saunaplan' ); ?></th>
					<th><?php esc_html_e( 'People (flat)', 'saunaplan' ); ?></th>
					<th><?php esc_html_e( 'Waiting list', 'saunaplan' ); ?></th>
					<th class="column-actions"><?php esc_html_e( 'Actions', 'saunaplan' ); ?></th>
				</tr>
			</thead>
			<tbody>
			<?php
			$free = 0;
			foreach ( $day as $time => $slot ) :
				$occ = Schedule::occupancy( $slot );
				if ( ! $occ && ! $slot['waitlist'] ) {
					$free++;
					continue;
				}
				$booking = $slot['booking'];
				$end     = Time::hhmm( Time::minutes( (string) $time ) + (int) $slot['duration'] );
				?>
				<tr class="<?php echo esc_attr( $occ ? 'is-booked mode-' . $occ['mode'] : 'is-free' ); ?>">
					<td class="column-time"><strong><?php echo esc_html( $time . '–' . $end ); ?></strong></td>
					<td>
						<?php if ( $occ ) : ?>
							<?php echo esc_html( Admin::mode_label( $occ['mode'] ) ); ?>
							<?php if ( $occ['title'] ) : ?>
								<br><em><?php echo esc_html( $occ['title'] ); ?></em>
							<?php endif; ?>
							<?php foreach ( Room::profile()->badges( $occ ) as $badge ) : ?>
								<span class="saunaplan-badge"><?php echo esc_html( $badge['icon'] . ' ' . $badge['label'] ); ?></span>
							<?php endforeach; ?>
							<?php if ( $slot['series'] || ( $booking && $booking->series_id ) ) : ?>
								<br><span class="description"><?php esc_html_e( 'Recurring session', 'saunaplan' ); ?></span>
							<?php elseif ( $booking ) : ?>
								<br><span class="description">
									<?php
									/* translators: %s: flat */
									echo esc_html( sprintf( __( 'Booked by %s', 'saunaplan' ), $unit_label( $booking->unit_id ) ) );
									?>
								</span>
							<?php endif; ?>
						<?php else : ?>
							<span class="description"><?php esc_html_e( 'Free', 'saunaplan' ); ?></span>
						<?php endif; ?>
					</td>
					<td>
						<?php if ( $slot['participants'] ) : ?>
							<ul class="saunaplan-list">
								<?php foreach ( $slot['participants'] as $p ) : ?>
									<li><?php echo esc_html( $unit_label( $p->unit_id ) . ': ' . ( Room::format_counts( $p->counts ) ?: '–' ) ); ?></li>
								<?php endforeach; ?>
							</ul>
						<?php else : ?>
							–
						<?php endif; ?>
					</td>
					<td>
						<?php
						echo $slot['waitlist']
							? esc_html( implode( ', ', array_map( static function ( $w ) use ( $unit_label ) {
								return $unit_label( (int) $w->unit_id );
							}, $slot['waitlist'] ) ) )
							: '–';
						?>
					</td>
					<td class="column-actions">
						<?php if ( $booking ) : ?>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
								<?php Admin::form_fields( 'saunaplan_cancel_booking' ); ?>
								<input type="hidden" name="booking_id" value="<?php echo esc_attr( $booking->id ); ?>">
								<button class="button button-small saunaplan-confirm" data-confirm="<?php esc_attr_e( 'Cancel this booking? People who joined and the waiting list will be notified.', 'saunaplan' ); ?>"><?php esc_html_e( 'Cancel', 'saunaplan' ); ?></button>
							</form>
						<?php elseif ( $slot['series'] ) : ?>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
								<?php Admin::form_fields( 'saunaplan_skip_series' ); ?>
								<input type="hidden" name="series_id" value="<?php echo esc_attr( $slot['series']->id ); ?>">
								<input type="hidden" name="date" value="<?php echo esc_attr( $date ); ?>">
								<input type="hidden" name="time" value="<?php echo esc_attr( $time ); ?>">
								<button class="button button-small saunaplan-confirm" data-confirm="<?php esc_attr_e( 'Cancel the recurring session on this date? The slot becomes free for bookings.', 'saunaplan' ); ?>"><?php esc_html_e( 'Skip this date', 'saunaplan' ); ?></button>
							</form>
						<?php endif; ?>
					</td>
				</tr>
			<?php endforeach; ?>
			<?php if ( $free ) : ?>
				<tr class="is-free">
					<td colspan="5" class="description">
						<?php
						/* translators: %d: number of free slots */
						echo esc_html( sprintf( _n( '%d free slot', '%d free slots', $free, 'saunaplan' ), $free ) );
						?>
					</td>
				</tr>
			<?php endif; ?>
			</tbody>
		</table>
	<?php endforeach; ?>
</div>
