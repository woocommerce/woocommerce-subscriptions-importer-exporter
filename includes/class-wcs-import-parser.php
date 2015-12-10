<?php 

class WCS_Import_Parser {

	private static $results = array();

	/* The current row number of CSV */
	private static $row_number;

	/* Front-end import settings chosen */
	public static $test_mode;
	public static $email_customer;

	/* Specifically for the shutdown handler */
	public static $fields = array();
	public static $row    = array();

	/** Subscription post meta */
	public static $order_meta_fields = array(
		'order_shipping',
		'order_shipping_tax',
		'order_tax',
		'cart_discount',
		'cart_discount_tax',
		'order_discount',
		'order_total',
		'payment_method',
		'shipping_method',

		'billing_first_name', // Billing Address Info
		'billing_last_name',
		'billing_company',
		'billing_address_1',
		'billing_address_2',
		'billing_city',
		'billing_state',
		'billing_postcode',
		'billing_country',
		'billing_email',
		'billing_phone',

		'shipping_first_name', // Shipping Address Info
		'shipping_last_name',
		'shipping_company',
		'shipping_address_1',
		'shipping_address_2',
		'shipping_city',
		'shipping_state',
		'shipping_postcode',
		'shipping_country',
	);

	/* Subscription line item meta */
	public static $order_item_meta_fields = array (
		'line_subtotal',
		'line_total',
		'line_tax',
		'line_subtotal_tax',
	);

	/**
	 * Setup function for the import parse class
	 *
	 * @since 1.0
	 * @param array $data
	 */
	public static function import_data( $data ) {
		$file_path = addslashes( $data['file_path'] );

		self::$row_number      = $data['starting_row'];
		self::$test_mode       = ( $data['test_mode'] == 'true' ) ? true : false;
		self::$email_customer  = ( $data['email_customer'] == 'true' ) ? true : false;
		self::$fields          = $data['mapped_fields'];


		self::import_start( $file_path, $data['file_start'], $data['file_end'] );


		return self::$results;
	}

	/**
	 * Loads the csv file contents and starts the import
	 *
	 * @since 1.0
	 * @param string $file_path
	 * @param int $start_position
	 * @param int $end_position
	 */
	public static function import_start( $file_path, $start_position, $end_position ) {

		$file_encoding = mb_detect_encoding( $file_path, 'UTF-8, ISO-8859-1', true );

		if ( $file_encoding ) {
			setlocale( LC_ALL, 'en_US.' . $file_encoding );
		}

		@ini_set( 'auto_detect_line_endings', true );

		if ( $file_path ) {
			if ( ( $file_handle = fopen( $file_path, 'r' ) ) !== FALSE ) {
				$data = array();
				$column_headers = fgetcsv( $file_handle, 0 );

				if ( $start_position != 0 ) {
					fseek( $file_handle, $start_position );
				}

				while ( ( $csv_row = fgetcsv( $file_handle, 0 ) ) !== false ) {

					foreach ( $column_headers as $key => $header ) {
						if ( ! $header ) {
							continue;
						}
						$data[ $header ] = ( isset( $csv_row[ $key ] ) ) ? trim( wcsi_format_data( $csv_row[ $key ], $file_encoding ) ) : '';
					}

					self::$row_number++;
					self::import_subscription( $data );

					if( ftell( $file_handle ) >= $end_position ) {
						break;
					}
				}
				fclose( $file_handle );
			}
		}
	}

	/**
	 * Create a new subscription and attach all relevant meta given the data in the CSV.
	 * This function will also create a user if enough valid information is given and there's no
	 *
	 * @since 1.0
	 * @param array $data
	 */
	private static function import_subscription( $data ) {

		$set_manual      = false;
		$product_id      = absint( $data[ self::$fields['product_id'] ] );
		$result          = array();

		$post_meta = $result['warning'] = $result['error'] = array();
		$result['row_number']           = self::$row_number;

		if ( empty( self::$fields['product_id'] ) || ! ( wcsi_check_product( $product_id ) ) ) {
			$result['error'][] = esc_html__( 'The product_id is not a subscription product in your store.', 'wcs-importer' );
		}

		$user_id = wcsi_check_customer( $data, self::$fields, self::$test_mode );

		if ( is_wp_error( $user_id ) ) {
			$result['error'][] = $user_id->get_error_message();

		} elseif ( empty( $user_id ) ) {
			$result['error'][] = esc_html__( 'An error occurred with the customer information provided.', 'wcs-importer' );

		} elseif ( ! self::$test_mode ) {
			$result['username'] = sprintf( '<a href="%s">%s</a>', get_edit_user_link( $user_id ), self::get_user_display_name( $user_id ) );
		}

		if ( ! empty( $result['error'] ) ) {
			$result['status'] = 'failed';

			array_push( self::$results, $result );
			return;
		}

		$_product       = wc_get_product( $product_id );
		$quantity       = ( ! empty( $data[ self::$fields['quantity'] ] ) ) ? $data[ self::$fields['quantity'] ] : 1;
		$result['item'] = sprintf( '<a href="%s">%s</a>', get_edit_post_link( $_product->id ), $_product->get_title() );

		$missing_shipping_addresses = $missing_billing_addresses = array();

		foreach( self::$order_meta_fields as $column ) {
			switch( $column ) {
				case 'shipping_method':
					$method = ( ! empty( $data[ self::$fields['shipping_method'] ] ) ) ? $data[ self::$fields['shipping_method'] ] : '';
					$title  = ( ! empty( $data[ self::$fields['shipping_method_title'] ] ) ) ? $data[ self::$fields['shipping_method_title'] ] : $method;

					$post_meta[] = array( 'key' => '_' . $column, 'value' => $method );
					$post_meta[] = array( 'key' => '_shipping_method_title', 'value' => $title );

					if( empty( $method ) || empty( $title ) ) {
						$result['warning'][] = __( 'Shipping method and title for the subscription have been left as empty. ', 'wcs-importer' );
					}
					break;

				case 'order_shipping':
					$value       = ( ! empty( $data[ self::$fields[ $column ] ] ) ) ? $data[ self::$fields[ $column ] ] : 0;
					$post_meta[] = array( 'key' => '_' . $column, 'value' => $value );
					break;

				case 'order_shipping_tax':
					$value       = ( ! empty( $data[ self::$fields[ $column ] ] ) ) ? $data[ self::$fields[ $column ] ] : 0;
					$post_meta[] = array( 'key' => '_' . $column, 'value' => $value );
					break;

				case 'payment_method':
					$payment_method = ( ! empty( $data[ self::$fields[ $column ] ] ) ) ? strtolower( $data[ self::$fields[ $column ] ] ) : '';
					$title          = ( ! empty( $data[ self::$fields['payment_method_title'] ] ) ) ? $data[ self::$fields['payment_method_title'] ] : $payment_method;

					if ( ! empty( $payment_method ) && 'manual' != $payment_method ) {
						$post_meta[] = array( 'key' => '_' . $column, 'value' => $payment_method );
						$post_meta[] = array( 'key' => '_payment_method_title', 'value' => $title );
					} else {
						$set_manual = true;
					}
					break;

				case 'shipping_address_1':
				case 'shipping_city':
				case 'shipping_postcode':
				case 'shipping_state':
				case 'shipping_country':
					$value = ( ! empty( $data[ self::$fields[ $column ] ] ) ) ? $data[ self::$fields[ $column ] ] : '';

					if ( empty( $value ) ) {
						$metadata = get_user_meta( $user_id, $column );
						$value    = ( ! empty( $metadata[0] ) ) ? $metadata[0] : '';
					}

					if ( empty( $value ) ) {
						$missing_shipping_addresses[] = $column;
					}

					$post_meta[] = array( 'key' => '_' . $column, 'value' => $value );
					break;

				case 'billing_address_1':
				case 'billing_city':
				case 'billing_postcode':
				case 'billing_state':
				case 'billing_country':
					$value = ( ! empty( $data[ self::$fields[ $column ] ] ) ) ? $data[ self::$fields[ $column ] ] : '';

					if ( empty( $value ) ) {
						$metadata = get_user_meta( $user_id, $column );
						$value = ( ! empty( $metadata[0] ) ) ? $metadata[0] : '';
					}

					if ( empty( $value ) ) {
						$missing_billing_addresses[] = $column;
					}

					$post_meta[] = array( 'key' => '_' . $column, 'value' => $value );
					break;

				case 'order_total':
					if ( ! empty( $data[ self::$fields[ $column ] ] ) ) {
						$value = $data[ self::$fields[ $column ] ];
					} elseif ( ! empty( $data[ self::$fields[ 'line_total' ] ] ) ) {
						$value = $data[ self::$fields[ 'line_total' ] ];
					} else {
						$value = $quantity * $_product->subscription_price;
					}

					$post_meta[] = array( 'key' => '_' . $column, 'value' => $value );
					break;

				default:
					$value       = ( ! empty( $data[ self::$fields[ $column ] ] ) ) ? $data[ self::$fields[ $column ] ] : '';
					$post_meta[] = array( 'key' => '_' . $column, 'value' => $value );
			}
		}


			}
		}

		if ( empty( $data[ self::$fields['status'] ] ) ) {
			$status              = 'pending';
			$result['warning'][] = esc_html__( 'No subscription status was specified. The subscription will be created with the status "pending". ', 'wcs-importer' );
		} else {
			$status = $data[ self::$fields['status'] ];
		}

		$dates_to_update = array( 'start' => ( ! empty( $data[ self::$fields['start_date'] ] ) ) ? gmdate( 'Y-m-d H:i:s', strtotime( $data[ self::$fields['start_date'] ] ) ) : gmdate( 'Y-m-d H:i:s', time() - 1 ) );

		foreach ( array( 'trial_end_date', 'next_payment_date', 'end_date', 'last_payment_date' ) as $date_type ) {
			$dates_to_update[ $date_type ] = ( ! empty( $data[ self::$fields[ $date_type ] ] ) ) ? gmdate( 'Y-m-d H:i:s', strtotime( $data[ self::$fields[ $date_type ] ] ) ) : '';
		}

		foreach ( $dates_to_update as $date_type => $datetime ) {

			if ( empty( $datetime ) ) {
				continue;
			}

			switch ( $date_type ) {
				case 'end_date' :
					if ( ! empty( $dates_to_update['last_payment_date'] ) && strtotime( $datetime ) <= strtotime( $dates_to_update['last_payment_date'] ) ) {
						$result['error'][] = sprintf( __( 'The %s date must occur after the last payment date.', 'wcs-importer' ), $date_type );
					}

					if ( ! empty( $dates_to_update['next_payment_date'] ) && strtotime( $datetime ) <= strtotime( $dates_to_update['next_payment_date'] ) ) {
						$result['error'][] = sprintf( __( 'The %s date must occur after the next payment date.', 'wcs-importer' ), $date_type );
					}
				case 'next_payment_date' :
					if ( ! empty( $dates_to_update['trial_end_date'] ) && strtotime( $datetime ) < strtotime( $dates_to_update['trial_end_date'] ) ) {
						$result['error'][] = sprintf( __( 'The %s date must occur after the trial end date.', 'wcs-importer' ), $date_type );
					}
				case 'trial_end_date' :
					if ( strtotime( $datetime ) <= strtotime( $dates_to_update['start'] ) ) {
						$result['error'][] = sprintf( __( 'The %s must occur after the start date.', 'wcs-importer' ), $date_type );
					}
			}
		}

		if ( ! self::$test_mode ) {

			if ( empty( $result['error'] ) ) {
				try {
					$wpdb->query( 'START TRANSACTION' );

					// add custom user meta before subscription is created
					foreach ( self::$fields['custom_user_meta'] as $meta_key ) {
						if ( ! empty( $data[ $meta_key ] ) ) {
							update_user_meta( $user_id, $meta_key, $data[ $meta_key ] );
						}
					}

					$subscription = wcs_create_subscription( array(
							'customer_id'      => $user_id,
							'start_date'       => $dates_to_update['start'],
							'billing_interval' => ( ! empty( $data[ self::$fields['billing_interval'] ] ) ) ? $data[ self::$fields['billing_interval'] ] : WC_Subscriptions_Product::get_interval( $_product ),
							'billing_period'   => ( ! empty( $data[ self::$fields['billing_period'] ] ) ) ? $data[ self::$fields['billing_period'] ] : WC_Subscriptions_Product::get_period( $_product ),
						)
					);

					if ( is_wp_error( $subscription ) ) {
						throw new Exception( sprintf( esc_html__( 'Could not create subscription: %s', 'wcs-importer' ), $subscription->get_message() ) );

					} else {

						update_post_meta( $subscription->id, '_created_with_wcs_importer', WCS_Importer::$version );

						foreach ( $post_meta as $meta_data ) {
							update_post_meta( $subscription->id, $meta_data['key'], $meta_data['value'] );
						}

						foreach ( self::$fields['custom_post_meta'] as $meta_key ) {
							if ( ! empty( $data[ $meta_key ] ) ) {
								update_post_meta( $subscription->id, $meta_key, $data[ $meta_key ] );
							}
						}

						foreach ( self::$fields['custom_user_post_meta'] as $meta_key ) {
							if ( ! empty( $data[ $meta_key ] ) ) {
								update_post_meta( $subscription->id, $meta_key, $data[ $meta_key ] );
								update_user_meta( $user_id, $meta_key, $data[ $meta_key ] );
							}
						}

						$item_args        = array();
						$item_args['qty'] = $quantity;

						foreach ( array( 'total', 'tax', 'subtotal', 'subtotal_tax' ) as $line_item_data ) {

							switch ( $line_item_data ) {
								case 'subtotal' :
								case 'total' :
									$default = WC_Subscriptions_Product::get_price( $product_id );
									break;
								default :
									$default = 0;
							}
							$item_args['totals'][ $line_item_data ] = ( ! empty( $data[ self::$fields[ 'line_' . $line_item_data ] ] ) ) ? $data[ self::$fields[ 'line_' . $line_item_data ] ] : $default;
						}

						if ( $_product->variation_data ) {
							$item_args['variation'] = array();

							foreach ( $_product->variation_data as $attribute => $variation ) {
								$item_args['variation'][ $attribute ] = $variation;
							}
							$result['item'] .= ' [#' . $product_id . ']';
						}

						$item_id = $subscription->add_product( $_product, $quantity, $item_args );

						if ( ! $item_id ) {
							throw new Exception( sprintf( esc_html__( 'An unexpected error occurred when trying to add product "%s" to your subscription. The error was caught and no subscription for this row will be created. Please fix up the data from your CSV and try again.', 'wcs-importer' ), $result['item'] ) );
						}

						$subscription->update_dates( $dates_to_update );
						$subscription->update_status( $status );

						if ( $set_manual ) {
							$subscription->update_manual();
						} elseif ( ! $subscription->has_status( wcs_get_subscription_ended_statuses() ) ) { // don't bother trying to set payment meta on a subscription that won't ever renew
							$result['warning'] = array_merge( $result['warning'], self::set_payment_meta( $subscription, $data ) );
						}

						if ( ! empty( $data[ self::$fields['download_permission_granted'] ] ) && 'true' == $data[ self::$fields['download_permission_granted'] ] ) {
							self::save_download_permissions( $subscription, $_product, $quantity );
						}

					}

					$wpdb->query( 'COMMIT' );

				} catch ( Exception $e ) {
					$wpdb->query( 'ROLLBACK' );
					$result['error'][] = $e->getMessage();
				}
			}

			if ( empty( $result['error'] ) ) {

				$result['status']              = 'success';
				$result['subscription']        = sprintf( '<a href="%s">#%s</a>', esc_url( admin_url( 'post.php?post=' . absint( $subscription->id ) . '&action=edit' ) ), $subscription->get_order_number() );
				$result['subscription_status'] = $subscription->get_status();

				array_push( self::$results, $result );
			} else {

				$result['status']  = 'failed';
				self::log( sprintf( 'Row #%s failed: %s', $result['row_number'], print_r( $result['error'], true ) ) );

				array_push( self::$results, $result );
			}

		} else {
			array_push( self::$results, $result );
		}
	}

	/**
	 * Get the display name for the given user. Uses the first name and last name or falls back to the display name.
	 *
	 * @since 1.0
	 * @param WP_User|int $customer
	 */
	public static function get_user_display_name( $customer ) {

		if ( ! is_object( $customer ) ) {
			$customer = get_userdata( $customer );
		}

		$username = '';

		if ( false !== $customer ) {
			$username  = '<a href="user-edit.php?user_id=' . absint( $customer->ID ) . '">';

			if ( $customer->first_name || $customer->last_name ) {
				$username .= esc_html( ucfirst( $customer->first_name ) . ' ' . ucfirst( $customer->last_name ) );
			} else {
				$username .= esc_html( ucfirst( $customer->display_name ) );
			}

			$username .= '</a>';

		}
		return $username;
	}
}

?>
