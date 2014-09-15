<?php
/**
 * A timeout resistant, single-serve upgrader for WC Subscriptions.
 *
 * This class is used to make all reasonable attempts to neatly upgrade data between versions of Subscriptions.
 *
 * For example, the subscription meta data associated with an order significantly changed between 1.1.n and 1.2.
 * It was imperative the data be upgraded to the new schema without hassle. A hassle could easily occur if 100,000
 * orders were being modified - memory exhaustion, script time out etc.
 *
 * @package		WooCommerce Subscriptions
 * @subpackage	WC_Subscriptions_Checkout
 * @category	Class
 * @author		Brent Shepherd
 * @since		1.2
 */
class WC_Subscriptions_Upgrader {

	private static $active_version;

	private static $upgrade_limit;

	private static $about_page_url;

	private static $last_upgraded_user_id = false;

	/**
	 * Hooks upgrade function to init.
	 *
	 * @since 1.2
	 */
	public static function init() {

		self::$active_version = get_option( WC_Subscriptions_Admin::$option_prefix . '_active_version', '0' );

		self::$upgrade_limit = apply_filters( 'woocommerce_subscriptions_hooks_to_upgrade', 250 );

		self::$about_page_url = admin_url( 'index.php?page=wcs-about&wcs-updated=true' );

		if ( isset( $_POST['action'] ) && 'wcs_upgrade' == $_POST['action'] ) {

			add_action( 'wp_ajax_wcs_upgrade', __CLASS__ . '::ajax_upgrade', 10 );

		} elseif ( @current_user_can( 'activate_plugins' ) ) {

			if ( 'true' == get_transient( 'wc_subscriptions_is_upgrading' ) ) {

				self::upgrade_in_progress_notice();

			} elseif ( isset( $_GET['wcs_upgrade_step'] ) || version_compare( self::$active_version, WC_Subscriptions::$version, '<' ) ) {

				// Run updates as soon as admin hits site
				add_action( 'init', __CLASS__ . '::upgrade', 11 );

			} elseif( is_admin() && isset( $_GET['page'] ) && 'wcs-about' == $_GET['page'] ){

				add_action( 'admin_menu', __CLASS__ . '::updated_welcome_page' );

			}

		}
	}

	/**
	 * Checks which upgrades need to run and calls the necessary functions for that upgrade.
	 *
	 * @since 1.2
	 */
	public static function upgrade(){
		global $wpdb;

		update_option( WC_Subscriptions_Admin::$option_prefix . '_previous_version', self::$active_version );

		// Update the hold stock notification to be one week (if it's still at the default 60 minutes) to prevent cancelling subscriptions using manual renewals and payment methods that can take more than 1 hour (i.e. PayPal eCheck)
		if ( '0' == self::$active_version || version_compare( self::$active_version, '1.4', '<' ) ) {

			$hold_stock_duration = get_option( 'woocommerce_hold_stock_minutes' );

			if ( 60 == $hold_stock_duration ) {
				update_option( 'woocommerce_hold_stock_minutes', 60 * 24 * 7 );
			}

			// Allow products & subscriptions to be purchased in the same transaction
			update_option( 'woocommerce_subscriptions_multiple_purchase', 'yes' );

		}

		// Keep track of site url to prevent duplicate payments from staging sites, first added in 1.3.8 & updated with 1.4.2 to work with WP Engine staging sites
		if ( '0' == self::$active_version || version_compare( self::$active_version, '1.4.2', '<' ) ) {
			WC_Subscriptions::set_duplicate_site_url_lock();
		}

		// Don't autoload cron locks
		if ( '0' != self::$active_version && version_compare( self::$active_version, '1.4.3', '<' ) ) {
			$wpdb->query(
				"UPDATE $wpdb->options
				SET autoload = 'no'
				WHERE option_name LIKE 'wcs_blocker_%'"
			);
		}

		// Add support for quantities  & migrate wp_cron schedules to the new action-scheduler system.
		if ( '0' != self::$active_version && version_compare( self::$active_version, '1.5', '<' ) ) {
			self::upgrade_to_version_1_5();
		}

		// Update to new system to limit subscriptions by status rather than in a binary way
		if ( '0' != self::$active_version && version_compare( self::$active_version, '1.5.4', '<' ) ) {
			$wpdb->query(
				"UPDATE $wpdb->postmeta
				SET meta_value = 'any'
				WHERE meta_key LIKE '_subscription_limit'
				AND meta_value LIKE 'yes'"
			);
		}

		self::upgrade_complete();
	}

	/**
	 * When an upgrade is complete, set the active version, delete the transient locking upgrade and fire a hook.
	 *
	 * @since 1.2
	 */
	public static function upgrade_complete() {
		// Set the new version now that all upgrade routines have completed
		update_option( WC_Subscriptions_Admin::$option_prefix . '_active_version', WC_Subscriptions::$version );

		do_action( 'woocommerce_subscriptions_upgraded', WC_Subscriptions::$version );
	}

	/**
	 * Add support for quantities for subscriptions.
	 * Update all current subscription wp_cron tasks to the new action-scheduler system.
	 *
	 * @since 1.5
	 */
	private static function upgrade_to_version_1_5() {

		$_GET['wcs_upgrade_step'] = ( ! isset( $_GET['wcs_upgrade_step'] ) ) ? 0 : $_GET['wcs_upgrade_step'];

		switch ( (int)$_GET['wcs_upgrade_step'] ) {
			case 1:
				self::display_database_upgrade_helper();
				break;
			case 3: // keep a way to circumvent the upgrade routine just in case
				self::upgrade_complete();
				wp_safe_redirect( self::$about_page_url );
				break;
			case 0:
			default:
				wp_safe_redirect( admin_url( 'admin.php?wcs_upgrade_step=1' ) );
				break;
		}

		exit();
	}

	/**
	 * Move scheduled subscription hooks out of wp-cron and into the new Action Scheduler.
	 *
	 * Also set all existing subscriptions to "sold individually" to maintain previous behavior
	 * for existing subscription products before the subscription quantities feature was enabled..
	 *
	 * @since 1.5
	 */
	public static function ajax_upgrade() {
		global $wpdb;

		@set_time_limit( 600 );
		@ini_set( 'memory_limit', apply_filters( 'admin_memory_limit', WP_MAX_MEMORY_LIMIT ) );

		set_transient( 'wc_subscriptions_is_upgrading', 'true', 60 * 2 );

		if ( 'really_old_version' == $_POST['upgrade_step'] ) {

			$database_updates = '';

			if ( '0' != self::$active_version && version_compare( self::$active_version, '1.2', '<' ) ) {
				self::upgrade_database_to_1_2();
				self::generate_renewal_orders();
				update_option( WC_Subscriptions_Admin::$option_prefix . '_active_version', '1.2' );
				$database_updates = '1.2, ';
			}

			// Add Variable Subscription product type term
			if ( '0' != self::$active_version && version_compare( self::$active_version, '1.3', '<' ) ) {
				self::upgrade_database_to_1_3();
				update_option( WC_Subscriptions_Admin::$option_prefix . '_active_version', '1.3' );
				$database_updates .= '1.3 & ';
			}

			// Moving subscription meta out of user meta and into item meta
			if ( '0' != self::$active_version && version_compare( self::$active_version, '1.4', '<' ) ) {
				self::upgrade_database_to_1_4();
				update_option( WC_Subscriptions_Admin::$option_prefix . '_active_version', '1.4' );
				$database_updates .= '1.4.';
			}

			$results = array(
				'message' => sprintf( __( 'Database updated to version %s', 'woocommerce-subscriptions' ), $database_updates )
			);

		} elseif ( 'products' == $_POST['upgrade_step'] ) {

			// Set status to 'sold individually' for all existing subscriptions that haven't already been updated
			$sql = "SELECT DISTINCT ID FROM {$wpdb->posts} as posts
				JOIN {$wpdb->postmeta} as postmeta
					ON posts.ID = postmeta.post_id
					AND (postmeta.meta_key LIKE '_subscription%')
				JOIN  {$wpdb->postmeta} AS soldindividually
					ON posts.ID = soldindividually.post_id
					AND ( soldindividually.meta_key LIKE '_sold_individually' AND soldindividually.meta_value !=  'yes' )
				WHERE posts.post_type = 'product'";

			$subscription_product_ids = $wpdb->get_results( $sql );

			foreach ( $subscription_product_ids as $product_id ) {
				update_post_meta( $product_id->ID, '_sold_individually', 'yes' );
			}

			$results = array(
				'message' => sprintf( __( 'Marked %s subscription products as "sold individually".', 'woocommerce-subscriptions' ), count( $subscription_product_ids ) )
			);

		} else {

			$counter  = 0;

			$before_cron_update = microtime(true);

			// update all of the current Subscription cron tasks to the new Action Scheduler
			$cron = _get_cron_array();

			foreach ( $cron as $timestamp => $actions ) {
				foreach ( $actions as $hook => $details ) {
					if ( $hook == 'scheduled_subscription_payment' || $hook == 'scheduled_subscription_expiration' || $hook == 'scheduled_subscription_end_of_prepaid_term' || $hook == 'scheduled_subscription_trial_end' || $hook == 'paypal_check_subscription_payment' ) {
						foreach ( $details as $hook_key => $values ) {


							if ( ! wc_next_scheduled_action( $hook, $values['args'] ) ) {
								wc_schedule_single_action( $timestamp, $hook, $values['args'] );
								unset( $cron[$timestamp][$hook][$hook_key] );
								$counter++;
							}


							if ( $counter >= self::$upgrade_limit ) {
								break;
							}
						}

						// If there are no other jobs scheduled for this hook at this timestamp, remove the entire hook
						if ( 0 == count( $cron[$timestamp][$hook] ) ) {
							unset( $cron[$timestamp][$hook] );
						}
						if ( $counter >= self::$upgrade_limit ) {
							break;
						}
					}
				}

				// If there are no actions schedued for this timestamp, remove the entire schedule
				if ( 0 == count( $cron[$timestamp] ) ) {
					unset( $cron[$timestamp] );
				}
				if ( $counter >= self::$upgrade_limit ) {
					break;
				}
			}

			// Set the cron with the removed schedule
			_set_cron_array( $cron );

			$results = array(
				'upgraded_count' => $counter,
				'message'        => sprintf( __( 'Migrated %s subscription related hooks to the new scheduler (in {execution_time} seconds).', 'woocommerce-subscriptions' ), $counter )
			);

		}

		if ( isset( $counter ) && $counter < self::$upgrade_limit ) {
			self::upgrade_complete();
		}

		delete_transient( 'wc_subscriptions_is_upgrading' );

		header( 'Content-Type: application/json; charset=utf-8' );
		echo json_encode( $results );
		exit();
	}

	/**
	 * Version 1.2 introduced a massive change to the order meta data schema. This function goes
	 * through and upgrades the existing data on all orders to the new schema.
	 *
	 * The upgrade process is timeout safe as it keeps a record of the orders upgraded and only
	 * deletes this record once all orders have been upgraded successfully. If operating on a huge
	 * number of orders and the upgrade process times out, only the orders not already upgraded
	 * will be upgraded in future requests that trigger this function.
	 *
	 * @since 1.2
	 */
	private static function upgrade_database_to_1_2() {
		global $wpdb;

		// Get IDs only and use a direct DB query for efficiency
		$orders_to_upgrade = $wpdb->get_col( "SELECT ID FROM $wpdb->posts WHERE post_type = 'shop_order' AND post_parent = 0" );

		$upgraded_orders = get_option( 'wcs_1_2_upgraded_order_ids', array() );

		// Transition deprecated subscription status if we aren't in the middle of updating orders
		if ( empty( $upgraded_orders ) ) {
			$wpdb->query( $wpdb->prepare( "UPDATE $wpdb->usermeta SET meta_value = replace( meta_value, 's:9:\"suspended\"', 's:7:\"on-hold\"' ) WHERE meta_key LIKE %s", '%_woocommerce_subscriptions' ) );
			$wpdb->query( $wpdb->prepare( "UPDATE $wpdb->usermeta SET meta_value = replace( meta_value, 's:6:\"failed\"', 's:9:\"cancelled\"' ) WHERE meta_key LIKE %s", '%_woocommerce_subscriptions' ) );
		}

		$orders_to_upgrade = array_diff( $orders_to_upgrade, $upgraded_orders );

		// Upgrade all _sign_up_{field} order meta to new order data format
		foreach ( $orders_to_upgrade as $order_id ) {

			$order = new WC_Order( $order_id );

			// Manually check if a product in an order is a subscription, we can't use WC_Subscriptions_Order::order_contains_subscription( $order ) because it relies on the new data structure
			$contains_subscription = false;
			foreach ( $order->get_items() as $order_item ) {
				if ( WC_Subscriptions_Product::is_subscription( WC_Subscriptions_Order::get_items_product_id( $order_item ) ) ) {
					$contains_subscription = true;
					break;
				}
			}

			if ( ! $contains_subscription ) {
				continue;
			}

			$trial_lengths = WC_Subscriptions_Order::get_meta( $order, '_order_subscription_trial_lengths', array() );
			$trial_length = array_pop( $trial_lengths );

			$has_trial = ( ! empty( $trial_length ) && $trial_length > 0 ) ? true : false ;

			$sign_up_fee_total = WC_Subscriptions_Order::get_meta( $order, '_sign_up_fee_total', 0 );

			// Create recurring_* meta data from existing cart totals

			$cart_discount = $order->get_cart_discount();
			update_post_meta( $order_id, '_order_recurring_discount_cart', $cart_discount );

			$order_discount = $order->get_order_discount();
			update_post_meta( $order_id, '_order_recurring_discount_total', $order_discount );

			$order_shipping_tax = get_post_meta( $order_id, '_order_shipping_tax', true );
			update_post_meta( $order_id, '_order_recurring_shipping_tax_total', $order_shipping_tax );

			$order_tax = get_post_meta( $order_id, '_order_tax', true ); // $order->get_total_tax() includes shipping tax
			update_post_meta( $order_id, '_order_recurring_tax_total', $order_tax );

			$order_total = $order->get_total();
			update_post_meta( $order_id, '_order_recurring_total', $order_total );

			// Set order totals to include sign up fee fields, if there was a sign up fee on the order and a trial period (other wise, the recurring totals are correct)
			if ( $sign_up_fee_total > 0 ) {

				// Order totals need to be changed to be equal to sign up fee totals
				if ( $has_trial ) {

					$cart_discount  = WC_Subscriptions_Order::get_meta( $order, '_sign_up_fee_discount_cart', 0 );
					$order_discount = WC_Subscriptions_Order::get_meta( $order, '_sign_up_fee_discount_total', 0 );
					$order_tax      = WC_Subscriptions_Order::get_meta( $order, '_sign_up_fee_tax_total', 0 );
					$order_total    = $sign_up_fee_total;

				} else { // No trial, sign up fees need to be added to order totals

					$cart_discount  += WC_Subscriptions_Order::get_meta( $order, '_sign_up_fee_discount_cart', 0 );
					$order_discount += WC_Subscriptions_Order::get_meta( $order, '_sign_up_fee_discount_total', 0 );
					$order_tax      += WC_Subscriptions_Order::get_meta( $order, '_sign_up_fee_tax_total', 0 );
					$order_total    += $sign_up_fee_total;

				}

				update_post_meta( $order_id, '_order_total', $order_total );
				update_post_meta( $order_id, '_cart_discount', $cart_discount );
				update_post_meta( $order_id, '_order_discount', $order_discount );
				update_post_meta( $order_id, '_order_tax', $order_tax );

			}

			// Make sure we get order taxes in WC 1.x format
			if ( false == self::$is_wc_version_2 ) {

				$order_taxes = $order->get_taxes();

			} else {

				$order_tax_row = $wpdb->get_row( $wpdb->prepare( "
					SELECT * FROM {$wpdb->postmeta}
					WHERE meta_key = '_order_taxes_old'
					AND post_id = %s
					", $order_id )
				);

				$order_taxes = (array) maybe_unserialize( $order_tax_row->meta_value );
			}

			// Set recurring taxes to order taxes, if using WC 2.0, this will be migrated to the new format in @see self::upgrade_to_latest_wc()
			update_post_meta( $order_id, '_order_recurring_taxes', $order_taxes );

			$sign_up_fee_taxes = WC_Subscriptions_Order::get_meta( $order, '_sign_up_fee_taxes', array() );

			// Update order taxes to include sign up fee taxes
			foreach ( $sign_up_fee_taxes as $index => $sign_up_tax ) {

				if ( $has_trial && $sign_up_fee_total > 0 ) { // Order taxes need to be set to the same as the sign up fee taxes

					if ( isset( $sign_up_tax['cart_tax'] ) && $sign_up_tax['cart_tax'] > 0 ) {
						$order_taxes[ $index ]['cart_tax'] = $sign_up_tax['cart_tax'];
					}

				} elseif ( ! $has_trial && $sign_up_fee_total > 0 ) { // Sign up fee taxes need to be added to order taxes

					if ( isset( $sign_up_tax['cart_tax'] ) && $sign_up_tax['cart_tax'] > 0 ) {
						$order_taxes[ $index ]['cart_tax'] += $sign_up_tax['cart_tax'];
					}

				}

			}

			if ( false == self::$is_wc_version_2 ) { // Doing it right: updated Subs *before* updating WooCommerce, the WooCommerce updater will take care of data migration

				update_post_meta( $order_id, '_order_taxes', $order_taxes );

			} else { // Doing it wrong: updated Subs *after* updating WooCommerce, need to store in WC2.0 tax structure

				$index = 0;
				$new_order_taxes = $order->get_taxes();

				foreach( $new_order_taxes as $item_id => $order_tax ) {

					$index = $index + 1;

					if ( ! isset( $order_taxes[ $index ]['label'] ) || ! isset( $order_taxes[ $index ]['cart_tax'] ) || ! isset( $order_taxes[ $index ]['shipping_tax'] ) ) {
						continue;
					}

					// Add line item meta
					if ( $item_id ) {
						woocommerce_update_order_item_meta( $item_id, 'compound', absint( isset( $order_taxes[ $index ]['compound'] ) ? $order_taxes[ $index ]['compound'] : 0 ) );
						woocommerce_update_order_item_meta( $item_id, 'tax_amount', WC_Subscriptions::format_total( $order_taxes[ $index ]['cart_tax'] ) );
						woocommerce_update_order_item_meta( $item_id, 'shipping_tax_amount', WC_Subscriptions::format_total( $order_taxes[ $index ]['shipping_tax'] ) );
					}
				}

			}

			/* Upgrade each order item to use new Item Meta schema */
			$order_subscription_periods       = WC_Subscriptions_Order::get_meta( $order_id, '_order_subscription_periods', array() );
			$order_subscription_intervals     = WC_Subscriptions_Order::get_meta( $order_id, '_order_subscription_intervals', array() );
			$order_subscription_lengths       = WC_Subscriptions_Order::get_meta( $order_id, '_order_subscription_lengths', array() );
			$order_subscription_trial_lengths = WC_Subscriptions_Order::get_meta( $order_id, '_order_subscription_trial_lengths', array() );

			$order_items = $order->get_items();

			foreach ( $order_items as $index => $order_item ) {

				$product_id = WC_Subscriptions_Order::get_items_product_id( $order_item );
				$item_meta  = new WC_Order_Item_Meta( $order_item['item_meta'] );

				$subscription_interval     = ( isset( $order_subscription_intervals[ $product_id ] ) ) ? $order_subscription_intervals[ $product_id ] : 1;
				$subscription_length       = ( isset( $order_subscription_lengths[ $product_id ] ) ) ? $order_subscription_lengths[ $product_id ] : 0;
				$subscription_trial_length = ( isset( $order_subscription_trial_lengths[ $product_id ] ) ) ? $order_subscription_trial_lengths[ $product_id ] : 0;

				$subscription_sign_up_fee  = WC_Subscriptions_Order::get_meta( $order, '_cart_contents_sign_up_fee_total', 0 );

				if ( $sign_up_fee_total > 0 ) {

					// Discounted price * Quantity
					$sign_up_fee_line_total = WC_Subscriptions_Order::get_meta( $order, '_cart_contents_sign_up_fee_total', 0 );
					$sign_up_fee_line_tax   = WC_Subscriptions_Order::get_meta( $order, '_sign_up_fee_tax_total', 0 );

					// Base price * Quantity
					$sign_up_fee_line_subtotal     = WC_Subscriptions_Order::get_meta( $order, '_cart_contents_sign_up_fee_total', 0 ) + WC_Subscriptions_Order::get_meta( $order, '_sign_up_fee_discount_cart', 0 );
					$sign_up_fee_propotion         = ( $sign_up_fee_line_total > 0 ) ? $sign_up_fee_line_subtotal / $sign_up_fee_line_total : 0;
					$sign_up_fee_line_subtotal_tax = WC_Subscriptions_Manager::get_amount_from_proportion( WC_Subscriptions_Order::get_meta( $order, '_sign_up_fee_tax_total', 0 ), $sign_up_fee_propotion );

					if ( $has_trial ) { // Set line item totals equal to sign up fee totals

						$order_item['line_subtotal']     = $sign_up_fee_line_subtotal;
						$order_item['line_subtotal_tax'] = $sign_up_fee_line_subtotal_tax;
						$order_item['line_total']        = $sign_up_fee_line_total;
						$order_item['line_tax']          = $sign_up_fee_line_tax;

					} else { // No trial period, sign up fees need to be added to order totals

						$order_item['line_subtotal']     += $sign_up_fee_line_subtotal;
						$order_item['line_subtotal_tax'] += $sign_up_fee_line_subtotal_tax;
						$order_item['line_total']        += $sign_up_fee_line_total;
						$order_item['line_tax']          += $sign_up_fee_line_tax;

					}
				}

				// Upgrading with WC 1.x
				if ( method_exists( $item_meta, 'add' ) ) {

					$item_meta->add( '_subscription_period', $order_subscription_periods[ $product_id ] );
					$item_meta->add( '_subscription_interval', $subscription_interval );
					$item_meta->add( '_subscription_length', $subscription_length );
					$item_meta->add( '_subscription_trial_length', $subscription_trial_length );

					$item_meta->add( '_subscription_recurring_amount', $order_item['line_subtotal'] ); // WC_Subscriptions_Product::get_price() would return a price without filters applied
					$item_meta->add( '_subscription_sign_up_fee', $subscription_sign_up_fee );

					// Set recurring amounts for the item
					$item_meta->add( '_recurring_line_total', $order_item['line_total'] );
					$item_meta->add( '_recurring_line_tax', $order_item['line_tax'] );
					$item_meta->add( '_recurring_line_subtotal', $order_item['line_subtotal'] );
					$item_meta->add( '_recurring_line_subtotal_tax', $order_item['line_subtotal_tax'] );

					$order_item['item_meta'] = $item_meta->meta;

					$order_items[ $index ] = $order_item;

				} else { // Ignoring all advice, upgrading 4 months after version 1.2 was released, and doing it with WC 2.0 installed

					woocommerce_add_order_item_meta( $index, '_subscription_period', $order_subscription_periods[ $product_id ] );
					woocommerce_add_order_item_meta( $index, '_subscription_interval', $subscription_interval );
					woocommerce_add_order_item_meta( $index, '_subscription_length', $subscription_length );
					woocommerce_add_order_item_meta( $index, '_subscription_trial_length', $subscription_trial_length );
					woocommerce_add_order_item_meta( $index, '_subscription_trial_period', $order_subscription_periods[ $product_id ] );

					woocommerce_add_order_item_meta( $index, '_subscription_recurring_amount', $order_item['line_subtotal'] );
					woocommerce_add_order_item_meta( $index, '_subscription_sign_up_fee', $subscription_sign_up_fee );

					// Calculated recurring amounts for the item
					woocommerce_add_order_item_meta( $index, '_recurring_line_total', $order_item['line_total'] );
					woocommerce_add_order_item_meta( $index, '_recurring_line_tax', $order_item['line_tax'] );
					woocommerce_add_order_item_meta( $index, '_recurring_line_subtotal', $order_item['line_subtotal'] );
					woocommerce_add_order_item_meta( $index, '_recurring_line_subtotal_tax', $order_item['line_subtotal_tax'] );

					if ( $sign_up_fee_total > 0 ) { // Order totals have changed
						woocommerce_update_order_item_meta( $index, '_line_subtotal', woocommerce_format_decimal( $order_item['line_subtotal'] ) );
						woocommerce_update_order_item_meta( $index, '_line_subtotal_tax', woocommerce_format_decimal( $order_item['line_subtotal_tax'] ) );
						woocommerce_update_order_item_meta( $index, '_line_total', woocommerce_format_decimal( $order_item['line_total'] ) );
						woocommerce_update_order_item_meta( $index, '_line_tax', woocommerce_format_decimal( $order_item['line_tax'] ) );
					}

				}
			}

			// Save the new meta on the order items for WC 1.x (the API functions already saved the data for WC2.x)
			if ( false == self::$is_wc_version_2 ) {
				update_post_meta( $order_id, '_order_items', $order_items );
			}

			$upgraded_orders[] = $order_id;

			update_option( 'wcs_1_2_upgraded_order_ids', $upgraded_orders );

		}
	}

	/**
	 * Version 1.2 introduced child renewal orders to keep a record of each completed subscription
	 * payment. Before 1.2, these orders did not exist, so this function creates them.
	 *
	 * @since 1.2
	 */
	private static function generate_renewal_orders() {
		global $woocommerce, $wpdb;

		$subscriptions_grouped_by_user = WC_Subscriptions_Manager::get_all_users_subscriptions();

		// Don't send any order emails
		$email_actions = array( 'woocommerce_low_stock', 'woocommerce_no_stock', 'woocommerce_product_on_backorder', 'woocommerce_order_status_pending_to_processing', 'woocommerce_order_status_pending_to_completed', 'woocommerce_order_status_pending_to_on-hold', 'woocommerce_order_status_failed_to_processing', 'woocommerce_order_status_failed_to_completed', 'woocommerce_order_status_pending_to_processing', 'woocommerce_order_status_pending_to_on-hold', 'woocommerce_order_status_completed', 'woocommerce_new_customer_note' );
		foreach ( $email_actions as $action ){
			remove_action( $action, array( &$woocommerce, 'send_transactional_email') );
		}

		remove_action( 'woocommerce_payment_complete', 'WC_Subscriptions_Renewal_Order::maybe_record_renewal_order_payment', 10, 1 );

		foreach ( $subscriptions_grouped_by_user as $user_id => $users_subscriptions ) {
			foreach ( $users_subscriptions as $subscription_key => $subscription ) {
				$order_post = get_post( $subscription['order_id'] );

				if ( isset( $subscription['completed_payments'] ) && count( $subscription['completed_payments'] ) > 0 && $order_post != null ) {
					foreach ( $subscription['completed_payments'] as $payment_date ) {

						$existing_renewal_order = $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_date_gmt = %s AND post_parent = %d AND post_type = 'shop_order'", $payment_date, $subscription['order_id'] ) );

						// If a renewal order exists on this date, don't generate another one
						if ( NULL !== $existing_renewal_order ) {
							continue;
						}

						$renewal_order_id = WC_Subscriptions_Renewal_Order::generate_renewal_order( $subscription['order_id'], $subscription['product_id'], array( 'new_order_role' => 'child' ) );

						if ( $renewal_order_id ) {

							// Mark the order as paid
							$renewal_order = new WC_Order( $renewal_order_id );

							$renewal_order->payment_complete();

							// Avoid creating 100s "processing" orders
							$renewal_order->update_status( 'completed' );

							// Set correct dates on the order
							$renewal_order = array(
								'ID'            => $renewal_order_id,
								'post_date'     => $payment_date,
								'post_date_gmt' => $payment_date
							);
							wp_update_post( $renewal_order );

							update_post_meta( $renewal_order_id, '_paid_date', $payment_date );
							update_post_meta( $renewal_order_id, '_completed_date', $payment_date );

						}

					}
				}
			}
		}
	}

	/**
	 * Upgrade cron lock values to be options rather than transients to work around potential early deletion by W3TC
	 * and other caching plugins. Also add the Variable Subscription product type (if it doesn't exist).
	 *
	 * @since 1.3
	 */
	private static function upgrade_database_to_1_3() {
		global $wpdb;

		// Change transient timeout entries to be a vanilla option
		$wpdb->query( " UPDATE $wpdb->options
						SET option_name = TRIM(LEADING '_transient_timeout_' FROM option_name)
						WHERE option_name LIKE '_transient_timeout_wcs_blocker_%'" );

		// Change transient keys from the < 1.1.5 format to new format
		$wpdb->query( " UPDATE $wpdb->options
						SET option_name = CONCAT('wcs_blocker_', TRIM(LEADING '_transient_timeout_block_scheduled_subscription_payments_' FROM option_name))
						WHERE option_name LIKE '_transient_timeout_block_scheduled_subscription_payments_%'" );

		// Delete old transient values
		$wpdb->query( " DELETE FROM $wpdb->options
						WHERE option_name LIKE '_transient_wcs_blocker_%'
						OR option_name LIKE '_transient_block_scheduled_subscription_payments_%'" );

	}

	/**
	 * Version 1.4 moved subscription meta out of usermeta and into the new WC2.0 order item meta
	 * table.
	 *
	 * @since 1.4
	 */
	private static function upgrade_database_to_1_4() {
		global $wpdb;

		$subscriptions_meta_key = $wpdb->get_blog_prefix() . 'woocommerce_subscriptions';
		$order_items_table      = $wpdb->get_blog_prefix() . 'woocommerce_order_items';
		$order_item_meta_table  = $wpdb->get_blog_prefix() . 'woocommerce_order_itemmeta';

		// Get the IDs of all users who have a subscription
		$users_to_upgrade = get_users( array(
			'meta_key' => $subscriptions_meta_key,
			'fields'   => 'ID',
			'orderby'  => 'ID',
			)
		);

		$users_to_upgrade = array_filter( $users_to_upgrade, __CLASS__ . '::is_user_upgraded_to_1_4' );

		foreach ( $users_to_upgrade as $user_to_upgrade ) {

			// Can't use WC_Subscriptions_Manager::get_users_subscriptions() because it relies on the new structure
			$users_old_subscriptions = get_user_option( $subscriptions_meta_key, $user_to_upgrade );

			foreach ( $users_old_subscriptions as $subscription_key => $subscription ) {

				if ( ! isset( $subscription['order_id'] ) ) { // Subscription created incorrectly with v1.1.2
					continue;
				}

				$order_item_id = WC_Subscriptions_Order::get_item_id_by_subscription_key( $subscription_key );

				if ( empty( $order_item_id ) ) { // Subscription created incorrectly with v1.1.2
					continue;
				}

				if ( ! isset( $subscription['trial_expiry_date'] ) ) {
					$subscription['trial_expiry_date'] = '';
				}

				// Set defaults
				$failed_payments    = isset( $subscription['failed_payments'] ) ? $subscription['failed_payments'] : 0;
				$completed_payments = isset( $subscription['completed_payments'] ) ? $subscription['completed_payments'] : array();
				$suspension_count   = isset( $subscription['suspension_count'] ) ? $subscription['suspension_count'] : 0;
				$trial_expiry_date  = isset( $subscription['trial_expiry_date'] ) ? $subscription['trial_expiry_date'] : '';

				$wpdb->query(
					$wpdb->prepare(
						"INSERT INTO $order_item_meta_table (order_item_id, meta_key, meta_value)
						VALUES
						(%d,%s,%s),
						(%d,%s,%s),
						(%d,%s,%s),
						(%d,%s,%s),
						(%d,%s,%s),
						(%d,%s,%s),
						(%d,%s,%s),
						(%d,%s,%s)",
						$order_item_id, '_subscription_status', $subscription['status'],
						$order_item_id, '_subscription_start_date', $subscription['start_date'],
						$order_item_id, '_subscription_expiry_date', $subscription['expiry_date'],
						$order_item_id, '_subscription_end_date', $subscription['end_date'],
						$order_item_id, '_subscription_trial_expiry_date', $trial_expiry_date,
						$order_item_id, '_subscription_failed_payments', $failed_payments,
						$order_item_id, '_subscription_completed_payments', serialize( $completed_payments ),
						$order_item_id, '_subscription_suspension_count', $suspension_count
					)
				);

			}

			update_option( 'wcs_1_4_last_upgraded_user_id', $user_to_upgrade );
			self::$last_upgraded_user_id = $user_to_upgrade;

		}

		// Add an underscore prefix to usermeta key to deprecate, but not delete, subscriptions in user meta
		$wpdb->update(
			$wpdb->usermeta,
			array( 'meta_key' => '_' . $subscriptions_meta_key ),
			array( 'meta_key' => $subscriptions_meta_key )
		);

		// Now set the recurring shipping & payment method on all subscription orders
		$wpdb->query(
			"INSERT INTO $wpdb->postmeta (`post_id`, `meta_key`, `meta_value`)
			SELECT `post_id`, CONCAT('_recurring',`meta_key`), `meta_value`
			FROM $wpdb->postmeta
			WHERE `meta_key` IN ('_shipping_method','_shipping_method_title','_payment_method','_payment_method_title')
			AND `post_id` IN (
				SELECT `post_id` FROM $wpdb->postmeta WHERE `meta_key` = '_order_recurring_total'
			)"
		);

		// Set the recurring shipping total on all subscription orders
		$wpdb->query(
			"INSERT INTO $wpdb->postmeta (`post_id`, `meta_key`, `meta_value`)
			SELECT `post_id`, '_order_recurring_shipping_total', `meta_value`
			FROM $wpdb->postmeta
			WHERE `meta_key` = '_order_shipping'
			AND `post_id` IN (
				SELECT `post_id` FROM $wpdb->postmeta WHERE `meta_key` = '_order_recurring_total'
			)"
		);

		// Get the ID of all orders for a subscription with a free trial and no sign-up fee
		$order_ids = $wpdb->get_col(
			"SELECT order_items.order_id FROM $order_items_table AS order_items
				LEFT JOIN $order_item_meta_table AS itemmeta USING (order_item_id)
				LEFT JOIN $order_item_meta_table AS itemmeta2 USING (order_item_id)
			WHERE itemmeta.meta_key = '_subscription_trial_length'
			AND itemmeta.meta_value > 0
			AND itemmeta2.meta_key = '_subscription_sign_up_fee'
			AND itemmeta2.meta_value > 0"
		);

		$order_ids = implode( ',', $order_ids );

		// Now set the order totals to $0 (can't use $wpdb->update as it only allows joining WHERE clauses with AND)
		if ( ! empty ( $order_ids ) ) {
			$wpdb->query(
					"UPDATE $wpdb->postmeta
					 SET `meta_value` = 0
					 WHERE `meta_key` IN ( '_order_total', '_order_tax', '_order_shipping_tax', '_order_shipping', '_order_discount', '_cart_discount' )
					 AND `post_id` IN ( $order_ids )"
			);

			// Now set the line totals to $0
			$wpdb->query(
				"UPDATE $order_item_meta_table
				 SET `meta_value` = 0
				 WHERE `meta_key` IN ( '_line_subtotal', '_line_subtotal_tax', '_line_total', '_line_tax', 'tax_amount', 'shipping_tax_amount' )
				 AND `order_item_id` IN (
					SELECT `order_item_id` FROM $order_items_table
					WHERE `order_item_type` IN ('tax','line_item')
					AND `order_id` IN ( $order_ids )
				)"
			);
		}

		update_option( 'wcs_1_4_upgraded_order_ids', explode( ',', $order_ids ) );
	}

	/**
	 * Used to check if a user ID is greater than the last user upgraded to version 1.4.
	 *
	 * Needs to be a separate function so that it can use a static variable (and therefore avoid calling get_option() thousands
	 * of times when iterating over thousands of users).
	 *
	 * @since 1.4
	 */
	public static function is_user_upgraded_to_1_4( $user_id ) {

		if ( false === self::$last_upgraded_user_id ) {
			self::$last_upgraded_user_id = get_option( "wcs_1_4_last_upgraded_user_id", 0 );
		}

		return ( $user_id > self::$last_upgraded_user_id ) ? true : false;
	}

	/**
	 * Let the site administrator know we are upgrading the database and provide a confirmation is complete.
	 *
	 * This is important to avoid the possibility of a database not upgrading correctly, but the site continuing
	 * to function without any remedy.
	 *
	 * @since 1.2
	 */
	public static function display_database_upgrade_helper() {
		global $woocommerce;

		wp_register_style( 'wcs-upgrade', plugins_url( '/css/wcs-upgrade.css', WC_Subscriptions::$plugin_file ) );
		wp_register_script( 'wcs-upgrade', plugins_url( '/js/wcs-upgrade.js', WC_Subscriptions::$plugin_file ), 'jquery' );

		$script_data = array(
			'really_old_version' => ( version_compare( self::$active_version, '1.4', '<' ) ) ? 'true' : 'false',
			'hooks_per_request' => self::$upgrade_limit,
			'ajax_url' => admin_url( 'admin-ajax.php' ),
		);

		wp_localize_script( 'wcs-upgrade', 'wcs_update_script_data', $script_data );

		// Can't get subscription count with database structure < 1.4
		if ( 'false' == $script_data['really_old_version'] ) {
			$subscription_count = WC_Subscriptions::get_total_subscription_count();
			$estimated_duration = ceil( $subscription_count / 500 );
		}

@header( 'Content-Type: ' . get_option( 'html_type' ) . '; charset=' . get_option( 'blog_charset' ) ); ?>
<!DOCTYPE html>
<html xmlns="http://www.w3.org/1999/xhtml" <?php language_attributes(); ?>>
<head>
	<meta http-equiv="Content-Type" content="<?php bloginfo( 'html_type' ); ?>; charset=<?php echo get_option( 'blog_charset' ); ?>" />
	<title><?php _e( 'WooCommerce Subscriptions Update', 'woocommerce-subscriptions' ); ?></title>
	<?php wp_admin_css( 'install', true ); ?>
	<?php wp_admin_css( 'ie', true ); ?>
	<?php wp_print_styles( 'wcs-upgrade' ); ?>
	<?php wp_print_scripts( 'jquery' ); ?>
	<?php wp_print_scripts( 'wcs-upgrade' ); ?>
</head>
<body class="wp-core-ui">
<h1 id="logo"><img alt="WooCommerce Subscriptions" width="325px" height="120px" src="<?php echo plugins_url( 'images/woocommerce_subscriptions_logo.png', WC_Subscriptions::$plugin_file ); ?>" /></h1>
<div id="update-welcome">
	<h2><?php _e( 'Database Update Required', 'woocommerce-subscriptions' ); ?></h2>
	<p><?php _e( 'The WooCommerce Subscriptions plugin has been updated!', 'woocommerce-subscriptions' ); ?></p>
	<p><?php _e( 'Before we send you on your way, we need to update your database to the newest version. If you do not have a recent backup of your site, now is a good time to create one.', 'woocommerce-subscriptions' ); ?></p>
	<p><?php _e( 'The update process may take a little while, so please be patient.', 'woocommerce-subscriptions' ); ?></p>
	<form id="subscriptions-upgrade" method="get" action="<?php echo admin_url( 'admin.php' ); ?>">
		<input type="submit" class="button" value="<?php _e( 'Update Database', 'woocommerce-subscriptions' ); ?>">
	</form>
</div>
<div id="update-messages">
	<h2><?php _e( 'Update in Progress', 'woocommerce-subscriptions' ); ?></h2>
	<?php if ( 'false' == $script_data['really_old_version'] ) : ?>
	<p><?php printf( __( 'The full update process for the %s subscriptions on your site will take approximately %s to %s minutes.', 'woocommerce-subscriptions' ), $subscription_count, $estimated_duration, $estimated_duration * 2 ); ?></p>
	<?php endif; ?>
	<p><?php _e( 'This page will display the results of the process as each batch of subscriptions is updated. No need to refresh or restart the process. Customers and other non-administrative users will continue to be able to browse your site without interuption while the update is in progress.', 'woocommerce-subscriptions' ); ?></p>
	<ol>
	</ol>
	<img id="update-ajax-loader" alt="loading..." width="16px" height="16px" src="<?php echo plugins_url( 'images/ajax-loader@2x.gif', WC_Subscriptions::$plugin_file ); ?>" />
</div>
<div id="update-complete">
	<h2><?php _e( 'Update Complete', 'woocommerce-subscriptions' ); ?></h2>
	<p><?php _e( 'Your database has been successfully updated!', 'woocommerce-subscriptions' ); ?></p>
	<p class="step"><a class="button" href="<?php echo esc_url( self::$about_page_url ); ?>"><?php _e( 'Continue', 'woocommerce-subscriptions' ); ?></a></p>
</div>
<div id="update-error">
	<h2><?php _e( 'Update Error', 'woocommerce-subscriptions' ); ?></h2>
	<p><?php _e( 'There was an error with the update. Please refresh the page and try again.', 'woocommerce-subscriptions' ); ?></p>
</div>
</body>
</html>
<?php
	}

	/**
	 * Let the site administrator know we are upgrading the database already to prevent duplicate processes running the
	 * upgrade. Also provides some useful diagnostic information, like how long before the site admin can restart the
	 * upgrade process, and how many subscriptions per request can typically be updated given the amount of memory
	 * allocated to PHP.
	 *
	 * @since 1.4
	 */
	public static function upgrade_in_progress_notice() {

		$upgrade_transient_timeout = get_option( '_transient_timeout_wc_subscriptions_is_upgrading' );

		$time_until_update_allowed = $upgrade_transient_timeout - time();

		// Find out how many subscriptions can be processed before running out of memory on this installation. Subscriptions can process around 2500 with the usual 64M memory
		$memory_limit = ini_get( 'memory_limit' );
		$subscription_before_exhuastion = round( ( 3500 / 250 ) * str_replace( 'M', '', $memory_limit ) );

@header( 'Content-Type: ' . get_option( 'html_type' ) . '; charset=' . get_option( 'blog_charset' ) ); ?>
<!DOCTYPE html>
<html xmlns="http://www.w3.org/1999/xhtml" <?php language_attributes(); ?>>
<head>
	<meta http-equiv="Content-Type" content="<?php bloginfo( 'html_type' ); ?>; charset=<?php echo get_option( 'blog_charset' ); ?>" />
	<title><?php _e( 'WooCommerce Subscriptions Update in Progress', 'woocommerce-subscriptions' ); ?></title>
	<?php wp_admin_css( 'install', true ); ?>
	<?php wp_admin_css( 'ie', true ); ?>
</head>
<body class="wp-core-ui">
<h1 id="logo"><img alt="WooCommerce Subscriptions" width="325px" height="120px" src="<?php echo plugins_url( 'images/woocommerce_subscriptions_logo.png', WC_Subscriptions::$plugin_file ); ?>" /></h1>
<h2><?php _e( 'The Upgrade is in Progress', 'woocommerce-subscriptions' ); ?></h2>
<p><?php _e( 'The WooCommerce Subscriptions plugin is currently running its database upgrade routine.', 'woocommerce-subscriptions' ); ?></p>
<p><?php printf( __( 'If you received a server error and reloaded the page to find this notice, please refresh the page in %s seconds and the upgrade routine will recommence without issues. Subscriptions can update approximately %s subscriptions before exhausting the memory available on your PHP installation (which has %s allocated). It will update approxmiately 750 subscriptions per minute.', 'woocommerce-subscriptions' ), $time_until_update_allowed, $subscription_before_exhuastion, $memory_limit ); ?></p>
<p><?php _e( 'Rest assured, although the update process may take a little while, it is coded to prevent defects, your site is safe and will be up and running again, faster than ever, shortly.', 'woocommerce-subscriptions' ); ?></p>
</body>
</html>
<?php
	die();
	}

	public static function updated_welcome_page() {
		$about_page = add_dashboard_page( __( 'Welcome to WooCommerce Subscriptions 1.5', 'woocommerce-subscriptions' ), __( 'About WooCommerce Subscriptions', 'woocommerce-subscriptions' ), 'manage_options', 'wcs-about', __CLASS__ . '::about_screen' );
		add_action( 'admin_print_styles-'. $about_page, __CLASS__ . '::admin_css' );
		add_action( 'admin_head',  __CLASS__ . '::admin_head' );
	}

	/**
	 * admin_css function.
	 *
	 * @access public
	 * @return void
	 */
	public function admin_css() {
		wp_enqueue_style( 'woocommerce-subscriptions-about', plugins_url( '/css/about.css', WC_Subscriptions::$plugin_file ), array(), self::$active_version );
	}

	/**
	 * Add styles just for this page, and remove dashboard page links.
	 *
	 * @access public
	 * @return void
	 */
	public function admin_head() {
		remove_submenu_page( 'index.php', 'wcs-about' );
		remove_submenu_page( 'index.php', 'wcs-credits' );
		remove_submenu_page( 'index.php', 'wcs-translators' );
	}

	/**
	 * Output the about screen.
	 */
	public function about_screen() {
		$settings_page = admin_url( 'admin.php?page=wc-settings&tab=subscriptions' );
		?>
	<div class="wrap about-wrap">

		<h1><?php _e( 'Welcome to Subscriptions 1.5', 'woocommerce-subscriptions' ); ?></h1>

		<div class="about-text woocommerce-about-text">
			<?php _e( 'Thank you for updating to the latest version! Subscriptions 1.5 is more powerful, scalable, and reliable than ever before. We hope you enjoy it.', 'woocommerce-subscriptions' ); ?>
		</div>

		<div class="wcs-badge"><?php printf( __( 'Version 1.5', 'woocommerce-subscriptions' ), self::$active_version ); ?></div>

		<p class="woocommerce-actions">
			<a href="<?php echo $settings_page; ?>" class="button button-primary"><?php _e( 'Settings', 'woocommerce-subscriptions' ); ?></a>
			<a class="docs button button-primary" href="<?php echo esc_url( apply_filters( 'woocommerce_docs_url', 'http://docs.woothemes.com/documentation/subscriptions/', 'woocommerce-subscriptions' ) ); ?>"><?php _e( 'Docs', 'woocommerce-subscriptions' ); ?></a>
			<a href="https://twitter.com/share" class="twitter-share-button" data-url="http://www.woothemes.com/products/woocommerce-subscriptions/" data-text="I just upgraded to Subscriptions 1.5" data-via="WooThemes" data-size="large" data-hashtags="WooCommerce">Tweet</a>
<script>!function(d,s,id){var js,fjs=d.getElementsByTagName(s)[0];if(!d.getElementById(id)){js=d.createElement(s);js.id=id;js.src="//platform.twitter.com/widgets.js";fjs.parentNode.insertBefore(js,fjs);}}(document,"script","twitter-wjs");</script>
		</p>

		<div class="changelog">
			<h3><?php _e( "Check Out What's New", 'woocommerce-subscriptions' ); ?></h3>

			<div class="feature-section col two-col">
				<div>
					<img src="<?php echo plugins_url( '/images/customer-view-syncd-subscription-monthly.png', WC_Subscriptions::$plugin_file ); ?>" />
				</div>

				<div class="last-feature">
					<h4><?php _e( 'Renewal Synchronisation', 'woocommerce-subscriptions' ); ?></h4>
					<p><?php _e( 'Subscription renewal dates can now be aligned to a specific day of the week, month or year.', 'woocommerce-subscriptions' ); ?></p>
					<p><?php _e( 'If you sell physical goods and want to ship only on certain days, or sell memberships and want to align membership periods to a calendar day, WooCommerce Subscriptions can now work on your schedule.', 'woocommerce-subscriptions' ); ?></p>
					<p><?php printf( __( '%sEnable renewal synchronisation%s now or %slearn more%s about this feature.', 'woocommerce-subscriptions' ), '<a href="' . esc_url( $settings_page ) . '">', '</a>', '<a href="' .  esc_url( 'http://docs.woothemes.com/document/subscriptions/renewal-synchronisation/' ) . '">', '</a>' ); ?></p>
				</div>
			</div>
			<hr/>
			<div class="feature-section col two-col">
				<div>
					<h4><?php _e( 'Mixed Checkout', 'woocommerce-subscriptions' ); ?></h4>
					<p><?php _e( 'Simple, variable and other non-subscription products can now be purchased in the same transaction as a subscription product.', 'woocommerce-subscriptions' ); ?></p>
					<p><?php printf( __( 'This makes it easier for your customers to buy more from your store and soon it will also be possible to offer %sProduct Bundles%s & %sComposite Products%s which include a subscription.', 'woocommerce-subscriptions' ), '<a href="' . esc_url( 'http://www.woothemes.com/products/product-bundles/' ) . '">', '</a>', '<a href="' . esc_url( 'http://www.woothemes.com/products/composite-products/' ) . '">', '</a>' ); ?></p>
					<p><?php printf( __( '%sEnable mixed checkout%s now under the %sMiscellaneous%s settings section.', 'woocommerce-subscriptions' ), '<a href="' . esc_url( $settings_page ) . '">', '</a>', '<strong>', '</strong>' ); ?></p>
				</div>

				<div class="last-feature">
					<img src="<?php echo plugins_url( '/images/mixed-checkout.png', WC_Subscriptions::$plugin_file ); ?>" />
				</div>
			</div>
			<hr/>
			<div class="feature-section col two-col">
				<div>
					<img src="<?php echo plugins_url( '/images/subscription-quantities.png', WC_Subscriptions::$plugin_file ); ?>" />
				</div>

				<div class="last-feature">
					<h4><?php _e( 'Subscription Quantities', 'woocommerce-subscriptions' ); ?></h4>
					<p><?php _e( 'Your customers no longer need to purchase a subscription multiple times to access multiple quantities of the same product.', 'woocommerce-subscriptions' ); ?></p>
					<p><?php printf( __( 'For any subscription product not marked as %sSold Individually%s on the %sInventory%s tab%s of the %sEdit Product%s screen, your customers can now choose to purchase multiple quantities in the one transaction.', 'woocommerce-subscriptions' ), '<strong>', '</strong>', '<strong><a href="' . esc_url( 'http://docs.woothemes.com/document/managing-products/#inventory-tab' ) . '">', '</strong>', '</a>', '<strong>', '</strong>' ); ?></p>
					<p><?php printf( __( 'Your existing subscription products have been automatically set as %sSold Individually%s, so nothing will change for existing products, unless you want it to. Edit %ssubscription products%s.', 'woocommerce-subscriptions' ), '<strong>', '</strong>', '<a href="' . admin_url( 'edit.php?post_type=product&product_type=subscription' ) . '">', '</a>' ); ?></p>
				</div>
			</div>
		</div>
		<hr/>
		<div class="changelog">

			<div class="feature-section col three-col">

				<div>
					<img src="<?php echo plugins_url( '/images/responsive-subscriptions.png', WC_Subscriptions::$plugin_file ); ?>" />
					<h4><?php _e( 'Responsive Subscriptions Table', 'woocommerce-subscriptions' ); ?></h4>
					<p><?php printf( __( 'The default template for the %sMy Subscriptions%s table is now responsive to make it easy for your customers to view and manage their subscriptions on any device.', 'woocommerce-subscriptions' ), '<strong><a href="' . esc_url( 'http://docs.woothemes.com/document/subscriptions/customers-view/#section-1' ) . '">', '</a></strong>' ); ?></p>
				</div>

				<div>
					<img src="<?php echo plugins_url( '/images/subscription-switch-customer-email.png', WC_Subscriptions::$plugin_file ); ?>" />
					<h4><?php _e( 'Subscription Switch Emails', 'woocommerce-subscriptions' ); ?></h4>
					<p><?php printf( __( 'Subscriptions now sends two new emails when a customer upgrades or downgrades her subscription. Enable, disable or customise these emails on the %sEmail Settings%s screen.', 'woocommerce-subscriptions' ), '<strong><a href="' . admin_url( 'admin.php?page=wc-settings&tab=email&section=wcs_email_completed_switch_order' ) . '">', '</a></strong>' ); ?></p>
				</div>

				<div class="last-feature">
					<img src="<?php echo plugins_url( '/images/woocommerce-points-and-rewards-points-log.png', WC_Subscriptions::$plugin_file ); ?>" />
					<h4><?php _e( 'Points & Rewards', 'woocommerce-subscriptions' ); ?></h4>
					<p><?php printf( __( 'Support for the %sPoints & Rewards extension%s: points will now be rewarded for each subscription renewal.', 'woocommerce-subscriptions' ), '<a href="' . esc_url( 'http://www.woothemes.com/products/woocommerce-points-and-rewards/' ) . '">', '</a>' ); ?></p>
				</div>

			</div>
		</div>
		<hr/>
		<div class="changelog under-the-hood">

			<h3><?php _e( 'Under the Hood - New Scheduling System', 'woocommerce-subscriptions' ); ?></h3>
			<p><?php _e( 'Subscriptions 1.5 also introduces a completely new scheduling system - Action Scheduler.', 'woocommerce-subscriptions' ); ?></p>

			<div class="feature-section col three-col">
				<div>
					<h4><?php _e( 'Built to Sync', 'woocommerce-subscriptions' ); ?></h4>
					<p><?php _e( 'Introducing the new subscription synchronisation feature also introduces a new technical challenge - thousands of renewals may be scheduled for the same time.', 'woocommerce-subscriptions' ); ?></p>
					<p><?php _e( 'WordPress\'s scheduling system was not made to handle queues like that, but the new Action Scheduler is designed to process queues with thousands of renewals so you can sync subscriptions with confidence.', 'woocommerce-subscriptions' ); ?></p>
				</div>
				<div>
					<h4><?php _e( 'Built to Debug', 'woocommerce-subscriptions' ); ?></h4>
					<p><?php _e( 'When things go wrong, the more information available, the easier it is to diagnose and find a fix. Traditionally, a subscription renewal problem was tricky to diagnose because renewal happened in the background.', 'woocommerce-subscriptions' ); ?></p>
					<p><?php _e( 'Action Scheduler now logs important events around renewals and makes this and other important information available through a specially designed administration interface.', 'woocommerce-subscriptions' ); ?></p>
				</div>
				<div class="last-feature">
					<h4><?php _e( 'Built to Scale', 'woocommerce-subscriptions' ); ?></h4>
					<p><?php _e( 'The new Action Scheduler uses battle tested WordPress core functionality to ensure your site can scale its storage of scheduled subscription events, like an expiration date or renewal date, to handle thousands or even hundreds of thousands of subscriptions.', 'woocommerce-subscriptions' ); ?></p>
					<p><?php _e( 'We want stores of all sizes to be able to rely on WooCommerce Subscriptions.', 'woocommerce-subscriptions' ); ?></p>
				</div>
		</div>
		<hr/>
		<div class="return-to-dashboard">
			<a href="<?php echo esc_url( $settings_page ); ?>"><?php _e( 'Go to WooCommerce Subscriptions Settings', 'woocommerce-subscriptions' ); ?></a>
		</div>
	</div>
		<?php
	}
}
add_action( 'plugins_loaded', 'WC_Subscriptions_Upgrader::init', 10 );
