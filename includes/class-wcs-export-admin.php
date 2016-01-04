<?php
/**
 * Admin section for the WooCommerce Subscriptions exporter
 *
 * @since 1.0
 */
class WCS_Export_Admin {

	public $exporter_setup = array();
	public $action        = '';
	public $error_message = '';

	private $exporter = null;

	/**
	 * Initialise all admin hooks and filters for the subscriptions exporter
	 *
	 * @since 1.0
	 */
	public function __construct() {
		add_action( 'admin_menu', array( &$this, 'add_sub_menu' ), 10 );

		add_action( 'admin_enqueue_scripts', array( &$this, 'enqueue_scripts' ) );

		$this->action = admin_url( 'admin.php?page=export_subscriptions' );
	}

	/**
	 * Adds the Subscriptions exporter under Tools
	 *
	 * @since 1.0
	 */
	public function add_sub_menu() {
		add_submenu_page( 'woocommerce', __( 'Subscription Exporter', 'wcs-importer' ),  __( 'Subscription Exporter', 'wcs-importer' ), 'manage_options', 'export_subscriptions', array( &$this, 'export_page' ) );
	}

	/**
	 * Load exporter scripts
	 *
	 * @since 1.0
	 */
	public function enqueue_scripts() {
		wp_enqueue_script( 'wcs-exporter-admin', plugin_dir_url( WCS_Importer::$plugin_file ) . '/assets/js/wcs-exporter.js' );
	}

}
