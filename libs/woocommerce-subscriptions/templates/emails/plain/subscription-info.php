<?php
/**
 * Subscription information template
 *
 * @author	Brent Shepherd / Chuck Mac
 * @package WooCommerce_Subscriptions/Templates/Emails
 * @version 1.5
 */

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

echo __( 'Subscription Information:', 'woocommerce-subscriptions' ) . "\n\n";
foreach ( WC_Subscriptions_Order::get_recurring_items( $order ) as $item ) {
	echo __( 'Subscription', 'woocommerce-subscriptions' ) . ': ' . $item['name'] . "\n";
	echo __( 'Start Date', 'woocommerce-subscriptions' ) . ': ' . date_i18n( woocommerce_date_format(), strtotime( $item['subscription_start_date'] ) ) . "\n";
	echo __( 'End Date', 'woocommerce-subscriptions' ) . ': ' . (!empty($item['subscription_expiry_date']) ? date_i18n( woocommerce_date_format(), strtotime( $item['subscription_expiry_date'] ) ) : __('When Cancelled', 'woocommerce-subscriptions' ) );
	echo "\n\n";
}

echo "\n****************************************************\n\n";
