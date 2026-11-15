<?php
// Writes demo-store.php into the runPHP step of blueprint.json, and demo-variant-rows.php into a writeFile step
// plus an activatePlugin step that add it as a demo-only plugin: php .wordpress-org/blueprints/build.php
$file      = __DIR__ . '/blueprint.json';
$blueprint = json_decode( file_get_contents( $file ), true );
$mu = array(
	'step' => 'writeFile',
	'path' => '/wordpress/wp-content/plugins/demo-variant-rows.php',
	'data' => file_get_contents( __DIR__ . '/demo-variant-rows.php' ),
);
$activate = array( 'step' => 'activatePlugin', 'pluginPath' => $mu['path'] );
$blueprint['steps'] = array_values( array_filter( $blueprint['steps'], function ( $step ) use ( $mu ) {
	return ! in_array( $step['step'], array( 'writeFile', 'activatePlugin' ), true ) || ( $step['path'] ?? $step['pluginPath'] ) !== $mu['path'];
} ) );
// After WooCommerce is installed, before the demo products are made.
array_splice( $blueprint['steps'], count( $blueprint['steps'] ) - 1, 0, array( $mu, $activate ) );
foreach ( $blueprint['steps'] as &$step ) {
	if ( 'runPHP' === $step['step'] ) {
		$step['code'] = file_get_contents( __DIR__ . '/demo-store.php' );
	}
}
unset( $step );
file_put_contents( $file, json_encode( $blueprint, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n" );
echo "blueprint.json updated.\n";
