<?php
/**
 * Variation Converter: groups of simple products that are one product in several variants, the variant written into
 * the name ("Tri-Rings Black", "Tri-Rings Blue"). Members share a SKU family (SE1421-01 CL, SE1421-03 CL) and the
 * start of their name; what follows is the variant label. Pure functions, no WordPress calls besides translation; see
 * variation-converter.php and tests/test-scan.php.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Longest variant label the scan proposes, in words; longer differences are more likely different products. */
const VARCONV_MAX_LABEL_WORDS = 5;

/**
 * The SKU family: the SKU without its packaging suffix and last segment ("SE1421-01 CL" -> "SE1421",
 * "D1701-12" -> "D1701"). SKUs without a separator keep their first 5 characters ("W622001" -> "W6220"); the name check keeps unrelated products in such a family apart.
 */
function varconv_family( string $sku ): string {
	$sku = strtoupper( trim( preg_replace( '/[\s\x{00a0}]+/u', ' ', $sku ) ) );
	$sku = preg_replace( '/ [A-Z]{1,3}$/', '', $sku );
	if ( '' === $sku ) {
		return '';
	}
	$cut = strrpos( $sku, '-' );
	if ( false !== $cut && $cut > 0 ) {
		return rtrim( substr( $sku, 0, $cut ), ' -_.' );
	}
	return strlen( $sku ) > 5 ? substr( $sku, 0, 5 ) : $sku;
}

/** @return string[] The name's words, as written; a number and its unit stay one word ("12 oz", "12oz"). */
function varconv_words( string $name ): array {
	$words = preg_split( '/\s+/u', trim( $name ), -1, PREG_SPLIT_NO_EMPTY ) ?: array();
	$out = array();
	foreach ( $words as $word ) {
		$last = count( $out ) - 1;
		if ( $last >= 0 && preg_match( '/^\d+(?:[.,]\d+)?$/', $out[ $last ] ) && preg_match( '/^(?:oz|fl\.?oz|ml|l|g|kg|lb|lbs|cm|mm|in)\b/i', $word ) ) {
			$out[ $last ] .= ' ' . $word;
		} else {
			$out[] = $word;
		}
	}
	return $out;
}

/** Whether two words are the same, ignoring case and the space in "12 oz". */
function varconv_same_word( string $a, string $b ): bool {
	return 0 === strcasecmp( str_replace( ' ', '', $a ), str_replace( ' ', '', $b ) );
}

/** A word compared for brand names: lowercase, without trademark signs or punctuation at its ends. */
function varconv_norm( string $word ): string {
	$word = str_replace( array( '™', '®', '©' ), '', $word );
	// mbstring is optional in PHP and WordPress has no polyfill for mb_strtolower(); without it, ASCII lowercase.
	$word = function_exists( 'mb_strtolower' ) ? mb_strtolower( $word, 'UTF-8' ) : strtolower( $word );
	return trim( $word, " \t.,:;!'\"()" );
}

/**
 * Brand names as word lists, longest first ("Doc Johnson", "California Exotics"). Empty names and duplicates are
 * dropped.
 *
 * @param string[] $names
 * @return array<int,string[]>
 */
function varconv_brands( array $names ): array {
	$brands = array();
	foreach ( $names as $name ) {
		$words = array_values( array_filter( array_map( 'varconv_norm', varconv_words( html_entity_decode( (string) $name, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) ), 'strlen' ) );
		if ( $words ) {
			$brands[ implode( ' ', $words ) ] = $words;
		}
	}
	usort( $brands, static fn( $a, $b ) => count( $b ) <=> count( $a ) );
	return $brands;
}

/** Number of leading words of a name that are a brand name ("Doc Johnson Butt Plug Black" -> 2). */
function varconv_brand_words( array $words, array $brands ): int {
	foreach ( $brands as $brand ) {
		$n = count( $brand );
		if ( $n <= count( $words ) && array_map( 'varconv_norm', array_slice( $words, 0, $n ) ) === $brand ) {
			return $n;
		}
	}
	return 0;
}

/** Families a name start must begin names in before the scan treats it as a brand or product line. */
const VARCONV_LINE_FAMILIES = 5;

/**
 * Name starts of 1 to 3 words found in many SKU families ("Doc Johnson", "Pocket Exotics"): brands and product
 * lines typed into titles, which catch brands the store has not set up as brand terms.
 *
 * @param array<string,array> $families SKU family => its products.
 * @return string[]
 */
function varconv_lines( array $families ): array {
	$seen = array();
	foreach ( $families as $family => $products ) {
		foreach ( $products as $p ) {
			$words = array_map( 'varconv_norm', varconv_words( (string) $p['name'] ) );
			// Never the whole name: something must be left to compare.
			for ( $n = 1; $n <= min( 3, count( $words ) - 1 ); $n++ ) {
				$seen[ implode( ' ', array_slice( $words, 0, $n ) ) ][ $family ] = true;
			}
		}
	}
	return array_keys( array_filter( $seen, static fn( $f ) => count( $f ) >= VARCONV_LINE_FAMILIES ) );
}

/** Number of leading words two names share, ignoring case. */
function varconv_common_words( array $a, array $b ): int {
	$n = 0;
	$max = min( count( $a ), count( $b ) );
	while ( $n < $max && varconv_same_word( $a[ $n ], $b[ $n ] ) ) {
		++$n;
	}
	return $n;
}

/** The longest common start of strings, trimmed of separators ("SE1421-01 CL", "SE1421-03 CL" -> "SE1421"). */
function varconv_common_prefix( array $strings ): string {
	$prefix = (string) array_shift( $strings );
	foreach ( $strings as $s ) {
		$n = 0;
		$max = min( strlen( $prefix ), strlen( (string) $s ) );
		while ( $n < $max && $prefix[ $n ] === $s[ $n ] ) {
			++$n;
		}
		$prefix = substr( $prefix, 0, $n );
	}
	return rtrim( $prefix, " -_." );
}

/** Without separators left at either end where the name was split ("Zen -", "- Black"). */
function varconv_trim( string $text ): string {
	return (string) preg_replace( '/^[\s\-\x{2013}\x{2014}:,\/|]+|[\s\-\x{2013}\x{2014}:,\/|]+$/u', '', $text );
}

/** A variant label without brackets around all of it ("(Large)" -> "Large"). */
function varconv_unwrap( string $label ): string {
	return (string) preg_replace( '/^[(\[]\s*([^()\[\]]*?)\s*[)\]]$/u', '$1', $label );
}

/**
 * Where a size label goes in size order (XS before S before M), or null when it is not a size this knows. Labels
 * with a number sort by it ("8oz" before "12oz").
 */
function varconv_size_rank( string $label ): ?float {
	$sizes = array( 'xxs', 'xs', 's', 'm', 'l', 'xl', 'xxl', 'xxxl' );
	$words = array( 'extra small' => 1, 'x-small' => 1, 'small' => 2, 'medium' => 3, 'large' => 4, 'extra large' => 5, 'x-large' => 5, '2xl' => 6, '3xl' => 7, '4xl' => 8 );
	$key = strtolower( trim( $label ) );
	$at = array_search( $key, $sizes, true );
	if ( false !== $at ) {
		return (float) $at;
	}
	if ( isset( $words[ $key ] ) ) {
		return (float) $words[ $key ];
	}
	return preg_match( '/^(\d+(?:\.\d+)?)/', $key, $m ) ? 100 + (float) $m[1] : null;
}

/** Members in size order when every label is a size varconv_size_rank() knows; otherwise as they were. */
function varconv_size_order( array $members ): array {
	$ranks = array_map( static fn( $m ) => varconv_size_rank( (string) $m['label'] ), $members );
	if ( in_array( null, $ranks, true ) ) {
		return $members;
	}
	array_multisort( $ranks, SORT_ASC, SORT_NUMERIC, array_keys( $members ), $members );
	return array_values( $members );
}

/**
 * The variable product's SKU: the family when every member's SKU starts with it ("D0212-00 CD", "D0212-03 CD" ->
 * "D0212", not "D0212-0"), otherwise what the SKUs have in common.
 */
function varconv_parent_sku( string $family, array $members ): string {
	$skus = array_map( static fn( $m ) => strtoupper( trim( (string) $m['sku'] ) ), $members );
	$common = varconv_common_prefix( $skus );
	return '' !== $family && str_starts_with( $common . '-', $family . '-' ) ? $family : $common;
}

/** A guess at the variation attribute from the labels; the admin can change it before creating. */
function varconv_attribute( array $labels ): string {
	$kinds = array(
		'Color'   => 'black|white|red|blue|pink|purple|green|yellow|orange|brown|grey|gray|silver|gold|ivory|natural|clear|nude|beige|tan|teal|aqua|turquoise|lavender|lilac|violet|magenta|fuchsia|coral|rose|burgundy|navy|mint|plum|bronze|copper|chrome|smoke|frost|glow|blush|charcoal|cream|caramel|chocolate|vanilla|mocha|champagne|leopard|zebra|rainbow|transparent|multicolou?r',
		'Size'    => 'xs|s|m|l|xl|xxl|xxxl|2xl|3xl|4xl|xtra|x-large|small|medium|large|mini|petite|regular|plus|queen|o\/s|os|one size|[0-9.]+ ?(?:"|in|inch|inches|cm|mm)',
		'Flavor'  => 'banana|watermelon|strawberry|cherry|mango|raspberry|peach|apple|grape|lemon|lime|mint|peppermint|cinnamon|coconut|pineapple|blueberry|kiwi|passion fruit|cotton candy|bubble gum|unflavou?red',
		'Scent'   => 'lavender|rose petals|jasmine|sandalwood|unscented|fragrance free',
		'Volume'  => '[0-9.]+ ?(?:ml|oz|fl oz|g|l)',
	);
	$counts = array_fill_keys( array_keys( $kinds ), 0 );
	foreach ( $labels as $label ) {
		foreach ( $kinds as $kind => $words ) {
			if ( preg_match( '/(?:^|[\s\/(\-])(?:' . $words . ')(?=$|[\s\/),\-])/iu', (string) $label ) ) {
				++$counts[ $kind ];
			}
		}
	}
	arsort( $counts );
	$best = array_key_first( $counts );
	// Most labels must say it; a stray "Pink" among sizes is not a color attribute.
	return $counts[ $best ] * 2 > count( $labels ) ? $best : 'Option';
}

/**
 * Splits the products of one SKU family into collections. Each member's base name is the longest word start it
 * shares with another member, so "Pocket Exotics Single Bullet Silver/Ivory" are a pair and "Pocket Exotics Double
 * Bullet" stays out; members with the same base form a collection, and the rest of each name is its label.
 * A brand name at the start of the name does not count as shared: "Doc Johnson Butt Plug" and "Doc Johnson Kink
 * Wand" are different products of one brand, not variants.
 *
 * @param array<int,array{id:int,sku:string,name:string}> $products Same family.
 * @param array<int,string[]> $brands From varconv_brands().
 * @return array<int,array{brand:string,base:string,members:array}> Collections of 2 or more, labels added to each member.
 */
function varconv_split( array $products, array $brands = array() ): array {
	$words = array();
	$lead = array();
	foreach ( $products as $i => $p ) {
		$words[ $i ] = varconv_words( $p['name'] );
		$lead[ $i ] = varconv_brand_words( $words[ $i ], $brands );
	}
	$by_base = array();
	foreach ( $products as $i => $p ) {
		$best = 0;
		foreach ( $products as $j => $q ) {
			if ( $i === $j ) {
				continue;
			}
			$n = varconv_common_words( $words[ $i ], $words[ $j ] );
			// More in common than the brand, and both labels short enough to be a variant.
			if ( $n > $best && $n > max( $lead[ $i ], $lead[ $j ] ) && count( $words[ $i ] ) - $n <= VARCONV_MAX_LABEL_WORDS && count( $words[ $j ] ) - $n <= VARCONV_MAX_LABEL_WORDS ) {
				$best = $n;
			}
		}
		if ( $best ) {
			$key = strtolower( str_replace( ' ', '', implode( '|', array_slice( $words[ $i ], 0, $best ) ) ) );
			$by_base[ $key ][] = $i;
		}
	}
	$collections = array();
	foreach ( $by_base as $members ) {
		if ( count( $members ) < 2 ) {
			continue;
		}
		$n = count( $words[ $members[0] ] );
		foreach ( $members as $i ) {
			$n = min( $n, varconv_common_words( $words[ $members[0] ], $words[ $i ] ) );
		}
		$labels = array();
		foreach ( $members as $i ) {
			$labels[ $i ] = array_slice( $words[ $i ], $n );
		}
		// Words every label ends with belong to the name ("Pink Rechargeable", "Blue Rechargeable").
		$suffix = array();
		while ( true ) {
			$last = null;
			foreach ( $labels as $label ) {
				if ( count( $label ) < 2 || ( null !== $last && ! varconv_same_word( $last, end( $label ) ) ) ) {
					break 2;
				}
				$last = end( $label );
			}
			array_unshift( $suffix, end( $labels[ $members[0] ] ) );
			foreach ( $labels as $i => $label ) {
				$labels[ $i ] = array_slice( $label, 0, -1 );
			}
		}
		$out = array();
		foreach ( $members as $i ) {
			$label = varconv_unwrap( varconv_trim( implode( ' ', $labels[ $i ] ) ) );
			// A name with no variant words ("Alpha" beside "Alpha Blue") is labeled with its SKU, so it can still be created.
			$out[] = $products[ $i ] + array( 'label' => '' === $label ? trim( (string) $products[ $i ]['sku'] ) : $label );
		}
		$collections[] = array(
			// The brand the name starts with, as written ("Doc Johnson"), when it starts with one.
			'brand'   => implode( ' ', array_slice( $words[ $members[0] ], 0, $lead[ $members[0] ] ) ),
			'base'    => varconv_trim( implode( ' ', array_merge( array_slice( $words[ $members[0] ], 0, $n ), $suffix ) ) ),
			'members' => $out,
		);
	}
	return $collections;
}

/** A SKU compared for duplicates: uppercase, single spaces. */
function varconv_sku_key( string $sku ): string {
	return strtoupper( trim( (string) preg_replace( '/[\s\x{00a0}]+/u', ' ', $sku ) ) );
}

/**
 * SKUs on more than one product, with those products' IDs, lowest first. Two products with one SKU are a duplicate
 * listing, not two variants.
 *
 * @return array<string,int[]>
 */
function varconv_duplicate_skus( array $products ): array {
	$ids = array();
	foreach ( $products as $p ) {
		$key = varconv_sku_key( (string) $p['sku'] );
		if ( '' !== $key ) {
			$ids[ $key ][] = (int) $p['id'];
		}
	}
	$ids = array_filter( $ids, static fn( $list ) => count( $list ) > 1 );
	foreach ( $ids as &$list ) {
		sort( $list );
	}
	return $ids;
}

/** The products with one of each SKU: the lowest ID of a duplicated SKU, the others left out of the scan. */
function varconv_unique_skus( array $products ): array {
	usort( $products, static fn( $a, $b ) => (int) $a['id'] <=> (int) $b['id'] );
	$seen = array();
	$out = array();
	foreach ( $products as $p ) {
		$key = varconv_sku_key( (string) $p['sku'] );
		if ( '' === $key || ! isset( $seen[ $key ] ) ) {
			$seen[ $key ] = true;
			$out[] = $p;
		}
	}
	return $out;
}

/** Order of a collection's products: by SKU, then by name. */
function varconv_member_order( array $a, array $b ): int {
	return strnatcasecmp( (string) $a['sku'], (string) $b['sku'] ) ?: strnatcasecmp( (string) ( $a['name'] ?? '' ), (string) ( $b['name'] ?? '' ) );
}

/** The collection's brand for display: its members' brand terms, or else the brand its name starts with. */
function varconv_brand( array $collection ): string {
	$terms = array();
	foreach ( $collection['members'] as $m ) {
		foreach ( (array) ( $m['brands'] ?? array() ) as $brand ) {
			$terms[ strtolower( (string) $brand ) ] ??= (string) $brand;
		}
	}
	return $terms ? implode( ', ', $terms ) : (string) ( $collection['brand'] ?? '' );
}

/**
 * Candidate collections across the catalog, largest first.
 *
 * @param array<int,array{id:int,sku:string,name:string,price?:string,cats?:int[],brands?:string[]}> $products Published simple products.
 * @param string[] $dismissed Collection keys the admin marked "not a collection".
 * @param string[] $brand_names Brand names, which never count as the shared part of names; nor do name starts found
 *                              in many families (varconv_lines()).
 * @return array<int,array{key:string,family:string,name:string,brand:string,attribute:string,sku:string,members:array,notes:array<string,string>}>
 */
function varconv_scan( array $products, array $dismissed = array(), array $brand_names = array() ): array {
	$families = array();
	$duplicates = varconv_duplicate_skus( $products );
	$names = array_column( $products, 'name', 'id' );
	foreach ( varconv_unique_skus( $products ) as $p ) {
		$family = varconv_family( (string) $p['sku'] );
		if ( '' !== $family && '' !== trim( (string) $p['name'] ) ) {
			$families[ $family ][] = $p;
		}
	}
	$brands = varconv_brands( array_merge( $brand_names, varconv_lines( $families ) ) );
	$out = array();
	foreach ( $families as $family => $members ) {
		if ( count( $members ) < 2 ) {
			continue;
		}
		foreach ( varconv_split( $members, $brands ) as $collection ) {
			$ids = array_map( static fn( $m ) => (int) $m['id'], $collection['members'] );
			sort( $ids );
			$key = substr( md5( $family . '|' . implode( ',', $ids ) ), 0, 12 );
			if ( in_array( $key, $dismissed, true ) ) {
				continue;
			}
			usort( $collection['members'], 'varconv_member_order' );
			$labels = array_map( static fn( $m ) => $m['label'], $collection['members'] );
			$attribute = varconv_attribute( $labels );
			if ( 'Size' === $attribute ) {
				$collection['members'] = varconv_size_order( $collection['members'] );
			}
			$out[] = array(
				'key'       => $key,
				'family'    => $family,
				'name'      => $collection['base'],
				'brand'     => varconv_brand( $collection ),
				'attribute' => $attribute,
				'sku'       => varconv_parent_sku( $family, $collection['members'] ),
				'members'   => $collection['members'],
				'notes'     => varconv_notes( $collection['members'], $duplicates, $names ),
			);
		}
	}
	usort( $out, static fn( $a, $b ) => array( count( $b['members'] ), $a['name'] ) <=> array( count( $a['members'] ), $b['name'] ) );
	return $out;
}

/**
 * A collection from products the admin picked (Products > All Products > "Combine into variable product"), whatever
 * their SKUs: the words every name starts with are its name, the rest of each name is its label (its SKU when nothing
 * is left), and the SKUs' common start is its parent SKU.
 *
 * @param array<int,array{id:int,sku:string,name:string,price?:string,cats?:int[],brands?:string[]}> $products
 * @return array{key:string,family:string,name:string,brand:string,attribute:string,sku:string,members:array,notes:array<string,string>,manual:bool}
 */
function varconv_manual( array $products ): array {
	$products = array_values( $products );
	usort( $products, 'varconv_member_order' );
	$words = array_map( static fn( $p ) => varconv_words( (string) $p['name'] ), $products );
	$n = $words ? count( $words[0] ) : 0;
	foreach ( $words as $w ) {
		$n = min( $n, varconv_common_words( $words[0], $w ) );
	}
	$members = array();
	foreach ( $products as $i => $p ) {
		$label = varconv_trim( implode( ' ', array_slice( $words[ $i ], $n ) ) );
		$members[] = $p + array( 'label' => '' === $label ? trim( (string) $p['sku'] ) : $label );
	}
	$ids = array_map( static fn( $m ) => (int) $m['id'], $members );
	sort( $ids );
	$name = varconv_trim( implode( ' ', array_slice( $words[0] ?? array(), 0, $n ) ) );
	return array(
		'key'       => 'm' . substr( md5( 'manual|' . implode( ',', $ids ) ), 0, 11 ),
		'family'    => '',
		'name'      => '' !== $name ? $name : (string) ( $products[0]['name'] ?? '' ),
		'brand'     => varconv_brand( array( 'members' => $members ) ),
		'attribute' => varconv_attribute( array_column( $members, 'label' ) ),
		'sku'       => varconv_parent_sku( '', $members ),
		'members'   => $members,
		'notes'     => varconv_notes( $members, varconv_duplicate_skus( $products ) ),
		'manual'    => true,
	);
}

/** Collections of at least $min products. */
function varconv_min_size( array $collections, int $min ): array {
	return array_values( array_filter( $collections, static fn( $c ) => count( $c['members'] ) >= $min ) );
}

/** Collections without any of the notes $kinds (see varconv_notes()); label also covers repeat. */
function varconv_without( array $collections, array $kinds ): array {
	if ( in_array( 'label', $kinds, true ) ) {
		$kinds[] = 'repeat';
	}
	return array_values( array_filter( $collections, static fn( $c ) => ! array_intersect( array_keys( $c['notes'] ), $kinds ) ) );
}

/**
 * Collections without the members the admin unchecked (collection key => product IDs); a collection left with fewer
 * than 2 members is dropped.
 *
 * @param array<string,int[]> $excluded
 */
function varconv_without_excluded( array $collections, array $excluded ): array {
	$out = array();
	foreach ( $collections as $c ) {
		$ids = array_map( 'intval', (array) ( $excluded[ $c['key'] ?? '' ] ?? array() ) );
		if ( $ids ) {
			$c['members'] = array_values( array_filter( $c['members'], static fn( $m ) => ! in_array( (int) $m['id'], $ids, true ) ) );
		}
		if ( count( $c['members'] ) >= 2 ) {
			$out[] = $c;
		}
	}
	return $out;
}

/** The brands a collection's display brand names ("A, B" when its members carry two). */
function varconv_brand_list( array $c ): array {
	return array_values( array_filter( array_map( 'trim', explode( ',', (string) ( $c['brand'] ?? '' ) ) ), 'strlen' ) );
}

/** Collections of the brand $brand (case-insensitive); '' keeps them all. */
function varconv_of_brand( array $collections, string $brand ): array {
	if ( '' === $brand ) {
		return $collections;
	}
	return array_values( array_filter( $collections, static fn( $c ) => in_array( strtolower( $brand ), array_map( 'strtolower', varconv_brand_list( $c ) ), true ) ) );
}

/** Collections matching a search in their name, SKU, brand or members' SKUs and names (case-insensitive). */
function varconv_matching( array $collections, string $search ): array {
	if ( '' === trim( $search ) ) {
		return $collections;
	}
	return array_values( array_filter( $collections, static function ( $c ) use ( $search ) {
		$hay = implode( ' ', array_merge(
			array( $c['name'], $c['sku'], (string) ( $c['brand'] ?? '' ) ),
			array_column( $c['members'], 'sku' ),
			array_column( $c['members'], 'name' )
		) );
		return false !== stripos( $hay, trim( $search ) );
	} ) );
}

/** The lowest regular price among a collection's members (0 without prices). */
function varconv_low_price( array $c ): float {
	$prices = array_filter( array_map( static fn( $m ) => (float) ( $m['price'] ?? 0 ), $c['members'] ), static fn( $p ) => $p > 0 );
	return $prices ? min( $prices ) : 0.0;
}

/**
 * Collections in the order the list shows: products (largest first, the scan's order), name, price (lowest
 * first) or brand (collections without a brand last); $order asc or desc reverses that, and ties go by name.
 */
function varconv_sorted( array $collections, string $sort, string $order = '' ): array {
	$by_name = static fn( $a, $b ) => strnatcasecmp( $a['name'], $b['name'] );
	if ( 'name' === $sort ) {
		$compare = $by_name;
		$default = 'asc';
	} elseif ( 'price' === $sort ) {
		$compare = static fn( $a, $b ) => varconv_low_price( $a ) <=> varconv_low_price( $b ) ?: $by_name( $a, $b );
		$default = 'asc';
	} elseif ( 'brand' === $sort ) {
		$compare = static function ( $a, $b ) use ( $by_name ) {
			$x = (string) ( $a['brand'] ?? '' );
			$y = (string) ( $b['brand'] ?? '' );
			return array( '' === $x, strtolower( $x ) ) <=> array( '' === $y, strtolower( $y ) ) ?: $by_name( $a, $b );
		};
		$default = 'asc';
	} else {
		$compare = static fn( $a, $b ) => count( $b['members'] ) <=> count( $a['members'] ) ?: $by_name( $a, $b );
		$default = 'desc';
	}
	$flip = in_array( $order, array( 'asc', 'desc' ), true ) && $order !== $default;
	usort( $collections, static fn( $a, $b ) => $flip ? $compare( $b, $a ) : $compare( $a, $b ) );
	return $collections;
}

/** Collections carrying the note $kind (see varconv_notes()); label also covers repeat; '' keeps them all. */
function varconv_with( array $collections, string $kind ): array {
	if ( '' === $kind ) {
		return $collections;
	}
	$kinds = 'label' === $kind ? array( 'label', 'repeat' ) : array( $kind );
	return array_values( array_filter( $collections, static fn( $c ) => (bool) array_intersect( array_keys( $c['notes'] ), $kinds ) ) );
}

/**
 * Ways to pick part of a group by SKU: the shortest SKU start, longer than what every SKU shares, that splits the
 * members into at least 2 runs while leaving at least one run of 2 or more ("GS3-A" x2, "GS3-B" x3, "GS3-C" x1).
 * None when no such start exists, as when every SKU differs only in its last character.
 *
 * @param string[] $skus
 * @return array<string,int> SKU start => products, in the order first seen.
 */
function varconv_sku_bands( array $skus ): array {
	$skus = array_values( array_filter( array_map( static fn( $s ) => strtoupper( trim( (string) $s ) ), $skus ), 'strlen' ) );
	if ( count( $skus ) < 3 ) {
		return array();
	}
	$common = strlen( varconv_common_prefix( $skus ) );
	// varconv_common_prefix() trims separators; step back over them.
	while ( $common < strlen( $skus[0] ) && 1 === count( array_unique( array_map( static fn( $s ) => substr( $s, 0, $common + 1 ), $skus ) ) ) ) {
		++$common;
	}
	$longest = max( array_map( 'strlen', $skus ) );
	for ( $n = $common + 1; $n < $longest; $n++ ) {
		$bands = array();
		foreach ( $skus as $sku ) {
			$prefix = rtrim( substr( $sku, 0, $n ), ' -_.' );
			$bands[ $prefix ] = ( $bands[ $prefix ] ?? 0 ) + 1;
		}
		if ( count( $bands ) >= 2 && max( $bands ) >= 2 && count( $bands ) < count( $skus ) ) {
			return $bands;
		}
	}
	return array();
}

/** How far apart the members' regular prices are, in percent of the lowest (0 when they agree or are unknown). */
function varconv_spread( array $members ): int {
	$prices = array_filter( array_map( static fn( $m ) => (float) ( $m['price'] ?? 0 ), $members ), static fn( $p ) => $p > 0 );
	return count( $prices ) > 1 ? (int) round( ( max( $prices ) - min( $prices ) ) / min( $prices ) * 100 ) : 0;
}

/**
 * Things to check before creating, keyed by kind: dupes (another product has a member's SKU; it is left out), label
 * (a missing label, or the SKU standing in for one), repeat (a label used twice), long (long labels), price (prices
 * differ) and cats (categories differ). None of them blocks a create.
 *
 * @param array<string,int[]> $duplicates From varconv_duplicate_skus().
 * @param array<int,string>   $names      Product ID => name, to name the products left out.
 * @return array<string,string>
 */
function varconv_notes( array $members, array $duplicates = array(), array $names = array() ): array {
	$notes = array();
	$dupes = array();
	foreach ( $members as $m ) {
		foreach ( array_diff( $duplicates[ varconv_sku_key( (string) $m['sku'] ) ] ?? array(), array( (int) $m['id'] ) ) as $other ) {
			$dupes[] = isset( $names[ $other ] )
				/* translators: 1: product name, 2: product ID, 3: SKU. */
				? sprintf( __( '"%1$s" (#%2$d) has the same SKU, %3$s, as a product here', 'variation-converter' ), $names[ $other ], $other, trim( (string) $m['sku'] ) )
				/* translators: 1: product ID, 2: SKU. */
				: sprintf( __( 'product #%1$d has the same SKU, %2$s, as a product here', 'variation-converter' ), $other, trim( (string) $m['sku'] ) );
		}
	}
	if ( $dupes ) {
		/* translators: %s: the products left out and why, semicolon separated. */
		$notes['dupes'] = sprintf( _n( 'Left out as a duplicate: %s.', 'Left out as duplicates: %s.', count( $dupes ), 'variation-converter' ), implode( '; ', $dupes ) );
	}
	$labels = array_map( static fn( $m ) => strtolower( $m['label'] ), $members );
	if ( in_array( '', $labels, true ) ) {
		$notes['label'] = __( 'A member has no variant words in its name; give it a label.', 'variation-converter' );
	} elseif ( array_filter( $members, static fn( $m ) => 0 === strcasecmp( (string) $m['label'], trim( (string) $m['sku'] ) ) ) ) {
		$notes['label'] = __( 'A member has no variant words in its name, so its label is its SKU; change it if you like.', 'variation-converter' );
	}
	if ( count( array_unique( $labels ) ) < count( $labels ) ) {
		$notes['repeat'] = __( 'Two members have the same label.', 'variation-converter' );
	}
	foreach ( $labels as $label ) {
		if ( count( varconv_words( $label ) ) > 3 ) {
			$notes['long'] = __( 'Long labels; check these are variants of one product.', 'variation-converter' );
			break;
		}
	}
	$prices = array_unique( array_filter( array_map( static fn( $m ) => (string) ( $m['price'] ?? '' ), $members ), 'strlen' ) );
	if ( count( $prices ) > 1 ) {
		$spread = varconv_spread( $members );
		$notes['price'] = $spread
			/* translators: 1: the members' prices, comma separated, 2: how far apart they are, in percent. */
			? sprintf( __( 'Prices differ (%1$s; %2$d%% apart).', 'variation-converter' ), implode( ', ', $prices ), $spread )
			/* translators: %s: the members' prices, comma separated. */
			: sprintf( __( 'Prices differ (%s).', 'variation-converter' ), implode( ', ', $prices ) );
	}
	$cats = array_unique( array_map( static function ( $m ) {
		$c = array_map( 'intval', $m['cats'] ?? array() );
		sort( $c );
		return implode( ',', $c );
	}, $members ) );
	if ( count( $cats ) > 1 ) {
		$notes['cats'] = __( 'Categories differ. Choose the new product\'s categories in its card; Create from the list gives it the first product\'s.', 'variation-converter' );
	}
	return $notes;
}

/**
 * Checks a collection the admin submitted: member ID => label. Returns the problems, none when it can be created.
 *
 * @param array<int,string> $members
 */
function varconv_validate( string $name, string $attribute, array $members ): array {
	$errors = array();
	if ( '' === trim( $name ) ) {
		$errors[] = __( 'The product needs a name.', 'variation-converter' );
	}
	if ( '' === trim( $attribute ) ) {
		$errors[] = __( 'The attribute needs a name (Color, Size, ...).', 'variation-converter' );
	}
	if ( count( $members ) < 2 ) {
		$errors[] = __( 'Choose at least 2 products.', 'variation-converter' );
	}
	$seen = array();
	foreach ( $members as $id => $label ) {
		$label = trim( (string) $label );
		if ( '' === $label ) {
			/* translators: %d: product ID. */
			$errors[] = sprintf( __( 'Product %d needs a variant label.', 'variation-converter' ), $id );
		} elseif ( str_contains( $label, '|' ) ) {
			/* translators: %d: product ID. */
			$errors[] = sprintf( __( 'Labels cannot contain "|" (product %d).', 'variation-converter' ), $id );
		} elseif ( isset( $seen[ strtolower( $label ) ] ) ) {
			/* translators: %s: variant label. */
			$errors[] = sprintf( __( 'Label "%s" is used twice.', 'variation-converter' ), $label );
		}
		$seen[ strtolower( $label ) ] = true;
	}
	return $errors;
}
