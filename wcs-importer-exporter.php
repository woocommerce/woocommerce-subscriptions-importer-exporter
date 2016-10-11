<?php
/**
 * Plugin Name: WooCommerce Subscriptions CSV Importer and Exporter
 * Plugin URI: https://github.com/Prospress/woocommerce-subscriptions-importer-exporter
 * Description: Import or export subscriptions in your WooCommerce store via CSV.
 * Version: 2.0-beta
 * Author: Prospress Inc
 * Author URI: http://prospress.com
 * License: GPLv2
 *
 * GitHub Plugin URI: Prospress/woocommerce-subscriptions-importer-exporter
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

		add_filter( 'woocommerce_subscription_get_last_payment_date', array(__CLASS__, 'last_payment' ), 10, 3 );

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
	* This is to calculate last_payment when the last payment not set by import
	* this is done by taking next interval and removing twice.
	* Only change from 0 if it was an imported subscription
	*
	* @param string Or 0 $date A MySQL formatted date/time string in GMT/UTC timezone.
	* @param $subscription WC_Subscription
	* @param $timezone The timezone of the $datetime param, either 'gmt' or 'site'. Default 'gmt'.
	* @return $date OR date of last payment calulation
	*/
	public static function last_payment($date, $subscription, $timezone ) {
		if ( 0 == $date && "importer" === $subscription->created_via ) {
			$next_payment  = wcs_date_to_time( $subscription->get_date( 'next_payment', $timezone ) );
			$next_interval = wcs_add_time( $subscription->billing_interval, $subscription->billing_period, $next_payment );
			$last_payment  = $next_payment - ( $next_interval - $next_payment );

			return gmdate( 'Y-m-d H:i:s', $last_payment );
		}
		return $date;
	}

	/**
	 * Include Docs & Settings links on the Plugins administration screen
	 *
	 * @since 1.0
	 * @param mixed $links
	 * @return array
	 */
	public static function action_links( $links ) {

		$plugin_links = array(
			'<a href="' . esc_url( admin_url( 'admin.php?page=import_subscription' ) ) . '">' . esc_html__( 'Import', 'wcs-import-export' ) . '</a>',
			'<a href="https://github.com/Prospress/woocommerce-subscriptions-importer-exporter/blob/master/README.md">' . esc_html__( 'Docs', 'wcs-import-export' ) . '</a>',
			'<a href="https://github.com/Prospress/woocommerce-subscriptions-importer-exporter/issues/new">' . esc_html__( 'Support', 'wcs-import-export' ) . '</a>',
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
					<p><?php printf( esc_html__( '%1$sWooCommerce Subscriptions Importer is inactive.%2$s The %3$sWooCommerce Subscriptions plugin%4$s must be active for WooCommerce Subscriptions Importer to work. Please %5$sinstall & activate%6$s WooCommerce.', 'wcs-import-export' ), '<strong>', '</strong>', '<a href="http://www.woothemes.com/products/woocommerce-subscriptions/">', '</a>', '<a href="' . esc_url( admin_url( 'plugins.php' ) ) . '">', '</a>' ); ?></p>
				</div>
			<?php else : ?>
				<div id="message" class="error">
					<p><?php printf( esc_html__( '%1$sWooCommerce Subscriptions Importer is inactive.%2$s Both %3$sWooCommerce%4$s and %5$sWooCommerce Subscriptions%6$s plugins must be active for WooCommerce Subscriptions Importer to work. Please %7$sinstall & activate%8$s these plugins before continuing.', 'wcs-import-export' ), '<strong>', '</strong>', '<a href="http://wordpress.org/extend/plugins/woocommerce/">', '</a>', '<a href="http://www.woothemes.com/products/woocommerce-subscriptions/">', '</a>', '<a href="' . esc_url( admin_url( 'plugins.php' ) ) . '">', '</a>' ); ?></p>
				</div>
			<?php endif;?>
		<?php elseif ( ! class_exists( 'WC_Subscriptions' ) || version_compare( WC_Subscriptions::$version, '2.0', '<' ) ) : ?>
			<div id="message" class="error">
				<p><?php printf( esc_html__( '%1$sWooCommerce Subscriptions Importer is inactive.%2$s The %3$sWooCommerce Subscriptions%4$s version 2.0 (or greater) is required to safely run WooCommerce Subscriptions Importer. Please %5$supdate & activate%6$s WooCommerce Subscriptions.', 'wcs-import-export' ), '<strong>', '</strong>', '<a href="http://www.woothemes.com/products/woocommerce-subscriptions/">', '</a>', '<a href="' . esc_url( admin_url( 'plugins.php' ) ) . '">', '&nbsp;&raquo;</a>' ); ?></p>
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
