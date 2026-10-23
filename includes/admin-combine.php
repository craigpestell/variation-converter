<?php
/**
 * The Combine tab: the scan's groups as compact rows (brand, name, "3 of 5 products", price range, warnings), each
 * opening into a card to edit and create it, with filters, sorting and creating selected groups in bulk.
 * assets/admin.js adds creating, undoing and skipping without a reload, the filter inside a group, row order and the
 * price preview; everything also works as plain forms.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const VARCONV_PER_PAGE_OPTION = 'varconv_per_page';

// The GET values read here only choose what is displayed; every change goes through a handler that checks its nonce.
// phpcs:disable WordPress.Security.NonceVerification.Recommended

/** @return array<string,string> Note kind => short badge text. */
function varconv_note_badges(): array {
	return array(
		'dupes'  => __( 'Duplicates', 'variation-converter' ),
		'label'  => __( 'Check labels', 'variation-converter' ),
		'repeat' => __( 'Repeated label', 'variation-converter' ),
		'long'   => __( 'Long labels', 'variation-converter' ),
		'price'  => __( 'Prices differ', 'variation-converter' ),
		'cats'   => __( 'Categories differ', 'variation-converter' ),
	);
}

/** @return array<string,string> Note kind the list can leave out => its option text. */
function varconv_hide_options(): array {
	return array(
		'label' => __( 'missing or repeated labels', 'variation-converter' ),
		'long'  => __( 'long labels', 'variation-converter' ),
		'price' => __( 'different prices', 'variation-converter' ),
		'cats'  => __( 'different categories', 'variation-converter' ),
		'dupes' => __( 'duplicate products', 'variation-converter' ),
	);
}

/**
 * The list's filters from a request: search, brand, minimum group size, warnings to leave out, sort.
 *
 * @param array $request $_GET, or the filters a form carried.
 * @return array{s:string,brand:string,min:int,hide:string[],sort:string}
 */
function varconv_filters( array $request ): array {
	$hide = isset( $request['hide'] ) && is_array( $request['hide'] ) ? array_map( 'sanitize_key', wp_unslash( $request['hide'] ) ) : array();
	$sort = isset( $request['sort'] ) ? sanitize_key( wp_unslash( $request['sort'] ) ) : '';
	return array(
		's'     => isset( $request['s'] ) ? sanitize_text_field( wp_unslash( $request['s'] ) ) : '',
		'brand' => isset( $request['brand'] ) ? sanitize_text_field( wp_unslash( $request['brand'] ) ) : '',
		'min'   => min( 5, max( 2, isset( $request['min'] ) ? absint( wp_unslash( $request['min'] ) ) : 2 ) ),
		'hide'  => array_values( array_intersect( $hide, array_keys( varconv_hide_options() ) ) ),
		'sort'  => in_array( $sort, array( 'name', 'brand' ), true ) ? $sort : 'size',
	);
}

/** The filters that differ from the defaults, for links and forms. */
function varconv_filter_args( array $f ): array {
	return array_filter( array(
		's'     => $f['s'],
		'brand' => $f['brand'],
		'min'   => 2 === $f['min'] ? '' : $f['min'],
		'hide'  => $f['hide'],
		'sort'  => 'size' === $f['sort'] ? '' : $f['sort'],
	) );
}

/** The groups the filters show, in their order. */
function varconv_filtered( array $collections, array $f ): array {
	return varconv_sorted( varconv_without( varconv_min_size( varconv_of_brand( varconv_matching( $collections, $f['s'] ), $f['brand'] ), $f['min'] ), $f['hide'] ), $f['sort'] );
}

function varconv_per_page(): int {
	$n = (int) get_user_option( VARCONV_PER_PAGE_OPTION );
	return $n >= 5 && $n <= 100 ? $n : VARCONV_PER_PAGE;
}

/** Screen Options > Groups per page. */
function varconv_screen_options(): void {
	add_screen_option( 'per_page', array(
		'label'   => __( 'Groups per page', 'variation-converter' ),
		'default' => VARCONV_PER_PAGE,
		'option'  => VARCONV_PER_PAGE_OPTION,
	) );
}

add_filter( 'set_screen_option_' . VARCONV_PER_PAGE_OPTION, 'varconv_set_per_page', 10, 3 );
function varconv_set_per_page( $keep, string $option, $value ) {
	return min( 100, max( 5, (int) $value ) );
}

/** A price as WooCommerce shows it, as plain text. */
function varconv_price_text( float $price ): string {
	return html_entity_decode( wp_strip_all_tags( wc_price( $price ) ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
}

/** The price range a variable product made from these members shows ("$9.99 – $12.99"); '' without prices. */
function varconv_price_range( array $members ): string {
	$prices = array_filter( array_map( static fn( $m ) => (float) ( $m['price'] ?? 0 ), $members ), static fn( $p ) => $p > 0 );
	if ( ! $prices ) {
		return '';
	}
	$low = min( $prices );
	$high = max( $prices );
	return $low === $high ? varconv_price_text( $low ) : varconv_price_text( $low ) . ' – ' . varconv_price_text( $high );
}

/**
 * What the price preview script needs to format prices as the store does.
 *
 * @return array{symbol:string,format:string,decimals:int,decimal:string,thousand:string}
 */
function varconv_price_settings(): array {
	return array(
		'symbol'   => html_entity_decode( get_woocommerce_currency_symbol(), ENT_QUOTES | ENT_HTML5, 'UTF-8' ),
		'format'   => html_entity_decode( get_woocommerce_price_format(), ENT_QUOTES | ENT_HTML5, 'UTF-8' ),
		'decimals' => wc_get_price_decimals(),
		'decimal'  => wc_get_price_decimal_separator(),
		'thousand' => wc_get_price_thousand_separator(),
	);
}

/** The values a failed Create submitted for the group $key, kept for the current user to refill its card. */
function varconv_posted_for( string $key ): ?array {
	$posted = get_transient( 'varconv_posted_' . get_current_user_id() );
	return is_array( $posted ) && ( $posted['key'] ?? '' ) === $key ? $posted : null;
}

function varconv_render_combine(): void {
	$scan = varconv_candidates();
	$f = varconv_filters( $_GET );
	$all = $scan['collections'];
	$collections = varconv_filtered( $all, $f );
	$per_page = varconv_per_page();
	$total = count( $collections );
	$pages = max( 1, (int) ceil( $total / $per_page ) );
	$paged = min( $pages, max( 1, isset( $_GET['paged'] ) ? absint( wp_unslash( $_GET['paged'] ) ) : 1 ) );
	$shown = array_slice( $collections, ( $paged - 1 ) * $per_page, $per_page );
	$dismissed = count( (array) get_option( VARCONV_DISMISSED_OPTION, array() ) );
	$excluded = varconv_excluded();
	$brands = array();
	foreach ( $all as $c ) {
		foreach ( varconv_brand_list( $c ) as $brand ) {
			$brands[ strtolower( $brand ) ] = $brand;
		}
	}
	natcasesort( $brands );
	$filtered = varconv_filter_args( $f ) !== array();
	?>
	<p class="varconv-intro"><?php esc_html_e( 'Finds simple products that are really one product in several variants, such as one per color or size.', 'variation-converter' ); ?></p>
	<ol class="varconv-steps">
		<li><?php esc_html_e( 'Review the suggested groups.', 'variation-converter' ); ?></li>
		<li><?php esc_html_e( 'Create a variable product from each one you approve.', 'variation-converter' ); ?></li>
		<li><?php esc_html_e( 'Undo any time in History. Originals are hidden, never deleted.', 'variation-converter' ); ?></li>
	</ol>

	<form method="get" class="varconv-filters">
		<input type="hidden" name="post_type" value="product">
		<input type="hidden" name="page" value="<?php echo esc_attr( VARCONV_PAGE ); ?>">
		<p class="search-box">
			<label class="screen-reader-text" for="varconv-search"><?php esc_html_e( 'Search groups', 'variation-converter' ); ?></label>
			<input type="search" id="varconv-search" name="s" value="<?php echo esc_attr( $f['s'] ); ?>" placeholder="<?php esc_attr_e( 'Name or SKU', 'variation-converter' ); ?>">
		</p>
		<?php if ( $brands ) : ?>
			<label><span><?php esc_html_e( 'Brand', 'variation-converter' ); ?></span>
				<select name="brand">
					<option value=""><?php esc_html_e( 'All brands', 'variation-converter' ); ?></option>
					<?php foreach ( $brands as $brand ) : ?>
						<option value="<?php echo esc_attr( $brand ); ?>" <?php selected( strtolower( $f['brand'] ), strtolower( $brand ) ); ?>><?php echo esc_html( $brand ); ?></option>
					<?php endforeach; ?>
				</select>
			</label>
		<?php endif; ?>
		<label><span><?php esc_html_e( 'Size', 'variation-converter' ); ?></span>
			<select name="min">
				<?php foreach ( array( 2, 3, 4, 5 ) as $n ) : ?>
					<?php /* translators: %d: smallest number of products in a group. */ ?>
					<option value="<?php echo (int) $n; ?>" <?php selected( $f['min'], $n ); ?>><?php echo esc_html( sprintf( __( '%d+ products', 'variation-converter' ), $n ) ); ?></option>
				<?php endforeach; ?>
			</select>
		</label>
		<label><span><?php esc_html_e( 'Sort', 'variation-converter' ); ?></span>
			<select name="sort">
				<option value="size" <?php selected( $f['sort'], 'size' ); ?>><?php esc_html_e( 'Largest first', 'variation-converter' ); ?></option>
				<option value="name" <?php selected( $f['sort'], 'name' ); ?>><?php esc_html_e( 'Name', 'variation-converter' ); ?></option>
				<option value="brand" <?php selected( $f['sort'], 'brand' ); ?>><?php esc_html_e( 'Brand', 'variation-converter' ); ?></option>
			</select>
		</label>
		<fieldset class="varconv-hide">
			<legend><?php esc_html_e( 'Leave out groups with', 'variation-converter' ); ?></legend>
			<?php foreach ( varconv_hide_options() as $kind => $label ) : ?>
				<label><input type="checkbox" name="hide[]" value="<?php echo esc_attr( $kind ); ?>" <?php checked( in_array( $kind, $f['hide'], true ) ); ?>> <?php echo esc_html( $label ); ?></label>
			<?php endforeach; ?>
		</fieldset>
		<button class="button"><?php esc_html_e( 'Apply', 'variation-converter' ); ?></button>
		<?php if ( $filtered ) : ?>
			<a href="<?php echo esc_url( varconv_url() ); ?>"><?php esc_html_e( 'Clear filters', 'variation-converter' ); ?></a>
		<?php endif; ?>
	</form>

	<p class="varconv-summary">
		<?php
		$products = array_sum( array_map( static fn( $c ) => count( $c['members'] ), $all ) );
		echo esc_html( sprintf(
			/* translators: 1: number of groups, 2: number of products in them, 3: time since the scan, e.g. "5 mins". */
			_n( '%1$d group found (%2$d products), scanned %3$s ago.', '%1$d groups found (%2$d products), scanned %3$s ago.', count( $all ), 'variation-converter' ),
			count( $all ),
			$products,
			human_time_diff( $scan['time'] )
		) );
		if ( $filtered ) {
			/* translators: %d: number of groups the filters show. */
			echo ' ' . esc_html( sprintf( _n( '%d shown.', '%d shown.', $total, 'variation-converter' ), $total ) );
		}
		?>
	</p>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="varconv-rescan">
		<input type="hidden" name="action" value="varconv_rescan">
		<?php wp_nonce_field( 'varconv_rescan' ); ?>
		<button class="button"><?php esc_html_e( 'Scan again', 'variation-converter' ); ?></button>
		<?php if ( $dismissed ) : ?>
			<button class="button-link" name="undismiss" value="1">
				<?php
				/* translators: %d: number of skipped groups. */
				echo esc_html( sprintf( _n( 'Show %d skipped group', 'Show %d skipped groups', $dismissed, 'variation-converter' ), $dismissed ) );
				?>
			</button>
		<?php endif; ?>
	</form>

	<?php
	if ( ! $shown ) {
		varconv_render_empty( $scan, $dismissed, $filtered );
		return;
	}
	?>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="varconv-bulk" class="varconv-bulk" data-keys="<?php echo esc_attr( (string) wp_json_encode( array_column( $collections, 'key' ) ) ); ?>">
		<input type="hidden" name="action" value="varconv_bulk">
		<input type="hidden" name="filters" value="<?php echo esc_attr( http_build_query( varconv_filter_args( $f ) ) ); ?>">
		<?php wp_nonce_field( 'varconv_bulk' ); ?>
		<label><input type="checkbox" class="varconv-pick-page"> <?php esc_html_e( 'Select all on this page', 'variation-converter' ); ?></label>
		<?php if ( $total > count( $shown ) ) : ?>
			<label>
				<input type="checkbox" name="all" value="1" class="varconv-pick-all">
				<?php
				/* translators: %d: number of groups the filters show. */
				echo esc_html( sprintf( __( 'Select all %d groups that match', 'variation-converter' ), $total ) );
				?>
			</label>
		<?php endif; ?>
		<button class="button button-primary varconv-bulk-create"><?php esc_html_e( 'Create selected groups', 'variation-converter' ); ?></button>
		<span class="description"><?php esc_html_e( 'Uses each group\'s suggested name, attribute and labels, and the first product\'s categories. Groups that need a label are skipped.', 'variation-converter' ); ?></span>
		<div class="varconv-progress" aria-live="polite" hidden></div>
	</form>

	<datalist id="varconv-attributes">
		<?php foreach ( array( 'Color', 'Size', 'Flavor', 'Scent', 'Volume', 'Style' ) as $option ) : ?>
			<option><?php echo esc_html( $option ); ?></option>
		<?php endforeach; ?>
	</datalist>
	<div class="varconv-groups">
		<?php foreach ( $shown as $c ) : ?>
			<?php varconv_render_group( $c, $excluded[ $c['key'] ] ?? array(), $f, $paged, 1 === count( $shown ) ); ?>
		<?php endforeach; ?>
	</div>

	<?php if ( $pages > 1 ) : ?>
		<p class="varconv-pages">
			<?php
			echo wp_kses_post( paginate_links( array(
				'base'    => add_query_arg( 'paged', '%#%', varconv_url( varconv_filter_args( $f ) ) ),
				'format'  => '',
				'current' => $paged,
				'total'   => $pages,
			) ) );
			?>
		</p>
	<?php endif; ?>
	<?php
	// The refill after a failed Create is shown once.
	delete_transient( 'varconv_posted_' . get_current_user_id() );
}

/** Why the list is empty, and what to do about it. */
function varconv_render_empty( array $scan, int $dismissed, bool $filtered ): void {
	echo '<div class="varconv-empty">';
	if ( $filtered && $scan['collections'] ) {
		printf(
			'<p>%s <a href="%s">%s</a></p>',
			esc_html__( 'No groups match these filters.', 'variation-converter' ),
			esc_url( varconv_url() ),
			esc_html__( 'Clear filters', 'variation-converter' )
		);
	} elseif ( $dismissed && ! $scan['collections'] ) {
		echo '<p>' . esc_html__( 'Every group the scan found is skipped. Use "Show skipped groups" above to review them again.', 'variation-converter' ) . '</p>';
	} elseif ( empty( $scan['products'] ) ) {
		echo '<p>' . esc_html__( 'There are no published simple products with a SKU to check. The scan only looks at simple products that have a SKU and are shown in the catalog.', 'variation-converter' ) . '</p>';
	} else {
		echo '<p>' . esc_html__( 'No groups found. The scan looks for simple products whose SKUs share a family (SE1421-01, SE1421-03) and whose names start the same ("Tri-Rings Black", "Tri-Rings Blue").', 'variation-converter' ) . '</p>';
	}
	echo '</div>';
}

/**
 * One group: a compact row that opens into its card.
 *
 * @param int[] $excluded Members the admin unchecked.
 */
function varconv_render_group( array $c, array $excluded, array $f, int $paged, bool $open ): void {
	$posted = varconv_posted_for( $c['key'] );
	$excluded = array_map( 'intval', $excluded );
	$checked = array();
	foreach ( $c['members'] as $m ) {
		$checked[ (int) $m['id'] ] = $posted ? isset( $posted['members'][ (int) $m['id'] ] ) : ! in_array( (int) $m['id'], $excluded, true );
	}
	$picked = array_values( array_filter( $c['members'], static fn( $m ) => $checked[ (int) $m['id'] ] ) );
	$badges = array_intersect_key( varconv_note_badges(), $c['notes'] );
	$title_id = 'varconv-title-' . $c['key'];
	?>
	<div class="varconv-group" id="varconv-<?php echo esc_attr( $c['key'] ); ?>" data-key="<?php echo esc_attr( $c['key'] ); ?>">
		<input type="checkbox" class="varconv-pick" form="varconv-bulk" name="keys[]" value="<?php echo esc_attr( $c['key'] ); ?>" aria-labelledby="<?php echo esc_attr( $title_id ); ?>">
		<details<?php echo $open || $posted ? ' open' : ''; ?>>
			<summary>
				<?php if ( '' !== (string) $c['brand'] ) : ?>
					<span class="varconv-brand"><?php echo esc_html( $c['brand'] ); ?></span>
				<?php endif; ?>
				<h2 class="varconv-title" id="<?php echo esc_attr( $title_id ); ?>"><?php echo esc_html( $c['name'] ); ?></h2>
				<span class="varconv-count">
					<?php
					/* translators: 1: products checked, 2: products in the group. */
					echo esc_html( sprintf( __( '%1$d of %2$d products', 'variation-converter' ), count( $picked ), count( $c['members'] ) ) );
					?>
				</span>
				<span class="varconv-sku"><?php echo esc_html( $c['sku'] ); ?></span>
				<span class="varconv-range"><?php echo esc_html( varconv_price_range( $picked ) ); ?></span>
				<?php foreach ( $badges as $kind => $badge ) : ?>
					<span class="varconv-badge varconv-badge-<?php echo esc_attr( $kind ); ?>"><?php echo esc_html( $badge ); ?></span>
				<?php endforeach; ?>
			</summary>
			<?php varconv_render_card( $c, $checked, $posted, $f, $paged ); ?>
		</details>
	</div>
	<?php
}

/**
 * The card: fields, categories, warnings, the members table and the actions.
 *
 * @param array<int,bool> $checked Member ID => checked.
 * @param array|null      $posted  Values a failed Create submitted, to refill.
 */
function varconv_render_card( array $c, array $checked, ?array $posted, array $f, int $paged ): void {
	$members = $c['members'];
	if ( $posted ) {
		// The order the admin had set.
		$order = array_flip( array_map( 'intval', array_keys( (array) $posted['order'] ) ) );
		usort( $members, static fn( $a, $b ) => ( $order[ (int) $a['id'] ] ?? PHP_INT_MAX ) <=> ( $order[ (int) $b['id'] ] ?? PHP_INT_MAX ) );
	}
	$name = $posted['name'] ?? $c['name'];
	$attribute = $posted['attribute'] ?? $c['attribute'];
	$sku = $posted['sku'] ?? $c['sku'];
	$cat_ids = array();
	$cat_sets = array();
	foreach ( $members as $m ) {
		$ids = array_map( 'intval', $m['cats'] ?? array() );
		sort( $ids );
		$cat_sets[ implode( ',', $ids ) ] = true;
		$cat_ids = array_merge( $cat_ids, $ids );
	}
	$cat_ids = array_values( array_unique( $cat_ids ) );
	$chosen = $posted ? array_map( 'intval', (array) ( $posted['categories'] ?? array() ) ) : array_map( 'intval', $members[0]['cats'] ?? array() );
	?>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="varconv-card">
		<input type="hidden" name="action" value="varconv_create">
		<input type="hidden" name="key" value="<?php echo esc_attr( $c['key'] ); ?>">
		<input type="hidden" name="filters" value="<?php echo esc_attr( http_build_query( varconv_filter_args( $f ) ) ); ?>">
		<input type="hidden" name="paged" value="<?php echo (int) $paged; ?>">
		<?php wp_nonce_field( 'varconv_create_' . $c['key'] ); ?>
		<div class="varconv-card-message" aria-live="polite">
			<?php if ( $posted ) : ?>
				<div class="notice notice-error inline"><p><?php echo esc_html( (string) $posted['error'] ); ?></p></div>
			<?php endif; ?>
		</div>
		<div class="varconv-fields">
			<label class="name"><?php esc_html_e( 'Product name', 'variation-converter' ); ?> <input type="text" name="name" value="<?php echo esc_attr( $name ); ?>" required></label>
			<label><?php esc_html_e( 'Attribute', 'variation-converter' ); ?> <input type="text" name="attribute" class="varconv-attribute" value="<?php echo esc_attr( $attribute ); ?>" list="varconv-attributes" required></label>
			<label><?php esc_html_e( 'Parent SKU', 'variation-converter' ); ?> <input type="text" name="sku" class="varconv-parent-sku" value="<?php echo esc_attr( $sku ); ?>">
				<span class="varconv-sku-note" aria-live="polite"></span>
			</label>
		</div>
		<?php if ( count( $cat_sets ) > 1 && $cat_ids ) : ?>
			<fieldset class="varconv-cats">
				<legend><?php esc_html_e( 'Categories for the new product', 'variation-converter' ); ?></legend>
				<?php foreach ( $cat_ids as $cat ) : ?>
					<label><input type="checkbox" name="categories[]" value="<?php echo (int) $cat; ?>" <?php checked( in_array( $cat, $chosen, true ) ); ?>> <?php echo esc_html( varconv_cat_name( $cat ) ); ?></label>
				<?php endforeach; ?>
			</fieldset>
		<?php endif; ?>
		<?php if ( $c['notes'] ) : ?>
			<div class="notice notice-warning inline varconv-notes">
				<ul>
					<?php foreach ( $c['notes'] as $note ) : ?>
						<li><?php echo esc_html( $note ); ?></li>
					<?php endforeach; ?>
				</ul>
			</div>
		<?php endif; ?>
		<?php if ( count( $members ) > 3 ) : ?>
			<div class="varconv-row-tools">
				<label>
					<span class="screen-reader-text"><?php esc_html_e( 'Filter products in this group by SKU or title', 'variation-converter' ); ?></span>
					<input type="search" class="varconv-row-filter" placeholder="<?php esc_attr_e( 'Filter by SKU or title', 'variation-converter' ); ?>">
				</label>
				<button type="button" class="button varconv-check-shown"><?php esc_html_e( 'Check shown only', 'variation-converter' ); ?></button>
				<button type="button" class="button varconv-uncheck-shown"><?php esc_html_e( 'Uncheck shown', 'variation-converter' ); ?></button>
				<label class="varconv-band">
					<?php esc_html_e( 'Shade by first', 'variation-converter' ); ?>
					<input type="number" class="varconv-sku-chars" min="0" max="30" value="0" aria-describedby="varconv-band-help-<?php echo esc_attr( $c['key'] ); ?>">
					<?php esc_html_e( 'SKU characters', 'variation-converter' ); ?>
				</label>
				<span class="description" id="varconv-band-help-<?php echo esc_attr( $c['key'] ); ?>"><?php esc_html_e( 'Rows whose SKUs match up to that character share a shade. Create from some products; the rest stay here as a group.', 'variation-converter' ); ?></span>
			</div>
		<?php endif; ?>
		<div class="varconv-table-wrap">
			<table class="widefat striped varconv-members">
				<thead><tr>
					<td class="check-column"><input type="checkbox" class="varconv-check-all" aria-label="<?php esc_attr_e( 'Check all products', 'variation-converter' ); ?>" <?php checked( ! in_array( false, $checked, true ) ); ?>></td>
					<th class="thumb"><span class="screen-reader-text"><?php esc_html_e( 'Image', 'variation-converter' ); ?></span></th>
					<th class="label"><span class="varconv-attribute-name"><?php echo esc_html( $attribute ); ?></span></th>
					<th><?php esc_html_e( 'SKU', 'variation-converter' ); ?></th>
					<th><?php esc_html_e( 'Current title', 'variation-converter' ); ?></th>
					<th><?php esc_html_e( 'Category', 'variation-converter' ); ?></th>
					<th><?php esc_html_e( 'Price', 'variation-converter' ); ?></th>
					<th><?php esc_html_e( 'Stock', 'variation-converter' ); ?></th>
					<th class="order"><span class="screen-reader-text"><?php esc_html_e( 'Order', 'variation-converter' ); ?></span></th>
				</tr></thead>
				<tbody>
				<?php foreach ( $members as $m ) : ?>
					<?php
					$id = (int) $m['id'];
					$label = $posted['members'][ $id ] ?? $posted['labels'][ $id ] ?? $m['label'];
					$title = varconv_plain( (string) ( $m['title'] ?? $m['name'] ) );
					$image = (int) get_post_thumbnail_id( $id );
					?>
					<tr data-id="<?php echo (int) $id; ?>" data-price="<?php echo esc_attr( (string) ( $m['price'] ?? '' ) ); ?>" data-search="<?php echo esc_attr( strtolower( $m['sku'] . ' ' . $title ) ); ?>">
						<th scope="row" class="check-column">
							<input type="hidden" name="order[<?php echo (int) $id; ?>]" value="1">
							<?php /* translators: %s: product SKU. */ ?>
							<input type="checkbox" class="varconv-use" name="members[<?php echo (int) $id; ?>][use]" value="1" <?php checked( $checked[ $id ] ); ?> aria-label="<?php echo esc_attr( sprintf( __( 'Include %s', 'variation-converter' ), $m['sku'] ) ); ?>">
						</th>
						<td class="thumb"><?php echo $image ? wp_get_attachment_image( $image, array( 40, 40 ) ) : ''; ?></td>
						<?php /* translators: 1: attribute name, e.g. Color, 2: product SKU. */ ?>
						<td><input type="text" name="members[<?php echo (int) $id; ?>][label]" value="<?php echo esc_attr( $label ); ?>" aria-label="<?php echo esc_attr( sprintf( __( '%1$s for SKU %2$s', 'variation-converter' ), $attribute, $m['sku'] ) ); ?>"></td>
						<td class="sku"><a class="varconv-member-sku" href="<?php echo esc_url( (string) get_edit_post_link( $id ) ); ?>" data-sku="<?php echo esc_attr( $m['sku'] ); ?>"><?php echo esc_html( $m['sku'] ); ?></a></td>
						<td class="title"><?php echo esc_html( $title ); ?></td>
						<td><?php echo esc_html( implode( ', ', array_map( 'varconv_cat_name', array_map( 'intval', $m['cats'] ?? array() ) ) ) ); ?></td>
						<td><?php echo '' !== (string) ( $m['price'] ?? '' ) ? esc_html( varconv_price_text( (float) $m['price'] ) ) : ''; ?></td>
						<td><?php echo esc_html( varconv_stock_text( (string) ( $m['stock'] ?? '' ) ) ); ?></td>
						<td class="order">
							<?php /* translators: %s: product SKU. */ ?>
							<button type="button" class="button-link varconv-up" aria-label="<?php echo esc_attr( sprintf( __( 'Move %s up', 'variation-converter' ), $m['sku'] ) ); ?>">&uarr;</button>
							<?php /* translators: %s: product SKU. */ ?>
							<button type="button" class="button-link varconv-down" aria-label="<?php echo esc_attr( sprintf( __( 'Move %s down', 'variation-converter' ), $m['sku'] ) ); ?>">&darr;</button>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<p class="varconv-preview">
			<?php esc_html_e( 'Shoppers will see', 'variation-converter' ); ?>
			<strong class="varconv-preview-range"><?php echo esc_html( varconv_price_range( array_filter( $members, static fn( $m ) => $checked[ (int) $m['id'] ] ) ) ); ?></strong>
			<span class="description"><?php esc_html_e( 'The first row gives the product its image and description; use the arrows to change the order.', 'variation-converter' ); ?></span>
		</p>
		<div class="varconv-actions">
			<button class="button button-primary varconv-create"><?php esc_html_e( 'Create variable product', 'variation-converter' ); ?></button>
			<button class="button varconv-dismiss" name="dismiss" value="1" formnovalidate><?php esc_html_e( 'Skip, not variants', 'variation-converter' ); ?></button>
		</div>
	</form>
	<?php
}

function varconv_cat_name( int $id ): string {
	$term = get_term( $id, 'product_cat' );
	return $term && ! is_wp_error( $term ) ? html_entity_decode( $term->name, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) : '';
}

/** A stock status as WooCommerce names it ("In stock"). */
function varconv_stock_text( string $status ): string {
	return wc_get_product_stock_status_options()[ $status ] ?? $status;
}

/**
 * The group a Create form posted.
 *
 * @return array{name:string,attribute:string,sku:string,members:array<int,string>,categories:int[],labels:array<int,string>,order:array<int,int>}
 */
function varconv_posted_group(): array {
	// phpcs:disable WordPress.Security.NonceVerification.Missing -- callers check the nonce first.
	$rows = isset( $_POST['members'] ) && is_array( $_POST['members'] ) ? map_deep( wp_unslash( $_POST['members'] ), 'sanitize_text_field' ) : array();
	$order = isset( $_POST['order'] ) && is_array( $_POST['order'] ) ? array_map( 'absint', array_keys( wp_unslash( $_POST['order'] ) ) ) : array_map( 'absint', array_keys( $rows ) );
	$members = array();
	$labels = array();
	// In the order the rows were shown, which the admin may have changed.
	foreach ( $order as $id ) {
		$row = $rows[ $id ] ?? null;
		if ( ! is_array( $row ) ) {
			continue;
		}
		$labels[ $id ] = (string) ( $row['label'] ?? '' );
		if ( ! empty( $row['use'] ) ) {
			$members[ $id ] = $labels[ $id ];
		}
	}
	$group = array(
		'name'       => isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '',
		'attribute'  => isset( $_POST['attribute'] ) ? sanitize_text_field( wp_unslash( $_POST['attribute'] ) ) : '',
		'sku'        => isset( $_POST['sku'] ) ? sanitize_text_field( wp_unslash( $_POST['sku'] ) ) : '',
		'members'    => $members,
		'categories' => isset( $_POST['categories'] ) && is_array( $_POST['categories'] ) ? array_map( 'absint', wp_unslash( $_POST['categories'] ) ) : array(),
		'labels'     => $labels,
		'order'      => array_fill_keys( $order, 1 ),
	);
	// phpcs:enable WordPress.Security.NonceVerification.Missing
	return $group;
}

/** Creates the posted group. @throws RuntimeException When it cannot be made. */
function varconv_create_posted( array $group ): int {
	return varconv_create( $group['name'], $group['attribute'], $group['sku'], $group['members'], $group['categories'] );
}

/** Undo button for a product just made, shown in its notice and in place of its card. */
function varconv_created_html( int $id ): string {
	ob_start();
	?>
	<p>
		<?php
		echo wp_kses_post( sprintf(
			/* translators: 1: edit link, 2: product name, 3: view link. */
			__( 'Created <a href="%1$s">%2$s</a> (<a href="%3$s">view</a>). Its simple products are now hidden.', 'variation-converter' ),
			esc_url( (string) get_edit_post_link( $id ) ),
			esc_html( get_post_field( 'post_title', $id, 'raw' ) ),
			esc_url( (string) get_permalink( $id ) )
		) );
		?>
	</p>
	<?php
	varconv_undo_form( 'varconv_undo', $id, __( 'Undo conversion', 'variation-converter' ) );
	return (string) ob_get_clean();
}
