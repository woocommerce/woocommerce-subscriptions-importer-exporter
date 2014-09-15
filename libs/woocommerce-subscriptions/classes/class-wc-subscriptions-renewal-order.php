<?php
/**
 * Subscriptions Renewal Order Class
 * 
 * Provides an API for creating and handling renewal orders.
 *
 * @package		WooCommerce Subscriptions
 * @subpackage	WC_Subscriptions_Order
 * @category	Class
 * @author		Brent Shepherd
 * @since 		1.2
 */
class WC_Subscriptions_Renewal_Order {

	public static $product_deleted_error_message;

	/**
	 * Bootstraps the class and hooks required actions & filters.
	 *
	 * @since 1.0
	 */
	public static function init() {

		// Generate an order to keep a record of each subscription payment
		add_action( 'processed_subscription_payment', __CLASS__ . '::generate_paid_renewal_order', 10, 2 );
		add_action( 'processed_subscription_payment_failure', __CLASS__ . '::generate_failed_payment_renewal_order', 10, 2 );

		// If a subscription requires manual payment, generate an order to accept the payment
		add_action( 'scheduled_subscription_payment', __CLASS__ . '::maybe_generate_manual_renewal_order', 10, 2 );

		// Make sure *manual* payment on a renewal order is correctly processed
		add_action( 'woocommerce_payment_complete', __CLASS__ . '::maybe_record_renewal_order_payment', 10, 1 );

		// Make sure *manual* payment on renewal orders is correctly processed for gateways that do not call WC_Order::payment_complete()
		add_action( 'woocommerce_order_status_on-hold_to_processing', __CLASS__ . '::maybe_record_renewal_order_payment', 10, 1 );
		add_action( 'woocommerce_order_status_on-hold_to_completed', __CLASS__ . '::maybe_record_renewal_order_payment', 10, 1 );

		// Make sure payment on renewal orders is correctly processed when the *automatic* payment had previously failed
		add_action( 'woocommerce_order_status_failed_to_processing', __CLASS__ . '::process_failed_renewal_order_payment', 10, 1 );
		add_action( 'woocommerce_order_status_failed_to_completed', __CLASS__ . '::process_failed_renewal_order_payment', 10, 1 );
		add_action( 'woocommerce_order_status_failed', __CLASS__ . '::maybe_record_renewal_order_payment_failure', 10, 1 );

		// Check if a user is requesting to create a renewal order for a subscription
		add_action( 'init', __CLASS__ . '::maybe_create_renewal_order_for_user', 100 );

		// Used to detect if payment on failed order is being made from 'My Account'
		add_action( 'before_woocommerce_pay',  __CLASS__ . '::before_woocommerce_pay' , 10 );

		// To handle virtual/non-downloaded that require store manager approval
		add_action( 'woocommerce_payment_complete', __CLASS__ . '::maybe_process_failed_renewal_order_payment', 10, 1 );
		add_action( 'woocommerce_order_status_on-hold_to_processing', __CLASS__ . '::maybe_process_failed_renewal_order_payment', 10, 1 );
		add_action( 'woocommerce_order_status_on-hold_to_completed', __CLASS__ . '::maybe_process_failed_renewal_order_payment', 10, 1 );

		// Add a renewal orders section to the Related Orders meta box
		add_action( 'woocommerce_subscriptions_related_orders_meta_box', __CLASS__ . '::renewal_orders_meta_box_section', 10, 2 );

		// Allow renewal of limited subscriptions
		add_filter( 'woocommerce_subscription_is_purchasable', __CLASS__ . '::is_purchasable', 12, 2 );
		add_filter( 'woocommerce_subscription_variation_is_purchasable', __CLASS__ . '::is_purchasable', 12, 2 );

		add_action( 'woocommerce_payment_complete', __CLASS__ . '::trigger_renewal_payment_complete', 10 );

		add_filter( 'woocommerce_get_checkout_payment_url', __CLASS__ . '::get_checkout_payment_url', 10, 2 );

		add_filter( 'woocommerce_product_addons_adjust_price', __CLASS__ . '::product_addons_adjust_price', 10, 2 );

		// The notice displayed when a subscription product has been deleted and the custoemr attempts to manually renew or make a renewal payment for a failed recurring payment for that product/subscription
		self::$product_deleted_error_message = apply_filters( 'woocommerce_subscriptions_renew_deleted_product_error_message', __( 'That product has been deleted and can no longer be renewed. Please choose a new product or contact us for assistance.', 'woocommerce-subscriptions' ) );
	}

	/**
	 * Creates a new order for renewing a subscription product based on the details of a previous order.
	 *
	 * No trial periods or sign up fees are applied to the renewal order. However, if the order has failed
	 * payments and the store manager has set failed payments to be added to renewal orders, then the
	 * orders totals will be set to include the outstanding balance.
	 *
	 * If the $args['new_order_role'] flag is set to 'parent', then the renewal order will supersede the existing 
	 * order. The existing order and subscription associated with it will be cancelled. A new order and
	 * subscription will be created. 
	 *
	 * If the $args['new_order_role'] flag is 'child', the $original_order will remain the master order for the
	 * subscription and the new order is just for accepting a recurring payment on the subscription.
	 *
	 * Renewal orders have the same meta data as the original order. If the renewal order is set to be a 'child'
	 * then any subscription related meta data will not be stored on the new order. This is to keep subscription
	 * meta data associated only with the one master order for the subscription.
	 *
	 * @param WC_Order|int $order The WC_Order object or ID of the order for which the a new order should be created.
	 * @param string $product_id The ID of the subscription product in the order which needs to be added to the new order.
	 * @param array $args (optional) An array of name => value flags:
	 *         'new_order_role' string A flag to indicate whether the new order should become the master order for the subscription. Accepts either 'parent' or 'child'. Defaults to 'parent' - replace the existing order.
	 *         'checkout_renewal' bool Indicates if invoked from an interactive cart/checkout session and certain order items are not set, like taxes, shipping as they need to be set in teh calling function, like @see WC_Subscriptions_Checkout::filter_woocommerce_create_order(). Default false.
	 *         'failed_order_id' int For checkout_renewal true, indicates order id being replaced
	 * @since 1.2
	 */
	public static function generate_renewal_order( $original_order, $product_id, $args = array() ) {
		global $wpdb, $woocommerce;

		if ( ! is_object( $original_order ) ) {
			$original_order = new WC_Order( $original_order );
		}

		if ( ! WC_Subscriptions_Order::order_contains_subscription( $original_order ) || ! WC_Subscriptions_Order::is_item_subscription( $original_order, $product_id ) ) {
			return false;
		}

		if ( self::is_renewal( $original_order, array( 'order_role' => 'child' ) ) ) {
			$original_order = self::get_parent_order( $original_order );
		}

		if ( ! is_array( $args ) ) {
			_deprecated_argument( __CLASS__ . '::' . __FUNCTION__, '1.3', __( 'Third parameter is now an array of name => value pairs. Use array( "new_order_role" => "parent" ) instead.', 'woocommerce-subscriptions' ) );
			$args = array(
				'new_order_role' => $args,
			);
		}

		$args = wp_parse_args( $args, array(
			'new_order_role'   => 'parent',
			'checkout_renewal' => false,
			)
		);

		$renewal_order_key = uniqid( 'order_' );

		// Create the new order
		$renewal_order_data = array(
			'post_type'     => 'shop_order',
			'post_title' 	=> sprintf( __( 'Subscription Renewal Order &ndash; %s', 'woocommerce-subscriptions' ), strftime( _x( '%b %d, %Y @ %I:%M %p', 'Order date parsed by strftime', 'woocommerce-subscriptions' ) ) ),
			'ping_status'   => 'closed',
			'post_excerpt'  => $original_order->customer_note,
			'post_author'   => 1,
			'post_password' => $renewal_order_key,
		);

		$create_new_order = true;

		if ( ! WC_Subscriptions::is_woocommerce_pre_2_2() ) { // WC 2.2 order status
			$renewal_order_data['post_status'] = 'wc-pending';
		}

		if ( 'child' == $args['new_order_role'] ) {
			$renewal_order_data['post_parent'] = $original_order->id;
		}

		if ( true === $args['checkout_renewal'] ) {

			$renewal_order_id = null;

			if ( $woocommerce->session->order_awaiting_payment > 0 ) {
				$renewal_order_id = absint( $woocommerce->session->order_awaiting_payment );
			} elseif ( isset( $args['failed_order_id'] ) ) {

				$failed_order_id = $args['failed_order_id'];

				if ( WC_Subscriptions::is_woocommerce_pre_2_2() ) { // WC 2.1  - need to use taxonomy
					/* Check order is unpaid by getting its status */
					$terms = wp_get_object_terms( $failed_order_id, 'shop_order_status', array( 'fields' => 'slugs' ) );
					$order_status = isset( $terms[0] ) ? $terms[0] : 'pending';
				} else { // WC 2.2+
					$failed_order = wc_get_order( $failed_order_id );
					$order_status = $failed_order->get_status();
				}

				/* If paying on a pending order, we are resuming */
				if ( $order_status == 'pending' ) {
					$renewal_order_id = $failed_order_id;
				}
			}

			if ( $renewal_order_id ) {

				if ( WC_Subscriptions::is_woocommerce_pre_2_2() ) { // WC 2.1  - need to use taxonomy
					/* Check order is unpaid by getting its status */
					$terms = wp_get_object_terms( $renewal_order_id, 'shop_order_status', array( 'fields' => 'slugs' ) );
					$order_status = isset( $terms[0] ) ? $terms[0] : 'pending';
				} else { // WC 2.2+
					$existing_order = wc_get_order( $renewal_order_id );
					$order_status = $existing_order->get_status();
				}

				// Resume the unpaid order if its pending
				if ( $order_status == 'pending' || $order_status == 'failed' ) {

					// Update the existing order as we are resuming it
					$create_new_order = false;
					$renewal_order_data['ID'] = $renewal_order_id;
					wp_update_post( $renewal_order_data );

					// Clear the old line items - we'll add these again in case they changed
					$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}woocommerce_order_itemmeta WHERE order_item_id IN ( SELECT order_item_id FROM {$wpdb->prefix}woocommerce_order_items WHERE order_id = %d )", $renewal_order_id ) );

					$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}woocommerce_order_items WHERE order_id = %d", $renewal_order_id ) );
				}
			}
		}

		if ( $create_new_order ) {
			$renewal_order_id = wp_insert_post( $renewal_order_data );
		}

		if ( WC_Subscriptions::is_woocommerce_pre_2_2() ) { // WC 2.1 order status
			// Set the order as pending
			wp_set_object_terms( $renewal_order_id, 'pending', 'shop_order_status' );
		}

		// Set a unique key for this order
		update_post_meta( $renewal_order_id, '_order_key', $renewal_order_key );

		$order_meta_query = "SELECT `meta_key`, `meta_value`
							 FROM $wpdb->postmeta
							 WHERE `post_id` = $original_order->id
							 AND `meta_key` NOT IN ('_paid_date', '_completed_date', '_order_key', '_edit_lock', '_original_order', '_wc_points_earned', '_transaction_id')";

		// Superseding existing order so don't carry over payment details
		if ( 'parent' == $args['new_order_role'] || true === $args['checkout_renewal'] ) {
			$order_meta_query .= " AND `meta_key` NOT IN ('_payment_method', '_payment_method_title', '_recurring_payment_method', '_recurring_payment_method_title', '_shipping_method', '_shipping_method_title', '_recurring_shipping_method', '_recurring_shipping_method_title')";
		} else {
			$order_meta_query .= " AND `meta_key` NOT LIKE '_order_recurring_%' AND `meta_key` NOT IN ('_payment_method', '_payment_method_title', '_recurring_payment_method', '_recurring_payment_method_title', '_shipping_method', '_shipping_method_title', '_recurring_shipping_method', '_recurring_shipping_method_title')";
		}

		// Allow extensions to add/remove order meta
		$order_meta_query = apply_filters( 'woocommerce_subscriptions_renewal_order_meta_query', $order_meta_query, $original_order->id, $renewal_order_id, $args['new_order_role'] );

		// Carry all the required meta from the old order over to the new order
		$order_meta = $wpdb->get_results( $order_meta_query, 'ARRAY_A' );

		$order_meta = apply_filters( 'woocommerce_subscriptions_renewal_order_meta', $order_meta, $original_order->id, $renewal_order_id, $args['new_order_role'] );

		foreach( $order_meta as $meta_item ) {
			add_post_meta( $renewal_order_id, $meta_item['meta_key'], maybe_unserialize( $meta_item['meta_value'] ), true );
		}

		$outstanding_balance       = WC_Subscriptions_Order::get_outstanding_balance( $original_order, $product_id );
		$failed_payment_multiplier = 1;

		if ( false == $args['checkout_renewal'] ) {

			// If there are outstanding payment amounts, add them to the order, otherwise set the order details to the values of the recurring totals
			if ( $outstanding_balance > 0 && 'yes' == get_option( WC_Subscriptions_Admin::$option_prefix . '_add_outstanding_balance', 'no' ) ) {
				$failed_payment_multiplier = WC_Subscriptions_Order::get_failed_payment_count( $original_order, $product_id );
			}

			// Set order totals based on recurring totals from the original order
			$cart_discount      = $failed_payment_multiplier * wc_format_decimal( get_post_meta( $original_order->id, '_order_recurring_discount_cart', true ) );
			$order_discount     = $failed_payment_multiplier * wc_format_decimal( get_post_meta( $original_order->id, '_order_recurring_discount_total', true ) );
			$order_shipping_tax = $failed_payment_multiplier * wc_format_decimal( get_post_meta( $original_order->id, '_order_recurring_shipping_tax_total', true ) );
			$order_shipping     = $failed_payment_multiplier * wc_format_decimal( get_post_meta( $original_order->id, '_order_recurring_shipping_total', true ) );
			$order_tax          = $failed_payment_multiplier * wc_format_decimal( get_post_meta( $original_order->id, '_order_recurring_tax_total', true ) );
			$order_total        = $failed_payment_multiplier * wc_format_decimal( get_post_meta( $original_order->id, '_order_recurring_total', true ) );

			update_post_meta( $renewal_order_id, '_cart_discount', $cart_discount );
			update_post_meta( $renewal_order_id, '_order_discount', $order_discount );
			update_post_meta( $renewal_order_id, '_order_shipping_tax', $order_shipping_tax );
			update_post_meta( $renewal_order_id, '_order_shipping', $order_shipping );
			update_post_meta( $renewal_order_id, '_order_tax', $order_tax );
			update_post_meta( $renewal_order_id, '_order_total', $order_total );

			// Set shipping for orders created with WC 2.0.n (or when we are using WC 2.0.n)
			if ( WC_Subscriptions::is_woocommerce_pre_2_1() || isset( $original_order->recurring_shipping_method ) ) {

				update_post_meta( $renewal_order_id, '_shipping_method', $original_order->recurring_shipping_method );
				update_post_meta( $renewal_order_id, '_shipping_method_title', $original_order->recurring_shipping_method_title );

				// Also set recurring shipping as it's a parent renewal order
				if ( 'parent' == $args['new_order_role'] ) {
					update_post_meta( $renewal_order_id, '_recurring_shipping_method', $original_order->recurring_shipping_method );
					update_post_meta( $renewal_order_id, '_recurring_shipping_method_title', $original_order->recurring_shipping_method_title );
				}

			}

			// Apply the recurring shipping & payment methods to child renewal orders
			if ( 'child' == $args['new_order_role'] ) {
				update_post_meta( $renewal_order_id, '_payment_method', $original_order->recurring_payment_method );
				update_post_meta( $renewal_order_id, '_payment_method_title', $original_order->recurring_payment_method_title );
			}

			// Set order taxes based on recurring taxes from the original order
			$recurring_order_taxes = WC_Subscriptions_Order::get_recurring_taxes( $original_order );

			foreach ( $recurring_order_taxes as $index => $recurring_order_tax ) {

				$item_ids = array();

				$item_ids[] = woocommerce_add_order_item( $renewal_order_id, array(
					'order_item_name' => $recurring_order_tax['name'],
					'order_item_type' => 'tax'
				) );

				// Also set recurring taxes on parent renewal orders
				if ( 'parent' == $args['new_order_role'] ) {
					$item_ids[] = woocommerce_add_order_item( $renewal_order_id, array(
						'order_item_name' => $recurring_order_tax['name'],
						'order_item_type' => 'recurring_tax'
					) );
				}

				// Add line item meta
				foreach( $item_ids as $item_id ) {

					woocommerce_add_order_item_meta( $item_id, 'compound', absint( isset( $recurring_order_tax['compound'] ) ? $recurring_order_tax['compound'] : 0 ) );
					woocommerce_add_order_item_meta( $item_id, 'tax_amount', $failed_payment_multiplier * WC_Subscriptions::format_total( $recurring_order_tax['tax_amount'] ) );
					woocommerce_add_order_item_meta( $item_id, 'shipping_tax_amount', $failed_payment_multiplier * WC_Subscriptions::format_total( $recurring_order_tax['shipping_tax_amount'] ) );

					if ( isset( $recurring_order_tax['rate_id'] ) ) {
						woocommerce_add_order_item_meta( $item_id, 'rate_id', $recurring_order_tax['rate_id'] );
					}

					if ( isset( $recurring_order_tax['label'] ) ) {
						woocommerce_add_order_item_meta( $item_id, 'label', $recurring_order_tax['label'] );
					}
				}
			}

			// Set up shipping items on renewal order
			$recurring_shipping_items = WC_Subscriptions_Order::get_recurring_shipping_methods( $original_order );

			foreach ( $recurring_shipping_items as $index => $recurring_shipping_item ) {

				$item_ids = array();

				$item_ids[] = woocommerce_add_order_item( $renewal_order_id, array(
					'order_item_name' => $recurring_shipping_item['name'],
					'order_item_type' => 'shipping'
				) );

				// Also set recurring shipping as it's a parent renewal order
				if ( 'parent' == $args['new_order_role'] ) {
					$item_ids[] = woocommerce_add_order_item( $renewal_order_id, array(
						'order_item_name' => $recurring_shipping_item['name'],
						'order_item_type' => 'recurring_shipping'
					) );
				}

				// Add shipping item meta
				foreach( $item_ids as $item_id ) {
					woocommerce_add_order_item_meta( $item_id, 'method_id', $recurring_shipping_item['method_id'] );
					woocommerce_add_order_item_meta( $item_id, 'cost', $failed_payment_multiplier * WC_Subscriptions::format_total( $recurring_shipping_item['cost'] ) );
				}
			}

		}

		// Set line totals to be recurring line totals and remove the subscription/recurring related item meta from each order item
		$order_items = WC_Subscriptions_Order::get_recurring_items( $original_order );

		// Allow extensions to add/remove items or item meta
		$order_items = apply_filters( 'woocommerce_subscriptions_renewal_order_items', $order_items, $original_order->id, $renewal_order_id, $product_id, $args['new_order_role'] );

		if ( true === $args['checkout_renewal'] ) {
			$cart_items = $woocommerce->cart->get_cart();
		}

		foreach ( $order_items as $item_index => $order_item ) {

			$renewal_order_item_name = apply_filters( 'woocommerce_subscriptions_renewal_order_item_name', $order_item['name'], $order_item, $original_order );

			// Create order line item on the renewal order
			$recurring_item_id = woocommerce_add_order_item( $renewal_order_id, array(
				'order_item_name' => $renewal_order_item_name,
				'order_item_type' => 'line_item'
			));

			if ( true === $args['checkout_renewal'] ) {

				$cart_item = array();

				foreach ( $cart_items as $item ) {
					if ( $item['product_id'] == $order_item['product_id'] && ( empty( $order_item['variation_id'] ) || $item['variation_id'] == $order_item['variation_id']) ) {
						$cart_item = $item;
					}
				}

				if ( ! empty( $cart_item ) ) {
					woocommerce_update_order_item_meta( $recurring_item_id, '_line_total', woocommerce_format_decimal( $cart_item['line_total'] ) );
					woocommerce_update_order_item_meta( $recurring_item_id, '_line_tax', woocommerce_format_decimal( $cart_item['line_tax'] ) );
					woocommerce_update_order_item_meta( $recurring_item_id, '_line_subtotal', woocommerce_format_decimal( $cart_item['line_subtotal'] ) );
					woocommerce_update_order_item_meta( $recurring_item_id, '_line_subtotal_tax', woocommerce_format_decimal( $cart_item['line_subtotal_tax'] ) );

					if ( is_object( $cart_item['data'] ) ) {
						woocommerce_update_order_item_meta( $recurring_item_id, '_tax_class', $cart_item['data']->get_tax_class() );
					}
				}

				$cart_items = $woocommerce->cart->get_cart();
			}

			$item_meta = new WC_Order_Item_Meta( $order_item['item_meta'] );

			// Remove recurring line items and set item totals based on recurring line totals
			foreach ( $item_meta->meta as $meta_key => $meta ) {

				// $meta is an array, so the item needs to be extracted from $meta[0] (just like order meta on a WC Order)
				$meta_value = $meta[0];

				if ( false === $args['checkout_renewal'] ) { // Already set earlier

					// Map line item totals based on recurring line totals
					switch( $meta_key ) {
						case '_recurring_line_total':
							woocommerce_update_order_item_meta( $recurring_item_id, '_line_total', $failed_payment_multiplier * woocommerce_format_decimal( $meta_value ) );
							break;
						case '_recurring_line_tax':
							woocommerce_update_order_item_meta( $recurring_item_id, '_line_tax', $failed_payment_multiplier * woocommerce_format_decimal( $meta_value ) );
							break;
						case '_recurring_line_subtotal':
							woocommerce_update_order_item_meta( $recurring_item_id, '_line_subtotal', $failed_payment_multiplier * woocommerce_format_decimal( $meta_value ) );
							break;
						case '_recurring_line_subtotal_tax':
							woocommerce_update_order_item_meta( $recurring_item_id, '_line_subtotal_tax', $failed_payment_multiplier * woocommerce_format_decimal( $meta_value ) );
							break;
						default:
							break;
					}
				}

				// Copy over line item meta data, with some parent/child role based exceptions for recurring amounts
				$copy_to_renewal_item = true;
				switch( $meta_key ) {
					case '_recurring_line_total':
					case '_recurring_line_tax':
					case '_recurring_line_subtotal':
					case '_recurring_line_subtotal_tax':
					case '_subscription_recurring_amount':
					case '_subscription_sign_up_fee':
					case '_subscription_period':
					case '_subscription_interval':
					case '_subscription_length':
					case '_subscription_trial_period':
					case '_subscription_end_date':
					case '_subscription_expiry_date':
					case '_subscription_start_date':
					case '_subscription_status':
					case '_subscription_completed_payments':
						if ( 'child' == $args['new_order_role'] ) {
							$copy_to_renewal_item = false;
						}
						break;
					case '_subscription_trial_length': // We never want to duplicate free trials on renewal orders
						$copy_to_renewal_item = false;
						break;
					case '_subscription_suspension_count': // We want to reset some values for the new order
					case '_subscription_trial_expiry_date':
					case '_subscription_failed_payments':
						$copy_to_renewal_item = false;
						$meta_value = 0;
						break;
					default:
						break;
				}

				// Copy existing item over to new recurring order item
				if ( $copy_to_renewal_item ) {
					woocommerce_add_order_item_meta( $recurring_item_id, $meta_key, $meta_value );
				}

			}

		}

		if ( false == $args['checkout_renewal'] ) {

			// Add fees
			foreach ( $original_order->get_fees() as $item_id => $order_fee ) {

				if ( ! isset( $order_fee['recurring_line_total'] ) ) {
					continue;
				}

				$item_id = woocommerce_add_order_item( $renewal_order_id, array(
					'order_item_name' => $order_fee['name'],
					'order_item_type' => 'fee'
				) );

				woocommerce_add_order_item_meta( $item_id, '_tax_class', $order_fee['tax_class'] );
				woocommerce_add_order_item_meta( $item_id, '_line_total', WC_Subscriptions::format_total( $order_fee['recurring_line_total'] ) );
				woocommerce_add_order_item_meta( $item_id, '_line_tax', WC_Subscriptions::format_total( $order_fee['recurring_line_tax'] ) );
			}

		}

		// Keep a record of the original order's ID on the renewal order
		update_post_meta( $renewal_order_id, '_original_order', $original_order->id, true );

		$renewal_order = new WC_Order( $renewal_order_id );

		if ( 'parent' == $args['new_order_role'] ) {
			WC_Subscriptions_Manager::process_subscriptions_on_checkout( $renewal_order_id );
			$original_order->add_order_note( sprintf( __( 'Order superseded by Renewal Order %s.', 'woocommerce-subscriptions' ), $renewal_order->get_order_number() ) );
		}

		do_action( 'woocommerce_subscriptions_renewal_order_created', $renewal_order, $original_order, $product_id, $args['new_order_role'] );

		return apply_filters( 'woocommerce_subscriptions_renewal_order_id', $renewal_order_id, $original_order, $product_id, $args['new_order_role'] );
	}

	/**
	 * Generate an order to record an automatic subscription payment.
	 *
	 * This function is hooked to the 'process_subscription_payment' which is fired when a payment gateway calls 
	 * the @see WC_Subscriptions_Manager::process_subscription_payment() function. Because manual payments will
	 * also call this function, the function only generates a renewal order if the @see WC_Order::payment_complete()
	 * will be called for the renewal order.
	 *
	 * @param int $user_id The id of the user who purchased the subscription
	 * @param string $subscription_key A subscription key of the form created by @see WC_Subscriptions_Manager::get_subscription_key()
	 * @since 1.2
	 */
	public static function generate_paid_renewal_order( $user_id, $subscription_key ) {
		global $woocommerce;

		$subscription = WC_Subscriptions_Manager::get_subscription( $subscription_key );

		$parent_order = new WC_Order( $subscription['order_id'] );

		$renewal_order_id = self::generate_renewal_order( $parent_order, $subscription['product_id'], array( 'new_order_role' => 'child' ) );

		$renewal_order = new WC_Order( $renewal_order_id );

		// Don't duplicate renewal orders
		remove_action( 'processed_subscription_payment', __CLASS__ . '::generate_paid_renewal_order', 10, 2 );

		$renewal_order->payment_complete();

		// Set the correct transaction ID on the renewal order - https://github.com/woothemes/woocommerce-subscriptions/issues/321
		update_post_meta( $renewal_order_id, '_transaction_id', get_post_meta( $subscription['order_id'], '_transaction_id', true ) );
		update_post_meta( $subscription['order_id'], '_transaction_id', get_post_meta( $subscription['order_id'], '_transaction_id_original', true ) );

		// But make sure orders are still generated for other payments in the same request
		add_action( 'processed_subscription_payment', __CLASS__ . '::generate_paid_renewal_order', 10, 2 );

		WC_Subscriptions_Manager::reactivate_subscription( $user_id, $subscription_key );

		$parent_order->add_order_note( sprintf( __( 'Subscription payment recorded in renewal order %s', 'woocommerce-subscriptions' ), $renewal_order->get_order_number() ) );

		return $renewal_order_id;
	}

	/**
	 * Generate an order to record a subscription payment failure.
	 *
	 * This function is hooked to the 'processed_subscription_payment_failure' hook called when a payment
	 * gateway calls the @see WC_Subscriptions_Manager::process_subscription_payment_failure()
	 *
	 * @param int $user_id The id of the user who purchased the subscription
	 * @param string $subscription_key A subscription key of the form created by @see WC_Subscriptions_Manager::get_subscription_key()
	 * @since 1.2
	 */
	public static function generate_failed_payment_renewal_order( $user_id, $subscription_key ) {

		$subscription = WC_Subscriptions_Manager::get_subscription( $subscription_key );

		$renewal_order_id = self::generate_renewal_order( $subscription['order_id'], $subscription['product_id'], array( 'new_order_role' => 'child' ) );

		// Mark payment completed on order
		$renewal_order = new WC_Order( $renewal_order_id );

		$renewal_order->update_status( 'failed' );

		update_post_meta( $renewal_order_id, '_transaction_id', get_post_meta( $subscription['order_id'], '_transaction_id', true ) );
		update_post_meta( $subscription['order_id'], '_transaction_id', get_post_meta( $subscription['order_id'], '_transaction_id_original', true ) );

		return $renewal_order_id;
	}

	/**
	 * Generate an order to record a subscription payment.
	 *
	 * This function is hooked to the scheduled subscription payment hook to create a pending
	 * order for each scheduled subscription payment.
	 *
	 * When a payment gateway calls the @see WC_Subscriptions_Manager::process_subscription_payment()
	 * @see WC_Order::payment_complete() will be called for the renewal order.
	 *
	 * @param int $user_id The id of the user who purchased the subscription
	 * @param string $subscription_key A subscription key of the form created by @see WC_Subscriptions_Manager::get_subscription_key()
	 * @since 1.2
	 */
	public static function maybe_generate_manual_renewal_order( $user_id, $subscription_key ) {

		if ( WC_Subscriptions_Manager::requires_manual_renewal( $subscription_key, $user_id ) ) {

			$subscription = WC_Subscriptions_Manager::get_subscription( $subscription_key );

			// $0 renewals don't require a pending renewal order, instead a paid renewal order will be created by WC_Subscriptions_Manager::maybe_process_subscription_payment()
			if ( WC_Subscriptions_Order::get_recurring_total( $subscription['order_id'] ) > 0 ) {

				$renewal_order_id = self::generate_renewal_order( $subscription['order_id'], $subscription['product_id'], array( 'new_order_role' => 'child' ) );

				do_action( 'woocommerce_generated_manual_renewal_order', $renewal_order_id );

			}

		}

	}

	/**
	 * If the payment for a renewal order has previously failed and is then paid, then the
	 * @see WC_Subscriptions_Manager::process_subscription_payments_on_order() function would
	 * never be called. This function makes sure it is called.
	 *
	 * @param WC_Order|int $order A WC_Order object or ID of a WC_Order order.
	 * @since 1.2
	 */
	public static function process_failed_renewal_order_payment( $order_id ) {
		if ( self::is_renewal( $order_id, array( 'order_role' => 'child' ) ) && ! WC_Subscriptions_Order::requires_manual_renewal( self::get_parent_order( $order_id ) ) ) {
			add_action( 'woocommerce_payment_complete', __CLASS__ . '::process_subscription_payment_on_child_order', 10, 1 );
		}
	}

	/**
	 * Records manual payment of a renewal order against a subscription.
	 *
	 * @param WC_Order|int $order A WC_Order object or ID of a WC_Order order.
	 * @since 1.2
	 */
	public static function maybe_record_renewal_order_payment( $order_id ) {
		if ( self::is_renewal( $order_id, array( 'order_role' => 'child' ) ) && WC_Subscriptions_Order::requires_manual_renewal( self::get_parent_order( $order_id ) ) ) {
			self::process_subscription_payment_on_child_order( $order_id );
		}
	}

	/**
	 * Records manual payment of a renewal order against a subscription.
	 *
	 * @param WC_Order|int $order A WC_Order object or ID of a WC_Order order.
	 * @since 1.2
	 */
	public static function maybe_record_renewal_order_payment_failure( $order_id ) {
		if ( self::is_renewal( $order_id, array( 'order_role' => 'child' ) ) && WC_Subscriptions_Order::requires_manual_renewal( self::get_parent_order( $order_id ) ) ) {
			self::process_subscription_payment_on_child_order( $order_id, 'failed' );
		}
	}

	/**
	 * If the payment for a renewal order has previously failed and is then paid, we need to make sure the
	 * subscription payment function is called.
	 *
	 * @param int $user_id The id of the user who purchased the subscription
	 * @param string $subscription_key A subscription key of the form created by @see WC_Subscriptions_Manager::get_subscription_key()
	 * @since 1.2
	 */
	public static function process_subscription_payment_on_child_order( $order_id, $payment_status = 'completed' ) {

		if ( self::is_renewal( $order_id, array( 'order_role' => 'child' ) ) ) {

			$child_order = new WC_Order( $order_id );

			$parent_order = self::get_parent_order( $child_order );

			$subscriptions_in_order = $child_order->get_items();

			// Should only be one subscription in the renewal order, but just in case
			foreach ( $subscriptions_in_order as $item ) {

				$item_id = WC_Subscriptions_Order::get_items_product_id( $item );

				if ( WC_Subscriptions_Order::is_item_subscription( $parent_order, $item_id ) ) {

					if ( 'failed' == $payment_status ) {

						// Don't duplicate renewal order
						remove_action( 'processed_subscription_payment_failure', __CLASS__ . '::generate_failed_payment_renewal_order', 10, 2 );

						WC_Subscriptions_Manager::process_subscription_payment_failure_on_order( $parent_order->id, $item_id );

						// But make sure orders are still generated for other payments in the same request
						add_action( 'processed_subscription_payment_failure', __CLASS__ . '::generate_failed_payment_renewal_order', 10, 2 );

					} else {

						// Don't duplicate renewal order
						remove_action( 'processed_subscription_payment', __CLASS__ . '::generate_paid_renewal_order', 10, 2 );

						WC_Subscriptions_Manager::process_subscription_payments_on_order( $parent_order->id, $item_id );

						// But make sure orders are still generated for other payments in the same request
						add_action( 'processed_subscription_payment', __CLASS__ . '::generate_paid_renewal_order', 10, 2 );

						// Reactivate the subscription - activate_subscription doesn't operate on child orders
						$subscription_key = WC_Subscriptions_Manager::get_subscription_key( $parent_order->id, $item_id );
						WC_Subscriptions_Manager::reactivate_subscription( $parent_order->customer_user, $subscription_key );
					}
				}
			}
		}
	}

	/* Helper functions */

	/**
	 * Check if a given order is a subscription renewal order and optionally, if it is a renewal order of a certain role.
	 *
	 * If an order 
	 *
	 * @param WC_Order|int $order The WC_Order object or ID of a WC_Order order.
	 * @param array $args (optional) An array of name => value flags:
	 *         'order_role' string (optional) A specific role to check the order against. Either 'parent' or 'child'.
	 *         'via_checkout' Indicates whether to check if the renewal order was via the cart/checkout process.
	 * @since 1.2
	 */
	public static function is_renewal( $order, $args = array() ) {

		if ( ! is_object( $order ) ) {
			$order = new WC_Order( $order );
		}

		if ( ! is_array( $args ) ) {
			_deprecated_argument( __CLASS__ . '::' . __FUNCTION__, '1.3', __( 'Second parameter is now an array of name => value pairs. Use array( "order_role" => "child" ) instead.', 'woocommerce-subscriptions' ) );
			$args = array(
				'order_role' => $args,
			);
		}

		$args = wp_parse_args( $args, array(
			'order_role'   => '',
			'via_checkout' => false,
			)
		);

		if ( WC_Subscriptions_Order::get_meta( $order, 'original_order', false ) ) {
			$is_renewal = true;
		} else {
			$is_renewal = false;
		}

		if ( ! empty ( $args['order_role'] ) ) {
			$order_post = get_post( $order->id );

			if ( 'parent' == $args['order_role'] && 0 != $order_post->post_parent ) { // It's a child order
				$is_renewal = false;
			} elseif ( 'child' == $args['order_role'] && 0 == $order_post->post_parent ) { // It's a parent order
				$is_renewal = false;
			}
		}

		// Further qualify whether renewal order was via the cart/checkout process
		if ( true === $args['via_checkout'] && 'yes' != get_post_meta( $order->id, '_checkout_renewal', true ) ) {
			$is_renewal = false;
		}

		return apply_filters( 'woocommerce_subscriptions_is_renewal_order', $is_renewal, $order );
	}

	/**
	 * Get the ID of the parent order for a subscription renewal order. 
	 *
	 * @param WC_Order|int $order The WC_Order object or ID of a WC_Order order.
	 * @since 1.2
	 */
	public static function get_parent_order_id( $renewal_order ) {

		$parent_order = self::get_parent_order( $renewal_order );

		return $parent_order->id;
	}

	/**
	 * Get the parent order for a subscription renewal order.
	 *
	 * @param WC_Order|int $order The WC_Order object or ID of a WC_Order order.
	 * @since 1.2
	 */
	public static function get_parent_order( $renewal_order ) {

		if ( ! is_object( $renewal_order ) ) {
			$renewal_order = new WC_Order( $renewal_order );
		}

		$order_post = get_post( $renewal_order->id );

		if ( 0 == $order_post->post_parent ) {  // The renewal order is the parent order
			$parent_order = $renewal_order;
		} else {
			$parent_order = new WC_Order( $order_post->post_parent );
		}

		return apply_filters( 'woocommerce_subscriptions_parent_order', $parent_order, $renewal_order );
	}

	/**
	 * Returns the number of renewals for a given parent order
	 *
	 * @param int $order_id The ID of a WC_Order object.
	 * @since 1.2
	 */
	public static function get_renewal_order_count( $order_id ) {
		global $wpdb;

		/** @var wpdb $wpdb  */
		$renewal_order_count = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_parent = %d AND post_type = 'shop_order'", $order_id ) );

		return apply_filters( 'woocommerce_subscriptions_renewal_order_count', $renewal_order_count, $order_id );
	}

	/**
	 * Returns the renewal orders for a given parent order
	 *
	 * @param int $order_id The ID of a WC_Order object.
	 * @param string $output (optional) How you'd like the result. Can be 'ID' for IDs only or 'WC_Order' for order objects.
	 * @since 1.2
	 */
	public static function get_renewal_orders( $order_id, $output = 'ID' ) {
		global $wpdb;

		$renewal_order_ids = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_parent = %d AND post_type = 'shop_order'", $order_id ) );

		if ( 'WC_Order' == $output ) {

			$renewal_orders = array();

			foreach ( $renewal_order_ids as $renewal_order_id ) {
				$renewal_orders[ $renewal_order_id ] = new WC_Order( $renewal_order_id );
			}

		} else {

			$renewal_orders = $renewal_order_ids;

		}

		return apply_filters( 'woocommerce_subscriptions_renewal_orders', $renewal_orders, $order_id );
	}

	/**
	 * Check if a given subscription can be renewed. 
	 *
	 * For a subscription to be renewable, it must:
	 * 1. be inactive (expired or cancelled)
	 * 2. had at least one payment, to avoid circumventing sign-up fees
	 * 3. its parent order must not have already been superseded by a renewal order (to prevent
	 * displaying "Renew" links on subscriptions that have already been renewed)
	 * 4. the product to which the subscription was purchase must not have been deleted
	 *
	 * @param string $subscription_key A subscription key of the form created by @see WC_Subscriptions_Manager::get_subscription_key()
	 * @param int $user_id The ID of the user who owns the subscriptions. Although this parameter is optional, if you have the User ID you should pass it to improve performance.
	 * @since 1.2
	 */
	public static function can_subscription_be_renewed( $subscription_key, $user_id = '' ) {

		$subscription = WC_Subscriptions_Manager::get_subscription( $subscription_key );

		if ( empty( $subscription ) ) {
			$subscription_can_be_renewed = false;
		} else {

			$renewal_orders = get_posts( array(
				'meta_key'    => '_original_order', 
				'meta_value'  => $subscription['order_id'], 
				'post_type'   => 'shop_order', 
				'post_status' => 'any',
				'post_parent' => 0 
				)
			);

			$product = ( ! empty( $subscription['variation_id'] ) ) ? get_product( $subscription['variation_id'] ) : get_product( $subscription['product_id'] );

			if ( empty( $renewal_orders ) && ! empty( $subscription['completed_payments'] ) && false !== $product && in_array( $subscription['status'], array( 'cancelled', 'expired', 'trash', 'failed' ) ) ) {
				$subscription_can_be_renewed = true;
			} else {
				$subscription_can_be_renewed = false;
			}

		}

		return apply_filters( 'woocommerce_can_subscription_be_renewed', $subscription_can_be_renewed, $subscription, $subscription_key, $user_id );
	}

	/**
	 * Returns a URL including required parameters for an authenticated user to renew a subscription
	 *
	 * @param string $subscription_key A subscription key of the form created by @see WC_Subscriptions_Manager::get_subscription_key()
	 * @since 1.2
	 */
	public static function get_users_renewal_link( $subscription_key, $role = 'parent' ) {

		$renewal_url = add_query_arg( array( 'renew_subscription' => $subscription_key, 'role' => $role ) );
		$renewal_url = wp_nonce_url( $renewal_url, __FILE__ );

		return apply_filters( 'woocommerce_subscriptions_users_renewal_link', $renewal_url, $subscription_key );
	}

	/**
	 * Returns a URL including required parameters for an authenticated user to renew a subscription by product ID.
	 *
	 * @param string $subscription_key A subscription key of the form created by @see WC_Subscriptions_Manager::get_subscription_key()
	 * @since 1.2
	 */
	public static function get_users_renewal_link_for_product( $product_id ) {

		$renewal_url = '';

		if ( is_user_logged_in() ) {
			$users_subscriptions = WC_Subscriptions_Manager::get_users_subscriptions();
			foreach ( $users_subscriptions as $subscription_key => $users_subscription ) {
				if ( $users_subscription['product_id'] == $product_id && self::can_subscription_be_renewed( $subscription_key ) ) {
					$renewal_url = self::get_users_renewal_link( $subscription_key );
					break;
				}
			}
		}

		return $renewal_url;
	}

	/**
	 * Checks if the current request is by a user to renew their subscription, and if it is
	 * set up a subscription renewal via the cart for the product/variation that is being renewed.
	 *
	 * @since 1.2
	 */
	public static function maybe_create_renewal_order_for_user() {
		global $woocommerce;

		if ( isset( $_GET['renew_subscription'] ) && isset( $_GET['_wpnonce'] )  ) {

			$user_id      = get_current_user_id();
			$subscription = WC_Subscriptions_Manager::get_subscription( $_GET['renew_subscription'] );

			if ( isset( $_GET['role'] ) ) {
				$role = woocommerce_clean( $_GET['role'] );
			} else {
				$role = 'parent';
			}

			$redirect_to = get_permalink( woocommerce_get_page_id( 'myaccount' ) );

			if ( wp_verify_nonce( $_GET['_wpnonce'], __FILE__ ) === false ) {

				WC_Subscriptions::add_notice( __( 'There was an error with the renewal request. Please try again.', 'woocommerce-subscriptions' ), 'error' );

			} elseif ( empty( $subscription ) ) {

				WC_Subscriptions::add_notice( __( 'That doesn\'t appear to be one of your subscriptions.', 'woocommerce-subscriptions' ), 'error' );

			} elseif ( ! self::can_subscription_be_renewed( $_GET['renew_subscription'], $user_id ) ) {

				WC_Subscriptions::add_notice( __( 'That subscription can not be renewed. Please contact us if you need assistance.', 'woocommerce-subscriptions' ), 'error' );

			} else {

				$original_order = new WC_Order( $subscription['order_id'] );

				$product_id = $subscription['product_id'];
				$product    = get_product( $product_id );
				$item       = WC_Subscriptions_Order::get_item_by_product_id( $original_order, $product_id );

				$variation_id   = '';
				$variation_data = array();

				// Display error message for deleted products
				if ( false === $product ) {

					WC_Subscriptions::add_notice( self::$product_deleted_error_message, 'error' );

				// Make sure we don't actually need the variation ID (if the product was a variation, it will have a variation ID; however, if the product has changed from a simple subscription to a variable subscription, there will be no variation_id)
				} elseif ( $product->is_type( array( 'variable-subscription' ) ) && ! empty( $item['variation_id'] ) ) {

					$variation_id   = $item['variation_id'];
					$variation      = get_product( $variation_id );

					if ( false === $variation ) {
						WC_Subscriptions::add_notice( self::$product_deleted_error_message, 'error' );
						$variation_data = array();
					} else {
						$variation_data = $variation->get_variation_attributes();
					}

				} elseif ( $product->is_type( array( 'subscription_variation' ) ) ) { // Handle existing renewal orders incorrectly using variation_id as the product_id

					$product_id     = $product->id;
					$variation_id   = $product->get_variation_id();
					$variation_data = $product->get_variation_attributes();

				}

				// Make sure a value is set for each attribute - handles the "catch all" variations which do not have a value set for an attribute, but which will have been set on the cart item when the customer added the item
				if ( ! empty( $variation_data ) ) {
					foreach ( $variation_data as $attribute_name => $attribute_value ) {
						if ( empty( $attribute_value ) && ! empty( $item[ str_replace( 'attribute_', '', $attribute_name ) ] ) ) {
							$variation_data[ $attribute_name ] = $item[ str_replace( 'attribute_', '', $attribute_name ) ];
						}
					}
				}

				$cart_item_data = array(
					'subscription_renewal' => array(
						'original_order' => $original_order->id,
						'failed_order'   => null,
						'role'           => $role
					)
				);

				$cart_item_data = apply_filters( 'woocommerce_order_again_cart_item_data', $cart_item_data, $item, $original_order );

				woocommerce_empty_cart();

				$woocommerce->cart->add_to_cart( $product_id, 1, $variation_id, $variation_data, $cart_item_data );

				WC_Subscriptions::add_notice( __( 'Renew your subscription.', 'woocommerce-subscriptions' ), 'success' );

				$redirect_to = $woocommerce->cart->get_checkout_url();
			}

			wp_safe_redirect( $redirect_to );
			exit;
		}
	}

	/**
	 * Check if a payment is being made on a failed renewal order from 'My Account'. If so,
	 * redirect the order into a cart/checkout payment flow.
	 *
	 * @since 1.3
	 */
	public static function before_woocommerce_pay() {
		global $woocommerce, $wp;

		if ( isset( $_GET['pay_for_order'] ) && ( ( isset( $_GET['order'] ) && isset( $_GET['order_id'] ) ) ||  isset( $_GET['key'] ) && isset( $wp->query_vars['order-pay'] ) ) ) {

			// Pay for existing order
			$order_key = ( isset( $_GET[ 'key' ] ) ? $_GET[ 'key' ] : $_GET[ 'order' ] ); // WC 2.1 compatibility
			$order_id = ( isset( $wp->query_vars['order-pay'] ) ) ? $wp->query_vars['order-pay'] : absint( $_GET['order_id'] );
			$order = new WC_Order( $order_id );

			$failed_order_replaced_by = get_post_meta( $order_id , '_failed_order_replaced_by', true);

			if ( is_numeric( $failed_order_replaced_by ) ) {
				WC_Subscriptions::add_notice( sprintf( __( 'Sorry, this failed order has already been paid. See order %s.', 'woocommerce-subscriptions' ), $failed_order_replaced_by ), 'error' );
				wp_safe_redirect( get_permalink( woocommerce_get_page_id( 'myaccount' ) ) );
				exit;
			}

			if ( $order->id == $order_id && $order->order_key == $order_key && in_array( $order->status, array( 'pending', 'failed' ) ) && self::is_renewal( $order ) ) {

				// If order being paid is a parent order, get the original order, else query parent_order
				if ( self::is_renewal( $order_id, array( 'order_role' => 'parent' ) ) ) {

					$role = 'parent';
					$original_order = new WC_Order( WC_Subscriptions_Order::get_meta( $order, 'original_order', false ) );

				} elseif ( self::is_renewal( $order_id, array( 'order_role' => 'child' ) ) ) {

					$role = 'child';
					$original_order = self::get_parent_order( $order_id );

				}

				$order_items      = WC_Subscriptions_Order::get_recurring_items( $original_order );
				$first_order_item = reset( $order_items );
				$product_id       = WC_Subscriptions_Order::get_items_product_id( $first_order_item );
				$product          = get_product( $product_id );
				$item             = WC_Subscriptions_Order::get_item_by_product_id( $original_order, $product_id );

				$variation_id   = '';
				$variation_data = array();

				// Display error message for deleted products
				if ( false === $product ) {

					WC_Subscriptions::add_notice( self::$product_deleted_error_message, 'error' );

				// Make sure we don't actually need the variation ID (if the product was a variation, it will have a variation ID; however, if the product has changed from a simple subscription to a variable subscription, there will be no variation_id)
				} elseif ( $product->is_type( array( 'variable-subscription' ) ) && ! empty( $item['variation_id'] ) ) {

					$variation_id   = $item['variation_id'];
					$variation      = get_product( $variation_id );

					// Display error message for deleted product variations
					if ( false === $variation ) {
						WC_Subscriptions::add_notice( self::$product_deleted_error_message, 'error' );
					} else {
						$variation_data = $variation->get_variation_attributes();
					}

				} elseif ( $product->is_type( array( 'subscription_variation' ) ) ) { // Handle existing renewal orders incorrectly using variation_id as the product_id

					$product_id     = $product->id;
					$variation_id   = $product->get_variation_id();
					$variation_data = $product->get_variation_attributes();

				}

				// Make sure a value is set for each attribute - handles the "catch all" variations which do not have a value set for an attribute, but which will have been set on the cart item when the customer added the item
				if ( ! empty( $variation_data ) ) {
					foreach ( $variation_data as $attribute_name => $attribute_value ) {
						if ( empty( $attribute_value ) && ! empty( $item[ str_replace( 'attribute_', '', $attribute_name ) ] ) ) {
							$variation_data[ $attribute_name ] = $item[ str_replace( 'attribute_', '', $attribute_name ) ];
						}
					}
				}

				$woocommerce->cart->empty_cart( true );
				$woocommerce->cart->add_to_cart( $product_id, 1, $variation_id, $variation_data, array(
					'subscription_renewal' => array(
						'original_order' => $original_order->id,
						'failed_order'   => $order_id,
						'role'           => $role
						)
					)
				);

				wp_safe_redirect( $woocommerce->cart->get_checkout_url() );
				exit;
			}
		}
	}

	/**
	 * Process a renewal payment when a customer has completed the payment for a renewal payment which previously failed.
	 *
	 * @since 1.3
	 */
	public static function maybe_process_failed_renewal_order_payment( $order_id ) {

		if ( self::is_renewal( $order_id, array( 'via_checkout' => true ) ) ) {

			add_action( 'reactivated_subscription', __CLASS__ . '::trigger_processed_failed_renewal_order_payment_hook', 10, 2 );

			self::process_subscription_payment_on_child_order( $order_id );

			remove_action( 'reactivated_subscription', __CLASS__ . '::trigger_processed_failed_renewal_order_payment_hook', 10, 2 );

			$renewal_order = new WC_Order( $order_id );

			$original_order = self::get_parent_order( $renewal_order );

			do_action( 'woocommerce_subscriptions_paid_for_failed_renewal_order', $renewal_order, $original_order );
		}
	}

	/**
	 * Trigger a hook when a subscription suspended due to a failed renewal payment is reactivated
	 *
	 * @since 1.3
	 */
	public static function trigger_processed_failed_renewal_order_payment_hook( $user_id, $subscription_key ) {

		$subscription   = WC_Subscriptions_Manager::get_subscription( $subscription_key );
		$original_order = new WC_Order( $subscription['order_id'] );

		do_action( 'woocommerce_subscriptions_processed_failed_renewal_order_payment', $subscription_key, $original_order );
	}

	/**
	 * Adds a renewal orders section to the Related Orders meta box displayed on subscription orders.
	 *
	 * @since 1.4
	 */
	public static function renewal_orders_meta_box_section( $order, $post ) {

		if ( self::is_renewal( $order, array( 'order_role' => 'child' ) ) ) {
			$parent_id = self::get_parent_order_id( $order );
		} elseif ( WC_Subscriptions_Order::order_contains_subscription( $order ) ) {
			$parent_id = $order->id;
		}

		// Find any renewal orders associated with this order.
		$items = get_posts( array(
			'post_type'   => $post->post_type,
			'post_parent' => $parent_id,
			'post_status' => 'any',
			'numberposts' => -1,
		));

		if ( self::is_renewal( $order, array( 'order_role' => 'child' ) ) ) {
			$parent_order = new WC_Order( $parent_id );
			printf(
				'<p>%1$s <a href="%2$s">%3$s</a></p>',
				__( 'Initial Order:', 'woocommerce-subscriptions' ),
				get_edit_post_link( $parent_id ),
				$parent_order->get_order_number()
			);
		} elseif ( self::is_renewal( $order, array( 'order_role' => 'parent' ) ) ) {
				$original_order_id = WC_Subscriptions_Order::get_meta( $order, 'original_order', false );
				$original_order = new WC_Order( $original_order_id );
				printf(
					'<p>%1$s <a href="%2$s">%3$s</a></p>',
					__( 'Renewal of Subscription Purchased in Order:', 'woocommerce-subscriptions' ),
					get_edit_post_link( $original_order_id ),
					$original_order->get_order_number()
				);
		} else {

			$original_order_post = get_posts( array(
					'meta_key'    => '_original_order',
					'meta_value'  => $parent_id,
					'post_parent' => 0,
					'post_type'   => 'shop_order',
					'post_status' => 'any',
				)
			);

			if ( ! empty( $original_order_post ) && isset( $original_order_post[0] ) ) {
				$original_order = new WC_Order( $original_order_post[0]->ID );
				printf(
					'<p>%1$s <a href="%2$s">%3$s</a></p>',
					__( 'Renewed by Subscription Purchased in Order:', 'woocommerce-subscriptions' ),
					get_edit_post_link( $original_order->id ),
					$original_order->get_order_number()
				);
			}
		}

		if ( empty ( $items ) ) {
			printf(
				' <p class="renewal-subtitle">%s</p>',
				__( 'No renewal payments yet.', 'woocommerce-subscriptions' )
			);
		} else {
			printf(
				'<p class="renewal-subtitle">%s</p>',
				__( 'Renewal Orders:', 'woocommerce-subscriptions' )
			);
			echo '<ul class="renewal-orders">';
			foreach( $items as $item ) {
				$renewal_order = new WC_Order($item->ID);

				if ( $item->ID == $post->ID ) {
					printf('<li><strong>%s</strong></li>', $renewal_order->get_order_number() );
				} else {
					printf(
						'<li><a href="%1$s">%2$s</a></li>',
						get_edit_post_link($item->ID),
						$renewal_order->get_order_number()
					);
				}
			}
			echo '</ul>';
		}

	}

	/**
	 * If a product is being marked as not purchasable because it is limited and the customer has a subscription,
	 * but the current request is to switch the subscription, then mark it as purchasable.
	 *
	 * @since 1.5
	 * @return bool
	 */
	public static function is_purchasable( $is_purchasable, $product ) {
		global $woocommerce;

		if ( false === $is_purchasable && WC_Subscriptions_Product::is_subscription( $product->id ) && 'no' != $product->limit_subscriptions && is_user_logged_in() && ( ( 'active' == $product->limit_subscriptions && WC_Subscriptions_Manager::user_has_subscription( 0, $product->id, 'on-hold' ) ) || WC_Subscriptions_Manager::user_has_subscription( 0, $product->id, $product->limit_subscriptions ) ) ) {

			// Adding to cart from the product page
			if ( isset( $_GET['renew_subscription'] ) || isset( $_GET['manual_subscription_renewal'] ) ) {

				$is_purchasable = true;

			// Validating when restoring cart from session
			} elseif ( $cart_item = WC_Subscriptions_Cart::cart_contains_subscription_renewal( 'parent' ) ) {

				$is_purchasable = true;

			// Restoring cart from session, so need to check the cart in the session (WC_Subscriptions_Cart::cart_contains_subscription_renewal() only checks the cart)
			} elseif ( isset( $woocommerce->session->cart ) ) {

				foreach ( $woocommerce->session->cart as $cart_item_key => $cart_item ) {
					if ( $product->id == $cart_item['product_id'] && isset( $cart_item['subscription_renewal'] ) ) {
						$is_purchasable = true;
						break;
					}
				}

			}
		}

		return $is_purchasable;
	}

	/**
	 * Trigger a special hook for payments on a completed renewal order.
	 *
	 * @since 1.5.4
	 */
	public static function trigger_renewal_payment_complete( $order_id ) {
		if ( self::is_renewal( $order_id ) ) {
			do_action( 'woocommerce_renewal_order_payment_complete', $order_id );
		}
	}

	/**
	 * Flag payment of manual renewal orders.
	 *
	 * This is particularly important to ensure renewals of limited subscriptions can be completed.
	 *
	 * @since 1.5.5
	 */
	public static function get_checkout_payment_url( $pay_url, $order ) {

		if ( self::is_renewal( $order, array( 'order_role' => 'child' ) ) ) {
			$pay_url = add_query_arg( array( 'manual_subscription_renewal' => 'true' ), $pay_url );
		}

		return $pay_url;
	}

	/**
	 * When restoring the cart from the session, if the cart item contains addons, but is also
	 * a subscription renewal, do not adjust the price because the original order's price will
	 * be used, and this includes the addons amounts.
	 *
	 * @since 1.5.5
	 */
	public static function product_addons_adjust_price( $adjust_price, $cart_item ) {

		if ( true === $adjust_price && isset( $cart_item['subscription_renewal'] ) && 'parent' == $cart_item['subscription_renewal']['role'] ) {
			$adjust_price = false;
		}

		return $adjust_price;
	}

	/* Deprecated functions */

	/**
	 * Hooks to the renewal order created action to determine if the order should be emailed to the customer. 
	 *
	 * @param WC_Order|int $order The WC_Order object or ID of a WC_Order order.
	 * @since 1.2
	 * @deprecated 1.4
	 */
	public static function maybe_send_customer_renewal_order_email( $order ) {
		_deprecated_function( __CLASS__ . '::' . __FUNCTION__, '1.4' );
		if ( 'yes' == get_option( WC_Subscriptions_Admin::$option_prefix . '_email_renewal_order' ) ) {
			self::send_customer_renewal_order_email( $order );
		}
	}

	/**
	 * Processing Order
	 *
	 * @param WC_Order|int $order The WC_Order object or ID of a WC_Order order.
	 * @since 1.2
	 * @deprecated 1.4
	 */
	public static function send_customer_renewal_order_email( $order ) {
		global $woocommerce;

		_deprecated_function( __CLASS__ . '::' . __FUNCTION__, '1.4' );

		if ( ! is_object( $order ) ) {
			$order = new WC_Order( $order );
		}

		$mailer = $woocommerce->mailer();
		$mails  = $mailer->get_emails();

		$mails['WCS_Email_Customer_Renewal_Invoice']->trigger( $order->id );
	}

	/**
	 * Change the email subject of the new order email to specify the order is a subscription renewal order
	 *
	 * @param string $subject The default WooCommerce email subject
	 * @param WC_Order $order The WC_Order object which the email relates to
	 * @since 1.2
	 * @deprecated 1.4
	 */
	public static function email_subject_new_renewal_order( $subject, $order ) {
		_deprecated_function( __CLASS__ . '::' . __FUNCTION__, '1.4' );

		if ( self::is_renewal( $order ) ) {
			$blogname = wp_specialchars_decode( get_option( 'blogname' ), ENT_QUOTES );
			$subject  = apply_filters(
				'woocommerce_subscriptions_email_subject_new_renewal_order',
				sprintf( __( '[%s] New Subscription Renewal Order (%s)', 'woocommerce-subscriptions' ), $blogname, $order->get_order_number() ),
				$order
			);
		}

		return $subject;
	}

	/**
	 * Change the email subject of the processing order email to specify the order is a subscription renewal order
	 *
	 * @param string $subject The default WooCommerce email subject
	 * @param WC_Order $order The WC_Order object which the email relates to
	 * @since 1.2
	 * @deprecated 1.4
	 */
	public static function email_subject_customer_procesing_renewal_order( $subject, $order ) {
		_deprecated_function( __CLASS__ . '::' . __FUNCTION__, '1.4' );

		if ( self::is_renewal( $order ) ) {
			$blogname = wp_specialchars_decode( get_option( 'blogname' ), ENT_QUOTES );
			$subject  = apply_filters(
				'woocommerce_subscriptions_email_subject_customer_procesing_renewal_order',
				sprintf( __( '[%s] Subscription Renewal Order', 'woocommerce-subscriptions' ), $blogname ),
				$order
			);
		}

		return $subject;
	}

	/**
	 * Change the email subject of the completed order email to specify the order is a subscription renewal order
	 *
	 * @param string $subject The default WooCommerce email subject
	 * @param WC_Order $order The WC_Order object which the email relates to
	 * @since 1.2
	 * @deprecated 1.4
	 */
	public static function email_subject_customer_completed_renewal_order( $subject, $order ) {
		_deprecated_function( __CLASS__ . '::' . __FUNCTION__, '1.4' );

		if ( self::is_renewal( $order ) ) {
			$blogname = wp_specialchars_decode( get_option( 'blogname' ), ENT_QUOTES );
			$subject  = apply_filters(
				'woocommerce_subscriptions_email_subject_customer_completed_renewal_order',
				sprintf( __( '[%s] Subscription Renewal Order', 'woocommerce-subscriptions' ), $blogname ),
				$order
			);
		}

		return $subject;
	}
}
WC_Subscriptions_Renewal_Order::init();
