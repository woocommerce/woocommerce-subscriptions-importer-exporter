<?php
/**
 * Customer renewal invoice email (plain text)
 *
 * @author	Brent Shepherd
 * @package WooCommerce_Subscriptions/Templates/Emails/Plain
 * @version 1.4
 */
if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

echo $email_heading . "\n\n";

if ( $order->status == 'pending' ) {
	echo sprintf( __( 'An invoice has been created for you to renew your subscription with %s. To pay for this order please use the following link: %s', 'woocommerce-subscriptions' ), get_bloginfo( 'name' ), $order->get_checkout_payment_url() ) . "\n\n";
} elseif ( 'failed' == $order->status ) {
	echo sprintf( __( 'The automatic payment to renew your subscription with %s has failed. To reactivate the subscription, please login and pay for the renewal from you account page: %s', 'woocommerce-subscriptions' ), get_bloginfo( 'name' ), $order->get_checkout_payment_url() );
}

echo "****************************************************\n\n";

do_action( 'woocommerce_email_before_order_table', $order, false );

echo sprintf( __( 'Order number: %s', 'woocommerce-subscriptions'), $order->get_order_number() ) . "\n";
echo sprintf( __( 'Order date: %s', 'woocommerce-subscriptions'), date_i18n( woocommerce_date_format(), strtotime( $order->order_date ) ) ) . "\n";

do_action( 'woocommerce_email_order_meta', $order, false, true );

echo "\n";

switch ( $order->status ) {
	case "completed" :
		echo $order->email_order_items_table( $order->is_download_permitted(), false, true, '', '', true );
	break;
	case "processing" :
		echo $order->email_order_items_table( $order->is_download_permitted(), true, true, '', '', true );
	break;
	default :
		echo $order->email_order_items_table( $order->is_download_permitted(), true, false, '', '', true );
	break;
}

echo "----------\n\n";

if ( $totals = $order->get_order_item_totals() ) {
	foreach ( $totals as $total ) {
		echo $total['label'] . "\t " . $total['value'] . "\n";
	}
}

echo "\n****************************************************\n\n";

do_action( 'woocommerce_email_after_order_table', $order, false, true );

echo apply_filters( 'woocommerce_email_footer_text', get_option( 'woocommerce_email_footer_text' ) );