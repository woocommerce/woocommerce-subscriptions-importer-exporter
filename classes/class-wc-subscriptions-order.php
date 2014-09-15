<?php
/**
 * Subscriptions Order Class
 *
 * Mirrors and overloads a few functions in the WC_Order class to work for subscriptions.
 *
 * @package		WooCommerce Subscriptions
 * @subpackage	WC_Subscriptions_Order
 * @category	Class
 * @author		Brent Shepherd
 */
class WC_Subscriptions_Order {

	/**
	 * Store a record of which product/item IDs need to have subscriptions details updated
	 * whenever a subscription is saved via the "Edit Order" page.
	 */
	private static $requires_update = array(
		'next_billing_date' => array(),
		'trial_expiration'  => array(),
		'expiration_date'   => array(),
	);

	/**
	 * A flag to indicate whether subscription price strings should include the subscription length
	 */
	public static $recurring_only_price_strings = false;

	/**
	 * Bootstraps the class and hooks required actions & filters.
	 *
	 * @since 1.0
	 */
	public static function init() {
		add_filter( 'woocommerce_get_order_item_totals', __CLASS__ . '::get_order_item_totals', 10, 2 );
		add_filter( 'woocommerce_get_formatted_order_total', __CLASS__ . '::get_formatted_order_total', 10, 2 );
		add_filter( 'woocommerce_order_formatted_line_subtotal', __CLASS__ . '::get_formatted_line_total', 10, 3 );
		add_filter( 'woocommerce_order_subtotal_to_display', __CLASS__ . '::get_subtotal_to_display', 10, 3 );
		add_filter( 'woocommerce_order_cart_discount_to_display', __CLASS__ . '::get_cart_discount_to_display', 10, 2 );
		add_filter( 'woocommerce_order_discount_to_display', __CLASS__ . '::get_order_discount_to_display', 10, 2 );
		add_filter( 'woocommerce_order_shipping_to_display', __CLASS__ . '::get_shipping_to_display', 10, 2 );

		add_action( 'woocommerce_thankyou', __CLASS__ . '::subscription_thank_you' );

		add_action( 'manage_shop_order_posts_custom_column', __CLASS__ . '::add_contains_subscription_hidden_field', 10, 1 );
		add_action( 'woocommerce_admin_order_data_after_order_details', __CLASS__ . '::contains_subscription_hidden_field', 10, 1 );

		// Save subscription related order meta data when submitting the Edit Order screen
		add_action( 'woocommerce_process_shop_order_meta', __CLASS__ . '::pre_process_shop_order_meta', 0, 2 ); // Need to fire before WooCommerce
		add_action( 'woocommerce_process_shop_order_meta', __CLASS__ . '::process_shop_order_item_meta', 11, 2 ); // Then fire after WooCommerce

		// Record initial payment against the subscription & set start date based on that payment
		add_action( 'woocommerce_payment_complete', __CLASS__ . '::maybe_record_order_payment', 9, 1 );
		add_action( 'woocommerce_order_status_processing', __CLASS__ . '::maybe_record_order_payment', 9, 1 );
		add_action( 'woocommerce_order_status_completed', __CLASS__ . '::maybe_record_order_payment', 9, 1 );

		// Prefill subscription item meta when manually adding a subscription to an order
		add_action( 'woocommerce_ajax_order_item', __CLASS__ . '::prefill_order_item_meta', 10, 2 );
		add_action( 'wp_ajax_woocommerce_subscriptions_calculate_line_taxes', __CLASS__ . '::calculate_recurring_line_taxes', 10 );
		add_action( 'wp_ajax_woocommerce_subscriptions_remove_line_tax', __CLASS__ . '::remove_line_tax', 10 );
		add_action( 'wp_ajax_woocommerce_subscriptions_add_line_tax', __CLASS__ . '::add_line_tax' );

		// Don't allow downloads for inactive subscriptions
		add_action( 'woocommerce_order_is_download_permitted', __CLASS__ . '::is_download_permitted', 10, 2 );

		// Load Subscription related order data when populating an order
		add_filter( 'woocommerce_load_order_data', __CLASS__ . '::load_order_data' );

		// Don't display all subscription meta data on the Edit Order screen
		add_filter( 'woocommerce_hidden_order_itemmeta', __CLASS__ . '::hide_order_itemmeta' );

		// Sometimes, even if the order total is $0, the order still needs payment
		add_filter( 'woocommerce_order_needs_payment', __CLASS__ . '::order_needs_payment' , 10, 3 );

		// Make sure that the correct recurring payment method is set on an order
		add_action( 'woocommerce_payment_complete', __CLASS__ . '::set_recurring_payment_method', 10, 1 );

		// Add subscription information to the order complete emails.
		add_action( 'woocommerce_email_after_order_table', __CLASS__ . '::add_sub_info_email', 15, 2);

		// Add dropdown to admin orders screen to filter on order type
		add_action( 'restrict_manage_posts', __CLASS__ . '::restrict_manage_subscriptions', 50 );

		// Add filer to queries on admin orders screen to filter on order type
		add_filter( 'request', __CLASS__ . '::orders_by_type_query' );

		// If an order includes recurring shipping but not up-front shipping, make sure it returns a shipping method
		add_filter( 'woocommerce_order_shipping_method', __CLASS__ . '::order_shipping_method', 1, 2 );

		add_filter( 'wc_order_is_editable', __CLASS__ . '::is_order_editable', 10, 2 );
	}

	/*
	 * Helper functions for extracting the details of subscriptions in an order
	 */

	/**
	 * Checks an order to see if it contains a subscription.
	 *
	 * @param mixed $order A WC_Order object or the ID of the order which the subscription was purchased in.
	 * @return bool True if the order contains a subscription, otherwise false.
	 * @version 1.2
	 * @since 1.0
	 */
	public static function order_contains_subscription( $order ) {

		if ( ! is_object( $order ) ) {
			$order = new WC_Order( $order );
		}

		$contains_subscription = false;

		foreach ( $order->get_items() as $order_item ) {
			if ( self::is_item_subscription( $order, $order_item ) ) {
				$contains_subscription = true;
				break;
			}
		}

		return $contains_subscription;
	}

	/**
	 * Checks if a subscription requires manual payment because the payment gateway used to purchase the subscription
	 * did not support automatic payments at the time of the subscription sign up. Or because we're on a staging site.
	 *
	 * @param mixed $order A WC_Order object or the ID of the order which the subscription was purchased in.
	 * @return bool True if the subscription exists and requires manual payments, false if the subscription uses automatic payments (defaults to false for backward compatibility).
	 * @since 1.2
	 */
	public static function requires_manual_renewal( $order ) {

		if ( 'true' == self::get_meta( $order, '_wcs_requires_manual_renewal', 'false' ) || WC_Subscriptions::is_duplicate_site() ) {
			$requires_manual_renewal = true;
		} else {
			$requires_manual_renewal = false;
		}

		return $requires_manual_renewal;
	}

	/**
	 * Returns the total amount to be charged at the outset of the Subscription.
	 *
	 * This may return 0 if there is a free trial period and no sign up fee, otherwise it will be the sum of the sign up
	 * fee and price per period. This function should be used by payment gateways for the initial payment.
	 *
	 * @param mixed $order A WC_Order object or the ID of the order which the subscription was purchased in.
	 * @return float The total initial amount charged when the subscription product in the order was first purchased, if any.
	 * @since 1.1
	 */
	public static function get_total_initial_payment( $order, $product_id = '' ) {

		if ( ! is_object( $order ) ) {
			$order = new WC_Order( $order );
		}

		$order_total     = $order->get_total();
		$recurring_total = self::get_recurring_total( $order );
		$trial_length    = self::get_subscription_trial_length( $order );

		// If there is a free trial period and no sign up fee, the initial payment is 0
		if ( $trial_length > 0 && $order_total == $recurring_total ) {
			$initial_payment = 0;
		} else {
			$initial_payment = $order_total; // Order total already accounts for sign up fees when there is no trial period
		}

		return apply_filters( 'woocommerce_subscriptions_total_initial_payment', $initial_payment, $order, $product_id );
	}

	/**
	 * Returns the total amount to be charged for non-subscription products at the outset of a subscription.
	 *
	 * This may return 0 if there no non-subscription products in the cart, or otherwise it will be the sum of the
	 * line totals for each non-subscription product.
	 *
	 * @param mixed $order A WC_Order object or the ID of the order which the subscription was purchased in.
	 * @since 1.5.3
	 */
	public static function get_non_subscription_total( $order ) {

		if ( ! is_object( $order ) ) {
			$order = new WC_Order( $order );
		}

		$non_subscription_total = 0;

		foreach ( $order->get_items() as $order_item ) {
			if ( ! self::is_item_subscription( $order, $order_item ) ) {
				$non_subscription_total += $order_item['line_total'];
			}
		}

		return apply_filters( 'woocommerce_subscriptions_order_non_subscription_total', $non_subscription_total, $order );
	}

	/**
	 * Returns the total sign-up fee for a subscription product in an order.
	 *
	 * @param mixed $order A WC_Order object or the ID of the order which the subscription was purchased in.
	 * @param int $product_id (optional) The post ID of the subscription WC_Product object purchased in the order. Defaults to the ID of the first product purchased in the order.
	 * @return float The initial sign-up fee charged when the subscription product in the order was first purchased, if any.
	 * @since 1.0
	 */
	public static function get_sign_up_fee( $order, $product_id = '' ) {

		$recurring_total        = self::get_recurring_total( $order );
		$initial_payment        = self::get_total_initial_payment( $order );
		$non_subscription_total = self::get_non_subscription_total( $order );

		if ( self::get_subscription_trial_length( $order ) > 0 ) {
			$sign_up_fee = $initial_payment - $non_subscription_total;
		} elseif ( $recurring_total != $initial_payment ) {
			$sign_up_fee = max( $initial_payment - $recurring_total - $non_subscription_total, 0 );
		} else {
			$sign_up_fee = 0;
		}

		return apply_filters( 'woocommerce_subscriptions_sign_up_fee', $sign_up_fee, $order, $product_id, $non_subscription_total );
	}

	/**
	 * Returns the period (e.g. month) for a each subscription product in an order.
	 *
	 * @param mixed $order A WC_Order object or the ID of the order which the subscription was purchased in.
	 * @param int $product_id (optional) The post ID of the subscription WC_Product object purchased in the order. Defaults to the ID of the first product purchased in the order.
	 * @return string A string representation of the period for the subscription, i.e. day, week, month or year.
	 * @since 1.0
	 */
	public static function get_subscription_period( $order, $product_id = '' ) {
		return self::get_item_meta( $order, '_subscription_period', $product_id );
	}

	/**
	 * Returns the billing interval for a each subscription product in an order.
	 *
	 * For example, this would return 3 for a subscription charged every 3 months or 1 for a subscription charged every month.
	 *
	 * @param mixed $order A WC_Order object or the ID of the order which the subscription was purchased in.
	 * @param int $product_id (optional) The post ID of the subscription WC_Product object purchased in the order. Defaults to the ID of the first product purchased in the order.
	 * @return int The billing interval for a each subscription product in an order.
	 * @since 1.0
	 */
	public static function get_subscription_interval( $order, $product_id = '' ) {
		return self::get_item_meta( $order, '_subscription_interval', $product_id, 1 );
	}

	/**
	 * Returns the length for a subscription in an order.
	 *
	 * There must be only one subscription in an order for this to be accurate.
	 *
	 * @param mixed $order A WC_Order object or the ID of the order which the subscription was purchased in.
	 * @param int $product_id (optional) The post ID of the subscription WC_Product object purchased in the order. Defaults to the ID of the first product purchased in the order.
	 * @return int The number of periods for which the subscription will recur. For example, a $5/month subscription for one year would return 12. A $10 every 3 month subscription for one year would also return 12.
	 * @since 1.0
	 */
	public static function get_subscription_length( $order, $product_id = '' ) {
		return self::get_item_meta( $order, '_subscription_length', $product_id, 0 );
	}

	/**
	 * Returns the length for a subscription product's trial period as set when added to an order.
	 *
	 * The trial period is the same as the subscription period, as derived from @see self::get_subscription_period().
	 *
	 * For now, there must be only one subscription in an order for this to be accurate.
	 *
	 * @param mixed $order A WC_Order object or the ID of the order which the subscription was purchased in.
	 * @param int $product_id (optional) The post ID of the subscription WC_Product object purchased in the order. Defaults to the ID of the first product purchased in the order.
	 * @return int The number of periods the trial period lasts for. For no trial, this will return 0, for a 3 period trial, it will return 3.
	 * @since 1.1
	 */
	public static function get_subscription_trial_length( $order, $product_id = '' ) {
		return self::get_item_meta( $order, '_subscription_trial_length', $product_id, 0 );
	}

	/**
	 * Returns the period (e.g. month)  for a subscription product's trial as set when added to an order.
	 *
	 * As of 1.2.x, a subscriptions trial period may be different than the recurring period
	 *
	 * For now, there must be only one subscription in an order for this to be accurate.
	 *
	 * @param mixed $order A WC_Order object or the ID of the order which the subscription was purchased in.
	 * @param int $product_id (optional) The post ID of the subscription WC_Product object purchased in the order. Defaults to the ID of the first product purchased in the order.
	 * @return string A string representation of the period for the subscription, i.e. day, week, month or year.
	 * @since 1.2
	 */
	public static function get_subscription_trial_period( $order, $product_id = '' ) {

		$period = self::get_item_meta( $order, '_subscription_trial_period', $product_id, '' );

		// Backward compatibility
		if ( empty( $period ) ) {
			$period = self::get_subscription_period( $order, $product_id );
		}

		return $period;
	}

	/**
	 * Returns the recurring amount for an item
	 *
	 * @param WC_Order $order A WC_Order object
	 * @param int $product_id The product/post ID of a subscription
	 * @return float The total amount to be charged for each billing period, if any, not including failed payments.
	 * @since 1.2
	 */
	public static function get_item_recurring_amount( $order, $product_id ) {
		return self::get_item_meta( $order, '_subscription_recurring_amount', $product_id, 0 );
	}

	/**
	 * Returns the sign up fee for an item
	 *
	 * @param WC_Order $order A WC_Order object
	 * @param int $product_id The product/post ID of a subscription
	 * @since 1.2
	 */
	public static function get_item_sign_up_fee( $order, $product_id = '' ) {

		if ( ! is_object( $order ) ) {
			$order = new WC_Order( $order );
		}

		$item = self::get_item_by_product_id( $order, $product_id );

		$line_subtotal           = $order->get_line_subtotal( $item );
		$recurring_line_subtotal = self::get_item_recurring_amount( $order, $product_id );

		if ( self::get_subscription_trial_length( $order, $product_id ) > 0 ) {
			$sign_up_fee = $line_subtotal;
		} else if ( $line_subtotal != $recurring_line_subtotal ) {
			$sign_up_fee = max( $line_subtotal - self::get_item_recurring_amount( $order, $product_id ), 0 );
		} else {
			$sign_up_fee = 0;
		}

		return $sign_up_fee;
	}

	/**
	 * Takes a subscription product's ID and returns the timestamp on which the next payment is due.
	 *
	 * A convenience wrapper for @see WC_Subscriptions_Manager::get_next_payment_date() to get the
	 * next payment date for a subscription when all you have is the order and product.
	 *
	 * @param mixed $order A WC_Order object or the ID of the order which the subscription was purchased in.
	 * @param int $product_id The product/post ID of the subscription
	 * @param mixed $deprecated Never used.
	 * @return int If no more payments are due, returns 0, otherwise returns a timestamp of the date the next payment is due.
	 * @version 1.2
	 * @since 1.0
	 */
	public static function get_next_payment_timestamp( $order, $product_id, $deprecated = null ) {

		if ( null != $deprecated ) { // We want to calculate a date
			_deprecated_argument( __CLASS__ . '::' . __FUNCTION__, '1.2' );
			$next_payment_timestamp = self::calculate_next_payment_date( $order, $product_id, 'timestamp', $deprecated );
		} else {

			if ( ! is_object( $order ) ) {
				$order = new WC_Order( $order );
			}

			$subscription_key       = WC_Subscriptions_Manager::get_subscription_key( $order->id, $product_id );
			$next_payment_timestamp = WC_Subscriptions_Manager::get_next_payment_date( $subscription_key, $order->user_id, 'timestamp' );
		}

		return $next_payment_timestamp;
	}

	/**
	 * Takes a subscription product's ID and the order it was purchased in and returns the date on
	 * which the next payment is due.
	 *
	 * A convenience wrapper for @see WC_Subscriptions_Manager::get_next_payment_date() to get the next
	 * payment date for a subscription when all you have is the order and product.
	 *
	 * @param mixed $order A WC_Order object or the ID of the order which the subscription was purchased in.
	 * @param int $product_id The product/post ID of the subscription
	 * @param mixed $deprecated Never used.
	 * @return mixed If no more payments are due, returns 0, otherwise it returns the MySQL formatted date/time string for the next payment date.
	 * @version 1.2
	 * @since 1.0
	 */
	public static function get_next_payment_date( $order, $product_id, $deprecated = null ) {

		if ( null != $deprecated ) { // We want to calculate a date
			_deprecated_argument( __CLASS__ . '::' . __FUNCTION__, '1.2' );
			$next_payment_date = self::calculate_next_payment_date( $order, $product_id, 'mysql', $deprecated );
		} else {
			if ( ! is_object( $order ) ) {
				$order = new WC_Order( $order );
			}

			$subscription_key  = WC_Subscriptions_Manager::get_subscription_key( $order->id, $product_id );
			$next_payment_date = WC_Subscriptions_Manager::get_next_payment_date( $subscription_key, $order->user_id, 'mysql' );
		}

		return $next_payment_date;
	}

	/**
	 * Takes a subscription product's ID and the order it was purchased in and returns the date on
	 * which the last payment was made.
	 *
	 * A convenience wrapper for @see WC_Subscriptions_Manager::get_last_payment_date() to get the next
	 * payment date for a subscription when all you have is the order and product.
	 *
	 * @param mixed $order A WC_Order object or the ID of the order which the subscription was purchased in.
	 * @param int $product_id The product/post ID of the subscription
	 * @param mixed $deprecated Never used.
	 * @return mixed If no more payments are due, returns 0, otherwise it returns the MySQL formatted date/time string for the next payment date.
	 * @version 1.2.1
	 * @since 1.0
	 */
	public static function get_last_payment_date( $order, $product_id ) {

		if ( ! is_object( $order ) ) {
			$order = new WC_Order( $order );
		}

		$subscription_key  = WC_Subscriptions_Manager::get_subscription_key( $order->id, $product_id );
		$last_payment_date = WC_Subscriptions_Manager::get_last_payment_date( $subscription_key, $order->user_id );

		return $last_payment_date;
	}

	/**
	 * Takes a subscription product's ID and calculates the date on which the next payment is due.
	 *
	 * Calculation is based on $from_date if specified, otherwise it will fall back to the last
	 * completed payment, the subscription's start time, or the current date/time, in that order.
	 *
	 * The next payment date will occur after any free trial period and up to any expiration date.
	 *
	 * @param mixed $order A WC_Order object or the ID of the order which the subscription was purchased in.
	 * @param int $product_id The product/post ID of the subscription
	 * @param string $type (optional) The format for the Either 'mysql' or 'timestamp'.
	 * @param mixed $from_date A MySQL formatted date/time string from which to calculate the next payment date, or empty (default), which will use the last payment on the subscription, or today's date/time if no previous payments have been made.
	 * @return mixed If there is no future payment set, returns 0, otherwise it will return a date of the next payment in the form specified by $type
	 * @since 1.0
	 */
	public static function calculate_next_payment_date( $order, $product_id, $type = 'mysql', $from_date = '' ) {

		if ( ! is_object( $order ) ) {
			$order = new WC_Order( $order );
		}

		$from_date_arg = $from_date;

		$subscription              = WC_Subscriptions_Manager::get_subscription( WC_Subscriptions_Manager::get_subscription_key( $order->id, $product_id ) );
		$subscription_period       = self::get_subscription_period( $order, $product_id );
		$subscription_interval     = self::get_subscription_interval( $order, $product_id );
		$subscription_trial_length = self::get_subscription_trial_length( $order, $product_id );
		$subscription_trial_period = self::get_subscription_trial_period( $order, $product_id );
		$subscription_length       = self::get_subscription_length( $order, $product_id );

		$trial_end_time = ( ! empty( $subscription['trial_expiry_date'] ) ) ? $subscription['trial_expiry_date'] : WC_Subscriptions_Product::get_trial_expiration_date( $product_id, get_gmt_from_date( $order->order_date ) );
		$trial_end_time = strtotime( $trial_end_time );

		// If the subscription is not active, there is no next payment date
		if ( $subscription['status'] != 'active' || $subscription_interval == $subscription_length ) {

			$next_payment_timestamp = 0;

		// If the subscription has a free trial period, and we're still in the free trial period, the next payment is due at the end of the free trial
		} elseif ( $subscription_trial_length > 0 && $trial_end_time > ( gmdate( 'U' ) + 60 * 60 * 23 + 120 ) ) { // Make sure trial expiry is more than 23+ hours in the future to account for trial expiration dates incorrectly stored in non-UTC/GMT timezone and also for any potential changes to the site's timezone

			$next_payment_timestamp = $trial_end_time;

		// The next payment date is {interval} billing periods from the from date
		} else {

			// We have a timestamp
			if ( ! empty( $from_date ) && is_numeric( $from_date ) ) {
				$from_date = date( 'Y-m-d H:i:s', $from_date );
			}

			if ( empty( $from_date ) ) {

				if ( ! empty( $subscription['completed_payments'] ) ) {
					$from_date = array_pop( $subscription['completed_payments'] );
					$add_failed_payments = true;
				} else if ( ! empty ( $subscription['start_date'] ) ) {
					$from_date = $subscription['start_date'];
					$add_failed_payments = true;
				} else {
					$from_date = gmdate( 'Y-m-d H:i:s' );
					$add_failed_payments = false;
				}

				$failed_payment_count = self::get_failed_payment_count( $order, $product_id );

				// Maybe take into account any failed payments
				if ( true === $add_failed_payments && $failed_payment_count > 0 ) {
					$failed_payment_periods = $failed_payment_count * $subscription_interval;
					$from_timestamp = strtotime( $from_date );

					if ( 'month' == $subscription_period ) {
						$from_date = date( 'Y-m-d H:i:s', WC_Subscriptions::add_months( $from_timestamp, $failed_payment_periods ) );
					} else { // Safe to just add the billing periods
						$from_date = date( 'Y-m-d H:i:s', strtotime( "+ {$failed_payment_periods} {$subscription_period}", $from_timestamp ) );
					}
				}
			}

			$from_timestamp = strtotime( $from_date );

			if ( 'month' == $subscription_period ) { // Workaround potential PHP issue
				$next_payment_timestamp = WC_Subscriptions::add_months( $from_timestamp, $subscription_interval );
			} else {
				$next_payment_timestamp = strtotime( "+ {$subscription_interval} {$subscription_period}", $from_timestamp );
			}

			// Make sure the next payment is in the future
			$i = 1;
			while ( $next_payment_timestamp < gmdate( 'U' ) && $i < 30 ) {
				if ( 'month' == $subscription_period ) {
					$next_payment_timestamp = WC_Subscriptions::add_months( $next_payment_timestamp, $subscription_interval );
				} else { // Safe to just add the billing periods
					$next_payment_timestamp = strtotime( "+ {$subscription_interval} {$subscription_period}", $next_payment_timestamp );
				}
				$i = $i + 1;
			}

		}

		// If the subscription has an expiry date and the next billing period comes after the expiration, return 0
		if ( isset( $subscription['expiry_date'] ) && 0 != $subscription['expiry_date'] && ( $next_payment_timestamp + 120 ) > strtotime( $subscription['expiry_date'] ) ) {
			$next_payment_timestamp =  0;
		}

		$next_payment = ( 'mysql' == $type && 0 != $next_payment_timestamp ) ? date( 'Y-m-d H:i:s', $next_payment_timestamp ) : $next_payment_timestamp;

		return apply_filters( 'woocommerce_subscriptions_calculated_next_payment_date', $next_payment, $order, $product_id, $type, $from_date, $from_date_arg );
	}

	/**
	 * Gets the product ID for an order item in a way that is backwards compatible with WC 1.x.
	 *
	 * Version 2.0 of WooCommerce changed the ID of an order item from its product ID to a unique ID for that particular item.
	 * This function checks if the 'product_id' field exists on an order item before falling back to 'id'.
	 *
	 * @param array $order_item An order item in the structure returned by WC_Order::get_items()
	 * @since 1.2.5
	 */
	public static function get_items_product_id( $order_item ) {
		return ( isset( $order_item['product_id'] ) ) ? $order_item['product_id'] : $order_item['id'];
	}

	/**
	 * Gets an item by product id from an order.
	 *
	 * @param WC_Order|int $order The WC_Order object or ID of the order for which the meta should be sought.
	 * @param int $product_id The product/post ID of a subscription product.
	 * @since 1.2.5
	 */
	public static function get_item_by_product_id( $order, $product_id = '' ) {

		if ( ! is_object( $order ) ) {
			$order = new WC_Order( $order );
		}

		foreach ( $order->get_items() as $item ) {
			if ( ( self::get_items_product_id( $item ) == $product_id || empty( $product_id ) ) && self::is_item_subscription( $order, $item ) ) {
				return $item;
			}
		}

		return array();
	}

	/**
	 * Gets an item by a subscription key of the form created by @see WC_Subscriptions_Manager::get_subscription_key().
	 *
	 * @param WC_Order|int $order The WC_Order object or ID of the order for which the meta should be sought.
	 * @param int $product_id The product/post ID of a subscription product.
	 * @since 1.2.5
	 */
	public static function get_item_by_subscription_key( $subscription_key ) {

		$item_id = self::get_item_id_by_subscription_key( $subscription_key );

		$item = self::get_item_by_id( $item_id );

		return $item;
	}

	/**
	 * Gets the ID of a subscription item which belongs to a subscription key of the form created
	 * by @see WC_Subscriptions_Manager::get_subscription_key().
	 *
	 * @param WC_Order|int $order The WC_Order object or ID of the order for which the meta should be sought.
	 * @param int $product_id The product/post ID of a subscription product.
	 * @since 1.4
	 */
	public static function get_item_id_by_subscription_key( $subscription_key ) {
		global $wpdb;

		$order_and_product_ids = explode( '_', $subscription_key );

		$item_id = $wpdb->get_var( $wpdb->prepare(
				"SELECT `{$wpdb->prefix}woocommerce_order_items`.order_item_id FROM `{$wpdb->prefix}woocommerce_order_items`
					INNER JOIN `{$wpdb->prefix}woocommerce_order_itemmeta` on `{$wpdb->prefix}woocommerce_order_items`.order_item_id = `{$wpdb->prefix}woocommerce_order_itemmeta`.order_item_id
					AND `{$wpdb->prefix}woocommerce_order_itemmeta`.meta_key = '_product_id'
					AND `{$wpdb->prefix}woocommerce_order_itemmeta`.meta_value = %d
				WHERE `{$wpdb->prefix}woocommerce_order_items`.order_id = %d",
				$order_and_product_ids[1],
				$order_and_product_ids[0]
			)
		);

		return $item_id;
	}

	/**
	 * Gets an individual order item by ID without requiring the order ID associated with it.
	 *
	 * @param WC_Order|int $order The WC_Order object or ID of the order for which the meta should be sought.
	 * @param int $item_id The product/post ID of a subscription. Option - if no product id is provided, the first item's meta will be returned
	 * @return array $item An array containing the order_item_id, order_item_name, order_item_type, order_id and any item_meta. Array structure matches that returned by WC_Order::get_items()
	 * @since 1.2.5
	 */
	public static function get_item_by_id( $order_item_id ) {
		global $wpdb;

		$item = $wpdb->get_row( $wpdb->prepare( "
			SELECT order_item_id, order_item_name, order_item_type, order_id
			FROM   {$wpdb->prefix}woocommerce_order_items
			WHERE  order_item_id = %d
		", $order_item_id ), ARRAY_A );

		$order = new WC_Order( absint( $item['order_id'] ) );

		$item['name']      = $item['order_item_name'];
		$item['type']      = $item['order_item_type'];
		$item['item_meta'] = $order->get_item_meta( $item['order_item_id'] );

		// Put meta into item array
		if ( is_array( $item['item_meta'] ) ) {
			foreach( $item['item_meta'] as $meta_name => $meta_value ) {
				$key = substr( $meta_name, 0, 1 ) == '_' ? substr( $meta_name, 1 ) : $meta_name;
				$item[ $key ] = maybe_unserialize( $meta_value[0] );
			}
		}

		return $item;
	}

	/**
	 * A unified API for accessing product specific meta on an order.
	 *
	 * @param WC_Order|int $order The WC_Order object or ID of the order for which the meta should be sought. 
	 * @param string $meta_key The key as stored in the post meta table for the meta item. 
	 * @param int $product_id The product/post ID of a subscription. Option - if no product id is provided, we will loop through the order and find the subscription
	 * @param mixed $default (optional) The default value to return if the meta key does not exist. Default 0.
	 * @since 1.2
	 */
	public static function get_item_meta( $order, $meta_key, $product_id = '', $default = 0 ) {

		$meta_value = $default;

		if ( $product_id == '' ) {
			$items = self::get_recurring_items( $order );
			foreach ( $items as $item ) {
				$product_id = $item['product_id'];
			}
		}

		$item = self::get_item_by_product_id( $order, $product_id );

		if ( ! empty ( $item ) ) {

			foreach ( $item['item_meta'] as $key => $value ) {

				$value = $value[0];

				if ( $key == $meta_key ) {
					$meta_value = $value;
				}

			}
		}

		return apply_filters( 'woocommerce_subscriptions_item_meta', $meta_value, $meta_key, $order, $product_id );
	}

	/**
	 * Access an individual piece of item metadata (@see woocommerce_get_order_item_meta returns all metadata for an item)
	 *
	 * You may think it would make sense if this function was called "get_item_meta", and you would be correct, but a function
	 * with that name was created before the item meta data API of WC 2.0, so it needs to persist with it's own different
	 * set of parameters.
	 *
	 * @param int $meta_id The order item meta data ID of the item you want to get.
	 * @since 1.2.5
	 */
	public static function get_item_meta_data( $meta_id ) {
		global $wpdb;

		$item_meta = $wpdb->get_row( $wpdb->prepare( "
			SELECT *
			FROM   {$wpdb->prefix}woocommerce_order_itemmeta
			WHERE  meta_id = %d
		", $meta_id ) );

		return $item_meta;
	}

	/**
	 * Gets the name of a subscription item by product ID from an order.
	 *
	 * @param WC_Order|int $order The WC_Order object or ID of the order for which the meta should be sought.
	 * @param int $product_id The product/post ID of a subscription. Option - if no product id is provided, it is expected that only one item exists and the last item's meta will be returned
	 * @since 1.2
	 */
	public static function get_item_name( $order, $product_id = '' ) {

		$item = self::get_item_by_product_id( $order, $product_id );

		if ( isset( $item['name'] ) ) {
			return $item['name'];
		} else {
			return '';
		}
	}

	/**
	 * A unified API for accessing subscription order meta, especially for sign-up fee related order meta.
	 *
	 * Because WooCommerce 2.1 deprecated WC_Order::$order_custom_fields, this function is also used to provide
	 * version independent meta data access to non-subscription meta data.
	 *
	 * @param WC_Order|int $order The WC_Order object or ID of the order for which the meta should be sought.
	 * @param string $meta_key The key as stored in the post meta table for the meta item.
	 * @param mixed $default (optional) The default value to return if the meta key does not exist. Default 0.
	 * @since 1.0
	 */
	public static function get_meta( $order, $meta_key, $default = 0 ) {

		if ( ! is_object( $order ) ) {
			$order = new WC_Order( $order );
		}

		$meta_key = preg_replace( '/^_/', '', $meta_key );

		if ( isset( $order->$meta_key ) ) { // WC 2.1+ magic __isset() & __get() methods
			$meta_value = $order->$meta_key;
		} elseif ( is_array( $order->order_custom_fields ) && isset( $order->order_custom_fields[ '_' . $meta_key ][0] ) && $order->order_custom_fields[ '_' . $meta_key ][0] ) {  // < WC 2.1+
			$meta_value = maybe_unserialize( $order->order_custom_fields[ '_' . $meta_key ][0] );
		} else {
			$meta_value = get_post_meta( $order->id, '_' . $meta_key, true );

			if ( empty( $meta_value ) ) {
				$meta_value = $default;
			}
		}

		return $meta_value;
	}

	/*
	 * Functions to customise the way WooCommerce displays order prices.
	 */

	/**
	 * Appends the subscription period/duration string to order total
	 *
	 * @since 1.0
	 */
	public static function get_formatted_line_total( $formatted_total, $item, $order ) {

		if ( self::is_item_subscription( $order, $item ) ) {

			$item_id = self::get_items_product_id( $item );

			$subscription_details = array(
				'currency'              => self::get_order_currency( $order ),
				'subscription_interval' => self::get_subscription_interval( $order, $item_id ),
				'subscription_period'   => self::get_subscription_period( $order, $item_id ),
			);

			if ( ! self::$recurring_only_price_strings ) {
				$subscription_details['subscription_length'] = self::get_subscription_length( $order );
				$subscription_details['trial_length']        = self::get_subscription_trial_length( $order );
				$subscription_details['trial_period']        = self::get_subscription_trial_period( $order );
			}

			$sign_up_fee  = self::get_sign_up_fee( $order );
			$trial_length = self::get_subscription_trial_length( $order );

			if ( self::$recurring_only_price_strings ) {
				$subscription_details['initial_amount'] = '';
			} elseif ( $sign_up_fee > 0 && $trial_length == 0 && $subscription_details['subscription_interval'] != $subscription_details['subscription_length'] ) {
				$subscription_details['initial_amount'] = $formatted_total;
			} elseif ( $sign_up_fee > 0 && $trial_length > 0 ) {
				$subscription_details['initial_amount'] = $formatted_total;
			} else {
				$subscription_details['initial_amount'] = '';
			}

			// Use the core WC_Order::get_formatted_line_subtotal() WC function to get the recurring total
			remove_filter( 'woocommerce_order_formatted_line_subtotal', __CLASS__ . '::' . __FUNCTION__, 10, 3 ); // Avoid getting into an infinite loop

			foreach ( self::get_recurring_items( $order ) as $recurring_item ) {
				if ( self::get_items_product_id( $recurring_item ) == $item_id ) {
					$subscription_details['recurring_amount'] = $order->get_formatted_line_subtotal( $recurring_item );
				}
			}

			add_filter( 'woocommerce_order_formatted_line_subtotal', __CLASS__ . '::' . __FUNCTION__, 10, 3 );

			$formatted_total = WC_Subscriptions_Manager::get_subscription_price_string( $subscription_details );
		}

		return $formatted_total;
	}

	/**
	 * Appends the subscription period/duration string to order subtotal
	 *
	 * @since 1.0
	 */
	public static function get_subtotal_to_display( $subtotal, $compound, $order ) {
		global $woocommerce;

		if( self::order_contains_subscription( $order ) ) {

			$subscription_details = array(
				'currency'              => self::get_order_currency( $order ),
				'subscription_interval' => self::get_subscription_interval( $order ),
				'subscription_period'   => self::get_subscription_period( $order )
			);

			$sign_up_fee  = self::get_sign_up_fee( $order );
			$trial_length = self::get_subscription_trial_length( $order );

			$recurring_subtotal = 0;

			if ( ! $compound ) {

				foreach ( self::get_recurring_items( $order ) as $item ) {
					$recurring_subtotal += $order->get_line_subtotal( $item ); // Can use the $order function here as we pass it the recurring item amounts

					if ( ! $order->display_cart_ex_tax ) {
						$recurring_subtotal += $item['line_subtotal_tax'];
					}
				}

				$subscription_details['recurring_amount'] = $recurring_subtotal;

			} else {

				foreach ( self::get_recurring_items( $order ) as $item ) {
					$recurring_subtotal += $item['line_subtotal'];
				}

				// Add Shipping Costs
				$recurring_subtotal += self::get_recurring_shipping_total( $order );

				// Remove non-compound taxes
				foreach ( self::get_recurring_taxes( $order ) as $tax ) {
					if ( isset( $tax['compound'] ) && $tax['compound'] ) {
						continue;
					}

					$recurring_subtotal = $recurring_subtotal + $tax['cart_tax'] + $tax['shipping_tax'];
				}

				// Remove discounts
				$recurring_subtotal = $recurring_subtotal - self::get_recurring_cart_discount( $order );

				$subscription_details['recurring_amount'] = $recurring_subtotal;

			}

			if ( self::$recurring_only_price_strings ) {
				$subscription_details['initial_amount'] = '';
			} elseif ( $sign_up_fee > 0 && $trial_length == 0 && $subscription_details['subscription_interval'] != self::get_subscription_length( $order ) ) {
				$subscription_details['initial_amount'] = $subtotal;
			} elseif ( $sign_up_fee > 0 && $trial_length > 0 ) {
				$subscription_details['initial_amount'] = $subtotal;
			} elseif ( $subtotal !== woocommerce_price( $subscription_details['recurring_amount'] ) ) { // Applying initial payment only discount
				$subscription_details['initial_amount'] = $subtotal;
			} else {
				$subscription_details['initial_amount'] = '';
			}

			$subtotal = WC_Subscriptions_Manager::get_subscription_price_string( $subscription_details );

			if ( ! $compound && $order->display_cart_ex_tax && $order->prices_include_tax ) {
				$subtotal .= ' <small>' . $woocommerce->countries->ex_tax_or_vat() . '</small>';
			}

		}

		return $subtotal;
	}

	/**
	 * Appends the subscription period/duration string to order total
	 *
	 * @since 1.0
	 */
	public static function get_cart_discount_to_display( $discount, $order ) {

		if( self::order_contains_subscription( $order ) ) {

			$subscription_details = array(
				'currency'              => self::get_order_currency( $order ),
				'recurring_amount'      => self::get_recurring_discount_cart( $order ),
				'subscription_interval' => self::get_subscription_interval( $order ),
				'subscription_period'   => self::get_subscription_period( $order )
			);

			$sign_up_fee  = self::get_sign_up_fee( $order );
			$trial_length = self::get_subscription_trial_length( $order );

			if ( self::$recurring_only_price_strings ) {
				$subscription_details['initial_amount'] = '';
			} elseif ( $sign_up_fee > 0 && $trial_length == 0 && $subscription_details['subscription_interval'] != self::get_subscription_length( $order ) ) {
				$subscription_details['initial_amount'] = $discount;
			} elseif ( $sign_up_fee > 0 && $trial_length > 0 ) {
				$subscription_details['initial_amount'] = $discount;
			} elseif ( $discount !== woocommerce_price( $subscription_details['recurring_amount'] ) ) { // Applying initial payment only discount
				$subscription_details['initial_amount'] = $discount;
			} else {
				$subscription_details['initial_amount'] = '';
			}

			$discount = WC_Subscriptions_Manager::get_subscription_price_string( $subscription_details );
			$discount = sprintf( __( '%s discount', 'woocommerce-subscriptions' ), $discount );
		}

		return $discount;
	}

	/**
	 * Appends the subscription period/duration string to order total
	 *
	 * @since 1.0
	 */
	public static function get_order_discount_to_display( $discount, $order ) {

		if( self::order_contains_subscription( $order ) ) {

			$subscription_details = array(
				'currency'              => self::get_order_currency( $order ),
				'recurring_amount'      => self::get_recurring_discount_total( $order ),
				'subscription_interval' => self::get_subscription_interval( $order ),
				'subscription_period'   => self::get_subscription_period( $order )
			);

			$sign_up_fee  = self::get_sign_up_fee( $order );
			$trial_length = self::get_subscription_trial_length( $order );

			if ( self::$recurring_only_price_strings ) {
				$subscription_details['initial_amount'] = '';
			} elseif ( $sign_up_fee > 0 && $trial_length == 0 && $subscription_details['subscription_interval'] != self::get_subscription_length( $order ) ) {
				$subscription_details['initial_amount'] = $discount;
			} elseif ( $sign_up_fee > 0 && $trial_length > 0 ) {
				$subscription_details['initial_amount'] = $discount;
			} elseif ( $discount !== woocommerce_price( $subscription_details['recurring_amount'] ) ) { // Applying initial payment only discount
				$subscription_details['initial_amount'] = $discount;
			} else {
				$subscription_details['initial_amount'] = '';
			}

			$discount = WC_Subscriptions_Manager::get_subscription_price_string( $subscription_details );
			$discount = sprintf( __( '%s discount', 'woocommerce-subscriptions' ), $discount );
		}

		return $discount;
	}

	/**
	 * Appends the subscription period/duration string to order total
	 *
	 * @since 1.0
	 */
	public static function get_formatted_order_total( $formatted_total, $order ) {

		if ( self::order_contains_subscription( $order ) ) {

			$subscription_details = array(
				'currency'              => self::get_order_currency( $order ),
				'recurring_amount'      => self::get_recurring_total( $order ),
				'subscription_interval' => self::get_subscription_interval( $order ),
				'subscription_period'   => self::get_subscription_period( $order ),
			);

			if ( ! self::$recurring_only_price_strings ) {
				$subscription_details['subscription_length'] = self::get_subscription_length( $order );
				$subscription_details['trial_length']        = self::get_subscription_trial_length( $order );
				$subscription_details['trial_period']        = self::get_subscription_trial_period( $order );
			}

			$sign_up_fee  = self::get_sign_up_fee( $order );
			$trial_length = self::get_subscription_trial_length( $order );

			if ( self::$recurring_only_price_strings ) {
				$subscription_details['initial_amount'] = '';
			} elseif ( $sign_up_fee > 0 && $trial_length == 0 && $subscription_details['subscription_interval'] != $subscription_details['subscription_length'] ) {
				$subscription_details['initial_amount'] = $formatted_total;
			} elseif ( $sign_up_fee > 0 && $trial_length > 0 ) {
				$subscription_details['initial_amount'] = $formatted_total;
			} elseif ( $formatted_total !== woocommerce_price( $subscription_details['recurring_amount'] ) ) { // Applying initial payment only discount
				$subscription_details['initial_amount'] = $formatted_total;
			} else {
				$subscription_details['initial_amount'] = '';
			}

			$formatted_total = WC_Subscriptions_Manager::get_subscription_price_string( $subscription_details );
		}

		return $formatted_total;
	}

	/**
	 * Appends the subscription period/duration string to shipping fee
	 *
	 * @since 1.0
	 */
	public static function get_shipping_to_display( $shipping_to_display, $order ) {

		if ( self::order_contains_subscription( $order ) && self::get_recurring_shipping_total( $order ) > 0 ) {

			$subscription_details = array(
				'currency'              => self::get_order_currency( $order ),
				'recurring_amount'      => self::get_recurring_shipping_total( $order ),
				'subscription_interval' => self::get_subscription_interval( $order ),
				'subscription_period'   => self::get_subscription_period( $order ),
			);

			$tax_text = '';

			if ( $order->tax_display_cart == 'excl' ) {

				// Show shipping excluding tax
				$subscription_details['initial_amount']   = $order->order_shipping;
				$subscription_details['recurring_amount'] = self::get_recurring_shipping_total( $order );

				if ( $order->order_shipping_tax > 0 && $order->prices_include_tax ) {
					$tax_text = WC()->countries->ex_tax_or_vat() . ' ';
				}

			} else {

				// Show shipping including tax
				$subscription_details['initial_amount']   = $order->order_shipping + $order->order_shipping_tax;
				$subscription_details['recurring_amount'] = self::get_recurring_shipping_total( $order ) + self::get_recurring_shipping_tax_total( $order );

				if ( $order->order_shipping_tax > 0 && ! $order->prices_include_tax ) {
					$tax_text = WC()->countries->inc_tax_or_vat() . ' ';
				}

			}

			if ( ! self::$recurring_only_price_strings ) {
				$subscription_details['trial_length'] = self::get_subscription_trial_length( $order );
				$subscription_details['trial_period'] = self::get_subscription_trial_period( $order );
			}

			$shipping_to_display  = WC_Subscriptions_Manager::get_subscription_price_string( $subscription_details );
			$shipping_to_display .= sprintf( __( '&nbsp;<small>%svia %s</small>', 'woocommerce' ), $tax_text, $order->get_shipping_method() );

		}

		return $shipping_to_display;
	}

	/**
	 * Individual totals are taken care of by filters, but taxes and fees are not, so we need to override them here.
	 *
	 * @since 1.0
	 */
	public static function get_order_item_totals( $total_rows, $order ) {
		global $woocommerce;

		if ( self::order_contains_subscription( $order ) && self::get_recurring_total_tax( $order ) > 0 && 'incl' !== $order->tax_display_cart ) {

			$order_taxes         = $order->get_taxes();
			$recurring_taxes     = self::get_recurring_taxes( $order );
			$subscription_length = self::get_subscription_length( $order );
			$sign_up_fee         = self::get_sign_up_fee( $order );
			$trial_length        = self::get_subscription_trial_length( $order );

			// Only want to display recurring amounts for taxes, no need for trial period, length etc.
			$subscription_details = array(
				'currency'              => self::get_order_currency( $order ),
				'subscription_interval' => self::get_subscription_interval( $order ),
				'subscription_period'   => self::get_subscription_period( $order )
			);

			if ( count( $order_taxes ) > 0 || count( $recurring_taxes ) > 0 ) {

				if ( 'itemized' == get_option( 'woocommerce_tax_total_display' ) ) {

					foreach ( $recurring_taxes as $index => $tax ) {

						if ( $tax['compound'] ) {
							continue;
						}

						$tax_key      = sanitize_title( $tax['name'] );
						$tax_name     = $tax['name'];
						$tax_amount   = $tax['tax_amount'];
						$shipping_tax = $tax['shipping_tax_amount'];

						if ( $tax['tax_amount'] > 0 ) {

							foreach ( $order_taxes as $order_tax ) {
								if ( $tax_name == $order_tax['name'] ) {
									$order_tax_amount = isset( $order_tax['tax_amount'] ) ? $order_tax['tax_amount'] + $order_tax['shipping_tax_amount'] : '';
								}
							}

							$recurring_tax = isset( $tax['tax_amount'] ) ? $tax['tax_amount'] + $tax['shipping_tax_amount'] : '';
						}

						if ( $tax_amount > 0 ) {

							$subscription_details['recurring_amount'] = $recurring_tax;

							if ( self::$recurring_only_price_strings ) {
								$subscription_details['initial_amount'] = '';
							} elseif ( $sign_up_fee > 0 && $trial_length == 0 && $subscription_details['subscription_interval'] != $subscription_length ) {
								$subscription_details['initial_amount'] = $total_rows[ $tax_key ]['value'];
							} elseif ( $sign_up_fee > 0 && $trial_length > 0 ) {
								$subscription_details['initial_amount'] = $total_rows[ $tax_key ]['value'];
							} elseif ( $order_tax_amount !== $subscription_details['recurring_amount'] ) { // Applying initial payment only discount
								$subscription_details['initial_amount'] = $total_rows[ $tax_key ]['value'];
							} else {
								$subscription_details['initial_amount'] = '';
							}

							$total_rows[ $tax_key ]['value'] = WC_Subscriptions_Manager::get_subscription_price_string( $subscription_details );

						} elseif ( $shipping_tax > 0  ) { // Just a recurring shipping tax

							$subscription_details['recurring_amount'] = $shipping_tax;

							if ( self::$recurring_only_price_strings ) {
								$subscription_details['initial_amount'] = '';
							} elseif ( $sign_up_fee > 0 && $trial_length == 0 && $subscription_details['subscription_interval'] != $subscription_length ) {
								$subscription_details['initial_amount'] = $shipping_tax;
							} elseif ( $sign_up_fee > 0 && $trial_length > 0 ) {
								$subscription_details['initial_amount'] = $shipping_tax;
							} elseif ( $shipping_tax !== $subscription_details['recurring_amount'] ) { // Applying initial payment only discount
								$subscription_details['initial_amount'] = $shipping_tax;
							} else {
								$subscription_details['initial_amount'] = '';
							}

							$shipping_tax_row = array(
								$tax_key . '_shipping' => array(
									'label' => $tax_name,
									'value' => WC_Subscriptions_Manager::get_subscription_price_string( $subscription_details )
								)
							);

							// Insert the tax just before the order total
							$total_rows = array_splice( $total_rows, 0, -1 ) + $shipping_tax_row + array_splice( $total_rows, -1 );
						}

					}

					foreach ( $recurring_taxes as $index => $tax ) {

						if ( ! $tax['compound'] ) {
							continue;
						}

						$tax_key      = sanitize_title( $tax['label'] );
						$tax_name     = $tax['label'];
						$tax_amount   = $tax['cart_tax'];
						$shipping_tax = $tax['shipping_tax'];

						if ( $tax_amount > 0 ) {

							foreach ( $order_taxes as $order_tax ) {
								if ( $tax_name == $order_tax['label'] ) {
									$order_tax_amount = isset( $order_tax['cart_tax'] ) ? $order_tax['cart_tax'] + $order_tax['shipping_tax'] : '';
								}
							}

							$recurring_tax = isset( $tax['cart_tax'] ) ? $tax['cart_tax'] + $tax['shipping_tax'] : '';
						}

						if ( $tax_amount > 0 ) {

							if ( self::$recurring_only_price_strings ) {
								$subscription_details['initial_amount'] = '';
							} elseif ( $sign_up_fee > 0 && $trial_length == 0 && $subscription_details['subscription_interval'] != $subscription_length ) {
								$subscription_details['initial_amount'] = $total_rows[ $tax_key ]['value'];
							} elseif ( $sign_up_fee > 0 && $trial_length > 0 ) {
								$subscription_details['initial_amount'] = $total_rows[ $tax_key ]['value'];
							} elseif ( $order_tax_amount !== woocommerce_price( $subscription_details['recurring_amount'] ) ) { // Applying initial payment only discount
								$subscription_details['initial_amount'] = $total_rows[ $tax_key ]['value'];
							} else {
								$subscription_details['initial_amount'] = '';
							}

							$subscription_details['recurring_amount'] = $recurring_tax;

							$total_rows[ $tax_key ]['value'] = WC_Subscriptions_Manager::get_subscription_price_string( $subscription_details );

						} elseif ( $shipping_tax > 0  ) { // Just a recurring shipping tax

							$subscription_details['recurring_amount'] = $shipping_tax;

							if ( self::$recurring_only_price_strings ) {
								$subscription_details['initial_amount'] = '';
							} elseif ( $sign_up_fee > 0 && $trial_length == 0 && $subscription_details['subscription_interval'] != $subscription_length ) {
								$subscription_details['initial_amount'] = $shipping_tax;
							} elseif ( $sign_up_fee > 0 && $trial_length > 0 ) {
								$subscription_details['initial_amount'] = $shipping_tax;
							} elseif ( $shipping_tax !== woocommerce_price( $subscription_details['recurring_amount'] ) ) { // Applying initial payment only discount
								$subscription_details['initial_amount'] = $shipping_tax;
							} else {
								$subscription_details['initial_amount'] = '';
							}

							$shipping_tax_row = array(
								$tax_key . '_shipping' => array(
									'label' => $tax_name,
									'value' => WC_Subscriptions_Manager::get_subscription_price_string( $subscription_details )
								)
							);

							// Insert the tax just before the order total
							$total_rows = array_splice( $total_rows, 0, -1 ) + $shipping_tax_row + array_splice( $total_rows, -1, 0 );
						}
					}

				} elseif ( isset( $total_rows['tax'] ) ) { // this will be set even if the initial tax is $0 but there is a recurring tax

					$subscription_details['recurring_amount'] = self::get_recurring_total_tax( $order );
					$order_total_tax = woocommerce_price( $order->get_total_tax() );

					if ( self::$recurring_only_price_strings ) {
						$subscription_details['initial_amount'] = '';
					} elseif ( $sign_up_fee > 0 && $trial_length == 0 && $subscription_details['subscription_interval'] != $subscription_length ) {
						$subscription_details['initial_amount'] = $order_total_tax;
					} elseif ( $sign_up_fee > 0 && $trial_length > 0 ) {
						$subscription_details['initial_amount'] = $order_total_tax;
					} elseif ( $order_total_tax !== woocommerce_price( $subscription_details['recurring_amount'] ) ) { // Applying initial payment only discount
						$subscription_details['initial_amount'] = $order_total_tax;
					} else {
						$subscription_details['initial_amount'] = '';
					}

					$total_rows['tax']['value'] = WC_Subscriptions_Manager::get_subscription_price_string( $subscription_details );
				}
			}

			if ( $fees = $order->get_fees() ) {

				$index_increment = 0;

				foreach( $fees as $id => $fee ) {
					if ( $fee['recurring_line_total'] + $fee['recurring_line_tax'] == 0 ) {
						continue;
					}

					if ( 'excl' == $order->tax_display_cart ) {

						$initial_amount = $fee['line_total'];
						$subscription_details['recurring_amount'] = $fee['recurring_line_total'];

					} else {

						$initial_amount = $fee['line_total'] + $fee['line_tax'];
						$subscription_details['recurring_amount'] = $fee['recurring_line_total'] + $fee['recurring_line_tax'];

					}

					$subscription_details['recurring_amount'] = woocommerce_price( $subscription_details['recurring_amount'], array( 'currency'=> $subscription_details['currency'] ) );
					$initial_amount = woocommerce_price( $initial_amount, array( 'currency'=> $subscription_details['currency'] ) );

					if ( self::$recurring_only_price_strings ) {
						$subscription_details['initial_amount'] = '';
					} elseif ( $sign_up_fee > 0 && $trial_length == 0 && $subscription_details['subscription_interval'] != $subscription_length ) {
						$subscription_details['initial_amount'] = $initial_amount;
					} elseif ( $sign_up_fee > 0 && $trial_length > 0 ) {
						$subscription_details['initial_amount'] = $initial_amount;
					} elseif ( $initial_amount !== $subscription_details['recurring_amount'] ) {
						$subscription_details['initial_amount'] = $initial_amount;
					} else {
						$subscription_details['initial_amount'] = '';
					}

					// if the fees have no initial amount (i.e. there is a free trial) the order will be out so we need to insert them after shipping manually
					if ( ! isset( $total_rows[ 'fee_' . $id ] ) ) {
						$index_increment++; // keep original order
						$shipping_index = (int)array_search( 'shipping', array_keys( $total_rows ) ) + $index_increment;
						$total_rows     = array_merge( array_slice( $total_rows, 0, $shipping_index ), array( 'fee_' . $id => array() ), array_slice( $total_rows, $shipping_index ) );
					}

					$total_rows[ 'fee_' . $id ] = array(
						'label' => $fee['name'],
						'value' => WC_Subscriptions_Manager::get_subscription_price_string( $subscription_details )
					);

				}
			}

			// Now, if we're displaying recurring totals only, make sure we're not showing any $0 / period discounts
			if ( self::$recurring_only_price_strings ) {

				if ( isset( $total_rows['cart_discount'] ) && 0 == self::get_recurring_discount_cart( $order ) ) {
					unset( $total_rows['cart_discount'] );
				}

				if ( isset( $total_rows['order_discount'] ) && 0 == self::get_recurring_discount_total( $order ) ) {
					unset( $total_rows['order_discount'] );
				}

			}

		}

		return $total_rows;
	}

	/**
	 * Displays a few details about what happens to their subscription. Hooked
	 * to the thank you page.
	 *
	 * @since 1.0
	 */
	public static function subscription_thank_you( $order_id ){

		if( self::order_contains_subscription( $order_id ) ) {
			$thank_you_message = '<p>' . __( 'Your subscription will be activated when payment clears.', 'woocommerce-subscriptions' ) . '</p>';
			$thank_you_message = sprintf( __( '%sView the status of your subscription in %syour account%s.%s', 'woocommerce-subscriptions' ), '<p>', '<a href="' . get_permalink( woocommerce_get_page_id( 'myaccount' ) ) . '">', '</a>','</p>' );
			echo apply_filters( 'woocommerce_subscriptions_thank_you_message', $thank_you_message, $order_id );
		}

	}

	/**
	 * Returns the number of failed payments for a given subscription.
	 *
	 * @param WC_Order $order The WC_Order object of the order for which you want to determine the number of failed payments.
	 * @param product_id int The ID of the subscription product.
	 * @return string The key representing the given subscription.
	 * @since 1.0
	 */
	public static function get_failed_payment_count( $order, $product_id ) {

		$failed_payment_count = WC_Subscriptions_Manager::get_subscriptions_failed_payment_count( WC_Subscriptions_Manager::get_subscription_key( $order->id, $product_id ), $order->customer_user );

		return $failed_payment_count;
	}

	/**
	 * Returns the amount outstanding on a subscription product.
	 *
	 * @param WC_Order $order The WC_Order object of the order for which you want to determine the number of failed payments.
	 * @param product_id int The ID of the subscription product.
	 * @return string The key representing the given subscription.
	 * @since 1.0
	 */
	public static function get_outstanding_balance( $order, $product_id ) {

		$failed_payment_count = self::get_failed_payment_count( $order, $product_id );

		$oustanding_balance = $failed_payment_count * self::get_recurring_total( $order, $product_id );

		return $oustanding_balance;
	}

	/**
	 * Output a hidden element in the order status of the orders list table to provide information about whether
	 * the order displayed in that row contains a subscription or not.
	 *
	 * It would be more semantically correct to display a hidden input element than a span element with data, but
	 * that can result in "requested URL's length exceeds the capacity limit" errors when bulk editing orders.
	 *
	 * @param string $column The string of the current column.
	 * @since 1.1
	 */
	public static function add_contains_subscription_hidden_field( $column ) {
		global $post;

		if ( $column == 'order_status' ) {
			$contains_subscription = self::order_contains_subscription( $post->ID ) ? 'true' : 'false';
			printf( '<span class="contains_subscription" data-contains_subscription="%s" style="display: none;"></span>', $contains_subscription );
		}
	}

	/**
	 * Output a hidden element in the order status of the orders list table to provide information about whether
	 * the order displayed in that row contains a subscription or not.
	 *
	 * @param string $column The string of the current column.
	 * @since 1.1
	 */
	public static function contains_subscription_hidden_field( $order_id ) {

		$has_subscription = self::order_contains_subscription( $order_id ) ? 'true' : 'false';

		echo '<input type="hidden" name="contains_subscription" value="' . $has_subscription . '">';
	}

	/**
	 * When an order is added or updated from the admin interface, check if a subscription product
	 * has been manually added to the order or the details of the subscription have been modified,
	 * and create/update the subscription as required.
	 *
	 * Save subscription order meta items
	 *
	 * @param int $post_id The ID of the post which is the WC_Order object.
	 * @param Object $post The post object of the order.
	 * @since 1.1
	 */
	public static function pre_process_shop_order_meta( $post_id, $post ) {
		global $woocommerce, $wpdb;

		$order_contains_subscription = false;

		$order = new WC_Order( $post_id );

		$existing_product_ids = array();

		foreach ( $order->get_items() as $existing_item ) {
			$existing_product_ids[] = self::get_items_product_id( $existing_item );
		}

		$product_ids = array();

		if ( isset( $_POST['order_item_id'] ) ) {
			foreach ( $_POST['order_item_id'] as $order_item_id ) {
				$product_ids[ $order_item_id ] = woocommerce_get_order_item_meta( $order_item_id, '_product_id' );
			}
		}

		// Check if there are new subscription products to be added, or the order already has a subscription item
		foreach ( array_merge( $product_ids, $existing_product_ids ) as $order_item_id => $product_id ) {

			$is_existing_item = false;

			if ( in_array( $product_id, $existing_product_ids ) ) {
				$is_existing_item = true;
			}

			// If this is a new item and it's a subscription product, we have a subscription
			if ( ! $is_existing_item && WC_Subscriptions_Product::is_subscription( $product_id ) ) {
				$order_contains_subscription = true;
			}

			// If this is an existing item and it's a subscription item, we have a subscription
			if ( $is_existing_item && self::is_item_subscription( $order, $product_id ) ) {
				$order_contains_subscription = true;
			}
		}

		if ( ! $order_contains_subscription ) {
			return $post_id;
		}

		$existing_payment_method = get_post_meta( $post_id, '_recurring_payment_method', true );
		$chosen_payment_method   = ( isset( $_POST['_recurring_payment_method'] ) ) ? stripslashes( $_POST['_recurring_payment_method'] ) : '';

		// If the recurring payment method is changing, or it isn't set make sure we have correct manual payment flag set
		if ( isset( $_POST['_recurring_payment_method'] ) || empty( $existing_payment_method ) && ( $chosen_payment_method != $existing_payment_method || empty( $chosen_payment_method ) ) ) {

			$payment_gateways = $woocommerce->payment_gateways->payment_gateways();

			// Make sure the subscription is cancelled with the current gateway
			if ( ! empty( $existing_payment_method ) && isset( $payment_gateways[ $existing_payment_method ] ) && $payment_gateways[ $existing_payment_method ]->supports( 'subscriptions' ) ) {
				foreach ( $product_ids as $product_id ) {
					WC_Subscriptions_Payment_Gateways::trigger_gateway_cancelled_subscription_hook( absint( $_POST['customer_user'] ), WC_Subscriptions_Manager::get_subscription_key( $post_id, $product_id ) );
				}
			}

			if ( ! empty( $chosen_payment_method ) && isset( $payment_gateways[ $chosen_payment_method ] ) && $payment_gateways[ $chosen_payment_method ]->supports( 'subscriptions' ) ) {
				$manual_renewal = 'false';
			} else {
				$manual_renewal = 'true';
			}

			update_post_meta( $post_id, '_wcs_requires_manual_renewal', $manual_renewal );

			if ( ! empty( $chosen_payment_method ) ) {
				update_post_meta( $post_id, '_recurring_payment_method', stripslashes( $_POST['_recurring_payment_method'] ) );
			}

		}

		// Make sure the recurring order totals are correct
		update_post_meta( $post_id, '_order_recurring_discount_total', WC_Subscriptions::format_total( $_POST['_order_recurring_discount_total'] ) );
		update_post_meta( $post_id, '_order_recurring_total', WC_Subscriptions::format_total( $_POST['_order_recurring_total'] ) );

		// Update fields for WC < 2.1
		if ( WC_Subscriptions::is_woocommerce_pre_2_1() ) {

			// Also allow updates to the recurring payment method's title
			if ( isset( $_POST['_recurring_payment_method_title'] ) ) {
				update_post_meta( $post_id, '_recurring_payment_method_title', stripslashes( $_POST['_recurring_payment_method_title'] ) );
			} else { // it's been deleted
				update_post_meta( $post_id, '_recurring_payment_method_title', '' );
			}

			if ( isset( $_POST['_order_recurring_discount_cart'] ) ) {
				update_post_meta( $post_id, '_order_recurring_discount_cart', stripslashes( $_POST['_order_recurring_discount_cart'] ) );
			} else { // it's been deleted
				update_post_meta( $post_id, '_order_recurring_discount_cart', 0 );
			}

			if ( isset( $_POST['_order_recurring_tax_total'] ) ) { // WC < 2.1
				update_post_meta( $post_id, '_order_recurring_tax_total', stripslashes( $_POST['_order_recurring_tax_total'] ) );
			} else { // it's been deleted
				update_post_meta( $post_id, '_order_recurring_tax_total', 0 );
			}

			if ( isset( $_POST['_order_recurring_shipping_tax_total'] ) ) {
				update_post_meta( $post_id, '_order_recurring_shipping_tax_total', stripslashes( $_POST['_order_recurring_shipping_tax_total'] ) );
			} else { // it's been deleted
				update_post_meta( $post_id, '_order_recurring_shipping_tax_total', 0 );
			}

			if ( isset( $_POST['_order_recurring_shipping_total'] ) ) {
				update_post_meta( $post_id, '_order_recurring_shipping_total', stripslashes( $_POST['_order_recurring_shipping_total'] ) );
			} else { // it's been deleted
				update_post_meta( $post_id, '_order_recurring_shipping_total', 0 );
			}
		}

		// Save tax rows
		$total_tax          = 0;
		$total_shipping_tax = 0;

		if ( isset( $_POST['recurring_order_taxes_id'] ) ) { // WC 2.0+

			$tax_keys = array( 'recurring_order_taxes_id', 'recurring_order_taxes_rate_id', 'recurring_order_taxes_amount', 'recurring_order_taxes_shipping_amount' );

			foreach( $tax_keys as $tax_key ) {
				$$tax_key = isset( $_POST[ $tax_key ] ) ? $_POST[ $tax_key ] : array();
			}

			foreach( $recurring_order_taxes_id as $item_id => $value ) {

				$item_id  = absint( $item_id );
				$rate_id  = absint( $recurring_order_taxes_rate_id[ $item_id ] );

				if ( $rate_id ) {
					$rate     = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}woocommerce_tax_rates WHERE tax_rate_id = %s", $rate_id ) );
					$label    = $rate->tax_rate_name ? $rate->tax_rate_name : $woocommerce->countries->tax_or_vat();
					$compound = $rate->tax_rate_compound ? 1 : 0;

					$code = array();

					$code[] = $rate->tax_rate_country;
					$code[] = $rate->tax_rate_state;
					$code[] = $rate->tax_rate_name ? $rate->tax_rate_name : 'TAX';
					$code[] = absint( $rate->tax_rate_priority );
					$code   = strtoupper( implode( '-', array_filter( $code ) ) );
				} else {
					$code  = '';
					$label = $woocommerce->countries->tax_or_vat();
				}

				$wpdb->update(
					$wpdb->prefix . "woocommerce_order_items",
					array( 'order_item_name' => woocommerce_clean( $code ) ),
					array( 'order_item_id' => $item_id ),
					array( '%s' ),
					array( '%d' )
				);

				woocommerce_update_order_item_meta( $item_id, 'rate_id', $rate_id );
				woocommerce_update_order_item_meta( $item_id, 'label', $label );
				woocommerce_update_order_item_meta( $item_id, 'compound', $compound );

				if ( isset( $recurring_order_taxes_amount[ $item_id ] ) ) {
					woocommerce_update_order_item_meta( $item_id, 'tax_amount', WC_Subscriptions::format_total( $recurring_order_taxes_amount[ $item_id ] ) );

					$total_tax += WC_Subscriptions::format_total( $recurring_order_taxes_amount[ $item_id ] );
				}

				if ( isset( $recurring_order_taxes_shipping_amount[ $item_id ] ) ) {
					woocommerce_update_order_item_meta( $item_id, 'shipping_tax_amount', WC_Subscriptions::format_total( $recurring_order_taxes_shipping_amount[ $item_id ] ) );

					$total_shipping_tax += WC_Subscriptions::format_total( $recurring_order_taxes_shipping_amount[ $item_id ] );
				}
			}
		}

		if ( ! isset( $_POST['_order_recurring_tax_total'] ) && function_exists( 'wc_round_tax_total' ) ) { // WC 2.1+
			update_post_meta( $post_id, '_order_recurring_tax_total', wc_round_tax_total( $total_tax ) );
		}

		if ( ! isset( $_POST['_order_recurring_shipping_tax_total'] ) && function_exists( 'wc_round_tax_total' ) ) { // WC 2.1+
			update_post_meta( $post_id, '_order_recurring_shipping_tax_total', wc_round_tax_total( $total_shipping_tax ) );
		}

		// And that shipping methods are updated as required
		if ( isset( $_POST['_recurring_shipping_method'] ) || isset( $_POST['_recurring_shipping_method_title'] ) ) { // WC < 2.1
			update_post_meta( $post_id, '_recurring_shipping_method', stripslashes( $_POST['_recurring_shipping_method'] ) );
			update_post_meta( $post_id, '_recurring_shipping_method_title', stripslashes( $_POST['_recurring_shipping_method_title'] ) );
		}

		// Shipping Rows
		$recurring_order_shipping = 0;

		if ( isset( $_POST['recurring_shipping_method_id'] ) ) { // WC 2.1+

			$get_values = array( 'recurring_shipping_method_id', 'recurring_shipping_method_title', 'recurring_shipping_method', 'recurring_shipping_cost' );

			foreach( $get_values as $value ) {
				$$value = isset( $_POST[ $value ] ) ? $_POST[ $value ] : array();
			}

			foreach( $recurring_shipping_method_id as $item_id => $value ) {

				if ( 'new' == $item_id ) {

					foreach ( $value as $new_key => $new_value ) {
						$method_id    = woocommerce_clean( $recurring_shipping_method[ $item_id ][ $new_key ] );
						$method_title = woocommerce_clean( $recurring_shipping_method_title[ $item_id ][ $new_key ] );
						$cost         = WC_Subscriptions::format_total( $recurring_shipping_cost[ $item_id ][ $new_key ] );

						$new_id = woocommerce_add_order_item( $post_id, array(
							'order_item_name' => $method_title,
							'order_item_type' => 'recurring_shipping'
						) );

						if ( $new_id ) {
							woocommerce_add_order_item_meta( $new_id, 'method_id', $method_id );
							woocommerce_add_order_item_meta( $new_id, 'cost', $cost );
						}

						$recurring_order_shipping += $cost;
					}

				} elseif ( 'old' == $item_id ) { // Migrate a WC 2.0.n shipping method to WC 2.1 format

					$method_id    = woocommerce_clean( $recurring_shipping_method[ $item_id ] );
					$method_title = woocommerce_clean( $recurring_shipping_method_title[ $item_id ] );
					$cost         = WC_Subscriptions::format_total( $recurring_shipping_cost[ $item_id ] );

					$new_id = woocommerce_add_order_item( $post_id, array(
						'order_item_name' => $method_title,
						'order_item_type' => 'recurring_shipping'
					) );

					if ( $new_id ) {
						woocommerce_add_order_item_meta( $new_id, 'method_id', $method_id );
						woocommerce_add_order_item_meta( $new_id, 'cost', $cost );
					}

					$recurring_order_shipping += $cost;

					delete_post_meta( $post_id, '_recurring_shipping_method' );
					delete_post_meta( $post_id, '_recurring_shipping_method_title' );

				} else {

					$item_id      = absint( $item_id );
					$method_id    = woocommerce_clean( $recurring_shipping_method[ $item_id ] );
					$method_title = woocommerce_clean( $recurring_shipping_method_title[ $item_id ] );
					$cost         = WC_Subscriptions::format_total( $recurring_shipping_cost[ $item_id ] );

					$wpdb->update(
						$wpdb->prefix . "woocommerce_order_items",
						array( 'order_item_name' => $method_title ),
						array( 'order_item_id' => $item_id ),
						array( '%s' ),
						array( '%d' )
					);

					woocommerce_update_order_item_meta( $item_id, 'method_id', $method_id );
					woocommerce_update_order_item_meta( $item_id, 'cost', $cost );

					$recurring_order_shipping += $cost;
				}
			}
		}

		if ( ! isset( $_POST['_order_recurring_shipping_total'] ) ) { // WC 2.1+
			update_post_meta( $post_id, '_order_recurring_shipping_total', $recurring_order_shipping );
		}

		// Check if all the subscription products on the order have associated subscriptions on the user's account, and if not, add a new one
		foreach ( $product_ids as $order_item_id => $product_id ) {

			$is_existing_item = false;

			if ( in_array( $product_id, $existing_product_ids ) ) {
				$is_existing_item = true;
			}

			// If this is a new item and it's not a subscription product, ignore it
			if ( ! $is_existing_item && ! WC_Subscriptions_Product::is_subscription( $product_id ) ) {
				continue;
			}

			// If this is an existing item and it's not a subscription, ignore it
			if ( $is_existing_item && ! self::is_item_subscription( $order, $product_id ) ) {
				continue;
			}

			$subscription_key = WC_Subscriptions_Manager::get_subscription_key( $post_id, $product_id );

			$subscription = array();

			if ( ! empty( $order->customer_user ) && $_POST['customer_user'] != $order->customer_user ) {
				$customer_has_changed = true;

				$hook_args = array(
					'user_id' => (int)$order->customer_user,
					'subscription_key' => $subscription_key,
				);

				wc_unschedule_action( 'scheduled_subscription_trial_end', $hook_args );
				wc_unschedule_action( 'scheduled_subscription_payment', $hook_args );
				wc_unschedule_action( 'scheduled_subscription_expiration', $hook_args );

			} else {
				$customer_has_changed = false;
			}

			// In case it's a new order or the customer has changed
			$order->customer_user = $order->user_id = (int)$_POST['customer_user'];

			$subscription = WC_Subscriptions_Manager::get_subscription( $subscription_key );

			if ( empty( $subscription ) ) { // Add a new subscription

				// The order may not exist yet, so we need to set a few things ourselves
				if ( empty ( $order->order_key ) ) {
					$order->order_key = uniqid( 'order_' );
					add_post_meta( $post_id, '_order_key', $order->order_key, true );
				}

				if ( empty( $_POST['order_date'] ) ) {
					$start_date = gmdate( 'Y-m-d H:i:s' );
				} else {
					$start_date = get_gmt_from_date( $_POST['order_date'] . ' ' . (int) $_POST['order_date_hour'] . ':' . (int) $_POST['order_date_minute'] . ':00' );
				}

				WC_Subscriptions_Manager::create_pending_subscription_for_order( $order, $product_id, array( 'start_date' => $start_date ) );

				// Add the subscription meta for this item to the order
				$functions_and_meta = array( 'get_period' => '_order_subscription_periods', 'get_interval' => '_order_subscription_intervals', 'get_length' => '_order_subscription_lengths' );

				foreach ( $functions_and_meta as $function_name => $meta_key ) {
					$subscription_meta = self::get_meta( $order, $meta_key, array() );
					$subscription_meta[ $product_id ] = WC_Subscriptions_Product::$function_name( $product_id );
					update_post_meta( $order->id, $meta_key, $subscription_meta );
				}

				// This works because meta is added when the item is added via Ajax
				self::process_shop_order_item_meta( $post_id, $post );

				// If the order's existing status is something other than pending and the order status is not being changed, manually set the subscription's status (otherwise, it will be handled when WC transitions the order's status)
				if ( $order->status == $_POST['order_status'] && 'pending' != $order->status ) {
					switch( $order->status ) {
						case 'completed' :
						case 'processing' :
							WC_Subscriptions_Manager::activate_subscription( $order->customer_user, $subscription_key );
							break;
						case 'cancelled' :
							WC_Subscriptions_Manager::cancel_subscription( $order->customer_user, $subscription_key );
							break;
						case 'failed' :
							WC_Subscriptions_Manager::failed_subscription_signup( $order->customer_user, $subscription_key );
							break;
					}
				}
			}
		}

		// Determine whether we need to update any subscription dates for existing subscriptions (before the item meta is updated)
		if ( ! empty( $product_ids ) ) {

			$start_date = $_POST['order_date'] . ' ' . (int) $_POST['order_date_hour'] . ':' . (int) $_POST['order_date_minute'] . date( ':s', strtotime( $order->order_date ) );

			// Order's customer or start date changed for an existing order
			if ( $customer_has_changed || ( ! empty( $order->order_date ) && $order->order_date != $start_date ) ) {

				self::$requires_update['expiration_date']   = array_values( $product_ids );
				self::$requires_update['trial_expiration']  = array_values( $product_ids );
				self::$requires_update['next_billing_date'] = array_values( $product_ids );

			} elseif ( isset( $_POST['meta_key'] ) ) {

				$item_meta_keys  = ( isset( $_POST['meta_key'] ) ) ? $_POST['meta_key'] : array();
				$new_meta_values = ( isset( $_POST['meta_value'] ) ) ? $_POST['meta_value'] : array();

				foreach ( $item_meta_keys as $item_meta_id => $meta_key ) {

					$meta_data  = self::get_item_meta_data( $item_meta_id );
					$product_id = woocommerce_get_order_item_meta( $meta_data->order_item_id, '_product_id' );

					// Set flags to update payment dates if required
					switch( $meta_key ) {
						case '_subscription_period':
						case '_subscription_interval':
							if ( $new_meta_values[ $item_meta_id ] != $meta_data->meta_value ) {
								self::$requires_update['next_billing_date'][] = $product_id;
							}
							break;
						case '_subscription_start_date':
						case '_subscription_trial_length':
						case '_subscription_trial_period':
							if ( $new_meta_values[ $item_meta_id ] != $meta_data->meta_value ) {
								self::$requires_update['expiration_date'][]   = $product_id;
								self::$requires_update['trial_expiration'][]  = $product_id;
								self::$requires_update['next_billing_date'][] = $product_id;
							}
							break;
						case '_subscription_length':
							if ( $new_meta_values[ $item_meta_id ] != $meta_data->meta_value ) {
								self::$requires_update['expiration_date'][]   = $product_id;
								self::$requires_update['next_billing_date'][] = $product_id;
							}
							break;
						case '_subscription_trial_expiry_date':
							if ( $new_meta_values[ $item_meta_id ] != $meta_data->meta_value ) {
								self::$requires_update['trial_expiration'][]  = $product_id;
							}
							break;
						case '_subscription_expiry_date':
							if ( $new_meta_values[ $item_meta_id ] != $meta_data->meta_value ) {
								self::$requires_update['expiration_date'][]  = $product_id;
							}
							break;
					}
				}
			}
		}
	}

	/**
	 * Work around a bug in WooCommerce which ignores order item meta values of 0.
	 *
	 * Code in this function is identical to a section of the @see woocommerce_process_shop_order_meta() function, except
	 * that it doesn't include the bug which ignores item meta with a 0 value.
	 *
	 * @param int $post_id The ID of the post which is the WC_Order object.
	 * @param Object $post The post object of the order.
	 * @since 1.2.4
	 */
	public static function process_shop_order_item_meta( $post_id, $post ) {
		global $woocommerce;

		$product_ids = array();

		if ( isset( $_POST['order_item_id'] ) ) {
			foreach ( $_POST['order_item_id'] as $order_item_id ) { // WC 2.0+ has unique order item IDs and the product ID is a piece of meta
				$product_ids[ $order_item_id ] = woocommerce_get_order_item_meta( $order_item_id, '_product_id' );
			}
		}

		// Now that meta has been updated, we can update the schedules (if there were any changes to schedule related meta)
		if ( ! empty( $product_ids ) ) {

			$user_id = (int)$_POST['customer_user'];

			foreach ( $product_ids as $product_id ) {
				$subscription_key = WC_Subscriptions_Manager::get_subscription_key( $post_id, $product_id );

				// Order is important here, expiration date takes into account trial expriation date and next payment date takes into account expiration date
				if ( in_array( $product_id, self::$requires_update['trial_expiration'] ) ) {
					WC_Subscriptions_Manager::set_trial_expiration_date( $subscription_key, $user_id );
				}

				if ( in_array( $product_id, self::$requires_update['expiration_date'] ) ) {
					WC_Subscriptions_Manager::set_expiration_date( $subscription_key, $user_id );
				}

				if ( in_array( $product_id, self::$requires_update['next_billing_date'] ) ) {
					WC_Subscriptions_Manager::set_next_payment_date( $subscription_key, $user_id );
				}
			}
		}
	}

	/**
	 * Once payment is completed on an order, set a lock on payments until the next subscription payment period.
	 *
	 * @param int $user_id The id of the user who purchased the subscription
	 * @param string $subscription_key A subscription key of the form created by @see WC_Subscriptions_Manager::get_subscription_key()
	 * @since 1.1.2
	 */
	public static function safeguard_scheduled_payments( $order_id ) {

		$order = new WC_Order( $order_id );

		if ( self::order_contains_subscription( $order ) ) {

			$subscription_key = WC_Subscriptions_Manager::get_subscription_key( $order_id );

			WC_Subscriptions_Manager::safeguard_scheduled_payments( $order->customer_user, $subscription_key );

		}
	}

	/**
	 * Records the initial payment against a subscription.
	 *
	 * This function is called when a gateway calls @see WC_Order::payment_complete() and payment
	 * is completed on an order. It is also called when an orders status is changed to completed or
	 * processing for those gateways which never call @see WC_Order::payment_complete(), like the
	 * core WooCommerce Cheque and Bank Transfer gateways.
	 *
	 * It will also set the start date on the subscription to the time the payment is completed.
	 *
	 * @param WC_Order|int $order A WC_Order object or ID of a WC_Order order.
	 * @since 1.1.2
	 */
	public static function maybe_record_order_payment( $order ) {

		if ( ! is_object( $order ) ) {
			$order = new WC_Order( $order );
		}

		$subscriptions_in_order = self::get_recurring_items( $order );

		foreach ( $subscriptions_in_order as $subscription_item ) {

			$subscription_key = WC_Subscriptions_Manager::get_subscription_key( $order->id, self::get_items_product_id( $subscription_item ) );
			$subscription     = WC_Subscriptions_Manager::get_subscription( $subscription_key );

			// No payments have been recorded yet
			if ( empty( $subscription['completed_payments'] ) ) {

				// Don't duplicate orders
				remove_action( 'processed_subscription_payment', 'WC_Subscriptions_Renewal_Order::generate_paid_renewal_order', 10, 2 );

				WC_Subscriptions_Manager::update_subscription( $subscription_key, array( 'start_date' => gmdate( 'Y-m-d H:i:s' ) ) );

				WC_Subscriptions_Manager::process_subscription_payments_on_order( $order->id );

				WC_Subscriptions_Manager::safeguard_scheduled_payments( $order->customer_user, $subscription_key );

				// Make sure orders are still generated for other payments in the same request
				add_action( 'processed_subscription_payment', 'WC_Subscriptions_Renewal_Order::generate_paid_renewal_order', 10, 2 );
			}
		}
	}

	/* Order Price Getters */

	/**
	 * Returns the proportion of cart discount that is recurring for the product specified with $product_id
	 *
	 * @param WC_Order|int $order A WC_Order object or ID of a WC_Order order.
	 * @since 1.2
	 */
	public static function get_recurring_discount_cart( $order, $product_id = '' ) {
		return self::get_meta( $order, '_order_recurring_discount_cart', 0 );
	}

	/**
	 * Returns the proportion of total discount that is recurring for the product specified with $product_id
	 *
	 * @param WC_Order|int $order A WC_Order object or ID of a WC_Order order.
	 * @since 1.2
	 */
	public static function get_recurring_discount_total( $order, $product_id = '' ) {
		return self::get_meta( $order, '_order_recurring_discount_total', 0 );
	}

	/**
	 * Returns the amount of shipping tax that is recurring. As shipping only applies
	 * to recurring payments, and only 1 subscription can be purchased at a time,
	 * this is equal to @see WC_Order::get_total_tax()
	 *
	 * @param WC_Order|int $order A WC_Order object or ID of a WC_Order order.
	 * @since 1.2
	 */
	public static function get_recurring_shipping_tax_total( $order, $product_id = '' ) {
		return self::get_meta( $order, '_order_recurring_shipping_tax_total', 0 );
	}

	/**
	 * Returns the recurring shipping price . As shipping only applies to recurring
	 * payments, and only 1 subscription can be purchased at a time, this is
	 * equal to @see WC_Order::get_total_shipping()
	 *
	 * @param WC_Order|int $order A WC_Order object or ID of a WC_Order order.
	 * @since 1.2
	 */
	public static function get_recurring_shipping_total( $order, $product_id = '' ) {
		return self::get_meta( $order, '_order_recurring_shipping_total', 0 );
	}

	/**
	 * Returns an array of items in an order which are recurring along with their recurring totals.
	 *
	 * @param WC_Order|int $order A WC_Order object or ID of a WC_Order order.
	 * @since 1.2
	 */
	public static function get_recurring_items( $order ) {

		if ( ! is_object( $order ) ) {
			$order = new WC_Order( $order );
		}

		$items = array();

		foreach ( $order->get_items() as $item_id => $item_details ) {

			if ( ! self::is_item_subscription( $order, $item_details ) ) {
				continue;
			}

			$items[ $item_id ] = $item_details;

			foreach ( $item_details['item_meta'] as $meta_key => $meta_value ) {

				$meta_value = $meta_value[0];

				switch ( $meta_key ) {
					case '_recurring_line_subtotal' :
						$items[ $item_id ]['line_subtotal'] = $meta_value;
						break;
					case '_recurring_line_subtotal_tax' :
						$items[ $item_id ]['line_subtotal_tax'] = $meta_value;
						break;
					case '_recurring_line_total' :
						$items[ $item_id ]['line_total'] = $meta_value;
						break;
					case '_recurring_line_tax' :
						$items[ $item_id ]['line_tax'] = $meta_value;
						break;
				}

			}

		}

		return $items;
	}

	/**
	 * Checks if a given order item is a subscription. A subscription with will have a piece of meta
	 * with the 'meta_name' starting with 'recurring' or 'subscription'.
	 *
	 * @param WC_Order|int $order A WC_Order object or ID of a WC_Order order.
	 * @param array $item | int An array representing an order item or a product ID of an item in an order (not an order item ID)
	 * @since 1.2
	 */
	public static function is_item_subscription( $order, $item ) {

		if ( ! is_array( $item ) ) {
			$item = self::get_item_by_product_id( $order, $item );
		}

		$item_is_subscription = false;

		if ( isset( $item['item_meta'] ) && is_array( $item['item_meta'] ) ) {
			foreach ( $item['item_meta'] as $item_key => $item_meta ) {
				if ( 0 === strncmp( $item_key, '_subscription', strlen( '_subscription' ) ) || 0 === strncmp( $item_key, '_recurring', strlen( '_recurring' ) ) ) {
					$item_is_subscription = true;
					break;
				}
			}
		}

		return $item_is_subscription;
	}

	/**
	 * Returns an array of taxes on an order with their recurring totals.
	 *
	 * @param WC_Order|int $order A WC_Order object or ID of a WC_Order order.
	 * @since 1.2
	 */
	public static function get_recurring_taxes( $order ) {

		if ( ! is_object( $order ) ) {
			$order = new WC_Order( $order );
		}

		$recurring_taxes = $order->get_items( 'recurring_tax' );

		return $recurring_taxes;
	}

	/**
	 * Returns the proportion of total tax on an order that is recurring for the product specified with $product_id
	 *
	 * @param WC_Order|int $order A WC_Order object or ID of a WC_Order order.
	 * @since 1.2
	 */
	public static function get_recurring_total_tax( $order, $product_id = '' ) {
		return self::get_meta( $order, '_order_recurring_tax_total', 0 );
	}

	/**
	 * Returns the proportion of total before tax on an order that is recurring for the product specified with $product_id
	 *
	 * @param WC_Order|int $order A WC_Order object or ID of a WC_Order order.
	 * @since 1.2
	 */
	public static function get_recurring_total_ex_tax( $order, $product_id = '' ) {
		return self::get_recurring_total( $order, $product_id ) - self::get_recurring_total_tax( $order, $product_id );
	}

	/**
	 * Returns the price per period for a subscription in an order.
	 *
	 * @param mixed $order A WC_Order object or the ID of the order which the subscription was purchased in.
	 * @param int $product_id (optional) The post ID of the subscription WC_Product object purchased in the order. Defaults to the ID of the first product purchased in the order.
	 * @since 1.2
	 */
	public static function get_recurring_total( $order ) {
		return self::get_meta( $order, '_order_recurring_total', 0 );
	}

	/**
	 * Creates a string representation of the subscription period/term for each item in the cart
	 *
	 * @param WC_Order $order A WC_Order object.
	 * @param mixed $deprecated Never used.
	 * @param mixed $deprecated Never used.
	 * @since 1.0
	 */
	public static function get_order_subscription_string( $order, $deprecated_price = '', $deprecated_sign_up_fee = '' ) {

		if ( ! empty( $deprecated_price ) || ! empty( $deprecated_sign_up_fee ) ) {
			_deprecated_argument( __CLASS__ . '::' . __FUNCTION__, '1.2' );
		}

		$initial_amount = woocommerce_price( self::get_total_initial_payment( $order ) );

		$subscription_string = self::get_formatted_order_total( $initial_amount, $order );

		return $subscription_string;
	}


	/* Edit Order Ajax Handlers */

	/**
	 * Add subscription related order item meta when a subscription product is added as an item to an order via Ajax.
	 *
	 * @param item_id int An order_item_id as returned by the insert statement of @see woocommerce_add_order_item()
	 * @since 1.2.5
	 * @version 1.4
	 * @return void
	 */
	public static function prefill_order_item_meta( $item, $item_id ) {

		$order_id   = $_POST['order_id'];
		$product_id = $item['variation_id'] ? $item['variation_id'] : $item['product_id'];

		if ( $item_id && WC_Subscriptions_Product::is_subscription( $product_id ) ) {

			$order    = new WC_Order( $order_id );
			$_product = get_product( $product_id );

			$recurring_amount  = $_product->get_price_excluding_tax();
			$sign_up_fee       = $_product->get_sign_up_fee_excluding_tax();
			$free_trial_length = WC_Subscriptions_Product::get_trial_length( $product_id );

			woocommerce_add_order_item_meta( $item_id, '_subscription_period', WC_Subscriptions_Product::get_period( $product_id ) );
			woocommerce_add_order_item_meta( $item_id, '_subscription_interval', WC_Subscriptions_Product::get_interval( $product_id ) );
			woocommerce_add_order_item_meta( $item_id, '_subscription_length', WC_Subscriptions_Product::get_length( $product_id ) );
			woocommerce_add_order_item_meta( $item_id, '_subscription_trial_length', $free_trial_length );
			woocommerce_add_order_item_meta( $item_id, '_subscription_trial_period', WC_Subscriptions_Product::get_trial_period( $product_id ) );
			woocommerce_add_order_item_meta( $item_id, '_subscription_recurring_amount', $recurring_amount );
			woocommerce_add_order_item_meta( $item_id, '_subscription_sign_up_fee', $sign_up_fee );

			woocommerce_add_order_item_meta( $item_id, '_recurring_line_total', $recurring_amount );
			woocommerce_add_order_item_meta( $item_id, '_recurring_line_tax', 0 );
			woocommerce_add_order_item_meta( $item_id, '_recurring_line_subtotal', $recurring_amount );
			woocommerce_add_order_item_meta( $item_id, '_recurring_line_subtotal_tax', 0 );

			WC_Subscriptions_Manager::create_pending_subscription_for_order( $order_id, $item['product_id'] );

			switch ( $order->status ) {
				case 'completed' :
				case 'processing' :
					woocommerce_update_order_item_meta( $item_id, '_subscription_status', 'active' );
					break;
				case 'on-hold' :
					woocommerce_update_order_item_meta( $item_id, '_subscription_status', 'on-hold' );
					break;
				case 'failed' :
				case 'cancelled' :
					woocommerce_add_order_item_meta( $item_id, '_subscription_status', 'cancelled' );
					break;
			}

			// We need to override the line totals to $0 when there is a free trial
			if ( $free_trial_length > 0 || $sign_up_fee > 0 ) {

				$line_total_keys = array(
					'line_subtotal',
					'line_total',
				);

				// Make sure sign up fees are included in the total (or $0 if no sign up fee and a free trial)
				foreach( $line_total_keys as $line_total_key ){
					$item[ $line_total_key ] = $sign_up_fee;
				}

				// If there is no free trial, make sure line totals include sign up fee and recurring fees
				if ( 0 == $free_trial_length ) {
					foreach( $line_total_keys as $line_total_key ) {
						$item[ $line_total_key ] += $recurring_amount;
					}
				}

				foreach( $line_total_keys as $line_total_key ) {
					$item[ $line_total_key ] = WC_Subscriptions::format_total( $item[ $line_total_key ], 2 );
				}

			} else {

				$item['line_subtotal'] = $recurring_amount;
				$item['line_total']    = $recurring_amount;

			}

			woocommerce_update_order_item_meta( $item_id, '_line_subtotal', $item['line_subtotal'] );
			woocommerce_update_order_item_meta( $item_id, '_line_total', $item['line_total'] );

		}

		return $item;
	}

	/**
	 * Calculate recurring line taxes when a store manager clicks the "Calc Line Tax" button on the "Edit Order" page.
	 *
	 * Based on the @see woocommerce_calc_line_taxes() function.
	 * @since 1.2.4
	 * @return void
	 */
	public static function calculate_recurring_line_taxes() {
		global $woocommerce, $wpdb;

		check_ajax_referer( 'woocommerce-subscriptions', 'security' );

		$tax = new WC_Tax();

		$taxes = $tax_rows = $item_taxes = $shipping_taxes = $return = array();

		$item_tax = 0;

		$order_id      = absint( $_POST['order_id'] );
		$country       = strtoupper( esc_attr( $_POST['country'] ) );
		$state         = strtoupper( esc_attr( $_POST['state'] ) );
		$postcode      = strtoupper( esc_attr( $_POST['postcode'] ) );
		$tax_class     = esc_attr( $_POST['tax_class'] );

		if ( isset( $_POST['city'] ) ) {
			$city = sanitize_title( esc_attr( $_POST['city'] ) );
		}

		$shipping = $_POST['shipping'];

		$line_subtotal = isset( $_POST['line_subtotal'] ) ? esc_attr( $_POST['line_subtotal'] ) : 0;
		$line_total    = isset( $_POST['line_total'] ) ? esc_attr( $_POST['line_total'] ) : 0;

		$product_id = '';

		if ( isset( $_POST['order_item_id'] ) ) {
			$product_id = woocommerce_get_order_item_meta( $_POST['order_item_id'], '_product_id' );
		} elseif ( isset( $_POST['product_id'] ) ) {
			$product_id = esc_attr( $_POST['product_id'] );
		}

		if ( ! empty( $product_id ) && WC_Subscriptions_Product::is_subscription( $product_id ) ) {

			// Get product details
			$product         = WC_Subscriptions::get_product( $product_id );
			$item_tax_status = $product->get_tax_status();

			if ( $item_tax_status == 'taxable' ) {

				$tax_rates = $tax->find_rates( array(
					'country'   => $country,
					'state'     => $state,
					'postcode'  => $postcode,
					'city'      => $city,
					'tax_class' => $tax_class
				) );

				$line_subtotal_taxes = $tax->calc_tax( $line_subtotal, $tax_rates, false );
				$line_taxes = $tax->calc_tax( $line_total, $tax_rates, false );

				$line_subtotal_tax = $tax->round( array_sum( $line_subtotal_taxes ) );
				$line_tax = $tax->round( array_sum( $line_taxes ) );

				if ( $line_subtotal_tax < 0 ) {
					$line_subtotal_tax = 0;
				}

				if ( $line_tax < 0 ) {
					$line_tax = 0;
				}

				$return = array(
					'recurring_line_subtotal_tax' => $line_subtotal_tax,
					'recurring_line_tax'          => $line_tax
				);

				// Sum the item taxes
				foreach ( array_keys( $taxes + $line_taxes ) as $key ) {
					$taxes[ $key ] = ( isset( $line_taxes[ $key ] ) ? $line_taxes[ $key ] : 0 ) + ( isset( $taxes[ $key ] ) ? $taxes[ $key ] : 0 );
				}
			}

			// Now calculate shipping tax
			$matched_tax_rates = array();

			$tax_rates = $tax->find_rates( array(
				'country' 	=> $country,
				'state' 	=> $state,
				'postcode' 	=> $postcode,
				'city'		=> $city,
				'tax_class' => ''
			) );

			if ( $tax_rates ) {
				foreach ( $tax_rates as $key => $rate ) {
					if ( isset( $rate['shipping'] ) && $rate['shipping'] == 'yes' ) {
						$matched_tax_rates[ $key ] = $rate;
					}
				}
			}

			$shipping_taxes = $tax->calc_shipping_tax( $shipping, $matched_tax_rates );
			$shipping_tax = $tax->round( array_sum( $shipping_taxes ) );
			$return['recurring_shipping_tax'] = $shipping_tax;

			// Remove old tax rows
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}woocommerce_order_itemmeta WHERE order_item_id IN ( SELECT order_item_id FROM {$wpdb->prefix}woocommerce_order_items WHERE order_id = %d AND order_item_type = 'recurring_tax' )", $order_id ) );

			$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}woocommerce_order_items WHERE order_id = %d AND order_item_type = 'recurring_tax'", $order_id ) );

		 	// Get tax rates
			$rates = $wpdb->get_results( "SELECT tax_rate_id, tax_rate_country, tax_rate_state, tax_rate_name, tax_rate_priority FROM {$wpdb->prefix}woocommerce_tax_rates ORDER BY tax_rate_name" );

			$tax_codes = array();

			foreach( $rates as $rate ) {
				$code = array();

				$code[] = $rate->tax_rate_country;
				$code[] = $rate->tax_rate_state;
				$code[] = $rate->tax_rate_name ? sanitize_title( $rate->tax_rate_name ) : 'TAX';
				$code[] = absint( $rate->tax_rate_priority );

				$tax_codes[ $rate->tax_rate_id ] = strtoupper( implode( '-', array_filter( $code ) ) );
			}

			// Now merge to keep tax rows
			ob_start();

			foreach ( array_keys( $taxes + $shipping_taxes ) as $key ) {

				$item                        = array();
				$item['rate_id']             = $key;
				$item['name']                = $tax_codes[ $key ];
				$item['label']               = $tax->get_rate_label( $key );
				$item['compound']            = $tax->is_compound( $key ) ? 1 : 0;
				$item['tax_amount']          = $tax->round( isset( $taxes[ $key ] ) ? $taxes[ $key ] : 0 );
				$item['shipping_tax_amount'] = $tax->round( isset( $shipping_taxes[ $key ] ) ? $shipping_taxes[ $key ] : 0 );

				if ( ! $item['label'] ) {
					$item['label'] = $woocommerce->countries->tax_or_vat();
				}

				// Add line item
				$item_id = woocommerce_add_order_item( $order_id, array(
					'order_item_name' => $item['name'],
					'order_item_type' => 'recurring_tax'
				) );

				// Add line item meta
				if ( $item_id ) {
					woocommerce_add_order_item_meta( $item_id, 'rate_id', $item['rate_id'] );
					woocommerce_add_order_item_meta( $item_id, 'label', $item['label'] );
					woocommerce_add_order_item_meta( $item_id, 'compound', $item['compound'] );
					woocommerce_add_order_item_meta( $item_id, 'tax_amount', $item['tax_amount'] );
					woocommerce_add_order_item_meta( $item_id, 'shipping_tax_amount', $item['shipping_tax_amount'] );
				}

				include( plugin_dir_path( WC_Subscriptions::$plugin_file ) . 'templates/admin/post-types/writepanels/order-tax-html.php' );
			}

			$return['tax_row_html'] = ob_get_clean();

			echo json_encode( $return );

		}

		die();
	}


	/* Edit Order Page Content */

	/**
	 * Display recurring order totals on the "Edit Order" page.
	 *
	 * @param int $post_id The post ID of the shop_order post object.
	 * @since 1.2.4
	 * @return void
	 */
	public static function recurring_order_totals_meta_box_section( $post_id ) {
		global $woocommerce, $wpdb, $current_screen;

		$order = new WC_Order( $post_id );

		$display_none = ' style="display: none"';

		$contains_subscription = ( self::order_contains_subscription( $order ) ) ? true : false;

		$chosen_gateway = WC_Subscriptions_Payment_Gateways::get_payment_gateway( get_post_meta( $post_id, '_recurring_payment_method', true ) );

		$manual_renewal = self::requires_manual_renewal( $post_id );

		$changes_supported = ( $chosen_gateway === false || $manual_renewal == 'true' || $chosen_gateway->supports( 'subscription_amount_changes' ) ) ? 'true' : 'false';

		$data = get_post_meta( $post_id );

		if ( WC_Subscriptions::is_woocommerce_pre_2_2() ) : ?>
	<div class="clear"></div>
</div>
		<?php endif; ?>
<div id="gateway_support"<?php if ( ! $contains_subscription ) { echo $display_none; } ?>>
	<input type="hidden" name="gateway_supports_subscription_changes" value="<?php echo $changes_supported; ?>">
	<div class="error"<?php if ( ! $contains_subscription || $changes_supported == 'true' ) { echo $display_none; } ?>>
		<p><?php printf( __( 'The %s payment gateway is used to charge automatic subscription payments for this order. This gateway <strong>does not</strong> support changing a subscription\'s details.', 'woocommerce-subscriptions' ), get_post_meta( $post_id, '_recurring_payment_method_title', true ) ); ?></p>
		<p>
			<?php _e( 'It is strongly recommended you <strong>do not change</strong> any of the recurring totals or subscription item\'s details.', 'woocommerce-subscriptions' ); ?>
			<a href="http://docs.woothemes.com/document/subscriptions/add-or-modify-a-subscription/#section-4"><?php _e( 'Learn More', 'woocommerce-subscriptions' ); ?> &raquo;</a>
		</p>
	</div>
</div>
<div id="recurring_order_totals"<?php if ( ! $contains_subscription ) { echo $display_none; } ?>>
	<?php if ( WC_Subscriptions::is_woocommerce_pre_2_2() ) : ?>
	<h3><?php _e( 'Recurring Totals', 'woocommerce-subscriptions'); ?></h3>
	<?php endif; ?>

	<?php if ( 'add' !== $current_screen->action ) : // Can't add recurring shipping to a manually added subscription ?>
	<div class="totals_group">
		<h4><span class="tax_total_display inline_total"></span><?php _e( 'Shipping for Renewal Orders', 'woocommerce-subscriptions' ); ?></h4>
		<div id="recurring_shipping_rows">
		<?php
		if ( ! WC_Subscriptions::is_woocommerce_pre_2_1() ) {

			if ( $woocommerce->shipping() ) {
				$shipping_methods = $woocommerce->shipping->load_shipping_methods();
			}

			foreach ( self::get_recurring_shipping_methods( $order ) as $item_id => $item ) {

				$chosen_method  = $item['method_id'];
				$shipping_title = $item['name'];
				$shipping_cost  = $item['cost'];

				include( plugin_dir_path( WC_Subscriptions::$plugin_file ) . 'templates/admin/post-types/writepanels/order-shipping-html.php' );
			}

			// Shipping created pre 2.1
			if ( isset( $data['_recurring_shipping_method'] ) ) {
				$item_id        = 'old'; // so that when saving, we know to delete the data in the old form
				$chosen_method  = ! empty( $data['_recurring_shipping_method'][0] ) ? $data['_recurring_shipping_method'][0] : '';
				$shipping_title = ! empty( $data['_recurring_shipping_method_title'][0] ) ? $data['_recurring_shipping_method_title'][0] : '';
				$shipping_cost  = ! empty( $data['_order_recurring_shipping_total'][0] ) ? $data['_order_recurring_shipping_total'][0] : '0.00';

				include( plugin_dir_path( WC_Subscriptions::$plugin_file ) . 'templates/admin/post-types/writepanels/order-shipping-html.php' );
			} ?>
		<?php } else { // WC < 2.1 ?>
			<ul class="totals">
				<li class="wide">
					<label><?php _e( 'Label:', 'woocommerce-subscriptions' ); ?></label>
					<input type="text" id="_recurring_shipping_method_title" name="_recurring_shipping_method_title" placeholder="<?php _e( 'The shipping title for renewal orders', 'woocommerce-subscriptions' ); ?>" value="<?php echo $order->recurring_shipping_method_title; ?>" class="first" />
				</li>

				<li class="left">
					<label><?php _e( 'Cost:', 'woocommerce-subscriptions' ); ?></label>
					<input type="text" id="_order_recurring_shipping_total" name="_order_recurring_shipping_total" placeholder="0.00 <?php _e( '(ex. tax)', 'woocommerce-subscriptions' ); ?>" value="<?php echo self::get_recurring_shipping_total( $order ); ?>" class="first" />
				</li>

				<li class="right">
					<label><?php _e( 'Method:', 'woocommerce-subscriptions' ); ?></label>
					<select name="_recurring_shipping_method" id="_recurring_shipping_method" class="first">
						<option value=""><?php _e( 'N/A', 'woocommerce-subscriptions' ); ?></option>
						<?php
							$chosen_shipping_method = $order->recurring_shipping_method;
							$found_method           = false;

							if ( $woocommerce->shipping() ) {
								foreach ( $woocommerce->shipping->load_shipping_methods() as $method ) {

									if ( strpos( $chosen_shipping_method, $method->id ) === 0 ) {
										$value = $chosen_shipping_method;
									} else {
										$value = $method->id;
									}

									echo '<option value="' . esc_attr( $value ) . '" ' . selected( $chosen_shipping_method == $value, true, false ) . '>' . esc_html( $method->get_title() ) . '</option>';

									if ( $chosen_shipping_method == $value ) {
										$found_method = true;
									}
								}
							}

							if ( ! $found_method && ! empty( $chosen_shipping_method ) ) {
								echo '<option value="' . esc_attr( $chosen_shipping_method ) . '" selected="selected">' . __( 'Other', 'woocommerce-subscriptions' ) . '</option>';
							} else {
								echo '<option value="other">' . __( 'Other', 'woocommerce-subscriptions' ) . '</option>';
							}
						?>
					</select>
				</li>
			</ul>
		<?php } // ! WC_Subscriptions::is_woocommerce_pre_2_1() ?>
		</div>
		<div class="clear"></div>
	</div>
	<?php endif; ?>

	<?php if ( 'yes' == get_option( 'woocommerce_calc_taxes' ) ) : ?>

	<div class="totals_group tax_rows_group">
		<h4>
			<span class="tax_total_display inline_total"></span>
			<?php _e( 'Recurring Taxes', 'woocommerce-subscriptions' ); ?>
			<a class="tips" data-tip="<?php _e( 'These rows contain taxes included in each recurring amount for this subscription. This allows you to display multiple or compound taxes rather than a single total on future subscription renewal orders.', 'woocommerce-subscriptions' ); ?>" href="#">[?]</a>
		</h4>
		<div id="recurring_tax_rows" class="total_rows">
			<?php
				$loop = 0;
				$taxes = self::get_recurring_taxes( $order );
				if ( is_array( $taxes ) && sizeof( $taxes ) > 0 ) :

					$rates = $wpdb->get_results( "SELECT tax_rate_id, tax_rate_country, tax_rate_state, tax_rate_name, tax_rate_priority FROM {$wpdb->prefix}woocommerce_tax_rates ORDER BY tax_rate_name" );

					$tax_codes = array();

					foreach( $rates as $rate ) {
						$code = array();

						$code[] = $rate->tax_rate_country;
						$code[] = $rate->tax_rate_state;
						$code[] = $rate->tax_rate_name ? sanitize_title( $rate->tax_rate_name ) : 'TAX';
						$code[] = absint( $rate->tax_rate_priority );

						$tax_codes[ $rate->tax_rate_id ] = strtoupper( implode( '-', array_filter( $code ) ) );
					}

					foreach ( $taxes as $item_id => $item ) {
						include( plugin_dir_path( WC_Subscriptions::$plugin_file ) . 'templates/admin/post-types/writepanels/order-tax-html.php' );
						$loop++;
					}
				endif;
			?>
		</div>
		<h4 style="padding-bottom: 10px;"><a href="#" class="add_recurring_tax_row"><?php _e( '+ Add tax row', 'woocommerce-subscriptions' ); ?></a></h4>
		<div class="clear"></div>
	</div>

	<?php if ( WC_Subscriptions::is_woocommerce_pre_2_1() ) : ?>
	<div class="totals_group">
		<h4><span class="tax_total_display inline_total"></span><?php _e( 'Tax Totals', 'woocommerce-subscriptions' ); ?></h4>
		<ul class="totals">

			<li class="left">
				<label><?php _e( 'Recurring Sales Tax:', 'woocommerce-subscriptions' ); ?></label>
				<input type="number" step="any" min="0" id="_order_recurring_tax_total" name="_order_recurring_tax_total" placeholder="0.00" value="<?php echo self::get_recurring_total_tax( $order ); ?>" class="calculated" />
			</li>

			<li class="right">
				<label><?php _e( 'Shipping Tax:', 'woocommerce-subscriptions' ); ?></label>
				<input type="number" step="any" min="0" id="_order_recurring_shipping_tax_total" name="_order_recurring_shipping_tax_total" placeholder="0.00" value="<?php echo self::get_recurring_shipping_tax_total( $order ); ?>" class="calculated" />
			</li>

		</ul>
		<div class="clear"></div>
	</div>
	<?php endif; // WC_Subscriptions::is_woocommerce_pre_2_1() ?>

	<?php endif; // woocommerce_calc_taxes ?>

	<div class="totals_group">
		<h4><label for="_order_recurring_discount_total"><?php _e( 'Recurring Order Discount', 'woocommerce-subscriptions'); ?> <a class="tips" data-tip="<?php _e( 'The discounts applied to each recurring payment charged in the future.', 'woocommerce-subscriptions' ); ?>" href="#">[?]</a></label></h4>
		<input type="text" class="wc_input_price"  id="_order_recurring_discount_total" name="_order_recurring_discount_total" placeholder="<?php echo wc_format_localized_price( 0 ); ?>" value="<?php echo esc_attr( wc_format_localized_price( self::get_recurring_discount_total( $order ) ) ); ?>" style="margin: 6px 0 10px;"/>
	</div>

	<div class="totals_group">
		<h4><label for="_order_recurring_total"><?php _e( 'Recurring Order Total', 'woocommerce-subscriptions' ); ?> <a class="tips" data-tip="<?php _e( 'The total amounts charged for each future recurring payment.', 'woocommerce-subscriptions' ); ?>" href="#">[?]</a></label></h4>
		<input type="text" id="_order_recurring_total" name="_order_recurring_total" placeholder="<?php echo wc_format_localized_price( 0 ); ?>" value="<?php echo esc_attr( wc_format_localized_price( self::get_recurring_total( $order ) ) ); ?>" class="calculated"  style="margin: 6px 0 10px;"/>
	</div>

	<div class="totals_group">
		<h4><?php _e( 'Recurring Payment Method:', 'woocommerce-subscriptions' ); ?></h4>
		<div class="<?php echo $order->recurring_payment_method; ?>" style="padding-top: 4px; font-style: italic; margin: 2px 0 10px;"><?php echo ( $manual_renewal || empty( $order->recurring_payment_method ) ) ? __( 'Manual', 'woocommerce-subscriptions' ) : $order->recurring_payment_method_title; ?></div>
	</div>
		<?php if ( ! WC_Subscriptions::is_woocommerce_pre_2_2() ) : ?>
</div>
		<?php endif; ?>
<?php
	}

	/**
	 * Adds a line tax item from an order by ID. Hooked to
	 * an Ajax call from the "Edit Order" page and mirrors the
	 * @see woocommerce_add_line_tax() function.
	 *
	 * @return void
	 */
	public static function add_line_tax() {
		global $woocommerce, $wpdb;

		check_ajax_referer( 'woocommerce-subscriptions', 'security' );

		$order_id = absint( $_POST['order_id'] );
		$order    = new WC_Order( $order_id );

	 	// Get tax rates
		$rates = $wpdb->get_results( "SELECT tax_rate_id, tax_rate_country, tax_rate_state, tax_rate_name, tax_rate_priority FROM {$wpdb->prefix}woocommerce_tax_rates ORDER BY tax_rate_name" );

		$tax_codes = array();

		foreach( $rates as $rate ) {
			$code = array();

			$code[] = $rate->tax_rate_country;
			$code[] = $rate->tax_rate_state;
			$code[] = $rate->tax_rate_name ? sanitize_title( $rate->tax_rate_name ) : 'TAX';
			$code[] = absint( $rate->tax_rate_priority );

			$tax_codes[ $rate->tax_rate_id ] = strtoupper( implode( '-', array_filter( $code ) ) );
		}

		// Add line item
		$item_id = woocommerce_add_order_item( $order_id, array(
			'order_item_name' => '',
			'order_item_type' => 'recurring_tax'
		) );

		// Add line item meta
		if ( $item_id ) {
			woocommerce_add_order_item_meta( $item_id, 'rate_id', '' );
			woocommerce_add_order_item_meta( $item_id, 'label', '' );
			woocommerce_add_order_item_meta( $item_id, 'compound', '' );
			woocommerce_add_order_item_meta( $item_id, 'tax_amount', '' );
			woocommerce_add_order_item_meta( $item_id, 'shipping_tax_amount', '' );
		}

		include( plugin_dir_path( WC_Subscriptions::$plugin_file ) . 'templates/admin/post-types/writepanels/order-tax-html.php' );

		die();
	}

	/**
	 * Removes a line tax item from an order by ID. Hooked to
	 * an Ajax call from the "Edit Order" page and mirrors the
	 * @see woocommerce_remove_line_tax() function.
	 *
	 * @return void
	 */
	public static function remove_line_tax() {

		check_ajax_referer( 'woocommerce-subscriptions', 'security' );

		$tax_row_id = absint( $_POST['tax_row_id'] );

		woocommerce_delete_order_item( $tax_row_id );

		die();
	}

	/**
	 * Checks if an order contains an in active subscription and if it does, denies download acces
	 * to files purchased on the order.
	 *
	 * @return bool False if the order contains a subscription that has expired or is cancelled/on-hold, otherwise, the original value of $download_permitted
	 * @since 1.3
	 */
	public static function is_download_permitted( $download_permitted, $order ) {

		if ( self::order_contains_subscription( $order ) ) {

			foreach ( self::get_recurring_items( $order ) as $order_item ) {

				$subscription_key = WC_Subscriptions_Manager::get_subscription_key( $order->id, self::get_items_product_id( $order_item ) );
				$subscription     = WC_Subscriptions_Manager::get_subscription( $subscription_key );

				if ( ! isset( $subscription['status'] ) || 'active' !== $subscription['status'] ) {
					$download_permitted = false;
					break;
				}
			}

		}

		return $download_permitted;
	}

	/**
	 * Returns all parent subscription orders for a user, specificed with $user_id
	 *
	 * @return array An array of order IDs.
	 * @since 1.4
	 */
	public static function get_users_subscription_orders( $user_id = 0 ) {
		global $wpdb;

		if ( 0 === $user_id ) {
			$user_id = get_current_user_id();
		}

		// Get all the customers orders which are not subscription renewal orders
		$order_ids = $wpdb->get_col( $wpdb->prepare(
			"SELECT ID FROM $wpdb->posts as posts
				LEFT JOIN $wpdb->postmeta as postmeta ON posts.ID = postmeta.post_id
				WHERE post_type = 'shop_order'
				AND post_parent = 0
				AND postmeta.meta_key = '_customer_user'
				AND postmeta.meta_value = %s;
			",
			$user_id
			)
		);

		foreach ( $order_ids as $index => $order_id ) {
			if ( ! self::order_contains_subscription( $order_id ) ) {
				unset( $order_ids[ $index ] );
			}
		}

		// Normalise array keys
		$order_ids = array_values( $order_ids );

		return apply_filters( 'users_subscription_orders', $order_ids, $user_id );
	}

	/**
	 * Load Subscription related order data when populating an order
	 *
	 * @since 1.4
	 */
	public static function load_order_data( $order_data ) {

		$order_data = $order_data + array(
			'recurring_shipping_method'       => '',
			'recurring_shipping_method_title' => '',
			'recurring_payment_method'        => '',
			'recurring_payment_method_title'  => '',
		);

		return $order_data;
	}

	/**
	 * Don't display all subscription meta data on the Edit Order screen
	 *
	 * @since 1.4
	 */
	public static function hide_order_itemmeta( $hidden_meta_keys ) {

		if ( ! defined( 'WCS_DEBUG' ) || true !== WCS_DEBUG ) {
			$hidden_meta_keys = array_merge( $hidden_meta_keys, array(
				'_subscription_status',
				'_subscription_start_date',
				'_subscription_expiry_date',
				'_subscription_end_date',
				'_subscription_trial_expiry_date',
				'_subscription_completed_payments',
				'_subscription_suspension_count',
				'_subscription_failed_payments',
				)
			);
		}

		return $hidden_meta_keys;
	}

	/**
	 * Check whether an order needs payment even if the order total is $0 (because it has a recurring total and
	 * automatic payments are not switched off)
	 *
	 * @param bool $needs_payment The existing flag for whether the cart needs payment or not.
	 * @param WC_Order $order A WooCommerce WC_Order object.
	 * @return bool
	 */
	public static function order_needs_payment( $needs_payment, $order, $valid_order_statuses ) {

		if ( self::order_contains_subscription( $order ) && in_array( $order->status, $valid_order_statuses ) && 0 == $order->get_total() && false === $needs_payment && self::get_recurring_total( $order ) > 0 && 'yes' !== get_option( WC_Subscriptions_Admin::$option_prefix . '_turn_off_automatic_payments', 'no' ) ) {
			$needs_payment = true;
		}

		return $needs_payment;
	}

	/**
	 * This function make sure the recurring payment method is set correctly on an order when a customer places an order
	 * with one payment method (like PayPal), and then returns and completes payment using a different payment method.
	 *
	 * @since 1.4
	 */
	public static function set_recurring_payment_method( $order_id ) {

		if ( self::order_contains_subscription( $order_id ) ) {
			update_post_meta( $order_id, '_recurring_payment_method', get_post_meta( $order_id, '_payment_method', true ) );
			update_post_meta( $order_id, '_recurring_payment_method_title', get_post_meta( $order_id, '_payment_method_title', true ) );
		}

	}

	/**
	 * Adds the subscription information to our order emails if enabled.
	 *
	 * @since 1.5
	 */
	public static function add_sub_info_email( $order, $is_admin_email ) {

		if ( 'yes' == get_option( WC_Subscriptions_Admin::$option_prefix . '_add_sub_info_email', 'yes' ) && self::order_contains_subscription( $order ) && ! $is_admin_email ) {
			global $woocommerce;

			$template_base  = plugin_dir_path( WC_Subscriptions::$plugin_file ) . 'templates/';
			$template = ( 'plain' == $woocommerce->mailer()->emails['WC_Email_Customer_Completed_Order']->email_type ) ? 'emails/plain/subscription-info.php' : 'emails/subscription-info.php';

			woocommerce_get_template(
				$template,
				array(
					'order' => $order
				),
				'',
				$template_base
			);
		}
	}

	/**
	 * Return an array of shipping costs within this order.
	 *
	 * @return array
	 */
	public static function get_recurring_shipping_methods( $order ) {
		return $order->get_items( 'recurring_shipping' );
	}

	/**
	 * Wrapper around @see WC_Order::get_order_currency() for versions of WooCommerce prior to 2.1.
	 *
	 * @since version 1.4.9
	 */
	public static function get_order_currency( $order ) {

		if ( method_exists( $order, 'get_order_currency' ) ) {
			$order_currency = $order->get_order_currency();
		} else {
			$order_currency = get_woocommerce_currency();
		}

		return $order_currency;
	}

	/**
	 * Add admin dropdown for order types to Woocommerce -> Orders screen
	 *
	 * @since version 1.5
	 */
	public static function restrict_manage_subscriptions() {
		global $woocommerce, $typenow, $wp_query;

		if ( 'shop_order' != $typenow ) {
			return;
		}?>
		<select name='shop_order_subtype' id='dropdown_shop_order_subtype'>
			<option value=""><?php _e( 'Show all types', 'woocommerce-subscriptions' ); ?></option>
			<?php
				$terms = array('Original', 'Renewal');
		
				foreach ( $terms as $term ) {
					echo '<option value="' . esc_attr( $term ) . '"';
		
					if ( isset( $_GET['shop_order_subtype'] ) && $_GET['shop_order_subtype'] ) {
						selected( $term, $_GET['shop_order_subtype'] );
					}
		
					echo '>' . esc_html__( $term, 'woocommerce' ) . '</option>';
				}
			?>
			</select>
		<?php

		wc_enqueue_js( "
			jQuery('select#dropdown_shop_order_subtype, select[name=m]').css('width', '150px').chosen();	
		" );

	}


	/**
	 * Add request filter for order types to Woocommerce -> Orders screen
	 * Search on post parent to determine order type.
	 *
	 * @since version 1.5
	 */
	public static function orders_by_type_query( $vars ) {
		global $typenow, $wp_query;
		
		if ( $typenow == 'shop_order' && isset( $_GET['shop_order_subtype'] ) ) {
			if ( $_GET['shop_order_subtype'] == 'Original' ) {
				$vars['post_parent'] = '0';
			} elseif ( $_GET['shop_order_subtype'] == 'Renewal' ) {
				$vars['post_parent__not_in'] = array('0');
			}
		}
		
		return $vars;
	}

	/**
	 * Add request filter for order types to Woocommerce -> Orders screen
	 *
	 * @since version 1.5.4
	 */
	public static function order_shipping_method( $shipping_method, $order ) {

		foreach ( self::get_recurring_shipping_methods( $order ) as $recurring_shipping_method ) {
			if ( false === strpos( $shipping_method, $recurring_shipping_method['name'] ) ) {
				if ( ! empty( $shipping_method ) ) {
					$shipping_method .= ', ';
				}
				$shipping_method .= $recurring_shipping_method['name'];
			}
		}

		return $shipping_method;
	}

	/**
	 * Allow subscription order items to be edited in WC 2.2. until Subscriptions 2.0 introduces
	 * its own WC_Subscription object.
	 *
	 * @since 1.5.10
	 */
	public static function is_order_editable( $is_editable, $order ) {

		if ( false === $is_editable && self::order_contains_subscription( $order ) ) {

			$chosen_gateway = WC_Subscriptions_Payment_Gateways::get_payment_gateway( get_post_meta( $order->id, '_recurring_payment_method', true ) );
			$manual_renewal = self::requires_manual_renewal( $order->id );

			// Only allow editing of subscriptions using a recurring payment method that supports changes
			if ( 'true' == $manual_renewal || false === $chosen_gateway || ( $chosen_gateway->supports( 'subscription_amount_changes' ) && $chosen_gateway->supports( 'subscription_date_changes' ) ) ) {
				$backtrace = debug_backtrace();
				$file_name = 'html-order-item.php';

				// It's important we only allow the order item to be edited so that it must be saved with the order rather than via ajax (where there are no hooks to attach too)
				if ( $file_name == substr( $backtrace[3]['file'], -strlen( $file_name ) ) ) {
					$is_editable = true;
				}
			}
		}

		return $is_editable;
	}

	/* Deprecated Functions */

	/**
	 * Returned the recurring amount for a subscription in an order.
	 *
	 * @deprecated 1.2
	 * @since 1.0
	 */
	public static function get_price_per_period( $order, $product_id = '' ) {
		_deprecated_function( __CLASS__ . '::' . __FUNCTION__, '1.2', __CLASS__ . '::get_recurring_total( $order, $product_id )' );
		return self::get_recurring_total( $order, $product_id );
	}

	/**
	 * Creates a new order for renewing a subscription product based on the details of a previous order.
	 *
	 * @param WC_Order|int $order The WC_Order object or ID of the order for which the a new order should be created.
	 * @param string $product_id The ID of the subscription product in the order which needs to be added to the new order.
	 * @param string $new_order_role A flag to indicate whether the new order should become the master order for the subscription. Accepts either 'parent' or 'child'. Defaults to 'parent' - replace the existing order.
	 * @deprecated 1.2
	 * @since 1.0
	 */
	public static function generate_renewal_order( $original_order, $product_id, $new_order_role = 'parent' ) {
		_deprecated_function( __CLASS__ . '::' . __FUNCTION__, '1.2', 'WC_Subscriptions_Renewal_Order::generate_renewal_order( $original_order, $product_id, array( "new_order_role" => $new_order_role ) )' );
		return WC_Subscriptions_Renewal_Order::generate_renewal_order( $original_order, $product_id, array( 'new_order_role' => $new_order_role ) );
	}

	/**
	 * Hooks to the renewal order created action to determine if the order should be emailed to the customer.
	 *
	 * @param WC_Order|int $order The WC_Order object or ID of a WC_Order order.
	 * @deprecated 1.2
	 * @since 1.0
	 */
	public static function maybe_send_customer_renewal_order_email( $order ) {
		_deprecated_function( __CLASS__ . '::' . __FUNCTION__, '1.2', 'WC_Subscriptions_Renewal_Order::maybe_send_customer_renewal_order_email( $order )' );
		WC_Subscriptions_Renewal_Order::maybe_send_customer_renewal_order_email( $order );
	}

	/**
	 * Processing Order
	 *
	 * @param WC_Order|int $order The WC_Order object or ID of a WC_Order order.
	 * @deprecated 1.2
	 * @since 1.0
	 */
	public static function send_customer_renewal_order_email( $order ) {
		_deprecated_function( __CLASS__ . '::' . __FUNCTION__, '1.2', 'WC_Subscriptions_Renewal_Order::send_customer_renewal_order_email( $order )' );
		WC_Subscriptions_Renewal_Order::send_customer_renewal_order_email( $order );
	}

	/**
	 * Check if a given order is a subscription renewal order
	 *
	 * @param WC_Order|int $order The WC_Order object or ID of a WC_Order order.
	 * @deprecated 1.2
	 * @since 1.0
	 */
	public static function is_renewal( $order ) {
		_deprecated_function( __CLASS__ . '::' . __FUNCTION__, '1.2', 'WC_Subscriptions_Renewal_Order::is_renewal( $order )' );
		return WC_Subscriptions_Renewal_Order::is_renewal( $order );
	}

	/**
	 * Once payment is completed on an order, record the payment against the subscription automatically so that
	 * payment gateway extension developers don't have to do this.
	 *
	 * @param int $order_id The id of the order to record payment against
	 * @deprecated 1.2
	 * @since 1.1.2
	 */
	public static function record_order_payment( $order_id ) {
		_deprecated_function( __CLASS__ . '::' . __FUNCTION__, '1.2', __CLASS__ . '::maybe_record_order_payment( $order_id )' );
		return self::maybe_record_order_payment( $order_id );
	}

	/**
	 * Checks an order item to see if it is a subscription. The item needs to exist and have been a subscription
	 * product at the time of purchase for the function to return true.
	 *
	 * @param mixed $order A WC_Order object or the ID of the order which the subscription was purchased in.
	 * @param int $product_id The ID of a WC_Product object purchased in the order.
	 * @return bool True if the order contains a subscription, otherwise false.
	 * @deprecated 1.2.4
	 */
	public static function is_item_a_subscription( $order, $product_id ) {
		_deprecated_function( __CLASS__ . '::' . __FUNCTION__, '1.2.4', __CLASS__ . '::is_item_subscription( $order, $product_id )' );
		return self::is_item_subscription( $order, $product_id );
	}

	/**
	 * Deprecated due to change of order item ID/API in WC 2.0.
	 *
	 * @param WC_Order|int $order The WC_Order object or ID of the order for which the meta should be sought.
	 * @param int $item_id The product/post ID of a subscription. Option - if no product id is provided, the first item's meta will be returned
	 * @since 1.2
	 * @deprecated 1.2.5
	 */
	public static function get_item( $order, $product_id = '' ) {
		_deprecated_function( __CLASS__ . '::' . __FUNCTION__, '1.2.5', __CLASS__ . '::get_item_by_product_id( $order, $product_id )' );
		return self::get_item_by_product_id( $order, $product_id );
	}

	/**
	 * Deprecated due to different totals calculation method.
	 *
	 * Determined the proportion of the order total that a recurring amount accounts for and
	 * returns that proportion.
	 *
	 * If there is only one subscription in the order and no sign up fee for the subscription,
	 * this function will return 1 (i.e. 100%).
	 *
	 * Shipping only applies to recurring amounts so is deducted from both the order total and
	 * recurring amount so it does not distort the proportion.
	 *
	 * @param WC_Order|int $order A WC_Order object or ID of a WC_Order order.
	 * @return float The proportion of the order total which the recurring amount accounts for
	 * @since 1.2
	 * @deprecated 1.4
	 */
	public static function get_recurring_total_proportion( $order, $product_id = '' ) {
		_deprecated_function( __CLASS__ . '::' . __FUNCTION__, '1.4' );

		$order_shipping_total          = self::get_recurring_shipping_total( $order ) + self::get_recurring_shipping_tax_total( $order );
		$order_total_sans_shipping     = $order->get_total() - $order_shipping_total;
		$recurring_total_sans_shipping = self::get_recurring_total( $order, $product_id ) - $order_shipping_total;

		return $recurring_total_sans_shipping / $order_total_sans_shipping;
	}
}

WC_Subscriptions_Order::init();
