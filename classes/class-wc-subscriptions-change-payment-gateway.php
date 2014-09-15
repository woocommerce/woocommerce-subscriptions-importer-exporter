<?php
/**
 * A class to make it possible to change the payment gateway used for an existing subscription.
 *
 * @package		WooCommerce Subscriptions
 * @subpackage	WC_Subscriptions_Change_Payment_Gateway
 * @category	Class
 * @author		Brent Shepherd
 * @since		1.4
 */
class WC_Subscriptions_Change_Payment_Gateway {

	public static $is_request_to_change_payment = false;

	private static $woocommerce_messages = array();

	private static $woocommerce_errors = array();

	private static $original_order_dates = array();

	/**
	 * Bootstraps the class and hooks required actions & filters.
	 *
	 * @since 1.4
	 */
	public static function init(){

		// Maybe allow for a recurring payment method to be changed
		add_action( 'plugins_loaded', __CLASS__ . '::set_change_payment_method_flag' );

		// Keep a record of any messages or errors that should be displayed
		add_action( 'before_woocommerce_pay', __CLASS__ . '::store_pay_shortcode_mesages', 100 );

		// Hijack the default pay shortcode
		add_action( 'after_woocommerce_pay', __CLASS__ . '::maybe_replace_pay_shortcode', 100 );

		// Maybe allow for a recurring payment method to be changed
		add_filter( 'woocommerce_my_account_my_subscriptions_actions', __CLASS__ . '::change_payment_method_button', 10, 2 );

		// Maybe allow for a recurring payment method to be changed
		add_action( 'init', __CLASS__ . '::change_payment_method_via_pay_shortcode', 20 );

		// Filter the available payment gateways to only show those which support acting as the new payment method
		add_filter( 'woocommerce_available_payment_gateways', __CLASS__ . '::get_available_payment_gateways' );

		// If we're changing the payment method, we want to make sure a number of totals return $0 (to prevent payments being processed now)
		add_filter( 'woocommerce_subscriptions_total_initial_payment', __CLASS__ . '::maybe_zero_total', 11, 2 );
		add_filter( 'woocommerce_subscriptions_sign_up_fee', __CLASS__ . '::maybe_zero_total', 11, 2 );

		// Redirect to My Account page after changing payment method
		add_filter( 'woocommerce_get_return_url', __CLASS__ . '::get_return_url', 11 );

		// Update the recurring payment method when a customer has completed the payment for a renewal payment which previously failed
		add_action( 'woocommerce_subscriptions_paid_for_failed_renewal_order', __CLASS__ . '::change_failing_payment_method', 10, 2 );

		// Add a 'new-payment-method' handler to the WC_Subscriptions_Manager::can_subscription_be_changed_to() function
		add_filter( 'woocommerce_can_subscription_be_changed_to', __CLASS__ . '::can_subscription_be_changed_to', 10, 3 );

		// Restore the original order's dates after a sucessful payment method change
		add_action( 'woocommerce_payment_complete_order_status', __CLASS__ . '::store_original_order_dates', 10, 2 );
		add_action( 'woocommerce_payment_complete', __CLASS__ . '::restore_original_order_dates', 10 );
	}

	/**
	 * Set a flag to indicate that the current request is for changing payment. Better than requiring other extensions
	 * to check the $_GET global as it allows for the flag to be overridden.
	 *
	 * @since 1.4
	 */
	public static function set_change_payment_method_flag() {
		if ( isset( $_GET['change_payment_method'] ) ) {
			self::$is_request_to_change_payment = true;
		}
	}

	/**
	 * Store any messages or errors added by other plugins, particularly important for those occasions when the new payment
	 * method caused and error or failure.
	 *
	 * @since 1.4
	 */
	public static function store_pay_shortcode_mesages() {
		global $woocommerce;

		if ( function_exists( 'wc_notice_count' ) ) { // WC 2.1+

			if ( wc_notice_count( 'notice' ) > 0 ) {
				self::$woocommerce_messages  = wc_get_notices( 'success' );
				self::$woocommerce_messages += wc_get_notices( 'notice' );
			}

			if ( wc_notice_count( 'error' ) > 0 ) {
				self::$woocommerce_errors = wc_get_notices( 'error' );
			}

		} else {

			if ( $woocommerce->message_count() > 0 ) {
				self::$woocommerce_messages = $woocommerce->get_messages();
			}

			if ( $woocommerce->error_count() > 0 ) {
				self::$woocommerce_errors = $woocommerce->get_errors();
			}

		}
	}

	/**
	 * If requesting a payment method change, replace the woocommerce_pay_shortcode() with a change payment form.
	 *
	 * @since 1.4
	 */
	public static function maybe_replace_pay_shortcode() {
		global $woocommerce;

		if ( ! self::$is_request_to_change_payment ) {
			return;
		}

		ob_clean();

		do_action( 'before_woocommerce_pay' );

		echo '<div class="woocommerce">';

		if ( ! empty( self::$woocommerce_errors ) ) {
			foreach ( self::$woocommerce_errors as $error ) {
				WC_Subscriptions::add_notice( $error, 'error' );
			}
		}

		if ( ! empty( self::$woocommerce_messages ) ) {
			foreach ( self::$woocommerce_messages as $message ) {
				WC_Subscriptions::add_notice( $message, 'success' );
			}
		}

		$subscription_key = $_GET['change_payment_method'];
		$subscription = WC_Subscriptions_Manager::get_subscription( $subscription_key );

		if ( wp_verify_nonce( $_GET['_wpnonce'], __FILE__ ) === false ) {

			WC_Subscriptions::add_notice( __( 'There was an error with your request. Please try again.', 'woocommerce-subscriptions' ), 'error' );
			WC_Subscriptions::print_notices();

		} elseif ( ! WC_Subscriptions_Manager::user_owns_subscription( $subscription_key ) ) {

			WC_Subscriptions::add_notice( __( 'That doesn\'t appear to be one of your subscriptions.', 'woocommerce-subscriptions' ), 'error' );
			WC_Subscriptions::print_notices();

		} elseif ( empty( $subscription ) ) {

			WC_Subscriptions::add_notice( __( 'Invalid subscription.', 'woocommerce-subscriptions' ), 'error' );
			WC_Subscriptions::print_notices();

		} elseif ( ! WC_Subscriptions_Manager::can_subscription_be_changed_to( 'new-payment-method', $subscription_key, get_current_user_id() ) ) {

			WC_Subscriptions::add_notice( __( 'The payment method can not be changed for that subscription.', 'woocommerce-subscriptions' ), 'error' );
			WC_Subscriptions::print_notices();

		} else {

			$order      = new WC_Order( $subscription['order_id'] );
			$order_id   = absint( $_GET[ 'order_id' ] );
			$order_key  = ( isset( $_GET[ 'key' ] ) ? $_GET[ 'key' ] : $_GET[ 'order' ] );
			$product_id = $subscription['product_id'];

			$next_payment_timestamp = WC_Subscriptions_Order::get_next_payment_timestamp( $order, $product_id );

			if ( ! empty( $next_payment_timestamp ) ) {
				$next_payment_string = sprintf( __( ' Next payment is due %s.', 'woocommerce-subscriptions' ), date_i18n( woocommerce_date_format(), $next_payment_timestamp ) );
			} else {
				$next_payment_string = '';
			}

			WC_Subscriptions::add_notice( sprintf( __( 'Choose a new payment method.%s', 'woocommerce-subscriptions' ), $next_payment_string ), 'notice' );
			WC_Subscriptions::print_notices();

			if ( $order->order_key == $order_key ) {

				// Set customer location to order location
				if ( $order->billing_country ) {
					$woocommerce->customer->set_country( $order->billing_country );
				}
				if ( $order->billing_state ) {
					$woocommerce->customer->set_state( $order->billing_state );
				}
				if ( $order->billing_postcode ) {
					$woocommerce->customer->set_postcode( $order->billing_postcode );
				}

				// Show form
				WC_Subscriptions_Order::$recurring_only_price_strings = true;

				woocommerce_get_template( 'checkout/form-change-payment-method.php', array( 'order' => $order, 'subscription_key' => $subscription_key ), '', plugin_dir_path( WC_Subscriptions::$plugin_file ) . 'templates/' );

				WC_Subscriptions_Order::$recurring_only_price_strings = false;

			} else {

				WC_Subscriptions::add_notice( __( 'Invalid order.', 'woocommerce-subscriptions' ), 'error' );
				WC_Subscriptions::print_notices();

			}

		}
	}

	/**
	 * Add a "Change Payment Method" button to the "My Subscriptions" table.
	 *
	 * @param array $all_actions The $subscription_key => $actions array with all actions that will be displayed for a subscription on the "My Subscriptions" table
	 * @param array $subscriptions All of a given users subscriptions that will be displayed on the "My Subscriptions" table
	 * @since 1.4
	 */
	public static function change_payment_method_button( $all_actions, $subscriptions ) {

		foreach ( $all_actions as $subscription_key => $actions ) {

			$order = new WC_Order( $subscriptions[ $subscription_key ]['order_id'] );

			if ( WC_Subscriptions_Manager::can_subscription_be_changed_to( 'new-payment-method', $subscription_key, get_current_user_id() ) ) {

				if ( ! WC_Subscriptions::is_woocommerce_pre_2_1() ) { // WC 2.1+
					$url = add_query_arg( array( 'change_payment_method' => $subscription_key, 'order_id' => $order->id ), $order->get_checkout_payment_url() );
					$url = wp_nonce_url( $url, __FILE__ );
				} else {
					$url = add_query_arg( array( 'change_payment_method' => $subscription_key, 'order_id' => $order->id, 'order' => $order->order_key ), get_permalink( woocommerce_get_page_id( 'pay' ) ) );
					$url = wp_nonce_url( $url, __FILE__ );
				}

				$all_actions[ $subscription_key ] = array( 'change_payment_method' => array(
					'url'  => $url,
					'name' => __( 'Change Payment Method', 'woocommerce-subscriptions' ),
					)
				) + $all_actions[ $subscription_key ];

			}

		}

		return $all_actions;
	}

	/**
	 * Process the change payment form.
	 *
	 * Based on the @see woocommerce_pay_action() function.
	 *
	 * @access public
	 * @return void
	 * @since 1.4
	 */
	public static function change_payment_method_via_pay_shortcode() {
		global $woocommerce;

		if ( isset( $_POST['woocommerce_change_payment'] ) && wp_verify_nonce( $_POST['_wpnonce'], 'woocommerce-change_payment' ) ) {

			$subscription_key = $_POST['woocommerce_change_payment'];

			// Pay for existing order
			$order_key  = ( isset( $_GET[ 'key' ] ) ? $_GET[ 'key' ] : $_GET[ 'order' ] );
			$order_id  = absint( $_GET['order_id'] );
			$order     = new WC_Order( $order_id );

			do_action( 'woocommerce_subscriptions_change_payment_method_via_pay_shortcode', $subscription_key, $order );

			ob_start();

			if ( $order->id == $order_id && $order->order_key == $order_key ) {

				// Set customer location to order location
				if ( $order->billing_country ) {
					$woocommerce->customer->set_country( $order->billing_country );
				}
				if ( $order->billing_state ) {
					$woocommerce->customer->set_state( $order->billing_state );
				}
				if ( $order->billing_postcode ) {
					$woocommerce->customer->set_postcode( $order->billing_postcode );
				}
				if ( $order->billing_city ) {
					$woocommerce->customer->set_city( $order->billing_city );
				}

				// Update payment method
				$new_payment_method = woocommerce_clean( $_POST['payment_method'] );

				self::update_recurring_payment_method( $subscription_key, $order, $new_payment_method );

				$available_gateways = $woocommerce->payment_gateways->get_available_payment_gateways();

				// Validate
				$available_gateways[ $new_payment_method ]->validate_fields();

				// Process payment for the new method (with a $0 order total)
				if ( function_exists( 'wc_notice_count' ) ) { // WC 2.1
					if ( wc_notice_count( 'error' ) == 0 ){

						$result = $available_gateways[ $new_payment_method ]->process_payment( $order_id );

						// Redirect to success/confirmation/payment page
						if ( $result['result'] == 'success' ) {
							WC_Subscriptions::add_notice( __( 'Payment method updated.', 'woocommerce-subscriptions' ), 'success' );
							wp_redirect( $result['redirect'] );
							exit;
						}
					}
				} else {
					if ( $woocommerce->error_count() == 0 ) {

						$result = $available_gateways[ $new_payment_method ]->process_payment( $order_id );

						// Redirect to success/confirmation/payment page
						if ( $result['result'] == 'success' ) {
							WC_Subscriptions::add_notice( __( 'Payment method updated.', 'woocommerce-subscriptions' ), 'success' );
							wp_redirect( $result['redirect'] );
							exit;
						}

					}
				}
			}

		}
	}

	/**
	 * Update the recurring payment method on a subscription order.
	 *
	 * @param array $available_gateways The payment gateways which are currently being allowed.
	 * @since 1.4
	 */
	private static function update_recurring_payment_method( $subscription_key, $order, $new_payment_method ) {
		global $woocommerce;

		$old_payment_method       = $order->recurring_payment_method;
		$old_payment_method_title = $order->recurring_payment_method_title;
		$available_gateways       = $woocommerce->payment_gateways->get_available_payment_gateways(); // Also inits all payment gateways to make sure that hooks are attached correctly

		do_action( 'woocommerce_subscriptions_pre_update_recurring_payment_method', $order, $subscription_key, $new_payment_method, $old_payment_method );

		// Make sure the subscription is cancelled with the current gateway
		WC_Subscriptions_Payment_Gateways::trigger_gateway_cancelled_subscription_hook( $order->customer_user, $subscription_key );

		// Update meta
		update_post_meta( $order->id, '_old_recurring_payment_method', $old_payment_method );
		update_post_meta( $order->id, '_recurring_payment_method', $new_payment_method );

		if ( isset( $available_gateways[ $new_payment_method ] ) ) {
			$new_payment_method_title = $available_gateways[ $new_payment_method ]->get_title();
		} else {
			$new_payment_method_title = '';
		}

		update_post_meta( $order->id, '_old_recurring_payment_method_title', $old_payment_method_title );
		update_post_meta( $order->id, '_recurring_payment_method_title', $new_payment_method_title );

		if ( empty( $old_payment_method_title )  ) {
			$old_payment_method_title = $old_payment_method;
		}

		if ( empty( $new_payment_method_title )  ) {
			$new_payment_method_title = $new_payment_method;
		}

		// Log change on order
		$order->add_order_note( sprintf( __( 'Recurring payment method changed from "%s" to "%s" by the subscriber from their account page.', 'woocommerce-subscriptions' ), $old_payment_method_title, $new_payment_method_title ) );

		do_action( 'woocommerce_subscriptions_updated_recurring_payment_method', $order, $subscription_key, $new_payment_method, $old_payment_method );
		do_action( 'woocommerce_subscriptions_updated_recurring_payment_method_to_' . $new_payment_method, $order, $subscription_key, $old_payment_method );
		do_action( 'woocommerce_subscriptions_updated_recurring_payment_method_from_' . $old_payment_method, $order, $subscription_key, $new_payment_method );
	}

	/**
	 * Only display gateways which support changing payment method when paying for a failed renewal order or
	 * when requesting to change the payment method.
	 *
	 * @param array $available_gateways The payment gateways which are currently being allowed.
	 * @since 1.4
	 */
	public static function get_available_payment_gateways( $available_gateways ) {

		if ( isset( $_GET['change_payment_method'] ) || WC_Subscriptions_Cart::cart_contains_failed_renewal_order_payment() ) {
			foreach ( $available_gateways as $gateway_id => $gateway ) {
				if ( ! method_exists( $gateway, 'supports' ) || true !== $gateway->supports( 'subscription_payment_method_change' ) ) {
					unset( $available_gateways[ $gateway_id ] );
				}
			}
		}

		return $available_gateways;
	}

	/**
	 * Make sure certain totals are set to 0 when the request is to change the payment method without charging anything.
	 *
	 * @since 1.4
	 */
	public static function maybe_zero_total( $total, $order ) {

		if ( isset( $_POST['woocommerce_change_payment'] ) ) {

			$order_key = ( isset( $_GET[ 'key' ] ) ? $_GET[ 'key' ] : $_GET[ 'order' ] ); // WC 2.1 compatibility

			if ( $order->order_key == $order_key && $order->id == absint( $_GET['order_id'] ) ) {
				$total = 0;
			}
		}

		return $total;
	}

	/**
	 * Redirect back to the "My Account" page instead of the "Thank You" page after changing the payment method.
	 *
	 * @since 1.4
	 */
	public static function get_return_url( $return_url ) {

		if ( isset( $_POST['woocommerce_change_payment'] ) ) {
			$return_url = get_permalink( woocommerce_get_page_id( 'myaccount' ) );
		}

		return $return_url;
	}

	/**
	 * Update the recurring payment method for a subscription after a customer has paid for a failed renewal order
	 * (which usually failed because of an issue with the existing payment, like an expired card or token).
	 *
	 * Also trigger a hook for payment gateways to update any meta on the original order for a subscription.
	 *
	 * @param WC_Order $renewal_order The order which recorded the successful payment (to make up for the failed automatic payment).
	 * @param WC_Order $original_order The original order in which the subscription was purchased.
	 * @since 1.4
	 */
	public static function change_failing_payment_method( $renewal_order, $original_order ) {

		if ( ! WC_Subscriptions_Order::requires_manual_renewal( $original_order->id ) ) {

			$subscription_key = WC_Subscriptions_Manager::get_subscription_key( $original_order->id );

			$new_payment_method = woocommerce_clean( $_POST['payment_method'] );

			self::update_recurring_payment_method( $subscription_key, $original_order, $new_payment_method );

			do_action( 'woocommerce_subscriptions_changed_failing_payment_method', $original_order, $renewal_order, $subscription_key );
			do_action( 'woocommerce_subscriptions_changed_failing_payment_method_' . $new_payment_method, $original_order, $renewal_order, $subscription_key );
		}
	}

	/**
	 * Add a 'new-payment-method' handler to the @see WC_Subscriptions_Manager::can_subscription_be_changed_to() function
	 * to determine whether the recurring payment method on a subscription can be changed.
	 *
	 * For the recurring payment method to be changeable, the subscription must be active, have future (automatic) payments
	 * and use a payment gateway which allows the subscription to be cancelled.
	 *
	 * @param bool $subscription_can_be_changed Flag of whether the subscription can be changed to
	 * @param string $new_status_or_meta The status or meta data you want to change th subscription to. Can be 'active', 'on-hold', 'cancelled', 'expired', 'trash', 'deleted', 'failed', 'new-payment-date' or some other value attached to the 'woocommerce_can_subscription_be_changed_to' filter.
	 * @param object $args Set of values used in @see WC_Subscriptions_Manager::can_subscription_be_changed_to() for determining if a subscription can be changes, include:
	 *			'subscription_key'           string A subscription key of the form created by @see WC_Subscriptions_Manager::get_subscription_key()
	 *			'subscription'               array Subscription of the form returned by @see WC_Subscriptions_Manager::get_subscription()
	 *			'user_id'                    int The ID of the subscriber.
	 *			'order'                      WC_Order The order which recorded the successful payment (to make up for the failed automatic payment).
	 *			'payment_gateway'            WC_Payment_Gateway The subscription's recurring payment gateway
	 *			'order_uses_manual_payments' bool A boolean flag indicating whether the subscription requires manual renewal payment.
	 * @since 1.4
	 */
	public static function can_subscription_be_changed_to( $subscription_can_be_changed, $new_status_or_meta, $args ) {
		global $woocommerce;

		if ( 'new-payment-method' === $new_status_or_meta ) {

			$next_payment_timestamp = WC_Subscriptions_Manager::get_next_payment_date( $args->subscription_key, '', 'timestamp' );

			// Check if any payment gateway supports recurring payment method changes
			$one_gateway_supports_changes = false;

			foreach ( $woocommerce->payment_gateways->get_available_payment_gateways() as $gateway ) {
				if ( $gateway->supports( 'subscription_payment_method_change' ) ) {
					$one_gateway_supports_changes = true;
					break;
				}
			}

			if ( $one_gateway_supports_changes && 0 !== $next_payment_timestamp && false === $args->order_uses_manual_payments && $args->payment_gateway->supports( 'subscription_cancellation' ) && 'active' == $args->subscription['status'] ) {
				$subscription_can_be_changed = true;
			} else {
				$subscription_can_be_changed = false;
			}

		}

		return $subscription_can_be_changed;
	}

	/**
	 * Keep a record of an order's dates if we're marking it as completed during a request to change the payment method.
	 *
	 * @since 1.4
	 */
	public static function store_original_order_dates( $new_order_status, $order_id ) {

		if ( self::$is_request_to_change_payment ) {

			$order = new WC_Order( $order_id );
			$post  = get_post( $order_id );

			self::$original_order_dates = array(
				'_paid_date'      => WC_Subscriptions_Order::get_meta( $order, 'paid_date' ),
				'_completed_date' => WC_Subscriptions_Order::get_meta( $order, 'completed_date' ),
				'post_date'       => $order->order_date,
				'post_date_gmt'   => $post->post_date_gmt,
			);
		}

		return $new_order_status;
	}

	/**
	 * Restore an order's dates if we marked it as completed during a request to change the payment method.
	 *
	 * @since 1.4
	 */
	public static function restore_original_order_dates( $order_id ) {

		if ( self::$is_request_to_change_payment ) {
			$order = new WC_Order( $order_id );

			update_post_meta( $order_id, '_paid_date', self::$original_order_dates['_paid_date'], true );
			update_post_meta( $order_id, '_completed_date', self::$original_order_dates['_completed_date'], true );

			$this_order = array(
				'ID'            => $order_id,
				'post_date'     => self::$original_order_dates['post_date'],
				'post_date_gmt' => self::$original_order_dates['post_date_gmt'],
			);
			wp_update_post( $this_order );
		}
	}
}
WC_Subscriptions_Change_Payment_Gateway::init();
