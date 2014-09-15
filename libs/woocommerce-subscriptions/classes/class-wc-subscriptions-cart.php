<?php
/**
 * Subscriptions Cart Class
 * 
 * Mirrors a few functions in the WC_Cart class to work for subscriptions. 
 * 
 * @package		WooCommerce Subscriptions
 * @subpackage	WC_Subscriptions_Cart
 * @category	Class
 * @author		Brent Shepherd
 * @since		1.0
 */
class WC_Subscriptions_Cart {

	/**
	 * A flag to control how to modify the calculation of totals by WC_Cart::calculate_totals()
	 *
	 * Can take any one of these values:
	 * - 'none' used to calculate the initial total.
	 * - 'combined_total' used to calculate the total of sign-up fee + recurring amount.
	 * - 'sign_up_fee_total' used to calculate the initial amount when there is a free trial period and a sign-up fee. Different to 'combined_total' because shipping is not charged on a sign-up fee.
	 * - 'recurring_total' used to calculate the totals for the recurring amount when the recurring amount differs to to 'combined_total' because of coupons or sign-up fees.
	 * - 'free_trial_total' used to calculate the initial total when there is a free trial period and no sign-up fee. Different to 'combined_total' because shipping is not charged up-front when there is a free trial.
	 *
	 * @since 1.2
	 */
	private static $calculation_type = 'none';

	/**
	 * Bootstraps the class and hooks required actions & filters.
	 *
	 * @since 1.0
	 */
	public static function init() {

		// Make sure the price per period totals persist in the cart
		add_action( 'init', __CLASS__ . '::get_cart_from_session', 6 );
		add_action( 'woocommerce_cart_updated', __CLASS__ . '::set_session' );
		add_action( 'woocommerce_cart_emptied', __CLASS__ . '::reset' );

		// Make sure WC calculates total on sign up fee + price per period, and keep a record of the price per period
		add_action( 'woocommerce_before_calculate_totals', __CLASS__ . '::add_calculation_price_filter', 10 );
		add_action( 'woocommerce_calculate_totals', __CLASS__ . '::remove_calculation_price_filter', 10 );

		add_filter( 'woocommerce_calculated_total', __CLASS__ . '::calculate_subscription_totals', 1000, 2 );
		add_filter( 'woocommerce_calculated_total', __CLASS__ . '::set_calculated_total', 1001, 1 );

		// Override Formatted Discount Totals
		add_filter( 'woocommerce_cart_discounts_before_tax', __CLASS__ . '::get_formatted_discounts_before_tax', 11, 2 );
		add_filter( 'woocommerce_cart_discounts_after_tax', __CLASS__ . '::get_formatted_discounts_after_tax', 11, 2 );

		// Display recurring discounts with WooCommerce 2.1+
		add_filter( 'woocommerce_coupon_discount_amount_html', __CLASS__ . '::cart_coupon_discount_amount_html' , 10, 2 );

		// Display correct subscription string for additional fees 2.1+
		add_filter( 'woocommerce_cart_totals_fee_html', __CLASS__ . '::cart_totals_fee_html' , 10, 2 );

		// Override Formatted Cart Tax
		add_filter( 'woocommerce_cart_tax_totals', __CLASS__ . '::get_recurring_tax_totals', 11, 2 );
		add_filter( 'woocommerce_cart_totals_taxes_total_html', __CLASS__ . '::get_taxes_total_html', 11, 4 );

		// Include billing period on shipping total
		add_filter( 'woocommerce_cart_shipping_method_full_label', __CLASS__ . '::get_cart_shipping_method_full_label', 11, 2 );

		// Display Formatted Totals
		add_filter( 'woocommerce_cart_product_subtotal', __CLASS__ . '::get_formatted_product_subtotal', 11, 4 );
		add_filter( 'woocommerce_cart_contents_total', __CLASS__ . '::get_formatted_cart_total', 11 );
		add_filter( 'woocommerce_cart_subtotal', __CLASS__ . '::get_formatted_cart_subtotal', 11, 3 );

		add_filter( 'woocommerce_cart_total_ex_tax', __CLASS__ . '::get_formatted_total_ex_tax', 11 );
		add_filter( 'woocommerce_cart_total', __CLASS__ . '::get_formatted_total', 11 );

		// Renewal order via cart/checkout related
		add_filter( 'woocommerce_get_cart_item_from_session', __CLASS__ . '::get_cart_item_from_session' , 10, 3 );
		add_action( 'woocommerce_before_calculate_totals', __CLASS__ . '::before_calculate_totals', 10 );
		add_filter( 'woocommerce_get_discounted_price', __CLASS__ . '::get_discounted_price_for_renewal', 10, 3 );

		// Sometimes, even if the order total is $0, the cart still needs payment
		add_filter( 'woocommerce_cart_needs_payment', __CLASS__ . '::cart_needs_payment' , 10, 2 );

		// Make sure cart product prices correctly include/exclude taxes
		add_filter( 'woocommerce_cart_product_price', __CLASS__ . '::cart_product_price' , 10, 2 );
	}

	/**
	 * Attaches the "set_subscription_prices_for_calculation" filter to the WC Product's woocommerce_get_price hook.
	 *
	 * This function is hooked to "woocommerce_before_calculate_totals" so that WC will calculate a subscription
	 * product's total based on the total of it's price per period and sign up fee (if any).
	 *
	 * @since 1.2
	 */
	public static function add_calculation_price_filter() {

		// Only hook when cart contains a subscription
		if ( ! self::cart_contains_subscription() ) {
			return;
		}

		// Make sure all totals are calculated to prevent incorrect totals being displayed before visitng the cart/checkout page (for example, in the cart widget)
		if ( ! is_checkout() && ! is_cart() && ! defined( 'WOOCOMMERCE_CHECKOUT' ) && ! defined( 'WOOCOMMERCE_CART' ) ) {
			define( 'WOOCOMMERCE_CART', true );
		}

		// Set which price should be used for calculation
		add_filter( 'woocommerce_get_price', __CLASS__ . '::set_subscription_prices_for_calculation', 100, 2 );
	}

	/**
	 * Removes the "set_subscription_prices_for_calculation" filter from the WC Product's woocommerce_get_price hook once
	 * calculations are complete.
	 *
	 * @since 1.2
	 */
	public static function remove_calculation_price_filter() {
		remove_filter( 'woocommerce_get_price', __CLASS__ . '::set_subscription_prices_for_calculation', 100, 2 );
	}

	/**
	 * If we are running a custom calculation, we need to set the price returned by a product
	 * to be the appropriate value. This may include just the sign-up fee, a combination of the
	 * sign-up fee and recurring amount or just the recurring amount (default).
	 *
	 * If there are subscriptions in the cart and the product is not a subscription, then
	 * set the recurring total to 0.
	 *
	 * @since 1.2
	 */
	public static function set_subscription_prices_for_calculation( $price, $product ) {
		global $woocommerce;

		if ( WC_Subscriptions_Product::is_subscription( $product ) ) {

			$product_id = ( $product->is_type( array( 'subscription_variation' ) ) ) ? $product->variation_id : $product->id;

			$woocommerce->cart->base_recurring_prices[ $product_id ] = $price;

			$sign_up_fee = WC_Subscriptions_Product::get_sign_up_fee( $product );

			$woocommerce->cart->base_sign_up_fees[ $product_id ] = $sign_up_fee;

			if ( 'combined_total' == self::$calculation_type ) {

				if ( $sign_up_fee > 0 ) {
					if ( WC_Subscriptions_Product::get_trial_length( $product ) > 0 ) {
						$price = $sign_up_fee;
					} else {
						$price += $sign_up_fee;
					}
				}

			} elseif ( 'sign_up_fee_total' == self::$calculation_type ) {

				$price = $sign_up_fee;

			} elseif ( 'free_trial_total' == self::$calculation_type ) {

				$price = 0;

			}  // else $price = recurring amount already as WC_Product->get_price() returns subscription price

			$price = apply_filters( 'woocommerce_subscriptions_cart_get_price', $price, $product );

		// Make sure the recurring amount for any non-subscription products in the cart with a subscription is $0
		} elseif ( self::cart_contains_subscription() && 'recurring_total' == self::$calculation_type ) {

			$price = 0;

		}

		return $price;
	}

	/**
	 * Checks the structure of the subscription price (i.e. whether it includes sign-up fees and/or free trial period)
	 * and calculates the appropriate totals by using the @see self::$calculation_type flag and cloning the cart to
	 * run @see WC_Cart::calculate_totals()
	 *
	 * @since 1.3.5
	 */
	public static function calculate_subscription_totals( $total, $cart ) {
		global $woocommerce;

		if ( ! self::cart_contains_subscription() && ! self::cart_contains_subscription_renewal( 'parent' ) ) { // cart doesn't contain subscription
			return $total;
		} elseif ( 'none' != self::$calculation_type ) { // We're in the middle of a recalculation, let it run
			return $total;
		}

		$cart_sign_up_fee = self::get_cart_subscription_sign_up_fee();
		$cart_has_trial   = self::cart_contains_free_trial();

		// save the original cart values/totals, as we'll use this when there is no sign-up fee
		if ( $total < 0 ) {
			$total = 0;
		}
		$woocommerce->cart->total = $total;
		$original_cart = clone $woocommerce->cart;

		// calculate the recurring fee totals in case there are coupons applied
		self::$calculation_type = 'recurring_total';
		self::maybe_recalculate_shipping();

		// remove any non-subscription items from the cart to make sure shipping totals etc. are calculated correctly
		foreach ( $woocommerce->cart->get_cart() as $cart_item_key => $cart_item ) {
			if ( ! WC_Subscriptions_Product::is_subscription( $cart_item['product_id'] ) ) {
				unset( $woocommerce->cart->cart_contents[ $cart_item_key ] );
			}
		}

		$woocommerce->cart->fees = array();
		$woocommerce->cart->fee_total = 0;
		$woocommerce->cart->calculate_totals();
		self::maybe_recalculate_shipping();

		$recurring_cart = clone $woocommerce->cart;

		// When we calculated totals above on the recurring cart, it also set the totals on WC()->shipping, so we need to restore those
		$woocommerce->cart = $original_cart;
		$woocommerce->cart->calculate_shipping();

		// We also need to restore the contents on the cart object used in WC_Cart::calculate_totals(), as PHP only allows modifying an objects properties when passed by reference
		$cart->cart_contents        = $original_cart->cart_contents;
		$cart->cart_contents_count  = $original_cart->cart_contents_count;
		$cart->cart_contents_weight = $original_cart->cart_contents_weight;
		$cart->cart_contents_total  = $original_cart->cart_contents_total;
		$cart->cart_contents_tax    = $original_cart->cart_contents_tax;

		// if there is a sign-up fee and a free trial, we need to calculate the totals with the sign-up fee only (to account for shipping deductions etc.)
		if ( $cart_sign_up_fee > 0 && $cart_has_trial ) {
			self::$calculation_type = 'sign_up_fee_total';
			self::maybe_recalculate_shipping();
			$woocommerce->cart->fees = array();
			$woocommerce->cart->fee_total = 0;
			$woocommerce->cart->calculate_totals();
			$sign_up_cart = clone $woocommerce->cart;
		}

		// if there is no sign-up fee and a free trial, we need to calculate the totals with $0 for the first billing period
		if ( 0 == $cart_sign_up_fee && $cart_has_trial ) {
			self::$calculation_type = 'free_trial_total';
			self::maybe_recalculate_shipping();
			$woocommerce->cart->fees = array();
			$woocommerce->cart->fee_total = 0;
			$woocommerce->cart->calculate_totals();
			$free_trial_cart = clone $woocommerce->cart;

			// If we're not purchasing any products with the subscription, make sure the free trial is truly free (i.e. no fees or shipping cost)
			if ( 1 == $free_trial_cart->cart_contents_count ) {
				foreach ( $free_trial_cart->get_fees() as $fee_index => $fee ) {
					$free_trial_cart->fees[ $fee_index ]->amount = 0;
					$free_trial_cart->fees[ $fee_index ]->tax    = 0;
				}
				$free_trial_cart->fee_total = 0;
				$free_trial_cart->total = 0;
			}
		}

		// if there is a sign-up fee and NO free trial, we need to calculate totals for combination sign-up fee & price
		if ( $cart_sign_up_fee > 0 && ! $cart_has_trial ) {
			self::$calculation_type = 'combined_total';
			self::maybe_recalculate_shipping();
			$woocommerce->cart->fees = array();
			$woocommerce->cart->fee_total = 0;
			$woocommerce->cart->calculate_totals();
			$initial_cart = clone $woocommerce->cart;
		}

		// Now choose the cart with the appropriate total

		// if there is a sign-up fee and a free trial, the cart used to calculate sign-up totals holds the correct values
		if ( $cart_sign_up_fee > 0 && $cart_has_trial ) {
			$woocommerce->cart = $sign_up_cart;
		}

		// if there is NO sign-up fee and a free trial, the cart used to calculate free trial totals holds the correct values
		if ( 0 == $cart_sign_up_fee && $cart_has_trial ) {
			$woocommerce->cart = $free_trial_cart;
		}

		// if there is a sign-up fee and NO free trial, the cart used to calculate combined initial totals holds the correct values
		if ( $cart_sign_up_fee > 0 && ! $cart_has_trial ) {
			$woocommerce->cart = $initial_cart;
		}

		// if there is NO sign-up fee and NO free trial, the original calculations hold
		if ( 0 == $cart_sign_up_fee && ! $cart_has_trial ) {
			$woocommerce->cart = $original_cart;
		}

		// And set the recurring totals for the main cart from the $recurring_cart
		foreach ( $recurring_cart->get_cart() as $cart_item_key => $values ) {
			if ( WC_Subscriptions_Product::is_subscription( $values['product_id'] ) ) {
				$woocommerce->cart->recurring_cart_contents[ $values['product_id'] ]['recurring_line_total']        = $values['line_total'];
				$woocommerce->cart->recurring_cart_contents[ $values['product_id'] ]['recurring_line_tax']          = $values['line_tax'];
				$woocommerce->cart->recurring_cart_contents[ $values['product_id'] ]['recurring_line_subtotal']     = $values['line_subtotal'];
				$woocommerce->cart->recurring_cart_contents[ $values['product_id'] ]['recurring_line_subtotal_tax'] = $values['line_subtotal_tax'];
			}
		}

		if ( ! empty( $recurring_cart->coupon_discount_amounts ) ) {
			foreach ( $recurring_cart->coupon_discount_amounts as $coupon_code => $discount_amount ) {
				$woocommerce->cart->recurring_coupon_discount_amounts[ $coupon_code ] = $discount_amount;
			}
		}

		$woocommerce->cart->recurring_cart_contents_total = $recurring_cart->cart_contents_total;
		$woocommerce->cart->recurring_discount_cart       = $recurring_cart->discount_cart;
		$woocommerce->cart->recurring_discount_total      = $recurring_cart->discount_total;
		$woocommerce->cart->recurring_subtotal            = $recurring_cart->subtotal;
		$woocommerce->cart->recurring_subtotal_ex_tax     = $recurring_cart->subtotal_ex_tax;

		$woocommerce->cart->recurring_shipping_taxes     = $recurring_cart->shipping_taxes;
		$woocommerce->cart->recurring_shipping_tax_total = $recurring_cart->shipping_tax_total;
		$woocommerce->cart->recurring_shipping_total     = $recurring_cart->shipping_total;

		$woocommerce->cart->recurring_taxes     = $recurring_cart->taxes; // Does not include shipping taxes
		$woocommerce->cart->recurring_tax_total = $recurring_cart->tax_total;

		// Add recurring amount component of fees
		$recurring_fees = $recurring_cart->get_fees();

		foreach ( $woocommerce->cart->get_fees() as $fee_index => $fee ) {

			$woocommerce->cart->fees[ $fee_index ]->recurring_amount = 0;
			$woocommerce->cart->fees[ $fee_index ]->recurring_tax    = 0;

			foreach ( $recurring_fees as $index => $recurring_fee ) {
				if ( $fee->id == $recurring_fee->id ) {
					$woocommerce->cart->fees[ $fee_index ]->recurring_amount = $recurring_fee->amount;
					$woocommerce->cart->fees[ $fee_index ]->recurring_tax    = $recurring_fee->tax;
					unset( $recurring_fees[ $index ] );
					break;
				}
			}

		}

		$woocommerce->cart->recurring_fee_total = $recurring_cart->fee_total;

		// If there is no sign-up fee and a free trial, and no products being purchased with the subscription, we need to zero the fees for the first billing period
		if ( 0 == $cart_sign_up_fee && $cart_has_trial && 1 == count( WC()->cart->cart_contents ) ) {
			foreach ( $woocommerce->cart->get_fees() as $fee_index => $fee ) {
				$woocommerce->cart->fees[ $fee_index ]->amount = 0;
				$woocommerce->cart->fees[ $fee_index ]->tax = 0;
			}
			$woocommerce->cart->fee_total = 0;
		}

		$woocommerce->cart->recurring_total = $recurring_cart->total;

		self::$calculation_type = 'none';

		$total = max( 0, round( $woocommerce->cart->cart_contents_total + $woocommerce->cart->tax_total + $woocommerce->cart->shipping_tax_total + $woocommerce->cart->shipping_total - $woocommerce->cart->discount_total + $woocommerce->cart->fee_total, $woocommerce->cart->dp ) );

		return $total;
	}


	/**
	 * Uses the a subscription's combined price total calculated by WooCommerce to determine the 
	 * total price that should be charged per period.
	 *
	 * @since 1.2
	 */
	public static function set_calculated_total( $total ) {
		global $woocommerce;

		if ( 'none' == self::$calculation_type || ( ! self::cart_contains_subscription() && ! self::cart_contains_subscription_renewal( 'parent' ) ) ) {
			return $total;
		}

		// We've requested totals be recalculated with sign up fee only or free trial, we need to remove anything shipping related from the totals if there are no other items in the cart that require shipping
		if ( 'sign_up_fee_total' == self::$calculation_type || 'free_trial_total' == self::$calculation_type ) {

			if ( ! self::charge_shipping_up_front() ) {
				$total = $total - $woocommerce->cart->shipping_tax_total - $woocommerce->cart->shipping_total;
				$woocommerce->cart->shipping_taxes = array();
				$woocommerce->cart->shipping_tax_total = 0;
				$woocommerce->cart->shipping_total     = 0;
			}

		}

		self::$calculation_type = 'none';

		return $total;
	}

	/**
	 * Check whether shipping should be charged on the initial order.
	 *
	 * When the cart contains a physical subscription with a free trial and no other physical items, shipping
	 * should not be charged up-front.
	 *
	 * @since 1.5.4
	 */
	public static function charge_shipping_up_front() {

		$charge_shipping_up_front = true;

		if ( WC()->cart->cart_contents && self::cart_contains_free_trial() ) {

			// the cart contains only a subscription with a free trial
			if ( 1 == count( WC()->cart->cart_contents ) ) {

				$charge_shipping_up_front = false;

			// cart contains other items, see if any require shipping
			} else {

				$other_items_need_shipping = false;

				foreach ( WC()->cart->cart_contents as $cart_item_key => $cart_item ) {
					if ( ! WC_Subscriptions_Product::is_subscription( $cart_item['data'] ) && $cart_item['data']->needs_shipping() ) {
						$other_items_need_shipping = true;
					}
				}

				if ( false === $other_items_need_shipping ) {
					$charge_shipping_up_front = false;
				}
			}
		}

		return apply_filters( 'woocommerce_subscriptions_cart_shipping_up_front', $charge_shipping_up_front );
	}

	/**
	 * Check if the cart contains a subscription which requires shipping.
	 *
	 * @since 1.5.4
	 */
	public static function cart_contains_subscriptions_needing_shipping() {

		$cart_contains_subscriptions_needing_shipping = false;

		if ( self::cart_contains_subscription() ) {
			foreach ( WC()->cart->cart_contents as $cart_item_key => $values ) {
				$_product = $values['data'];
				if ( WC_Subscriptions_Product::is_subscription( $_product ) && $_product->needs_shipping() ) {
					$cart_contains_subscriptions_needing_shipping = true;
				}
			}
		}

		return apply_filters( 'woocommerce_cart_contains_subscriptions_needing_shipping', $cart_contains_subscriptions_needing_shipping );
	}

	/* Formatted Totals Functions */

	/**
	 * Returns the subtotal for a cart item including the subscription period and duration details
	 *
	 * @since 1.0
	 */
	public static function get_formatted_product_subtotal( $product_subtotal, $product, $quantity, $cart ) {
		global $woocommerce;

		if ( WC_Subscriptions_Product::is_subscription( $product ) && ! self::cart_contains_subscription_renewal( 'child' ) ) {

			// Avoid infinite loop
			remove_filter( 'woocommerce_cart_product_subtotal', __CLASS__ . '::get_formatted_product_subtotal', 11, 4 );

			add_filter( 'woocommerce_get_price', array( &$product, 'get_sign_up_fee' ), 100, 0 );

			// And get the appropriate sign up fee string
			$sign_up_fee_string = $cart->get_product_subtotal( $product, $quantity );

			remove_filter( 'woocommerce_get_price', array( &$product, 'get_sign_up_fee' ), 100, 0 );

			$product_subtotal = WC_Subscriptions_Product::get_price_string( $product, array(
				'price'           => $product_subtotal,
				'sign_up_fee'     => $sign_up_fee_string,
				'tax_calculation' => WC()->cart->tax_display_cart,
				)
			);

			if ( false !== strpos( $product_subtotal, $woocommerce->countries->inc_tax_or_vat() ) ) {
				$product_subtotal = str_replace( $woocommerce->countries->inc_tax_or_vat(), '', $product_subtotal ) . ' <small class="tax_label">' . $woocommerce->countries->inc_tax_or_vat() . '</small>';
			}
			if ( false !== strpos( $product_subtotal, $woocommerce->countries->ex_tax_or_vat() ) ) {
				$product_subtotal = str_replace( $woocommerce->countries->ex_tax_or_vat(), '', $product_subtotal ) . ' <small class="tax_label">' .  $woocommerce->countries->ex_tax_or_vat() . '</small>';
			}

			$product_subtotal = '<span class="subscription-price">' . $product_subtotal . '</span>';
		}

		return $product_subtotal;
	}

	/**
	 * Returns a string with the cart discount and subscription period.
	 *
	 * @return mixed formatted price or false if there are none
	 * @since 1.2
	 */
	public static function get_formatted_discounts_before_tax( $discount, $cart ) {
		global $woocommerce;

		if ( self::cart_contains_subscription() && ( $discount !== false || self::get_recurring_discount_cart() > 0 ) ) {
			$discount = self::get_cart_subscription_string( $discount, self::get_recurring_discount_cart() );
		}

		return $discount;
	}

	/**
	 * Gets the order discount amount - these are applied after tax
	 *
	 * @return mixed formatted price or false if there are none
	 * @since 1.2
	 */
	public static function get_formatted_discounts_after_tax( $discount, $cart ) {

		if ( self::cart_contains_subscription() && ( $discount !== false || self::get_recurring_discount_total() > 0 ) ) {
			$discount = self::get_cart_subscription_string( $discount, self::get_recurring_discount_total() );
		}

		return $discount;
	}

	/**
	 * Returns individual coupon's formatted discount amount for WooCommerce 2.1+
	 *
	 * @param string $discount_html String of the coupon's discount amount
	 * @param string $coupon WC_Coupon object for the coupon to which this line item relates
	 * @return string formatted subscription price string if the cart includes a coupon being applied to recurring amount
	 * @since 1.4.6
	 */
	public static function cart_coupon_discount_amount_html( $discount_html, $coupon ) {
		global $woocommerce;

		if ( self::cart_contains_subscription() ) {
			$recurring_discount = isset( $woocommerce->cart->recurring_coupon_discount_amounts[ $coupon->code ] ) ? $woocommerce->cart->recurring_coupon_discount_amounts[ $coupon->code ] : 0;
			$recurring_discount = '-' . woocommerce_price( $recurring_discount );

			// Discount is same for initial payment and recurring discount, don't display initial amount
			$include_trial = ( '' == $discount_html ) ? true : false;

			$discount_html = self::get_cart_subscription_string( $discount_html, $recurring_discount, array( 'include_trial' => $include_trial ) );
		}

		return $discount_html;
	}

	/**
	 * Returns individual coupon's formatted discount amount for WooCommerce 2.1+
	 *
	 * @param string $discount_html String of the coupon's discount amount
	 * @param string $coupon WC_Coupon object for the coupon to which this line item relates
	 * @return string formatted subscription price string if the cart includes a coupon being applied to recurring amount
	 * @since 1.4.6
	 */
	public static function cart_totals_fee_html( $cart_totals_fee_html, $fee ) {
		global $woocommerce;

		if ( self::cart_contains_subscription() ) {
			$recurring_fee_total = ( 'excl' == $woocommerce->cart->tax_display_cart ) ? $fee->recurring_amount : $fee->recurring_amount + $fee->recurring_tax;
			$include_trial = ( 0 == $fee->amount ) ? true : false;
			$cart_totals_fee_html = self::get_cart_subscription_string( $cart_totals_fee_html, $recurring_fee_total, array( 'include_trial' => $include_trial ) );
		}

		return $cart_totals_fee_html;
	}

	/**
	 * Includes the sign-up fee total in the cart total (after calculation).
	 *
	 * @since 1.5.10
	 * @return string formatted price
	 */
	public function get_formatted_cart_total( $cart_contents_total ) {

		if ( self::cart_contains_subscription() && ! self::cart_contains_subscription_renewal( 'child' ) ) {
			if ( ! WC()->cart->prices_include_tax ) {
				$cart_contents_total = self::get_cart_subscription_string( $cart_contents_total, self::get_recurring_cart_contents_total() );
			} else {
				$cart_contents_total = self::get_cart_subscription_string( $cart_contents_total, self::get_recurring_cart_contents_total() + self::get_recurring_total_tax() );
			}
		}

		return $cart_contents_total;
	}

	/**
	 * Includes the sign-up fee subtotal in the subtotal displayed in the cart.
	 *
	 * @since 1.2
	 */
	public static function get_formatted_cart_subtotal( $cart_subtotal, $compound, $cart ) {
		global $woocommerce;

		if ( self::cart_contains_subscription() && ! self::cart_contains_subscription_renewal( 'child' ) ) {

			// We're in the cart widget and totals haven't been properly calculated yet so just show the product's subscription price string
			if ( $compound ) { // If the cart has compound tax, we want to show the subtotal as cart + non-compound taxes - after discount

				$cart_subtotal = self::get_cart_subscription_string( $cart_subtotal, self::get_recurring_cart_contents_total() + self::get_recurring_shipping_total() + self::get_recurring_taxes_total( false ) );

			// Otherwise we show cart items totals only (before discount)
			} else {

				// Display varies depending on settings
				if ( $cart->tax_display_cart == 'excl' ) {

					$cart_subtotal = self::get_cart_subscription_string( $cart_subtotal, self::get_recurring_subtotal_ex_tax() );

					if ( $cart->tax_total > 0 && $cart->prices_include_tax ) {
						$cart_subtotal = str_replace( $woocommerce->countries->ex_tax_or_vat(), '', $cart_subtotal ) . ' <small>' . $woocommerce->countries->ex_tax_or_vat() . '</small>';
					}

				} else {

					$cart_subtotal = self::get_cart_subscription_string( $cart_subtotal, self::get_recurring_subtotal() );

					if ( $cart->tax_total > 0 && ! $cart->prices_include_tax ) {
						$cart_subtotal = str_replace( $woocommerce->countries->inc_tax_or_vat(), '', $cart_subtotal ) . ' <small>' . $woocommerce->countries->inc_tax_or_vat() . '</small>';
					}

				}
			}
		}

		return $cart_subtotal;
	}

	/**
	 * Returns an array of taxes merged by code, formatted with recurring amount ready for output.
	 *
	 * @return array Array of tax_id => tax_amounts for items in the cart
	 * @since 1.3.5
	 */
	public static function get_recurring_tax_totals( $tax_totals, $cart ) {

		if ( self::cart_contains_subscription() ) {

			$recurring_taxes = self::get_recurring_taxes();

			// Add any recurring tax not already handled - when a subscription has a free trial and a sign-up fee, we get a recurring shipping tax with no initial shipping tax
			foreach ( $recurring_taxes as $key => $tax ) {

				$code = $cart->tax->get_rate_code( $key );

				if ( ! isset( $tax_totals[ $code ] ) ) {
					$tax_totals[ $code ] = new stdClass();
					$tax_totals[ $code ]->is_compound      = $cart->tax->is_compound( $key );
					$tax_totals[ $code ]->label            = $cart->tax->get_rate_label( $key );
					$tax_totals[ $code ]->amount           = 0;
				}

				if ( ! isset( $tax_totals[ $code ]->recurring_amount ) ) {
					$tax_totals[ $code ]->recurring_amount = 0;
				}

				$tax_totals[ $code ]->recurring_amount += $tax;
			}

			// Now create the correctly formed subscription price string for each total
			foreach ( $tax_totals as $code => $tax ) {
				$include_trial = ( 0 == $tax_totals[ $code ]->amount ) ? true : false;
				$tax_totals[ $code ]->formatted_amount = self::get_cart_subscription_string( $tax_totals[ $code ]->amount, $tax_totals[ $code ]->recurring_amount, array( 'include_trial' => $include_trial ) );
			}

		}

		return apply_filters( 'woocommerce_cart_recurring_tax_totals', $tax_totals, $cart );
	}

	/**
	 * Returns a string of the sum of all taxes in the cart for initial payment and
	 * recurring amount.
	 *
	 * @return array Array of tax_id => tax_amounts for items in the cart
	 * @since 1.4.10
	 */
	public static function get_taxes_total_html( $total ) {

		if ( self::cart_contains_subscription() ) {

			$recurring_total = self::get_recurring_taxes_total();

			if ( function_exists( 'wc_round_tax_total' ) ) { // WC 2.1
				$recurring_total = wc_round_tax_total( $recurring_total );
			}

			$include_trial = ( woocommerce_price( 0 ) == $total ) ? true : false;
			$total = self::get_cart_subscription_string( $total, $recurring_total, array( 'include_trial' => $include_trial ) );
		}

		return $total;
	}

	/**
	 * Appends the cart subscription string to a cart total using the @see self::get_cart_subscription_string and then returns it. 
	 *
	 * @return string Formatted subscription price string for the cart total.
	 * @since 1.2
	 */
	public static function get_formatted_total( $total ) {
		global $woocommerce;

		if ( self::cart_contains_subscription() ) {
			$total = self::get_cart_subscription_string( $total, self::get_recurring_total(), array( 'include_lengths' => true ) );
		}

		return $total;
	}

	/**
	 * Appends the cart's subscription string to the shipping total label using the @see self::get_cart_subscription_string.
	 *
	 * @return string Formatted subscription price string for the cart shipping.
	 * @since 1.3
	 */
	public static function get_cart_shipping_method_full_label( $label, $method ) {
		global $woocommerce;

		if ( $method->cost > 0 && self::cart_contains_subscriptions_needing_shipping() ) {

			$label = $method->label;

			if ( WC()->cart->tax_display_cart == 'excl' ) {
				$initial_amount   = ( self::charge_shipping_up_front() ) ? wc_price( $method->cost ) : 0;
				$recurring_amount = wc_price( $method->cost );
			} else {
				$initial_amount   = ( self::charge_shipping_up_front() ) ? wc_price( $method->cost + $method->get_shipping_tax() ) : 0;
				$recurring_amount = wc_price( $method->cost + $method->get_shipping_tax() );
			}

			$include_trial = ( $initial_amount == $recurring_amount && self::charge_shipping_up_front() ) ? false : true;

			$label .= ': ' . self::get_cart_subscription_string( $initial_amount, $recurring_amount, array( 'include_trial' => $include_trial ) );

			if ( WC()->cart->tax_display_cart == 'excl' ) {
				if ( $method->get_shipping_tax() > 0 && WC()->cart->prices_include_tax ) {
					$label .= ' <small>' . WC()->countries->ex_tax_or_vat() . '</small>';
				}
			} else {
				if ( $method->get_shipping_tax() > 0 && ! WC()->cart->prices_include_tax ) {
					$label .= ' <small>' . WC()->countries->inc_tax_or_vat() . '</small>';
				}
			}
		}

		return $label;
	}

	/**
	 * Appends the cart subscription string to a cart total using the @see self::get_cart_subscription_string and then returns it. 
	 *
	 * @return string Formatted subscription price string for the cart total.
	 * @since 1.2
	 */
	public static function get_formatted_total_ex_tax( $total_ex_tax ) {
		global $woocommerce;

		if ( self::cart_contains_subscription() ) {
			$total_ex_tax = self::get_cart_subscription_string( $total_ex_tax, self::get_recurring_total_ex_tax(), array( 'include_lengths' => true ) );
		}

		return $total_ex_tax;
	}


	/*
	 * Helper functions for extracting the details of subscriptions in the cart
	 */

	/**
	 * Creates a string representation of the subscription period/term for each item in the cart
	 *
	 * @param string $initial_amount The initial amount to be displayed for the subscription as passed through the @see woocommerce_price() function.
	 * @param float $recurring_amount The price to display in the subscription.
	 * @param array $args (optional) Flags to customise  to display the trial and length of the subscription. Default to false - don't display.
	 * @since 1.0
	 */
	public static function get_cart_subscription_string( $initial_amount, $recurring_amount, $args = array() ) {
		global $woocommerce;

		if ( ! is_array( $args ) ) {
			_deprecated_argument( __CLASS__ . '::' . __FUNCTION__, '1.4', __( 'Third parameter is now an array of name => value pairs. Use array( "include_lengths" => true ) instead.', 'woocommerce-subscriptions' ) );
			$args = array(
				'include_lengths' => $args,
			);
		}

		$args = wp_parse_args( $args, array(
				'include_lengths' => false,
				'include_trial'   => true,
			)
		);

		$subscription_details = array(
			'initial_amount'        => $initial_amount,
			'initial_description'   => __( 'now', 'woocommerce-subscriptions' ),
			'recurring_amount'      => $recurring_amount,
			'subscription_interval' => self::get_cart_subscription_interval(),
			'subscription_period'   => self::get_cart_subscription_period(),
			'trial_length'          => self::get_cart_subscription_trial_length(),
			'trial_period'          => self::get_cart_subscription_trial_period()
		);

		$is_one_payment = ( self::get_cart_subscription_length() > 0 && self::get_cart_subscription_length() == self::get_cart_subscription_interval() ) ? true : false;

		// Override defaults when subscription is for one billing period
		if ( $is_one_payment ) {

			$subscription_details['subscription_length'] = self::get_cart_subscription_length();

		} else {

			if ( true === $args['include_lengths'] ) {
				$subscription_details['subscription_length'] = self::get_cart_subscription_length();
			}

			if ( false === $args['include_trial'] ) {
				$subscription_details['trial_length'] = 0;
			}

		}

		$initial_amount_string   = ( is_numeric( $subscription_details['initial_amount'] ) ) ? woocommerce_price( $subscription_details['initial_amount'] ) : $subscription_details['initial_amount'];
		$recurring_amount_string = ( is_numeric( $subscription_details['recurring_amount'] ) ) ? woocommerce_price( $subscription_details['recurring_amount'] ) : $subscription_details['recurring_amount'];

		// Don't show up front fees when there is no trial period and no sign up fee and they are the same as the recurring amount
		if ( self::get_cart_subscription_trial_length() == 0 && self::get_cart_subscription_sign_up_fee() == 0 && $initial_amount_string == $recurring_amount_string ) {
			$subscription_details['initial_amount'] = '';
		} elseif ( wc_price( 0 ) == $initial_amount_string && false === $is_one_payment && self::get_cart_subscription_trial_length() > 0 ) { // don't show $0.00 initial amount (i.e. a free trial with no non-subscription products in the cart) unless the recurring period is the same as the billing period
			$subscription_details['initial_amount'] = '';
		}

		// Include details of a synced subscription in the cart
		if ( $synchronised_cart_item = WC_Subscriptions_Synchroniser::cart_contains_synced_subscription() ) {
			$subscription_details += array(
				'is_synced'                => true,
				'synchronised_payment_day' => WC_Subscriptions_Synchroniser::get_products_payment_day( $synchronised_cart_item['data'] ),
			);
		}

		$subscription_details = apply_filters( 'woocommerce_cart_subscription_string_details', $subscription_details, $args );

		$subscription_string = WC_Subscriptions_Manager::get_subscription_price_string( $subscription_details );

		return $subscription_string;
	}

	/**
	 * Checks the cart to see if it contains a subscription product. 
	 *
	 * @since 1.0
	 */
	public static function cart_contains_subscription() {
		global $woocommerce;

		$contains_subscription = false;

		if ( self::cart_contains_subscription_renewal( 'child' ) ) {

			$contains_subscription = false;

		} else if ( ! empty( $woocommerce->cart->cart_contents ) ) {
			foreach ( $woocommerce->cart->cart_contents as $cart_item ) {
				if ( WC_Subscriptions_Product::is_subscription( $cart_item['product_id'] ) ) {
					$contains_subscription = true;
					break;
				}
			}
		}

		return $contains_subscription;
	}

	/**
	 * Checks the cart to see if it contains a subscription product renewal. 
	 *
	 * Returns the cart_item containing the product renewal, else false.
	 *
	 * @since 1.3
	 */
	public static function cart_contains_subscription_renewal( $role = '' ) {
		global $woocommerce;

		$contains_renewal = false;

		if ( ! empty( $woocommerce->cart->cart_contents ) ) {
			foreach ( $woocommerce->cart->cart_contents as $cart_item ) {
				if ( isset( $cart_item['subscription_renewal'] ) && ( empty( $role ) || $role === $cart_item['subscription_renewal']['role'] ) ) {
					$contains_renewal = $cart_item;
					break;
				}
			}
		}

		return $contains_renewal;
	}

	/**
	 * Checks the cart to see if it contains a subscription product renewal.
	 *
	 * Returns the cart_item containing the product renewal, else false.
	 *
	 * @since 1.4
	 */
	public static function cart_contains_failed_renewal_order_payment() {
		global $woocommerce;

		$contains_renewal = false;

		if ( ! empty( $woocommerce->cart->cart_contents ) ) {
			foreach ( $woocommerce->cart->cart_contents as $cart_item ) {
				if ( isset( $cart_item['subscription_renewal'] ) && null !== $cart_item['subscription_renewal']['failed_order'] ) {
					$failed_order = new WC_Order( $cart_item['subscription_renewal']['failed_order'] );
					if ( 'failed' === $failed_order->status ) {
						$contains_renewal = $cart_item;
						break;
					}
				}
			}
		}

		return $contains_renewal;
	}

	/**
	 * Checks the cart to see if it contains a subscription product with a free trial
	 *
	 * @since 1.2
	 */
	public static function cart_contains_free_trial() {
		return ( self::get_cart_subscription_trial_length() > 0 ) ? true : false;
	}

	/**
	 * Gets the recalculate flag
	 *
	 * @since 1.2
	 */
	public static function get_calculation_type() {
		return self::$calculation_type;
	}

	/**
	 * Gets the subscription period from the cart and returns it as an array (eg. array( 'month', 'day' ) )
	 *
	 * @since 1.0
	 */
	public static function get_cart_subscription_period() {
		global $woocommerce;

		foreach ( $woocommerce->cart->cart_contents as $cart_item ) {
			$item_id = empty( $cart_item['variation_id'] ) ? $cart_item['product_id'] : $cart_item['variation_id'];
			if ( isset( $cart_item['data']->subscription_period ) ) {
				$period = $cart_item['data']->subscription_period;
				break;
			} elseif ( WC_Subscriptions_Product::is_subscription( $item_id ) ) {
				$period = WC_Subscriptions_Product::get_period( $item_id );
				break;
			}
		}

		return apply_filters( 'woocommerce_subscriptions_cart_period', $period );
	}

	/**
	 * Gets the subscription period from the cart and returns it as an array (eg. array( 'month', 'day' ) )
	 *
	 * @since 1.0
	 */
	public static function get_cart_subscription_interval() {
		global $woocommerce;

		foreach ( $woocommerce->cart->cart_contents as $cart_item ) {
			$item_id = empty( $cart_item['variation_id'] ) ? $cart_item['product_id'] : $cart_item['variation_id'];
			if ( WC_Subscriptions_Product::is_subscription( $item_id ) ) {
				$interval = WC_Subscriptions_Product::get_interval( $item_id );
				break;
			}
		}

		return apply_filters( 'woocommerce_subscriptions_cart_interval', $interval );
	}

	/**
	 * Gets the subscription length from the cart and returns it as an array (eg. array( 'month', 'day' ) )
	 *
	 * @since 1.0
	 */
	public static function get_cart_subscription_length() {
		global $woocommerce;

		$length = 0;

		foreach ( $woocommerce->cart->cart_contents as $cart_item ) {
			$item_id = empty( $cart_item['variation_id'] ) ? $cart_item['product_id'] : $cart_item['variation_id'];
			if ( isset( $cart_item['data']->subscription_length ) ) {
				$length = $cart_item['data']->subscription_length;
				break;
			} elseif ( WC_Subscriptions_Product::is_subscription( $item_id ) ) {
				$length = WC_Subscriptions_Product::get_length( $item_id );
				break;
			}
		}

		return apply_filters( 'woocommerce_subscriptions_cart_length', $length );
	}

	/**
	 * Gets the subscription length from the cart and returns it as an array (eg. array( 'month', 'day' ) )
	 *
	 * @since 1.1
	 */
	public static function get_cart_subscription_trial_length() {
		global $woocommerce;

		$trial_length = 0;

		foreach ( $woocommerce->cart->cart_contents as $cart_item ) {
			$item_id = empty( $cart_item['variation_id'] ) ? $cart_item['product_id'] : $cart_item['variation_id'];
			if ( isset( $cart_item['data']->subscription_trial_length ) ) {
				$trial_length = $cart_item['data']->subscription_trial_length;
				break;
			} elseif ( WC_Subscriptions_Product::is_subscription( $item_id ) ) {
				$trial_length = WC_Subscriptions_Product::get_trial_length( $item_id );
				break;
			}
		}

		return apply_filters( 'woocommerce_subscriptions_cart_trial_length', $trial_length );
	}

	/**
	 * Gets the subscription trial period from the cart and returns it as an array (eg. array( 'month', 'day' ) )
	 *
	 * @since 1.2
	 */
	public static function get_cart_subscription_trial_period() {
		global $woocommerce;

		$trial_period = '';

		// Get the original trial period
		foreach ( $woocommerce->cart->cart_contents as $cart_item ) {
			$item_id = empty( $cart_item['variation_id'] ) ? $cart_item['product_id'] : $cart_item['variation_id'];
			if ( isset( $cart_item['data']->subscription_trial_period ) ) {
				$trial_period = $cart_item['data']->subscription_trial_period;
				break;
			} elseif ( WC_Subscriptions_Product::is_subscription( $item_id ) ) {
				$trial_period = WC_Subscriptions_Product::get_trial_period( $item_id );
				break;
			}
		}

		return apply_filters( 'woocommerce_subscriptions_cart_trial_period', $trial_period );
	}

	/**
	 * Gets the subscription sign up fee for the cart and returns it
	 *
	 * Currently short-circuits to return just the sign-up fee of the first subscription, because only
	 * one subscription can be purchased at a time. 
	 *
	 * @since 1.0
	 */
	public static function get_cart_subscription_sign_up_fee() {
		global $woocommerce;

		$sign_up_fee = 0;

		if ( ! self::cart_contains_subscription_renewal() ) {
			foreach ( $woocommerce->cart->cart_contents as $cart_item ) {
				$item_id = empty( $cart_item['variation_id'] ) ? $cart_item['product_id'] : $cart_item['variation_id'];
				if ( isset( $cart_item['data']->subscription_sign_up_fee ) ) {
					$sign_up_fee = $cart_item['data']->subscription_sign_up_fee;
					break;
				} elseif ( WC_Subscriptions_Product::is_subscription( $item_id ) ) {
					$sign_up_fee = WC_Subscriptions_Product::get_sign_up_fee( $item_id );
					break;
				}
			}
		}

		return apply_filters( 'woocommerce_subscriptions_cart_sign_up_fee', $sign_up_fee );
	}

	/* Total Getters */

	/**
	 * Get tax row amounts with or without compound taxes includes
	 *
	 * @return float price
	 */
	public static function get_recurring_cart_contents_total() {
		global $woocommerce;

		if ( ! $woocommerce->cart->prices_include_tax ) {
			$cart_contents_total = $woocommerce->cart->recurring_cart_contents_total;
		} else {
			$cart_contents_total = $woocommerce->cart->recurring_cart_contents_total + $woocommerce->cart->recurring_tax_total;
		}

		return $cart_contents_total;
	}

	/**
	 * Returns the proportion of cart discount that is recurring for the product specified with $product_id
	 *
	 * @return double The total recurring item subtotal amount less tax for items in the cart.
	 * @since 1.2
	 */
	public static function get_recurring_subtotal_ex_tax() {
		global $woocommerce;

		return $woocommerce->cart->recurring_subtotal_ex_tax;
	}

	/**
	 * Returns the proportion of cart discount that is recurring for the product specified with $product_id
	 *
	 * @return double The total recurring item subtotal amount for items in the cart.
	 * @since 1.2
	 */
	public static function get_recurring_subtotal() {
		global $woocommerce;

		return $woocommerce->cart->recurring_subtotal;
	}

	/**
	 * Returns the proportion of cart discount that is recurring for the product specified with $product_id
	 *
	 * @return double The total recurring cart discount amount for items in the cart.
	 * @since 1.2
	 */
	public static function get_recurring_discount_cart() {
		global $woocommerce;

		return $woocommerce->cart->recurring_discount_cart;
	}

	/**
	 * Returns the proportion of total discount that is recurring for the product specified with $product_id
	 *
	 * @return double The total recurring discount amount for items in the cart.
	 * @since 1.2
	 */
	public static function get_recurring_discount_total() {
		global $woocommerce;

		return $woocommerce->cart->recurring_discount_total;
	}

	/**
	 * Returns the amount of shipping tax that is recurring. As shipping only applies
	 * to recurring payments, and only 1 subscription can be purchased at a time, 
	 * this is equal to @see WC_Cart::$shipping_tax_total
	 *
	 * @return double The total recurring shipping tax amount for items in the cart.
	 * @since 1.2
	 */
	public static function get_recurring_shipping_tax_total() {
		global $woocommerce;

		return $woocommerce->cart->recurring_shipping_tax_total;
	}

	/**
	 * Returns the recurring shipping price . As shipping only applies to recurring
	 * payments, and only 1 subscription can be purchased at a time, this is
	 * equal to @see WC_Cart::shipping_total
	 *
	 * @return double The total recurring shipping amount for items in the cart.
	 * @since 1.2
	 */
	public static function get_recurring_shipping_total() {
		global $woocommerce;

		return $woocommerce->cart->recurring_shipping_total;
	}

	/**
	 * Returns an array of taxes on an order with their recurring totals.
	 *
	 * @return array Array of tax_id => tax_amounts for items in the cart
	 * @since 1.2
	 */
	public static function get_recurring_taxes() {
		global $woocommerce;

		$taxes = array();

		foreach ( array_keys( $woocommerce->cart->recurring_taxes + $woocommerce->cart->recurring_shipping_taxes ) as $key ) {
			$taxes[ $key ] = ( isset( $woocommerce->cart->recurring_shipping_taxes[ $key ] ) ? $woocommerce->cart->recurring_shipping_taxes[ $key ] : 0 ) + ( isset( $woocommerce->cart->recurring_taxes[ $key ] ) ? $woocommerce->cart->recurring_taxes[ $key ] : 0 );
		}

		return $taxes;
	}

	/**
	 * Returns an array of recurring fees.
	 *
	 * @return array Array of fee_id => fee_details for items in the cart
	 * @since 1.4.9
	 */
	public static function get_recurring_fees() {
		global $woocommerce;

		return isset( $woocommerce->cart->recurring_fees ) ? $woocommerce->cart->recurring_fees : array();
	}

	/**
	 * Get tax row amounts with or without compound taxes includes
	 *
	 * @return double The total recurring tax amount tax for items in the cart (maybe not including compound taxes)
	 * @since 1.2
	 */
	public static function get_recurring_taxes_total( $compound = true ) {
		global $woocommerce;

		$recurring_taxes_total = 0;

		foreach ( self::get_recurring_taxes() as $tax_id => $tax_amount ) {

			if ( ! $compound && $woocommerce->cart->tax->is_compound( $tax_id ) ) {
				continue;
			}

			$recurring_taxes_total += $tax_amount;
		}

		return $recurring_taxes_total;
	}

	/**
	 * Returns the proportion of total tax on an order that is recurring for the product specified with $product_id
	 *
	 * @return double The total recurring tax amount tax for items in the cart.
	 * @since 1.2
	 */
	public static function get_recurring_total_tax() {
		global $woocommerce;

		return $woocommerce->cart->recurring_tax_total;
	}

	/**
	 * Returns the proportion of total before tax on an order that is recurring for the product specified with $product_id
	 *
	 * @return double The total recurring amount less tax for items in the cart.
	 * @since 1.2
	 */
	public static function get_recurring_total_ex_tax() {
		return self::get_recurring_total() - self::get_recurring_total_tax() - self::get_recurring_shipping_tax_total();
	}

	/**
	 * Returns the price per period for a subscription in an order.
	 *
	 * @return double The total recurring amount for items in the cart.
	 * @since 1.2
	 */
	public static function get_recurring_total() {
		global $woocommerce;

		return $woocommerce->cart->recurring_total;
	}


	/* Session management */

	/**
	 * Get the recurring amounts values from the session
	 *
	 * @since 1.0
	 */
	public static function get_cart_from_session() {
		global $woocommerce;

		if ( is_object( $woocommerce->cart ) ) {
			foreach ( self::get_recurring_totals_fields() as $field => $default_value ) {
				$woocommerce->cart->{$field} = isset( $woocommerce->session->$field ) ? $woocommerce->session->$field : $default_value;
			}
		}
	}

	/**
	 * Store the sign-up fee cart values in the session
	 *
	 * @since 1.0
	 */
	public static function set_session() {
		global $woocommerce;

		foreach ( self::get_recurring_totals_fields() as $field => $default_value ) {

			$value = isset( $woocommerce->cart->{$field} ) ? $woocommerce->cart->{$field} : $default_value;

			$woocommerce->session->$field = $value;
		}
	}

	/**
	 * Reset the sign-up fee fields in the current session
	 *
	 * @since 1.0
	 */
	public static function reset() {
		global $woocommerce;

		foreach ( self::get_recurring_totals_fields() as $field => $default_value ) {
			$woocommerce->cart->{$field} = $default_value;
			unset( $woocommerce->session->$field );
		}

	}

	/**
	 * Returns an array of the recurring total fields
	 *
	 * @since 1.2
	 */
	public static function get_recurring_totals_fields() {
		return array(
			'recurring_cart_contents_total'     => 0,
			'recurring_coupon_discount_amounts' => array(),
			'recurring_discount_cart'           => 0,
			'recurring_discount_total'          => 0,
			'recurring_subtotal'                => 0,
			'recurring_subtotal_ex_tax'         => 0,
			'recurring_taxes'                   => array(),
			'recurring_tax_total'               => 0,
			'recurring_total'                   => 0,
		);
	}
	
	/**
	 * Restore renewal flag when cart is reset and modify Product object with
	 * renewal order related info
	 *
	 * @since 1.3
	 */
	public static function get_cart_item_from_session( $session_data, $values, $key ) {

		if ( isset( $values['subscription_renewal'] ) ) {

			$session_data['subscription_renewal'] = $values['subscription_renewal'];

			// Need to get the original order price, not the current price
			$original_order_id = $values['subscription_renewal']['original_order'];
			$order_items = WC_Subscriptions_Order::get_recurring_items( $original_order_id );
			$first_order_item = reset( $order_items );
			$price = $first_order_item['subscription_recurring_amount'];

			/*
			 * Modify the Cart $_product object. 
			 * All the cart calculations and cart/checkout/mini-cart displays will use this object.
			 * So by modifying it here, we take care of all those cases.
			 */
			$_product = $session_data['data'];
			$_product->price = $price;

			// Don't carry over any sign up fee
			$_product->subscription_sign_up_fee = 0;

			if ( WC_Subscriptions::is_woocommerce_pre_2_2() ) {
				$_product->product_custom_fields['_subscription_sign_up_fee'][0] = 0;
			}

			// Make sure the original subscription terms perisist
			if ( 'parent' == $session_data['subscription_renewal']['role'] ) {

				$_product->subscription_price = $price;
				$_product->subscription_period = $first_order_item['subscription_period'];
				$_product->subscription_period_interval = $first_order_item['subscription_interval'];
				$_product->subscription_trial_period = $first_order_item['subscription_trial_period'];
				$_product->subscription_length = $first_order_item['subscription_length'];

				// Never give a free trial period again
				$_product->subscription_trial_length = 0;

				if ( WC_Subscriptions::is_woocommerce_pre_2_2() ) {
					$_product->product_custom_fields['_subscription_price'][0] = $price;
					$_product->product_custom_fields['_subscription_period'][0] = $first_order_item['subscription_period'];
					$_product->product_custom_fields['_subscription_period_interval'][0] = $first_order_item['subscription_interval'];
					$_product->product_custom_fields['_subscription_trial_period'][0] = $first_order_item['subscription_trial_period'];
					$_product->product_custom_fields['_subscription_length'][0] = $first_order_item['subscription_length'];
					$_product->product_custom_fields['_subscription_trial_length'][0] = 0;
				}

			}

			$_product->post->post_title = apply_filters( 'woocommerce_subscriptions_renewal_product_title', $_product->get_title(), $_product );

			// Make sure the same quantity is renewed
			$session_data['quantity'] = $first_order_item['qty'];
		}

		return $session_data;
	}

	/**
	 * For subscription renewal via cart, use original order discount
	 *
	 * @since 1.3
	 */	
	public static function before_calculate_totals( $cart ) {

		$cart_item = self::cart_contains_subscription_renewal( 'child' );

		if ( $cart_item ) {

			$original_order_id = $cart_item['subscription_renewal']['original_order'];

			$cart->discount_cart = WC_Subscriptions_Order::get_meta( $original_order_id, '_order_recurring_discount_cart', 0 );
			$cart->discount_total = WC_Subscriptions_Order::get_meta( $original_order_id, '_order_recurring_discount_total', 0 );
		}
	}

	/**
	 * Store how much discount each coupon grants.
	 *
	 * @param mixed $code
	 * @param mixed $amount
	 * @return void
	 */
	public static function increase_coupon_discount_amount( $code, $amount ) {
		global $woocommerce;

		if ( empty( $woocommerce->cart->coupon_discount_amounts[ $code ] ) ) {
			$woocommerce->cart->coupon_discount_amounts[ $code ] = 0;
		}

		$woocommerce->cart->coupon_discount_amounts[ $code ] += $amount;
	}

	/**
	 * Check whether the cart needs payment even if the order total is $0
	 *
	 * @param bool $needs_payment The existing flag for whether the cart needs payment or not.
	 * @param WC_Cart $cart The WooCommerce cart object.
	 * @return bool
	 */
	public static function cart_needs_payment( $needs_payment, $cart ) {

		if ( false === $needs_payment && self::cart_contains_subscription() && $cart->total == 0 && $cart->recurring_total > 0  && 'yes' !== get_option( WC_Subscriptions_Admin::$option_prefix . '_turn_off_automatic_payments', 'no' ) ) {

			$is_one_period = ( self::get_cart_subscription_length() > 0 && self::get_cart_subscription_length() == self::get_cart_subscription_interval() ) ? true : false;
			$has_trial     = ( self::get_cart_subscription_trial_length() > 0 ) ? true : false;
			$is_syncd      = WC_Subscriptions_Synchroniser::cart_contains_synced_subscription();

			if ( false === $is_one_period || true === $has_trial || ( false !== $is_syncd && false == WC_Subscriptions_Synchroniser::is_today( WC_Subscriptions_Synchroniser::calculate_first_payment_date( $is_syncd['data'], 'timestamp' ) ) ) ) {
				$needs_payment = true;
			}
		}

		return $needs_payment;
	}

	/**
	 * Calculate the total amount of recurring shipping needed.  Removes any item from the calculation that
	 * is not a subscription and calculates the totals.
	 *
	 * @return string
	 *
	 * @since 1.5
	 */
	public static function calculate_recurring_shipping() {
		global $woocommerce;

		$packages = array();
		$recurring_shipping_total = 0;

		foreach ( $woocommerce->cart->get_cart() as $cart_item_key => $cart_item ) {
			if ( WC_Subscriptions_Product::is_subscription( $cart_item['product_id'] ) && $cart_item['data']->needs_shipping() ) {
				$packages[0]['contents'][ $cart_item_key ] = $cart_item;
			}
		}

		if ( ! empty( $packages ) ) {

			$packages[0]['contents_cost']            = 0; // Cost of items in the package, set below
			$packages[0]['applied_coupons']          = 0;
			$packages[0]['destination']['country']   = WC()->customer->get_shipping_country();
			$packages[0]['destination']['state']     = WC()->customer->get_shipping_state();
			$packages[0]['destination']['postcode']  = WC()->customer->get_shipping_postcode();
			$packages[0]['destination']['city']      = WC()->customer->get_shipping_city();
			$packages[0]['destination']['address']   = WC()->customer->get_shipping_address();
			$packages[0]['destination']['address_2'] = WC()->customer->get_shipping_address_2();

			WC()->shipping->calculate_shipping( $packages );

			$recurring_shipping_total = WC()->shipping->shipping_total;
		}

		return $recurring_shipping_total;
	}

	/**
	 * For subscription renewal via cart, previously adjust item price by original order discount
	 *
	 * No longer required as of 1.3.5 as totals are calculated correctly internally.
	 *
	 * @since 1.3
	 */
	public static function get_discounted_price_for_renewal( $price, $values, $cart ) {

		$cart_item = self::cart_contains_subscription_renewal( 'child' );

		if ( $cart_item ) {
			$original_order_id = $cart_item['subscription_renewal']['original_order'];
			$price -= WC_Subscriptions_Order::get_meta( $original_order_id, '_order_recurring_discount_cart', 0 );
		}

		return $price;
	}

	/**
	 * Re-calculate a shipping and tax estimate when on the cart page.
	 *
	 * The WC_Shortcode_Cart actually calculates shipping when the "Calculate Shipping" form is submitted on the
	 * cart page. Because of that, our own @see self::calculate_totals() method calculates incorrect values on
	 * the cart page because it triggers the method multiple times for multiple different pricing structures.
	 * This uses the same logic found in WC_Shortcode_Cart::output() to determine the correct estimate.
	 *
	 * @since 1.4.10
	 */
	private static function maybe_recalculate_shipping() {
		if ( ! empty( $_POST['calc_shipping'] ) && wp_verify_nonce( $_POST['_wpnonce'], 'woocommerce-cart' ) && function_exists( 'WC' ) ) {

			try {
				WC()->shipping->reset_shipping();

				$country  = wc_clean( $_POST['calc_shipping_country'] );
				$state    = isset( $_POST['calc_shipping_state'] ) ? wc_clean( $_POST['calc_shipping_state'] ) : '';
				$postcode = apply_filters( 'woocommerce_shipping_calculator_enable_postcode', true ) ? wc_clean( $_POST['calc_shipping_postcode'] ) : '';
				$city     = apply_filters( 'woocommerce_shipping_calculator_enable_city', false ) ? wc_clean( $_POST['calc_shipping_city'] ) : '';

				if ( $postcode && ! WC_Validation::is_postcode( $postcode, $country ) ) {
					throw new Exception( __( 'Please enter a valid postcode/ZIP.', 'woocommerce' ) );
				} elseif ( $postcode ) {
					$postcode = wc_format_postcode( $postcode, $country );
				}

				if ( $country ) {
					WC()->customer->set_location( $country, $state, $postcode, $city );
					WC()->customer->set_shipping_location( $country, $state, $postcode, $city );
				} else {
					WC()->customer->set_to_base();
					WC()->customer->set_shipping_to_base();
				}

				WC()->customer->calculated_shipping( true );

				do_action( 'woocommerce_calculated_shipping' );

			} catch ( Exception $e ) {
				if ( ! empty( $e ) ) {
					wc_add_notice( $e->getMessage(), 'error' );
				}
			}
		}

		// Now make sure the correct shipping method is set
		$chosen_shipping_methods = WC()->session->get( 'chosen_shipping_methods' );

		if ( isset( $_POST['shipping_method'] ) && is_array( $_POST['shipping_method'] ) ) {
			foreach ( $_POST['shipping_method'] as $i => $value ) {
				$chosen_shipping_methods[ $i ] = wc_clean( $value );
			}
		}

		WC()->session->set( 'chosen_shipping_methods', $chosen_shipping_methods );
	}

	/**
	 * Returns a cart item's product ID. For a variation, this will be a variation ID, for a simple product,
	 * it will be the product's ID.
	 *
	 * @since 1.5
	 */
	public static function get_items_product_id( $cart_item ) {
		return ( ! empty( $cart_item['variation_id'] ) ) ? $cart_item['variation_id'] : $cart_item['product_id'];
	}

	/**
	 * Make sure cart product prices correctly include/exclude taxes.
	 *
	 * @since 1.5.8
	 */
	public static function cart_product_price( $price, $product ) {

		if ( WC_Subscriptions_Product::is_subscription( $product ) ) {
			$price = WC_Subscriptions_Product::get_price_string( $product, array( 'price' => $price, 'tax_calculation' => WC()->cart->tax_display_cart ) );
		}

		return $price;
	}

	/* Deprecated */

	/**
	 * Returns the formatted subscription price string for an item
	 *
	 * @since 1.0
	 */
	public static function get_cart_item_price_html( $price_string, $cart_item ) {

		_deprecated_function( __CLASS__ . '::' . __FUNCTION__, '1.2' );

		return $price_string;
	}

	/**
	 * Returns either the total if prices include tax because this doesn't include tax, or the 
	 * subtotal if prices don't includes tax, because this doesn't include tax. 
	 *
	 * @return string formatted price
	 *
	 * @since 1.0
	 */
	public static function get_cart_contents_total( $cart_contents_total ) {

		_deprecated_function( __CLASS__ . '::' . __FUNCTION__, '1.2' );

		return $cart_contents_total;
	}

	/**
	 * Calculate totals for the sign-up fees in the cart, based on @see WC_Cart::calculate_totals()
	 *
	 * @since 1.0
	 */
	public static function calculate_sign_up_fee_totals() {
		_deprecated_function( __CLASS__ . '::' . __FUNCTION__, '1.2' );
	}

	/**
	 * Function to apply discounts to a product and get the discounted price (before tax is applied)
	 *
	 * @param mixed $values
	 * @param mixed $price
	 * @param bool $add_totals (default: false)
	 * @return float price
	 * @since 1.0
	 */
	public static function get_discounted_price( $values, $price, $add_totals = false ) {

		_deprecated_function( __CLASS__ . '::' . __FUNCTION__, '1.2' );

		return $price;
	}

	/**
	 * Function to apply product discounts after tax
	 *
	 * @param mixed $values
	 * @param mixed $price
	 * @since 1.0
	 */
	public static function apply_product_discounts_after_tax( $values, $price ) {
		_deprecated_function( __CLASS__ . '::' . __FUNCTION__, '1.2' );
	}

	/**
	 * Function to apply cart discounts after tax
	 *
	 * @since 1.0
	 */
	public static function apply_cart_discounts_after_tax() {
		_deprecated_function( __CLASS__ . '::' . __FUNCTION__, '1.2' );
	}

	/**
	 * Get tax row amounts with or without compound taxes includes
	 *
	 * @return float price
	 */
	public static function get_sign_up_taxes_total( $compound = true ) {
		_deprecated_function( __CLASS__ . '::' . __FUNCTION__, '1.2' );
		return 0;
	}

	public static function get_sign_up_fee_fields() {

		_deprecated_function( __CLASS__ . '::' . __FUNCTION__, '1.2' );

		return array(
			'cart_contents_sign_up_fee_total',
			'cart_contents_sign_up_fee_count',
			'sign_up_fee_total',
			'sign_up_fee_subtotal',
			'sign_up_fee_subtotal_ex_tax',
			'sign_up_fee_tax_total',
			'sign_up_fee_taxes',
			'sign_up_fee_discount_cart',
			'sign_up_fee_discount_total'
		);
	}


	/* Ambigious getters replaced with explict get_formatted_x functions */

	/**
	 * Returns the subtotal for a cart item including the subscription period and duration details
	 *
	 * @since 1.0
	 */
	public static function get_product_subtotal( $product_subtotal, $product ) {
		_deprecated_function( __CLASS__ . '::' . __FUNCTION__, '1.2', __CLASS__ .'::get_formatted_product_subtotal( $product_subtotal, $product )' );
		return self::get_formatted_product_subtotal( $product_subtotal, $product );
	}

	/**
	 * Returns a string with the cart discount and subscription period.
	 *
	 * @deprecated 1.2
	 * @since 1.0
	 */
	public static function get_discounts_before_tax( $discount, $cart ) {
		_deprecated_function( __CLASS__ . '::' . __FUNCTION__, '1.2', __CLASS__ .'::get_formatted_discounts_before_tax( $discount )' );
		return self::get_formatted_discounts_before_tax( $total );
	}

	/**
	 * Gets the order discount amount - these are applied after tax
	 *
	 * @deprecated 1.2
	 * @since 1.0
	 */
	public static function get_discounts_after_tax( $discount, $cart ) {
		_deprecated_function( __CLASS__ . '::' . __FUNCTION__, '1.2', __CLASS__ .'::get_formatted_discounts_after_tax( $discount )' );
		return self::get_formatted_discounts_after_tax( $total );
	}

	/**
	 * Includes the sign-up fee subtotal in the subtotal displayed in the cart.
	 *
	 * @deprecated 1.2
	 * @since 1.0
	 */
	public static function get_cart_subtotal( $cart_subtotal, $compound, $cart ) {
		_deprecated_function( __CLASS__ . '::' . __FUNCTION__, '1.2', __CLASS__ .'::get_formatted_cart_subtotal( $cart_subtotal, $compound, $cart )' );
		return self::get_formatted_cart_subtotal( $total, $compound, $cart );
	}

	/**
	 * Appends the cart subscription string to a cart total using the @see self::get_cart_subscription_string and then returns it. 
	 *
	 * @deprecated 1.2
	 * @since 1.0
	 */
	public static function get_total( $total ) {
		_deprecated_function( __CLASS__ . '::' . __FUNCTION__, '1.2', __CLASS__ .'::get_formatted_total( $total )' );
		return self::get_formatted_total( $total );
	}

	/**
	 * Appends the cart subscription string to a cart total using the @see self::get_cart_subscription_string and then returns it. 
	 *
	 * @deprecated 1.2
	 * @since 1.0
	 */
	public static function get_total_ex_tax( $total_ex_tax ) {
		_deprecated_function( __CLASS__ . '::' . __FUNCTION__, '1.2', __CLASS__ .'::get_formatted_total_ex_tax( $total_ex_tax )' );
		return self::get_formatted_total_ex_tax( $total_ex_tax );
	}


	/**
	 * Displays each cart tax in a subscription string and calculates the sign-up fee taxes (if any)
	 * to display in the string.
	 *
	 * @since 1.2
	 */
	public static function get_formatted_taxes( $formatted_taxes, $cart ) {
		_deprecated_function( __CLASS__ . '::' . __FUNCTION__, '1.4.9', __CLASS__ .'::get_recurring_tax_totals( $total_ex_tax )' );

		if ( self::cart_contains_subscription() ) {

			$recurring_taxes = self::get_recurring_taxes();

			foreach ( $formatted_taxes as $tax_id => $tax_amount ) {
				$formatted_taxes[ $tax_id ] = self::get_cart_subscription_string( $tax_amount, $recurring_taxes[ $tax_id ] );
			}

			// Add any recurring tax not already handled - when a subscription has a free trial and a sign-up fee, we get a recurring shipping tax with no initial shipping tax
			foreach ( $recurring_taxes as $tax_id => $tax_amount ) {
				if ( ! array_key_exists( $tax_id, $formatted_taxes ) ) {
					$formatted_taxes[ $tax_id ] = self::get_cart_subscription_string( '', $tax_amount );
				}
			}

		}

		return $formatted_taxes;
	}
}

WC_Subscriptions_Cart::init();
