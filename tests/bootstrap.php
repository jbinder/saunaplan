<?php
/**
 * Minimal helpers for the plain-PHP tests (no WordPress needed).
 */

define( 'ABSPATH', __DIR__ . '/' );

$GLOBALS['failures'] = 0;
$GLOBALS['count']    = 0;

function check( string $name, $expected, $actual ): void {
	$GLOBALS['count']++;
	if ( $expected === $actual ) {
		echo "  ok   {$name}\n";
		return;
	}
	$GLOBALS['failures']++;
	echo "  FAIL {$name}\n       expected: " . json_encode( $expected ) . "\n       actual:   " . json_encode( $actual ) . "\n";
}

function finish(): void {
	echo "\n{$GLOBALS['count']} checks, {$GLOBALS['failures']} failed\n";
	exit( $GLOBALS['failures'] ? 1 : 0 );
}

// The few WordPress functions the tested classes use.
if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( $s ) {
		return trim( preg_replace( '/[\r\n\t ]+/', ' ', strip_tags( (string) $s ) ) );
	}
}
