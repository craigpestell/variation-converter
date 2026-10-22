<?php
/** Offline checks for the variant collection scan (includes/scan.php). */
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

// The usual family: one name, colour at the end.
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
expect( $scan[0]['attribute'], 'Colour', 'colour attribute' );
expect( array_column( $scan[0]['members'], 'label' ), array( 'Glow in the Dark', 'Natural', 'Black', 'Red', 'Blue' ), 'labels in SKU order' );
expect( array_column( $scan[0]['members'], 'id' ), array( 17543, 17545, 17547, 17549, 17551 ), 'members in SKU order' );
expect( $scan[0]['notes'], array( 'Long labels; check these are variants of one product.' ), 'long label note' );
expect( strlen( $scan[0]['key'] ), 12, 'key length' );

// Sizes; a different product in the same family stays out.
$scan = varconv_scan( array(
	$p( 1, 'SE1412-01 BX', 'Ball Spreader Medium' ),
	$p( 2, 'SE1412-02 BX', 'Ball Spreader Large', '10.99' ),
	$p( 3, 'SE1412-50 BX', 'Double Helix Erection Arouser' ),
) );
expect( count( $scan ), 1, 'sizes' );
expect( $scan[0]['attribute'], 'Size', 'size attribute' );
expect( $scan[0]['notes'], array( 'Prices differ (9.99, 10.99).' ), 'price note' );

// Members pair with the member they share the most with; a lone one is not a collection.
$scan = varconv_scan( array(
	$p( 1, 'SE1105-05 CL', 'Pocket Exotics Single Bullet Silver' ),
	$p( 2, 'SE1105-09 CL', 'Pocket Exotics Single Bullet Ivory' ),
	$p( 3, 'SE1105-20 CL', 'Pocket Exotics Double Bullet Silver' ),
) );
expect( count( $scan ), 1, 'closest pair' );
expect( $scan[0]['name'], 'Pocket Exotics Single Bullet', 'closest pair name' );
expect( array_column( $scan[0]['members'], 'id' ), array( 1, 2 ), 'closest pair members' );

// Words every label ends with stay in the name; flavours.
$scan = varconv_scan( array(
	$p( 1, 'AB100-01', '100 ml Frutopia Banana Lube' ),
	$p( 2, 'AB100-02', '100 ml Frutopia Cherry Lube' ),
	$p( 3, 'AB100-03', '100 ml Frutopia Mango Lube' ),
) );
expect( $scan[0]['name'], '100 ml Frutopia Lube', 'suffix kept in the name' );
expect( array_column( $scan[0]['members'], 'label' ), array( 'Banana', 'Cherry', 'Mango' ), 'suffix taken off labels' );
expect( $scan[0]['attribute'], 'Flavour', 'flavour attribute' );

// Separators at the split stay out of the name and labels.
$scan = varconv_scan( array( $p( 1, 'SE2140-03', 'Zen - Tranquility - Black' ), $p( 2, 'SE2140-09', 'Zen - Bliss - White' ) ) );
expect( $scan[0]['name'], 'Zen', 'name without trailing dash' );
expect( array_column( $scan[0]['members'], 'label' ), array( 'Tranquility - Black', 'Bliss - White' ), 'labels' );

// Nothing in common, single products, missing SKUs, dismissed keys: no collections.
expect( varconv_scan( array( $p( 1, 'X1000-01', 'Alpha One' ), $p( 2, 'X1000-02', 'Beta Two' ) ) ), array(), 'no common name' );
expect( varconv_scan( array( $p( 1, 'X1000-01', 'Alpha One' ), $p( 2, '', 'Alpha Two' ) ) ), array(), 'no SKU' );
$two = array( $p( 1, 'X1000-01', 'Alpha Red' ), $p( 2, 'X1000-02', 'Alpha Blue', '9.99', array( 6 ) ) );
$scan = varconv_scan( $two );
expect( $scan[0]['notes'], array( 'Categories differ; the new product takes the first member\'s.' ), 'category note' );
expect( varconv_scan( $two, array( $scan[0]['key'] ) ), array(), 'dismissed' );
// A name that is all base gets an empty label and a note.
$scan = varconv_scan( array( $p( 1, 'X1000-01', 'Alpha' ), $p( 2, 'X1000-02', 'Alpha Blue' ) ) );
expect( array_column( $scan[0]['members'], 'label' ), array( '', 'Blue' ), 'empty label' );
expect( $scan[0]['notes'][0], 'A member has no variant words in its name; give it a label.', 'empty label note' );
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
expect( varconv_attribute( array( 'Green Velvet', 'Purple', 'Rose' ) ), 'Colour', 'colours' );

// Validation of what the admin submits.
expect( varconv_validate( 'Tri-Rings', 'Colour', array( 1 => 'Red', 2 => 'Blue' ) ), array(), 'valid' );
expect( varconv_validate( '', ' ', array( 1 => 'Red' ) ), array( 'The product needs a name.', 'The attribute needs a name (Colour, Size, ...).', 'Choose at least 2 products.' ), 'missing fields' );
expect( varconv_validate( 'X', 'Colour', array( 1 => 'Red', 2 => 'red', 3 => '', 4 => 'A|B' ) ), array( 'Label "red" is used twice.', 'Product 3 needs a variant label.', 'Labels cannot contain "|" (product 4).' ), 'bad labels' );

echo "Variant collection scan tests passed.\n";
