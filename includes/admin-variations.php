<?php
/**
 * Make simple, one variation at a time: a "Make simple product" link on each variation in the product edit screen's
 * Variations panel and on each row of the Split tab's card. Both open a confirm screen on the Split tab with the
 * new product's name and categories (giving a variant categories of its own is the usual reason to make it simple),
 * which posts to the varconv_take_one handler and returns to where the link was.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** The confirm screen for making one variation a simple product. $back is "edit" (the product edit screen) or "split". */
function varconv_take_one_url( int $variation_id, string $back ): string {
	return varconv_url( array( 'tab' => 'split', 'make_simple' => $variation_id, 'back' => $back ) );
}

/** Where Cancel and the finished change go back to. */
function varconv_take_back_url( string $back, int $parent ): string {
	if ( 'edit' === $back && $parent ) {
		return (string) get_edit_post_link( $parent, 'url' );
	}
	return varconv_url( array( 'tab' => 'split', 'product' => $parent ?: '' ) ) . ( $parent ? '#varconv-split-' . $parent : '' );
}

// In the variation's header row, beside WooCommerce's Remove.
add_action( 'woocommerce_variation_header', 'varconv_variation_header', 10, 2 );
function varconv_variation_header( $variation, $loop ): void {
	unset( $loop );
	if ( ! current_user_can( VARCONV_CAP ) || ! $variation instanceof WP_Post ) {
		return;
	}
	$id = (int) $variation->ID;
	$taken = varconv_taken_simple( $id );
	if ( $taken ) {
		printf(
			'<span class="varconv-made-simple">%s <a href="%s">%s</a></span>',
			esc_html__( 'Made simple:', 'variation-converter-for-woocommerce' ),
			esc_url( (string) get_edit_post_link( $taken ) ),
			esc_html( get_post_field( 'post_title', $taken, 'raw' ) )
		);
		return;
	}
	if ( '' !== (string) get_post_meta( (int) $variation->post_parent, VARCONV_SPLIT_META, true ) ) {
		return;
	}
	printf(
		'<a href="%s" class="varconv-make-simple">%s</a>',
		esc_url( varconv_take_one_url( $id, 'edit' ) ),
		esc_html__( 'Make simple product', 'variation-converter-for-woocommerce' )
	);
}

add_action( 'admin_enqueue_scripts', 'varconv_variations_assets' );
function varconv_variations_assets( string $hook ): void {
	if ( ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) || 'product' !== get_post_type() || ! current_user_can( VARCONV_CAP ) ) {
		return;
	}
	$url = plugin_dir_url( VARCONV_FILE ) . 'assets/';
	wp_enqueue_style( 'varconv-variations', $url . 'variations.css', array(), VARCONV_VERSION );
	wp_enqueue_script( 'varconv-variations', $url . 'variations.js', array( 'jquery' ), VARCONV_VERSION, true );
}

// phpcs:disable WordPress.Security.NonceVerification.Recommended -- the confirm screen only displays; its form has a nonce.

/** The confirm screen: what the simple product will be, its name and categories. */
function varconv_render_take_one( int $variation_id ): void {
	$back = isset( $_GET['back'] ) && 'edit' === sanitize_key( wp_unslash( $_GET['back'] ) ) ? 'edit' : 'split';
	$variation = wc_get_product( $variation_id );
	$parent = $variation instanceof WC_Product_Variation ? wc_get_product( $variation->get_parent_id() ) : null;
	$cancel = varconv_take_back_url( $back, $parent ? $parent->get_id() : 0 );
	printf( '<p><a href="%s">&larr; %s</a></p>', esc_url( $cancel ), esc_html__( 'Back', 'variation-converter-for-woocommerce' ) );
	if ( ! $variation instanceof WC_Product_Variation || ! $parent instanceof WC_Product_Variable ) {
		echo '<div class="varconv-empty"><p>' . esc_html__( 'That variation was not found.', 'variation-converter-for-woocommerce' ) . '</p></div>';
		return;
	}
	$taken = varconv_taken_simple( $variation_id );
	$problem = $taken ? __( 'This variation is already a simple product. Undo it in History to put it back.', 'variation-converter-for-woocommerce' ) : varconv_take_out_problem( $variation );
	$parent_title = get_post_field( 'post_title', $parent->get_id(), 'raw' );
	$row = array_column( varconv_split_rows( $parent ), null, 'id' )[ $variation_id ] ?? null;
	if ( '' !== $problem || ! $row ) {
		echo '<div class="notice notice-warning inline"><p>' . esc_html( $problem ) . '</p></div>';
		return;
	}
	$member = (int) $row['member'];
	$cats = varconv_take_out_categories( $variation );
	$left = count( array_diff( varconv_enabled_children( $parent ), array( $variation_id ) ) );
	$shared_stock = true !== $variation->get_manage_stock( 'edit' ) && $parent->get_manage_stock( 'edit' );
	?>
	<div class="varconv-review-inner varconv-review-alone varconv-take-one">
		<h2>
			<?php
			/* translators: %s: variable product name. */
			echo esc_html( sprintf( __( 'Make a variation of %s a simple product', 'variation-converter-for-woocommerce' ), $parent_title ) );
			?>
		</h2>
		<p>
			<?php
			if ( $member ) {
				esc_html_e( 'Its original simple product, which Combine made this variation from, is shown again with the variation\'s current price, stock and description. It keeps its own page, reviews and orders, and leaves the variable product.', 'variation-converter-for-woocommerce' );
			} else {
				esc_html_e( 'A new simple product is made from this variation, with its SKU, price, stock, weight and image, and the variable product\'s description, tags and other details.', 'variation-converter-for-woocommerce' );
			}
			echo ' ';
			esc_html_e( 'The variation is disabled, not deleted, and its old link sends shoppers to the simple product. The rest of the variable product stays as it is. Undo is in History.', 'variation-converter-for-woocommerce' );
			?>
		</p>
		<?php if ( 0 === $left ) : ?>
			<div class="notice notice-warning inline"><p>
				<?php
				/* translators: %s: variable product name. */
				echo esc_html( sprintf( __( 'This is the last variation of %s for sale, so the variable product will be moved to the Trash. Its old link will send shoppers to the simple products made from it. Undo in History restores it.', 'variation-converter-for-woocommerce' ), $parent_title ) );
				?>
			</p></div>
		<?php elseif ( 1 === $left ) : ?>
			<div class="notice notice-info inline"><p>
				<?php
				/* translators: %s: variable product name. */
				echo esc_html( sprintf( __( '%s will have 1 variation left for sale.', 'variation-converter-for-woocommerce' ), $parent_title ) );
				?>
			</p></div>
		<?php endif; ?>
		<?php if ( $shared_stock ) : ?>
			<div class="notice notice-warning inline"><p><?php esc_html_e( 'This variation uses the variable product\'s stock count, which the simple product can\'t share, so it won\'t track stock. Set its stock after.', 'variation-converter-for-woocommerce' ); ?></p></div>
		<?php endif; ?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="varconv_take_one">
			<input type="hidden" name="variation" value="<?php echo (int) $variation_id; ?>">
			<input type="hidden" name="back" value="<?php echo esc_attr( $back ); ?>">
			<?php wp_nonce_field( 'varconv_take_' . $variation_id ); ?>
			<table class="widefat varconv-members">
				<thead><tr>
					<th class="thumb"><span class="screen-reader-text"><?php esc_html_e( 'Image', 'variation-converter-for-woocommerce' ); ?></span></th>
					<th><?php esc_html_e( 'Product name', 'variation-converter-for-woocommerce' ); ?></th>
					<th class="col-sku"><?php esc_html_e( 'SKU', 'variation-converter-for-woocommerce' ); ?></th>
					<th class="col-price"><?php esc_html_e( 'Price', 'variation-converter-for-woocommerce' ); ?></th>
					<th class="col-stock"><?php esc_html_e( 'Stock', 'variation-converter-for-woocommerce' ); ?></th>
				</tr></thead>
				<tbody><tr>
					<td class="thumb"><?php echo varconv_thumb_html( (int) $row['image'], 40, (string) $row['name'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped where it is built. ?></td>
					<td><input type="text" name="name" class="regular-text" value="<?php echo esc_attr( $row['name'] ); ?>" aria-label="<?php esc_attr_e( 'Product name', 'variation-converter-for-woocommerce' ); ?>" required></td>
					<td class="col-sku"><span class="varconv-mono"><?php echo esc_html( $row['sku'] ?: '#' . $variation_id ); ?></span></td>
					<td class="col-price"><?php echo '' !== (string) $row['price'] ? esc_html( varconv_price_text( (float) $row['price'] ) ) : ''; ?></td>
					<td class="col-stock">
						<?php
						echo wp_kses_post( varconv_stock_html( (string) $row['stock'] ) );
						if ( true === $variation->get_manage_stock( 'edit' ) ) {
							echo ' (' . esc_html( wc_stock_amount( (float) $variation->get_stock_quantity( 'edit' ) ) ) . ')';
						}
						?>
					</td>
				</tr></tbody>
			</table>
			<fieldset class="varconv-take-cats">
				<legend><?php esc_html_e( 'Categories', 'variation-converter-for-woocommerce' ); ?></legend>
				<p class="varconv-muted"><?php echo $member ? esc_html__( 'Checked: the original product\'s own categories.', 'variation-converter-for-woocommerce' ) : esc_html__( 'Checked: the variable product\'s categories.', 'variation-converter-for-woocommerce' ); ?></p>
				<ul class="categorychecklist">
					<?php
					wp_terms_checklist(
						0,
						array(
							'taxonomy'      => 'product_cat',
							'selected_cats' => $cats,
							'checked_ontop' => false,
						)
					);
					?>
				</ul>
			</fieldset>
			<div class="varconv-review-foot">
				<a class="button" href="<?php echo esc_url( $cancel ); ?>"><?php esc_html_e( 'Cancel', 'variation-converter-for-woocommerce' ); ?></a>
				<button class="button button-primary"><?php esc_html_e( 'Make simple product', 'variation-converter-for-woocommerce' ); ?></button>
			</div>
		</form>
	</div>
	<?php
}

// phpcs:enable WordPress.Security.NonceVerification.Recommended

add_action( 'admin_post_varconv_take_one', 'varconv_handle_take_one' );
function varconv_handle_take_one(): void {
	if ( ! current_user_can( VARCONV_CAP ) ) {
		wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'variation-converter-for-woocommerce' ), 403 );
	}
	$id = isset( $_POST['variation'] ) ? absint( wp_unslash( $_POST['variation'] ) ) : 0;
	check_admin_referer( 'varconv_take_' . $id );
	$back = isset( $_POST['back'] ) && 'edit' === sanitize_key( wp_unslash( $_POST['back'] ) ) ? 'edit' : 'split';
	$name = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
	$cats = isset( $_POST['tax_input']['product_cat'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['tax_input']['product_cat'] ) ) : array();
	$parent = (int) wp_get_post_parent_id( $id );
	try {
		if ( 'product_variation' !== get_post_type( $id ) || ! $parent ) {
			/* translators: %d: product ID. */
			throw new RuntimeException( esc_html( sprintf( __( 'Product %d is not a variation.', 'variation-converter-for-woocommerce' ), $id ) ) );
		}
		$made = varconv_take_out( $parent, array( $id => $name ), array( $id => $cats ) );
	} catch ( RuntimeException $e ) {
		set_transient( 'varconv_error_' . get_current_user_id(), $e->getMessage(), 5 * MINUTE_IN_SECONDS );
		wp_safe_redirect( add_query_arg( 'varconv_failed', 1, varconv_take_one_url( $id, $back ) ) );
		exit;
	}
	varconv_candidates();
	$args = array( 'varconv_done' => 'taken', 'varconv_product' => $parent, 'varconv_count' => count( $made ), 'varconv_made' => (int) reset( $made ) );
	if ( varconv_taken_all( $parent ) ) {
		// The variable product was removed, so its edit screen and Split card are gone: show the new product instead.
		$args['varconv_removed'] = 1;
		$url = 'edit' === $back ? (string) get_edit_post_link( (int) reset( $made ), 'url' ) : varconv_take_back_url( 'split', 0 );
	} else {
		$url = varconv_take_back_url( $back, $parent );
	}
	$hash = strpos( $url, '#' );
	wp_safe_redirect( false === $hash ? add_query_arg( $args, $url ) : add_query_arg( $args, substr( $url, 0, $hash ) ) . substr( $url, $hash ) );
	exit;
}

/**
 * The message after Make simple, on the product edit screen and the Split tab. $removed is the variable product when
 * it was removed because no variation was left for sale.
 */
function varconv_taken_notice_html( int $count, int $made, int $removed = 0 ): string {
	$history = varconv_url( array( 'tab' => 'history' ) );
	if ( $removed ) {
		return sprintf(
			/* translators: 1: number of simple products, 2: variable product name, 3: link to the History tab. */
			_n( 'Made %1$d variation a simple product. %2$s had no variation left for sale, so it is in the Trash now; Undo in <a href="%3$s">History</a> restores it.', 'Made %1$d variations simple products. %2$s had no variation left for sale, so it is in the Trash now; Undo in <a href="%3$s">History</a> restores it.', $count, 'variation-converter-for-woocommerce' ),
			$count,
			esc_html( get_post_field( 'post_title', $removed, 'raw' ) ),
			esc_url( $history )
		);
	}
	if ( 1 === $count && $made ) {
		return sprintf(
			/* translators: 1: link to the simple product, 2: link to the History tab. */
			__( 'Made %1$s a simple product. Its variation is disabled; Undo is in <a href="%2$s">History</a>.', 'variation-converter-for-woocommerce' ),
			sprintf( '<a href="%s">%s</a>', esc_url( (string) get_edit_post_link( $made ) ), esc_html( get_post_field( 'post_title', $made, 'raw' ) ) ),
			esc_url( $history )
		);
	}
	return sprintf(
		/* translators: 1: number of simple products, 2: link to the History tab. */
		_n( 'Made %1$d variation a simple product. It is disabled in the variable product; Undo is in <a href="%2$s">History</a>.', 'Made %1$d variations simple products. They are disabled in the variable product; Undo is in <a href="%2$s">History</a>.', $count, 'variation-converter-for-woocommerce' ),
		$count,
		esc_url( $history )
	);
}

// The product edit screen's notice after Make simple. Display only; the change itself checked its nonce.
add_action( 'admin_notices', 'varconv_edit_screen_notice' );
function varconv_edit_screen_notice(): void {
	$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
	// phpcs:disable WordPress.Security.NonceVerification.Recommended
	if ( ! $screen || 'product' !== $screen->id || ! current_user_can( VARCONV_CAP ) || ! isset( $_GET['varconv_done'] ) || 'taken' !== $_GET['varconv_done'] ) {
		return;
	}
	$count = isset( $_GET['varconv_count'] ) ? absint( wp_unslash( $_GET['varconv_count'] ) ) : 0;
	$made = isset( $_GET['varconv_made'] ) ? absint( wp_unslash( $_GET['varconv_made'] ) ) : 0;
	$removed = ! empty( $_GET['varconv_removed'] ) && isset( $_GET['varconv_product'] ) ? absint( wp_unslash( $_GET['varconv_product'] ) ) : 0;
	// phpcs:enable WordPress.Security.NonceVerification.Recommended
	printf( '<div class="notice notice-success is-dismissible"><p>%s</p></div>', wp_kses_post( varconv_taken_notice_html( $count, $made, $removed ) ) );
}
