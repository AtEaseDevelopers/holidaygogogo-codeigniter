"use strict";

var KTDatatablesAdvancedColumnRendering = function() {
	var init = function() {
		var table = $("#kt_datatable");

		// Pages that sort + paginate entirely on the server opt out of the client
		// DataTable (it would only re-sort the current page and fight the server
		// order). Opt out by putting data-no-datatable on the table element.
		if (!table.length || table.data('no-datatable')) {
			return;
		}

		// begin first table
		var priorityColumn = table.find('thead th.manual-lead-priority').index();
		table.DataTable({
			deferRender: true,
			responsive: table.data('responsive') !== false,
			autoWidth: !table.hasClass('manual-leads-table'),
			paging: true,
			orderFixed: priorityColumn >= 0 ? [[priorityColumn, 'desc']] : [],
			columnDefs: [
				{ targets: 'manual-lead-priority', visible: false, searchable: false },
				{
					targets: ['booking_checkbox', 'test', 'gl_status', 'status', 'autocount_sync_status','action'],
					orderable: false
				},
				{ 'type': 'date', 'targets': ['bc_date', 'start_date', 'end_date', 'transaction_date', 'deadline', 'month'] },
				{ 'type': 'num-fmt', 'targets': ['subtotal', 'profit', 'amount_received', 'in', 'out', 'retail_price', 'supplier_price'] }
			],
			lengthMenu: [
	            [100, 200, 500, -1],
	            [100, 200, 500, 'All'],
	        ],
		});
	};
	return {

		//main function to initiate the module
		init: function() {
			init();
		}
	};
}();

jQuery(document).ready(function() {
	KTDatatablesAdvancedColumnRendering.init();
});
