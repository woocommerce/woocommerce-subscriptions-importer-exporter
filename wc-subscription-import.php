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
include dirname( __FILE__ ) . '/includes/class-wcs-import-admin.php';
include dirname( __FILE__ ) . '/includes/class-wcs-import-parser.php';

WC_Subscription_Importer::init();

class WC_Subscription_Importer {

	public static function init() {
		global $wcs_importer;
		$wcs_importer = new WCS_Admin_Importer();
		add_action( 'admin_menu', array( __CLASS__, 'add_sub_menu' ), 10 );
		add_action( 'admin_init', array( __CLASS__, 'add_import_tool' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_scripts_wcs_import' ) );
		add_action( 'wp_ajax_wcs_import_request', array( $wcs_importer, 'display_content' ) );
	}

	/* Add menu item under Woocommerce > Subscription CSV Import Suite */
	public static function add_sub_menu() {
		$menu = add_submenu_page('woocommerce', __( 'Subscription CSV Import Suite', 'wcs-importer' ),  __( 'Subscription CSV Import Suite', 'wcs-importer' ), 'manage_options', 'import_subscription', array( __CLASS__, 'home' ) );

	}

	public static function add_import_tool() {
		register_importer('subscription_csv', 'WooCommerce Subscriptions (CSV)', __( 'Import <strong>subscriptions</strong> to your store via a csv file.', 'subscription_importer' ), array( __CLASS__, 'home' ) );
	}

	public static function enqueue_scripts_wcs_import() {
		wp_register_style( 'wcs-import_admin_css', plugin_dir_url(__FILE__) . '/css/style.css' );
		wp_enqueue_style( 'wcs-import_admin_css' );
	}

	/* Main page header */
	public static function home() {
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
