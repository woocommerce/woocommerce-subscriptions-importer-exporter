<?php

/**
 * WC_Importer_Test_Bootstrap
 * @since 1.0.0
 */
class WC_Importer_Test_Bootstrap {

	protected static $instance = null;

	// directory storing dependency plugins
	private $modules_dir = '';

	function __construct() {
		ini_set( 'display_errors','on' );
		error_reporting( E_ALL );

		$this->modules_dir = dirname( dirname( __FILE__ ) ) . '/libs';

		// If testing locally, set the WP_TESTS_DIR to the correct local directory
		if( false == getenv( 'WP_TESTS_DIR' ) ) {
			$test_dir = dirname( dirname( dirname( dirname( dirname( dirname( __FILE__ ) ) ) ) ) ) . '/tests/phpunit';
			putenv( "WP_TESTS_DIR=$test_dir" );
		}

		// Check for select constants defined as environment variables
		foreach ( array('WP_CONTENT_DIR', 'WP_CONTENT_URL', 'WP_PLUGIN_DIR', 'WP_PLUGIN_URL', 'WPMU_PLUGIN_DIR') as $env_constant ) {
			if ( false !== getenv( $env_constant ) && !defined( $env_constant ) ) {
				define( $env_constant, getenv( $env_constant ));
			}
		}

		// Load functions.php so that tests_add_filter function exists
		require_once( getenv( 'WP_TESTS_DIR' ) . '/includes/functions.php');

		// Load dependent files
		tests_add_filter( 'muplugins_loaded', array( $this, 'load_dependency_files' ) );

		// Load WooCommerce_Subscriptions and WooCommerce
		tests_add_filter( 'setup_theme', array( $this, 'install_dependant_plugins_for_testing' ) );

		require getenv( 'WP_TESTS_DIR' ) . '/includes/bootstrap.php';

		// factories
		require_once $this->modules_dir . '/woocommerce/tests/framework/factories/class-wc-unit-test-factory-for-webhook.php';
		require_once $this->modules_dir . '/woocommerce/tests/framework/factories/class-wc-unit-test-factory-for-webhook-delivery.php';

		// framework
		require_once $this->modules_dir . '/woocommerce/tests/framework/class-wc-unit-test-factory.php';
		require_once $this->modules_dir . '/woocommerce/tests/framework/class-wc-mock-session-handler.php';
		// test cases
		require_once $this->modules_dir . '/woocommerce/tests/framework/class-wc-unit-test-case.php';
		require_once $this->modules_dir . '/woocommerce/tests/framework/class-wc-api-unit-test-case.php';

		include_once('WCS_Importer_UnitTestCase.php');
	}

	/**
	 * Load files used for testing
	 * @since 1.0.0
	 */
	function load_dependency_files() {
		// Load WooCommerce
		require_once $this->modules_dir . '/woocommerce/woocommerce.php';

		// Load WooCommerce Subsscriptions files that are used through import process.
		require_once $this->modules_dir . '/woocommerce-subscriptions/woocommerce-subscriptions.php';
		require_once $this->modules_dir . '/woocommerce-subscriptions/classes/class-wc-subscriptions-product.php';
		require_once $this->modules_dir . '/woocommerce-subscriptions/classes/class-wc-subscriptions-admin.php';
		require_once $this->modules_dir . '/woocommerce-subscriptions/classes/class-wc-subscriptions-manager.php';
		require_once $this->modules_dir . '/woocommerce-subscriptions/classes/class-wc-product-subscription.php';
		require_once $this->modules_dir . '/woocommerce-subscriptions/classes/class-wc-subscriptions-renewal-order.php';
		require_once $this->modules_dir . '/woocommerce-subscriptions/classes/class-wc-subscriptions-order.php';
		// Load ActionScheduler module
		require_once $this->modules_dir . '/woocommerce-subscriptions/lib/action-scheduler/action-scheduler.php';
		require_once $this->modules_dir . '/woocommerce-subscriptions/lib/action-scheduler/classes/ActionScheduler.php';
		require_once $this->modules_dir . '/woocommerce-subscriptions/lib/action-scheduler/functions.php';

		// Load Subscriptions Importer
		require_once( dirname( dirname( __FILE__ ) ) . '/wc-subscription-import.php' );
	}

	/** 
	 * Load plugins for testing
	 * @since 1.0.0
	 */
	function install_dependant_plugins_for_testing() {
		// run the installer for WC
		echo "Installing WooCommerce..." . PHP_EOL;
		define( 'WP_UNINSTALL_PLUGIN', true );
		include( $this->modules_dir . '/woocommerce/uninstall.php' );

		$installer = include( $this->modules_dir . '/woocommerce/includes/class-wc-install.php' );
		$installer->install();
		// reload capabilities after install, see https://core.trac.wordpress.org/ticket/28374
		$GLOBALS['wp_roles']->reinit();

		$GLOBALS['woocommerce'] = WC();
		WC()->init();

		// Run the installer for WooSubs
		echo "Installing WooCommerce Subscriptions..." . PHP_EOL;
		WC_Subscriptions::maybe_activate_woocommerce_subscriptions();
	}

	public static function instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

}
WC_Importer_Test_Bootstrap::instance();
