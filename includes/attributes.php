<?php
/**
 * Attribute names spelled one way across the store. Stores in Canada, the UK or Australia often end up with both
 * Color and Colour (or Flavor and Flavour) on their products; the plugin writes the store's chosen spelling, and can
 * rename the other spelling on products already made.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// The store's spelling: 'british' (Colour, Flavour) or 'american' (Color, Flavor). Unset follows the site language.
const VARCONV_SPELLING_OPTION = 'varconv_spelling';
// How long one Rename request may work before it returns and asks for another click.
const VARCONV_RESPELL_SECONDS = 20;

/**
 * Attribute names spelled two ways, American => British.
 *
 * @return array<string,string>
 */
function varconv_spelling_pairs(): array {
	$pairs = array(
		'Color'  => 'Colour',
		'Flavor' => 'Flavour',
	);
	if ( function_exists( 'apply_filters' ) ) {
		/**
		 * Filters the attribute names that are spelled two ways, American => British.
		 *
		 * @param array<string,string> $pairs
		 */
		$pairs = (array) apply_filters( 'varconv_spelling_pairs', $pairs );
	}
	return $pairs;
}

/**
 * $name in the $spelling given ('british' or 'american'), when it is one of $pairs in either spelling and any case;
 * other names are returned as they are.
 *
 * @param array<string,string> $pairs American => British.
 */
function varconv_spell( string $name, string $spelling, array $pairs ): string {
	$name = trim( $name );
	foreach ( $pairs as $american => $british ) {
		if ( 0 === strcasecmp( $name, $american ) || 0 === strcasecmp( $name, $british ) ) {
			return 'british' === $spelling ? $british : $american;
		}
	}
	return $name;
}

/** The store's spelling: the saved choice, else British for English other than US English, else American. */
function varconv_spelling(): string {
	$saved = get_option( VARCONV_SPELLING_OPTION, '' );
	if ( in_array( $saved, array( 'british', 'american' ), true ) ) {
		return $saved;
	}
	$locale = get_locale();
	return str_starts_with( $locale, 'en_' ) && 'en_US' !== $locale ? 'british' : 'american';
}

/** An attribute name as the store spells it: Color becomes Colour in a British-spelling store, and the other way. */
function varconv_attribute_name( string $name ): string {
	$spelled = varconv_spell( $name, varconv_spelling(), varconv_spelling_pairs() );
	/**
	 * Filters the name of an attribute the plugin is about to write or suggest.
	 *
	 * @param string $spelled The name in the store's spelling.
	 * @param string $name    The name as given.
	 */
	return (string) apply_filters( 'varconv_attribute_name', $spelled, $name );
}

/**
 * Products with a custom (not global) attribute spelled the other way.
 *
 * @return int[] Product IDs, oldest first.
 */
function varconv_misspelled_products(): array {
	global $wpdb;
	$keys = array();
	foreach ( varconv_spelling_pairs() as $american => $british ) {
		$keys[] = sanitize_title( 'british' === varconv_spelling() ? $american : $british );
	}
	if ( ! $keys ) {
		return array();
	}
	// A rough match on the stored attributes first ("color";a:...), then a check of each product's attributes.
	$likes = implode( ' OR ', array_fill( 0, count( $keys ), 'pm.meta_value LIKE %s' ) );
	$args = array_map( static fn( $key ) => '%' . $wpdb->esc_like( '"' . $key . '";a:' ) . '%', $keys );
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- $likes is only placeholders; the result changes as products are renamed.
	$ids = $wpdb->get_col( $wpdb->prepare( "SELECT pm.post_id FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE pm.meta_key = '_product_attributes' AND p.post_type = 'product' AND p.post_status <> 'trash' AND ( $likes ) ORDER BY pm.post_id", $args ) );
	return array_values( array_filter( array_map( 'intval', $ids ), static fn( $id ) => array() !== varconv_respellings( $id ) ) );
}

/**
 * The custom attributes of product $id to rename, as their name now => the store's spelling.
 *
 * @return array<string,string>
 */
function varconv_respellings( int $id ): array {
	$product = wc_get_product( $id );
	if ( ! $product ) {
		return array();
	}
	$renames = array();
	foreach ( $product->get_attributes( 'edit' ) as $attribute ) {
		if ( $attribute instanceof WC_Product_Attribute && ! $attribute->is_taxonomy() ) {
			$spelled = varconv_attribute_name( $attribute->get_name() );
			if ( $spelled !== $attribute->get_name() ) {
				$renames[ $attribute->get_name() ] = $spelled;
			}
		}
	}
	return $renames;
}

/**
 * Renames product $id's custom attributes to the store's spelling, with its variations' choices and default choices,
 * so shoppers see one spelling and the variations still match. Orders keep the names they were placed with.
 *
 * @return array<string,string> The names renamed, as before => after.
 * @throws RuntimeException When the product has both spellings, so renaming would make two attributes one.
 */
function varconv_respell( int $id ): array {
	$product = wc_get_product( $id );
	$renames = varconv_respellings( $id );
	if ( ! $product || ! $renames ) {
		return array();
	}
	$attributes = array_filter( $product->get_attributes( 'edit' ) );
	$keys = array();
	$position = 0;
	foreach ( $attributes as $key => $attribute ) {
		// Positions as shown now, so a renamed attribute keeps its place when the positions tie.
		$attribute->set_position( $position++ );
		$name = $attribute->get_name();
		$new_key = isset( $renames[ $name ] ) ? sanitize_title( $renames[ $name ] ) : $key;
		if ( isset( $keys[ $new_key ] ) ) {
			throw new RuntimeException(
				/* translators: 1: product name, 2: attribute name, e.g. Colour. */
				esc_html( sprintf( __( '"%1$s" has both spellings of %2$s; merge them on the product by hand.', 'variation-converter-for-woocommerce' ), $product->get_name(), $renames[ $name ] ?? $name ) )
			);
		}
		$keys[ $new_key ] = $key;
		if ( isset( $renames[ $name ] ) ) {
			$attribute->set_name( $renames[ $name ] );
		}
	}
	// The old key => the new key, for the attributes whose key changes (Color => Colour does; colour => Colour doesn't).
	$moved = array_filter( array_flip( $keys ), static fn( $new_key, $key ) => $new_key !== $key, ARRAY_FILTER_USE_BOTH );
	$rekey = static function ( array $values ) use ( $moved ): array {
		$out = array();
		foreach ( $values as $key => $value ) {
			$out[ $moved[ $key ] ?? $key ] = $value;
		}
		return $out;
	};

	if ( $moved && $product->is_type( 'variable' ) ) {
		foreach ( $product->get_children() as $child_id ) {
			$variation = wc_get_product( $child_id );
			if ( $variation ) {
				$variation->set_attributes( $rekey( $variation->get_attributes( 'edit' ) ) );
				$variation->save();
			}
		}
		$product->set_default_attributes( $rekey( $product->get_default_attributes( 'edit' ) ) );
	}
	$product->set_attributes( array_values( $attributes ) );
	$product->save();
	if ( $moved && $product->is_type( 'variable' ) ) {
		WC_Product_Variable::sync( $id );
	}
	return $renames;
}

/**
 * Renames products to the store's spelling until $seconds have passed (0: no limit).
 *
 * @return array{done:int,failed:string[],left:int}
 */
function varconv_respell_all( int $seconds = 0 ): array {
	$start = time();
	$ids = varconv_misspelled_products();
	$result = array( 'done' => 0, 'failed' => array(), 'left' => 0 );
	foreach ( $ids as $i => $id ) {
		if ( $seconds && time() - $start >= $seconds ) {
			$result['left'] = count( $ids ) - $i;
			break;
		}
		try {
			varconv_respell( $id );
			++$result['done'];
		} catch ( RuntimeException $e ) {
			$result['failed'][] = varconv_message( $e );
		}
	}
	return $result;
}

/**
 * Global attributes (Products > Attributes) that are one name spelled two ways. The plugin doesn't merge these: their
 * terms, filters and lookup tables are WooCommerce's, so the admin merges them there.
 *
 * @return string[] Each as "Color and Colour".
 */
function varconv_global_spelling_twins(): array {
	$labels = array();
	foreach ( wc_get_attribute_taxonomies() as $taxonomy ) {
		$labels[ strtolower( $taxonomy->attribute_label ) ] = $taxonomy->attribute_label;
	}
	$twins = array();
	foreach ( varconv_spelling_pairs() as $american => $british ) {
		if ( isset( $labels[ strtolower( $american ) ], $labels[ strtolower( $british ) ] ) ) {
			/* translators: 1: an attribute name, e.g. Color, 2: the same name spelled the other way, e.g. Colour. */
			$twins[] = sprintf( __( '%1$s and %2$s', 'variation-converter-for-woocommerce' ), $labels[ strtolower( $american ) ], $labels[ strtolower( $british ) ] );
		}
	}
	return $twins;
}
