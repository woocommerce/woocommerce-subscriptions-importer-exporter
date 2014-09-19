
<?php

require_once dirname( dirname( dirname( __FILE__ ) ) ) . '/libs/woocommerce-subscriptions/classes/class-wc-subscriptions-product.php';
/**
 * Class WCS_Importer_Fail_Product_Test - A test class testing all variations of importing a csv with important product information missing.
 */
class WCS_Importer_Fail_Product_Test extends WCS_Importer_UnitTestCase {

	static $mapped_fields = array();

	public function setUp() {
		// Manually set the mapped fields for the test case
		self::$mapped_fields = array(
			'custom_user_meta'							=> array(),
			'custom_order_meta'							=> array(),
			'custom_user_order_meta'					=> array(),
			'customer_id' 						   		=> '',
			'customer_username' 				   		=> '',
			'customer_password'					   		=> '',
			'billing_email' 					   		=> '',
			'billing_phone' 					   		=> '',
			'billing_company'					   		=> '',
			'shipping_first_name' 				   		=> '',
			'shipping_last_name' 				   		=> '',
			'shipping_address_2' 				   		=> '',
			'subscription_trial_expiry_date'	   		=> '',
			'recurring_line_total' 				   		=> '',
			'recurring_line_tax' 				   		=> '',
			'recurring_line_subtotal' 			   		=> '',
			'recurring_line_subtotal_tax'		   		=> '',
			'line_total' 						   		=> '',
			'line_tax' 							   		=> '',
			'line_subtotal' 					   		=> '',
			'line_subtotal_tax' 				   		=> '',
			'order_discount' 					   		=> '',
			'cart_discount' 					   		=> '',
			'order_shipping_tax' 				   		=> '',
			'order_shipping'					   		=> '',
			'order_tax'							   		=> '',
			'order_total' 						 		=> '',
			'order_recurring_total'				 		=> '',
			'stripe_customer_id'				  		=> '',
			'paypal_subscriber_id'				  		=> '',
			'download_permission_granted'		   		=> '',
			'product_id'								=> '',
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
	}

	/**
	 * 
	 */
	public function test_product_not_exists() {
		// test file
		$test_csv = dirname( __FILE__ ) . '/test-files/no-product-exists-test.csv';

		// running the case where the product in the csv doesn't exist.
		$import_results = WCS_Import_Parser::import_data( $test_csv, self::$mapped_fields, 0, 10000, 1, 'false', 'false' );
	}

	/**
	 *
	 */
	public function test_product_id_missing_from_csv() {
		$test_csv = dirname( __FILE__ ) . '/test-files/missing-product-column.csv';

		// running the case where the product in the csv doesn't exist.
		$import_results = WCS_Import_Parser::import_data( $test_csv, self::$mapped_fields, 0, 10000, 1, 'false', 'false' );

	}

	/**
	 *
	 */
	public function test_incorrect_variation_id() {
		// Create a new variable subscription product to test on
		/*$product_id = wp_insert_post( array( 
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

		wp_set_object_terms( $product_id, 'subscription', 'product_type' );*/

		// set the test csv file
		$test_csv = dirname( __FILE__ ) . '/test-files/incorrect-variation_id.csv';

		// running the case where the product in the csv doesn't exist.
		$import_results = WCS_Import_Parser::import_data( $test_csv, self::$mapped_fields, 0, 10000, 1, 'false', 'false' );
	}

}