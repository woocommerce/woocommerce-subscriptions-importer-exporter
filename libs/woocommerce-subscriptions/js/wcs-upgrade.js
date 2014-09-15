jQuery(document).ready(function($){

	$('#update-messages').slideUp();
	$('#upgrade-step-3').slideUp();

	$('form#subscriptions-upgrade').on('submit',function(e){
		$('#update-welcome').slideUp(600);
		$('#update-messages').slideDown(600);
		if('true'==wcs_update_script_data.really_old_version){
			wcs_ajax_update_really_old_version();
		} else {
			wcs_ajax_update_products();
			wcs_ajax_update_hooks();
		}
		e.preventDefault();
	});
	function wcs_ajax_update_really_old_version(){
		$.ajax({
			url:	wcs_update_script_data.ajax_url,
			type:	'POST',
			data:	{
				action:			'wcs_upgrade',
				upgrade_step:	'really_old_version',
			},
			success: function(results) {
				$('#update-messages ol').append($('<li />').text(results.message));
				wcs_ajax_update_products();
				wcs_ajax_update_hooks();
			},
			error: function(results,status,errorThrown){
				wcs_ajax_update_error();
			}
		});
	}
	function wcs_ajax_update_products(){
		$.ajax({
			url:	wcs_update_script_data.ajax_url,
			type:	'POST',
			data:	{
				action:			'wcs_upgrade',
				upgrade_step:	'products',
			},
			success: function(results) {
				$('#update-messages ol').append($('<li />').text(results.message));
			},
			error: function(results,status,errorThrown){
				wcs_ajax_update_error();
			}
		});
	}
	function wcs_ajax_update_hooks() {
		var start_time = new Date();
		$.ajax({
			url:	wcs_update_script_data.ajax_url,
			type:	'POST',
			data:	{
				action:			'wcs_upgrade',
				upgrade_step:	'hooks',
			},
			success: function(results) {
				if(results.message){
					var end_time = new Date(),
						execution_time = Math.ceil( ( end_time.getTime() - start_time.getTime() ) / 1000 );
					$('#update-messages ol').append($('<li />').text(results.message.replace('{execution_time}',execution_time)));
				}
				if( undefined == typeof(results.upgraded_count) || parseInt(results.upgraded_count) <= ( wcs_update_script_data.hooks_per_request - 1 ) ){
					wcs_ajax_update_complete();
				} else {
					wcs_ajax_update_hooks();
				}
			},
			error: function(results,status,errorThrown){
				wcs_ajax_update_error();
			}
		});
	}
	function wcs_ajax_update_complete() {
		$('#update-ajax-loader').slideUp(function(){
			$('#update-complete').slideDown();
		});
	}
	function wcs_ajax_update_error() {
		$('#update-ajax-loader').slideUp(function(){
			$('#update-error').slideDown();
		});
	}
});