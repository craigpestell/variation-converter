<?php
/**
 * REST routes for the History tab: every conversion made here, and Undo and Move originals to Trash for one, for
 * those checked, or for every one the list shows. See rest.php.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function varconv_rest_history_routes(): void {
	varconv_rest_route( '/history', 'GET', 'varconv_rest_history' );
	varconv_rest_route( '/history/undo', 'POST', 'varconv_rest_history_undo' );
	varconv_rest_route( '/history/trash', 'POST', 'varconv_rest_history_trash' );
}

/** What a History row says about its products, as HTML (escaped here). */
function varconv_history_products_html( int $id, string $kind, array $originals ): string {
	if ( 'taken' === $kind ) {
		$variation = (int) get_post_meta( $id, VARCONV_TAKEN_META, true );
		$parent = (int) wp_get_post_parent_id( $variation );
		if ( $parent && varconv_taken_all( $parent ) ) {
			return esc_html( sprintf(
				/* translators: %s: variable product name. */
				__( 'Made from a variation of %s, which had no variation left for sale and is in the Trash. Undo restores it.', 'variation-converter-for-woocommerce' ),
				get_post_field( 'post_title', $parent, 'raw' )
			) );
		}
		return wp_kses_post( sprintf(
			/* translators: %s: link to the variable product. */
			__( 'Made from a variation of %s, which is disabled there.', 'variation-converter-for-woocommerce' ),
			$parent ? sprintf( '<a href="%s">%s</a>', esc_url( (string) get_edit_post_link( $parent ) ), esc_html( get_post_field( 'post_title', $parent, 'raw' ) ) ) : esc_html__( 'a variable product that no longer exists', 'variation-converter-for-woocommerce' )
		) );
	}
	$combined = 'combined' === $kind;
	$ids = json_decode( (string) get_post_meta( $id, $combined ? VARCONV_META : VARCONV_SPLIT_META, true ), true ) ?: array();
	$html = esc_html( $combined
		/* translators: %d: number of simple products. */
		? sprintf( _n( 'Made from %d simple product:', 'Made from %d simple products:', count( $ids ), 'variation-converter-for-woocommerce' ), count( $ids ) )
		/* translators: %d: number of simple products. */
		: sprintf( _n( 'Made into %d simple product (this one is hidden):', 'Made into %d simple products (this one is hidden):', count( $ids ), 'variation-converter-for-woocommerce' ), count( $ids ) ) );
	$html .= ' ' . wp_kses_post( varconv_product_links( $ids ) );
	if ( $originals['trash'] ) {
		$html .= '<br><span class="varconv-muted">' . esc_html( sprintf(
			/* translators: %d: number of simple products. */
			_n( '%d in the Trash, with its reviews moved here. Undo restores it.', '%d in the Trash, with their reviews moved here. Undo restores them.', count( $originals['trash'] ), 'variation-converter-for-woocommerce' ),
			count( $originals['trash'] )
		) ) . '</span>';
	}
	if ( $originals['gone'] ) {
		$html .= '<br><span class="varconv-muted">' . esc_html( sprintf(
			/* translators: %d: number of simple products. */
			_n( '%d deleted from the Trash, so this can no longer be undone.', '%d deleted from the Trash, so this can no longer be undone.', count( $originals['gone'] ), 'variation-converter-for-woocommerce' ),
			count( $originals['gone'] )
		) ) . '</span>';
	}
	return $html;
}

/** What Undo asks before it changes anything, for one conversion of $kind. */
function varconv_undo_confirm( string $kind, int $id ): string {
	if ( 'combined' === $kind ) {
		return __( 'Delete this variable product and show its simple products again?', 'variation-converter-for-woocommerce' );
	}
	if ( 'taken' === $kind ) {
		return '1' === (string) get_post_meta( $id, VARCONV_TAKEN_NEW_META, true )
			? __( 'Delete this simple product and enable its variation again, with this product\'s price and stock?', 'variation-converter-for-woocommerce' )
			: __( 'Hide this product again and enable its variation, with this product\'s price and stock?', 'variation-converter-for-woocommerce' );
	}
	return __( 'Delete the simple products made from this product and show it again?', 'variation-converter-for-woocommerce' );
}

/** The History tab's list: one page of conversions for the view and search, and each view's count. */
function varconv_rest_history( WP_REST_Request $request ): array {
	$view = sanitize_key( (string) $request->get_param( 'view' ) );
	$view = in_array( $view, array( 'combined', 'split', 'taken' ), true ) ? $view : '';
	$search = varconv_rest_text( $request, 's' );
	$all = varconv_history( $search );
	$rows = varconv_history_view( $search, $view );
	$pages = max( 1, (int) ceil( count( $rows ) / VARCONV_PER_PAGE ) );
	$page = min( $pages, max( 1, absint( $request->get_param( 'paged' ) ) ) );
	$items = array();
	foreach ( array_slice( $rows, ( $page - 1 ) * VARCONV_PER_PAGE, VARCONV_PER_PAGE ) as $r ) {
		$id = (int) $r['id'];
		$originals = 'combined' === $r['kind'] ? varconv_originals( $id ) : array( 'hidden' => array(), 'trash' => array(), 'gone' => array() );
		$items[] = array_merge( varconv_rest_product( $id ), array(
			'kind'         => $r['kind'],
			'productsHtml' => varconv_history_products_html( $id, $r['kind'], $originals ),
			'canUndo'      => ! $originals['gone'],
			'canTrash'     => (bool) $originals['hidden'],
			'undoConfirm'  => varconv_undo_confirm( $r['kind'], $id ),
			/* translators: 1: date, 2: time. */
			'date'         => sprintf( __( '%1$s at %2$s', 'variation-converter-for-woocommerce' ), wp_date( (string) get_option( 'date_format' ), $r['time'] ), wp_date( (string) get_option( 'time_format' ), $r['time'] ) ),
		) );
	}
	return array(
		'counts' => array(
			''         => count( $all ),
			'combined' => count( array_filter( $all, static fn( $r ) => 'combined' === $r['kind'] ) ),
			'split'    => count( array_filter( $all, static fn( $r ) => 'split' === $r['kind'] ) ),
			'taken'    => count( array_filter( $all, static fn( $r ) => 'taken' === $r['kind'] ) ),
		),
		'total'  => count( $rows ),
		'pages'  => $pages,
		'page'   => $page,
		/* translators: %s: number of conversions. */
		'label'  => sprintf( _n( '%s item', '%s items', count( $rows ), 'variation-converter-for-woocommerce' ), number_format_i18n( count( $rows ) ) ),
		'items'  => $items,
		'empty'  => '' !== $search ? __( 'No conversions match your search.', 'variation-converter-for-woocommerce' ) : __( 'Nothing combined, split or made simple yet.', 'variation-converter-for-woocommerce' ),
		'trashConfirm' => varconv_trash_originals_confirm(),
	);
}

/** The conversions a request acts on: those given, or every row the view and search show ("Select all N conversions"). */
function varconv_rest_history_ids( WP_REST_Request $request ): array {
	$ids = $request->get_param( 'all' )
		? array_column( varconv_history_view( varconv_rest_text( $request, 's' ), sanitize_key( (string) $request->get_param( 'view' ) ) ), 'id' )
		: varconv_rest_ids( $request->get_param( 'ids' ) );
	if ( ! $ids ) {
		throw new RuntimeException( esc_html__( 'Check at least one conversion first.', 'variation-converter-for-woocommerce' ) );
	}
	return array_map( 'intval', $ids );
}

/**
 * Starts a background job when more conversions are picked than one batch holds, so a large selection never times
 * out. Returns the job for the app to follow, or null when the request should do the work itself.
 */
function varconv_rest_history_job( array $ids, string $kind ): ?array {
	if ( count( $ids ) <= VARCONV_JOB_BATCH ) {
		return null;
	}
	varconv_job_start( $ids, $kind );
	return array( 'job' => varconv_rest_job( true ) );
}

/** Undo for the picked conversions, each as its own Undo would. */
function varconv_rest_history_undo( WP_REST_Request $request ): array {
	$ids = varconv_rest_history_ids( $request );
	$job = varconv_rest_history_job( $ids, 'undo' );
	if ( $job ) {
		return $job;
	}
	// Not while a bulk job is working on the same products.
	if ( varconv_job_running( varconv_job() ) || ! varconv_lock() ) {
		throw new RuntimeException( esc_html__( 'A bulk job is running. Undo when it has finished.', 'variation-converter-for-woocommerce' ) );
	}
	try {
		$result = varconv_undo_many( $ids );
	} finally {
		varconv_unlock();
	}
	// Scan again now, so the Combine tab's count is right.
	varconv_candidates();
	if ( $result['errors'] ) {
		/* translators: 1: number of conversions undone, 2: why the others were not, one reason after another. */
		return array( 'notice' => varconv_rest_notice( 'error', esc_html( sprintf( __( 'Undid %1$d. Not undone: %2$s', 'variation-converter-for-woocommerce' ), $result['undone'], implode( ' ', $result['errors'] ) ) ) ) );
	}
	return array( 'notice' => varconv_rest_notice( 'success', esc_html( sprintf(
		/* translators: %d: number of conversions undone. */
		_n( 'Undid %d conversion. Its products are back as they were.', 'Undid %d conversions. Their products are back as they were.', $result['undone'], 'variation-converter-for-woocommerce' ),
		$result['undone']
	) ) ) );
}

/** Move originals to Trash for the picked combines. */
function varconv_rest_history_trash( WP_REST_Request $request ): array {
	$ids = varconv_rest_history_ids( $request );
	$job = varconv_rest_history_job( $ids, 'trash' );
	if ( $job ) {
		return $job;
	}
	if ( varconv_job_running( varconv_job() ) || ! varconv_lock() ) {
		throw new RuntimeException( esc_html__( 'A bulk job is running. Try again when it has finished.', 'variation-converter-for-woocommerce' ) );
	}
	try {
		$result = varconv_trash_originals_many( $ids );
	} finally {
		varconv_unlock();
	}
	if ( ! $result['done'] && ! $result['errors'] ) {
		throw new RuntimeException( esc_html__( 'Nothing was moved: none of these conversions has hidden originals left. Only combines whose originals are still hidden can move them to the Trash.', 'variation-converter-for-woocommerce' ) );
	}
	if ( $result['errors'] ) {
		/* translators: 1: number of products moved to the Trash, 2: why others were not, one reason after another. */
		return array( 'notice' => varconv_rest_notice( 'error', esc_html( sprintf( __( 'Moved %1$d originals to the Trash. Not moved: %2$s', 'variation-converter-for-woocommerce' ), $result['products'], implode( ' ', $result['errors'] ) ) ) ) );
	}
	$html = esc_html( sprintf(
		/* translators: %d: number of simple products moved to the Trash. */
		_n( 'Moved %d original simple product to the Trash. Its reviews are on the variable product now, and its old page redirects there. Undo restores it while it is in the Trash.', 'Moved %d original simple products to the Trash. Their reviews are on the variable product now, and their old pages redirect there. Undo restores them while they are in the Trash.', $result['products'], 'variation-converter-for-woocommerce' ),
		$result['products']
	) );
	if ( $result['skipped'] ) {
		$html .= ' ' . esc_html( sprintf(
			/* translators: %d: number of conversions skipped. */
			_n( 'Skipped %d conversion with no hidden originals (a split, a Made simple, or originals already in the Trash).', 'Skipped %d conversions with no hidden originals (splits, Made simple, or originals already in the Trash).', $result['skipped'], 'variation-converter-for-woocommerce' ),
			$result['skipped']
		) );
	}
	return array( 'notice' => varconv_rest_notice( 'success', $html ) );
}
