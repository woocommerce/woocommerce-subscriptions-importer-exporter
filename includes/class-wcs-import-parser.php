<?php
/**
 * The parser class for the Subscriptions CSV Importer.
 * This class reads a number of lines (can vary) from the CSV and imports the subscriptions into your store.
 * All errors and unexpected PHP shutdowns will be logged to assist in debugging.
 *
 * @since 1.0
 */
class WCS_Import_Parser {

	private static $results = array();
	public static $logger   = null;

	/* The current row number of CSV */
	private static $row_number;

	private static $membership_plans = null;
	private static $all_virtual      = true;

	/* Front-end import settings chosen */
	public static $test_mode;
	public static $email_customer;
	public static $add_memberships;

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
		self::$add_memberships = ( $data['add_memberships'] == 'true' ) ? true : false;
		self::$fields          = $data['mapped_fields'];

		add_action( 'shutdown', __CLASS__ . '::catch_unexpected_shutdown' );

		self::import_start( $file_path, $data['file_start'], $data['file_end'] );

		remove_action( 'shutdown', __CLASS__ . '::catch_unexpected_shutdown' );

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
		global $wpdb;

		self::$row  = $data;
		$set_manual = false;
		$post_meta  = array();
		$result     = array(
			'warning'    => array(),
			'error'      => array(),
			'item'       => '',
			'row_number' => self::$row_number,
		);

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
			self::log( sprintf( 'Row #%s failed: %s', $result['row_number'], print_r( $result['error'], true ) ) );

			array_push( self::$results, $result );
			return;
		}

		$missing_shipping_addresses = $missing_billing_addresses = array();

		foreach( self::$order_meta_fields as $column ) {
			switch( $column ) {
				case 'shipping_method':
					$shipping_method = ( ! empty( $data[ self::$fields['shipping_method'] ] ) ) ? $data[ self::$fields['shipping_method'] ] : '';
					$title           = ( ! empty( $data[ self::$fields['shipping_method_title'] ] ) ) ? $data[ self::$fields['shipping_method_title'] ] : $shipping_method;

					$post_meta[] = array( 'key' => '_' . $column, 'value' => $shipping_method );
					$post_meta[] = array( 'key' => '_shipping_method_title', 'value' => $title );
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
					} else {
						$value = 0;
					}

					$post_meta[] = array( 'key' => '_' . $column, 'value' => $value );
					break;

				default:
					$value       = ( ! empty( $data[ self::$fields[ $column ] ] ) ) ? $data[ self::$fields[ $column ] ] : '';
					$post_meta[] = array( 'key' => '_' . $column, 'value' => $value );
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

					$subscription = wcs_create_subscription( array(
							'customer_id'      => $user_id,
							'start_date'       => $dates_to_update['start'],
							'billing_interval' => ( ! empty( $data[ self::$fields['billing_interval'] ] ) ) ? $data[ self::$fields['billing_interval'] ] : 1,
							'billing_period'   => ( ! empty( $data[ self::$fields['billing_period'] ] ) ) ? $data[ self::$fields['billing_period'] ] : 'month',
							'created_via'      => 'importer',
						)
					);

					if ( is_wp_error( $subscription ) ) {
						throw new Exception( sprintf( esc_html__( 'Could not create subscription: %s', 'wcs-importer' ), $subscription->get_error_message() ) );
					}

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

					$subscription->update_dates( $dates_to_update );
					$subscription->update_status( $status );

					if ( $set_manual ) {
						$subscription->update_manual();
					} elseif ( ! $subscription->has_status( wcs_get_subscription_ended_statuses() ) ) { // don't bother trying to set payment meta on a subscription that won't ever renew
						$result['warning'] = array_merge( $result['warning'], self::set_payment_meta( $subscription, $data ) );
					}

					if ( self::$add_memberships ) {
						self::maybe_add_memberships( $user_id, $subscription->id, $product_id );
					}
				} else {
					$subscription = null;
				}

				if ( ! empty( $data[ self::$fields['coupon_items'] ] ) ) {
					self::add_coupons( $subscription, $data );
				}

				if ( ! empty( $data[ self::$fields['order_items'] ] ) ) {
					$order_items = explode( ';', $data[ self::$fields['order_items'] ] );

					if ( ! empty( $order_items ) ) {
						foreach( $order_items as $order_item ) {
							$order_data = array();

							foreach ( explode( '|', $order_item ) as $item ) {
								list( $name, $value ) = explode( ':', $item );
								$order_data[ trim( $name ) ] = trim( $value );
							}

							$result['item'] .= self::add_product( $subscription, $order_data );
						}
					}
				}

				// only show the following warnings on the import when the subscription requires shipping
				if ( ! self::$all_virtual ) {
					if ( ! empty( $missing_shipping_addresses ) ) {
						$result['warning'][] = esc_html__( 'The following shipping address fields have been left empty: ' . rtrim( implode( ', ', $missing_shipping_addresses ), ',' ) . '. ', 'wcs-importer' );
					}

					if ( ! empty( $missing_billing_addresses ) ) {
						$result['warning'][] = esc_html__( 'The following billing address fields have been left empty: ' . rtrim( implode( ', ', $missing_billing_addresses ), ',' ) . '. ', 'wcs-importer' );
					}

					if ( empty( $shipping_method ) ) {
						$result['warning'][] = esc_html__( 'Shipping method and title for the subscription have been left as empty. ', 'wcs-importer' );
					}
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
				$result['subscription']        = sprintf( '<a href="%s">#%s</a>', esc_url( admin_url( 'post.php?post=' . absint( $subscription->id ) . '&action=edit' ) ), $subscription->get_order_number() );
				$result['subscription_status'] = $subscription->get_status();

			} else {
				$result['status']  = 'failed';
				self::log( sprintf( 'Row #%s failed: %s', $result['row_number'], print_r( $result['error'], true ) ) );
			}
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
	 * Log all the things during an import
	 *
	 * @since 1.0
	 * @param string $message
	 * @param string $log Defaults to wcs-importer
	 */
	public static function log( $message, $log = 'wcs-importer' ) {

		if ( ! self::$test_mode && ( ! defined( 'WCSI_LOG' ) || false !== WCSI_LOG ) ) {
			if ( ! self::$logger ) {
				self::$logger = new WC_Logger();
			}

			self::$logger->add( $log, $message );
		}
	}

	/**
	 * Set the payment method meta on the imported subscription or on user meta
	 *
	 * @since 1.0
	 * @param WC_Subscription $subscription
	 * @param array $data Current line from the CSV
	 */
	private static function set_payment_meta( $subscription, $data ) {

		$warnings         = array();
		$payment_gateways = WC()->payment_gateways->get_available_payment_gateways();
		$payment_method   = $subscription->payment_method;


		if ( ! empty( $payment_method ) ) {

			$payment_method_table = apply_filters( 'woocommerce_subscription_payment_meta', array(), $subscription );
			$payment_gateway      = ( isset( $payment_gateways[ $payment_method ] ) ) ? $payment_gateways[ $payment_method ] : '';

			if ( ! empty( $payment_gateway ) && isset( $payment_method_table[ $payment_gateway->id ] ) ) {
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
								$payment_meta_fields = self::$fields['payment_method_post_meta'];
								break;
							case 'user_meta':
							case 'usermeta':
								$payment_meta_fields = self::$fields['payment_method_user_meta'];
								break;
							default :
								$payment_meta_fields = array();
						}

						if ( in_array( $meta_key, $payment_meta_fields ) ) {
							$meta_data['value'] = ( ! empty( $data[ $meta_key ] ) ) ? $data[ $meta_key ] : '';
							$meta_set = true;
						}
					}
				}

				if ( $meta_set ) {
					$subscription->set_payment_method( $payment_gateway, $payment_method_data );
				} else {
					$warnings[] = sprintf( esc_html__( 'No payment meta was set for your %s subscription (%s). The next renewal is going to fail if you leave this.', 'wcs-importer' ), $payment_method, $subscription->id );
				}

			} else {

				if ( 'paypal' == $payment_method ) {
					$warnings[] = sprintf( esc_html__( 'Could not set payment method as PayPal. Either PayPal was not enabled or your PayPal account does not have Reference Transaction setup. Learn more about enabling Reference Transactions %shere%s.', 'wcs-importer' ), '<a href="https://support.woothemes.com/hc/en-us/articles/205151193-PayPal-Reference-Transactions-for-Subscriptions">', '</a>' );
				} else {
					$warnings[] = sprintf( esc_html__( 'The payment method "%s" is either not enabled or does not support the new features of Subscriptions 2.0 and can not be properly attached to your subscription. This subscription has been set to manual renewals. Please contact support if you believe this is not correct.', 'wcs-importer' ), $payment_method );
				}
				$subscription->update_manual();
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
			$downloads  = $product->get_files();
			$product_id = isset( $product->variation_id ) ? $product->variation_id : $product->id;

			foreach ( array_keys( $downloads ) as $download_id ) {
				wc_downloadable_file_permission( $download_id, $product_id, $subscription, $quantity );
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
			}
		}
	}

	/**
	 * Catch any unexpected shutdowns experienced during the import process
	 *
	 * @since 1.0
	 */
	public static function catch_unexpected_shutdown() {
		if ( ! empty( self::$fields ) && ! empty( self::$row ) && $error = error_get_last() ) {

			if ( E_ERROR == $error['type'] ) {
				self::log( '--------- Expected shutdown during the importer ---------', 'wcs-importer-shutdown' );
				self::log( 'Mapped Fields: ' . print_r( self::$fields, true ), 'wcs-importer-shutdown' );
				self::log( 'CSV Row: ' . print_r( self::$row, true ), 'wcs-importer-shutdown' );
				self::log( sprintf( 'PHP Fatal error %s in %s on line %s.', $error['message'], $error['file'], $error['line'] ), 'wcs-importer-shutdown' );
			}

			self::$fields = self::$row = null;
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
			foreach( $coupon_items as $coupon_item ) {
				$coupon_data = array();

				foreach ( explode( '|', $coupon_item ) as $item ) {
					list( $name, $value ) = explode( ':', $item );
					$coupon_data[ trim( $name ) ] = trim( $value );
				}

				$coupon_code = isset( $coupon_data['code'] ) ? $coupon_data['code'] : '';
				$coupon      = new WC_Coupon( $coupon_code );

				if ( ! $coupon ) {
					throw new Exception( sprintf( esc_html__( 'Could not find coupon with code "%s" in your store.', 'wcs-importer' ), $coupon_code ) );
				} elseif ( isset( $coupon_data['amount'] ) ) {
					$discount_amount = floatval( $coupon_data['amount'] );
				} else {
					$discount_amount = $coupon->discount_amount;
				}

				if ( ! self::$test_mode ) {
					$coupon_id = $subscription->add_coupon( $coupon_code, $discount_amount );

					if ( ! $coupon_id ) {
						throw new Exception( sprintf( esc_html__( 'Coupon "%s" could not be added to subscription.', 'wcs-importer' ), $coupon_code ) );
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
	 * @return string
	 */
	public static function add_product( $subscription, $data ) {
		$item_args        = array();
		$item_args['qty'] = isset( $data['quantity'] ) ? $data['quantity'] : 1;

		if ( ! isset( $data['product_id'] ) ) {
			throw new Exception( __( 'The product_id is missing from CSV.', 'wcs-importer' ) );
		}

		$_product = wc_get_product( $data['product_id'] );

		if ( ! $_product ) {
			throw new Exception( sprintf( __( 'No product or variation in your store matches the product ID #%s.', 'wcs-importer' ), $data['product_id'] ) );
		}

		$product_string = sprintf( '<a href="%s">%s</a>', get_edit_post_link( $_product->id ), $_product->get_title() );

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

		if ( $_product->variation_data ) {
			$item_args['variation'] = array();

			foreach ( $_product->variation_data as $attribute => $variation ) {
				$item_args['variation'][ $attribute ] = $variation;
			}
			$product_string .= ' [#' . $data['product_id'] . ']';
		}

		if ( self::$all_virtual && ! $_product->is_virtual() ) {
			self::$all_virtual = false;
		}

		if ( ! self::$test_mode ) {
			$item_id = $subscription->add_product( $_product, $data['quantity'], $item_args );

			if ( ! $item_id ) {
				throw new Exception( __( 'An unexpected error occurred when trying to add product "%s" to your subscription. The error was caught and no subscription for this row will be created. Please fix up the data from your CSV and try again.', 'wcs-importer' ) );
			}

			if ( ! empty( self::$row[ self::$fields['download_permission_granted'] ] ) && ( 'true' == self::$row[ self::$fields['download_permission_granted'] ] || '1' == self::$row[ self::$fields['download_permission_granted'] ] ) ) {
				self::save_download_permissions( $subscription, $_product, $item_args['qty'] );
			}
		}

		return $product_string;
	}
}

?>
