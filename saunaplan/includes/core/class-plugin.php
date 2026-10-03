<?php
namespace Saunaplan\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Bootstraps all plugin components.
 */
class Plugin {

	public static function init() {
		I18n::init();
		add_action( 'update_option_' . Settings::OPTION, array( Settings::class, 'flush' ) );
		add_action( 'change_locale', array( Settings::class, 'flush' ) );

		Installer::maybe_upgrade();
		Scheduler::init();
		Notifications::init();
		Rest_Controller::init();
		Shortcode::init();
		Link_Handler::init();
		Privacy::init();
		Unlisted::init();
		do_action( 'saunaplan_init', Room::profile() );

		if ( is_admin() ) {
			\Saunaplan\Admin\Admin::init();
		}
	}
}
