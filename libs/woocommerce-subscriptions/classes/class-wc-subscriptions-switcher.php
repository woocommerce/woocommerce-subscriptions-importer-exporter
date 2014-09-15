<?php
/**
 * A class to make it possible to switch between different subscriptions (i.e. upgrade/downgrade a subscription)
 *
 * @package		WooCommerce Subscriptions
 * @subpackage	WC_Subscriptions_Switcher
 * @category	Class
 * @author		Brent Shepherd
 * @since		1.4
 */
class WC_Subscriptions_Switcher {

	/**
	 * Bootstraps the class and hooks required actions & filters.
	 *
	 * @since 1.4
	 */
	public static function init(){

		// Check if the current request is for switching a subscription and if so, start he switching process
		add_filter( 'template_redirect', __CLASS__ . '::subscription_switch_handler', 100 );

		// Add the settings to control whether Switching is enabled and how it will behave
		add_filter( 'woocommerce_subscription_settings', __CLASS__ . '::add_settings' );

		// Add the "Switch" button to the My Subscriptions table
		add_filter( 'woocommerce_my_account_my_subscriptions_actions', __CLASS__ . '::add_switch_button', 10, 2 );

		// Add a 'new-subscription' handler to the WC_Subscriptions_Manager::can_subscription_be_changed_to() function
		add_filter( 'woocommerce_can_subscription_be_changed_to', __CLASS__ . '::can_subscription_be_changed_to', 10, 3 );

		// Make sure "switched" subscriptions can't be "cancelled"
		add_filter( 'woocommerce_subscription_can_be_changed_to_cancelled', __CLASS__ . '::can_subscription_be_cancelled', 10, 2 );

		// When creating an order, add meta if it's for switching a subscription
		add_action( 'woocommerce_checkout_update_order_meta', __CLASS__ . '::add_order_meta', 10, 2 );

		// Make sure no trial period is set
		add_action( 'woocommerce_add_order_item_meta', __CLASS__ . '::fix_order_item_meta', 11, 2 );

		// After an order that was placed to switch a subscription is processed/completed, make sure the subscription switch is complete
		add_action( 'woocommerce_payment_complete', __CLASS__ . '::maybe_complete_switch', 10, 1 );
		add_action( 'woocommerce_order_status_processing', __CLASS__ . '::maybe_complete_switch', 10, 1 );
		add_action( 'woocommerce_order_status_completed', __CLASS__ . '::maybe_complete_switch', 10, 1 );

		// Add a renewal orders section to the Related Orders meta box
		add_action( 'woocommerce_subscriptions_related_orders_meta_box', __CLASS__ . '::switch_order_meta_box_section' );

		// i18nify the 'switched' subscription status
		add_filter( 'woocommerce_subscriptions_custom_status_string', __CLASS__ . '::add_switched_status_string' );

		// Don't allow switching to the same product
		add_filter( 'woocommerce_add_to_cart_validation', __CLASS__ . '::validate_switch_request', 10, 4 );

		// Record subscription switching in the cart
		add_action( 'woocommerce_add_cart_item_data', __CLASS__ . '::set_switch_details_in_cart', 10, 3 );

		// Make sure the 'switch_subscription' cart item data persists
		add_action( 'woocommerce_get_cart_item_from_session', __CLASS__ . '::get_cart_from_session', 10, 3 );

		// Set totals for subscription switch orders (needs to be hooked just before WC_Subscriptions_Cart::calculate_subscription_totals())
		add_filter( 'woocommerce_calculated_total', __CLASS__ . '::maybe_set_apporitioned_totals', 99, 1 );

		// Make sure the first payment date a new subscription is correct
		add_filter( 'woocommerce_subscriptions_calculated_next_payment_date', __CLASS__ . '::calculate_first_payment_date', 20, 4 );

		// Display more accurate cart subscription price strings
		add_filter( 'woocommerce_cart_subscription_string_details', __CLASS__ . '::customise_cart_subscription_string_details', 11, 4 );
		add_filter( 'woocommerce_cart_total_ex_tax', __CLASS__ . '::customise_subscription_price_string', 12 );
		add_filter( 'woocommerce_cart_total', __CLASS__ . '::customise_subscription_price_string', 12 );

		// Don't display free trials when switching a subscription, because no free trials are provided
		add_filter( 'woocommerce_subscriptions_product_price_string_inclusions', __CLASS__ . '::customise_product_string_inclusions', 12, 2 );

		// Allow switching between variations on a limited subscription
		add_filter( 'woocommerce_subscription_is_purchasable', __CLASS__ . '::is_purchasable', 12, 2 );
		add_filter( 'woocommerce_subscription_variation_is_purchasable', __CLASS__ . '::is_purchasable', 12, 2 );

		// Autocomplete subscription switch orders
		add_action( 'woocommerce_payment_complete_order_status', __CLASS__ . '::subscription_switch_autocomplete', 10, 2 );

		// Don't carry switch meta data to renewal orders
		add_filter( 'woocommerce_subscriptions_renewal_order_meta_query', __CLASS__ . '::remove_renewal_order_meta_query', 10 );

		// Make sure the switch process persists when having to choose product addons
		add_action( 'addons_add_to_cart_url', __CLASS__ . '::addons_add_to_cart_url', 10 );
	}

	/**
	 * Handles the subscription upgrade/downgrade process.
	 *
	 * @since 1.4
	 */
	public static function subscription_switch_handler() {
		global $woocommerce, $post;

		// If the current user doesn't own the subscription, remove the query arg from the URL
		if ( isset ( $_GET['switch-subscription'] ) ) {

			// Visiting a switch link for someone elses subscription
			if ( ! WC_Subscriptions_Manager::user_owns_subscription( $_GET['switch-subscription'] ) ) {

				wp_redirect( remove_query_arg( 'switch-subscription' ) );
				exit();

			} else {

				if ( isset( $_GET['auto-switch'] ) ) {
					$switch_message = __( 'You have an active subscription to this product. Choosing a new subscription will replace your existing subscription.', 'woocommerce-subscriptions' );
				} else {
					$switch_message = __( 'Choose a new subscription.', 'woocommerce-subscriptions' );
				}

				WC_Subscriptions::add_notice( $switch_message, 'notice' );

			}

		} elseif ( ( is_cart() || is_checkout() ) && ! is_order_received_page() && false !== ( $cart_item = self::cart_contains_subscription_switch() ) ) {

			if ( ! WC_Subscriptions_Manager::user_owns_subscription( $cart_item['subscription_key'] ) ) {

				WC_Subscriptions::add_notice( __( 'Your cart contained an invalid subscription switch request and has been emptied.', 'woocommerce-subscriptions' ), 'error' );
				$woocommerce->cart->empty_cart( true );
				wp_redirect( $woocommerce->cart->get_cart_url() );
				exit();

			} else {

				WC_Subscriptions::add_notice( __( 'Once sign up is complete, this will replace your existing subscription.', 'woocommerce-subscriptions' ), 'notice' );

			}

		} elseif ( is_product() && $product = get_product( $post ) ) { // Automatically initiate the switch process for limited variable subscriptions

			if ( ( $product->is_type( array( 'variable-subscription', 'subscription_variation' ) ) || 0 !== $product->post->post_parent ) && WC_Subscriptions_Product::is_subscription( $product->id ) && 'no' != $product->limit_subscriptions ) {

				// Check if the user has an active subscription for this product, and if so, initiate the switch process
				$subscriptions = WC_Subscriptions_Manager::get_users_subscriptions();

				foreach ( $subscriptions as $subscription_key => $subscription ) {
					if ( $subscription['product_id'] == $product->id && 'active' == $subscription['status'] ) {
						wp_redirect( add_query_arg( 'auto-switch', 'true', self::get_switch_link( $subscription_key ) ) );
						exit;
					}
				}
			}
		}
	}

	/**
	 * Add Switch settings to the Subscription's settings page.
	 *
	 * @since 1.4
	 */
	public static function add_settings( $settings ) {

		array_splice( $settings, 12, 0, array(

			array(
				'name'     => __( 'Switching', 'woocommerce-subscriptions' ),
				'type'     => 'title',
				'desc'     => sprintf( __( 'Allow subscribers to switch (upgrade or downgrade) between different subscriptions. %sLearn more%s.', 'woocommerce-subscriptions' ), '<a href="' . esc_url( 'http://docs.woothemes.com/document/subscriptions/switching-guide/' ) . '">', '</a>' ),
				'id'       => WC_Subscriptions_Admin::$option_prefix . '_switch_settings'
			),

			array(
				'name'    => __( 'Allow Switching', 'woocommerce-subscriptions' ),
				'desc'    => __( 'Allow subscribers to switch between subscriptions combined in a grouped product, different variations of a Variable subscription or don\'t allow switching.', 'woocommerce-subscriptions' ),
				'tip'     => '',
				'id'      => WC_Subscriptions_Admin::$option_prefix . '_allow_switching',
				'css'     => 'min-width:150px;',
				'default' => 'no',
				'type'    => 'select',
				'options' => array(
					'no'               => __( 'Never', 'woocommerce-subscriptions' ),
					'variable'         => __( 'Between Subscription Variations', 'woocommerce-subscriptions' ),
					'grouped'          => __( 'Between Grouped Subscriptions', 'woocommerce-subscriptions' ),
					'variable_grouped' => __( 'Between Both Variations & Grouped Subscriptions', 'woocommerce-subscriptions' ),
				),
				'desc_tip' => true,
			),

			array(
				'name'    => __( 'Prorate Recurring Payment', 'woocommerce-subscriptions' ),
				'desc'    => __( 'When switching to a subscription with a different recurring payment or billing period, should the price paid for the existing billing period be prorated when switching to the new subscription?', 'woocommerce-subscriptions' ),
				'tip'     => '',
				'id'      => WC_Subscriptions_Admin::$option_prefix . '_apportion_recurring_price',
				'css'     => 'min-width:150px;',
				'default' => 'no',
				'type'    => 'select',
				'options' => array(
					'no'              => __( 'Never', 'woocommerce-subscriptions' ),
					'virtual-upgrade' => __( 'For Upgrades of Virtual Subscription Products Only', 'woocommerce-subscriptions' ),
					'yes-upgrade'     => __( 'For Upgrades of All Subscription Products', 'woocommerce-subscriptions' ),
					'virtual'         => __( 'For Upgrades & Downgrades of Virtual Subscription Products Only', 'woocommerce-subscriptions' ),
					'yes'             => __( 'For Upgrades & Downgrades of All Subscription Products', 'woocommerce-subscriptions' ),
				),
				'desc_tip' => true,
			),

			array(
				'name'    => __( 'Prorate Sign up Fee', 'woocommerce-subscriptions' ),
				'desc'    => __( 'When switching to a subscription with a sign up fee, you can require the customer pay only the gap between the existing subscription\'s sign up fee and the new subscription\'s sign up fee (if any).', 'woocommerce-subscriptions' ),
				'tip'     => '',
				'id'      => WC_Subscriptions_Admin::$option_prefix . '_apportion_sign_up_fee',
				'css'     => 'min-width:150px;',
				'default' => 'no',
				'type'    => 'select',
				'options' => array(
					'no'                 => __( 'Never (do not charge a sign up fee)', 'woocommerce-subscriptions' ),
					'full'               => __( 'Never (charge the full sign up fee)', 'woocommerce-subscriptions' ),
					'yes'                => __( 'Always', 'woocommerce-subscriptions' ),
				),
				'desc_tip' => true,
			),

			array(
				'name'    => __( 'Prorate Subscription Length', 'woocommerce-subscriptions' ),
				'desc'    => __( 'When switching to a subscription with a length, you can take into account the payments already completed by the customer when determining how many payments the subscriber needs to make for the new subscription.', 'woocommerce-subscriptions' ),
				'tip'     => '',
				'id'      => WC_Subscriptions_Admin::$option_prefix . '_apportion_length',
				'css'     => 'min-width:150px;',
				'default' => 'no',
				'type'    => 'select',
				'options' => array(
					'no'                 => __( 'Never', 'woocommerce-subscriptions' ),
					'virtual'            => __( 'For Virtual Subscription Products Only', 'woocommerce-subscriptions' ),
					'yes'                => __( 'For All Subscription Products', 'woocommerce-subscriptions' ),
				),
				'desc_tip' => true,
			),

			array(
				'name'     => __( 'Switch Button Text', 'woocommerce-subscriptions' ),
				'desc'     => __( 'Customise the text displayed on the button next to the subscription on the subscriber\'s account page. The default is "Switch Subscription", but you may wish to change this to "Upgrade" or "Change Subscription".', 'woocommerce-subscriptions' ),
				'tip'      => '',
				'id'       => WC_Subscriptions_Admin::$option_prefix . '_switch_button_text',
				'css'      => 'min-width:150px;',
				'default'  => __( 'Switch Subscription', 'woocommerce-subscriptions' ),
				'type'     => 'text',
				'desc_tip' => true,
			),

			array( 'type' => 'sectionend', 'id' => WC_Subscriptions_Admin::$option_prefix . '_switch_settings' ),
		));

		return $settings;
	}

	/**
	 * Adds a "Switch" button to the "My Subscriptions" table for those subscriptions can be upgraded/downgraded.
	 *
	 * @param array $all_actions The $subscription_key => $actions array with all actions that will be displayed for a subscription on the "My Subscriptions" table
	 * @param array $subscriptions All of a given users subscriptions that will be displayed on the "My Subscriptions" table
	 * @since 1.4
	 */
	public static function add_switch_button( $all_actions, $subscriptions  ) {

		$user_id = get_current_user_id();

		foreach ( $all_actions as $subscription_key => $actions ) {

			if ( WC_Subscriptions_Manager::can_subscription_be_changed_to( 'new-subscription', $subscription_key, $user_id ) ) {
				$all_actions[ $subscription_key ] = array( 'switch' => array(
					'url'  => self::get_switch_link( $subscription_key ),
					'name' => get_option( WC_Subscriptions_Admin::$option_prefix . '_switch_button_text', __( 'Switch Subscription', 'woocommerce-subscriptions' ) )
					)
				) + $all_actions[ $subscription_key ];

			}
		}

		return $all_actions;
	}

	/**
	 * The link for switching a subscription - the product page for variable subscriptions, or grouped product page for grouped subscriptions.
	 *
	 * @param string $subscription_key A subscription key of the form created by @see self::get_subscription_key()
	 * @since 1.4
	 */
	public static function get_switch_link( $subscription_key ) {

		$subscription = WC_Subscriptions_Manager::get_subscription( $subscription_key );

		$product = get_product( $subscription['product_id'] );

		// Grouped product
		if ( 0 !== $product->post->post_parent ) {
			$switch_url = get_permalink( $product->post->post_parent );
		} else {
			$switch_url = get_permalink( $subscription['product_id'] );
		}

		$switch_url = add_query_arg( array( 'switch-subscription' => $subscription_key ), $switch_url );

		return $switch_url;
	}

	/**
	 * If the order being generated is for switching a subscription, keep a record of some of the switch
	 * routines meta against the order.
	 *
	 * @since 1.4
	 */
	public static function add_order_meta( $order_id, $posted ) {
		global $woocommerce;

		if ( $switch_details = self::cart_contains_subscription_switch() ) {

			$subscription = WC_Subscriptions_Manager::get_subscription( $switch_details['subscription_key'] );

			update_post_meta( $order_id, '_switched_subscription_key', $switch_details['subscription_key'] );
			update_post_meta( $subscription['order_id'], '_switched_subscription_new_order', $order_id );

			// Record the next payment date to account for downgrades and prepaid term
			foreach ( $woocommerce->cart->get_cart() as $cart_item_key => $cart_item ) {
				if ( isset( $cart_item['subscription_switch']['first_payment_timestamp'] ) ) {
					update_post_meta( $order_id, '_switched_subscription_first_payment_timestamp', $cart_item['subscription_switch']['first_payment_timestamp'] );
					break;
				}
			}
		}
	}

	/**
	 * We use a trial period to make sure totals are calculated correctly (i.e. order total does not include any recurring amounts)
	 * but we don't want switched subscriptions to actually have a trial period, so reset the value on the order after checkout.
	 *
	 * @since 1.4
	 */
	public static function fix_order_item_meta( $item_id, $values ) {

		if ( false !== self::cart_contains_subscription_switch() && WC_Subscriptions_Product::is_subscription( $values['product_id'] ) ) {
			woocommerce_update_order_item_meta( $item_id, '_subscription_trial_length', 0 );
			woocommerce_update_order_item_meta( $item_id, '_subscription_trial_expiry_date', 0 );
		}

	}

	/**
	 * After payment is completed on an order for switching a subscription, complete the switch.
	 *
	 * @param WC_Order|int $order A WC_Order object or ID of a WC_Order order.
	 * @since 1.4
	 */
	public static function maybe_complete_switch( $order_id ) {

		$original_subscription_key = get_post_meta( $order_id, '_switched_subscription_key', true );

		if ( ! empty( $original_subscription_key ) ) {

			$original_subscription = WC_Subscriptions_Manager::get_subscription( $original_subscription_key );
			$original_order        = new WC_Order( $original_subscription['order_id'] );

			if ( 'switched' !== $original_subscription['status'] ) {

				// Don't send "Cancelled Subscription" email to admins
				remove_action( 'cancelled_subscription', 'WC_Subscriptions_Email::send_subscription_email', 10, 2 );

				// Cancel the existing subscription
				WC_Subscriptions_Manager::cancel_subscription( $original_order->customer_user, $original_subscription_key );

				add_action( 'cancelled_subscription', 'WC_Subscriptions_Email::send_subscription_email', 10, 2 );

				// Now set a custom status of "switched"
				$original_subscription['status'] = 'switched';
				WC_Subscriptions_Manager::update_subscription( $original_subscription_key, $original_subscription );

				wc_unschedule_action( 'scheduled_subscription_end_of_prepaid_term', array( 'user_id' => (int)$original_order->customer_user, 'subscription_key' => $original_subscription_key ) );
			}
		}
	}

	/**
	 * Add a 'new-subscription' handler to the WC_Subscriptions_Manager::can_subscription_be_changed_to() function.
	 *
	 * For the subscription to be switchable, switching must be enabled, and the subscription must: 
	 * - be active or on-hold
	 * - be a variable subscription or part of a grouped product (at the time the check is made, not at the time the subscription was purcahsed)
	 * - be using manual renwals or use a payment method which supports cancellation
	 *
	 * @param bool $subscription_can_be_changed Flag of whether the subscription can be changed to
	 * @param string $new_status_or_meta The status or meta data you want to change th subscription to. Can be 'active', 'on-hold', 'cancelled', 'expired', 'trash', 'deleted', 'failed', 'AMQPChannel to the 'woocommerce_can_subscription_be_changed_to' filter.
	 * @param object $args Set of values used in @see WC_Subscriptions_Manager::can_subscription_be_changed_to() for determining if a subscription can be changed
	 * @since 1.4
	 */
	public static function can_subscription_be_changed_to( $subscription_can_be_changed, $new_status_or_meta, $args ) {

		if ( 'new-subscription' === $new_status_or_meta ) {

			$product = get_product( $args->subscription['product_id'] );

			if ( empty ( $product ) ) {

				$is_product_switchable = false;

			} else {

				$allow_switching = get_option( WC_Subscriptions_Admin::$option_prefix . '_allow_switching', 'no' );

				switch ( $allow_switching ) {
					case 'variable' :
						$is_product_switchable = ( $product->is_type( 'variable-subscription' ) ) ? true : false;
						break;
					case 'grouped' :
						$is_product_switchable = ( 0 !== $product->post->post_parent ) ? true : false;
						break;
					case 'variable_grouped' :
						$is_product_switchable = ( $product->is_type( 'variable-subscription' ) || 0 !== $product->post->post_parent ) ? true : false;
						break;
					case 'no' :
					default:
						$is_product_switchable = false;
						break;
				}
			}

			if ( $is_product_switchable && in_array( $args->subscription['status'], array( 'active', 'on-hold' ) ) && ( $args->order_uses_manual_payments || $args->payment_gateway->supports( 'subscription_cancellation' ) ) ) {
				$subscription_can_be_changed = true;
			} else {
				$subscription_can_be_changed = false;
			}
		}

		return $subscription_can_be_changed;
	}

	/**
	 * Don't allow switched subscriptions to be cancelled.
	 *
	 * @param bool $subscription_can_be_changed
	 * @param array $subscription A subscription of the form created by @see WC_Subscriptions_Manager::get_subscription()
	 * @since 1.4
	 */
	public static function can_subscription_be_cancelled( $subscription_can_be_changed, $subscription ) {

		if ( 'switched' == $subscription['status'] ) {
			$subscription_can_be_changed = false;
		}

		return $subscription_can_be_changed;
	}

	/**
	 * If the subscription purchased in an order has since been switched, include a link to the order placed to switch the subscription
	 * in the "Related Orders" meta box (displayed on the Edit Order screen).
	 *
	 * @param WC_Order $order The current order.
	 * @since 1.4
	 */
	public static function switch_order_meta_box_section( $order ) {

		$original_subscription_key = get_post_meta( $order->id, '_switched_subscription_key', true );

		// Did the order switch a subscription?
		if ( ! empty( $original_subscription_key ) ) {

			$original_subscription = WC_Subscriptions_Manager::get_subscription( $original_subscription_key );
			$original_order        = new WC_Order( $original_subscription['order_id'] );

			printf(
				'<p>%1$s <a href="%2$s">%3$s</a></p>',
				__( 'Subscription Switched from Order:', 'woocommerce-subscriptions' ),
				get_edit_post_link( $original_subscription['order_id'] ),
				$original_order->get_order_number()
			);
		}

		// Has the order's subscription been switched?
		$new_order_id = get_post_meta( $order->id, '_switched_subscription_new_order', true );

		if ( ! empty( $new_order_id ) ) {

			$new_order = new WC_Order( $new_order_id );

			printf(
				'<p>%1$s <a href="%2$s">%3$s</a></p>',
				__( 'Subscription Switched in Order:', 'woocommerce-subscriptions' ),
				get_edit_post_link( $new_order_id ),
				$new_order->get_order_number()
			);
		}

	}

	/**
	 * Check if the cart includes a request to switch a subscription.
	 *
	 * @return bool Returns true if any item in the cart is a subscription switch request, otherwise, false.
	 * @since 1.4
	 */
	public static function cart_contains_subscription_switch() {
		global $woocommerce;

		$cart_contains_subscription_switch = false;

		if ( isset( $woocommerce->cart ) ) {
			foreach ( $woocommerce->cart->get_cart() as $cart_item_key => $cart_item ) {
				if ( isset( $cart_item['subscription_switch'] ) ) {
					$cart_contains_subscription_switch = $cart_item['subscription_switch'];
					break;
				}
			}
		}

		return $cart_contains_subscription_switch;
	}

	/**
	 * Check if a given order was created to switch a subscription.
	 *
	 * @param WC_Order $order An order to check.
	 * @return bool Returns true if the order switched a subscription, otherwise, false.
	 * @since 1.4
	 */
	public static function order_contains_subscription_switch( $order_id ) {
		global $woocommerce;

		$original_subscription_key = get_post_meta( $order_id, '_switched_subscription_key', true );

		$order_contains_subscription_switch = ( ! empty( $original_subscription_key ) ) ? true : false;

		return $order_contains_subscription_switch;
	}

	/**
	 * Add an i18n'ified string for the "switched" subscription status.
	 *
	 * @since 1.4
	 */
	public static function add_switched_status_string( $status_string ) {

		if ( 'switched' === strtolower( $status_string ) ) {
			$status_string = __( 'Switched', 'woocommerce-subscriptions' );
		}

		return $status_string;
	}

	/**
	 * When a product is added to the cart, check if it is being added to switch a subscription and if so,
	 * make sure it's valid (i.e. not the same subscription).
	 *
	 * @since 1.4
	 */
	public static function validate_switch_request( $is_valid, $product_id, $quantity, $variation_id = '' ){
		global $woocommerce;

		if ( ! isset ( $_GET['switch-subscription'] ) ) {
			return $is_valid;
		}

		$subscription = WC_Subscriptions_Manager::get_subscription( $_GET['switch-subscription'] );

		// Check if the chosen variation's attributes are different to the existing subscription's attributes (to support switching between a "catch all" variation)
		$original_order = new WC_Order( $subscription['order_id'] );
		$product_id     = $subscription['product_id'];
		$item           = WC_Subscriptions_Order::get_item_by_product_id( $original_order, $product_id );

		$identical_attributes = true;

		foreach ( $_POST as $key => $value ) {
			if ( false !== strpos( $key, 'attribute_' ) && ! empty( $item[ str_replace( 'attribute_', '', $key ) ] ) && $item[ str_replace( 'attribute_', '', $key ) ] != $value ) {
				$identical_attributes = false;
				break;
			}
		}

		if ( $product_id == $subscription['product_id'] && ( empty( $variation_id ) || ( $variation_id == $subscription['variation_id'] && true == $identical_attributes ) ) ) {
			WC_Subscriptions::add_notice( __( 'You can not switch to the same subscription.', 'woocommerce-subscriptions' ), 'error' );
			$is_valid = false;
		}

		return $is_valid;
	}

	/**
	 * When a subscription switch is added to the cart, store a record of pertinent meta about the switch.
	 *
	 * @since 1.4
	 */
	public static function set_switch_details_in_cart( $cart_item_data, $product_id, $variation_id ) {
		global $woocommerce;

		if ( ! isset ( $_GET['switch-subscription'] ) ) {
			return $cart_item_data;
		}

		$subscription = WC_Subscriptions_Manager::get_subscription( $_GET['switch-subscription'] );

		// Requesting a switch for someone elses subscription
		if ( ! WC_Subscriptions_Manager::user_owns_subscription( $_GET['switch-subscription'] ) ) {
			WC_Subscriptions::add_notice( __( 'You can not switch this subscription. It appears you do not own the subscription.', 'woocommerce-subscriptions' ), 'error' );
			$woocommerce->cart->empty_cart( true );
			wp_redirect( get_permalink( $subscription['product_id'] ) );
			exit();
		}

		// Else it's a valid switch
		$product = get_product( $subscription['product_id'] );

		$child_products = ( 0 !== $product->post->post_parent ) ? get_product( $product->post->post_parent )->get_children() : array();

		if ( $product_id != $subscription['product_id'] && ! in_array( $subscription['product_id'], $child_products ) ) {
			return $cart_item_data;
		}

		$next_payment_timestamp = WC_Subscriptions_Manager::get_next_payment_date( $_GET['switch-subscription'], get_current_user_id(), 'timestamp' );

		// If there are no more payments due on the subscription, because we're in the last billing period, we need to use the subscription's expiration date, not next payment date
		if ( false == $next_payment_timestamp && WC_Subscriptions_Manager::get_subscriptions_completed_payment_count( $_GET['switch-subscription'] ) >= WC_Subscriptions_Order::get_subscription_length( $subscription['order_id'], $subscription['product_id'] ) ) {
			$next_payment_timestamp = WC_Subscriptions_Manager::get_subscription_expiration_date( $_GET['switch-subscription'], get_current_user_id(), 'timestamp' );
		}

		$cart_item_data['subscription_switch'] = array(
			'subscription_key'        => $_GET['switch-subscription'],
			'next_payment_timestamp'  => $next_payment_timestamp,
			'upgraded_or_downgraded'  => '',
		);

		return $cart_item_data;
	}

	/**
	 * Get the recurring amounts values from the session
	 *
	 * @since 1.4
	 */
	public static function get_cart_from_session( $cart_item_data, $cart_item, $key ) {
		global $woocommerce;

		if ( isset( $cart_item['subscription_switch'] ) ) {
			$cart_item_data['subscription_switch'] = $cart_item['subscription_switch'];
		}

		return $cart_item_data;
	}

	/**
	 * Set the subscription prices to be used in calculating totals by @see WC_Subscriptions_Cart::calculate_subscription_totals()
	 *
	 * @since 1.4
	 */
	public static function maybe_set_apporitioned_totals( $total ) {
		global $woocommerce;

		if ( false === self::cart_contains_subscription_switch() ) {
			return $total;
		}

		// Maybe charge an initial amount to account for upgrading from a cheaper subscription
		$apportion_recurring_price = get_option( WC_Subscriptions_Admin::$option_prefix . '_apportion_recurring_price', 'no' );
		$apportion_sign_up_fee     = get_option( WC_Subscriptions_Admin::$option_prefix . '_apportion_sign_up_fee', 'no' );
		$apportion_length          = get_option( WC_Subscriptions_Admin::$option_prefix . '_apportion_length', 'no' );

		foreach ( $woocommerce->cart->get_cart() as $cart_item_key => $cart_item ) {

			if ( ! isset( $cart_item['subscription_switch']['subscription_key'] ) ) {
				continue;
			}

			$old_subscription_key = $cart_item['subscription_switch']['subscription_key'];

			$item_data = $cart_item['data'];

			$product_id = empty( $cart_item['variation_id'] ) ? $cart_item['product_id'] : $cart_item['variation_id'];

			$product = get_product( $product_id );

			// Set the date on which the first payment for the new subscription should be charged
			$woocommerce->cart->cart_contents[ $cart_item_key ]['subscription_switch']['first_payment_timestamp'] = $cart_item['subscription_switch']['next_payment_timestamp'];

			$is_virtual_product = ( 'no' != $item_data->virtual ) ? true : false;

			// Add any extra sign up fees required to switch to the new subscription
			if ( 'yes' == $apportion_sign_up_fee ) {

				$old_subscription = WC_Subscriptions_Manager::get_subscription( $old_subscription_key );

				$sign_up_fee_due  = $product->subscription_sign_up_fee;

				if ( self::order_contains_subscription_switch( $old_subscription['order_id'] ) ) {
					$old_order = new WC_Order( $old_subscription['order_id'] );
					$sign_up_fee_paid = $old_order->get_total();
				} else {
					$sign_up_fee_paid = WC_Subscriptions_Order::get_item_sign_up_fee( $old_subscription['order_id'], $old_subscription['product_id'] );
				}

				$woocommerce->cart->cart_contents[ $cart_item_key ]['data']->subscription_sign_up_fee = max( $sign_up_fee_due - $sign_up_fee_paid, 0 );

			} elseif ( 'no' == $apportion_sign_up_fee ) { // $0 the initial sign-up fee

				$woocommerce->cart->cart_contents[ $cart_item_key ]['data']->subscription_sign_up_fee = 0;

			}

			// Now lets see if we should add a prorated amount to the sign-up fee (for upgrades) or extend the next payment date (for downgrades)
			if ( in_array( $apportion_recurring_price, array( 'yes', 'yes-upgrade' ) ) || ( in_array( $apportion_recurring_price, array( 'virtual', 'virtual-upgrade' ) ) && $is_virtual_product ) ) {

				// Get the current subscription
				$old_subscription = isset( $old_subscription ) ? $old_subscription : WC_Subscriptions_Manager::get_subscription( $old_subscription_key );

				// Get the current subscription's original order
				$old_order = isset( $old_order ) ? $old_order : new WC_Order( $old_subscription['order_id'] );

				// Get the current subscription's last payment date
				$last_payment_timestamp  = WC_Subscriptions_Manager::get_last_payment_date( $old_subscription_key, get_current_user_id(), 'timestamp' );
				$days_since_last_payment = floor( ( gmdate( 'U' ) - $last_payment_timestamp ) / ( 60 * 60 * 24 ) );

				// Get the current subscription's next payment date
				$next_payment_timestamp  = $cart_item['subscription_switch']['next_payment_timestamp'];
				$days_until_next_payment = ceil( ( $next_payment_timestamp - gmdate( 'U' ) ) / ( 60 * 60 * 24 ) );

				// Find the number of days between the two
				$days_in_old_cycle = $days_until_next_payment + $days_since_last_payment;

				// Find the $price per day for the old subscription's recurring total
				$old_recurring_total = WC_Subscriptions_Order::get_item_recurring_amount( $old_subscription['order_id'], $old_subscription['product_id'] );
				$old_price_per_day   = $old_recurring_total / $days_in_old_cycle;

				// Find the price per day for the new subscription's recurring total

				// If the subscription uses the same billing interval & cycle as the old subscription, 
				if ( $item_data->subscription_period == $old_subscription['period'] && $item_data->subscription_period_interval == $old_subscription['interval'] ) {

					$days_in_new_cycle = $days_in_old_cycle; // Use $days_in_old_cycle to make sure they're consistent
					$new_price_per_day = $product->subscription_price / $days_in_old_cycle;

				} else {

					// We need to figure out the price per day for the new subscription based on its billing schedule
					switch ( $item_data->subscription_period ) {
						case 'day' :
							$days_in_new_cycle = $item_data->subscription_period_interval;
							break;
						case 'week' :
							$days_in_new_cycle = $item_data->subscription_period_interval * 7;
							break;
						case 'month' :
							$days_in_new_cycle = $item_data->subscription_period_interval  * 30.4375; // Average days per month over 4 year period
							break;
						case 'year' :
							$days_in_new_cycle = $item_data->subscription_period_interval * 365.25; // Average days per year over 4 year period
							break;
					}

					$new_price_per_day = $product->subscription_price / $days_in_new_cycle;

				}

				// If the customer is upgrading, we may need to add a gap payment to the sign-up fee or to reduce the pre-paid period (or both)
				if ( $old_price_per_day < $new_price_per_day ) {

					// The new subscription may be more expensive, but it's also on a shorter billing cycle, so reduce the next pre-paid term
					if ( $days_in_old_cycle > $days_in_new_cycle ) {

						// Find out how many days at the new price per day the customer would receive for the total amount already paid
						// (e.g. if the customer paid $10 / month previously, and was switching to a $5 / week subscription, she has pre-paid 14 days at the new price)
						$pre_paid_days = 0;
						do {
							$pre_paid_days++;
							$new_total_paid = $pre_paid_days * $new_price_per_day;
						} while ( $new_total_paid < $old_recurring_total );

						// If the total amount the customer has paid entitles her to more days at the new price than she has received, there is no gap payment, just shorten the pre-paid term the appropriate number of days
						if ( $days_since_last_payment < $pre_paid_days ) {

							$woocommerce->cart->cart_contents[ $cart_item_key ]['subscription_switch']['first_payment_timestamp'] = $last_payment_timestamp + ( $pre_paid_days * 60 * 60 * 24 );

						// If the total amount the customer has paid entitles her to the same or less days at the new price then start the new subscription from today
						} else {

							$woocommerce->cart->cart_contents[ $cart_item_key ]['subscription_switch']['first_payment_timestamp'] = 0;

						}

					} else {

						$extra_to_pay = $days_until_next_payment * ( $new_price_per_day - $old_price_per_day );

						$woocommerce->cart->cart_contents[ $cart_item_key ]['data']->subscription_sign_up_fee += round( $extra_to_pay, 2 );

					}

					$woocommerce->cart->cart_contents[ $cart_item_key ]['subscription_switch']['upgraded_or_downgraded'] = 'upgraded';

				// If the customer is downgrading, set the next payment date and maybe extend it if downgrades are prorated
				} elseif ( $old_price_per_day > $new_price_per_day && $new_price_per_day > 0 ) {

					$old_total_paid = $old_price_per_day * $days_until_next_payment;
					$new_total_paid = $new_price_per_day;

					// if downgrades are apportioned, extend the next payment date for n more days
					if ( in_array( $apportion_recurring_price, array( 'virtual', 'yes' ) ) ) {

						// Find how many more days at the new lower price it takes to exceed the amount already paid
						for ( $days_to_add = 0; $new_total_paid <= $old_total_paid; $days_to_add++ ) {
							$new_total_paid = $days_to_add * $new_price_per_day;
						}

						$days_to_add -= $days_until_next_payment;
					} else {
						$days_to_add = 0;
					}

					$woocommerce->cart->cart_contents[ $cart_item_key ]['subscription_switch']['first_payment_timestamp'] = $next_payment_timestamp + ( $days_to_add * 60 * 60 * 24 );
					$woocommerce->cart->cart_contents[ $cart_item_key ]['subscription_switch']['upgraded_or_downgraded'] = 'downgraded';

				} // The old price per day == the new price per day, no need to change anything

			}

			// Finally, if we need to make sure the initial total doesn't include any recurring amount, we can by spoofing a free trial
			if ( 0 != $woocommerce->cart->cart_contents[ $cart_item_key ]['subscription_switch']['first_payment_timestamp'] ) {
				$woocommerce->cart->cart_contents[ $cart_item_key ]['data']->subscription_trial_length = 1;
			}

			if ( 'yes' == $apportion_length || ( 'virtual' == $apportion_length && $is_virtual_product ) ) {

				// Maybe charge an initial amount to account for upgrading from a cheaper subscription
				$base_length        = WC_Subscriptions_Product::get_length( $product_id );
				$completed_payments = WC_Subscriptions_Manager::get_subscriptions_completed_payment_count( $old_subscription_key );
				$length_remaining   = $base_length - $completed_payments;

				// Default to the base length if more payments have already been made than this subscription requires
				if ( $length_remaining < 0 ) {
					$length_remaining = $base_length;
				}

				$woocommerce->cart->cart_contents[ $cart_item_key ]['data']->subscription_length = $length_remaining;
			}
		}

		return $total;
	}

	/**
	 * Make sure anything requesting the first payment date for a switched subscription receives a date which
	 * takes into account the switch (i.e. prepaid days and possibly a downgrade).
	 *
	 * This is necessary as the self::calculate_first_payment_date() is not called when the subscription is active
	 * (which it isn't until the first payment is completed and the subscription is activated).
	 *
	 * @since 1.4
	 */
	public static function get_first_payment_date( $next_payment_date, $subscription_key, $user_id, $type ) {

		$subscription = WC_Subscriptions_Manager::get_subscription( $subscription_key );

		if ( 'active' == $subscription['status'] && self::order_contains_subscription_switch( $subscription['order_id'] ) && 1 >= WC_Subscriptions_Manager::get_subscriptions_completed_payment_count( $subscription_key ) ) {

			$first_payment_timestamp = get_post_meta( $subscription['order_id'], '_switched_subscription_first_payment_timestamp', true );

			if ( 0 != $first_payment_timestamp ) {
				$next_payment_date = ( 'mysql' == $type ) ? date( 'Y-m-d H:i:s', $first_payment_timestamp ) : $first_payment_timestamp;
			}
		}

		return $next_payment_date;
	}

	/**
	 * Make sure when calculating the first payment date for a switched subscription, the date takes into
	 * account the switch (i.e. prepaid days and possibly a downgrade).
	 *
	 * @since 1.4
	 */
	public static function calculate_first_payment_date( $next_payment_date, $order, $product_id, $type ) {
		return self::get_first_payment_date( $next_payment_date, WC_Subscriptions_Manager::get_subscription_key( $order->id, $product_id ), $order->user_id, $type );
	}

	/**
	 * Never display the trial period for a subscription switch (we're only setting it to calculate correct totals)
	 *
	 * @since 1.4
	 */
	public static function customise_cart_subscription_string_details( $subscription_details, $args ) {

		if ( false !== self::cart_contains_subscription_switch() ) {
			$subscription_details['trial_length'] = 0;
		}

		return $subscription_details;
	}

	/**
	 * Add the next payment date to the end of the subscription to clarify when the new rate will be charged
	 *
	 * @since 1.4
	 */
	public static function customise_subscription_price_string( $subscription_string ) {

		$switch_details = self::cart_contains_subscription_switch();

		if ( false !== $switch_details && 0 != $switch_details['first_payment_timestamp'] ) {
			$subscription_string = sprintf( __( '%s %s(next payment %s)%s', 'woocommerce-subscriptions' ), $subscription_string, '<small>', date_i18n( woocommerce_date_format(), $switch_details['first_payment_timestamp'] ), '</small>' );
		}

		return $subscription_string;
	}

	/**
	 * If the current request is to switch subscriptions, don't show a product's free trial period (because there is no
	 * free trial for subscription switches) and also if the length is being prorateed, don't display the length until
	 * checkout.
	 *
	 * @since 1.4
	 */
	public static function customise_product_string_inclusions( $inclusions, $product ) {

		if ( isset ( $_GET['switch-subscription'] ) || false !== self::cart_contains_subscription_switch() ) {

			$inclusions['trial_length'] = false;

			$apportion_length      = get_option( WC_Subscriptions_Admin::$option_prefix . '_apportion_length', 'no' );
			$apportion_sign_up_fee = get_option( WC_Subscriptions_Admin::$option_prefix . '_apportion_sign_up_fee', 'no' );

			if ( 'yes' == $apportion_length || ( 'virtual' == $apportion_length && $product->is_virtual() ) ) {
				$inclusions['subscription_length'] = false;
			}

			if ( self::cart_contains_subscription_switch() && 'no' === $apportion_sign_up_fee ) {

				$inclusions['sign_up_fee'] = false;

			}

		}

		return $inclusions;
	}

	/**
	 * If a product is being marked as not purchasable because it is limited and the customer has a subscription,
	 * but the current request is to switch the subscription, then mark it as purchasable.
	 *
	 * @since 1.4.4
	 * @return bool
	 */
	public static function is_purchasable( $is_purchasable, $product ) {
		global $woocommerce;

		if ( false === $is_purchasable && WC_Subscriptions_Product::is_subscription( $product->id ) && 'no' != $product->limit_subscriptions && is_user_logged_in() && WC_Subscriptions_Manager::user_has_subscription( 0, $product->id, $product->limit_subscriptions ) ) {

			// Adding to cart from the product page
			if ( isset ( $_GET['switch-subscription'] ) ) {

				$is_purchasable = true;

			// Validating when restring cart from session
			} elseif ( self::cart_contains_subscription_switch() ) {

				$is_purchasable = true;

			// Restoring cart from session, so need to check the cart in the session (self::cart_contains_subscription_switch() only checks the cart)
			} elseif ( isset( $woocommerce->session->cart ) ) {

				foreach ( $woocommerce->session->cart as $cart_item_key => $cart_item ) {
					if ( $product->id == $cart_item['product_id'] && isset( $cart_item['subscription_switch'] ) ) {
						$is_purchasable = true;
						break;
					}
				}

			}
		}

		return $is_purchasable;
	}

	/**
	 * Automatically set a switch order's status to complete (even if the items require shipping because 
	 * the order is simply a record of the switch and not indicative of an item needing to be shipped)
	 *
	 * @since 1.5
	 */
	public static function subscription_switch_autocomplete( $new_order_status, $order_id ) {

		if ( 'processing' == $new_order_status && self::order_contains_subscription_switch( $order_id ) ) {
			$order = new WC_Order( $order_id );
			if ( 1 == count( $order->get_items() ) ) { // Can't use $order->get_item_count() because it takes quantity into account
				$new_order_status = 'completed';
			}
		}

		return $new_order_status;
	}

	/**
	 * Do not carry over switch related meta data to renewal orders.
	 *
	 * @since 1.5.4
	 */
	public static function remove_renewal_order_meta_query( $order_meta_query ) {

		$order_meta_query .= " AND `meta_key` NOT IN ('_switched_subscription_key', '_switched_subscription_new_order', '_switched_subscription_first_payment_timestamp')";

		return $order_meta_query;
	}

	/**
	 * Make the switch process persist even if the subscription product has Product Addons that need to be set.
	 *
	 * @since 1.5.6
	 */
	public static function addons_add_to_cart_url( $add_to_cart_url ) {

		if ( isset( $_GET['switch-subscription'] ) && false === strpos( $add_to_cart_url, 'switch-subscription' ) ) {
			$add_to_cart_url = add_query_arg( array( 'switch-subscription' => $_GET['switch-subscription'] ), $add_to_cart_url );
		}

		return $add_to_cart_url;
	}
}
WC_Subscriptions_Switcher::init();
