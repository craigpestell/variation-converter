<?php
/**
 * Variation Converter: groups of simple products that are one product in several variants, the variant written into
 * the name ("Tri-Rings Black", "Tri-Rings Blue"). Members share a SKU family (SE1421-01 CL, SE1421-03 CL) and the
 * start of their name; what follows is the variant label. Pure functions, no WordPress calls; see
 * variation-converter.php and tests/test-scan.php.
 */

/** Longest variant label the scan proposes, in words; longer differences are more likely different products. */
const VARCONV_MAX_LABEL_WORDS = 5;

/**
 * The SKU family: the SKU without its packaging suffix and last segment ("SE1421-01 CL" -> "SE1421",
 * "D1701-12" -> "D1701"). SKUs without a separator keep their first 5 characters ("W622001" -> "W6220"), as the
 * dev preview grouped them; the name check keeps unrelated products in such a family apart.
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
		'Colour'  => 'black|white|red|blue|pink|purple|green|yellow|orange|brown|grey|gray|silver|gold|ivory|natural|clear|nude|beige|tan|teal|aqua|turquoise|lavender|lilac|violet|magenta|fuchsia|coral|rose|burgundy|navy|mint|plum|bronze|copper|chrome|smoke|frost|glow|blush|charcoal|cream|caramel|chocolate|vanilla|mocha|champagne|leopard|zebra|rainbow|transparent|multicolou?r',
		'Size'    => 'xs|s|m|l|xl|xxl|xxxl|2xl|3xl|4xl|xtra|x-large|small|medium|large|mini|petite|regular|plus|queen|o\/s|os|one size|[0-9.]+ ?(?:"|in|inch|inches|cm|mm)',
		'Flavour' => 'banana|watermelon|strawberry|cherry|mango|raspberry|peach|apple|grape|lemon|lime|mint|peppermint|cinnamon|coconut|pineapple|blueberry|kiwi|passion fruit|cotton candy|bubble gum|unflavou?red',
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
	// Most labels must say it; a stray "Pink" among sizes is not a colour attribute.
	return $counts[ $best ] * 2 > count( $labels ) ? $best : 'Option';
}

/**
 * Splits the products of one SKU family into collections. Each member's base name is the longest word start it
 * shares with another member, so "Pocket Exotics Single Bullet Silver/Ivory" are a pair and "Pocket Exotics Double
 * Bullet" stays out; members with the same base form a collection, and the rest of each name is its label.
 *
 * @param array<int,array{id:int,sku:string,name:string}> $products Same family.
 * @return array<int,array{base:string,members:array}> Collections of 2 or more, labels added to each member.
 */
function varconv_split( array $products ): array {
	$words = array();
	foreach ( $products as $i => $p ) {
		$words[ $i ] = varconv_words( $p['name'] );
	}
	$by_base = array();
	foreach ( $products as $i => $p ) {
		$best = 0;
		foreach ( $products as $j => $q ) {
			if ( $i === $j ) {
				continue;
			}
			$n = varconv_common_words( $words[ $i ], $words[ $j ] );
			// Both labels short enough to be a variant, and something in common left as the name.
			if ( $n > $best && count( $words[ $i ] ) - $n <= VARCONV_MAX_LABEL_WORDS && count( $words[ $j ] ) - $n <= VARCONV_MAX_LABEL_WORDS ) {
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
			$out[] = $products[ $i ] + array( 'label' => varconv_trim( implode( ' ', $labels[ $i ] ) ) );
		}
		$collections[] = array(
			'base'    => varconv_trim( implode( ' ', array_merge( array_slice( $words[ $members[0] ], 0, $n ), $suffix ) ) ),
			'members' => $out,
		);
	}
	return $collections;
}

/**
 * Candidate collections across the catalog, largest first.
 *
 * @param array<int,array{id:int,sku:string,name:string,price?:string,cats?:int[]}> $products Published simple products.
 * @param string[] $dismissed Collection keys the admin marked "not a collection".
 * @return array<int,array{key:string,name:string,attribute:string,sku:string,members:array,notes:string[]}>
 */
function varconv_scan( array $products, array $dismissed = array() ): array {
	$families = array();
	foreach ( $products as $p ) {
		$family = varconv_family( (string) $p['sku'] );
		if ( '' !== $family && '' !== trim( (string) $p['name'] ) ) {
			$families[ $family ][] = $p;
		}
	}
	$out = array();
	foreach ( $families as $family => $members ) {
		if ( count( $members ) < 2 ) {
			continue;
		}
		foreach ( varconv_split( $members ) as $collection ) {
			$ids = array_map( static fn( $m ) => (int) $m['id'], $collection['members'] );
			sort( $ids );
			$key = substr( md5( $family . '|' . implode( ',', $ids ) ), 0, 12 );
			if ( in_array( $key, $dismissed, true ) ) {
				continue;
			}
			usort( $collection['members'], static fn( $a, $b ) => strnatcasecmp( (string) $a['sku'], (string) $b['sku'] ) );
			$labels = array_map( static fn( $m ) => $m['label'], $collection['members'] );
			$out[] = array(
				'key'       => $key,
				'family'    => $family,
				'name'      => $collection['base'],
				'attribute' => varconv_attribute( $labels ),
				'sku'       => varconv_parent_sku( $family, $collection['members'] ),
				'members'   => $collection['members'],
				'notes'     => varconv_notes( $collection['members'] ),
			);
		}
	}
	usort( $out, static fn( $a, $b ) => array( count( $b['members'] ), $a['name'] ) <=> array( count( $a['members'] ), $b['name'] ) );
	return $out;
}

/** Things to check before creating; none of them blocks it. */
function varconv_notes( array $members ): array {
	$notes = array();
	$labels = array_map( static fn( $m ) => strtolower( $m['label'] ), $members );
	if ( in_array( '', $labels, true ) ) {
		$notes[] = 'A member has no variant words in its name; give it a label.';
	}
	if ( count( array_unique( $labels ) ) < count( $labels ) ) {
		$notes[] = 'Two members have the same label.';
	}
	foreach ( $labels as $label ) {
		if ( count( varconv_words( $label ) ) > 3 ) {
			$notes[] = 'Long labels; check these are variants of one product.';
			break;
		}
	}
	$prices = array_unique( array_filter( array_map( static fn( $m ) => (string) ( $m['price'] ?? '' ), $members ), 'strlen' ) );
	if ( count( $prices ) > 1 ) {
		$notes[] = 'Prices differ (' . implode( ', ', $prices ) . ').';
	}
	$cats = array_unique( array_map( static function ( $m ) {
		$c = array_map( 'intval', $m['cats'] ?? array() );
		sort( $c );
		return implode( ',', $c );
	}, $members ) );
	if ( count( $cats ) > 1 ) {
		$notes[] = 'Categories differ; the new product takes the first member\'s.';
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
		$errors[] = 'The product needs a name.';
	}
	if ( '' === trim( $attribute ) ) {
		$errors[] = 'The attribute needs a name (Colour, Size, ...).';
	}
	if ( count( $members ) < 2 ) {
		$errors[] = 'Choose at least 2 products.';
	}
	$seen = array();
	foreach ( $members as $id => $label ) {
		$label = trim( (string) $label );
		if ( '' === $label ) {
			$errors[] = 'Product ' . $id . ' needs a variant label.';
		} elseif ( str_contains( $label, '|' ) ) {
			$errors[] = 'Labels cannot contain "|" (product ' . $id . ').';
		} elseif ( isset( $seen[ strtolower( $label ) ] ) ) {
			$errors[] = 'Label "' . $label . '" is used twice.';
		}
		$seen[ strtolower( $label ) ] = true;
	}
	return $errors;
}
