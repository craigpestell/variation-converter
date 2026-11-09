<?php
// The Live Preview demo store. This file is the source of the runPHP step in blueprint.json:
// edit it, then run `php .wordpress-org/blueprints/build.php` and commit both files.
require '/wordpress/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/image.php';
foreach ( array(
	'woocommerce_currency' => 'USD', 'woocommerce_default_country' => 'US:OR', 'woocommerce_coming_soon' => 'no',
	'woocommerce_store_pages_only' => 'no', 'woocommerce_allow_tracking' => 'no', 'woocommerce_show_marketplace_suggestions' => 'no',
	'woocommerce_task_list_hidden' => 'yes', 'woocommerce_extended_task_list_hidden' => 'yes',
	'woocommerce_onboarding_profile' => array( 'skipped' => true ), 'blogname' => 'Demo Store',
) as $name => $value ) {
	update_option( $name, $value );
}
delete_transient( '_wc_activation_redirect' );
function demo_cat( $name ) {
	$term = term_exists( $name, 'product_cat' ) ?: wp_insert_term( $name, 'product_cat' );
	return (int) $term['term_id'];
}

// Flat product pictures drawn with GD: a shape in the product's color. One image per look, shared by products that look alike.
function demo_color( $im, $hex, $shade = 1.0 ) {
	$c = array_map( 'hexdec', str_split( ltrim( $hex, '#' ), 2 ) );
	return imagecolorallocate( $im, (int) min( 255, $c[0] * $shade ), (int) min( 255, $c[1] * $shade ), (int) min( 255, $c[2] * $shade ) );
}
function demo_points( array $xy, $s ) {
	// Scale around the bottom center, so smaller sizes stand on the same floor.
	$out = array();
	for ( $i = 0; $i < count( $xy ); $i += 2 ) {
		$out[] = (int) round( 300 + ( $xy[ $i ] - 300 ) * $s );
		$out[] = (int) round( 520 - ( 520 - $xy[ $i + 1 ] ) * $s );
	}
	return $out;
}
function demo_shape( $im, array $xy, $s, $fill, $line ) {
	$p = demo_points( $xy, $s );
	imagefilledpolygon( $im, $p, $fill );
	imagesetthickness( $im, 4 );
	imagepolygon( $im, $p, $line );
	imagesetthickness( $im, 1 );
}
function demo_text( $im, $text, $cx, $cy, $height, $color ) {
	// GD's built-in font, drawn small and scaled up, so no font file is needed.
	$w     = imagefontwidth( 5 ) * strlen( $text );
	$small = imagecreatetruecolor( $w, 15 );
	imagealphablending( $small, false );
	$clear = imagecolorallocatealpha( $small, 0, 0, 0, 127 );
	imagefill( $small, 0, 0, $clear );
	imagesavealpha( $small, true );
	imagestring( $small, 5, 0, 0, $text, $color );
	$k = $height / 15;
	imagealphablending( $im, true );
	imagecopyresized( $im, $small, (int) ( $cx - $w * $k / 2 ), (int) ( $cy - $height / 2 ), 0, 0, (int) ( $w * $k ), (int) $height, $w, 15 );
	imagedestroy( $small );
}
function demo_image( $shape, $hex, $s = 1.0, $accent = '', $label = '' ) {
	static $made = array();
	$key = sanitize_title( "$shape-$hex-$s-$accent-$label" );
	if ( isset( $made[ $key ] ) || ! function_exists( 'imagecreatetruecolor' ) ) {
		return $made[ $key ] ?? 0;
	}
	$im   = imagecreatetruecolor( 600, 600 );
	$bg   = demo_color( $im, '#f2f1ee' );
	$fill = demo_color( $im, $hex );
	$line = demo_color( $im, $hex, 0.7 );
	if ( hexdec( substr( $hex, 1, 2 ) ) > 230 ) {
		$line = demo_color( $im, '#c9c6bf' );
	}
	imagefill( $im, 0, 0, $bg );
	$shadow = demo_color( $im, '#e2e0db' );
	imagefilledellipse( $im, 300, 530, (int) ( 360 * $s ), 36, $shadow );
	if ( 'tee' === $shape || 'vneck' === $shape ) {
		demo_shape( $im, array( 240, 110, 150, 140, 80, 250, 160, 290, 185, 255, 185, 510, 415, 510, 415, 255, 440, 290, 520, 250, 450, 140, 360, 110 ), $s, $fill, $line );
		$neck = demo_points( array( 300, 108 ), $s );
		imagesetthickness( $im, 4 );
		if ( 'tee' === $shape ) {
			imagefilledellipse( $im, $neck[0], $neck[1], (int) ( 124 * $s ), (int) ( 70 * $s ), $bg );
			imagearc( $im, $neck[0], $neck[1], (int) ( 124 * $s ), (int) ( 70 * $s ), 0, 180, $line );
		} else {
			$v = demo_points( array( 238, 106, 362, 106, 300, 190 ), $s );
			imagefilledpolygon( $im, $v, $bg );
			imageline( $im, $v[0], $v[1] + 4, $v[4], $v[5], $line );
			imageline( $im, $v[4], $v[5], $v[2], $v[3] + 4, $line );
		}
		imagesetthickness( $im, 1 );
	} elseif ( 'mug' === $shape ) {
		imagefilledellipse( $im, 390, 340, 170, 180, $line );
		imagefilledellipse( $im, 390, 340, 110, 120, $bg );
		demo_shape( $im, array( 150, 190, 390, 190, 390, 470, 370, 500, 170, 500, 150, 470 ), 1, $fill, $line );
		imagefilledellipse( $im, 270, 190, 240, 40, demo_color( $im, $hex, 0.8 ) );
		if ( $accent ) {
			mt_srand( 7 );
			$dot = demo_color( $im, $accent );
			for ( $i = 0; $i < 90; $i++ ) {
				imagefilledellipse( $im, mt_rand( 165, 375 ), mt_rand( 215, 490 ), 7, 7, $dot );
			}
		} else {
			imagesetthickness( $im, 6 );
			imagearc( $im, 270, 190, 240, 40, 0, 360, demo_color( $im, '#ffffff' ) );
		}
	} elseif ( 'bag' === $shape ) {
		demo_shape( $im, array( 190, 140, 410, 140, 430, 510, 170, 510 ), $s, $fill, $line );
		demo_shape( $im, array( 185, 110, 415, 110, 415, 150, 185, 150 ), $s, demo_color( $im, $hex, 0.75 ), $line );
		demo_shape( $im, array( 220, 250, 380, 250, 380, 400, 220, 400 ), $s, demo_color( $im, '#f7f3ea' ), $line );
		demo_shape( $im, array( 220, 360, 380, 360, 380, 400, 220, 400 ), $s, demo_color( $im, $accent ), $line );
		$bean = demo_points( array( 300, 305 ), $s );
		imagefilledellipse( $im, $bean[0], $bean[1], (int) ( 60 * $s ), (int) ( 80 * $s ), demo_color( $im, '#5a3a22' ) );
	} elseif ( 'bottle' === $shape ) {
		demo_shape( $im, array( 270, 60, 330, 60, 330, 100, 270, 100 ), $s, demo_color( $im, '#2b2b2b' ), demo_color( $im, '#2b2b2b' ) );
		demo_shape( $im, array( 275, 100, 325, 100, 325, 180, 380, 240, 380, 510, 220, 510, 220, 240, 275, 180 ), $s, $fill, $line );
		demo_shape( $im, array( 220, 320, 380, 320, 380, 430, 220, 430 ), $s, demo_color( $im, '#fbfaf7' ), $line );
	}
	if ( '' !== $label ) {
		$at   = array( 'tee' => 330, 'vneck' => 330, 'bag' => 455, 'bottle' => 375 )[ $shape ] ?? 300;
		$p    = demo_points( array( 300, $at ), $s );
		$dark = 'bottle' === $shape || hexdec( substr( $hex, 1, 2 ) ) + hexdec( substr( $hex, 3, 2 ) ) + hexdec( substr( $hex, 5, 2 ) ) > 450;
		$ink  = $dark ? imagecolorallocate( $im, 40, 40, 40 ) : imagecolorallocate( $im, 255, 255, 255 );
		demo_text( $im, $label, $p[0], $p[1], (int) ( ( 'bottle' === $shape ? 44 : 90 ) * $s ), $ink );
	}
	ob_start();
	imagepng( $im );
	$png = ob_get_clean();
	imagedestroy( $im );
	$file = wp_upload_bits( "demo-$key.png", null, $png );
	if ( ! empty( $file['error'] ) ) {
		return $made[ $key ] = 0;
	}
	$id = wp_insert_attachment( array( 'post_mime_type' => 'image/png', 'post_title' => $key, 'post_status' => 'inherit' ), $file['file'] );
	wp_update_attachment_metadata( $id, wp_generate_attachment_metadata( $id, $file['file'] ) );
	return $made[ $key ] = $id;
}
// The pictures are flat 600px PNGs, so skip the resized copies (quicker in Playground).
add_filter( 'intermediate_image_sizes_advanced', '__return_empty_array' );

$apparel = demo_cat( 'Apparel' );
$drink   = demo_cat( 'Drinkware' );
$coffee  = demo_cat( 'Coffee' );
$tees    = array( 'Black' => '#26262a', 'Blue' => '#2f6fd0', 'Red' => '#cf3a32', 'White' => '#fbfbf9', 'Navy' => '#1f2f57' );
$mugs    = array( 'Navy' => '#1f2f57', 'Red' => '#cf3a32', 'Forest Green' => '#2f6b45' );
// Bag color and label stripe per grind.
$grinds  = array( 'Whole Bean' => array( '#b7a07a', '#7a4a2a' ), 'Ground' => array( '#4f7a5a', '#c8902f' ), 'Espresso Grind' => array( '#2e2826', '#c0392b' ) );
$rows    = array();
foreach ( array( 'Black' => 'BLK', 'Blue' => 'BLU', 'Red' => 'RED', 'White' => 'WHT' ) as $color => $code ) {
	$rows[] = array( "Classic Tee $color", "TEE100-$code", 24, $apparel, array( 'Black' => 18, 'Blue' => 7, 'Red' => 0, 'White' => 25 )[ $color ], array( 'tee', $tees[ $color ] ) );
}
foreach ( array( 'Black' => 'BLK', 'White' => 'WHT', 'Navy' => 'NVY' ) as $color => $code ) {
	foreach ( array( 'Small' => 'S', 'Medium' => 'M', 'Large' => 'L' ) as $size => $sc ) {
		$rows[] = array( "Everyday Tee $color $size", "ET-$code-$sc", 22, $apparel, null, array( 'tee', $tees[ $color ], array( 'S' => 0.85, 'M' => 0.92, 'L' => 1.0 )[ $sc ], '', $sc ) );
	}
}
foreach ( array( 'Small' => 'S', 'Medium' => 'M', 'Large' => 'L', 'XL' => 'XL' ) as $size => $sc ) {
	$rows[] = array( "Organic V-Neck Tee ($size)", "VN220-$sc", 'XL' === $sc ? 30 : 28, $apparel, null, array( 'vneck', '#6b7a4b', array( 'S' => 0.82, 'M' => 0.88, 'L' => 0.94, 'XL' => 1.0 )[ $sc ], '', $sc ) );
}
foreach ( array( 'Navy' => 'NV', 'Red' => 'RD', 'Forest Green' => 'FG' ) as $color => $code ) {
	$rows[] = array( "Enamel Camp Mug 12oz $color", "MUG12-$code", 16, $drink, null, array( 'mug', $mugs[ $color ] ) );
}
$rows[] = array( 'Speckled Stoneware Mug', 'MUG-SPK', 22, $drink, null, array( 'mug', '#d9d4ca', 1.0, '#6f6a62' ) );
foreach ( array( '340g' => array( 'CF340', 15, 0.8, array( 40, 12, 3 ) ), '1kg' => array( 'CF1000', 38, 1.0, array( 20, 8, 5 ) ) ) as $weight => $w ) {
	$i = 0;
	foreach ( array( 'Whole Bean' => 'WB', 'Ground' => 'GR', 'Espresso Grind' => 'ES' ) as $grind => $gc ) {
		$rows[] = array( "House Blend Coffee $grind $weight", "{$w[0]}-$gc", $w[1], $coffee, $w[3][ $i++ ], array( 'bag', $grinds[ $grind ][0], $w[2], $grinds[ $grind ][1], $weight ) );
	}
}
foreach ( array( 'Vanilla' => array( 'VA', '#efe0b0' ), 'Caramel' => array( 'CA', '#c2772b' ) ) as $flavor => $f ) {
	foreach ( array( '250ml' => array( '02', 9, 0.7 ), '750ml' => array( '07', 19, 0.88 ), '1L' => array( '10', 24, 1.0 ) ) as $size => $info ) {
		if ( 'Vanilla' !== $flavor || '1L' !== $size ) {
			$rows[] = array( "Barista Syrup $flavor $size", "SYR-{$f[0]}-{$info[0]}", $info[1], $coffee, null, array( 'bottle', $f[1], $info[2], '', $size ) );
		}
	}
}
foreach ( $rows as $row ) {
	if ( wc_get_product_id_by_sku( $row[1] ) ) {
		continue;
	}
	$p = new WC_Product_Simple();
	$p->set_name( $row[0] );
	$p->set_sku( $row[1] );
	$p->set_regular_price( (string) $row[2] );
	$p->set_category_ids( array( $row[3] ) );
	if ( null !== $row[4] ) {
		$p->set_manage_stock( true );
		$p->set_stock_quantity( $row[4] );
	}
	$image = demo_image( ...$row[5] );
	if ( $image ) {
		$p->set_image_id( $image );
	}
	$p->set_status( 'publish' );
	$p->save();
}
