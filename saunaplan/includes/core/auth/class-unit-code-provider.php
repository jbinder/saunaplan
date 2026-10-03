<?php
namespace Saunaplan\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Login with the personal code of a flat (printed letter in the mailbox).
 *
 * Everyone in the flat uses the same code; every login is a new "person"
 * of that flat, so limits and ratings are counted per flat.
 */
class Unit_Code_Provider implements Identity_Provider {

	public function id(): string {
		return 'unit_code';
	}

	public function verify( array $input ) {
		$code = Units::normalize_code( (string) ( $input['code'] ?? '' ) );
		if ( strlen( $code ) !== Units::CODE_LENGTH ) {
			return new \WP_Error( 'saunaplan_code', __( 'Please enter the 8-character code from your letter.', 'saunaplan' ), array( 'status' => 400 ) );
		}
		$unit = Repository::unit_by_code_hash( Units::hash_code( $code ) );
		if ( ! $unit ) {
			return new \WP_Error( 'saunaplan_code', __( 'This code is not valid. Please check it, or ask the administrators for a new letter.', 'saunaplan' ), array( 'status' => 403 ) );
		}
		return array(
			'unit_id'     => (int) $unit->id,
			'external_id' => '',
		);
	}
}
