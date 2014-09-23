
<?php

/**
 * Class WCS_Importer_Fail_Product_Test - A test class testing all variations of importing a csv with important product information missing.
 */
class WCS_Importer_Fail_Product_Test extends WCS_Importer_UnitTestCase {

	protected $mapped_fields = array(
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
			'shipping_company'							=> '',
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
			'_wc_authorize_net_cim_payment_profile_id' 	=> 'wc_authorize_net_cim_payment_profile_id',
			'_wc_authorize_net_cim_customer_profile_id' => 'wc_authorize_net_cim_customer_profile_id',
		);

	/**
	 * Test that the errors received consist of a product_id that doesn't exist
	 * @since 1.0.0
	 */
	public function test_product_not_exists() {
		// test file
		$test_csv = dirname( __FILE__ ) . '/test-files/no-product-exists-test.csv';
		$this->mapped_fields['product_id'] = 'product_id';

		// the case where the subscription product in the csv doesn't exist in the store.
		$import_results = WCS_Import_Parser::import_data( $test_csv, $this->mapped_fields, 0, 3000, 1, 'false', 'false' );
		$import_results = $import_results[0];

		// check resulting status
		$expected_status = 'failed';
		$this->assertEquals( $expected_status, $import_results['status'] );

		// check that result values weren't set
		$this->assertFalse( isset( $import_results['order_id'] ) );
		$this->assertFalse( isset( $import_results['edit_order_linkder_id'] ) );
		$this->assertFalse( isset( $import_results['subscription_status'] ) );
		$this->assertFalse( isset( $import_results['item_id'] ) );
		$this->assertFalse( isset( $import_results['edit_post_link'] ) );

		// check the error array returned for a missing subscription product message
		$error_message_found = false;
		$expected_message = 'The product_id is not a subscription product in your store.';
		foreach( $import_results['error'] as $error ) {
			if ( $error == $expected_message ) {
				$error_message_found = true;
			}
		}
		$this->assertTrue( $error_message_found );
	}

	/**
	 * Test that the errors received consist of a product_id that doesn't exist when the column
	 * is not given in the CSV.
	 * @since 1.0.0
	 */
	public function test_missing_product_column() {
		// test file
		$test_csv = dirname( __FILE__ ) . '/test-files/missing-product-column.csv';

		// running the case where the product column in the csv doesn't exist.
		$import_results = WCS_Import_Parser::import_data( $test_csv, $this->mapped_fields, 0, 3000, 1, 'false', 'false' );
		$import_results = $import_results[0];

		// check resulting status
		$expected_status = 'failed';
		$this->assertEquals( $expected_status, $import_results['status'] );

		// check that result values weren't set
		$this->assertFalse( isset( $import_results['order_id'] ) );
		$this->assertFalse( isset( $import_results['edit_order_linkder_id'] ) );
		$this->assertFalse( isset( $import_results['subscription_status'] ) );
		$this->assertFalse( isset( $import_results['item_id'] ) );
		$this->assertFalse( isset( $import_results['edit_post_link'] ) );

		// check the error array returned for a missing subscription product message
		$error_message_found = false;
		$expected_message = 'The product_id is not a subscription product in your store.';
		foreach( $import_results['error'] as $error ) {
			if ( $error == $expected_message ) {
				$error_message_found = true;
			}
		}
		$this->assertTrue( $error_message_found );
	}

	/**
	 * Create a variable subscription without any variations and try to use the variable_subscription id's to import.
	 * The import should fail.
	 * @since 1.0.0
	 */
	public function test_variable_subscription_incorrect_id() {
		// test file
		$test_csv = dirname( __FILE__ ) . '/test-files/incorrect-variation_id.csv';
		$this->mapped_fields['product_id'] = 'product_id';
		//create the product as a variable subscription with out any variations
		$variable_id = wp_insert_post( array( 
			'post_type' 				=> 'product',
			'post_author' 				=> 1,
			'post_title' 				=> 'Simple Test Subscription',
			'post_status' 				=> 'publish',
			'comment_status' 			=> 'open',
			'ping_status' 				=> 'closed',
			'post_name' 				=> 'simple-subscription-example',
			'filter'					=> 'raw',
		));

		update_post_meta( $variable_id, '_subscription_price', 10 );
		update_post_meta( $variable_id, '_subscription_period', 'month' );
		update_post_meta( $variable_id, '_subscription_period_interval', 1 );
		update_post_meta( $variable_id, '_subscription_length', 0 );

		wp_set_object_terms( $variable_id, 'variable-subscription', 'product_type' );

		$import_results = WCS_Import_Parser::import_data( $test_csv, $this->mapped_fields, 0, 3000, 1, 'false', 'false' );
		$import_results = $import_results[0];

		// check resulting status
		$expected_status = 'failed';
		$this->assertEquals( $expected_status, $import_results['status'] );

		// check that result values weren't set
		$this->assertFalse( isset( $import_results['order_id'] ) );
		$this->assertFalse( isset( $import_results['edit_order_linkder_id'] ) );
		$this->assertFalse( isset( $import_results['subscription_status'] ) );
		$this->assertFalse( isset( $import_results['item_id'] ) );
		$this->assertFalse( isset( $import_results['edit_post_link'] ) );

		// check the error array returned for a missing subscription product message
		$error_message_found = false;
		$expected_message = 'The product_id is not a subscription product in your store.';
		foreach( $import_results['error'] as $error ) {
			if ( $error == $expected_message ) {
				$error_message_found = true;
			}
		}
		$this->assertTrue( $error_message_found );
	}

	/**
	 * Clean slate before each test is ran.
	 * @since 1.0.0
	 */
	 function tearDown() {
	 	// as some test cases need to set this array value and some need it to be left empty we should clear it if it's set
	 	if( ! empty( $this->mapped_fields['product_id'] ) ) {
	 		$this->mapped_fields['product_id'] = '';
	 	}
	 	// clear the usual WCS_Import_Parser static variables
		WCS_Import_Parser::$results = array();
		WCS_Import_Parser::$result = array();
	}

}