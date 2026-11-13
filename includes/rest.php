<?php
/**
 * The REST API the admin app (src/admin, built into build/admin.js) talks to: varconv/v1. Every route needs the
 * manage_woocommerce capability, and WordPress checks the REST nonce the app sends with each request. The routes only
 * shape requests and answers; the work is in scan.php, create.php, split.php, take-out.php, add-to.php,
 * trash-originals.php and job.php.
 *
 * This file holds what every tab shares (status, the bulk job) and the Combine tab. Split, History and
 * "Add to a combined product" are in rest-split.php, rest-history.php and rest-add.php.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const VARCONV_REST = 'varconv/v1';

require_once __DIR__ . '/rest-split.php';
require_once __DIR__ . '/rest-history.php';
require_once __DIR__ . '/rest-add.php';

/** Every route's permission check. */
function varconv_rest_can(): bool {
	return current_user_can( VARCONV_CAP );
}

/**
 * Registers a route under varconv/v1.
 *
 * @param string   $path     Starting with a slash.
 * @param string   $method   GET or POST.
 * @param callable $callback Takes the WP_REST_Request; returns data, or throws a RuntimeException to answer with its
 *                           message as an error.
 */
function varconv_rest_route( string $path, string $method, callable $callback ): void {
	register_rest_route( VARCONV_REST, $path, array(
		'methods'             => $method,
		'permission_callback' => 'varconv_rest_can',
		'callback'            => static function ( WP_REST_Request $request ) use ( $callback ) {
			// WooCommerce refuses to make a product over REST while another product holds its SKU, which guards its
			// own API against two requests at once. A conversion moves a SKU from one product to the one made from
			// it under the bulk lock, as it did before this was a REST request, so that guard does not apply here.
			add_filter( 'woocommerce_is_rest_api_request', '__return_false', 99 );
			try {
				return rest_ensure_response( $callback( $request ) );
			} catch ( RuntimeException $e ) {
				return new WP_Error( 'varconv_failed', varconv_message( $e ), array( 'status' => 400 ) );
			} finally {
				remove_filter( 'woocommerce_is_rest_api_request', '__return_false', 99 );
			}
		},
	) );
}

add_action( 'rest_api_init', 'varconv_rest_routes' );
function varconv_rest_routes(): void {
	varconv_rest_route( '/status', 'GET', 'varconv_rest_status' );
	varconv_rest_route( '/scan', 'POST', 'varconv_rest_scan' );
	varconv_rest_route( '/job', 'POST', 'varconv_rest_job_step' );
	varconv_rest_route( '/job/stop', 'POST', 'varconv_rest_job_stop' );
	varconv_rest_route( '/combine', 'GET', 'varconv_rest_combine' );
	varconv_rest_route( '/combine/card', 'GET', 'varconv_rest_card' );
	varconv_rest_route( '/combine/create', 'POST', 'varconv_rest_create' );
	varconv_rest_route( '/combine/exclude', 'POST', 'varconv_rest_exclude' );
	varconv_rest_route( '/combine/pick', 'POST', 'varconv_rest_pick' );
	varconv_rest_route( '/combine/bulk', 'POST', 'varconv_rest_bulk' );
	varconv_rest_route( '/combine/undo', 'POST', 'varconv_rest_undo_created' );
	varconv_rest_split_routes();
	varconv_rest_history_routes();
	varconv_rest_add_routes();
}

/** A sanitized text value from the request. */
function varconv_rest_text( WP_REST_Request $request, string $name ): string {
	$value = $request->get_param( $name );
	return is_scalar( $value ) ? sanitize_text_field( (string) $value ) : '';
}

/** @return int[] Positive IDs from a request value: an array, or a comma separated list. */
function varconv_rest_ids( $value ): array {
	$list = is_array( $value ) ? $value : explode( ',', is_scalar( $value ) ? (string) $value : '' );
	return array_values( array_unique( array_filter( array_map( 'absint', array_filter( $list, 'is_scalar' ) ) ) ) );
}

/** @return string[] Keys from a request value: an array, or a comma separated list. */
function varconv_rest_keys( $value ): array {
	$list = is_array( $value ) ? $value : explode( ',', is_scalar( $value ) ? (string) $value : '' );
	return array_values( array_unique( array_filter( array_map( 'sanitize_key', array_filter( $list, 'is_scalar' ) ) ) ) );
}

/** A notice for the app to show: its kind (success, info, warning, error) and its HTML, escaped here. */
function varconv_rest_notice( string $status, string $html, array $more = array() ): array {
	return array_merge( array( 'status' => $status, 'html' => $html ), $more );
}

/** A product's name and links, for the app to show. */
function varconv_rest_product( int $id ): array {
	return array(
		'id'      => $id,
		'name'    => (string) get_post_field( 'post_title', $id, 'raw' ),
		'state'   => varconv_status_text( $id ),
		'editUrl' => (string) get_edit_post_link( $id, 'url' ),
		'viewUrl' => (string) get_permalink( $id ),
	);
}

/** A thumbnail and the larger image shown on hover, as URLs; empty strings without an image. */
function varconv_rest_image( int $image ): array {
	return array(
		'thumb' => $image ? (string) wp_get_attachment_image_url( $image, array( 80, 80 ) ) : '',
		'large' => $image ? (string) wp_get_attachment_image_url( $image, 'medium' ) : '',
	);
}

/**
 * What the header and tabs show: the count on each tab, when the scan was made, and the bulk job. The Combine count
 * comes only from a scan already made, so opening Split or History never starts one.
 */
function varconv_rest_status(): array {
	$scan = get_transient( VARCONV_SCAN_TRANSIENT );
	return array(
		'counts'  => array(
			'combine' => is_array( $scan ) ? count( $scan['collections'] ) : null,
			'split'   => null,
			'history' => count( varconv_created() ) + count( varconv_split_list() ) + count( varconv_taken_list() ),
		),
		/* translators: %s: time since the scan, e.g. "5 mins". */
		'scanned' => is_array( $scan ) ? sprintf( __( 'Scanned %s ago', 'variation-converter-for-woocommerce' ), human_time_diff( (int) $scan['time'] ) ) : '',
		'job'     => varconv_rest_job( false ),
	);
}

/** "Scan again": forgets the scan, so the next list makes a new one. */
function varconv_rest_scan(): array {
	delete_transient( VARCONV_SCAN_TRANSIENT );
	return array( 'done' => true );
}

/**
 * The bulk job for the app: whether it runs, its progress while it does, and its summary once it has finished. A
 * finished job is forgotten once it has been reported, so its summary shows once.
 *
 * @param bool $worked Whether this request ran a batch; the app asks again sooner then.
 */
function varconv_rest_job( bool $worked ): ?array {
	$job = varconv_job();
	if ( ! $job ) {
		return null;
	}
	$running = varconv_job_running( $job );
	if ( ! $running ) {
		delete_option( VARCONV_JOB_OPTION );
	}
	return array(
		'kind'    => $job['kind'],
		'running' => $running,
		'worked'  => $worked,
		'status'  => varconv_job_status( $job ),
		'text'    => $running ? varconv_job_text( $job ) : '',
		'html'    => $running ? '' : varconv_job_html( $job ),
		'stopped' => (bool) $job['stopped'],
		'done'    => (int) $job['done'],
		'total'   => (int) $job['total'],
	);
}

/** Runs the job's next batch and returns its progress. The app calls this while any tab of the page is open. */
function varconv_rest_job_step(): array {
	return array( 'job' => varconv_rest_job( varconv_job_step() ) );
}

function varconv_rest_job_stop(): array {
	varconv_job_stop();
	return array( 'job' => varconv_rest_job( false ) );
}

/** One member of a group, as its card shows it. */
function varconv_rest_member( array $m, bool $checked ): array {
	$id = (int) $m['id'];
	$price = (string) ( $m['price'] ?? '' );
	$cats = array_map( 'intval', $m['cats'] ?? array() );
	return array_merge(
		array(
			'id'        => $id,
			'sku'       => (string) $m['sku'],
			'title'     => varconv_plain( (string) ( $m['title'] ?? $m['name'] ) ),
			'label'     => (string) $m['label'],
			'group'     => (string) ( $m['group'] ?? '' ),
			'price'     => '' !== $price ? (float) $price : null,
			'priceText' => '' !== $price ? varconv_price_text( (float) $price ) : '',
			'stock'     => (string) ( $m['stock'] ?? '' ),
			'stockText' => varconv_stock_text( (string) ( $m['stock'] ?? '' ) ),
			'state'     => varconv_status_text( $id ),
			'cats'      => $cats,
			'catNames'  => implode( ', ', array_map( 'varconv_cat_name', $cats ) ),
			'editUrl'   => (string) get_edit_post_link( $id, 'url' ),
			'checked'   => $checked,
		),
		varconv_rest_image( (int) get_post_thumbnail_id( $id ) )
	);
}

/**
 * A group for the list and its card: the scan's group, each member with what the card shows, the categories to
 * choose from when the members' differ, and the SKU starts to select by.
 *
 * @param int[] $excluded Members the admin unchecked.
 */
function varconv_rest_group( array $c, array $excluded = array(), bool $skipped = false ): array {
	$excluded = array_map( 'intval', $excluded );
	$members = array();
	$cat_ids = array();
	$cat_sets = array();
	foreach ( $c['members'] as $m ) {
		$members[] = varconv_rest_member( $m, ! in_array( (int) $m['id'], $excluded, true ) );
		$ids = array_map( 'intval', $m['cats'] ?? array() );
		sort( $ids );
		$cat_sets[ implode( ',', $ids ) ] = true;
		$cat_ids = array_merge( $cat_ids, $ids );
	}
	$cat_ids = array_values( array_unique( $cat_ids ) );
	$notes = array();
	foreach ( $c['notes'] as $kind => $note ) {
		$notes[] = 'price' === $kind
			? array( 'kind' => $kind, 'lead' => __( 'Prices differ:', 'variation-converter-for-woocommerce' ), 'text' => varconv_price_note( $c['members'] ) )
			: array( 'kind' => $kind, 'lead' => '', 'text' => (string) $note );
	}
	$badges = array();
	foreach ( array_intersect_key( varconv_note_badges(), $c['notes'] ) as $kind => $label ) {
		$badges[] = array( 'kind' => $kind, 'label' => $label );
	}
	$bands = array();
	foreach ( varconv_sku_bands( array_column( $c['members'], 'sku' ) ) as $prefix => $n ) {
		$bands[] = array( 'prefix' => (string) $prefix, 'count' => (int) $n );
	}
	return array(
		'key'            => (string) $c['key'],
		'name'           => (string) $c['name'],
		'brand'          => (string) ( $c['brand'] ?? '' ),
		'sku'            => (string) $c['sku'],
		'attribute'      => (string) $c['attribute'],
		'groupAttribute' => (string) ( $c['group_attribute'] ?? '' ),
		'grid'           => array_values( array_map( 'strval', $c['grid'] ?? array() ) ),
		'manual'         => ! empty( $c['manual'] ),
		'skipped'        => $skipped,
		'members'        => $members,
		'notes'          => $notes,
		'badges'         => $badges,
		'bands'          => $bands,
		'cats'           => array_map( static fn( $id ) => array( 'id' => $id, 'name' => varconv_cat_name( $id ) ), $cat_ids ),
		'catsDiffer'     => count( $cat_sets ) > 1 && (bool) $cat_ids,
		'chosenCats'     => array_map( 'intval', $c['members'][0]['cats'] ?? array() ),
	);
}

/** The Combine tab's list: the groups the view, search and filters show, one page of them, and what surrounds them. */
function varconv_rest_combine( WP_REST_Request $request ): array {
	$scan = varconv_candidates();
	$f = varconv_filters( $request->get_params() );
	$groups = varconv_filtered( $scan, $f );
	$per_page = varconv_per_page();
	$total = count( $groups );
	$pages = max( 1, (int) ceil( $total / $per_page ) );
	$page = min( $pages, max( 1, absint( $request->get_param( 'paged' ) ) ) );
	$shown = array_slice( $groups, ( $page - 1 ) * $per_page, $per_page );
	$open = sanitize_key( (string) $request->get_param( 'open' ) );
	// A group opened from a link or after part of it was created may be on another page: it comes first then.
	if ( '' !== $open && ! in_array( $open, array_column( $shown, 'key' ), true ) ) {
		foreach ( $groups as $c ) {
			if ( $c['key'] === $open ) {
				array_unshift( $shown, $c );
			}
		}
	}
	$excluded = varconv_excluded();
	$skipped = 'skipped' === $f['view'];
	$brands = array();
	foreach ( array_merge( $scan['collections'], (array) ( $scan['skipped'] ?? array() ) ) as $c ) {
		foreach ( varconv_brand_list( $c ) as $brand ) {
			$brands[ strtolower( $brand ) ] = $brand;
		}
	}
	natcasesort( $brands );
	$loose = $skipped ? array() : varconv_loose_matching( (array) ( $scan['loose'] ?? array() ), $f['s'] );
	$loose_shown = array();
	foreach ( array_slice( $loose, 0, VARCONV_LOOSE_MAX ) as $p ) {
		$loose_shown[] = array(
			'id'        => (int) $p['id'],
			'title'     => (string) $p['title'],
			'state'     => varconv_status_text( (int) $p['id'] ),
			'sku'       => (string) $p['sku'],
			'priceText' => '' !== (string) $p['price'] ? varconv_price_text( (float) $p['price'] ) : '',
			'editUrl'   => (string) get_edit_post_link( (int) $p['id'], 'url' ),
		);
	}
	$filtered = '' !== $f['s'] || '' !== $f['brand'] || 2 !== $f['min'] || '' !== $f['warn'];
	return array(
		'counts'   => array(
			''        => count( $scan['collections'] ),
			'ready'   => count( array_filter( $scan['collections'], static fn( $c ) => ! $c['notes'] ) ),
			'review'  => count( array_filter( $scan['collections'], static fn( $c ) => (bool) $c['notes'] ) ),
			'skipped' => count( (array) ( $scan['skipped'] ?? array() ) ),
		),
		/* translators: %s: time since the scan, e.g. "5 mins". */
		'scanned'  => sprintf( __( 'Scanned %s ago', 'variation-converter-for-woocommerce' ), human_time_diff( (int) $scan['time'] ) ),
		'brands'   => array_values( $brands ),
		'total'    => $total,
		'products' => array_sum( array_map( static fn( $c ) => count( $c['members'] ), $groups ) ),
		'pages'    => $pages,
		'page'     => $page,
		'keys'     => array_column( $groups, 'key' ),
		'groups'   => array_map( static fn( $c ) => varconv_rest_group( $c, $excluded[ $c['key'] ] ?? array(), $skipped ), $shown ),
		'empty'    => varconv_empty_text( $scan, $f, $filtered ),
		'filtered' => $filtered,
		'loose'    => array( 'total' => count( $loose ), 'items' => $loose_shown ),
	);
}

/**
 * The card for products picked by hand (combine=IDs, from Products > All Products or the Combine button) or for
 * groups combined into one product with two attributes (grid=keys, with p<ID> for a product on its own).
 */
function varconv_rest_card( WP_REST_Request $request ): array {
	$ids = array_slice( varconv_rest_ids( $request->get_param( 'combine' ) ), 0, 200 );
	if ( $ids ) {
		$found = varconv_products_by_id( $ids );
		$enough = count( $found['products'] ) >= 2;
		return array(
			'lead'    => __( 'Combine the products you selected into one variable product. Check the name, attribute and labels, then create it.', 'variation-converter-for-woocommerce' ),
			'notices' => array_values( array_filter( array(
				varconv_left_out_html( array_combine( $found['refused'], array_map( 'varconv_combine_problem', $found['refused'] ) ) ?: array() ),
				varconv_made_simple_html( array_column( $found['products'], 'id' ) ),
			) ) ),
			'empty'   => $enough ? '' : __( 'Select at least two products that can be combined.', 'variation-converter-for-woocommerce' ),
			'group'   => $enough ? varconv_rest_group( varconv_manual( $found['products'] ) ) : null,
		);
	}
	$keys = array_slice( varconv_rest_keys( $request->get_param( 'grid' ) ), 0, VARCONV_GRID_MAX );
	$scan = varconv_candidates();
	$by_key = array_column( $scan['collections'], null, 'key' );
	// Products in no group come as p<ID>, each a group of its own, labeled from the labels the groups use.
	$product_ids = array_map( static fn( $key ) => (int) substr( $key, 1 ), array_filter( $keys, static fn( $key ) => (bool) preg_match( '/^p\d+$/', $key ) ) );
	$labels = array();
	foreach ( $keys as $key ) {
		foreach ( $by_key[ $key ]['members'] ?? array() as $m ) {
			$labels[ strtolower( $m['label'] ) ] = $m['label'];
		}
	}
	foreach ( $product_ids ? varconv_products_by_id( $product_ids )['products'] : array() as $p ) {
		$by_key[ 'p' . $p['id'] ] = varconv_single_group( $p, array_values( $labels ) );
	}
	$collections = array_values( array_filter( array_map( static fn( $key ) => $by_key[ $key ] ?? null, $keys ) ) );
	$notices = array();
	if ( count( $collections ) < count( $keys ) ) {
		$notices[] = varconv_rest_notice( 'warning', esc_html__( 'Some of the selected groups or products can no longer be combined, because they were created, skipped or changed. They are left out.', 'variation-converter-for-woocommerce' ) );
	}
	$enough = count( $collections ) >= 2;
	$excluded = array();
	if ( $enough ) {
		$notices[] = varconv_made_simple_html( $product_ids );
		// Products unchecked in a group start unchecked here too.
		foreach ( $collections as $c ) {
			$excluded = array_merge( $excluded, varconv_excluded()[ $c['key'] ] ?? array() );
		}
	}
	return array(
		'lead'    => __( 'Combine these groups into one variable product with two attributes: one from the groups (for example Flavor) and one within each group (for example Size). Each product becomes the variation for its pair of labels; pairs that no product has are not sold. A product you picked on its own is a group by itself. Check the names and labels, then create it.', 'variation-converter-for-woocommerce' ),
		'notices' => array_values( array_filter( $notices ) ),
		'empty'   => $enough ? '' : __( 'Select at least two groups or products to combine into one product.', 'variation-converter-for-woocommerce' ),
		'group'   => $enough ? varconv_rest_group( varconv_grid( $collections ), $excluded ) : null,
	);
}

/**
 * Creates the variable product a card describes: its name, one or two attributes, and its members in order, each with
 * its labels and whether it is checked. Answers with the new product, and the group that holds what is left of a
 * group created in part.
 */
function varconv_rest_create( WP_REST_Request $request ): array {
	$key = sanitize_key( (string) $request->get_param( 'key' ) );
	$two = null !== $request->get_param( 'groupAttribute' );
	$members = array();
	$pairs = array();
	$all = array();
	foreach ( (array) $request->get_param( 'members' ) as $row ) {
		$id = is_array( $row ) ? absint( $row['id'] ?? 0 ) : 0;
		if ( ! $id ) {
			continue;
		}
		$all[] = $id;
		if ( empty( $row['checked'] ) ) {
			continue;
		}
		$label = sanitize_text_field( (string) ( $row['label'] ?? '' ) );
		$members[ $id ] = $label;
		$pairs[ $id ] = array( sanitize_text_field( (string) ( $row['group'] ?? '' ) ), $label );
	}
	$name = varconv_rest_text( $request, 'name' );
	$attribute = varconv_rest_text( $request, 'attribute' );
	$categories = varconv_rest_ids( $request->get_param( 'categories' ) );
	$id = $two
		? varconv_create_grid( $name, varconv_rest_text( $request, 'groupAttribute' ), $attribute, $pairs, $categories )
		: varconv_create( $name, $attribute, $members, $categories );
	// The checked products are in the new product now; what stays unchecked is the rest of the group.
	varconv_set_excluded( $key, array_keys( $members ), false );
	return array(
		'notice' => varconv_created_notice( $id ),
		'next'   => varconv_group_of( array_diff( $all, array_keys( $members ) ) ),
		'whole'  => count( $members ) === count( $all ),
	);
}

/** The notice for a product just created; "undo" is the product its Undo button undoes. */
function varconv_created_notice( int $id ): array {
	return varconv_rest_notice(
		'success',
		wp_kses_post( sprintf(
			/* translators: 1: edit link, 2: product name, 3: view link. */
			__( 'Created <a href="%1$s">%2$s</a> (<a href="%3$s">view</a>). Its simple products are now hidden.', 'variation-converter-for-woocommerce' ),
			esc_url( (string) get_edit_post_link( $id ) ),
			esc_html( get_post_field( 'post_title', $id, 'raw' ) ),
			esc_url( (string) get_permalink( $id ) )
		) ),
		array( 'undo' => $id )
	);
}

/**
 * The key of the group, in a new scan, that holds any of $ids: what is left of a group after part of it was created,
 * for the page to open next. Empty when they are in no group.
 *
 * @param int[] $ids
 */
function varconv_group_of( array $ids ): string {
	if ( ! $ids ) {
		return '';
	}
	foreach ( varconv_candidates()['collections'] as $c ) {
		if ( array_intersect( $ids, array_map( static fn( $m ) => (int) $m['id'], $c['members'] ) ) ) {
			return (string) $c['key'];
		}
	}
	return '';
}

/** Undo of a product just created, from its notice. */
function varconv_rest_undo_created( WP_REST_Request $request ): array {
	varconv_undo( absint( $request->get_param( 'product' ) ) );
	return array( 'notice' => varconv_rest_notice( 'success', esc_html__( 'Undone. The simple products are visible again; scan again to see them as a group.', 'variation-converter-for-woocommerce' ) ) );
}

/** Remembers products unchecked in a group, so its card shows them unchecked next time and bulk runs leave them out. */
function varconv_rest_exclude( WP_REST_Request $request ): array {
	varconv_set_excluded( sanitize_key( (string) $request->get_param( 'key' ) ), varconv_rest_ids( $request->get_param( 'ids' ) ), (bool) $request->get_param( 'on' ) );
	return array( 'done' => true );
}

/** The groups a request acts on: those checked, or every group its filters show ("Select all N groups that match"). */
function varconv_rest_picked_keys( WP_REST_Request $request ): array {
	if ( $request->get_param( 'all' ) ) {
		return array_column( varconv_filtered( varconv_candidates(), varconv_filters( (array) $request->get_param( 'filters' ) ) ), 'key' );
	}
	return varconv_rest_keys( $request->get_param( 'keys' ) );
}

/**
 * Where "Combine into one product" and "Add to a combined product" lead for the checked groups and products: the
 * query the app opens next. One group opens its card; one group with products, or products alone, open a card for
 * those products; two or more groups open the two-attribute card, where each product is a group of its own.
 */
function varconv_rest_pick( WP_REST_Request $request ): array {
	$keys = varconv_rest_picked_keys( $request );
	$ids = varconv_rest_ids( $request->get_param( 'ids' ) );
	if ( 'add' === $request->get_param( 'action' ) ) {
		$add = $ids;
		foreach ( $keys as $key ) {
			$add = array_merge( $add, varconv_group_member_ids( $key ) );
		}
		$add = array_values( array_unique( $add ) );
		if ( ! $add ) {
			throw new RuntimeException( esc_html__( 'Select the groups or products to add to a combined product.', 'variation-converter-for-woocommerce' ) );
		}
		if ( count( $add ) > VARCONV_ADD_MAX ) {
			/* translators: %d: most products added at once. */
			throw new RuntimeException( esc_html( sprintf( __( 'Add at most %d products at a time.', 'variation-converter-for-woocommerce' ), VARCONV_ADD_MAX ) ) );
		}
		return array( 'query' => array( 'add' => implode( ',', $add ) ) );
	}
	if ( 1 === count( $keys ) && ! $ids ) {
		return array( 'query' => array( 'open' => $keys[0] ), 'keep' => true );
	}
	if ( count( $keys ) + count( $ids ) < 2 ) {
		throw new RuntimeException( esc_html__( 'Select at least two groups or products to combine into one product.', 'variation-converter-for-woocommerce' ) );
	}
	if ( count( $keys ) <= 1 ) {
		$members = $keys ? varconv_group_member_ids( $keys[0] ) : array();
		return array( 'query' => array( 'combine' => implode( ',', array_merge( $members, $ids ) ) ) );
	}
	$all = array_merge( $keys, array_map( static fn( $id ) => 'p' . $id, $ids ) );
	if ( count( $all ) > VARCONV_GRID_MAX ) {
		/* translators: %d: most groups and products that can be combined at once. */
		throw new RuntimeException( esc_html( sprintf( __( 'Select at most %d groups and products to combine into one product.', 'variation-converter-for-woocommerce' ), VARCONV_GRID_MAX ) ) );
	}
	return array( 'query' => array( 'grid' => implode( ',', $all ) ) );
}

/** The IDs of a scan group's products, without those unchecked in its card. @return int[] */
function varconv_group_member_ids( string $key ): array {
	$by_key = array_column( varconv_candidates()['collections'], null, 'key' );
	$excluded = array_map( 'intval', varconv_excluded()[ $key ] ?? array() );
	$ids = array();
	foreach ( $by_key[ $key ]['members'] ?? array() as $m ) {
		if ( ! in_array( (int) $m['id'], $excluded, true ) ) {
			$ids[] = (int) $m['id'];
		}
	}
	return $ids;
}

/**
 * Acts on the checked groups, or on all that match: skip, restore, create one as suggested (its row's Create), or
 * create them all as a background job (includes/job.php).
 */
function varconv_rest_bulk( WP_REST_Request $request ): array {
	$action = sanitize_key( (string) $request->get_param( 'action' ) );
	$keys = varconv_rest_picked_keys( $request );
	if ( ! $keys ) {
		throw new RuntimeException( esc_html__( 'Select at least one group first.', 'variation-converter-for-woocommerce' ) );
	}
	if ( 'skip' === $action ) {
		foreach ( $keys as $key ) {
			varconv_dismiss( $key );
		}
		return array( 'notice' => varconv_rest_notice( 'info', esc_html( sprintf(
			/* translators: %d: number of groups skipped. */
			_n( 'Skipped %d group. Later scans leave it out; the Skipped view lists it.', 'Skipped %d groups. Later scans leave them out; the Skipped view lists them.', count( $keys ), 'variation-converter-for-woocommerce' ),
			count( $keys )
		) ) ) );
	}
	if ( 'restore' === $action ) {
		varconv_restore( $keys );
		return array( 'notice' => varconv_rest_notice( 'success', esc_html( sprintf(
			/* translators: %d: number of groups restored. */
			_n( 'Restored %d group to the list.', 'Restored %d groups to the list.', count( $keys ), 'variation-converter-for-woocommerce' ),
			count( $keys )
		) ) ) );
	}
	if ( 'create_one' === $action ) {
		$key = $keys[0];
		$result = varconv_bulk_create( array( $key ) )['results'][ $key ] ?? null;
		if ( ! $result ) {
			throw new RuntimeException( esc_html__( 'This group is no longer in the scan. Scan again to see the current groups.', 'variation-converter-for-woocommerce' ) );
		}
		if ( 'made' !== $result['status'] ) {
			// Needs a label checked, or the products changed since the scan: review it in its card.
			return array( 'open' => $key, 'notice' => varconv_rest_notice( 'error', esc_html( (string) $result['message'] ) ) );
		}
		return array( 'notice' => varconv_created_notice( (int) $result['id'] ) );
	}
	if ( 'create' !== $action ) {
		throw new RuntimeException( esc_html__( 'Choose a bulk action first.', 'variation-converter-for-woocommerce' ) );
	}
	varconv_job_start( $keys );
	// The app asks for the first batch at once; answering now shows the progress and Stop button straight away.
	return array( 'job' => varconv_rest_job( true ) );
}
