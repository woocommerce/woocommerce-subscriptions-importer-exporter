jQuery(document).ready(function($){
	var counter = 0;

	if ( wcs_script_data.test_mode == 'false' && wcs_script_data.error_message.length > 0 ) {
		if ( confirm( wcs_script_data.error_message ) ){
			ajax_import( wcs_script_data.file_positions[counter], wcs_script_data.file_positions[counter+1], wcs_script_data.start_row_num[counter/2] );
		} else {
			window.location.href = wcs_script_data.cancelled_url;
		}
	} else {
		ajax_import( wcs_script_data.file_positions[counter], wcs_script_data.file_positions[counter+1], wcs_script_data.start_row_num[counter/2] );
	}

	function ajax_import( start_pos, end_pos, row_start ) {
		var data = {
			action:		'wcs_import_request',
			file_id:	wcs_script_data.file_id,
			start:		start_pos,
			end:		end_pos,
			row_num:	row_start,
			test_mode:	wcs_script_data.test_mode,
			email_customer:	wcs_script_data.email_customer,
		}
		$.ajax({
			url:	wcs_script_data.ajax_url,
			type:	'POST',
			data:	data,
			success: function( results ) {
				// Update confirmation table
				// Get the valid JSON only from the returned string
				if( wcs_script_data.test_mode == "false" ) {
					// Parse
					for( var i = 0; i < results.length; i++ ){
						var table_data = '',
							row_classes = ( i % 2 ) ? '' : 'alternate';
						if( results[i].status == "success" ) {
							var warnings = results[i].warning;
							if( warnings.length > 0 ) {
								table_data += '<td class="row warning" rowspan="2"><strong>' + wcs_script_data.success +' ( !! )</strong></td>';
								table_data += '<td class="row">' + ( results[i].order != null  ? results[i].order : '-' ) + '</td>';
							} else {
								table_data += '<td class="row success">' + wcs_script_data.success +'</td>';
								table_data += '<td class="row">' + ( results[i].order != null  ? results[i].order : '-' ) + '</td>';
							}

							table_data += '<td class="row">' + results[i].item + ' ( #' + results[i].item_id + ' )</td>';
							table_data += '<td class="row">' + results[i].username + ' ( #' + results[i].user_id + ' )</td>';
							table_data += '<td class="row column-status"><mark class="' + results[i].subscription_status + '">' + results[i].subscription_status + '</mark></td>';
							table_data += '<td class="row">' + warnings.length + '</td>';

							$('#wcs-import-progress tbody').append( '<tr class="' + row_classes + '">' + table_data + '</tr>' );

							// Display Warnings
							if( warnings.length > 0 ) {
								var warningString = ( ( warnings.length > 1 ) ? wcs_script_data.warnings : wcs_script_data.warning);
								warningString.replace( warningString, ( ( warnings.length > 1 ) ? wcs_script_data.warnings : wcs_script_data.warning ) );
								warningString += ': ';
								for( var x = 0; x < warnings.length; x++ ) {
									warningString += warnings[x];
								}
								$('#wcs-import-progress tbody').append( '<tr class="' + row_classes + '"><td colspan="5">' + warningString + ' [ <a href="' + results[i].edit_order + '">' + wcs_script_data.edit_order + ' #' + results[i].order +'</a> ]</td></tr>');
							}
						} else {
							table_data += '<td class="row error-import">' + wcs_script_data.failed + '</td>';
							// Display Error
							var errorString = '<ul>';
							for( var x = 0; x < results[i].error.length; x++ ){
								errorString += '<li>' + results[i].error[x] + '</li>';
							}
							errorString += '</ul>';
							table_data += '<td colspan="5">' + wcs_script_data.error_string + '</td>';
							table_data = table_data.replace( "{row_number}", results[i].row_number );
							table_data = table_data.replace( "{error_messages}", errorString );
							$('#wcs-import-progress tbody').append( '<tr class="' + row_classes + '">' + table_data + '</tr>' );
						}
					}
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
					$('#wcs-test-ratio').html( parseInt( success/tests * 100, 10 ) );
					$('#wcs-fatal-details').html( parseInt( $('#wcs-fatal-details').html() ) + critical );
					$('#wcs-warning-details').html( parseInt( $('#wcs-warning-details').html() ) + minor );
					var results_text = '';
					for( key in errors ) {
						results_text += '[' + errors[key].length + '] ' + key + ' ' + wcs_script_data.located_at+ ': ' + errors[key].toString() + '.<br/>';
					}
					$('#wcs-importer_test_errors td').append( results_text );

					results_text = ''; //clear string so the same variable can be used for warnings and errors
					for( warningKey in warnings ) {
						results_text += '[' + warnings[warningKey].length + '] ' + warningKey + ' ' + wcs_script_data.located_at + ': ' + warnings[warningKey].toString() + '.<br/>';
					}
					$('#wcs-importer_test_warnings td').append( results_text );

				}

				counter += 2;

				if ( ! check_completed() ) {
					ajax_import( wcs_script_data.file_positions[counter], wcs_script_data.file_positions[counter+1], wcs_script_data.start_row_num[counter/2] );
				}

			}
		});
	}

	/* Check the number of requests has been completed */
	function check_completed() {
		if( ( counter / 2 ) >= wcs_script_data.total ) {
			if( wcs_script_data.test_mode == "false" ) {
				$('.importer-loading').addClass('finished').removeClass('importer-loading');
				$('.finished').html('<td colspan="6" class="row">' + wcs_script_data.finished_importing + '</td>');
			}
			$('#wcs-completed-message').show();
			return true;
		}

		return false;
	}

});
