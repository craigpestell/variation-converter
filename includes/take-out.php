<?php
/**
 * Make simple: makes the chosen variations of a variable product into simple products while the rest of it stays
 * variable. Works on any variable product, including ones Combine made.
 *
 * For a variation Combine made from a simple product, that simple product is shown again and leaves the combined
 * product: it takes the variation's current prices, stock and the fields Combine gave the variation (the variation is
 * what the shop sold and the admin edited), and keeps its own ID, page, reviews and orders. Its page stops redirecting.
 * For any other variation a new simple product is made from it, as Split makes one. Either way the admin can give it
 * its own categories, and the variation is disabled, not deleted: past orders keep their link, and Undo enables it
 * again with the simple product's current prices and stock, then hides the original simple product again (back in the
 * combined product) or deletes the one made here. A shopper opening the variation's old URL is sent to the simple
 * product (redirect.php).
 *
 * When no variation is left for sale, the variable product has nothing to sell, so it is moved to the Trash (with
 * its variations, which keeps them for Undo and past orders) and its old URL, and each variant URL, sends shoppers
 * to the simple products. Undo of any of its simple products restores it from the Trash. If the Trash is turned off
 * (EMPTY_TRASH_DAYS is 0) it is made private instead. If it is emptied from the Trash, the simple products stay as
 * ordinary products and leave History.
 *
 * Markers:
 *   _varconv_taken          on the simple product: the variation it was made from
 *   _varconv_taken_new      on the simple product: "1" when it was made here, so Undo deletes it
 *   _varconv_time           on the simple product: when, for History
 *   _varconv_taken_status   on the variation: its status before it was disabled
 *   _varconv_taken_default  on the variation: the variable product's default attributes, when they named it and were
 *                           cleared
 *   _varconv_taken_all      on the variable product, when it was removed because no variation was left for sale: JSON
 *                           {"variations":[IDs in order],"status":"its status before"}
 *
 * Hooks: action varconv_made_simple( $simple_id, $variation_id, $parent_id, $reused ) after the simple product is saved
 * and while the variation is still enabled, so a site can copy its own fields; varconv_made_simple_undone( $simple_id,
 * $variation_id, $parent_id ) after Undo, before a simple product made here is deleted; varconv_made_simple_removed(
 * $parent_id ) after the variable product was removed because none of its variations was left for sale.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const VARCONV_TAKEN_META = '_varconv_taken';
const VARCONV_TAKEN_NEW_META = '_varconv_taken_new';
const VARCONV_TAKEN_STATUS_META = '_varconv_taken_status';
const VARCONV_TAKEN_DEFAULT_META = '_varconv_taken_default';
const VARCONV_TAKEN_ALL_META = '_varconv_taken_all';

/**
 * Checks which variations the admin chose to make simple. Returns the problems, none when it can be done.
 *
 * @param int[] $chosen   Variation IDs.
 * @param int[] $children The variable product's variations.
 * @param int[] $taken    Those already made into simple products.
 * @return string[]
 */
function varconv_take_out_validate( array $chosen, array $children, array $taken ): array {
	$errors = array();
	if ( ! $chosen ) {
		$errors[] = __( 'Choose at least 1 variation.', 'variation-converter-for-woocommerce' );
	}
	foreach ( $chosen as $id ) {
		if ( ! in_array( $id, $children, true ) ) {
			/* translators: %d: product ID. */
			$errors[] = sprintf( __( 'Product %d is not a variation of this product.', 'variation-converter-for-woocommerce' ), $id );
		} elseif ( in_array( $id, $taken, true ) ) {
			/* translators: %d: variation ID. */
			$errors[] = sprintf( __( 'Variation %d is already a simple product.', 'variation-converter-for-woocommerce' ), $id );
		}
	}
	return $errors;
}

/**
 * Whether making these variations simple leaves none for sale, so the variable product is removed.
 *
 * @param int[] $chosen  Variation IDs.
 * @param int[] $enabled The variable product's enabled (for sale) variations.
 */
function varconv_take_out_empties( array $chosen, array $enabled ): bool {
	return (bool) $chosen && ! array_diff( $enabled, $chosen );
}

/** The variable product's variations that are enabled (for sale). @return int[] */
function varconv_enabled_children( WC_Product_Variable $parent ): array {
	return array_values( array_filter( array_map( 'intval', $parent->get_children() ), static fn( $id ) => 'publish' === get_post_status( $id ) ) );
}

/** Whether Make simple removed this variable product (to the Trash, or private) because no variation was left for sale. */
function varconv_taken_all( int $parent_id ): bool {
	return '' !== (string) get_post_meta( $parent_id, VARCONV_TAKEN_ALL_META, true );
}

/** The simple product made from a variation by Make simple, or 0. */
function varconv_taken_simple( int $variation_id ): int {
	global $wpdb;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	return (int) $wpdb->get_var( $wpdb->prepare(
		"SELECT p.ID FROM {$wpdb->posts} p JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = %s AND m.meta_value = %s
		WHERE p.post_type = 'product' AND p.post_status <> 'trash' ORDER BY p.ID DESC LIMIT 1",
		VARCONV_TAKEN_META,
		(string) $variation_id
	) );
}

/** Simple products made from variations by Make simple, newest first. @return int[] */
function varconv_taken_list(): array {
	global $wpdb;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	return array_map( 'intval', $wpdb->get_col( $wpdb->prepare(
		"SELECT p.ID FROM {$wpdb->posts} p JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = %s
		WHERE p.post_type = 'product' AND p.post_status <> 'trash' ORDER BY p.ID DESC",
		VARCONV_TAKEN_META
	) ) );
}

/** The simple products a variable product was combined from. @return int[] */
function varconv_members( int $parent_id ): array {
	global $wpdb;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	return array_map( 'intval', $wpdb->get_col( $wpdb->prepare(
		"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = %s",
		VARCONV_PARENT_META,
		(string) $parent_id
	) ) );
}

/**
 * Gives a combined product's original simple product what its variation holds now: prices and stock, and the fields
 * varconv_copy_to_variation() gave the variation (description, GTIN, low stock, downloads, tax and shipping class,
 * public custom fields). The variation wins, as it is what the shop sold and the admin edited.
 */
function varconv_copy_back( WC_Product_Variation $variation, WC_Product $simple, WC_Product_Variable $parent ): void {
	varconv_copy_current( $variation, $simple );
	if ( '' !== (string) $variation->get_description( 'edit' ) ) {
		$simple->set_description( $variation->get_description( 'edit' ) );
	}
	if ( method_exists( $variation, 'get_global_unique_id' ) ) {
		$simple->set_global_unique_id( $variation->get_global_unique_id( 'edit' ) );
	}
	$simple->set_low_stock_amount( $variation->get_low_stock_amount( 'edit' ) );
	$simple->set_virtual( $variation->get_virtual( 'edit' ) );
	$simple->set_downloadable( $variation->get_downloadable( 'edit' ) );
	$simple->set_downloads( $variation->get_downloads( 'edit' ) );
	$simple->set_download_limit( $variation->get_download_limit( 'edit' ) );
	$simple->set_download_expiry( $variation->get_download_expiry( 'edit' ) );
	$simple->set_tax_class( 'parent' === $variation->get_tax_class( 'edit' ) ? $parent->get_tax_class( 'edit' ) : $variation->get_tax_class( 'edit' ) );
	$simple->set_shipping_class_id( $variation->get_shipping_class_id( 'edit' ) ?: $parent->get_shipping_class_id( 'edit' ) );
	foreach ( $variation->get_meta_data() as $entry ) {
		$data = $entry->get_data();
		if ( '' !== (string) $data['key'] && '_' !== $data['key'][0] ) {
			$simple->update_meta_data( $data['key'], $data['value'] );
		}
	}
}

/** Adds a simple product to, or takes it out of, the list of products a variable product was combined from. */
function varconv_collection_list( int $parent_id, int $simple_id, bool $in ): void {
	$ids = array_map( 'intval', (array) json_decode( (string) get_post_meta( $parent_id, VARCONV_META, true ), true ) );
	$ids = $in ? array_values( array_unique( array_merge( $ids, array( $simple_id ) ) ) ) : array_values( array_diff( $ids, array( $simple_id ) ) );
	update_post_meta( $parent_id, VARCONV_META, wp_json_encode( $ids ) );
}

/**
 * The categories a simple product made from this variation gets unless the admin chooses others: the original
 * simple product's own, for a variation Combine made, else the variable product's.
 *
 * @return int[]
 */
function varconv_take_out_categories( WC_Product_Variation $variation ): array {
	$parent_id = $variation->get_parent_id();
	$twins = '' !== (string) get_post_meta( $parent_id, VARCONV_META, true ) ? varconv_twins( $parent_id, varconv_members( $parent_id ) ) : array();
	$member = (int) ( $twins[ $variation->get_id() ] ?? 0 );
	return array_map( 'intval', wp_get_post_terms( $member ?: $parent_id, 'product_cat', array( 'fields' => 'ids' ) ) );
}

/**
 * Makes the chosen variations of a variable product into simple products and disables the variations; the rest of
 * the variable product stays as it is.
 *
 * @param array<int,string> $names      Variation ID => name for the simple product ('' keeps the original product's
 *                                      name, or makes one from the variable product's name and the variation's values).
 * @param array<int,int[]>  $categories Variation ID => the simple product's categories; a variation left out gets
 *                                      varconv_take_out_categories().
 * @return int[] The simple products, one per chosen variation.
 * @throws RuntimeException When it cannot be done; nothing is left half done.
 */
function varconv_take_out( int $parent_id, array $names, array $categories = array() ): array {
	$parent = wc_get_product( $parent_id );
	if ( ! $parent instanceof WC_Product_Variable || ! varconv_status_ok( $parent->get_status() ) ) {
		/* translators: %d: product ID. */
		throw new RuntimeException( esc_html( sprintf( __( 'Product %d is not a variable product whose variations can be made simple.', 'variation-converter-for-woocommerce' ), $parent_id ) ) );
	}
	if ( '' !== (string) $parent->get_meta( VARCONV_SPLIT_META ) ) {
		/* translators: %d: product ID. */
		throw new RuntimeException( esc_html( sprintf( __( 'Product %d is already split. Undo the split in History first.', 'variation-converter-for-woocommerce' ), $parent_id ) ) );
	}
	$children = array_map( 'intval', $parent->get_children() );
	$enabled = varconv_enabled_children( $parent );
	$taken = array_values( array_filter( $children, static fn( $id ) => varconv_taken_simple( $id ) > 0 ) );
	$chosen = array_values( array_unique( array_map( 'intval', array_keys( $names ) ) ) );
	$errors = varconv_take_out_validate( $chosen, $children, $taken );
	$members = '' !== (string) $parent->get_meta( VARCONV_META ) ? varconv_twins( $parent_id, varconv_members( $parent_id ) ) : array();
	$jobs = array();
	foreach ( $errors ? array() : $chosen as $id ) {
		$variation = wc_get_product( $id );
		$member = isset( $members[ $id ] ) ? wc_get_product( $members[ $id ] ) : null;
		if ( ! $variation instanceof WC_Product_Variation ) {
			/* translators: %d: product ID. */
			$errors[] = sprintf( __( 'Product %d is not a variation of this product.', 'variation-converter-for-woocommerce' ), $id );
		} elseif ( in_array( '', varconv_variation_values( $parent, $variation ), true ) ) {
			/* translators: %d: variation ID. */
			$errors[] = sprintf( __( 'Variation %d is set to "Any" for an attribute, so it is not one product; give it a value first.', 'variation-converter-for-woocommerce' ), $id );
		} else {
			$name = trim( (string) $names[ $id ] );
			if ( '' === $name ) {
				$name = $member ? $member->get_name( 'edit' ) : varconv_split_name( $parent->get_name( 'edit' ), varconv_variation_values( $parent, $variation ) );
			}
			$cats = isset( $categories[ $id ] ) ? array_values( array_filter( array_map( 'intval', (array) $categories[ $id ] ) ) ) : varconv_take_out_categories( $variation );
			$jobs[] = array( $variation, $member, $name, $cats );
		}
	}
	if ( $errors ) {
		throw new RuntimeException( esc_html( implode( ' ', array_unique( $errors ) ) ) );
	}

	$terms = varconv_split_terms( $parent_id );
	$done = array();
	// A simple product shares its disabled variation's SKU and GTIN, which WooCommerce otherwise refuses as duplicates.
	add_filter( 'wc_product_has_unique_sku', '__return_false', 99 );
	add_filter( 'wc_product_has_global_unique_id', '__return_false', 99 );
	try {
		foreach ( $jobs as $i => [ $variation, $member, $name, $cats ] ) {
			if ( $member && 'trash' === $member->get_status( 'edit' ) ) {
				// Moved to the Trash by Trash originals: restored first, with its reviews.
				varconv_untrash_original( $member->get_id() );
				$member = wc_get_product( $member->get_id() );
			}
			if ( $member ) {
				// The original simple product, with what changed while it was a variation, out of the combined product.
				varconv_copy_back( $variation, $member, $parent );
				$member->set_catalog_visibility( $member->get_meta( VARCONV_HIDDEN_META ) ?: 'visible' );
				$member->delete_meta_data( VARCONV_HIDDEN_META );
				$member->delete_meta_data( VARCONV_PARENT_META );
				$simple = $member;
			} else {
				$simple = varconv_simple_from_variation( $parent, $variation, '', $parent->get_menu_order( 'edit' ) + $i, $terms );
				$simple->update_meta_data( VARCONV_TAKEN_NEW_META, '1' );
				$simple->update_meta_data( VARCONV_TWIN_META, (string) $variation->get_id() );
			}
			$simple->set_name( $name );
			// WooCommerce gives a product with no category its default one.
			$simple->set_category_ids( $cats );
			$simple->update_meta_data( VARCONV_TAKEN_META, (string) $variation->get_id() );
			$simple->update_meta_data( VARCONV_TIME_META, time() );
			$id = $simple->save();
			if ( ! $id ) {
				/* translators: %d: variation ID. */
				throw new RuntimeException( esc_html( sprintf( __( 'WooCommerce did not save the simple product for variation %d.', 'variation-converter-for-woocommerce' ), $variation->get_id() ) ) );
			}
			$done[] = $id;
			if ( $member ) {
				varconv_collection_list( $parent_id, $id, false );
				$variation->delete_meta_data( VARCONV_TWIN_META );
			} else {
				foreach ( array_diff_key( $terms, array( 'product_cat' => 1 ) ) as $taxonomy => $ids ) {
					wp_set_object_terms( $id, $ids, $taxonomy );
				}
			}
			/**
			 * Fires when a variation has been made into a simple product, before the variation is disabled, so a site can
			 * copy fields of its own.
			 *
			 * @param int  $simple_id    The simple product.
			 * @param int  $variation_id The variation, still enabled.
			 * @param int  $parent_id    The variable product.
			 * @param bool $reused       True when it is the original product Combine made the variation from.
			 */
			do_action( 'varconv_made_simple', $id, $variation->get_id(), $parent_id, (bool) $member );
			$variation->update_meta_data( VARCONV_TAKEN_STATUS_META, $variation->get_status( 'edit' ) );
			$variation->set_status( 'private' );
			$variation->save();
		}
	} catch ( Throwable $e ) {
		remove_filter( 'wc_product_has_unique_sku', '__return_false', 99 );
		remove_filter( 'wc_product_has_global_unique_id', '__return_false', 99 );
		foreach ( array_reverse( $done ) as $id ) {
			try {
				varconv_take_out_undo( $id );
			} catch ( Throwable $ignored ) {
				// Keep undoing the rest.
				unset( $ignored );
			}
		}
		/* translators: %s: the reason. */
		throw new RuntimeException( esc_html( sprintf( __( 'Not made simple: %s', 'variation-converter-for-woocommerce' ), $e->getMessage() ) ), 0, $e ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- $e is the previous exception, not output.
	}
	remove_filter( 'wc_product_has_unique_sku', '__return_false', 99 );
	remove_filter( 'wc_product_has_global_unique_id', '__return_false', 99 );
	varconv_take_out_defaults( $parent_id, array_map( static fn( $job ) => $job[0], $jobs ) );
	WC_Product_Variable::sync( $parent_id );
	wc_delete_product_transients( $parent_id );
	if ( varconv_take_out_empties( $chosen, $enabled ) ) {
		varconv_take_out_remove( $parent_id, $children );
	}
	varconv_reindex( array_merge( $done, array( $parent_id ) ) );
	delete_transient( VARCONV_SCAN_TRANSIENT );
	return $done;
}

/**
 * Removes a variable product none of whose variations is for sale any more: to the Trash with its variations, or,
 * when the Trash is turned off, made private. Kept either way, so Undo can bring it back.
 *
 * @param int[] $children Its variations, in order, kept for the old variant URLs' redirects.
 */
function varconv_take_out_remove( int $parent_id, array $children ): void {
	update_post_meta( $parent_id, VARCONV_TAKEN_ALL_META, wp_json_encode( array( 'variations' => array_values( $children ), 'status' => (string) get_post_status( $parent_id ) ) ) );
	if ( EMPTY_TRASH_DAYS ) {
		wp_trash_post( $parent_id );
	} else {
		wp_update_post( array( 'ID' => $parent_id, 'post_status' => 'private' ) );
	}
	wc_delete_product_transients( $parent_id );
	/**
	 * Fires when Make simple left a variable product with no variation for sale and it was moved to the Trash (or made
	 * private, when the Trash is turned off).
	 *
	 * @param int $parent_id The variable product.
	 */
	do_action( 'varconv_made_simple_removed', $parent_id );
}

/** Brings back a variable product varconv_take_out_remove() removed, with the status it had. */
function varconv_take_out_restore( int $parent_id ): void {
	$saved = json_decode( (string) get_post_meta( $parent_id, VARCONV_TAKEN_ALL_META, true ), true );
	if ( ! is_array( $saved ) ) {
		return;
	}
	// The variations share SKUs and GTINs with the simple products, which WooCommerce would otherwise change on restore.
	add_filter( 'wc_product_has_unique_sku', '__return_false', 99 );
	add_filter( 'wc_product_has_global_unique_id', '__return_false', 99 );
	if ( 'trash' === get_post_status( $parent_id ) ) {
		wp_untrash_post( $parent_id );
	} elseif ( 'private' === get_post_status( $parent_id ) && varconv_status_ok( (string) ( $saved['status'] ?? '' ) ) ) {
		wp_update_post( array( 'ID' => $parent_id, 'post_status' => $saved['status'] ) );
	}
	remove_filter( 'wc_product_has_unique_sku', '__return_false', 99 );
	remove_filter( 'wc_product_has_global_unique_id', '__return_false', 99 );
	delete_post_meta( $parent_id, VARCONV_TAKEN_ALL_META );
	wc_delete_product_transients( $parent_id );
}

// A removed variable product emptied from the Trash: its simple products stay, as ordinary products.
add_action( 'before_delete_post', 'varconv_take_out_deleted' );
function varconv_take_out_deleted( $post_id ): void {
	$saved = json_decode( (string) get_post_meta( (int) $post_id, VARCONV_TAKEN_ALL_META, true ), true );
	if ( is_array( $saved ) && ! empty( $saved['variations'] ) ) {
		varconv_forget_taken( array_map( 'intval', (array) $saved['variations'] ) );
	}
}

/**
 * Clears the variable product's default attributes when they name one of these variations, which are no longer for
 * sale, and keeps them on the variation for Undo.
 *
 * @param WC_Product_Variation[] $variations
 */
function varconv_take_out_defaults( int $parent_id, array $variations ): void {
	$parent = wc_get_product( $parent_id );
	$defaults = $parent ? array_filter( array_map( 'strval', $parent->get_default_attributes( 'edit' ) ), 'strlen' ) : array();
	if ( ! $defaults ) {
		return;
	}
	foreach ( $variations as $variation ) {
		$values = array_map( 'strval', $variation->get_attributes( 'edit' ) );
		$match = true;
		foreach ( $values as $key => $value ) {
			if ( ! isset( $defaults[ $key ] ) || sanitize_title( $defaults[ $key ] ) !== sanitize_title( $value ) ) {
				$match = false;
				break;
			}
		}
		if ( $match ) {
			update_post_meta( $variation->get_id(), VARCONV_TAKEN_DEFAULT_META, wp_json_encode( $defaults ) );
			$parent->set_default_attributes( array() );
			$parent->save();
			return;
		}
	}
}

/**
 * Undo Make simple: enables the variation again with the simple product's current prices and stock, then puts the
 * original simple product back in the combined product, hidden, or deletes the simple product made here.
 *
 * @return int The variation enabled again.
 */
function varconv_take_out_undo( int $simple_id ): int {
	$variation_id = (int) get_post_meta( $simple_id, VARCONV_TAKEN_META, true );
	$simple = 'product' === get_post_type( $simple_id ) ? wc_get_product( $simple_id ) : null;
	if ( ! $simple || ! $variation_id ) {
		/* translators: %d: product ID. */
		throw new RuntimeException( esc_html( sprintf( __( 'Product %d was not made from a variation by Variation Converter.', 'variation-converter-for-woocommerce' ), $simple_id ) ) );
	}
	$variation = wc_get_product( $variation_id );
	if ( ! $variation instanceof WC_Product_Variation ) {
		/* translators: 1: product name, 2: variation ID. */
		throw new RuntimeException( esc_html( sprintf( __( '%1$s cannot go back: its variation %2$d no longer exists.', 'variation-converter-for-woocommerce' ), $simple->get_name( 'edit' ), $variation_id ) ) );
	}
	$parent_id = $variation->get_parent_id();
	// Back from the Trash first, which restores the variations as they were when it was removed.
	varconv_take_out_restore( $parent_id );
	$variation = wc_get_product( $variation_id );
	$new = '1' === (string) $simple->get_meta( VARCONV_TAKEN_NEW_META );
	$combined = ! $new && '' !== (string) get_post_meta( $parent_id, VARCONV_META, true );
	varconv_copy_current( $simple, $variation );
	$variation->set_status( $variation->get_meta( VARCONV_TAKEN_STATUS_META ) ?: 'publish' );
	$variation->delete_meta_data( VARCONV_TAKEN_STATUS_META );
	if ( $combined ) {
		$variation->update_meta_data( VARCONV_TWIN_META, (string) $simple_id );
	}
	$defaults = json_decode( (string) $variation->get_meta( VARCONV_TAKEN_DEFAULT_META ), true );
	$variation->delete_meta_data( VARCONV_TAKEN_DEFAULT_META );
	$variation->save();
	$parent = wc_get_product( $parent_id );
	if ( is_array( $defaults ) && $parent && ! array_filter( $parent->get_default_attributes( 'edit' ) ) ) {
		$parent->set_default_attributes( $defaults );
		$parent->save();
	}
	/**
	 * Fires after Make simple is undone: the variation is enabled again. A simple product made by Make simple is
	 * deleted right after this.
	 *
	 * @param int $simple_id    The simple product.
	 * @param int $variation_id The variation.
	 * @param int $parent_id    The variable product.
	 */
	do_action( 'varconv_made_simple_undone', $simple_id, $variation_id, $parent_id );
	if ( $new ) {
		wp_delete_post( $simple_id, true );
		wc_delete_product_transients( $simple_id );
	} else {
		if ( $combined ) {
			// Back in the combined product, as Combine left it.
			$simple->update_meta_data( VARCONV_HIDDEN_META, $simple->get_catalog_visibility( 'edit' ) );
			$simple->update_meta_data( VARCONV_PARENT_META, (string) $parent_id );
			$simple->set_catalog_visibility( 'hidden' );
			varconv_collection_list( $parent_id, $simple_id, true );
		}
		$simple->delete_meta_data( VARCONV_TAKEN_META );
		$simple->delete_meta_data( VARCONV_TIME_META );
		$simple->save();
	}
	WC_Product_Variable::sync( $parent_id );
	wc_delete_product_transients( $parent_id );
	varconv_reindex( array( $simple_id, $parent_id ) );
	delete_transient( VARCONV_SCAN_TRANSIENT );
	return $variation_id;
}

/**
 * Forgets Make simple on the simple products made from these variations, which are about to be deleted (a combined
 * product's Undo): the products stay as ordinary simple products.
 *
 * @param int[] $variation_ids
 */
function varconv_forget_taken( array $variation_ids ): void {
	foreach ( $variation_ids as $variation_id ) {
		$id = varconv_taken_simple( (int) $variation_id );
		if ( $id ) {
			foreach ( array( VARCONV_TAKEN_META, VARCONV_TAKEN_NEW_META, VARCONV_TWIN_META, VARCONV_TIME_META ) as $key ) {
				delete_post_meta( $id, $key );
			}
		}
	}
}

/** Why this variation cannot be made into a simple product now, or '' when it can. */
function varconv_take_out_problem( WC_Product_Variation $variation ): string {
	$parent = wc_get_product( $variation->get_parent_id() );
	if ( ! $parent instanceof WC_Product_Variable || ! varconv_status_ok( $parent->get_status() ) ) {
		return __( 'Only variations of published, private, pending or draft variable products can be made simple.', 'variation-converter-for-woocommerce' );
	}
	if ( '' !== (string) $parent->get_meta( VARCONV_SPLIT_META ) ) {
		return __( 'This product is split. Undo the split in History first.', 'variation-converter-for-woocommerce' );
	}
	if ( in_array( '', varconv_variation_values( $parent, $variation ), true ) ) {
		return __( 'This variation is set to "Any" for an attribute, so it is not one product; give it a value first.', 'variation-converter-for-woocommerce' );
	}
	$children = array_map( 'intval', $parent->get_children() );
	$taken = array_values( array_filter( $children, static fn( $id ) => varconv_taken_simple( $id ) > 0 ) );
	$errors = varconv_take_out_validate( array( $variation->get_id() ), $children, $taken );
	return $errors ? (string) $errors[0] : '';
}
