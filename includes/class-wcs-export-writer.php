<?php
/**
 * Subscription Export CSV Writer Class
 *
 * @since 1.0
 */
class WCS_Export_Writer {

	private static $file = null;

	public static $headers = array();

	/**
	 * Init function for the export writer. Opens the writer and writes the given CSV headers to the output buffer.
	 *
	 * @since 1.0
	 * @param array $header
	 */
	public static function write_headers( $headers ) {
		self::$file    = fopen( 'php://output', 'w' );
		ob_start();

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

		foreach ( self::$headers as $header_key => $_ ) {
			switch ( $header_key ) {
				case 'subscription_id':
					$value = $subscription->id;
					break;
				case 'subscription_status':
					$value = $subscription->post_status;
					break;
				case 'shipping_total':
				case 'shipping_tax_total':
				case 'fee_total':
				case 'fee_tax_total':
				case 'tax_total':
				case 'cart_discount':
				case 'order_discount':
				case 'order_total':
					$value = empty( $subscription->{$header_key} ) ? 0 : $subscription->{$header_key};
					break;
				case 'start_date':
				case 'trial_end_date':
				case 'next_payment_date':
				case 'last_payment_date':
				case 'end_date':
				case 'payment_method':
				case 'shipping_method':
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
					$value = $subscription->{$header_key};
					break;
				case 'order_notes':
					remove_filter( 'comments_clauses', array( 'WC_Comments', 'exclude_order_comments' ) );
					$notes = get_comments( array( 'post_id' => $subscription->id, 'approve' => 'approve', 'type' => 'order_note' ) );
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

						$item_meta = new WC_Order_Item_Meta( $item );
						$meta = $item_meta->display( true, true );

						if ( $meta ) {
							$meta = str_replace( array( "\r", "\r\n", "\n" ), '', $meta );
							$meta = str_replace( array( ': ', ':', ';', '|' ), '=', $meta );
						}

						$line_item = array(
							'name'     => html_entity_decode( $item['name'], ENT_NOQUOTES, 'UTF-8' ),
							'quantity' => $item['qty'],
							'total'    => wc_format_decimal( $subscription->get_line_total( $item ), 2 ),
							'meta'     => html_entity_decode( $meta, ENT_NOQUOTES, 'UTF-8' ),
						);

						// add line item tax
						$line_tax_data    = isset( $item['line_tax_data'] ) ? $item['line_tax_data'] : array();
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

						$coupon = new WC_Coupon( $coupon_item['name'] );

						$coupon_post = get_post( $coupon->id );

						$coupon_items[] = implode( '|', array(
								'code:' . $coupon_item['name'],
								'description:' . ( is_object( $coupon_post ) ? $coupon_post->post_excerpt : '' ),
								'amount:' . wc_format_decimal( $coupon_item['discount_amount'], 2 ),
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