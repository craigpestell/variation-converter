<?php
/** Offline checks for the variant collection scan (includes/scan.php): php tests/test-scan.php */
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../includes/scan.php';

function expect( $actual, $expected, string $what ): void {
	if ( $actual !== $expected ) {
		throw new RuntimeException( $what . ': ' . json_encode( array( 'expected' => $expected, 'actual' => $actual ), JSON_UNESCAPED_SLASHES ) );
	}
}

// SKU families: packaging suffix and last segment dropped; no separator keeps 5 characters.
expect( varconv_family( 'SE1421-01 CL' ), 'SE1421', 'family CL' );
expect( varconv_family( 'se1421-12  cl' ), 'SE1421', 'family case and spaces' );
expect( varconv_family( 'D1701-12 CD' ), 'D1701', 'family CD' );
expect( varconv_family( 'SE0436-70-3' ), 'SE0436-70', 'family two dashes' );
expect( varconv_family( 'W622001' ), 'W6220', 'family no separator' );
expect( varconv_family( 'AB12' ), 'AB12', 'family short' );
expect( varconv_family( '' ), '', 'family empty' );

expect( varconv_common_prefix( array( 'SE1421-01 CL', 'SE1421-03 CL', 'SE1421-12 CL' ) ), 'SE1421', 'parent SKU' );

expect( varconv_parent_sku( 'D0212', array( array( 'sku' => 'D0212-00 CD' ), array( 'sku' => 'D0212-03 CD' ) ) ), 'D0212', 'parent SKU is the family' );
expect( varconv_parent_sku( 'W6220', array( array( 'sku' => 'W622001' ), array( 'sku' => 'W622002' ) ) ), 'W62200', 'parent SKU without separator' );
expect( varconv_trim( ' - Zen - ' ), 'Zen', 'trim separators' );

$p = static fn( int $id, string $sku, string $name, string $price = '9.99', array $cats = array( 5 ) ) => compact( 'id', 'sku', 'name', 'price', 'cats' );

// The usual family: one name, color at the end.
$scan = varconv_scan( array(
	$p( 17543, 'SE1421-00 CL', 'Tri-Rings Glow in the Dark' ),
	$p( 17545, 'SE1421-01 CL', 'Tri-Rings Natural' ),
	$p( 17547, 'SE1421-03 CL', 'Tri-Rings Black' ),
	$p( 17551, 'SE1421-12 CL', 'Tri-Rings Blue' ),
	$p( 17549, 'SE1421-11 CL', 'Tri-Rings Red' ),
	$p( 17555, 'SE1425-00 CL', 'Prolong Beaded Ring' ),
) );
expect( count( $scan ), 1, 'one collection' );
expect( $scan[0]['name'], 'Tri-Rings', 'collection name' );
expect( $scan[0]['sku'], 'SE1421', 'collection SKU' );
expect( $scan[0]['attribute'], 'Color', 'color attribute' );
expect( array_column( $scan[0]['members'], 'label' ), array( 'Glow in the Dark', 'Natural', 'Black', 'Red', 'Blue' ), 'labels in SKU order' );
expect( array_column( $scan[0]['members'], 'id' ), array( 17543, 17545, 17547, 17549, 17551 ), 'members in SKU order' );
expect( $scan[0]['notes'], array( 'long' => 'Long labels; check these are variants of one product.' ), 'long label note' );
expect( strlen( $scan[0]['key'] ), 12, 'key length' );

// Sizes; a different product in the same family stays out.
$scan = varconv_scan( array(
	$p( 1, 'SE1412-01 BX', 'Ball Spreader Medium' ),
	$p( 2, 'SE1412-02 BX', 'Ball Spreader Large', '10.99' ),
	$p( 3, 'SE1412-50 BX', 'Double Helix Erection Arouser' ),
) );
expect( count( $scan ), 1, 'sizes' );
expect( $scan[0]['attribute'], 'Size', 'size attribute' );
expect( $scan[0]['notes'], array( 'price' => 'Prices differ (9.99, 10.99; 10% apart).' ), 'price note' );

// Members pair with the member they share the most with; a lone one is not a collection.
$scan = varconv_scan( array(
	$p( 1, 'SE1105-05 CL', 'Pocket Exotics Single Bullet Silver' ),
	$p( 2, 'SE1105-09 CL', 'Pocket Exotics Single Bullet Ivory' ),
	$p( 3, 'SE1105-20 CL', 'Pocket Exotics Double Bullet Silver' ),
) );
expect( count( $scan ), 1, 'closest pair' );
expect( $scan[0]['name'], 'Pocket Exotics Single Bullet', 'closest pair name' );
expect( array_column( $scan[0]['members'], 'id' ), array( 1, 2 ), 'closest pair members' );

// Words every label ends with stay in the name; flavors.
$scan = varconv_scan( array(
	$p( 1, 'AB100-01', '100 ml Frutopia Banana Lube' ),
	$p( 2, 'AB100-02', '100 ml Frutopia Cherry Lube' ),
	$p( 3, 'AB100-03', '100 ml Frutopia Mango Lube' ),
) );
expect( $scan[0]['name'], '100 ml Frutopia Lube', 'suffix kept in the name' );
expect( array_column( $scan[0]['members'], 'label' ), array( 'Banana', 'Cherry', 'Mango' ), 'suffix taken off labels' );
expect( $scan[0]['attribute'], 'Flavor', 'flavor attribute' );

// Separators at the split stay out of the name and labels.
$scan = varconv_scan( array( $p( 1, 'SE2140-03', 'Zen - Tranquility - Black' ), $p( 2, 'SE2140-09', 'Zen - Bliss - White' ) ) );
expect( $scan[0]['name'], 'Zen', 'name without trailing dash' );
expect( array_column( $scan[0]['members'], 'label' ), array( 'Tranquility - Black', 'Bliss - White' ), 'labels' );

// Nothing in common, single products, missing SKUs, dismissed keys: no collections.
expect( varconv_scan( array( $p( 1, 'X1000-01', 'Alpha One' ), $p( 2, 'X1000-02', 'Beta Two' ) ) ), array(), 'no common name' );
expect( varconv_scan( array( $p( 1, 'X1000-01', 'Alpha One' ), $p( 2, '', 'Alpha Two' ) ) ), array(), 'no SKU' );
$two = array( $p( 1, 'X1000-01', 'Alpha Red' ), $p( 2, 'X1000-02', 'Alpha Blue', '9.99', array( 6 ) ) );
$scan = varconv_scan( $two );
expect( $scan[0]['notes'], array( 'cats' => 'Categories differ; the new product takes the first member\'s.' ), 'category note' );
expect( varconv_scan( $two, array( $scan[0]['key'] ) ), array(), 'dismissed' );
// A name that is all base is labeled with its SKU, with a note.
$scan = varconv_scan( array( $p( 1, 'X1000-01', 'Alpha' ), $p( 2, 'X1000-02', 'Alpha Blue' ) ) );
expect( array_column( $scan[0]['members'], 'label' ), array( 'X1000-01', 'Blue' ), 'SKU as label' );
expect( $scan[0]['notes']['label'], 'A member has no variant words in its name, so its label is its SKU; change it if you like.', 'SKU label note' );
// Long differences are different products.
expect( varconv_scan( array( $p( 1, 'X1000-01', 'Alpha one two three four five six' ), $p( 2, 'X1000-02', 'Alpha Blue' ) ) ), array(), 'label too long' );

// Largest collections first.
$scan = varconv_scan( array(
	$p( 1, 'AA100-1', 'Small Thing Red' ), $p( 2, 'AA100-2', 'Small Thing Blue' ),
	$p( 3, 'BB100-1', 'Big Thing Red' ), $p( 4, 'BB100-2', 'Big Thing Blue' ), $p( 5, 'BB100-3', 'Big Thing Pink' ),
) );
expect( array_column( $scan, 'name' ), array( 'Big Thing', 'Small Thing' ), 'order' );

// Attribute guesses need most labels.
expect( varconv_attribute( array( 'Pink', 'Large', 'Huge' ) ), 'Option', 'mixed labels' );
expect( varconv_attribute( array( '1 oz', '2 oz' ) ), 'Volume', 'volume' );
expect( varconv_attribute( array( '7"', '8 inch' ) ), 'Size', 'inches' );
expect( varconv_attribute( array( 'Green Velvet', 'Purple', 'Rose' ) ), 'Color', 'colors' );

// Validation of what the admin submits.
expect( varconv_validate( 'Tri-Rings', 'Color', array( 1 => 'Red', 2 => 'Blue' ) ), array(), 'valid' );
expect( varconv_validate( '', ' ', array( 1 => 'Red' ) ), array( 'The product needs a name.', 'The attribute needs a name (Color, Size, ...).', 'Choose at least 2 products.' ), 'missing fields' );
expect( varconv_validate( 'X', 'Color', array( 1 => 'Red', 2 => 'red', 3 => '', 4 => 'A|B' ) ), array( 'Label "red" is used twice.', 'Product 3 needs a variant label.', 'Labels cannot contain "|" (product 4).' ), 'bad labels' );

// A shared brand is not a shared name: different products of one brand stay apart, variants still group.
$brand = array(
	$p( 1, 'DJ100-01', 'Doc Johnson Butt Plug' ),
	$p( 2, 'DJ100-02', 'Doc Johnson Kink Wand' ),
	$p( 3, 'DJ100-03', 'Doc Johnson Vibe Black' ),
	$p( 4, 'DJ100-04', 'Doc Johnson Vibe Pink' ),
);
expect( count( varconv_scan( array_slice( $brand, 0, 2 ) ) ), 1, 'without brands, a brand looks like a name' );
expect( varconv_scan( array_slice( $brand, 0, 2 ), array(), array( 'Doc Johnson' ) ), array(), 'brand alone is not shared' );
$scan = varconv_scan( $brand, array(), array( 'Doc Johnson®' ) );
expect( count( $scan ), 1, 'variants of a brand still group' );
expect( $scan[0]['name'], 'Doc Johnson Vibe', 'brand kept in the name' );
expect( $scan[0]['brand'], 'Doc Johnson', 'brand from the name' );
expect( array_column( $scan[0]['members'], 'id' ), array( 3, 4 ), 'brand group members' );
$scan = varconv_scan( array( $p( 1, 'Z100-1', 'Vibe Red' ) + array( 'brands' => array( 'Acme' ) ), $p( 2, 'Z100-2', 'Vibe Blue' ) + array( 'brands' => array( 'acme' ) ) ) );
expect( $scan[0]['brand'], 'Acme', 'brand from brand terms' );

// Name starts found in 5 or more SKU families are treated as brands even without brand terms.
$lines = array();
foreach ( array( 'Plug', 'Wand', 'Ring', 'Bead', 'Egg' ) as $i => $thing ) {
	$lines[] = $p( 10 + $i, 'L' . $i . '00-01', 'Pocket Exotics ' . $thing . ' Black' );
	$lines[] = $p( 20 + $i, 'L' . $i . '00-02', 'Pocket Exotics Other ' . $thing );
}
expect( varconv_lines( array( 'A' => array( $p( 1, 'A-1', 'Pocket Exotics X' ) ) ) ), array(), 'one family is not a line' );
expect( in_array( 'pocket exotics', varconv_lines( array_map( static fn( $x ) => array( $x ), array_combine( array_column( $lines, 'sku' ), $lines ) ) ), true ), true, 'line found' );
expect( varconv_scan( $lines ), array(), 'line alone is not shared' );

// Products sharing one SKU are copies: the lowest ID is kept, the rest noted.
$scan = varconv_scan( array( $p( 7, 'X2000-01', 'Gamma Red' ), $p( 3, 'X2000-01 ', 'Gamma Red' ), $p( 5, 'X2000-02', 'Gamma Blue' ) ) );
expect( array_column( $scan[0]['members'], 'id' ), array( 3, 5 ), 'duplicate SKU left out' );
expect( $scan[0]['notes']['dupes'], 'Duplicate products left out: X2000-01  (also on 7).', 'duplicate note' );
expect( varconv_duplicate_skus( array( $p( 2, 'a 1', 'x' ), $p( 1, 'A  1', 'y' ), $p( 3, 'B', 'z' ) ) ), array( 'A 1' => array( 1, 2 ) ), 'duplicate SKUs' );

// Members in SKU order, then name.
expect( varconv_member_order( array( 'sku' => 'A-2' ), array( 'sku' => 'A-10' ) ) < 0, true, 'natural SKU order' );
expect( varconv_member_order( array( 'sku' => 'A', 'name' => 'b' ), array( 'sku' => 'A', 'name' => 'a' ) ) > 0, true, 'then name' );
expect( varconv_spread( array( array( 'price' => '10' ), array( 'price' => '15' ), array( 'price' => '' ) ) ), 50, 'spread' );

// Bulk filters: group size, warnings, unchecked members.
$groups = array(
	array( 'key' => 'a', 'members' => array( array( 'id' => 1 ), array( 'id' => 2 ), array( 'id' => 3 ) ), 'notes' => array() ),
	array( 'key' => 'b', 'members' => array( array( 'id' => 4 ), array( 'id' => 5 ) ), 'notes' => array( 'price' => 'x' ) ),
	array( 'key' => 'c', 'members' => array( array( 'id' => 6 ), array( 'id' => 7 ) ), 'notes' => array( 'repeat' => 'x' ) ),
);
expect( array_column( varconv_min_size( $groups, 3 ), 'key' ), array( 'a' ), 'min size' );
expect( array_column( varconv_without( $groups, array( 'price' ) ), 'key' ), array( 'a', 'c' ), 'without price' );
expect( array_column( varconv_without( $groups, array( 'label' ) ), 'key' ), array( 'a', 'b' ), 'label covers repeat' );
$left = varconv_without_excluded( $groups, array( 'a' => array( 2 ), 'b' => array( 4 ) ) );
expect( array_column( $left, 'key' ), array( 'a', 'c' ), 'group left with one member dropped' );
expect( array_column( $left[0]['members'], 'id' ), array( 1, 3 ), 'unchecked member left out' );

echo "Variant collection scan tests passed.\n";
