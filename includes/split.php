<?php
/**
 * Splitting a variable product into simple products, one per variation, and undoing it.
 *
 * Markers:
 *   _varconv_split              on the variable product: JSON list of the simple product IDs made from it
 *   _varconv_split_from         on each new simple product: the variable product it was made from
 *   _varconv_collection_hidden  on the variable product: its catalog visibility before it was hidden
 * The simple products reuse the variations' SKUs, so each SKU exists twice (the variation inside the hidden variable
 * product and the simple product) while the variable product is kept for Undo.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const VARCONV_SPLIT_META = '_varconv_split';
const VARCONV_SPLIT_FROM_META = '_varconv_split_from';

/** A new simple product's name: the variable product's name and the variation's values ("Tri-Rings Black"). */
function varconv_split_name( string $parent, array $values ): string {
	$values = array_filter( array_map( static fn( $v ) => trim( (string) $v ), $values ), 'strlen' );
	return trim( trim( $parent ) . ' ' . implode( ' ', $values ) );
}

/**
 * Checks a split the admin submitted: variation ID => new product name. Returns the problems, none when it can be
 * made.
 *
 * @param array<int,string> $names
 */
function varconv_split_validate( array $names ): array {
	$errors = array();
	if ( ! $names ) {
		$errors[] = __( 'Choose at least 1 variation.', 'variation-converter-for-woocommerce' );
	}
	foreach ( $names as $id => $name ) {
		if ( '' === trim( (string) $name ) ) {
			/* translators: %d: variation ID. */
			$errors[] = sprintf( __( 'Variation %d needs a product name.', 'variation-converter-for-woocommerce' ), $id );
		}
	}
	return $errors;
}

/**
 * Published variable products not already split, optionally matching a name or SKU: newest first, or by number of
 * variations when $orderby is "variations" ($order "asc" or "desc", the default).
 *
 * @return array{ids:int[],total:int}
 */
function varconv_variables( string $search, int $paged, int $per_page, string $orderby = '', string $order = '' ): array {
	$args = array(
		'post_type'      => 'product',
		'post_status'    => 'publish',
		'posts_per_page' => $per_page,
		'paged'          => $paged,
		'fields'         => 'ids',
		'orderby'        => 'ID',
		'order'          => 'DESC',
		// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
		'tax_query'      => array( array( 'taxonomy' => 'product_type', 'field' => 'slug', 'terms' => 'variable' ) ),
		// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
		'meta_query'     => array( array( 'key' => VARCONV_SPLIT_META, 'compare' => 'NOT EXISTS' ) ),
	);
	if ( '' !== $search ) {
		// The product data store matches SKUs as well as titles.
		$args['post__in'] = array_merge( array( 0 ), array_map( 'intval', WC_Data_Store::load( 'product' )->search_products( $search, 'variable', false, false ) ) );
	}
	$by_count = static function ( array $clauses ) use ( $order ): array {
		global $wpdb;
		$direction = 'asc' === $order ? 'ASC' : 'DESC';
		$clauses['orderby'] = "(SELECT COUNT(*) FROM {$wpdb->posts} v WHERE v.post_parent = {$wpdb->posts}.ID AND v.post_type = 'product_variation' AND v.post_status IN ('publish', 'private')) {$direction}, {$wpdb->posts}.ID DESC";
		return $clauses;
	};
	if ( 'variations' === $orderby ) {
		add_filter( 'posts_clauses', $by_count );
	}
	$query = new WP_Query( $args );
	remove_filter( 'posts_clauses', $by_count );
	return array( 'ids' => array_map( 'intval', $query->posts ), 'total' => (int) $query->found_posts );
}

/** Variable products split here, newest first. */
function varconv_split_list(): array {
	global $wpdb;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	return array_map( 'intval', $wpdb->get_col( $wpdb->prepare(
		"SELECT p.ID FROM {$wpdb->posts} p JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = %s
		WHERE p.post_type = 'product' ORDER BY p.ID DESC",
		VARCONV_SPLIT_META
	) ) );
}

/**
 * Everything combined or split here, newest first, optionally matching a name or SKU. A conversion made before
 * conversion times were kept is dated by its product's last change.
 *
 * @return array<int,array{id:int,kind:string,time:int}> kind: combined or split.
 */
function varconv_history( string $search = '' ): array {
	$rows = array();
	foreach ( array( 'combined' => varconv_created(), 'split' => varconv_split_list() ) as $kind => $ids ) {
		foreach ( $ids as $id ) {
			$hay = get_post_field( 'post_title', $id, 'raw' ) . ' ' . get_post_meta( $id, '_sku', true );
			if ( '' !== $search && false === stripos( $hay, $search ) ) {
				continue;
			}
			$time = (int) get_post_meta( $id, VARCONV_TIME_META, true );
			$rows[] = array(
				'id'   => $id,
				'kind' => $kind,
				'time' => $time ?: (int) get_post_modified_time( 'U', true, $id ),
			);
		}
	}
	usort( $rows, static fn( $a, $b ) => array( $b['time'], $b['id'] ) <=> array( $a['time'], $a['id'] ) );
	return $rows;
}

/**
 * A variation's attribute values as shown to shoppers ("Black", "Large"); an empty value is one set to "Any".
 *
 * @return string[]
 */
function varconv_variation_values( WC_Product_Variable $parent, WC_Product_Variation $variation ): array {
	$attributes = $parent->get_attributes( 'edit' );
	$values = array();
	foreach ( $variation->get_attributes( 'edit' ) as $key => $value ) {
		$value = (string) $value;
		$attribute = $attributes[ $key ] ?? null;
		if ( '' !== $value && $attribute && $attribute->is_taxonomy() ) {
			$term = get_term_by( 'slug', $value, $attribute->get_name() );
			$value = $term && ! is_wp_error( $term ) ? $term->name : $value;
		} elseif ( '' !== $value && $attribute ) {
			// Custom attributes keep the option as written; the variation may hold its slug.
			foreach ( $attribute->get_options() as $option ) {
				if ( sanitize_title( $option ) === sanitize_title( $value ) ) {
					$value = $option;
					break;
				}
			}
		}
		$values[] = $value;
	}
	return $values;
}

/**
 * One row per variation for the review card.
 *
 * @return array<int,array{id:int,name:string,sku:string,price:string,stock:string,image:int,problem:string}>
 */
function varconv_split_rows( WC_Product_Variable $parent ): array {
	$rows = array();
	foreach ( $parent->get_children() as $child ) {
		$variation = wc_get_product( $child );
		if ( ! $variation instanceof WC_Product_Variation ) {
			continue;
		}
		$values = varconv_variation_values( $parent, $variation );
		$rows[] = array(
			'id'      => $variation->get_id(),
			'name'    => varconv_split_name( $parent->get_name( 'edit' ), $values ),
			'sku'     => $variation->get_sku( 'edit' ),
			'price'   => $variation->get_regular_price( 'edit' ),
			'stock'   => $variation->get_stock_status( 'edit' ),
			'image'   => (int) ( $variation->get_image_id( 'edit' ) ?: $parent->get_image_id( 'edit' ) ),
			'problem' => in_array( '', $values, true ) ? __( 'Set to "Any" for an attribute, so it is not one product; give it a value first.', 'variation-converter-for-woocommerce' ) : '',
		);
	}
	return $rows;
}

/**
 * Makes a published simple product from each chosen variation of a variable product, with the variation's SKU,
 * prices, stock, weight, size and image. Each takes the variable product's descriptions, categories, tags and other
 * attributes; fields a variation leaves to its parent (weight, tax class, shipping class, image) come from the
 * variable product. The variable product is then hidden from the catalog and search.
 *
 * @param array<int,string> $names Variation ID => new product name.
 * @return int[] The new products' IDs.
 * @throws RuntimeException When the split cannot be made; nothing is left half made.
 */
function varconv_split_product( int $parent_id, array $names ): array {
	$parent = wc_get_product( $parent_id );
	if ( ! $parent instanceof WC_Product_Variable || 'publish' !== $parent->get_status() ) {
		/* translators: %d: product ID. */
		throw new RuntimeException( esc_html( sprintf( __( 'Product %d is not a published variable product.', 'variation-converter-for-woocommerce' ), $parent_id ) ) );
	}
	if ( '' !== (string) $parent->get_meta( VARCONV_META ) ) {
		/* translators: %d: product ID. */
		throw new RuntimeException( esc_html( sprintf( __( 'Product %d was combined by Variation Converter; Undo it instead.', 'variation-converter-for-woocommerce' ), $parent_id ) ) );
	}
	if ( '' !== (string) $parent->get_meta( VARCONV_SPLIT_META ) ) {
		/* translators: %d: product ID. */
		throw new RuntimeException( esc_html( sprintf( __( 'Product %d is already split.', 'variation-converter-for-woocommerce' ), $parent_id ) ) );
	}
	$names = array_map( static fn( $n ) => trim( (string) $n ), $names );
	$errors = varconv_split_validate( $names );
	$children = array_map( 'intval', $parent->get_children() );
	$sources = array();
	foreach ( $names as $id => $name ) {
		$variation = wc_get_product( (int) $id );
		if ( ! $variation instanceof WC_Product_Variation || ! in_array( (int) $id, $children, true ) ) {
			/* translators: 1: product ID, 2: variable product ID. */
			$errors[] = sprintf( __( 'Product %1$d is not a variation of product %2$d.', 'variation-converter-for-woocommerce' ), $id, $parent_id );
		} elseif ( in_array( '', varconv_variation_values( $parent, $variation ), true ) ) {
			/* translators: %d: variation ID. */
			$errors[] = sprintf( __( 'Variation %d is set to "Any" for an attribute; give it a value first.', 'variation-converter-for-woocommerce' ), $id );
		} else {
			$sources[] = array( $variation, $name );
		}
	}
	if ( $errors ) {
		throw new RuntimeException( esc_html( implode( ' ', array_unique( $errors ) ) ) );
	}

	$attributes = array_values( array_filter( $parent->get_attributes( 'edit' ), static fn( $a ) => ! $a->get_variation() ) );
	$terms = array();
	$skip = array( 'product_type', 'product_visibility', 'product_shipping_class' );
	foreach ( array_diff( get_object_taxonomies( 'product' ), $skip ) as $taxonomy ) {
		$ids = wp_get_object_terms( $parent_id, $taxonomy, array( 'fields' => 'ids' ) );
		if ( $ids && ! is_wp_error( $ids ) ) {
			$terms[ $taxonomy ] = $ids;
		}
	}
	$created = array();
	// The simple products reuse the variations' SKUs, which WooCommerce otherwise refuses as duplicates.
	add_filter( 'wc_product_has_unique_sku', '__return_false', 99 );
	try {
		foreach ( $sources as $i => [ $variation, $name ] ) {
			$inherit = static fn( string $field ) => '' !== (string) $variation->{"get_$field"}( 'edit' ) ? $variation->{"get_$field"}( 'edit' ) : $parent->{"get_$field"}( 'edit' );
			$simple = new WC_Product_Simple();
			$simple->set_name( $name );
			$simple->set_status( 'publish' );
			$simple->set_menu_order( $parent->get_menu_order( 'edit' ) + $i );
			$simple->set_sku( $variation->get_sku( 'edit' ) );
			$simple->set_regular_price( $variation->get_regular_price( 'edit' ) );
			$simple->set_sale_price( $variation->get_sale_price( 'edit' ) );
			$simple->set_date_on_sale_from( $variation->get_date_on_sale_from( 'edit' ) );
			$simple->set_date_on_sale_to( $variation->get_date_on_sale_to( 'edit' ) );
			// A variation can share its parent's stock count; copying that count to every new product would multiply it.
			if ( true === $variation->get_manage_stock( 'edit' ) ) {
				$simple->set_manage_stock( true );
				$simple->set_stock_quantity( $variation->get_stock_quantity( 'edit' ) );
				$simple->set_backorders( $variation->get_backorders( 'edit' ) );
			}
			$simple->set_stock_status( $variation->get_stock_status( 'edit' ) );
			$simple->set_virtual( $variation->get_virtual( 'edit' ) );
			$simple->set_downloadable( $variation->get_downloadable( 'edit' ) );
			$simple->set_downloads( $variation->get_downloads( 'edit' ) );
			$simple->set_weight( $inherit( 'weight' ) );
			$simple->set_length( $inherit( 'length' ) );
			$simple->set_width( $inherit( 'width' ) );
			$simple->set_height( $inherit( 'height' ) );
			$simple->set_image_id( $variation->get_image_id( 'edit' ) ?: $parent->get_image_id( 'edit' ) );
			$simple->set_gallery_image_ids( $parent->get_gallery_image_ids( 'edit' ) );
			$simple->set_description( '' !== $variation->get_description( 'edit' ) ? $variation->get_description( 'edit' ) : $parent->get_description( 'edit' ) );
			$simple->set_short_description( $parent->get_short_description( 'edit' ) );
			$simple->set_purchase_note( $parent->get_purchase_note( 'edit' ) );
			$simple->set_reviews_allowed( $parent->get_reviews_allowed( 'edit' ) );
			$simple->set_tax_status( $parent->get_tax_status( 'edit' ) );
			$simple->set_tax_class( 'parent' === $variation->get_tax_class( 'edit' ) ? $parent->get_tax_class( 'edit' ) : $variation->get_tax_class( 'edit' ) );
			$simple->set_shipping_class_id( $variation->get_shipping_class_id( 'edit' ) ?: $parent->get_shipping_class_id( 'edit' ) );
			$simple->set_attributes( $attributes );
			// Set on the product, not only as terms after saving: WooCommerce would otherwise give it the default
			// category, and its cached copy of the product would keep that until the next request.
			$simple->set_category_ids( $terms['product_cat'] ?? array() );
			$simple->set_tag_ids( $terms['product_tag'] ?? array() );
			if ( $parent->get_date_created( 'edit' ) ) {
				$simple->set_date_created( $parent->get_date_created( 'edit' )->getTimestamp() );
			}
			$simple->update_meta_data( VARCONV_SPLIT_FROM_META, (string) $parent_id );
			$simple->update_meta_data( VARCONV_TWIN_META, (string) $variation->get_id() );
			$id = $simple->save();
			if ( ! $id ) {
				/* translators: %d: variation ID. */
				throw new RuntimeException( esc_html( sprintf( __( 'WooCommerce did not save the simple product for variation %d.', 'variation-converter-for-woocommerce' ), $variation->get_id() ) ) );
			}
			$created[] = $id;
			foreach ( $terms as $taxonomy => $ids ) {
				wp_set_object_terms( $id, $ids, $taxonomy );
			}
		}
	} catch ( Throwable $e ) {
		remove_filter( 'wc_product_has_unique_sku', '__return_false', 99 );
		foreach ( $created as $id ) {
			wp_delete_post( $id, true );
		}
		/* translators: %s: the reason. */
		throw new RuntimeException( esc_html( sprintf( __( 'Not split: %s', 'variation-converter-for-woocommerce' ), $e->getMessage() ) ), 0, $e ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- $e is the previous exception, not output.
	}
	remove_filter( 'wc_product_has_unique_sku', '__return_false', 99 );

	$parent->update_meta_data( VARCONV_HIDDEN_META, $parent->get_catalog_visibility( 'edit' ) );
	$parent->update_meta_data( VARCONV_SPLIT_META, wp_json_encode( $created ) );
	$parent->update_meta_data( VARCONV_TIME_META, time() );
	$parent->set_catalog_visibility( 'hidden' );
	$parent->save();
	varconv_reindex( array_merge( $created, array( $parent_id ) ) );
	wc_delete_product_transients( $parent_id );
	delete_transient( VARCONV_SCAN_TRANSIENT );
	return $created;
}

/**
 * Undo a split: deletes the simple products made from the variable product and gives it its catalog visibility back.
 *
 * @return int Simple products deleted.
 */
function varconv_split_undo( int $parent_id ): int {
	if ( 'product' !== get_post_type( $parent_id ) || '' === (string) get_post_meta( $parent_id, VARCONV_SPLIT_META, true ) ) {
		/* translators: %d: product ID. */
		throw new RuntimeException( esc_html( sprintf( __( 'Product %d was not split by Variation Converter.', 'variation-converter-for-woocommerce' ), $parent_id ) ) );
	}
	global $wpdb;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$ids = array_map( 'intval', $wpdb->get_col( $wpdb->prepare(
		"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = %s",
		VARCONV_SPLIT_FROM_META,
		(string) $parent_id
	) ) );
	// The simple products sold while the variable product was hidden; give its variations their current prices and stock.
	foreach ( varconv_twins( $parent_id, $ids ) as $variation_id => $simple_id ) {
		$variation = wc_get_product( $variation_id );
		$simple = wc_get_product( $simple_id );
		if ( $variation && $simple ) {
			varconv_copy_current( $simple, $variation );
			$variation->save();
		}
	}
	WC_Product_Variable::sync( $parent_id );
	foreach ( $ids as $id ) {
		wp_delete_post( $id, true );
		wc_delete_product_transients( $id );
	}
	$parent = wc_get_product( $parent_id );
	$parent->set_catalog_visibility( $parent->get_meta( VARCONV_HIDDEN_META ) ?: 'visible' );
	$parent->delete_meta_data( VARCONV_HIDDEN_META );
	$parent->delete_meta_data( VARCONV_SPLIT_META );
	$parent->delete_meta_data( VARCONV_TIME_META );
	$parent->save();
	varconv_reindex( array( $parent_id ) );
	wc_delete_product_transients( $parent_id );
	delete_transient( VARCONV_SCAN_TRANSIENT );
	return count( $ids );
}
