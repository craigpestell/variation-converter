<?php
// wp eval-file: a quiet WooCommerce store. No setup wizard, no coming-soon page, no tracking, US dollars.
$options = array(
	'woocommerce_store_address'          => '100 Main Street',
	'woocommerce_store_city'             => 'Portland',
	'woocommerce_default_country'        => 'US:OR',
	'woocommerce_store_postcode'         => '97201',
	'woocommerce_currency'               => 'USD',
	'woocommerce_weight_unit'            => 'lbs',
	'woocommerce_dimension_unit'         => 'in',
	'woocommerce_coming_soon'            => 'no',
	'woocommerce_store_pages_only'       => 'no',
	'woocommerce_allow_tracking'         => 'no',
	'woocommerce_show_marketplace_suggestions' => 'no',
	'woocommerce_task_list_hidden'       => 'yes',
	'woocommerce_extended_task_list_hidden' => 'yes',
	'woocommerce_onboarding_profile'     => array( 'skipped' => true ),
	// Gravatar is often unreachable in sandboxes and shows as a broken image in screenshots.
	'show_avatars'                       => 0,
	'woocommerce_admin_install_timestamp' => time(),
);
foreach ( $options as $name => $value ) {
	update_option( $name, $value );
}
// Pages (shop, cart, checkout, my account) and the lookup tables.
WC_Install::create_pages();
delete_transient( '_wc_activation_redirect' );
// Don't show the admin the WooCommerce welcome screens or the block editor tour.
update_user_meta( 1, 'woocommerce_admin_task_list_tracked_started_tasks', '{}' );
update_user_meta( 1, 'wp_persisted_preferences', array( 'core/edit-post' => array( 'welcomeGuide' => false ) ) );
echo "Store set up.\n";
