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

/** @return string[] The name's words, as written. */
function varconv_words( string $name ): array {
	return preg_split( '/\s+/u', trim( $name ), -1, PREG_SPLIT_NO_EMPTY ) ?: array();
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
	while ( $n < $max && 0 === strcasecmp( $a[ $n ], $b[ $n ] ) ) {
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
			$key = strtolower( implode( ' ', array_slice( $words[ $i ], 0, $best ) ) );
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
				if ( count( $label ) < 2 || ( null !== $last && 0 !== strcasecmp( $last, end( $label ) ) ) ) {
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
			$label = varconv_trim( implode( ' ', $labels[ $i ] ) );
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
			$out[] = array(
				'key'       => $key,
				'family'    => $family,
				'name'      => $collection['base'],
				'brand'     => varconv_brand( $collection ),
				'attribute' => varconv_attribute( $labels ),
				'sku'       => varconv_parent_sku( $family, $collection['members'] ),
				'members'   => $collection['members'],
				'notes'     => varconv_notes( $collection['members'], $duplicates ),
			);
		}
	}
	usort( $out, static fn( $a, $b ) => array( count( $b['members'] ), $a['name'] ) <=> array( count( $a['members'] ), $b['name'] ) );
	return $out;
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
 * @return array<string,string>
 */
function varconv_notes( array $members, array $duplicates = array() ): array {
	$notes = array();
	$dupes = array();
	foreach ( $members as $m ) {
		$others = array_diff( $duplicates[ varconv_sku_key( (string) $m['sku'] ) ] ?? array(), array( (int) $m['id'] ) );
		if ( $others ) {
			/* translators: 1: a SKU, 2: IDs of the other products with that SKU, comma separated. */
			$dupes[] = sprintf( __( '%1$s (also on %2$s)', 'variation-converter' ), $m['sku'], implode( ', ', $others ) );
		}
	}
	if ( $dupes ) {
		/* translators: %s: the duplicated SKUs and their products, semicolon separated. */
		$notes['dupes'] = sprintf( __( 'Duplicate products left out: %s.', 'variation-converter' ), implode( '; ', $dupes ) );
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
		$notes['cats'] = __( 'Categories differ; the new product takes the first member\'s.', 'variation-converter' );
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
