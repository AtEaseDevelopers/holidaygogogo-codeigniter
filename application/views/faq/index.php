<?php $can_edit = isset($can_edit) ? $can_edit : ((int)$this->session->level === 10); // OWNER or FAQ EDIT ACCESS (FE) may create/edit/delete + run bulk Excel/PDF/Export/Import; others view only ?>

<style>
	/* Tag / Destination pills: let long labels (e.g. "Rawa Island Resort - Key
	   Contacts") wrap inside the coloured pill instead of spilling past it. */
	#kt_datatable .label.label-inline {
		height: auto;
		min-height: 24px;
		white-space: normal;
		line-height: 1.4;
		padding-top: 4px;
		padding-bottom: 4px;
		text-align: center;
	}

	/* Mobile responsive child rows (Created By / Action): align each label and
	   its value into two tidy columns so labels and values line up. */
	#kt_datatable > tbody > tr.child ul.dtr-details {
		width: 100%;
		margin: 0;
		padding: 0;
		list-style: none;
	}
	#kt_datatable > tbody > tr.child ul.dtr-details > li {
		display: flex;
		align-items: center;
		border-bottom: 1px solid #ebedf3;
		padding: 8px 10px;
	}
	#kt_datatable > tbody > tr.child ul.dtr-details > li:last-child {
		border-bottom: none;
	}
	#kt_datatable > tbody > tr.child .dtr-title {
		flex: 0 0 110px;
		font-weight: 600;
		color: #3f4254;
	}
	#kt_datatable > tbody > tr.child .dtr-data {
		flex: 1 1 auto;
	}
</style>

<div class="d-flex flex-column-fluid">
	<div class="container-fluid">
		<?php if($this->session->flashdata('faq_success')) { ?>
			<div class="alert alert-light-success" role="alert" style="border-left:4px solid #1bc5bd;">
				<?php echo htmlspecialchars($this->session->flashdata('faq_success')); ?>
			</div>
		<?php } ?>
		<?php if($this->session->flashdata('faq_error')) { ?>
			<div class="alert alert-light-danger" role="alert" style="border-left:4px solid #f64e60;">
				<?php echo htmlspecialchars($this->session->flashdata('faq_error')); ?>
			</div>
		<?php } ?>

		<div class="card card-custom mb-5">
			<div class="card-header flex-wrap py-3" style="background-color:#D7E2F2;">
				<div class="card-title">
					<h3 class="card-label" style="color:#6082B6;">
						<strong>FAQ Records</strong>
					</h3>
				</div>
				<div class="card-toolbar">
					<a href="<?php echo base_url('Faq/Internal'); ?>" target="_blank" rel="noopener" class="btn btn-light-primary font-weight-bold" data-toggle="tooltip" title="Open every FAQ together on one page">
						<i class="la la-book"></i>Internal FAQs
					</a>
					<a href="<?php echo base_url('Faq_Suggestion'); ?>" class="btn btn-light-info font-weight-bold ml-2" data-toggle="tooltip" title="Review AI-suggested FAQs mined from recent WhatsApp / GHL chats">
						<i class="la la-magic"></i>AI Suggestions
					</a>
					<?php if($can_edit) { ?>
						<a href="<?php echo base_url('Faq/Download'); ?>" class="btn btn-light-success font-weight-bold ml-2" data-toggle="tooltip" title="Download every FAQ as an Excel file">
							<i class="la la-file-excel"></i>Excel
						</a>
						<a href="<?php echo base_url('Faq/Download_Pdf'); ?>" class="btn btn-light-danger font-weight-bold ml-2" data-toggle="tooltip" title="Download every FAQ as a PDF file">
							<i class="la la-file-pdf"></i>PDF
						</a>
						<a href="<?php echo base_url('Faq/Export_Template'); ?>" class="btn btn-light-info font-weight-bold ml-2" data-toggle="tooltip" title="Download an editable Excel template of all FAQs to re-import">
							<i class="la la-file-export"></i>Export
						</a>
						<button type="button" class="btn btn-light-warning font-weight-bold ml-2" data-toggle="modal" data-target="#faq_import_modal" title="Upload an edited template to rebuild all FAQs">
							<i class="la la-file-import"></i>Import
						</button>
					<?php } ?>
					<?php if($can_edit) { ?>
						<a href="<?php echo base_url('Faq/Create'); ?>" class="btn btn-primary font-weight-bold ml-2" style="width:160px;">
							<i class="la la-clipboard-list"></i>Create FAQ
						</a>
					<?php } ?>
					<?php $current_url = base_url($_SERVER['REQUEST_URI']); ?>
				</div>
			</div>
			<div class="card-body">
				<div class="accordion accordion-solid accordion-toggle-plus">
					<div class="card">
						<div class="card-header">
							<div id="faq_header" data-toggle="collapse" data-target="#faq_info" class="card-title collapsed" style="font-size:13px;">Filter By FAQ Information</div>
						</div>
						<div id="faq_info" class="collapse">
							<div class="card-body">
								<form id="faq_filter_form" action="<?php echo base_url('Faq') ?>" method="get" class="form">
									<div class="row">
										<div class="col-md-4">
											<div class="form-group">
												<label>Tags</label>
												<?php $selected_tags = !empty($this->input->get('tag')) ? explode(',', $this->input->get('tag')) : array(); ?>
												<select id="tag_select" data-live-search="true" data-live-search-style="contains" data-live-search-normalize="true" class="form-control selectpicker" multiple data-actions-box="true" title="--SELECT TAG--">
													<?php foreach($tags as $tag) { ?>
														<option data-icon="la la-tag font-size-lg bs-icon" value="<?php echo (int)$tag->FAQTagID; ?>" <?php if(in_array((string)$tag->FAQTagID, $selected_tags, true)) { echo 'selected'; } ?>><?php echo htmlspecialchars((string)$tag->Name); ?></option>
													<?php } ?>
												</select>
												<input type="hidden" name="tag" id="tag_hidden" value="<?php echo htmlspecialchars((string)$this->input->get('tag'), ENT_QUOTES); ?>">
											</div>
										</div>
										<div class="col-md-4">
											<div class="form-group">
												<label>Destination</label>
												<?php $selected_destinations = !empty($this->input->get('destination')) ? explode(',', $this->input->get('destination')) : array(); ?>
												<select id="destination_select" data-live-search="true" data-live-search-style="contains" data-live-search-normalize="true" class="form-control selectpicker" multiple data-actions-box="true" title="--SELECT DESTINATION--">
													<?php foreach($destinations as $destination) { ?>
														<option data-icon="la la-map-pin font-size-lg bs-icon" value="<?php echo (int)$destination->CategoryID; ?>" <?php if(in_array((string)$destination->CategoryID, $selected_destinations, true)) { echo 'selected'; } ?>><?php echo htmlspecialchars((string)$destination->Name); ?></option>
													<?php } ?>
												</select>
												<input type="hidden" name="destination" id="destination_hidden" value="<?php echo htmlspecialchars((string)$this->input->get('destination'), ENT_QUOTES); ?>">
											</div>
										</div>
									</div>
									<input type="submit" value="Filter" class="btn btn-light-success font-weight-bold" style="width:80px;">
									<input type="button" id="reset" value="Reset" class="btn btn-light-primary font-weight-bold" style="width:80px;">
								</form>
							</div>
						</div>
					</div>
				</div>
				<br><br>
				<div class="dataTables_wrapper dt-bootstrap4 no-footer" <?php if(empty($faqs)) { echo 'style="overflow-x:auto;"'; } ?>>
					<table id="kt_datatable" class="table table-bordered table-head-custom table-checkable dataTable no-footer dtr-inline">
						<thead>
							<tr>
								<th style="text-align:center;">No.</th>
								<th style="text-align:center;">Title</th>
								<th style="text-align:center;">Questions</th>
								<?php /* temporary: external FAQ not needed for now, Type column hidden
								<th style="text-align:center;">Type</th>
								*/ ?>
								<th style="text-align:center;">Tags</th>
								<th style="text-align:center;">Destination</th>
								<th style="text-align:center;">Created By</th>
								<th class="action" style="text-align:center;">Action</th>
							</tr>
						</thead>
						<tbody>
							<?php if(empty($faqs)) { ?>
								<tr><td colspan="7" style="text-align:center; padding-top:10px; padding-bottom:10px;">FAQ Records Not Found</td></tr>
							<?php } else { $count = 1; foreach($faqs as $faq) { ?>
								<tr>
									<td style="text-align:center; padding-top:15px; padding-bottom:15px;"><?php echo $count; ?></td>
									<td style="text-align:left;"><strong><?php echo htmlspecialchars($faq->Title); ?></strong><?php if(!empty($faq->SearchText)) { ?><span class="faq-search-blob" style="display:none;"><?php echo htmlspecialchars($faq->SearchText); ?></span><?php } ?></td>
									<td style="text-align:center;"><span class="label label-inline label-pill label-light-dark font-weight-bold"><?php echo (int)$faq->QuestionCount; ?></span></td>
									<td style="text-align:center;">
										<?php
											$tag_names = ($faq->Tags === null || $faq->Tags === '') ? array() : explode('||', $faq->Tags);
											if(empty($tag_names)) {
												echo '<span class="text-muted">-</span>';
											} else {
												foreach($tag_names as $tag_name) {
													echo '<span class="label label-inline label-pill label-light-info font-weight-bold mr-1 mb-1">' . htmlspecialchars($tag_name) . '</span>';
												}
											}
										?>
									</td>
									<td style="text-align:center;">
										<?php
											$destination_names = ($faq->Destinations === null || $faq->Destinations === '') ? array() : explode('||', $faq->Destinations);
											if(empty($destination_names)) {
												echo '<span class="text-muted">-</span>';
											} else {
												foreach($destination_names as $destination_name) {
													echo '<span class="label label-inline label-pill label-light-primary font-weight-bold mr-1 mb-1">' . htmlspecialchars($destination_name) . '</span>';
												}
											}
										?>
									</td>
									<?php /* temporary: external FAQ not needed for now, Type column hidden
									<td style="text-align:center;">
										<?php if($faq->Type === 'external') { ?>
											<span class="label label-inline label-pill label-light-success font-weight-bold">External</span>
										<?php } else { ?>
											<span class="label label-inline label-pill label-light-primary font-weight-bold">Internal</span>
										<?php } ?>
									</td>
									*/ ?>
									<td style="text-align:center;"><?php echo htmlspecialchars((string)$faq->InsertByName); ?></td>
									<td style="text-align:center;">
										<div class="btn-group">
											<?php if(!empty($faq->Slug)) { ?>
												<a href="<?php echo base_url('faq/' . rawurlencode($faq->Slug)); ?>" target="_blank" class="btn btn-icon btn-light-primary btn-sm" data-toggle="tooltip" title="Open FAQ page in new tab">
													<i class="la la-external-link-alt"></i>
												</a>
											<?php } ?>
											<?php if($can_edit) { ?>
												<a href="<?php echo base_url('Faq/Update?faq_id=') . (int)$faq->FAQID; ?>" class="btn btn-icon btn-light-warning btn-sm ml-1" data-toggle="tooltip" title="Edit FAQ">
													<i class="la la-edit"></i>
												</a>
												<button onclick="Delete_Record('<?php echo base_url('assets/image/sweetalert.jpg'); ?>', '<?php echo 'FAQ : ' . str_replace('\'', '', $faq->Title); ?>', '<?php echo base_url('Faq/Delete'); ?>', 'faq_id', <?php echo (int)$faq->FAQID; ?>, 'Y', '<?php if(strpos($current_url, '?') == true) { echo base_url('Faq?') . (explode('?', $current_url))[1]; } else { echo base_url('Faq'); } ?>')" class="btn btn-icon btn-light-danger btn-sm ml-1" data-toggle="tooltip" title="Delete FAQ">
													<i class="la la-trash"></i>
												</button>
											<?php } ?>
										</div>
									</td>
								</tr>
								<?php $count++; ?>
							<?php } } ?>
						</tbody>
					</table>
				</div>
			</div>
		</div>
	</div>
</div>

<?php if($can_edit) { ?>
<!-- Import modal: an editor (OWNER or FE) uploads an edited Export template to
     rebuild the whole internal FAQ library (wipe-and-import). -->
<div class="modal fade" id="faq_import_modal" tabindex="-1" role="dialog" aria-labelledby="faq_import_label" aria-hidden="true">
	<div class="modal-dialog modal-dialog-centered" role="document">
		<form action="<?php echo base_url('Faq/Import'); ?>" method="post" enctype="multipart/form-data" id="faq_import_form">
			<div class="modal-content">
				<div class="modal-header" style="background-color:#D7E2F2;">
					<h5 class="modal-title" id="faq_import_label" style="color:#6082B6;"><strong>Import FAQs from Excel</strong></h5>
					<button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
				</div>
				<div class="modal-body">
					<div class="alert alert-light-warning" role="alert" style="border-left:4px solid #ffa800;">
						<strong>Heads up:</strong> Importing <strong>replaces all internal FAQs</strong> with the rows in your file. Export first, edit that file, then import it back.
					</div>
					<div class="form-group">
						<label>Excel File <span class="text-danger">*</span></label>
						<div class="custom-file">
							<input type="file" name="import_file" class="custom-file-input" id="faq_import_file" accept=".xlsx,.xls" required>
							<label class="custom-file-label" for="faq_import_file" id="faq_import_file_label">Choose .xlsx / .xls file</label>
						</div>
						<span class="form-text text-muted">Use the exported template (columns: FAQ, Destination, Question, Answer, Tags). Unknown tags are created; unknown destinations are skipped. The last 3 uploads are kept as backups.</span>
					</div>
				</div>
				<div class="modal-footer">
					<button type="button" class="btn btn-light font-weight-bold" data-dismiss="modal">Cancel</button>
					<button type="submit" class="btn btn-warning font-weight-bold" id="faq_import_submit">
						<i class="la la-file-import"></i>Replace &amp; Import
					</button>
				</div>
			</div>
		</form>
	</div>
</div>
<?php } ?>

<script>
	<?php if(trim((string)$this->input->get('tag')) !== '' || trim((string)$this->input->get('destination')) !== '') { ?>
		$('#faq_header').click();
	<?php } ?>

	// Mirror each multi-select's chosen ids into its hidden input (CSV) so the
	// GET form submits them under one named field, matching the model's parser.
	var faq_multi_filters = ['tag', 'destination'];
	function syncFaqMultiSelect(name) {
		var $sel = $('#' + name + '_select');
		var $hid = $('#' + name + '_hidden');
		if(!$sel.length || !$hid.length) return;
		var v = $sel.val();
		$hid.val(v ? v.join(',') : '');
	}
	faq_multi_filters.forEach(function(name) {
		$('#' + name + '_select').on('changed.bs.select', function() { syncFaqMultiSelect(name); });
	});
	$('#faq_filter_form').on('submit', function() {
		faq_multi_filters.forEach(syncFaqMultiSelect);
	});

	$('#reset').click(function() { Reset('<?php echo base_url('Faq'); ?>'); });
	$('[data-toggle="tooltip"]').tooltip();

	// Import modal: show the picked filename, and confirm the wipe-and-replace
	// once before the form submits (it rebuilds the whole internal FAQ library).
	$('#faq_import_file').on('change', function() {
		var name = (this.files && this.files.length) ? this.files[0].name : 'Choose .xlsx / .xls file';
		$('#faq_import_file_label').text(name);
	});
	$('#faq_import_form').on('submit', function() {
		if(!window.confirm('This will replace ALL internal FAQs with the rows in your file. Continue?')) {
			return false;
		}
		$('#faq_import_submit').prop('disabled', true).html('<i class="la la-spinner la-spin"></i>Importing...');
	});
</script>
