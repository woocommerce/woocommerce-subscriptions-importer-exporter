<?php 
global $woocommerce;

class WCS_Import_Parser {
	var $delimiter;
	var $mapping = array();
	var $results = array();

	function import_data( $file, $delimiter, $mapping ) {
		$file_path = addslashes( $file );
		$this->delimiter = $delimiter;
		$this->mapping = $mapping;

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

					$this->import( $product_id, $cust_id, $email, $username, $address, $status, $start_date, $expiry_date, $end_date );
				}
			fclose( $handle );
			}
		}
	}

	function format_data_from_csv( $data, $enc ) {
		return ( $enc == 'UTF-8' ) ? $data : utf8_encode( $data );
	}

	/* Import Subscription */
	function import($prod, $cust, $email, $user, $addr, $status, $start, $expiry, $end ) {
		global $woocommerce;
		$subscription = array();
		// Check Product id a woocommerce product
		if( ! ( $this->check_product( $prod ) ) ) {
			$subscription['error_product'] = __( 'The product_id is not a subscription product in your store.', 'wcs_import' );
		}

		// Check customer id is valid or create one
		$cust_id = $this->check_customer( $customer_id, $email, $username , $address );

		if ( empty( $cust_id ) ) {
			// Error with customer information in line of csv
			$subscription['error_customer'] = __( 'An error occurred with the customer information provided.', ' wcs_import' );
		}

		// Set defaults for empty fields
		if( empty( $status ) ) {
			$status = 'pending';
		}
		// Create the subscription - magic happens here

		// Attache each subscription to the results array
		array_push( $this->results, $subscription ); // Test the data correctly adds to the array and is printed to the console
	}

	/* Check the product is a woocommerce subscription - an error status will show up on table if this is not the case. */
	function check_product( $product_id ) {
		$is_subscription = WC_Subscriptions_Product::is_subscription( $product_id );
		return ( empty( $is_subscription) ) ? false : true;
	}

	/* Checks customer information and creates a new store customer when no customer Id has been given */
	function check_customer( $customer_id, $email, $username, $address ) {
		if( empty( $customer_id ) ) {
			// try creating a customer from email, username and address information
			return 1;
		} else {
			// check customer id
			return $customer_id;
		}
	}
}
?>
