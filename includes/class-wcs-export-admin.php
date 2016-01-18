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

		add_action( 'admin_init', array( &$this, 'export_handler' ) );

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
		?>

		<div class="wrap woocommerce">
		<h2><?php __( 'Subscription CSV Exporter', 'wcs-importer' ); ?></h2>

		<?php if ( ! empty( $this->error_message ) ) : ?>
			<div id="message" class="error">
				<p><?php esc_html_e( $this->error_message ); ?></p>
			</div>
		<?php endif; ?>

		<h2 class="nav-tab-wrapper woo-nav-tab-wrapper"><?php

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
	 * Home page for the exporter. Allows the store manager to choose various options for the export.
	 *
	 * @since 1.0
	 */
	public function home_page() {
		global $wpdb;

		$statuses           = wcs_get_subscription_statuses();
		$status_count_query = $wpdb->get_results( "SELECT post_status, COUNT(*) AS count FROM {$wpdb->posts} WHERE post_type = 'shop_subscription' GROUP BY post_status" );
		$status_count       = array();

		foreach ( $status_count_query as $result ) {
			$status_count[ $result->post_status ] = $result->count;
		}

		?>
			<table class="widefat striped" id="wcsi-export-table">
				<tbody>
					<tr>
						<td width="200"><label for="filename"><?php esc_html_e( 'Export File name', 'wcs-importer' ); ?>:</label></th>
						<td><input type="text" name="filename" placeholder="export filename" value="<?php echo ! empty( $_POST['filename'] ) ? $_POST['filename'] : 'subscriptions.csv'; ?>" required></td>
					</tr>
					<tr>
						<td style="text-align:top"><?php esc_html_e( 'Subscription Statuses', 'wcs-importer' ); ?>:</td>
						<td>
							<?php foreach( $statuses as $status => $status_display ) : ?>
								<input type="checkbox" name="status[<?php echo $status; ?>]" checked><?php echo $status_display; ?>  [<?php echo ! empty( $status_count[ $status ] ) ? $status_count[ $status ] : 0; ?>]<br>
							<?php endforeach; ?>
						</td>
					</tr>
					<tr>
						<td><label for="customer"><?php esc_html_e( 'Export for Customer', 'wcs-importer' ); ?>:</label></td>
						<td><input type="number" name="customer" value="" placeholder="customer id" /></td>
					</tr>
					<tr>
						<td><label><?php esc_html_e( 'Payment method', 'wcs-importer' ); ?>:</label></td>
						<td>
							<select name="payment">
								<option value="any"><?php esc_html_e( 'Any Payment Method', 'wcs-importer' ); ?></option>
								<option value="none"><?php esc_html_e( 'None', 'wcs-importer' ); ?></option>

								<?php foreach ( WC()->payment_gateways->get_available_payment_gateways() as $gateway_id => $gateway ) : ?>
									<option value="<?php esc_attr_e( $gateway_id ); ?>"><?php esc_html_e( $gateway->title ); ?></option>;
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
					<!-- <tr>
						<td><label for="product"><?php esc_html_e( 'Products', 'wcs-importer' ); ?>:</label></td>
						<td><input type="text" name="product" value="" placeholder="product ids" /></td>
					</tr> -->
				</tbody>
		</table>

	<?php
		$this->export_headers();

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
			'subscription_id'       => __( 'Subscription ID', 'wcs-importer' ),
			'subscription_status'   => __( 'Subscription Status', 'wcs-importer' ),
			'customer_id'           => __( 'Customer ID', 'wcs-importer' ),
			'start_date'            => __( 'Start Date', 'wcs-importer' ),
			'trial_end_date'        => __( 'Trial End Date', 'wcs-importer' ),
			'next_payment_date'     => __( 'Next Payment Date', 'wcs-importer' ),
			'last_payment_date'     => __( 'Last Payment Date', 'wcs-importer' ),
			'end_date'              => __( 'End Date', 'wcs-importer' ),
			'shipping_total'        => __( 'Total Shipping', 'wcs-importer' ),
			'shipping_tax_total'    => __( 'Total Shipping Tax', 'wcs-importer' ),
			'fee_total'             => __( 'Total Subscription Fees', 'wcs-importer' ),
			'fee_tax_total'         => __( 'Total Fees Tax', 'wcs-importer' ),
			'tax_total'             => __( 'Subscription Total Tax', 'wcs-importer' ),
			'cart_discount'         => __( 'Cart Discount', 'wcs-importer' ),
			'order_discount'        => __( 'Subscription Discount', 'wcs-importer' ),
			'order_total'           => __( 'Subscription Total', 'wcs-importer' ),
			'payment_method'        => __( 'Payment Method', 'wcs-importer' ),
			'payment_method_title'  => __( 'Payment Method Title', 'wcs-importer' ),
			'shipping_method'       => __( 'Shipping Method', 'wcs-importer' ),
			'shipping_method_title' => __( 'Shipping Method', 'wcs-importer' ),
			'billing_first_name'    => __( 'Billing First Name', 'wcs-importer' ),
			'billing_last_name'     => __( 'Billing Last Name', 'wcs-importer' ),
			'billing_email'         => __( 'Billing Email', 'wcs-importer' ),
			'billing_phone'         => __( 'Billing Phone', 'wcs-importer' ),
			'billing_address_1'     => __( 'Billing Address 1', 'wcs-importer' ),
			'billing_address_2'     => __( 'Billing Address 2', 'wcs-importer' ),
			'billing_postcode'      => __( 'Billing Postcode', 'wcs-importer' ),
			'billing_city'          => __( 'Billing City', 'wcs-importer' ),
			'billing_state'         => __( 'Billing State', 'wcs-importer' ),
			'billing_country'       => __( 'Billing Country', 'wcs-importer' ),
			'billing_company'       => __( 'Billing Company', 'wcs-importer' ),
			'shipping_first_name'   => __( 'Shipping First Name', 'wcs-importer' ),
			'shipping_last_name'    => __( 'Shipping Last Name', 'wcs-importer' ),
			'shipping_address_1'    => __( 'Shipping Address 1', 'wcs-importer' ),
			'shipping_address_2'    => __( 'Shipping Address 2', 'wcs-importer' ),
			'shipping_postcode'     => __( 'Shipping Post code', 'wcs-importer' ),
			'shipping_city'         => __( 'Shipping City', 'wcs-importer' ),
			'shipping_state'        => __( 'Shipping State', 'wcs-importer' ),
			'shipping_country'      => __( 'Shipping Country', 'wcs-importer' ),
			'shipping_company'      => __( 'Shipping Company', 'wcs-importer' ),
			'customer_note'         => __( 'Customer Note', 'wcs-importer' ),
			'order_items'           => __( 'Subscription Items', 'wcs-importer' ),
			'order_notes'           => __( 'Subscription order notes', 'wcs-importer' ),
			'coupon_items'          => __( 'Coupons', 'wcs-importer' ),
			'download_permissions'  => __( 'Download Permissions Granted', 'wcs-importer' ),
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
						<td width="15" style="text-align:center"><input type="checkbox" name="mapped[<?php echo $data; ?>]" checked></td>
						<td><label><?php esc_html_e( $title ); ?></label></td>
						<td><label><?php esc_html_e( $data ); ?></label></td>
						<td><input type="text" name="<?php esc_attr_e( $data ); ?>" value="<?php esc_attr_e( $data ); ?>" placeholder="<?php esc_attr_e( $data ); ?>"></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php

	}

	/**
	 * Query the subscriptions using the users specific filters.
	 *
	 * @since 1.0
	 * @param array $filters
	 * @return array
	 */
	private function get_subscriptions_to_export( $filters ) {

		$args = array(
			'subscriptions_per_page' => -1,
			'subscription_status'    => 'none', // don't default to 'any' status if no statuses were chosen
		);

		if ( ! empty( $filters['statuses'] ) && is_array( $filters['statuses'] ) ) {
			$args['subscription_status'] = implode(',', $filters['statuses'] );
		}

		if ( ! empty( $filters['customer'] ) && is_numeric( $filters['customer'] ) ) {
			$args['customer_id'] = $filters['customer'];
		}

		if ( ! empty( $filters['payment_method'] ) ) {
			add_filter( 'woocommerce_get_subscriptions_query_args', array( &$this, 'filter_payment_method' ), 10, 2 );
		}

		return wcs_get_subscriptions( $args );
	}

	/**
	 * Filter the query in @see wcs_get_subscriptions() by payment method.
	 *
	 * @since 1.0
	 * @param array $query_args
	 * @param array $args
	 * @return array
	 */
	public function filter_payment_method( $query_args, $args ) {

		if ( isset( $_POST['payment'] ) && $_POST['payment'] != 'any' ) {
			$payment_payment = ( 'none' == $_POST['payment'] ) ? '' : $_POST['payment'];

			$query_args['meta_query'][] = array(
				'key'   => '_payment_method',
				'value' => $payment_payment,
			);
		}

		return $query_args;
	}

	/**
	 * Function to start the download process
	 *
	 * @since 1.0
	 * @param array $headers
	 */
	public function process_download( $headers = array() ) {
		require_once( 'class-wcs-export-writer.php' );

		$filters = array(
			'statuses'       => array_keys( $_POST['status'] ),
			'customer'       => isset( $_POST['customer'] ) ? $_POST['customer'] : '',
			'product'        => isset( $_POST['product'] ) ? $_POST['product'] : '',
			'payment_method' => $_POST['payment'],
		);

		$subscriptions = $this->get_subscriptions_to_export( $filters );

		if ( ! empty( $subscriptions ) ) {
			WCS_Export_Writer::write_headers( $headers );

			foreach ( $subscriptions as $subscription ) {
				WCS_Export_Writer::write_subscriptions_csv_row( $subscription );
			}

			WCS_Export_Writer::process_export( $_POST['filename'] );
		} else {
			$this->error_message = __( 'No subscriptions to export given the filters you have selected.', 'wcs-importer' );
		}

	}

	/**
	 * Check params sent through as POST and start the export
	 *
	 * @since 1.0
	 */
	public function export_handler() {

		if ( isset( $_GET['page'] ) && 'export_subscriptions' == $_GET['page'] ) {
			if ( isset( $_GET['step'] ) && 'download' == $_GET['step'] ) {
				if ( ! empty( $_POST['mapped'] ) ) {
					$csv_headers = array();

					foreach ( array_keys( $_POST['mapped'] ) as $column ) {
						if ( ! empty( $_POST[ $column ] ) ) {
							$csv_headers[ $column ] = $_POST[ $column ];
						}
					}
				}

				if ( ! empty( $csv_headers ) ) {
					$this->process_download( $csv_headers );
				} else {
					$this->error_message = __( 'No csv headers were chosen, please select at least one CSV header to complete the Subscriptions Exporter.', 'wcs-importer' );
				}
			}
		}
	}

}
