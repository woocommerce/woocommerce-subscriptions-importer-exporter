<?php
/**
 * Plugin Name: WooCommerce Subscriptions CSV Importer and Exporter
 * Plugin URI: https://github.com/Prospress/woocommerce-subscriptions-importer-exporter
 * Description: Import or export subscriptions in your WooCommerce store via CSV.
 * Version: 2.1.0
 * Author: Prospress Inc
 * Author URI: http://prospress.com
 * License: GPLv3
 *
 * WC requires at least: 3.0
 * WC tested up to: 3.5
 *
 * GitHub Plugin URI: Prospress/woocommerce-subscriptions-importer-exporter
 * GitHub Branch: master
 *
 * Copyright 2019 Prospress, Inc.  (email : freedoms@prospress.com)
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 * @package		WooCommerce Subscriptions Importer Exporter
 * @author		Prospress Inc.
 * @since		1.0
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

	public static $version = '2.0.1';

	protected static $required_subscriptions_version = '2.2.0';

	protected static $plugin_file = __FILE__;

	/**
	 * Initialise filters for the Subscriptions CSV Importer
	 *
	 * @since 1.0
	 */
	public static function init() {
		add_filter( 'plugins_loaded', __CLASS__ . '::setup_importer' );
		add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), __CLASS__ . '::action_links' );
		add_action( 'wcs_export_cron', array( 'WCS_Exporter_Cron', 'cron_handler' ), 10, 2 );
		add_action( 'wcs_export_scheduled_cleanup', array( 'WCS_Exporter_Cron', 'delete_export_file' ), 10, 1 );

		spl_autoload_register( __CLASS__ . '::autoload' );
	}

	/**
	 * Create an instance of the importer on admin pages and check for WooCommerce Subscriptions dependency.
	 *
	 * @since 1.0
	 */
	public static function setup_importer() {

		if ( is_admin() ) {
			if ( class_exists( 'WC_Subscriptions' ) && version_compare( WC_Subscriptions::$version, self::$required_subscriptions_version, '>=' ) ) {
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
					<p><?php printf( esc_html__( '%1$sWooCommerce Subscriptions CSV Importer and Exporter is inactive.%2$s The %3$sWooCommerce Subscriptions plugin%4$s must be active for WooCommerce Subscriptions CSV Importer and Exporter to work. Please %5$sinstall & activate%6$s WooCommerce Subscriptions.', 'wcs-import-export' ), '<strong>', '</strong>', '<a href="http://www.woocommerce.com/products/woocommerce-subscriptions/">', '</a>', '<a href="' . esc_url( admin_url( 'plugins.php' ) ) . '">', '</a>' ); ?></p>
				</div>
			<?php else : ?>
				<div id="message" class="error">
					<p><?php printf( esc_html__( '%1$sWooCommerce Subscriptions CSV Importer and Exporter is inactive.%2$s Both %3$sWooCommerce%4$s and %5$sWooCommerce Subscriptions%6$s plugins must be active for WooCommerce Subscriptions CSV Importer and Exporter to work. Please %7$sinstall & activate%8$s these plugins before continuing.', 'wcs-import-export' ), '<strong>', '</strong>', '<a href="http://wordpress.org/extend/plugins/woocommerce/">', '</a>', '<a href="http://www.woocommerce.com/products/woocommerce-subscriptions/">', '</a>', '<a href="' . esc_url( admin_url( 'plugins.php' ) ) . '">', '</a>' ); ?></p>
				</div>
			<?php endif;?>
		<?php elseif ( ! class_exists( 'WC_Subscriptions' ) || version_compare( WC_Subscriptions::$version, self::$required_subscriptions_version, '<' ) ) : ?>
			<div id="message" class="error">
				<p><?php printf( esc_html__( '%1$sWooCommerce Subscriptions CSV Importer and Exporter is inactive.%2$s The %3$sWooCommerce Subscriptions%4$s version %7$s (or greater) is required to safely run WooCommerce Subscriptions CSV Importer and Exporter. Please %5$supdate & activate%6$s WooCommerce Subscriptions.', 'wcs-import-export' ), '<strong>', '</strong>', '<a href="http://www.woocommerce.com/products/woocommerce-subscriptions/">', '</a>', '<a href="' . esc_url( admin_url( 'plugins.php' ) ) . '">', '&nbsp;&raquo;</a>', self::$required_subscriptions_version ); ?></p>
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
