<?php
/**
 * Subscriptions Admin Class
 *
 * Adds a Subscription setting tab and saves subscription settings. Adds a Subscriptions Management page. Adds
 * Welcome messages and pointers to streamline learning process for new users.
 *
 * @package		WooCommerce Subscriptions
 * @subpackage	WC_Subscriptions_Admin
 * @category	Class
 * @author		Brent Shepherd
 * @since		1.0
 */
class WC_Subscriptions_Admin {

	/**
	 * The WooCommerce settings tab name
	 *
	 * @since 1.0
	 */
	public static $tab_name = 'subscriptions';

	/**
	 * The prefix for subscription settings
	 *
	 * @since 1.0
	 */
	public static $option_prefix = 'woocommerce_subscriptions';

	/**
	 * A translation safe screen ID for the Manage Subscriptions admin page.
	 *
	 * Set once all plugins are loaded to apply the 'woocommerce_subscriptions_screen_id' filter.
	 *
	 * @since 1.3.3
	 */
	public static $admin_screen_id;

	/**
	 * Store an instance of the list table
	 *
	 * @since 1.4.6
	 */
	private static $subscriptions_list_table;

	/**
	 * Bootstraps the class and hooks required actions & filters.
	 *
	 * @since 1.0
	 */
	public static function init() {

		// Add subscriptions to the product select box
		add_filter( 'product_type_selector', __CLASS__ . '::add_subscription_products_to_select' );

		// Add subscription pricing fields on edit product page
		add_action( 'woocommerce_product_options_general_product_data', __CLASS__ . '::subscription_pricing_fields' );

		// Add advanced subscription options on edit product page
		add_action( 'woocommerce_product_options_reviews', __CLASS__ . '::subscription_advanced_fields' );

		// And also on the variations section
		add_action( 'woocommerce_product_after_variable_attributes', __CLASS__ . '::variable_subscription_pricing_fields', 10, 3 );

		// Add bulk edit actions for variable subscription products
		add_action( 'woocommerce_variable_product_bulk_edit_actions', __CLASS__ . '::variable_subscription_bulk_edit_actions', 10 );

		// Save subscription meta when a subscription product is changed via bulk edit
		add_action( 'woocommerce_product_bulk_edit_save', __CLASS__ . '::bulk_edit_save_subscription_meta', 10 );

		// Save subscription meta only when a subscription product is saved, can't run on the "'woocommerce_process_product_meta_' . $product_type" action because we need to override some WC defaults
		add_action( 'save_post', __CLASS__ . '::save_subscription_meta', 11 );

		// Save variable subscription meta
		add_action( 'woocommerce_process_product_meta_variable-subscription', __CLASS__ . '::process_product_meta_variable_subscription' );

		add_filter( 'woocommerce_settings_tabs_array', __CLASS__ . '::add_subscription_settings_tab', 50 );

		add_action( 'woocommerce_settings_tabs_subscriptions', __CLASS__ . '::subscription_settings_page' );

		add_action( 'woocommerce_update_options_' . self::$tab_name, __CLASS__ . '::update_subscription_settings' );

		add_action( 'admin_menu', __CLASS__ . '::add_menu_pages' );

		add_filter( 'manage_users_columns', __CLASS__ . '::add_user_columns', 11, 1 );

		add_filter( 'manage_users_custom_column', __CLASS__ . '::user_column_values', 11, 3 ); // Fire after default to avoid being broken by plugins #doing_it_wrong

		add_action( 'admin_enqueue_scripts', __CLASS__ . '::enqueue_styles_scripts' );

		add_action( 'woocommerce_admin_field_informational', __CLASS__ . '::add_informational_admin_field' );

		add_action( 'add_meta_boxes', __CLASS__ . '::add_meta_boxes', 35 );

		add_filter( 'request', __CLASS__ . '::filter_orders_by_renewal_parent' );
		add_action( 'admin_notices',  __CLASS__ . '::display_renewal_filter_notice' );

		add_action( 'pre_user_query', __CLASS__ . '::add_subscribers_to_customers' );

		add_shortcode( 'subscriptions', __CLASS__ . '::do_subscriptions_shortcode' );

		add_filter( 'set-screen-option', __CLASS__ . '::set_manage_subscriptions_screen_option', 10, 3 );

		add_action( 'plugins_loaded', __CLASS__ . '::set_admin_screen_id' );

		add_action( 'plugins_loaded', __CLASS__ . '::add_subscriptions_table_column_filter', 11 );

		add_filter( 'woocommerce_debug_posting', __CLASS__ . '::add_system_status_items' );

		add_filter( 'woocommerce_payment_gateways_setting_columns', __CLASS__ . '::payment_gateways_rewewal_column' );

		add_action( 'woocommerce_payment_gateways_setting_column_renewals', __CLASS__ . '::payment_gateways_rewewal_support' );
		
	}

	/**
	 * Set a translation safe screen ID for Subcsription
	 *
	 * @since 1.3.3
	 */
	public static function set_admin_screen_id(){
		self::$admin_screen_id = apply_filters( 'woocommerce_subscriptions_screen_id', 'woocommerce_page_subscriptions' );
	}

	/**
	 * Once we have set a correct admin page screen ID, we can use it for adding the Manage Subscriptions table's columns.
	 *
	 * @since 1.3.3
	 */
	public static function add_subscriptions_table_column_filter(){
		add_filter( 'manage_' . self::$admin_screen_id . '_columns', __CLASS__ . '::get_subscription_table_columns' );
	}

	/**
	 * Add the 'subscriptions' product type to the WooCommerce product type select box.
	 *
	 * @param array Array of Product types & their labels, excluding the Subscription product type.
	 * @return array Array of Product types & their labels, including the Subscription product type.
	 * @since 1.0
	 */
	public static function add_subscription_products_to_select( $product_types ){

		$product_types[WC_Subscriptions::$name] = __( 'Simple subscription', 'woocommerce-subscriptions' );

		if ( class_exists( 'WC_Product_Variable_Subscription' ) ) {
			$product_types['variable-subscription'] = __( 'Variable subscription', 'woocommerce-subscriptions' );
		}

		return $product_types;
	}

	/**
	 * Output the subscription specific pricing fields on the "Edit Product" admin page.
	 *
	 * @since 1.0
	 */
	public static function subscription_pricing_fields() {
		global $post;

		// Set month as the default billing period
		if ( ! $subscription_period = get_post_meta( $post->ID, '_subscription_period', true ) ) {
		 	$subscription_period = 'month';
		}

		echo '<div class="options_group subscription_pricing show_if_subscription">';

		// Subscription Price
		woocommerce_wp_text_input( array(
			'id'          => '_subscription_price',
			'class'       => 'wc_input_subscription_price',
			'label'       => sprintf( __( 'Subscription Price (%s)', 'woocommerce-subscriptions' ), get_woocommerce_currency_symbol() ),
			'placeholder' => __( 'e.g. 5.90', 'woocommerce-subscriptions' ),
			'type'        => 'text',
			'custom_attributes' => array(
					'step' => 'any',
					'min'  => '0',
				)
			)
		);

		// Subscription Period Interval
		woocommerce_wp_select( array(
			'id'          => '_subscription_period_interval',
			'class'       => 'wc_input_subscription_period_interval',
			'label'       => __( 'Subscription Periods', 'woocommerce-subscriptions' ),
			'options'     => WC_Subscriptions_Manager::get_subscription_period_interval_strings(),
			)
		);

		// Billing Period
		woocommerce_wp_select( array(
			'id'          => '_subscription_period',
			'class'       => 'wc_input_subscription_period',
			'label'       => __( 'Billing Period', 'woocommerce-subscriptions' ),
			'value'       => $subscription_period,
			'description' => __( 'for', 'woocommerce-subscriptions' ),
			'options'     => WC_Subscriptions_Manager::get_subscription_period_strings(),
			)
		);

		// Subscription Length
		woocommerce_wp_select( array(
			'id'          => '_subscription_length',
			'class'       => 'wc_input_subscription_length',
			'label'       => __( 'Subscription Length', 'woocommerce-subscriptions' ),
			'options'     => WC_Subscriptions_Manager::get_subscription_ranges( $subscription_period ),
			'description' => '',
			)
		);

		// Sign-up Fee
		woocommerce_wp_text_input( array(
			'id'          => '_subscription_sign_up_fee',
			'class'       => 'wc_input_subscription_intial_price',
			'label'       => sprintf( __( 'Sign-up Fee (%s)', 'woocommerce-subscriptions' ), get_woocommerce_currency_symbol() ),
			'placeholder' => __( 'e.g. 9.90', 'woocommerce-subscriptions' ),
			'description' => __( 'sign-up fee', 'woocommerce-subscriptions' ),
			'description' => __( 'Optionally include an amount to be charged at the outset of the subscription. The sign-up fee will be charged immediately, even if the product has a free trial or the payment dates are synced.', 'woocommerce-subscriptions' ),
			'desc_tip'    => true,
			'type'        => 'text',
			'custom_attributes' => array(
					'step' => 'any',
					'min'  => '0',
				)
			)
		);

		// Trial Length
		woocommerce_wp_text_input( array(
			'id'          => '_subscription_trial_length',
			'class'       => 'wc_input_subscription_trial_length',
			'label'       => __( 'Free Trial', 'woocommerce-subscriptions' ),
			)
		);

		// Trial Period
		woocommerce_wp_select( array(
			'id'          => '_subscription_trial_period',
			'class'       => 'wc_input_subscription_trial_period',
			'label'       => __( 'Subscription Trial Period', 'woocommerce-subscriptions' ),
			'options'     => WC_Subscriptions_Manager::get_available_time_periods(),
			'description' => sprintf( __( 'Include an optional period of time to wait before charging the first recurring payment. Any sign up fee will still be charged at the outset of the subscription. %s', 'woocommerce-subscriptions' ), self::get_trial_period_validation_message() ),
			'desc_tip'    => true,
			'value'       => WC_Subscriptions_Product::get_trial_period( $post->ID ), // Explicity set value in to ensure backward compatibility
			)
		);

		do_action( 'woocommerce_subscriptions_product_options_pricing' );

		echo '</div>';
		echo '<div class="show_if_subscription clear"></div>';
	}


	/**
	 * Output advanced subscription options on the "Edit Product" admin screen
	 *
	 * @since 1.3.5
	 */
	public static function subscription_advanced_fields() {
		global $post;

		echo '</div>';
		echo '<div class="options_group limit_subscription">';

		// Only one Subscription per customer
		woocommerce_wp_select( array(
			'id'          => '_subscription_limit',
			'label'       => __( 'Limit Subscription', 'woocommerce-subscriptions' ),
			'description' => sprintf( __( 'Only allow a customer to have one subscription to this product. %sLearn more.%s', 'woocommerce-subscriptions' ), '<a href="http://docs.woothemes.com/document/subscriptions/store-manager-guide/#limit-subscription">', '</a>' ),
			'options'     => array(
				'no'     => __( 'Do no limit', 'woocommerce-subscriptions' ),
				'active' => __( 'Limit to one active subscription', 'woocommerce-subscriptions' ),
				'any'    => __( 'Limit to one of any status', 'woocommerce-subscriptions' ),
			),
		));

		do_action( 'woocommerce_subscriptions_product_options_advanced' );

	}

	/**
	 * Output the subscription specific pricing fields on the "Edit Product" admin page.
	 *
	 * @since 1.3
	 */
	public static function variable_subscription_pricing_fields( $loop, $variation_data, $variation ) {
		global $woocommerce, $thepostid;

		// Set month as the default billing period
		if ( ! $subscription_period = get_post_meta( $variation->ID, '_subscription_period', true ) ) {
			$subscription_period = 'month';
		}

		// When called via Ajax
		if ( ! function_exists( 'woocommerce_wp_text_input' ) ) {
			require_once( $woocommerce->plugin_path() . '/admin/post-types/writepanels/writepanels-init.php' );
		}

		if ( ! isset( $thepostid ) ) {
			$thepostid = $variation->post_parent;
		}

?>
<tr class="variable_subscription_pricing show_if_variable-subscription">
	<td colspan="2">
		<label><?php printf( __( 'Subscription Price (%s)', 'woocommerce-subscriptions' ), get_woocommerce_currency_symbol() ) ?></label>
		<?php
		// Subscription Price
		woocommerce_wp_text_input( array(
			'id'            => 'variable_subscription_price[' . $loop . ']',
			'class'         => 'wc_input_subscription_price',
			'wrapper_class' => '_subscription_price_field',
			'label'         => sprintf( __( 'Subscription Price (%s)', 'woocommerce-subscriptions' ), get_woocommerce_currency_symbol() ),
			'placeholder'   => __( 'e.g. 5.90', 'woocommerce-subscriptions' ),
			'value'         => get_post_meta( $variation->ID, '_subscription_price', true ),
			'type'          => 'number',
			'custom_attributes' => array(
					'step' => 'any',
					'min'  => '0',
				)
			)
		);

		// Subscription Period Interval
		woocommerce_wp_select( array(
			'id'            => 'variable_subscription_period_interval[' . $loop . ']',
			'class'         => 'wc_input_subscription_period_interval',
			'wrapper_class' => '_subscription_period_interval_field',
			'label'         => __( 'Subscription Periods', 'woocommerce-subscriptions' ),
			'options'       => WC_Subscriptions_Manager::get_subscription_period_interval_strings(),
			'value'         => get_post_meta( $variation->ID, '_subscription_period_interval', true ),
			)
		);

		// Billing Period
		woocommerce_wp_select( array(
			'id'            => 'variable_subscription_period[' . $loop . ']',
			'class'         => 'wc_input_subscription_period',
			'wrapper_class' => '_subscription_period_field',
			'label'         => __( 'Billing Period', 'woocommerce-subscriptions' ),
			'value'         => $subscription_period,
			'description'   => __( 'for', 'woocommerce-subscriptions' ),
			'options'       => WC_Subscriptions_Manager::get_subscription_period_strings(),
			)
		);

		// Subscription Length
		woocommerce_wp_select( array(
			'id'            => 'variable_subscription_length[' . $loop . ']',
			'class'         => 'wc_input_subscription_length',
			'wrapper_class' => '_subscription_length_field',
			'label'         => __( 'Subscription Length', 'woocommerce-subscriptions' ),
			'options'       => WC_Subscriptions_Manager::get_subscription_ranges( $subscription_period ),
			'value'         => get_post_meta( $variation->ID, '_subscription_length', true ),
			)
		);
?>
	</td>
</tr>
<tr class="variable_subscription_trial show_if_variable-subscription variable_subscription_trial_sign_up">
	<td class="sign-up-fee-cell show_if_variable-subscription">
<?php
		// Sign-up Fee
		woocommerce_wp_text_input( array(
			'id'            => 'variable_subscription_sign_up_fee[' . $loop . ']',
			'class'         => 'wc_input_subscription_intial_price',
			'wrapper_class' => '_subscription_sign_up_fee_field',
			'label'         => sprintf( __( 'Sign-up Fee (%s)', 'woocommerce-subscriptions' ), get_woocommerce_currency_symbol() ),
			'placeholder'   => __( 'e.g. 9.90', 'woocommerce-subscriptions' ),
			'value'         => get_post_meta( $variation->ID, '_subscription_sign_up_fee', true ),
			'type'          => 'number',
			'custom_attributes' => array(
					'step' => 'any',
					'min'  => '0',
				)
			)
		);
?>	</td>
	<td colspan="1" class="show_if_variable-subscription">
		<label><?php _e( 'Free Trial', 'woocommerce-subscriptions' ); ?></label>
<?php
		// Trial Length
		woocommerce_wp_text_input( array(
			'id'            => 'variable_subscription_trial_length[' . $loop . ']',
			'class'         => 'wc_input_subscription_trial_length',
			'wrapper_class' => '_subscription_trial_length_field',
			'label'         => __( 'Free Trial', 'woocommerce-subscriptions' ),
			'placeholder'   => __( 'e.g. 3', 'woocommerce-subscriptions' ),
			'value'         => get_post_meta( $variation->ID, '_subscription_trial_length', true ),
			)
		);

		// Trial Period
		woocommerce_wp_select( array(
			'id'            => 'variable_subscription_trial_period[' . $loop . ']',
			'class'         => 'wc_input_subscription_trial_period',
			'wrapper_class' => '_subscription_trial_period_field',
			'label'         => __( 'Subscription Trial Period', 'woocommerce-subscriptions' ),
			'options'       => WC_Subscriptions_Manager::get_available_time_periods(),
			'description'   => sprintf( __( 'An optional period of time to wait before charging the first recurring payment. Any sign up fee will still be charged at the outset of the subscription. %s', 'woocommerce-subscriptions' ), self::get_trial_period_validation_message() ),
			'desc_tip'      => true,
			'value'         => WC_Subscriptions_Product::get_trial_period( $variation->ID ), // Explicity set value in to ensure backward compatibility
			)
		);?>
	</td>
</tr>
<?php do_action( 'woocommerce_variable_subscription_pricing', $loop, $variation_data, $variation );
	}

	/**
	 * Output extra options in the Bulk Edit select box for editing Subscription terms.
	 *
	 * @since 1.3
	 */
	public static function variable_subscription_bulk_edit_actions() {
		global $post;

		if ( WC_Subscriptions_Product::is_subscription( $post->ID ) ) : ?>
						<optgroup label="<?php esc_attr_e( 'Subscription Pricing', 'woocommerce-subscriptions' ); ?>">
							<option value="variable_subscription_sign_up_fee"><?php _e( 'Subscription sign-up fee', 'woocommerce-subscriptions' ); ?></option>
							<option value="variable_subscription_period_interval"><?php _e( 'Subscription billing interval', 'woocommerce-subscriptions' ); ?></option>
							<option value="variable_subscription_period"><?php _e( 'Subscription period', 'woocommerce-subscriptions' ); ?></option>
							<option value="variable_subscription_length"><?php _e( 'Subscription length', 'woocommerce-subscriptions' ); ?></option>
							<option value="variable_subscription_trial_length"><?php _e( 'Free trial length', 'woocommerce-subscriptions' ); ?></option>
							<option value="variable_subscription_trial_period"><?php _e( 'Free trial period', 'woocommerce-subscriptions' ); ?></option>
						</optgroup>
		<?php endif;
	}

	/**
	 * Save meta data for simple subscription product type when the "Edit Product" form is submitted.
	 *
	 * @param array Array of Product types & their labels, excluding the Subscription product type.
	 * @return array Array of Product types & their labels, including the Subscription product type.
	 * @since 1.0
	 */
	public static function save_subscription_meta( $post_id ) {

		if ( ! isset( $_POST['product-type'] ) || ! in_array( $_POST['product-type'], apply_filters( 'woocommerce_subscription_product_types', array( WC_Subscriptions::$name ) ) ) ) {
			return;
		}

		$subscription_price = self::clean_number( stripslashes( $_REQUEST['_subscription_price'] ) );
		$sale_price         = self::clean_number( stripslashes( $_REQUEST['_sale_price'] ) );

		update_post_meta( $post_id, '_subscription_price', $subscription_price );

		// Set sale details - these are ignored by WC core for the subscription product type
		update_post_meta( $post_id, '_regular_price', $subscription_price );
		update_post_meta( $post_id, '_sale_price', $sale_price );

		$date_from = ( isset( $_POST['_sale_price_dates_from'] ) ) ? strtotime( $_POST['_sale_price_dates_from'] ) : '';
		$date_to   = ( isset( $_POST['_sale_price_dates_to'] ) ) ? strtotime( $_POST['_sale_price_dates_to'] ) : '';

		$now = gmdate( 'U' );

		if ( ! empty( $date_to ) && empty( $date_from ) ) {
			$date_from = $now;
		}

		update_post_meta( $post_id, '_sale_price_dates_from', $date_from );
		update_post_meta( $post_id, '_sale_price_dates_to', $date_to );

		// Update price if on sale
		if ( ! empty( $sale_price ) && ( ( empty( $date_to ) && empty( $date_from ) ) || ( $date_from < $now && ( empty( $date_to ) || $date_to > $now ) ) ) ) {
			$price = $sale_price;
		} else {
			$price = $subscription_price;
		}

		update_post_meta( $post_id, '_price', stripslashes( $price ) );

		// Make sure trial period is within allowable range
		$subscription_ranges = WC_Subscriptions_Manager::get_subscription_ranges();

		$max_trial_length = count( $subscription_ranges[ $_POST['_subscription_trial_period'] ] ) - 1;

		$_POST['_subscription_trial_length'] = absint( $_POST['_subscription_trial_length'] );

		if ( $_POST['_subscription_trial_length'] > $max_trial_length ) {
			$_POST['_subscription_trial_length'] = $max_trial_length;
		}

		update_post_meta( $post_id, '_subscription_trial_length', $_POST['_subscription_trial_length'] );

		$_REQUEST['_subscription_sign_up_fee'] = self::clean_number( stripslashes( $_REQUEST['_subscription_sign_up_fee'] ) );

		$subscription_fields = array(
			'_subscription_sign_up_fee',
			'_subscription_period',
			'_subscription_period_interval',
			'_subscription_length',
			'_subscription_trial_period',
			'_subscription_limit',
		);

		foreach ( $subscription_fields as $field_name ) {
			update_post_meta( $post_id, $field_name, stripslashes( $_REQUEST[ $field_name ] ) );
		}

	}

	/**
	 * Calculate and set a simple subscription's prices when edited via the bulk edit
	 *
	 * @param object $product An instance of a WC_Product_* object.
	 * @return null
	 * @since 1.3.9
	 */
	public static function bulk_edit_save_subscription_meta( $product ) {

		if ( ! $product->is_type( 'subscription' ) ) {
			return;
		}

		$price_changed = false;

		$old_regular_price = $product->regular_price;
		$old_sale_price    = $product->sale_price;

		if ( ! empty( $_REQUEST['change_regular_price'] ) ) {

			$change_regular_price = absint( $_REQUEST['change_regular_price'] );
			$regular_price = esc_attr( stripslashes( $_REQUEST['_regular_price'] ) );

			switch ( $change_regular_price ) {
				case 1 :
					$new_price = $regular_price;
				break;
				case 2 :
					if ( strstr( $regular_price, '%' ) ) {
						$percent = str_replace( '%', '', $regular_price ) / 100;
						$new_price = $old_regular_price + ( $old_regular_price * $percent );
					} else {
						$new_price = $old_regular_price + $regular_price;
					}
				break;
				case 3 :
					if ( strstr( $regular_price, '%' ) ) {
						$percent = str_replace( '%', '', $regular_price ) / 100;
						$new_price = $old_regular_price - ( $old_regular_price * $percent );
					} else {
						$new_price = $old_regular_price - $regular_price;
					}
				break;
			}

			if ( isset( $new_price ) && $new_price != $old_regular_price ) {
				$price_changed = true;
				update_post_meta( $product->id, '_regular_price', $new_price );
				update_post_meta( $product->id, '_subscription_price', $new_price );
				$product->regular_price = $new_price;
			}
		}

		if ( ! empty( $_REQUEST['change_sale_price'] ) ) {

			$change_sale_price = absint( $_REQUEST['change_sale_price'] );
			$sale_price = esc_attr( stripslashes( $_REQUEST['_sale_price'] ) );

			switch ( $change_sale_price ) {
				case 1 :
					$new_price = $sale_price;
				break;
				case 2 :
					if ( strstr( $sale_price, '%' ) ) {
						$percent = str_replace( '%', '', $sale_price ) / 100;
						$new_price = $old_sale_price + ( $old_sale_price * $percent );
					} else {
						$new_price = $old_sale_price + $sale_price;
					}
				break;
				case 3 :
					if ( strstr( $sale_price, '%' ) ) {
						$percent = str_replace( '%', '', $sale_price ) / 100;
						$new_price = $old_sale_price - ( $old_sale_price * $percent );
					} else {
						$new_price = $old_sale_price - $sale_price;
					}
				break;
				case 4 :
					if ( strstr( $sale_price, '%' ) ) {
						$percent = str_replace( '%', '', $sale_price ) / 100;
						$new_price = $product->regular_price - ( $product->regular_price * $percent );
					} else {
						$new_price = $product->regular_price - $sale_price;
					}
				break;
			}

			if ( isset( $new_price ) && $new_price != $old_sale_price ) {
				$price_changed = true;
				update_post_meta( $product->id, '_sale_price', $new_price );
				$product->sale_price = $new_price;
			}
		}

		if ( $price_changed ) {
			update_post_meta( $product->id, '_sale_price_dates_from', '' );
			update_post_meta( $product->id, '_sale_price_dates_to', '' );

			if ( $product->regular_price < $product->sale_price ) {
				$product->sale_price = '';
				update_post_meta( $product->id, '_sale_price', '' );
			}

			if ( $product->sale_price ) {
				update_post_meta( $product->id, '_price', $product->sale_price );
			} else {
				update_post_meta( $product->id, '_price', $product->regular_price );
			}
		}
	}

	/**
	 * Save a variable subscription's details when the edit product page is submitted for a variable
	 * subscription product type (or the bulk edit product is saved).
	 *
	 * @param int $post_id ID of the parent WC_Product_Variable_Subscription
	 * @return null
	 * @since 1.3
	 */
	public static function process_product_meta_variable_subscription( $post_id ) {

		if ( ! WC_Subscriptions_Product::is_subscription( $post_id ) ) {
			return;
		}

		// Make sure WooCommerce calculates correct prices
		$_POST['variable_regular_price'] = isset( $_POST['variable_subscription_price'] ) ? $_POST['variable_subscription_price'] : 0;

		// Run WooCommerce core saving routine
		if ( class_exists( 'WC_Meta_Box_Product_Data' ) ) {

			WC_Meta_Box_Product_Data::save_variations( $post_id, get_post( $post_id ) );

		} else { // WC < 2.1

			process_product_meta_variable( $post_id );

		}

		update_post_meta( $post_id, '_subscription_limit', stripslashes( $_REQUEST['_subscription_limit'] ) );

		if ( ! isset( $_REQUEST['variable_post_id'] ) ) {
			return;
		}

		$variable_post_ids = $_POST['variable_post_id'];

		$max_loop = max( array_keys( $variable_post_ids ) );

		// Save each variations details
		for ( $i = 0; $i <= $max_loop; $i ++ ) {

			if ( ! isset( $variable_post_ids[ $i ] ) ) {
				continue;
			}

			$variation_id = absint( $variable_post_ids[ $i ] );

			if ( isset( $_POST['variable_subscription_price'] ) && is_array( $_POST['variable_subscription_price'] ) ) {
				$subscription_price = self::clean_number( woocommerce_clean( $_POST['variable_subscription_price'][ $i ] ) );
				update_post_meta( $variation_id, '_subscription_price', $subscription_price );
				update_post_meta( $variation_id, '_regular_price', $subscription_price );
			}

			// Make sure trial period is within allowable range
			$subscription_ranges = WC_Subscriptions_Manager::get_subscription_ranges();

			$max_trial_length = count( $subscription_ranges[ $_POST['variable_subscription_trial_period'][ $i ] ] ) - 1;

			$_POST['variable_subscription_trial_length'][ $i ] = absint( $_POST['variable_subscription_trial_length'][ $i ] );

			if ( $_POST['variable_subscription_trial_length'][ $i ] > $max_trial_length ) {
				$_POST['variable_subscription_trial_length'][ $i ] = $max_trial_length;
			}

			// Work around a WPML bug which means 'variable_subscription_trial_period' is not set when using "Edit Product" as the product translation interface
			if ( $_POST['variable_subscription_trial_length'][ $i ] < 0 ) {
				$_POST['variable_subscription_trial_length'][ $i ] = 0;
			}

			$subscription_fields = array(
				'_subscription_sign_up_fee',
				'_subscription_period',
				'_subscription_period_interval',
				'_subscription_length',
				'_subscription_trial_period',
				'_subscription_trial_length',
			);

			foreach ( $subscription_fields as $field_name ) {
				if ( isset( $_POST['variable' . $field_name][ $i ] ) ) {
					update_post_meta( $variation_id, $field_name, woocommerce_clean( $_POST['variable' . $field_name][ $i ] ) );
				}
			}
		}

		// Now that all the varation's meta is saved, sync the min variation price
		$variable_subscription = get_product( $post_id );
		$variable_subscription->variable_product_sync();

	}

	/**
	 * Adds all necessary admin styles.
	 *
	 * @param array Array of Product types & their labels, excluding the Subscription product type.
	 * @return array Array of Product types & their labels, including the Subscription product type.
	 * @since 1.0
	 */
	public static function enqueue_styles_scripts() {
		global $woocommerce, $pagenow, $post;

		// Get admin screen id
	    $screen = get_current_screen();

		$is_woocommerce_screen = ( in_array( $screen->id, array( 'product', 'edit-shop_order', 'shop_order', self::$admin_screen_id, 'users', 'woocommerce_page_wc-settings' ) ) ) ? true : false;
		$is_activation_screen  = ( get_transient( WC_Subscriptions::$activation_transient ) == true ) ? true : false;

		if ( $is_woocommerce_screen ) {

			$dependencies = array( 'jquery' );

			// Version juggling
			if ( WC_Subscriptions::is_woocommerce_pre_2_1() ) { // WC 2.0
				$woocommerce_admin_script_handle = 'woocommerce_writepanel';
			} elseif ( WC_Subscriptions::is_woocommerce_pre_2_2() ) { // WC 2.1
				$woocommerce_admin_script_handle = 'woocommerce_admin_meta_boxes';
			} else {
				$woocommerce_admin_script_handle = 'wc-admin-meta-boxes';
			}

			if( $screen->id == 'product' ) {
				$dependencies[] = $woocommerce_admin_script_handle;

				if ( ! WC_Subscriptions::is_woocommerce_pre_2_2() ) {
					$dependencies[] = 'wc-admin-product-meta-boxes';
					$dependencies[] = 'wc-admin-variation-meta-boxes';
				}

				$script_params = array(
					'productType'              => WC_Subscriptions::$name,
					'trialPeriodSingular'      => WC_Subscriptions_Manager::get_available_time_periods(),
					'trialPeriodPlurals'       => WC_Subscriptions_Manager::get_available_time_periods( 'plural' ),
					'subscriptionLengths'      => WC_Subscriptions_Manager::get_subscription_ranges(),
					'trialTooLongMessages'     => self::get_trial_period_validation_message( 'separate' ),
					'bulkEditPeriodMessage'    => __( 'Enter the new period, either day, week, month or year:', 'woocommerce-subscriptions' ),
					'bulkEditLengthMessage'    => __( 'Enter a new length (e.g. 5):', 'woocommerce-subscriptions' ),
					'bulkEditIntervalhMessage' => __( 'Enter a new interval as a single number (e.g. to charge every 2nd month, enter 2):', 'woocommerce-subscriptions' ),
				);
			} else if ( 'edit-shop_order' == $screen->id ) {
				$script_params = array(
					'bulkTrashWarning' => __( "You are about to trash one or more orders which contain a subscription.\n\nTrashing the orders will also trash the subscriptions purchased with these orders.", 'woocommerce-subscriptions' )
				);
			} else if ( 'shop_order' == $screen->id ) {
				$dependencies[] = $woocommerce_admin_script_handle;

				if ( ! WC_Subscriptions::is_woocommerce_pre_2_2() ) {
					$dependencies[] = 'wc-admin-order-meta-boxes';
					$dependencies[] = 'wc-admin-order-meta-boxes-modal';
				}

				$script_params = array(
					'bulkTrashWarning'  => __( 'Trashing this order will also trash the subscription purchased with the order.', 'woocommerce-subscriptions' ),
					'changeMetaWarning' => __( "WARNING: Bad things are about to happen!\n\nThe payment gateway used to purchase this subscription does not support modifying a subscription's details.\n\nChanges to the billing period, recurring discount, recurring tax or recurring total may not be reflected in the amount charged by the payment gateway.", 'woocommerce-subscriptions' ),
					'removeItemWarning' => __( "You are deleting a subscription item. You will also need to manually cancel and trash the subscription on the Manage Subscriptions screen.", 'woocommerce-subscriptions' ),
					'roundAtSubtotal'   => esc_attr( get_option( 'woocommerce_tax_round_at_subtotal' ) ),
					'EditOrderNonce'    => wp_create_nonce( 'woocommerce-subscriptions' ),
					'postId'            => $post->ID,
				);
			} else if ( 'users' == $screen->id ) {
				$dependencies[] = 'ajax-chosen';
				$script_params = array(
					'deleteUserWarning' => __( "WARNING: Deleting a user will also remove them from any subscription.", 'woocommerce-subscriptions' )
				);
			} else if ( self::$admin_screen_id == $screen->id ) {
				$dependencies[] = 'ajax-chosen';
				$script_params = array(
					'ajaxDateChangeNonce'  => wp_create_nonce( 'woocommerce-subscriptions' ),
					'searchCustomersNonce' => wp_create_nonce( 'search-customers' ),
					'searchCustomersLabel' => __( 'Show all customers', 'woocommerce-subscriptions' ),
					'searchProductsNonce'  => wp_create_nonce( 'search-products' ),
				);
			}

			$script_params['ajaxLoaderImage'] = $woocommerce->plugin_url() . '/assets/images/ajax-loader.gif';
			$script_params['ajaxUrl']         = admin_url('admin-ajax.php');
			$script_params['isWCPre21']       = var_export( WC_Subscriptions::is_woocommerce_pre_2_1(), true );
			$script_params['isWCPre22']       = var_export( WC_Subscriptions::is_woocommerce_pre_2_2(), true );

			wp_enqueue_script( 'woocommerce_subscriptions_admin', plugin_dir_url( WC_Subscriptions::$plugin_file ) . 'js/admin.js', $dependencies, filemtime( plugin_dir_path( WC_Subscriptions::$plugin_file ) . 'js/admin.js' ) );
			wp_localize_script( 'woocommerce_subscriptions_admin', 'WCSubscriptions', apply_filters( 'woocommerce_subscriptions_admin_script_parameters', $script_params ) );

			// Maybe add the pointers for first timers
			if ( isset( $_GET['subscription_pointers'] ) && self::show_user_pointers() ) {

				$dependencies[] = 'wp-pointer';

				$pointer_script_params = array(
					'typePointerContent'  => sprintf( __( '%sChoose Subscription%sThe WooCommerce Subscriptions extension adds two new subscription product types - %sSimple subscription%s and %sVariable subscription%s.%s', 'woocommerce-subscriptions' ), '<h3>', '</h3><p>', '<em>', '</em>', '<em>', '</em>', '</p>' ),
					'pricePointerContent' => sprintf( __( '%sSet a Price%sSubscription prices are a little different to other product prices. For a subscription, you can set a billing period, length, sign-up fee and free trial.%s', 'woocommerce-subscriptions' ), '<h3>', '</h3><p>', '</p>' ),
				);

				wp_enqueue_script( 'woocommerce_subscriptions_admin_pointers', plugin_dir_url( WC_Subscriptions::$plugin_file ) . 'js/admin-pointers.js', $dependencies, WC_Subscriptions::$version );

				wp_localize_script( 'woocommerce_subscriptions_admin_pointers', 'WCSPointers', apply_filters( 'woocommerce_subscriptions_admin_pointer_script_parameters', $pointer_script_params ) );

				wp_enqueue_style( 'wp-pointer' );
			}

		}

		// Maybe add the admin notice
		if ( $is_activation_screen ) {

			$woocommerce_plugin_dir_file = self::get_woocommerce_plugin_dir_file();

			if ( ! empty( $woocommerce_plugin_dir_file ) ) {

				wp_enqueue_style( 'woocommerce-activation', plugins_url(  '/assets/css/activation.css', self::get_woocommerce_plugin_dir_file() ), array(), WC_Subscriptions::$version );

				if ( ! isset( $_GET['page'] ) || 'wcs-about' != $_GET['page'] ) {
					add_action( 'admin_notices', __CLASS__ . '::admin_installed_notice' );
				}

			}
			delete_transient( WC_Subscriptions::$activation_transient );
		}

		if ( $is_woocommerce_screen || $is_activation_screen ) {
			wp_enqueue_style( 'woocommerce_admin_styles', $woocommerce->plugin_url() . '/assets/css/admin.css', array(), WC_Subscriptions::$version );
			wp_enqueue_style( 'woocommerce_subscriptions_admin', plugin_dir_url( WC_Subscriptions::$plugin_file ) . 'css/admin.css', array( 'woocommerce_admin_styles' ), WC_Subscriptions::$version );
		}

	}

	/**
	 * Add the "Active Subscriber?" column to the User's admin table
	 */
	public static function add_user_columns( $columns ) {

		if ( current_user_can( 'manage_woocommerce' ) ) {
			// Move Active Subscriber before Orders for aesthetics
			$last_column = array_slice( $columns, -1, 1, true );
			array_pop( $columns );
			$columns['woocommerce_active_subscriber'] = __( 'Active Subscriber?', 'woocommerce-subscriptions' );
			$columns += $last_column;
		}

		return $columns;
	}

	/**
	 * Hooked to the users table to display a check mark if a given user has an active subscription.
	 *
	 * @param string $value The string to output in the column specified with $column_name
	 * @param string $column_name The string key for the current column in an admin table
	 * @param int $user_id The ID of the user to which this row relates
	 * @return string $value A check mark if the column is the active_subscriber column and the user has an active subscription.
	 * @since 1.0
	 */
	public static function user_column_values( $value, $column_name, $user_id ) {
		global $woocommerce;

		if ( $column_name == 'woocommerce_active_subscriber' ) {

			if ( WC_Subscriptions_Manager::user_has_subscription( $user_id, '', 'active' ) ) {

				if ( WC_Subscriptions::is_woocommerce_pre_2_1() ) {
					$value = '<img src="' . $woocommerce->plugin_url() . '/assets/images/success.png" alt="yes" width="16px" />';
				} else {
					$value = '<div class="active-subscriber"></div>';
				}

			} else {

				if ( WC_Subscriptions::is_woocommerce_pre_2_1() ) {
					$value = '<img src="' . $woocommerce->plugin_url() . '/assets/images/success-off.png" alt="no" width="16px" />';
				} else {
					$value = '<div class="inactive-subscriber">-</div>';
				}

			}

		}

		return $value;
	}

	/**
	 * Add a Subscriptions Management page under WooCommerce top level admin menu
	 *
	 * @since 1.0
	 */
	public static function add_menu_pages() {
		$page_hook = add_submenu_page( 'woocommerce', __( 'Manage Subscriptions', 'woocommerce-subscriptions' ),  __( 'Subscriptions', 'woocommerce-subscriptions' ), 'manage_woocommerce', self::$tab_name, __CLASS__ . '::subscriptions_management_page' );

		// Add the screen options tab
		add_action( "load-$page_hook", __CLASS__ . '::add_manage_subscriptions_screen_options' );

		// Make sure the list table is constructed (and actions processed) before the page's headers are sent
		add_action( "load-$page_hook", __CLASS__ . '::get_subscriptions_list_table' );
	}

	/**
	 * Outputs the Subscription Management admin page with a sortable @see WC_Subscriptions_List_Table used to
	 * display all the subscriptions that have been purchased.
	 *
	 * @uses WC_Subscriptions_List_Table
	 * @since 1.0
	 */
	public static function subscriptions_management_page() {

		$subscriptions_table = self::get_subscriptions_list_table();
		$subscriptions_table->prepare_items(); ?>
<div class="wrap">
	<div id="icon-woocommerce" class="icon32-woocommerce-users icon32"><br/></div>
	<h2><?php _e( 'Manage Subscriptions', 'woocommerce-subscriptions' ); ?></h2>
	<?php $subscriptions_table->messages(); ?>
	<?php $subscriptions_table->views(); ?>
	<?php if ( false === WC_Subscriptions::is_large_site() ) : ?>
	<form id="subscriptions-search" action="" method="get"><?php // Don't send all the subscription meta across ?>
		<?php $subscriptions_table->search_box( __( 'Search Subscriptions', 'woocommerce-subscriptions' ), 'subscription' ); ?>
		<input type="hidden" name="page" value="subscriptions" />
		<?php if ( isset( $_REQUEST['status'] ) ) { ?>
			<input type="hidden" name="status" value="<?php echo esc_attr( $_REQUEST['status'] ); ?>" />
		<?php } ?>
	</form>
	<?php endif; ?>
	<form id="subscriptions-filter" action="" method="get">
		<?php $subscriptions_table->display(); ?>
	</form>
</div>
		<?php
	}

	/**
	 * Outputs the screen options on the Subscription Management admin page.
	 *
	 * @since 1.3.1
	 */
	public static function add_manage_subscriptions_screen_options() {
		add_screen_option( 'per_page', array(
			'label'   => __( 'Subscriptions', 'woocommerce-subscriptions' ),
			'default' => 10,
			'option'  => self::$option_prefix . '_admin_per_page',
			)
		);
	}

	/**
	 * Sets the correct value for screen options on the Subscription Management admin page.
	 *
	 * @since 1.3.1
	 */
	public static function set_manage_subscriptions_screen_option( $status, $option, $value ) {

		if ( self::$option_prefix . '_admin_per_page' == $option ) {
			return $value;
		}

		return $status;
	}

	/**
	 * Returns the columns for the Manage Subscriptions table, specifically used for adding the
	 * show/hide column screen options.
	 *
	 * @since 1.3.1
	 */
	public static function get_subscription_table_columns( $columns ) {

		$subscriptions_table = self::get_subscriptions_list_table();

		return array_merge( $subscriptions_table->get_columns(), $columns );
	}

	/**
	 * Returns the columns for the Manage Subscriptions table, specifically used for adding the
	 * show/hide column screen options.
	 *
	 * @since 1.3.1
	 */
	public static function get_subscriptions_list_table() {

		if ( ! isset( self::$subscriptions_list_table ) ) {

			if ( ! class_exists( 'WC_Subscriptions_List_Table' ) ) {
				require_once( 'class-wc-subscriptions-list-table.php' );
			}

			self::$subscriptions_list_table = new WC_Subscriptions_List_Table();
		}

		return self::$subscriptions_list_table;
	 }

	/**
	 * Uses the WooCommerce options API to save settings via the @see woocommerce_update_options() function.
	 *
	 * @uses woocommerce_update_options()
	 * @uses self::get_settings()
	 * @since 1.0
	 */
	public static function update_subscription_settings() {

		// Make sure automatic payments are on when manual renewals are switched off
		if ( ! isset( $_POST[self::$option_prefix . '_accept_manual_renewals'] ) && isset( $_POST[self::$option_prefix . '_turn_off_automatic_payments'] ) ) {
			unset( $_POST[self::$option_prefix . '_turn_off_automatic_payments'] );
		}

		woocommerce_update_options( self::get_settings() );
	}

	/**
	 * Uses the WooCommerce admin fields API to output settings via the @see woocommerce_admin_fields() function.
	 *
	 * @uses woocommerce_admin_fields()
	 * @uses self::get_settings()
	 * @since 1.0
	 */
	public static function subscription_settings_page() {
		woocommerce_admin_fields( self::get_settings() );
	}

	/**
	 * Add the Subscriptions settings tab to the WooCommerce settings tabs array.
	 *
	 * @param array $settings_tabs Array of WooCommerce setting tabs & their labels, excluding the Subscription tab.
	 * @return array $settings_tabs Array of WooCommerce setting tabs & their labels, including the Subscription tab.
	 * @since 1.0
	 */
	public static function add_subscription_settings_tab( $settings_tabs ) {

		$settings_tabs[self::$tab_name] = __( 'Subscriptions', 'woocommerce-subscriptions' );

		return $settings_tabs;
	}

	/**
	 * Sets default values for all the WooCommerce Subscription options. Called on plugin activation.
	 *
	 * @see WC_Subscriptions::activate_woocommerce_subscriptions
	 * @since 1.0
	 */
	public static function add_default_settings() {
		foreach ( self::get_settings() as $setting ) {
			if ( isset( $setting['default'] ) ) {
				add_option( $setting['id'], $setting['default'] );
			}
		}

	}

	/**
	 * Get all the settings for the Subscriptions extension in the format required by the @see woocommerce_admin_fields() function.
	 *
	 * @return array Array of settings in the format required by the @see woocommerce_admin_fields() function.
	 * @since 1.0
	 */
	public static function get_settings() {
		global $woocommerce;

		$roles = get_editable_roles();

		foreach ( $roles as $role => $details ) {
			$roles_options[ $role ] = translate_user_role( $details['name'] );
		}

		$available_gateways = array();

		foreach ( $woocommerce->payment_gateways->payment_gateways() as $gateway ) {
			if ( $gateway->supports( 'subscriptions' ) ) {
				$available_gateways[] = $gateway->title;
			}
		}

		if ( count( $available_gateways ) == 0 ) {
			$available_gateways_description = sprintf( __( 'No payment gateways capable of processing automatic subscription payments are enabled. Please enable the %sPayPal Standard%s gateway.', 'woocommerce-subscriptions' ), '<strong><a href="' . admin_url( 'admin.php?page=wc-settings&tab=checkout&section=wc_gateway_paypal' ) . '">', '</a></strong>' );
		} elseif ( count( $available_gateways ) == 1 ) {
			$available_gateways_description = sprintf( __( 'The %s gateway is enabled and can process automatic subscription payments.', 'woocommerce-subscriptions' ), '<strong>' . $available_gateways[0] . '</strong>' );
		} elseif ( count( $available_gateways ) > 1 ) {
			$available_gateways_description = sprintf( __( 'The %s & %s gateways can process automatic subscription payments.', 'woocommerce-subscriptions' ), '<strong>' . implode( '</strong>, <strong>', array_slice( $available_gateways, 0, count( $available_gateways ) - 1 ) ) . '</strong>', '<strong>' . array_pop( $available_gateways ) . '</strong>' );
		}

		return apply_filters( 'woocommerce_subscription_settings', array(

			array(
				'name'     => __( 'Button Text', 'woocommerce-subscriptions' ),
				'type'     => 'title',
				'desc'     => '',
				'id'       => self::$option_prefix . '_button_text'
			),

			array(
				'name'     => __( 'Add to Cart Button Text', 'woocommerce-subscriptions' ),
				'desc'     => __( 'A product displays a button with the text "Add to Cart". By default, a subscription changes this to "Sign Up Now". You can customise the button text for subscriptions here.', 'woocommerce-subscriptions' ),
				'tip'      => '',
				'id'       => self::$option_prefix . '_add_to_cart_button_text',
				'css'      => 'min-width:150px;',
				'default'  => __( 'Sign Up Now', 'woocommerce-subscriptions' ),
				'type'     => 'text',
				'desc_tip' => true,
			),

			array(
				'name'     => __( 'Place Order Button Text', 'woocommerce-subscriptions' ),
				'desc'     => __( 'Use this field to customise the text displayed on the checkout button when an order contains a subscription. Normally the checkout submission button displays "Place Order". When the cart contains a subscription, this is changed to "Sign Up Now".', 'woocommerce-subscriptions' ),
				'tip'      => '',
				'id'       => self::$option_prefix . '_order_button_text',
				'css'      => 'min-width:150px;',
				'default'  => __( 'Sign Up Now', 'woocommerce-subscriptions' ),
				'type'     => 'text',
				'desc_tip' => true,
			),

			array( 'type' => 'sectionend', 'id' => self::$option_prefix . '_button_text' ),

			array(
				'name'     => __( 'Roles', 'woocommerce-subscriptions' ),
				'type'     => 'title',
				'desc'     => __( 'Choose the default roles to assign to active and inactive subscribers. For record keeping purposes, a user account must be created for subscribers. Users with the <em>administrator</em> role, such as yourself, will never be allocated these roles to prevent locking out administrators.', 'woocommerce-subscriptions' ),
				'id'       => self::$option_prefix . '_role_options'
			),

			array(
				'name'     => __( 'Subscriber Default Role', 'woocommerce-subscriptions' ),
				'desc'     => __( 'When a subscription is activated, either manually or after a successful purchase, new users will be assigned this role.', 'woocommerce-subscriptions' ),
				'tip'      => '',
				'id'       => self::$option_prefix . '_subscriber_role',
				'css'      => 'min-width:150px;',
				'default'  => 'subscriber',
				'type'     => 'select',
				'options'  => $roles_options,
				'desc_tip' => true,
			),

			array(
				'name'     => __( 'Inactive Subscriber Role', 'woocommerce-subscriptions' ),
				'desc'     => __( 'If a subscriber\'s subscription is manually cancelled or expires, she will be assigned this role.', 'woocommerce-subscriptions' ),
				'tip'      => '',
				'id'       => self::$option_prefix . '_cancelled_role',
				'css'      => 'min-width:150px;',
				'default'  => 'customer',
				'type'     => 'select',
				'options'  => $roles_options,
				'desc_tip' => true,
			),

			array( 'type' => 'sectionend', 'id' => self::$option_prefix . '_role_options' ),

			array(
				'name'          => __( 'Renewals', 'woocommerce-subscriptions' ),
				'type'          => 'title',
				'desc'          => '',
				'id'            => self::$option_prefix . '_renewal_options'
			),

			array(
				'name'            => __( 'Manual Renewal Payments', 'woocommerce-subscriptions' ),
				'desc'            => __( 'Accept Manual Renewals', 'woocommerce-subscriptions' ),
				'id'              => self::$option_prefix . '_accept_manual_renewals',
				'default'         => 'no',
				'type'            => 'checkbox',
				'desc_tip'        => sprintf( __( "With manual renewals, a customer's subscription is put on-hold until they login and pay to renew it. %sLearn more%s.", 'woocommerce-subscriptions' ), '<a href="http://docs.woothemes.com/document/subscriptions/store-manager-guide/#accept-manual-renewals">', '</a>' ),
				'checkboxgroup'   => 'start',
				'show_if_checked' => 'option',
			),

			array(
				'desc'            => __( 'Turn off Automatic Payments', 'woocommerce-subscriptions' ),
				'id'              => self::$option_prefix . '_turn_off_automatic_payments',
				'default'         => 'no',
				'type'            => 'checkbox',
				'desc_tip'        => sprintf( __( 'If you never want a customer to be automatically charged for a subscription renewal payment, you can turn off automatic payments completely. %sLearn more%s.', 'woocommerce-subscriptions' ), '<a href="http://docs.woothemes.com/document/subscriptions/store-manager-guide/#turn-off-automatic-payments">', '</a>' ),
				'checkboxgroup'   => 'end',
				'show_if_checked' => 'yes',
			),

			array( 'type' => 'sectionend', 'id' => self::$option_prefix . '_renewal_options' ),

			array(
				'name'          => __( 'Miscellaneous', 'woocommerce-subscriptions' ),
				'type'          => 'title',
				'desc'          => '',
				'id'            => self::$option_prefix . '_miscellaneous'
			),

			array(
				'name'          => __( 'Customer Suspensions', 'woocommerce-subscriptions' ),
				'desc'          => __( 'suspensions per billing period.', 'woocommerce-subscriptions' ),
				'id'            => self::$option_prefix . '_max_customer_suspensions',
				'css'           => 'min-width:50px;',
				'default'       => 0,
				'type'          => 'select',
				'options'       => apply_filters( 'woocommerce_subscriptions_max_customer_suspension_range', array_merge( range( 0, 12 ), array( 'unlimited' => 'Unlimited' ) ) ),
				'desc_tip'      => __( 'Set a maximum number of times a customer can suspend their account for each billing period. For example, for a value of 3 and a subscription billed yearly, if the customer has suspended their account 3 times, they will not be presented with the option to suspend their account until the next year. Store managers will always be able able to suspend an active subscription. Set this to 0 to turn off the customer suspension feature completely.', 'woocommerce-subscriptions' ),
			),

			array(
				'name'          => __( 'Failed Payment', 'woocommerce-subscriptions' ),
				'desc'          => __( 'Add Outstanding Balance', 'woocommerce-subscriptions' ),
				'id'            => self::$option_prefix . '_add_outstanding_balance',
				'default'       => 'no',
				'checkboxgroup' => 'start',
				'type'          => 'checkbox',
				'desc_tip'      => __( 'If a recurring payment fails and the subscription is manually reactivated without processing the failed payment, you can choose to add the outstanding balance to the next payment.', 'woocommerce-subscriptions' ),
			),

			array(
				'name'          => __( 'Mixed Checkout', 'woocommerce-subscriptions' ),
				'desc'          => __( 'Allow subscriptions and products to be purchased simultaneously.', 'woocommerce-subscriptions' ),
				'id'            => self::$option_prefix . '_multiple_purchase',
				'default'       => 'no',
				'checkboxgroup' => 'start',
				'type'          => 'checkbox',
				'desc_tip'      => __( 'Allow subscriptions and products to be purchased in a single transaction.', 'woocommerce-subscriptions' ),
			),

			array(
				'name'          => __( 'Subscription Dates In Email', 'woocommerce-subscriptions' ),
				'desc'          => __( 'Add subscription start/end information to order email.', 'woocommerce-subscriptions' ),
				'id'            => self::$option_prefix . '_add_sub_info_email',
				'default'       => 'no',
				'checkboxgroup' => 'start',
				'type'          => 'checkbox',
				'desc_tip'      => __( 'Add details about the subscription start and end date in the completed order email.', 'woocommerce-subscriptions' ),
			),

			array( 'type' => 'sectionend', 'id' => self::$option_prefix . '_miscellaneous' ),

			array(
				'name'          => __( 'Payment Gateways', 'woocommerce-subscriptions' ),
				'type'          => 'title',
				'desc'          => '',
				'id'            => self::$option_prefix . '_payment_gateways_title'
			),

			array(
				'desc'          => $available_gateways_description,
				'id'            => self::$option_prefix . '_payment_gateways_available',
				'type'          => 'informational'
			),

			array(
				'desc'          => sprintf( __( 'Other payment gateways can be used to process %smanual subscription renewal payments%s only.', 'woocommerce-subscriptions' ), '<a href="http://docs.woothemes.com/document/subscriptions/renewal-process/">', '</a>' ),
				'id'            => self::$option_prefix . '_payment_gateways_additional',
				'type'          => 'informational'
			),

			array(
				'desc'          => sprintf( __( 'Find new gateways that %ssupport automatic subscription payments%s in the official %sWooCommerce Marketplace%s.', 'woocommerce-subscriptions' ), '<a href="' . esc_url( 'http://docs.woothemes.com/document/subscriptions/payment-gateways/' ) . '">', '</a>', '<a href="' . esc_url( 'http://www.woothemes.com/product-category/woocommerce-extensions/' ) . '">', '</a>' ),
				'id'            => self::$option_prefix . '_payment_gateways_additional',
				'type'          => 'informational'
			),

			array( 'type' => 'sectionend', 'id' => self::$option_prefix . '_payment_gateway_options' ),

		));

	}

	/**
	 * Displays instructional information for a WooCommerce setting.
	 *
	 * @since 1.0
	 */
	public static function add_informational_admin_field( $field_details ) {

		if ( isset( $field_details['name'] ) && $field_details['name'] ) {
			echo '<h3>' . $field_details['name'] . '</h3>';
		}

		if ( isset( $field_details['desc'] ) && $field_details['desc'] ) {
			echo wpautop( wptexturize( $field_details['desc'] ) );
		}
	}

	/**
	 * Outputs a welcome message. Called when the Subscriptions extension is activated.
	 *
	 * @since 1.0
	 */
	public static function admin_installed_notice() { ?>
<div id="message" class="updated woocommerce-message wc-connect woocommerce-subscriptions-activated">
	<div class="squeezer">
		<h4><?php printf( __( '%sWooCommerce Subscriptions Installed%s &#8211; %sYou\'re ready to start selling subscriptions!%s', 'woocommerce-subscriptions' ), '<strong>', '</strong>', '<em>', '</em>' ); ?></h4>

		<p class="submit">
			<a href="<?php echo self::add_subscription_url(); ?>" class="button button-primary"><?php _e( 'Add a Subscription Product', 'woocommerce-subscriptions' ); ?></a>
			<a href="<?php echo self::settings_tab_url(); ?>" class="docs button button-primary"><?php _e( 'Settings', 'woocommerce-subscriptions' ); ?></a>
			<a href="https://twitter.com/share" class="twitter-share-button" data-url="http://www.woothemes.com/products/woocommerce-subscriptions/" data-text="Woot! I can sell subscriptions with #WooCommerce" data-via="WooThemes" data-size="large">Tweet</a>
			<script>!function(d,s,id){var js,fjs=d.getElementsByTagName(s)[0];if(!d.getElementById(id)){js=d.createElement(s);js.id=id;js.src="//platform.twitter.com/widgets.js";fjs.parentNode.insertBefore(js,fjs);}}(document,"script","twitter-wjs");</script>
		</p>
	</div>
</div>
		<?php
	}

	/**
	 * Checks whether a user should be shown pointers or not, based on whether a user has previously dismissed pointers.
	 *
	 * @since 1.0
	 */
	public static function show_user_pointers(){
		// Get dismissed pointers
		$dismissed = explode( ',', (string) get_user_meta( get_current_user_id(), 'dismissed_wp_pointers', true ) );

		// Pointer has been dismissed
		if ( in_array( 'wcs_pointer', $dismissed ) ) {
			return false;
		} else {
			return true;
		}
	}

	/**
	 * Returns a URL for adding/editing a subscription, which special parameters to define whether pointers should be shown.
	 *
	 * The 'select_subscription' flag is picked up by JavaScript to set the value of the product type to "Subscription".
	 *
	 * @since 1.0
	 */
	public static function add_subscription_url( $show_pointers = true ) {
		$add_subscription_url = admin_url( 'post-new.php?post_type=product&select_subscription=true' );

		if ( $show_pointers == true ) {
			$add_subscription_url = add_query_arg( 'subscription_pointers', 'true', $add_subscription_url );
		}

		return $add_subscription_url;
	}

	/**
	 * Searches through the list of active plugins to find WooCommerce. Just in case
	 * WooCommerce resides in a folder other than /woocommerce/
	 *
	 * @since 1.0
	 */
	public static function get_woocommerce_plugin_dir_file() {

		$woocommerce_plugin_file = '';

		foreach ( get_option( 'active_plugins', array() ) as $plugin ) {
			if ( substr( $plugin, strlen( '/woocommerce.php' ) * -1 ) === '/woocommerce.php' ) {
				$woocommerce_plugin_file = $plugin;
				break;
			}
		}

		return $woocommerce_plugin_file;
	}

	/**
	 * Registers the "Renewal Orders" meta box for the "Edit Order" page.
	 */
	public static function add_meta_boxes() {
		global $current_screen, $post_id;

		// Only display the meta box if an order relates to a subscription
		if ( 'shop_order' == $current_screen->id ) {

			$order_contains_subscription = WC_Subscriptions_Order::order_contains_subscription( $post_id );

			if ( $order_contains_subscription || WC_Subscriptions_Renewal_Order::is_renewal( $post_id, array( 'order_role' => 'child' ) ) ) {
				add_meta_box( 'subscription_renewal_orders', __( 'Related Subscription Orders', 'woocommerce-subscriptions' ), __CLASS__ . '::related_orders_meta_box', 'shop_order', 'normal', 'default' );
			}

			if ( $order_contains_subscription || 'add' == $current_screen->action ) {
				if ( ! WC_Subscriptions::is_woocommerce_pre_2_2() ) {
					add_meta_box( 'woocommerce-order-totals', __( 'Recurring Totals', 'woocommerce-subscriptions' ), __CLASS__ . '::recurring_totals_meta_box', 'shop_order', 'side', 'high' );
				} else {
					// WC 2.1 compatibility
					add_filter( 'woocommerce_admin_order_totals_after_shipping', 'WC_Subscriptions_Order::recurring_order_totals_meta_box_section', 100, 1 );
				}
			}
		}
	}

	/**
	 * Outputs the contents of the "Renewal Orders" meta box.
	 *
	 * @param object $post Current post data.
	 */
	public static function related_orders_meta_box( $post ) {

		$order = new WC_Order( absint( $post->ID ) );

		do_action( 'woocommerce_subscriptions_related_orders_meta_box', $order, $post );
	}

	/**
	 * Output the metabox
	 */
	public static function recurring_totals_meta_box( $post ) {
		WC_Subscriptions_Order::recurring_order_totals_meta_box_section( $post->ID );
	}

	/**
	 * Filter the "Orders" list to show only renewal orders associated with a specific parent order.
	 *
	 * @param array $request
	 * @return array
	 */
	public static function filter_orders_by_renewal_parent( $request )  {
		global $typenow;
		$query_arg = '_renewal_order_parent_id';
		if ( is_admin() && $typenow == 'shop_order' && isset( $_GET[ $query_arg ] ) && $_GET[ $query_arg ] > 0 ) {
			$request['post_parent'] = absint( $_GET[ $query_arg ] );
		}
		return $request;
	}

	/**
	 * Display a notice indicating that the "Orders" list is filtered.
	 * @see self::filter_orders_by_renewal_parent()
	 */
	public static function display_renewal_filter_notice() {
		$query_arg = '_renewal_order_parent_id';
		if ( isset( $_GET[ $query_arg ] ) && $_GET[ $query_arg ] > 0 ) {

			$initial_order = new WC_Order( absint( $_GET[ $query_arg ] ) );
			echo '<div class="updated"><p>';
			printf(
				'<a href="%1$s" class="close-subscriptions-search">&times;</a>',
				remove_query_arg($query_arg)
			);
			printf(
				__( 'Showing renewal orders for the subscription purchased in <a href="%1$s">Order %2$s</a>', 'woocommerce-subscriptions'),
				get_edit_post_link( absint( $_GET[ $query_arg ] ) ),
				$initial_order->get_order_number()
			);
			echo '</p></div>';

		}
	}

	/**
	 * Returns either a string or array of strings describing the allowable trial period range
	 * for a subscription.
	 *
	 * @since 1.0
	 */
	public static function get_trial_period_validation_message( $form = 'combined' ) {

		$subscription_ranges = WC_Subscriptions_Manager::get_subscription_ranges();

		if ( 'combined' == $form ) {
			$error_message = sprintf( __( 'The trial period can not exceed: %1s, %2s, %3s or %4s.', 'woocommerce-subscriptions' ), array_pop( $subscription_ranges['day'] ), array_pop( $subscription_ranges['week'] ), array_pop( $subscription_ranges['month'] ), array_pop( $subscription_ranges['year'] ) );
		} else {
			foreach ( WC_Subscriptions_Manager::get_available_time_periods() as $period => $string ) {
				$error_message[ $period ] = sprintf( __( 'The trial period can not exceed %1s.', 'woocommerce-subscriptions' ), array_pop( $subscription_ranges[ $period ] ) );
			}
		}

		return apply_filters( 'woocommerce_subscriptions_trial_period_validation_message', $error_message );
	}

	/**
	 * Add users with subscriptions to the "Customers" report in WooCommerce -> Reports.
	 *
	 * @param WP_User_Query $user_query
	 */
	public static function add_subscribers_to_customers( $user_query ) {
		global $plugin_page, $wpdb;

		if ( ! is_admin() || $plugin_page !== 'woocommerce_reports' || ! isset( $_GET['tab'] ) ) {
			return $user_query;
		}

		if ( 'customers' === $_GET['tab'] && isset( $user_query->query_vars['role'] ) && $user_query->query_vars['role'] === 'customer' ) {
			$users_with_subscriptions = WC_Subscriptions_Manager::get_all_users_subscriptions();
			$include_user_ids = array();
			foreach ( $users_with_subscriptions as $user_id => $subscriptions ) {
				if ( !empty($subscriptions) ) {
					$include_user_ids[] = $user_id;
				}
			}

			if ( !empty($include_user_ids) ) {
				//Turn the original customer query into a sub-query.
				$user_query->query_from = "FROM {$wpdb->users} LEFT JOIN (
						SELECT {$wpdb->users}.ID
						{$user_query->query_from}
						{$user_query->query_where}
					) AS customers ON (customers.ID = {$wpdb->users}.ID)";

				//Select users with subscriptions + customers returned by the original query.
				$user_query->query_where = sprintf(
					"WHERE ({$wpdb->users}.ID IN (%s)) OR (customers.ID IS NOT NULL)",
					implode(', ', $include_user_ids)
				);
			}
		}
	}

	/**
	 * Callback for the [subscriptions] shortcode that displays subscription names for a particular user.
	 *
	 * @param array $attributes Shortcode attributes.
	 * @return string
	 */
	public static function do_subscriptions_shortcode( $attributes ) {
		$attributes = wp_parse_args(
			$attributes,
			array(
				'user_id' => 0,
				'status'  => 'active',
            )
		);
		$status = $attributes['status'];

		$subscriptions = WC_Subscriptions_Manager::get_users_subscriptions( $attributes['user_id'] );
		if ( empty( $subscriptions ) ) {
			return '<ul class="user-subscriptions no-user-subscriptions">
						<li>No subscriptions found.</li>
					</ul>';
		}

		$list = '<ul class="user-subscriptions">';
		foreach ( $subscriptions as $subscription ) {
			if ( ( $subscription['status'] == $status ) || ( $status == 'all' ) ) {

				$subscription_details = WC_Subscriptions_Order::get_item_name( $subscription['order_id'], $subscription['product_id'] );

				$order      = new WC_Order( $subscription['order_id'] );
				$order_item = WC_Subscriptions_Order::get_item_by_product_id( $subscription['order_id'], $subscription['product_id'] );
				$product    = $order->get_product_from_item( $order_item );

				if ( isset( $product->variation_data ) ) {
					$subscription_details .= ' <span class="subscription-variation-data">(' . woocommerce_get_formatted_variation( $product->variation_data, true ) . ')</span>';
				}

				$list .= sprintf( '<li>%s</li>', $subscription_details );
			}
		}
		$list .= '</ul>';

		return $list;
	}

	/**
	 * Callback for the [subscriptions] shortcode that displays subscription names for a particular user.
	 *
	 * @param array $attributes Shortcode attributes.
	 * @return string
	 */
	private static function clean_number( $number ) {

		$number = preg_replace( "/[^0-9\.]/", '', $number );

		return $number;
	}

	/**
	 * Adds Subscriptions specific details to the WooCommerce System Status report.
	 *
	 * @param array $attributes Shortcode attributes.
	 * @return array
	 */
	public static function add_system_status_items( $debug_data ) {

		$is_wcs_debug = defined( 'WCS_DEBUG' ) ? WCS_DEBUG : false;

		$debug_data['wcs_debug'] = array(
			'name'    => __( 'WCS_DEBUG', 'woocommerce-subscriptions' ),
			'note'    => ( $is_wcs_debug ) ? __( 'Yes', 'woocommerce-subscriptions' ) :  __( 'No', 'woocommerce-subscriptions' ),
			'success' => $is_wcs_debug ? 0 : 1,
		);

		$debug_data['wcs_staging'] = array(
			'name'    => __( 'Subscriptions Mode', 'woocommerce-subscriptions' ),
			'note'    => ( WC_Subscriptions::is_duplicate_site() ) ? __( '<strong>Staging</strong>', 'woocommerce-subscriptions' ) :  __( '<strong>Live</strong>', 'woocommerce-subscriptions' ),
			'success' => ( WC_Subscriptions::is_duplicate_site() ) ? 0 :  1,
		);

		return $debug_data;
	}

	/**
	 * A WooCommerce version aware function for getting the Subscriptions admin settings
	 * tab URL.
	 *
	 * @since 1.4.5
	 * @return string
	 */
	public static function settings_tab_url() {

		if ( WC_Subscriptions::is_woocommerce_pre_2_1() ) {
			$settings_tab_url = admin_url( 'admin.php?page=woocommerce_settings&tab=subscriptions' );
		} else {
			$settings_tab_url = admin_url( 'admin.php?page=wc-settings&tab=subscriptions' );
		}

		return apply_filters( 'woocommerce_subscriptions_settings_tab_url', $settings_tab_url );
	}

	/**
	 * Add a column to the Payment Gateway table to show whether the gateway supports automated renewals.
	 *
	 * @since 1.5
	 * @return string
	 */
	public static function payment_gateways_rewewal_column( $header ) {

		$header_new = array_slice($header, 0, count($header) - 1, true) +
			array('renewals' => __('Automatic Recurring Payments', 'woocommerce-subscriptions') ) + // Ideally, we could add a link to the docs here, but the title is passed through esc_html()
			array_slice($header, count($header) - 1, count($header)-(count($header) - 1), true);

		return $header_new;
	}

	/**
	 * Check whether the payment gateway passed in supports automated renewals or not.
	 * Automatically flag support for Paypal since it is included with subscriptions.
	 * Display in the Payment Gateway column.
	 *
	 * @since 1.5
	 */
	public static function payment_gateways_rewewal_support( $gateway ) {

		echo '<td class="renewals">';
		if ( ( is_array( $gateway->supports ) && in_array( 'subscriptions', $gateway->supports ) ) || $gateway->id == 'paypal' ) {
			echo '<span class="status-enabled tips" data-tip="' . __( 'Supports automatic renewal payments with the WooCommerce Subscriptions extension.', 'woocommerce-subscriptions' ) . '">' . __ ( 'Yes', 'woocommerce-subscriptions' ) . '</span>';
		} else {
			echo '-';
		}
		echo '</td>';

	}

	/**
	 * Deprecated due to new meta boxes required for WC 2.2.
	 *
	 * @deprecated 1.5.10
	 */
	public static function add_related_orders_meta_box() {
		_deprecated_function( __CLASS__ . '::' . __FUNCTION__, '1.5.10', __CLASS__ . '::add_meta_boxes()' );
		self::add_meta_boxes();
	}
}

WC_Subscriptions_Admin::init();
