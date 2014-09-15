<?php
/**
 * Subscriptions Coupon Class
 *
 * Mirrors a few functions in the WC_Cart class to handle subscription-specific discounts
 *
 * @package		WooCommerce Subscriptions
 * @subpackage	WC_Subscriptions_Coupon
 * @category	Class
 * @author		Max Rice
 * @since		1.2
 */
class WC_Subscriptions_Coupon {

	/** @var string error message for invalid subscription coupons */
	public static $coupon_error;

	/**
	 * Stores the coupons not applied to a given calculation (so they can be applied later)
	 *
	 * @since 1.3.5
	 */
	private static $removed_coupons = array();

	/**
	 * Set up the class, including it's hooks & filters, when the file is loaded.
	 *
	 * @since 1.2
	 **/
	public static function init() {

		// Add custom coupon types
		add_filter( 'woocommerce_coupon_discount_types', __CLASS__ . '::add_discount_types' );

		// Handle before tax discounts
		add_filter( 'woocommerce_get_discounted_price', __CLASS__ . '::apply_subscription_discount_before_tax', 10, 3 );

		// Handle after tax discounts
		add_action( 'woocommerce_product_discount_after_tax_sign_up_fee', __CLASS__ . '::apply_subscription_discount_after_tax', 10, 3 );
		add_action( 'woocommerce_product_discount_after_tax_sign_up_fee_percent', __CLASS__ . '::apply_subscription_discount_after_tax', 10, 3 );
		add_action( 'woocommerce_product_discount_after_tax_recurring_fee', __CLASS__ . '::apply_subscription_discount_after_tax', 10, 3 );
		add_action( 'woocommerce_product_discount_after_tax_recurring_percent', __CLASS__ . '::apply_subscription_discount_after_tax', 10, 3 );

		// Validate subscription coupons
		add_filter( 'woocommerce_coupon_is_valid', __CLASS__ . '::validate_subscription_coupon', 10, 2 );

		// Remove coupons which don't apply to certain cart calculations
		add_action( 'woocommerce_before_calculate_totals', __CLASS__ . '::remove_coupons', 10 );
		add_action( 'woocommerce_calculate_totals', __CLASS__ . '::restore_coupons', 10 );
	}

	/**
	 * Add discount types
	 *
	 * @since 1.2
	 */
	public static function add_discount_types( $discount_types ) {

		return array_merge(
			$discount_types,
			array(
				'sign_up_fee'         => __( 'Sign Up Fee Discount', 'woocommerce-subscriptions' ),
				'sign_up_fee_percent' => __( 'Sign Up Fee % Discount', 'woocommerce-subscriptions' ),
				'recurring_fee'       => __( 'Recurring Discount', 'woocommerce-subscriptions' ),
				'recurring_percent'   => __( 'Recurring % Discount', 'woocommerce-subscriptions' ),
			)
		);
	}

	/**
	 * Apply sign up fee or recurring fee discount before tax is calculated
	 *
	 * @since 1.2
	 */
	public static function apply_subscription_discount_before_tax( $original_price, $cart_item, $cart ) {
		global $woocommerce;

		$product_id = ( $cart_item['data']->is_type( array( 'subscription_variation' ) ) ) ? $cart_item['data']->variation_id : $cart_item['data']->id;

		if ( ! WC_Subscriptions_Product::is_subscription( $product_id ) ) {
			return $original_price;
		}

		$price = $original_price;

		$calculation_type = WC_Subscriptions_Cart::get_calculation_type();

		if ( ! empty( $cart->applied_coupons ) ) {
				foreach ( $cart->applied_coupons as $code ) {
					$coupon = new WC_Coupon( $code );

					if ( $coupon->apply_before_tax() && $coupon->is_valid() ) {

						$apply_sign_up_coupon = $apply_sign_up_percent_coupon = $apply_recurring_coupon = $apply_recurring_percent_coupon = $apply_initial_coupon = $apply_initial_percent_coupon = false;

						// Apply sign-up fee discounts to sign-up total calculations
						if ( 'sign_up_fee_total' == $calculation_type ) {

							$apply_sign_up_coupon         = ( 'sign_up_fee' == $coupon->type ) ? true : false;
							$apply_sign_up_percent_coupon = ( 'sign_up_fee_percent' == $coupon->type ) ? true : false;

						// Apply recurring fee discounts to recurring total calculations
						} elseif ( 'recurring_total' == $calculation_type ) {

							$apply_recurring_coupon         = ( 'recurring_fee' == $coupon->type ) ? true : false;
							$apply_recurring_percent_coupon = ( 'recurring_percent' == $coupon->type ) ? true : false;

						}

						if ( in_array( $calculation_type, array( 'combined_total', 'none' ) ) ) {

							if ( ! WC_Subscriptions_Cart::cart_contains_free_trial() ) { // Apply recurring discounts to initial total

								if ( 'recurring_fee' == $coupon->type ) {
									$apply_initial_coupon = true;
								}

								if ( 'recurring_percent' == $coupon->type ) {
									$apply_initial_percent_coupon = true;
								}
							}

							if ( WC_Subscriptions_Cart::get_cart_subscription_sign_up_fee() > 0 ) { // Apply sign-up discounts to initial total

								if ( 'sign_up_fee' == $coupon->type ) {
									$apply_initial_coupon = true;
								}

								if ( 'sign_up_fee_percent' == $coupon->type ) {
									$apply_initial_percent_coupon = true;
								}
							}

						}

						if ( $apply_sign_up_coupon || $apply_recurring_coupon || $apply_initial_coupon ) {

							$discount_amount = ( $price < $coupon->amount ) ? $price : $coupon->amount;

							// add to discount totals
							$woocommerce->cart->discount_cart = $woocommerce->cart->discount_cart + ( $discount_amount * $cart_item['quantity'] );
							WC_Subscriptions_Cart::increase_coupon_discount_amount( $coupon->code, $discount_amount * $cart_item['quantity'] );

							$price = $price - $discount_amount;

							if ( $price < 0 ) {
								$price = 0;
							}

						} elseif ( $apply_sign_up_percent_coupon || $apply_recurring_percent_coupon ) {

							$discount_amount = round( ( $cart_item['data']->get_price() / 100 ) * $coupon->amount, $woocommerce->cart->dp );

							$woocommerce->cart->discount_cart = $woocommerce->cart->discount_cart + ( $discount_amount * $cart_item['quantity'] );
							WC_Subscriptions_Cart::increase_coupon_discount_amount( $coupon->code, $discount_amount * $cart_item['quantity'] );

							$price = $price - $discount_amount;

						} elseif ( $apply_initial_percent_coupon ) { // Need to calculate percent from base price

							// We need to calculate the right amount to discount when the price is the combined sign-up fee and recurring amount
							if ( 'combined_total' == $calculation_type && ! WC_Subscriptions_Cart::cart_contains_free_trial() && isset( $woocommerce->cart->base_sign_up_fees[ $product_id ] ) && $woocommerce->cart->base_sign_up_fees[ $product_id ] > 0 ) {

								$base_total = $woocommerce->cart->base_sign_up_fees[ $product_id ] + $woocommerce->cart->base_recurring_prices[ $product_id ];

								if ( 'recurring_percent' == $coupon->type ) {
									$portion_of_total = $woocommerce->cart->base_recurring_prices[ $product_id ] / $base_total;
								}

								if ( 'sign_up_fee_percent' == $coupon->type ) {
									$portion_of_total = $woocommerce->cart->base_sign_up_fees[ $product_id ] / $base_total;
								}

								$amount_to_discount = WC_Subscriptions_Manager::get_amount_from_proportion( $base_total, $portion_of_total );

							} else {

								$amount_to_discount = $cart_item['data']->get_price();

							}

							$discount_amount = round( ( $amount_to_discount / 100 ) * $coupon->amount, $woocommerce->cart->dp );

							$woocommerce->cart->discount_cart = $woocommerce->cart->discount_cart + $discount_amount * $cart_item['quantity'];
							WC_Subscriptions_Cart::increase_coupon_discount_amount( $coupon->code, $discount_amount * $cart_item['quantity'] );

							$price = $price - $discount_amount;

						}

					}
				}
		}

		return $price;
	}

	/**
	 * Apply sign up fee or recurring fee discount after tax is calculated
	 *
	 * @since 1.2
	 * @version 1.3.6
	 */
	public static function apply_subscription_discount_after_tax( $coupon, $cart_item, $price ) {
		global $woocommerce;

		$calculation_type = WC_Subscriptions_Cart::get_calculation_type();

		if ( WC_Subscriptions_Product::is_subscription( $cart_item['product_id'] ) ) {

			if ( ! $coupon->apply_before_tax() && $coupon->is_valid() && self::is_subscription_discountable( $cart_item, $coupon ) ) {

				$apply_sign_up_coupon = $apply_sign_up_percent_coupon = $apply_recurring_coupon = $apply_recurring_percent_coupon = $apply_initial_coupon = $apply_initial_percent_coupon = false;

				if ( 'sign_up_fee_total' == $calculation_type ) {

					$apply_sign_up_coupon         = ( 'sign_up_fee' == $coupon->type ) ? true : false;
					$apply_sign_up_percent_coupon = ( 'sign_up_fee_percent' == $coupon->type ) ? true : false;

				} elseif ( 'recurring_total' == $calculation_type ) {

					$apply_recurring_coupon         = ( 'recurring_fee' == $coupon->type ) ? true : false;
					$apply_recurring_percent_coupon = ( 'recurring_percent' == $coupon->type ) ? true : false;

				}

				if ( in_array( $calculation_type, array( 'combined_total', 'none' ) ) ) {

					if ( ! WC_Subscriptions_Cart::cart_contains_free_trial() ) { // Apply recurring discounts to initial total

						if ( 'recurring_fee' == $coupon->type ) {
							$apply_initial_coupon = true;
						}

						if ( 'recurring_percent' == $coupon->type ) {
							$apply_initial_percent_coupon = true;
						}
					}

					if ( WC_Subscriptions_Cart::get_cart_subscription_sign_up_fee() > 0 ) { // Apply sign-up discounts to initial total

						if ( 'sign_up_fee' == $coupon->type ) {
							$apply_initial_coupon = true;
						}

						if ( 'sign_up_fee_percent' == $coupon->type ) {
							$apply_initial_percent_coupon = true;
						}
					}

				}

				// Deduct coupon amounts
				if ( $apply_sign_up_coupon || $apply_recurring_coupon || $apply_initial_coupon ) {

					if ( $price < $coupon->amount ) {
						$discount_amount = $price;
					} else {
						$discount_amount = $coupon->amount;
					}

					$woocommerce->cart->discount_total = $woocommerce->cart->discount_total + ( $discount_amount * $cart_item['quantity'] );
					WC_Subscriptions_Cart::increase_coupon_discount_amount( $coupon->code, $discount_amount * $cart_item['quantity'] );

				// Deduct coupon % discounts from relevant total
				} elseif ( $apply_sign_up_percent_coupon || $apply_recurring_percent_coupon ) {

					$woocommerce->cart->discount_total = $woocommerce->cart->discount_total + round( ( $price / 100 ) * $coupon->amount, $woocommerce->cart->dp );
					WC_Subscriptions_Cart::increase_coupon_discount_amount( $coupon->code, round( ( $price / 100 ) * $coupon->amount, $woocommerce->cart->dp ) );

				// Deduct coupon % discounts from combined total (we need to calculate percent from base price)
				} elseif ( $apply_initial_percent_coupon ) {

					$product_id = ( $cart_item['data']->is_type( array( 'subscription_variation' ) ) ) ? $cart_item['data']->variation_id : $cart_item['data']->id;

					// We need to calculate the right amount to discount when the price is the combined sign-up fee and recurring amount
					if ( 'combined_total' == $calculation_type && ! WC_Subscriptions_Cart::cart_contains_free_trial() && isset( $woocommerce->cart->base_sign_up_fees[ $product_id ] ) && $woocommerce->cart->base_sign_up_fees[ $product_id ] > 0 ) {

						$base_total = $woocommerce->cart->base_sign_up_fees[ $product_id ] + $woocommerce->cart->base_recurring_prices[ $product_id ];

						if ( 'recurring_percent' == $coupon->type ) {
							$portion_of_total = $woocommerce->cart->base_recurring_prices[ $product_id ] / $base_total;
						}

						if ( 'sign_up_fee_percent' == $coupon->type ) {
							$portion_of_total = $woocommerce->cart->base_sign_up_fees[ $product_id ] / $base_total;
						}

						$amount_to_discount = WC_Subscriptions_Manager::get_amount_from_proportion( $price, $portion_of_total );

					} else {

						$amount_to_discount = $price;

					}

					$discount_amount = round( ( $amount_to_discount / 100 ) * $coupon->amount, $woocommerce->cart->dp );

					$woocommerce->cart->discount_total = $woocommerce->cart->discount_total + $discount_amount;
					WC_Subscriptions_Cart::increase_coupon_discount_amount( $coupon->code, $discount_amount );

				}
			}
		}
	}

	/**
	 * Determine if the cart contains a discount code of a given coupon type.
	 *
	 * Used internally for checking if a WooCommerce discount coupon ('core') has been applied, or for if a specific
	 * subscription coupon type, like 'recurring_fee' or 'sign_up_fee', has been applied.
	 *
	 * @param string $coupon_type Any available coupon type or a special keyword referring to a class of coupons. Can be:
	 *  - 'any' to check for any type of discount
	 *  - 'core' for any core WooCommerce coupon
	 *  - 'recurring_fee' for the recurring amount subscription coupon
	 *  - 'sign_up_fee' for the sign-up fee subscription coupon
	 *
	 * @since 1.3.5
	 */
	public static function cart_contains_discount( $coupon_type = 'any' ) {
		global $woocommerce;

		$contains_discount = false;
		$core_coupons = array( 'fixed_product', 'percent_product', 'fixed_cart', 'percent' );

		if ( $woocommerce->cart->applied_coupons ) {

			foreach ( $woocommerce->cart->applied_coupons as $code ) {

				$coupon = new WC_Coupon( $code );

				if ( 'any' == $coupon_type || $coupon_type == $coupon->type || ( 'core' == $coupon_type && in_array( $coupon->type, $core_coupons ) ) ){
					$contains_discount = true;
					break;
				}

			}

		}

		return $contains_discount;
	}

	/**
	 * Check is a subscription coupon is valid before applying
	 *
	 * @since 1.2
	 */
	public static function validate_subscription_coupon( $valid, $coupon ) {

		self::$coupon_error = '';

		// ignore non-subscription coupons
		if ( ! in_array( $coupon->type, array( 'recurring_fee', 'sign_up_fee', 'recurring_percent', 'sign_up_fee_percent' ) ) ) {

			// but make sure there is actually something for the coupon to be applied to (i.e. not a free trial)
			if ( WC_Subscriptions_Cart::cart_contains_free_trial() && 0 == WC_Subscriptions_Cart::get_cart_subscription_sign_up_fee() && 1 == count( WC()->cart->cart_contents ) ) { // make sure there are no products in the cart which the coupon could be applied to - WC()->cart->get_cart_contents_count() returns the quantity of items in the cart, not the total number of unique items, we need to use WC()->cart->cart_contents for that.
				self::$coupon_error = __( 'Sorry, this coupon is only valid for an initial payment and the subscription already has a free trial.', 'woocommerce-subscriptions' );
			}

		} else {

			// prevent subscription coupons from being applied to renewal payments
			if ( WC_Subscriptions_Cart::cart_contains_subscription_renewal() ) {
				self::$coupon_error = __( 'Sorry, this coupon is only valid for new subscriptions.', 'woocommerce-subscriptions' );
			}

			// prevent subscription coupons from being applied to non-subscription products
			if ( ! WC_Subscriptions_Cart::cart_contains_subscription_renewal() && ! WC_Subscriptions_Cart::cart_contains_subscription() ) {
				self::$coupon_error = __( 'Sorry, this coupon is only valid for subscription products.', 'woocommerce-subscriptions' );
			}

			// prevent sign up fee coupons from being applied to subscriptions without a sign up fee
			if ( 0 == WC_Subscriptions_Cart::get_cart_subscription_sign_up_fee() && in_array( $coupon->type, array( 'sign_up_fee', 'sign_up_fee_percent' ) ) ) {
				self::$coupon_error = __( 'Sorry, this coupon is only valid for subscription products with a sign-up fee.', 'woocommerce-subscriptions' );
			}

		}

		if ( ! empty( self::$coupon_error ) ) {
			$valid = false;
			add_filter( 'woocommerce_coupon_error', __CLASS__ . '::add_coupon_error', 10 );
		}

		return $valid;
	}

	/**
	 * Returns a subscription coupon-specific error if validation failed
	 *
	 * @since 1.2
	 */
	public static function add_coupon_error( $error ) {

		if ( self::$coupon_error ) {
			return self::$coupon_error;
		} else {
			return $error;
		}

	}

	/**
	 * Checks a given product / coupon combination to determine if the subscription should be discounted
	 *
	 * @since 1.2
	 */
	private static function is_subscription_discountable( $cart_item, $coupon ) {

		$product_cats = wp_get_post_terms( $cart_item['product_id'], 'product_cat', array( 'fields' => 'ids' ) );

		$this_item_is_discounted = false;

		// Specific products get the discount
		if ( sizeof( $coupon->product_ids ) > 0 ) {

			if ( in_array( $cart_item['product_id'], $coupon->product_ids ) || in_array( $cart_item['variation_id'], $coupon->product_ids ) || in_array( $cart_item['data']->get_parent(), $coupon->product_ids ) ) {
				$this_item_is_discounted = true;
			}

			// Category discounts
		} elseif ( sizeof( $coupon->product_categories ) > 0 ) {

			if ( sizeof( array_intersect( $product_cats, $coupon->product_categories ) ) > 0 ) {
				$this_item_is_discounted = true;
			}

		} else {

			// No product ids - all items discounted
			$this_item_is_discounted = true;

		}

		// Specific product ID's excluded from the discount
		if ( sizeof( $coupon->exclude_product_ids ) > 0 ) {
			if ( in_array( $cart_item['product_id'], $coupon->exclude_product_ids ) || in_array( $cart_item['variation_id'], $coupon->exclude_product_ids ) || in_array( $cart_item['data']->get_parent(), $coupon->exclude_product_ids ) ) {
				$this_item_is_discounted = false;
			}
		}

		// Specific categories excluded from the discount
		if ( sizeof( $coupon->exclude_product_categories ) > 0 ) {
			if ( sizeof( array_intersect( $product_cats, $coupon->exclude_product_categories ) ) > 0 ) {
				$this_item_is_discounted = false;
			}
		}

		// Apply filter
		return apply_filters( 'woocommerce_item_is_discounted', $this_item_is_discounted, $cart_item, $before_tax = false );
	}

	/**
	 * Sets which coupons should be applied for this calculation.
	 *
	 * This function is hooked to "woocommerce_before_calculate_totals" so that WC will calculate a subscription
	 * product's total based on the total of it's price per period and sign up fee (if any).
	 *
	 * @since 1.3.5
	 */
	public static function remove_coupons( $cart ) {
		global $woocommerce;

		$calculation_type = WC_Subscriptions_Cart::get_calculation_type();

		// Only hook when totals are being calculated completely (on cart & checkout pages)
		if ( 'none' == $calculation_type || ! WC_Subscriptions_Cart::cart_contains_subscription() || ( ! is_checkout() && ! is_cart() && ! defined( 'WOOCOMMERCE_CHECKOUT' ) && ! defined( 'WOOCOMMERCE_CART' ) ) ) {
			return;
		}

		$applied_coupons = $cart->get_applied_coupons();

		// If we're calculating a sign-up fee or recurring fee only amount, remove irrelevant coupons
		if ( ! empty( $applied_coupons ) ) {

			// Keep track of which coupons, if any, need to be reapplied immediately
			$coupons_to_reapply = array();

			if ( in_array( $calculation_type, array( 'combined_total', 'sign_up_fee_total', 'recurring_total' ) ) ) {

				foreach ( $applied_coupons as $coupon_code ) {

					$coupon = new WC_Coupon( $coupon_code );

					if ( in_array( $coupon->type, array( 'recurring_fee', 'recurring_percent' ) ) ) {  // always apply coupons to their specific calculation case
						if ( 'recurring_total' == $calculation_type ) {
							$coupons_to_reapply[] = $coupon_code;
						} elseif ( 'combined_total' == $calculation_type && ! WC_Subscriptions_Cart::cart_contains_free_trial() ) { // sometimes apply recurring coupons to initial total
							$coupons_to_reapply[] = $coupon_code;
						} else {
							self::$removed_coupons[] = $coupon_code;
						}
					} elseif ( in_array( $calculation_type, array( 'combined_total', 'sign_up_fee_total', 'none' ) ) && ! in_array( $coupon->type, array( 'recurring_fee', 'recurring_percent' ) ) ) { // apply all coupons to the first payment
						$coupons_to_reapply[] = $coupon_code;
					} else {
						self::$removed_coupons[] = $coupon_code;
					}

				}

				// Now remove all coupons (WC only provides a function to remove all coupons)
				$cart->remove_coupons();

				// And re-apply those which relate to this calculation
				$woocommerce->cart->applied_coupons = $coupons_to_reapply;
			}
		}
	}

	/**
	 * Restores discount coupons which had been removed for special subscription calculations.
	 *
	 * @since 1.3.5
	 */
	public static function restore_coupons( $cart ) {
		global $woocommerce;

		if ( ! empty ( self::$removed_coupons ) ) {

			// Can't use $cart->add_dicount here as it calls calculate_totals()
			$woocommerce->cart->applied_coupons = array_merge( $woocommerce->cart->applied_coupons, self::$removed_coupons );

			self::$removed_coupons = array();
		}
	}

	/* Deprecated */

	/**
	 * Determines if cart contains a recurring fee discount code
	 *
	 * Does not check if the code is valid, etc
	 *
	 * @since 1.2
	 */
	public static function cart_contains_recurring_discount() {

		_deprecated_function( __CLASS__ . '::' . __FUNCTION__, '1.3.5', __CLASS__ .'::cart_contains_discount( "recurring_fee" )' );

		return self::cart_contains_discount( 'recurring_fee' );
	}

	/**
	 * Determines if cart contains a sign up fee discount code
	 *
	 * Does not check if the code is valid, etc
	 *
	 * @since 1.2
	 */
	public static function cart_contains_sign_up_discount() {

		_deprecated_function( __CLASS__ . '::' . __FUNCTION__, '1.3.5', __CLASS__ .'::cart_contains_discount( "sign_up_fee" )' );

		return self::cart_contains_discount( 'sign_up_fee' );
	}
}

WC_Subscriptions_Coupon::init();
