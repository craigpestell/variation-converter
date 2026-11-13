<?php
/**
 * What the admin app shows, as data: the Combine list's views and filters, prices and stock as the store formats
 * them, the bulk job's progress and summary, and the messages after a change. The REST routes (rest.php) and the
 * admin page (admin.php) both use these; nothing here prints.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const VARCONV_PAGE = 'varconv';
const VARCONV_CAP = 'manage_woocommerce';
const VARCONV_PER_PAGE = 25;
const VARCONV_PER_PAGE_OPTION = 'varconv_per_page';
/** Most products in no group a search lists. */
const VARCONV_LOOSE_MAX = 50;

function varconv_url( array $args = array() ): string {
	return add_query_arg( array_merge( array( 'post_type' => 'product', 'page' => VARCONV_PAGE ), $args ), admin_url( 'edit.php' ) );
}

/** Marks a group as not variants, so scans leave it out. */
function varconv_dismiss( string $key ): void {
	$dismissed = (array) get_option( VARCONV_DISMISSED_OPTION, array() );
	$dismissed[] = $key;
	update_option( VARCONV_DISMISSED_OPTION, array_values( array_unique( $dismissed ) ), false );
	delete_transient( VARCONV_SCAN_TRANSIENT );
}

/** Puts skipped groups back in the list. @param string[] $keys */
function varconv_restore( array $keys ): void {
	update_option( VARCONV_DISMISSED_OPTION, array_values( array_diff( (array) get_option( VARCONV_DISMISSED_OPTION, array() ), $keys ) ), false );
	delete_transient( VARCONV_SCAN_TRANSIENT );
}

/** A running job's progress, or that it is stopping. */
function varconv_job_text( array $job ): string {
	if ( $job['stopped'] ) {
		return 'create' === $job['kind']
			? __( 'Stopping after the group being created now...', 'variation-converter-for-woocommerce' )
			: __( 'Stopping after the conversion being worked on now...', 'variation-converter-for-woocommerce' );
	}
	if ( 'undo' === $job['kind'] ) {
		/* translators: 1: conversions done, 2: conversions selected. */
		$text = __( 'Undoing conversions: %1$d of %2$d done. This keeps going if you leave the page.', 'variation-converter-for-woocommerce' );
	} elseif ( 'trash' === $job['kind'] ) {
		/* translators: 1: conversions done, 2: conversions selected. */
		$text = __( 'Moving originals to the Trash: %1$d of %2$d conversions done. This keeps going if you leave the page.', 'variation-converter-for-woocommerce' );
	} else {
		/* translators: 1: groups done, 2: groups selected. */
		$text = __( 'Creating groups: %1$d of %2$d done. This keeps going if you leave the page.', 'variation-converter-for-woocommerce' );
	}
	return sprintf( $text, $job['done'], $job['total'] );
}

/** What a bulk create did, with a link to History. */
function varconv_bulk_summary( int $made, int $skip, int $failed ): string {
	return sprintf(
		/* translators: 1: products made, 2: groups skipped, 3: groups that failed, 4: link to the History tab. */
		_n( 'Created %1$d variable product; %2$d groups were skipped because they need a label checked, and %3$d failed. Undo is in <a href="%4$s">History</a>.', 'Created %1$d variable products; %2$d groups were skipped because they need a label checked, and %3$d failed. Undo is in <a href="%4$s">History</a>.', $made, 'variation-converter-for-woocommerce' ),
		$made,
		$skip,
		$failed,
		esc_url( varconv_url( array( 'tab' => 'history' ) ) )
	);
}

/** What Trash originals asks before it moves anything. */
function varconv_trash_originals_confirm(): string {
	return __( 'Move the hidden original simple products to the Trash? Their reviews move to the variable product and their old pages keep redirecting to it. Undo works while they are in the Trash; once the Trash is emptied (WordPress does this after 30 days) this combine can no longer be undone.', 'variation-converter-for-woocommerce' );
}

/** Links to products by SKU, or by ID when they have none. */
function varconv_product_links( array $ids ): string {
	return implode( ', ', array_map( static fn( $s ) => sprintf(
		'<a href="%s">%s</a>',
		esc_url( (string) get_edit_post_link( (int) $s ) ),
		esc_html( (string) get_post_meta( (int) $s, '_sku', true ) ?: '#' . (int) $s )
	), $ids ) );
}

/**
 * Links to products' edit screens, by their names.
 *
 * @param array<int,string> $names Product ID => name.
 */
function varconv_named_links( array $names ): string {
	return implode( ', ', array_map( static fn( $id, $name ) => sprintf( '<a href="%s">%s</a>', esc_url( (string) get_edit_post_link( (int) $id ) ), esc_html( $name ) ), array_keys( $names ), $names ) );
}

/** @return array<string,string> Note kind => short badge text for the Status column. */
function varconv_note_badges(): array {
	return array(
		'dupes'  => __( 'Duplicates', 'variation-converter-for-woocommerce' ),
		'label'  => __( 'Check labels', 'variation-converter-for-woocommerce' ),
		'repeat' => __( 'Repeated label', 'variation-converter-for-woocommerce' ),
		'long'   => __( 'Long labels', 'variation-converter-for-woocommerce' ),
		'noprice' => __( 'No price', 'variation-converter-for-woocommerce' ),
		'price'  => __( 'Prices differ', 'variation-converter-for-woocommerce' ),
		'cats'   => __( 'Categories differ', 'variation-converter-for-woocommerce' ),
	);
}

/** @return array<string,string> Note kind the warning filter offers => its checkbox text. */
function varconv_warning_options(): array {
	return array(
		'noprice' => __( 'No price', 'variation-converter-for-woocommerce' ),
		'price' => __( 'Prices differ', 'variation-converter-for-woocommerce' ),
		'cats'  => __( 'Categories differ', 'variation-converter-for-woocommerce' ),
		'label' => __( 'Missing or repeated labels', 'variation-converter-for-woocommerce' ),
		'long'  => __( 'Long labels', 'variation-converter-for-woocommerce' ),
		'dupes' => __( 'Duplicate products', 'variation-converter-for-woocommerce' ),
	);
}

/** @return array<string,string> View => label. */
function varconv_views(): array {
	return array(
		''        => __( 'All', 'variation-converter-for-woocommerce' ),
		'ready'   => __( 'Ready', 'variation-converter-for-woocommerce' ),
		'review'  => __( 'Needs review', 'variation-converter-for-woocommerce' ),
		'skipped' => __( 'Skipped', 'variation-converter-for-woocommerce' ),
	);
}

/**
 * The list's filters from a request: view, search, brand, minimum group size, warnings, sort column and direction.
 * Warnings come as warn[] from the filter form or comma separated from links; they are kept comma separated.
 *
 * @param array $request $_GET, or the filters a form carried.
 * @return array{view:string,s:string,brand:string,min:int,warn:string,orderby:string,order:string}
 */
function varconv_filters( array $request ): array {
	$get = static fn( string $key ): string => isset( $request[ $key ] ) && is_scalar( $request[ $key ] ) ? sanitize_text_field( wp_unslash( (string) $request[ $key ] ) ) : '';
	$view = sanitize_key( $get( 'view' ) );
	$warn = isset( $request['warn'] ) ? wp_unslash( $request['warn'] ) : '';
	$warn = array_map( 'sanitize_key', is_array( $warn ) ? array_filter( $warn, 'is_string' ) : explode( ',', is_string( $warn ) ? $warn : '' ) );
	$warn = implode( ',', array_keys( array_intersect_key( varconv_warning_options(), array_flip( $warn ) ) ) );
	$orderby = sanitize_key( $get( 'orderby' ) );
	$order = sanitize_key( $get( 'order' ) );
	return array(
		'view'    => isset( varconv_views()[ $view ] ) ? $view : '',
		's'       => $get( 's' ),
		'brand'   => $get( 'brand' ),
		'min'     => min( 5, max( 2, (int) $get( 'min' ) ) ),
		'warn'    => $warn,
		'orderby' => in_array( $orderby, array( 'size', 'price' ), true ) ? $orderby : '',
		'order'   => in_array( $order, array( 'asc', 'desc' ), true ) ? $order : '',
	);
}

/** The filters that differ from the defaults, for links and forms. */
function varconv_filter_args( array $f ): array {
	return array_filter( array(
		'view'    => $f['view'],
		's'       => $f['s'],
		'brand'   => $f['brand'],
		'min'     => 2 === $f['min'] ? '' : $f['min'],
		'warn'    => $f['warn'],
		'orderby' => $f['orderby'],
		'order'   => $f['order'],
	) );
}

/** The groups the view and filters show, in their order. */
function varconv_filtered( array $scan, array $f ): array {
	$groups = 'skipped' === $f['view'] ? (array) ( $scan['skipped'] ?? array() ) : $scan['collections'];
	if ( 'ready' === $f['view'] ) {
		$groups = array_values( array_filter( $groups, static fn( $c ) => ! $c['notes'] ) );
	} elseif ( 'review' === $f['view'] ) {
		$groups = array_values( array_filter( $groups, static fn( $c ) => (bool) $c['notes'] ) );
	}
	$groups = varconv_with( varconv_min_size( varconv_of_brand( varconv_matching( $groups, $f['s'] ), $f['brand'] ), $f['min'] ), $f['warn'] );
	return varconv_sorted( $groups, '' === $f['orderby'] ? 'name' : $f['orderby'], $f['order'] );
}

function varconv_per_page(): int {
	$n = (int) get_user_option( VARCONV_PER_PAGE_OPTION );
	return $n >= 5 && $n <= 100 ? $n : VARCONV_PER_PAGE;
}

/** A price as WooCommerce shows it, as plain text. */
function varconv_price_text( float $price ): string {
	return html_entity_decode( wp_strip_all_tags( wc_price( $price ) ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
}

/** The price range a variable product made from these members shows ("$9.99 – $12.99"); '' without prices. */
function varconv_price_range( array $members ): string {
	$prices = array_filter( array_map( static fn( $m ) => (float) ( $m['price'] ?? 0 ), $members ), static fn( $p ) => $p > 0 );
	if ( ! $prices ) {
		return '';
	}
	$low = min( $prices );
	$high = max( $prices );
	return $low === $high ? varconv_price_text( $low ) : varconv_price_text( $low ) . ' – ' . varconv_price_text( $high );
}

/** "$4.00 for 3 products, $5.00 for 3. Shoppers will see $4.00 – $5.00." */
function varconv_price_note( array $members ): string {
	$counts = array();
	foreach ( $members as $m ) {
		$price = (float) ( $m['price'] ?? 0 );
		if ( $price > 0 ) {
			$key = (string) $price;
			$counts[ $key ] = ( $counts[ $key ] ?? 0 ) + 1;
		}
	}
	uksort( $counts, static fn( $a, $b ) => (float) $a <=> (float) $b );
	$parts = array();
	foreach ( $counts as $price => $n ) {
		$parts[] = $parts
			/* translators: 1: a price, 2: number of products at that price. */
			? sprintf( __( '%1$s for %2$d', 'variation-converter-for-woocommerce' ), varconv_price_text( (float) $price ), $n )
			/* translators: 1: a price, 2: number of products at that price. */
			: sprintf( _n( '%1$s for %2$d product', '%1$s for %2$d products', $n, 'variation-converter-for-woocommerce' ), varconv_price_text( (float) $price ), $n );
	}
	/* translators: 1: prices and how many products have each, 2: the price range shoppers see. */
	return sprintf( __( '%1$s. Shoppers will see %2$s.', 'variation-converter-for-woocommerce' ), implode( ', ', $parts ), varconv_price_range( $members ) );
}

/**
 * What the price preview script needs to format prices as the store does.
 *
 * @return array{symbol:string,format:string,decimals:int,decimal:string,thousand:string}
 */
function varconv_price_settings(): array {
	return array(
		'symbol'   => html_entity_decode( get_woocommerce_currency_symbol(), ENT_QUOTES | ENT_HTML5, 'UTF-8' ),
		'format'   => html_entity_decode( get_woocommerce_price_format(), ENT_QUOTES | ENT_HTML5, 'UTF-8' ),
		'decimals' => wc_get_price_decimals(),
		'decimal'  => wc_get_price_decimal_separator(),
		'thousand' => wc_get_price_thousand_separator(),
	);
}

function varconv_cat_name( int $id ): string {
	$term = get_term( $id, 'product_cat' );
	return $term && ! is_wp_error( $term ) ? html_entity_decode( $term->name, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) : '';
}

/** A stock status as WooCommerce names it ("In stock"). */
function varconv_stock_text( string $status ): string {
	return wc_get_product_stock_status_options()[ $status ] ?? $status;
}

/** "4 (Size)": how many variations, and the attributes they vary by. */
function varconv_variations_text( WC_Product_Variable $product ): string {
	$names = array_map( 'wc_attribute_label', array_keys( $product->get_variation_attributes() ) );
	$count = count( $product->get_children() );
	return $names ? sprintf( '%d (%s)', $count, implode( ', ', $names ) ) : (string) $count;
}

/** The variable product's regular price range as shoppers would see it. */
function varconv_variable_price( WC_Product_Variable $product ): string {
	$prices = array_filter( array_map( 'floatval', $product->get_variation_prices()['regular_price'] ?? array() ) );
	return varconv_price_range( array_map( static fn( $p ) => array( 'price' => $p ), $prices ) );
}

/** The confirm screen for making one variation a simple product. $back is "edit" (the product edit screen) or "split". */
function varconv_take_one_url( int $variation_id, string $back ): string {
	return varconv_url( array( 'tab' => 'split', 'make_simple' => $variation_id, 'back' => $back ) );
}

/** Where Cancel and the finished change go back to. */
function varconv_take_back_url( string $back, int $parent ): string {
	if ( 'edit' === $back && $parent ) {
		return (string) get_edit_post_link( $parent, 'url' );
	}
	return varconv_url( array( 'tab' => 'split', 'product' => $parent ?: '' ) ) . ( $parent ? '#varconv-split-' . $parent : '' );
}

/**
 * The message after Make simple, on the product edit screen and the Split tab. $removed is the variable product when
 * it was removed because no variation was left for sale.
 */
function varconv_taken_notice_html( int $count, int $made, int $removed = 0 ): string {
	$history = varconv_url( array( 'tab' => 'history' ) );
	if ( $removed ) {
		return sprintf(
			/* translators: 1: number of simple products, 2: variable product name, 3: link to the History tab. */
			_n( 'Made %1$d variation a simple product. %2$s had no variation left for sale, so it is in the Trash now; Undo in <a href="%3$s">History</a> restores it.', 'Made %1$d variations simple products. %2$s had no variation left for sale, so it is in the Trash now; Undo in <a href="%3$s">History</a> restores it.', $count, 'variation-converter-for-woocommerce' ),
			$count,
			esc_html( get_post_field( 'post_title', $removed, 'raw' ) ),
			esc_url( $history )
		);
	}
	if ( 1 === $count && $made ) {
		return sprintf(
			/* translators: 1: link to the simple product, 2: link to the History tab. */
			__( 'Made %1$s a simple product. Its variation is disabled; Undo is in <a href="%2$s">History</a>.', 'variation-converter-for-woocommerce' ),
			sprintf( '<a href="%s">%s</a>', esc_url( (string) get_edit_post_link( $made ) ), esc_html( get_post_field( 'post_title', $made, 'raw' ) ) ),
			esc_url( $history )
		);
	}
	return sprintf(
		/* translators: 1: number of simple products, 2: link to the History tab. */
		_n( 'Made %1$d variation a simple product. It is disabled in the variable product; Undo is in <a href="%2$s">History</a>.', 'Made %1$d variations simple products. They are disabled in the variable product; Undo is in <a href="%2$s">History</a>.', $count, 'variation-converter-for-woocommerce' ),
		$count,
		esc_url( $history )
	);
}

/** The screen's URL for these products, keeping what else is given. */
function varconv_add_url( array $ids, array $args = array() ): string {
	return varconv_url( array_merge( array( 'add' => implode( ',', array_map( 'absint', $ids ) ) ), array_filter( $args ) ) );
}

/** The notice after Add. */
function varconv_added_html( int $id, int $count ): string {
	return wp_kses_post( sprintf(
		/* translators: 1: number of products, 2: edit link, 3: product name, 4: view link. */
		_n( 'Added %1$d product to <a href="%2$s">%3$s</a> (<a href="%4$s">view</a>) as a variation. It is now hidden, and Undo in History gives it back with the others.', 'Added %1$d products to <a href="%2$s">%3$s</a> (<a href="%4$s">view</a>) as variations. They are now hidden, and Undo in History gives them back with the others.', $count, 'variation-converter-for-woocommerce' ),
		$count,
		esc_url( (string) get_edit_post_link( $id ) ),
		esc_html( get_post_field( 'post_title', $id, 'raw' ) ),
		esc_url( (string) get_permalink( $id ) )
	) );
}


/** "Private", "Draft" or "Pending", as Products > All Products shows after a product's name; '' for a published one. */
function varconv_status_text( int $id ): string {
	$status = (string) get_post_status( $id );
	$object = 'publish' === $status ? null : get_post_status_object( $status );
	return $object ? (string) $object->label : '';
}

/** Why the Combine list is empty, and what to do about it. */
function varconv_empty_text( array $scan, array $f, bool $filtered ): string {
	if ( '' !== $f['s'] && '' === $f['brand'] && 2 === $f['min'] && '' === $f['warn'] ) {
		return __( 'No suggested groups match this search. Products it finds that are in no group are listed below.', 'variation-converter-for-woocommerce' );
	}
	if ( $filtered ) {
		return __( 'No groups match these filters.', 'variation-converter-for-woocommerce' );
	}
	if ( 'skipped' === $f['view'] ) {
		return __( 'No skipped groups. Groups you mark "Skip, not variants" show here, and can be restored.', 'variation-converter-for-woocommerce' );
	}
	if ( 'ready' === $f['view'] || 'review' === $f['view'] ) {
		return __( 'No groups in this view.', 'variation-converter-for-woocommerce' );
	}
	if ( ! empty( $scan['skipped'] ) ) {
		return __( 'Every group the scan found is skipped. The Skipped view lists them.', 'variation-converter-for-woocommerce' );
	}
	if ( empty( $scan['products'] ) ) {
		return __( 'There are no simple products with a SKU to check. The scan only looks at simple products that have a SKU and are shown in the catalog.', 'variation-converter-for-woocommerce' );
	}
	return __( 'No groups found. The scan looks for simple products whose SKUs share a family (TEE100-BLK, TEE100-BLU) and whose names start the same ("Classic Tee Black", "Classic Tee Blue").', 'variation-converter-for-woocommerce' );
}

/** How a bulk job's notice is colored: info while it runs, then success, or warning when something was left undone. */
function varconv_job_status( array $job ): string {
	if ( varconv_job_running( $job ) ) {
		return 'info';
	}
	return $job['failed'] || $job['stopped'] || ( 'create' !== $job['kind'] && ! $job['made'] ) ? 'warning' : 'success';
}

/** A bulk job's notice as HTML (escaped here): its progress while it runs, then its summary. */
function varconv_job_html( array $job ): string {
	if ( varconv_job_running( $job ) ) {
		return esc_html( varconv_job_text( $job ) );
	}
	if ( 'create' === $job['kind'] ) {
		$stopped = $job['stopped'] ? esc_html( sprintf(
			/* translators: 1: groups done, 2: groups selected. */
			__( 'Bulk create stopped after %1$d of %2$d groups.', 'variation-converter-for-woocommerce' ),
			$job['done'],
			$job['total']
		) ) . ' ' : '';
		return $stopped . wp_kses_post( varconv_bulk_summary( $job['made'], $job['skip'], $job['failed'] ) );
	}
	$text = $job['stopped'] ? sprintf(
		/* translators: 1: conversions done, 2: conversions selected. */
		__( 'Stopped after %1$d of %2$d conversions.', 'variation-converter-for-woocommerce' ),
		$job['done'],
		$job['total']
	) . ' ' : '';
	if ( 'undo' === $job['kind'] ) {
		/* translators: %d: number of conversions undone. */
		$text .= sprintf( _n( 'Undid %d conversion. Its products are back as they were.', 'Undid %d conversions. Their products are back as they were.', $job['made'], 'variation-converter-for-woocommerce' ), $job['made'] );
		$why = __( 'Not undone:', 'variation-converter-for-woocommerce' );
	} elseif ( ! $job['made'] && ! $job['failed'] ) {
		$text .= __( 'Nothing was moved: none of these conversions has hidden originals left. Only combines whose originals are still hidden can move them to the Trash.', 'variation-converter-for-woocommerce' );
		$why = '';
	} else {
		$text .= sprintf(
			/* translators: 1: number of simple products moved to the Trash, 2: number of conversions they came from. */
			_n( 'Moved %1$d original simple product to the Trash, from %2$d combined products. Reviews are on the variable products now, and old pages redirect there. Undo restores them while they are in the Trash.', 'Moved %1$d original simple products to the Trash, from %2$d combined products. Reviews are on the variable products now, and old pages redirect there. Undo restores them while they are in the Trash.', $job['products'], 'variation-converter-for-woocommerce' ),
			$job['products'],
			$job['made']
		);
		$why = __( 'Not moved:', 'variation-converter-for-woocommerce' );
	}
	if ( $job['failed'] && $why ) {
		$text .= ' ' . $why . ' ' . implode( ' ', $job['errors'] );
		if ( $job['failed'] > count( $job['errors'] ) ) {
			/* translators: %d: number of other conversions that failed. */
			$text .= ' ' . sprintf( __( 'And %d more.', 'variation-converter-for-woocommerce' ), $job['failed'] - count( $job['errors'] ) );
		}
	}
	return esc_html( $text );
}

/**
 * The notice for selected products that were left out, one line per reason with the products it applies to; null
 * when none was.
 *
 * @param array<int,string> $reasons Product ID => why it was left out.
 */
function varconv_left_out_html( array $reasons ): ?array {
	if ( ! $reasons ) {
		return null;
	}
	$by_reason = array();
	foreach ( $reasons as $id => $reason ) {
		$by_reason[ $reason ][] = (int) $id;
	}
	$html = esc_html( sprintf(
		/* translators: %d: number of selected products left out. */
		_n( '%d selected product is left out:', '%d selected products are left out:', count( $reasons ), 'variation-converter-for-woocommerce' ),
		count( $reasons )
	) ) . '<ul>';
	foreach ( $by_reason as $reason => $ids ) {
		$html .= '<li>' . wp_kses_post( varconv_product_links( $ids ) ) . ': ' . esc_html( $reason ) . '</li>';
	}
	return array( 'status' => 'warning', 'html' => $html . '</ul>' );
}

/**
 * The notice that some of these products were made by a split, which can't be undone while they are in this
 * product; null when none were.
 *
 * @param int[] $ids
 */
function varconv_split_from_html( array $ids ): ?array {
	$from = array();
	foreach ( $ids as $id ) {
		$parent_id = (int) get_post_meta( (int) $id, VARCONV_SPLIT_FROM_META, true );
		if ( $parent_id && 'product' === get_post_type( $parent_id ) ) {
			$from[ $parent_id ] = (string) get_post_field( 'post_title', $parent_id, 'raw' );
		}
	}
	if ( ! $from ) {
		return null;
	}
	return array(
		'status' => 'info',
		'html'   => wp_kses_post( sprintf(
			/* translators: %s: links to the variable products. */
			__( 'Some of these products were made by splitting %s. Once they are combined, undo this combine in History before you undo that split.', 'variation-converter-for-woocommerce' ),
			varconv_named_links( $from )
		) ),
	);
}

/**
 * The notice that some of these products came from Make simple, which combining them ends; null when none did.
 *
 * @param int[] $ids
 */
function varconv_made_simple_html( array $ids ): ?array {
	$from = varconv_made_simple_from( $ids );
	if ( ! $from ) {
		return null;
	}
	$links = varconv_named_links( $from );
	return array(
		'status' => 'info',
		'html'   => wp_kses_post( sprintf(
			/* translators: %s: links to the variable products. */
			__( 'Some of these products were made by Make simple from %s. Once they are in this product, that Make simple can no longer be undone in History; the old variant URLs still lead here.', 'variation-converter-for-woocommerce' ),
			$links
		) ),
	);
}
