<?php
global $file;

class WCS_Admin_Importer {
	var $id;
	var $delimiter;
	var $import_results = array();
	var $mapping;
	var $file_url;
	var $test_import = false;

	public function __construct() {

		$this->admin_url        = admin_url( 'admin.php?page=import_subscription' );
		$this->rows_per_request = ( defined( 'WCS_IMPORT_ROWS_PER_REQUEST' ) ) ? WCS_IMPORT_ROWS_PER_REQUEST : 20;

		add_action( 'wp_ajax_wcs_import_request', array( &$this, 'ajax_request_handler' ) );
	}

	/**
	 * Displays header followed by the current pages content
	 *
	 * @since 1.0
	 */
	public function display_content() {
		global $file;

		$page = ( isset($_GET['step'] ) ) ? $_GET['step'] : 1;
		switch( $page ) {
		case 1 : //Step: Upload File
			$this->upload_page();
			break;
		case 2 : // Handle upload and map fields
			check_admin_referer( 'import-upload' );
			if( isset( $_POST['action'] ) ) {
				$this->handle_file();
			}
			break;
		case 3 : // check mapping
			$this->check_mapping();
			break;
		case 4 :
			$this->wcs_import_results();
			$this->ajax_setup();
			break;
		default : //default to home page
			$this->upload_page();
			break;
		}
	}

	/**
	 * Initial plugin page. Prompts the admin to upload the CSV file containing subscription details.
	 *
	 * @since 1.0
	 */
	static function upload_page() { 
		echo '<h3>' . __( 'Step 1: Upload CSV File', 'wcs-importer' ) . '</h3>';
		$action = 'admin.php?page=import_subscription&amp;step=2&amp;';
		$bytes = apply_filters( 'import_upload_size_limit', wp_max_upload_size() );
		$size = size_format( $bytes );
		$upload_dir = wp_upload_dir();
		if ( ! empty( $upload_dir['error'] ) ) : ?>
			<div class="error"><p><?php _e( 'Before you can upload your import file, you will need to fix the following error:' ); ?></p>
			<p><strong><?php echo $upload_dir['error']; ?></strong></p></div><?php
		else :
			?>
			<p><?php _e( 'Upload a CSV file containing details about your subscriptions to bring across to your store with WooCommerce.', 'wcs-importer' ); ?></p>
			<p><?php _e( 'Choose a CSV (.csv) file to upload, then click Upload file and import.', 'wcs-importer' ); ?></p>
			<form enctype="multipart/form-data" id="import-upload-form" method="post" action="<?php echo esc_attr(wp_nonce_url($action, 'import-upload')); ?>">
				<table class="form-table">
					<tbody>
						<tr>
							<th>
								<label for="upload"><?php _e( 'Choose a file from your computer:' ); ?></label>
							</th>
							<td>
								<input type="file" id="upload" name="import" size="25" />
								<input type="hidden" name="action" value="save" />
								<input type="hidden" name="max_file_size" value="<?php echo $bytes; ?>" />
								<small><?php printf( __('Maximum size: %s' ), $size ); ?></small>
							</td>
						</tr>
						<tr>
							<th>
								<label for="file_url"><?php _e( 'OR enter path to file:', 'wcs-importer' ); ?></label>
							</th>
							<td>
								<?php echo ' ' . ABSPATH . ' '; ?><input type="text" id="file_url" name="file_url" size="50" />
							</td>
						</tr>
						<tr>
							<th><label><?php _e( 'Delimiter', 'wcs-importer' ); ?></label><br/></th>
							<td><input type="text" name="delimiter" placeholder="," size="2" /></td>
						</tr>
					</tbody>
				</table>
				<p class="submit">
					<input type="submit" class="button" value="<?php esc_attr_e( 'Upload file and import' ); ?>" />
				</p>
			</form>
			<?php
		endif;
	}

	/**
	 *
	 * @since 1.0
	 */
	function handle_file() {
		global $file;
		if ( empty( $_POST['file_url'] ) ) {
			$file = wp_import_handle_upload();
			if( isset( $file['error'] ) ) {
				$this->importer_error();
				exit;
			}
			$this->id = $file['id'];
			$file = get_attached_file( $this->id );
		} else {
			if ( file_exists( ABSPATH . $_POST['file_url'] ) ) {
				$this->file_url = esc_attr( $_POST['file_url'] );
				$file = ABSPATH . $this->file_url;
			} else {
				$this->importer_error();
				exit;
			}
		}
		if( $file ) {
			$this->delimiter = ( ! empty( $_POST['delimiter'] ) ) ? stripslashes( trim( $_POST['delimiter'] ) ) : ',';

			$enc = mb_detect_encoding( $file, 'UTF-8, ISO-8859-1', true );
			if ( $enc ) setlocale( LC_ALL, 'en_US.' . $enc );
			@ini_set( 'auto_detect_line_endings', true );

			// Get headers
			if ( ( $handle = fopen( $file, "r" ) ) !== FALSE ) {
				$row = $raw_headers = array();

				$header = fgetcsv( $handle, 0, $this->delimiter );
				while ( ( $postmeta = fgetcsv( $handle, 0, $this->delimiter ) ) !== false ) {
					foreach ( $header as $key => $heading ) {
						if ( ! $heading ) continue;
						$s_heading = strtolower( $heading );
						$row[$s_heading] = ( isset( $postmeta[$key] ) ) ? $this->format_data_from_csv( $postmeta[$key], $enc ) : '';
						$raw_headers[ $s_heading ] = $heading;
					}
					break;
				}
				fclose( $handle );
			}
			$this->map_fields( $row );
		}
	}

	/**
	 *
	 * @since 1.0
	 */
	function format_data_from_csv( $data, $enc ) {
		return ( $enc == 'UTF-8' ) ? $data : utf8_encode( $data );
	}

	/**
	 * Step 2: Once uploaded file is recognised, the admin will be required to map CSV columns to the required fields.
	 *
	 * @since 1.0
	 */
	function map_fields( $row ) {
		$action = 'admin.php?page=import_subscription&amp;step=3&amp;';
		$row_number = 1;
		?>
		<h3><?php _e( 'Step 2: Map Fields to Column Names', 'wcs-importer' ); ?></h3>
		<form method="post" action="<?php echo esc_attr(wp_nonce_url($action, 'import-upload')); ?>">
			<input type="hidden" name="file_id" value="<?php echo $this->id; ?>">
			<input type="hidden" name="file_url" value="<?php echo $this->file_url; ?>">
			<input type="hidden" name="delimiter" value="<?php echo $this->delimiter; ?>">
			<table class="widefat widefat_importer">
				<thead>
					<tr>
						<th><?php _e( 'Map to', 'wcs-importer' ); ?></th>
						<th><?php _e( 'Column Header', 'wcs-importer' ); ?></th>
						<th><?php _e( 'Example Column Value', 'wcs-importer' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach( $row as $header => $sample ) : ?>
					<tr <?php echo ( ++$row_number % 2 ) ? '' : 'class="alternate"'; ?>>
						<td> <!-- Available mapping options -->
							<select name="mapto[<?php echo $header; ?>]">
								<option value="0"><?php _e( 'Do not import', 'wcs-importer' ); ?></option>
								<optgroup label="<?php _e( 'Customer Fields', 'wcs-importer'); ?>">
									<option value="customer_id" <?php selected( $header, 'customer_id' ); ?>>customer_id</option>
									<option value="customer_email" <?php selected( $header, 'customer_email' ); ?>>customer_email</option>
									<option value="customer_username" <?php selected( $header, 'customer_username' ); ?>>customer_username</option>
									<option value="customer_password" <?php selected( $header, 'customer_password' ); ?>>customer_password</option>
									<option value="billing_first_name" <?php selected( $header, 'billing_first_name' ); ?>>billing_first_name</option>
									<option value="billing_last_name" <?php selected( $header, 'billing_last_name' ); ?>>billing_last_name</option>
									<option value="billing_address_1" <?php selected( $header, 'billing_address_1' ); ?>>billing_address_1</option>
									<option value="billing_address_2" <?php selected( $header, 'billing_address_2' ); ?>>billing_address_2</option>
									<option value="billing_city" <?php selected( $header, 'billing_city' ); ?>>billing_city</option>
									<option value="billing_state" <?php selected( $header, 'billing_state' ); ?>>billing_state</option>
									<option value="billing_postcode" <?php selected( $header, 'billing_postcode' ); ?>>billing_postcode</option>
									<option value="billing_country" <?php selected( $header, 'billing_country' ); ?>>billing_country</option>
									<option value="billing_email" <?php selected( $header, 'billing_email' ); ?>>billing_email</option>
									<option value="billing_phone" <?php selected( $header, 'billing_phone' ); ?>>billing_phone</option>
									<option value="billing_company" <?php selected( $header, 'billing_company' ); ?>>billing_company</option>
									<option value="shipping_address_1" <?php selected( $header, 'shipping_address_1' ); ?>>shipping_address_1</option>
									<option value="shipping_address_2" <?php selected( $header, 'shipping_address_2' ); ?>>shipping_address_2</option>
									<option value="shipping_city" <?php selected( $header, 'shipping_city' ); ?>>shipping_city</option>
									<option value="shipping_state" <?php selected( $header, 'shipping_state' ); ?>>shipping_state</option>
									<option value="shipping_postcode" <?php selected( $header, 'shipping_postcode' ); ?>>shipping_postcode</option>
									<option value="shipping_country" <?php selected( $header, 'shipping_country' ); ?>>shipping_country</option>
								</optgroup>
								<optgroup label="<?php _e( 'Order Fields', 'wcs-importer' ); ?>">
									<option value="recurring_line_total" <?php selected( $header, 'recurring_line_total' ); ?>>recurring_line_total</option>
									<option value="recurring_line_tax" <?php selected( $header, 'recurring_line_tax' ); ?>>recurring_line_tax</option>
									<option value="recurring_line_subtotal" <?php selected( $header, 'recurring_line_subtotal' ); ?>>recurring_line_subtotal</option>
									<option value="recurring_line_subtotal_tax" <?php selected( $header, 'recurring_line_subtotal_tax' ); ?>>recurring_line_subtotal_tax</option>
									<option value="line_total" <?php selected( $header, 'line_total' ); ?>>line_total</option>
									<option value="line_tax" <?php selected( $header, 'line_tax' ); ?>>line_tax</option>
									<option value="line_subtotal" <?php selected( $header, 'line_subtotal' ); ?>>line_subtotal</option>
									<option value="line_subtotal_tax" <?php selected( $header, 'line_subtotal_tax' ); ?>>line_subtotal_tax</option>
									<option value="order_discount" <?php selected( $header, 'order_discount' ); ?>>order_discount</option>
									<option value="cart_discount" <?php selected( $header, 'cart_discount' ); ?>>cart_discount</option>
									<option value="order_shipping_tax" <?php selected( $header, 'order_shipping_tax' ); ?>>order_shipping_tax</option>
									<option value="order_shipping" <?php selected( $header, 'order_shipping' ); ?>>order_shipping</option>
									<option value="order_tax" <?php selected( $header, 'order_tax' ); ?>>order_tax</option>
									<option value="order_total" <?php selected( $header, 'order_total' ); ?>>order_total</option>
									<option value="payment_method" <?php selected( $header, 'payment_method' ); ?>>payment_method</option>
									<option value="payment_method_title" <?php selected( $header, 'payment_method_title' ); ?>>payment_method_title</option>
									<option value="shipping_method" <?php selected( $header, 'shipping_method' ); ?>>shipping_method</option>
									<option value="shipping_method_title" <?php selected( $header, 'shipping_method_title' ); ?>>shipping_method_title</option>
									<option value="stripe_customer_id" <?php selected( $header, 'stripe_customer_id' ); ?>>stripe_customer_id</option>
									<option value="paypal_subscriber_id" <?php selected( $header, 'paypal_subscriber_id' ); ?>>paypal_subscriber_id</option>
									<option value="download_permission_granted" <?php selected( $header, 'download_permission_granted' ); ?>>download_permission_granted</option>
								</optgroup>
								<optgroup label="<?php _e( 'Subscription Status', 'wcs-importer' ); ?>">
									<option value="subscription_status" <?php selected( $header, 'subscription_status' ); ?>>subscription_status</option>
									<option value="subscription_start_date" <?php selected( $header, 'subscription_start_date' ); ?>>subscription_start_date</option>
									<option value="subscription_expiry_date" <?php selected( $header, 'subscription_expiry_date' ); ?>>subscription_expiry_date</option>
									<option value="subscription_end_date" <?php selected( $header, 'subscription_end_date' ); ?>>subscription_end_date</option>
									<option value="product_id" <?php selected( $header, 'product_id' ); ?>>product_id</option>
								</optgroup>
							</select>
						</td>
						<td width="25%"><?php echo $header; ?></td> <!-- Column deader from csv file -->
						<td><code><?php echo ( ! empty( $sample ) ) ? esc_html( $sample ) : '-'; ?></code></td>
					</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<p class="submit">
				<input type="submit" class="button" value="<?php esc_attr_e( 'Submit', 'wcs-importer' ); ?>" />
			</p>
		</form>
		<?php
	}

	/**
	 * Checks the mapping provides enough information to continue importing subscriptions
	 *
	 * @since 1.0
	 */
	function check_mapping() {
		// Possible mapping options
		$this->mapping = array(
			'product_id'				  => '',
			'customer_id' 				  => '',
			'customer_email' 			  => '',
			'customer_username' 		  => '',
			'customer_password'			  => '',
			'billing_first_name' 		  => '',
			'billing_last_name' 		  => '',
			'billing_address_1' 		  => '',
			'billing_address_2' 		  => '',
			'billing_city' 				  => '',
			'billing_state' 			  => '',
			'billing_postcode' 			  => '',
			'billing_country' 			  => '',
			'billing_email' 			  => '',
			'billing_phone' 			  => '',
			'billing_company'			  => '',
			'shipping_first_name' 		  => '',
			'shipping_last_name' 		  => '',
			'shipping_company' 			  => '',
			'shipping_address_1' 		  => '',
			'shipping_address_2' 		  => '',
			'shipping_city' 			  => '',
			'shipping_state' 			  => '',
			'shipping_postcode' 		  => '',
			'shipping_country' 			  => '',
			'subscription_status'		  => '',
			'subscription_start_date' 	  => '',
			'subscription_expiry_date'	  => '',
			'subscription_end_date'		  => '',
			'payment_method' 			  => '',
			'shipping_method' 			  => '',
			'shipping_method_title'		  => '',
			'recurring_line_total' 		  => '',
			'recurring_line_tax' 		  => '',
			'recurring_line_subtotal' 	  => '',
			'recurring_line_subtotal_tax' => '',
			'line_total' 				  => '',
			'line_tax' 					  => '',
			'line_subtotal' 			  => '',
			'line_subtotal_tax' 		  => '',
			'order_discount' 			  => '',
			'cart_discount' 			  => '',
			'order_shipping_tax' 		  => '',
			'order_shipping'			  => '',
			'order_tax'					  => '',
			'order_total' 				  => '',
			'order_recurring_total'		  => '',
			'stripe_customer_id'		  => '',
			'paypal_subscriber_id'		  => '',
			'payment_method_title'		  => '',
			'download_permission_granted' => '',
		);

		$mapping = $_POST['mapto'];
		// Doesnt yet handle multiple fields mapped to the same field
		foreach( $this->mapping as $key => $value) {
			$m_key = array_search( $key, $mapping );
			if( $m_key ) {
				$this->mapping[$key] = $m_key;
			}
		}
		// Need to check for errors
		$this->pre_import_check();
	}

	/**
	 * Checks if the admin wants to run the importer in test mode before creating WC Orders
	 * containing Subscriptions.
	 * @since 1.0
	 */
	function pre_import_check() {
		$action = 'admin.php?page=import_subscription&amp;step=4&amp;';
		echo '<h3>' . __( 'Step 4: Admin Options', 'wcs-importer' ) . '</h3>';
	?>
		<form method="post" action="<?php echo esc_attr(wp_nonce_url($action, 'import-upload')); ?>">
			<input type="hidden" name="file_id" value="<?php echo ( isset ( $_POST['file_id'] ) ) ? $_POST['file_id'] : ''; ?>">
			<input type="hidden" name="file_url" value="<?php echo ( isset ( $_POST['file_url'] ) ) ? $_POST['file_url'] : ''; ?>">
			<input type="hidden" name="delimiter" value="<?php echo ( isset ( $_POST['delimiter'] ) ) ? $_POST['delimiter'] : ''; ?>">
			<input type="hidden" name="mapping" value='<?php echo json_encode( $this->mapping ); ?>'>
			<table class="form-table">
				<tr>
					<td colspan="2"><em><?php echo sprintf( __( 'Test mode will present a list of critical errors and warnings found throughout the importing process without creating the subscription orders. %s It is highly recommended to make use of this feature to ensure no unexpected outcomes are a result of using of the WooCommerce Subscription Importer.', 'wcs-importer' ), '<br>' ); ?></em></td>
				</tr>
				<tr>
					<th><?php _e( 'Run in Test Mode', 'wcs-importer' ); ?>:</th>
					<td><input type="checkbox" name="test-mode" value="wcs-import-test"></td>
				</tr>
				<tr>
					<th><?php _e( 'Email new customers their temporary password?', 'wcs-importer' ); ?></th>
					<td><input type="checkbox" name="send-reg-email" value="wcs-import-reg_email"></td>
				</tr>
			</table>
			<p class="submit">
				<input type="submit" class="button" value="<?php esc_attr_e( 'Continue', 'wcs-importer' ); ?>" />
			</p>
		</form>
	<?php
	}

	/**
	 * Sets up the AJAX requests and calls import_ajax_start( .. )
	 *
	 * @since 1.0
	 */
	function ajax_setup() {
		$request_limit = ( defined( 'WCS_REQ_LIMIT' ) ) ? WCS_REQ_LIMIT : 15;
		$this->test_import = ( isset( $_POST['test-mode'] ) ) ? true : false;
		$send_user_email = ( isset( $_POST['send-reg-email'] ) ) ? true : false;
		$this->mapping = json_decode( stripslashes( $_POST['mapping'] ), true );
		$file_positions = $row_start = array();
		$payment_method_error = $payment_meta_error = array();
		$delimiter = ( ! empty( $_POST['delimiter'] ) ) ? $_POST['delimiter'] : ',';

		if( empty( $_POST['file_url'] ) ) {
			$file = get_attached_file( $_POST['file_id'] );
		} else {
			$file = ABSPATH . $_POST['file_url'];
		}
		$enc = mb_detect_encoding( $file, 'UTF-8, ISO-8859-1', true );
		if ( $enc ) setlocale( LC_ALL, 'en_US.' . $enc );
		@ini_set( 'auto_detect_line_endings', true );

		$count = 0;
		$total = 0;
		$previous_pos = 0;
		$position = 0;
		$row_start[] = 1;

		if ( ( $handle = fopen( $file, "r" ) ) !== FALSE ) {
			$row = $raw_headers = array();

			$header = fgetcsv( $handle, 0, $delimiter );
			while ( ( $postmeta = fgetcsv( $handle, 0, $delimiter ) ) !== FALSE ) {
				$count++;
				foreach ( $header as $key => $heading ) {
					if ( ! $heading ) continue;
					$s_heading = strtolower( $heading );
					$row[$s_heading] = ( isset( $postmeta[$key] ) ) ? $this->format_data_from_csv( $postmeta[$key], $enc ) : '';
				}

				if( strtolower( $row[$this->mapping['payment_method']] ) == 'stripe' && empty( $row[$this->mapping['stripe_customer_id']] ) ) {
					$payment_method_error[] = 'Stripe';
					$payment_meta_error[] = 'stripe_customer_id';
				} else if ( strtolower( $row[$this->mapping['payment_method']] ) == 'paypal' && empty( $row[$this->mapping['paypal_subscriber_id']] ) ) {
					$payment_method_error[] = 'Paypal';
					$payment_meta_error[] = 'paypal_subscriber_id';
				}

				if ( $count >= $request_limit ) {
					$previous_pos = $position;
					$position = ftell( $handle );
					$row_start[] = end( $row_start ) + $count;
					reset( $row_start );
					$count = 0;
					$total++;
					// Import rows between $previous_position $position
					$file_positions[] = $previous_pos;
					$file_positions[] = $position;
				}
			}

			// Account for the remainder
			if ( $count > 0 ) {
				$total++;
				$file_positions[] = $position;
				$file_positions[] = ftell( $handle );
			}
			fclose( $handle );
		}

		$array = json_encode( $file_positions );
		$starting_row_number = json_encode( $row_start );

		?>
		<script>
				jQuery(document).ready(function($) {
					var import_data = {
						file_id:		<?php echo ( ! empty( $_POST['file_id'] ) ) ? $_POST['file_id'] : ''; ?>,
						file_url:		'<?php echo ( ! empty( $_POST['file_url'] ) ) ? $_POST['file_url'] : ''; ?>',
						file_positions: <?php echo $array; ?>,
						total: 			<?php echo $total; ?>,
						start_row_num: 	<?php echo $starting_row_number; ?>,
						delimiter:		'<?php echo $delimiter; ?>',
						file:			'<?php echo addslashes( $file ); ?>',
						mapping: 		'<?php echo json_encode( $this->mapping ); ?>',
						ajax_url:		'<?php echo admin_url( 'admin-ajax.php' ); ?>',
						test_run: 		<?php echo ( $this->test_import ) ? "true" : "false"; ?>,
						send_reg_email:	<?php echo ( $send_user_email ) ? "true" : "false"; ?>
					}

					if ( import_data.test_run == 'false' && <?php echo count( $payment_method_error ); ?> > 0 ) { <?php 
						$method_error = json_encode( array_unique( $payment_method_error ) );
						$method_meta  = json_encode( array_unique( $payment_meta_error ) );
						$errorString  = sprintf( __( "You\'re importing subscriptions for %s without specifying %s . This will create subscriptions that use the manual renewal process, not the automatic process. Are you sure you want to do this?", 'wcs-importer' ), str_replace( '"', ' ', $method_error ), str_replace( '"', ' ', $method_meta ) ); ?>

						if ( confirm( "<?php echo $errorString; ?>" ) ){
							$( 'body' ).trigger( 'import-start', import_data );
						} else {
							window.location.href = "<?php echo admin_url( 'admin.php?page=import_subscription&cancelled=true' ); ?>";
						}
					} else {
						$( 'body' ).trigger( 'import-start', import_data );
					}
				});
		</script>
<?php
	}

	/**
	 * AJAX request holding the file, delimiter and mapping information is sent to this function.
	 *
	 * @since 1.0
	 */
	function ajax_request_handler() {
		if ( ! current_user_can( 'manage_woocommerce' ) ){
			wp_die( "Cheatin' huh?");
		}

		@set_time_limit(0);
		@ob_flush();
		@flush();

		if( isset( $_POST['file'] ) && isset( $_POST['row_num'] ) && isset( $_POST['mapping'] ) ) {
			$file = stripslashes($_POST['file']);
			$mapping = json_decode( stripslashes( $_POST['mapping'] ), true );
			$delimiter = $_POST['delimiter'];
			$start = ( isset( $_POST['start'] ) ) ? absint( $_POST['start'] ) : 0;
			$end = ( isset( $_POST['end'] ) ) ? absint( $_POST['end'] ) : 0;
			$starting_row_num = absint( $_POST['row_num'] );
			$test_mode = $_POST['test_mode'];
			$send_email = $_POST['send_email'];
			$this->parser = new WCS_Import_Parser();
			$this->results = $this->parser->import_data( $file, $delimiter, $mapping, $start, $end, $starting_row_num, $test_mode, $send_email );
			echo '<div style="display:none;">';
			$start_tag = ( $test_mode == 'true' ) ? "<!--WCS_TEST_START-->" : "<!--WCS_IMPORT_START-->";
			$end_tag = ( $test_mode == 'true' ) ? "<!--WCS_TEST_END-->" : "<!--WCS_IMPORT_END-->";
			echo $start_tag . json_encode( $this->results ) . $end_tag;
			echo '</div>';
		}
		exit; // End
	}

	/**
	 * Shows information dependant on whether $_POST['test-mode'] is set or not.
	 * If set, the admin is provided with a list of critical errors and non-critical warnings
	 * @since 1.0
	 */
	function wcs_import_results() { 
		$action = 'admin.php?page=import_subscription&amp;step=4&amp;';
		if ( isset( $_POST['test-mode'] ) ): ?>
			<h3><?php _e( 'Test Run Results', 'wcs-importer' ); ?></h3>
			<form method="post" action="<?php echo esc_attr(wp_nonce_url($action, 'import-upload')); ?>">
				<input type="hidden" name="file_id" value="">
				<input type="hidden" name="file_url" value="">
				<input type="hidden" name="delimiter" value="">
				<input type="hidden" name="mapping" value="">
				<table id="wcs-import-progress" class="widefat_importer widefat">
					<thead>
						<tr>
							<th class="row" colspan="2"><?php _e( 'Importer Test Results', 'wcs-importer' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<tr class="alternate">
							<th><strong><?php _e( 'Results', 'wcs-importer' ); ?></strong></th>
							<td id="wcs-importer_test_results"><strong><?php echo sprintf( __( '%s0%s tests passed, %s0%s tests failed ( %s0%s of the CSV will be imported ).', 'wcs-importer' ), '<span id="wcs-test-passed">', '</span>', '<span id="wcs-test-failed">', '</span>', '<span id="wcs-test-ratio">', '</span>%' ); ?></strong></td>
						</tr>
						<tr>
							<th><strong><?php _e( 'Details', 'wcs-importer' ); ?></strong></th>
							<td id="wcs-importer_test_details"><strong><?php echo sprintf( __( '%s0%s fatal errors and %s0%s warnings found.', 'wcs-importer' ), '<span id="wcs-fatal-details">', '</span>', '<span id="wcs-warning-details">', '</span>' ); ?></strong></td>
						</tr>
						<tr class="alternate" id="wcs-importer_test_errors"><th><?php _e( 'Error Messages', 'wcs-importer' ); ?>:</th><td></td></tr>
						<tr id="wcs-importer_test_warnings"><th><?php _e( 'Warnings', 'wcs-importer' ); ?>:</th><td></td></tr>
					</tbody>
				</table>
				<div id="wcs-completed-message">
					<p><?php _e( 'Test Finished!', 'wcs-importer' );?> <a href="<?php echo admin_url( 'admin.php?page=import_subscription' ); ?>"><?php _e( 'Back to Home', 'wcs-importer' ); ?></a></p>
					<input type="submit" class="button" value="<?php esc_attr_e( 'Continue Importing' , 'wcs-importer' ); ?>">
				</div>
			</form>
		<?php else : ?> 
			<h3><?php _e( 'Importing Results', 'wcs-importer' ); ?></h3>
			<table id="wcs-import-progress" class="widefat_importer widefat">
				<thead>
					<tr>
						<th class="row"><?php _e( 'Import Status', 'wcs-importer' ); ?></th>
						<th class="row"><?php _e( 'Order #', 'wcs-importer' ); ?></th>
						<th class="row"><?php _e( 'Subscription', 'wcs-importer' ); ?></th>
						<th class="row"><?php _e( 'User Name', 'wcs-importer' ); ?></th>
						<th class="row"><?php _e( 'Subscription Status', 'wcs-importer' ); ?></th>
						<th class="row"><?php _e( 'Number of Warnings', 'wcs-importer' ); ?></th>
					</tr>
				</thead>
				<tfoot>
					<tr class="importer-loading">
						<td colspan="6"></td>
					</tr>
				</tfoot>
				<tbody></tbody>
			</table>
			<p id="wcs-completed-message"><?php _e( 'All done!', 'wcs-importer' );?> <a href="<?php echo admin_url( 'admin.php?page=subscriptions' ); ?>"><?php _e( 'View Subscriptions', 'wcs-importer' ); ?></a>, <a href="<?php echo admin_url( 'edit.php?post_type=shop_order' ); ?>"><?php _e( 'View Orders', 'wcs-importer' ); ?></a> or <a href="<?php echo admin_url( 'admin.php?page=import_subscription' ); ?>"><?php _e( 'Import another file', 'wcs-importer' ); ?></a></p>
		<?php endif;
	}

	/**
	 * Handles displaying an error message throughout the process of importing subscriptions.
	 *
	 * @since 1.0
	 */
	function importer_error() {
		global $file;
		?>
		<h3><?php _e( 'Error while uploading File', 'wcs-importer' ); ?></h3>
		<p>Error message: <?php _e( $file['error'], 'wcs-importer' ); ?></p>
		<p><a href="<?php echo admin_url( 'admin.php?page=import_subscription' ); ?>"><?php _e( 'Import another file', 'wcs-importer' ); ?></a></p>
		<?php
	}
}
?>