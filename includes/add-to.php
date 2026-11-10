<?php
/**
 * Add to a combined product: makes simple products the scan did not group (another SKU, or no SKU at all) into new
 * variations of a variable product Combine already made, as Combine would have if they had been picked with it.
 *
 * Each product joins the combine: it is hidden, gets the combine's markers (_varconv_collection_parent,
 * _varconv_collection_hidden) and is added to the variable product's _varconv_collection list, so its old page
 * redirects to its variant, Undo of the combined product in History brings it back with the others, and Make simple
 * on its variation takes just that one back out. Only variable products made here can take products, since only
 * their Undo knows how to give products back.
 *
 * Hook: action varconv_added_to( $parent_id, $simple_ids ) after the products are in.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Most products added at a time. */
const VARCONV_ADD_MAX = 50;

/**
 * Labels to start with for a product joining a variable product. With one attribute, what the product's name adds to
 * the variable product's name ("Navy Blue" for Classic Tee Navy Blue); otherwise, for each attribute, the longest of
 * its options the name holds as whole words, and for the one attribute left, what remains. The SKU when nothing fits.
 *
 * @param string     $name        The product's name.
 * @param string     $parent_name The variable product's name.
 * @param string[][] $options     Each attribute's options, in order.
 * @return string[] One label per attribute ('' where there is no good guess).
 */
function varconv_add_guess( string $name, string $parent_name, array $options, string $sku ): array {
	$options = array_map( static fn( $list ) => array_values( array_filter( array_map( static fn( $o ) => trim( (string) $o ), (array) $list ), 'strlen' ) ), array_values( $options ) );
	$beyond = static function ( string $text ) use ( $parent_name ): string {
		$words = varconv_words( $text );
		$n = varconv_common_words( varconv_words( $parent_name ), $words );
		return $n > 0 ? varconv_trim( implode( ' ', array_slice( $words, $n ) ) ) : '';
	};
	$spelled = static function ( string $label, array $list ): string {
		foreach ( $list as $option ) {
			if ( 0 === strcasecmp( $option, $label ) ) {
				return $option;
			}
		}
		return $label;
	};
	// One attribute: what the name adds to the variable product's name is the label, "Navy Blue" beside "Blue".
	if ( 1 === count( $options ) && '' !== $beyond( $name ) ) {
		return array( $spelled( $beyond( $name ), $options[0] ) );
	}
	$labels = array();
	$rest = ' ' . $name . ' ';
	foreach ( $options as $i => $list ) {
		usort( $list, static fn( $a, $b ) => strlen( $b ) <=> strlen( $a ) );
		$labels[ $i ] = '';
		foreach ( $list as $option ) {
			$pattern = '/(?<=[\s\-,\/(])' . preg_quote( $option, '/' ) . '(?=[\s\-,\/)])/iu';
			if ( preg_match( $pattern, $rest ) ) {
				$labels[ $i ] = $option;
				$rest = (string) preg_replace( $pattern, ' ', $rest, 1 );
				break;
			}
		}
	}
	$empty = array_keys( $labels, '', true );
	if ( 1 === count( $empty ) ) {
		$left = $beyond( $rest );
		$labels[ $empty[0] ] = '' !== $left ? $left : trim( $sku );
	}
	return $labels;
}

/**
 * Checks the labels for products joining a variable product. Returns the problems, none when they can be added.
 *
 * @param array<int,string[]> $members  Product ID => one label per attribute.
 * @param int                 $count    How many attributes the variable product has.
 * @param string[][]          $existing The labels its variations have now.
 * @return string[]
 */
function varconv_add_validate( array $members, int $count, array $existing ): array {
	$errors = array();
	if ( ! $members ) {
		$errors[] = __( 'Choose at least 1 product to add.', 'variation-converter-for-woocommerce' );
	}
	$key = static fn( array $labels ): string => strtolower( implode( '|', array_map( static fn( $l ) => trim( (string) $l ), $labels ) ) );
	$seen = array_flip( array_map( $key, $existing ) );
	foreach ( $members as $id => $labels ) {
		$labels = array_values( array_map( static fn( $l ) => trim( (string) $l ), (array) $labels ) );
		if ( count( $labels ) !== $count || in_array( '', $labels, true ) ) {
			$errors[] = 1 === $count
				/* translators: %d: product ID. */
				? sprintf( __( 'Product %d needs a label.', 'variation-converter-for-woocommerce' ), $id )
				/* translators: %d: product ID. */
				: sprintf( __( 'Product %d needs a label for each attribute.', 'variation-converter-for-woocommerce' ), $id );
		} elseif ( array_filter( $labels, static fn( $l ) => str_contains( $l, '|' ) ) ) {
			/* translators: %d: product ID. */
			$errors[] = sprintf( __( 'Labels cannot contain "|" (product %d).', 'variation-converter-for-woocommerce' ), $id );
		} elseif ( isset( $seen[ $key( $labels ) ] ) ) {
			/* translators: %s: the variant's labels, such as "Blue" or "Blue, Large". */
			$errors[] = sprintf( __( 'There is already a "%s" variation. Each one needs its own labels.', 'variation-converter-for-woocommerce' ), implode( ', ', $labels ) );
		} else {
			$seen[ $key( $labels ) ] = true;
		}
	}
	return $errors;
}

/**
 * Why products cannot be added to this product, or '' when they can.
 *
 * @param WC_Product|false|null $parent
 */
function varconv_add_target_problem( $parent ): string {
	if ( ! $parent instanceof WC_Product_Variable || '' === (string) $parent->get_meta( VARCONV_META ) ) {
		return __( 'Products can only be added to a variable product Variation Converter combined.', 'variation-converter-for-woocommerce' );
	}
	if ( ! varconv_status_ok( $parent->get_status() ) ) {
		return __( 'Products can only be added to a published, private, pending or draft product.', 'variation-converter-for-woocommerce' );
	}
	if ( ! array_filter( $parent->get_attributes( 'edit' ), static fn( $a ) => $a->get_variation() ) ) {
		return __( 'This product has no attribute used for variations.', 'variation-converter-for-woocommerce' );
	}
	return '';
}

/**
 * Why a simple product cannot be added to a combined product, or '' when it can. Unlike Combine, it may have any
 * SKU or none.
 *
 * @param WC_Product|false|null $product
 */
function varconv_add_problem( $product ): string {
	if ( ! $product || ! $product->is_type( 'simple' ) || ! varconv_status_ok( $product->get_status() ) ) {
		return __( 'Only published, private, pending or draft simple products can be added.', 'variation-converter-for-woocommerce' );
	}
	if ( $product->get_meta( VARCONV_PARENT_META ) ) {
		return __( 'It is already in a combined product.', 'variation-converter-for-woocommerce' );
	}
	if ( $product->get_meta( VARCONV_SPLIT_FROM_META ) || $product->get_meta( VARCONV_TAKEN_META ) ) {
		return __( 'It was made by a split or Make simple; undo that in History instead.', 'variation-converter-for-woocommerce' );
	}
	return '';
}

/**
 * The products among $ids that can be added, as varconv_products() describes them, and the rest.
 *
 * @param int[] $ids
 * @return array{products:array,refused:int[]}
 */
function varconv_addable( array $ids ): array {
	$out = array( 'products' => array(), 'refused' => array() );
	foreach ( array_slice( array_values( array_unique( array_filter( array_map( 'intval', $ids ) ) ) ), 0, VARCONV_ADD_MAX ) as $id ) {
		$product = wc_get_product( $id );
		if ( '' !== varconv_add_problem( $product ) ) {
			$out['refused'][] = $id;
			continue;
		}
		$sku = (string) $product->get_sku( 'edit' );
		$out['products'][] = array(
			'id'     => $id,
			'sku'    => $sku,
			'name'   => varconv_clean_name( $product->get_name( 'edit' ), $sku ),
			'title'  => $product->get_name( 'edit' ),
			'price'  => varconv_regular_price( $product ),
			'status' => $product->get_status(),
		);
	}
	return $out;
}

/**
 * The variable product's attributes used for variations, by key, in order.
 *
 * @return array<string,WC_Product_Attribute>
 */
function varconv_variation_attributes( WC_Product_Variable $parent ): array {
	return array_filter( $parent->get_attributes( 'edit' ), static fn( $a ) => $a->get_variation() );
}

/** Each variation attribute's options as names (terms' names for global attributes). @return string[][] */
function varconv_attribute_options( WC_Product_Variable $parent ): array {
	$out = array();
	foreach ( varconv_variation_attributes( $parent ) as $attribute ) {
		if ( $attribute->is_taxonomy() ) {
			$terms = $attribute->get_terms();
			$out[] = $terms ? array_map( static fn( $t ) => (string) $t->name, $terms ) : array();
		} else {
			$out[] = array_map( 'strval', $attribute->get_options() );
		}
	}
	return $out;
}

/** An attribute's name to show: a global attribute's label, or a custom attribute's name. */
function varconv_attribute_label( WC_Product_Attribute $attribute ): string {
	return $attribute->is_taxonomy() ? wc_attribute_label( $attribute->get_name() ) : $attribute->get_name();
}

/**
 * Adds simple products to a variable product Combine made, each as a new variation with the given labels.
 *
 * @param array<int,string[]> $members Simple product ID => one label per variation attribute, in order.
 * @return int[] The new variations.
 * @throws RuntimeException When they cannot be added; nothing is changed then.
 */
function varconv_add_to( int $parent_id, array $members ): array {
	$parent = wc_get_product( $parent_id );
	$problem = varconv_add_target_problem( $parent );
	if ( '' !== $problem ) {
		throw new RuntimeException( esc_html( $problem ) );
	}
	$attributes = varconv_variation_attributes( $parent );
	$errors = array();
	$sources = array();
	foreach ( $members as $id => $labels ) {
		$simple = wc_get_product( (int) $id );
		$why = varconv_add_problem( $simple );
		if ( '' !== $why ) {
			/* translators: 1: product name or ID, 2: the reason. */
			$errors[] = sprintf( __( '%1$s cannot be added: %2$s', 'variation-converter-for-woocommerce' ), $simple ? $simple->get_name( 'edit' ) : '#' . (int) $id, $why );
		} else {
			$sources[] = array( $simple, array_values( array_map( static fn( $l ) => trim( (string) $l ), (array) $labels ) ) );
		}
	}
	$existing = array();
	foreach ( $parent->get_children() as $child ) {
		$variation = wc_get_product( $child );
		if ( $variation instanceof WC_Product_Variation ) {
			$existing[] = varconv_variation_values( $parent, $variation );
		}
	}
	$errors = array_merge( $errors, varconv_add_validate( array_combine( array_map( static fn( $s ) => $s[0]->get_id(), $sources ), array_column( $sources, 1 ) ) ?: array(), count( $attributes ), $existing ) );
	if ( count( $members ) > VARCONV_ADD_MAX ) {
		/* translators: %d: most products added at once. */
		$errors[] = sprintf( __( 'Add at most %d products at a time.', 'variation-converter-for-woocommerce' ), VARCONV_ADD_MAX );
	}

	// Each label as the variation stores it: a custom attribute's option (an existing one keeps its spelling), or a
	// global attribute's term slug. Global attributes take only terms that exist.
	$values = array();
	$options = array();
	$terms = array();
	$i = 0;
	foreach ( $attributes as $key => $attribute ) {
		$options[ $key ] = $attribute->is_taxonomy() ? array() : array_map( 'strval', $attribute->get_options() );
		foreach ( $sources as $n => [ $simple, $labels ] ) {
			$label = $labels[ $i ] ?? '';
			if ( '' === $label ) {
				continue;
			}
			if ( $attribute->is_taxonomy() ) {
				$term = get_term_by( 'name', $label, $attribute->get_name() ) ?: get_term_by( 'slug', sanitize_title( $label ), $attribute->get_name() );
				if ( ! $term || is_wp_error( $term ) ) {
					/* translators: 1: label, 2: attribute name. */
					$errors[] = sprintf( __( '"%1$s" is not a term of %2$s. Add it in Products > Attributes first.', 'variation-converter-for-woocommerce' ), $label, varconv_attribute_label( $attribute ) );
					continue;
				}
				$values[ $n ][ $key ] = $term->slug;
				$terms[ $attribute->get_name() ][] = (int) $term->term_id;
				continue;
			}
			$match = '';
			foreach ( $options[ $key ] as $option ) {
				if ( 0 === strcasecmp( $option, $label ) ) {
					$match = $option;
					break;
				}
			}
			if ( '' === $match ) {
				$options[ $key ][] = $label;
				$match = $label;
			}
			$values[ $n ][ $key ] = $match;
		}
		++$i;
	}
	if ( $errors ) {
		throw new RuntimeException( esc_html( implode( ' ', array_unique( $errors ) ) ) );
	}

	$status = $parent->get_status();
	$order = 0;
	foreach ( $parent->get_children() as $child ) {
		$order = max( $order, (int) get_post_field( 'menu_order', $child ) + 1 );
	}
	$made = array();
	add_filter( 'wc_product_has_unique_sku', '__return_false', 99 );
	add_filter( 'wc_product_has_global_unique_id', '__return_false', 99 );
	try {
		foreach ( $sources as $n => [ $simple ] ) {
			// A product less visible than the variable product becomes a disabled variation, as in Combine.
			$visible = varconv_status_for( array( $status, $simple->get_status() ) ) === $simple->get_status();
			$made[] = varconv_new_variation( $simple, $parent, $values[ $n ], $visible ? 'publish' : 'private', $order + $n )->get_id();
		}
	} catch ( Throwable $e ) {
		remove_filter( 'wc_product_has_unique_sku', '__return_false', 99 );
		remove_filter( 'wc_product_has_global_unique_id', '__return_false', 99 );
		foreach ( $made as $id ) {
			wp_delete_post( $id, true );
		}
		/* translators: %s: the reason. */
		throw new RuntimeException( esc_html( sprintf( __( 'Not added: %s', 'variation-converter-for-woocommerce' ), $e->getMessage() ) ), 0, $e ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- $e is the previous exception, not output.
	}
	remove_filter( 'wc_product_has_unique_sku', '__return_false', 99 );
	remove_filter( 'wc_product_has_global_unique_id', '__return_false', 99 );

	// Copies: changing the product's own attribute objects would leave nothing for WooCommerce to see as changed.
	$all = array_map( static fn( $a ) => clone $a, $parent->get_attributes( 'edit' ) );
	foreach ( array_intersect_key( $all, $attributes ) as $key => $attribute ) {
		if ( $attribute->is_taxonomy() ) {
			$ids = array_values( array_unique( array_merge( array_map( 'intval', $attribute->get_options() ), $terms[ $attribute->get_name() ] ?? array() ) ) );
			$attribute->set_options( $ids );
			wp_set_object_terms( $parent_id, $ids, $attribute->get_name() );
		} else {
			$attribute->set_options( $options[ $key ] );
		}
		$all[ $key ] = $attribute;
	}
	$parent->set_attributes( $all );
	$images = array( array_merge( array( $parent->get_image_id( 'edit' ) ), $parent->get_gallery_image_ids( 'edit' ) ) );
	foreach ( $sources as [ $simple ] ) {
		$images[] = array_merge( array( $simple->get_image_id( 'edit' ) ), $simple->get_gallery_image_ids( 'edit' ) );
	}
	$parent->set_gallery_image_ids( varconv_gallery( $images ) );
	$parent->save();

	$ids = array();
	foreach ( $sources as [ $simple ] ) {
		$simple->update_meta_data( VARCONV_HIDDEN_META, $simple->get_catalog_visibility( 'edit' ) );
		$simple->update_meta_data( VARCONV_PARENT_META, (string) $parent_id );
		$simple->set_catalog_visibility( 'hidden' );
		$simple->save();
		varconv_collection_list( $parent_id, $simple->get_id(), true );
		$ids[] = $simple->get_id();
	}
	WC_Product_Variable::sync( $parent_id );
	varconv_reindex( array_merge( array( $parent_id ), $ids ) );
	wc_delete_product_transients( $parent_id );
	delete_transient( VARCONV_SCAN_TRANSIENT );
	/**
	 * Fires after simple products were added to a combined product as new variations.
	 *
	 * @param int   $parent_id  The variable product.
	 * @param int[] $simple_ids The products added, now hidden.
	 */
	do_action( 'varconv_added_to', $parent_id, $ids );
	return $made;
}

/**
 * Combined products whose name or SKU holds $search (all of them for an empty search), for choosing where to add
 * products: newest first, at most $limit.
 *
 * @return int[]
 */
function varconv_add_targets( string $search, int $limit = 20 ): array {
	$search = trim( $search );
	$out = array();
	foreach ( varconv_created() as $id ) {
		if ( ! varconv_status_ok( (string) get_post_status( $id ) ) ) {
			continue;
		}
		if ( '' !== $search && false === stripos( get_post_field( 'post_title', $id, 'raw' ) . ' ' . get_post_meta( $id, '_sku', true ), $search ) ) {
			continue;
		}
		$out[] = $id;
		if ( count( $out ) >= $limit ) {
			break;
		}
	}
	return $out;
}
