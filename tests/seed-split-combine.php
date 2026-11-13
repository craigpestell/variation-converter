<?php
/**
 * A variable product with three sizes, split into three simple products, for tests/browser-split-combine.mjs:
 *   harness/wp-harness eval tests/seed-split-combine.php
 */
if ( ! defined( 'ABSPATH' ) || ! function_exists( 'varconv_split_product' ) ) {
	fwrite( STDERR, "Run with wp eval-file on a site where WooCommerce and Variation Converter are active.\n" );
	exit( 1 );
}
$attribute = new WC_Product_Attribute();
$attribute->set_name( 'Size' );
$attribute->set_options( array( 'Small', 'Medium', 'Large' ) );
$attribute->set_variation( true );
$attribute->set_visible( true );
$variable = new WC_Product_Variable();
$variable->set_name( 'Linen Apron' );
$variable->set_status( 'publish' );
$variable->set_attributes( array( $attribute ) );
$id = $variable->save();
$names = array();
foreach ( array( 'Small', 'Medium', 'Large' ) as $label ) {
	$v = new WC_Product_Variation();
	$v->set_parent_id( $id );
	$v->set_attributes( array( 'size' => $label ) );
	$v->set_sku( 'APRON-' . strtoupper( $label ) );
	$v->set_regular_price( '24' );
	$v->set_status( 'publish' );
	$names[ $v->save() ] = 'Linen Apron ' . $label;
}
WC_Product_Variable::sync( $id );
varconv_split_product( $id, $names );
echo "Linen Apron split into three simple products.\n";
