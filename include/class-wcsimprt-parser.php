<?php 
global $woocommerce;

class WCS_Import_Parser {
	var $delimiter;
	var $mapping = array();
	var $results = array();
	var $start_pos;
	var $end_pos;
	var $starting_row_number;

	function __construct() {
		// Order meta values
		$this->order_meta_fields = array(
			"order_shipping",
			"order_shipping_tax",
			"order_tax",
			"cart_discount",
			"order_discount",
			"order_total",
			"order_recurring_total",
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
			"recurring_line_total",
			"recurring_line_tax",
			"recurring_line_subtotal",
			"recurring_line_subtotal_tax",
			"line_subtotal",
			"line_total",
			"line_tax",
			"line_subtotal_tax",
		);
	}

	function import_data( $file, $delimiter, $mapping, $start, $end, $starting_row_num ) {
		global $woocommerce;
		$file_path = addslashes( $file );
		$this->delimiter = $delimiter;
		$this->mapping = $mapping;
		$this->start_pos = $start;
		$this->end_pos = $end;
		$this->starting_row_number = $starting_row_num;

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

					// will move to just sending $row instead of listing all these variables
					$this->starting_row_number++;
					$this->import( $row );
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
	function import( $row ) {
		global $woocommerce;

		$postmeta = array();
		$subscription = array();
		$subscription['warning'] = $subscription['error'] = array();
		// Check Product id a woocommerce product
		if( ! ( $this->check_product( $row[$this->mapping['product_id']] ) ) ) {
			$subscription['error'][] = __( 'The product_id is not a subscription product in your store.', 'wcs_import' );
		}

		// Check customer id is valid or create one
		$cust_id = $this->check_customer( $row );
		if ( empty( $cust_id ) ) {
			// Error with customer information in line of csv
			$subscription['error'][] = __( 'An error occurred with the customer information provided.', ' wcs_import' );
		} else {
			$customer = get_user_by( 'id', $cust_id );
			$subscription['user_id'] = $customer->ID;
			$subscription['username'] = $customer->user_login;
		}

		// skip importing rows without the required information
		if( ! empty( $subscription['error'] ) ) {
			$subscription['status'] = __( 'failed', 'wcs_import' );
			$subscription['row_number'] = $this->starting_row_number;
			array_push($this->results, $subscription);
			return;
		}

		// Get product object - checked validity @ L141
		$_product = get_product( $row[$this->mapping['product_id']] );
		$subscription['item'] = __( $_product->get_title(), 'wcs_import' );

		$missing_ship_addr = $missing_bill_addr = array();

		// populate order meta data
		foreach( $this->order_meta_fields as $column ) {
			switch( $column ) {
				case 'shipping_method':
					$method = ( ! empty( $row[$this->mapping['shipping_method']] ) ) ? $row[$this->mapping['shipping_method']] : '';
					$title = ( ! empty( $row[$this->mapping['shipping_method_title']] ) ) ? $row[$this->mapping['shipping_method_title']] : '';
					$postmeta[] = array( 'key' => '_' . $column, 'value' => $method );
					$postmeta[] = array( 'key' => '_shipping_method_title', 'value' => $title );
					if( empty( $method ) || empty( $title ) ) {
						// set up warning message to show admin -  Do i need be more specific??
						$subscription['warning'][] = __( 'Shipping method and/or title for the order has been set to empty. ', 'wcs_import' );
					}
					break;
				case 'payment_method':
					if( strtolower( $row[$this->mapping[$column]] ) == 'paypal' && ! empty( $row[$this->mapping['paypal_subscriber_id']] ) ) {
						// Paypal
						$paypal_sub_id = $row[$this->mapping['paypal_subscriber_id']];
						$title = ( ! empty( $row[$this->mapping['payment_method_title']] ) ) ? $row[$this->mapping['payment_method_title']] : '';
						$postmeta[] = array( 'key' => '_' . $column, 'value' => 'paypal' );
						$postmeta[] = array( 'key' => '_payment_method_title', 'value' => $title );
						$postmeta[] = array( 'key' => '_recurring_payment_method', 'value' => 'paypal' );
						$postmeta[] = array( 'key' => '_recurring_payment_method_title', 'value' => 'Paypal Transfer' );
						$postmeta[] = array( 'key' => '_paypal_subscriber_id', 'value' => $paypal_sub_id );
					} else if( strtolower( $row[$this->mapping[$column]] ) == 'stripe' && ! empty( $row[$this->mapping['stripe_customer_id']] ) ) {
						// Stripe
						$stripe_cust_id = $row[$this->mapping['stripe_customer_id']];
						$title = ( ! empty( $row[$this->mapping['payment_method_title']] ) ) ? $row[$this->mapping['payment_method_title']] : '';
						// $stripe_cust_id will be checked before this point to make sure it's not null
						$postmeta[] = array( 'key' => '_' . $column, 'value' => 'stripe' );
						$postmeta[] = array( 'key' => '_payment_method_title', 'value' => $title );
						$postmeta[] = array( 'key' => '_recurring_payment_method', 'value' => 'stripe' );
						$postmeta[] = array( 'key' => '_recurring_payment_method_title', 'value' => 'Stripe Transfer' );
						$postmeta[] = array( 'key' => '_stripe_customer_id', 'value' => $stripe_cust_id );
					} else { // default to manual payment regardless
						$postmeta[] = array( 'key' => '_wcs_requires_manual_renewal', 'value' => 'true' );
						$subscription['warning'][] = __( 'No recognisable payment method has been specified therefore default payment method being used. ', 'wcs_import' );
					}
					break;
				case 'shipping_addresss_1':
				case 'shipping_city':
				case 'shipping_postcode':
				case 'shipping_state':
				case 'shipping_country':
					$value = ( ! empty( $row[$this->mapping[$column]] ) ) ? $row[$this->mapping[$column]] : '';
					if( empty( $value ) ) {
						$metadata = get_user_meta( $cust_id, $column );
						$value = ( ! empty( $metadata[0] ) ) ? $metadata[0] : '';
					}
					if( empty( $value ) ) {
						$missing_ship_addr[] = $column;
					}
					$postmeta[] = array( 'key' => '_' . $column, 'value' => $value);
					break;
				case 'billing_addresss_1':
				case 'billing_city':
				case 'billing_postcode':
				case 'billing_state':
				case 'billing_country':
					$value = ( ! empty( $row[$this->mapping[$column]] ) ) ? $row[$this->mapping[$column]] : '';
					if( empty( $value ) ) {
						$metadata = get_user_meta( $cust_id, $column );
						$value = ( ! empty( $metadata[0] ) ) ? $metadata[0] : '';
					}
					if( empty( $value ) ) {
						$missing_bill_addr[] = $column;
					}
					$postmeta[] = array( 'key' => '_' . $column, 'value' => $value);
					break;
				case 'customer_user':
					$postmeta[] = array( 'key' => '_' . $column, 'value' => $cust_id);
					break;
				case 'order_total':
				case 'order_recurring_total':
					$value = ( ! empty( $row[$this->mapping[$column]] ) ) ? $row[$this->mapping[$column]] : $_product->subscription_price;
					$postmeta[] = array( 'key' => '_' . $column, 'value' => $value );
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
		if( ! empty( $missing_ship_addr ) ) {
			$subscription['warning'][] = __( 'The following shipping address fields have been left empty: ' . rtrim(implode(', ', $missing_ship_addr), ',') . '. ', 'wcs_import');
		}
		if ( ! empty( $missing_bill_addr ) ) {
			$subscription['warning'][] = __( 'The following billing address fields have been left empty: ' . rtrim(implode(', ', $missing_bill_addr), ',') . '. ', 'wcs_import');
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

		// Add line item
		$item_id = wc_add_order_item( $order_id, array(
			'order_item_name' => $_product->get_title(),
			'order_item_type' => 'line_item'
		) );

		// Add line item meta
		if ( $item_id ) {
			wc_add_order_item_meta( $item_id, '_qty', apply_filters( 'woocommerce_stock_amount', 1 ) );
			wc_add_order_item_meta( $item_id, '_tax_class', $_product->get_tax_class() );
			wc_add_order_item_meta( $item_id, '_product_id', $_product->id );
			wc_add_order_item_meta( $item_id, '_variation_id', ( ! empty ($_product->variation_id ) ) ? $_product->variation_id : '');

			// add the additional subscription meta data to the order
			foreach( $this->order_item_meta_fields as $metadata ) {
				$value = ( ! empty( $row[$this->mapping[$metadata]] ) ) ? $row[$this->mapping[$metadata]] : 0;
				wc_add_order_item_meta( $item_id, '_' . $metadata, $value );
			}

			// Add line item meta for backorder status
			if ( $_product->backorders_require_notification() && $_product->is_on_backorder( 1 ) )
				wc_add_order_item_meta( $item_id, apply_filters( 'woocommerce_backordered_item_meta_name', __( 'Backordered', 'woocommerce' ), $cart_item_key, $order_id ), $values['quantity'] - max( 0, $_product->get_total_stock() ) );

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

			$_POST['order_id'] = $order_id;
			WC_Subscriptions_Order::prefill_order_item_meta( Array( 'product_id' => $_product->id, 'variation_id' => $_product->id ), $item_id );

			// Update the status of the subscription order
			if( ! empty( $this->mapping['subscription_status'] ) && $row[$this->mapping['subscription_status']] ) {
				WC_Subscriptions_Manager::update_users_subscriptions_for_order( $order_id, strtolower( $row[$this->mapping['subscription_status']] ) );
			} else {
				WC_Subscriptions_Manager::update_users_subscriptions_for_order( $order_id, 'pending' );
				$subscription['warning'][] = __( 'Used default subscription status as none was given. ', 'wcs_import' );
			}
		}

		$subscription['edit_order'] = admin_url( 'post.php?post=' . $order_id .'&action=edit' );
		// Check if the subscription has been successfully added
		$key = WC_Subscriptions_Manager::get_subscription_key( $order_id, $_product->id );
		$subscription_check = WC_Subscriptions_Manager::get_subscription( $key );
		if( ! empty ( $subscription_check['order_id'] ) && ! empty ( $subscription_check['product_id'] ) ) {
			// successfully added subscription
			// Attach information on each order to the results array
			$subscription['status'] = __( 'success', 'wcs_import' );
			$subscription['order'] = $subscription_check['order_id'];
			$subscription['subscription_status'] = $subscription_check['status'];
			$subscription['item_id'] = ( ! empty ( $subscription_check['variation_id'] ) ) ? $subcription_check['variation_id'] : $subscription_check['product_id'];
			array_push( $this->results, $subscription );
		} else {
			$subscription['status'] = __( 'failed', 'wcs_import' );
			array_push( $this->results, $subscription );
		}

	}

	/* Check the product is a woocommerce subscription - an error status will show up on table if this is not the case. */
	function check_product( $product_id ) {
		$is_subscription = WC_Subscriptions_Product::is_subscription( $product_id );
		return ( empty( $is_subscription) ) ? false : true;
	}

	/* Checks customer information and creates a new store customer when no customer Id has been given */
	function check_customer( $row ) {
		$customer_email = ( ! empty ( $row[$this->mapping['customer_email']] ) ) ? $row[$this->mapping['customer_email']] : '';
		$username = ( ! empty ( $row[$this->mapping['customer_username']] ) ) ? $row[$this->mapping['customer_username']] : '';
		$customer_id = ( ! empty( $row[$this->mapping['customer_id']] ) ) ? $row[$this->mapping['customer_id']] : '';

		$found_customer = false;
		if( empty( $customer_id ) ) {
			// check for registered email if customer id is not set
			if( ! $found_customer && is_email( $customer_email ) ) {
					// check by email
					$found_customer = email_exists( $customer_email );
			// if customer still not found, check by username
			}
			if( ! $found_customer && ! empty( $username ) ) {
				$found_customer = username_exists( $username );
			}

			// try creating a customer from email, username and address information
			if( ! $found_customer && is_email( $customer_email ) && ! empty( $username ) ) {
				$found_customer = wp_create_user( $username, '1234', $customer_email );
				// update user meta data
				foreach( $this->user_data_titles as $key ) {
					switch( $key ) {
						case 'billing_email':
							// user billing email if set in csv otherwise use the user's account email
							$meta_value = ( ! empty( $row[$this->mapping[$key]] ) ) ? $row[$this->mapping[$key]] : $customer_email;
							update_user_meta( $found_customer, $key, $meta_value );
							break;
						case 'billing_first_name':
							$meta_value = ( ! empty( $row[$this->mapping[$key]] ) ) ? $row[$this->mapping[$key]] : $username;
							update_user_meta( $found_customer, $key, $meta_value );
							break;
						case 'shipping_addresss_1':
						case 'shipping_address_2':
						case 'shipping_city':
						case 'shipping_postcode':
						case 'shipping_state':
						case 'shipping_country':
							// Set the shipping address fields to match the billing fields if not specified in CSV
							$meta_value = ( ! empty( $row[$this->mapping[$key]] ) ) ? $row[$this->mapping[$key]] : '';
							if( empty ( $meta_value ) ) {
								$n_key = str_replace( "shipping", "billing", $key );
								$meta_value = ( ! empty($row[ $this->mapping[$n_key]] ) ) ? $row[$this->mapping[$n_key]] : '';
							}
							update_user_meta( $found_customer, $key, $meta_value );
							break;
						default:
							$meta_value = ( ! empty( $row[$this->mapping[$key]] ) ) ? $row[$this->mapping[$key]] : '';
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
