<?php
/**
 * History's bulk Move originals to Trash and Undo as a background job (includes/job.php), against a real WordPress +
 * WooCommerce with the plugin active:
 *   wp eval-file tests/integration-history-job.php
 * Makes its own test products and deletes them at the end. Run it on a test site, not a live store.
 */
if ( ! defined( 'ABSPATH' ) || ! function_exists( 'varconv_job_start' ) ) {
	fwrite( STDERR, "Run with wp eval-file on a site where WooCommerce and Variation Converter are active.\n" );
	exit( 1 );
}

function check( $actual, $expected, string $what ): void {
	if ( $actual !== $expected ) {
		throw new RuntimeException( $what . ': ' . wp_json_encode( array( 'expected' => $expected, 'actual' => $actual ) ) );
	}
}

// The notice text lives in the admin page's code.
if ( ! function_exists( 'varconv_job_html' ) ) {
	require_once dirname( VARCONV_FILE ) . '/includes/admin.php';
}

/** Runs background batches, as Action Scheduler would, until the job finishes. */
function run_job(): array {
	for ( $i = 0; $i < 50 && varconv_job_running( varconv_job() ); $i++ ) {
		do_action( VARCONV_JOB_HOOK );
	}
	return varconv_job();
}

$tag = 'VCH' . wp_rand( 1000, 9999 );
$cleanup = array();
$parents = array();
$count = VARCONV_JOB_BATCH + 3;
try {
	delete_option( VARCONV_JOB_OPTION );
	as_unschedule_all_actions( VARCONV_JOB_HOOK );
	// More combines than one batch, two originals each.
	$originals = array();
	for ( $n = 0; $n < $count; $n++ ) {
		$ids = array();
		foreach ( array( 'Red', 'Blue' ) as $i => $label ) {
			$p = new WC_Product_Simple();
			$p->set_name( "$tag Mug $n $label" );
			$p->set_status( 'publish' );
			$p->set_sku( "$tag-$n-$i" );
			$p->set_regular_price( '5' );
			$ids[ $cleanup[] = $p->save() ] = $label;
		}
		$originals = array_merge( $originals, array_keys( $ids ) );
		$parents[] = varconv_create( "$tag Mug $n", 'Color', $ids );
	}

	// Move originals to Trash: a product not in History is skipped, every combine's originals move.
	$job = varconv_job_start( array_merge( $parents, array( $cleanup[0] ) ), 'trash' );
	check( array( $job['kind'], $job['total'] ), array( 'trash', $count + 1 ), 'trash job started' );
	check( varconv_job_text( $job ), sprintf( 'Moving originals to the Trash: 0 of %d conversions done. This keeps going if you leave the page.', $count + 1 ), 'progress text' );
	try {
		varconv_job_start( $parents, 'undo' );
		throw new LogicException( 'second job started' );
	} catch ( RuntimeException $e ) {
		check( str_contains( $e->getMessage(), 'already running' ), true, 'a second job is refused' );
	}
	check( varconv_job_step(), true, 'one batch ran' );
	check( varconv_job()['done'], VARCONV_JOB_BATCH, 'one batch done' );
	$job = run_job();
	check( array( $job['done'], $job['made'], $job['products'], $job['skip'], $job['failed'] ), array( $count + 1, $count, 2 * $count, 1, 0 ), 'trash job counts' );
	check( array_unique( array_map( 'get_post_status', $originals ) ), array( 'trash' ), 'every original in the Trash' );
	check( str_contains( varconv_job_html( $job ), sprintf( 'Moved %d original simple products to the Trash, from %d combined products.', 2 * $count, $count ) ), true, 'trash summary' );
	check( str_contains( varconv_job_class( $job ), 'notice-success' ), true, 'trash summary is a success' );

	// Again: nothing is left to move.
	delete_option( VARCONV_JOB_OPTION );
	varconv_job_start( $parents, 'trash' );
	$job = run_job();
	check( array( $job['made'], $job['skip'] ), array( 0, $count ), 'nothing to move' );
	check( str_contains( varconv_job_html( $job ), 'Nothing was moved' ), true, 'nothing-moved summary' );
	check( str_contains( varconv_job_class( $job ), 'notice-warning' ), true, 'nothing-moved is a warning' );

	// Undo, stopped part way: what was undone stays undone, the rest stay combined.
	delete_option( VARCONV_JOB_OPTION );
	varconv_job_start( $parents, 'undo' );
	check( varconv_job_step(), true, 'first undo batch' );
	varconv_job_stop();
	$job = varconv_job();
	check( array( $job['stopped'], $job['finished'] > 0, $job['done'], $job['made'] ), array( true, true, VARCONV_JOB_BATCH, VARCONV_JOB_BATCH ), 'undo stopped after a batch' );
	check( str_contains( varconv_job_html( $job ), sprintf( 'Stopped after %d of %d conversions.', VARCONV_JOB_BATCH, $count ), ), true, 'stopped summary' );
	$left = array_values( array_intersect( $parents, varconv_created() ) );
	check( count( $left ), $count - VARCONV_JOB_BATCH, 'the rest still combined' );

	// Undo the rest: their originals come back from the Trash, published.
	delete_option( VARCONV_JOB_OPTION );
	varconv_job_start( $left, 'undo' );
	$job = run_job();
	check( array( $job['made'], $job['failed'] ), array( count( $left ), 0 ), 'undo job counts' );
	check( array_values( array_intersect( $parents, varconv_created() ) ), array(), 'nothing combined is left' );
	check( array_unique( array_map( 'get_post_status', $originals ) ), array( 'publish' ), 'every original published again' );
	$parents = array();

	// A product that is not in History: reported, not fatal.
	delete_option( VARCONV_JOB_OPTION );
	varconv_job_start( array( $cleanup[0] ), 'undo' );
	$job = run_job();
	check( array( $job['made'], $job['failed'] ), array( 0, 1 ), 'not in History fails' );
	check( str_contains( varconv_job_html( $job ), 'Not undone:' ), true, 'failure in the summary' );

	echo "History job integration tests passed.\n";
} finally {
	varconv_unlock();
	delete_option( VARCONV_JOB_OPTION );
	as_unschedule_all_actions( VARCONV_JOB_HOOK );
	foreach ( $parents as $id ) {
		try {
			varconv_undo( $id );
		} catch ( Throwable $e ) {
			unset( $e );
		}
	}
	foreach ( array_reverse( $cleanup ) as $id ) {
		wp_delete_post( $id, true );
	}
	delete_transient( VARCONV_SCAN_TRANSIENT );
}
