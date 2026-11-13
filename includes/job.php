<?php
/**
 * Bulk jobs on the server: Create on the Combine tab, and Undo and Move originals to Trash on History. The admin page
 * saves what to do as one job; WooCommerce's Action Scheduler works through it a batch at a time in the background, so
 * it finishes even if the admin leaves the page. While a tab
 * of the plugin page is open, the admin app (src/admin) runs batches too, so progress is quick to show. The bulk lock keeps any
 * two batches (or a WP-CLI run) from working at once.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// The job: its kind, the keys (or History product IDs) still to do, the counts so far, and when it finished (0 while
// running). One job runs at a time, of any kind.
const VARCONV_JOB_OPTION = 'varconv_job';
// The Action Scheduler hook that runs the next batch.
const VARCONV_JOB_HOOK = 'varconv_bulk_batch';
const VARCONV_JOB_BATCH = 10;
// What a job does: create groups, undo conversions, or move combines' originals to the Trash.
const VARCONV_JOB_KINDS = array( 'create', 'undo', 'trash' );

/**
 * Creates the groups $keys as the scan proposed them, under the bulk lock.
 *
 * @param string[] $keys
 * @return array{made:int,skip:int,failed:int,results:array<string,array>}
 * @throws RuntimeException When another bulk run holds the lock.
 */
function varconv_bulk_create( array $keys ): array {
	if ( ! varconv_lock() ) {
		throw new RuntimeException( esc_html__( 'Another bulk run is in progress. Try again in a few minutes.', 'variation-converter-for-woocommerce' ) );
	}
	try {
		return varconv_bulk_run( $keys );
	} finally {
		varconv_unlock();
	}
}

/**
 * Creates the groups $keys; the caller holds the bulk lock. Keys no longer in the scan are left out. $stop, checked
 * before each group, ends the run early when it returns true.
 *
 * @param string[] $keys
 * @return array{made:int,skip:int,failed:int,results:array<string,array>}
 */
function varconv_bulk_run( array $keys, ?callable $stop = null ): array {
	$counts = array( 'made' => 0, 'skip' => 0, 'failed' => 0, 'results' => array() );
	$wanted = array_flip( $keys );
	foreach ( varconv_bulk_candidates() as $c ) {
		if ( ! isset( $wanted[ $c['key'] ] ) ) {
			continue;
		}
		if ( $stop && $stop() ) {
			break;
		}
		$result = varconv_convert( $c );
		$counts['made'] += 'made' === $result['status'] ? 1 : 0;
		$counts['skip'] += 'skip' === $result['status'] ? 1 : 0;
		$counts['failed'] += 'failed' === $result['status'] ? 1 : 0;
		$counts['results'][ $c['key'] ] = $result;
		varconv_lock_renew();
	}
	return $counts;
}

/**
 * The bulk job, read from the database rather than the request's cache, since other requests change it. "made" counts
 * groups created, conversions undone, or combines whose originals moved to the Trash; "products" counts the originals
 * moved; "errors" keeps the first few reasons a conversion failed.
 *
 * @return array{kind:string,keys:array,total:int,done:int,made:int,skip:int,failed:int,products:int,errors:string[],user:int,started:int,finished:int,stopped:bool}|null
 */
function varconv_job(): ?array {
	wp_cache_delete( VARCONV_JOB_OPTION, 'options' );
	$job = get_option( VARCONV_JOB_OPTION, null );
	// A job saved before undo and trash jobs existed is a bulk create.
	return is_array( $job ) ? $job + array( 'kind' => 'create', 'products' => 0, 'errors' => array() ) : null;
}

function varconv_job_running( ?array $job ): bool {
	return null !== $job && ! $job['finished'];
}

/**
 * Starts a job, and queues its first batch: creating the groups $keys ("create"), or undoing ("undo") or moving the
 * originals to the Trash of ("trash") the History products $keys.
 *
 * @param array $keys
 * @throws RuntimeException When a job is already running.
 */
function varconv_job_start( array $keys, string $kind = 'create' ): array {
	if ( varconv_job_running( varconv_job() ) ) {
		throw new RuntimeException( esc_html__( 'A bulk job is already running. Wait for it to finish, or stop it.', 'variation-converter-for-woocommerce' ) );
	}
	if ( ! in_array( $kind, VARCONV_JOB_KINDS, true ) ) {
		throw new InvalidArgumentException( 'Unknown job kind.' );
	}
	$keys = 'create' === $kind ? array_values( array_unique( $keys ) ) : array_values( array_unique( array_map( 'intval', $keys ) ) );
	$job = array(
		'kind'     => $kind,
		'keys'     => $keys,
		'total'    => count( $keys ),
		'done'     => 0,
		'made'     => 0,
		'skip'     => 0,
		'failed'   => 0,
		'products' => 0,
		'errors'   => array(),
		'user'     => get_current_user_id(),
		'started'  => time(),
		'finished' => $keys ? 0 : time(),
		'stopped'  => false,
	);
	update_option( VARCONV_JOB_OPTION, $job, false );
	varconv_job_queue();
	return $job;
}

/**
 * Stops the job: no more groups are started. When a batch is running, the job stays running ("stopping") until that
 * batch has finished its current group and recorded its counts; otherwise it finishes now.
 */
function varconv_job_stop(): void {
	$job = varconv_job();
	if ( ! varconv_job_running( $job ) ) {
		return;
	}
	$job['keys'] = array();
	$job['stopped'] = true;
	update_option( VARCONV_JOB_OPTION, $job, false );
	if ( varconv_lock() ) {
		$job = varconv_job() ?? $job;
		$job['finished'] = $job['finished'] ?: time();
		update_option( VARCONV_JOB_OPTION, $job, false );
		varconv_unlock();
	}
}

/** Whether Stop was pressed: the batch running checks between groups. */
function varconv_job_stopping(): bool {
	$job = varconv_job();
	return ! $job || $job['stopped'];
}

/**
 * Runs the job's next batch, unless another batch or a WP-CLI run holds the lock, and queues the one after.
 *
 * @return bool Whether a batch ran.
 */
function varconv_job_step(): bool {
	if ( ! varconv_job_running( varconv_job() ) || ! varconv_lock() ) {
		return false;
	}
	try {
		// Read again now this request holds the lock: a batch may have finished in between.
		$job = varconv_job();
		if ( ! varconv_job_running( $job ) ) {
			return false;
		}
		$batch = array_slice( $job['keys'], 0, VARCONV_JOB_BATCH );
		$counts = varconv_job_batch( $job['kind'], $batch );
		// And again, in case Stop was pressed while the batch ran. A job deleted meanwhile stays deleted.
		$job = varconv_job();
		if ( ! $job ) {
			return true;
		}
		$job['keys'] = array_values( array_diff( $job['keys'], $batch ) );
		$job['done'] += $job['stopped'] ? $counts['ran'] : count( $batch );
		foreach ( array( 'made', 'skip', 'failed', 'products' ) as $count ) {
			$job[ $count ] += $counts[ $count ];
		}
		$job['errors'] = array_slice( array_merge( $job['errors'], $counts['errors'] ), 0, 10 );
		if ( ! $job['keys'] && ! $job['finished'] ) {
			$job['finished'] = time();
		}
		update_option( VARCONV_JOB_OPTION, $job, false );
	} finally {
		varconv_unlock();
	}
	varconv_job_queue();
	return true;
}

/**
 * Works through one batch of a job of $kind; the caller holds the bulk lock. Stops early when Stop is pressed.
 *
 * @return array{ran:int,made:int,skip:int,failed:int,products:int,errors:string[]}
 */
function varconv_job_batch( string $kind, array $batch ): array {
	$out = array( 'ran' => 0, 'made' => 0, 'skip' => 0, 'failed' => 0, 'products' => 0, 'errors' => array() );
	if ( ! $batch ) {
		return $out;
	}
	if ( 'create' === $kind ) {
		$counts = varconv_bulk_run( $batch, 'varconv_job_stopping' );
		$failed = array_filter( $counts['results'], static fn( $r ) => 'failed' === $r['status'] );
		return array(
			'ran'      => count( $counts['results'] ),
			'made'     => $counts['made'],
			'skip'     => $counts['skip'],
			'failed'   => $counts['failed'],
			'products' => 0,
			'errors'   => array_values( array_filter( array_map( static fn( $r ) => (string) ( $r['message'] ?? '' ), $failed ) ) ),
		);
	}
	if ( 'undo' === $kind ) {
		$result = varconv_undo_many( $batch, 'varconv_job_stopping' );
		$out['made'] = $result['undone'];
	} else {
		$result = varconv_trash_originals_many( $batch, 'varconv_job_stopping' );
		$out['made'] = $result['done'];
		$out['skip'] = $result['skipped'];
		$out['products'] = $result['products'];
	}
	$out['ran'] = $result['ran'];
	$out['failed'] = count( $result['errors'] );
	$out['errors'] = $result['errors'];
	return $out;
}

/**
 * Queues a background batch while the job runs, unless one is already waiting. Without Action Scheduler the job moves
 * only while a tab of the plugin page is open.
 */
function varconv_job_queue( int $delay = 0 ): void {
	if ( ! function_exists( 'as_schedule_single_action' ) || ! varconv_job_running( varconv_job() ) ) {
		return;
	}
	$waiting = as_get_scheduled_actions( array( 'hook' => VARCONV_JOB_HOOK, 'status' => ActionScheduler_Store::STATUS_PENDING ), 'ids' );
	if ( ! $waiting ) {
		as_schedule_single_action( time() + $delay, VARCONV_JOB_HOOK, array(), 'variation-converter' );
	}
}

/** The background batch. When another batch holds the lock, it tries again shortly, so the job never stalls. */
add_action( VARCONV_JOB_HOOK, 'varconv_job_background' );
function varconv_job_background(): void {
	if ( ! varconv_job_step() ) {
		varconv_job_queue( 30 );
	}
}
