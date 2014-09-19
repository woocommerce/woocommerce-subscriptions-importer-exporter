<?php

require_once dirname( dirname( dirname( __FILE__ ) ) ) . '/libs/woocommerce-subscriptions/classes/class-wc-subscriptions-product.php';
/**
 * Class WCS_Importer_Simple_Test
 * A simple test case testing importing a csv with 1 row not in test mode. This is a basic test that just checks that users are being created all meta
 * information for both users and orders are added correctly.
 */
class WCS_Importer_Simple_Test extends WCS_Importer_UnitTestCase {

	static $import_results = array();
	static $user_id;

	public function setUp() {
		// Create a new subscription product to test on
		$product_id = wp_insert_post( array( 
			'post_type' 				=> 'product',
			'post_author' 				=> 1,
			'post_title' 				=> 'Simple Test Subscription',
			'post_status' 				=> 'publish',
			'comment_status' 			=> 'open',
			'ping_status' 				=> 'closed',
			'post_name' 				=> 'simple-subscription-example',
			'filter'					=> 'raw',
		));
		update_post_meta( $product_id, '_subscription_price', 10 );
		update_post_meta( $product_id, '_subscription_period', 'month' );
		update_post_meta( $product_id, '_subscription_period_interval', 1 );
		update_post_meta( $product_id, '_subscription_length', 0 );

		error_log( 'Newly created product id = ' . $product_id );
		wp_set_object_terms( $product_id, 'subscription', 'product_type' );

		// setup a mapped_fields case 
		$test_csv = dirname( __FILE__ ) . '/test-files/simple-test.csv';
		// Manually set the mapped fields for the test case
		$mapped_fields = array(
			'custom_user_meta'							=> array(),
			'custom_order_meta'							=> array(),
			'custom_user_order_meta'					=> array(),
			'customer_id' 								=> '',
			'customer_username' 						=> '',
			'customer_password'							=> '',
			'billing_email' 							=> '',
			'billing_phone' 							=> '',
			'billing_company'							=> '',
			'shipping_first_name' 						=> '',
			'shipping_last_name' 						=> '',
			'shipping_address_2' 						=> '',
			'subscription_trial_expiry_date'			=> '',
			'recurring_line_total' 						=> '',
			'recurring_line_tax' 						=> '',
			'recurring_line_subtotal' 					=> '',
			'recurring_line_subtotal_tax'				=> '',
			'line_total' 								=> '',
			'line_tax' 									=> '',
			'line_subtotal' 							=> '',
			'line_subtotal_tax' 						=> '',
			'order_discount' 							=> '',
			'cart_discount' 							=> '',
			'order_shipping_tax' 						=> '',
			'order_shipping'							=> '',
			'order_tax'									=> '',
			'order_total' 								=> '',
			'order_recurring_total'						=> '',
			'stripe_customer_id'						=> '',
			'paypal_subscriber_id'						=> '',
			'download_permission_granted'				=> '',
			'product_id'								=> 'product_id',
			'customer_email' 							=> 'customer_email',
			'billing_first_name' 						=> 'billing_first_name',
			'billing_last_name' 						=> 'billing_last_name',
			'billing_address_1' 						=> 'billing_address_1',
			'billing_address_2' 						=> 'billing_address_2',
			'billing_city' 								=> 'billing_city',
			'billing_state' 							=> 'billing_state',
			'billing_postcode' 							=> 'billing_postcode',
			'billing_country' 							=> 'billing_country',
			'shipping_address_1' 						=> 'shipping_address_1',
			'shipping_city' 							=> 'shipping_city',
			'shipping_state' 							=> 'shipping_state',
			'shipping_postcode' 						=> 'shipping_postcode',
			'shipping_country' 							=> 'shipping_country',
			'subscription_status'						=> 'subscription_status',
			'subscription_start_date'					=> 'subscription_start_date',
			'subscription_expiry_date'					=> 'subscription_expiry_date',
			'subscription_end_date'						=> 'subscription_end_date',
			'payment_method' 							=> 'payment_method',
			'payment_method_title'						=> 'payment_method_title',
			'shipping_method' 							=> 'shipping_method',
			'shipping_method_title'						=> 'shipping_method_title',
			'wc_authorize_net_cim_payment_profile_id' 	=> 'wc_authorize_net_cim_payment_profile_id',
			'wc_authorize_net_cim_customer_profile_id' 	=> 'wc_authorize_net_cim_customer_profile_id',
		);

		// running the importer in test mode and email customer set to false.
		self::$import_results = WCS_Import_Parser::import_data( $test_csv, $mapped_fields, 0, 10000, 1, 'false', 'false' )[0];
		self::$user_id = self::$import_results['user_id'];
		error_log( 'Results: ' . print_r( self::$import_results, true ) );
	}

	/**
	 * If no username is given in the CSV the importer will create the new username using the first part of the email.
	 * Test this functionality.
	 */
	public function test_new_customer_default_username() {

		$expected_username = 'johndoe';
		$this->assertEquals( $expected_username, wp_get_user( self::$user_id, 'user_login', true ) );
	}

	/**
	* test the new user meta data is added correctly
	* - test email
	* - test billing information
	*/
	public function test_new_customer_meta_from_csv() {

		$expected_email = 'johndoe@example.com';
		$this->assertEquals( $expected_email, wp_get_user( self::$user_id, 'user_email', true ) );
		// Will consider separating these into individual functions to easily understanding exactly which case is causing the assertion
		$expected_billing_first_name = 'John';
		$expected_billing_last_name  = 'Doe';
		$expected_billing_address_1  = '1 First Avenue';
		$expected_billing_address_2  = '2 Second Avenue';
		$expected_billing_company    = '';
		$expected_billing_city       = 'Brisbane';
		$expected_billing_state      = 'Queensland';
		$expected_billing_postcode   = '4109';
		$expected_billing_country    = 'Australia';
		$expected_billing_phone      = '';

		// Run a bunch of assertions
		$this->assertEquals( $expected_billing_first_name, get_user_meta( self::$user_id, 'billing_first_name', true ) );
		$this->assertEquals( $expected_billing_last_name, get_user_meta( self::$user_id, 'billing_last_name', true ) );
		$this->assertEquals( $expected_billing_address_1, get_user_meta( self::$user_id, 'billing_address_1', true ) );
		$this->assertEquals( $expected_billing_address_2, get_user_meta( self::$user_id, 'billing_address_2', true ) );
		$this->assertEquals( $expected_billing_company, get_user_meta( self::$user_id, 'billing_company', true ) );
		$this->assertEquals( $expected_billing_city, get_user_meta( self::$user_id, 'billing_city', true ) );
		$this->assertEquals( $expected_billing_state, get_user_meta( self::$user_id, 'billing_state', true ) );
		$this->assertEquals( $expected_billing_postcode, get_user_meta( self::$user_id, 'billing_postcode', true ) );
		$this->assertEquals( $expected_billing_country, get_user_meta( self::$user_id, 'billing_country', true ) );
		$this->assertEquals( $expected_billing_phone, get_user_meta( self::$user_id, 'billing_phone', true ) );

	}

	/**
	 * Test shipping information has been added as the billing information on user meta
	 */
	public function test_new_customer_meta_shipping() {
		$expected_shipping_first_name = 'John';
		$expected_shipping_last_name  = 'Doe';
		$expected_shipping_address_1  = '1 First Avenue';
		$expected_shipping_address_2  = '2 Second Avenue';
		$expected_shipping_city       = 'Brisbane';
		$expected_shipping_state      = 'Queensland';
		$expected_shipping_postcode   = '4109';
		$expected_shipping_country    = 'Australia';

		// Run a bunch of assertions to check shipping details on user meta
		$this->assertEquals( $expected_shipping_first_name, get_user_meta( self::$user_id, 'shipping_first_name', true ) );
		$this->assertEquals( $expected_shipping_last_name, get_user_meta( self::$user_id, 'shipping_last_name', true ) );
		$this->assertEquals( $expected_shipping_address_1, get_user_meta( self::$user_id, 'shipping_address_1', true ) );
		$this->assertEquals( $expected_shipping_address_2, get_user_meta( self::$user_id, 'shipping_address_2', true ) );
		$this->assertEquals( $expected_shipping_country, get_user_meta( self::$user_id, 'shipping_country', true ) );
		$this->assertEquals( $expected_shipping_city, get_user_meta( self::$user_id, 'shipping_city', true ) );
		$this->assertEquals( $expected_shipping_state, get_user_meta( self::$user_id, 'shipping_state', true ) );
		$this->assertEquals( $expected_shipping_postcode, get_user_meta( self::$user_id, 'shipping_postcode', true ) );
	}

}