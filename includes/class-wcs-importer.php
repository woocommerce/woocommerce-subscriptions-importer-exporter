<?php
/**
 * The main importer class.
 *
 * This class reads a number of lines from the CSV file and imports the subscriptions based on the data
 * in those rows. All errors and unexpected PHP shutdowns will be logged to assist in debugging.
 *
 * @since 1.0
 */
class WCS_Importer {

	public static $results = array();

	/* The current row number of CSV */
	public static $row_number;

	public static $membership_plans = null;
	public static $all_virtual      = true;

	/* Front-end import settings chosen */
	public static $test_mode;
	public static $email_customer;
	public static $add_memberships;

	/* Specifically for the shutdown handler */
	public static $fields = array();
	public static $row    = array();

	/** Subscription post meta */
	public static $order_totals_fields = array(
		'order_shipping',
		'order_shipping_tax',
		'cart_discount',
		'cart_discount_tax',
		'order_total',
		'order_tax',
	);

	public static $user_meta_fields = array(
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

	private static $order_meta_setter_map = [
		'cart_discount'      => 'discount_total',
		'cart_discount_tax'  => 'discount_tax',
		'customer_user'      => 'customer_id',
		'order_tax'          => 'cart_tax',
		'order_shipping'     => 'shipping_total',
		'order_currency'     => 'currency',
		'order_shipping_tax' => 'shipping_tax',
		'order_total'        => 'total',
	];

	/**
	 * Setup function for the import parse class
	 *
	 * @since 1.0
	 * @param array $data
	 */
	public static function import_data( $data ) {
		$file_path = addslashes( $data['file_path'] );

		self::$row_number      = $data['starting_row'];
		self::$test_mode       = ( 'true' == $data['test_mode'] ) ? true : false;
		self::$email_customer  = ( 'true' == $data['email_customer'] ) ? true : false;
		self::$add_memberships = ( 'true' == $data['add_memberships'] ) ? true : false;
		self::$fields          = $data['mapped_fields'];

		add_action( 'shutdown', 'WCS_Import_Logger::shutdown_handler' );

		self::import_start( $file_path, $data['file_start'], $data['file_end'] );

		self::add_order_key_post_meta_if_missing();

		remove_action( 'shutdown', 'WCS_Import_Logger::shutdown_handler' );

		return self::$results;
	}

	/**
	 * Looks for subscriptions whose order_key post meta is missing or NULL or empty in the database
	 * and generates order_key for them
	 *
	 * @since 2.1
	 */
	public static function add_order_key_post_meta_if_missing() {
		global $wpdb;

		if ( ! wcsi_is_hpos_enabled() ) {
			// Get the post_ids of the subscriptions whose order_key post meta is NULL or empty or missing
			$subscription_ids_needing_order_key = $wpdb->get_col( "
				SELECT ID FROM {$wpdb->prefix}posts WHERE
					post_type = 'shop_subscription'
						AND
					ID NOT IN (
						SELECT post_id FROM {$wpdb->prefix}postmeta WHERE
							meta_key = '_order_key'
								AND
							meta_value IS NOT NULL
								AND
							meta_value <> ''
					)
						AND
					post_status IN ( '" . implode( "','", array_keys( wcs_get_subscription_statuses() ) ) . "' )"
			);
		} else {
			$subscription_ids_needing_order_key = wc_get_orders(
				array(
					'type'      => 'shop_subscription',
					'field_query' => array(
						array(
							'relation' => 'or',
							array(
								'field' => 'order_key',
								'value' => 'NOT EXISTS',
							),
							array(
								'field' => 'order_key',
								'value' => '',
							),
						)
					),
					'status'    => array_keys( wcs_get_subscription_statuses() ),
					'return'    => 'ids',
					'limit'     => -1,
				)
			);
		}

		//Set the order_key post meta for each of them
		foreach( $subscription_ids_needing_order_key as $order_id ) {
			$order = wc_get_order( $order_id );
			$order->set_order_key( wc_generate_order_key() );
			$order->save();
		}
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

		// Check if the function mb_detect_encoding exists. The function does not found if the mbstring extension is not installed on the server.
		$file_encoding = function_exists('mb_detect_encoding') ? mb_detect_encoding( $file_path, 'UTF-8, ISO-8859-1', true ) : false;

		if ( $file_encoding ) {
			setlocale( LC_ALL, 'en_US.' . $file_encoding );
		}

		@ini_set( 'auto_detect_line_endings', true );

		if ( $file_path ) {
			if ( ( $file_handle = fopen( $file_path, 'r' ) ) !== false ) {
				$data = array();
				$column_headers = fgetcsv( $file_handle, 0 );

				if ( 0 != $start_position ) {
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

					if ( ftell( $file_handle ) >= $end_position ) {
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
	 * user existing
	 *
	 * @since 1.0
	 * @param array $data
	 */
	public static function import_subscription( $data ) {
		global $wpdb;

		self::$row  = $data;
		$set_manual = $requires_manual_renewal = false;
		$order_meta = $order_items = array();
		$result     = array(
			'warning'    => array(),
			'error'      => array(),
			'items'      => '',
			'row_number' => self::$row_number,
		);

		$user_id = wcsi_check_customer( $data, self::$fields, self::$test_mode, self::$email_customer );

		if ( is_wp_error( $user_id ) ) {
			$result['error'][] = $user_id->get_error_message();

		} elseif ( empty( $user_id ) ) {
			$result['error'][] = esc_html__( 'An error occurred with the customer information provided.', 'wcs-import-export' );

		} elseif ( ! self::$test_mode ) {
			$result['username'] = sprintf( '<a href="%s">%s</a>', get_edit_user_link( $user_id ), self::get_user_display_name( $user_id ) );
		}

		if ( ! empty( $result['error'] ) ) {
			$result['status'] = 'failed';
			WCS_Import_Logger::log( sprintf( 'Row #%s failed: %s', $result['row_number'], print_r( $result['error'], true ) ) );

			array_push( self::$results, $result );
			return;
		}

		$missing_shipping_addresses = $missing_billing_addresses = array();

		foreach ( array_merge( self::$order_totals_fields, self::$user_meta_fields, array( 'payment_method' ) ) as $column ) {
			switch ( $column ) {
				case 'cart_discount':
				case 'cart_discount_tax':
				case 'order_shipping':
				case 'order_shipping_tax':
				case 'order_total':
					$order_meta[ $column ] = ( ! empty( $data[ self::$fields[ $column ] ] ) ) ? $data[ self::$fields[ $column ] ] : 0;
					break;
				case 'payment_method':
					$payment_method = ( ! empty( $data[ self::$fields[ $column ] ] ) ) ? strtolower( $data[ self::$fields[ $column ] ] ) : '';

					if ( ! empty( $payment_method ) && 'manual' != $payment_method ) {
						$order_meta[ $column ]                = $payment_method;
						$order_meta[ 'payment_method_title' ] = ( ! empty( $data[ self::$fields['payment_method_title'] ] ) ) ? $data[ self::$fields['payment_method_title'] ] : $payment_method;
					} else {
						$set_manual = true;
					}

					if ( ! empty( $data[ self::$fields['requires_manual_renewal'] ] ) && 'true' == $data[ self::$fields['requires_manual_renewal'] ] ) {
						$requires_manual_renewal = true;
					}
					break;
				case 'shipping_address_1':
				case 'shipping_city':
				case 'shipping_postcode':
				case 'shipping_state':
				case 'shipping_country':
				case 'billing_address_1':
				case 'billing_city':
				case 'billing_postcode':
				case 'billing_state':
				case 'billing_country':
				case 'billing_phone':
				case 'billing_company':
				case 'billing_email':
					$value = ( ! empty( $data[ self::$fields[ $column ] ] ) ) ? $data[ self::$fields[ $column ] ] : '';

					if ( empty( $value ) ) {
						$metadata = get_user_meta( $user_id, $column );
						$value    = ( ! empty( $metadata[0] ) ) ? $metadata[0] : '';
					}

					if ( empty( $value ) && 'billing_email' == $column ) {
						$value = ( ! empty( $data[ self::$fields['customer_email'] ] ) ) ? $data[ self::$fields['customer_email'] ] : get_userdata( $user_id )->user_email;
					}

					if ( empty( $value ) ) {
						if ( 0 === strpos( $column, 'billing_' ) ) {
							$missing_billing_addresses[] = $column;
						} else {
							$missing_shipping_addresses[] = $column;
						}
					}

					$order_meta[ $column ] = $value;
					break;

				default:
					$order_meta[ $column ] = ( ! empty( $data[ self::$fields[ $column ] ] ) ) ? $data[ self::$fields[ $column ] ] : '';
			}
		}

		$status = 'pending';
		if ( empty( $data[ self::$fields['subscription_status'] ] ) ) {
			$result['warning'][] = esc_html__( 'No subscription status was specified. The subscription will be created with the status "pending". ', 'wcs-import-export' );
		} else {
			$status = ( 'wc-' === substr( $data[ self::$fields['subscription_status'] ], 0, 3 ) ) ? substr( $data[ self::$fields['subscription_status'] ], 3 ) : $data[ self::$fields['subscription_status'] ];
		}

		$dates_to_update = array( 'start' => ( ! empty( $data[ self::$fields['start_date'] ] ) ) ? gmdate( 'Y-m-d H:i:s', strtotime( $data[ self::$fields['start_date'] ] ) ) : gmdate( 'Y-m-d H:i:s', time() - 1 ) );

		foreach ( array( 'trial_end_date', 'next_payment_date', 'cancelled_date', 'end_date', 'last_payment_date' ) as $date_type ) {
			$dates_to_update[ $date_type ] = ( ! empty( $data[ self::$fields[ $date_type ] ] ) ) ? gmdate( 'Y-m-d H:i:s', strtotime( $data[ self::$fields[ $date_type ] ] ) ) : '';
		}

		foreach ( $dates_to_update as $date_type => $datetime ) {

			if ( empty( $datetime ) ) {
				continue;
			}

			switch ( $date_type ) {
				case 'cancelled_date':
					if ( 'cancelled_date' === $date_type && ! in_array( $status, wcs_get_subscription_ended_statuses() ) ) {
						$result['error'][] = sprintf( __( 'Cannot set a %s date for an active subscription.', 'wcs-import-export' ), $date_type );
					}
				case 'end_date' :
					if ( 'end_date' === $date_type && ! empty( $dates_to_update['next_payment_date'] ) && strtotime( $datetime ) <= strtotime( $dates_to_update['next_payment_date'] ) ) {
						$result['error'][] = sprintf( __( 'The %s date must occur after the next payment date.', 'wcs-import-export' ), $date_type );
					}
					if ( 'end_date' === $date_type && ! empty( $dates_to_update['cancelled_date'] ) && strtotime( $datetime ) <= strtotime( $dates_to_update['cancelled_date'] ) ) {
						$result['error'][] = sprintf( __( 'The %s date must occur after the cancelled date.', 'wcs-import-export' ), $date_type );
					}

					if ( 'end_date' === $date_type && empty( $dates_to_update['cancelled_date'] ) ) {
						$dates_to_update['cancelled_date'] = $datetime;
						ksort( $dates_to_update ); // Sort so that `cancelled_date` is processed before `end_date`, otherwise updating dates will trigger a fatal.
						$result['warning'][] = sprintf( __( 'Setting %1$s date requires a cancelled date. Setting the cancelled date to %2$s.', 'wcs-import-export' ), $date_type, $datetime );
					}
				case 'next_payment_date' :
					if ( 'next_payment_date' === $date_type && ! empty( $dates_to_update['trial_end_date'] ) && strtotime( $datetime ) < strtotime( $dates_to_update['trial_end_date'] ) ) {
						$result['error'][] = sprintf( __( 'The %s date must occur after the trial end date.', 'wcs-import-export' ), $date_type );
					}
				case 'trial_end_date' :
					if ( 'trial_end_date' === $date_type && strtotime( $datetime ) <= strtotime( $dates_to_update['start'] ) ) {
						$result['error'][] = sprintf( __( 'The %s must occur after the start date.', 'wcs-import-export' ), $date_type );
					}
			}
		}

		// make the sure end of prepaid term exists for subscription that are about to be set to pending-cancellation - continue to use the next payment date if that exists
		if ( 'pending-cancel' == $status && ( empty( $dates_to_update['next_payment_date'] ) || strtotime( $dates_to_update['next_payment_date'] ) < current_time( 'timestamp', true ) ) ) {
			if ( ! empty( $dates_to_update['end_date'] ) && strtotime( $dates_to_update['end_date'] ) > current_time( 'timestamp', true ) ) {
				$dates_to_update['next_payment_date'] = $dates_to_update['end_date'];
				unset( $dates_to_update['end_date'] );
			} else {
				$result['error'][] = __( 'Importing a pending cancelled subscription requires an end date in the future.', 'wcs-import-export' );
			}
		}

		if ( empty( $result['error'] ) || self::$test_mode ) {
			try {
				if ( ! self::$test_mode ) {
					$wpdb->query( 'START TRANSACTION' );

					// add custom user meta before subscription is created
					foreach ( self::$fields['custom_user_meta'] as $meta_key ) {
						if ( ! empty( $data[ $meta_key ] ) ) {
							update_user_meta( $user_id, $meta_key, $data[ $meta_key ] );
						}
					}

					add_filter( 'woocommerce_can_subscription_be_updated_to_cancelled', '__return_true' );
					add_filter( 'woocommerce_can_subscription_be_updated_to_pending-cancel', '__return_true' );

					$subscription = wcs_create_subscription( array(
							'customer_id'      => $user_id,
							'start_date'       => $dates_to_update['start'],
							'billing_interval' => ( ! empty( $data[ self::$fields['billing_interval'] ] ) ) ? $data[ self::$fields['billing_interval'] ] : 1,
							'billing_period'   => ( ! empty( $data[ self::$fields['billing_period'] ] ) ) ? $data[ self::$fields['billing_period'] ] : '',
							'created_via'      => 'importer',
							'customer_note'    => ( ! empty( $data[ self::$fields['customer_note'] ] ) ) ? $data[ self::$fields['customer_note'] ] : '',
							'currency'         => ( ! empty( $data[ self::$fields['order_currency'] ] ) ) ? $data[ self::$fields['order_currency'] ] : '',
							'status'           => $status,
						)
					);

					remove_filter( 'woocommerce_can_subscription_be_updated_to_cancelled', '__return_true' );
					remove_filter( 'woocommerce_can_subscription_be_updated_to_pending-cancel', '__return_true' );

					if ( is_wp_error( $subscription ) ) {
						throw new Exception( sprintf( esc_html__( 'Could not create subscription: %s', 'wcs-import-export' ), $subscription->get_error_message() ) );
					}

					$subscription_id = version_compare( WC()->version, '3.0', '>=' ) ? $subscription->get_id() : $subscription->id;

					foreach ( $order_meta as $key => $value ) {
						$subscription->{'set_' . ( self::$order_meta_setter_map[ $key ] ?? $key )}( $value );
					}

					foreach ( self::$fields['custom_post_meta'] as $meta_key ) {
						if ( ! empty( $data[ $meta_key ] ) ) {
							$subscription->update_meta_data( $meta_key, $data[ $meta_key ] );
						}
					}

					foreach ( self::$fields['custom_user_post_meta'] as $meta_key ) {
						if ( ! empty( $data[ $meta_key ] ) ) {
							$subscription->update_meta_data( $meta_key, $data[ $meta_key ] );
							update_user_meta( $user_id, $meta_key, $data[ $meta_key ] );
						}
					}

					$subscription->save();

					// Now that we've set all the meta data, reinit the object so the data is set
					$subscription = wcs_get_subscription( $subscription_id );

					// Set dates.
					$subscription->update_dates( $dates_to_update );

					if ( ! $set_manual && ! in_array( $status, wcs_get_subscription_ended_statuses() ) ) { // don't bother trying to set payment meta on a subscription that won't ever renew
						$result['warning'] = array_merge( $result['warning'], self::set_payment_meta( $subscription, $data ) );
					}

					if ( $set_manual || $requires_manual_renewal ) {
						$subscription->set_requires_manual_renewal( true );
					}

					if ( ! empty( $data[ self::$fields['order_notes'] ] ) ) {
						$order_notes = explode( ';', $data[ self::$fields['order_notes'] ] );

						foreach ( $order_notes as $order_note ) {
							$subscription->add_order_note( $order_note );
						}
					}
				} else {
					$subscription = null;
					$subscription_id = 0;
				}

				if ( $set_manual ) {
					$result['warning'][] = esc_html__( 'No payment method was given in CSV and so the subscription has been set to manual renewal.', 'wcs-import-export' );
				} else if ( $requires_manual_renewal ) {
					$result['warning'][] = esc_html__( 'Import forced manual renewal.', 'wcs-import-export' );
				}

				if ( ! empty( $data[ self::$fields['coupon_items'] ] ) ) {
					self::add_coupons( $subscription, $data );
				}

				$chosen_tax_rate_id = 0;
				if ( ! empty( $data[ self::$fields['tax_items'] ] ) ) {
					$chosen_tax_rate_id = self::add_taxes( $subscription, $data );
				}

				if ( ! empty( $data[ self::$fields['order_items'] ] ) ) {
					if ( is_numeric( $data[ self::$fields['order_items'] ] ) ) {
						$product_id      = absint( $data[ self::$fields['order_items'] ] );
						$result['items'] = self::add_product( $subscription, array( 'product_id' => $product_id ), $chosen_tax_rate_id );
						$order_items[]   = $product_id;
					} else {
						$order_items_row = explode( ';', $data[ self::$fields['order_items'] ] );

						if ( ! empty( $order_items_row ) ) {
							foreach ( $order_items_row as $order_item ) {
								$item_data = array();

								foreach ( explode( '|', $order_item ) as $item ) {
									list( $name, $value ) = explode( ':', $item );
									$item_data[ trim( $name ) ] = trim( $value );
								}

								$result['items'] .= self::add_product( $subscription, $item_data, $chosen_tax_rate_id ) . '<br/>';
								$order_items[]    = $item_data['product_id'];
							}
						}
					}
				}

				if ( ! empty( $data[ self::$fields['fee_items'] ] ) ) {
					self::add_fees( $subscription, $data, $chosen_tax_rate_id );
				}

				if ( ! empty( $data[ self::$fields['shipping_method'] ] ) ) {
					$shipping_method = self::add_shipping_lines( $subscription, $data, $chosen_tax_rate_id );
				}

				// only show the following warnings on the import when the subscription requires shipping
				if ( ! self::$all_virtual ) {
					if ( ! empty( $missing_shipping_addresses ) ) {
						$result['warning'][] = sprintf( esc_html__( 'The following shipping address fields have been left empty: %s. ', 'wcs-import-export' ), rtrim( implode( ', ', $missing_shipping_addresses ), ',' ) );
					}

					if ( ! empty( $missing_billing_addresses ) ) {
						$result['warning'][] = sprintf( esc_html__( 'The following billing address fields have been left empty: %s. ', 'wcs-import-export' ), rtrim( implode( ', ', $missing_billing_addresses ), ',' ) );
					}

					if ( empty( $shipping_method ) ) {
						$result['warning'][] = esc_html__( 'Shipping method and title for the subscription have been left as empty. ', 'wcs-import-export' );
					}
				}

				if ( ! self::$test_mode ) {
					if ( self::$add_memberships ) {
						foreach ( $order_items as $product_id ) {
							self::maybe_add_memberships( $user_id, $subscription->get_id(), $product_id );
						}
					}

					$subscription->save();
				}

				$wpdb->query( 'COMMIT' );

			} catch ( Exception $e ) {
				$wpdb->query( 'ROLLBACK' );
				$result['error'][] = $e->getMessage();
			}
		}

		if ( ! self::$test_mode ) {

			if ( empty( $result['error'] ) ) {
				$result['status']              = 'success';
				$result['subscription']        = sprintf( '<a href="%s">#%s</a>', esc_url( admin_url( 'post.php?post=' . absint( $subscription_id ) . '&action=edit' ) ), $subscription->get_order_number() );
				$result['subscription_status'] = $subscription->get_status();

			} else {
				$result['status']  = 'failed';
				WCS_Import_Logger::log( sprintf( 'Row #%s failed: %s', $result['row_number'], print_r( $result['error'], true ) ) );
			}

			/**
			 * Action hook to allow for custom actions after a subscription has been imported and can be manipulated.
			 *
			 * @param WC_Subscription $subscription The subscription object created by the importer.
			 * @param array $result The result of the import.
			 */
			do_action( 'woocommerce_subscription_imported_via_csv', $subscription, $result );
		}

		array_push( self::$results, $result );
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

	/**
	 * Set the payment method meta on the imported subscription or on user meta
	 *
	 * @since 1.0
	 * @param WC_Subscription $subscription
	 * @param array $data Current line from the CSV
	 */
	public static function set_payment_meta( $subscription, $data ) {
		$warnings         = array();
		$payment_gateways = WC()->payment_gateways->get_available_payment_gateways();
		$subscription_id  = version_compare( WC()->version, '3.0', '>=' ) ? $subscription->get_id() : $subscription->id;
		$payment_method   = version_compare( WC()->version, '3.0', '>=' ) ? $subscription->get_payment_method() : $subscription->payment_method;

		if ( ! empty( $payment_method ) ) {
			$payment_method_table = apply_filters( 'woocommerce_subscription_payment_meta', array(), $subscription );
			$payment_gateway      = ( isset( $payment_gateways[ $payment_method ] ) ) ? $payment_gateways[ $payment_method ] : '';

			if ( ! empty( $payment_gateway ) && isset( $payment_method_table[ $payment_gateway->id ] ) ) {
				$payment_post_meta = $payment_user_meta = array();

				if ( ! empty( $data[ self::$fields['payment_method_post_meta'] ] ) ) {
					foreach ( explode( '|', $data[ self::$fields['payment_method_post_meta'] ] ) as $meta ) {
						list( $name, $value ) = explode( ':', $meta );
						$payment_post_meta[ trim( $name ) ] = trim( $value );
					}
				}

				if ( ! empty( $data[ self::$fields['payment_method_user_meta'] ] ) ) {
					foreach ( explode( '|', $data[ self::$fields['payment_method_user_meta'] ] ) as $meta ) {
						list( $name, $value ) = explode( ':', $meta );
						$payment_user_meta[ trim( $name ) ] = trim( $value );
					}
				}

				$payment_method_data = $payment_method_table[ $payment_gateway->id ];
				$meta_set            = false;

				foreach ( $payment_method_data as $meta_table => &$meta ) {
					if ( ! is_array( $meta ) ) {
						continue;
					}

					foreach ( $meta as $meta_key => &$meta_data ) {
						switch ( $meta_table ) {
							case 'post_meta':
							case 'postmeta':
								$value = ( ! empty( $payment_post_meta[ $meta_key ] ) ) ? $payment_post_meta[ $meta_key ] : '';
								break;
							case 'user_meta':
							case 'usermeta':
								$value = ( ! empty( $payment_user_meta[ $meta_key ] ) ) ? $payment_user_meta[ $meta_key ] : '';
								break;
							default :
								$value = '';
						}

						if ( ! empty( $value ) ) {
							$meta_data['value'] = $value;
							$meta_set = true;
						}
					}
				}

				if ( $meta_set ) {
					// Reload the subscription to update the meta values.
					$subscription  = wcs_get_subscription( $subscription->get_id() );
					$subscription->set_payment_method( $payment_gateway, $payment_method_data );
					$subscription->save();
				} else {
					$warnings[] = sprintf( esc_html__( 'No payment meta was set for your %1$s subscription (%2$s). The next renewal is going to fail if you leave this.', 'wcs-import-export' ), $payment_method, $subscription_id );
				}
			} else {
				if ( 'paypal' == $payment_method ) {
					$warnings[] = sprintf( esc_html__( 'Could not set payment method as PayPal, defaulted to manual renewals. Either PayPal was not enabled or your PayPal account does not have Reference Transaction setup. Learn more about enabling Reference Transactions %1$shere%2$s.', 'wcs-import-export' ), '<a href="https://support.woocommerce.com/hc/en-us/articles/205151193-PayPal-Reference-Transactions-for-Subscriptions">', '</a>' );
				} else {
					$warnings[] = sprintf( esc_html__( 'The payment method "%s" is either not enabled or does not support the new features of Subscriptions 2.0 and can not be properly attached to your subscription. This subscription has been set to manual renewals.', 'wcs-import-export' ), $payment_method );
				}
				$subscription->set_requires_manual_renewal( true );
			}
		}
		return $warnings;
	}

	/**
	 * Save download permission to the subscription.
	 *
	 * @since 1.0
	 * @param WC_Subscription $subscription
	 * @param WC_Product $product
	 * @param int $quantity
	 */
	public static function save_download_permissions( $subscription, $product, $quantity = 1 ) {

		if ( $product && $product->exists() && $product->is_downloadable() ) {
			$downloads = $product->get_downloads();

			foreach ( array_keys( $downloads ) as $download_id ) {
				wc_downloadable_file_permission( $download_id, $product->get_id(), $subscription, $quantity );
			}
		}
	}

	/**
	 * Add membership plans to imported subscriptions if applicable
	 *
	 * @since 1.0
	 * @param int $user_id
	 * @param int $subscription_id
	 * @param int $product_id
	 */
	public static function maybe_add_memberships( $user_id, $subscription_id, $product_id ) {

		if ( function_exists( 'wc_memberships_get_membership_plans' ) ) {

			if ( ! self::$membership_plans ) {
				self::$membership_plans = wc_memberships_get_membership_plans();
			}

			foreach ( self::$membership_plans as $plan ) {
				if ( $plan->has_product( $product_id ) ) {
					$plan->grant_access_from_purchase( $user_id, $product_id, $subscription_id );
				}

				// if the product is a variation we want to also check if the parent variable product has any plans as well and add them
				$product   = wc_get_product( $product_id );
				$parent_id = wcs_get_objects_property( $product, 'parent_id' );
				if ( $product && $product->is_type( 'variation' ) && ! empty( $parent_id ) && $plan->has_product( $parent_id ) ) {
					$plan->grant_access_from_purchase( $user_id, $product->parent->id, $subscription_id );
				}
			}
		}
	}

	/**
	 * Add coupon line item to the subscription. The discount amount used is based on priority list.
	 *
	 * @since 1.0
	 * @param WC_Subscription $subscription
	 * @param array $data
	 */
	public static function add_coupons( $subscription, $data ) {

		$coupon_items = explode( ';', $data[ self::$fields['coupon_items'] ] );

		if ( ! empty( $coupon_items ) ) {
			foreach ( $coupon_items as $coupon_item ) {
				$coupon_data = array();

				foreach ( explode( '|', $coupon_item ) as $item ) {
					list( $name, $value ) = explode( ':', $item );
					$coupon_data[ trim( $name ) ] = trim( $value );
				}

				$coupon_code = isset( $coupon_data['code'] ) ? $coupon_data['code'] : '';
				$coupon      = new WC_Coupon( $coupon_code );

				if ( ! $coupon ) {
					throw new Exception( sprintf( esc_html__( 'Could not find coupon with code "%s" in your store.', 'wcs-import-export' ), $coupon_code ) );
				} elseif ( isset( $coupon_data['amount'] ) ) {
					$discount_amount = floatval( $coupon_data['amount'] );
				} else {
					$discount_amount = version_compare( WC()->version, '3.0', '>=' ) ? $coupon->get_discount_amount() : $coupon->discount_amount;
				}

				if ( ! self::$test_mode ) {

					$coupon_line_item = new WC_Order_Item_Coupon();
					$coupon_line_item->set_props( array(
						'code'         => $coupon_code,
						'discount'     => $discount_amount,
						'discount_tax' => 0,
						'order_id'     => $subscription->get_id(),
					) );
					$coupon_line_item->save();
					$subscription->add_item( $coupon_line_item );
					$coupon_id =  $coupon_line_item->get_id();

					if ( ! $coupon_id ) {
						throw new Exception( sprintf( esc_html__( 'Coupon "%s" could not be added to subscription.', 'wcs-import-export' ), $coupon_code ) );
					}
				}
			}
		}
	}

	/**
	 * Adds the line item to the subscription
	 *
	 * @since 1.0
	 * @param WC_Subscription $subscription
	 * @param array $data
	 * @param int $chosen_tax_rate_id
	 * @return string
	 */
	public static function add_product( $subscription, $data, $chosen_tax_rate_id ) {
		$item_args        = array();
		$item_args['qty'] = isset( $data['quantity'] ) ? $data['quantity'] : 1;

		if ( ! isset( $data['product_id'] ) ) {
			throw new Exception( __( 'The product_id is missing from CSV.', 'wcs-import-export' ) );
		}

		$_product = wc_get_product( $data['product_id'] );

		if ( ! $_product ) {
			throw new Exception( sprintf( __( 'No product or variation in your store matches the product ID #%s.', 'wcs-import-export' ), $data['product_id'] ) );
		}

		$_product_id    = version_compare( WC()->version, '3.0', '>=' ) ? $_product->get_id() : $_product->id;
		$line_item_name = ( ! empty( $data['name'] ) ) ? $data['name'] : $_product->get_title();
		$product_string = sprintf( '<a href="%s">%s</a>', get_edit_post_link( $_product_id ), $line_item_name );

		foreach ( array( 'total', 'tax', 'subtotal', 'subtotal_tax' ) as $line_item_data ) {

			switch ( $line_item_data ) {
				case 'total' :
					$default = WC_Subscriptions_Product::get_price( $data['product_id'] );
					break;
				case 'subtotal' :
					$default = ( ! empty( $data['total'] ) ) ? $data['total'] : WC_Subscriptions_Product::get_price( $data['product_id'] );
					break;
				default :
					$default = 0;
			}
			$item_args['totals'][ $line_item_data ] = ( ! empty( $data[ $line_item_data ] ) ) ? $data[ $line_item_data ] : $default;
		}

		// Add this site's variation meta data if no line item meta data was specified in the CSV
		if ( empty( $data['meta'] ) && $_product->is_type( 'variable' ) ) {
			$_product_variation_data = version_compare( WC()->version, '3.0', '>=' ) ? $_product->get_available_variations() : $_product->variation_data;

			$item_args['variation'] = array();

			foreach ( $_product_variation_data as $attribute => $variation ) {
				$item_args['variation'][ $attribute ] = $variation;
			}
			$product_string .= ' [#' . $data['product_id'] . ']';
		}

		if ( self::$all_virtual && ! $_product->is_virtual() ) {
			self::$all_virtual = false;
		}

		if ( ! empty( $item_args['totals']['tax'] ) && ! empty( $chosen_tax_rate_id ) ) {
			$item_args['totals']['tax_data']['total']    = array( $chosen_tax_rate_id => $item_args['totals']['tax'] );
			$item_args['totals']['tax_data']['subtotal'] = array( $chosen_tax_rate_id => $item_args['totals']['tax'] );
		}

		if ( ! self::$test_mode ) {
			$item_id = $subscription->add_product( $_product, $item_args['qty'], $item_args );

			// Set the name used in the CSV if it's different to the product's current title (which is what WC_Abstract_Order::add_product() uses)
			if ( ! empty( $data['name'] ) && $_product->get_title() != $data['name'] ) {
				wc_update_order_item( $item_id, array( 'order_item_name' => $data['name'] ) );
			}

			// Add any meta data for the line item
			if ( ! empty( $data['meta'] ) ) {
				foreach ( explode( '+', $data['meta'] ) as $meta ) {
					$meta = explode( '=', $meta );
					wc_update_order_item_meta( $item_id, $meta[0], $meta[1] );
				}
			}

			if ( ! $item_id ) {
				throw new Exception( __( 'An unexpected error occurred when trying to add product "%s" to your subscription. The error was caught and no subscription for this row will be created. Please fix up the data from your CSV and try again.', 'wcs-import-export' ) );
			}

			if ( ! empty( self::$row[ self::$fields['download_permissions'] ] ) && ( 'true' == self::$row[ self::$fields['download_permissions'] ] || 1 == (int) self::$row[ self::$fields['download_permissions'] ] ) ) {
				self::save_download_permissions( $subscription, $_product, $item_args['qty'] );
			}
		}

		return $product_string;
	}

	/**
	 * Import fee line items
	 *
	 * @since 1.0
	 * @param WC_Subscription $subscription
	 * @param array $data
	 * @param int $chosen_tax_rate_id
	 */
	public static function add_fees( $subscription, $data, $chosen_tax_rate_id ) {
		$fee_items = explode( ';', $data[ self::$fields['fee_items'] ] );

		if ( ! empty( $fee_items ) ) {
			foreach ( $fee_items as $fee_item ) {
				$fee_data = array();

				foreach ( explode( '|', $fee_item ) as $item ) {
					list( $name, $value ) = explode( ':', $item );
					$fee_data[ trim( $name ) ] = trim( $value );
				}

				if ( empty( $fee_data['name'] ) ) {
					throw new Exception( __( 'Fee name is missing from your CSV. This subscription has not been imported.', 'wcs-import-export' ) );
				}

				$fee            = new stdClass();
				$fee->id        = sanitize_title( $fee_data['name'] );
				$fee->name      = $fee_data['name'];
				$fee->amount    = isset( $fee_data['total'] ) ? floatval( $fee_data['total'] ) : 0;
				$fee->taxable   = false;
				$fee->tax       = 0;
				$fee->tax_data  = array();
				$fee->tax_class = 0;

				if ( ! empty( $fee_data['tax'] ) ) {
					$fee->tax       = wc_format_decimal( $fee_data['tax'] );
					$fee->tax_class = ( ! empty( $fee_data['tax_class'] ) ) ? $fee_data['tax_class'] : 0;
					$fee->taxable   = true;

					if ( ! empty( $chosen_tax_rate_id ) ) {
						$fee->tax_data = array( 'total' => array( $chosen_tax_rate_id => $fee->tax ), 'subtotal' => array( $chosen_tax_rate_id => $fee->tax ) );
					}
				}

				if ( ! self::$test_mode ) {

					$fee_line_item = new WC_Order_Item_Fee();
					$fee_line_item->set_props( array(
						'name'      => $fee->name,
						'tax_class' => $fee->taxable ? $fee->tax_class : 0,
						'total'     => $fee->amount,
						'total_tax' => $fee->tax,
						'taxes'     => array(
							'total' => $fee->tax_data,
						),
						'order_id'  => $subscription->get_id(),
					) );
					$fee_line_item->save();
					$subscription->add_item( $fee_line_item );

					$fee_id = $fee_line_item->get_id();

					if ( ! $fee_id ) {
						throw new Exception( __( 'Could not add the fee to your subscription, the subscription has not been imported.', 'wcs-import-export' ) );
					}
				}
			}
		}
	}

	/**
	 * Import subscription shipping lines
	 *
	 * @since 1.0
	 * @param WC_Subscription $subscription
	 * @param array $data
	 * @param int $chosen_tax_rate_id
	 * @return string
	 */
	public static function add_shipping_lines( $subscription, $data, $chosen_tax_rate_id ) {
		$shipping_items   = explode( ';', $data[ self::$fields['shipping_method'] ] );
		$shipping_method  = '';
		$default_total    = ( ! empty( $data[ self::$fields['order_shipping'] ] ) ) ? $data[ self::$fields['order_shipping'] ] : 0;

		if ( ! empty( $shipping_items ) ) {
			foreach ( $shipping_items as $shipping_item ) {
				$shipping_line = array();

				if ( false !== strpos( $shipping_item, ':' ) ) {
					foreach ( explode( '|', $shipping_item ) as $item ) {
						list( $name, $value ) = explode( ':', $item );
						$shipping_line[ trim( $name ) ] = trim( $value );
					}
				} else {
					$shipping_line['method_id'] = $shipping_item;
				}

				$shipping_method = isset( $shipping_line['method_id'] ) ? $shipping_line['method_id'] : '';
				$shipping_title  = isset( $shipping_line['method_title'] ) ? $shipping_line['method_title'] : $shipping_method;

				if ( ! self::$test_mode ) {
					$shipping_rate = new WC_Shipping_Rate( $shipping_method, $shipping_title, isset( $shipping_line['total'] ) ? floatval( $shipping_line['total'] ) : $default_total, array(), $shipping_method );

					if ( ! empty( $data[ self::$fields['order_shipping_tax'] ] ) && ! empty( $chosen_tax_rate_id ) ) {
						$shipping_rate->taxes = array( $chosen_tax_rate_id => $data[ self::$fields['order_shipping_tax'] ] );
					}

					$shipping_line_item = new WC_Order_Item_Shipping();
					$shipping_line_item->set_props( array(
						'method_title' => $shipping_rate->label,
						'method_id'    => $shipping_rate->id,
						'total'        => wc_format_decimal( $shipping_rate->cost ),
						'taxes'        => $shipping_rate->taxes,
						'order_id'     => $subscription->get_id(),
					) );
					foreach ( $shipping_rate->get_meta_data() as $key => $value ) {
						$shipping_line_item->add_meta_data( $key, $value, true );
					}
					$shipping_line_item->save();
					$subscription->add_item( $shipping_line_item );
					wc_do_deprecated_action( 'woocommerce_order_add_shipping', array( $subscription->get_id(), $shipping_line_item->get_id(), $shipping_rate ), '3.0', 'woocommerce_new_order_item action instead.' );
					$shipping_id = $shipping_line_item->get_id();

					if ( ! $shipping_id ) {
						throw new Exception( __( 'An error occurred when trying to add the shipping item to the subscription, a subscription not been created for this row.', 'wcs-import-export' ) );
					}

					$subscription->update_meta_data( '_shipping_method', $shipping_method );
					$subscription->update_meta_data( '_shipping_method_title', $shipping_title );
					$subscription->save();
				}
			}
		}

		return $shipping_method;
	}

	/**
	 * Import tax lines
	 *
	 * @since 1.0
	 * @param WC_Subscription $subscription
	 * @param array $data
	 */
	public static function add_taxes( $subscription, $data ) {
		global $wpdb;

		$tax_items          = explode( ';', $data[ self::$fields['tax_items'] ] );
		$chosen_tax_rate_id = 0;

		if ( ! empty( $tax_items ) ) {
			foreach ( $tax_items as $tax_item ) {
				$tax_data = array();

				if ( false !== strpos( $tax_item, ':' ) ) {
					foreach ( explode( '|', $tax_item ) as $item ) {
						list( $name, $value ) = explode( ':', $item );
						$tax_data[ trim( $name ) ] = trim( $value );
					}
				} elseif ( 1 == count( $tax_items ) ) {
					if ( is_numeric( $tax_item ) ) {
						$tax_data['id'] = $tax_item;
					} else {
						$tax_data['code'] = $tax_item;
					}
				}

				if ( ! empty( $tax_data['id'] ) ) {
					$tax_rate = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}woocommerce_tax_rates WHERE tax_rate_id = %s", $tax_data['id'] ) );
				} elseif ( ! empty( $tax_data['code'] ) ) {
					$tax_rate = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}woocommerce_tax_rates WHERE tax_rate_name = %s ORDER BY tax_rate_priority LIMIT 1", $tax_data['code'] ) );
				} else {
					$result['warning'][] = sprintf( esc_html__( 'Missing tax code or ID from column: %s', 'wcs-import-export' ), self::$fields['tax_items'] );
				}

				if ( ! empty( $tax_rate ) ) {
					if ( ! self::$test_mode ) {
						$tax_rate = array_pop( $tax_rate );

						$tax_line_item = new WC_Order_Item_Tax();
						$tax_line_item->set_props( array(
							'rate_id'            => $tax_rate->tax_rate_id,
							'tax_total'          => ( ! empty( $data[ self::$fields['order_tax'] ] ) ) ? $data[ self::$fields['order_tax'] ] : 0,
							'shipping_tax_total' => ( ! empty( $data[ self::$fields['order_shipping_tax'] ] ) ) ? $data[ self::$fields['order_shipping_tax'] ] : 0,
						) );
						$tax_line_item->set_rate( $tax_rate->tax_rate_id );
						$tax_line_item->set_order_id( $subscription->get_id() );
						$tax_line_item->save();
						$subscription->add_item( $tax_line_item );
						$tax_id = $tax_line_item->get_id();

						if ( ! $tax_id ) {
							$result['warning'][] = esc_html__( 'Tax line item could not properly be added to this subscription. Please review this subscription.', 'wcs-import-export' );
						} else {
							$chosen_tax_rate_id = $tax_rate->tax_rate_id;
						}
					}
				} else {
					$result['warning'][] = sprintf( esc_html__( 'The tax code "%s" could not be found in your store.', 'wcs-import-export' ), $tax_data['code'] );
				}
			}
		}

		return $chosen_tax_rate_id;
	}
}
