<?php
/**
 * Plugin Name: WooCommerce Subscriptions CSV Importer and Exporter
 * Plugin URI: https://github.com/Prospress/woocommerce-subscriptions-importer
 * Description: Import or export subscriptions in your WooCommerce store via CSV.
 * Version: 2.0-beta
 * Author: Prospress Inc
 * Author URI: http://prospress.com
 * License: GPLv2
 *
 * GitHub Plugin URI: Prospress/woocommerce-subscriptions-importer
 * GitHub Branch: master
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'woothemes_queue_update' ) || ! function_exists( 'is_woocommerce_active' ) ) {
	require_once( 'woo-includes/woo-functions.php' );
}

require_once( 'includes/wcsi-functions.php' );

class WCS_Importer_Exporter {

	public static $wcs_importer;

	public static $wcs_exporter;

	public static $version = '1.0.0';

	protected static $plugin_file = __FILE__;

	/**
	 * Initialise filters for the Subscriptions CSV Importer
	 *
	 * @since 1.0
	 */
	public static function init() {
		add_filter( 'plugins_loaded', __CLASS__ . '::setup_importer' );
		add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), __CLASS__ . '::action_links' );

		spl_autoload_register( __CLASS__ . '::autoload' );
	}

	/**
	 * Create an instance of the importer on admin pages and check for WooCommerce Subscriptions dependency.
	 *
	 * @since 1.0
	 */
	public static function setup_importer() {

		if ( is_admin() ) {
			if ( class_exists( 'WC_Subscriptions' ) && version_compare( WC_Subscriptions::$version, '2.0', '>=' ) ) {
				self::$wcs_exporter = new WCS_Export_Admin();
				self::$wcs_importer = new WCS_Import_Admin();
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
			'<a href="' . esc_url( admin_url( 'admin.php?page=import_subscription' ) ) . '">' . esc_html__( 'Import', 'wcs-import-export' ) . '</a>',
			'<a href="https://github.com/Prospress/woocommerce-subscriptions-importer/blob/master/README.md">' . esc_html__( 'Docs', 'wcs-import-export' ) . '</a>',
			'<a href="https://github.com/Prospress/woocommerce-subscriptions-importer/issues/new">' . esc_html__( 'Support', 'wcs-import-export' ) . '</a>',
		);

		return array_merge( $plugin_links, $links );
	}

	/**
	 * Display error message according to the missing dependency
	 *
	 * Will only show error for missing WC if Subscriptions is missing as well,
	 * this is to avoid duplicating messages printed by Subscriptions.
	 *
	 * @since 1.0
	 */
	public static function plugin_dependency_notice() {

		if ( ! class_exists( 'WC_Subscriptions' ) || ! class_exists( 'WC_Subscriptions_Admin' ) ) :
			if ( is_woocommerce_active() ) : ?>
				<div id="message" class="error">
					<p><?php printf( esc_html__( '%sWooCommerce Subscriptions Importer is inactive.%s The %sWooCommerce Subscriptions plugin%s must be active for WooCommerce Subscriptions Importer to work. Please %sinstall & activate%s WooCommerce.', 'wcs-import-export' ), '<strong>', '</strong>', '<a href="http://www.woothemes.com/products/woocommerce-subscriptions/">', '</a>', '<a href="' . esc_url( admin_url( 'plugins.php' ) ) . '">', '</a>' ); ?></p>
				</div>
			<?php else : ?>
				<div id="message" class="error">
					<p><?php printf( esc_html__( '%sWooCommerce Subscriptions Importer is inactive. %sBoth %sWooCommerce%s and %sWooCommerce Subscriptions%s plugins must be active for WooCommerce Subscriptions Importer to work. Please %sinstall & activate%s these plugins before continuing.', 'wcs-import-export' ), '<strong>', '</strong>', '<a href="http://wordpress.org/extend/plugins/woocommerce/">', '</a>', '<a href="http://www.woothemes.com/products/woocommerce-subscriptions/">', '</a>', '<a href="' . esc_url( admin_url( 'plugins.php' ) ) . '">', '</a>' ); ?></p>
				</div>
			<?php endif;?>
		<?php elseif ( ! class_exists( 'WC_Subscriptions' ) || version_compare( WC_Subscriptions::$version, '2.0', '<' ) ) : ?>
			<div id="message" class="error">
				<p><?php printf( esc_html__( '%sWooCommerce Subscriptions Importer is inactive.%s The %sWooCommerce Subscriptions%s version 2.0 (or greater) is required to safely run WooCommerce Subscriptions Importer. Please %supdate & activate%s WooCommerce Subscriptions.', 'wcs-import-export' ), '<strong>', '</strong>', '<a href="http://www.woothemes.com/products/woocommerce-subscriptions/">', '</a>', '<a href="' . esc_url( admin_url( 'plugins.php' ) ) . '">', '&nbsp;&raquo;</a>' ); ?></p>
			</div>
		<?php endif;
	}

	/**
	 * Get the plugin's URL path for loading assets
	 *
	 * @since 2.0
	 * @return string
	 */
	public static function plugin_url() {
		return plugin_dir_url( self::$plugin_file );
	}

	/**
	 * Get the plugin's path for loading files
	 *
	 * @since 2.0
	 * @return string
	 */
	public static function plugin_dir() {
		return plugin_dir_path( self::$plugin_file );
	}

	/**
	 * Get the plugin's path for loading files
	 *
	 * @since 2.0
	 * @return string
	 */
	public static function autoload( $class ) {
		$class = strtolower( $class );
		$file  = 'class-' . str_replace( '_', '-', $class ) . '.php';

		if ( 0 === strpos( $class, 'wcs_import' ) || 0 === strpos( $class, 'wcs_export' ) ) {
			require_once( self::plugin_dir() . '/includes/' . $file );
		}
	}
}

WCS_Importer_Exporter::init();
