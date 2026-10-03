<?php
namespace Saunaplan\Core;

defined( 'ABSPATH' ) || exit;

/**
 * A booking rule. Returns an error to stop the booking, or null to allow it.
 */
interface Rule {

	/** @return \WP_Error|null */
	public function check( Draft $draft );
}
