(function( $ ) {
	'use strict';


	jQuery(document).ready(function($) {
		$('.add-more').on('click', function() {
			var lastRow = $('#attribute-table tbody tr:last');
			var newRow = lastRow.clone();
			var inputs = newRow.find('input, select, textarea');
	
			inputs.each(function() {
				var name = $(this).attr('name');
				var matches = name.match(/\[(\d+)\]/);
	
				if (matches) {
					var index = parseInt(matches[1]) + 1; 
					var newName = name.replace(/\[\d+\]/, '[' + index + ']'); 
					$(this).attr('name', newName);
					$(this).prop('checked', false); 
				}
			});
			inputs.val('');
			$('#attribute-table tbody').append(newRow);
		});
	
	
		$(document).on('click', '.delete-row', function() {
			$(this).closest('tr').remove();
			$('#attribute-table tbody tr').each(function(index) {
				$(this).find('input, select, textarea').each(function() {
					var name = $(this).attr('name');
					var newName = name.replace(/\[\d+\]/, '[' + index + ']');
					$(this).attr('name', newName);
				});
			});
		});
	});

	/**
	 * All of the code for your admin-facing JavaScript source
	 * should reside in this file.
	 *
	 * Note: It has been assumed you will write jQuery code here, so the
	 * $ function reference has been prepared for usage within the scope
	 * of this function.
	 *
	 * This enables you to define handlers, for when the DOM is ready:
	 *
	 * $(function() {
	 *
	 * });
	 *
	 * When the window is loaded:
	 *
	 * $( window ).load(function() {
	 *
	 * });
	 *
	 * ...and/or other possibilities.
	 *
	 * Ideally, it is not considered best practise to attach more than a
	 * single DOM-ready or window-load handler for a particular page.
	 * Although scripts in the WordPress core, Plugins and Themes may be
	 * practising this, we should strive to set a better example in our own work.
	 */

})( jQuery );


