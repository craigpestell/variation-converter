<?php
/**
 * WP-CLI commands: wp varconv combine | undo | spelling.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Combines simple products into variable products, or undoes conversions, from the command line.
 */
class Varconv_CLI_Command {

	/**
	 * Creates a variable product from groups the scan proposes, as the admin page's Create would with the proposed
	 * name, attribute, SKU and labels. Members unchecked on the admin page are left out. Groups that need a label
	 * are skipped for review on the admin page; a parent SKU already in use is left empty.
	 *
	 * ## OPTIONS
	 *
	 * [<key>...]
	 * : Keys of the groups to create (the key is shown with --dry-run).
	 *
	 * [--all]
	 * : Create every group the scan proposes, instead of the keys given.
	 *
	 * [--dry-run]
	 * : List what would be created, and change nothing.
	 *
	 * [--limit=<n>]
	 * : Create at most this many groups.
	 *
	 * [--min-size=<n>]
	 * : Only groups of at least this many products.
	 * ---
	 * default: 2
	 * ---
	 *
	 * [--skip=<kinds>]
	 * : Leave out groups with these warnings, comma separated: dupes, label, long, price, cats.
	 *
	 * ## EXAMPLES
	 *
	 *     wp varconv combine --all --dry-run
	 *     wp varconv combine --all --min-size=3 --skip=price,cats
	 *     wp varconv combine 3f2a9c81d0e4
	 *
	 * @param string[] $args
	 * @param array    $options
	 */
	public function combine( $args, $options ) {
		$all = ! empty( $options['all'] );
		if ( ! $all && ! $args ) {
			WP_CLI::error( 'Give the keys of the groups to create, or --all.' );
		}
		$dry = ! empty( $options['dry-run'] );
		$min = max( 2, (int) ( $options['min-size'] ?? 2 ) );
		varconv_candidates( true );
		$collections = varconv_bulk_candidates( $min, array_filter( array_map( 'trim', explode( ',', (string) ( $options['skip'] ?? '' ) ) ) ) );
		if ( ! $all ) {
			$collections = array_values( array_filter( $collections, static fn( $c ) => in_array( $c['key'], $args, true ) ) );
			$missing = array_diff( $args, array_column( $collections, 'key' ) );
			if ( $missing ) {
				WP_CLI::warning( 'Not found (already created, dismissed or filtered out): ' . implode( ', ', $missing ) );
			}
		}
		if ( isset( $options['limit'] ) ) {
			$collections = array_slice( $collections, 0, max( 1, (int) $options['limit'] ) );
		}
		if ( ! $dry && ! varconv_lock() ) {
			WP_CLI::error( 'Another bulk run (here or on the admin page) is creating products; wait for it to finish.' );
		}
		$counts = array( 'made' => 0, 'would' => 0, 'skip' => 0, 'failed' => 0 );
		try {
			foreach ( $collections as $c ) {
				$result = varconv_convert( $c, $dry );
				++$counts[ $result['status'] ];
				$line = implode( "\t", array_filter( array(
					strtoupper( $result['status'] ),
					$result['id'] ? (string) $result['id'] : '',
					$c['key'],
					$c['sku'],
					$c['name'],
					varconv_attribute_name( $c['attribute'] ) . ': ' . implode( ' / ', array_column( $c['members'], 'label' ) ),
					$result['message'],
				), 'strlen' ) );
				'failed' === $result['status'] ? WP_CLI::warning( $line ) : WP_CLI::log( $line );
				if ( ! $dry ) {
					varconv_lock_renew();
				}
			}
		} finally {
			if ( ! $dry ) {
				varconv_unlock();
			}
		}
		$summary = sprintf(
			'%d %s, %d skipped (need a label on the admin page), %d failed, of %d groups.',
			$dry ? $counts['would'] : $counts['made'],
			$dry ? 'would be created' : 'created',
			$counts['skip'],
			$counts['failed'],
			count( $collections )
		);
		$counts['failed'] ? WP_CLI::error( $summary ) : WP_CLI::success( $summary );
	}

	/**
	 * Undoes conversions, as the admin page's Undo does. For a combined product, deletes it and shows its simple
	 * products again; for a split product, deletes the simple products made from it and shows it again.
	 *
	 * ## OPTIONS
	 *
	 * [<id>...]
	 * : IDs of the variable products to undo.
	 *
	 * [--all]
	 * : Undo every conversion, in both directions.
	 *
	 * [--yes]
	 * : Don't ask for confirmation.
	 *
	 * ## EXAMPLES
	 *
	 *     wp varconv undo 1234
	 *     wp varconv undo --all --yes
	 *
	 * @param string[] $args
	 * @param array    $options
	 */
	public function undo( $args, $options ) {
		$ids = ! empty( $options['all'] ) ? array_merge( varconv_created(), varconv_split_list() ) : array_map( 'intval', $args );
		if ( ! $ids ) {
			WP_CLI::error( empty( $options['all'] ) ? 'Give the IDs of the products to undo, or --all.' : 'Nothing to undo.' );
		}
		WP_CLI::confirm( sprintf( 'Undo %d conversions?', count( $ids ) ), $options );
		$done = 0;
		foreach ( $ids as $id ) {
			try {
				if ( '' !== (string) get_post_meta( $id, VARCONV_META, true ) ) {
					WP_CLI::log( sprintf( "UNDONE\t%d\tcombined; %d simple products visible again", $id, varconv_undo( $id ) ) );
				} else {
					WP_CLI::log( sprintf( "UNDONE\t%d\tsplit; %d simple products deleted", $id, varconv_split_undo( $id ) ) );
				}
				++$done;
			} catch ( RuntimeException $e ) {
				WP_CLI::warning( $id . "\t" . $e->getMessage() );
			}
		}
		$summary = sprintf( 'Undid %d of %d conversions.', $done, count( $ids ) );
		$done < count( $ids ) ? WP_CLI::error( $summary ) : WP_CLI::success( $summary );
	}

	/**
	 * Shows or sets how the store spells attribute names, and renames custom attributes spelled the other way (Color
	 * on some products, Colour on others) to it, with their variations. Global attributes (Products > Attributes) are
	 * only reported; merge those in WooCommerce.
	 *
	 * ## OPTIONS
	 *
	 * [--set=<spelling>]
	 * : Save the store's spelling first.
	 * ---
	 * options:
	 *   - british
	 *   - american
	 * ---
	 *
	 * [--rename]
	 * : Rename the products listed. Without it, only list them.
	 *
	 * [--yes]
	 * : Don't ask for confirmation.
	 *
	 * ## EXAMPLES
	 *
	 *     wp varconv spelling
	 *     wp varconv spelling --set=british
	 *     wp varconv spelling --rename --yes
	 *
	 * @param string[] $args
	 * @param array    $options
	 */
	public function spelling( $args, $options ) {
		if ( isset( $options['set'] ) ) {
			update_option( VARCONV_SPELLING_OPTION, $options['set'] );
		}
		$spelling = varconv_spelling();
		WP_CLI::log( sprintf( 'Spelling: %s (%s)', $spelling, implode( ', ', array_map( static fn( $n ) => varconv_spell( $n, $spelling, varconv_spelling_pairs() ), array_keys( varconv_spelling_pairs() ) ) ) ) );
		foreach ( varconv_global_spelling_twins() as $twins ) {
			WP_CLI::warning( sprintf( 'Global attributes %s are both in Products > Attributes; merge them in WooCommerce.', $twins ) );
		}
		$ids = varconv_misspelled_products();
		foreach ( $ids as $id ) {
			$renames = varconv_respellings( $id );
			WP_CLI::log( sprintf( "%d\t%s\t%s", $id, varconv_product_name( $id ), implode( ', ', array_map( static fn( $from, $to ) => $from . ' => ' . $to, array_keys( $renames ), $renames ) ) ) );
		}
		if ( ! $ids ) {
			WP_CLI::success( 'Every product uses the store\'s spelling.' );
			return;
		}
		if ( empty( $options['rename'] ) ) {
			WP_CLI::log( sprintf( '%d products to rename; run again with --rename to rename them.', count( $ids ) ) );
			return;
		}
		WP_CLI::confirm( sprintf( 'Rename the attributes of %d products?', count( $ids ) ), $options );
		$result = varconv_respell_all();
		foreach ( $result['failed'] as $message ) {
			WP_CLI::warning( $message );
		}
		$summary = sprintf( 'Renamed %d of %d products.', $result['done'], count( $ids ) );
		$result['failed'] ? WP_CLI::error( $summary ) : WP_CLI::success( $summary );
	}
}

WP_CLI::add_command( 'varconv', 'Varconv_CLI_Command' );
