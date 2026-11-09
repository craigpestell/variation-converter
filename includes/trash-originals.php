<?php
/**
 * Trash originals: after a combine, the merchant can move the hidden simple products a variable product was made from
 * to the Trash. Nothing is deleted for good here: WordPress empties the Trash itself after EMPTY_TRASH_DAYS (30 by
 * default), or the merchant empties it, and the plugin refuses when the Trash is turned off.
 *
 * Before an original goes to the Trash:
 *   - its reviews move to the variable product, so they show there and count toward its rating (each keeps the
 *     original's ID in _varconv_review_from, so they can move back);
 *   - its slug is kept in _varconv_old_slug on its variation and on the variable product, so its old page keeps sending
 *     shoppers and search engines (301) to the variation's URL even once it is deleted for good (redirect.php).
 * Past orders keep their line items (name, SKU, price) whatever happens to the product.
 *
 * While an original is in the Trash, Undo of the combine and Make simple of its variation restore it from the Trash
 * first, with its reviews. Restoring it from the Trash by hand also takes its reviews back. Once it has been deleted
 * for good, the combine can no longer be undone, since Undo would delete the variation that is now its only copy.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const VARCONV_OLD_SLUG_META = '_varconv_old_slug';
const VARCONV_REVIEW_FROM_META = '_varconv_review_from';

/**
 * The original simple products of a combined variable product, by state: still hidden, in the Trash, or deleted for
 * good (listed in the combine's record but no longer there).
 *
 * @return array{hidden:int[],trash:int[],gone:int[]}
 */
function varconv_originals( int $parent_id ): array {
	$out = array( 'hidden' => array(), 'trash' => array(), 'gone' => array() );
	$listed = (array) json_decode( (string) get_post_meta( $parent_id, VARCONV_META, true ), true );
	$ids = array_values( array_unique( array_merge( array_map( 'intval', $listed ), varconv_members( $parent_id ) ) ) );
	foreach ( $ids as $id ) {
		$status = get_post_status( $id );
		if ( false === $status || 'product' !== get_post_type( $id ) ) {
			$out['gone'][] = $id;
		} elseif ( 'trash' === $status ) {
			$out['trash'][] = $id;
		} elseif ( (int) get_post_meta( $id, VARCONV_PARENT_META, true ) === $parent_id ) {
			$out['hidden'][] = $id;
		}
	}
	return $out;
}

/**
 * Moves a combined product's hidden originals to the Trash, after moving their reviews to the variable product and
 * keeping their slugs for the redirect.
 *
 * @return int Originals moved to the Trash.
 */
function varconv_trash_originals( int $parent_id ): int {
	$parent = wc_get_product( $parent_id );
	if ( ! $parent instanceof WC_Product_Variable || '' === (string) $parent->get_meta( VARCONV_META ) ) {
		/* translators: %d: product ID. */
		throw new RuntimeException( esc_html( sprintf( __( 'Product %d is not a variable product combined by Variation Converter.', 'variation-converter-for-woocommerce' ), $parent_id ) ) );
	}
	if ( ! EMPTY_TRASH_DAYS ) {
		throw new RuntimeException( esc_html__( 'The Trash is turned off on this site (EMPTY_TRASH_DAYS is 0), so the originals would be deleted for good. They were left hidden.', 'variation-converter-for-woocommerce' ) );
	}
	$hidden = varconv_originals( $parent_id )['hidden'];
	if ( ! $hidden ) {
		/* translators: %s: product name. */
		throw new RuntimeException( esc_html( sprintf( __( '%s has no hidden originals left.', 'variation-converter-for-woocommerce' ), $parent->get_name( 'edit' ) ) ) );
	}
	$twins = array_flip( varconv_twins( $parent_id, $hidden ) );
	$done = 0;
	foreach ( $hidden as $id ) {
		$slug = (string) get_post_field( 'post_name', $id, 'raw' );
		if ( '' !== $slug ) {
			add_post_meta( $parent_id, VARCONV_OLD_SLUG_META, $slug );
			if ( isset( $twins[ $id ] ) ) {
				update_post_meta( $twins[ $id ], VARCONV_OLD_SLUG_META, $slug );
			}
		}
		varconv_move_reviews( $id, $parent_id );
		if ( wp_trash_post( $id ) ) {
			++$done;
		}
	}
	varconv_review_counts( $parent_id );
	wc_delete_product_transients( $parent_id );
	delete_transient( VARCONV_SCAN_TRANSIENT );
	/**
	 * Fires after a combined product's hidden originals were moved to the Trash.
	 *
	 * @param int   $parent_id The variable product.
	 * @param int[] $ids       The originals.
	 */
	do_action( 'varconv_originals_trashed', $parent_id, $hidden );
	return $done;
}

/**
 * Trash originals for several History rows. Rows that are not combines, or have nothing hidden left, are reported and
 * the rest carry on.
 *
 * @param int[] $ids Variable products.
 * @return array{done:int,products:int,errors:string[]}
 */
function varconv_trash_originals_many( array $ids ): array {
	$out = array( 'done' => 0, 'products' => 0, 'errors' => array() );
	$combined = varconv_created();
	foreach ( array_unique( array_map( 'intval', $ids ) ) as $id ) {
		if ( ! in_array( $id, $combined, true ) ) {
			/* translators: %s: product name. */
			$out['errors'][] = sprintf( __( '%s was not made by combining, so it has no hidden originals.', 'variation-converter-for-woocommerce' ), get_post_field( 'post_title', $id, 'raw' ) ?: '#' . $id );
			continue;
		}
		try {
			$out['products'] += varconv_trash_originals( $id );
			++$out['done'];
		} catch ( RuntimeException $e ) {
			$out['errors'][] = varconv_message( $e );
		}
	}
	return $out;
}

/**
 * Restores an original from the Trash with the status it had (the untrashed_post hook below brings its reviews back
 * and drops its kept slug). It shares its SKU and GTIN with its variation, which WooCommerce would otherwise clear on
 * restore.
 */
function varconv_untrash_original( int $id ): void {
	if ( 'trash' !== get_post_status( $id ) ) {
		return;
	}
	// Priority 98, so callers that already allow duplicates at 99 (Make simple) keep theirs.
	add_filter( 'wc_product_has_unique_sku', '__return_false', 98 );
	add_filter( 'wc_product_has_global_unique_id', '__return_false', 98 );
	wp_untrash_post( $id );
	remove_filter( 'wc_product_has_unique_sku', '__return_false', 98 );
	remove_filter( 'wc_product_has_global_unique_id', '__return_false', 98 );
}

// An original back from the Trash, by Undo, Make simple or by hand, gets the status it had (WordPress 5.6+ would
// make it a draft), so it is not a draft after a later Undo or Make simple.
add_filter( 'wp_untrash_post_status', 'varconv_original_untrash_status', 10, 3 );
function varconv_original_untrash_status( $status, $post_id, $previous ) {
	if ( 'product' === get_post_type( (int) $post_id ) && '' !== (string) get_post_meta( (int) $post_id, VARCONV_PARENT_META, true ) && varconv_status_ok( (string) $previous ) ) {
		return $previous;
	}
	return $status;
}

// An original back from the Trash, by Undo, Make simple or by hand: its reviews and its page are its own again.
add_action( 'untrashed_post', 'varconv_original_untrashed' );
function varconv_original_untrashed( $post_id ): void {
	$id = (int) $post_id;
	$parent_id = (int) get_post_meta( $id, VARCONV_PARENT_META, true );
	if ( ! $parent_id || 'product' !== get_post_type( $id ) ) {
		return;
	}
	$back = get_comments(
		array(
			'post_id'    => $parent_id,
			'status'     => 'all',
			'type'       => 'all',
			'fields'     => 'ids',
			'meta_key'   => VARCONV_REVIEW_FROM_META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- a few reviews of one product.
			'meta_value' => (string) $id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- as above.
		)
	);
	foreach ( $back as $comment_id ) {
		varconv_comment_to( (int) $comment_id, $id );
		delete_comment_meta( (int) $comment_id, VARCONV_REVIEW_FROM_META );
	}
	$slug = (string) get_post_meta( $id, '_wp_desired_post_slug', true ) ?: (string) get_post_field( 'post_name', $id, 'raw' );
	if ( '' !== $slug ) {
		delete_post_meta( $parent_id, VARCONV_OLD_SLUG_META, $slug );
		foreach ( varconv_twins( $parent_id, array( $id ) ) as $variation_id => $simple_id ) {
			delete_post_meta( $variation_id, VARCONV_OLD_SLUG_META );
		}
	}
	varconv_review_counts( $id );
	varconv_review_counts( $parent_id );
}

/** Moves every review (and any other comment) on product $from to product $to, noting where each came from. */
function varconv_move_reviews( int $from, int $to ): void {
	$ids = get_comments(
		array(
			'post_id' => $from,
			'status'  => 'all',
			'type'    => 'all',
			'fields'  => 'ids',
		)
	);
	foreach ( $ids as $comment_id ) {
		if ( varconv_comment_to( (int) $comment_id, $to ) ) {
			update_comment_meta( (int) $comment_id, VARCONV_REVIEW_FROM_META, (string) $from );
		}
	}
	varconv_review_counts( $from );
}

function varconv_comment_to( int $comment_id, int $post_id ): bool {
	return 1 === wp_update_comment(
		array(
			'comment_ID'      => $comment_id,
			'comment_post_ID' => $post_id,
		)
	);
}

/** A product's comment count, average rating and review count, after reviews moved. */
function varconv_review_counts( int $id ): void {
	wp_update_comment_count_now( $id );
	if ( class_exists( 'WC_Comments' ) && 'product' === get_post_type( $id ) ) {
		WC_Comments::clear_transients( $id );
	}
}

/**
 * Where the old URL of an original moved to the Trash (or deleted from it) goes: its variation's URL, or the variable
 * product's when the variation is not enabled or is gone. Empty when $slug names no such product, or its variable
 * product is not published.
 */
function varconv_trashed_original_url( string $slug ): string {
	global $wpdb;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$rows = $wpdb->get_results( $wpdb->prepare(
		"SELECT p.ID, p.post_type, p.post_parent FROM {$wpdb->postmeta} m JOIN {$wpdb->posts} p ON p.ID = m.post_id
		WHERE m.meta_key = %s AND m.meta_value = %s AND p.post_type IN ( 'product', 'product_variation' )
		ORDER BY p.post_type = 'product_variation' DESC, p.ID DESC",
		VARCONV_OLD_SLUG_META,
		sanitize_title( $slug )
	) );
	foreach ( $rows as $row ) {
		$variation = 'product_variation' === $row->post_type;
		$parent_id = $variation ? (int) $row->post_parent : (int) $row->ID;
		if ( 'publish' !== get_post_status( $parent_id ) ) {
			continue;
		}
		$url = $variation ? varconv_variant_url( (int) $row->ID ) : '';
		/** This filter is documented in includes/redirect.php */
		return (string) apply_filters(
			'varconv_redirect_url',
			'' !== $url ? $url : (string) get_permalink( $parent_id ),
			$variation ? (int) get_post_meta( (int) $row->ID, VARCONV_TWIN_META, true ) : 0,
			$parent_id
		);
	}
	return '';
}
