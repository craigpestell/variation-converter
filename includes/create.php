<?php
/**
 * Reading the catalog for the scan, and making (or undoing) a variable product from an approved collection.
 *
 * Markers:
 *   _vcol_collection         on the new variable product: JSON list of the source simple product IDs
 *   _vcol_collection_parent  on each source: the variable product made from it
 *   _vcol_collection_hidden  on each source: its catalog visibility before it was hidden
 * The variations reuse the sources' SKUs, prices, stock and images (same attachments), so each SKU exists twice
 * (the hidden simple product and the variation) while the sources are kept for Undo.
 */

const VCOL_META = '_vcol_collection';
const VCOL_PARENT_META = '_vcol_collection_parent';
const VCOL_HIDDEN_META = '_vcol_collection_hidden';
const VCOL_DISMISSED_OPTION = 'vcol_dismissed';
const VCOL_SCAN_TRANSIENT = 'vcol_scan';
// Product meta the new product takes from its first member, besides the WooCommerce fields set below.
const VCOL_COPY_META = array();

/**
 * Published simple products with a SKU, shown in the catalog, not already in a collection or the dev preview.
 *
 * @return array<int,array{id:int,sku:string,name:string,title:string,price:string,stock:string,cats:int[]}>
 */
function vcol_products(): array {
	global $wpdb;
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
		AND p.ID NOT IN ( SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key IN ( %s) )
		AND p.ID NOT IN ( SELECT vr.object_id FROM {$wpdb->term_relationships} vr
			JOIN {$wpdb->term_taxonomy} vt ON vt.term_taxonomy_id = vr.term_taxonomy_id AND vt.taxonomy = 'product_visibility'
			JOIN {$wpdb->terms} v ON v.term_id = vt.term_id AND v.slug = 'exclude-from-catalog' )",
		VCOL_PARENT_META
	) );
	$cats = array();
	foreach ( $wpdb->get_results(
		"SELECT tr.object_id, tt.term_id FROM {$wpdb->term_relationships} tr
		JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id AND tt.taxonomy = 'product_cat'"
	) as $row ) {
		$cats[ (int) $row->object_id ][] = (int) $row->term_id;
	}
	$products = array();
	foreach ( $rows as $row ) {
		$id = (int) $row->ID;
		$products[] = array(
			'id'    => $id,
			'sku'   => (string) $row->sku,
			// Without the SKU line and retailer notices, as feeds' cleaned name.
			'name'  => vcol_clean_name( (string) $row->post_title, (string) $row->sku ),
			'title' => (string) $row->post_title,
			'price' => (string) $row->price,
			'stock' => (string) $row->stock,
			'cats'  => $cats[ $id ] ?? array(),
		);
	}
	return $products;
}

/** The scan, kept for 30 minutes; creating, undoing or dismissing clears it. */
function vcol_candidates( bool $fresh = false ): array {
	$cached = $fresh ? false : get_transient( VCOL_SCAN_TRANSIENT );
	if ( is_array( $cached ) ) {
		return $cached;
	}
	$scan = array(
		'time'        => time(),
		'collections' => vcol_scan( vcol_products(), (array) get_option( VCOL_DISMISSED_OPTION, array() ) ),
	);
	set_transient( VCOL_SCAN_TRANSIENT, $scan, 30 * MINUTE_IN_SECONDS );
	return $scan;
}

/** Variable products made here, newest first. */
function vcol_created(): array {
	global $wpdb;
	return array_map( 'intval', $wpdb->get_col( $wpdb->prepare(
		"SELECT p.ID FROM {$wpdb->posts} p JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = %s
		WHERE p.post_type = 'product' ORDER BY p.ID DESC",
		VCOL_META
	) ) );
}

/**
 * Makes a published variable product from simple products: one variation per member, in the order given, with the
 * member's SKU, prices, stock, weight and image. The product takes the first member's descriptions, categories,
 * tags, brand and retailer notes, and is dated just after its newest member so the shop order barely moves. The
 * members are then hidden from the catalog and search.
 *
 * @param array<int,string> $members Simple product ID => variant label.
 * @return int The new product's ID.
 * @throws RuntimeException When the collection cannot be made; nothing is left half made.
 */
function vcol_create( string $name, string $attribute_name, string $sku, array $members ): int {
	if ( ! in_array( wp_get_environment_type(), array( 'development', 'local' ), true ) ) {
		throw new RuntimeException( 'Variant collections can only be created on dev.' );
	}
	$name = trim( $name );
	$attribute_name = trim( $attribute_name );
	$sku = trim( $sku );
	$errors = vcol_validate( $name, $attribute_name, $members );
	$sources = array();
	foreach ( $members as $id => $label ) {
		$simple = wc_get_product( (int) $id );
		if ( ! $simple || ! $simple->is_type( 'simple' ) || 'publish' !== $simple->get_status() ) {
			$errors[] = 'Product ' . $id . ' is not a published simple product.';
		} elseif ( $simple->get_meta( VCOL_PARENT_META ) ) {
			$errors[] = 'Product ' . $id . ' is already in a variable product.';
		} else {
			$sources[] = array( $simple, trim( (string) $label ) );
		}
	}
	$taken = '' === $sku ? 0 : (int) wc_get_product_id_by_sku( $sku );
	if ( $taken ) {
		$errors[] = 'SKU ' . $sku . ' is already used by product ' . $taken . '; change it or leave it empty.';
	}
	if ( $errors ) {
		throw new RuntimeException( implode( ' ', array_unique( $errors ) ) );
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
		if ( $newest ) {
			$parent->set_date_created( $newest + 1 );
		}
		$parent->update_meta_data( VCOL_META, wp_json_encode( array_map( static fn( $s ) => $s[0]->get_id(), $sources ) ) );
		foreach ( VCOL_COPY_META as $key ) {
			$value = $first->get_meta( $key, true, 'edit' );
			if ( '' !== $value && null !== $value ) {
				$parent->update_meta_data( $key, $value );
			}
		}
		$parent_id = $parent->save();
		if ( ! $parent_id ) {
			throw new RuntimeException( 'WooCommerce did not save the variable product.' );
		}
		$skip = array( 'product_type', 'product_visibility', 'product_shipping_class' );
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
				throw new RuntimeException( 'WooCommerce did not save the variation for product ' . $simple->get_id() . '.' );
			}
		}
		WC_Product_Variable::sync( $parent_id );
	} catch ( Throwable $e ) {
		remove_filter( 'wc_product_has_unique_sku', '__return_false', 99 );
		if ( $parent_id ) {
			vcol_delete( $parent_id );
		}
		throw new RuntimeException( 'Not created: ' . $e->getMessage(), 0, $e );
	}
	remove_filter( 'wc_product_has_unique_sku', '__return_false', 99 );

	foreach ( $sources as [ $simple ] ) {
		$simple->update_meta_data( VCOL_HIDDEN_META, $simple->get_catalog_visibility( 'edit' ) );
		$simple->update_meta_data( VCOL_PARENT_META, (string) $parent_id );
		$simple->set_catalog_visibility( 'hidden' );
		$simple->save();
	}
	vcol_reindex( array_merge( array( $parent_id ), array_map( static fn( $s ) => $s[0]->get_id(), $sources ) ) );
	wc_delete_product_transients( $parent_id );
	delete_transient( VCOL_SCAN_TRANSIENT );
	return $parent_id;
}

/**
 * Undo: gives the members their catalog visibility back and deletes the variable product and its variations.
 *
 * @return int Members restored.
 */
function vcol_undo( int $parent_id ): int {
	if ( 'product' !== get_post_type( $parent_id ) || '' === (string) get_post_meta( $parent_id, VCOL_META, true ) ) {
		throw new RuntimeException( 'Product ' . $parent_id . ' was not made from a variant collection.' );
	}
	global $wpdb;
	$ids = array_map( 'intval', $wpdb->get_col( $wpdb->prepare(
		"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = %s",
		VCOL_PARENT_META,
		(string) $parent_id
	) ) );
	foreach ( $ids as $id ) {
		$simple = wc_get_product( $id );
		if ( $simple ) {
			$simple->set_catalog_visibility( $simple->get_meta( VCOL_HIDDEN_META ) ?: 'visible' );
			$simple->delete_meta_data( VCOL_HIDDEN_META );
			$simple->delete_meta_data( VCOL_PARENT_META );
			$simple->save();
		}
	}
	vcol_delete( $parent_id );
	vcol_reindex( $ids );
	delete_transient( VCOL_SCAN_TRANSIENT );
	return count( $ids );
}

function vcol_delete( int $parent_id ): void {
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
function vcol_reindex( array $ids ): void {
	if ( ! function_exists( 'relevanssi_index_doc' ) ) {
		return;
	}
	$fields = function_exists( 'relevanssi_get_custom_fields' ) ? relevanssi_get_custom_fields() : '';
	foreach ( $ids as $id ) {
		relevanssi_index_doc( (int) $id, true, $fields, true );
	}
}
