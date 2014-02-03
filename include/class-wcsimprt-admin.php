<?php
global $file;

class WCS_Admin_Importer {
	var $id;
	var $delimiter;
	var $import_results = array();
	var $mapping;
	var $file_url;

	/* Displays header followed by the current pages content */
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
		case 3 :
			$this->check_mapping();
		case 4 :
			$this->AJAX_request_handler();
			break;
		default : //default to home page
			$this->upload_page();
			break;
		}
	}
	/* Initial plugin page. Prompts the admin to upload the CSV file containing subscription details. */
	static function upload_page() { 
		echo '<h3>' . __( 'Step 1: Upload CSV File', 'wcs_import' ) . '</h3>';
		$action = 'admin.php?page=import_subscription&amp;step=2&amp;';
		$bytes = apply_filters( 'import_upload_size_limit', wp_max_upload_size() );
		$size = size_format( $bytes );
		$upload_dir = wp_upload_dir();
		if ( ! empty( $upload_dir['error'] ) ) : ?>
			<div class="error"><p><?php _e('Before you can upload your import file, you will need to fix the following error:'); ?></p>
			<p><strong><?php echo $upload_dir['error']; ?></strong></p></div><?php
		else :
			?>
			<p>Upload a CSV file containing details about your subscriptions to bring across to your store with WooCommerce.</p>
			<p>Choose a CSV (.csv) file to upload, then click Upload file and import.</p>
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
								<label for="file_url"><?php _e( 'OR enter path to file:', 'wcs_import' ); ?></label>
							</th>
							<td>
								<?php echo ' ' . ABSPATH . ' '; ?><input type="text" id="file_url" name="file_url" size="50" />
							</td>
						</tr>
						<tr>
							<th><label><?php _e( 'Delimiter', 'wcs_import' ); ?></label><br/></th>
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

	function handle_file() {
		global $file;
		if ( empty( $_POST['file_url'] ) ) {
			$file = wp_import_handle_upload();
			if( isset( $file['error'] ) ) {
				$this->importer_error();
			}
			$this->id = $file['id'];
			$file = get_attached_file( $this->id );
		} else {
			if ( file_exists( ABSPATH . $_POST['file_url'] ) ) {
				$this->file_url = esc_attr( $_POST['file_url'] );
				$file = ABSPATH . $this->file_url;
			} else {
				$this->importer_error();
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

	function format_data_from_csv( $data, $enc ) {
		return ( $enc == 'UTF-8' ) ? $data : utf8_encode( $data );
	}

	/* Step 2: Once uploaded file is recognised, the admin will be required to map CSV columns to the required fields. */
	function map_fields( $row ) {
		$action = 'admin.php?page=import_subscription&amp;step=3&amp;';
		?>
		<h3><?php _e( 'Step 2: Map Fields to Column Names', 'wcs_import' ); ?></h3>
		<form method="post" action="<?php echo esc_attr(wp_nonce_url($action, 'import-upload')); ?>">
			<input type="hidden" name="file_id" value="<?php echo $this->id; ?>">
			<input type="hidden" name="file_url" value="<?php echo $this->file_url; ?>">
			<input type="hidden" name="delimiter" value="<?php echo $this->delimiter; ?>">
			<table class="widefat widefat_importer">
				<thead>
					<tr>
						<th><?php _e( 'Map to', 'wcs_import' ); ?></th>
						<th><?php _e( 'Column Header', 'wcs_import' ); ?></th>
						<th><?php _e( 'Example Column Value', 'wcs_import' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach( $row as $header => $example ) : ?>
					<tr>
						<td> <!-- Available mapping options -->
							<select name="mapto[<?php echo $header; ?>]">
								<optgroup label="<?php _e( 'Subsciption Information', 'wcs_import'); ?>">
									<option value="0"><?php _e( 'Do not import', 'wcs_import'); ?></option>
									<option value="product_id" <?php selected( $header, 'product_id' ); ?>>product_id</option>
									<option value="customer_id" <?php selected( $header, 'customer_id' ); ?>>customer_id</option>
									<optgroup label="<?php _e( 'Other Customer Data', 'wcs_import' ); ?>">
										<option value="customer_email" <?php selected( $header, 'customer_email' ); ?>>customer_email</option>
										<option value="customer_username" <?php selected( $header, 'customer_username' ); ?>>customer_username</option>
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
										<option value="shipping_address_1" <?php selected( $header, 'shipping_address_1' ); ?>>shipping_address_1</option>
										<option value="shipping_address_2" <?php selected( $header, 'shipping_address_2' ); ?>>shipping_address_2</option>
										<option value="shipping_city" <?php selected( $header, 'shipping_city' ); ?>>shipping_city</option>
										<option value="shipping_state" <?php selected( $header, 'shipping_state' ); ?>>shipping_state</option>
										<option value="shipping_postcode" <?php selected( $header, 'shipping_postcode' ); ?>>shipping_postcode</option>
										<option value="shipping_country" <?php selected( $header, 'shipping_country' ); ?>>shipping_country</option>
									</optgroup>
									<option value="subscription_status" <?php selected( $header, 'subscription_status' ); ?>>subscription_status</option>
									<option value="subscription_start_date" <?php selected( $header, 'subscription_start_date' ); ?>>subscription_start_date</option>
									<option value="subscription_expiry_date" <?php selected( $header, 'subscription_expiry_date' ); ?>>subscription_expiry_date</option>
									<option value="subscription_end_date" <?php selected( $header, 'subscription_end_date' ); ?>>subscription_end_date</option>
								</optgroup>
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
								<option value="shipping_method" <?php selected( $header, 'shipping_method' ); ?>>shipping_method</option>
							</select>
						</td>
						<td width="25%"><?php echo $header; ?></td> <!-- Column deader from csv file -->
						<td><code><?php if ( $example != '' ) echo esc_html( $example ); else echo '-'; ?></code></td>
					</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<p class="submit">
				<input type="submit" class="button" value="<?php esc_attr_e( 'Submit' ); ?>" />
			</p>
		</form>
		<?php
	}

	/* Checks the mapping provides enough information to continue importing subscriptions */
	function check_mapping() {
		// Possible mapping options
		$this->mapping = array(
			'product_id'				  => '',
			'customer_id' 				  => '',
			'customer_email' 			  => '',
			'customer_username' 		  => '',
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
		$this->confirmation_table();
		$this->AJAX_setup();
	}

	/* Sets up the AJAX requests and calls import_AJAX_start( .. ) */
	function AJAX_setup() {
		$request_limit = 15; // May change
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
		
		if ( ( $handle = fopen( $file, "r" ) ) !== FALSE ) {
			while ( ( $postmeta = fgetcsv( $handle, 0, $delimiter ) ) !== FALSE ) {
				$count++;
				$total++;

				if ( $count >= $request_limit ) {
					$previous_pos = $position;
					$position = ftell( $handle );
					$count = 0;

					// Import rows between $previous_position $position
					$this->import_AJAX_start( $file, $delimiter, $previous_pos, $position );
				}
			}

			// Account for the remainder
			if ( $count > 0 ) {
				//rows.push( [ <?php echo $position; , '' ] );
				$this->import_AJAX_start( $file, $delimiter, $position, ftell( $handle ) );
			}
			fclose( $handle );
		}
	}

	/* Sends the AJAX call and waits for the repsonse data to fill in the confirmation table. */
	function import_AJAX_start( $file, $delimiter, $start_pos, $end_pos ) {
		?>
		<script>
			jQuery(document).ready(function($) {
				/* AJAX Request to import subscriptions */
					var data = {
						action:		'wcs_import_request',
						mapping:	'<?php echo json_encode( $this->mapping ); ?>',
						delimiter:	'<?php echo $delimiter; ?>',
						file:		'<?php echo addslashes( $file ); ?>',
						start:		'<?php echo $start_pos; ?>',
						end:		'<?php echo $end_pos; ?>'
					}
console.log(data);
					$.ajax({
						url:	'<?php echo add_query_arg( array( 'import_page' => 'subscription_csv', 'step' => '4'), admin_url( 'admin-ajax.php' ) ) ; ?>',
						type:	'POST',
						data:	data,
						success: function( response ) {
							console.log(response);
						}
					});
					console.log('<?php echo addslashes( $file ); ?>');
					console.log('<?php echo json_encode( $_POST['mapto'] ); ?>');
					console.log('<?php echo $delimiter; ?>');

			});
		</script>
	<?php
	}

	/* 
	function get_num_rows( $file ) {
	
	}

	/* AJAX request holding the file, delimiter and mapping information is sent to this function. */
	function AJAX_request_handler() {
		if ( ! current_user_can( 'manage_woocommerce' ) ){
			error_log('invalid user');
			die();
		}
		@set_time_limit(0);
		@ob_flush();
		@flush();

		$file = stripslashes($_POST['file']);
		$mapping = json_decode( stripslashes( $_POST['mapping'] ), true );
		$delimiter = $_POST['delimiter'];
		$start = ( isset( $_POST['start'] ) ) ? absint( $_POST['start'] ) : 0;
		$end = ( isset( $_POST['end'] ) ) ? absint( $_POST['end'] ) : 0;
		$this->parser = new WCS_Import_Parser();
		$this->results = $this->parser->import_data( $file, $delimiter, $mapping, $start, $end );
		echo "<!--WC_START-->";
		echo json_encode( $this->results );
		echo "<!--WC_END-->";
		exit; // End
	}

	/* Step 3: Displays the information about to be uploaded and waits for confirmation by the admin. */
	function confirmation_table() { 
		global $file;
		echo '<h3>' . __( 'Step 3: Confirmation', 'wcs_import' ) . '</h3>';
		?>
		<table id="import-progress" class="widefat_importer widefat">
			<thead>
				<tr>
					<th class="status">&nbsp;</th>
					<th class="row"><?php _e( 'Row', 'wcs_import' ); ?></th>
					<th><?php _e( 'Subscription', 'wcs_import' ); ?></th>
					<th class="reason"><?php _e( 'Status Msg', 'wcs_import' ); ?></th>
				</tr>
			</thead>
			<tfoot>
				<tr class="importer-loading">
					<td colspan="5"></td>
				</tr>
			</tfoot>
			<tbody></tbody>
		</table><?php
	}

	/* Handles displaying an error message throughout the process of importing subscriptions. */
	function importer_error() {
		global $file;
		?>
		<h3>Error</h3>
		<p>Error: <?php _e($file['error']); ?></p>
		<?php
		// Unfinished. Doesnt show anything but error message
	}
}
?>