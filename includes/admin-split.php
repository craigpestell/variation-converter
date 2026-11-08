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
		esc_attr( sprintf( __( 'Image of %s', 'variation-converter-for-woocommerce' ), $name ) ),
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

/** The Split tab: variable products, with the chosen one's card open inside the table. */
function varconv_render_split(): void {
	$make_simple = isset( $_GET['make_simple'] ) ? absint( wp_unslash( $_GET['make_simple'] ) ) : 0;
	if ( $make_simple ) {
		varconv_render_take_one( $make_simple );
		return;
	}
	$open = isset( $_GET['product'] ) ? absint( wp_unslash( $_GET['product'] ) ) : 0;
	$search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
	$view = isset( $_GET['view'] ) ? sanitize_key( wp_unslash( $_GET['view'] ) ) : '';
	$view = in_array( $view, array( 'ready', 'combined' ), true ) ? $view : '';
	$paged = max( 1, isset( $_GET['paged'] ) ? absint( wp_unslash( $_GET['paged'] ) ) : 1 );
	$orderby = isset( $_GET['orderby'] ) && 'variations' === $_GET['orderby'] ? 'variations' : '';
	$order = isset( $_GET['order'] ) && 'asc' === $_GET['order'] ? 'asc' : 'desc';
	$found = varconv_variables( $search, $paged, VARCONV_PER_PAGE, $orderby, $order, $view );
	$labels = array(
		''         => __( 'All', 'variation-converter-for-woocommerce' ),
		'ready'    => __( 'Can be split', 'variation-converter-for-woocommerce' ),
		'combined' => __( 'Combined here', 'variation-converter-for-woocommerce' ),
	);
	$counts = array();
	foreach ( array_keys( $labels ) as $key ) {
		$counts[ $key ] = $key === $view ? $found['total'] : varconv_variables( $search, 1, 1, '', '', $key )['total'];
	}
	$args = array_filter( array( 'tab' => 'split', 'view' => $view, 's' => $search, 'orderby' => $orderby, 'order' => $orderby ? $order : '' ) );
	// Most variations first on the first click, then the other way.
	$sorted = 'variations' === $orderby;
	$variations_url = varconv_url( array_merge( $args, array( 'orderby' => 'variations', 'order' => $sorted && 'desc' === $order ? 'asc' : 'desc', 'paged' => '' ) ) );
	/* translators: %s: number of variable products. */
	$label = sprintf( _n( '%s variable product', '%s variable products', $found['total'], 'variation-converter-for-woocommerce' ), number_format_i18n( $found['total'] ) );
	?>
	<p class="varconv-lead"><?php esc_html_e( 'Turn a variable product into one simple product per variation, or make just the variations you pick into simple products and keep the rest variable. Nothing is deleted, and Undo in History puts it back.', 'variation-converter-for-woocommerce' ); ?></p>
	<?php varconv_views_list( $labels, $counts, $view, array_filter( array( 'tab' => 'split', 's' => $search ) ) ); ?>
	<form method="get" class="search-form">
		<input type="hidden" name="post_type" value="product">
		<input type="hidden" name="page" value="<?php echo esc_attr( VARCONV_PAGE ); ?>">
		<input type="hidden" name="tab" value="split">
		<?php foreach ( array_diff_key( $args, array( 'tab' => 1, 's' => 1 ) ) as $name => $value ) : ?>
			<input type="hidden" name="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( (string) $value ); ?>">
		<?php endforeach; ?>
		<p class="search-box">
			<label class="screen-reader-text" for="varconv-split-search"><?php esc_html_e( 'Search variable products', 'variation-converter-for-woocommerce' ); ?></label>
			<input type="search" id="varconv-split-search" name="s" value="<?php echo esc_attr( $search ); ?>">
			<button class="button"><?php esc_html_e( 'Search products', 'variation-converter-for-woocommerce' ); ?></button>
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
	varconv_tablenav( 'top', $found['total'], $paged, VARCONV_PER_PAGE, $args, $label );
	$headers = static function () use ( $sorted, $order, $variations_url ): void {
		?>
		<tr>
			<th scope="col" class="manage-column column-primary"><?php esc_html_e( 'Variable product', 'variation-converter-for-woocommerce' ); ?></th>
			<th scope="col" class="manage-column column-sku"><?php esc_html_e( 'SKU', 'variation-converter-for-woocommerce' ); ?></th>
			<th scope="col" class="manage-column column-variations <?php echo $sorted ? 'sorted ' . esc_attr( $order ) : 'sortable asc'; ?>"<?php echo $sorted ? ' aria-sort="' . ( 'asc' === $order ? 'ascending' : 'descending' ) . '"' : ''; ?>>
				<a href="<?php echo esc_url( $variations_url ); ?>"><span><?php esc_html_e( 'Variations', 'variation-converter-for-woocommerce' ); ?></span><span class="sorting-indicators"><span class="sorting-indicator asc" aria-hidden="true"></span><span class="sorting-indicator desc" aria-hidden="true"></span></span></a>
			</th>
			<th scope="col" class="manage-column column-price"><?php esc_html_e( 'Price', 'variation-converter-for-woocommerce' ); ?></th>
			<th scope="col" class="manage-column column-note"><?php esc_html_e( 'Note', 'variation-converter-for-woocommerce' ); ?></th>
		</tr>
		<?php
	};
	?>
	<table class="wp-list-table widefat fixed striped table-view-list varconv-split-list">
		<thead><?php $headers(); ?></thead>
		<tbody>
		<?php if ( ! $found['ids'] ) : ?>
			<tr class="no-items"><td class="colspanchange" colspan="5">
				<?php
				if ( '' !== $search ) {
					/* translators: %s: search text. */
					echo esc_html( sprintf( __( 'No variable products match "%s".', 'variation-converter-for-woocommerce' ), $search ) );
				} elseif ( '' !== $view ) {
					esc_html_e( 'No variable products in this view.', 'variation-converter-for-woocommerce' );
				} else {
					esc_html_e( 'There are no variable products to split.', 'variation-converter-for-woocommerce' );
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
			$card_url = $is_open ? varconv_url( $args ) : varconv_url( array_merge( $args, array( 'product' => $pid, 'paged' => $paged > 1 ? $paged : '' ) ) ) . '#varconv-split-' . $pid;
			?>
			<tr class="<?php echo $is_open ? 'varconv-open' : ''; ?>">
				<td class="column-primary has-row-actions">
					<strong>
						<a class="row-title" href="<?php echo esc_url( $card_url ); ?>" aria-expanded="<?php echo $is_open ? 'true' : 'false'; ?>"><span class="varconv-toggle" aria-hidden="true"><?php echo $is_open ? '&#9662;' : '&#9656;'; ?></span> <?php echo esc_html( $title ); ?></a><?php echo varconv_status_label( $pid ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped where it is built. ?>
					</strong>
					<div class="row-actions">
						<?php if ( ! $combined ) : ?>
							<span class="split"><a href="<?php echo esc_url( $card_url ); ?>"><?php esc_html_e( 'Split', 'variation-converter-for-woocommerce' ); ?></a> | </span>
						<?php endif; ?>
						<span class="make-simple"><a href="<?php echo esc_url( $card_url ); ?>"><?php esc_html_e( 'Make variations simple', 'variation-converter-for-woocommerce' ); ?></a> | </span>
						<span class="edit"><a href="<?php echo esc_url( (string) get_edit_post_link( $pid ) ); ?>"><?php esc_html_e( 'Edit', 'variation-converter-for-woocommerce' ); ?></a> | </span>
						<span class="view"><a href="<?php echo esc_url( (string) get_permalink( $pid ) ); ?>"><?php esc_html_e( 'View', 'variation-converter-for-woocommerce' ); ?></a></span>
					</div>
					<button type="button" class="toggle-row"><span class="screen-reader-text"><?php esc_html_e( 'Show more details', 'variation-converter-for-woocommerce' ); ?></span></button>
				</td>
				<td class="column-sku" data-colname="<?php esc_attr_e( 'SKU', 'variation-converter-for-woocommerce' ); ?>"><span class="varconv-mono"><?php echo esc_html( $p->get_sku( 'edit' ) ); ?></span></td>
				<td class="column-variations" data-colname="<?php esc_attr_e( 'Variations', 'variation-converter-for-woocommerce' ); ?>"><?php echo esc_html( varconv_variations_text( $p ) ); ?></td>
				<td class="column-price" data-colname="<?php esc_attr_e( 'Price', 'variation-converter-for-woocommerce' ); ?>"><?php echo esc_html( varconv_variable_price( $p ) ); ?></td>
				<td class="column-note varconv-muted" data-colname="<?php esc_attr_e( 'Note', 'variation-converter-for-woocommerce' ); ?>">
					<?php if ( $combined ) : ?>
						<?php esc_html_e( 'Combined here.', 'variation-converter-for-woocommerce' ); ?>
						<a href="<?php echo esc_url( varconv_url( array( 'tab' => 'history' ) ) ); ?>"><?php esc_html_e( 'Undo in History', 'variation-converter-for-woocommerce' ); ?></a>
					<?php endif; ?>
				</td>
			</tr>
			<?php if ( $is_open ) : ?>
				<tr class="varconv-review"><td colspan="5"><div class="varconv-review-inner" id="varconv-split-<?php echo (int) $pid; ?>">
					<?php varconv_render_split_card( $p, $args ); ?>
				</div></td></tr>
			<?php endif; ?>
		<?php endforeach; ?>
		</tbody>
		<tfoot><?php $headers(); ?></tfoot>
	</table>
	<?php
	varconv_tablenav( 'bottom', $found['total'], $paged, VARCONV_PER_PAGE, $args, $label );
}

/**
 * The card for one variable product: its variations, to split into simple products (the variable product is hidden)
 * or to make just the checked ones simple while the rest stays variable. A product Combine made can only have
 * variations made simple, which shows their original simple products again; Undo in History undoes the whole combine.
 */
function varconv_render_split_card( WC_Product_Variable $product, array $args ): void {
	$id = $product->get_id();
	$cancel = varconv_url( $args );
	$combined = '' !== (string) $product->get_meta( VARCONV_META );
	if ( '' !== (string) $product->get_meta( VARCONV_SPLIT_META ) ) {
		echo '<p>' . esc_html__( 'This product is already split. Undo deletes the simple products made from it and shows it again.', 'variation-converter-for-woocommerce' ) . '</p>';
		varconv_undo_form( 'varconv_split_undo', $id );
		return;
	}
	if ( ! varconv_status_ok( $product->get_status() ) ) {
		echo '<p>' . esc_html__( 'Only published, private, pending or draft products can be split.', 'variation-converter-for-woocommerce' ) . '</p>';
		return;
	}
	$rows = varconv_split_rows( $product );
	if ( ! $rows ) {
		echo '<p>' . esc_html__( 'This product has no variations to split.', 'variation-converter-for-woocommerce' ) . '</p>';
		return;
	}
	$count = count( array_filter( $rows, static fn( $r ) => '' === $r['problem'] ) );
	?>
	<?php if ( isset( $_GET['varconv_failed'] ) ) : ?>
		<?php // The error itself is the notice above the tabs. ?>
		<div class="notice notice-error inline"><p><?php esc_html_e( 'Nothing changed. See the message above.', 'variation-converter-for-woocommerce' ); ?></p></div>
	<?php endif; ?>
	<?php if ( $combined ) : ?>
		<p><?php esc_html_e( 'Combined by Variation Converter. Make a variation simple to show its original simple product again, with the variation\'s current price and stock, while the rest stays variable. Undo in History restores every original product.', 'variation-converter-for-woocommerce' ); ?></p>
	<?php endif; ?>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<input type="hidden" name="action" value="varconv_split">
		<input type="hidden" name="tab" value="split">
		<input type="hidden" name="product" value="<?php echo (int) $id; ?>">
		<?php wp_nonce_field( 'varconv_split_' . $id ); ?>
		<div class="varconv-table-wrap">
			<table class="widefat varconv-members">
				<thead><tr>
					<td class="manage-column column-cb check-column"><input type="checkbox" aria-label="<?php esc_attr_e( 'Select all', 'variation-converter-for-woocommerce' ); ?>"<?php checked( ! $combined ); ?>></td>
					<th class="thumb"><span class="screen-reader-text"><?php esc_html_e( 'Image', 'variation-converter-for-woocommerce' ); ?></span></th>
					<th><?php echo $combined ? esc_html__( 'Original product', 'variation-converter-for-woocommerce' ) : esc_html__( 'New product name', 'variation-converter-for-woocommerce' ); ?></th>
					<th class="col-sku"><?php esc_html_e( 'SKU', 'variation-converter-for-woocommerce' ); ?></th>
					<th class="col-price"><?php esc_html_e( 'Price', 'variation-converter-for-woocommerce' ); ?></th>
					<th class="col-stock"><?php esc_html_e( 'Stock', 'variation-converter-for-woocommerce' ); ?></th>
				</tr></thead>
				<tbody>
				<?php foreach ( $rows as $r ) : ?>
					<?php $ok = '' === $r['problem']; ?>
					<tr>
						<th scope="row" class="check-column">
							<?php /* translators: %s: new product name. */ ?>
							<input type="checkbox" name="rows[<?php echo (int) $r['id']; ?>][use]" value="1" <?php checked( $ok && ! $combined ); ?> <?php disabled( ! $ok ); ?> aria-label="<?php echo esc_attr( sprintf( __( 'Include %s', 'variation-converter-for-woocommerce' ), $r['name'] ) ); ?>">
						</th>
						<td class="thumb"><?php echo varconv_thumb_html( (int) $r['image'], 40, (string) $r['name'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped where it is built. ?></td>
						<td>
							<?php if ( $r['member'] ) : ?>
								<a href="<?php echo esc_url( (string) get_edit_post_link( $r['member'] ) ); ?>"><?php echo esc_html( $r['name'] ); ?></a>
							<?php elseif ( $r['taken'] ) : ?>
								<?php echo esc_html( $r['name'] ); ?>
							<?php else : ?>
								<?php /* translators: %d: variation ID. */ ?>
								<input type="text" name="rows[<?php echo (int) $r['id']; ?>][name]" value="<?php echo esc_attr( $r['name'] ); ?>" aria-label="<?php echo esc_attr( sprintf( __( 'New product name for variation %d', 'variation-converter-for-woocommerce' ), $r['id'] ) ); ?>">
							<?php endif; ?>
							<?php if ( $r['taken'] ) : ?>
								<p class="varconv-notes">
									<?php esc_html_e( 'Made simple:', 'variation-converter-for-woocommerce' ); ?>
									<a href="<?php echo esc_url( (string) get_edit_post_link( $r['taken'] ) ); ?>"><?php echo esc_html( get_post_field( 'post_title', $r['taken'], 'raw' ) ); ?></a>.
									<a href="<?php echo esc_url( varconv_url( array( 'tab' => 'history', 'view' => 'taken' ) ) ); ?>"><?php esc_html_e( 'Undo in History', 'variation-converter-for-woocommerce' ); ?></a>
								</p>
							<?php elseif ( ! $ok ) : ?>
								<p class="varconv-notes"><?php echo esc_html( $r['problem'] ); ?></p>
							<?php else : ?>
								<div class="varconv-row-actions">
									<?php if ( ! $r['enabled'] ) : ?>
										<span class="varconv-muted"><?php esc_html_e( 'Disabled.', 'variation-converter-for-woocommerce' ); ?></span>
									<?php endif; ?>
									<?php /* translators: %s: product name. */ ?>
									<a href="<?php echo esc_url( varconv_take_one_url( (int) $r['id'], 'split' ) ); ?>" aria-label="<?php echo esc_attr( sprintf( __( 'Make %s a simple product', 'variation-converter-for-woocommerce' ), $r['name'] ) ); ?>"><?php esc_html_e( 'Make simple product', 'variation-converter-for-woocommerce' ); ?></a>
								</div>
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
			<span class="varconv-muted">
				<?php
				echo $combined
					? esc_html__( 'Checked variations are disabled and their original products shown again. The rest stays variable; with every variation checked, the variable product moves to the Trash.', 'variation-converter-for-woocommerce' )
					: esc_html__( 'Make simple: the checked variations become simple products and are disabled; the rest stays variable, or moves to the Trash when none is left for sale. Split: every checked variation becomes a simple product and the variable product is hidden. New products copy the description, categories and tags.', 'variation-converter-for-woocommerce' );
				?>
			</span>
			<a class="button" href="<?php echo esc_url( $cancel ); ?>"><?php esc_html_e( 'Cancel', 'variation-converter-for-woocommerce' ); ?></a>
			<button class="button<?php echo $combined ? ' button-primary' : ''; ?>" name="do" value="take"><?php esc_html_e( 'Make checked simple, keep the rest', 'variation-converter-for-woocommerce' ); ?></button>
			<?php if ( ! $combined ) : ?>
				<button class="button button-primary" name="do" value="split">
					<?php
					/* translators: %d: number of simple products to make. */
					echo esc_html( sprintf( _n( 'Split into %d simple product', 'Split into %d simple products', $count, 'variation-converter-for-woocommerce' ), $count ) );
					?>
				</button>
			<?php endif; ?>
		</div>
	</form>
	<?php
}

/** The History tab: one table of everything combined or split here, newest first, with Undo. */
function varconv_render_history(): void {
	$view = isset( $_GET['view'] ) ? sanitize_key( wp_unslash( $_GET['view'] ) ) : '';
	$view = in_array( $view, array( 'combined', 'split', 'taken' ), true ) ? $view : '';
	$search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
	$paged = max( 1, isset( $_GET['paged'] ) ? absint( wp_unslash( $_GET['paged'] ) ) : 1 );
	$all = varconv_history( $search );
	$counts = array(
		''         => count( $all ),
		'combined' => count( array_filter( $all, static fn( $r ) => 'combined' === $r['kind'] ) ),
		'split'    => count( array_filter( $all, static fn( $r ) => 'split' === $r['kind'] ) ),
		'taken'    => count( array_filter( $all, static fn( $r ) => 'taken' === $r['kind'] ) ),
	);
	$rows = '' === $view ? $all : array_values( array_filter( $all, static fn( $r ) => $view === $r['kind'] ) );
	$shown = array_slice( $rows, ( $paged - 1 ) * VARCONV_PER_PAGE, VARCONV_PER_PAGE );
	$labels = array(
		''         => __( 'All', 'variation-converter-for-woocommerce' ),
		'combined' => __( 'Combined', 'variation-converter-for-woocommerce' ),
		'split'    => __( 'Split', 'variation-converter-for-woocommerce' ),
		'taken'    => __( 'Made simple', 'variation-converter-for-woocommerce' ),
	);
	$args = array_filter( array( 'tab' => 'history', 'view' => $view, 's' => $search ) );
	/* translators: %s: number of conversions. */
	$label = sprintf( _n( '%s item', '%s items', count( $rows ), 'variation-converter-for-woocommerce' ), number_format_i18n( count( $rows ) ) );
	$bulk = static function ( string $which ): void {
		varconv_bulk_actions( array( 'undo' => __( 'Undo', 'variation-converter-for-woocommerce' ) ), $which, 'varconv-undo-checked' );
	};
	$headers = static function ( string $which ): void {
		?>
		<tr>
			<td class="manage-column column-cb check-column"><input type="checkbox" id="varconv-history-all<?php echo 'bottom' === $which ? '-2' : ''; ?>" aria-label="<?php esc_attr_e( 'Select all on this page', 'variation-converter-for-woocommerce' ); ?>"></td>
			<th scope="col" class="manage-column column-primary"><?php esc_html_e( 'Product', 'variation-converter-for-woocommerce' ); ?></th>
			<th scope="col" class="manage-column column-change"><?php esc_html_e( 'Change', 'variation-converter-for-woocommerce' ); ?></th>
			<th scope="col" class="manage-column column-products"><?php esc_html_e( 'Products', 'variation-converter-for-woocommerce' ); ?></th>
			<th scope="col" class="manage-column column-date"><?php esc_html_e( 'Date', 'variation-converter-for-woocommerce' ); ?></th>
		</tr>
		<?php
	};
	?>
	<p class="varconv-lead"><?php esc_html_e( 'Every conversion made here, newest first. Undo puts the products back as they were.', 'variation-converter-for-woocommerce' ); ?></p>
	<?php varconv_views_list( $labels, $counts, $view, array_filter( array( 'tab' => 'history', 's' => $search ) ) ); ?>
	<form method="get" class="search-form">
		<input type="hidden" name="post_type" value="product">
		<input type="hidden" name="page" value="<?php echo esc_attr( VARCONV_PAGE ); ?>">
		<input type="hidden" name="tab" value="history">
		<?php if ( '' !== $view ) : ?>
			<input type="hidden" name="view" value="<?php echo esc_attr( $view ); ?>">
		<?php endif; ?>
		<p class="search-box">
			<label class="screen-reader-text" for="varconv-history-search"><?php esc_html_e( 'Search history', 'variation-converter-for-woocommerce' ); ?></label>
			<input type="search" id="varconv-history-search" name="s" value="<?php echo esc_attr( $search ); ?>">
			<button class="button"><?php esc_html_e( 'Search history', 'variation-converter-for-woocommerce' ); ?></button>
		</p>
	</form>
	<?php // The rows' checkboxes and the Bulk actions menus belong to this form by its id, as each row also holds its own Undo form. ?>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="varconv-undo-checked" class="varconv-undo-checked" data-confirm="<?php esc_attr_e( 'Undo every checked conversion? Variable products made by combining are deleted and their simple products shown again; simple products made by a split are deleted and the variable product shown again; variations made simple are enabled again.', 'variation-converter-for-woocommerce' ); ?>">
		<input type="hidden" name="action" value="varconv_undo_checked">
		<input type="hidden" name="tab" value="history">
		<?php if ( '' !== $search ) : ?>
			<input type="hidden" name="s" value="<?php echo esc_attr( $search ); ?>">
		<?php endif; ?>
		<?php wp_nonce_field( 'varconv_undo_checked' ); ?>
	</form>
	<?php varconv_tablenav( 'top', count( $rows ), $paged, VARCONV_PER_PAGE, $args, $label, $shown ? $bulk : null ); ?>
	<table class="wp-list-table widefat fixed striped table-view-list varconv-history">
		<thead><?php $headers( 'top' ); ?></thead>
		<tbody>
		<?php if ( ! $shown ) : ?>
			<tr class="no-items"><td class="colspanchange" colspan="5"><?php echo '' !== $search ? esc_html__( 'No conversions match your search.', 'variation-converter-for-woocommerce' ) : esc_html__( 'Nothing combined, split or made simple yet.', 'variation-converter-for-woocommerce' ); ?></td></tr>
		<?php endif; ?>
		<?php foreach ( $shown as $r ) : ?>
			<?php
			$id = $r['id'];
			$kind = $r['kind'];
			$combined = 'combined' === $kind;
			$ids = 'taken' === $kind ? array() : ( json_decode( (string) get_post_meta( $id, $combined ? VARCONV_META : VARCONV_SPLIT_META, true ), true ) ?: array() );
			$undo = array( 'combined' => 'varconv_undo', 'split' => 'varconv_split_undo', 'taken' => 'varconv_take_undo' )[ $kind ];
			$badges = array(
				'combined' => __( 'Combined', 'variation-converter-for-woocommerce' ),
				'split'    => __( 'Split', 'variation-converter-for-woocommerce' ),
				'taken'    => __( 'Made simple', 'variation-converter-for-woocommerce' ),
			);
			?>
			<tr>
				<th scope="row" class="check-column"><input type="checkbox" name="products[]" value="<?php echo (int) $id; ?>" form="varconv-undo-checked" aria-label="<?php echo esc_attr( sprintf( /* translators: %s: product name. */ __( 'Select %s', 'variation-converter-for-woocommerce' ), get_post_field( 'post_title', $id, 'raw' ) ) ); ?>"></th>
				<td class="column-primary has-row-actions">
					<strong><a class="row-title" href="<?php echo esc_url( (string) get_edit_post_link( $id ) ); ?>"><?php echo esc_html( get_post_field( 'post_title', $id, 'raw' ) ); ?></a><?php echo varconv_status_label( $id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped where it is built. ?></strong>
					<div class="row-actions">
						<span class="edit"><a href="<?php echo esc_url( (string) get_edit_post_link( $id ) ); ?>"><?php esc_html_e( 'Edit', 'variation-converter-for-woocommerce' ); ?></a> | </span>
						<span class="view"><a href="<?php echo esc_url( (string) get_permalink( $id ) ); ?>"><?php esc_html_e( 'View', 'variation-converter-for-woocommerce' ); ?></a> | </span>
						<span class="trash"><?php varconv_undo_form( $undo, $id, '', true ); ?></span>
					</div>
					<button type="button" class="toggle-row"><span class="screen-reader-text"><?php esc_html_e( 'Show more details', 'variation-converter-for-woocommerce' ); ?></span></button>
				</td>
				<td class="column-change" data-colname="<?php esc_attr_e( 'Change', 'variation-converter-for-woocommerce' ); ?>">
					<span class="varconv-badge varconv-badge-<?php echo esc_attr( $kind ); ?>"><?php echo esc_html( $badges[ $kind ] ); ?></span>
				</td>
				<td class="column-products" data-colname="<?php esc_attr_e( 'Products', 'variation-converter-for-woocommerce' ); ?>">
					<?php
					if ( 'taken' === $kind ) {
						$variation = (int) get_post_meta( $id, VARCONV_TAKEN_META, true );
						$parent = (int) wp_get_post_parent_id( $variation );
						if ( $parent && varconv_taken_all( $parent ) ) {
							echo esc_html( sprintf(
								/* translators: %s: variable product name. */
								__( 'Made from a variation of %s, which had no variation left for sale and is in the Trash. Undo restores it.', 'variation-converter-for-woocommerce' ),
								get_post_field( 'post_title', $parent, 'raw' )
							) );
						} else {
							echo wp_kses_post( sprintf(
							/* translators: %s: link to the variable product. */
							__( 'Made from a variation of %s, which is disabled there.', 'variation-converter-for-woocommerce' ),
							$parent ? sprintf( '<a href="%s">%s</a>', esc_url( (string) get_edit_post_link( $parent ) ), esc_html( get_post_field( 'post_title', $parent, 'raw' ) ) ) : esc_html__( 'a variable product that no longer exists', 'variation-converter-for-woocommerce' )
							) );
						}
					} else {
						echo esc_html( $combined
						/* translators: %d: number of simple products. */
						? sprintf( _n( 'Made from %d simple product:', 'Made from %d simple products:', count( $ids ), 'variation-converter-for-woocommerce' ), count( $ids ) )
						/* translators: %d: number of simple products. */
						: sprintf( _n( 'Made into %d simple product (this one is hidden):', 'Made into %d simple products (this one is hidden):', count( $ids ), 'variation-converter-for-woocommerce' ), count( $ids ) ) );
						echo ' ' . wp_kses_post( varconv_product_links( $ids ) );
					}
					?>
				</td>
				<td class="column-date varconv-muted" data-colname="<?php esc_attr_e( 'Date', 'variation-converter-for-woocommerce' ); ?>">
					<?php
					/* translators: 1: date, 2: time. */
					echo esc_html( sprintf( __( '%1$s at %2$s', 'variation-converter-for-woocommerce' ), wp_date( (string) get_option( 'date_format' ), $r['time'] ), wp_date( (string) get_option( 'time_format' ), $r['time'] ) ) );
					?>
				</td>
			</tr>
		<?php endforeach; ?>
		</tbody>
		<tfoot><?php $headers( 'bottom' ); ?></tfoot>
	</table>
	<?php
	varconv_tablenav( 'bottom', count( $rows ), $paged, VARCONV_PER_PAGE, $args, $label, $shown ? $bulk : null );
}
