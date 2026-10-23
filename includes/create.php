<?php
/**
 * Reading the catalog for the scan, and making (or undoing) a variable product from an approved collection.
 *
 * Markers:
 *   _varconv_collection         on the new variable product: JSON list of the source simple product IDs
 *   _varconv_collection_parent  on each source: the variable product made from it
 *   _varconv_collection_hidden  on each source: its catalog visibility before it was hidden
 * The variations reuse the sources' SKUs, prices, stock and images (same attachments), so each SKU exists twice
 * (the hidden simple product and the variation) while the sources are kept for Undo.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const VARCONV_META = '_varconv_collection';
const VARCONV_PARENT_META = '_varconv_collection_parent';
const VARCONV_HIDDEN_META = '_varconv_collection_hidden';
const VARCONV_DISMISSED_OPTION = 'varconv_dismissed';
const VARCONV_SCAN_TRANSIENT = 'varconv_scan';
// Members the admin unchecked, by collection key; bulk runs leave them out too.
const VARCONV_EXCLUDED_OPTION = 'varconv_excluded';
// Held while a bulk run creates products; its value is the time it expires.
const VARCONV_LOCK_OPTION = 'varconv_lock';

/**
 * Published simple products with a SKU, shown in the catalog, not already in a collection or made by a split.
 *
 * @return array<int,array{id:int,sku:string,name:string,title:string,price:string,stock:string,cats:int[],brands:string[]}>
 */
function varconv_products(): array {
	global $wpdb;
	// One query for the whole catalog: WC_Product_Query would load every product object. The result is cached by
	// varconv_candidates().
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$rows = $wpdb->get_results( $wpdb->prepare(
		"SELECT p.ID, p.post_title, sku.meta_value AS sku, price.meta_value AS price, stock.meta_value AS stock
		FROM {$wpdb->posts} p
		JOIN {$wpdb->term_relationships} tr ON tr.object_id = p.ID
		JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id AND tt.taxonomy = 'product_type'
		JOIN {$wpdb->terms} t ON t.term_id = tt.term_id AND t.slug = 'simple'
		JOIN {$wpdb->postmeta} sku ON sku.post_id = p.ID AND sku.meta_key = '_sku' AND sku.meta_value <> ''
		LEFT JOIN {$wpdb->postmeta} price ON price.post_id = p.ID AND price.meta_key = '_regular_price'
		LEFT JOIN {$wpdb->postmeta} stock ON stock.post_id = p.ID AND stock.meta_key = '_stock_status'
		WHERE p.post_type = 'product' AND p.post_status = 'publish'
		AND p.ID NOT IN ( SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key IN ( %s, %s ) )
		AND p.ID NOT IN ( SELECT vr.object_id FROM {$wpdb->term_relationships} vr
			JOIN {$wpdb->term_taxonomy} vt ON vt.term_taxonomy_id = vr.term_taxonomy_id AND vt.taxonomy = 'product_visibility'
			JOIN {$wpdb->terms} v ON v.term_id = vt.term_id AND v.slug = 'exclude-from-catalog' )",
		VARCONV_PARENT_META,
		VARCONV_SPLIT_FROM_META
	) );
	$cats = array();
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	foreach ( $wpdb->get_results(
		"SELECT tr.object_id, tt.term_id FROM {$wpdb->term_relationships} tr
		JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id AND tt.taxonomy = 'product_cat'"
	) as $row ) {
		$cats[ (int) $row->object_id ][] = (int) $row->term_id;
	}
	$brands = array();
	if ( taxonomy_exists( 'product_brand' ) ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		foreach ( $wpdb->get_results(
			"SELECT tr.object_id, t.name FROM {$wpdb->term_relationships} tr
			JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id AND tt.taxonomy = 'product_brand'
			JOIN {$wpdb->terms} t ON t.term_id = tt.term_id"
		) as $row ) {
			$brands[ (int) $row->object_id ][] = html_entity_decode( (string) $row->name, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		}
	}
	$products = array();
	foreach ( $rows as $row ) {
		$id = (int) $row->ID;
		$products[] = array(
			'id'    => $id,
			'sku'   => (string) $row->sku,
			// Plain text, without a leading SKU.
			'name'  => varconv_clean_name( (string) $row->post_title, (string) $row->sku ),
			'title' => (string) $row->post_title,
			'price' => (string) $row->price,
			'stock' => (string) $row->stock,
			'cats'   => $cats[ $id ] ?? array(),
			'brands' => $brands[ $id ] ?? array(),
		);
	}
	return $products;
}

/** Every brand's name (WooCommerce Brands); titles often start with one, which is not a shared product name. */
function varconv_brand_names(): array {
	if ( ! taxonomy_exists( 'product_brand' ) ) {
		return array();
	}
	$names = get_terms( array( 'taxonomy' => 'product_brand', 'hide_empty' => false, 'fields' => 'names' ) );
	return is_wp_error( $names ) ? array() : $names;
}

/** The scan, kept for 30 minutes; creating, undoing or dismissing clears it. */
function varconv_candidates( bool $fresh = false ): array {
	$cached = $fresh ? false : get_transient( VARCONV_SCAN_TRANSIENT );
	if ( is_array( $cached ) ) {
		return $cached;
	}
	/**
	 * Filters the simple products the scan groups, for example to leave out testers or display units.
	 *
	 * @param array $products Each with id, sku, name, title, price, stock, cats (term IDs) and brands (names).
	 */
	$products = (array) apply_filters( 'varconv_scan_products', varconv_products() );
	/**
	 * Filters the brand names a product name can start with. A shared brand is not a shared product name, so
	 * "Acme Plug" and "Acme Wand" are not proposed as variants. Defaults to the WooCommerce Brands terms.
	 *
	 * @param string[] $names
	 */
	$brand_names = (array) apply_filters( 'varconv_brand_names', varconv_brand_names() );
	$dismissed = (array) get_option( VARCONV_DISMISSED_OPTION, array() );
	/**
	 * Filters the proposed groups, for example to add products to a group or to drop groups.
	 *
	 * @param array $collections Each with key, family, name, brand, attribute, sku, members (each with a label) and
	 *                           notes (kind => text).
	 * @param array $products    The products that were scanned.
	 */
	$collections = (array) apply_filters( 'varconv_collections', varconv_scan( $products, $dismissed, $brand_names ), $products );
	$scan = array(
		'time'        => time(),
		'products'    => count( $products ),
		'collections' => $collections,
	);
	set_transient( VARCONV_SCAN_TRANSIENT, $scan, 30 * MINUTE_IN_SECONDS );
	return $scan;
}

/** Variable products made here, newest first. */
function varconv_created(): array {
	global $wpdb;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	return array_map( 'intval', $wpdb->get_col( $wpdb->prepare(
		"SELECT p.ID FROM {$wpdb->posts} p JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = %s
		WHERE p.post_type = 'product' ORDER BY p.ID DESC",
		VARCONV_META
	) ) );
}

/**
 * Makes a published variable product from simple products: one variation per member, in the order given, with the
 * member's SKU, prices, stock, weight and image. The product takes the first member's descriptions, categories,
 * tags and other taxonomies, and is dated just after its newest member so the shop order barely moves. The
 * members are then hidden from the catalog and search.
 *
 * @param array<int,string> $members Simple product ID => variant label.
 * @param int[] $categories Product categories for the new product, each one of the members'; none takes the first
 *                          member's categories.
 * @return int The new product's ID.
 * @throws RuntimeException When the collection cannot be made; nothing is left half made.
 */
function varconv_create( string $name, string $attribute_name, string $sku, array $members, array $categories = array() ): int {
	$name = trim( $name );
	$attribute_name = trim( $attribute_name );
	$sku = trim( $sku );
	$errors = varconv_validate( $name, $attribute_name, $members );
	$sources = array();
	foreach ( $members as $id => $label ) {
		$simple = wc_get_product( (int) $id );
		if ( ! $simple || ! $simple->is_type( 'simple' ) || 'publish' !== $simple->get_status() ) {
			/* translators: %d: product ID. */
			$errors[] = sprintf( __( 'Product %d is not a published simple product.', 'variation-converter' ), $id );
		} elseif ( $simple->get_meta( VARCONV_PARENT_META ) ) {
			/* translators: %d: product ID. */
			$errors[] = sprintf( __( 'Product %d is already in a variable product.', 'variation-converter' ), $id );
		} else {
			$sources[] = array( $simple, trim( (string) $label ) );
		}
	}
	$categories = array_values( array_unique( array_filter( array_map( 'intval', $categories ) ) ) );
	$member_cats = array_merge( array(), ...array_map( static fn( $s ) => array_map( 'intval', $s[0]->get_category_ids( 'edit' ) ), $sources ) );
	if ( array_diff( $categories, $member_cats ) ) {
		$errors[] = __( 'A chosen category is not one of the selected products\' categories.', 'variation-converter' );
	}
	$taken = '' === $sku ? 0 : (int) wc_get_product_id_by_sku( $sku );
	if ( $taken ) {
		/* translators: 1: SKU, 2: product ID. */
		$errors[] = sprintf( __( 'SKU %1$s is already used by product %2$d; change it or leave it empty.', 'variation-converter' ), $sku, $taken );
	}
	if ( $errors ) {
		throw new RuntimeException( esc_html( implode( ' ', array_unique( $errors ) ) ) );
	}

	$first = $sources[0][0];
	$parent_id = 0;
	// The variations reuse the members' SKUs, which WooCommerce otherwise refuses as duplicates.
	add_filter( 'wc_product_has_unique_sku', '__return_false', 99 );
	try {
		$attribute = new WC_Product_Attribute();
		$attribute->set_name( $attribute_name );
		$attribute->set_options( array_map( static fn( $s ) => $s[1], $sources ) );
		$attribute->set_visible( true );
		$attribute->set_variation( true );

		$newest = max( array_map( static fn( $s ) => $s[0]->get_date_created() ? $s[0]->get_date_created()->getTimestamp() : 0, $sources ) );
		$parent = new WC_Product_Variable();
		$parent->set_name( $name );
		$parent->set_status( 'publish' );
		$parent->set_sku( $sku );
		$parent->set_description( $first->get_description( 'edit' ) );
		$parent->set_short_description( $first->get_short_description( 'edit' ) );
		$parent->set_image_id( $first->get_image_id( 'edit' ) );
		$parent->set_gallery_image_ids( array_values( array_unique( array_filter( array_merge(
			$first->get_gallery_image_ids( 'edit' ),
			array_map( static fn( $s ) => (int) $s[0]->get_image_id( 'edit' ), array_slice( $sources, 1 ) )
		) ) ) ) );
		$parent->set_tax_class( $first->get_tax_class( 'edit' ) );
		$parent->set_tax_status( $first->get_tax_status( 'edit' ) );
		$parent->set_shipping_class_id( $first->get_shipping_class_id( 'edit' ) );
		$parent->set_attributes( array( $attribute ) );
		// Set on the product, not only as terms after saving, so WooCommerce doesn't add the default category.
		$parent->set_category_ids( $categories ?: $first->get_category_ids( 'edit' ) );
		$parent->set_tag_ids( $first->get_tag_ids( 'edit' ) );
		if ( $newest ) {
			$parent->set_date_created( $newest + 1 );
		}
		$parent->update_meta_data( VARCONV_META, wp_json_encode( array_map( static fn( $s ) => $s[0]->get_id(), $sources ) ) );
		$parent_id = $parent->save();
		if ( ! $parent_id ) {
			throw new RuntimeException( esc_html__( 'WooCommerce did not save the variable product.', 'variation-converter' ) );
		}
		// Categories and tags were set on the product above.
		$skip = array( 'product_type', 'product_visibility', 'product_shipping_class', 'product_cat', 'product_tag' );
		foreach ( array_diff( get_object_taxonomies( 'product' ), $skip ) as $taxonomy ) {
			$terms = wp_get_object_terms( $first->get_id(), $taxonomy, array( 'fields' => 'ids' ) );
			if ( $terms && ! is_wp_error( $terms ) ) {
				wp_set_object_terms( $parent_id, $terms, $taxonomy );
			}
		}

		$key = sanitize_title( $attribute->get_name() );
		foreach ( $sources as $i => [ $simple, $label ] ) {
			$variation = new WC_Product_Variation();
			$variation->set_parent_id( $parent_id );
			$variation->set_attributes( array( $key => $label ) );
			$variation->set_status( 'publish' );
			$variation->set_menu_order( $i );
			$variation->set_sku( $simple->get_sku( 'edit' ) );
			$variation->set_regular_price( $simple->get_regular_price( 'edit' ) );
			$variation->set_sale_price( $simple->get_sale_price( 'edit' ) );
			$variation->set_date_on_sale_from( $simple->get_date_on_sale_from( 'edit' ) );
			$variation->set_date_on_sale_to( $simple->get_date_on_sale_to( 'edit' ) );
			$variation->set_manage_stock( $simple->get_manage_stock( 'edit' ) );
			$variation->set_stock_quantity( $simple->get_stock_quantity( 'edit' ) );
			$variation->set_stock_status( $simple->get_stock_status( 'edit' ) );
			$variation->set_backorders( $simple->get_backorders( 'edit' ) );
			$variation->set_weight( $simple->get_weight( 'edit' ) );
			$variation->set_length( $simple->get_length( 'edit' ) );
			$variation->set_width( $simple->get_width( 'edit' ) );
			$variation->set_height( $simple->get_height( 'edit' ) );
			$variation->set_image_id( $simple->get_image_id( 'edit' ) );
			if ( ! $variation->save() ) {
				/* translators: %d: product ID. */
				throw new RuntimeException( esc_html( sprintf( __( 'WooCommerce did not save the variation for product %d.', 'variation-converter' ), $simple->get_id() ) ) );
			}
		}
		WC_Product_Variable::sync( $parent_id );
	} catch ( Throwable $e ) {
		remove_filter( 'wc_product_has_unique_sku', '__return_false', 99 );
		if ( $parent_id ) {
			varconv_delete( $parent_id );
		}
		/* translators: %s: the reason. */
		throw new RuntimeException( esc_html( sprintf( __( 'Not created: %s', 'variation-converter' ), $e->getMessage() ) ), 0, $e ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- $e is the previous exception, not output.
	}
	remove_filter( 'wc_product_has_unique_sku', '__return_false', 99 );

	foreach ( $sources as [ $simple ] ) {
		$simple->update_meta_data( VARCONV_HIDDEN_META, $simple->get_catalog_visibility( 'edit' ) );
		$simple->update_meta_data( VARCONV_PARENT_META, (string) $parent_id );
		$simple->set_catalog_visibility( 'hidden' );
		$simple->save();
	}
	varconv_reindex( array_merge( array( $parent_id ), array_map( static fn( $s ) => $s[0]->get_id(), $sources ) ) );
	wc_delete_product_transients( $parent_id );
	delete_transient( VARCONV_SCAN_TRANSIENT );
	return $parent_id;
}

/**
 * Undo: gives the members their catalog visibility back and deletes the variable product and its variations.
 *
 * @return int Members restored.
 */
function varconv_undo( int $parent_id ): int {
	if ( 'product' !== get_post_type( $parent_id ) || '' === (string) get_post_meta( $parent_id, VARCONV_META, true ) ) {
		/* translators: %d: product ID. */
		throw new RuntimeException( esc_html( sprintf( __( 'Product %d was not made by Variation Converter.', 'variation-converter' ), $parent_id ) ) );
	}
	global $wpdb;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$ids = array_map( 'intval', $wpdb->get_col( $wpdb->prepare(
		"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = %s",
		VARCONV_PARENT_META,
		(string) $parent_id
	) ) );
	foreach ( $ids as $id ) {
		$simple = wc_get_product( $id );
		if ( $simple ) {
			$simple->set_catalog_visibility( $simple->get_meta( VARCONV_HIDDEN_META ) ?: 'visible' );
			$simple->delete_meta_data( VARCONV_HIDDEN_META );
			$simple->delete_meta_data( VARCONV_PARENT_META );
			$simple->save();
		}
	}
	varconv_delete( $parent_id );
	varconv_reindex( $ids );
	delete_transient( VARCONV_SCAN_TRANSIENT );
	return count( $ids );
}

function varconv_delete( int $parent_id ): void {
	$product = wc_get_product( $parent_id );
	foreach ( $product ? $product->get_children() : array() as $child ) {
		wp_delete_post( $child, true );
	}
	wp_delete_post( $parent_id, true );
	wc_delete_product_transients( $parent_id );
}

/**
 * Relevanssi indexes a variable product with its variations' SKUs only when the parent is indexed after them, and
 * drops hidden members only when they are indexed again; WooCommerce's saves above do neither reliably.
 */
function varconv_reindex( array $ids ): void {
	if ( ! function_exists( 'relevanssi_index_doc' ) ) {
		return;
	}
	$fields = function_exists( 'relevanssi_get_custom_fields' ) ? relevanssi_get_custom_fields() : '';
	foreach ( $ids as $id ) {
		relevanssi_index_doc( (int) $id, true, $fields, true );
	}
}

/** @return array<string,int[]> Collection key => product IDs the admin unchecked. */
function varconv_excluded(): array {
	return (array) get_option( VARCONV_EXCLUDED_OPTION, array() );
}

/**
 * Remembers that members of a collection are unchecked ($on) or checked again.
 *
 * @param int[] $product_ids
 */
function varconv_set_excluded( string $key, array $product_ids, bool $on ): void {
	$excluded = varconv_excluded();
	$product_ids = array_map( 'intval', $product_ids );
	$ids = array_diff( array_map( 'intval', $excluded[ $key ] ?? array() ), $product_ids );
	if ( $on ) {
		$ids = array_merge( $ids, $product_ids );
	}
	if ( $ids ) {
		$excluded[ $key ] = array_values( array_unique( $ids ) );
	} else {
		unset( $excluded[ $key ] );
	}
	update_option( VARCONV_EXCLUDED_OPTION, $excluded, false );
}

/**
 * The collections a bulk run creates: the scan's, with at least $min products, without those carrying any of the
 * notes $skip, and without unchecked members.
 */
function varconv_bulk_candidates( int $min = 2, array $skip = array() ): array {
	return varconv_without_excluded(
		varconv_without( varconv_min_size( varconv_candidates()['collections'], max( 2, $min ) ), $skip ),
		varconv_excluded()
	);
}

/**
 * One collection of a bulk run, as the scan proposed it. A collection that needs review by hand (a missing or
 * repeated label) is skipped; a parent SKU already in use is left empty.
 *
 * @return array{status:string,id:int,message:string} status: made, would (dry run), skip or failed.
 */
function varconv_convert( array $c, bool $dry = false ): array {
	$members = array();
	foreach ( $c['members'] as $m ) {
		$members[ (int) $m['id'] ] = (string) $m['label'];
	}
	$errors = varconv_validate( $c['name'], $c['attribute'], $members );
	if ( $errors ) {
		return array( 'status' => 'skip', 'id' => 0, 'message' => implode( ' ', $errors ) );
	}
	$sku = (string) $c['sku'];
	$note = '';
	if ( '' !== $sku && wc_get_product_id_by_sku( $sku ) ) {
		$sku = '';
		$note = __( 'Parent SKU in use; left empty.', 'variation-converter' );
	}
	if ( $dry ) {
		return array( 'status' => 'would', 'id' => 0, 'message' => $note );
	}
	try {
		return array( 'status' => 'made', 'id' => varconv_create( $c['name'], $c['attribute'], $sku, $members ), 'message' => $note );
	} catch ( RuntimeException $e ) {
		return array( 'status' => 'failed', 'id' => 0, 'message' => $e->getMessage() );
	}
}

/**
 * Takes the lock that keeps bulk runs (command line or admin page) from running at once: two runs at once would each
 * make every collection, giving twin products. A run that dies without unlocking loses the lock after $seconds.
 */
function varconv_lock( int $seconds = 600 ): bool {
	global $wpdb;
	$until = (string) ( time() + $seconds );
	// INSERT IGNORE is atomic, unlike add_option(): of two runs starting together, only one inserts the row.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	if ( $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", VARCONV_LOCK_OPTION, $until ) ) ) {
		return true;
	}
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$held = (string) $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", VARCONV_LOCK_OPTION ) );
	if ( (int) $held > time() ) {
		return false;
	}
	// The run holding it died: take the expired lock over, unless another run just did.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	return (bool) $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s", $until, VARCONV_LOCK_OPTION, $held ) );
}

/** Keeps a held lock for another $seconds, for a long run. */
function varconv_lock_renew( int $seconds = 600 ): void {
	global $wpdb;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$wpdb->update( $wpdb->options, array( 'option_value' => (string) ( time() + $seconds ) ), array( 'option_name' => VARCONV_LOCK_OPTION ) );
}

function varconv_unlock(): void {
	global $wpdb;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$wpdb->delete( $wpdb->options, array( 'option_name' => VARCONV_LOCK_OPTION ) );
}
