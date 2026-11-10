<?php
/**
 * The Combine tab's "Add to a combined product" screen (add=IDs): pick the variable product, check each product's
 * labels, and add them (includes/add-to.php). Reached from the Combine button of the same name, with the checked
 * groups and products, and from the Products list's bulk action, which takes any simple product, with or without a SKU.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.Security.NonceVerification.Recommended -- display only; Add checks its own nonce.

/** The product IDs to add, if any. @return int[] */
function varconv_add_ids(): array {
	$list = isset( $_GET['add'] ) ? sanitize_text_field( wp_unslash( $_GET['add'] ) ) : '';
	return array_slice( array_values( array_unique( array_filter( array_map( 'absint', explode( ',', $list ) ) ) ) ), 0, VARCONV_ADD_MAX );
}

/** The screen's URL for these products, keeping what else is given. */
function varconv_add_url( array $ids, array $args = array() ): string {
	return varconv_url( array_merge( array( 'add' => implode( ',', array_map( 'absint', $ids ) ) ), array_filter( $args ) ) );
}

function varconv_render_add( array $ids ): void {
	$found = varconv_addable( $ids );
	$products = $found['products'];
	$into = isset( $_GET['into'] ) ? absint( wp_unslash( $_GET['into'] ) ) : 0;
	$parent = $into ? wc_get_product( $into ) : null;
	if ( $parent && '' !== varconv_add_target_problem( $parent ) ) {
		printf( '<div class="notice notice-error inline"><p>%s</p></div>', esc_html( varconv_add_target_problem( $parent ) ) );
		$parent = null;
	}
	echo '<p><a href="' . esc_url( varconv_url() ) . '">&larr; ' . esc_html__( 'All suggested groups', 'variation-converter-for-woocommerce' ) . '</a></p>';
	echo '<h2>' . esc_html__( 'Add to a combined product', 'variation-converter-for-woocommerce' ) . '</h2>';
	if ( $found['refused'] ) {
		printf(
			'<div class="notice notice-warning inline"><p>%s %s</p></div>',
			esc_html( sprintf(
				/* translators: %d: number of selected products left out. */
				_n( '%d selected product is left out: only simple products not already in a combined product, split or Make simple can be added.', '%d selected products are left out: only simple products not already in a combined product, split or Make simple can be added.', count( $found['refused'] ), 'variation-converter-for-woocommerce' ),
				count( $found['refused'] )
			) ),
			wp_kses_post( varconv_product_links( $found['refused'] ) )
		);
	}
	if ( ! $products ) {
		echo '<div class="varconv-empty"><p>' . esc_html__( 'Select at least one simple product to add.', 'variation-converter-for-woocommerce' ) . '</p></div>';
		return;
	}
	if ( ! $parent ) {
		varconv_render_add_targets( $products );
		return;
	}
	varconv_render_add_form( $products, $parent );
}

/**
 * Step one: the combined products to choose from, found by name or SKU. The search starts with what the products'
 * names share.
 */
function varconv_render_add_targets( array $products ): void {
	$ids = array_column( $products, 'id' );
	$find = isset( $_GET['find'] ) ? sanitize_text_field( wp_unslash( $_GET['find'] ) ) : null;
	if ( null === $find ) {
		$words = varconv_words( (string) $products[0]['name'] );
		$find = (string) ( $words[0] ?? '' );
		// The first word the names share is usually enough to find their product, and short enough to find it anyway.
		foreach ( $products as $p ) {
			if ( '' !== $find && false === stripos( (string) $p['name'], $find ) ) {
				$find = '';
			}
		}
	}
	$targets = varconv_add_targets( $find );
	if ( ! $targets && '' !== $find && ! isset( $_GET['find'] ) ) {
		$find = '';
		$targets = varconv_add_targets( '' );
	}
	?>
	<p class="varconv-lead">
		<?php
		echo esc_html( sprintf(
			/* translators: %d: number of products. */
			_n( 'Choose the combined product to add %d product to. It becomes a variation, and is hidden as Combine hides its products.', 'Choose the combined product to add these %d products to. Each becomes a variation, and is hidden as Combine hides its products.', count( $products ), 'variation-converter-for-woocommerce' ),
			count( $products )
		) );
		?>
	</p>
	<p><?php echo esc_html( implode( ', ', array_column( $products, 'title' ) ) ); ?></p>
	<form method="get" class="varconv-find" role="search">
		<input type="hidden" name="post_type" value="product">
		<input type="hidden" name="page" value="<?php echo esc_attr( VARCONV_PAGE ); ?>">
		<input type="hidden" name="add" value="<?php echo esc_attr( implode( ',', $ids ) ); ?>">
		<label for="varconv-find-into" class="varconv-find-label"><?php esc_html_e( 'Find the combined product', 'variation-converter-for-woocommerce' ); ?></label>
		<span class="varconv-find-row">
			<input type="search" id="varconv-find-into" name="find" value="<?php echo esc_attr( $find ); ?>" placeholder="<?php esc_attr_e( 'Product name, for example Classic Tee', 'variation-converter-for-woocommerce' ); ?>">
			<button class="button button-primary"><?php esc_html_e( 'Search', 'variation-converter-for-woocommerce' ); ?></button>
		</span>
		<span class="description"><?php esc_html_e( 'Only products Variation Converter combined are listed, since their Undo can give the products back.', 'variation-converter-for-woocommerce' ); ?></span>
	</form>
	<table class="wp-list-table widefat fixed striped table-view-list varconv-add-targets">
		<thead>
			<tr>
				<th scope="col" class="manage-column column-primary"><?php esc_html_e( 'Combined product', 'variation-converter-for-woocommerce' ); ?></th>
				<th scope="col" class="manage-column"><?php esc_html_e( 'Variations', 'variation-converter-for-woocommerce' ); ?></th>
				<th scope="col" class="manage-column"><?php esc_html_e( 'Attributes', 'variation-converter-for-woocommerce' ); ?></th>
				<th scope="col" class="manage-column"><span class="screen-reader-text"><?php esc_html_e( 'Choose', 'variation-converter-for-woocommerce' ); ?></span></th>
			</tr>
		</thead>
		<tbody>
			<?php if ( ! $targets ) : ?>
				<tr class="no-items"><td colspan="4">
					<?php
					echo esc_html( '' !== $find
						/* translators: %s: what was searched for. */
						? sprintf( __( 'No combined product matches "%s".', 'variation-converter-for-woocommerce' ), $find )
						: __( 'There is no combined product yet. Combine some products first, or combine these with a group on the Combine tab.', 'variation-converter-for-woocommerce' ) );
					?>
				</td></tr>
			<?php endif; ?>
			<?php foreach ( $targets as $id ) : ?>
				<?php
				$target = wc_get_product( $id );
				if ( ! $target instanceof WC_Product_Variable ) {
					continue;
				}
				$problem = varconv_add_target_problem( $target );
				?>
				<tr>
					<td class="column-primary"><a href="<?php echo esc_url( (string) get_edit_post_link( $id ) ); ?>"><?php echo esc_html( $target->get_name( 'edit' ) ); ?></a><?php echo varconv_status_label( $id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped where it is built. ?></td>
					<td data-colname="<?php esc_attr_e( 'Variations', 'variation-converter-for-woocommerce' ); ?>"><?php echo (int) count( $target->get_children() ); ?></td>
					<td data-colname="<?php esc_attr_e( 'Attributes', 'variation-converter-for-woocommerce' ); ?>"><?php echo esc_html( implode( ', ', array_map( 'varconv_attribute_label', varconv_variation_attributes( $target ) ) ) ); ?></td>
					<td>
						<?php if ( '' === $problem ) : ?>
							<a class="button" href="<?php echo esc_url( varconv_add_url( $ids, array( 'into' => $id ) ) ); ?>"><?php esc_html_e( 'Choose', 'variation-converter-for-woocommerce' ); ?></a>
						<?php else : ?>
							<span class="description"><?php echo esc_html( $problem ); ?></span>
						<?php endif; ?>
					</td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>
	<?php
}

/** Step two: each product's labels for the chosen product's attributes, then Add. */
function varconv_render_add_form( array $products, WC_Product_Variable $parent ): void {
	$attributes = array_values( varconv_variation_attributes( $parent ) );
	$options = varconv_attribute_options( $parent );
	$posted = get_transient( 'varconv_add_posted_' . get_current_user_id() );
	delete_transient( 'varconv_add_posted_' . get_current_user_id() );
	$posted = is_array( $posted ) && (int) ( $posted['into'] ?? 0 ) === $parent->get_id() ? $posted : null;
	$ids = array_column( $products, 'id' );
	?>
	<p class="varconv-lead">
		<?php
		echo wp_kses_post( sprintf(
			/* translators: 1: edit link, 2: product name, 3: link to choose another product. */
			__( 'Adding to <a href="%1$s">%2$s</a>. <a href="%3$s">Choose another product</a>', 'variation-converter-for-woocommerce' ),
			esc_url( (string) get_edit_post_link( $parent->get_id() ) ),
			esc_html( $parent->get_name( 'edit' ) ),
			esc_url( varconv_add_url( $ids ) )
		) );
		?>
	</p>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="varconv-add-form">
		<input type="hidden" name="action" value="varconv_add_to">
		<input type="hidden" name="into" value="<?php echo (int) $parent->get_id(); ?>">
		<input type="hidden" name="add" value="<?php echo esc_attr( implode( ',', $ids ) ); ?>">
		<?php wp_nonce_field( 'varconv_add_to_' . $parent->get_id() ); ?>
		<?php if ( $posted ) : ?>
			<div class="notice notice-error inline"><p><?php echo esc_html( (string) $posted['error'] ); ?></p></div>
		<?php endif; ?>
		<?php foreach ( $options as $i => $list ) : ?>
			<datalist id="varconv-options-<?php echo (int) $i; ?>">
				<?php foreach ( $list as $option ) : ?>
					<option><?php echo esc_html( $option ); ?></option>
				<?php endforeach; ?>
			</datalist>
		<?php endforeach; ?>
		<table class="wp-list-table widefat fixed striped table-view-list varconv-add-table">
			<thead>
				<tr>
					<th scope="col" class="manage-column column-primary"><?php esc_html_e( 'Product', 'variation-converter-for-woocommerce' ); ?></th>
					<th scope="col" class="manage-column column-sku"><?php esc_html_e( 'SKU', 'variation-converter-for-woocommerce' ); ?></th>
					<th scope="col" class="manage-column column-price"><?php esc_html_e( 'Price', 'variation-converter-for-woocommerce' ); ?></th>
					<?php foreach ( $attributes as $attribute ) : ?>
						<th scope="col" class="manage-column"><?php echo esc_html( varconv_attribute_label( $attribute ) ); ?></th>
					<?php endforeach; ?>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $products as $p ) : ?>
					<?php $labels = $posted['labels'][ $p['id'] ] ?? varconv_add_guess( (string) $p['name'], $parent->get_name( 'edit' ), $options, (string) $p['sku'] ); ?>
					<tr>
						<td class="column-primary"><a href="<?php echo esc_url( (string) get_edit_post_link( $p['id'] ) ); ?>"><?php echo esc_html( $p['title'] ); ?></a><?php echo varconv_status_label( (int) $p['id'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped where it is built. ?></td>
						<td class="column-sku" data-colname="<?php esc_attr_e( 'SKU', 'variation-converter-for-woocommerce' ); ?>"><span class="varconv-mono"><?php echo '' !== $p['sku'] ? esc_html( $p['sku'] ) : '&mdash;'; ?></span></td>
						<td class="column-price" data-colname="<?php esc_attr_e( 'Price', 'variation-converter-for-woocommerce' ); ?>"><?php echo esc_html( '' !== $p['price'] ? varconv_price_text( (float) $p['price'] ) : __( 'No price', 'variation-converter-for-woocommerce' ) ); ?></td>
						<?php foreach ( $attributes as $i => $attribute ) : ?>
							<td data-colname="<?php echo esc_attr( varconv_attribute_label( $attribute ) ); ?>">
								<?php /* translators: 1: attribute name, 2: product name. */ ?>
								<input type="text" name="labels[<?php echo (int) $p['id']; ?>][<?php echo (int) $i; ?>]" value="<?php echo esc_attr( (string) ( $labels[ $i ] ?? '' ) ); ?>" list="varconv-options-<?php echo (int) $i; ?>" required aria-label="<?php echo esc_attr( sprintf( __( '%1$s for %2$s', 'variation-converter-for-woocommerce' ), varconv_attribute_label( $attribute ), $p['title'] ) ); ?>">
							</td>
						<?php endforeach; ?>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<p class="description"><?php esc_html_e( 'Each product becomes a variation with its own SKU, price, stock and image, and its old page redirects to it. Undo of the combined product in History gives every product back; Make simple product on one variation takes just that one back out.', 'variation-converter-for-woocommerce' ); ?></p>
		<p><button class="button button-primary"><?php echo esc_html( _n( 'Add as a variation', 'Add as variations', count( $products ), 'variation-converter-for-woocommerce' ) ); ?></button></p>
	</form>
	<?php
}

// phpcs:enable WordPress.Security.NonceVerification.Recommended

add_action( 'admin_post_varconv_add_to', 'varconv_handle_add_to' );
function varconv_handle_add_to(): void {
	if ( ! current_user_can( VARCONV_CAP ) ) {
		wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'variation-converter-for-woocommerce' ), 403 );
	}
	$into = isset( $_POST['into'] ) ? absint( wp_unslash( $_POST['into'] ) ) : 0;
	check_admin_referer( 'varconv_add_to_' . $into );
	$ids = isset( $_POST['add'] ) ? array_filter( array_map( 'absint', explode( ',', sanitize_text_field( wp_unslash( $_POST['add'] ) ) ) ) ) : array();
	$labels = array();
	// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- each label is sanitized below.
	$raw = isset( $_POST['labels'] ) && is_array( $_POST['labels'] ) ? wp_unslash( $_POST['labels'] ) : array();
	foreach ( $ids as $id ) {
		$labels[ $id ] = array_map( 'sanitize_text_field', array_values( (array) ( $raw[ $id ] ?? array() ) ) );
	}
	try {
		$made = varconv_add_to( $into, $labels );
	} catch ( RuntimeException $e ) {
		set_transient( 'varconv_add_posted_' . get_current_user_id(), array( 'into' => $into, 'labels' => $labels, 'error' => $e->getMessage() ), 5 * MINUTE_IN_SECONDS );
		wp_safe_redirect( varconv_add_url( $ids, array( 'into' => $into ) ) );
		exit;
	}
	wp_safe_redirect( varconv_url( array( 'varconv_done' => 'added', 'varconv_product' => $into, 'varconv_count' => count( $made ) ) ) );
	exit;
}

/** The notice after Add. */
function varconv_added_html( int $id, int $count ): string {
	return '<p>' . wp_kses_post( sprintf(
		/* translators: 1: number of products, 2: edit link, 3: product name, 4: view link. */
		_n( 'Added %1$d product to <a href="%2$s">%3$s</a> (<a href="%4$s">view</a>) as a variation. It is now hidden, and Undo in History gives it back with the others.', 'Added %1$d products to <a href="%2$s">%3$s</a> (<a href="%4$s">view</a>) as variations. They are now hidden, and Undo in History gives them back with the others.', $count, 'variation-converter-for-woocommerce' ),
		$count,
		esc_url( (string) get_edit_post_link( $id ) ),
		esc_html( get_post_field( 'post_title', $id, 'raw' ) ),
		esc_url( (string) get_permalink( $id ) )
	) ) . '</p>';
}
