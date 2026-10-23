<?php
/**
 * A neutral demo catalog, for wp eval-file. Safe to run again: products already there (by SKU, or by
 * name when there is no SKU) are skipped. Every product it makes has the meta _harness_seed.
 *
 * On purpose, it is messy the way real stores are: groups of simple products that are really one
 * product in several variants, with inconsistent names (case, spacing, dashes, brackets), SKUs in
 * several styles, a duplicate SKU, products without SKUs, sale prices, stock counts, a draft, a
 * hidden product and one real variable product.
 */
if ( ! function_exists( 'wc_get_product_id_by_sku' ) ) {
	fwrite( STDERR, "WooCommerce is not active.\n" );
	exit( 1 );
}

function harness_term( string $name, string $taxonomy = 'product_cat' ): int {
	$term = term_exists( $name, $taxonomy );
	if ( ! $term ) {
		$term = wp_insert_term( $name, $taxonomy );
	}
	return (int) $term['term_id'];
}

function harness_exists( array $p ): bool {
	if ( ! empty( $p['dup'] ) ) {
		$same = get_posts( array( 'post_type' => 'product', 'post_status' => 'any', 'meta_key' => '_sku', 'meta_value' => $p['sku'], 'fields' => 'ids' ) );
		return count( $same ) > 1;
	}
	if ( ! empty( $p['sku'] ) ) {
		return (bool) wc_get_product_id_by_sku( $p['sku'] );
	}
	$found = get_posts(
		array(
			'post_type'   => 'product',
			'post_status' => 'any',
			'title'       => $p['name'],
			'meta_key'    => '_harness_seed',
			'fields'      => 'ids',
		)
	);
	return (bool) $found;
}

// wp eval-file runs this file inside a function, so top-level variables are not globals.
function harness_simple( array $p ): bool {
	if ( harness_exists( $p ) ) {
		return false;
	}
	$product = new WC_Product_Simple();
	$product->set_name( $p['name'] );
	$product->set_status( $p['status'] ?? 'publish' );
	$product->set_regular_price( (string) $p['price'] );
	if ( isset( $p['sale'] ) ) {
		$product->set_sale_price( (string) $p['sale'] );
	}
	if ( isset( $p['stock'] ) ) {
		$product->set_manage_stock( true );
		$product->set_stock_quantity( $p['stock'] );
	}
	if ( isset( $p['weight'] ) ) {
		$product->set_weight( (string) $p['weight'] );
	}
	if ( isset( $p['visibility'] ) ) {
		$product->set_catalog_visibility( $p['visibility'] );
	}
	$product->set_short_description( $p['short'] ?? '' );
	// Categories and tags go on before the first save (WooCommerce 11 caches product instances).
	$product->set_category_ids( $p['cats'] );
	$product->set_tag_ids( $p['tags'] ?? array() );
	if ( ! empty( $p['dup'] ) ) {
		// A second product with an SKU already in use: WooCommerce refuses it, so it is set directly.
		$id = $product->save();
		update_post_meta( $id, '_sku', $p['sku'] );
	} else {
		if ( ! empty( $p['sku'] ) ) {
			$product->set_sku( $p['sku'] );
		}
		$id = $product->save();
	}
	update_post_meta( $id, '_harness_seed', 1 );
	wc_delete_product_transients( $id );
	return true;
}

$apparel   = harness_term( 'Apparel' );
$drinkware = harness_term( 'Drinkware' );
$coffee    = harness_term( 'Coffee' );
$home      = harness_term( 'Home' );
$sale      = harness_term( 'Sale' );
$gift      = harness_term( 'Gift ideas', 'product_tag' );

$products = array();

// Shirts: one tee in colors, one in sizes (XL costs more), names written several ways.
foreach ( array( 'Black' => 18, 'White' => 25, 'Heather Grey' => 9, 'Navy' => 0 ) as $color => $stock ) {
	$products[] = array( 'name' => "Classic Crew Tee $color", 'sku' => 'TEE100-' . strtoupper( substr( str_replace( ' ', '', $color ), 0, 3 ) ), 'price' => 24, 'stock' => $stock, 'cats' => array( $apparel ) );
}
$products[] = array( 'name' => 'Organic V-Neck Tee (Small)', 'sku' => 'VN220-S', 'price' => 28, 'cats' => array( $apparel ) );
$products[] = array( 'name' => 'Organic V-Neck Tee (Medium)', 'sku' => 'VN220-M', 'price' => 28, 'cats' => array( $apparel ) );
$products[] = array( 'name' => 'Organic V-neck tee (Large)', 'sku' => 'VN220-L', 'price' => 28, 'sale' => 22, 'cats' => array( $apparel, $sale ) );
$products[] = array( 'name' => 'Organic V-Neck Tee  (XL)', 'sku' => 'vn220-xl', 'price' => 30, 'cats' => array( $apparel ) );
$products[] = array( 'name' => 'Pocket Tee Olive', 'sku' => 'PKT-OLV', 'price' => 26, 'cats' => array( $apparel ) );

// Mugs: a color group with uneven spacing, a one-off, and a hidden discontinued one.
$products[] = array( 'name' => 'Enamel Camp Mug 12oz Navy', 'sku' => 'MUG12-NV', 'price' => 16, 'weight' => 0.5, 'cats' => array( $drinkware ), 'tags' => array( $gift ) );
$products[] = array( 'name' => 'Enamel Camp Mug 12 oz Red', 'sku' => 'MUG12-RD', 'price' => 16, 'weight' => 0.5, 'cats' => array( $drinkware ), 'tags' => array( $gift ) );
$products[] = array( 'name' => 'Enamel Camp Mug 12oz Forest Green ', 'sku' => 'MUG12-FG', 'price' => 16, 'weight' => 0.5, 'cats' => array( $drinkware ) );
$products[] = array( 'name' => 'Enamel Camp Mug 12oz Mustard', 'sku' => 'MUG12-MS', 'price' => 16, 'cats' => array( $drinkware ), 'visibility' => 'hidden' );
$products[] = array( 'name' => 'Speckled Stoneware Mug', 'sku' => 'MUG-SPK', 'price' => 22, 'cats' => array( $drinkware ) );

// Coffee: grinds of one blend (with a duplicate SKU and a lower-case name), sizes of another, a draft.
$products[] = array( 'name' => 'House Blend Coffee Whole Bean 340g', 'sku' => 'CF340-WB', 'price' => 15, 'stock' => 40, 'cats' => array( $coffee ) );
$products[] = array( 'name' => 'House Blend Coffee Whole Bean 340g', 'sku' => 'CF340-WB', 'dup' => true, 'price' => 15, 'cats' => array( $coffee ) );
$products[] = array( 'name' => 'House Blend Coffee Ground 340g', 'sku' => 'CF340-GR', 'price' => 15, 'stock' => 12, 'cats' => array( $coffee ) );
$products[] = array( 'name' => 'house blend coffee - Espresso Grind 340g', 'sku' => 'CF340-ES', 'price' => 15, 'stock' => 3, 'cats' => array( $coffee ) );
$products[] = array( 'name' => 'Single Origin Colombia 250g', 'sku' => 'SOC-250', 'price' => 14, 'cats' => array( $coffee ) );
$products[] = array( 'name' => 'Single Origin Colombia 1kg', 'sku' => 'SOC-1000', 'price' => 42, 'sale' => 38, 'cats' => array( $coffee, $sale ) );
$products[] = array( 'name' => 'Decaf Swiss Water 340g', 'sku' => 'DCF-340', 'price' => 17, 'status' => 'draft', 'cats' => array( $coffee ) );

// Candles: scents with a nested SKU style, and products without SKUs.
foreach ( array( 'LAV' => 'Lavender', 'CDS' => 'Cedar & Sage', 'VAN' => 'Vanilla Bean', 'SEA' => 'Sea Salt' ) as $code => $scent ) {
	$products[] = array( 'name' => "Soy Candle 8oz - $scent", 'sku' => "CDL-08-$code", 'price' => 'SEA' === $code ? 20 : 18, 'cats' => array( $home ), 'tags' => array( $gift ) );
}
$products[] = array( 'name' => 'Beeswax Taper Candles (Pair) Natural', 'price' => 12, 'cats' => array( $home ) );
$products[] = array( 'name' => 'Beeswax Taper Candles (Pair) White', 'price' => 12, 'cats' => array( $home ) );

// A brand at the start of unrelated names (Northwind), so "shares the first word" is not enough.
$products[] = array( 'name' => 'Northwind Tote Bag Natural', 'sku' => 'NW-TOTE-NAT', 'price' => 32, 'cats' => array( $home ) );
$products[] = array( 'name' => 'Northwind Tote Bag Black', 'sku' => 'NW-TOTE-BLK', 'price' => 32, 'cats' => array( $home ) );
$products[] = array( 'name' => 'Northwind Tumbler 20oz', 'sku' => 'NW-TMB-20', 'price' => 28, 'cats' => array( $drinkware ) );
$products[] = array( 'name' => 'Northwind Beanie Charcoal', 'sku' => 'NW-BNE-CHR', 'price' => 22, 'cats' => array( $apparel ) );

$made = 0;
foreach ( $products as $p ) {
	$made += (int) harness_simple( $p );
}

// One real variable product: Canvas Apron in two colors.
if ( ! wc_get_product_id_by_sku( 'APR-01' ) ) {
	$attribute = new WC_Product_Attribute();
	$attribute->set_name( 'Color' );
	$attribute->set_options( array( 'Sand', 'Charcoal' ) );
	$attribute->set_visible( true );
	$attribute->set_variation( true );
	$apron = new WC_Product_Variable();
	$apron->set_name( 'Canvas Apron' );
	$apron->set_sku( 'APR-01' );
	$apron->set_status( 'publish' );
	$apron->set_category_ids( array( $home ) );
	$apron->set_attributes( array( $attribute ) );
	$apron_id = $apron->save();
	foreach ( array( 'Sand' => 34, 'Charcoal' => 36 ) as $color => $price ) {
		$variation = new WC_Product_Variation();
		$variation->set_parent_id( $apron_id );
		$variation->set_attributes( array( 'color' => $color ) );
		$variation->set_sku( 'APR-01-' . strtoupper( substr( $color, 0, 3 ) ) );
		$variation->set_regular_price( (string) $price );
		$variation->save();
	}
	WC_Product_Variable::sync( $apron_id );
	update_post_meta( $apron_id, '_harness_seed', 1 );
	++$made;
}

$total = count( get_posts( array( 'post_type' => 'product', 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids', 'meta_key' => '_harness_seed' ) ) );
echo "Demo catalog: $made products added, $total in all.\n";
