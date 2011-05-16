jQuery(function($) {
	$('#etsy-showcase-form [name=clear]').click(function() {
		$(this)
			.closest('form')
				.each(function() {this.reset()})
				.find('[name=command]').val('clear').end()
				.submit()
		;
	})
})