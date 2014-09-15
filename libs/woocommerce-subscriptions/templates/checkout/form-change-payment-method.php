<?php
/**
 * Pay for order form displayed after a customer has clicked the "Change Payment method" button
 * next to a subscription on their My Account page.
 *
 * @author 		WooThemes
 * @package 	WooCommerce/Templates
 * @version     1.6.4
 */

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

global $woocommerce;
?>
<form id="order_review" method="post">

	<table class="shop_table">
		<thead>
			<tr>
				<th class="product-name"><?php _e( 'Product', 'woocommerce-subscriptions' ); ?></th>
				<th class="product-quantity"><?php _e( 'Qty', 'woocommerce-subscriptions' ); ?></th>
				<th class="product-total"><?php _e( 'Totals', 'woocommerce-subscriptions' ); ?></th>
			</tr>
		</thead>
		<tfoot>
		<?php
			if ( $totals = $order->get_order_item_totals() ) foreach ( $totals as $total ) :
				?>
				<tr>
					<th scope="row" colspan="2"><?php echo $total['label']; ?></th>
					<td class="product-total"><?php echo $total['value']; ?></td>
				</tr>
				<?php
			endforeach;
		?>
		</tfoot>
		<tbody>
			<?php
			$recurring_order_items = WC_Subscriptions_Order::get_recurring_items( $order );
			if ( sizeof( $recurring_order_items ) > 0 ) :
				foreach ( $recurring_order_items as $item ) :
					echo '
						<tr>
							<td class="product-name">' . $item['name'] . '</td>
							<td class="product-quantity">' . $item['qty'] . '</td>
							<td class="product-subtotal">' . $order->get_formatted_line_subtotal( $item ) . '</td>
						</tr>';
				endforeach;
			endif;
			?>
		</tbody>
	</table>

	<div id="payment">
			<?php if ( $available_gateways = $woocommerce->payment_gateways->get_available_payment_gateways() ) { ?>
		<ul class="payment_methods methods">
			<?php
			if ( sizeof( $available_gateways ) ) {
				current( $available_gateways )->set_current();
			}

			foreach ( $available_gateways as $gateway ) { ?>
				<li>
					<input id="payment_method_<?php echo $gateway->id; ?>" type="radio" class="input-radio" name="payment_method" value="<?php echo esc_attr( $gateway->id ); ?>" <?php checked( $gateway->chosen, true ); ?> data-order_button_text="<?php echo esc_attr( $gateway->order_button_text ); ?>" />
					<label for="payment_method_<?php echo $gateway->id; ?>"><?php echo $gateway->get_title(); ?> <?php echo $gateway->get_icon(); ?></label>
					<?php
						if ( $gateway->has_fields() || $gateway->get_description() ) {
							echo '<div class="payment_box payment_method_' . $gateway->id . '" style="display:none;">';
							$gateway->payment_fields();
							echo '</div>';
						}
					?>
				</li>
				<?php
			} ?>
		</ul>
				<?php } else { ?>
		<div class="woocommerce-error">
			<p> <?php _e( 'Sorry, it seems no payment gateways support changing the recurring payment method. Please contact us if you require assistance or to make alternate arrangements.', 'woocommerce-subscriptions' ); ?></p>
		</div>
				<?php } ?>

		<?php if ( $available_gateways ) : ?>
		<div class="form-row">
			<?php wp_nonce_field( 'woocommerce-change_payment', '_wpnonce', true, true); ?>
			<?php
				$pay_order_button_text = apply_filters( 'woocommerce_change_payment_button_text', __( 'Change Payment Method', 'woocommerce-subscriptions' ) );

				echo apply_filters( 'woocommerce_change_payment_button_html', '<input type="submit" class="button alt" id="place_order" value="' . esc_attr( $pay_order_button_text ) . '" data-value="' . esc_attr( $pay_order_button_text ) . '" />' );
			?>
			<input type="hidden" name="woocommerce_change_payment" value="<?php echo $subscription_key; ?>" />
		</div>
		<?php endif; ?>

	</div>

</form>