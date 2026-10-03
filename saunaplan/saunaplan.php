<?php
/**
 * Plugin Name:       Saunaplan
 * Description:       Book the building's sauna: private or open sessions, waiting list, Aufguss, reminders with cooldown instructions, quick ratings and fair-use rules.
 * Version:           0.1.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       saunaplan
 * Domain Path:       /languages
 */

defined( 'ABSPATH' ) || exit;

define( 'SAUNAPLAN_VERSION', '0.1.0' );
define( 'SAUNAPLAN_FILE', __FILE__ );
define( 'SAUNAPLAN_DIR', plugin_dir_path( __FILE__ ) );
define( 'SAUNAPLAN_URL', plugin_dir_url( __FILE__ ) );

// Generic booking core: knows nothing about saunas (see tests/architecture-test.php).
foreach ( array(
	'interface-room-profile',
	'interface-rule',
	'interface-identity-provider',
	'class-settings',
	'class-i18n',
	'class-time',
	'class-room',
	'class-repository',
	'class-units',
	'auth/class-unit-code-provider',
	'class-sessions',
	'class-slots',
	'class-series',
	'class-schedule',
	'class-draft',
	'class-rules',
	'class-booking-service',
	'class-ratings',
	'class-mailer',
	'class-notifications',
	'class-scheduler',
	'class-rest-controller',
	'class-shortcode',
	'class-link-handler',
	'class-privacy',
	'class-unlisted',
	'class-installer',
	'class-plugin',
) as $saunaplan_file ) {
	require_once SAUNAPLAN_DIR . "includes/core/{$saunaplan_file}.php";
}

// The sauna: modes, people categories, Aufguss/keep warm, rating questions, instructions.
require_once SAUNAPLAN_DIR . 'includes/sauna/class-profile.php';

if ( is_admin() ) {
	require_once SAUNAPLAN_DIR . 'admin/class-admin.php';
}

add_filter(
	'saunaplan_room_profile',
	static function ( $profile ) {
		return $profile ?: new Saunaplan\Sauna\Profile();
	}
);

register_activation_hook( __FILE__, array( 'Saunaplan\\Core\\Installer', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Saunaplan\\Core\\Installer', 'deactivate' ) );

// Everything that may translate strings runs on init (WP 6.7+ warns about earlier translation loading).
add_action( 'init', array( 'Saunaplan\\Core\\Plugin', 'init' ), 0 );
