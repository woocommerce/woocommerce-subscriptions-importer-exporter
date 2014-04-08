<?php
/*
Plugin Name: WooCommerce Subscriptions Importer
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

	static $plugin_file = __FILE__;

	public static function init() {

		self::$wcs_importer = new WCS_Admin_Importer();
		// Add the "Settings | Documentation" links on the Plugins administration screen
		add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), __CLASS__ . '::action_links' );
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
