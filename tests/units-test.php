<?php
/**
 * Flat import parsing and login code format.
 *
 * Run: php tests/units-test.php
 */

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/../saunaplan/includes/core/class-units.php';

use Saunaplan\Core\Units;

echo "Import\n";
$parsed = Units::parse_import( "Stiege;Tür\n1;1\n1, 2\n2/14\n3\tTop 4\n1;1\n\nnonsense\n" );
check( 'units', array( array( '1', '1' ), array( '1', '2' ), array( '2', '14' ), array( '3', 'Top 4' ) ), $parsed['units'] );
check( 'header skipped, duplicate ignored, bad line reported', array( 8 ), $parsed['invalid'] );
check( 'space separated', array( array( '2', '7' ) ), Units::parse_import( '2 7' )['units'] );

echo "Codes\n";
$code = Units::generate_code();
check( 'length', Units::CODE_LENGTH, strlen( $code ) );
check( 'only unambiguous characters', 1, preg_match( '/^[A-HJ-NP-Z2-9]+$/', $code ) );
check( 'normalize input', 'K7QX3MPA', Units::normalize_code( ' k7qx-3mpa ' ) );
check( 'format', 'K7QX-3MPA', Units::format_code( 'k7qx3mpa' ) );

finish();
