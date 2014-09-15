<?php
/**
 * Subscriptions Checkout
 * 
 * Extends the WooCommerce checkout class to add subscription meta on checkout.
 *
 * @package		WooCommerce Subscriptions
 * @subpackage	WC_Subscriptions_Checkout
 * @category	Class
 * @author		Brent Shepherd
 */
class WC_Subscriptions_Checkout {

	private static $signup_option_changed = false;

	private static $guest_checkout_option_changed = false;

	/**
	 * Bootstraps the class and hooks required actions & filters.
	 *
	 * @since 1.0
	 */
	public static function init(){

		// Add the order item meta
		add_action( 'woocommerce_add_order_item_meta', __CLASS__ . '::add_order_item_meta', 10, 2 );

		// Add the recurring totals meta
		add_action( 'woocommerce_checkout_update_order_meta', __CLASS__ . '::add_order_meta', 10, 2 );

		// Make sure users can register on checkout (before any other hooks before checkout)
		add_action( 'woocommerce_before_checkout_form', __CLASS__ . '::make_checkout_registration_possible', -1 );

		// Display account fields as required
		add_action( 'woocommerce_checkout_fields', __CLASS__ . '::make_checkout_account_fields_required', 10 );

		// Restore the settings after switching them for the checkout form
		add_action( 'woocommerce_after_checkout_form', __CLASS__ . '::restore_checkout_registration_settings', 100 );

		// Make sure guest checkout is not enabled in option param passed to WC JS
		add_filter( 'woocommerce_params', __CLASS__ . '::filter_woocommerce_script_paramaters', 10, 1 );
		add_filter( 'wc_checkout_params', __CLASS__ . '::filter_woocommerce_script_paramaters', 10, 1 );

		// Check if we want to create the order ourself (a renewal order)
		add_filter( 'woocommerce_create_order', __CLASS__ . '::filter_woocommerce_create_order', 10, 2 );

		// Force registration during checkout process
		add_action( 'woocommerce_before_checkout_process', __CLASS__ . '::force_registration_during_checkout', 10 );

		add_filter( 'woocommerce_my_account_my_orders_actions', __CLASS__ . '::filter_woocommerce_my_account_my_orders_actions', 10, 2 );
	}

	/**
	 * Customise which actions are shown against a subscriptions order on the My Account page.
	 *
	 * @since 1.3
	 */
	public static function filter_woocommerce_my_account_my_orders_actions( $actions, $order ) {

		if ( WC_Subscriptions_Order::order_contains_subscription( $order ) || WC_Subscriptions_Renewal_Order::is_renewal( $order ) ) {
			unset( $actions['cancel'] );

			if ( is_numeric( get_post_meta( $order->id, '_failed_order_replaced_by', true ) ) ) {
				unset( $actions['pay'] );
			}

			$original_order = WC_Subscriptions_Renewal_Order::get_parent_order( $order );

			// If the original order hasn't been deleted
			if ( $original_order->id > 0 ) {

				$order_items = WC_Subscriptions_Order::get_recurring_items( $original_order );
				$first_order_item = reset( $order_items );
				$product_id = WC_Subscriptions_Order::get_items_product_id( $first_order_item );

				$subscription_key = WC_Subscriptions_Manager::get_subscription_key( $original_order->id, $product_id );
				$subscription = WC_Subscriptions_Manager::get_subscription( $subscription_key );

				if ( empty( $subscription ) || ! in_array( $subscription['status'], array( 'on-hold', 'pending' ) ) ) {
					unset( $actions['pay'] );
				}

			}
		}

		return $actions;
	}

	/**
	 * When creating an order at checkout, if the order is for renewing a subscription from a failed
	 * payment, hijack the order creation to make a renewal order - not a vanilla WooCommerce order.
	 *
	 * @since 1.3
	 */
	public static function filter_woocommerce_create_order( $order_id, $checkout_object ) {
		global $woocommerce;

		$cart_item = WC_Subscriptions_Cart::cart_contains_subscription_renewal();

		if ( $cart_item && 'child' == $cart_item['subscription_renewal']['role'] ) {

			$product_id        = $cart_item['product_id'];
			$failed_order_id   = $cart_item['subscription_renewal']['failed_order'];
			$original_order_id = $cart_item['subscription_renewal']['original_order'];
			$role              = $cart_item['subscription_renewal']['role'];

			$renewal_order_args = array(
				'new_order_role'   => $role,
				'checkout_renewal' => true,
				'failed_order_id'  => $failed_order_id
			);

			$renewal_order_id = WC_Subscriptions_Renewal_Order::generate_renewal_order( $original_order_id, $product_id, $renewal_order_args );

			$original_order = new WC_Order( $original_order_id );

			$customer_id = $original_order->customer_user;

			// Save posted billing address fields to both renewal and original order
			if ( $checkout_object->checkout_fields['billing'] ) {
				foreach ( $checkout_object->checkout_fields['billing'] as $key => $field ) {

					update_post_meta( $renewal_order_id, '_' . $key, $checkout_object->posted[ $key ] );
					update_post_meta( $original_order->id, '_' . $key, $checkout_object->posted[ $key ] );

					// User
					if ( $customer_id && !empty( $checkout_object->posted[ $key ] ) ) {
						update_user_meta( $customer_id, $key, $checkout_object->posted[ $key ] );

						// Special fields
						switch ( $key ) {
							case "billing_email" :
								if ( !email_exists( $checkout_object->posted[ $key ] ) ) {
									wp_update_user( array( 'ID' => $customer_id, 'user_email' => $checkout_object->posted[ $key ] ) );
								}
								break;
							case "billing_first_name" :
								wp_update_user( array( 'ID' => $customer_id, 'first_name' => $checkout_object->posted[ $key ] ) );
								break;
							case "billing_last_name" :
								wp_update_user( array( 'ID' => $customer_id, 'last_name' => $checkout_object->posted[ $key ] ) );
								break;
						}
					}
				}
			}

			// Save posted shipping address fields to both renewal and original order
			if ( $checkout_object->checkout_fields['shipping'] && ( $woocommerce->cart->needs_shipping() || get_option( 'woocommerce_require_shipping_address' ) == 'yes' ) ) {
				foreach ( $checkout_object->checkout_fields['shipping'] as $key => $field ) {
					$postvalue = false;

					if ( ( isset( $checkout_object->posted['shiptobilling'] ) && $checkout_object->posted['shiptobilling'] ) || ( isset( $checkout_object->posted['ship_to_different_address'] ) && $checkout_object->posted['ship_to_different_address'] ) ) {
						if ( isset( $checkout_object->posted[str_replace( 'shipping_', 'billing_', $key )] ) ) {
							$postvalue = $checkout_object->posted[str_replace( 'shipping_', 'billing_', $key )];
							update_post_meta( $renewal_order_id, '_' . $key, $postvalue );
							update_post_meta( $original_order->id, '_' . $key, $postvalue );
						}
					} elseif ( isset( $checkout_object->posted[ $key ] ) ) {
						$postvalue = $checkout_object->posted[ $key ];
						update_post_meta( $renewal_order_id, '_' . $key, $postvalue );
						update_post_meta( $original_order->id, '_' . $key, $postvalue );
					}

					// User
					if ( $postvalue && $customer_id ) {
						update_user_meta( $customer_id, $key, $postvalue );
					}
				}
			}

			if ( $checkout_object->posted['payment_method'] ) {

				$available_gateways = $woocommerce->payment_gateways->get_available_payment_gateways();

				if ( isset( $available_gateways[ $checkout_object->posted['payment_method'] ] ) ) {
					$payment_method = $available_gateways[ $checkout_object->posted['payment_method'] ];
					$payment_method->validate_fields();
					update_post_meta( $renewal_order_id, '_payment_method', 	  $payment_method->id );
					update_post_meta( $renewal_order_id, '_payment_method_title', $payment_method->get_title() );
				}
			}

			// Set the shipping method for WC < 2.1
			if ( $checkout_object->posted['shipping_method'] && method_exists( $woocommerce->shipping, 'get_available_shipping_methods' ) ) {

				$available_shipping_methods = $woocommerce->shipping->get_available_shipping_methods();

				if ( isset( $available_shipping_methods[ $checkout_object->posted['shipping_method'] ] ) ) {
					$shipping_method = $available_shipping_methods[ $checkout_object->posted['shipping_method'] ];
					update_post_meta( $renewal_order_id, '_shipping_method', 	  $shipping_method->id );
					update_post_meta( $renewal_order_id, '_shipping_method_title', $shipping_method->label );
				}
			}

			if ( isset( $failed_order_id ) ) {
				$failed_order = new WC_Order( $failed_order_id );
				if ( $failed_order->status == 'failed' ) {
					update_post_meta( $failed_order_id, '_failed_order_replaced_by', $renewal_order_id );
				}
			}

			// Store fees, any new fees on this order should be applied now
			foreach ( $woocommerce->cart->get_fees() as $fee ) {
				$item_id = woocommerce_add_order_item( $renewal_order_id, array(
					'order_item_name' => $fee->name,
					'order_item_type' => 'fee'
				) );

				if ( $fee->taxable ) {
					woocommerce_add_order_item_meta( $item_id, '_tax_class', $fee->tax_class );
				} else {
					woocommerce_add_order_item_meta( $item_id, '_tax_class', '0' );
				}

				woocommerce_add_order_item_meta( $item_id, '_line_total', woocommerce_format_decimal( $fee->amount ) );
				woocommerce_add_order_item_meta( $item_id, '_line_tax', woocommerce_format_decimal( $fee->tax ) );
			}

			// Store tax rows
			foreach ( array_keys( $woocommerce->cart->taxes + $woocommerce->cart->shipping_taxes ) as $key ) {

				$item_id = woocommerce_add_order_item( $renewal_order_id, array(
					'order_item_name' => $woocommerce->cart->tax->get_rate_code( $key ),
					'order_item_type' => 'tax'
				) );

				// Add line item meta
				if ( $item_id ) {
					woocommerce_add_order_item_meta( $item_id, 'rate_id', $key );
					woocommerce_add_order_item_meta( $item_id, 'label', $woocommerce->cart->tax->get_rate_label( $key ) );
					woocommerce_add_order_item_meta( $item_id, 'compound', absint( $woocommerce->cart->tax->is_compound( $key ) ? 1 : 0 ) );
					woocommerce_add_order_item_meta( $item_id, 'tax_amount', woocommerce_format_decimal( isset( $woocommerce->cart->taxes[ $key ] ) ? $woocommerce->cart->taxes[ $key ] : 0 ) );
					woocommerce_add_order_item_meta( $item_id, 'shipping_tax_amount', woocommerce_format_decimal( isset( $woocommerce->cart->shipping_taxes[ $key ] ) ? $woocommerce->cart->shipping_taxes[ $key ] : 0 ) );
				}
			}

			// Store shipping for all packages on this order (as this can differ between each order), WC 2.1
			if ( method_exists( $woocommerce->shipping, 'get_packages' ) ) {
				$packages = $woocommerce->shipping->get_packages();

				foreach ( $packages as $i => $package ) {
					if ( isset( $package['rates'][ $checkout_object->shipping_methods[ $i ] ] ) ) {

						$method = $package['rates'][ $checkout_object->shipping_methods[ $i ] ];

						$item_id = woocommerce_add_order_item( $renewal_order_id, array(
							'order_item_name' => $method->label,
							'order_item_type' => 'shipping'
						) );

						if ( $item_id ) {
							woocommerce_add_order_item_meta( $item_id, 'method_id', $method->id );
							woocommerce_add_order_item_meta( $item_id, 'cost', woocommerce_format_decimal( $method->cost ) );
							do_action( 'woocommerce_add_shipping_order_item', $renewal_order_id, $item_id, $i );
						}
					}
				}
			}

			update_post_meta( $renewal_order_id, '_order_shipping', 	WC_Subscriptions::format_total( $woocommerce->cart->shipping_total ) );
			update_post_meta( $renewal_order_id, '_order_discount', 	WC_Subscriptions::format_total( $woocommerce->cart->get_order_discount_total() ) );
			update_post_meta( $renewal_order_id, '_cart_discount', 		WC_Subscriptions::format_total( $woocommerce->cart->get_cart_discount_total() ) );
			update_post_meta( $renewal_order_id, '_order_tax', 			WC_Subscriptions::format_total( $woocommerce->cart->tax_total ) );
			update_post_meta( $renewal_order_id, '_order_shipping_tax', WC_Subscriptions::format_total( $woocommerce->cart->shipping_tax_total ) );
			update_post_meta( $renewal_order_id, '_order_total', 		WC_Subscriptions::format_total( $woocommerce->cart->total ) );

			update_post_meta( $renewal_order_id, '_order_currency', 		get_woocommerce_currency() );
			update_post_meta( $renewal_order_id, '_prices_include_tax', 	get_option( 'woocommerce_prices_include_tax' ) );
			update_post_meta( $renewal_order_id, '_customer_ip_address',	isset( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ? $_SERVER['HTTP_X_FORWARDED_FOR'] : $_SERVER['REMOTE_ADDR'] );
			update_post_meta( $renewal_order_id, '_customer_user_agent', 	isset( $_SERVER['HTTP_USER_AGENT'] ) ? $_SERVER['HTTP_USER_AGENT'] : '' );

			update_post_meta( $renewal_order_id, '_checkout_renewal', 'yes' );

			// Return the new order's ID to prevent WC creating an order
			$order_id = $renewal_order_id;
		}

		return $order_id;
	}

	/**
	 * When a new order is inserted, add subscriptions related order meta.
	 *
	 * @since 1.0
	 */
	public static function add_order_meta( $order_id, $posted ) {
		global $woocommerce;

		if ( ! WC_Subscriptions_Cart::cart_contains_subscription_renewal( 'child' ) && WC_Subscriptions_Order::order_contains_subscription( $order_id ) ) { // This works because the 'woocommerce_add_order_item_meta' runs before the 'woocommerce_checkout_update_order_meta' hook

			// Set the recurring totals so totals display correctly on order page
			update_post_meta( $order_id, '_order_recurring_discount_cart', WC_Subscriptions_Cart::get_recurring_discount_cart() );
			update_post_meta( $order_id, '_order_recurring_discount_total', WC_Subscriptions_Cart::get_recurring_discount_total() );
			update_post_meta( $order_id, '_order_recurring_shipping_tax_total', WC_Subscriptions_Cart::get_recurring_shipping_tax_total() );
			update_post_meta( $order_id, '_order_recurring_shipping_total', WC_Subscriptions_Cart::get_recurring_shipping_total() );
			update_post_meta( $order_id, '_order_recurring_tax_total', WC_Subscriptions_Cart::get_recurring_total_tax() );
			update_post_meta( $order_id, '_order_recurring_total', WC_Subscriptions_Cart::get_recurring_total() );

			// Set the recurring payment method - it starts out the same as the original by may change later
			update_post_meta( $order_id, '_recurring_payment_method', get_post_meta( $order_id, '_payment_method', true ) );
			update_post_meta( $order_id, '_recurring_payment_method_title', get_post_meta( $order_id, '_payment_method_title', true ) );

			$order      = new WC_Order( $order_id );
			$order_fees = $order->get_fees(); // the fee order items have already been set, we just need to to add the recurring total meta
			$cart_fees  = $woocommerce->cart->get_fees();

			foreach ( $order->get_fees() as $item_id => $order_fee ) {

				// Find the matching fee in the cart
				foreach ( $cart_fees as $fee_index => $cart_fee ) {

					if ( sanitize_title( $order_fee['name'] ) == $cart_fee->id ) {
						woocommerce_add_order_item_meta( $item_id, '_recurring_line_total', wc_format_decimal( $cart_fee->recurring_amount ) );
						woocommerce_add_order_item_meta( $item_id, '_recurring_line_tax', wc_format_decimal( $cart_fee->recurring_tax ) );
						unset( $cart_fees[ $fee_index ] );
						break;
					}

				}

			}

			// Get recurring taxes into same format as _order_taxes
			$order_recurring_taxes = array();

			foreach ( WC_Subscriptions_Cart::get_recurring_taxes() as $tax_key => $tax_amount ) {

				$item_id = woocommerce_add_order_item( $order_id, array(
					'order_item_name' => $woocommerce->cart->tax->get_rate_code( $tax_key ),
					'order_item_type' => 'recurring_tax'
				) );

				if ( $item_id ) {
					wc_add_order_item_meta( $item_id, 'rate_id', $tax_key );
					wc_add_order_item_meta( $item_id, 'label', WC()->cart->tax->get_rate_label( $tax_key ) );
					wc_add_order_item_meta( $item_id, 'compound', absint( WC()->cart->tax->is_compound( $tax_key ) ? 1 : 0 ) );
					wc_add_order_item_meta( $item_id, 'tax_amount', wc_format_decimal( isset( WC()->cart->recurring_taxes[ $tax_key ] ) ? WC()->cart->recurring_taxes[ $tax_key ] : 0 ) );
					wc_add_order_item_meta( $item_id, 'shipping_tax_amount', wc_format_decimal( isset( WC()->cart->recurring_shipping_taxes[ $tax_key ] ) ? WC()->cart->recurring_shipping_taxes[ $tax_key ] : 0 ) );
				}
			}

			$payment_gateways = $woocommerce->payment_gateways->payment_gateways();

			if ( 'yes' == get_option( WC_Subscriptions_Admin::$option_prefix . '_turn_off_automatic_payments', 'no' ) ) {
				update_post_meta( $order_id, '_wcs_requires_manual_renewal', 'true' );
			} elseif ( isset( $payment_gateways[ $posted['payment_method'] ] ) && ! $payment_gateways[ $posted['payment_method'] ]->supports( 'subscriptions' ) ) {
				update_post_meta( $order_id, '_wcs_requires_manual_renewal', 'true' );
			}

			$cart_item = WC_Subscriptions_Cart::cart_contains_subscription_renewal();

			if ( isset( $cart_item['subscription_renewal'] ) && 'parent' == $cart_item['subscription_renewal']['role'] ) {
				update_post_meta( $order_id, '_original_order', $cart_item['subscription_renewal']['original_order'] );
			}

			// WC 2.1+
			if ( ! WC_Subscriptions::is_woocommerce_pre_2_1() ) {

				// Recurring coupons
				if ( $applied_coupons = $woocommerce->cart->get_coupons() ) {
					foreach ( $applied_coupons as $code => $coupon ) {

						if ( ! isset( $woocommerce->cart->recurring_coupon_discount_amounts[ $code ] ) ) {
							continue;
						}

						$item_id = woocommerce_add_order_item( $order_id, array(
							'order_item_name' => $code,
							'order_item_type' => 'recurring_coupon'
						) );

						// Add line item meta
						if ( $item_id ) {
							woocommerce_add_order_item_meta( $item_id, 'discount_amount', isset( $woocommerce->cart->recurring_coupon_discount_amounts[ $code ] ) ? $woocommerce->cart->recurring_coupon_discount_amounts[ $code ] : 0 );
						}
					}
				}

				// Add recurring shipping order items
				if ( WC_Subscriptions_Cart::cart_contains_subscriptions_needing_shipping() ) {

					$packages = $woocommerce->shipping->get_packages();

					$checkout = $woocommerce->checkout();

					foreach ( $packages as $i => $package ) {
						if ( isset( $package['rates'][ $checkout->shipping_methods[ $i ] ] ) ) {

							$method = $package['rates'][ $checkout->shipping_methods[ $i ] ];

							$item_id = woocommerce_add_order_item( $order_id, array(
								'order_item_name' => $method->label,
								'order_item_type' => 'recurring_shipping'
							) );

							if ( $item_id ) {
								woocommerce_add_order_item_meta( $item_id, 'method_id', $method->id );
								woocommerce_add_order_item_meta( $item_id, 'cost', WC_Subscriptions::format_total( $method->cost ) );
								do_action( 'woocommerce_subscriptions_add_recurring_shipping_order_item', $order_id, $item_id, $i );
							}
						}
					}
				}

				// Remove shipping on original order if it was added but is not required
				if ( ! WC_Subscriptions_Cart::charge_shipping_up_front() ) {
					foreach( $order->get_shipping_methods() as $order_item_id => $shipping_method ) {
						wc_delete_order_item( $order_item_id );
					}
				}

			} else {
				update_post_meta( $order_id, '_recurring_shipping_method', get_post_meta( $order_id, '_shipping_method', true ), true );
				update_post_meta( $order_id, '_recurring_shipping_method_title', get_post_meta( $order_id, '_shipping_method_title', true ), true );
			}
		}
	}

	/**
	 * Add each subscription product's details to an order so that the state of the subscription persists even when a product is changed
	 *
	 * @since 1.2.5
	 */
	public static function add_order_item_meta( $item_id, $values ) {
		global $woocommerce;

		if ( ! WC_Subscriptions_Cart::cart_contains_subscription_renewal( 'child' ) && WC_Subscriptions_Product::is_subscription( $values['product_id'] ) ) {

			$cart_item = $values['data'];

			$product_id = empty( $values['variation_id'] ) ? $values['product_id'] : $values['variation_id'];

			// Add subscription details so order state persists even when a product is changed
			$period       = isset( $cart_item->subscription_period ) ? $cart_item->subscription_period : WC_Subscriptions_Product::get_period( $product_id );
			$interval     = isset( $cart_item->subscription_period_interval ) ? $cart_item->subscription_period_interval : WC_Subscriptions_Product::get_interval( $product_id );
			$length       = isset( $cart_item->subscription_length ) ? $cart_item->subscription_length : WC_Subscriptions_Product::get_length( $product_id );
			$trial_length = isset( $cart_item->subscription_trial_length ) ? $cart_item->subscription_trial_length : WC_Subscriptions_Product::get_trial_length( $product_id );
			$trial_period = isset( $cart_item->subscription_trial_period ) ? $cart_item->subscription_trial_period : WC_Subscriptions_Product::get_trial_period( $product_id );
			$sign_up_fee  = isset( $cart_item->subscription_sign_up_fee ) ? $cart_item->subscription_sign_up_fee : WC_Subscriptions_Product::get_sign_up_fee( $product_id );

			woocommerce_add_order_item_meta( $item_id, '_subscription_period', $period );
			woocommerce_add_order_item_meta( $item_id, '_subscription_interval', $interval );
			woocommerce_add_order_item_meta( $item_id, '_subscription_length', $length );
			woocommerce_add_order_item_meta( $item_id, '_subscription_trial_length', $trial_length );
			woocommerce_add_order_item_meta( $item_id, '_subscription_trial_period', $trial_period );
			woocommerce_add_order_item_meta( $item_id, '_subscription_recurring_amount', $woocommerce->cart->base_recurring_prices[ $product_id ] ); // WC_Subscriptions_Product::get_price() would return a price without filters applied
			woocommerce_add_order_item_meta( $item_id, '_subscription_sign_up_fee', $sign_up_fee );

			// Calculated recurring amounts for the item
			woocommerce_add_order_item_meta( $item_id, '_recurring_line_total', $woocommerce->cart->recurring_cart_contents[ $values['product_id'] ]['recurring_line_total'] );
			woocommerce_add_order_item_meta( $item_id, '_recurring_line_tax', $woocommerce->cart->recurring_cart_contents[ $values['product_id'] ]['recurring_line_tax'] );
			woocommerce_add_order_item_meta( $item_id, '_recurring_line_subtotal', $woocommerce->cart->recurring_cart_contents[ $values['product_id'] ]['recurring_line_subtotal'] );
			woocommerce_add_order_item_meta( $item_id, '_recurring_line_subtotal_tax', $woocommerce->cart->recurring_cart_contents[ $values['product_id'] ]['recurring_line_subtotal_tax'] );
		}
	}

	/**
	 * If shopping cart contains subscriptions, make sure a user can register on the checkout page
	 *
	 * @since 1.0
	 */
	public static function make_checkout_registration_possible( $checkout = '' ) {

		if ( WC_Subscriptions_Cart::cart_contains_subscription() && ! is_user_logged_in() ) {

			// Make sure users can sign up
			if ( false === $checkout->enable_signup ) {
				$checkout->enable_signup = true;
				self::$signup_option_changed = true;
			}

			// Make sure users are required to register an account
			if ( true === $checkout->enable_guest_checkout ) {
				$checkout->enable_guest_checkout = false;
				self::$guest_checkout_option_changed = true;

				if ( ! is_user_logged_in() ) {
					$checkout->must_create_account = true;
				}
			}

		}

	}

	/**
	 * Make sure account fields display the required "*" when they are required.
	 *
	 * @since 1.3.5
	 */
	public static function make_checkout_account_fields_required( $checkout_fields ) {

		if ( WC_Subscriptions_Cart::cart_contains_subscription() && ! is_user_logged_in() ) {

			$account_fields = array(
				'account_username',
				'account_password',
				'account_password-2',
			);

			foreach ( $account_fields as $account_field ) {
				if ( isset( $checkout_fields['account'][ $account_field ] ) ) {
					$checkout_fields['account'][ $account_field ]['required'] = true;
				}
			}

		}

		return $checkout_fields;
	}

	/**
	 * After displaying the checkout form, restore the store's original registration settings.
	 *
	 * @since 1.1
	 */
	public static function restore_checkout_registration_settings( $checkout = '' ) {

		if ( self::$signup_option_changed ) {
			$checkout->enable_signup = false;
		}

		if ( self::$guest_checkout_option_changed ) {
			$checkout->enable_guest_checkout = true;
			if ( ! is_user_logged_in() ) { // Also changed must_create_account
				$checkout->must_create_account = false;
			}
		}
	}

	/**
	 * Also make sure the guest checkout option value passed to the woocommerce.js forces registration.
	 * Otherwise the registration form is hidden by woocommerce.js.
	 *
	 * @since 1.1
	 */
	public static function filter_woocommerce_script_paramaters( $woocommerce_params ) {

		if ( WC_Subscriptions_Cart::cart_contains_subscription() && ! is_user_logged_in() && isset( $woocommerce_params['option_guest_checkout'] ) && $woocommerce_params['option_guest_checkout'] == 'yes' ) {
			$woocommerce_params['option_guest_checkout'] = 'no';
		}

		return $woocommerce_params;
	}

	/**
	 * During the checkout process, force registration when the cart contains a subscription.
	 *
	 * @since 1.1
	 */
	public static function force_registration_during_checkout( $woocommerce_params ) {

		if ( WC_Subscriptions_Cart::cart_contains_subscription() && ! is_user_logged_in() ) {
			$_POST['createaccount'] = 1;
		}

	}

}

WC_Subscriptions_Checkout::init();
