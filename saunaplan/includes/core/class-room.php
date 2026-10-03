<?php
namespace Saunaplan\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Access to the active room profile, plus helpers around its modes.
 */
class Room {

	/** Mode for admin-blocked slots (maintenance, cleaning …). Not bookable. */
	const BLOCKED = '_blocked';

	/** @var Room_Profile|null */
	private static $profile = null;

	public static function profile(): Room_Profile {
		if ( null === self::$profile ) {
			$profile = apply_filters( 'saunaplan_room_profile', null );
			if ( ! $profile instanceof Room_Profile ) {
				throw new \RuntimeException( 'No room profile registered (saunaplan_room_profile filter).' );
			}
			self::$profile = $profile;
		}
		return self::$profile;
	}

	/** Use a different profile (tests). */
	public static function set_profile( ?Room_Profile $profile ): void {
		self::$profile = $profile;
	}

	/** Profile modes plus the internal "blocked" mode. */
	public static function modes(): array {
		$modes = array();
		foreach ( self::profile()->modes() as $key => $mode ) {
			$modes[ $key ] = wp_parse_args(
				$mode,
				array(
					'label'               => $key,
					'short'               => $mode['label'] ?? $key,
					'description'         => '',
					'icon'                => '',
					'joinable'            => false,
					'counts_toward_limit' => false,
					'requires_people'     => false,
					'categories'          => array_keys( self::profile()->categories() ),
				)
			);
		}
		$modes[ self::BLOCKED ] = array(
			'label'               => __( 'Not available', 'saunaplan' ),
			'short'               => __( 'Not available', 'saunaplan' ),
			'description'         => __( 'Blocked by the administrators.', 'saunaplan' ),
			'icon'                => '⛔',
			'joinable'            => false,
			'counts_toward_limit' => false,
			'requires_people'     => false,
			'categories'          => array(),
			'internal'            => true,
		);
		return $modes;
	}

	public static function mode( string $key ): ?array {
		return self::modes()[ $key ] ?? null;
	}

	/** Modes residents can choose (no internal ones). */
	public static function bookable_modes(): array {
		return array_filter(
			self::modes(),
			static function ( $mode ) {
				return empty( $mode['internal'] );
			}
		);
	}

	/**
	 * Keep only known categories with sensible numbers.
	 *
	 * @return array<string,int>
	 */
	public static function sanitize_counts( $counts ): array {
		$out = array();
		foreach ( array_keys( self::profile()->categories() ) as $key ) {
			$n = is_array( $counts ) ? absint( $counts[ $key ] ?? 0 ) : 0;
			if ( $n > 0 ) {
				$out[ $key ] = min( 50, $n );
			}
		}
		return $out;
	}

	/**
	 * Keep only known fields that apply to the mode, as booleans.
	 *
	 * @return array<string,bool>
	 */
	public static function sanitize_attributes( $attributes, string $mode ): array {
		$out = array();
		foreach ( self::profile()->fields() as $key => $field ) {
			$modes = $field['modes'] ?? array_keys( self::bookable_modes() );
			if ( in_array( $mode, $modes, true ) && is_array( $attributes ) && ! empty( $attributes[ $key ] ) ) {
				$out[ $key ] = true;
			}
		}
		return $out;
	}

	/** "2 ♀ · 1 ♂" */
	public static function format_counts( array $counts ): string {
		$parts = array();
		foreach ( self::profile()->categories() as $key => $category ) {
			if ( ! empty( $counts[ $key ] ) ) {
				$parts[] = $counts[ $key ] . ' ' . $category['symbol'];
			}
		}
		return implode( ' · ', $parts );
	}

	/** Schema for the front end: what can be chosen and how it looks. */
	public static function schema(): array {
		$profile = self::profile();
		$modes   = array();
		foreach ( self::modes() as $key => $mode ) {
			$modes[] = array(
				'key'            => $key,
				'label'          => $mode['label'],
				'short'          => $mode['short'],
				'description'    => $mode['description'],
				'icon'           => $mode['icon'],
				'joinable'       => (bool) $mode['joinable'],
				'countsLimit'    => (bool) $mode['counts_toward_limit'],
				'requiresPeople' => (bool) $mode['requires_people'],
				'categories'     => array_values( $mode['categories'] ),
				'internal'       => ! empty( $mode['internal'] ),
			);
		}
		$categories = array();
		foreach ( $profile->categories() as $key => $category ) {
			$categories[] = array( 'key' => $key ) + $category;
		}
		$fields = array();
		foreach ( $profile->fields() as $key => $field ) {
			$fields[] = array(
				'key'   => $key,
				'label' => $field['label'] ?? $key,
				'help'  => $field['help'] ?? '',
				'icon'  => $field['icon'] ?? '',
				'modes' => array_values( $field['modes'] ?? array_keys( self::bookable_modes() ) ),
			);
		}
		$rating = $profile->rating_questions();
		return array(
			'room'       => $profile->label(),
			'modes'      => $modes,
			'categories' => $categories,
			'fields'     => $fields,
			'rating'     => array(
				'scales' => self::pairs( $rating['scales'] ?? array() ),
				'issues' => self::pairs( $rating['issues'] ?? array() ),
			),
		);
	}

	private static function pairs( array $map ): array {
		$out = array();
		foreach ( $map as $key => $label ) {
			$out[] = array( 'key' => $key, 'label' => $label );
		}
		return $out;
	}
}
