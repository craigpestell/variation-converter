<?php
/**
 * The Combine tab: the scan's groups as a WordPress list table (views, search, filters, bulk actions, sortable
 * columns, a Status column). Each group's review card opens in a row under it, like Quick Edit, to edit and create
 * it. assets/admin.js adds creating, undoing and skipping without a reload, Select by SKU, drag to reorder and
 * creating selected groups in batches; everything also works as plain links and forms.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const VARCONV_PER_PAGE_OPTION = 'varconv_per_page';

// The GET values read here only choose what is displayed; every change goes through a handler that checks its nonce.
// phpcs:disable WordPress.Security.NonceVerification.Recommended

/** @return array<string,string> Note kind => short badge text for the Status column. */
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

/** @return array<string,string> Note kind the warning filter offers => its option text. */
function varconv_warning_options(): array {
	return array(
		'price' => __( 'Prices differ', 'variation-converter' ),
		'cats'  => __( 'Categories differ', 'variation-converter' ),
		'label' => __( 'Missing or repeated labels', 'variation-converter' ),
		'long'  => __( 'Long labels', 'variation-converter' ),
		'dupes' => __( 'Duplicate products', 'variation-converter' ),
	);
}

/** @return array<string,string> View => label. */
function varconv_views(): array {
	return array(
		''        => __( 'All', 'variation-converter' ),
		'ready'   => __( 'Ready', 'variation-converter' ),
		'review'  => __( 'Needs review', 'variation-converter' ),
		'skipped' => __( 'Skipped', 'variation-converter' ),
	);
}

/**
 * The list's filters from a request: view, search, brand, minimum group size, warning, sort column and direction.
 *
 * @param array $request $_GET, or the filters a form carried.
 * @return array{view:string,s:string,brand:string,min:int,warn:string,orderby:string,order:string}
 */
function varconv_filters( array $request ): array {
	$get = static fn( string $key ): string => isset( $request[ $key ] ) && is_scalar( $request[ $key ] ) ? sanitize_text_field( wp_unslash( (string) $request[ $key ] ) ) : '';
	$view = sanitize_key( $get( 'view' ) );
	$warn = sanitize_key( $get( 'warn' ) );
	$orderby = sanitize_key( $get( 'orderby' ) );
	$order = sanitize_key( $get( 'order' ) );
	return array(
		'view'    => isset( varconv_views()[ $view ] ) ? $view : '',
		's'       => $get( 's' ),
		'brand'   => $get( 'brand' ),
		'min'     => min( 5, max( 2, (int) $get( 'min' ) ) ),
		'warn'    => isset( varconv_warning_options()[ $warn ] ) ? $warn : '',
		'orderby' => in_array( $orderby, array( 'name', 'price' ), true ) ? $orderby : '',
		'order'   => in_array( $order, array( 'asc', 'desc' ), true ) ? $order : '',
	);
}

/** The filters that differ from the defaults, for links and forms. */
function varconv_filter_args( array $f ): array {
	return array_filter( array(
		'view'    => $f['view'],
		's'       => $f['s'],
		'brand'   => $f['brand'],
		'min'     => 2 === $f['min'] ? '' : $f['min'],
		'warn'    => $f['warn'],
		'orderby' => $f['orderby'],
		'order'   => $f['order'],
	) );
}

/** The groups the view and filters show, in their order. */
function varconv_filtered( array $scan, array $f ): array {
	$groups = 'skipped' === $f['view'] ? (array) ( $scan['skipped'] ?? array() ) : $scan['collections'];
	if ( 'ready' === $f['view'] ) {
		$groups = array_values( array_filter( $groups, static fn( $c ) => ! $c['notes'] ) );
	} elseif ( 'review' === $f['view'] ) {
		$groups = array_values( array_filter( $groups, static fn( $c ) => (bool) $c['notes'] ) );
	}
	$groups = varconv_with( varconv_min_size( varconv_of_brand( varconv_matching( $groups, $f['s'] ), $f['brand'] ), $f['min'] ), $f['warn'] );
	return varconv_sorted( $groups, '' === $f['orderby'] ? 'size' : $f['orderby'], $f['order'] );
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

/** "$4.00 for 3 products, $5.00 for 3. Shoppers will see $4.00 – $5.00." */
function varconv_price_note( array $members ): string {
	$counts = array();
	foreach ( $members as $m ) {
		$price = (float) ( $m['price'] ?? 0 );
		if ( $price > 0 ) {
			$key = (string) $price;
			$counts[ $key ] = ( $counts[ $key ] ?? 0 ) + 1;
		}
	}
	uksort( $counts, static fn( $a, $b ) => (float) $a <=> (float) $b );
	$parts = array();
	foreach ( $counts as $price => $n ) {
		$parts[] = $parts
			/* translators: 1: a price, 2: number of products at that price. */
			? sprintf( __( '%1$s for %2$d', 'variation-converter' ), varconv_price_text( (float) $price ), $n )
			/* translators: 1: a price, 2: number of products at that price. */
			: sprintf( _n( '%1$s for %2$d product', '%1$s for %2$d products', $n, 'variation-converter' ), varconv_price_text( (float) $price ), $n );
	}
	/* translators: 1: prices and how many products have each, 2: the price range shoppers see. */
	return sprintf( __( '%1$s. Shoppers will see %2$s.', 'variation-converter' ), implode( ', ', $parts ), varconv_price_range( $members ) );
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

/** A nonced admin-post link acting on one group (create as suggested, skip, restore), back to the same list. */
function varconv_group_action_url( string $action, string $key, array $f ): string {
	return wp_nonce_url(
		add_query_arg( array( 'action' => $action, 'key' => $key, 'filters' => rawurlencode( http_build_query( varconv_filter_args( $f ) ) ) ), admin_url( 'admin-post.php' ) ),
		$action . '_' . $key
	);
}

/** A sortable column header, as WordPress list tables draw it. */
function varconv_sortable_header( string $column, string $label, array $f, string $class ): void {
	$current = $f['orderby'] === $column || ( '' === $column && '' === $f['orderby'] );
	$default = '' === $column ? 'desc' : 'asc';
	$order = '' !== $f['order'] ? $f['order'] : $default;
	$next = $current ? ( 'asc' === $order ? 'desc' : 'asc' ) : $default;
	$args = array_merge( varconv_filter_args( $f ), array( 'orderby' => $column, 'order' => $next ) );
	$classes = 'manage-column ' . $class . ( $current ? ' sorted ' . $order : ' sortable ' . ( 'asc' === $default ? 'desc' : 'asc' ) );
	?>
	<th scope="col" class="<?php echo esc_attr( $classes ); ?>"<?php echo $current ? ' aria-sort="' . ( 'asc' === $order ? 'ascending' : 'descending' ) . '"' : ''; ?>>
		<a href="<?php echo esc_url( varconv_url( array_filter( $args ) ) ); ?>"><span><?php echo esc_html( $label ); ?></span><span class="sorting-indicators"><span class="sorting-indicator asc" aria-hidden="true"></span><span class="sorting-indicator desc" aria-hidden="true"></span></span></a>
	</th>
	<?php
}

/** The table's header cells, for thead and tfoot; a hand-picked group's table has no checkboxes or sorting. */
function varconv_list_headers( array $f, bool $list ): void {
	$header = static function ( string $column, string $label, string $class ) use ( $f, $list ): void {
		if ( $list ) {
			varconv_sortable_header( $column, $label, $f, $class );
		} else {
			printf( '<th scope="col" class="manage-column %s">%s</th>', esc_attr( $class ), esc_html( $label ) );
		}
	};
	?>
	<tr>
		<?php if ( $list ) : ?>
			<td class="manage-column column-cb check-column"><input type="checkbox" class="varconv-pick-page" aria-label="<?php esc_attr_e( 'Select all groups on this page', 'variation-converter' ); ?>"></td>
		<?php endif; ?>
		<?php $header( 'name', __( 'Group', 'variation-converter' ), 'column-primary column-name' ); ?>
		<?php $header( '', __( 'Products', 'variation-converter' ), 'column-products' ); ?>
		<th scope="col" class="manage-column column-sku"><?php esc_html_e( 'Parent SKU', 'variation-converter' ); ?></th>
		<?php $header( 'price', __( 'Price', 'variation-converter' ), 'column-price' ); ?>
		<th scope="col" class="manage-column column-attr"><?php esc_html_e( 'Attribute', 'variation-converter' ); ?></th>
		<th scope="col" class="manage-column column-status"><?php esc_html_e( 'Status', 'variation-converter' ); ?></th>
	</tr>
	<?php
}

/** The bulk actions menu for the view; the bottom one names its select apart, as WordPress does. */
function varconv_bulk_menu( array $f, string $which ): void {
	$name = 'top' === $which ? 'bulk_action' : 'bulk_action2';
	$actions = 'skipped' === $f['view']
		? array( 'restore' => __( 'Restore to the list', 'variation-converter' ) )
		: array(
			'create' => __( 'Create variable products', 'variation-converter' ),
			'skip'   => __( 'Skip, not variants', 'variation-converter' ),
		);
	?>
	<div class="alignleft actions bulkactions">
		<label for="varconv-<?php echo esc_attr( $name ); ?>" class="screen-reader-text"><?php esc_html_e( 'Select bulk action', 'variation-converter' ); ?></label>
		<select name="<?php echo esc_attr( $name ); ?>" id="varconv-<?php echo esc_attr( $name ); ?>" form="varconv-bulk">
			<option value=""><?php esc_html_e( 'Bulk actions', 'variation-converter' ); ?></option>
			<?php foreach ( $actions as $value => $label ) : ?>
				<option value="<?php echo esc_attr( $value ); ?>"><?php echo esc_html( $label ); ?></option>
			<?php endforeach; ?>
		</select>
		<button class="button action varconv-bulk-apply" form="varconv-bulk" name="which" value="<?php echo esc_attr( $which ); ?>"><?php esc_html_e( 'Apply', 'variation-converter' ); ?></button>
	</div>
	<?php
}

/** Pagination in a tablenav, with the number of groups and products shown. */
function varconv_list_pages( int $total, int $products, int $paged, int $pages, array $f ): void {
	?>
	<div class="tablenav-pages<?php echo $pages > 1 ? '' : ' one-page'; ?>">
		<span class="displaying-num">
			<?php
			/* translators: 1: number of groups, 2: number of products in them. */
			echo esc_html( sprintf( _n( '%1$s group, %2$s products', '%1$s groups, %2$s products', $total, 'variation-converter' ), number_format_i18n( $total ), number_format_i18n( $products ) ) );
			?>
		</span>
		<?php
		if ( $pages > 1 ) {
			echo wp_kses_post( paginate_links( array(
				'base'    => add_query_arg( 'paged', '%#%', varconv_url( varconv_filter_args( $f ) ) ),
				'format'  => '',
				'current' => $paged,
				'total'   => $pages,
			) ) );
		}
		?>
	</div>
	<?php
}

function varconv_render_combine(): void {
	$scan = varconv_candidates();
	$f = varconv_filters( $_GET );
	$open = isset( $_GET['open'] ) ? sanitize_key( wp_unslash( $_GET['open'] ) ) : '';
	$groups = varconv_filtered( $scan, $f );
	$per_page = varconv_per_page();
	$total = count( $groups );
	$pages = max( 1, (int) ceil( $total / $per_page ) );
	$paged = min( $pages, max( 1, isset( $_GET['paged'] ) ? absint( wp_unslash( $_GET['paged'] ) ) : 1 ) );
	$shown = array_slice( $groups, ( $paged - 1 ) * $per_page, $per_page );
	$excluded = varconv_excluded();
	$all = array_merge( $scan['collections'], (array) ( $scan['skipped'] ?? array() ) );
	$counts = array(
		''        => count( $scan['collections'] ),
		'ready'   => count( array_filter( $scan['collections'], static fn( $c ) => ! $c['notes'] ) ),
		'review'  => count( array_filter( $scan['collections'], static fn( $c ) => (bool) $c['notes'] ) ),
		'skipped' => count( (array) ( $scan['skipped'] ?? array() ) ),
	);
	$brands = array();
	foreach ( $all as $c ) {
		foreach ( varconv_brand_list( $c ) as $brand ) {
			$brands[ strtolower( $brand ) ] = $brand;
		}
	}
	natcasesort( $brands );
	$filtered = '' !== $f['s'] || '' !== $f['brand'] || 2 !== $f['min'] || '' !== $f['warn'];
	$products = array_sum( array_map( static fn( $c ) => count( $c['members'] ), $groups ) );
	$skipped = 'skipped' === $f['view'];
	$keep = array_diff_key( varconv_filter_args( $f ), array( 's' => 1, 'brand' => 1, 'min' => 1, 'warn' => 1 ) );
	?>
	<p class="varconv-lead"><?php esc_html_e( 'Groups of simple products that look like one product in several variants. Nothing changes until you create a group, and every change can be undone from History.', 'variation-converter' ); ?></p>

	<ul class="subsubsub">
		<?php foreach ( varconv_views() as $key => $label ) : ?>
			<li><a href="<?php echo esc_url( varconv_url( array_filter( array( 'view' => $key ) ) ) ); ?>"<?php echo $key === $f['view'] ? ' class="current" aria-current="page"' : ''; ?>><?php echo esc_html( $label ); ?> <span class="count">(<?php echo (int) $counts[ $key ]; ?>)</span></a><?php echo 'skipped' === $key ? '' : ' |'; ?></li>
		<?php endforeach; ?>
	</ul>
	<form method="get" class="search-form">
		<input type="hidden" name="post_type" value="product">
		<input type="hidden" name="page" value="<?php echo esc_attr( VARCONV_PAGE ); ?>">
		<?php foreach ( array_diff_key( varconv_filter_args( $f ), array( 's' => 1 ) ) as $name => $value ) : ?>
			<input type="hidden" name="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( (string) $value ); ?>">
		<?php endforeach; ?>
		<p class="search-box">
			<label class="screen-reader-text" for="varconv-search"><?php esc_html_e( 'Search groups', 'variation-converter' ); ?></label>
			<input type="search" id="varconv-search" name="s" value="<?php echo esc_attr( $f['s'] ); ?>">
			<button class="button"><?php esc_html_e( 'Search groups', 'variation-converter' ); ?></button>
		</p>
	</form>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="varconv-bulk" data-keys="<?php echo esc_attr( (string) wp_json_encode( array_column( $groups, 'key' ) ) ); ?>">
		<input type="hidden" name="action" value="varconv_bulk">
		<input type="hidden" name="filters" value="<?php echo esc_attr( http_build_query( varconv_filter_args( $f ) ) ); ?>">
		<?php wp_nonce_field( 'varconv_bulk' ); ?>
	</form>
	<div class="tablenav top">
		<?php varconv_bulk_menu( $f, 'top' ); ?>
		<form method="get" class="alignleft actions varconv-filters">
			<input type="hidden" name="post_type" value="product">
			<input type="hidden" name="page" value="<?php echo esc_attr( VARCONV_PAGE ); ?>">
			<?php foreach ( array_merge( $keep, array_filter( array( 's' => $f['s'] ) ) ) as $name => $value ) : ?>
				<input type="hidden" name="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( (string) $value ); ?>">
			<?php endforeach; ?>
			<?php if ( $brands ) : ?>
				<label for="varconv-brand" class="screen-reader-text"><?php esc_html_e( 'Filter by brand', 'variation-converter' ); ?></label>
				<select name="brand" id="varconv-brand">
					<option value=""><?php esc_html_e( 'All brands', 'variation-converter' ); ?></option>
					<?php foreach ( $brands as $brand ) : ?>
						<option value="<?php echo esc_attr( $brand ); ?>" <?php selected( strtolower( $f['brand'] ), strtolower( $brand ) ); ?>><?php echo esc_html( $brand ); ?></option>
					<?php endforeach; ?>
				</select>
			<?php endif; ?>
			<label for="varconv-min" class="screen-reader-text"><?php esc_html_e( 'Filter by group size', 'variation-converter' ); ?></label>
			<select name="min" id="varconv-min">
				<option value="2"><?php esc_html_e( 'All sizes', 'variation-converter' ); ?></option>
				<?php foreach ( array( 3, 4, 5 ) as $n ) : ?>
					<?php /* translators: %d: smallest number of products in a group. */ ?>
					<option value="<?php echo (int) $n; ?>" <?php selected( $f['min'], $n ); ?>><?php echo esc_html( sprintf( __( '%d+ products', 'variation-converter' ), $n ) ); ?></option>
				<?php endforeach; ?>
			</select>
			<label for="varconv-warn" class="screen-reader-text"><?php esc_html_e( 'Filter by warning', 'variation-converter' ); ?></label>
			<select name="warn" id="varconv-warn">
				<option value=""><?php esc_html_e( 'All warnings', 'variation-converter' ); ?></option>
				<?php foreach ( varconv_warning_options() as $kind => $label ) : ?>
					<option value="<?php echo esc_attr( $kind ); ?>" <?php selected( $f['warn'], $kind ); ?>><?php echo esc_html( $label ); ?></option>
				<?php endforeach; ?>
			</select>
			<button class="button"><?php esc_html_e( 'Filter', 'variation-converter' ); ?></button>
			<?php if ( $filtered ) : ?>
				<a href="<?php echo esc_url( varconv_url( $keep ) ); ?>" class="varconv-clear"><?php esc_html_e( 'Clear filters', 'variation-converter' ); ?></a>
			<?php endif; ?>
		</form>
		<?php varconv_list_pages( $total, $products, $paged, $pages, $f ); ?>
		<br class="clear">
	</div>
	<?php if ( $total > count( $shown ) && ! $skipped ) : ?>
		<p class="varconv-pick-all-wrap">
			<label>
				<input type="checkbox" name="all" value="1" class="varconv-pick-all" form="varconv-bulk">
				<?php
				/* translators: %d: number of groups the view and filters show. */
				echo esc_html( sprintf( __( 'Select all %d groups that match, not just this page', 'variation-converter' ), $total ) );
				?>
			</label>
		</p>
	<?php endif; ?>
	<div class="varconv-progress notice notice-info inline" aria-live="polite" hidden></div>

	<datalist id="varconv-attributes">
		<?php foreach ( array( 'Color', 'Size', 'Flavor', 'Scent', 'Volume', 'Style' ) as $option ) : ?>
			<option><?php echo esc_html( $option ); ?></option>
		<?php endforeach; ?>
	</datalist>
	<table class="wp-list-table widefat fixed striped table-view-list varconv-list-table">
		<thead><?php varconv_list_headers( $f, true ); ?></thead>
		<tbody id="the-list">
			<?php if ( ! $shown ) : ?>
				<tr class="no-items"><td class="colspanchange" colspan="7"><?php varconv_render_empty( $scan, $f, $filtered ); ?></td></tr>
			<?php endif; ?>
			<?php foreach ( $shown as $c ) : ?>
				<?php varconv_render_group( $c, $excluded[ $c['key'] ] ?? array(), $f, $paged, $c['key'] === $open || 1 === $total, $skipped ); ?>
			<?php endforeach; ?>
		</tbody>
		<tfoot><?php varconv_list_headers( $f, true ); ?></tfoot>
	</table>
	<div class="tablenav bottom">
		<?php varconv_bulk_menu( $f, 'bottom' ); ?>
		<?php varconv_list_pages( $total, $products, $paged, $pages, $f ); ?>
		<br class="clear">
	</div>
	<?php
	// The refill after a failed Create is shown once.
	delete_transient( 'varconv_posted_' . get_current_user_id() );
}

/** Why the list is empty, and what to do about it. */
function varconv_render_empty( array $scan, array $f, bool $filtered ): void {
	if ( $filtered ) {
		printf( '%s <a href="%s">%s</a>', esc_html__( 'No groups match these filters.', 'variation-converter' ), esc_url( varconv_url( array_filter( array( 'view' => $f['view'] ) ) ) ), esc_html__( 'Clear filters', 'variation-converter' ) );
	} elseif ( 'skipped' === $f['view'] ) {
		esc_html_e( 'No skipped groups. Groups you mark "Skip, not variants" show here, and can be restored.', 'variation-converter' );
	} elseif ( 'ready' === $f['view'] || 'review' === $f['view'] ) {
		esc_html_e( 'No groups in this view.', 'variation-converter' );
	} elseif ( ! empty( $scan['skipped'] ) ) {
		esc_html_e( 'Every group the scan found is skipped. The Skipped view lists them.', 'variation-converter' );
	} elseif ( empty( $scan['products'] ) ) {
		esc_html_e( 'There are no published simple products with a SKU to check. The scan only looks at simple products that have a SKU and are shown in the catalog.', 'variation-converter' );
	} else {
		esc_html_e( 'No groups found. The scan looks for simple products whose SKUs share a family (SE1421-01, SE1421-03) and whose names start the same ("Tri-Rings Black", "Tri-Rings Blue").', 'variation-converter' );
	}
}

/**
 * One group: its row in the list, and the row under it holding its review card (hidden until opened).
 *
 * @param int[] $excluded Members the admin unchecked.
 */
function varconv_render_group( array $c, array $excluded, array $f, int $paged, bool $open, bool $skipped = false ): void {
	$key = $c['key'];
	$manual = ! empty( $c['manual'] );
	$posted = varconv_posted_for( $key );
	$open = $open || null !== $posted;
	$excluded = array_map( 'intval', $excluded );
	$checked = array();
	foreach ( $c['members'] as $m ) {
		$checked[ (int) $m['id'] ] = $posted ? isset( $posted['members'][ (int) $m['id'] ] ) : ! in_array( (int) $m['id'], $excluded, true );
	}
	$picked = array_values( array_filter( $c['members'], static fn( $m ) => $checked[ (int) $m['id'] ] ) );
	$badges = array_intersect_key( varconv_note_badges(), $c['notes'] );
	$review_url = varconv_url( array_merge( varconv_filter_args( $f ), array( 'open' => $key, 'paged' => $paged > 1 ? $paged : '' ) ) ) . '#varconv-' . $key;
	$count = count( $picked ) === count( $c['members'] )
		? (string) count( $c['members'] )
		/* translators: 1: products checked, 2: products in the group. */
		: sprintf( __( '%1$d of %2$d', 'variation-converter' ), count( $picked ), count( $c['members'] ) );
	?>
	<tr id="varconv-row-<?php echo esc_attr( $key ); ?>" class="varconv-group-row<?php echo $open ? ' varconv-open' : ''; ?>" data-key="<?php echo esc_attr( $key ); ?>">
		<?php if ( ! $manual ) : ?>
			<th scope="row" class="check-column">
				<?php /* translators: %s: group name. */ ?>
				<input type="checkbox" class="varconv-pick" form="varconv-bulk" name="keys[]" value="<?php echo esc_attr( $key ); ?>" aria-label="<?php echo esc_attr( sprintf( __( 'Select %s', 'variation-converter' ), $c['name'] ) ); ?>">
			</th>
		<?php endif; ?>
		<td class="column-primary has-row-actions">
			<strong>
				<a class="row-title varconv-review-toggle" href="<?php echo esc_url( $review_url ); ?>" aria-expanded="<?php echo $open ? 'true' : 'false'; ?>" aria-controls="varconv-<?php echo esc_attr( $key ); ?>"><span class="varconv-toggle" aria-hidden="true"><?php echo $open ? '&#9662;' : '&#9656;'; ?></span> <?php echo esc_html( $c['name'] ); ?></a>
			</strong>
			<?php if ( '' !== (string) ( $c['brand'] ?? '' ) ) : ?>
				<span class="varconv-brand"><?php echo esc_html( $c['brand'] ); ?></span>
			<?php endif; ?>
			<?php if ( ! $manual ) : ?>
				<div class="row-actions">
					<span class="review"><a class="varconv-review-toggle" href="<?php echo esc_url( $review_url ); ?>"><?php esc_html_e( 'Review', 'variation-converter' ); ?></a> | </span>
					<?php if ( $skipped ) : ?>
						<span class="restore"><a href="<?php echo esc_url( varconv_group_action_url( 'varconv_restore', $key, $f ) ); ?>"><?php esc_html_e( 'Restore to the list', 'variation-converter' ); ?></a></span>
					<?php else : ?>
						<span class="create"><a href="<?php echo esc_url( varconv_group_action_url( 'varconv_create_one', $key, $f ) ); ?>"><?php esc_html_e( 'Create', 'variation-converter' ); ?></a> | </span>
						<span class="trash"><a href="<?php echo esc_url( varconv_group_action_url( 'varconv_skip', $key, $f ) ); ?>"><?php esc_html_e( 'Skip, not variants', 'variation-converter' ); ?></a></span>
					<?php endif; ?>
				</div>
			<?php endif; ?>
			<button type="button" class="toggle-row"><span class="screen-reader-text"><?php esc_html_e( 'Show more details', 'variation-converter' ); ?></span></button>
		</td>
		<td class="column-products" data-colname="<?php esc_attr_e( 'Products', 'variation-converter' ); ?>"><?php echo esc_html( $count ); ?></td>
		<td class="column-sku" data-colname="<?php esc_attr_e( 'Parent SKU', 'variation-converter' ); ?>"><span class="varconv-mono"><?php echo esc_html( $c['sku'] ); ?></span></td>
		<td class="column-price" data-colname="<?php esc_attr_e( 'Price', 'variation-converter' ); ?>"><?php echo esc_html( varconv_price_range( $picked ) ); ?></td>
		<td class="column-attr" data-colname="<?php esc_attr_e( 'Attribute', 'variation-converter' ); ?>"><?php echo esc_html( $c['attribute'] ); ?></td>
		<td class="column-status" data-colname="<?php esc_attr_e( 'Status', 'variation-converter' ); ?>">
			<?php if ( $skipped ) : ?>
				<span class="varconv-badge varconv-badge-skipped"><?php esc_html_e( 'Skipped', 'variation-converter' ); ?></span>
			<?php elseif ( ! $badges ) : ?>
				<span class="varconv-badge varconv-badge-ready"><?php esc_html_e( 'Ready', 'variation-converter' ); ?></span>
			<?php endif; ?>
			<?php foreach ( $badges as $kind => $badge ) : ?>
				<span class="varconv-badge varconv-badge-warn varconv-badge-<?php echo esc_attr( $kind ); ?>"><?php echo esc_html( $badge ); ?></span>
			<?php endforeach; ?>
		</td>
	</tr>
	<tr class="varconv-review" id="varconv-<?php echo esc_attr( $key ); ?>" data-key="<?php echo esc_attr( $key ); ?>"<?php echo $open ? '' : ' hidden'; ?>>
		<td colspan="<?php echo $manual ? 6 : 7; ?>">
			<div class="varconv-review-inner">
				<?php varconv_render_card( $c, $checked, $posted, $f, $paged, $skipped ); ?>
			</div>
		</td>
	</tr>
	<?php
}

/**
 * The review card: fields, categories, warnings, Select by SKU, the members table and the actions.
 *
 * @param array<int,bool> $checked Member ID => checked.
 * @param array|null      $posted  Values a failed Create submitted, to refill.
 */
function varconv_render_card( array $c, array $checked, ?array $posted, array $f, int $paged, bool $skipped = false ): void {
	$members = $c['members'];
	$manual = ! empty( $c['manual'] );
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
	$cats_differ = count( $cat_sets ) > 1;
	$chosen = $posted ? array_map( 'intval', (array) ( $posted['categories'] ?? array() ) ) : array_map( 'intval', $members[0]['cats'] ?? array() );
	$bands = varconv_sku_bands( array_column( $members, 'sku' ) );
	$notes = $c['notes'];
	$cancel = varconv_url( array_merge( varconv_filter_args( $f ), array_filter( array( 'paged' => $paged > 1 ? $paged : '' ) ) ) );
	?>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="varconv-card" data-key="<?php echo esc_attr( $c['key'] ); ?>"<?php echo $manual ? ' data-manual="1"' : ''; ?>>
		<input type="hidden" name="action" value="varconv_create">
		<input type="hidden" name="key" value="<?php echo esc_attr( $c['key'] ); ?>">
		<input type="hidden" name="filters" value="<?php echo esc_attr( http_build_query( varconv_filter_args( $f ) ) ); ?>">
		<input type="hidden" name="paged" value="<?php echo (int) $paged; ?>">
		<?php if ( $manual ) : ?>
			<input type="hidden" name="combine" value="<?php echo esc_attr( implode( ',', array_map( static fn( $m ) => (int) $m['id'], $c['members'] ) ) ); ?>">
		<?php endif; ?>
		<?php wp_nonce_field( 'varconv_create_' . $c['key'] ); ?>
		<div class="varconv-card-message" aria-live="polite">
			<?php if ( $posted ) : ?>
				<div class="notice notice-error inline"><p><?php echo esc_html( (string) $posted['error'] ); ?></p></div>
			<?php endif; ?>
		</div>
		<div class="varconv-fields">
			<label class="name"><span><?php esc_html_e( 'Product name', 'variation-converter' ); ?></span> <input type="text" name="name" value="<?php echo esc_attr( $name ); ?>" required></label>
			<label><span><?php esc_html_e( 'Attribute', 'variation-converter' ); ?></span> <input type="text" name="attribute" class="varconv-attribute" value="<?php echo esc_attr( $attribute ); ?>" list="varconv-attributes" required></label>
			<label><span><?php esc_html_e( 'Parent SKU', 'variation-converter' ); ?></span> <input type="text" name="sku" class="varconv-parent-sku" value="<?php echo esc_attr( $sku ); ?>">
				<span class="varconv-sku-note" aria-live="polite"></span>
			</label>
		</div>
		<?php if ( $cats_differ && $cat_ids ) : ?>
			<fieldset class="varconv-cats">
				<legend><?php esc_html_e( 'Categories for the new product', 'variation-converter' ); ?></legend>
				<?php foreach ( $cat_ids as $cat ) : ?>
					<label><input type="checkbox" name="categories[]" value="<?php echo (int) $cat; ?>" <?php checked( in_array( $cat, $chosen, true ) ); ?>> <?php echo esc_html( varconv_cat_name( $cat ) ); ?></label>
				<?php endforeach; ?>
			</fieldset>
		<?php endif; ?>
		<?php if ( $notes ) : ?>
			<div class="notice notice-warning inline varconv-notes">
				<?php foreach ( $notes as $kind => $note ) : ?>
					<p>
						<?php if ( 'price' === $kind ) : ?>
							<strong><?php esc_html_e( 'Prices differ:', 'variation-converter' ); ?></strong> <?php echo esc_html( varconv_price_note( $members ) ); ?>
						<?php else : ?>
							<?php echo esc_html( $note ); ?>
						<?php endif; ?>
					</p>
				<?php endforeach; ?>
			</div>
		<?php endif; ?>
		<?php if ( $bands || count( $members ) > 10 ) : ?>
			<div class="varconv-prefix">
				<?php if ( $bands ) : ?>
					<span class="varconv-muted" id="varconv-bands-<?php echo esc_attr( $c['key'] ); ?>"><?php esc_html_e( 'Select by SKU:', 'variation-converter' ); ?></span>
					<span role="group" aria-labelledby="varconv-bands-<?php echo esc_attr( $c['key'] ); ?>">
						<button type="button" class="button varconv-band-pick" data-prefix=""><?php esc_html_e( 'All', 'variation-converter' ); ?></button>
						<?php foreach ( $bands as $prefix => $n ) : ?>
							<?php /* translators: 1: SKU start, 2: number of products. */ ?>
							<button type="button" class="button varconv-band-pick" data-prefix="<?php echo esc_attr( (string) $prefix ); ?>" aria-label="<?php echo esc_attr( sprintf( __( 'Check only the %2$d products whose SKU starts with %1$s', 'variation-converter' ), $prefix, $n ) ); ?>"><?php echo esc_html( sprintf( '%s (%d)', $prefix, $n ) ); ?></button>
						<?php endforeach; ?>
					</span>
				<?php endif; ?>
				<?php if ( count( $members ) > 10 ) : ?>
					<label class="varconv-row-filter-label">
						<span class="screen-reader-text"><?php esc_html_e( 'Filter products in this group by SKU or title', 'variation-converter' ); ?></span>
						<input type="search" class="varconv-row-filter" placeholder="<?php esc_attr_e( 'Filter by SKU or title', 'variation-converter' ); ?>">
					</label>
					<button type="button" class="button varconv-check-shown"><?php esc_html_e( 'Check shown only', 'variation-converter' ); ?></button>
				<?php endif; ?>
			</div>
		<?php endif; ?>
		<div class="varconv-table-wrap">
			<table class="widefat varconv-members">
				<thead><tr>
					<td class="varconv-check"><input type="checkbox" class="varconv-check-all" aria-label="<?php esc_attr_e( 'Check all products', 'variation-converter' ); ?>" <?php checked( ! in_array( false, $checked, true ) ); ?>></td>
					<th class="handle"><span class="screen-reader-text"><?php esc_html_e( 'Order', 'variation-converter' ); ?></span></th>
					<th class="thumb"><span class="screen-reader-text"><?php esc_html_e( 'Image', 'variation-converter' ); ?></span></th>
					<th class="col-label"><span class="varconv-attribute-name"><?php echo esc_html( $attribute ); ?></span></th>
					<th class="col-sku"><?php esc_html_e( 'SKU', 'variation-converter' ); ?></th>
					<th><?php esc_html_e( 'Current title', 'variation-converter' ); ?></th>
					<?php if ( $cats_differ ) : ?>
						<th><?php esc_html_e( 'Category', 'variation-converter' ); ?></th>
					<?php endif; ?>
					<th class="col-price"><?php esc_html_e( 'Price', 'variation-converter' ); ?></th>
					<th class="col-stock"><?php esc_html_e( 'Stock', 'variation-converter' ); ?></th>
				</tr></thead>
				<tbody>
				<?php foreach ( $members as $m ) : ?>
					<?php
					$id = (int) $m['id'];
					$label = $posted['members'][ $id ] ?? $posted['labels'][ $id ] ?? $m['label'];
					$title = varconv_plain( (string) ( $m['title'] ?? $m['name'] ) );
					$image = (int) get_post_thumbnail_id( $id );
					?>
					<tr data-id="<?php echo (int) $id; ?>" data-price="<?php echo esc_attr( (string) ( $m['price'] ?? '' ) ); ?>" data-sku="<?php echo esc_attr( strtoupper( trim( (string) $m['sku'] ) ) ); ?>" data-search="<?php echo esc_attr( strtolower( $m['sku'] . ' ' . $title ) ); ?>" class="<?php echo $checked[ $id ] ? '' : 'is-off'; ?>">
						<td class="varconv-check">
							<input type="hidden" name="order[<?php echo (int) $id; ?>]" value="1">
							<?php /* translators: %s: product SKU. */ ?>
							<input type="checkbox" class="varconv-use" name="members[<?php echo (int) $id; ?>][use]" value="1" <?php checked( $checked[ $id ] ); ?> aria-label="<?php echo esc_attr( sprintf( __( 'Include %s', 'variation-converter' ), $m['sku'] ) ); ?>">
						</td>
						<td class="handle">
							<span class="dashicons dashicons-menu varconv-handle" aria-hidden="true"></span>
							<?php /* translators: %s: product SKU. */ ?>
							<button type="button" class="varconv-up varconv-move" aria-label="<?php echo esc_attr( sprintf( __( 'Move %s up', 'variation-converter' ), $m['sku'] ) ); ?>">&uarr;</button>
							<?php /* translators: %s: product SKU. */ ?>
							<button type="button" class="varconv-down varconv-move" aria-label="<?php echo esc_attr( sprintf( __( 'Move %s down', 'variation-converter' ), $m['sku'] ) ); ?>">&darr;</button>
						</td>
						<td class="thumb"><?php echo $image ? wp_get_attachment_image( $image, array( 32, 32 ) ) : '<span class="varconv-no-image"></span>'; ?></td>
						<?php /* translators: 1: attribute name, e.g. Color, 2: product SKU. */ ?>
						<td class="col-label"><input type="text" name="members[<?php echo (int) $id; ?>][label]" value="<?php echo esc_attr( $label ); ?>" aria-label="<?php echo esc_attr( sprintf( __( '%1$s for SKU %2$s', 'variation-converter' ), $attribute, $m['sku'] ) ); ?>"></td>
						<td class="col-sku"><a class="varconv-mono" href="<?php echo esc_url( (string) get_edit_post_link( $id ) ); ?>"><?php echo esc_html( $m['sku'] ); ?></a></td>
						<td class="varconv-muted"><?php echo esc_html( $title ); ?></td>
						<?php if ( $cats_differ ) : ?>
							<td><?php echo esc_html( implode( ', ', array_map( 'varconv_cat_name', array_map( 'intval', $m['cats'] ?? array() ) ) ) ); ?></td>
						<?php endif; ?>
						<td class="col-price"><?php echo '' !== (string) ( $m['price'] ?? '' ) ? esc_html( varconv_price_text( (float) $m['price'] ) ) : ''; ?></td>
						<td class="col-stock"><?php echo wp_kses_post( varconv_stock_html( (string) ( $m['stock'] ?? '' ) ) ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<div class="varconv-review-foot">
			<span class="varconv-muted"><?php esc_html_e( 'Drag to reorder. The first product gives the new product its image and description.', 'variation-converter' ); ?></span>
			<?php if ( ! $manual && ! $skipped ) : ?>
				<button class="button-link varconv-dismiss" name="dismiss" value="1" formnovalidate><?php esc_html_e( 'Skip, not variants', 'variation-converter' ); ?></button>
			<?php endif; ?>
			<?php if ( ! $manual ) : ?>
				<a class="button varconv-cancel" href="<?php echo esc_url( $cancel ); ?>"><?php esc_html_e( 'Cancel', 'variation-converter' ); ?></a>
			<?php endif; ?>
			<button class="button button-primary varconv-create"><?php esc_html_e( 'Create variable product', 'variation-converter' ); ?></button>
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
	$order = isset( $_POST['order'] ) && is_array( $_POST['order'] ) ? array_map( 'absint', array_keys( map_deep( wp_unslash( $_POST['order'] ), 'sanitize_text_field' ) ) ) : array_map( 'absint', array_keys( $rows ) );
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
