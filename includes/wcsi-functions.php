<?php

/**
 * Format the data from CSV
 *
 * @since 1.0
 * @param string $data
 * @param string $file_encoding
 */
function wcsi_format_data( $data, $file_encoding = 'UTF-8' ) {
	// Check if the function utf8_encode exists. The function is not found if the php-xml extension is not installed on the server.
	$return = function_exists('utf8_encode') ? utf8_encode( $data ) : $data;
	return ( 'UTF-8' == $file_encoding ) ? $data : $return;
}

/**
 * Checks customer information and creates a new store customer when no customer id has been given
 *
 * @since 1.0
 * @param array $data
 * @param array $mapped_fields
 * @param bool $test_mode
 */
function wcsi_check_customer( $data, $mapped_fields, $test_mode = false, $email_customer = false ) {
	$customer_email = ( ! empty( $data[ $mapped_fields['customer_email'] ] ) ) ? $data[ $mapped_fields['customer_email'] ] : '';
	$username       = ( ! empty( $data[ $mapped_fields['customer_username'] ] ) ) ? $data[ $mapped_fields['customer_username'] ] : '';
	$customer_id    = ( ! empty( $data[ $mapped_fields['customer_id'] ] ) ) ? $data[ $mapped_fields['customer_id'] ] : '';

	if ( ! empty( $data[ $mapped_fields['customer_password'] ] ) ) {
		$password           = $data[ $mapped_fields['customer_password'] ];
		$password_generated = false;
	} else {
		$password           = wp_generate_password( 12, true );
		$password_generated = true;
	}

	$found_customer = false;

	if ( empty( $customer_id ) ) {

		if ( is_email( $customer_email ) && false !== email_exists( $customer_email ) ) {
			$found_customer = email_exists( $customer_email );
		} elseif ( ! empty( $username ) && false !== username_exists( $username ) ) {
			$found_customer = username_exists( $username );
		} elseif ( is_email( $customer_email ) ) {

			// In test mode, we just want to know if a user account can be created - as we have a valid email address, it can be.
			if ( $test_mode ) {

				$found_customer = true;
			} else {

				// Not in test mode, create a user account for this email
				if ( empty( $username ) ) {

					$maybe_username = explode( '@', $customer_email );
					$maybe_username = sanitize_user( $maybe_username[0] );
					$counter        = 1;
					$username       = $maybe_username;

					while ( username_exists( $username ) ) {
						$username = $maybe_username . $counter;
						$counter++;
					}
				}

				$found_customer = wp_create_user( $username, $password, $customer_email );

				if ( ! is_wp_error( $found_customer ) ) {

					// update user meta data
					foreach ( WCS_Importer::$user_meta_fields as $key ) {
						switch ( $key ) {
							case 'billing_email':
								// user billing email if set in csv otherwise use the user's account email
								$meta_value = ( ! empty( $data[ $mapped_fields[ $key ] ] ) ) ? $data[ $mapped_fields[ $key ] ] : $customer_email;
								update_user_meta( $found_customer, $key, $meta_value );
								break;

							case 'billing_first_name':
								$meta_value = ( ! empty( $data[ $mapped_fields[ $key ] ] ) ) ? $data[ $mapped_fields[ $key ] ] : $username;
								update_user_meta( $found_customer, $key, $meta_value );
								update_user_meta( $found_customer, 'first_name', $meta_value );
								break;

							case 'billing_last_name':
								$meta_value = ( ! empty( $data[ $mapped_fields[ $key ] ] ) ) ? $data[ $mapped_fields[ $key ] ] : '';

								update_user_meta( $found_customer, $key, $meta_value );
								update_user_meta( $found_customer, 'last_name', $meta_value );
								break;

							case 'shipping_first_name':
							case 'shipping_last_name':
							case 'shipping_address_1':
							case 'shipping_address_2':
							case 'shipping_city':
							case 'shipping_postcode':
							case 'shipping_state':
							case 'shipping_country':
								// Set the shipping address fields to match the billing fields if not specified in CSV
								$meta_value = ( ! empty( $data[ $mapped_fields[ $key ] ] ) ) ? $data[ $mapped_fields[ $key ] ] : '';

								if ( empty( $meta_value ) ) {
									$n_key      = str_replace( 'shipping', 'billing', $key );
									$meta_value = ( ! empty( $data[ $mapped_fields[ $n_key ] ] ) ) ? $data[ $mapped_fields[ $n_key ] ] : '';
								}

								update_user_meta( $found_customer, $key, $meta_value );
								break;

							default:
								$meta_value = ( ! empty( $data[ $mapped_fields[ $key ] ] ) ) ? $data[ $mapped_fields[ $key ] ] : '';
								update_user_meta( $found_customer, $key, $meta_value );
						}
					}

					wcs_make_user_active( $found_customer );

					// send user registration email if admin as chosen to do so
					if ( $email_customer && function_exists( 'wp_new_user_notification' ) ) {

						$previous_option = get_option( 'woocommerce_registration_generate_password' );

						// force the option value so that the password will appear in the email
						update_option( 'woocommerce_registration_generate_password', 'yes' );

						do_action( 'woocommerce_created_customer', $found_customer, array( 'user_pass' => $password ), true );

						update_option( 'woocommerce_registration_generate_password', $previous_option );
					}
				}
			}
		}
	} else {
		$user = get_user_by( 'id', $customer_id );

		if ( ! empty( $user ) && ! is_wp_error( $user ) ) {
			$found_customer = absint( $customer_id );

		} else {
			$found_customer = new WP_Error( 'wcsi_invalid_customer', sprintf( __( 'User with ID (#%s) does not exist.', 'wcs-import-export' ), $customer_id ) );
		}
	}

	return $found_customer;
}
