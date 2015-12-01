<?php
/*
Plugin Name: WooCommerce Subscriptions Importer
Plugin URI:
Description: CSV Importer to bring your subscriptions to Woocommerce.
Version: 1.0
Author: Prospress Inc
Author URI: http://prospress.com
License: GPLv2
*/
if ( ! defined( 'ABSPATH') ) exit; // Exit if accessed directly

/**
 * Required functions
 */
if ( ! defined( 'ABSPATH') ) exit; // Exit if accessed directly

if ( ! function_exists( 'woothemes_queue_update' ) || ! function_exists( 'is_woocommerce_active' ) ) {
	require_once( 'woo-includes/woo-functions.php' );
}

require_once( 'includes/class-wcs-import-admin.php' );
require_once( 'includes/class-wcs-import-parser.php' );
require_once( 'includes/wcs-import-functions.php' );

class WC_Subscription_Importer {

	static $wcs_importer;
	static $plugin_file = __FILE__;

	public static function init() {
		// Create the importer on admin side only
		add_filter( 'plugins_loaded', __CLASS__ . '::setup_importer', 1 );

		// Add the "Settings | Documentation" links on the Plugins administration screen
		add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), __CLASS__ . '::action_links' );
	}

	/**
	 * Create an instance of the importer on admin pages and check for WooCommerce Subscriptions dependency.
	 *
	 * @since 1.0
	 */
	public static function setup_importer() {

		if ( is_admin() ) {
			if ( class_exists( 'WC_Subscriptions' ) && version_compare( WC_Subscriptions::$version, '1.5', '>=' ) ) {
				self::$wcs_importer = new WCS_Admin_Importer();

			} else {
				add_action( 'admin_notices', __CLASS__ . '::plugin_dependency_notice' );
			}
		}
	}

	/**
	 * Include Docs & Settings links on the Plugins administration screen
	 *
	 * @since 1.0
	 * @param mixed $links
	 */
	public static function action_links( $links ) {

		$plugin_links = array(
			'<a href="' . esc_url( admin_url( 'admin.php?page=import_subscription' ) ) . '">' . esc_html__( 'Import', 'wcs-importer' ) . '</a>',
			'<a href="http://docs.woothemes.com/document/subscriptions-importer/">' . esc_html__( 'Docs', 'wcs-importer' ) . '</a>',
			'<a href="http://support.woothemes.com">' . esc_html__( 'Support', 'wcs-importer' ) . '</a>',
		);

		return array_merge( $plugin_links, $links );
	}

	/**
	 * Display error message according to the missing dependency
	 *
	 * @since 1.0
	 */
	public static function plugin_dependency_notice() {

		if ( ! class_exists( 'WC_Subscriptions' ) || ! class_exists( 'WC_Subscriptions_Admin' ) ) :
			/* Only show error for missing WooCommerce if WC Subscriptions is missing aswell, this is to avoid duplicating messages */
			if ( is_woocommerce_active() ) : ?>
				<div id="message" class="error">
					<p><?php printf( esc_html__( '%sWooCommerce Subscriptions Importer is inactive.%s The %sWooCommerce Subscriptions plugin%s must be active for WooCommerce Subscriptions Importer to work. Please %sinstall & activate%s WooCommerce.', 'wcs-importer' ), '<strong>', '</strong>', '<a href="http://www.woothemes.com/products/woocommerce-subscriptions/">', '</a>', '<a href="' . esc_url( admin_url( 'plugins.php' ) ) . '">', '</a>' ); ?></p>
				</div>
			<?php else : ?>
				<div id="message" class="error">
					<p><?php printf( esc_html__( '%sWooCommerce Subscriptions Importer is inactive. %sBoth %sWooCommerce%s and %sWooCommerce Subscriptions%s plugins must be active for WooCommerce Subscriptions Importer to work. Please %sinstall & activate%s these plugins before continuing.', 'wcs-importer' ), '<strong>', '</strong>', '<a href="http://wordpress.org/extend/plugins/woocommerce/">', '</a>', '<a href="http://www.woothemes.com/products/woocommerce-subscriptions/">', '</a>', '<a href="' . admin_url( 'plugins.php' ) . '">', '</a>' ); ?></p>
				</div>
			<?php endif;?>
		<?php elseif ( version_compare( WC_Subscriptions::$version, '1.5', '<' ) ) : ?>
			<div id="message" class="error">
				<p><?php printf( esc_html__( '%sWooCommerce Subscriptions Importer is inactive.%s The %sWooCommerce Subscriptions%s version 2.0 (or greater) is required to safely run WooCommerce Subscriptions Importer. Please %supdate & activate%s WooCommerce Subscriptions.', 'wcs-importer' ), '<strong>', '</strong>', '<a href="http://www.woothemes.com/products/woocommerce-subscriptions/">', '</a>', '<a href="' . admin_url( 'plugins.php' ) . '">', '&nbsp;&raquo;</a>' ); ?></p>
			</div>
		<?php endif;
	}
}

WC_Subscription_Importer::init();
