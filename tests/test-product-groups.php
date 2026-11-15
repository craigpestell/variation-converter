<?php
/** Offline checks for the Products list grouping query (includes/admin-product-groups.php): php tests/test-product-groups.php */
require_once __DIR__ . '/bootstrap.php';
if ( ! function_exists( 'esc_sql' ) ) {
	function esc_sql( $s ) {
		return addslashes( (string) $s );
	}
}
require_once __DIR__ . '/../includes/admin-product-groups.php';

function expect( bool $ok, string $what ): void {
	if ( ! $ok ) {
		throw new RuntimeException( $what );
	}
}

$sql = varconv_group_where( 'wp_posts', 'wp_postmeta', '_varconv_collection_parent' );
expect( 0 === strpos( $sql, ' AND NOT EXISTS (' ), 'adds to the WHERE clause' );
expect( false !== strpos( $sql, 'varconv_m.post_id = wp_posts.ID' ), 'looks at the listed product' );
expect( false !== strpos( $sql, "varconv_m.meta_key = '_varconv_collection_parent'" ), 'by the original-to-combined-product key' );
expect( false !== strpos( $sql, "varconv_p.post_status <> 'trash'" ), 'only while the combined product is not in the Trash' );
expect( false !== strpos( $sql, "varconv_p.post_type = 'product'" ), 'and still exists as a product' );
expect( false !== strpos( varconv_group_where( 'p', 'm', "a'b" ), "a\\'b" ), 'key escaped' );
expect( substr_count( $sql, '(' ) === substr_count( $sql, ')' ), 'balanced' );

echo "Product groups: ok\n";
