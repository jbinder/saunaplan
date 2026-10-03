<?php
/**
 * Dev only: booking page, sample flats with codes, a recurring session.
 *   docker compose run --rm -v "$PWD/dev:/dev-seed" cli wp eval-file /dev-seed/seed.php
 */

use Saunaplan\Core\Repository;
use Saunaplan\Core\Settings;
use Saunaplan\Core\Units;

$page_id = (int) Settings::get( 'page_id' );
if ( ! $page_id || ! get_post( $page_id ) ) {
	$page_id = wp_insert_post(
		array(
			'post_title'   => 'Sauna',
			'post_name'    => 'sauna-k7q2',
			'post_status'  => 'publish',
			'post_type'    => 'page',
			'post_content' => '<!-- wp:shortcode -->[saunaplan]<!-- /wp:shortcode -->',
		)
	);
}
$settings            = get_option( Settings::OPTION, array() );
$settings            = is_array( $settings ) ? $settings : array();
$settings['page_id'] = $page_id;
$settings['unlisted'] = 1;
update_option( Settings::OPTION, $settings );
Settings::flush();

$ids = Units::import( Units::parse_import( "1;1\n1;2\n1;3\n2;1\n2;2\n2;14" )['units'] );
if ( $ids ) {
	foreach ( Units::issue_codes( $ids ) as $id => $code ) {
		$unit = Repository::unit( $id );
		WP_CLI::line( Units::short_label( $unit ) . "\t" . Units::format_code( $code ) . "\t" . Units::login_url( $code ) );
	}
}

if ( ! Repository::all_series() ) {
	Repository::save_series(
		array(
			'title'      => 'Ladies evening',
			'mode'       => 'open_female',
			'attributes' => array( 'aufguss' => true ),
			'weekdays'   => array( 2 ),
			'interval_n' => 1,
			'start_date' => gmdate( 'Y-m-d' ),
			'end_date'   => null,
			'start_time' => '19:00:00',
			'slots'      => 2,
			'active'     => 1,
		)
	);
}
WP_CLI::success( 'Seeded. Page: ' . get_permalink( $page_id ) );
