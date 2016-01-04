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

	/**
	 * Export Home page
	 *
	 * @since 1.0
	 */
	public function export_page() {
		echo '<div class="wrap woocommerce">';
		echo '<h2>' . __( 'Subscription CSV Exporter', 'wcs-importer' ) . '</h2>';
		echo '<h2 class="nav-tab-wrapper woo-nav-tab-wrapper">';

		$tabs = array(
			'wcsi-export'  => __( 'Export', 'wcs-importer' ),
			'wcsi-headers' => __( 'CSV Headers', 'wcs-importer' ),
		);

		$current_tab = ( empty( $_GET[ 'tab' ] ) ) ? 'wcsi-export' : urldecode( $_GET[ 'tab' ] );

		foreach ( $tabs as $tab_id => $tab_title ) {

			$class = ( $tab_id == $current_tab ) ? array( 'nav-tab', 'nav-tab-active', 'wcsi-exporter-tabs' ) : array( 'nav-tab', 'wcsi-exporter-tabs' );

			echo '<a href="#" id="' . $tab_id . '" class="' . implode( ' ', array_map( 'sanitize_html_class', $class ) ) . '">' . esc_html( $tab_title ) . '</a>';

		}

		echo '</h2>';
		echo '<form class="wcsi-exporter-form" method="POST" action="' . esc_attr( add_query_arg( 'step', 'download' ) ) . '">';
		$this->home_page();
		echo '<p class="submit">';
		echo '<input type="submit" class="button" value="' . esc_html__( 'Export Subscriptions', 'wcs-importer' ) . '" />';
		echo '</p>';
		echo '</form>';
	}

}
