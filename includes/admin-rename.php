<?php
/**
 * "Set one name": give the checked simple products one shared name, each followed by its variant ("Classic Tee" +
 * "Black"), so the scan groups them and Combine can make them one variable product. Reached from the Combine tab's
 * Set one name button and from Products > All Products. The old titles are kept in meta, and the notice after it has
 * Undo.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** A product's title before Set one name changed it, for Undo. */
const VARCONV_OLD_TITLE_META = '_varconv_old_title';

/** Most products Set one name takes at a time. */
const VARCONV_RENAME_MAX = 200;

add_filter( 'bulk_actions-edit-product', 'varconv_rename_bulk_action' );
function varconv_rename_bulk_action( array $actions ): array {
	if ( current_user_can( VARCONV_CAP ) ) {
		$actions['varconv_rename'] = __( 'Set one name for variants', 'variation-converter-for-woocommerce' );
	}
	return $actions;
}

// WordPress checks the list's bulk-posts nonce before this filter runs.
add_filter( 'handle_bulk_actions-edit-product', 'varconv_handle_rename_bulk_action', 10, 3 );
function varconv_handle_rename_bulk_action( string $redirect, string $action, array $ids ): string {
	if ( 'varconv_rename' !== $action || ! current_user_can( VARCONV_CAP ) ) {
		return $redirect;
	}
	return varconv_url( array( 'rename' => implode( ',', array_map( 'absint', $ids ) ) ) );
}

/**
 * The simple products among $ids this user can rename, as ID => title, and the rest. Products already in a variable
 * product made here are left out.
 *
 * @param int[] $ids
 * @return array{titles:array<int,string>,refused:int[]}
 */
function varconv_renamable( array $ids ): array {
	$titles = array();
	$refused = array();
	foreach ( array_slice( array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) ), 0, VARCONV_RENAME_MAX ) as $id ) {
		$product = wc_get_product( $id );
		if ( ! $product || ! $product->is_type( 'simple' ) || 'trash' === $product->get_status() || '' !== (string) $product->get_meta( VARCONV_PARENT_META ) || ! current_user_can( 'edit_post', $id ) ) {
			$refused[] = $id;
			continue;
		}
		$titles[ $id ] = $product->get_name( 'edit' );
	}
	return array( 'titles' => $titles, 'refused' => $refused );
}

/**
 * Gives the products one name, each followed by its variant, keeping each old title for Undo. Products not renamable
 * (see varconv_renamable()) and those whose title would not change are left alone.
 *
 * @param int[]             $ids
 * @param array<int,string> $variants Product ID => what follows the name.
 * @return int[] The products renamed.
 */
function varconv_rename( array $ids, string $name, array $variants ): array {
	$done = array();
	foreach ( varconv_renamable( $ids )['titles'] as $id => $old ) {
		$title = varconv_renamed( $name, (string) ( $variants[ $id ] ?? '' ) );
		if ( '' === $title || $title === $old ) {
			continue;
		}
		$product = wc_get_product( $id );
		$product->update_meta_data( VARCONV_OLD_TITLE_META, $old );
		$product->set_name( $title );
		$product->save();
		$done[] = $id;
	}
	delete_transient( VARCONV_SCAN_TRANSIENT );
	return $done;
}

/**
 * Puts back the titles varconv_rename() changed. @param int[] $ids
 *
 * @return int How many products got their old title back.
 */
function varconv_rename_undo( array $ids ): int {
	$count = 0;
	foreach ( array_slice( array_values( array_filter( array_map( 'absint', $ids ) ) ), 0, VARCONV_RENAME_MAX ) as $id ) {
		$product = wc_get_product( $id );
		$old = $product ? (string) $product->get_meta( VARCONV_OLD_TITLE_META ) : '';
		if ( '' === $old || ! current_user_can( 'edit_post', $id ) ) {
			continue;
		}
		$product->set_name( $old );
		$product->delete_meta_data( VARCONV_OLD_TITLE_META );
		$product->save();
		++$count;
	}
	delete_transient( VARCONV_SCAN_TRANSIENT );
	return $count;
}

/**
 * Set one name on the Combine tab's checked groups and products: opens the form for all their products.
 *
 * @param string[] $keys Groups' keys.
 * @param int[]    $ids  Products in no group.
 */
function varconv_handle_rename_picks( array $keys, array $ids ): void {
	$members = array();
	foreach ( array_values( array_unique( $keys ) ) as $key ) {
		$members = array_merge( $members, varconv_group_member_ids( $key ) );
	}
	$all = array_values( array_unique( array_merge( $members, $ids ) ) );
	if ( count( $all ) < 2 ) {
		varconv_fail( __( 'Select at least two products to give one name.', 'variation-converter-for-woocommerce' ), array() );
	}
	varconv_back( array( 'rename' => implode( ',', $all ) ) );
}

add_action( 'admin_post_varconv_rename', 'varconv_handle_rename' );
function varconv_handle_rename(): void {
	if ( ! current_user_can( VARCONV_CAP ) ) {
		wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'variation-converter-for-woocommerce' ), 403 );
	}
	check_admin_referer( 'varconv_rename' );
	$name = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
	$variants = isset( $_POST['variants'] ) && is_array( $_POST['variants'] ) ? map_deep( wp_unslash( $_POST['variants'] ), 'sanitize_text_field' ) : array();
	$ids = array_map( 'absint', array_keys( $variants ) );
	if ( '' === $name ) {
		varconv_fail( __( 'Type the name the products should share.', 'variation-converter-for-woocommerce' ), array( 'rename' => implode( ',', $ids ) ) );
	}
	$done = varconv_rename( $ids, $name, $variants );
	// Back to the Combine tab, searching for the new name, so the products are listed ready to combine.
	varconv_back( array( 's' => $name, 'varconv_done' => 'renamed', 'varconv_ids' => implode( ',', $done ), 'varconv_all' => implode( ',', $ids ) ) );
}

add_action( 'admin_post_varconv_rename_undo', 'varconv_handle_rename_undo' );
function varconv_handle_rename_undo(): void {
	if ( ! current_user_can( VARCONV_CAP ) ) {
		wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'variation-converter-for-woocommerce' ), 403 );
	}
	check_admin_referer( 'varconv_rename_undo' );
	$ids = isset( $_POST['ids'] ) ? array_map( 'absint', explode( ',', sanitize_text_field( wp_unslash( $_POST['ids'] ) ) ) ) : array();
	$count = varconv_rename_undo( $ids );
	varconv_back( array( 'varconv_done' => 'rename_undone', 'varconv_count' => $count ) );
}

// phpcs:disable WordPress.Security.NonceVerification.Recommended -- display only; renaming checks its own nonce.

/** The product IDs to give one name, if any. @return int[] */
function varconv_rename_ids(): array {
	$list = isset( $_GET['rename'] ) ? sanitize_text_field( wp_unslash( $_GET['rename'] ) ) : '';
	return array_slice( array_values( array_filter( array_map( 'absint', explode( ',', $list ) ) ) ), 0, VARCONV_RENAME_MAX );
}

/** The Set one name form for the picked products. */
function varconv_render_rename( array $ids ): void {
	$found = varconv_renamable( $ids );
	$start = varconv_rename_start( $found['titles'] );
	varconv_back_link( varconv_filters( $_GET ) );
	echo '<p class="varconv-lead">' . esc_html__( 'Give the selected products one name, so they are listed together and can be combined into one variable product. After the name, keep what makes each product different, such as its color or size: Combine uses it as the option label.', 'variation-converter-for-woocommerce' ) . '</p>';
	if ( $found['refused'] ) {
		printf(
			'<div class="notice notice-warning inline"><p>%s</p></div>',
			esc_html( sprintf(
				/* translators: %d: number of selected products left out. */
				_n( '%d selected product is left out: only simple products not already combined can be renamed here.', '%d selected products are left out: only simple products not already combined can be renamed here.', count( $found['refused'] ), 'variation-converter-for-woocommerce' ),
				count( $found['refused'] )
			) )
		);
	}
	if ( count( $found['titles'] ) < 2 ) {
		echo '<div class="varconv-empty"><p>' . esc_html__( 'Select at least two simple products to give one name.', 'variation-converter-for-woocommerce' ) . '</p></div>';
		return;
	}
	?>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="varconv-rename">
		<input type="hidden" name="action" value="varconv_rename">
		<?php wp_nonce_field( 'varconv_rename' ); ?>
		<p>
			<label for="varconv-rename-name" class="varconv-find-label"><?php esc_html_e( 'Name for all of them', 'variation-converter-for-woocommerce' ); ?></label>
			<input type="text" id="varconv-rename-name" name="name" class="regular-text" value="<?php echo esc_attr( $start['name'] ); ?>" required placeholder="<?php esc_attr_e( 'For example Classic Tee', 'variation-converter-for-woocommerce' ); ?>">
		</p>
		<table class="wp-list-table widefat fixed striped table-view-list varconv-rename-table">
			<thead>
				<tr>
					<th scope="col" class="manage-column column-primary"><?php esc_html_e( 'Product now', 'variation-converter-for-woocommerce' ); ?></th>
					<th scope="col" class="manage-column column-sku"><?php esc_html_e( 'SKU', 'variation-converter-for-woocommerce' ); ?></th>
					<th scope="col" class="manage-column"><?php esc_html_e( 'After the name (optional)', 'variation-converter-for-woocommerce' ); ?></th>
					<th scope="col" class="manage-column"><?php esc_html_e( 'New name', 'variation-converter-for-woocommerce' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $found['titles'] as $id => $title ) : ?>
					<?php $variant = $start['variants'][ $id ]; ?>
					<tr>
						<td class="column-primary"><a href="<?php echo esc_url( (string) get_edit_post_link( $id ) ); ?>"><?php echo esc_html( $title ); ?></a></td>
						<td class="column-sku" data-colname="<?php esc_attr_e( 'SKU', 'variation-converter-for-woocommerce' ); ?>"><span class="varconv-mono"><?php echo esc_html( (string) get_post_meta( $id, '_sku', true ) ); ?></span></td>
						<td data-colname="<?php esc_attr_e( 'After the name', 'variation-converter-for-woocommerce' ); ?>">
							<?php /* translators: %s: product name. */ ?>
							<input type="text" name="variants[<?php echo (int) $id; ?>]" value="<?php echo esc_attr( $variant ); ?>" class="varconv-rename-variant" placeholder="<?php esc_attr_e( 'For example Black', 'variation-converter-for-woocommerce' ); ?>" aria-label="<?php echo esc_attr( sprintf( __( 'After the name for %s', 'variation-converter-for-woocommerce' ), $title ) ); ?>">
						</td>
						<td data-colname="<?php esc_attr_e( 'New name', 'variation-converter-for-woocommerce' ); ?>"><span class="varconv-rename-preview"><?php echo esc_html( varconv_renamed( $start['name'], $variant ) ); ?></span></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<p class="submit">
			<button class="button button-primary">
				<?php
				/* translators: %d: number of products. */
				echo esc_html( sprintf( __( 'Rename %d products', 'variation-converter-for-woocommerce' ), count( $found['titles'] ) ) );
				?>
			</button>
			<span class="description"><?php esc_html_e( 'URLs stay the same. Undo is offered right after.', 'variation-converter-for-woocommerce' ); ?></span>
		</p>
	</form>
	<?php
}

/** The notice after Set one name: what changed, a link to combine them, and Undo. */
function varconv_renamed_notice(): void {
	$ids = isset( $_GET['varconv_ids'] ) ? array_filter( array_map( 'absint', explode( ',', sanitize_text_field( wp_unslash( $_GET['varconv_ids'] ) ) ) ) ) : array();
	$all = isset( $_GET['varconv_all'] ) ? array_filter( array_map( 'absint', explode( ',', sanitize_text_field( wp_unslash( $_GET['varconv_all'] ) ) ) ) ) : array();
	$name = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
	?>
	<div class="notice notice-success varconv-renamed">
		<p>
			<?php
			echo esc_html( sprintf(
				/* translators: 1: number of products renamed, 2: the name they share. */
				_n( 'Renamed %1$d product to start with "%2$s". The products with that name are listed below.', 'Renamed %1$d products to start with "%2$s". The products with that name are listed below.', count( $ids ), 'variation-converter-for-woocommerce' ),
				count( $ids ),
				$name
			) );
			?>
		</p>
		<p>
			<?php if ( count( $all ) >= 2 ) : ?>
				<a class="button button-primary" href="<?php echo esc_url( varconv_url( array( 'combine' => implode( ',', $all ) ) ) ); ?>"><?php esc_html_e( 'Combine them into one product', 'variation-converter-for-woocommerce' ); ?></a>
			<?php endif; ?>
			<?php if ( $ids ) : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="varconv-rename-undo">
					<input type="hidden" name="action" value="varconv_rename_undo">
					<input type="hidden" name="ids" value="<?php echo esc_attr( implode( ',', $ids ) ); ?>">
					<?php wp_nonce_field( 'varconv_rename_undo' ); ?>
					<button class="button"><?php esc_html_e( 'Undo rename', 'variation-converter-for-woocommerce' ); ?></button>
				</form>
			<?php endif; ?>
		</p>
	</div>
	<?php
}
