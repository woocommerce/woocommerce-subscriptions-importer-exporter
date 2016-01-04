<?php
/**
 * Admin section for the WooCommerce Subscriptions exporter
 *
 * @since 1.0
 */
class WCS_Export_Admin {

	public $exporter_setup = array();
	public $action        = '';
	public $error_message = '';

	private $exporter = null;

	/**
	 * Initialise all admin hooks and filters for the subscriptions exporter
	 *
	 * @since 1.0
	 */
	public function __construct() {
		add_action( 'admin_menu', array( &$this, 'add_sub_menu' ), 10 );

		add_action( 'admin_enqueue_scripts', array( &$this, 'enqueue_scripts' ) );

		$this->action = admin_url( 'admin.php?page=export_subscriptions' );
	}

	/**
	 * Adds the Subscriptions exporter under Tools
	 *
	 * @since 1.0
	 */
	public function add_sub_menu() {
		add_submenu_page( 'woocommerce', __( 'Subscription Exporter', 'wcs-importer' ),  __( 'Subscription Exporter', 'wcs-importer' ), 'manage_options', 'export_subscriptions', array( &$this, 'export_page' ) );
	}

	/**
	 * Load exporter scripts
	 *
	 * @since 1.0
	 */
	public function enqueue_scripts() {
		wp_enqueue_script( 'wcs-exporter-admin', plugin_dir_url( WCS_Importer::$plugin_file ) . '/assets/js/wcs-exporter.js' );
	}

	/**
	 * Export Home page
	 *
	 * @since 1.0
	 */
	public function export_page() {
		echo '<div class="wrap woocommerce">';
		echo '<h2>' . __( 'Subscription CSV Exporter', 'wcs-importer' ) . '</h2>';
		echo '<h2 class="nav-tab-wrapper woo-nav-tab-wrapper">';

		$tabs = array(
			'wcsi-export'  => __( 'Export', 'wcs-importer' ),
			'wcsi-headers' => __( 'CSV Headers', 'wcs-importer' ),
		);

		$current_tab = ( empty( $_GET[ 'tab' ] ) ) ? 'wcsi-export' : urldecode( $_GET[ 'tab' ] );

		foreach ( $tabs as $tab_id => $tab_title ) {

			$class = ( $tab_id == $current_tab ) ? array( 'nav-tab', 'nav-tab-active', 'wcsi-exporter-tabs' ) : array( 'nav-tab', 'wcsi-exporter-tabs' );

			echo '<a href="#" id="' . $tab_id . '" class="' . implode( ' ', array_map( 'sanitize_html_class', $class ) ) . '">' . esc_html( $tab_title ) . '</a>';

		}

		echo '</h2>';
		echo '<form class="wcsi-exporter-form" method="POST" action="' . esc_attr( add_query_arg( 'step', 'download' ) ) . '">';
		$this->home_page();
		echo '<p class="submit">';
		echo '<input type="submit" class="button" value="' . esc_html__( 'Export Subscriptions', 'wcs-importer' ) . '" />';
		echo '</p>';
		echo '</form>';
	}

	/**
	 * Export headers page
	 *
	 * Display a list of all the csv headers that can be exported. Each csv header can be modified and disabled
	 *
	 * @since 1.0
	 */
	public function export_headers() {

		$csv_headers = array(
			'subscription_id'      => __( 'Subscription ID', 'wcs-importer' ),
			'subscription_status'  => __( 'Subscription Status', 'wcs-importer' ),
			'start_date'           => __( 'Start Date', 'wcs-importer' ),
			'trial_end_date'       => __( 'Trial End Date', 'wcs-importer' ),
			'next_payment_date'    => __( 'Next Payment Date', 'wcs-importer' ),
			'end_payment_date'     => __( 'End Payment Date', 'wcs-importer' ),
			'shipping_total'       => __( 'Total Shipping', 'wcs-importer' ),
			'shipping_tax_total'   => __( 'Total Shipping Tax', 'wcs-importer' ),
			'fee_total'            => __( 'Total Subscription Fees', 'wcs-importer' ),
			'fee_tax_total'        => __( 'Total Fees Tax', 'wcs-importer' ),
			'tax_total'            => __( 'Subscription Total Tax', 'wcs-importer' ),
			'cart_discount'        => __( 'Cart Discount', 'wcs-importer' ),
			'order_discount'       => __( 'Subscription Discount', 'wcs-importer' ),
			'order_total'          => __( 'Subscription Total', 'wcs-importer' ),
			'payment_method'       => __( 'Payment Method', 'wcs-importer' ),
			'shipping_method'      => __( 'Shipping Method', 'wcs-importer' ),
			'billing_first_name'   => __( 'Billing First Name', 'wcs-importer' ),
			'billing_last_name'    => __( 'Billing Last Name', 'wcs-importer' ),
			'billing_email'        => __( 'Billing Email', 'wcs-importer' ),
			'billing_phone'        => __( 'Billing Phone', 'wcs-importer' ),
			'billing_address_1'    => __( 'Billing Address 1', 'wcs-importer' ),
			'billing_address_2'    => __( 'Billing Address 2', 'wcs-importer' ),
			'billing_postcode'     => __( 'Billing Postcode', 'wcs-importer' ),
			'billing_city'         => __( 'Billing City', 'wcs-importer' ),
			'billing_state'        => __( 'Billing State', 'wcs-importer' ),
			'billing_country'      => __( 'Billing Country', 'wcs-importer' ),
			'billing_company'      => __( 'Billing Company', 'wcs-importer' ),
			'shipping_first_name'  => __( 'Shipping First Name', 'wcs-importer' ),
			'shipping_last_name'   => __( 'Shipping Last Name', 'wcs-importer' ),
			'shipping_address_1'   => __( 'Shipping Address 1', 'wcs-importer' ),
			'shipping_address_2'   => __( 'Shipping Address 2', 'wcs-importer' ),
			'shipping_postcode'    => __( 'Shipping Post code', 'wcs-importer' ),
			'shipping_city'        => __( 'Shipping City', 'wcs-importer' ),
			'shipping_state'       => __( 'Shipping State', 'wcs-importer' ),
			'shipping_country'     => __( 'Shipping Country', 'wcs-importer' ),
			'shipping_company'     => __( 'Shipping Company', 'wcs-importer' ),
			'customer_note'        => __( 'Customer Note', 'wcs-importer' ),
			'order_items'          => __( 'Subscription Items', 'wcs-importer' ),
			'order_notes'          => __( 'Subscription order notes', 'wcs-importer' ),
			'coupon_items'         => __( 'Coupons', 'wcs-importer' ),
			'download_permissions' => __( 'Download Permissions Granted', 'wcs-importer' ),
		);
		?>

		<table class="widefat widefat_importer striped" id="wcsi-headers-table" style="display:none;">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Enabled', 'wcs-importer' ); ?></th>
					<th><?php esc_html_e( 'Subscription Details', 'wcs-importer' ); ?></th>
					<th><?php esc_html_e( 'Importer Compatible Header', 'wcs-importer' ); ?></th>
					<th><?php esc_html_e( 'CSV Column Header', 'wcs-importer' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $csv_headers as $data => $title ) : ?>
					<tr>
						<td width="15" style="text-align:center"><input type="checkbox" name="mapped[<?php echo $data; ?>]"></td>
						<td><label><?php esc_html_e( $title ); ?></label></td>
						<td><label><?php esc_html_e( $data ); ?></label></td>
						<td><input type="text" name="<?php esc_attr_e( $data ); ?>" value="<?php esc_attr_e( $data ); ?>" placeholder="<?php esc_attr_e( $data ); ?>"></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php

	}

}
