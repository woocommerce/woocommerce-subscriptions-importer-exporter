<?php
/**
 * Cancelled Subscription email (plain text)
 *
 * @author	Brent Shepherd
 * @package WooCommerce_Subscriptions/Templates/Emails/Plain
 * @version 1.4
 */
if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

echo $email_heading . "\n\n";

printf( __( 'A subscription belonging to %s %s has been cancelled. Their subscription\'s details are as follows:', 'woocommerce-subscriptions' ), $order->billing_first_name, $order->billing_last_name );

echo "\n\n****************************************************\n";

echo sprintf( __( 'Order number: %s', 'woocommerce-subscriptions'), $order->get_order_number() ) . "\n";
echo sprintf( __( 'Order date: %s', 'woocommerce-subscriptions'), date_i18n( __( 'jS F Y', 'woocommerce-subscriptions' ), strtotime( $order->order_date ) ) ) . "\n";
echo "\n" . sprintf( __( 'Subscription: %s', 'woocommerce-subscriptions' ), $item_name );
echo "\n" . sprintf( __( 'Price: %s', 'woocommerce-subscriptions' ), $recurring_total );
echo "\n" . sprintf( __( 'Last Payment: %s', 'woocommerce-subscriptions' ), $last_payment );
if ( $end_of_prepaid_term ) {
	echo "\n" . sprintf( __( 'End of Prepaid Term: %s', 'woocommerce-subscriptions' ), $end_of_prepaid_term );
}

do_action( 'woocommerce_email_order_meta', $order, true, true );

echo "\n\n****************************************************\n\n";

_e( 'Customer details', 'woocommerce-subscriptions' );

if ( $order->billing_email ) {
	echo __( 'Email:', 'woocommerce-subscriptions' ); echo $order->billing_email. "\n";
}

if ( $order->billing_phone ) {
	echo __( 'Tel:', 'woocommerce-subscriptions' ); ?> <?php echo $order->billing_phone. "\n";
}

woocommerce_get_template( 'emails/plain/email-addresses.php', array( 'order' => $order ) );

echo "\n****************************************************\n\n";

echo apply_filters( 'woocommerce_email_footer_text', get_option( 'woocommerce_email_footer_text' ) );