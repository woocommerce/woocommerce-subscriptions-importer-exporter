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

		add_filter( 'woocommerce_screen_ids', array( &$this, 'register_export_screen_id' ) );

		$this->action = admin_url( 'admin.php?page=export_subscriptions' );
	}

	/**
	 * Adds the Subscriptions exporter under Tools
	 *
	 * @since 1.0
	 */
	public function add_sub_menu() {
		add_submenu_page( 'woocommerce', __( 'Subscription Exporter', 'wcs-import-export' ),  __( 'Subscription Exporter', 'wcs-import-export' ), 'manage_options', 'export_subscriptions', array( &$this, 'export_page' ) );
	}

	/**
	 * Load exporter scripts
	 *
	 * @since 1.0
	 */
	public function enqueue_scripts() {
		wp_enqueue_script( 'wcs-exporter-admin', plugin_dir_url( WCS_Importer_Exporter::$plugin_file ) . '/assets/js/wcs-exporter.js' );
	}

	/**
	 * Export Home page
	 *
	 * @since 1.0
	 */
	public function export_page() {
		?>

		<div class="wrap woocommerce">
		<h2><?php __( 'Subscription CSV Exporter', 'wcs-import-export' ); ?></h2>

		<?php if ( ! empty( $this->error_message ) ) : ?>
			<div id="message" class="error">
				<p><?php esc_html_e( $this->error_message ); ?></p>
			</div>
		<?php endif; ?>

		<h2 class="nav-tab-wrapper woo-nav-tab-wrapper"><?php

		$tabs = array(
			'wcsi-export'  => __( 'Export', 'wcs-import-export' ),
			'wcsi-headers' => __( 'CSV Headers', 'wcs-import-export' ),
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
		echo '<input type="submit" class="button" value="' . esc_html__( 'Export Subscriptions', 'wcs-import-export' ) . '" />';
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

		$statuses      = wcs_get_subscription_statuses();
		$status_counts = array();

		foreach ( wp_count_posts( 'shop_subscription' ) as $status => $count ) {
			if ( array_key_exists( $status, $statuses ) ) {
				$status_counts[ $status ] = $count;
			}
		}

		?>
			<table class="widefat striped" id="wcsi-export-table">
				<tbody>
					<tr>
						<td width="200"><label for="filename"><?php esc_html_e( 'Export File name', 'wcs-import-export' ); ?>:</label></th>
						<td><input type="text" name="filename" placeholder="export filename" value="<?php echo ! empty( $_POST['filename'] ) ? $_POST['filename'] : 'subscriptions.csv'; ?>" required></td>
					</tr>
					<tr>
						<td style="text-align:top"><?php esc_html_e( 'Subscription Statuses', 'wcs-import-export' ); ?>:</td>
						<td>
							<?php foreach( $statuses as $status => $status_display ) : ?>
								<input type="checkbox" name="status[<?php echo esc_attr( $status ); ?>]" checked><?php echo esc_html( $status_display ); ?>  [<?php echo esc_html( ! empty( $status_counts[ $status ] ) ? $status_counts[ $status ] : 0 ); ?>]<br>
							<?php endforeach; ?>
						</td>
					</tr>
					<tr>
						<td><label for="customer"><?php esc_html_e( 'Export for Customer', 'wcs-import-export' ); ?>:</label></td>
						<td><input type="hidden" class="wc-customer-search" name="customer" data-placeholder="<?php esc_attr_e( 'Search for a customer&hellip;', 'wcs-import-export' ); ?>" data-selected="" value="" data-allow_clear="true" /></td>
					</tr>
					<tr>
						<td><label><?php esc_html_e( 'Payment Method', 'wcs-import-export' ); ?>:</label></td>
						<td>
							<select name="payment">
								<option value="any"><?php esc_html_e( 'Any Payment Method', 'wcs-import-export' ); ?></option>
								<option value="none"><?php esc_html_e( 'None', 'wcs-import-export' ); ?></option>

								<?php foreach ( WC()->payment_gateways->get_available_payment_gateways() as $gateway_id => $gateway ) : ?>
									<option value="<?php esc_attr_e( $gateway_id ); ?>"><?php esc_html_e( $gateway->title ); ?></option>;
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
					<tr>
						<td><label><?php esc_html_e( 'Payment Method Tokens', 'wcs-import-export' ); ?>:</label></td>
						<td><input type="checkbox" name="paymentmeta"><?php esc_html_e( 'Export your customers payment and credit cart tokens to the CSV', 'wcs-import-export' ); ?></td>
					</tr>
					<tr>
						<td><label><?php esc_html_e( 'Offset', 'wcs-import-export' ); ?>:</label></td>
						<td><input type="number" name="offset" min="0" value="0"> <?php esc_html_e( 'Offset export results to export a specific subset of your subscriptions. Defaults to 0.', 'wcs-import-export' ); ?></td>
					</tr>
					<tr>
						<td><label><?php esc_html_e( 'Limit Export', 'wcs-import-export' ); ?>:</label></td>
						<td><input type="number" name="limit" min="-1"> <?php esc_html_e( 'Leave empty or set to "-1" to export all subscriptions in your store.', 'wcs-import-export' ); ?></td>
					</tr>
				</tbody>

		</table>
		<?php esc_html_e( 'When exporting all subscriptions, your site may experience memory exhaustion and therefore you may need to use the limit and offset to separate your export into multiple CSV files.', 'wcs-import-export' ); ?>

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
			'subscription_id'          => __( 'Subscription ID', 'wcs-import-export' ),
			'subscription_status'      => __( 'Subscription Status', 'wcs-import-export' ),
			'customer_id'              => __( 'Customer ID', 'wcs-import-export' ),
			'start_date'               => __( 'Start Date', 'wcs-import-export' ),
			'trial_end_date'           => __( 'Trial End Date', 'wcs-import-export' ),
			'next_payment_date'        => __( 'Next Payment Date', 'wcs-import-export' ),
			'last_payment_date'        => __( 'Last Payment Date', 'wcs-import-export' ),
			'end_date'                 => __( 'End Date', 'wcs-import-export' ),
			'billing_period'           => __( 'Billing Period', 'wcs-import-export' ),
			'billing_interval'         => __( 'Billing Interval', 'wcs-import-export' ),
			'order_shipping'           => __( 'Total Shipping', 'wcs-import-export' ),
			'order_shipping_tax'       => __( 'Total Shipping Tax', 'wcs-import-export' ),
			'fee_total'                => __( 'Total Subscription Fees', 'wcs-import-export' ),
			'fee_tax_total'            => __( 'Total Fees Tax', 'wcs-import-export' ),
			'order_tax'                => __( 'Subscription Total Tax', 'wcs-import-export' ),
			'order_cart_discount'      => __( 'Cart Discount', 'wcs-import-export' ),
			'order_discount'           => __( 'Subscription Discount', 'wcs-import-export' ),
			'order_total'              => __( 'Subscription Total', 'wcs-import-export' ),
			'order_currency'           => __( 'Subscription Currency', 'wcs-import-export' ),
			'payment_method'           => __( 'Payment Method', 'wcs-import-export' ),
			'payment_method_title'     => __( 'Payment Method Title', 'wcs-import-export' ),
			'payment_method_post_meta' => __( 'Payment Method Post Meta', 'wcs-import-export' ),
			'payment_method_user_meta' => __( 'Payment Method User Meta', 'wcs-import-export' ),
			'shipping_method'          => __( 'Shipping Method', 'wcs-import-export' ),
			'billing_first_name'       => __( 'Billing First Name', 'wcs-import-export' ),
			'billing_last_name'        => __( 'Billing Last Name', 'wcs-import-export' ),
			'billing_email'            => __( 'Billing Email', 'wcs-import-export' ),
			'billing_phone'            => __( 'Billing Phone', 'wcs-import-export' ),
			'billing_address_1'        => __( 'Billing Address 1', 'wcs-import-export' ),
			'billing_address_2'        => __( 'Billing Address 2', 'wcs-import-export' ),
			'billing_postcode'         => __( 'Billing Postcode', 'wcs-import-export' ),
			'billing_city'             => __( 'Billing City', 'wcs-import-export' ),
			'billing_state'            => __( 'Billing State', 'wcs-import-export' ),
			'billing_country'          => __( 'Billing Country', 'wcs-import-export' ),
			'billing_company'          => __( 'Billing Company', 'wcs-import-export' ),
			'shipping_first_name'      => __( 'Shipping First Name', 'wcs-import-export' ),
			'shipping_last_name'       => __( 'Shipping Last Name', 'wcs-import-export' ),
			'shipping_address_1'       => __( 'Shipping Address 1', 'wcs-import-export' ),
			'shipping_address_2'       => __( 'Shipping Address 2', 'wcs-import-export' ),
			'shipping_postcode'        => __( 'Shipping Post code', 'wcs-import-export' ),
			'shipping_city'            => __( 'Shipping City', 'wcs-import-export' ),
			'shipping_state'           => __( 'Shipping State', 'wcs-import-export' ),
			'shipping_country'         => __( 'Shipping Country', 'wcs-import-export' ),
			'shipping_company'         => __( 'Shipping Company', 'wcs-import-export' ),
			'customer_note'            => __( 'Customer Note', 'wcs-import-export' ),
			'order_items'              => __( 'Subscription Items', 'wcs-import-export' ),
			'order_notes'              => __( 'Subscription order notes', 'wcs-import-export' ),
			'coupon_items'             => __( 'Coupons', 'wcs-import-export' ),
			'fee_items'                => __( 'Fees', 'wcs-import-export' ),
			'tax_items'                => __( 'Taxes', 'wcs-import-export' ),
			'download_permissions'     => __( 'Download Permissions Granted', 'wcs-import-export' ),
		);
		?>

		<table class="widefat widefat_importer striped" id="wcsi-headers-table" style="display:none;">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Include', 'wcs-import-export' ); ?></th>
					<th><?php esc_html_e( 'Subscription Details', 'wcs-import-export' ); ?></th>
					<th><?php esc_html_e( 'Importer Compatible Header', 'wcs-import-export' ); ?></th>
					<th><?php esc_html_e( 'CSV Column Header', 'wcs-import-export' ); ?></th>
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
	 * @return array
	 */
	private function get_subscriptions_to_export() {

		$args = array(
			'subscriptions_per_page' => isset( $_POST['limit'] ) ? absint( $_POST['limit'] ) : -1,
			'offset'                 => isset( $_POST['offset'] ) ? $_POST['offset'] : 0,
			'product'                => isset( $_POST['product'] ) ? $_POST['product'] : '',
			'subscription_status'    => 'none', // don't default to 'any' status if no statuses were chosen
		);

		if ( ! empty( $_POST['status'] ) ) {
			$statuses = array_keys( $_POST['status'] );

			if ( ! empty( $statuses  ) && is_array( $statuses  ) ) {
				$args['subscription_status'] = implode(',', $statuses );
			}
		}

		if ( ! empty( $_POST['customer'] ) && is_numeric( $_POST['customer'] ) ) {
			$args['customer_id'] = $_POST['customer'];
		}

		if ( ! empty( $_POST['payment'] ) ) {
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

		WC()->payment_gateways();

		$subscriptions = $this->get_subscriptions_to_export( $filters );

		if ( ! empty( $subscriptions ) ) {
			if ( empty( $_POST['paymentmeta'] ) ) {
				unset( $headers['payment_method_post_meta'] );
				unset( $headers['payment_method_user_meta'] );
			}

			WCS_Export_Writer::write_headers( $headers );

			foreach ( $subscriptions as $subscription ) {
				WCS_Export_Writer::write_subscriptions_csv_row( $subscription );
			}

			WCS_Export_Writer::process_export( $_POST['filename'] );
		} else {
			$this->error_message = __( 'No subscriptions to export given the filters you have selected.', 'wcs-import-export' );
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
					$this->error_message = __( 'No csv headers were chosen, please select at least one CSV header to complete the Subscriptions Exporter.', 'wcs-import-export' );
				}
			}
		}
	}

	/**
	 * Filter screen ids to add the export page so that WooCommerce will load all their admin scripts
	 *
	 * @since 1.0
	 * @param array $screen_ids
	 */
	public function register_export_screen_id( $screen_ids ) {
		if ( isset( $_GET['page'] ) && 'export_subscriptions' == $_GET['page'] ) {
			$screen_ids[] = 'woocommerce_page_export_subscriptions';
		}

		return $screen_ids;
	}
}
