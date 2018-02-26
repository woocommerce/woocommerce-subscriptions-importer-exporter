/* global wcsi_data, jQuery, document */
jQuery(document).ready(function ($) {
	var counter = 0,
		import_count = 0,
		warning_count = 0,
		error_count = 0,
		retry_count = 0,
		estimate,
		$wcsi_error = $('#wcsi-error'),
		$wcsi_time_completition = $('#wcsi-time-completion'),
		$wcsi_estimated_time = $('#wcsi-estimated-time'),
		$wcsi_completed_message = $('#wcsi-completed-message'),
		$importer_loading = $('.importer-loading'),
		$subsubsub = $('.subsubsub'),
		$wcsi_progress = $('#wcsi-progress'),
		$wcsi_all_tbody = $('#wcsi-all-tbody'),
		$wcsi_failed_tbody = $('#wcsi-failed-tbody'),
		$wcsi_warning_tbody = $('#wcsi-warning-tbody'),
		$wcsi_test_passed = $('#wcsi-test-passed'),
		$wcsi_test_failed = $('#wcsi-test-failed'),
		$wcsi_error_count = $('#wcsi-error-count'),
		$wcsi_warning_count = $('#wcsi-warning-count'),
		$wcsi_all_count = $('#wcsi-all-count'),
		$wcsi_failed_count = $('#wcsi-failed-count'),
		$wcsi_error_title = $('#wcsi-error-title'),
		$wcsi_warning_title = $('#wcsi-warning-title'),
		$wcsi_test_errors = $('#wcsi_test_errors'),
		$wcsi_test_warnings = $('#wcsi_test_warnings'),
		$wcsi_completed_percent = $('#wcsi-completed-percent'),
		ajax_import = function (start_pos, end_pos, row_start) {
			var data = {
				action:				'wcs_import_request',
				file_id:			wcsi_data.file_id,
				start:				start_pos,
				end:				end_pos,
				row_num:			row_start,
				test_mode:			wcsi_data.test_mode,
				email_customer:		wcsi_data.email_customer,
				add_memberships:	wcsi_data.add_memberships,
				wcsie_wpnonce:		wcsi_data.import_wpnonce
			};

			$.ajax({
				url: wcsi_data.ajax_url,
				type: 'POST',
				data: data,
				timeout: 360000,
				success: function (results) {
					var i,
						x,
						c,
						warnings = [],
						success  = 0,
						failed   = 0,
						critical = 0,
						minor    = 0,
						errors   = [],
						table_data,
						row_classes,
						warning_alternate,
						warning_string,
						error_string = '',
						append_text = '',
						append_warning_text = '',
						append_failed_text = '';

					// Reset the failed import retry count after a successful import
					retry_count = 0;

					if (wcsi_data.test_mode === 'false') {
						for (i = 0; i < results.length; i += 1) {
							table_data  = '';
							row_classes = (i % 2) ? '' : 'alternate';

							if (results[i].status === 'success') {
								warnings = results[i].warning;
								append_text += '<tr class="' + row_classes + '">';

								table_data += '<td class="row ' + ((warnings.length > 0) ? 'warning' : 'success') + '">' + wcsi_data.success + '</td>';
								table_data += '<td class="row">' + (results[i].subscription !== null  ? results[i].subscription : '-') + '</td>';
								table_data += '<td class="row">' + results[i].items + '</td>';
								table_data += '<td class="row">' + results[i].username + '</td>';
								table_data += '<td class="row column-status"><mark class="' + results[i].subscription_status + '">' + results[i].subscription_status + '</mark></td>';
								table_data += '<td class="row">' + warnings.length + '</td>';

								append_text += table_data;
								append_text += '</tr>';

								if (warnings.length > 0) {
									warning_alternate = (warning_count % 2) ? '' : 'alternate';
									warning_string = '<td class="warning" colspan="6">' + ((warnings.length > 1) ? wcsi_data.warnings : wcsi_data.warning) + ':';

									for (x = 0; x < warnings.length; x += 1) {
										warning_string += '<br>' + (x + 1) + '. ' + warnings[x];
									}
									warning_string += '</td>';

									append_text += '<tr class="' + row_classes + '">' + warning_string + '</tr>';
									append_warning_text += '<tr class="' + warning_alternate + '">' + table_data + '</tr><tr class="' + warning_alternate + '">' + warning_string + '</tr>';

									warning_count += 1;
								}
							} else {
								table_data += '<td class="row error-import">' + wcsi_data.failed + '</td>';
								if (typeof results[i].error !== 'undefined') {
									for (x = 0; x < results[i].error.length; x += 1) {
										error_string += '<br>' + (x + 1) + '. ' + results[i].error[x];
									}
								}

								table_data += '<td colspan="5">' + wcsi_data.error_string + '</td>';
								table_data = table_data.replace('{row_number}', results[i].row_number);
								table_data = table_data.replace('{error_messages}', error_string);

								append_text += '<tr class="' + row_classes + ' error-import">' + table_data + '</tr>';
								append_failed_text += '<tr class="' + row_classes + ' error-import">' + table_data + '</tr>';

								error_count += 1;
							}
						}

						// Add all the strings to the dom once instead of on every iteration
						$wcsi_all_tbody.append(append_text);
						$wcsi_warning_tbody.append(append_warning_text);

						if (append_failed_text.length) {
							$wcsi_failed_tbody.append(append_failed_text);
						}

						import_count += results.length;

						$wcsi_warning_count.html('(' + warning_count + ')');
						$wcsi_failed_count.html('(' + error_count + ')');
						$wcsi_all_count.html('(' + import_count + ')');
					} else {
						for (i = 0; i < results.length; i += 1) {
							if (results[i].error.length > 0) {
								failed += 1;
								for (c = 0; c < results[i].error.length; c += 1) {
									critical += 1;
									if (!(results[i].error[c] in errors)) {
										errors[results[i].error[c]] = [];
									}
									errors[results[i].error[c]].push(results[i].row_number);
								}
							} else {
								success += 1;
							}

							if (results[i].warning.length > 0) {
								for (c = 0; c < results[i].warning.length; c += 1) {
									minor += 1;
									if (!(results[i].warning[c] in warnings)) {
										warnings[results[i].warning[c]] = [];
									}
									warnings[results[i].warning[c]].push(results[i].row_number);
								}
							}
						}

						$wcsi_test_passed.html(parseInt($wcsi_test_passed.html()) + success);
						$wcsi_test_failed.html(parseInt($wcsi_test_failed.html()) + failed);

						$wcsi_error_count.html(parseInt($wcsi_error_count.html()) + critical);
						$wcsi_error_title.html(critical > 1 || critical === 0 ? wcsi_data.errors : wcsi_data.error);

						$wcsi_warning_count.html(parseInt($wcsi_warning_count.html()) + minor);
						$wcsi_warning_title.html(minor > 1 || minor === 0 ? wcsi_data.warnings : wcsi_data.warning);

						populate_test_errors(errors, wcsi_data);

						populate_test_warnings(warnings, wcsi_data);
					}

					counter += 2;

					if ((counter / 2) >= wcsi_data.total) {
						display_http_error_rows();
						if (wcsi_data.test_mode === 'false') {
							$importer_loading.addClass('finished').removeClass('importer-loading');
							$importer_loading.html('<td colspan="6" class="row">' + wcsi_data.finished_importing + '</td>');
						}
						$wcsi_completed_message.show();
						$wcsi_completed_percent.html('100%');
					} else {
						// calculate percentage completed
						$wcsi_completed_percent.html(((((counter / 2) * wcsi_data.rows_per_request) / (wcsi_data.total * wcsi_data.rows_per_request)) * 100).toFixed(0) + '%');
						ajax_import(wcsi_data.file_positions[counter], wcsi_data.file_positions[counter + 1], wcsi_data.start_row_num[counter / 2]);
					}
				},
				error: function (xmlhttprequest, textstatus) {

					// Log the full error details to the console
					console.log('Subscriptions CSV Import Error: xmlhttprequest = ', xmlhttprequest);

					var table_row_data,
						retry_wait_time     = 20000,
						// Retry for 5 minutes before finally calling it quits
						retry_attempts      = ( 300000 / retry_wait_time ),
						last_csv_row_number = wcsi_data.start_row_num[counter / 2];

					table_row_data  = '<tr class="error-import http-error-import">';
					table_row_data += '<td class="row warning error-import" colspan="6">';
					table_row_data += '<p><strong>HTTP error: <span class="http-error-code">' + xmlhttprequest.status + ' - ' + xmlhttprequest.statusText + '</span></strong></p>';
					table_row_data += '<p>Last import batch start at CSV row number: ' + last_csv_row_number + '. <strong>The <span class="rows-with-http-errors">' + wcsi_data.rows_per_request + ' rows after row ' + last_csv_row_number + '</span> will not be reattempted</strong>. Please manually verify if those subscriptions were imported.</p>';

					if ( retry_count >= retry_attempts ) {

						table_row_data += '<p><strong>Terminating import due to HTTP errors.</strong> Maximum retry attempts (' + retry_attempts + ') completed unsuccessfully.</p>';
						table_row_data += '</td>';
						table_row_data += '</tr>';

						$wcsi_all_tbody.append( table_row_data );

						display_http_error_rows();

						$importer_loading.addClass('finished').removeClass('importer-loading');

						$wcsi_error.show();
						$wcsi_completed_message.html($wcsi_error.html());
						$wcsi_completed_message.show();
						$wcsi_time_completition.hide();

						return;
					}

					table_row_data += '<p>Another import request will be attempted in ' + retry_wait_time / 1000 + ' seconds.</p>';
					table_row_data += '<p>' + retry_count + ' retry attempts completed already of the ' + retry_attempts + ' attempts that will be performed before terminating the import.</p>';
					table_row_data += '</td>';
					table_row_data += '</tr>';

					$wcsi_all_tbody.append( table_row_data );

					setTimeout( function() {

						counter += 2;

						retry_count++;

						table_row_data  = '<tr class="http-error-import-retry">';
						table_row_data += '<td class="row warning" colspan="6">Waited ' + retry_wait_time / 1000 + ' seconds, attempting import again starting at row number ' + wcsi_data.start_row_num[counter / 2] + '.</td>';
						table_row_data += '</tr>';

						$wcsi_all_tbody.append( table_row_data );

						ajax_import( wcsi_data.file_positions[counter], wcsi_data.file_positions[counter + 1], wcsi_data.start_row_num[counter / 2] );
					}, retry_wait_time );
				}
			});
		},
		display_http_error_rows = function () {
			var table_row_data,
				http_error_count = $('.rows-with-http-errors').length;

			if ( http_error_count < 1 ) {
				return;
			}

			table_row_data  = '<tr class="error-import http-error-import">';
			table_row_data += '<td class="row warning error-import" colspan="6"><strong>HTTP errors interrupted ' + http_error_count + ' of the attempts to import. Please manually confirm successful import of the following rows of the CSV file:</strong>.';
			table_row_data += '<ol>';

			$('.http-error-import').each(function() {
				table_row_data += '<li>' + $('.rows-with-http-errors',this).text() + ' (Error code ' + $('.http-error-code',this).text() + ')</li>';
			});

			table_row_data += '</ol>';
			table_row_data += '</td>';
			table_row_data += '</tr>';

			$wcsi_all_tbody.append( table_row_data );
		},
		populate_test_errors = function (errors, wcsi_data) {
			var results_text = '',
				key;
			for (key in errors) {
				if (errors.hasOwnProperty(key)) {
					results_text += '[' + errors[key].length + '] ' + key + ' ' + wcsi_data.located_at + ': ' + errors[key].toString() + '.<br/>';
				}
			}
			$wcsi_test_errors.append(results_text);
		},
		populate_test_warnings = function (warnings, wcsi_data) {
			var results_text = '',
				key;
			for (key in warnings) {
				if (warnings.hasOwnProperty(key)) {
					results_text += '[' + warnings[key].length + '] ' + key + ' ' + wcsi_data.located_at + ': ' + warnings[key].toString() + '.<br/>';
				}
			}

			$wcsi_test_warnings.append(results_text);
		};

	if (wcsi_data.test_mode === 'false') {
		// calculate an estimate to give the admins a rough idea of a completion time ( 0.33 is based on averages from own tests + 50% )
		estimate = ((0.33 * parseInt(wcsi_data.rows_per_request) * parseInt(wcsi_data.total)) / 60).toFixed(0);
		$wcsi_estimated_time.html(estimate + ' to ' + ((estimate === 0) ? 1 : (estimate * 1.5).toFixed(0)));
	}
	ajax_import(wcsi_data.file_positions[counter], wcsi_data.file_positions[counter + 1], wcsi_data.start_row_num[counter / 2]);

	$subsubsub.on('click', 'a', function (e) {
		e.preventDefault();
		var id = $(this).parent('li').attr('data-value');

		$wcsi_progress.find('tbody').hide();
		$wcsi_progress.find('#wcsi-' + id + '-tbody').show();
	});
});
