<?php
	$can_edit = isset($can_edit) ? $can_edit : ((int)$this->session->level === 10); // OWNER or FAQ EDIT ACCESS (FE)

	// Cell renderers for a run row — shared by the initial server render and,
	// mirrored in JS below, by the live poll that updates a run in place.
	if (!function_exists('faq_sugg_status_cell')) {
		function faq_sugg_status_cell($r) {
			$state = strtolower((string)$r->RunState);
			if ($state === 'queued')  { return '<span class="label label-inline label-pill label-light-warning font-weight-bold"><i class="la la-clock-o"></i> Queued…</span>'; }
			if ($state === 'running') { return '<span class="label label-inline label-pill label-light-info font-weight-bold"><i class="la la-spinner la-spin"></i> Running…</span>'; }
			if ($state === 'error')   { return '<span class="label label-inline label-pill label-light-danger font-weight-bold" data-toggle="tooltip" title="' . htmlspecialchars((string)$r->ErrorMessage) . '">Error</span>'; }
			return '<span class="label label-inline label-pill label-light-success font-weight-bold">Done</span>';
		}
	}
	if (!function_exists('faq_sugg_count_cell')) {
		function faq_sugg_count_cell($r) {
			$state = strtolower((string)$r->RunState);
			if ($state === 'queued' || $state === 'running') { return '<span class="text-muted">—</span>'; }
			$html = '<span class="label label-inline label-pill label-light-dark font-weight-bold" data-toggle="tooltip" title="' . (int)$r->Created . ' stored of ' . (int)$r->Proposed . ' proposed">' . (int)$r->Created . '</span>';
			if ((int)$r->PendingCount > 0) {
				$html .= ' <span class="label label-inline label-pill label-light-warning font-weight-bold" data-toggle="tooltip" title="Still pending review">' . (int)$r->PendingCount . ' pending</span>';
			}
			return $html;
		}
	}
	if (!function_exists('faq_sugg_cost_cell')) {
		function faq_sugg_cost_cell($r) {
			if ($r->CostUsd === null || $r->CostUsd === '') { return '<span class="text-muted">-</span>'; }
			return '<span data-toggle="tooltip" title="Total AI cost for this run">$' . number_format((float)$r->CostUsd, 4) . '</span>';
		}
	}
?>

<style>
	#kt_datatable .label.label-inline {
		height: auto;
		min-height: 24px;
		white-space: normal;
		line-height: 1.4;
		padding-top: 4px;
		padding-bottom: 4px;
		text-align: center;
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
						<strong>FAQ AI Suggestions</strong>
						<?php if((int)$pending_count > 0) { ?>
							<span class="label label-inline label-pill label-light-warning font-weight-bold ml-2"><?php echo (int)$pending_count; ?> pending</span>
						<?php } ?>
					</h3>
				</div>
				<div class="card-toolbar">
					<a href="<?php echo base_url('Faq'); ?>" class="btn btn-light font-weight-bold" data-toggle="tooltip" title="Back to FAQ">
						<i class="la la-arrow-left"></i>Back
					</a>
					<?php if($can_edit) { ?>
						<button type="button" class="btn btn-primary font-weight-bold ml-2" data-toggle="modal" data-target="#faq_sugg_generate_modal" title="Choose a date range (and optionally a mobile number) to analyse WhatsApp / GHL chats and propose new FAQs">
							<i class="la la-magic"></i>Generate
						</button>
						<button type="button" class="btn btn-info font-weight-bold ml-2" data-toggle="modal" data-target="#faq_sugg_pdf_modal" title="Upload a PDF brochure / itinerary and propose FAQs from it">
							<i class="la la-file-pdf"></i>From PDF
						</button>
						<button type="button" class="btn btn-success font-weight-bold ml-2" data-toggle="modal" data-target="#faq_sugg_chatfile_modal" title="Upload a chat export (.txt or .zip) and propose FAQs from it">
							<i class="la la-comments"></i>From Chat File
						</button>
					<?php } ?>
				</div>
			</div>
			<div class="card-body">
				<p class="text-muted" style="margin-top:-6px;">
					Each row is a generation run. <strong>Generate</strong> mines chats over a date range you choose (optionally for one mobile number); <strong>From PDF</strong> mines an uploaded document; <strong>From Chat File</strong> mines an uploaded chat export (.txt or .zip). Open a run to review, edit, and accept the FAQs it produced.
				</p>

				<div class="dataTables_wrapper dt-bootstrap4 no-footer" <?php if(empty($runs)) { echo 'style="overflow-x:auto;"'; } ?>>
					<table id="kt_datatable" class="table table-bordered table-head-custom table-checkable dataTable no-footer dtr-inline">
						<thead>
							<tr>
								<th style="text-align:center;">No.</th>
								<th style="text-align:center;">Source</th>
								<th style="text-align:center;">Scope</th>
								<th style="text-align:center;">Suggestions</th>
								<th style="text-align:center;">AI Cost</th>
								<th style="text-align:center;">Status</th>
								<th style="text-align:center;">Date</th>
								<th class="action" style="text-align:center;">Action</th>
							</tr>
						</thead>
						<tbody>
							<?php if(empty($runs)) { ?>
								<tr><td colspan="8" style="text-align:center; padding-top:10px; padding-bottom:10px;">No generation runs yet. Click <strong>Generate</strong>, <strong>From PDF</strong>, or <strong>From Chat File</strong> to create one.</td></tr>
							<?php } else { $count = 1; foreach($runs as $r) {
								$src = strtolower((string)$r->Source);
								$src_label = ($src === 'pdf') ? 'PDF' : (($src === 'chatfile') ? 'Chat File' : 'Chats');
								$src_class = ($src === 'pdf') ? 'label-light-info' : (($src === 'chatfile') ? 'label-light-success' : 'label-light-primary');
								$view_url = base_url('Faq_Suggestion/View?id=') . (int)$r->RunID;
							?>
								<tr data-run-id="<?php echo (int)$r->RunID; ?>">
									<td style="text-align:center; padding-top:15px; padding-bottom:15px;"><?php echo $count; ?></td>
									<td style="text-align:center;">
										<span class="label label-inline label-pill <?php echo $src_class; ?> font-weight-bold"><?php echo $src_label; ?></span>
									</td>
									<td style="text-align:left;">
										<a href="<?php echo $view_url; ?>"><strong><?php echo htmlspecialchars($r->Scope); ?></strong></a>
									</td>
									<td style="text-align:center;" class="faq-run-count"><?php echo faq_sugg_count_cell($r); ?></td>
									<td style="text-align:center;" class="faq-run-cost"><?php echo faq_sugg_cost_cell($r); ?></td>
									<td style="text-align:center;" class="faq-run-status"><?php echo faq_sugg_status_cell($r); ?></td>
									<td style="text-align:center;"><?php echo htmlspecialchars($r->InsertDate ? date('j M Y', strtotime($r->InsertDate)) : '-'); ?></td>
									<td style="text-align:center;">
										<div class="btn-group">
											<a href="<?php echo $view_url; ?>" class="btn btn-icon btn-light-primary btn-sm" data-toggle="tooltip" title="Open run — review the FAQs it produced">
												<i class="la la-eye"></i>
											</a>
											<?php if($can_edit) { ?>
												<button onclick="Delete_Record('<?php echo base_url('assets/image/sweetalert.jpg'); ?>', '<?php echo 'Run : ' . str_replace('\'', '', $r->Scope); ?>', '<?php echo base_url('Faq_Suggestion/Delete_Run'); ?>', 'id', <?php echo (int)$r->RunID; ?>, 'Y', '<?php echo base_url('Faq_Suggestion'); ?>')" class="btn btn-icon btn-light-danger btn-sm ml-1" data-toggle="tooltip" title="Delete this run and its suggestions">
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

<?php if($can_edit) {
	$today      = date('Y-m-d');
	$week_start = date('Y-m-d', strtotime('-7 days'));
?>
<!-- Generate from chats -->
<div class="modal fade" id="faq_sugg_generate_modal" tabindex="-1" role="dialog" aria-hidden="true">
	<div class="modal-dialog modal-dialog-centered" role="document">
		<form method="post" action="<?php echo base_url('Faq_Suggestion/Generate'); ?>" id="faq_sugg_generate_form">
			<div class="modal-content">
				<div class="modal-header">
					<h5 class="modal-title"><i class="la la-magic"></i> Generate from Chats</h5>
					<button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
				</div>
				<div class="modal-body">
					<p class="text-muted">Analyse WhatsApp &amp; GHL chats within a date range and propose new FAQs. Leave the mobile number blank to include everyone.</p>
					<div class="form-group">
						<label>Date range <span class="text-danger">*</span></label>
						<div class="row">
							<div class="col-6">
								<input type="date" name="start_date" class="form-control" value="<?php echo $week_start; ?>" max="<?php echo $today; ?>" required>
								<small class="text-muted">From</small>
							</div>
							<div class="col-6">
								<input type="date" name="end_date" class="form-control" value="<?php echo $today; ?>" max="<?php echo $today; ?>" required>
								<small class="text-muted">To</small>
							</div>
						</div>
					</div>
					<div class="form-group mb-0">
						<label>Mobile number <span class="text-muted">(optional)</span></label>
						<input type="text" name="mobile" class="form-control" placeholder="e.g. 0123456789 — leave blank for all customers">
						<small class="text-muted">Scope the analysis to one customer's conversation.</small>
					</div>
				</div>
				<div class="modal-footer">
					<button type="button" class="btn btn-light font-weight-bold" data-dismiss="modal">Cancel</button>
					<button type="submit" class="btn btn-primary font-weight-bold" id="faq_sugg_generate_submit">
						<i class="la la-magic"></i>Generate
					</button>
				</div>
			</div>
		</form>
	</div>
</div>

<!-- Generate from PDF -->
<div class="modal fade" id="faq_sugg_pdf_modal" tabindex="-1" role="dialog" aria-hidden="true">
	<div class="modal-dialog modal-dialog-centered" role="document">
		<form method="post" action="<?php echo base_url('Faq_Suggestion/Generate_Pdf'); ?>" id="faq_sugg_pdf_form" enctype="multipart/form-data">
			<div class="modal-content">
				<div class="modal-header">
					<h5 class="modal-title"><i class="la la-file-pdf"></i> Generate from PDF</h5>
					<button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
				</div>
				<div class="modal-body">
					<p class="text-muted">Upload a tour brochure, itinerary, or price sheet (PDF, up to 20 MB). The AI reads it and proposes FAQs a customer would ask about.</p>
					<div class="form-group mb-0">
						<label>PDF file <span class="text-danger">*</span></label>
						<input type="file" name="file" class="form-control-file" accept="application/pdf,.pdf" required>
					</div>
				</div>
				<div class="modal-footer">
					<button type="button" class="btn btn-light font-weight-bold" data-dismiss="modal">Cancel</button>
					<button type="submit" class="btn btn-info font-weight-bold" id="faq_sugg_pdf_submit">
						<i class="la la-file-pdf"></i>Generate
					</button>
				</div>
			</div>
		</form>
	</div>
</div>

<!-- Generate from Chat File -->
<div class="modal fade" id="faq_sugg_chatfile_modal" tabindex="-1" role="dialog" aria-hidden="true">
	<div class="modal-dialog modal-dialog-centered" role="document">
		<form method="post" action="<?php echo base_url('Faq_Suggestion/Generate_Chat_File'); ?>" id="faq_sugg_chatfile_form" enctype="multipart/form-data">
			<div class="modal-content">
				<div class="modal-header">
					<h5 class="modal-title"><i class="la la-comments"></i> Generate from Chat File</h5>
					<button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
				</div>
				<div class="modal-body">
					<p class="text-muted">Upload a chat export as <strong>.txt</strong> (text only, without media), or a <strong>.zip</strong> bundling several exports. The AI reads the conversation and proposes FAQs from it. Max 5 MB per .txt, 30 MB per .zip.</p>
					<div class="form-group mb-0">
						<label>Chat export file (.txt or .zip) <span class="text-danger">*</span></label>
						<input type="file" name="file" class="form-control-file" accept=".txt,.zip,text/plain,application/zip" required>
					</div>
				</div>
				<div class="modal-footer">
					<button type="button" class="btn btn-light font-weight-bold" data-dismiss="modal">Cancel</button>
					<button type="submit" class="btn btn-success font-weight-bold" id="faq_sugg_chatfile_submit">
						<i class="la la-comments"></i>Generate
					</button>
				</div>
			</div>
		</form>
	</div>
</div>
<?php } ?>

<script>
	$('[data-toggle="tooltip"]').tooltip();

	// Submitting queues a background run and redirects; disable the button so the
	// user doesn't double-submit while the redirect happens.
	$('#faq_sugg_generate_form').on('submit', function() {
		$('#faq_sugg_generate_submit').prop('disabled', true).html('<i class="la la-spinner la-spin"></i>Starting...');
	});
	$('#faq_sugg_pdf_form').on('submit', function() {
		$('#faq_sugg_pdf_submit').prop('disabled', true).html('<i class="la la-spinner la-spin"></i>Starting...');
	});
	$('#faq_sugg_chatfile_form').on('submit', function() {
		$('#faq_sugg_chatfile_submit').prop('disabled', true).html('<i class="la la-spinner la-spin"></i>Starting...');
	});

	// --- Live status poll: update queued/running runs in place until all done ---
	var faqRunsTimer = null;

	function faqEsc(s) { return $('<div>').text(s == null ? '' : String(s)).html(); }

	function faqStatusHtml(r) {
		if (r.RunState === 'queued')  { return '<span class="label label-inline label-pill label-light-warning font-weight-bold"><i class="la la-clock-o"></i> Queued…</span>'; }
		if (r.RunState === 'running') { return '<span class="label label-inline label-pill label-light-info font-weight-bold"><i class="la la-spinner la-spin"></i> Running…</span>'; }
		if (r.RunState === 'error')   { return '<span class="label label-inline label-pill label-light-danger font-weight-bold" data-toggle="tooltip" title="' + faqEsc(r.ErrorMessage) + '">Error</span>'; }
		return '<span class="label label-inline label-pill label-light-success font-weight-bold">Done</span>';
	}
	function faqCountHtml(r) {
		if (r.RunState === 'queued' || r.RunState === 'running') { return '<span class="text-muted">—</span>'; }
		var html = '<span class="label label-inline label-pill label-light-dark font-weight-bold" data-toggle="tooltip" title="' + r.Created + ' stored of ' + r.Proposed + ' proposed">' + r.Created + '</span>';
		if (r.PendingCount > 0) { html += ' <span class="label label-inline label-pill label-light-warning font-weight-bold" data-toggle="tooltip" title="Still pending review">' + r.PendingCount + ' pending</span>'; }
		return html;
	}
	function faqCostHtml(r) {
		if (r.CostUsd === null || r.CostUsd === '') { return '<span class="text-muted">-</span>'; }
		return '<span data-toggle="tooltip" title="Total AI cost for this run">$' + Number(r.CostUsd).toFixed(4) + '</span>';
	}

	function faqRenderRuns(data) {
		(data.runs || []).forEach(function(r) {
			var $tr = $('tr[data-run-id="' + r.RunID + '"]');
			if (!$tr.length) return;
			$tr.find('.faq-run-status').html(faqStatusHtml(r));
			$tr.find('.faq-run-count').html(faqCountHtml(r));
			$tr.find('.faq-run-cost').html(faqCostHtml(r));
		});
		$('[data-toggle="tooltip"]').tooltip();

		if (data.running) {
			if (!faqRunsTimer) { faqRunsTimer = setInterval(faqPollRuns, 3000); }
		} else if (faqRunsTimer) {
			clearInterval(faqRunsTimer); faqRunsTimer = null;
		}
	}

	function faqPollRuns() {
		$.getJSON('<?php echo base_url('Faq_Suggestion/Runs_Status'); ?>').done(faqRenderRuns);
	}

	// Poll once on load; it self-schedules a 3s loop while anything is queued/running.
	$(document).ready(faqPollRuns);
</script>
