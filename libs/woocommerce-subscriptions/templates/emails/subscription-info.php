<?php
/**
 * Subscription information template
 *
 * @author	Brent Shepherd / Chuck Mac
 * @package WooCommerce_Subscriptions/Templates/Emails
 * @version 1.5
 */
if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly ?>

<h2><?php _e( 'Subscription Information:', 'woocommerce-subscriptions' ) ?></h2>
<table cellspacing="0" cellpadding="6" style="width: 100%; border: 1px solid #eee;" border="1" bordercolor="#eee">
	<thead>
		<tr>
			<th scope="col" style="text-align:left; border: 1px solid #eee;"><?php _e( 'Subscription', 'woocommerce-subscriptions' ); ?></th>
			<th scope="col" style="text-align:left; border: 1px solid #eee;"><?php _e( 'Start Date', 'woocommerce-subscriptions' ); ?></th>
			<th scope="col" style="text-align:left; border: 1px solid #eee;"><?php _e( 'End Date', 'woocommerce-subscriptions' ); ?></th>
		</tr>
	</thead>
	<tbody>
	<?php
	foreach ( WC_Subscriptions_Order::get_recurring_items( $order ) as $item ) { ?>
			<tr>
				<td scope="row" style="text-align:left; border: 1px solid #eee;"><?php echo $item['name']; ?></td>
				<td scope="row" style="text-align:left; border: 1px solid #eee;"><?php echo date_i18n( woocommerce_date_format(), strtotime( $item['subscription_start_date'] ) ); ?></td>
				<td scope="row" style="text-align:left; border: 1px solid #eee;"><?php echo ( ! empty( $item['subscription_expiry_date'] ) ? date_i18n( woocommerce_date_format(), strtotime( $item['subscription_expiry_date'] ) ) : __( 'When Cancelled', 'woocommerce-subscriptions' ) ); ?>
			</tr>
	<?php
	}
?>
	</tbody>
</table>
