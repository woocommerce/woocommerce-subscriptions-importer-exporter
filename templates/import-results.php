<?php
/**
 * Template file for displaying import results.
 *
 * @since 1.0
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<h3><?php esc_html_e( 'Importing Results', 'wcs-importer' ); ?></h3>

<p id="wcsi-timeout" style="display: none;">
	<?php echo wp_kses( sprintf( __( 'Error: The importing process has timed out. Please check the CSV is correct and do a test run before importing by enabling the checkbox on the Importer Home screen. %s Start Over. %s', 'wcs-importer' ), '<a href="' . $this->admin_url . '">', '</a>' ), array( 'a' => array( 'href' => true ) ) ); ?>
</p>
<p id="wcsi-time-completion">
	<?php echo wp_kses( sprintf( __( 'Total Estimated Import Time Between: %1$s 0%2$s minutes. ( %3$s0%4$s Completed! )', 'wcs-importer' ), '<span id="wcsi-estimated-time">', '</span>', '<span id="wcsi-completed-percent">', '</span>' ), array( 'span' => array( 'id' => true ) ) ); ?>
</p>
<br class="clear">
<ul class="subsubsub">
	<li class="wcsi-status-li" data-value="all"><a href="#"><?php esc_html_e( 'All', 'wcs-importer' ); ?></a><span id="wcsi-all-count">(0)</span></li>
	<li class="wcsi-status-li" data-value="warning"> | <a href="#"><?php esc_html_e( 'Warnings', 'wcs-importer' ); ?></a><span id="wcsi-warning-count">(0)</span></li>
	<li class="wcsi-status-li" data-value="failed"> | <a href="#"><?php esc_html_e( 'Failed', 'wcs-importer' ); ?></a><span id="wcsi-failed-count">(0)</span></li>
</ul>
<table id="wcsi-progress" class="widefat_importer widefat">
	<thead>
		<tr>
			<th class="row"><?php esc_html_e( 'Import Status', 'wcs-importer' ); ?></th>
			<th class="row"><?php esc_html_e( 'Subscription', 'wcs-importer' ); ?></th>
			<th class="row"><?php esc_html_e( 'Item', 'wcs-importer' ); ?></th>
			<th class="row"><?php esc_html_e( 'Customer', 'wcs-importer' ); ?></th>
			<th class="row"><?php esc_html_e( 'Subscription Status', 'wcs-importer' ); ?></th>
			<th class="row"><?php esc_html_e( 'Number of Warnings', 'wcs-importer' ); ?></th>
		</tr>
	</thead>
	<tfoot>
		<tr class="importer-loading">
			<td colspan="6"></td>
		</tr>
	</tfoot>
	<tbody id="wcsi-all-tbody"></tbody>
	<tbody id="wcsi-warning-tbody" style="display: none;"></tbody>
	<tbody id="wcsi-failed-tbody" style="display: none;"></tbody>
</table>
<p id="wcsi-completed-message" style="display: none;">
	<?php echo wp_kses( sprintf( __( 'Import Complete! %1$sView Subscriptions%2$s or %3$sImport another file%4$s.', 'wcs-importer' ), '<a href="' . esc_url( admin_url( 'edit.php?post_type=shop_subscription' ) ) . '">', '</a>', '<a href="' . esc_url( $this->admin_url ) . '">', '</a>' ), array( 'a' => array( 'href' => true ) ) ); ?>
</p>
