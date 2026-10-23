<?php
/**
 * The Split and History tabs. Split: variable products as a list table; a product's card opens inside the table to
 * make one simple product per variation. History: every product combined or split here, newest first, with Undo.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// The GET values read here only choose what is displayed; every change goes through a handler that checks its nonce.
// phpcs:disable WordPress.Security.NonceVerification.Recommended

/**
 * A product thumbnail that shows a larger image on hover or keyboard focus (assets/admin.css). The larger image loads
 * only when shown. Empty box when there is no image.
 */
function varconv_thumb_html( int $image, int $size, string $name ): string {
	if ( ! $image ) {
		return '<span class="varconv-no-image"></span>';
	}
	$large = wp_get_attachment_image( $image, 'medium', false, array( 'loading' => 'lazy', 'alt' => '' ) );
	if ( '' === $large ) {
		return wp_get_attachment_image( $image, array( $size, $size ) );
	}
	return sprintf(
		'<span class="varconv-thumb" tabindex="0" role="img" aria-label="%s">%s<span class="varconv-thumb-large" aria-hidden="true">%s</span></span>',
		/* translators: %s: product name. */
		esc_attr( sprintf( __( 'Image of %s', 'variation-converter' ), $name ) ),
		wp_get_attachment_image( $image, array( $size, $size ), false, array( 'alt' => '' ) ),
		$large
	);
}

/** A stock status as WooCommerce names it, colored as WooCommerce colors it. */
function varconv_stock_html( string $status ): string {
	return sprintf( '<span class="varconv-stock varconv-stock-%s">%s</span>', esc_attr( $status ), esc_html( varconv_stock_text( $status ) ) );
}

/** "4 (Size)": how many variations, and the attributes they vary by. */
function varconv_variations_text( WC_Product_Variable $product ): string {
	$names = array_map( 'wc_attribute_label', array_keys( $product->get_variation_attributes() ) );
	$count = count( $product->get_children() );
	return $names ? sprintf( '%d (%s)', $count, implode( ', ', $names ) ) : (string) $count;
}

/** The variable product's regular price range as shoppers would see it. */
function varconv_variable_price( WC_Product_Variable $product ): string {
	$prices = array_filter( array_map( 'floatval', $product->get_variation_prices()['regular_price'] ?? array() ) );
	return varconv_price_range( array_map( static fn( $p ) => array( 'price' => $p ), $prices ) );
}

/** Pagination links for a list table, in WordPress's tablenav. */
function varconv_tablenav( int $total, int $paged, int $per_page, array $args, string $label ): void {
	$pages = max( 1, (int) ceil( $total / $per_page ) );
	?>
	<div class="tablenav top">
		<div class="tablenav-pages<?php echo $pages > 1 ? '' : ' one-page'; ?>">
			<span class="displaying-num"><?php echo esc_html( $label ); ?></span>
			<?php
			if ( $pages > 1 ) {
				echo wp_kses_post( paginate_links( array(
					'base'    => add_query_arg( 'paged', '%#%', varconv_url( $args ) ),
					'format'  => '',
					'current' => min( $paged, $pages ),
					'total'   => $pages,
				) ) );
			}
			?>
		</div>
		<br class="clear">
	</div>
	<?php
}

/** The Split tab: variable products, with the chosen one's card open inside the table. */
function varconv_render_split(): void {
	$open = isset( $_GET['product'] ) ? absint( wp_unslash( $_GET['product'] ) ) : 0;
	$search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
	$paged = max( 1, isset( $_GET['paged'] ) ? absint( wp_unslash( $_GET['paged'] ) ) : 1 );
	$orderby = isset( $_GET['orderby'] ) && 'variations' === $_GET['orderby'] ? 'variations' : '';
	$order = isset( $_GET['order'] ) && 'asc' === $_GET['order'] ? 'asc' : 'desc';
	$found = varconv_variables( $search, $paged, VARCONV_PER_PAGE, $orderby, $order );
	$args = array_filter( array( 'tab' => 'split', 's' => $search, 'orderby' => $orderby, 'order' => $orderby ? $order : '' ) );
	// Most variations first on the first click, then the other way.
	$sorted = 'variations' === $orderby;
	$variations_url = varconv_url( array_merge( $args, array( 'orderby' => 'variations', 'order' => $sorted && 'desc' === $order ? 'asc' : 'desc', 'paged' => '' ) ) );
	?>
	<p class="varconv-lead"><?php esc_html_e( 'Turn a variable product into one simple product per variation. The variable product is hidden, not deleted, and Undo in History brings it back.', 'variation-converter' ); ?></p>
	<form method="get" class="search-form">
		<input type="hidden" name="post_type" value="product">
		<input type="hidden" name="page" value="<?php echo esc_attr( VARCONV_PAGE ); ?>">
		<input type="hidden" name="tab" value="split">
		<?php if ( $sorted ) : ?>
			<input type="hidden" name="orderby" value="variations">
			<input type="hidden" name="order" value="<?php echo esc_attr( $order ); ?>">
		<?php endif; ?>
		<p class="search-box">
			<label class="screen-reader-text" for="varconv-split-search"><?php esc_html_e( 'Search variable products', 'variation-converter' ); ?></label>
			<input type="search" id="varconv-split-search" name="s" value="<?php echo esc_attr( $search ); ?>">
			<button class="button"><?php esc_html_e( 'Search products', 'variation-converter' ); ?></button>
		</p>
	</form>
	<?php
	$product = $open ? wc_get_product( $open ) : null;
	if ( $product instanceof WC_Product_Variable && ! in_array( $open, $found['ids'], true ) ) {
		// Opened from Products > All Products but not on this page of the list.
		echo '<div class="varconv-review-inner varconv-review-alone">';
		varconv_render_split_card( $product, $args );
		echo '</div>';
	}
	/* translators: %s: number of variable products. */
	varconv_tablenav( $found['total'], $paged, VARCONV_PER_PAGE, $args, sprintf( _n( '%s variable product', '%s variable products', $found['total'], 'variation-converter' ), number_format_i18n( $found['total'] ) ) );
	?>
	<table class="wp-list-table widefat fixed striped table-view-list varconv-split-list">
		<thead><tr>
			<th scope="col" class="column-primary"><?php esc_html_e( 'Variable product', 'variation-converter' ); ?></th>
			<th scope="col" class="column-sku"><?php esc_html_e( 'SKU', 'variation-converter' ); ?></th>
			<th scope="col" class="manage-column column-variations <?php echo $sorted ? 'sorted ' . esc_attr( $order ) : 'sortable asc'; ?>"<?php echo $sorted ? ' aria-sort="' . ( 'asc' === $order ? 'ascending' : 'descending' ) . '"' : ''; ?>>
				<a href="<?php echo esc_url( $variations_url ); ?>"><span><?php esc_html_e( 'Variations', 'variation-converter' ); ?></span><span class="sorting-indicators"><span class="sorting-indicator asc" aria-hidden="true"></span><span class="sorting-indicator desc" aria-hidden="true"></span></span></a>
			</th>
			<th scope="col" class="column-price"><?php esc_html_e( 'Price', 'variation-converter' ); ?></th>
			<th scope="col" class="column-note"><?php esc_html_e( 'Note', 'variation-converter' ); ?></th>
		</tr></thead>
		<tbody>
		<?php if ( ! $found['ids'] ) : ?>
			<tr class="no-items"><td colspan="5">
				<?php
				if ( '' !== $search ) {
					/* translators: %s: search text. */
					echo esc_html( sprintf( __( 'No variable products match "%s".', 'variation-converter' ), $search ) );
				} else {
					esc_html_e( 'There are no published variable products to split.', 'variation-converter' );
				}
				?>
			</td></tr>
		<?php endif; ?>
		<?php foreach ( $found['ids'] as $pid ) : ?>
			<?php
			$p = wc_get_product( $pid );
			if ( ! $p instanceof WC_Product_Variable ) {
				continue;
			}
			$is_open = $pid === $open;
			$combined = '' !== (string) $p->get_meta( VARCONV_META );
			$title = get_post_field( 'post_title', $pid, 'raw' );
			?>
			<tr class="<?php echo $is_open ? 'varconv-open' : ''; ?>">
				<td class="column-primary">
					<strong>
						<?php if ( $combined ) : ?>
							<a class="row-title" href="<?php echo esc_url( (string) get_edit_post_link( $pid ) ); ?>"><?php echo esc_html( $title ); ?></a>
						<?php else : ?>
							<a class="row-title" href="<?php echo esc_url( $is_open ? varconv_url( $args ) : varconv_url( array_merge( $args, array( 'product' => $pid, 'paged' => $paged > 1 ? $paged : '' ) ) ) . '#varconv-split-' . $pid ); ?>" aria-expanded="<?php echo $is_open ? 'true' : 'false'; ?>"><span class="varconv-toggle" aria-hidden="true"><?php echo $is_open ? '&#9662;' : '&#9656;'; ?></span> <?php echo esc_html( $title ); ?></a>
						<?php endif; ?>
					</strong>
				</td>
				<td class="column-sku"><span class="varconv-mono"><?php echo esc_html( $p->get_sku( 'edit' ) ); ?></span></td>
				<td class="column-variations"><?php echo esc_html( varconv_variations_text( $p ) ); ?></td>
				<td class="column-price"><?php echo esc_html( varconv_variable_price( $p ) ); ?></td>
				<td class="column-note varconv-muted">
					<?php if ( $combined ) : ?>
						<?php esc_html_e( 'Combined here.', 'variation-converter' ); ?>
						<a href="<?php echo esc_url( varconv_url( array( 'tab' => 'history' ) ) ); ?>"><?php esc_html_e( 'Undo in History', 'variation-converter' ); ?></a>
					<?php endif; ?>
				</td>
			</tr>
			<?php if ( $is_open && ! $combined ) : ?>
				<tr class="varconv-review"><td colspan="5"><div class="varconv-review-inner" id="varconv-split-<?php echo (int) $pid; ?>">
					<?php varconv_render_split_card( $p, $args ); ?>
				</div></td></tr>
			<?php endif; ?>
		<?php endforeach; ?>
		</tbody>
	</table>
	<?php
}

/** The card for one variable product: its variations to make into simple products. */
function varconv_render_split_card( WC_Product_Variable $product, array $args ): void {
	$id = $product->get_id();
	$cancel = varconv_url( $args );
	if ( '' !== (string) $product->get_meta( VARCONV_META ) ) {
		echo '<p>' . esc_html__( 'This was combined by Variation Converter. Undo restores the original products.', 'variation-converter' ) . '</p>';
		varconv_undo_form( 'varconv_undo', $id );
		return;
	}
	if ( '' !== (string) $product->get_meta( VARCONV_SPLIT_META ) ) {
		echo '<p>' . esc_html__( 'This product is already split. Undo deletes the simple products made from it and shows it again.', 'variation-converter' ) . '</p>';
		varconv_undo_form( 'varconv_split_undo', $id );
		return;
	}
	if ( 'publish' !== $product->get_status() ) {
		echo '<p>' . esc_html__( 'Only published products can be split.', 'variation-converter' ) . '</p>';
		return;
	}
	$rows = varconv_split_rows( $product );
	if ( ! $rows ) {
		echo '<p>' . esc_html__( 'This product has no variations to split.', 'variation-converter' ) . '</p>';
		return;
	}
	$count = count( array_filter( $rows, static fn( $r ) => '' === $r['problem'] ) );
	?>
	<?php if ( isset( $_GET['varconv_failed'] ) ) : ?>
		<?php // The error itself is the notice above the tabs. ?>
		<div class="notice notice-error inline"><p><?php esc_html_e( 'Not split. See the message above.', 'variation-converter' ); ?></p></div>
	<?php endif; ?>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<input type="hidden" name="action" value="varconv_split">
		<input type="hidden" name="tab" value="split">
		<input type="hidden" name="product" value="<?php echo (int) $id; ?>">
		<?php wp_nonce_field( 'varconv_split_' . $id ); ?>
		<div class="varconv-table-wrap">
			<table class="widefat varconv-members">
				<thead><tr>
					<td class="check-column"><span class="screen-reader-text"><?php esc_html_e( 'Include', 'variation-converter' ); ?></span></td>
					<th class="thumb"><span class="screen-reader-text"><?php esc_html_e( 'Image', 'variation-converter' ); ?></span></th>
					<th><?php esc_html_e( 'New product name', 'variation-converter' ); ?></th>
					<th class="col-sku"><?php esc_html_e( 'SKU', 'variation-converter' ); ?></th>
					<th class="col-price"><?php esc_html_e( 'Price', 'variation-converter' ); ?></th>
					<th class="col-stock"><?php esc_html_e( 'Stock', 'variation-converter' ); ?></th>
				</tr></thead>
				<tbody>
				<?php foreach ( $rows as $r ) : ?>
					<tr>
						<th scope="row" class="check-column">
							<?php /* translators: %s: new product name. */ ?>
							<input type="checkbox" name="rows[<?php echo (int) $r['id']; ?>][use]" value="1" <?php checked( '' === $r['problem'] ); ?> <?php disabled( '' !== $r['problem'] ); ?> aria-label="<?php echo esc_attr( sprintf( __( 'Include %s', 'variation-converter' ), $r['name'] ) ); ?>">
						</th>
						<td class="thumb"><?php echo varconv_thumb_html( (int) $r['image'], 40, (string) $r['name'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped where it is built. ?></td>
						<td>
							<?php /* translators: %d: variation ID. */ ?>
							<input type="text" name="rows[<?php echo (int) $r['id']; ?>][name]" value="<?php echo esc_attr( $r['name'] ); ?>" aria-label="<?php echo esc_attr( sprintf( __( 'New product name for variation %d', 'variation-converter' ), $r['id'] ) ); ?>">
							<?php if ( $r['problem'] ) : ?>
								<p class="varconv-notes"><?php echo esc_html( $r['problem'] ); ?></p>
							<?php endif; ?>
						</td>
						<td class="col-sku"><span class="varconv-mono"><?php echo esc_html( $r['sku'] ?: '#' . $r['id'] ); ?></span></td>
						<td class="col-price"><?php echo '' !== (string) $r['price'] ? esc_html( varconv_price_text( (float) $r['price'] ) ) : ''; ?></td>
						<td class="col-stock"><?php echo wp_kses_post( varconv_stock_html( (string) $r['stock'] ) ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<div class="varconv-review-foot">
			<span class="varconv-muted"><?php esc_html_e( 'Each new product copies the description, categories and tags. Unchecked variations stay only in the hidden variable product.', 'variation-converter' ); ?></span>
			<a class="button" href="<?php echo esc_url( $cancel ); ?>"><?php esc_html_e( 'Cancel', 'variation-converter' ); ?></a>
			<button class="button button-primary">
				<?php
				/* translators: %d: number of simple products to make. */
				echo esc_html( sprintf( _n( 'Split into %d simple product', 'Split into %d simple products', $count, 'variation-converter' ), $count ) );
				?>
			</button>
		</div>
	</form>
	<?php
}

/** The History tab: one table of everything combined or split here, newest first, with Undo. */
function varconv_render_history(): void {
	$view = isset( $_GET['view'] ) ? sanitize_key( wp_unslash( $_GET['view'] ) ) : '';
	$view = in_array( $view, array( 'combined', 'split' ), true ) ? $view : '';
	$search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
	$paged = max( 1, isset( $_GET['paged'] ) ? absint( wp_unslash( $_GET['paged'] ) ) : 1 );
	$all = varconv_history( $search );
	$counts = array(
		''         => count( $all ),
		'combined' => count( array_filter( $all, static fn( $r ) => 'combined' === $r['kind'] ) ),
		'split'    => count( array_filter( $all, static fn( $r ) => 'split' === $r['kind'] ) ),
	);
	$rows = '' === $view ? $all : array_values( array_filter( $all, static fn( $r ) => $view === $r['kind'] ) );
	$shown = array_slice( $rows, ( $paged - 1 ) * VARCONV_PER_PAGE, VARCONV_PER_PAGE );
	$labels = array(
		''         => __( 'All', 'variation-converter' ),
		'combined' => __( 'Combined', 'variation-converter' ),
		'split'    => __( 'Split', 'variation-converter' ),
	);
	$args = array_filter( array( 'tab' => 'history', 'view' => $view, 's' => $search ) );
	?>
	<p class="varconv-lead"><?php esc_html_e( 'Every conversion made here, newest first. Undo puts the products back as they were.', 'variation-converter' ); ?></p>
	<ul class="subsubsub">
		<?php foreach ( $labels as $key => $label ) : ?>
			<li><a href="<?php echo esc_url( varconv_url( array_filter( array( 'tab' => 'history', 'view' => $key, 's' => $search ) ) ) ); ?>"<?php echo $key === $view ? ' class="current" aria-current="page"' : ''; ?>><?php echo esc_html( $label ); ?> <span class="count">(<?php echo (int) $counts[ $key ]; ?>)</span></a><?php echo 'split' === $key ? '' : ' |'; ?></li>
		<?php endforeach; ?>
	</ul>
	<form method="get" class="search-form">
		<input type="hidden" name="post_type" value="product">
		<input type="hidden" name="page" value="<?php echo esc_attr( VARCONV_PAGE ); ?>">
		<input type="hidden" name="tab" value="history">
		<?php if ( '' !== $view ) : ?>
			<input type="hidden" name="view" value="<?php echo esc_attr( $view ); ?>">
		<?php endif; ?>
		<p class="search-box">
			<label class="screen-reader-text" for="varconv-history-search"><?php esc_html_e( 'Search history', 'variation-converter' ); ?></label>
			<input type="search" id="varconv-history-search" name="s" value="<?php echo esc_attr( $search ); ?>">
			<button class="button"><?php esc_html_e( 'Search history', 'variation-converter' ); ?></button>
		</p>
	</form>
	<?php
	/* translators: %s: number of conversions. */
	varconv_tablenav( count( $rows ), $paged, VARCONV_PER_PAGE, $args, sprintf( _n( '%s item', '%s items', count( $rows ), 'variation-converter' ), number_format_i18n( count( $rows ) ) ) );
	?>
	<table class="wp-list-table widefat fixed striped table-view-list varconv-history">
		<thead><tr>
			<th scope="col" class="column-primary"><?php esc_html_e( 'Product', 'variation-converter' ); ?></th>
			<th scope="col" class="column-change"><?php esc_html_e( 'Change', 'variation-converter' ); ?></th>
			<th scope="col"><?php esc_html_e( 'Products', 'variation-converter' ); ?></th>
			<th scope="col" class="column-date"><?php esc_html_e( 'Date', 'variation-converter' ); ?></th>
			<th scope="col" class="column-undo"><span class="screen-reader-text"><?php esc_html_e( 'Actions', 'variation-converter' ); ?></span></th>
		</tr></thead>
		<tbody>
		<?php if ( ! $shown ) : ?>
			<tr class="no-items"><td colspan="5"><?php echo '' !== $search ? esc_html__( 'No conversions match your search.', 'variation-converter' ) : esc_html__( 'Nothing combined or split yet.', 'variation-converter' ); ?></td></tr>
		<?php endif; ?>
		<?php foreach ( $shown as $r ) : ?>
			<?php
			$id = $r['id'];
			$combined = 'combined' === $r['kind'];
			$ids = json_decode( (string) get_post_meta( $id, $combined ? VARCONV_META : VARCONV_SPLIT_META, true ), true ) ?: array();
			?>
			<tr>
				<td class="column-primary">
					<strong><a class="row-title" href="<?php echo esc_url( (string) get_edit_post_link( $id ) ); ?>"><?php echo esc_html( get_post_field( 'post_title', $id, 'raw' ) ); ?></a></strong>
					<div class="row-actions">
						<span class="edit"><a href="<?php echo esc_url( (string) get_edit_post_link( $id ) ); ?>"><?php esc_html_e( 'Edit', 'variation-converter' ); ?></a> | </span>
						<span class="view"><a href="<?php echo esc_url( (string) get_permalink( $id ) ); ?>"><?php esc_html_e( 'View', 'variation-converter' ); ?></a></span>
					</div>
				</td>
				<td class="column-change">
					<span class="varconv-badge <?php echo $combined ? 'varconv-badge-combined' : 'varconv-badge-split'; ?>"><?php echo $combined ? esc_html__( 'Combined', 'variation-converter' ) : esc_html__( 'Split', 'variation-converter' ); ?></span>
				</td>
				<td>
					<?php
					echo esc_html( $combined
						/* translators: %d: number of simple products. */
						? sprintf( _n( 'Made from %d simple product:', 'Made from %d simple products:', count( $ids ), 'variation-converter' ), count( $ids ) )
						/* translators: %d: number of simple products. */
						: sprintf( _n( 'Made into %d simple product (this one is hidden):', 'Made into %d simple products (this one is hidden):', count( $ids ), 'variation-converter' ), count( $ids ) ) );
					echo ' ' . wp_kses_post( varconv_product_links( $ids ) );
					?>
				</td>
				<td class="column-date varconv-muted">
					<?php
					/* translators: 1: date, 2: time. */
					echo esc_html( sprintf( __( '%1$s at %2$s', 'variation-converter' ), wp_date( (string) get_option( 'date_format' ), $r['time'] ), wp_date( (string) get_option( 'time_format' ), $r['time'] ) ) );
					?>
				</td>
				<td class="column-undo"><?php varconv_undo_form( $combined ? 'varconv_undo' : 'varconv_split_undo', $id ); ?></td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>
	<?php
}
