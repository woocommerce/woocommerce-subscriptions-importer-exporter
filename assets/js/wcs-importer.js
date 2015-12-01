jQuery(document).ready(function($){

	var counter = 0, import_count = 0, warning_count = 0, error_count = 0;

	if ( wcsi_data.test_mode == 'false' ) {
		// calculate an estimate to give the admins a rough idea of a completion time ( 0.33 is based on averages from own tests + 50% )
		var estimate = ( ( 0.33 * parseInt( wcsi_data.rows_per_request ) * parseInt( wcsi_data.total ) ) / 60 ).toFixed(0);

		$('#wcs-estimated-time').html( estimate + ' to ' + ( ( estimate == 0 ) ? 1 : ( estimate * 1.5).toFixed(0) ) );
		ajax_import( wcsi_data.file_positions[counter], wcsi_data.file_positions[counter+1], wcsi_data.start_row_num[counter/2] );
	} else {
		ajax_import( wcsi_data.file_positions[counter], wcsi_data.file_positions[counter+1], wcsi_data.start_row_num[counter/2] );
	}

	function ajax_import( start_pos, end_pos, row_start ) {
		var data = {
			action:           'wcs_import_request',
			file_id:          wcsi_data.file_id,
			start:            start_pos,
			end:              end_pos,
			row_num:          row_start,
			test_mode:        wcsi_data.test_mode,
			email_customer:   wcsi_data.email_customer,
			add_memberships:  wcsi_data.add_memberships,
		}

		$.ajax({
			url: wcsi_data.ajax_url,
			type: 'POST',
			data: data,
			timeout: 360000,
			success: function( results ) {

				if( wcsi_data.test_mode == "false" ) {

					for( var i = 0; i < results.length; i++ ){
						var table_data  = '',
							row_classes = ( i % 2 ) ? '' : 'alternate';

						if( results[i].status == "success" ) {
							var warnings = results[i].warning;

							table_data += '<td class="row ' + (( warnings.length > 0 ) ? 'warning' : 'success') + '">' + wcsi_data.success +'</td>';
							table_data += '<td class="row">' + ( results[i].subscription != null  ? results[i].subscription : '-' ) + '</td>';
							table_data += '<td class="row">' + results[i].item + '</td><td class="row">' + results[i].username + '</td>';
							table_data += '<td class="row column-status"><mark class="' + results[i].subscription_status + '">' + results[i].subscription_status + '</mark></td>';
							table_data += '<td class="row">' + warnings.length + '</td>';

							$('#wcsi-all-tbody').append( '<tr class="' + row_classes + '">' + table_data + '</tr>' );

							if( warnings.length > 0 ) {
								var warning_alternate = ( warning_count % 2 ) ? '' : 'alternate';

								$('#wcsi-warning-tbody').append( '<tr class="' + warning_alternate + '">' + table_data + '</tr>' );

								var warningString = '<td class="warning" colspan="6">' + (( warnings.length > 1 ) ? wcsi_data.warnings : wcsi_data.warning) + ':';

								for( var x = 0; x < warnings.length; x++ ) {
									warningString += '<br>' + (x+1) + '. ' + warnings[x];
								}

								$('#wcsi-all-tbody').append( '<tr class="' + row_classes + '">' + warningString + '</td></tr>');
								$('#wcsi-warning-tbody').append( '<tr class="' + warning_alternate + '">' + warningString + '</td></tr>');

								warning_count++;
							}
						} else {
							table_data += '<td class="row error-import">' + wcsi_data.failed + '</td>';

							var errorString = '';

							for( var x = 0; x < results[i].error.length; x++ ){
								errorString += '<br>' + (x+1) + '. ' + results[i].error[x];
							}

							table_data += '<td colspan="5">' + wcsi_data.error_string + '</td>';
							table_data = table_data.replace( "{row_number}", results[i].row_number );
							table_data = table_data.replace( "{error_messages}", errorString );

							$('<tr class="' + row_classes + ' error-import">' + table_data + '</tr>').appendTo('#wcsi-all-tbody, #wcsi-failed-tbody');
							error_count++;
						}
					}

					import_count += results.length;

					$('#wcsi-warning-count').html( '(' + warning_count + ')' );
					$('#wcsi-failed-count').html( '(' + error_count + ')' );
					$('#wcsi-all-count').html( '(' + import_count + ')' );

				} else {
					var success = 0,
						failed = 0,
						critical = 0,
						minor = 0,
						tests = 0;

					var errors = [],
						warnings = [];

					for( var i = 0; i < results.length; i++ ) {
						tests++;

						if( results[i].error.length > 0 ) {
							failed++;
							for( var c = 0; c < results[i].error.length; c++ ) {
								critical++;
								if ( !( results[i].error[c] in errors ) ) {
									errors[results[i].error[c]] = [];
								}
								errors[results[i].error[c]].push( results[i].row_number );
							}
						} else {
							success++;
						}

						if( results[i].warning.length > 0 ) {
							for( var c = 0; c < results[i].warning.length; c++ ) {
								minor++;
								if( !(results[i].warning[c] in warnings ) ) {
									warnings[results[i].warning[c]] = [];
								}
								warnings[results[i].warning[c]].push( results[i].row_number );
							}
						}
					}

					$('#wcs-test-passed').html( parseInt( $('#wcs-test-passed').html() ) + success );
					$('#wcs-test-failed').html( parseInt( $('#wcs-test-failed').html() ) + failed );
					$('#wcs-fatal-details').html( parseInt( $('#wcs-fatal-details').html() ) + critical );
					$('#wcs-warning-details').html( parseInt( $('#wcs-warning-details').html() ) + minor );

					var results_text = '';
					for( key in errors ) {
						results_text += '[' + errors[key].length + '] ' + key + ' ' + wcsi_data.located_at + ': ' + errors[key].toString() + '.<br/>';
					}
					$('#wcsi_test_errors_details').append( results_text );

					results_text = '';
					for( warningKey in warnings ) {
						results_text += '[' + warnings[warningKey].length + '] ' + warningKey + ' ' + wcsi_data.located_at + ': ' + warnings[warningKey].toString() + '.<br/>';
					}

					$('#wcsi_test_warnings_details').append( results_text );
				}

				counter += 2;

				if( ( counter / 2 ) >= wcsi_data.total ) {
					if( wcsi_data.test_mode == "false" ) {
						$('.importer-loading').addClass('finished').removeClass('importer-loading');
						$('.finished').html('<td colspan="6" class="row">' + wcsi_data.finished_importing + '</td>');
					}
					$('#wcs-completed-message').show();
					$('#wcs-completed-percent').html( '100%' );
				} else {
					// calculate percentage completed
					$('#wcs-completed-percent').html( ( ( ( ( counter/2 ) * wcsi_data.rows_per_request ) / ( wcsi_data.total * wcsi_data.rows_per_request ) ) * 100).toFixed(0) + '%' );
					ajax_import( wcsi_data.file_positions[counter], wcsi_data.file_positions[counter+1], wcsi_data.start_row_num[counter/2] );
				}
			},
			error: function(xmlhttprequest, textstatus, message) {

				$('.importer-loading').addClass('finished').removeClass('importer-loading');
				if( textstatus === "timeout" ) {
					$('#wcsi-timeout').show();
					$('#wcs-completed-message').html( $('#wcsi-timeout').html() );
					$('#wcs-completed-message').show();
					$('#wcsi-time-completion').hide();
				}
			}

		});
	}

	$('.wcsi-status-li a').click( function(e) {
		e.preventDefault()
		var id = $(this).parent('li').attr('data-value');

		$('#wcsi-progress tbody').hide();
		$('#wcsi-' + id + '-tbody').show();
	});

});
