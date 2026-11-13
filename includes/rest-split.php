<?php
/**
 * REST routes for the Split tab: the list of variable products, a product's card (its variations, to split or make
 * simple), and the confirm screen for making one variation a simple product. See rest.php.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function varconv_rest_split_routes(): void {
	varconv_rest_route( '/split', 'GET', 'varconv_rest_split_list' );
	varconv_rest_route( '/split/(?P<id>\d+)', 'GET', 'varconv_rest_split_card' );
	varconv_rest_route( '/split/(?P<id>\d+)', 'POST', 'varconv_rest_split' );
	varconv_rest_route( '/variation/(?P<id>\d+)', 'GET', 'varconv_rest_variation' );
	varconv_rest_route( '/variation/(?P<id>\d+)', 'POST', 'varconv_rest_make_simple' );
}

/** The Split tab's list: one page of variable products for the view, search and sort, and each view's count. */
function varconv_rest_split_list( WP_REST_Request $request ): array {
	$search = varconv_rest_text( $request, 's' );
	$view = sanitize_key( (string) $request->get_param( 'view' ) );
	$view = in_array( $view, array( 'ready', 'combined' ), true ) ? $view : '';
	$page = max( 1, absint( $request->get_param( 'paged' ) ) );
	$orderby = 'variations' === $request->get_param( 'orderby' ) ? 'variations' : '';
	$order = 'asc' === $request->get_param( 'order' ) ? 'asc' : 'desc';
	$found = varconv_variables( $search, $page, VARCONV_PER_PAGE, $orderby, $order, $view );
	$counts = array();
	foreach ( array( '', 'ready', 'combined' ) as $key ) {
		$counts[ $key ] = $key === $view ? $found['total'] : varconv_variables( $search, 1, 1, '', '', $key )['total'];
	}
	$items = array();
	foreach ( $found['ids'] as $id ) {
		$p = wc_get_product( $id );
		if ( ! $p instanceof WC_Product_Variable ) {
			continue;
		}
		$items[] = array_merge( varconv_rest_product( $id ), array(
			'sku'        => (string) $p->get_sku( 'edit' ),
			'variations' => varconv_variations_text( $p ),
			'priceText'  => varconv_variable_price( $p ),
			'combined'   => '' !== (string) $p->get_meta( VARCONV_META ),
		) );
	}
	if ( '' !== $search ) {
		/* translators: %s: search text. */
		$empty = sprintf( __( 'No variable products match "%s".', 'variation-converter-for-woocommerce' ), $search );
	} elseif ( '' !== $view ) {
		$empty = __( 'No variable products in this view.', 'variation-converter-for-woocommerce' );
	} else {
		$empty = __( 'There are no variable products to split.', 'variation-converter-for-woocommerce' );
	}
	return array(
		'counts' => $counts,
		'total'  => $found['total'],
		'pages'  => max( 1, (int) ceil( $found['total'] / VARCONV_PER_PAGE ) ),
		'page'   => $page,
		/* translators: %s: number of variable products. */
		'label'  => sprintf( _n( '%s variable product', '%s variable products', $found['total'], 'variation-converter-for-woocommerce' ), number_format_i18n( $found['total'] ) ),
		'items'  => $items,
		'empty'  => $empty,
	);
}

/** One variation as a row of the Split card or of the Make simple screen. */
function varconv_rest_split_row( array $r ): array {
	return array_merge(
		array(
			'id'        => (int) $r['id'],
			'name'      => (string) $r['name'],
			'sku'       => '' !== (string) $r['sku'] ? (string) $r['sku'] : '#' . (int) $r['id'],
			'priceText' => '' !== (string) $r['price'] ? varconv_price_text( (float) $r['price'] ) : '',
			'stock'     => (string) $r['stock'],
			'stockText' => varconv_stock_text( (string) $r['stock'] ),
			'problem'   => (string) $r['problem'],
			'enabled'   => (bool) $r['enabled'],
			'member'    => $r['member'] ? varconv_rest_product( (int) $r['member'] ) : null,
			'taken'     => $r['taken'] ? varconv_rest_product( (int) $r['taken'] ) : null,
		),
		varconv_rest_image( (int) $r['image'] )
	);
}

/**
 * The card for one variable product: its variations, to split into simple products or to make just the checked ones
 * simple. "state" says why there is nothing to do when there is not: already split, a status that cannot be split,
 * or no variations.
 */
function varconv_rest_split_card( WP_REST_Request $request ): array {
	$id = absint( $request['id'] );
	$product = wc_get_product( $id );
	if ( ! $product instanceof WC_Product_Variable ) {
		throw new RuntimeException( esc_html__( 'That product is not a variable product.', 'variation-converter-for-woocommerce' ) );
	}
	$out = array_merge( varconv_rest_product( $id ), array(
		'combined' => '' !== (string) $product->get_meta( VARCONV_META ),
		'state'    => 'ok',
		'message'  => '',
		'rows'     => array(),
	) );
	if ( '' !== (string) $product->get_meta( VARCONV_SPLIT_META ) ) {
		$out['state'] = 'split';
		$out['message'] = __( 'This product is already split. Undo deletes the simple products made from it and shows it again.', 'variation-converter-for-woocommerce' );
		return $out;
	}
	if ( ! varconv_status_ok( $product->get_status() ) ) {
		$out['state'] = 'status';
		$out['message'] = __( 'Only published, private, pending or draft products can be split.', 'variation-converter-for-woocommerce' );
		return $out;
	}
	$out['rows'] = array_map( 'varconv_rest_split_row', varconv_split_rows( $product ) );
	if ( ! $out['rows'] ) {
		$out['state'] = 'empty';
		$out['message'] = __( 'This product has no variations to split.', 'variation-converter-for-woocommerce' );
	}
	return $out;
}

/**
 * Splits the product into one simple product per checked variation ("split"), or makes just the checked variations
 * simple and keeps the rest variable ("take").
 */
function varconv_rest_split( WP_REST_Request $request ): array {
	$id = absint( $request['id'] );
	$names = array();
	foreach ( (array) $request->get_param( 'rows' ) as $row ) {
		if ( is_array( $row ) && absint( $row['id'] ?? 0 ) ) {
			$names[ absint( $row['id'] ) ] = sanitize_text_field( (string) ( $row['name'] ?? '' ) );
		}
	}
	if ( 'take' === $request->get_param( 'action' ) ) {
		$made = varconv_take_out( $id, $names );
		// Scan again now, so the Combine tab's count is right.
		varconv_candidates();
		$removed = varconv_taken_all( $id );
		return array(
			'removed' => $removed,
			'notice'  => varconv_rest_notice( 'success', wp_kses_post( varconv_taken_notice_html( count( $made ), (int) reset( $made ), $removed ? $id : 0 ) ) ),
		);
	}
	$created = varconv_split_product( $id, $names );
	varconv_candidates();
	return array(
		'removed' => true,
		'notice'  => varconv_rest_notice( 'success', wp_kses_post( sprintf(
			/* translators: 1: product name, 2: number of simple products, 3: link to the History tab. */
			_n( 'Split %1$s into %2$d simple product. The variable product is now hidden; Undo is in <a href="%3$s">History</a>.', 'Split %1$s into %2$d simple products. The variable product is now hidden; Undo is in <a href="%3$s">History</a>.', count( $created ), 'variation-converter-for-woocommerce' ),
			esc_html( get_post_field( 'post_title', $id, 'raw' ) ),
			count( $created ),
			esc_url( varconv_url( array( 'tab' => 'history' ) ) )
		) ) ),
	);
}

/** Product categories as a tree, for the Make simple screen's checklist. */
function varconv_rest_categories(): array {
	$terms = get_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => false ) );
	$children = array();
	foreach ( is_wp_error( $terms ) ? array() : $terms as $term ) {
		$children[ (int) $term->parent ][] = $term;
	}
	$branch = static function ( int $parent ) use ( &$branch, $children ): array {
		$out = array();
		foreach ( $children[ $parent ] ?? array() as $term ) {
			$out[] = array(
				'id'       => (int) $term->term_id,
				'name'     => html_entity_decode( $term->name, ENT_QUOTES | ENT_HTML5, 'UTF-8' ),
				'children' => $branch( (int) $term->term_id ),
			);
		}
		return $out;
	};
	return $branch( 0 );
}

/**
 * The Make simple screen for one variation: what the simple product will be, its name and categories, and what
 * happens to the variable product.
 */
function varconv_rest_variation( WP_REST_Request $request ): array {
	$id = absint( $request['id'] );
	$back = 'edit' === $request->get_param( 'back' ) ? 'edit' : 'split';
	$variation = wc_get_product( $id );
	$parent = $variation instanceof WC_Product_Variation ? wc_get_product( $variation->get_parent_id() ) : null;
	$out = array(
		'backUrl' => varconv_take_back_url( $back, $parent ? $parent->get_id() : 0 ),
		'parent'  => $parent ? $parent->get_id() : 0,
		'problem' => '',
		'row'     => null,
	);
	if ( ! $variation instanceof WC_Product_Variation || ! $parent instanceof WC_Product_Variable ) {
		$out['problem'] = __( 'That variation was not found.', 'variation-converter-for-woocommerce' );
		return $out;
	}
	$row = array_column( varconv_split_rows( $parent ), null, 'id' )[ $id ] ?? null;
	$problem = varconv_taken_simple( $id ) ? __( 'This variation is already a simple product. Undo it in History to put it back.', 'variation-converter-for-woocommerce' ) : varconv_take_out_problem( $variation );
	if ( '' !== $problem || ! $row ) {
		$out['problem'] = '' !== $problem ? $problem : __( 'That variation was not found.', 'variation-converter-for-woocommerce' );
		return $out;
	}
	$title = (string) get_post_field( 'post_title', $parent->get_id(), 'raw' );
	$member = (int) $row['member'];
	$left = count( array_diff( varconv_enabled_children( $parent ), array( $id ) ) );
	$notes = array();
	if ( 0 === $left ) {
		/* translators: %s: variable product name. */
		$notes[] = array( 'status' => 'warning', 'text' => sprintf( __( 'This is the last variation of %s for sale, so the variable product will be moved to the Trash. Its old link will send shoppers to the simple products made from it. Undo in History restores it.', 'variation-converter-for-woocommerce' ), $title ) );
	} elseif ( 1 === $left ) {
		/* translators: %s: variable product name. */
		$notes[] = array( 'status' => 'info', 'text' => sprintf( __( '%s will have 1 variation left for sale.', 'variation-converter-for-woocommerce' ), $title ) );
	}
	if ( true !== $variation->get_manage_stock( 'edit' ) && $parent->get_manage_stock( 'edit' ) ) {
		$notes[] = array( 'status' => 'warning', 'text' => __( 'This variation uses the variable product\'s stock count, which the simple product can\'t share, so it won\'t track stock. Set its stock after.', 'variation-converter-for-woocommerce' ) );
	}
	$shown = varconv_rest_split_row( $row );
	if ( true === $variation->get_manage_stock( 'edit' ) ) {
		$shown['stockText'] .= ' (' . wc_stock_amount( (float) $variation->get_stock_quantity( 'edit' ) ) . ')';
	}
	return array_merge( $out, array(
		/* translators: %s: variable product name. */
		'title'      => sprintf( __( 'Make a variation of %s a simple product', 'variation-converter-for-woocommerce' ), $title ),
		'lead'       => ( $member
			? __( 'Its original simple product, which Combine made this variation from, is shown again with the variation\'s current price, stock and description. It keeps its own page, reviews and orders, and leaves the variable product.', 'variation-converter-for-woocommerce' )
			: __( 'A new simple product is made from this variation, with its SKU, price, stock, weight and image, and the variable product\'s description, tags and other details.', 'variation-converter-for-woocommerce' ) )
			. ' ' . __( 'The variation is disabled, not deleted, and its old link sends shoppers to the simple product. The rest of the variable product stays as it is. Undo is in History.', 'variation-converter-for-woocommerce' ),
		'notes'      => $notes,
		'row'        => $shown,
		'catsHelp'   => $member ? __( 'Checked: the original product\'s own categories.', 'variation-converter-for-woocommerce' ) : __( 'Checked: the variable product\'s categories.', 'variation-converter-for-woocommerce' ),
		'categories' => varconv_rest_categories(),
		'chosenCats' => array_values( array_map( 'intval', varconv_take_out_categories( $variation ) ) ),
	) );
}

/**
 * Makes one variation a simple product, with the name and categories given. Answers with where to go next: back to
 * the product edit screen or the Split tab, or to the new product when the variable product was removed.
 */
function varconv_rest_make_simple( WP_REST_Request $request ): array {
	$id = absint( $request['id'] );
	$back = 'edit' === $request->get_param( 'back' ) ? 'edit' : 'split';
	$parent = (int) wp_get_post_parent_id( $id );
	if ( 'product_variation' !== get_post_type( $id ) || ! $parent ) {
		/* translators: %d: product ID. */
		throw new RuntimeException( esc_html( sprintf( __( 'Product %d is not a variation.', 'variation-converter-for-woocommerce' ), $id ) ) );
	}
	$made = varconv_take_out( $parent, array( $id => varconv_rest_text( $request, 'name' ) ), array( $id => varconv_rest_ids( $request->get_param( 'categories' ) ) ) );
	varconv_candidates();
	$removed = varconv_taken_all( $parent );
	$args = array( 'varconv_done' => 'taken', 'varconv_product' => $parent, 'varconv_count' => count( $made ), 'varconv_made' => (int) reset( $made ) );
	if ( $removed ) {
		$args['varconv_removed'] = 1;
	}
	$out = array(
		'removed' => $removed,
		'parent'  => $parent,
		'notice'  => varconv_rest_notice( 'success', wp_kses_post( varconv_taken_notice_html( count( $made ), (int) reset( $made ), $removed ? $parent : 0 ) ) ),
		'url'     => '',
	);
	if ( 'edit' === $back ) {
		// The product edit screen shows the notice itself. A removed variable product has no edit screen: show the new product.
		$out['url'] = add_query_arg( $args, (string) get_edit_post_link( $removed ? (int) reset( $made ) : $parent, 'url' ) );
	}
	return $out;
}
