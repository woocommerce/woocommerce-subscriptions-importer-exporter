<?php
/*
Plugin Name: Woocommerce Subscription Importer
Plugin URI: -
Description: CSV Importer to bring your subscriptions to Woocommerce.
Version: 1.0
Author: -
Author URI: -
License: -
*/

if ( !defined( 'ABSPATH') ) exit; // Exit if accessed directly
if ( is_plugin_active( 'woocommerce/woocommerce.php' ) ) {
	// Plugin Classes
	include dirname( __FILE__ ) . '/include/class-wcimprt-admin.php'; //empty


	WC_Subscription_Importer::init();

	class WC_Subscription_Importer {
	
		public static function init() {
			add_action( 'admin_menu', 'WC_Subscription_Importer::addSubMenu', 10 );
		
		}
	
		/* Add menu item under Woocommerce > Subscription CSV Import Suite */
		function addSubMenu() {
			$menu = add_submenu_page('woocommerce', __( 'Subscription CSV Import Suite', 'woocommerce' ),  __( 'Subscription CSV Import Suite', 'woocommerce' ), 'manage_options', 'sub_import_menu', 'WC_Subscription_Importer::header');
			add_action( 'admin_print_styles-'. $menu, 'woocommerce_admin_css' );
		}
	
		/* Main page header */
		static function header() {
			echo '<div class="wrap">';
			echo '<h2>' . __( 'Subscription CSV Import Suite', 'woocommerce' ) .'</h2>';
			echo '<p>Choose the CSV that contains all the subscription information.</p>';
			WC_Subscription_Importer::displayContent();
			echo '</div>';
		}

		/* Main page content */
		static function displayContent() { 
		?>
		<form enctype="multipart/form-data"method="post" action="">
			<label><?php __( 'Choose a file to upload:' ); ?></label>
			<input type="file" name="import-file" size="20" />
			<input type="submit" class="button" value="<?php esc_attr_e( 'Import File' ); ?>" />
		</form>
		<?php
		}
	}
}
?>
