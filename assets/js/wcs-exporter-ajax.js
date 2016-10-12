jQuery(document).ready(function($){

	var data = {
		action:			'wcs_export_request',
		file_name:		wcs_export_data.file_name,
		customer:		end_pos,
		payment_method:	start_pos,
		payment_tokens:	start_pos,
		statuses:		row_start,
		csv_headers:	wcs_script_data.test_mode,
		limit:			wcs_script_data
	}

	$.ajax({
			url:	wcs_script_data.ajax_url,
			type:	'POST',
			data:	data,
			timeout: 360000, // 6 minute timeout should be enough
			success: function( results ) {

			}

	});

});