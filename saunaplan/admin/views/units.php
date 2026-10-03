<?php
/**
 * Flats screen: import, codes, devices.
 *
 * @var array $units
 * @var array $devices unit ID => signed-in residents
 * @var array $emails  unit ID => residents with email
 */

namespace Saunaplan\Admin;

use Saunaplan\Core\Time;
use Saunaplan\Core\Units;

defined( 'ABSPATH' ) || exit;

$post_url = admin_url( 'admin-post.php' );
$missing  = count( array_filter( $units, static function ( $u ) {
	return $u->active && empty( $u->code_hash );
} ) );
?>
<div class="wrap saunaplan-admin">
	<h1><?php esc_html_e( 'Flats', 'saunaplan' ); ?></h1>
	<?php Admin::page_link(); ?>

	<p class="saunaplan-intro"><?php esc_html_e( 'Every flat gets a personal code on a printed letter for its mailbox. Whoever has the letter can sign in – so only people living here get access, without email addresses or passwords. Codes are stored encrypted (hashed): they can only be printed right after creating them. If a letter is lost, simply create a new code.', 'saunaplan' ); ?></p>

	<div class="saunaplan-columns">
		<div class="saunaplan-box">
			<h2><?php esc_html_e( 'Add flats', 'saunaplan' ); ?></h2>
			<form method="post" action="<?php echo esc_url( $post_url ); ?>" enctype="multipart/form-data">
				<?php Admin::form_fields( 'saunaplan_import_units' ); ?>
				<p><label for="sp-units"><?php esc_html_e( 'One flat per line: stair and door, separated by a semicolon, comma or slash. You can paste a column pair from a spreadsheet.', 'saunaplan' ); ?></label></p>
				<textarea id="sp-units" name="units" rows="8" class="large-text code" placeholder="1;1&#10;1;2&#10;2;14&#10;3/7"></textarea>
				<p><label><?php esc_html_e( 'Or upload a CSV file:', 'saunaplan' ); ?> <input type="file" name="file" accept=".csv,.txt,text/csv,text/plain"></label></p>
				<p><label><input type="checkbox" name="codes" value="1" checked> <?php esc_html_e( 'Create codes for the new flats and show the letters for printing', 'saunaplan' ); ?></label></p>
				<p><button class="button button-primary"><?php esc_html_e( 'Add flats', 'saunaplan' ); ?></button></p>
			</form>
		</div>

		<div class="saunaplan-box">
			<h2><?php esc_html_e( 'Code letters', 'saunaplan' ); ?></h2>
			<form method="post" action="<?php echo esc_url( $post_url ); ?>">
				<?php Admin::form_fields( 'saunaplan_bulk_codes' ); ?>
				<input type="hidden" name="which" value="missing">
				<p>
					<?php
					/* translators: %d: number of flats */
					echo esc_html( sprintf( _n( '%d active flat has no code yet.', '%d active flats have no code yet.', $missing, 'saunaplan' ), $missing ) );
					?>
				</p>
				<p><button class="button" <?php disabled( 0, $missing ); ?>><?php esc_html_e( 'Create codes for these flats and print letters', 'saunaplan' ); ?></button></p>
			</form>
			<form method="post" action="<?php echo esc_url( $post_url ); ?>">
				<?php Admin::form_fields( 'saunaplan_bulk_codes' ); ?>
				<input type="hidden" name="which" value="all">
				<p class="description"><?php esc_html_e( 'New codes for all flats, e.g. once a year. Old codes stop working.', 'saunaplan' ); ?></p>
				<p><label><input type="checkbox" name="signout" value="1"> <?php esc_html_e( 'Also sign out all devices', 'saunaplan' ); ?></label></p>
				<p><button class="button saunaplan-confirm" data-confirm="<?php esc_attr_e( 'Create new codes for ALL flats? Every flat will need a new letter.', 'saunaplan' ); ?>" <?php disabled( 0, count( $units ) ); ?>><?php esc_html_e( 'New codes for all flats', 'saunaplan' ); ?></button></p>
			</form>
		</div>
	</div>

	<h2>
		<?php
		/* translators: %d: number of flats */
		echo esc_html( sprintf( __( 'All flats (%d)', 'saunaplan' ), count( $units ) ) );
		?>
	</h2>
	<?php if ( ! $units ) : ?>
		<p><?php esc_html_e( 'No flats yet. Add them above.', 'saunaplan' ); ?></p>
	<?php else : ?>
		<table class="widefat striped saunaplan-units">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Flat', 'saunaplan' ); ?></th>
					<th><?php esc_html_e( 'Code', 'saunaplan' ); ?></th>
					<th><?php esc_html_e( 'Signed-in devices', 'saunaplan' ); ?></th>
					<th><?php esc_html_e( 'With email reminders', 'saunaplan' ); ?></th>
					<th><?php esc_html_e( 'Actions', 'saunaplan' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $units as $unit ) : ?>
					<tr class="<?php echo $unit->active ? '' : 'is-inactive'; ?>">
						<td>
							<strong><?php echo esc_html( Units::label( $unit ) ); ?></strong>
							<?php if ( ! $unit->active ) : ?>
								<span class="saunaplan-tag"><?php esc_html_e( 'inactive', 'saunaplan' ); ?></span>
							<?php endif; ?>
						</td>
						<td>
							<?php
							echo $unit->code_hash
								/* translators: %s: date */
								? esc_html( sprintf( __( 'created %s', 'saunaplan' ), Time::format_datetime( strtotime( $unit->code_set_at . ' UTC' ) ) ) )
								: '<span class="saunaplan-warning">' . esc_html__( 'no code yet', 'saunaplan' ) . '</span>';
							?>
						</td>
						<td><?php echo esc_html( (string) ( $devices[ $unit->id ] ?? 0 ) ); ?></td>
						<td><?php echo esc_html( (string) ( $emails[ $unit->id ] ?? 0 ) ); ?></td>
						<td class="saunaplan-actions">
							<?php
							$actions = array(
								'code'    => array( $unit->code_hash ? __( 'New code + letter', 'saunaplan' ) : __( 'Create code + letter', 'saunaplan' ), $unit->code_hash ? __( 'Create a new code? The old letter stops working. Devices that are already signed in stay signed in.', 'saunaplan' ) : '' ),
								'signout' => array( __( 'Sign out devices', 'saunaplan' ), __( 'Sign out all devices of this flat?', 'saunaplan' ) ),
								'toggle'  => array( $unit->active ? __( 'Deactivate', 'saunaplan' ) : __( 'Activate', 'saunaplan' ), $unit->active ? __( 'Deactivate this flat? It can no longer sign in or book.', 'saunaplan' ) : '' ),
								'delete'  => array( __( 'Delete', 'saunaplan' ), __( 'Delete this flat? Its devices are signed out; past bookings stay anonymous.', 'saunaplan' ) ),
							);
							foreach ( $actions as $do => $action ) :
								?>
								<form method="post" action="<?php echo esc_url( $post_url ); ?>">
									<?php Admin::form_fields( 'saunaplan_unit_action' ); ?>
									<input type="hidden" name="unit_id" value="<?php echo esc_attr( $unit->id ); ?>">
									<input type="hidden" name="do" value="<?php echo esc_attr( $do ); ?>">
									<button class="button button-small<?php echo $action[1] ? ' saunaplan-confirm' : ''; ?><?php echo 'delete' === $do ? ' button-link-delete' : ''; ?>" <?php echo $action[1] ? 'data-confirm="' . esc_attr( $action[1] ) . '"' : ''; ?>><?php echo esc_html( $action[0] ); ?></button>
								</form>
							<?php endforeach; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>
</div>
