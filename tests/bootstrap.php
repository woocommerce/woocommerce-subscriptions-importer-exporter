<?php

$GLOBALS['wp_tests_options'] = array(
	'active_plugins' => array(
		basename( dirname( dirname( __FILE__ ) ) ) . '/libs/woocommerce/woocommerce.php',
		basename( dirname( dirname( __FILE__ ) ) ) . '/libs/woocommerce-subscriptions/woocommerce-subscriptions.php',
		basename( dirname( dirname( __FILE__ ) ) ) . '/wc-subscription-import.php',
	),
	'template'       => 'twentythirteen',
	'stylesheet'     => 'twentythirteen',
);

// Check for select constants defined as environment variables
foreach ( array('WP_CONTENT_DIR', 'WP_CONTENT_URL', 'WP_PLUGIN_DIR', 'WP_PLUGIN_URL', 'WPMU_PLUGIN_DIR') as $env_constant ) {
	if ( false !== getenv( $env_constant ) && !defined( $env_constant ) ) {
		define( $env_constant, getenv( $env_constant ));
	}
}

// If the wordpress-tests repo location has been customized (and specified
// with WP_TESTS_DIR), use that location. This will most commonly be the case
// when configured for use with Travis CI.

// Otherwise, we'll just assume that this plugin is installed in the WordPress
// SVN external checkout configured in the wordpress-tests repo.

if( false !== getenv( 'WP_TESTS_DIR' ) ) {
	require getenv( 'WP_TESTS_DIR' ) . '/includes/bootstrap.php';
} else {
	require dirname( dirname( dirname( dirname( dirname( dirname( __FILE__ ) ) ) ) ) ) . '/tests/phpunit/includes/bootstrap.php';
}

// try activating the woocommerce plugin
activate_plugin( WP_CONTENT_DIR . '/plugins/woocommerce-subscriptions-importer/libs/woocommerce/woocommerce.php', '', TRUE, TRUE );
activate_plugin( WP_CONTENT_DIR . '/plugins/woocommerce-subscriptions-importer/libs/woocommerce-subscriptions/woocommerce-subscriptions.php', '', FALSE, TRUE );

include_once('WCS_Importer_UnitTestCase.php');