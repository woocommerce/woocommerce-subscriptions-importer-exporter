<?php 
global $woocommerce;

class WCS_Import_Parser {
	var $delimiter;
	var $mapping = array();
	var $results = array();
	var $start_pos;
	var $end_pos;
	var $available_gateways;
	var $available_shipping_methods;

	function __construct() {
		// Order meta values
		$this->order_meta_fields = array(
			"order_shipping",
			"order_shipping_tax",
			"order_tax",
			"cart_discount",
			"order_discount",
			"order_total",
			"payment_method",
			"shipping_method",
			"customer_user",
			"billing_first_name",
			"billing_last_name",
			"billing_company",
			"billing_address_1",
			"billing_address_2",
			"billing_city",
			"billing_state",
			"billing_postcode",
			"billing_country",
			"billing_email",
			"billing_phone",
			"shipping_first_name",
			"shipping_last_name",
			"shipping_company",
			"shipping_address_1",
			"shipping_address_2",
			"shipping_city",
			"shipping_state",
			"shipping_postcode",
			"shipping_country",
			"Download Permissions Granted",
		);

		// User meta data
		$this->user_data_titles = array (
			"billing_first_name",
			"billing_last_name",
			"billing_company",
			"billing_address_1",
			"billing_address_2",
			"billing_city",
			"billing_state",
			"billing_postcode",
			"billing_country",
			"billing_email",
			"billing_phone",
			"shipping_first_name",
			"shipping_last_name",
			"shipping_company",
			"shipping_address_1",
			"shipping_address_2",
			"shipping_city",
			"shipping_state",
			"shipping_postcode",
			"shipping_country",
		);

		$this->order_item_meta_fields = array (
			"_recurring_line_total",
			"_recurring_line_tax",
			"_recurring_line_subtotal",
			"_recurring_line_subtotal_tax",
		);
	}

	function import_data( $file, $delimiter, $mapping, $start, $end ) {
		global $woocommerce;
		$file_path = addslashes( $file );
		$this->delimiter = $delimiter;
		$this->mapping = $mapping;
		$this->start_pos = $start;
		$this->end_pos = $end;

		// Get the stores available payment gateways
		$this->available_gateways = $woocommerce->payment_gateways->get_available_payment_gateways();
		$this->available_shipping_methods  = $woocommerce->shipping->load_shipping_methods();

		$this->import_start( $file_path );
		return $this->results;
	}

	/* Loads the csv file contents into the class variable $this->file */
	function import_start( $file ) {
		$enc = mb_detect_encoding( $file, 'UTF-8, ISO-8859-1', true );
		if ( $enc ) setlocale( LC_ALL, 'en_US.' . $enc );
		@ini_set( 'auto_detect_line_endings', true );

		if ( $file ) {
			if ( ( $handle = fopen( $file, "r" ) ) !== FALSE ) {
				$row = array();
				$header = fgetcsv( $handle, 0, $this->delimiter );

				if( $this->start_pos != 0 ) {
					fseek( $handle, $this->start_pos );
				}

				while ( ( $postmeta = fgetcsv( $handle, 0, $this->delimiter ) ) !== false ) {
					foreach ( $header as $key => $heading ) {
						if ( ! $heading ) continue;
						$s_heading = strtolower( $heading );
						$row[$s_heading] = ( isset( $postmeta[$key] ) ) ? $this->format_data_from_csv( $postmeta[$key], $enc ) : '';
					}

					$product_id = $row[$this->mapping['product_id']];
					$cust_id = ( ! empty( $this->mapping['customer_id'] ) ) ? $row[$this->mapping['customer_id']] : '';
					$email = ( ! empty( $this->mapping['customer_email'] ) ) ? $row[$this->mapping['customer_email']] : '';
					$username = ( ! empty( $this->mapping['customer_username'] ) ) ? $row[$this->mapping['customer_username']] : '';
					$status = ( ! empty( $this->mapping['subscription_status'] ) ) ? $row[$this->mapping['subscription_status']] : 'pending';
					$start_date = ( ! empty( $this->mapping['subscription_start_date'] ) ) ? $row[$this->mapping['startsubscription_start_date_date']] : '';
					$expiry_date = ( ! empty( $this->mapping['subscription_expiry_date'] ) ) ? $row[$this->mapping['subscription_expiry_date']] : '';
					$end_date = ( ! empty( $this->mapping['subscription_end_date'] ) ) ? $row[$this->mapping['subscription_end_date']] : '';

					// will move to just sending $row instead of listing all these variables
					$this->import( $product_id, $cust_id, $email, $username, $status, $start_date, $expiry_date, $end_date, $row );
					if( ftell( $handle ) >= $this->end_pos ) {
						break;
					}
				}
			fclose( $handle );
			}
		}
	}

	function format_data_from_csv( $data, $enc ) {
		return ( $enc == 'UTF-8' ) ? $data : utf8_encode( $data );
	}

	/* Import Subscription */
	function import($prod, $cust, $email, $user, $status, $start, $expiry, $end, $row ) {
		global $woocommerce;

		$postmeta = array();
		$subscription = array();
		// Check Product id a woocommerce product
		if( ! ( $this->check_product( $prod ) ) ) {
			$subscription['error_product'] = __( 'The product_id is not a subscription product in your store.', 'wcs_import' );
		}

		// Check customer id is valid or create one
		$cust_id = $this->check_customer( $cust, $email, $user, $row );
		if ( empty( $cust_id ) ) {
			// Error with customer information in line of csv
			$subscription['error_customer'] = __( 'An error occurred with the customer information provided.', ' wcs_import' );
		}

		// skip importing rows without the required information
		if( ! empty( $subscription['error_customer'] ) || ! empty( $subscription['error_product'] ) ) {
			array_push($this->results, $subscription);
			return;
		}

		// populate order meta data
		foreach( $this->order_meta_fields as $column ) {
			switch( $column ) {
				case 'shipping_method':
					$method = ( ! empty( $row[$this->mapping['shipping_method']] ) ) ? $row[$this->mapping['shipping_method']] : '';
					$title = ( ! empty( $row[$this->mapping['shipping_method_title']] ) ) ? $row[$this->mapping['shipping_method_title']] : '';
					$postmeta[] = array( 'key' => '_' . $column, 'value' => $method );
					$postmeta[] = array( 'key' => '_shipping_method_title', 'value' => $title );
					if( empty( $method ) || empty( $title ) ) {
						// set up warning message to show admin
					}
					break;
				case 'payment_method':
					if( strtolower( $row[$this->mapping[$column]] ) == 'paypal' && ! empty( $row[$this->mapping['paypal_subscriber_id']] ) ) {
						// Paypal
					} else if( strtolower( $row[$this->mapping[$column]] ) == 'stripe' && ! empty( $row[$this->mapping['stripe_customer_id']] ) ) {
						// Stripe
						$stripe_cust_id = $row[$this->mapping['stripe_customer_id']];
						$title = ( ! empty( $row[$this->mapping['payment_method_title']] ) ) ? $row[$this->mapping['payment_method_title']] : '';
						// $stripe_cust_id will be checked before this point to make sure it's not null
						$postmeta[] = array( 'key' => '_' . $column, 'value' => 'stripe' );
						$postmeta[] = array( 'key' => '_payment_method_title', 'value' => $title );
						$postmeta[] = array( 'key' => '_recurring_payment_method', 'value' => 'stripe' );
						$postmeta[] = array( 'key' => '_recurring_payment_method_title', 'value' => 'Electronic Transfer' );
						$postmeta[] = array( 'key' => '_stripe_customer_id', 'value' => $stripe_cust_id );
					} else { // default to manual payment regardless
						$postmeta[] = array( 'key' => '_wcs_requires_manual_renewal', 'value' => 'true' );
					}
					break;
				case 'customer_user':
					$postmeta[] = array( 'key' => '_' . $column, 'value' => $cust_id);
					break;
				default:
					$value = isset( $row[$this->mapping[$column]] ) ? $row[$this->mapping[$column]] : '';
					if( empty( $value ) ) {
						$metadata = get_user_meta( $cust_id, $column );
						$value = ( ! empty( $metadata[0] ) ) ? $metadata[0] : '';
					}
					$postmeta[] = array( 'key' => '_' . $column, 'value' => $value);
			}
		}

		$order_data = array(
				'post_date'     => date( 'Y-m-d H:i:s', time() ),
				'post_type'     => 'shop_order',
				'post_title'    => 'Order &ndash; ' . date( 'F j, Y @ h:i A', time() ),
				'post_status'   => 'publish',
				'ping_status'   => 'closed',
				'post_author'   => 1,
				'post_password' => uniqid( 'order_' ),  // Protects the post just in case
		);

		$order_id = wp_insert_post( $order_data );

		foreach ( $postmeta as $meta ) {
			update_post_meta( $order_id, $meta['key'], $meta['value'] );

			if ( '_customer_user' == $meta['key'] && $meta['value'] ) {
				update_user_meta( $meta['value'], "paying_customer", 1 );
			}
		}

		// Add product to the order
		$_product = get_product( $prod );

		// Add line item
		$item_id = woocommerce_add_order_item( $order_id, array(
			'order_item_name' => $_product->get_title(),
			'order_item_type' => 'line_item'
		) );

		// Add line item meta
		if ( $item_id ) {
			woocommerce_add_order_item_meta( $item_id, '_qty', apply_filters( 'woocommerce_stock_amount', 1 ) );
			woocommerce_add_order_item_meta( $item_id, '_tax_class', $_product->get_tax_class() );
			woocommerce_add_order_item_meta( $item_id, '_product_id', $_product->id );
			woocommerce_add_order_item_meta( $item_id, '_variation_id', '');
			woocommerce_add_order_item_meta( $item_id, '_line_subtotal', '' );
			woocommerce_add_order_item_meta( $item_id, '_line_total', '' );
			woocommerce_add_order_item_meta( $item_id, '_line_tax', '' );
			woocommerce_add_order_item_meta( $item_id, '_line_subtotal_tax', '' );

			// add the additional subscription meta data to the order
			foreach( $this->order_item_meta_fields as $metadata ) {
				$value = ( ! empty( $row[$this->mapping[$metadata]] ) ) ? $row[$this->mapping[$metadata]] : 0;
				woocommerce_add_order_item_meta( $item_id, $metadata, $value );
			}

			// Store variation data in meta so admin can view it
			/*if ( $values['variation'] && is_array( $values['variation'] ) )
				foreach ( $values['variation'] as $key => $value )
				woocommerce_add_order_item_meta( $item_id, esc_attr( str_replace( 'attribute_', '', $key ) ), $value );*/

			// Add line item meta for backorder status
			if ( $_product->backorders_require_notification() && $_product->is_on_backorder( 1 ) )
				woocommerce_add_order_item_meta( $item_id, apply_filters( 'woocommerce_backordered_item_meta_name', __( 'Backordered', 'woocommerce' ), $cart_item_key, $order_id ), $values['quantity'] - max( 0, $_product->get_total_stock() ) );

			// Update the subscription meta data with values in $subscription_meta
			$subscription_meta = array (
					'start_date' 	=> ( ! empty( $row[$this->mapping['subscription_start_date']] ) ) ? $row[$this->mapping['subscription_start_date']] : '',
					'expiry_date'	=> ( ! empty( $row[$this->mapping['subscription_expiry_date']] ) ) ? $row[$this->mapping['subscription_expiry_date']] : '',
					'end_date'		=> ( ! empty( $row[$this->mapping['subscription_end_date']] ) ) ? $row[$this->mapping['subscription_end_date']] : '',
			);
			// Create pening Subscription
			WC_Subscriptions_Manager::create_pending_subscription_for_order( $order_id, $_product->id, $subscription_meta );
			// Add additional subscription meta data
			$sub_key = WC_Subscriptions_Manager::get_subscription_key( $order_id, $_product->id );
			WC_Subscriptions_Manager::update_subscription( $sub_key, $subscription_meta );

			// Update the status of the subscription order
			if( ! empty( $this->mapping['subscription_status'] ) && $row[$this->mapping['subscription_status']] ) {
				WC_Subscriptions_Manager::update_users_subscriptions_for_order( $order_id, strtolower( $row[$this->mapping['subscription_status']] ) );
			} else {
				WC_Subscriptions_Manager::update_users_subscriptions_for_order( $order_id, 'pending' );
			}
		}

		// Check if the subscription has been successfully added
		$key = WC_Subscriptions_Manager::get_subscription_key( $order_id, $_product->id );
		$subscription_check = WC_Subscriptions_Manager::get_subscription( $key );
		if( ! empty ( $subscription_check['order_id'] ) ) {
			// successfully added subscription
			// Attach information on each order to the results array
			array_push( $this->results, $subscription );
		}

	}

	/* Check the product is a woocommerce subscription - an error status will show up on table if this is not the case. */
	function check_product( $product_id ) {
		$is_subscription = WC_Subscriptions_Product::is_subscription( $product_id );
		return ( empty( $is_subscription) ) ? false : true;
	}

	/* Checks customer information and creates a new store customer when no customer Id has been given */
	function check_customer( $customer_id, $email, $username, $row ) {
		$found_customer = false;
		if( empty( $customer_id ) ) {
			// check for registered email if customer id is not set
			if( ! $found_customer && is_email( $email ) ) {
					// check by email
					$found_customer = email_exists( $email );
			// if customer still not found, check by username
			} elseif( ! $found_customer && ! empty( $username ) ) {
				$found_customer = username_exists( $username );
			}

			// try creating a customer from email, username and address information
			if( ! $found_customer && is_email( $email ) && ! empty( $username ) ) {
				$found_customer = wp_create_user( $username, '1234', $email );
				// update user meta data
				foreach( $this->user_data_titles as $key ) {
					switch( $key ) {
						case 'billing_email':
							// user billing email if set in csv otherwise use the user's account email
							$meta_value = ( ! empty( $this->mapping[$key] ) ) ? $row[$this->mapping[$key]] : $email;
							update_user_meta( $found_customer, $key, $meta_value );
							break;
						default:
							$meta_value = ( ! empty( $this->mapping[$key] ) ) ? $row[$this->mapping[$key]] : '';
							update_user_meta( $found_customer, $key, $meta_value );
					}
				}
			}
			return $found_customer;
		} else {
			// check customer id
			$found_customer = get_user_by( 'id', $customer_id );
			if( ! empty( $found_customer ) ) {
				return absint( $customer_id );
			}
			return $found_customer; // should be false
		}
	}
}
?>
