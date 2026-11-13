<?php
/**
 * Products > Variation Converter. The page is a React app (src/admin, built into build/admin.js with
 * @wordpress/scripts) made of WordPress's own components; this file adds the menu item, loads the app and gives it a
 * place to draw. The app gets its data from the REST routes in rest.php.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/admin-products.php';
require_once __DIR__ . '/admin-quick-edit.php';
require_once __DIR__ . '/admin-variations.php';

add_action( 'admin_menu', 'varconv_menu', 60 );

/**
 * Up to 0.2.x the page was page=variation-converter. Send old bookmarks and links to the new slug, keeping the rest
 * of the query (tab, view, search). Runs on admin_menu, because WordPress turns away an unknown page right after
 * building the menu, before admin_init.
 */
add_action( 'admin_menu', 'varconv_redirect_old_page' );
function varconv_redirect_old_page(): void {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only redirect, nothing is changed.
	if ( ! isset( $_GET['page'] ) || 'variation-converter' !== $_GET['page'] ) {
		return;
	}
	wp_safe_redirect( add_query_arg( 'page', VARCONV_PAGE ) );
	exit;
}

function varconv_menu(): void {
	$hook = add_submenu_page(
		'edit.php?post_type=product',
		__( 'Variation Converter', 'variation-converter-for-woocommerce' ),
		__( 'Variation Converter', 'variation-converter-for-woocommerce' ),
		VARCONV_CAP,
		VARCONV_PAGE,
		'varconv_render'
	);
	if ( $hook ) {
		add_action( 'admin_print_styles-' . $hook, 'varconv_assets' );
		add_action( 'load-' . $hook, 'varconv_screen_options' );
	}
}

/** Screen Options > Groups per page. */
function varconv_screen_options(): void {
	add_screen_option( 'per_page', array(
		'label'   => __( 'Groups per page', 'variation-converter-for-woocommerce' ),
		'default' => VARCONV_PER_PAGE,
		'option'  => VARCONV_PER_PAGE_OPTION,
	) );
}

add_filter( 'set_screen_option_' . VARCONV_PER_PAGE_OPTION, 'varconv_set_per_page', 10, 3 );
function varconv_set_per_page( $keep, string $option, $value ) {
	return min( 100, max( 5, (int) $value ) );
}

/**
 * Loads the app. build/admin.asset.php, written by the build, lists the WordPress scripts the app uses (React, the
 * components, apiFetch and so on), all of which ship with WordPress, so the plugin bundles none of them.
 */
function varconv_assets(): void {
	$dir = plugin_dir_path( VARCONV_FILE ) . 'build/';
	$url = plugin_dir_url( VARCONV_FILE ) . 'build/';
	$asset = is_readable( $dir . 'admin.asset.php' ) ? require $dir . 'admin.asset.php' : array( 'dependencies' => array(), 'version' => VARCONV_VERSION );
	varconv_jsx_runtime();
	wp_enqueue_style( 'varconv-admin', $url . 'admin.css', array( 'wp-components' ), $asset['version'] );
	wp_enqueue_script( 'varconv-admin', $url . 'admin.js', $asset['dependencies'], $asset['version'], true );
	wp_set_script_translations( 'varconv-admin', 'variation-converter-for-woocommerce' );
	wp_add_inline_script( 'varconv-admin', 'window.varconvBoot = ' . wp_json_encode( varconv_boot() ) . ';', 'before' );
}

/**
 * The build asks for React's JSX runtime as its own script, which WordPress ships from 6.6. On WordPress 6.5 this
 * makes it from the React that is there, so the app runs on both.
 */
function varconv_jsx_runtime(): void {
	if ( wp_script_is( 'react-jsx-runtime', 'registered' ) ) {
		return;
	}
	wp_register_script( 'react-jsx-runtime', false, array( 'react' ), VARCONV_VERSION, true );
	wp_add_inline_script(
		'react-jsx-runtime',
		'window.ReactJSXRuntime = ( function ( React ) {
			function make( many ) {
				return function ( type, config, key ) {
					var props = {}, children = config ? config.children : undefined, name;
					for ( name in config ) {
						if ( "children" !== name && Object.prototype.hasOwnProperty.call( config, name ) ) {
							props[ name ] = config[ name ];
						}
					}
					if ( undefined !== key ) {
						props.key = "" + key;
					}
					if ( undefined === children ) {
						return React.createElement( type, props );
					}
					return many && Array.isArray( children )
						? React.createElement.apply( null, [ type, props ].concat( children ) )
						: React.createElement( type, props, children );
				};
			}
			return { Fragment: React.Fragment, jsx: make( false ), jsxs: make( true ) };
		} )( window.React );'
	);
}

/** What the app knows before its first request: where it lives, the plugin version and what the tabs show. */
function varconv_boot(): array {
	return array(
		'version' => VARCONV_VERSION,
		'pageUrl' => varconv_url(),
		'status'  => varconv_rest_status(),
	);
}

function varconv_render(): void {
	if ( ! current_user_can( VARCONV_CAP ) ) {
		wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'variation-converter-for-woocommerce' ), 403 );
	}
	?>
	<div class="wrap varconv">
		<div id="varconv-root">
			<h1><?php esc_html_e( 'Variation Converter', 'variation-converter-for-woocommerce' ); ?></h1>
			<noscript><p><?php esc_html_e( 'This page needs JavaScript. Turn it on in your browser and load the page again.', 'variation-converter-for-woocommerce' ); ?></p></noscript>
		</div>
	</div>
	<?php
}

/** "Split into simple products" under each variable product in Products > All Products. */
add_filter( 'post_row_actions', 'varconv_row_action', 10, 2 );
function varconv_row_action( array $actions, WP_Post $post ): array {
	if ( 'product' !== $post->post_type || ! varconv_status_ok( $post->post_status ) || ! current_user_can( VARCONV_CAP ) ) {
		return $actions;
	}
	$product = wc_get_product( $post->ID );
	if ( $product && $product->is_type( 'variable' ) && '' === (string) $product->get_meta( VARCONV_SPLIT_META ) ) {
		$actions['varconv_split'] = sprintf( '<a href="%s">%s</a>', esc_url( varconv_url( array( 'tab' => 'split', 'product' => $post->ID ) ) ), esc_html__( 'Split into simple products', 'variation-converter-for-woocommerce' ) );
	}
	return $actions;
}
