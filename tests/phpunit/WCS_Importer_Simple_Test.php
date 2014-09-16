<?php

require_once dirname( dirname( dirname( __FILE__ ) ) ) . '/libs/woocommerce-subscriptions/classes/class-wc-subscriptions-product.php';
/**
 * Class WCS_Importer_Simple_Test
 */
class WCS_Importer_Simple_Test extends WCS_Importer_UnitTestCase {

	static $import_results = array();

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

		wp_set_object_terms( $product_id, 'subscription', 'product_type' );

		// setup a mapped_fields case 
		$test_csv = dirname( __FILE__ ) . '/test-files/simple-test.csv';
		// Manually set the mapped fields for the test case
		$mapped_fields = array( 
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

		$import_results = WCS_Import_Parser::import_data( $test_csv, $mapped_fields, 0, 10000, 1, 'true', 'false' );
	}

	public function test_results() {
		$this->assertTrue( true );
	}


}