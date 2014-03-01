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
require_once( dirname( __FILE__ ) . '/includes/class-wcs-import-admin.php' );
require_once( dirname( __FILE__ ) . '/includes/class-wcs-import-parser.php' );

WC_Subscription_Importer::init();

class WC_Subscription_Importer {

	static $wcs_importer;

	public static function init() {

		self::$wcs_importer = new WCS_Admin_Importer();

		add_action( 'admin_menu', array( __CLASS__, 'add_sub_menu' ), 10 );
		add_action( 'admin_init', array( __CLASS__, 'add_import_tool' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_scripts_wcs_import' ) );
		add_action( 'wp_ajax_wcs_import_request', array( self::$wcs_importer, 'display_content' ) );

		// Add the "Settings | Documentation" links on the Plugins administration screen
		add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), __CLASS__ . '::action_links' );
	}

	/**
	 * Add menu item under Woocommerce > Subscription CSV Importer
	 *
	 * @since 1.0
	 */
	public static function add_sub_menu() {
		$menu = add_submenu_page('woocommerce', __( 'Subscription Importer', 'wcs-importer' ),  __( 'Subscription Importer', 'wcs-importer' ), 'manage_options', 'import_subscription', array( __CLASS__, 'home' ) );
	}

	/**
	 *
	 * @since 1.0
	 */
	public static function add_import_tool() {
		register_importer( 'woocommerce_subscription_csv', 'WooCommerce Subscriptions (CSV)', __( 'Import <strong>subscriptions</strong> to your WooCommerce store via a CSV file.', 'wcs-importer' ), array( __CLASS__, 'home' ) );
	}

	/**
	 *
	 * @since 1.0
	 */
	public static function enqueue_scripts_wcs_import() {
		wp_register_style( 'wcs-import_admin_css', plugin_dir_url(__FILE__) . '/css/style.css' );
		wp_enqueue_style( 'wcs-import_admin_css' );
	}

	/**
	 * Main page header
	 *
	 * @since 1.0
	 */
	public static function home() {
		echo '<div class="wrap">';
		echo '<h2>' . __( 'Subscription CSV Importer', 'wcs-importer' ) . '</h2>';
		?>
		<div id="message" class="updated woocommerce-message wc-connect">
			<div class="squeezer">
				<h4><?php _e( '<strong>Subscription CSV Importer</strong> &#8211; before you begin, please prepare your CSV file.', 'wcs-importer' ); ?></h4>
				<p class="submit"><a href="" class="button-primary"><?php _e( 'Documentation', 'wcs-importer' ); ?></a></p>
			</div>
		</div>
		<?php //WC_Subscription_Importer::display_content();
		self::$wcs_importer->display_content();
		echo '</div>';
	}

	/**
	 * Include Docs & Settings links on the Plugins administration screen
	 *
	 * @param mixed $links
	 * @since 1.0
	 */
	public static function action_links( $links ) {

		$plugin_links = array(
			'<a href="' . admin_url( 'admin.php?page=import_subscription' ) . '">' . __( 'Import', 'wcs-importer' ) . '</a>',
			'<a href="http://docs.woothemes.com/document/subscriptions-importer/">' . __( 'Docs', 'wcs-importer' ) . '</a>',
			'<a href="http://support.woothemes.com">' . __( 'Support', 'wcs-importer' ) . '</a>',
		);

		return array_merge( $plugin_links, $links );
	}
}
