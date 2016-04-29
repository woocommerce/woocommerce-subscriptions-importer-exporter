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

<h3><?php esc_html_e( 'Test Run Results', 'wcs-import-export' ); ?></h3>
<table id="wcsi-import-progress" class="widefat_importer widefat">
	<thead>
		<tr>
			<th class="row" colspan="2"><?php esc_html_e( 'Importer Test Results', 'wcs-import-export' ); ?></th>
		</tr>
	</thead>
	<tbody>
		<tr class="alternate">
			<th width="20"><strong><?php esc_html_e( 'Results', 'wcs-import-export' ); ?></strong></th>
			<td id="wcsi-importer_test_results"><strong><?php echo wp_kses( sprintf( __( '%1$s0%2$s tests passed, %3$s0%4$s tests failed.', 'wcs-import-export' ), '<span id="wcsi-test-passed">', '</span>', '<span id="wcsi-test-failed">', '</span>' ), array( 'span' => array( 'id' => true ) ) ); ?>	</strong></td>
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
<div id="wcsi-completed-message" style="display: none;">
	<p><?php esc_html_e( 'Test Finished!', 'wcs-import-export' );?></p>
	<a class="button" href="<?php echo esc_attr( wp_nonce_url( $action, 'import-upload' ) ); ?> "><?php esc_html_e( 'Run Import' , 'wcs-import-export' ); ?></a>
</div>
