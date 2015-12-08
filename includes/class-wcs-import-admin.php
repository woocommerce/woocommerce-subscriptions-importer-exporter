<?php
/**
 * WooCommerce Subscriptions Import Admin class
 *
 * @since 1.0
 */
class WCS_Import_Admin {

	public $import_results = array();

	public $upload_error   = '';

	public function __construct() {

		$this->admin_url        = admin_url( 'admin.php?page=import_subscription' );
		$this->rows_per_request = ( defined( 'WCS_IMPORT_ROWS_PER_REQUEST' ) ) ? WCS_IMPORT_ROWS_PER_REQUEST : 20;

		add_action( 'admin_init', array( &$this, 'post_request_handler' ) );
		add_action( 'admin_init', array( &$this, 'add_import_tool' ) );

		add_action( 'admin_menu', array( &$this, 'add_sub_menu' ), 10 );

		add_action( 'admin_enqueue_scripts', array( &$this, 'enqueue_scripts' ) );

		add_action( 'wp_ajax_wcs_import_request', array( &$this, 'ajax_request_handler' ) );
	}

	/**
	 * Adds the Subscriptions CSV Importer into the Tools
	 *
	 * @since 1.0
	 */
	public function add_import_tool() {
		register_importer( 'woocommerce_subscription_csv', 'WooCommerce Subscriptions (CSV)', __( 'Import <strong>subscriptions</strong> to your WooCommerce store via a CSV file.', 'wcs-importer' ), array( &$this, 'admin_page' ) );
	}

	/**
	 * Add menu item under Woocommerce > Subscription CSV Importer
	 *
	 * @since 1.0
	 */
	public function add_sub_menu() {
		add_submenu_page( 'woocommerce', __( 'Subscription Importer', 'wcs-importer' ),  __( 'Subscription Importer', 'wcs-importer' ), 'manage_options', 'import_subscription', array( &$this, 'admin_page' ) );
	}

	/**
	 * Load scripts
	 *
	 * @since 1.0
	 */
	public function enqueue_scripts() {

		if ( isset( $_GET['page'] ) && 'import_subscription' == $_GET['page'] ) {

			wp_enqueue_style( 'wcs-importer-admin', plugin_dir_url( WCS_Importer::$plugin_file ) . '/assets/css/wcs-importer.css' );

			if ( isset( $_GET['step'] ) && 3 == absint( $_GET['step'] )  ) {

				wp_enqueue_script( 'wcs-importer-admin', plugin_dir_url( WCS_Importer::$plugin_file ) . '/assets/js/wcs-importer.js' );

				$file_id = absint( $_GET['file_id'] );
				$file    = get_attached_file( $_GET['file_id'] );
				$enc     = mb_detect_encoding( $file, 'UTF-8, ISO-8859-1', true );

				if ( $enc ) {
					setlocale( LC_ALL, 'en_US.' . $enc );
				}

				@ini_set( 'auto_detect_line_endings', true );

				$file_positions = $row_start = array();

				$count        = 0;
				$total        = 0;
				$previous_pos = 0;
				$position     = 0;
				$row_start[]  = 1;

				if ( ( $handle = fopen( $file, "r" ) ) !== FALSE ) {
					$row       = $raw_headers = array();

					$header = fgetcsv( $handle, 0 );
					while ( ( $postmeta = fgetcsv( $handle, 0 ) ) !== FALSE ) {
						$count++;

						foreach ( $header as $key => $heading ) {

							if ( ! $heading ) {
								continue;
							}

							$s_heading         = strtolower( $heading );
							$row[ $s_heading ] = ( isset( $postmeta[ $key ] ) ) ? wcsi_format_data( $postmeta[$key], $enc ) : '';
						}

						if ( $count >= $this->rows_per_request ) {
							$previous_pos = $position;
							$position     = ftell( $handle );
							$row_start[]  = end( $row_start ) + $count;

							reset( $row_start );

							$count = 0;
							$total++;

							$file_positions[] = $previous_pos;
							$file_positions[] = $position;
						}
					}

					if ( $count > 0 ) {
						$total++;
						$file_positions[] = $position;
						$file_positions[] = ftell( $handle );
					}

					fclose( $handle );
				}

				$script_data = array(
					'success' 				=> esc_html__( 'success', 'wcs-importer' ),
					'failed' 				=> esc_html__( 'failed', 'wcs-importer' ),
					'error_string'			=> sprintf( esc_html__( "Row #%s from CSV %sfailed to import%s with error/s: %s", 'wcs-importer' ), '{row_number}', '<strong>', '</strong>', '{error_messages}' ),
					'finished_importing' 	=> esc_html__( 'Finished Importing', 'wcs-importer' ),
					'edit_order' 			=> esc_html__( 'Edit Order', 'wcs-importer' ),
					'warning'				=> esc_html__( 'Warning', 'wcs-importer' ),
					'warnings'				=> esc_html__( 'Warnings', 'wcs-importer' ),
					'located_at'			=> esc_html__( 'Located at rows', 'wcs-importer' ),

					// Data for procesing the file
					'file_id'          => absint( $_GET['file_id'] ),
					'file_positions'   => $file_positions,
					'start_row_num'    => $row_start,
					'ajax_url'         => admin_url( 'admin-ajax.php' ),
					'rows_per_request' => $this->rows_per_request,
					'test_mode'        => ( 'yes' == $_GET['test_mode'] ) ? "true" : "false",
					'email_customer'   => ( 'yes' == $_GET['email_customer'] ) ? "true" : "false",
					'total'            => $total,
				);

				wp_localize_script( 'wcs-importer-admin', 'wcsi_data', $script_data );
			}
		}
	}

	/**
	 * Displays header followed by the current pages content
	 *
	 * @since 1.0
	 */
	public function admin_page() {

		echo '<div class="wrap">';
		echo '<h2>' . __( 'Subscription CSV Importer', 'wcs-importer' ) . '</h2>';

		if ( ! isset( $_GET['step'] ) || isset( $_GET['cancelled'] ) ) : ?>

			<div id="message" class="updated woocommerce-message wc-connect">
			<?php if ( isset( $_GET['cancelled'] ) ) : ?>
				<div id="message" class="updated error">
					<p><?php esc_html_e( 'Import cancelled.', 'wcs-importer' ); ?></p>
				</div>
			<?php endif; ?>
			<?php if ( ! isset( $_GET['step'] ) ) : ?>
				<div class="squeezer">
					<h4><?php printf( esc_html__( '%sBefore you begin%s, please prepare your CSV file.', 'wcs-importer' ), '<strong>', '</strong>' ); ?></h4>
					<p class="submit">
						<a href="http://docs.woothemes.com/document/subscriptions-importer/" class="button-primary"><?php _e( 'Documentation', 'wcs-importer' ); ?></a>
						<a href="<?php echo esc_url( plugins_url( 'wcs-import-sample.csv', WCS_Importer::$plugin_file ) ); ?>" class="button wcs-importer-download"><?php esc_html_e( 'Download Example CSV', 'wcs-importer' ); ?></a>
					</p>
				</div>
			<?php endif; ?>
		</div>
		<?php endif;

		$page = ( isset( $_GET['step'] ) ) ? $_GET['step'] : 1;

		switch ( $page ) {
			case 1 : //Step: Upload File
				$this->upload_page();
				break;
			case 2 : // check mapping
				$this->mapping_page();
				break;
			case 3 :
				$this->import_page();
				break;
			default : //default to home page
				$this->upload_page();
				break;
		}

		echo '</div>';
	}

	/**
	 * Initial plugin page. Prompts the admin to upload the CSV file containing subscription details.
	 *
	 * @since 1.0
	 */
	private function upload_page() {

		$upload_dir = wp_upload_dir();

		// Set defaults for admin flags
		$test_mode      = ( isset( $_POST['test_mode'] ) ) ? $_POST['test_mode'] : 'yes';
		$email_customer = ( isset( $_POST['email_customer'] ) ) ? $_POST['email_customer'] : 'no';

		if ( ! empty( $this->upload_error ) ) : ?>
			<div id="message" class="error">
				<p><?php printf( esc_html__( 'Error uploading file: %s', 'wcs-importer' ), $this->upload_error ); ?></p>
			</div>
		<?php endif; ?>

		<h3><?php esc_html_e( 'Step 1: Upload CSV File', 'wcs-importer' ); ?></h3>
		<?php if ( ! empty( $upload_dir['error'] ) ) : ?>
			<div class="error"><p><?php _e( 'Before you can upload your import file, you will need to fix the following error:' ); ?></p>
			<p><strong><?php echo $upload_dir['error']; ?></strong></p></div>
		<?php else : ?>
			<p><?php esc_html_e( 'Upload a CSV file containing details about your subscriptions to bring across to your store with WooCommerce.', 'wcs-importer' ); ?></p>
			<p><?php esc_html_e( 'Choose a CSV (.csv) file to upload, then click Upload file and import.', 'wcs-importer' ); ?></p>

			<form enctype="multipart/form-data" id="import-upload-form" method="post" action="<?php echo esc_attr( $this->admin_url ); ?>">
				<?php wp_nonce_field( 'import-upload' ); ?>
				<table class="form-table">
					<tbody>
						<tr>
							<th>
								<label for="upload"><?php esc_html_e( 'Choose a file:' ); ?></label>
							</th>
							<td>
								<input type="file" id="upload" name="import" size="25" />
								<input type="hidden" name="action" value="upload_file" />
								<small><?php printf( esc_html__( 'Maximum size: %s' ), size_format( apply_filters( 'import_upload_size_limit', wp_max_upload_size() ) ) ); ?></small>
							</td>
						</tr>
						<tr>
							<th><?php esc_html_e( 'Run in test mode', 'wcs-importer' ); ?>:</th>
							<td>
								<input type="checkbox" name="test_mode" value="yes" <?php checked( $test_mode, 'yes' ); ?> />
								<em><?php esc_html_e( 'Check your CSV file for errors and warnings without creating subscriptions, users or orders.', 'wcs-importer' ); ?></em>
							</td>
						</tr>
						<tr>
							<th><?php esc_html_e( 'Email passwords?', 'wcs-importer' ); ?></th>
							<td>
								<input type="checkbox" name="email_customer" value="yes" <?php checked( $email_customer, 'yes' ); ?> />
								<em><?php esc_html_e( 'If importing new users, you can email customers their account details.', 'wcs-importer' ); ?></em>
							</td>
						</tr>
					</tbody>
				</table>
				<p class="submit">
					<input type="submit" class="button" value="<?php esc_attr_e( 'Upload file and import', 'wcs-importer' ); ?>" />
				</p>
			</form>
		<?php endif;
	}

	/**
	 * Step 2: Once uploaded file is recognised, the admin will be required to map CSV columns to the required fields.
	 *
	 * @since 1.0
	 */
	private function mapping_page() {

		$file_id = absint( $_GET['file_id'] );
		$file    = get_attached_file( $file_id );

		if ( $file ) {
			$enc = mb_detect_encoding( $file, 'UTF-8, ISO-8859-1', true );

			if ( $enc ) {
				setlocale( LC_ALL, 'en_US.' . $enc );
			}

			@ini_set( 'auto_detect_line_endings', true );

			if ( ( $handle = fopen( $file, "r" ) ) !== FALSE ) {

				$row            = array();
				$column_headers = fgetcsv( $handle, 0 );

				while ( ( $postmeta = fgetcsv( $handle, 0 ) ) !== false ) {

					foreach ( $column_headers as $key => $column_header ) {

						if ( ! $column_header ) continue;
						$row[ $column_header ] = ( isset( $postmeta[ $key ] ) ) ? wcsi_format_data( $postmeta[ $key ], $enc ) : '';
					}

					break;
				}
				fclose( $handle );
			}
		}

		$url_params = array(
			'step'           => '3',
			'file_id'        => $file_id,
			'test_mode'      => $_GET['test_mode'],
			'email_customer' => $_GET['email_customer'],
		);

		$action      = add_query_arg( $url_params, $this->admin_url );
		$button_text = ( 'yes' == $_GET['test_mode'] ) ? __( 'Test CSV', 'wcs-importer' ) : __( 'Run Import', 'wcs-importer' );
		$row_number  = 1;

		$subscription_fields = array( 'customer_id', 'product_id', 'status', 'start_date', 'next_payment_date', 'end_date', 'trial_end_date', 'last_payment_date', 'billing_interval', 'billing_period' );
		?>

		<h3><?php esc_html_e( 'Step 2: Map Fields to Column Names', 'wcs-importer' ); ?></h3>
		<form method="post" action="<?php echo esc_attr( $action ); ?>">
			<?php wp_nonce_field( 'import-upload' ); ?>
			<input type="hidden" name="action" value="field_mapping" />
			<table class="widefat widefat_importer">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Map to', 'wcs-importer' ); ?></th>
						<th><?php esc_html_e( 'Column Header', 'wcs-importer' ); ?></th>
						<th><?php esc_html_e( 'Example Column Value', 'wcs-importer' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach( $row as $header => $sample ) : ?>
					<tr <?php echo ( ++$row_number % 2 ) ? '' : 'class="alternate"'; ?>>
						<td>
							<select name="mapto[<?php echo $header; ?>]">
								<option value="0"><?php esc_html_e( 'Do not import', 'wcs-importer' ); ?></option>
								<optgroup label="<?php esc_attr_e( 'Custom', 'wcs-importer' ); ?>">
									<option value='payment_method_post_meta'>payment_method_post_meta</option>
									<option value='payment_method_user_meta'>payment_method_user_meta</option>
									<option value="custom_user_post_meta">custom_user_post_meta</option>
									<option value="custom_user_meta">custom_user_meta</option>
									<option value="custom_post_meta">custom_post_meta</option>
								</optgroup>
								<optgroup label="<?php esc_attr_e( 'Subscription Details', 'wcs-importer' ); ?>">
									<?php foreach ( $subscription_fields as $option ) : ?>
										<option value="<?php echo $option; ?>" <?php selected( $header, $option ); ?>><?php echo $option; ?></option>
									<?php endforeach; ?>
								</optgroup>
								<optgroup label="<?php esc_attr_e( 'Subscription Item Meta', 'wcs-importer' ); ?>">
									<?php foreach ( array_merge( WCS_Import_Parser::$order_meta_fields, WCS_Import_Parser::$order_item_meta_fields ) as $option ) : ?>
										<option value="<?php echo esc_attr( $option ); ?>" <?php selected( $header, $option ); ?>><?php echo $option; ?></option>
									<?php endforeach; ?>
									<option value="payment_method_title" <?php selected( $header, 'payment_method_title' ); ?>>payment_method_title</option>
									<option value="shipping_method_title" <?php selected( $header, 'shipping_method_title' ); ?>>shipping_method_title</option>
									<option value="quantity" <?php selected( $header, 'quantity' ); ?>>quantity</option>
									<option value="download_permission_granted" <?php selected( $header, 'download_permission_granted' ); ?>>download_permission_granted</option>
								</optgroup>
								<optgroup label="<?php esc_attr_e( 'Payment Method Meta', 'wcs-importer' ); ?>">
									<option value="_stripe_customer_id" <?php selected( $header, 'stripe_customer_id' ); ?>>stripe_customer_id</option>
									<option value="_paypal_subscription_id" <?php selected( $header, 'paypal_subscription_id' ); ?>>_paypal_subscription_id</option>
									<option value="_wc_authorize_net_cim_credit_card_payment_token" <?php selected( $header, 'wc_authorize_net_cim_payment_profile_id' ); ?>>wc_authorize_net_cim_payment_profile_id</option>
									<option value="_wc_authorize_net_cim_credit_card_customer_id" <?php selected( $header, 'wc_authorize_net_cim_customer_profile_id' ); ?>>wc_authorize_net_cim_customer_profile_id</option>
								</optgroup>
							</select>
						</td>
						<td width="25%"><?php echo esc_html( $header ); ?></td>
						<td><code><?php echo ( ! empty( $sample ) ) ? esc_html( $sample ) : '-'; ?></code></td>
					</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<p class="submit">
				<input type="submit" class="button" value="<?php echo esc_attr( $button_text ); ?>" />
			</p>
		</form>
		<?php
	}

	/**
	 * Show test page if $_POST['test-mode'] is set and display a list of critical errors and warnings
	 *
	 * @since 1.0
	 */
	private function import_page() {

		if ( 'yes' == $_GET['test_mode'] ) {

			$action = add_query_arg( array(
				'step'            => '3',
				'file_id'         => $_GET['file_id'],
				'test_mode'       => 'no',
				'email_customer'  => $_GET['email_customer'],
				'add_memberships' => $_GET['add_memberships'],
			),$this->admin_url );

			include( plugin_dir_path( WCS_Importer::$plugin_file ) . 'templates/test-mode.php' );
		} else {
			include( plugin_dir_path( WCS_Importer::$plugin_file ) . 'templates/import-results.php' );
		}
	}

	/**
	 * Checks the mapping provides enough information to continue importing subscriptions
	 *
	 * @since 1.0
	 */
	public function save_mapping() {

		$mapped_fields = array(
			'custom_user_meta'            => array(),
			'custom_post_meta'            => array(),
			'custom_user_post_meta'       => array(),
			'payment_method_post_meta'    => array(),
			'payment_method_user_meta'    => array(),
			'customer_id'                 => '',
			'product_id'                  => '',
			'status'                      => '',
			'start_date'                  => '',
			'trial_end_date'              => '',
			'next_payment_date'           => '',
			'last_payment_date'           => '',
			'end_date'                    => '',
			'customer_email'              => '',
			'customer_username'           => '',
			'customer_password'           => '',
			'billing_first_name'          => '',
			'billing_last_name'           => '',
			'billing_address_1'           => '',
			'billing_address_2'           => '',
			'billing_city'                => '',
			'billing_state'               => '',
			'billing_postcode'            => '',
			'billing_country'             => '',
			'billing_email'               => '',
			'billing_phone'               => '',
			'billing_company'             => '',
			'shipping_first_name'         => '',
			'shipping_last_name'          => '',
			'shipping_company'            => '',
			'shipping_address_1'          => '',
			'shipping_address_2'          => '',
			'shipping_city'               => '',
			'shipping_state'              => '',
			'shipping_postcode'           => '',
			'shipping_country'            => '',
			'shipping_method'             => '',
			'shipping_method_title'       => '',
			'line_total'                  => '',
			'line_tax'                    => '',
			'line_subtotal'               => '',
			'line_subtotal_tax'           => '',
			'quantity'                    => '',
			'order_discount'              => '',
			'cart_discount'               => '',
			'cart_discount_tax'           => '',
			'order_shipping_tax'          => '',
			'order_shipping'              => '',
			'order_tax'                   => '',
			'order_total'                 => '',
			'download_permission_granted' => '',
			'payment_method'              => '',
			'payment_method_title'        => '',
			'billing_period'              => '',
			'billing_interval'            => '',
		);

		$mapping_rules = $_POST['mapto'];

		foreach ( $mapped_fields as $key => $value ) {
			if ( ! is_array( $value ) ) {
				$m_key = array_search( $key, $mapping_rules );

				if ( $m_key ) {
					$mapped_fields[ $key ] = $m_key;
				}
			}
		}

		foreach ( $mapping_rules as $key => $value ) {
			if ( ! empty( $value ) && is_array( $mapped_fields[ $value ] ) ) {
				array_push( $mapped_fields[ $value ], $key );
			}
		}

		update_post_meta( $_GET['file_id'], '_mapped_rules', $mapped_fields );
	}

	/**
	 * Displays header followed by the current pages content
	 *
	 * @since 1.0
	 */
	public function post_request_handler() {

		if ( isset( $_GET['page'] ) && 'import_subscription' == $_GET['page'] && isset( $_POST['action'] ) ) {

			check_admin_referer( 'import-upload' );

			$next_step_url_params = array(
				'file_id'        => isset( $_GET['file_id'] ) ? $_GET['file_id'] : 0,
				'test_mode'      => isset( $_REQUEST['test_mode'] ) ? $_REQUEST['test_mode'] : 'no',
				'email_customer' => isset( $_REQUEST['email_customer'] ) ? $_REQUEST['email_customer'] : 'no',
			);

			if ( 'upload_file' == $_POST['action'] ) {

				$file = wp_import_handle_upload();

				if ( isset( $file['error'] ) ) {

					$this->upload_error = $file['error'];
				} else {

					$next_step_url_params['step']    = 2;
					$next_step_url_params['file_id'] = $file['id'];

					wp_safe_redirect( add_query_arg( $next_step_url_params, $this->admin_url ) );
					exit;
				}

			} elseif ( 'field_mapping' == $_POST['action'] ) {

				$this->save_mapping();
				$next_step_url_params['step'] = 3;

				wp_safe_redirect( add_query_arg( $next_step_url_params, $this->admin_url ) );
				exit;
			}
		}
	}

	/**
	 * Process the AJAX request and import the subscription with the information gathered.
	 *
	 * @since 1.0
	 */
	public function ajax_request_handler() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( "Cheatin' huh?");
		}

		@set_time_limit(0);

		// Requests to admin-ajax.php use the front-end memory limit, we want to use the admin (i.e. max) memory limit
		@ini_set( 'memory_limit', apply_filters( 'admin_memory_limit', WP_MAX_MEMORY_LIMIT ) );

		if ( isset( $_POST['file_id'] ) && isset( $_POST['row_num'] ) ) {
			$results = WCS_Import_Parser::import_data( array(
					'file_path'       => get_attached_file( absint( $_POST['file_id'] ) ),
					'mapped_fields'   => get_post_meta( absint( $_POST['file_id'] ), '_mapped_rules', true ),
					'file_start'      => ( isset( $_POST['start'] ) ) ? absint( $_POST['start'] ) : 0,
					'file_end'        => ( isset( $_POST['end'] ) ) ? absint( $_POST['end'] ) : 0,
					'starting_row'    => absint( $_POST['row_num'] ),
					'test_mode'       => isset( $_POST['test_mode'] ) ? $_POST['test_mode'] : false,
					'email_customer'  => isset( $_POST['email_customer'] ) ? $_POST['email_customer'] : false,
					'add_memberships' => isset( $_POST['add_memberships'] ) ? $_POST['add_memberships'] : false,
				)
			);

			header( 'Content-Type: application/json; charset=utf-8' );
			echo json_encode( $results );
		}

		exit;
	}
}
