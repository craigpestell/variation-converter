<?php
/**
 * The bulk create job (includes/job.php) against a real WordPress + WooCommerce, with the plugin active:
 *   wp eval-file tests/integration-job.php
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

$cleanup = array();
$tag = 'VCJ' . wp_rand( 1000, 9999 );
$pending = static fn() => count( as_get_scheduled_actions( array( 'hook' => VARCONV_JOB_HOOK, 'status' => ActionScheduler_Store::STATUS_PENDING ), 'ids' ) );
try {
	delete_option( VARCONV_JOB_OPTION );
	as_unschedule_all_actions( VARCONV_JOB_HOOK );
	$make = static function ( string $sku, string $name ) use ( &$cleanup ) {
		$s = new WC_Product_Simple();
		$s->set_name( $name );
		$s->set_status( 'publish' );
		$s->set_sku( $sku );
		$s->set_regular_price( '5' );
		return $cleanup[] = $s->save();
	};
	$keys = array();
	foreach ( array( 'A', 'B', 'C' ) as $letter ) {
		$make( $tag . $letter . '-01', $tag . $letter . ' Mug Red' );
		$make( $tag . $letter . '-02', $tag . $letter . ' Mug Blue' );
	}
	varconv_candidates( true );
	foreach ( varconv_bulk_candidates() as $c ) {
		if ( 0 === strpos( (string) $c['sku'], $tag ) ) {
			$keys[] = $c['key'];
		}
	}
	check( count( $keys ), 3, 'three groups found' );

	// Starting saves the job and queues a background batch; a second start is refused while it runs.
	$job = varconv_job_start( array( $keys[0], $keys[1] ) );
	check( array( $job['total'], $job['done'], $job['finished'] ), array( 2, 0, 0 ), 'job started' );
	check( $pending(), 1, 'background batch queued' );
	try {
		varconv_job_start( array( $keys[2] ) );
		throw new LogicException( 'second job started' );
	} catch ( RuntimeException $e ) {
		check( str_contains( $e->getMessage(), 'already running' ), true, 'second job refused' );
	}

	// Another run holding the lock: the batch waits, and the job stays queued.
	varconv_lock();
	check( varconv_job_step(), false, 'no batch while locked' );
	varconv_unlock();

	// The background batch (as Action Scheduler runs it, with no page open) finishes the job.
	do_action( VARCONV_JOB_HOOK );
	$job = varconv_job();
	check( array( $job['done'], $job['made'], $job['skip'], $job['failed'] ), array( 2, 2, 0, 0 ), 'job counts' );
	check( $job['finished'] > 0, true, 'job finished' );
	check( $job['keys'], array(), 'nothing left' );
	foreach ( varconv_created() as $id ) {
		if ( 0 === strpos( (string) wc_get_product( $id )->get_name(), $tag ) ) {
			$cleanup[] = $id;
		}
	}
	check( count( $cleanup ), 8, 'two variable products made' );
	check( varconv_job_step(), false, 'finished job does nothing' );
	as_unschedule_all_actions( VARCONV_JOB_HOOK );

	// Stop ends a running job without creating the rest.
	delete_option( VARCONV_JOB_OPTION );
	varconv_job_start( array( $keys[2] ) );
	varconv_job_stop();
	$job = varconv_job();
	check( array( $job['stopped'], $job['made'], $job['finished'] > 0 ), array( true, 0, true ), 'job stopped' );
	check( varconv_job_step(), false, 'stopped job does nothing' );

	// Stop while a batch holds the lock: the job is "stopping" until that batch records its counts, and the batch's
	// write does not bring it back to running.
	delete_option( VARCONV_JOB_OPTION );
	varconv_job_start( array( $keys[2] ) );
	varconv_lock();
	varconv_job_stop();
	$job = varconv_job();
	check( array( $job['stopped'], $job['finished'], $job['keys'] ), array( true, 0, array() ), 'stopping while a batch runs' );
	check( varconv_job_running( $job ), true, 'still running until the batch ends' );
	varconv_unlock();
	check( varconv_job_step(), true, 'the next step finishes it' );
	$job = varconv_job();
	check( array( $job['finished'] > 0, $job['made'] ), array( true, 0 ), 'stopped without creating' );

	// A batch stops between groups once Stop is pressed.
	$calls = 0;
	$counts = varconv_bulk_run( array( $keys[2] ), static function () use ( &$calls ) {
		return 0 < $calls++;
	} );
	check( count( $counts['results'] ), 1, 'first group made before the stop check' );
	$cleanup[] = $counts['results'][ $keys[2] ]['id'];
	check( count( varconv_bulk_run( array( $keys[2] ), '__return_true' )['results'] ), 0, 'stopped before any group' );

	echo "Job integration tests passed.\n";
} finally {
	varconv_unlock();
	delete_option( VARCONV_JOB_OPTION );
	as_unschedule_all_actions( VARCONV_JOB_HOOK );
	foreach ( array_reverse( $cleanup ) as $id ) {
		$product = wc_get_product( $id );
		foreach ( $product ? $product->get_children() : array() as $child ) {
			wp_delete_post( $child, true );
		}
		wp_delete_post( $id, true );
	}
	delete_transient( VARCONV_SCAN_TRANSIENT );
}
