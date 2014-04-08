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

	public static function init() {

		self::$wcs_importer = new WCS_Admin_Importer();

		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_scripts_wcs_import' ) );

		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_scripts_wcs_import_localize' ) );
		// Add the "Settings | Documentation" links on the Plugins administration screen
		add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), __CLASS__ . '::action_links' );
	}

	/**
	 *
	 * @since 1.0
	 */
	public static function enqueue_scripts_wcs_import() {
		wp_register_style( 'wcs-import_admin_css', plugin_dir_url(__FILE__) . '/css/style.css' );
		wp_enqueue_style( 'wcs-import_admin_css' );

		wp_register_script( 'wcs-import_admin_js', plugin_dir_url(__FILE__) . '/js/wcs-import_ajax.js' );
		wp_enqueue_script( 'wcs-import_admin_js' );
	}

	/**
	 *
	 * @since 1.0
	 */
	public static function enqueue_scripts_wcs_import_localize() {
		$translation_array = array( 
			'success' 				=> __( 'success', 'wcs-importer' ),
			'failed' 				=> __( 'failed', 'wcs-importer' ),
			'error_string'			=> sprintf( __( "Row #%s from CSV %sfailed to import%s with error/s: %s", 'wcs-importer' ), '{row_number}', '<strong>', '</strong>', '{error_messages}' ),
			'finished_importing' 	=> __( 'Finished Importing', 'wcs-importer' ),
			'edit_order' 			=> __( 'Edit Order', 'wcs-importer' ),
			'warning'				=> __( 'Warning', 'wcs-importer' ),
			'warnings'				=> __( 'Warnings', 'wcs-importer' ),
			'located_at'			=> __( 'Located at rows', 'wcs-importer' ),
		);
		wp_localize_script( 'wcs-import_admin_js', 'wcs_import_lang', $translation_array );
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
