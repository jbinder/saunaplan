<?php
/**
 * The generic core must not know about saunas: no references to the sauna
 * layer and no sauna words. This keeps the core extractable for other rooms.
 *
 * Run: php tests/architecture-test.php
 */

require __DIR__ . '/bootstrap.php';

$core      = realpath( __DIR__ . '/../saunaplan/includes/core' );
$forbidden = array( 'Saunaplan\\Sauna', 'sauna', 'aufguss', 'warm', 'oven' );
$files     = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $core, FilesystemIterator::SKIP_DOTS ) );

echo "Core is room-neutral\n";
foreach ( $files as $file ) {
	if ( 'php' !== $file->getExtension() ) {
		continue;
	}
	$code = file_get_contents( $file->getPathname() );
	// The plugin's own name and prefixes ("Saunaplan\Core", "saunaplan_…") are fine.
	$code  = preg_replace( '/saunaplan/i', '', $code );
	$found = array();
	foreach ( $forbidden as $word ) {
		if ( false !== stripos( $code, $word ) ) {
			$found[] = $word;
		}
	}
	check( substr( $file->getPathname(), strlen( $core ) + 1 ), array(), $found );
}

$js    = preg_replace( '/saunaplan/i', '', file_get_contents( __DIR__ . '/../saunaplan/assets/js/core-app.js' ) );
$found = array_values( array_filter( array( 'sauna', 'aufguss', 'warm', 'female', 'male' ), static function ( $w ) use ( $js ) {
	return false !== stripos( $js, $w );
} ) );
check( 'assets/js/core-app.js', array(), $found );

finish();
