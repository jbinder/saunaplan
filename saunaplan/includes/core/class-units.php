<?php
namespace Saunaplan\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Units (flats), identified by stair + door, and their login codes.
 *
 * Codes are only stored as a keyed hash. The plain code exists once, when it
 * is generated for printing the letter.
 */
class Units {

	/** No I, O, 0, 1 – they are easy to mix up on paper. */
	const ALPHABET    = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
	const CODE_LENGTH = 8;

	public static function label( $unit ): string {
		if ( ! $unit ) {
			return '–';
		}
		/* translators: 1: stair, 2: door */
		return sprintf( __( 'Stair %1$s · Door %2$s', 'saunaplan' ), $unit->stair, $unit->door );
	}

	public static function short_label( $unit ): string {
		return $unit ? $unit->stair . '/' . $unit->door : '–';
	}

	/** Natural sort by stair, then door. */
	public static function compare( $a, $b ): int {
		return strnatcasecmp( $a->stair, $b->stair ) ?: strnatcasecmp( $a->door, $b->door );
	}

	public static function generate_code(): string {
		$code = '';
		$max  = strlen( self::ALPHABET ) - 1;
		for ( $i = 0; $i < self::CODE_LENGTH; $i++ ) {
			$code .= self::ALPHABET[ random_int( 0, $max ) ];
		}
		return $code;
	}

	/** "k7qx 3mpa" / "K7QX-3MPA" → "K7QX3MPA" */
	public static function normalize_code( string $code ): string {
		return preg_replace( '/[^A-Z0-9]/', '', strtoupper( $code ) );
	}

	/** "K7QX3MPA" → "K7QX-3MPA" */
	public static function format_code( string $code ): string {
		return implode( '-', str_split( self::normalize_code( $code ), 4 ) );
	}

	public static function hash_code( string $code ): string {
		return hash_hmac( 'sha256', self::normalize_code( $code ), wp_salt( 'auth' ) );
	}

	/**
	 * Give units new codes.
	 *
	 * @param int[] $ids
	 * @return array<int,string> unit ID => plain code (for printing, never stored).
	 */
	public static function issue_codes( array $ids ): array {
		$codes = array();
		foreach ( array_map( 'intval', $ids ) as $id ) {
			do {
				$code = self::generate_code();
				$hash = self::hash_code( $code );
			} while ( Repository::unit_by_code_hash( $hash ) );
			Repository::update_unit( $id, array( 'code_hash' => $hash, 'code_set_at' => Repository::now_utc() ) );
			$codes[ $id ] = $code;
		}
		return $codes;
	}

	public static function login_url( string $code ): string {
		return add_query_arg( 'code', self::format_code( $code ), Settings::page_url() );
	}

	/**
	 * Parse pasted or uploaded text: one flat per line, "stair;door"
	 * (also comma, tab, slash or space separated). A header line is skipped.
	 *
	 * @return array{units:array<int,array{0:string,1:string}>,invalid:int[]} invalid = line numbers.
	 */
	public static function parse_import( string $text ): array {
		$units   = array();
		$invalid = array();
		$seen    = array();
		$lines   = preg_split( '/\r\n|\r|\n/', $text );
		foreach ( $lines as $i => $line ) {
			$line = trim( $line );
			if ( '' === $line ) {
				continue;
			}
			if ( 0 === $i && preg_match( '/stair|stiege|door|t(ü|ue)r|top/i', $line ) && ! preg_match( '/\d/', $line ) ) {
				continue;
			}
			$parts = preg_split( '/\s*[;,\t\/]\s*/', $line );
			if ( count( $parts ) < 2 ) {
				$parts = preg_split( '/\s+/', $line, 2 );
			}
			$stair = sanitize_text_field( trim( (string) ( $parts[0] ?? '' ), " \"'" ) );
			$door  = sanitize_text_field( trim( (string) ( $parts[1] ?? '' ), " \"'" ) );
			if ( '' === $stair || '' === $door || mb_strlen( $stair ) > 50 || mb_strlen( $door ) > 50 ) {
				$invalid[] = $i + 1;
				continue;
			}
			$key = strtolower( $stair . '|' . $door );
			if ( isset( $seen[ $key ] ) ) {
				continue;
			}
			$seen[ $key ] = true;
			$units[]      = array( $stair, $door );
		}
		return array( 'units' => $units, 'invalid' => $invalid );
	}

	/**
	 * Add parsed units that don't exist yet.
	 *
	 * @return int[] IDs of the new units.
	 */
	public static function import( array $units ): array {
		$ids = array();
		foreach ( $units as $unit ) {
			if ( ! Repository::find_unit( $unit[0], $unit[1] ) ) {
				$id = Repository::insert_unit( $unit[0], $unit[1] );
				if ( $id ) {
					$ids[] = $id;
				}
			}
		}
		return $ids;
	}
}
