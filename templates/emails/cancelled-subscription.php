<?php
/**
 * Cancelled Subscription email
 *
 * @author	Brent Shepherd
 * @package WooCommerce_Subscriptions/Templates/Emails
 * @version 1.4
 */
if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly ?>

<?php do_action( 'woocommerce_email_header', $email_heading ); ?>

<p><?php printf( __( 'A subscription belonging to %s %s has been cancelled. Their subscription\'s details are as follows:', 'woocommerce-subscriptions' ), $order->billing_first_name, $order->billing_last_name ); ?></p>

<table cellspacing="0" cellpadding="6" style="width: 100%; border: 1px solid #eee;" border="1" bordercolor="#eee">
	<thead>
		<tr>
			<th scope="col" style="text-align:left; border: 1px solid #eee;"><?php _e( 'Order', 'woocommerce-subscriptions' ); ?></th>
			<th scope="col" style="text-align:left; border: 1px solid #eee;"><?php _e( 'Subscription', 'woocommerce-subscriptions' ); ?></th>
			<th scope="col" style="text-align:left; border: 1px solid #eee;"><?php _e( 'Price', 'woocommerce-subscriptions' ); ?></th>
			<th scope="col" style="text-align:left; border: 1px solid #eee;"><?php _e( 'Last Payment', 'woocommerce-subscriptions' ); ?></th>
			<th scope="col" style="text-align:left; border: 1px solid #eee;"><?php _e( 'End of Prepaid Term', 'woocommerce-subscriptions' ); ?></th>
		</tr>
	</thead>
	<tbody>
		<tr class="order">
			<td width="1%" style="text-align:left; border: 1px solid #eee; vertical-align:middle;">
				<?php echo $order->get_order_number(); ?>
			</td>
			<td style="text-align:left; border: 1px solid #eee; vertical-align:middle;">
				<?php echo $item_name; ?>
			</td>
			<td style="text-align:left; border: 1px solid #eee; vertical-align:middle;">
				<?php echo $recurring_total; ?>
			</td>
			<td style="text-align:left; border: 1px solid #eee; vertical-align:middle;">
				<?php echo $last_payment_date; ?>
			</td>
			<td style="text-align:left; border: 1px solid #eee; vertical-align:middle;">
				<?php echo $end_date; ?>
			</td>
		</tr>
	</tbody>
</table>

<h2><?php _e( 'Customer details', 'woocommerce-subscriptions' ); ?></h2>

<?php if ( $order->billing_email ) : ?>
	<p><strong><?php _e( 'Email:', 'woocommerce-subscriptions' ); ?></strong> <?php echo $order->billing_email; ?></p>
<?php endif; ?>
<?php if ( $order->billing_phone ) : ?>
	<p><strong><?php _e( 'Tel:', 'woocommerce-subscriptions' ); ?></strong> <?php echo $order->billing_phone; ?></p>
<?php endif; ?>

<?php woocommerce_get_template( 'emails/email-addresses.php', array( 'order' => $order ) ); ?>

<?php do_action( 'woocommerce_email_footer' ); ?>