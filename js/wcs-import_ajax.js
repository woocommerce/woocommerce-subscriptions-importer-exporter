jQuery(document).ready(function($){
	var count = 0;
	var mapping;
	var ajax_url;
	var total;
	var csv_delimiter;
	var file_name;

	$( 'body' ).on( 'import-start', function( event, import_data ) {
		var positions = import_data.file_positions;
		var starting_row_number = import_data.start_row_num;
		total = import_data.total;
		mapping = import_data.mapping;
		ajax_url = import_data.ajax_url;
		csv_delimiter = import_data.delimiter;
		file_name = import_data.file;

		for( var i = 0; i < positions.length; i+=2 ) {
			ajax_import( positions[i], positions[i+1], starting_row_number[i/2] );
		}
	});

	function ajax_import( start_pos, end_pos, row_start ) {
		var data = {
			action:		'wcs_import_request',
			mapping:	mapping,
			delimiter:	csv_delimiter,
			file:		file_name,
			start:		start_pos,
			end:		end_pos,
			row_num:	row_start,
		}

		$.ajax({
			url:	ajax_url,
			type:	'POST',
			data:	data,
			success: function( response ) {
				count++;
				// Update confirmation table
				// Get the valid JSON only from the returned string
				if ( response.indexOf("<!--WC_START-->") >= 0 ) {
					response = response.split("<!--WC_START-->")[1]; // Strip off before after WC_START
				}
				if ( response.indexOf("<!--WC_END-->") >= 0 ) {
					response = response.split("<!--WC_END-->")[0]; // Strip off anything after WC_END
				}
				// Parse
				var results = $.parseJSON( response );
				console.log(results);
				for( var i = 0; i < results.length; i++ ){
					var table_data = '',
						row_classes = ( i % 2 ) ? '' : 'alternate';
					if( results[i].status == "success" ) {
						var warnings = results[i].warning;
						if( warnings.length > 0 ) {
							table_data += '<td class="row warning" rowspan="2"><strong>{success} ( !! )</strong></td>';
							table_data += '<td class="row">' + ( results[i].order != null  ? results[i].order : '-' ) + '</td>';
						} else {
							table_data += '<td class="row success">{success}</td>';
							table_data += '<td class="row">' + ( results[i].order != null  ? results[i].order : '-' ) + '</td>';
						}
					
						table_data += '<td class="row">' + results[i].item + ' ( #' + results[i].item_id + ' )</td>';
						table_data += '<td class="row">' + results[i].username + ' ( #' + results[i].user_id + ' )</td>';
						table_data += '<td class="row column-status"><mark class="' + results[i].subscription_status + '">' + results[i].subscription_status + '</mark></td>';
						table_data += '<td class="row">' + warnings.length + '</td>';

						$('#wcs-import-progress tbody').append( '<tr class="' + row_classes + '">' + table_data + '</tr>' );

						// Display Warnings
						if( warnings.length > 0 ) {
							var warningString = ( ( warnings.length > 1 ) ? '{Warnings}' : '{Warning}');
							warningString += ': ';
							for( var x = 0; x < warnings.length; x++ ) {
								warningString += warnings[x];
							}
							$('#wcs-import-progress tbody').append( '<tr class="' + row_classes + '"><td colspan="5">' + warningString + ' [ <a href="' + results[i].edit_order + '">{edit-order} #' + results[i].order +'</a> ]</td></tr>');
						}
					} else {
						table_data += '<td class="row error-import">{failed}</td>';

						// Display Error
						var errorString = '';
						for( var x = 0; x < results[i].error.length; x++ ){
							errorString += x+1 + '. ' + results[i].error[x] + ' ';
						}
						//?php $error_string = sprintf( __( "Row #%s from CSV %sfailed to import%s with error/s: %s", 'wcs-importer' ), '{row_number}', '<strong>', '</strong>', '{error_messages}' ); 
						table_data += '<td colspan="5">{error_string}</td>';
						table_data = table_data.replace( "{row_number}", results[i].row_number );
						table_data = table_data.replace( "{error_messages}", errorString );
						$('#wcs-import-progress tbody').append( '<tr class="' + row_classes + '">' + table_data + '</tr>' );
					}
				}
				check_completed();
			}
		});
	}

	/* Check the number of requests has been completed */
	function check_completed() {
		if( count >= total ) {
			$('.importer-loading').addClass('finished').removeClass('importer-loading');
			$('.finished').html('<td colspan="6" class="row">{finished-importing}</td>');

			$('.wrap').append('<p>{completed-message}</p>');
		}
	}

});