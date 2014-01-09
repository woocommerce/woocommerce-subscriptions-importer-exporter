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
// Plugin Classes
include dirname( __FILE__ ) . '/include/class-wcsimprt-admin.php';

WC_Subscription_Importer::init();

class WC_Subscription_Importer {
	
	public static function init() {
		global $wcs_importer;

		add_action( 'admin_menu', 'WC_Subscription_Importer::add_sub_menu', 10 );
		add_action( 'admin_init', 'WC_Subscription_Importer::add_import_tool' );
	}

	/* Add menu item under Woocommerce > Subscription CSV Import Suite */
	function add_sub_menu() {
		$menu = add_submenu_page('woocommerce', __( 'Subscription CSV Import Suite', 'wcs-importer' ),  __( 'Subscription CSV Import Suite', 'wcs-importer' ), 'manage_options', 'import_subscription', 'WC_Subscription_Importer::home');
		//add_action( 'admin_print_styles-'. $menu, 'woocommerce_admin_css' ); //causing error
	}

	function add_import_tool() {
		register_importer('subscription_csv', 'WooCommerce Subscriptions (CSV)', __( 'Import <strong>subscriptions</strong> to your store via a csv file.', 'subscription_importer' ), 'WC_Subscription_Importer::home' );
	}

	/* Main page header */
	static function home() {
		$wcs_importer = new WCS_Admin_Importer();
		echo '<div class="wrap">';
		echo '<h2>' . __( 'Subscription CSV Import Suite', 'wcs-importer' ) . '</h2>';
		?>
		<div id="message" class="updated woocommerce-message wc-connect">
			<div class="squeezer">
				<h4><?php _e( '<strong>Subscription CSV Import Suite</strong> &#8211; Before gettign started prepare your CSV files', 'wcs-importer' ); ?></h4>
				<p class="submit"><a href="" class="button-primary"><?php _e( 'Documentation', 'wcs-importer' ); ?></a></p>
			</div>
		</div>
		<?php //WC_Subscription_Importer::display_content();
		$wcs_importer->display_content();
		echo '</div>';
	}

}
?>
