/* global wcsi_data, jQuery, document */
jQuery(document).ready(function ($) {
    var counter = 0,
        import_count = 0,
        warning_count = 0,
        error_count = 0,
        estimate,
        $wcsi_timeout = $('#wcsi-timeout'),
        $wcsi_completed_message = $('#wcsi-completed-message'),
        ajax_import = function (start_pos, end_pos, row_start) {
            var data = {
                action:           'wcs_import_request',
                file_id:          wcsi_data.file_id,
                start:            start_pos,
                end:              end_pos,
                row_num:          row_start,
                test_mode:        wcsi_data.test_mode,
                email_customer:   wcsi_data.email_customer,
                add_memberships:  wcsi_data.add_memberships
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
                        key,
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
                        warning_key,
                        error_string = '',
                        results_text,
                        $wcsi_all_tbody = $('#wcsi-all-tbody'),
                        $wcsi_warning_tbody = $('#wcsi-warning-tbody'),
                        $wcsi_test_passed = $('#wcsi-test-passed'),
                        $wcsi_test_failed = $('#wcsi-test-failed'),
                        $wcsi_error_count = $('#wcsi-error-count'),
                        $wcsi_warning_count = $('#wcsi-warning-count'),
                        $wcsi_completed_percent = $('#wcsi-completed-percent');

                    if (wcsi_data.test_mode === 'false') {
                        for (i = 0; i < results.length; i += 1) {
                            table_data  = '';
                            row_classes = (i % 2) ? '' : 'alternate';

                            if (results[i].status === 'success') {
                                warnings = results[i].warning;

                                table_data += '<td class="row ' + ((warnings.length > 0) ? 'warning' : 'success') + '">' + wcsi_data.success + '</td>';
                                table_data += '<td class="row">' + (results[i].subscription !== null  ? results[i].subscription : '-') + '</td>';
                                table_data += '<td class="row">' + results[i].item + '</td>';
                                table_data += '<td class="row">' + results[i].username + '</td>';
                                table_data += '<td class="row column-status"><mark class="' + results[i].subscription_status + '">' + results[i].subscription_status + '</mark></td>';
                                table_data += '<td class="row">' + warnings.length + '</td>';

                                $wcsi_all_tbody.append('<tr class="' + row_classes + '">' + table_data + '</tr>');

                                if (warnings.length > 0) {
                                    warning_alternate = (warning_count % 2) ? '' : 'alternate';
                                    warning_string = '<td class="warning" colspan="6">' + ((warnings.length > 1) ? wcsi_data.warnings : wcsi_data.warning) + ':';

                                    $wcsi_warning_tbody.append('<tr class="' + warning_alternate + '">' + table_data + '</tr>');

                                    for (x = 0; x < warnings.length; x += 1) {
                                        warning_string += '<br>' + (x + 1) + '. ' + warnings[x];
                                    }

                                    $wcsi_all_tbody.append('<tr class="' + row_classes + '">' + warning_string + '</td></tr>');
                                    $wcsi_warning_tbody.append('<tr class="' + warning_alternate + '">' + warning_string + '</td></tr>');

                                    warning_count += 1;
                                }
                            } else {
                                table_data += '<td class="row error-import">' + wcsi_data.failed + '</td>';
                                for (x = 0; x < results[i].error.length; x += 1) {
                                    error_string += '<br>' + (x + 1) + '. ' + results[i].error[x];
                                }

                                table_data += '<td colspan="5">' + wcsi_data.error_string + '</td>';
                                table_data = table_data.replace('{row_number}', results[i].row_number);
                                table_data = table_data.replace('{error_messages}', error_string);

                                $('<tr class="' + row_classes + ' error-import">' + table_data + '</tr>').appendTo('#wcsi-all-tbody, #wcsi-failed-tbody');
                                error_count += 1;
                            }
                        }

                        import_count += results.length;

                        $wcsi_warning_count.html('(' + warning_count + ')');
                        $('#wcsi-failed-count').html('(' + error_count + ')');
                        $('#wcsi-all-count').html('(' + import_count + ')');
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
                        $('#wcsi-error-title').html(critical > 1 || critical === 0 ? wcsi_data.errors : wcsi_data.error);

                        $wcsi_warning_count.html(parseInt($wcsi_warning_count.html()) + minor);
                        $('#wcsi-warning-title').html(minor > 1 || minor === 0 ? wcsi_data.warnings : wcsi_data.warning);

                        results_text = '';
                        for (key in errors) {
                            if (errors.hasOwnProperty(key)) {
                                results_text += '[' + errors[key].length + '] ' + key + ' ' + wcsi_data.located_at + ': ' + errors[key].toString() + '.<br/>';
                            }
                        }
                        $('#wcsi_test_errors').append(results_text);

                        results_text = '';
                        for (warning_key in warnings) {
                            if (warnings.hasOwnProperty(warning_key)) {
                                results_text += '[' + warnings[warning_key].length + '] ' + warning_key + ' ' + wcsi_data.located_at + ': ' + warnings[warning_key].toString() + '.<br/>';
                            }
                        }

                        $('#wcsi_test_warnings').append(results_text);
                    }

                    counter += 2;

                    if ((counter / 2) >= wcsi_data.total) {
                        if (wcsi_data.test_mode === 'false') {
                            $('.importer-loading').addClass('finished').removeClass('importer-loading');
                            $('.finished').html('<td colspan="6" class="row">' + wcsi_data.finished_importing + '</td>');
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
                    $('.importer-loading').addClass('finished').removeClass('importer-loading');
                    if (textstatus === 'timeout') {
                        $wcsi_timeout.show();
                        $wcsi_completed_message.html($wcsi_timeout.html());
                        $wcsi_completed_message.show();
                        $('#wcsi-time-completion').hide();
                    }
                }

            });
        };

    if (wcsi_data.test_mode === 'false') {
        // calculate an estimate to give the admins a rough idea of a completion time ( 0.33 is based on averages from own tests + 50% )
        estimate = ((0.33 * parseInt(wcsi_data.rows_per_request) * parseInt(wcsi_data.total)) / 60).toFixed(0);

        $('#wcsi-estimated-time').html(estimate + ' to ' + ((estimate === 0) ? 1 : (estimate * 1.5).toFixed(0)));
        ajax_import(wcsi_data.file_positions[counter], wcsi_data.file_positions[counter + 1], wcsi_data.start_row_num[counter / 2]);
    } else {
        ajax_import(wcsi_data.file_positions[counter], wcsi_data.file_positions[counter + 1], wcsi_data.start_row_num[counter / 2]);
    }

    $('.wcsi-status-li a').click(function (e) {
        e.preventDefault();
        var id = $(this).parent('li').attr('data-value');

        $('#wcsi-progress tbody').hide();
        $('#wcsi-' + id + '-tbody').show();
    });
});
