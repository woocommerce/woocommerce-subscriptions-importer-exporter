<?php
/**
 * Subscription Export CSV Class
 *
 * @since 1.0
 */
class WCS_Exporter {

	public static $file = null;

	public static $headers = array();

	/**
	 * Init function for the export writer. Opens the writer and writes the given CSV headers to the output buffer.
	 *
	 * @since 1.0
	 * @param array $header
	 */
	public static function write_headers( $headers ) {

		if ( self::$file == null ) {
		self::$file    = fopen( 'php://output', 'w' );
		ob_start();
		}

		self::$headers = $headers;
		self::write( $headers );
	}

	/**
	 * Takes the subscription and builds the CSV row based on the headers which have been set, then calls @see self::write().
	 *
	 * @since 1.0
	 * @param WC_Subscription $subscription
	 */
	public static function write_subscriptions_csv_row( $subscription ) {

		$subscription_id = version_compare( WC()->version, '3.0', '>=' ) ? $subscription->get_id() : $subscription->id;

		$fee_total = $fee_tax_total = 0;
		$fee_items = array();

		if ( 0 != count( array_intersect( array_keys( self::$headers ), array( 'fee_total', 'fee_tax_total', 'fee_items' ) ) ) ) {
			foreach ( $subscription->get_fees() as $fee_id => $fee ) {

				$fee_items[] = implode( '|', array(
					'name:' . $fee['name'],
					'total:' . wc_format_decimal( $fee['line_total'], 2 ),
					'tax:' . wc_format_decimal( $fee['line_tax'], 2 ),
					'tax_class:' . $fee['tax_class'],
				) );

				$fee_total     += $fee['line_total'];
				$fee_tax_total += $fee['line_tax'];
			}
		}

		if ( isset( self::$headers['payment_method_post_meta'] ) || isset( self::$headers['payment_method_user_meta'] ) ) {
			$payment_method_table = apply_filters( 'woocommerce_subscription_payment_meta', array(), $subscription );
			$subscription_payment_method =  version_compare( WC()->version, '3.0', '>=' ) ? $subscription->get_payment_method() : $subscription->payment_method;

			if ( is_array( $payment_method_table ) && ! empty( $payment_method_table[ $subscription_payment_method ] ) ) {
				$post_meta = $user_meta = array();

				foreach ( $payment_method_table[ $subscription_payment_method ] as $meta_table => $meta ) {
					foreach ( $meta as $meta_key => $meta_data ) {
						switch ( $meta_table ) {
							case 'post_meta':
							case 'postmeta':
								$post_meta[] = $meta_key . ':' . $meta_data['value'];
								break;
							case 'usermeta':
							case 'user_meta':
								$user_meta[] = $meta_key . ':' . $meta_data['value'];
								break;
						}
					}
				}

				$payment_post_meta = implode( '|', $post_meta );
				$payment_user_meta = implode( '|', $user_meta );
			}
		}

		$csv_row = array();

		foreach ( self::$headers as $header_key => $_ ) {
			switch ( $header_key ) {
				case 'subscription_status':
					$value = version_compare( WC()->version, '3.0', '>=' ) ? 'wc-' . $subscription->get_status() : $subscription->post_status;
					break;
				case 'customer_id':
					$value = version_compare( WC()->version, '3.0', '>=' ) ? $subscription->get_customer_id() : $subscription->customer_user;
					break;
				case 'subscription_id':
				case 'fee_total':
				case 'fee_tax_total':
					$value = ${$header_key};
					break;
				case 'order_shipping':
					$value = version_compare( WC()->version, '3.0', '>=' ) ? $subscription->get_shipping_total() : $subscription->order_shipping;
					if ( empty ( $value ) ) {
						$value = 0;
					}
					break;
				case 'order_shipping_tax':
					$value = version_compare( WC()->version, '3.0', '>=' ) ? $subscription->get_shipping_tax() : $subscription->order_shipping_tax;
					if ( empty( $value ) ) {
						$value = 0;
					}
					break;
				case 'order_tax':
					$value = version_compare( WC()->version, '3.0', '>=' ) ? $subscription->get_total_tax() : $subscription->order_tax;
					if ( empty ( $value ) ) {
						$value = 0;
					}
					break;
				case 'cart_discount':
					$value = version_compare( WC()->version, '3.0', '>=' ) ? $subscription->get_total_discount() : $subscription->cart_discount;
					if ( empty ( $value ) ) {
						$value = 0;
					}
					break;
				case 'cart_discount_tax':
					$value = version_compare( WC()->version, '3.0', '>=' ) ? $subscription->get_discount_tax() : $subscription->cart_discount_tax;
					if ( empty ( $value ) ) {
						$value = 0;
					}
					break;
				case 'order_total':
					$value = version_compare( WC()->version, '3.0', '>=' ) ? $subscription->get_total() : $subscription->order_total;
					if ( empty ( $value ) ) {
						$value = 0;
					}
					break;
				case 'start_date':
					$value = version_compare( WC()->version, '3.0', '>=' ) ? $subscription->get_date( 'date_created' ) : $subscription->start_date;
					break;
				case 'trial_end_date':
					$value = version_compare( WC()->version, '3.0', '>=' ) ? $subscription->get_date( 'trial_end' ) : $subscription->trial_end_date;
					break;
				case 'next_payment_date':
					$value = version_compare( WC()->version, '3.0', '>=' ) ? $subscription->get_date( 'next_payment' ) : $subscription->next_payment_date;
					break;
				case 'last_payment_date':
					$value = version_compare( WC()->version, '3.0', '>=' ) ? $subscription->get_date( 'last_order_date_created' ) : $subscription->last_payment_date;
					break;
				case 'end_date':
					$value = version_compare( WC()->version, '3.0', '>=' ) ? $subscription->get_date( 'end' ) : $subscription->end_date;
					break;
				case 'requires_manual_renewal':
					$value = version_compare( WC()->version, '3.0', '>=' ) ? ( $subscription->get_requires_manual_renewal() ? 'true' : 'false' ) : $subscription->requires_manual_renewal;
					break;
				case 'billing_period':
				case 'billing_interval':
				case 'payment_method':
				case 'payment_method_title':
				case 'billing_first_name':
				case 'billing_last_name':
				case 'billing_email':
				case 'billing_phone':
				case 'billing_address_1':
				case 'billing_address_2':
				case 'billing_postcode':
				case 'billing_city':
				case 'billing_state':
				case 'billing_country':
				case 'billing_company':
				case 'shipping_first_name':
				case 'shipping_last_name':
				case 'shipping_address_1':
				case 'shipping_address_2':
				case 'shipping_postcode':
				case 'shipping_city':
				case 'shipping_state':
				case 'shipping_country':
				case 'shipping_company':
				case 'customer_note':
					if ( version_compare( WC()->version, '3.0', '>=' ) ) {
						$value = call_user_func( array( $subscription, 'get_' . $header_key ) );
					} else {
						$value = $subscription->{$header_key};
					}
					break;
				case 'order_currency':
					$value = version_compare( WC()->version, '3.0', '>=' ) ? $subscription->get_currency() :$subscription->order_currency;
					break;
				case 'payment_method_post_meta':
					$value = ( ! empty( $payment_post_meta ) ) ? $payment_post_meta : '';
					break;
				case 'payment_method_user_meta':
					$value = ( ! empty( $payment_user_meta ) ) ? $payment_user_meta : '';
					break;
				case 'order_notes':
					remove_filter( 'comments_clauses', array( 'WC_Comments', 'exclude_order_comments' ) );
					$notes = get_comments( array( 'post_id' => $subscription_id, 'approve' => 'approve', 'type' => 'order_note' ) );
					add_filter( 'comments_clauses', array( 'WC_Comments', 'exclude_order_comments' ) );

					$order_notes = array();

					foreach ( $notes as $note ) {
						$order_notes[] = str_replace( array( "\r", "\n" ), ' ', $note->comment_content );
					}

					if ( ! empty( $order_notes ) ) {
						$value = implode( ';', $order_notes );
					} else {
						$value = '';
					}

					break;
				case 'order_items':
					$value      = '';
					$line_items = array();

					foreach ( $subscription->get_items() as $item_id => $item ) {

						$meta_string = '';

						if ( version_compare( WC()->version, '3.0', '>=' ) ) {
							$item_meta          = $item->get_meta_data();
							$item_name          = $item->get_name();
							$item_qty           = $item->get_quantity();
							$item_line_tax_data = method_exists( $item, 'get_taxes' ) ? $item->get_taxes() : array();
						} else {
							$item_meta          = $subscription->has_meta( $item_id );
							$item_name          = $item['name'];
							$item_qty           = $item['qty'];
							$item_line_tax_data = isset( $item['line_tax_data'] ) ? $item['line_tax_data'] : array();
						}

						foreach ( $item_meta as $meta_id => $meta_data ) {

							if ( version_compare( WC()->version, '3.0', '>=' ) ) {
								$meta_key   = $meta_data->key;
								$meta_value = $meta_data->value;
							} else {
								$meta_key   = $meta_data['meta_key'];
								$meta_value = $meta_data['meta_value'];
							}

							// Skip hidden core fields
							if ( in_array( $meta_key, apply_filters( 'woocommerce_hidden_order_itemmeta', array(
								'_qty',
								'_tax_class',
								'_product_id',
								'_variation_id',
								'_line_subtotal',
								'_line_subtotal_tax',
								'_line_total',
								'_line_tax',
								'_line_tax_data',
							) ) ) ) {
								continue;
							}

							// Add a custom delimiter to separate meta (we're running out of special characters to use!)
							if ( ! empty( $meta_string ) ) {
								$meta_string .= '+';
							}

							$meta_string .= sprintf( '%s=%s', $meta_key, $meta_value );
						}

						$line_item = array(
							'product_id' => function_exists( 'wcs_get_canonical_product_id' ) ? wcs_get_canonical_product_id( $item ) : '',
							'name'       => html_entity_decode( $item_name, ENT_NOQUOTES, 'UTF-8' ),
							'quantity'   => $item_qty,
							'total'      => wc_format_decimal( $subscription->get_line_total( $item ), 2 ),
							'meta'       => html_entity_decode( str_replace( array( "\r", "\r\n", "\n", ': ', ':', ';', '|' ), '', $meta_string ), ENT_NOQUOTES, 'UTF-8' ),
						);

						// add line item tax
						$line_tax_data    = $item_line_tax_data;
						$tax_data         = maybe_unserialize( $line_tax_data );
						$line_item['tax'] = isset( $tax_data['total'] ) ? wc_format_decimal( wc_round_tax_total( array_sum( (array) $tax_data['total'] ) ), 2 ) : '';

						foreach ( $line_item as $name => $value ) {
							$line_item[ $name ] = $name . ':' . $value;
						}
						$line_item = implode( '|', $line_item );

						if ( $line_item ) {
							$line_items[] = $line_item;
						}
					}

					if ( ! empty( $line_items ) ) {
						$value = implode( ';', $line_items );
					}
					break;

				case 'coupon_items':
					$coupon_items = array();

					foreach ( $subscription->get_items( 'coupon' ) as $_ => $coupon_item ) {
						$coupon_name = version_compare( WC()->version, '3.0', '>=' ) ? $coupon_item->get_name() : $coupon_item['name'];

						$coupon = new WC_Coupon( $coupon_name );

						$coupon_post   = get_post( version_compare( WC()->version, '3.0', '>=' ) ? $coupon->get_id() : $coupon->id );
						$coupon_amount = version_compare( WC()->version, '3.0', '>=' ) ? $coupon_item->get_discount() : $coupon_item['discount_amount'];

						$coupon_items[] = implode( '|', array(
								'code:' . $coupon_name,
								'description:' . ( is_object( $coupon_post ) ? $coupon_post->post_excerpt : '' ),
								'amount:' . wc_format_decimal( $coupon_amount, 2 ),
							)
						);
					}

					if ( ! empty( $coupon_items ) ) {
						$value = implode( ';', $coupon_items );
					} else {
						$value = '';
					}

					break;
				case 'download_permissions':
					$value = $subscription->download_permissions_granted ? $subscription->download_permissions_granted : 0;
					break;
				case 'shipping_method':
					$shipping_lines = array();

					foreach ( $subscription->get_shipping_methods() as $shipping_item_id => $shipping_item ) {
						if ( version_compare( WC()->version, '3.0', '>=' ) ) {
							$shipping_lines[] = implode( '|', array(
									'method_id:' . $shipping_item->get_method_id(),
									'method_title:' . $shipping_item->get_name(),
									'total:' . wc_format_decimal( $shipping_item->get_total(), 2 ),
								)
							);
						} else {
							$shipping_lines[] = implode( '|', array(
									'method_id:' . $shipping_item['method_id'],
									'method_title:' . $shipping_item['name'],
									'total:' . wc_format_decimal( $shipping_item['cost'], 2 ),
								)
							);
						}
					}

					if ( ! empty( $shipping_lines ) ) {
						$value = implode( ';', $shipping_lines );
					} else {
						$value = '';
					}

					break;
				case 'fee_items':
					$value = implode( ';', $fee_items );
					break;
				case 'tax_items':
					$tax_items = array();

					foreach ( $subscription->get_tax_totals() as $tax_code => $tax ) {
						$tax_items[] = implode( '|', array(
							'id:' . $tax->rate_id,
							'code:' . $tax->label,
							'total:' . wc_format_decimal( $tax->amount, 2 ),
						) );
					}

					if ( ! empty( $tax_items ) ) {
						$value = implode( ';', $tax_items );
					} else {
						$value = '';
					}
					break;
				default :
					$value = '';
			}

			$csv_row[ $header_key ] = $value;
		}

		self::write( $csv_row );
	}

	/**
	 * Write line to CSV
	 *
	 * @since 1.0
	 * @param array $row
	 */
	public static function write( $row ) {

		if ( empty( $row ) ) {
			return;
		}

		$data = array();

		foreach ( self::$headers as $header_key => $_ ) {

			if ( ! isset( $row[ $header_key ] ) ) {
				$row[ $header_key ] = '';
			}

			// strict string comparison, as values like '0' are valid
			$value  = ( '' !== $row[ $header_key ] ) ? $row[ $header_key ] : '';
			$data[] = $value;
		}

		fputcsv( self::$file, $data, ',', '"' );
	}

	/**
	 * Process export file
	 *
	 * @since 1.0
	 * @param string $filename
	 */
	public static function process_export( $filename = 'subscriptions.csv' ) {
		$csv = ob_get_clean();

		fclose( self::$file );

		// set headers for download
		header( apply_filters( 'wcsi_csv_export_download_content_type', 'Content-Type: text/csv; charset=' . get_option( 'blog_charset' ) ) );
		header( sprintf( 'Content-Disposition: attachment; filename="%s"', $filename ) );
		header( 'Pragma: no-cache' );
		header( 'Expires: 0' );

		// clear the output buffer
		@ini_set( 'zlib.output_compression', 'Off' );
		@ini_set( 'output_buffering', 'Off' );
		@ini_set( 'output_handler', '' );

		// open the output buffer for writing
		$fp = fopen( 'php://output', 'w' );

		// write the generated CSV to the output buffer
		fwrite( $fp, $csv );

		// close the output buffer
		fclose( $fp );

		exit;
	}

}
