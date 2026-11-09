<?php
// Writes demo-store.php into the runPHP step of blueprint.json: php .wordpress-org/blueprints/build.php
$file      = __DIR__ . '/blueprint.json';
$blueprint = json_decode( file_get_contents( $file ), true );
foreach ( $blueprint['steps'] as &$step ) {
	if ( 'runPHP' === $step['step'] ) {
		$step['code'] = file_get_contents( __DIR__ . '/demo-store.php' );
	}
}
unset( $step );
file_put_contents( $file, json_encode( $blueprint, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n" );
echo "blueprint.json updated.\n";
