<?php
/**
 * Template for the test-mode table.
 *
 * @since 1.0
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>

<h3><?php esc_html_e( 'Test Run Results', 'wcs-importer' ); ?></h3>
<table id="wcs-import-progress" class="widefat_importer widefat">
	<thead>
		<tr>
			<th class="row" colspan="2"><?php esc_html_e( 'Importer Test Results', 'wcs-importer' ); ?></th>
		</tr>
	</thead>
	<tbody>
		<tr class="alternate">
			<th width="20"><strong><?php esc_html_e( 'Results', 'wcs-importer' ); ?></strong></th>
			<td id="wcs-importer_test_results"><strong><?php echo sprintf( __( '%s0%s tests passed, %s0%s tests failed.', 'wcs-importer' ), '<span id="wcs-test-passed">', '</span>', '<span id="wcs-test-failed">', '</span>' ); ?></strong></td>
		</tr>
		<tr>
			<th colspan="2"><span id="wcsi-error-count">0</span> <span id="wcsi-error-title"></span>:</th>
		</tr>
		<tr><td></td><td id="wcsi_test_errors"></td></tr>
		<tr class="alternate">
			<th colspan="2"><span id="wcsi-warning-count">0</span> <span id="wcsi-warning-title"></span>:</th>
		</tr>
		<tr class="alternate"><td></td><td id="wcsi_test_warnings"></td></tr>
	</tbody>
</table>
<div id="wcs-completed-message" style="display: none;">
	<p><?php esc_html_e( 'Test Finished!', 'wcs-importer' );?></p>
	<a class="button" href="<?php echo esc_attr( wp_nonce_url( $action, 'import-upload' ) ); ?> "><?php esc_html_e( 'Run Import' , 'wcs-importer' ); ?></a>
</div>