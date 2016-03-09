jQuery(document).ready(function($){

	// show/hide tables depending on the tabs chosen
	$('.wcsi-exporter-tabs').click(function(e) {
		e.preventDefault();

		$('.wcsi-exporter-tabs').removeClass('nav-tab-active');
		$(this).addClass('nav-tab-active');

		$('.wcsi-exporter-form table').hide();
		$('#' + $(this).attr('id') + '-table').show();
	});
});