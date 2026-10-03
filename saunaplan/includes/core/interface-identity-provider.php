<?php
namespace Saunaplan\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Proves that someone belongs to a unit (flat) of the building.
 *
 * Providers only answer "who is this?"; sessions, bookings and limits are
 * handled by the core. Further providers (e.g. an access-control system or
 * email codes) can be added via the saunaplan_identity_provider filter.
 */
interface Identity_Provider {

	/** Machine name stored with the resident. */
	public function id(): string;

	/**
	 * Check the login input.
	 *
	 * @param array $input Fields sent by the login form.
	 * @return array|\WP_Error On success: unit_id (int) and external_id
	 *                         (string, or '' when every login is a new person).
	 */
	public function verify( array $input );
}
