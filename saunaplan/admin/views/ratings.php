<?php
/**
 * Ratings: averages and the latest feedback.
 *
 * @var int   $days
 * @var array $ratings
 * @var array $summary
 * @var array $units
 */

namespace Saunaplan\Admin;

use Saunaplan\Core\Room;
use Saunaplan\Core\Settings;
use Saunaplan\Core\Time;
use Saunaplan\Core\Units;

defined( 'ABSPATH' ) || exit;

$questions = Room::profile()->rating_questions();
$faces     = array( 1 => '☹️', 2 => '😐', 3 => '😊' );
?>
<div class="wrap saunaplan-admin">
	<h1><?php esc_html_e( 'Ratings', 'saunaplan' ); ?></h1>

	<form method="get" class="saunaplan-filter">
		<input type="hidden" name="page" value="saunaplan-ratings">
		<label><?php esc_html_e( 'Period', 'saunaplan' ); ?>
			<select name="days" onchange="this.form.submit()">
				<?php foreach ( array( 30, 90, 365 ) as $n ) : ?>
					<option value="<?php echo esc_attr( $n ); ?>" <?php selected( $days, $n ); ?>>
						<?php
						/* translators: %d: number of days */
						echo esc_html( sprintf( __( 'last %d days', 'saunaplan' ), $n ) );
						?>
					</option>
				<?php endforeach; ?>
			</select>
		</label>
	</form>

	<div class="saunaplan-cards">
		<div class="saunaplan-stat">
			<span class="saunaplan-stat-value"><?php echo esc_html( (string) $summary['count'] ); ?></span>
			<span class="saunaplan-stat-label"><?php esc_html_e( 'Ratings', 'saunaplan' ); ?></span>
		</div>
		<?php foreach ( $questions['scales'] as $key => $label ) : ?>
			<div class="saunaplan-stat">
				<span class="saunaplan-stat-value"><?php echo isset( $summary['scales'][ $key ] ) ? esc_html( number_format_i18n( $summary['scales'][ $key ], 1 ) ) . ' <small>/ 3</small>' : '–'; ?></span>
				<span class="saunaplan-stat-label"><?php echo esc_html( $label ); ?></span>
			</div>
		<?php endforeach; ?>
		<?php foreach ( $summary['issues'] as $key => $count ) : ?>
			<div class="saunaplan-stat is-issue">
				<span class="saunaplan-stat-value"><?php echo esc_html( (string) $count ); ?>×</span>
				<span class="saunaplan-stat-label"><?php echo esc_html( $questions['issues'][ $key ] ?? $key ); ?></span>
			</div>
		<?php endforeach; ?>
	</div>

	<?php if ( ! $ratings ) : ?>
		<p><?php esc_html_e( 'No ratings in this period yet.', 'saunaplan' ); ?></p>
	<?php else : ?>
		<table class="widefat striped">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Date', 'saunaplan' ); ?></th>
					<th><?php esc_html_e( 'Flat', 'saunaplan' ); ?></th>
					<?php foreach ( $questions['scales'] as $label ) : ?>
						<th><?php echo esc_html( $label ); ?></th>
					<?php endforeach; ?>
					<th><?php esc_html_e( 'Issues', 'saunaplan' ); ?></th>
					<th><?php esc_html_e( 'Comment', 'saunaplan' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $ratings as $rating ) : ?>
					<tr class="<?php echo $rating->issues ? 'has-issues' : ''; ?>">
						<td><?php echo esc_html( Time::format_date( $rating->slot_date ) ); ?></td>
						<td><?php echo $rating->unit_id ? esc_html( Units::short_label( $units[ (int) $rating->unit_id ] ?? null ) ) : '<span class="description">' . esc_html__( 'anonymized', 'saunaplan' ) . '</span>'; ?></td>
						<?php foreach ( array_keys( $questions['scales'] ) as $key ) : ?>
							<td><?php echo isset( $rating->answers[ $key ] ) ? esc_html( $faces[ (int) $rating->answers[ $key ] ] ?? '' ) : '–'; ?></td>
						<?php endforeach; ?>
						<td>
							<?php
							echo $rating->issues
								? esc_html( implode( ', ', array_map( static function ( $i ) use ( $questions ) {
									return $questions['issues'][ $i ] ?? $i;
								}, $rating->issues ) ) )
								: ( $rating->quick ? '<span class="description">' . esc_html__( 'Everything was fine', 'saunaplan' ) . '</span>' : '–' );
							?>
						</td>
						<td><?php echo '' !== (string) $rating->comment ? esc_html( $rating->comment ) : '–'; ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<p class="description">
			<?php
			/* translators: %d: days */
			echo esc_html( sprintf( __( 'After %d days the link to the flat is removed (see Settings → Privacy); the answers stay for statistics.', 'saunaplan' ), (int) Settings::get( 'retention_days' ) ) );
			?>
		</p>
	<?php endif; ?>
</div>
