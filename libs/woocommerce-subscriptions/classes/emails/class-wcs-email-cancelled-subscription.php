<?php

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

/**
 * Cancelled Subscription Email
 *
 * An email sent to the admin when a subscription is cancelled (either by a store manager, or the customer).
 *
 * @class 	WCS_Email_Cancelled_Subscription
 * @version	1.4
 * @package	WooCommerce_Subscriptions/Classes/Emails
 * @author 	Brent Shepherd
 * @extends WC_Email
 */
class WCS_Email_Cancelled_Subscription extends WC_Email {

	/**
	 * Create an instance of the class.
	 *
	 * @access public
	 * @return void
	 */
	function __construct() {

		$this->id          = 'cancelled_subscription';
		$this->title       = __( 'Cancelled Subscription', 'woocommerce-subscriptions' );
		$this->description = __( 'Cancelled Subscription emails are sent when a customer\'s subscription is cancelled (either by a store manager, or the customer).', 'woocommerce-subscriptions' );

		$this->heading     = __( 'Subscription Cancelled', 'woocommerce-subscriptions' );
		$this->subject     = __( '[{blogname}] Subscription Cancelled', 'woocommerce-subscriptions' );

		$this->template_html  = 'emails/cancelled-subscription.php';
		$this->template_plain = 'emails/plain/cancelled-subscription.php';
		$this->template_base  = plugin_dir_path( WC_Subscriptions::$plugin_file ) . 'templates/';

		add_action( 'cancelled_subscription_notification', array( $this, 'trigger' ) );

		parent::__construct();

		$this->recipient = $this->get_option( 'recipient' );

		if ( ! $this->recipient ) {
			$this->recipient = get_option( 'admin_email' );
		}
	}

	/**
	 * trigger function.
	 *
	 * @access public
	 * @return void
	 */
	function trigger( $subscription_key ) {
		global $woocommerce;

		$this->subscription_key = $subscription_key;
		$this->object           = WC_Subscriptions_Manager::get_subscription( $subscription_key );

		if ( ! $this->is_enabled() || ! $this->get_recipient() ) {
			return;
		}

		$this->send( $this->get_recipient(), $this->get_subject(), $this->get_content(), $this->get_headers(), $this->get_attachments() );
	}

	/**
	 * get_content_html function.
	 *
	 * @access public
	 * @return string
	 */
	function get_content_html() {

		$order      = new WC_Order( $this->object['order_id'] );
		$item_name  = WC_Subscriptions_Order::get_item_name( $this->object['order_id'], $this->object['product_id'] );
		$order_item = WC_Subscriptions_Order::get_item_by_product_id( $order, $this->object['product_id'] );
		$product    = $order->get_product_from_item( $order_item );

		if ( isset( $product->variation_data ) ) {
			$item_name .= '<br/><small>' . woocommerce_get_formatted_variation( $product->variation_data, true ) . '</small>';
		}

		WC_Subscriptions_Order::$recurring_only_price_strings = true;
		$recurring_total =  WC_Subscriptions_Order::get_formatted_order_total( 0, $order );
		WC_Subscriptions_Order::$recurring_only_price_strings = false;

		$end_of_prepaid_term = wc_next_scheduled_action( 'scheduled_subscription_end_of_prepaid_term', array( 'user_id' => (int)$order->user_id, 'subscription_key' => $this->subscription_key ) );

		if ( false === $end_of_prepaid_term ) :
			$end_timestamp = strtotime( $this->object['end_date'] );
		else :
			$end_timestamp = $end_of_prepaid_term;
		endif;

		$end_timestamp += ( get_option( 'gmt_offset' ) * 3600 );

		ob_start();
		woocommerce_get_template(
			$this->template_html,
			array(
				'subscription_key'       => $this->subscription_key,
				'subscription'           => $this->object,
				'order'                  => $order,
				'email_heading'          => $this->get_heading(),
				'item_name'              => $item_name,
				'recurring_total'        => $recurring_total,
				'last_payment_date'      => date_i18n( woocommerce_date_format(), WC_Subscriptions_Manager::get_last_payment_date( $this->subscription_key, '', 'timestamp' ) ),
				'end_date'               => date_i18n( woocommerce_date_format(), $end_timestamp ),
			),
			'',
			$this->template_base
		);
		return ob_get_clean();
	}

	/**
	 * get_content_plain function.
	 *
	 * @access public
	 * @return string
	 */
	function get_content_plain() {

		$order      = new WC_Order( $this->object['order_id'] );
		$item_name  = WC_Subscriptions_Order::get_item_name( $this->object['order_id'], $this->object['product_id'] );
		$order_item = WC_Subscriptions_Order::get_item_by_product_id( $order, $this->object['product_id'] );
		$product    = $order->get_product_from_item( $order_item );

		if ( isset( $product->variation_data ) ) {
			$item_name .= ' (' . woocommerce_get_formatted_variation( $product->variation_data, true ) . ')';
		}

		WC_Subscriptions_Order::$recurring_only_price_strings = true;
		$recurring_total =  WC_Subscriptions_Order::get_formatted_order_total( 0, $order );
		WC_Subscriptions_Order::$recurring_only_price_strings = false;

		if ( $end_of_prepaid_term = wc_next_scheduled_action( 'scheduled_subscription_end_of_prepaid_term', array( 'user_id' => (int)$order->user_id, 'subscription_key' => $this->subscription_key ) ) ) {
			$end_of_prepaid_term = date_i18n( woocommerce_date_format(), $end_of_prepaid_term + ( get_option( 'gmt_offset' ) * 3600 ) );
		}

		ob_start();
		woocommerce_get_template(
			$this->template_plain,
			array(
				'subscription_key'    => $this->subscription_key,
				'subscription'        => $this->object,
				'order'               => $order,
				'email_heading'       => $this->get_heading(),
				'item_name'           => $item_name,
				'recurring_total'     => $recurring_total,
				'last_payment'        => date_i18n( woocommerce_date_format(), WC_Subscriptions_Manager::get_last_payment_date( $this->subscription_key, '', 'timestamp' ) ),
				'end_of_prepaid_term' => $end_of_prepaid_term,
			),
			'',
			$this->template_base
		);
		return ob_get_clean();
	}

	/**
	 * Initialise Settings Form Fields
	 *
	 * @access public
	 * @return void
	 */
	function init_form_fields() {
		$this->form_fields = array(
			'enabled' => array(
				'title' 		=> __( 'Enable/Disable', 'woocommerce-subscriptions' ),
				'type' 			=> 'checkbox',
				'label' 		=> __( 'Enable this email notification', 'woocommerce-subscriptions' ),
				'default' 		=> 'no'
			),
			'recipient' => array(
				'title' 		=> __( 'Recipient(s)', 'woocommerce-subscriptions' ),
				'type' 			=> 'text',
				'description' 	=> sprintf( __( 'Enter recipients (comma separated) for this email. Defaults to <code>%s</code>.', 'woocommerce-subscriptions' ), esc_attr( get_option( 'admin_email' ) ) ),
				'placeholder' 	=> '',
				'default' 		=> ''
			),
			'subject' => array(
				'title' 		=> __( 'Subject', 'woocommerce-subscriptions' ),
				'type' 			=> 'text',
				'description' 	=> sprintf( __( 'This controls the email subject line. Leave blank to use the default subject: <code>%s</code>.', 'woocommerce-subscriptions' ), $this->subject ),
				'placeholder' 	=> '',
				'default' 		=> ''
			),
			'heading' => array(
				'title' 		=> __( 'Email Heading', 'woocommerce-subscriptions' ),
				'type' 			=> 'text',
				'description' 	=> sprintf( __( 'This controls the main heading contained within the email notification. Leave blank to use the default heading: <code>%s</code>.', 'woocommerce-subscriptions' ), $this->heading ),
				'placeholder' 	=> '',
				'default' 		=> ''
			),
			'email_type' => array(
				'title' 		=> __( 'Email type', 'woocommerce-subscriptions' ),
				'type' 			=> 'select',
				'description' 	=> __( 'Choose which format of email to send.', 'woocommerce-subscriptions' ),
				'default' 		=> 'html',
				'class'			=> 'email_type',
				'options'		=> array(
					'plain'		 	=> __( 'Plain text', 'woocommerce-subscriptions' ),
					'html' 			=> __( 'HTML', 'woocommerce-subscriptions' ),
					'multipart' 	=> __( 'Multipart', 'woocommerce-subscriptions' ),
				)
			)
		);
    }
}
