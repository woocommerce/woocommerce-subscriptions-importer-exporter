<?php 
global $woocommerce;

class WCS_Import_Parser {
	var $delimiter;
	var $mapping = array();
	var $results = array();
	var $start_pos;
	var $end_pos;
	var $available_gateways;

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
					$address = ( ! empty( $this->mapping['customer_address'] ) ) ? $row[$this->mapping['customer_address']] : '';
					$status = ( ! empty( $this->mapping['status'] ) ) ? $row[$this->mapping['status']] : 'pending';
					$start_date = ( ! empty( $this->mapping['start_date'] ) ) ? $row[$this->mapping['start_date']] : '';
					$expiry_date = ( ! empty( $this->mapping['expiry_date'] ) ) ? $row[$this->mapping['expiry_date']] : '';
					$end_date = ( ! empty( $this->mapping['end_date'] ) ) ? $row[$this->mapping['expiry_date']] : '';

					// will move to just sending $row instead of listing all these variables
					$this->import( $product_id, $cust_id, $email, $username, $address, $status, $start_date, $expiry_date, $end_date, $row );
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
	function import($prod, $cust, $email, $user, $addr, $status, $start, $expiry, $end, $row ) {
		global $woocommerce;

		$postmeta = array();
		$subscription = array();
		// Check Product id a woocommerce product
		if( ! ( $this->check_product( $prod ) ) ) {
			$subscription['error_product'] = __( 'The product_id is not a subscription product in your store.', 'wcs_import' );
		}

		// Check customer id is valid or create one
		$cust_id = $this->check_customer( $cust, $email, $user , $addr );
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
					$postmeta[] = array( 'key' => '_' . $column, 'value' => 'free_shipping' );
					break;
				case 'payment_method':
					if( strtolower( $row[$this->mapping[$column]] ) == 'paypal' && ! empty( $row[$this->mapping['paypal_subscriber_id']] ) ) {
						// Paypal
					} else if( strtolower( $row[$this->mapping[$column]] ) == 'stripe' && ! empty( $row[$this->mapping['stripe_customer_id']] ) ) {
						// Stripe
					} else { // default to manual payment regardless
						$postmeta[] = array( 'key' => '_wcs_requires_manual_renewal', 'value' => 'true' );
					}
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

		// Attach information on each order to the results array
		array_push( $this->results, $order_id ); // Test the data correctly adds to the array and is printed to the console
	}

	/* Check the product is a woocommerce subscription - an error status will show up on table if this is not the case. */
	function check_product( $product_id ) {
		$is_subscription = WC_Subscriptions_Product::is_subscription( $product_id );
		return ( empty( $is_subscription) ) ? false : true;
	}

	/* Checks customer information and creates a new store customer when no customer Id has been given */
	function check_customer( $customer_id, $email, $username, $address ) {
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
				update_user_meta( $found_customer, 'billing_email', $meta_value );
			}
			return $found_customer;
		} else {
			// check customer id
			$found_customer = get_user_by( 'id', $customer_id );
			return $found_customer;
		}
	}
}
?>
