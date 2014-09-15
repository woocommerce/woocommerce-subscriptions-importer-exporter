<?php
/**
 * Subscriptions Payment Gateways
 * 
 * Hooks into the WooCommerce payment gateways class to add subscription specific functionality.
 *
 * @package		WooCommerce Subscriptions
 * @subpackage	WC_Subscriptions_Payment_Gateways
 * @category	Class
 * @author		Brent Shepherd
 * @since		1.0
 */
class WC_Subscriptions_Payment_Gateways {

	/**
	 * Bootstraps the class and hooks required actions & filters.
	 *
	 * @since 1.0
	 */
	public static function init(){

		add_filter( 'woocommerce_available_payment_gateways', __CLASS__ . '::get_available_payment_gateways' );

		add_filter( 'woocommerce_no_available_payment_methods_message', __CLASS__ . '::no_available_payment_methods_message' );

		// Create a custom hook for gateways that need to manually charge recurring payments
		add_action( 'scheduled_subscription_payment', __CLASS__ . '::gateway_scheduled_subscription_payment', 10, 2 );

		// Create a gateway specific hooks for subscription events
		add_action( 'activated_subscription', __CLASS__ . '::trigger_gateway_activated_subscription_hook', 10, 2 );
		add_action( 'reactivated_subscription', __CLASS__ . '::trigger_gateway_reactivated_subscription_hook', 10, 2 );
		add_action( 'subscription_put_on-hold', __CLASS__ . '::trigger_gateway_subscription_put_on_hold_hook', 10, 2 );
		add_action( 'cancelled_subscription', __CLASS__ . '::trigger_gateway_cancelled_subscription_hook', 10, 2 );
		add_action( 'subscription_expired', __CLASS__ . '::trigger_gateway_subscription_expired_hook', 10, 2 );
	}

	/**
	 * Returns a payment gateway object by gateway's ID, or false if it could not find the gateway.
	 *
	 * @since 1.2.4
	 */
	public static function get_payment_gateway( $gateway_id ) {
		global $woocommerce;

		$found_gateway = false;

		if ( $woocommerce->payment_gateways ) {
			foreach ( $woocommerce->payment_gateways->payment_gateways() as $gateway ) {
				if ( $gateway_id == $gateway->id ) {
					$found_gateway = $gateway;
				}
			}
		}

		return $found_gateway;
	}

	/**
	 * Only display the gateways which support subscriptions if manual payments are not allowed.
	 *
	 * @since 1.0
	 */
	public static function get_available_payment_gateways( $available_gateways ) {

		$accept_manual_payment = get_option( WC_Subscriptions_Admin::$option_prefix . '_accept_manual_renewals', 'no' );

		if ( 'no' == $accept_manual_payment && ( WC_Subscriptions_Cart::cart_contains_subscription() || ( isset( $_GET['order_id'] ) && WC_Subscriptions_Order::order_contains_subscription( $_GET['order_id'] ) ) ) ) {
			foreach ( $available_gateways as $gateway_id => $gateway ) {
				if ( ! method_exists( $gateway, 'supports' ) || $gateway->supports( 'subscriptions' ) !== true ) {
					unset( $available_gateways[ $gateway_id ] );
				}
			}
		}

		return $available_gateways;
	}

	/**
	 * Improve message displayed on checkout when a subscription is in the cart but not gateways support subscriptions.
	 *
	 * @since 1.5.2
	 */
	public static function no_available_payment_methods_message( $no_gateways_message ) {
		global $woocommerce;

		if ( WC_Subscriptions_Cart::cart_contains_subscription() && 'no' == get_option( WC_Subscriptions_Admin::$option_prefix . '_accept_manual_renewals', 'no' ) ) {
			$no_gateways_message = __( 'Sorry, it seems there are no available payment methods which support subscriptions. Please contact us if you require assistance or wish to make alternate arrangements.', 'woocommerce-subscriptions' );
		}

		return $no_gateways_message;
	}

	/**
	 * Fire a gateway specific hook for when a subscription payment is due.
	 *
	 * @since 1.0
	 */
	public static function gateway_scheduled_subscription_payment( $user_id, $subscription_key ) {

		$subscription = WC_Subscriptions_Manager::get_subscription( $subscription_key );

		$order = new WC_Order( $subscription['order_id'] );

		if ( ! WC_Subscriptions_Order::requires_manual_renewal( $order ) ) {

			$amount_to_charge = WC_Subscriptions_Order::get_recurring_total( $order );

			$outstanding_payments = WC_Subscriptions_Order::get_outstanding_balance( $order, $subscription['product_id'] );

			if ( 'yes' == get_option( WC_Subscriptions_Admin::$option_prefix . '_add_outstanding_balance', 'no' ) && $outstanding_payments > 0 ) {
				$amount_to_charge += $outstanding_payments;
			}

			if ( $amount_to_charge > 0 ) {
				$transaction_id = get_post_meta( $order->id, '_transaction_id', true );
				update_post_meta( $order->id, '_transaction_id_original', $transaction_id );
				delete_post_meta( $order->id, '_transaction_id', $transaction_id ); // just in case the gateway uses add_post_meta() not update_post_meta() - this will be set later based on `'_transaction_id_original'` regardless of whether the payment fails or succeeds
				do_action( 'scheduled_subscription_payment_' . $order->recurring_payment_method, $amount_to_charge, $order, $subscription['product_id'] );
			}
		}
	}

	/**
	 * Fire a gateway specific hook for when a subscription is activated.
	 *
	 * @since 1.0
	 */
	public static function trigger_gateway_activated_subscription_hook( $user_id, $subscription_key ) {

		$subscription = WC_Subscriptions_Manager::get_subscription( $subscription_key );

		$order = new WC_Order( $subscription['order_id'] );

		if ( ! WC_Subscriptions_Order::requires_manual_renewal( $order ) ) {
			do_action( 'activated_subscription_' . $order->recurring_payment_method, $order, $subscription['product_id'] );
		}
	}

	/**
	 * Fire a gateway specific hook for when a subscription is activated.
	 *
	 * @since 1.0
	 */
	public static function trigger_gateway_reactivated_subscription_hook( $user_id, $subscription_key ) {

		$subscription = WC_Subscriptions_Manager::get_subscription( $subscription_key );

		$order = new WC_Order( $subscription['order_id'] );

		if ( ! WC_Subscriptions_Order::requires_manual_renewal( $order ) ) {
			do_action( 'reactivated_subscription_' . $order->recurring_payment_method, $order, $subscription['product_id'] );
		}
	}

	/**
	 * Fire a gateway specific hook for when a subscription is on-hold.
	 *
	 * @since 1.2
	 */
	public static function trigger_gateway_subscription_put_on_hold_hook( $user_id, $subscription_key ) {

		$subscription = WC_Subscriptions_Manager::get_subscription( $subscription_key );

		$order = new WC_Order( $subscription['order_id'] );

		if ( ! WC_Subscriptions_Order::requires_manual_renewal( $order ) ) {
			do_action( 'subscription_put_on-hold_' . $order->recurring_payment_method, $order, $subscription['product_id'] );
			// Backward compatibility
			do_action( 'suspended_subscription_' . $order->recurring_payment_method, $order, $subscription['product_id'] );
		}
	}

	/**
	 * Fire a gateway specific when a subscription is cancelled.
	 *
	 * @since 1.0
	 */
	public static function trigger_gateway_cancelled_subscription_hook( $user_id, $subscription_key ) {

		$subscription = WC_Subscriptions_Manager::get_subscription( $subscription_key );

		$order = new WC_Order( $subscription['order_id'] );

		if ( ! WC_Subscriptions_Order::requires_manual_renewal( $order ) ) {
			do_action( 'cancelled_subscription_' . $order->recurring_payment_method, $order, $subscription['product_id'] );
		}
	}

	/**
	 * Fire a gateway specific hook when a subscription expires.
	 *
	 * @since 1.0
	 */
	public static function trigger_gateway_subscription_expired_hook( $user_id, $subscription_key ) {

		$subscription = WC_Subscriptions_Manager::get_subscription( $subscription_key );

		$order = new WC_Order( $subscription['order_id'] );

		if ( ! WC_Subscriptions_Order::requires_manual_renewal( $order ) ) {
			do_action( 'subscription_expired_' . $order->recurring_payment_method, $order, $subscription['product_id'] );
		}
	}

	/**
	 * Fired a gateway specific when a subscription was suspended. Suspended status was changed in 1.2 to match 
	 * WooCommerce with the "on-hold" status.
	 *
	 * @deprecated 1.2
	 * @since 1.0
	 */
	public static function trigger_gateway_suspended_subscription_hook( $user_id, $subscription_key ) {
		_deprecated_function( __CLASS__ . '::' . __FUNCTION__, '1.2', __CLASS__ . '::trigger_gateway_subscription_put_on_hold_hook( $subscription_key, $user_id )' );
		self::trigger_gateway_subscription_put_on_hold_hook( $subscription_key, $user_id );
	}
}

WC_Subscriptions_Payment_Gateways::init();
