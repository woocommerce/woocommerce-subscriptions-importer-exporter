<?php
/**
 * Plugin Name: WooCommerce Subscriptions
 * Plugin URI: http://www.woothemes.com/products/woocommerce-subscriptions/
 * Description: Sell products and services with recurring payments in your WooCommerce Store.
 * Author: Brent Shepherd
 * Author URI: http://find.brentshepherd.com/
 * Version: 1.5.10
 *
 * Copyright 2014 Prospress, Inc.  (email : freedoms@prospress.com)
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 * @package		WooCommerce Subscriptions
 * @author		Brent Shepherd
 * @since		1.0
 */

/**
 * Required functions
 */
if ( ! function_exists( 'woothemes_queue_update' ) || ! function_exists( 'is_woocommerce_active' ) ) {
	require_once( 'woo-includes/woo-functions.php' );
}

/**
 * Plugin updates
 */
woothemes_queue_update( plugin_basename( __FILE__ ), '6115e6d7e297b623a169fdcf5728b224', '27147' );

/**
 * Check if WooCommerce is active, and if it isn't, disable Subscriptions.
 *
 * @since 1.0
 */
if ( ! is_woocommerce_active() || version_compare( get_option( 'woocommerce_db_version' ), '2.1', '<' ) ) {
	add_action( 'admin_notices', 'WC_Subscriptions::woocommerce_inactive_notice' );
	return;
}

require_once( 'classes/class-wc-subscriptions-coupon.php' );

require_once( 'classes/class-wc-subscriptions-product.php' );

require_once( 'classes/class-wc-subscriptions-admin.php' );

require_once( 'classes/class-wc-subscriptions-manager.php' );

require_once( 'classes/class-wc-subscriptions-cart.php' );

require_once( 'classes/class-wc-subscriptions-order.php' );

require_once( 'classes/class-wc-subscriptions-renewal-order.php' );

require_once( 'classes/class-wc-subscriptions-checkout.php' );

require_once( 'classes/class-wc-subscriptions-email.php' );

require_once( 'classes/class-wc-subscriptions-addresses.php' );

require_once( 'classes/class-wc-subscriptions-change-payment-gateway.php' );

require_once( 'classes/gateways/class-wc-subscriptions-payment-gateways.php' );

require_once( 'classes/gateways/gateway-paypal-standard-subscriptions.php' );

require_once( 'classes/class-wc-subscriptions-switcher.php' );

require_once( 'classes/class-wc-subscriptions-synchroniser.php' );

require_once( 'classes/class-wc-subscriptions-upgrader.php' );

require_once( 'lib/action-scheduler/action-scheduler.php' );


/**
 * The main subscriptions class.
 *
 * @since 1.0
 */
class WC_Subscriptions {

	public static $name = 'subscription';

	public static $activation_transient = 'woocommerce_subscriptions_activated';

	public static $plugin_file = __FILE__;

	public static $text_domain = 'deprecated-use-woocommerce-subscriptions-string';

	public static $version = '1.5.10';

	private static $total_subscription_count = null;

	private static $is_large_site = false;

	/**
	 * Set up the class, including it's hooks & filters, when the file is loaded.
	 *
	 * @since 1.0
	 **/
	public static function init() {

		add_action( 'admin_init', __CLASS__ . '::maybe_activate_woocommerce_subscriptions' );

		register_deactivation_hook( __FILE__, __CLASS__ . '::deactivate_woocommerce_subscriptions' );

		// Overide the WC default "Add to Cart" text to "Sign Up Now" (in various places/templates)
		add_filter( 'woocommerce_order_button_text', __CLASS__ . '::order_button_text' );
		add_action( 'woocommerce_subscription_add_to_cart', __CLASS__ . '::subscription_add_to_cart', 30 );

		// Redirect the user immediately to the checkout page after clicking "Sign Up Now" buttons to encourage immediate checkout
		add_filter( 'add_to_cart_redirect', __CLASS__ . '::add_to_cart_redirect' );

		// Ensure a subscription is never in the cart with products
		add_filter( 'woocommerce_add_to_cart_validation', __CLASS__ . '::maybe_empty_cart', 10, 3 );

		// Update Order totals via Ajax when a order form is updated
		add_action( 'wp_ajax_woocommerce_subscriptions_update_order_total', __CLASS__ . '::ajax_get_order_totals' );
		add_action( 'wp_ajax_nopriv_woocommerce_subscriptions_update_order_total', __CLASS__ . '::ajax_get_order_totals' );

		// Enqueue front-end styles
		add_filter( 'woocommerce_enqueue_styles', __CLASS__ . '::enqueue_styles', 10, 1 );

		// Display Subscriptions on a User's account page
		add_action( 'woocommerce_before_my_account', __CLASS__ . '::get_my_subscriptions_template' );

		// Load translation files
		add_action( 'plugins_loaded', __CLASS__ . '::load_plugin_textdomain' );

		// Load dependant files
		add_action( 'plugins_loaded', __CLASS__ . '::load_dependant_classes' );

		// WooCommerce 2.0 Notice
		add_action( 'admin_notices', __CLASS__ . '::woocommerce_dependancy_notice' );

		// Staging site or site migration notice
		add_action( 'admin_notices', __CLASS__ . '::woocommerce_site_change_notice' );

		// Add the "Settings | Documentation" links on the Plugins administration screen
		add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), __CLASS__ . '::action_links' );

		add_filter( 'action_scheduler_queue_runner_batch_size', __CLASS__ . '::action_scheduler_multisite_batch_size' );
	}

	/**
	 * Enqueues stylesheet for the My Subscriptions table on the My Account page.
	 *
	 * @since 1.5
	 */
	public static function enqueue_styles( $styles ) {

		if ( is_page( get_option( 'woocommerce_myaccount_page_id' ) ) ) {
			$styles['woocommerce-subscriptions'] = array(
				'src'     => str_replace( array( 'http:', 'https:' ), '', plugin_dir_url( __FILE__ )  ) . 'css/woocommerce-subscriptions.css',
				'deps'    => 'woocommerce-smallscreen',
				'version' => WC_VERSION,
				'media'   => ''
			);
		}

		return $styles;
	}

	/**
	 * Loads the my-subscriptions.php template on the My Account page.
	 *
	 * @since 1.0
	 */
	public static function get_my_subscriptions_template() {

		$subscriptions = WC_Subscriptions_Manager::get_users_subscriptions();

		$user_id = get_current_user_id();

		$all_actions = array();

		foreach ( $subscriptions as $subscription_key => $subscription_details ) {

			$actions = array();

			if ( $subscription_details['status'] == 'trash' ) {
				unset( $subscriptions[ $subscription_key ] );
				continue;
			}

			$admin_with_suspension_disallowed = ( current_user_can( 'manage_woocommerce' ) && 0 == get_option( WC_Subscriptions_Admin::$option_prefix . '_max_customer_suspensions', 0 ) ) ? true : false;
			if ( WC_Subscriptions_Manager::can_subscription_be_changed_to( 'on-hold', $subscription_key, $user_id ) && WC_Subscriptions_Manager::current_user_can_suspend_subscription( $subscription_key ) && ! $admin_with_suspension_disallowed ) {
				$actions['suspend'] = array(
					'url'  => WC_Subscriptions_Manager::get_users_change_status_link( $subscription_key, 'on-hold' ),
					'name' => __( 'Suspend', 'woocommerce-subscriptions' )
				);
			} elseif ( WC_Subscriptions_Manager::can_subscription_be_changed_to( 'active', $subscription_key, $user_id ) && ! WC_Subscriptions_Manager::subscription_requires_payment( $subscription_key, $user_id ) ) {
				$actions['reactivate'] = array(
					'url'  => WC_Subscriptions_Manager::get_users_change_status_link( $subscription_key, 'active' ),
					'name' => __( 'Reactivate', 'woocommerce-subscriptions' )
				);
			}

			if ( WC_Subscriptions_Renewal_Order::can_subscription_be_renewed( $subscription_key, $user_id ) ) {
				$actions['renew'] = array(
					'url'  => WC_Subscriptions_Renewal_Order::get_users_renewal_link( $subscription_key ),
					'name' => __( 'Renew', 'woocommerce-subscriptions' )
				);
			}

			$renewal_orders = WC_Subscriptions_Renewal_Order::get_renewal_orders( $subscription_details['order_id'], 'ID' );

			$last_order_id = end( $renewal_orders );

			if ( $last_order_id ) {

				$renewal_order = new WC_Order( $last_order_id );

				if ( WC_Subscriptions_Manager::can_subscription_be_changed_to( 'active', $subscription_key, $user_id ) && in_array( $renewal_order->status, array( 'pending', 'failed' ) ) && ! is_numeric( get_post_meta( $renewal_order->id, '_failed_order_replaced_by', true ) ) ) {
					$actions['pay'] = array(
						'url'  => $renewal_order->get_checkout_payment_url(),
						'name' => __( 'Pay', 'woocommerce-subscriptions' )
					);
				}

			} else { // Check if the master order still needs to be paid

				$order = new WC_Order( $subscription_details['order_id'] );

				if ( 'pending' == $order->status && WC_Subscriptions_Manager::can_subscription_be_changed_to( 'active', $subscription_key, $user_id ) ) {
					$actions['pay'] = array(
						'url'  => $order->get_checkout_payment_url(),
						'name' => __( 'Pay', 'woocommerce-subscriptions' )
					);
				}
			}

			if ( WC_Subscriptions_Manager::can_subscription_be_changed_to( 'cancelled', $subscription_key, $user_id ) && $subscription_details['interval'] != $subscription_details['length'] ) {
				$actions['cancel'] = array(
					'url'  => WC_Subscriptions_Manager::get_users_change_status_link( $subscription_key, 'cancelled' ),
					'name' => __( 'Cancel', 'woocommerce-subscriptions' )
				);
			}

			$all_actions[ $subscription_key ] = $actions;
		}

		$all_actions = apply_filters( 'woocommerce_my_account_my_subscriptions_actions', $all_actions, $subscriptions );

		woocommerce_get_template( 'myaccount/my-subscriptions.php', array( 'subscriptions' => $subscriptions, 'actions' => $all_actions, 'user_id' => $user_id ), '', plugin_dir_path( __FILE__ ) . 'templates/' );
	}

	/**
	 * Output a redirect URL when an item is added to the cart when a subscription was already in the cart.
	 *
	 * @since 1.0
	 */
	public static function redirect_ajax_add_to_cart( $fragments ) {
		global $woocommerce;

		$data = array(
			'error' => true,
			'product_url' => $woocommerce->cart->get_cart_url()
		);

		return $data;
	}

	/**
	 * When a subscription is added to the cart, remove other products/subscriptions to
	 * work with PayPal Standard, which only accept one subscription per checkout.
	 *
	 * If multiple purchase flag is set, allow them to be added at the same time.
	 *
	 * @since 1.0
	 */
	public static function maybe_empty_cart( $valid, $product_id, $quantity ) {
		global $woocommerce;

		if ( WC_Subscriptions_Product::is_subscription( $product_id ) && 'yes' != get_option( WC_Subscriptions_Admin::$option_prefix . '_multiple_purchase', 'no' ) ) {

			$woocommerce->cart->empty_cart();

		} elseif ( WC_Subscriptions_Product::is_subscription( $product_id ) && WC_Subscriptions_Cart::cart_contains_subscription_renewal( 'child' ) ) {

			self::remove_subscriptions_from_cart();

			self::add_notice( __( 'A subscription renewal has been removed from your cart. Multiple subscriptions can not be purchased at the same time.', 'woocommerce-subscriptions' ), 'notice' );

		} elseif ( WC_Subscriptions_Product::is_subscription( $product_id ) && WC_Subscriptions_Cart::cart_contains_subscription() ) {

			self::remove_subscriptions_from_cart();

			self::add_notice( __( 'A subscription has been removed from your cart. Multiple subscriptions can not be purchased at the same time.', 'woocommerce-subscriptions' ), 'notice' );

		} elseif ( WC_Subscriptions_Cart::cart_contains_subscription() && 'yes' != get_option( WC_Subscriptions_Admin::$option_prefix . '_multiple_purchase', 'no' ) ) {

			self::remove_subscriptions_from_cart();

			self::add_notice( __( 'A subscription has been removed from your cart. Products and subscriptions can not be purchased at the same time.', 'woocommerce-subscriptions' ), 'notice' );

			// Redirect to cart page to remove subscription & notify shopper
			add_filter( 'add_to_cart_fragments', __CLASS__ . '::redirect_ajax_add_to_cart' );

		}

		return $valid;
	}

	/**
	 * Removes all subscription products from the shopping cart.
	 *
	 * @since 1.0
	 */
	public static function remove_subscriptions_from_cart() {
		global $woocommerce;

		foreach( $woocommerce->cart->cart_contents as $cart_item_key => $cart_item ) {
			if ( WC_Subscriptions_Product::is_subscription( $cart_item['product_id'] ) ) {
				$woocommerce->cart->set_quantity( $cart_item_key, 0 );
			}
		}
	}

	/**
	 * For a smoother sign up process, tell WooCommerce to redirect the shopper immediately to
	 * the checkout page after she clicks the "Sign Up Now" button
	 *
	 * Only enabled if multiple checkout is not enabled.
	 *
	 * @param string $url The cart redirect $url WooCommerce determined.
	 * @since 1.0
	 */
	public static function add_to_cart_redirect( $url ) {

		// If product is of the subscription type
		if ( is_numeric( $_REQUEST['add-to-cart'] ) && WC_Subscriptions_Product::is_subscription( (int) $_REQUEST['add-to-cart'] ) && 'yes' != get_option( WC_Subscriptions_Admin::$option_prefix . '_multiple_purchase', 'no' ) ) {

			wc_clear_notices();

			// Redirect to checkout
			$url = WC()->cart->get_checkout_url();
		}

		return $url;
	}

	/**
	 * Override the WooCommerce "Place Order" text with "Sign Up Now"
	 *
	 * @since 1.0
	 */
	public static function order_button_text( $button_text ) {
		global $product;

		if ( WC_Subscriptions_Cart::cart_contains_subscription() ) {
			$button_text = get_option( WC_Subscriptions_Admin::$option_prefix . '_order_button_text', __( 'Sign Up Now', 'woocommerce-subscriptions' ) );
		}

		return $button_text;
	}

	/**
	 * Load the subscription add_to_cart template.
	 *
	 * Use the same cart template for subscription as that which is used for simple products. Reduce code duplication
	 * and is made possible by the friendly actions & filters found through WC.
	 *
	 * Not using a custom template both prevents code duplication and helps future proof this extension from core changes.
	 *
	 * @since 1.0
	 */
	public static function subscription_add_to_cart() {
		woocommerce_get_template( 'single-product/add-to-cart/subscription.php', array(), '', plugin_dir_path( __FILE__ ) . 'templates/' );
	}

	/**
	 * Takes a number and returns the number with its relevant suffix appended, eg. for 2, the function returns 2nd
	 *
	 * @since 1.0
	 */
	public static function append_numeral_suffix( $number ) {

		// Handle teens: if the tens digit of a number is 1, then write "th" after the number. For example: 11th, 13th, 19th, 112th, 9311th. http://en.wikipedia.org/wiki/English_numerals
		if ( strlen( $number ) > 1 && 1 == substr( $number, -2, 1 ) ) {
			$number_string = sprintf( __( '%sth', 'woocommerce-subscriptions' ), $number );
		} else { // Append relevant suffix
			switch( substr( $number, -1 ) ) {
				case 1:
					$number_string = sprintf( __( '%sst', 'woocommerce-subscriptions' ), $number );
					break;
				case 2:
					$number_string = sprintf( __( '%snd', 'woocommerce-subscriptions' ), $number );
					break;
				case 3:
					$number_string = sprintf( __( '%srd', 'woocommerce-subscriptions' ), $number );
					break;
				default:
					$number_string = sprintf( __( '%sth', 'woocommerce-subscriptions' ), $number );
					break;
			}
		}

		return apply_filters( 'woocommerce_numeral_suffix', $number_string, $number );
	}


	/*
	 * Plugin House Keeping
	 */

	/**
	 * Called when WooCommerce is inactive to display an inactive notice.
	 *
	 * @since 1.2
	 */
	public static function woocommerce_inactive_notice() {
		if ( current_user_can( 'activate_plugins' ) ) :
			if ( ! is_woocommerce_active() ) : ?>
<div id="message" class="error">
	<p><?php printf( __( '%sWooCommerce Subscriptions is inactive.%s The %sWooCommerce plugin%s must be active for WooCommerce Subscriptions to work. Please %sinstall & activate WooCommerce%s', 'woocommerce-subscriptions' ), '<strong>', '</strong>', '<a href="http://wordpress.org/extend/plugins/woocommerce/">', '</a>', '<a href="' . admin_url( 'plugins.php' ) . '">', '&nbsp;&raquo;</a>' ); ?></p>
</div>
		<?php elseif ( version_compare( get_option( 'woocommerce_db_version' ), '2.1', '<' ) ) : ?>
<div id="message" class="error">
	<p><?php printf( __( '%sWooCommerce Subscriptions is inactive.%s This version of Subscriptions requires WooCommerce 2.1 or newer. Please %supdate WooCommerce to version 2.1 or newer%s', 'woocommerce-subscriptions' ), '<strong>', '</strong>', '<a href="' . admin_url( 'plugins.php' ) . '">', '&nbsp;&raquo;</a>' ); ?></p>
</div>
		<?php endif; ?>
	<?php endif;
	}

	/**
	 * Checks on each admin page load if Subscriptions plugin is activated.
	 *
	 * Apparently the official WP API is "lame" and it's far better to use an upgrade routine fired on admin_init: http://core.trac.wordpress.org/ticket/14170
	 *
	 * @since 1.1
	 */
	public static function maybe_activate_woocommerce_subscriptions(){
		global $wpdb;

		$is_active = get_option( WC_Subscriptions_Admin::$option_prefix . '_is_active', false );

		if ( $is_active == false ) {

			// Add the "Subscriptions" product type
			if ( ! get_term_by( 'slug', self::$name, 'product_type' ) ) {
				wp_insert_term( self::$name, 'product_type' );
			}

			// Maybe add the "Variable Subscriptions" product type
			if ( ! get_term_by( 'slug', 'variable-subscription', 'product_type' ) ) {
				wp_insert_term( __( 'Variable Subscription', 'woocommerce-subscriptions' ), 'product_type' );
			}

			// If no Subscription settings exist, its the first activation, so add defaults
			if ( get_option( WC_Subscriptions_Admin::$option_prefix . '_cancelled_role', false ) == false ) {
				WC_Subscriptions_Admin::add_default_settings();
			}

			add_option( WC_Subscriptions_Admin::$option_prefix . '_is_active', true );

			set_transient( self::$activation_transient, true, 60 * 60 );

			do_action( 'woocommerce_subscriptions_activated' );
		}

	}

	/**
	 * Called when the plugin is deactivated. Deletes the subscription product type and fires an action.
	 *
	 * @since 1.0
	 */
	public static function deactivate_woocommerce_subscriptions() {

		delete_option( WC_Subscriptions_Admin::$option_prefix . '_is_active' );

		do_action( 'woocommerce_subscriptions_deactivated' );
	}

	/**
	 * Called on plugins_loaded to load any translation files.
	 *
	 * @since 1.1
	 */
	public static function load_plugin_textdomain(){

		$locale = apply_filters( 'plugin_locale', get_locale(), 'woocommerce-subscriptions' );

		// Allow upgrade safe, site specific language files in /wp-content/languages/woocommerce-subscriptions/
		load_textdomain( 'woocommerce-subscriptions', WP_LANG_DIR.'/woocommerce/woocommerce-subscriptions-'.$locale.'.mo' );

		$plugin_rel_path = apply_filters( 'woocommerce_subscriptions_translation_file_rel_path', dirname( plugin_basename( __FILE__ ) ) . '/languages' );

		// Then check for a language file in /wp-content/plugins/woocommerce-subscriptions/languages/ (this will be overriden by any file already loaded)
		load_plugin_textdomain( 'woocommerce-subscriptions', false, $plugin_rel_path );
	}

	/**
	 * Loads classes that depend on WooCommerce base classes.
	 *
	 * @since 1.2.4
	 */
	public static function load_dependant_classes() {
		global $woocommerce;

		if ( version_compare( $woocommerce->version, '2.0', '>=' ) ) {

			require_once( 'classes/class-wc-product-subscription.php' );

			require_once( 'classes/class-wc-product-subscription-variation.php' );

			require_once( 'classes/class-wc-product-variable-subscription.php' );
		}
	}

	/**
	 * Displays a notice to upgrade if using less than the ideal version of WooCommerce
	 *
	 * @since 1.3
	 */
	public static function woocommerce_dependancy_notice() {
		global $woocommerce;

		if ( version_compare( $woocommerce->version, '2.0', '<' ) && current_user_can( 'install_plugins' ) ) { ?>
<div id="message" class="error">
	<p><?php printf( __( '%sYou have an out-of-date version of WooCommerce installed%s. WooCommerce Subscriptions no longer supports versions of WooCommerce prior to 2.0. Please %supgrade WooCommerce to version 2.0 or newer%s to avoid issues.', 'woocommerce-subscriptions' ), '<strong>', '</strong>', '<a href="' . admin_url( 'plugins.php' ) . '">', '</a>' ); ?></p>
</div>
<?php
		} elseif ( version_compare( $woocommerce->version, '2.0.16', '<' ) && current_user_can( 'install_plugins' ) ) { ?>
<div id="message" class="error">
	<p><?php printf( __( '%sYou have an out-of-date version of WooCommerce installed%s. WooCommerce Subscriptions requires WooCommerce 2.0.16 or newer. Please %supdate WooCommerce to the latest version%s.', 'woocommerce-subscriptions' ), '<strong>', '</strong>', '<a href="' . admin_url( 'plugins.php' ) . '">', '</a>' ); ?></p>
</div>
<?php
		}
	}

	/**
	 * Displays a notice when Subscriptions is being run on a different site, like a staging or testing site.
	 *
	 * @since 1.3.8
	 */
	public static function woocommerce_site_change_notice() {
		global $woocommerce;

		if ( self::is_duplicate_site() && current_user_can( 'manage_options' ) ) {

			if ( isset( $_POST['wc_subscription_duplicate_site'] ) ) {

				if ( 'update' === $_POST['wc_subscription_duplicate_site'] ) {

					WC_Subscriptions::set_duplicate_site_url_lock();

				} elseif ( 'ignore' === $_POST['wc_subscription_duplicate_site'] ) {

					update_option( 'wcs_ignore_duplicate_siteurl_notice', self::get_current_sites_duplicate_lock() );

				}

			} elseif ( self::get_current_sites_duplicate_lock() !== get_option( 'wcs_ignore_duplicate_siteurl_notice' ) ) { ?>
<div id="message" class="error">
<p><?php printf( __( 'It looks like this site has moved or is a duplicate site. %sWooCommerce Subscriptions%s has disabled automatic payments and subscription related emails on this site to prevent duplicate payments from a staging or test environment. %sLearn more%s', 'woocommerce-subscriptions' ), '<strong>', '</strong>', '<a href="http://docs.woothemes.com/document/subscriptions/faq/#section-39" target="_blank">', '&raquo;</a>' ); ?></p>
<form action="" style="margin: 5px 0;" method="POST">
	<button class="button button-primary" name="wc_subscription_duplicate_site" value="ignore"><?php _e( 'Quit nagging me (but don\'t enable automatic payments)', 'woocommerce-subscriptions' ); ?></button>
	<button class="button" name="wc_subscription_duplicate_site" value="update"><?php _e( 'Enable automatic payments', 'woocommerce-subscriptions' ); ?></button>
</form>
</div>
<?php
			}
		}
	}

	/**
	 * Get's a WC_Product using the new core WC @see get_product() function if available, otherwise
	 * instantiating an instance of the WC_Product class.
	 *
	 * @since 1.2.4
	 */
	public static function get_product( $product_id ) {

		if ( function_exists( 'get_product' ) ) {
			$product = get_product( $product_id );
		} else {
			$product = new WC_Product( $product_id );  // Shouldn't matter if product is variation as all we need is the product_type
		}

		return $product;
	}

	/**
	 * A general purpose function for grabbing an array of subscriptions in form of 'subscription_key' => 'subscription_details'.
	 *
	 * The $args param is based on the parameter of the same name used by the core WordPress @see get_posts() function.
	 * It can be used to choose which subscriptions should be returned by the function, how many subscriptions should be returned
	 * and in what order those subscriptions should be returned.
	 *
	 * @param array $args A set of name value pairs to determine the return value.
	 *		'subscriptions_per_page' The number of subscriptions to return. Set to -1 for unlimited. Default 10.
	 *		'offset' An optional number of subscription to displace or pass over. Default 0.
	 *		'orderby' The field which the subscriptions should be ordered by. Can be 'start_date', 'expiry_date', 'end_date', 'status', 'name' or 'order_id'. Defaults to 'start_date'.
	 *		'order' The order of the values returned. Can be 'ASC' or 'DESC'. Defaults to 'DESC'
	 *		'customer_id' The user ID of a customer on the site.
	 *		'product_id' The post ID of a WC_Product_Subscription, WC_Product_Variable_Subscription or WC_Product_Subscription_Variation object
	 *		'subscription_status' Any valid subscription status. Can be 'any', 'active', 'cancelled', 'suspended', 'expired', 'pending' or 'trash'. Defaults to 'any'.
	 * @return array Subscription details in 'subscription_key' => 'subscription_details' form.
	 * @since 1.4
	 */
	public static function get_subscriptions( $args = array() ) {
		global $wpdb;

		$args = wp_parse_args( $args, array(
				'subscriptions_per_page' => 10,
				'paged'                  => 1,
				'offset'                 => 0,
				'orderby'                => '_subscription_start_date',
				'order'                  => 'DESC',
				'customer_id'            => '',
				'product_id'             => '',
				'variation_id'           => '',
				'order_id'               => array(),
				'subscription_status'    => 'any',
			)
		);

		// Map human friendly order_by values to internal keys
		switch ( $args['orderby'] ) {
			case 'status' :
				$args['orderby'] = '_subscription_status';
				break;
			case 'start_date' :
				$args['orderby'] = '_subscription_start_date';
				break;
			case 'expiry_date' :
				$args['orderby'] = '_subscription_expiry_date';
				break;
			case 'trial_expiry_date' :
				$args['orderby'] = '_subscription_trial_expiry_date';
				break;
			case 'end_date' :
				$args['orderby'] = '_subscription_end_date';
				break;
		}

		$subscriptions = array();

		// First see if we're paging, so the limit can be applied to subqueries
		if ( -1 !== $args['subscriptions_per_page'] ) {

			$per_page   = absint( $args['subscriptions_per_page'] );
			$page_start = '';

			if ( 0 == $args['paged'] ) {
				$args['paged'] = 1;
			}

			if ( $args['paged'] ) {
				$page_start = absint( $args['paged'] - 1 ) * $per_page . ', ';
			} elseif ( $args['offset'] ) {
				$page_start = absint( $args['offset'] ) . ', ';
			}

			$limit_query = '
				LIMIT ' . $page_start . $per_page;

		} else {

			$limit_query = '';

		}

		if ( 'DESC' === $args['order'] ) {
			$order_query = ' DESC';
		} else {
			$order_query = ' ASC';
		}

		// Now start building the actual query
		$query = "
			SELECT meta.*, items.*, r.renewal_order_count, (CASE WHEN (r.renewal_order_count > 0) THEN l.last_payment_date ELSE o.order_date END) AS last_payment_date FROM `{$wpdb->prefix}woocommerce_order_itemmeta` AS meta
			LEFT JOIN `{$wpdb->prefix}woocommerce_order_items` AS items USING (order_item_id)";

		$query .= "
			LEFT JOIN (
				SELECT a.order_item_id FROM `{$wpdb->prefix}woocommerce_order_itemmeta` AS a";

		// To filter order items by a specific product ID, we need to do another join
		if ( ! empty( $args['product_id'] ) || ! empty( $args['variation_id'] ) ) {

			// Can only specify a product ID or a variation ID, no need to specify a product ID if you want a variation of that product
			$meta_key = ! empty( $args['variation_id'] ) ? '_variation_id' : '_product_id';

			$query .= sprintf( "
				LEFT JOIN (
					SELECT `{$wpdb->prefix}woocommerce_order_itemmeta`.order_item_id FROM `{$wpdb->prefix}woocommerce_order_itemmeta`
					WHERE `{$wpdb->prefix}woocommerce_order_itemmeta`.meta_key = '%s'
					AND `{$wpdb->prefix}woocommerce_order_itemmeta`.meta_value = %s
				) AS p
				USING (order_item_id)",
				$meta_key,
				$args['product_id']
			);

		}

		// To filter order items by a specific subscription status, we need to do another join (unless we're also ordering by subscription status)
		if ( ! empty( $args['subscription_status'] ) && 'any' !== $args['subscription_status'] && '_subscription_status' !== $args['orderby'] ) {

			if ( 'all' == $args['subscription_status'] ) { // get all but trashed subscriptions

				$query .= "
				LEFT JOIN (
					SELECT `{$wpdb->prefix}woocommerce_order_itemmeta`.order_item_id FROM `{$wpdb->prefix}woocommerce_order_itemmeta`
					WHERE `{$wpdb->prefix}woocommerce_order_itemmeta`.meta_key = '_subscription_status'
					AND `{$wpdb->prefix}woocommerce_order_itemmeta`.meta_value != 'trash'
				) AS s
				USING (order_item_id)";

			} else {

				$query .= sprintf( "
				LEFT JOIN (
					SELECT `{$wpdb->prefix}woocommerce_order_itemmeta`.order_item_id FROM `{$wpdb->prefix}woocommerce_order_itemmeta`
					WHERE `{$wpdb->prefix}woocommerce_order_itemmeta`.meta_key = '_subscription_status'
					AND `{$wpdb->prefix}woocommerce_order_itemmeta`.meta_value = '%s'
				) AS s
				USING (order_item_id)",
				$args['subscription_status']
				);

			}

		}

		// We need an additional join when ordering by certain attributes
		switch ( $args['orderby'] ) {
			case '_product_id': // Because all products have a product ID, but not all products are subscriptions
				if ( empty( $args['product_id'] ) ) {
					$query .= "
				LEFT JOIN (
					SELECT `{$wpdb->prefix}woocommerce_order_itemmeta`.order_item_id FROM `{$wpdb->prefix}woocommerce_order_itemmeta`
					WHERE `{$wpdb->prefix}woocommerce_order_itemmeta`.meta_key LIKE '_subscription_%s'
					GROUP BY `{$wpdb->prefix}woocommerce_order_itemmeta`.order_item_id
				) AS a2 USING (order_item_id)";
				}
				break;
			case '_order_item_name': // Because the order item name is found in the order_items tables
			case 'name':
				if ( empty( $args['product_id'] ) ) {
					$query .= "
				LEFT JOIN (
					SELECT `{$wpdb->prefix}woocommerce_order_items`.order_item_id, `{$wpdb->prefix}woocommerce_order_items`.order_item_name FROM `{$wpdb->prefix}woocommerce_order_items`
					WHERE `{$wpdb->prefix}woocommerce_order_items`.order_item_type = 'line_item'
				) AS names USING (order_item_id)";
				}
				break;
			case 'order_id': // Because the order ID is found in the order_items tables
				$query .= "
				LEFT JOIN (
					SELECT `{$wpdb->prefix}woocommerce_order_items`.order_item_id, `{$wpdb->prefix}woocommerce_order_items`.order_id FROM `{$wpdb->prefix}woocommerce_order_items`
					WHERE `{$wpdb->prefix}woocommerce_order_items`.order_item_type = 'line_item'
				) AS order_ids USING (order_item_id)";
				break;
			case 'renewal_order_count':
				$query .= "
				LEFT JOIN (
					SELECT items2.order_item_id, items2.order_id, r2.renewal_order_count FROM `{$wpdb->prefix}woocommerce_order_items` AS items2
					LEFT JOIN (
						SELECT posts.post_parent, COUNT(posts.ID) as renewal_order_count FROM `{$wpdb->prefix}posts` AS posts
						WHERE posts.post_parent != 0
						AND posts.post_type = 'shop_order'
						GROUP BY posts.post_parent
					) AS r2 ON r2.post_parent = items2.order_id
					WHERE items2.order_item_type = 'line_item'
				) AS renewals USING (order_item_id)";
				break;
			case 'user_display_name':
			case 'user':
				if ( empty( $args['customer_id'] ) ) {
					$query .= "
				LEFT JOIN (
					SELECT items2.order_item_id, items2.order_id, users.display_name FROM `{$wpdb->prefix}woocommerce_order_items` AS items2
					LEFT JOIN (
						SELECT postmeta.post_id, postmeta.meta_value, u.display_name FROM `{$wpdb->prefix}postmeta` AS postmeta
						LEFT JOIN (
							SELECT `{$wpdb->prefix}users`.ID, `{$wpdb->prefix}users`.display_name FROM `{$wpdb->prefix}users`
						) AS u ON u.ID = postmeta.meta_value
						WHERE postmeta.meta_key = '_customer_user'
					) AS users ON users.post_id = items2.order_id
					WHERE items2.order_item_type = 'line_item'
				) AS users_items USING (order_item_id)";
				}
			case 'last_payment_date': // Because we need the date of the last renewal order (or maybe the original order if there are no renewal orders)
				$query .= "
				LEFT JOIN (
					SELECT items2.order_item_id, (CASE WHEN (r2.renewal_order_count > 0) THEN l.last_payment_date ELSE o.order_date END) AS last_payment_date FROM `{$wpdb->prefix}woocommerce_order_items` AS items2
					LEFT JOIN (
						SELECT posts.post_parent, COUNT(posts.ID) as renewal_order_count FROM `{$wpdb->prefix}posts` AS posts
						WHERE posts.post_parent != 0
						AND posts.post_type = 'shop_order'
						GROUP BY posts.post_parent
					) AS r2 ON r2.post_parent = items2.order_id
					LEFT JOIN (
						SELECT o.ID, o.post_date_gmt AS order_date FROM `{$wpdb->prefix}posts` AS o
						WHERE o.post_type = 'shop_order'
						AND o.post_parent = 0
					) AS o ON o.ID = items2.order_id
					LEFT JOIN (
						SELECT p.ID, p.post_parent, MAX(p.post_date_gmt) AS last_payment_date FROM `{$wpdb->prefix}posts` AS p
						WHERE p.post_type = 'shop_order'
						AND p.post_parent != 0
						GROUP BY p.post_parent
					) AS l ON l.post_parent = items2.order_id
					WHERE items2.order_item_type = 'line_item'
				) AS payment_dates USING (order_item_id)";
				break;
		}

		// Start where query
		$query .= "
				WHERE 1=1";

		// We only want subscriptions from within the product filter subclause
		if ( ! empty( $args['product_id'] ) || ! empty( $args['variation_id'] ) ) {
			$query .= "
				AND a.order_item_id = p.order_item_id";
		}

		// We only want subscriptions from within the status filter subclause
		if ( ! empty( $args['subscription_status'] ) && 'any' !== $args['subscription_status'] && '_subscription_status' !== $args['orderby'] ) {
			$query .= "
				AND a.order_item_id = s.order_item_id";
		}

		// We only want items from a certain order
		if ( ! empty( $args['order_id'] ) ) {

			$order_ids = is_array( $args['order_id'] ) ? implode( ',', $args['order_id'] ) : $args['order_id'];

			$query .= sprintf( "
				AND a.order_item_id IN (
					SELECT o.order_item_id FROM `{$wpdb->prefix}woocommerce_order_items` AS o
					WHERE o.order_id IN (%s)
				)", $order_ids );
		}

		// If we only want subscriptions for a certain customer ID, we need to make sure items are from the customer's orders
		if ( ! empty( $args['customer_id'] ) ) {
			$query .= sprintf( "
				AND a.order_item_id IN (
					SELECT `{$wpdb->prefix}woocommerce_order_items`.order_item_id FROM `{$wpdb->prefix}woocommerce_order_items`
					WHERE `{$wpdb->prefix}woocommerce_order_items`.order_id IN (
						SELECT `{$wpdb->prefix}postmeta`.post_id FROM `{$wpdb->prefix}postmeta`
						WHERE `{$wpdb->prefix}postmeta`.meta_key = '_customer_user'
						AND `{$wpdb->prefix}postmeta`.meta_value = %s
					)
				)", $args['customer_id'] );
		}

		// Now we need to sort the subscriptions, which may mean selecting a specific bit of meta data
		switch ( $args['orderby'] ) {
			case '_subscription_start_date':
			case '_subscription_expiry_date':
			case '_subscription_trial_expiry_date':
			case '_subscription_end_date':
				$query .= sprintf( "
				AND a.meta_key = '%s'
				ORDER BY CASE WHEN CAST(a.meta_value AS DATETIME) IS NULL THEN 1 ELSE 0 END, CAST(a.meta_value AS DATETIME) %s", $args['orderby'], $order_query );
				break;
			case '_subscription_status':
				$query .= "
				AND a.meta_key = '_subscription_status'
				ORDER BY a.meta_value" . $order_query;
				break;
			case '_product_id':
				if ( empty( $args['product_id'] ) ) {
					$query .= "
				AND a2.order_item_id = a.order_item_id
				AND a.meta_key = '_product_id'
				ORDER BY a.meta_value" . $order_query;
				}
				break;
			case '_order_item_name':
			case 'name':
				if ( empty( $args['product_id'] ) ) {
					$query .= "
				AND a.meta_key = '_subscription_start_date'
				AND names.order_item_id = a.order_item_id
				ORDER BY names.order_item_name" . $order_query  . ", CASE WHEN CAST(a.meta_value AS DATETIME) IS NULL THEN 1 ELSE 0 END, CAST(a.meta_value AS DATETIME) DESC";
				}
				break;
			case 'order_id':
				$query .= "
				AND a.meta_key = '_subscription_start_date'
				AND order_ids.order_item_id = a.order_item_id
				ORDER BY order_ids.order_id" . $order_query;
				break;
			case 'renewal_order_count':
				$query .= "
				AND a.meta_key = '_subscription_start_date'
				AND renewals.order_item_id = a.order_item_id
				ORDER BY renewals.renewal_order_count" . $order_query;
				break;
			case 'user_display_name':
			case 'user':
				if ( empty( $args['customer_id'] ) ) {
					$query .= "
				AND a.meta_key = '_subscription_start_date'
				AND users_items.order_item_id = a.order_item_id
				ORDER BY users_items.display_name" . $order_query  . ", CASE WHEN CAST(a.meta_value AS DATETIME) IS NULL THEN 1 ELSE 0 END, CAST(a.meta_value AS DATETIME) DESC";
				}
				break;
			case 'last_payment_date':
				$query .= "
				AND a.meta_key = '_subscription_start_date'
				AND payment_dates.order_item_id = a.order_item_id
				ORDER BY payment_dates.last_payment_date" . $order_query;
				break;
		}

		// Paging
		if ( -1 !== $args['subscriptions_per_page'] ) {
			$query .= $limit_query;
		}

		$query .= "
			) AS a3 USING (order_item_id)";

		// Add renewal order count & last payment date (there is duplication here when ordering by renewal order count or last payment date, but it's an arbitrary performance hit)
		$query .= "
			LEFT JOIN (
				SELECT `{$wpdb->prefix}posts`.post_parent, COUNT(`{$wpdb->prefix}posts`.ID) as renewal_order_count FROM `{$wpdb->prefix}posts`
				WHERE `{$wpdb->prefix}posts`.post_parent != 0
				AND `{$wpdb->prefix}posts`.post_type = 'shop_order'
				GROUP BY `{$wpdb->prefix}posts`.post_parent
			) AS r ON r.post_parent = items.order_id
			LEFT JOIN (
				SELECT o.ID, o.post_date_gmt AS order_date FROM `{$wpdb->prefix}posts` AS o
				WHERE o.post_type = 'shop_order'
				AND o.post_parent = 0
			) AS o ON o.ID = items.order_id
			LEFT JOIN (
				SELECT p.ID, p.post_parent, MAX(p.post_date_gmt) AS last_payment_date FROM `{$wpdb->prefix}posts` AS p
				WHERE p.post_type = 'shop_order'
				AND p.post_parent != 0
				GROUP BY p.post_parent
			) AS l ON l.post_parent = items.order_id";

		$query .= "
			WHERE meta.meta_key REGEXP '_subscription_(.*)|_product_id|_variation_id'
			AND meta.order_item_id = a3.order_item_id";

		$query = apply_filters( 'woocommerce_get_subscriptions_query', $query, $args );

		$wpdb->query( 'SET SQL_BIG_SELECTS = 1;' );

		$raw_subscriptions = $wpdb->get_results( $query );

		// Create a backward compatible structure
		foreach ( $raw_subscriptions as $raw_subscription ) {

			if ( ! isset( $raw_subscription->order_item_id ) ) {
				continue;
			}

			if ( ! array_key_exists( $raw_subscription->order_item_id, $subscriptions ) ) {
				$subscriptions[ $raw_subscription->order_item_id ] = array(
					'order_id'            => $raw_subscription->order_id,
					'name'                => $raw_subscription->order_item_name,
					'renewal_order_count' => empty( $raw_subscription->renewal_order_count ) ? 0 : $raw_subscription->renewal_order_count,
					'last_payment_date'   => $raw_subscription->last_payment_date,
				);

				$subscriptions[ $raw_subscription->order_item_id ]['user_id'] = get_post_meta( $raw_subscription->order_id, '_customer_user', true );
			}

			$meta_key = str_replace( '_subscription', '', $raw_subscription->meta_key );
			$meta_key = substr( $meta_key, 0, 1 ) == '_' ? substr( $meta_key, 1 ) : $meta_key;

			if ( 'product_id' === $meta_key ) {
				$subscriptions[ $raw_subscription->order_item_id ]['subscription_key'] = WC_Subscriptions_Manager::get_subscription_key( $subscriptions[ $raw_subscription->order_item_id ]['order_id'], $raw_subscription->meta_value );
			}

			$subscriptions[ $raw_subscription->order_item_id ][ $meta_key ] = maybe_unserialize( $raw_subscription->meta_value );
		}

		return apply_filters( 'woocommerce_get_subscriptions', $subscriptions, $args );
	}

	/**
	 * Takes an array of filter params and returns the number of subscriptions which match those params.
	 *
	 * @since 1.4
	 */
	public static function get_subscription_count( $args = array() ) {
		global $wpdb;

		$default_args = array(
			'customer_id'            => '',
			'product_id'             => '',
			'variation_id'           => '',
			'order_id'               => array(),
			'subscription_status'    => 'any',
			'include_trashed'        => false,
		);

		$args = wp_parse_args( $args, $default_args );

		// Cached total count
		if ( $args == $default_args && null !== self::$total_subscription_count ) {
			return self::$total_subscription_count;
		}

		$query = "
			SELECT meta.order_item_id FROM `{$wpdb->prefix}woocommerce_order_itemmeta` AS meta";

		// Start where query
		$query .= "
			WHERE meta.meta_key = '_subscription_status'";

		// We only want subscriptions from within the status filter subclause
		if ( ! empty( $args['subscription_status'] ) && ! in_array( $args['subscription_status'], array( 'any', 'all' ) ) ) {

			$query .= $wpdb->prepare( "
			AND meta.meta_value = %s", $args['subscription_status'] );

		} elseif ( in_array( $args['subscription_status'], array( 'any', 'all' ) ) && false == $args['include_trashed'] ) {

			$query .= "
			AND meta.meta_value <> 'trash'";

		}

		// We only want subscriptions from within the product filter subclause
		if ( ! empty( $args['product_id'] ) || ! empty( $args['variation_id'] ) ) {

			// Can only specify a product ID or a variation ID, no need to specify a product ID if you want a variation of that product
			$meta_key = ! empty( $args['variation_id'] ) ? '_variation_id' : '_product_id';

			$product_ids = is_array( $args['product_id'] ) ? implode( ',', $args['product_id'] ) : $args['product_id'];

			$query .= sprintf( "
			AND meta.order_item_id IN (
				SELECT `{$wpdb->prefix}woocommerce_order_itemmeta`.order_item_id FROM `{$wpdb->prefix}woocommerce_order_itemmeta`
				WHERE `{$wpdb->prefix}woocommerce_order_itemmeta`.meta_key = '%s'
				AND `{$wpdb->prefix}woocommerce_order_itemmeta`.meta_value IN(%s)
			)",
			$meta_key,
			$args['product_id']
			);
		}

		// We only want items from a certain order
		if ( ! empty( $args['order_id'] ) ) {

			$order_ids = is_array( $args['order_id'] ) ? implode( ',', $args['order_id'] ) : $args['order_id'];

			$query .= sprintf( "
			AND meta.order_item_id IN (
				SELECT o.order_item_id FROM `{$wpdb->prefix}woocommerce_order_items` AS o
				WHERE o.order_id IN (%s)
			)", $order_ids );
		}

		// If we only want subscriptions for a certain customer ID, we need to make sure items are from the customer's orders
		if ( ! empty( $args['customer_id'] ) ) {
			$query .= sprintf( "
			AND meta.order_item_id IN (
				SELECT `{$wpdb->prefix}woocommerce_order_items`.order_item_id FROM `{$wpdb->prefix}woocommerce_order_items`
				WHERE `{$wpdb->prefix}woocommerce_order_items`.order_id IN (
					SELECT `{$wpdb->prefix}postmeta`.post_id FROM `{$wpdb->prefix}postmeta`
					WHERE `{$wpdb->prefix}postmeta`.meta_key = '_customer_user'
					AND `{$wpdb->prefix}postmeta`.meta_value = %s
				)
				)", $args['customer_id'] );
		}

		$query .= "
			GROUP BY meta.order_item_id";

		$query = apply_filters( 'woocommerce_get_subscription_count_query', $query, $args );

		$wpdb->get_results( $query );

		$subscription_count = $wpdb->num_rows;

		return apply_filters( 'woocommerce_get_subscription_count', $subscription_count, $args );
	}

	/**
	 * Returns the total number of Subscriptions on the site.
	 *
	 * @since 1.4
	 */
	public static function get_total_subscription_count() {
		global $wpdb;

		if ( null === self::$total_subscription_count ) {
			self::$total_subscription_count = self::get_subscription_count();
		}

		return apply_filters( 'woocommerce_get_total_subscription_count', self::$total_subscription_count );
	}

	/**
	 * Returns an associative array with the structure 'status' => 'count' for all subscriptions on the site
	 * and includes an "all" status, representing all subscriptions.
	 *
	 * @since 1.4
	 */
	public static function get_subscription_status_counts() {
		global $wpdb;

		$results = $wpdb->get_results(
			"SELECT meta.meta_value, COUNT(*) as count
			FROM `{$wpdb->prefix}woocommerce_order_itemmeta` AS meta
			WHERE meta.meta_key = '_subscription_status'
			GROUP BY meta.meta_value",
			OBJECT_K
		);

		$counts = array();

		foreach ( $results as $status => $values ) {
			$counts[ $status ] = $values->count;
		}

		// Order with 'all' at the beginning, then alphabetically
		ksort( $counts );
		$counts = array( 'all' => array_sum( $counts ) ) + $counts;

		return apply_filters( 'woocommerce_subscription_status_counts', $counts );
	}

	/**
	 * Workaround the last day of month quirk in PHP's strtotime function.
	 *
	 * Adding +1 month to the last day of the month can yield unexpected results with strtotime().
	 * For example, 
	 * - 30 Jan 2013 + 1 month = 3rd March 2013
	 * - 28 Feb 2013 + 1 month = 28th March 2013
	 *
	 * What humans usually want is for the charge to continue on the last day of the month.
	 *
	 * @since 1.2.5
	 */
	public static function add_months( $from_timestamp, $months_to_add ) {

		$first_day_of_month = date( 'Y-m', $from_timestamp ) . '-1';
		$days_in_next_month = date( 't', strtotime( "+ {$months_to_add} month", strtotime( $first_day_of_month ) ) );

		// Payment is on the last day of the month OR number of days in next billing month is less than the the day of this month (i.e. current billing date is 30th January, next billing date can't be 30th February)
		if ( date( 'd m Y', $from_timestamp ) === date( 't m Y', $from_timestamp ) || date( 'd', $from_timestamp ) > $days_in_next_month ) {
			for ( $i = 1; $i <= $months_to_add; $i++ ) {
				$next_month = strtotime( '+ 3 days', $from_timestamp ); // Add 3 days to make sure we get to the next month, even when it's the 29th day of a month with 31 days
				$next_timestamp = $from_timestamp = strtotime( date( 'Y-m-t H:i:s', $next_month ) ); // NB the "t" to get last day of next month
			}
		} else { // Safe to just add a month
			$next_timestamp = strtotime( "+ {$months_to_add} month", $from_timestamp );
		}

		return $next_timestamp;
	}

	/**
	 * Returns the longest possible time period
	 *
	 * @since 1.3
	 */
	public static function get_longest_period( $current_period, $new_period ) {

		if ( empty( $current_period ) || 'year' == $new_period ) {
			$longest_period = $new_period;
		} elseif ( 'month' === $new_period && in_array( $current_period, array( 'week', 'day' ) ) ) {
			$longest_period = $new_period;
		} elseif ( 'week' === $new_period && 'day' === $current_period ) {
			$longest_period = $new_period;
		} else {
			$longest_period = $current_period;
		}

		return $longest_period;
	}

	/**
	 * Returns the shortest possible time period
	 *
	 * @since 1.3.7
	 */
	public static function get_shortest_period( $current_period, $new_period ) {

		if ( empty( $current_period ) || 'day' == $new_period ) {
			$shortest_period = $new_period;
		} elseif ( 'week' === $new_period && in_array( $current_period, array( 'month', 'year' ) ) ) {
			$shortest_period = $new_period;
		} elseif ( 'month' === $new_period && 'year' === $current_period ) {
			$shortest_period = $new_period;
		} else {
			$shortest_period = $current_period;
		}

		return $shortest_period;
	}

	/**
	 * Returns Subscriptions record of the site URL for this site
	 *
	 * @since 1.3.8
	 */
	public static function get_site_url( $blog_id = null, $path = '', $scheme = null ) {
		if ( empty( $blog_id ) || !is_multisite() ) {
			$url = get_option( 'wc_subscriptions_siteurl' );
		} else {
			switch_to_blog( $blog_id );
			$url = get_option( 'wc_subscriptions_siteurl' );
			restore_current_blog();
		}

		// Remove the prefix used to prevent the site URL being updated on WP Engine
		$url = str_replace( '_[wc_subscriptions_siteurl]_', '', $url );

		$url = set_url_scheme( $url, $scheme );

		if ( ! empty( $path ) && is_string( $path ) && strpos( $path, '..' ) === false ) {
			$url .= '/' . ltrim( $path, '/' );
		}

		return apply_filters( 'wc_subscriptions_site_url', $url, $path, $scheme, $blog_id );
	}

	/**
	 * Checks if the WordPress site URL is the same as the URL for the site subscriptions normally
	 * runs on. Useful for checking if automatic payments should be processed.
	 *
	 * @since 1.3.8
	 */
	public static function is_duplicate_site() {

		$is_duplicate = ( get_site_url() !== self::get_site_url() ) ? true : false;

		return apply_filters( 'woocommerce_subscriptions_is_duplicate_site', $is_duplicate );
	}


	/**
	 * Include Docs & Settings links on the Plugins administration screen
	 *
	 * @param mixed $links
	 * @since 1.4
	 */
	public static function action_links( $links ) {

		$plugin_links = array(
			'<a href="' . WC_Subscriptions_Admin::settings_tab_url() . '">' . __( 'Settings', 'woocommerce-subscriptions' ) . '</a>',
			'<a href="http://docs.woothemes.com/document/subscriptions/">' . __( 'Docs', 'woocommerce-subscriptions' ) . '</a>',
			'<a href="http://support.woothemes.com">' . __( 'Support', 'woocommerce-subscriptions' ) . '</a>',
		);

		return array_merge( $plugin_links, $links );
	}

	/**
	 * Creates a URL based on the current site's URL that can be used to prevent duplicate payments from staging sites.
	 *
	 * The URL can not simply be the site URL, e.g. http://example.com, because WP Engine replaces all instanes of the site URL in the database
	 * when creating a staging site. As a result, we obfuscate the URL by inserting '_[wc_subscriptions_siteurl]_' into the middle of it.
	 *
	 * Why not just use a hash? Because keeping the URL in the value allows for viewing and editing the URL directly in the database.
	 *
	 * @param mixed $links
	 * @since 1.4.2
	 */
	public static function get_current_sites_duplicate_lock() {

		$site_url = get_option( 'siteurl' );

		return substr_replace( $site_url, '_[wc_subscriptions_siteurl]_', strlen( $site_url ) / 2, 0 );
	}

	/**
	 * Sets a flag in the database to record the site's url. This then checked to determine if we are on a duplicate
	 * site or the original/main site, uses @see self::get_current_sites_duplicate_lock();
	 *
	 * @param mixed $links
	 * @since 1.4.2
	 */
	public static function set_duplicate_site_url_lock() {
		update_option( 'wc_subscriptions_siteurl', self::get_current_sites_duplicate_lock() );
	}

	/**
	 * A flag to indicate whether the current site has roughly more than 3000 subscriptions. Used to disable
	 * features on the Manage Subscriptions list table that do not scale well (yet).
	 *
	 * @since 1.4.4
	 */
	public static function is_large_site() {

		if ( false === self::$is_large_site ) {

			self::$is_large_site = filter_var( get_option( 'wcs_is_large_site' ), FILTER_VALIDATE_BOOLEAN );

			if ( false === self::$is_large_site && self::get_total_subscription_count() > 2500 ) {
				add_option( 'wcs_is_large_site', 'true', '', false );
				self::$is_large_site = true;
			}

		}

		return apply_filters( 'woocommerce_subscriptions_is_large_site', self::$is_large_site );
	}

	/**
	 * Check is the installed version of WooCommerce is 2.2 or newer.
	 *
	 * @since 1.5.10
	 */
	public static function is_woocommerce_pre_2_2() {

		if ( ! defined( 'WC_VERSION' ) || version_compare( WC_VERSION, '2.2', '<' ) ) {
			$woocommerce_is_pre_2_2 = true;
		} else {
			$woocommerce_is_pre_2_2 = false;
		}

		return $woocommerce_is_pre_2_2;
	}

	/**
	 * Check is the installed version of WooCommerce is 2.1 or newer.
	 *
	 * Only for use when we need to check version. If the code in question relys on a specific
	 * WC2.1 only function or class, then it's better to check that function or class exists rather
	 * than using this more generic check.
	 *
	 * @since 1.4.5
	 */
	public static function is_woocommerce_pre_2_1() {

		if ( ! defined( 'WC_VERSION' ) ) {
			$woocommerce_is_pre_2_1 = true;
		} else {
			$woocommerce_is_pre_2_1 = false;
		}

		return $woocommerce_is_pre_2_1;
	}

	/**
	 * Add WooCommerce error or success notice regardless of the version of WooCommerce running.
	 *
	 * @param  string $message The text to display in the notice.
	 * @param  string $notice_type The singular name of the notice type - either error, success or notice. [optional]
	 * @since version 1.4.5
	 */
	public static function add_notice( $message, $notice_type = 'success' ) {
		global $woocommerce;

		if ( function_exists( 'wc_add_notice' ) ) {

			wc_add_notice( $message, $notice_type );

		} else { // WC < 2.1

			if ( 'error' === $notice_type ) {
				$woocommerce->add_error( $message );
			} else {
				$woocommerce->add_message( $message );
			}

			$woocommerce->set_messages();

		}
	}

	/**
	 * Print WooCommerce messages regardless of the version of WooCommerce running.
	 *
	 * @since version 1.4.5
	 */
	public static function print_notices() {
		global $woocommerce;

		if ( function_exists( 'wc_print_notices' ) ) {

			wc_print_notices();

		} else { // WC < 2.1

			$woocommerce->show_messages();

		}
	}

	/**
	 * Wrapper around @see wc_format_decimal() which was called @see woocommerce_format_total() prior to WooCommerce 2.1.
	 *
	 * @since version 1.4.6
	 */
	public static function format_total( $number ) {
		global $woocommerce;

		if ( function_exists( 'wc_format_decimal' ) ) {
			return wc_format_decimal( $number );
		} else { // WC < 2.1
			return woocommerce_format_total( $number );
		}
	}

	/**
	 * Renewals use a lot more memory on WordPress multisite (10-15mb instead of 0.1-1mb) so
	 * we need to reduce the number of renewals run in each request.
	 *
	 * @since version 1.5
	 */
	public static function action_scheduler_multisite_batch_size( $batch_size ) {

		if ( is_multisite() ) {
			$batch_size = 10;
		}

		return $batch_size;
	}

	/* Deprecated Functions */

	/**
	 * Was called when a plugin is activated using official register_activation_hook() API
	 *
	 * Upgrade routine is now in @see maybe_activate_woocommerce_subscriptions()
	 *
	 * @since 1.0
	 */
	public static function activate_woocommerce_subscriptions(){
		_deprecated_function( __CLASS__ . '::' . __FUNCTION__, '1.1', __CLASS__ . '::maybe_activate_woocommerce_subscriptions()' );
	}

	/**
	 * Override the WooCommerce "Add to Cart" text with "Sign Up Now"
	 *
	 * @since 1.0
	 * @deprecated 1.5
	 */
	public static function add_to_cart_text( $button_text, $product_type = '' ) {
		global $product;

		_deprecated_function( __CLASS__ . '::' . __FUNCTION__, '1.1', 'WC_Product::add_to_cart_text()' );

		if ( WC_Subscriptions_Product::is_subscription( $product ) || in_array( $product_type, array( 'subscription', 'subscription-variation' ) ) ) {
			$button_text = get_option( WC_Subscriptions_Admin::$option_prefix . '_add_to_cart_button_text', __( 'Sign Up Now', 'woocommerce-subscriptions' ) );
		}

		return $button_text;
	}

	/**
	 * Subscriptions are individual items so override the WC_Product is_sold_individually function
	 * to reflect this.
	 *
	 * @since 1.0
	 * @deprecated 1.5
	 */
	public static function is_sold_individually( $is_individual, $product ) {

		_deprecated_function( __CLASS__ . '::' . __FUNCTION__, '1.1', 'WC_Product::is_sold_individually()' );

		return $is_individual;
	}
}

WC_Subscriptions::init();
