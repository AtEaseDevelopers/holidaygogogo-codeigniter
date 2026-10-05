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
			if ($r->Source==='reevaluate') { return '<span class="text-muted">'.(strtolower((string)$r->RunState)==='error'?'Failed':'1 re-evaluated').'</span>'; }
			$html = '<span class="label label-inline label-pill label-light-dark font-weight-bold" data-toggle="tooltip" title="' . (int)$r->Created . ' stored of ' . (int)$r->Proposed . ' proposed">' . (int)$r->Created . '</span>';
			if ((int)$r->PendingCount > 0) {
				$html .= ' <span class="label label-inline label-pill label-light-warning font-weight-bold" data-toggle="tooltip" title="Suggestions awaiting approval or more information">' . (int)$r->PendingCount . ' open</span>';
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
	// The exact text handed to the AI. Chats / chat-file runs store the
	// transcript — offer a "View" button that loads it on demand into a modal.
	// PDF runs' input is the uploaded document itself (not re-stored as text).
	if (!function_exists('faq_sugg_input_cell')) {
		function faq_sugg_input_cell($r) {
			if (strtolower((string)$r->Source) === 'pdf') {
				return '<span class="text-muted" data-toggle="tooltip" title="' . htmlspecialchars((string)$r->FileName) . '"><i class="la la-file-pdf"></i> Document</span>';
			}
			if (!empty($r->HasInput)) {
				return '<button type="button" class="btn btn-light-primary btn-sm font-weight-bold faq-input-btn" data-run-id="' . (int)$r->RunID . '" data-toggle="tooltip" title="View the transcript sent to the AI"><i class="la la-file-alt"></i> View</button>';
			}
			return '<span class="text-muted">—</span>';
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
		<?php $this->load->view('faq/sections',array('active_section'=>'history')); ?>
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
						<strong>Generation History</strong>
					</h3>
				</div>
			</div>
			<div class="card-body">
				<p class="text-muted" style="margin-top:-6px;">
					AI runs from chats, documents and re-evaluation. Open a run to inspect its input and results.
				</p>

				<div class="dataTables_wrapper dt-bootstrap4 no-footer" <?php if(empty($runs)) { echo 'style="overflow-x:auto;"'; } ?>>
					<table id="kt_datatable" class="table table-bordered table-head-custom table-checkable dataTable no-footer dtr-inline">
						<thead>
							<tr>
								<th style="text-align:center;">No.</th>
								<th style="text-align:center;">Source</th>
								<th style="text-align:center;">Scope</th>
								<th style="text-align:center;">AI Input</th>
								<th style="text-align:center;">Suggestions</th>
								<th style="text-align:center;">AI Cost</th>
								<th style="text-align:center;">Status</th>
								<th style="text-align:center;">Date</th>
								<th class="action" style="text-align:center;">Action</th>
							</tr>
						</thead>
						<tbody>
							<?php if(empty($runs)) { ?>
								<tr><td colspan="9" style="text-align:center; padding-top:10px; padding-bottom:10px;">No generation runs yet. Generate suggestions from the AI Suggestion page.</td></tr>
							<?php } else { $count = 1; foreach($runs as $r) {
								$src = strtolower((string)$r->Source);
								$src_label = ($src === 'pdf') ? 'PDF' : (($src === 'chatfile') ? 'Chat File' : (($src === 'reevaluate') ? 'Re-evaluation' : 'Chats'));
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
									<td style="text-align:center;" class="faq-run-input"><?php echo faq_sugg_input_cell($r); ?></td>
									<td style="text-align:center;" class="faq-run-count"><?php echo faq_sugg_count_cell($r); ?></td>
									<td style="text-align:center;" class="faq-run-cost"><?php echo faq_sugg_cost_cell($r); ?></td>
									<td style="text-align:center;" class="faq-run-status"><?php echo faq_sugg_status_cell($r); ?></td>
									<td style="text-align:center;"><?php echo htmlspecialchars($r->InsertDate ? date('j M Y', strtotime($r->InsertDate)) : '-'); ?></td>
									<td style="text-align:center;">
										<div class="btn-group">
											<a href="<?php echo $src==='reevaluate'?base_url('Faq_Suggestion/Update?id=').(int)preg_replace('/[^0-9]/','',(string)$r->FileName):$view_url; ?>" class="btn btn-icon btn-light-primary btn-sm" data-toggle="tooltip" title="Open run — review the FAQs it produced">
												<i class="la la-eye"></i>
											</a>
											<?php if($can_edit && $src!=='reevaluate') { ?>
												<form class="d-inline" method="post" action="<?php echo base_url('Faq_Suggestion/Delete_Run'); ?>" data-delete-title="<?php echo htmlspecialchars('Generation run #'.(int)$r->RunID.' and its suggestions',ENT_QUOTES,'UTF-8'); ?>" onsubmit="return Confirm_Delete_Form(this, '<?php echo base_url('assets/image/sweetalert.jpg'); ?>', this.dataset.deleteTitle);"><?php echo faq_workspace_csrf_field($this->session); ?><input type="hidden" name="id" value="<?php echo (int)$r->RunID; ?>"><button class="btn btn-icon btn-light-danger btn-sm ml-1" data-toggle="tooltip" title="Delete this run and its suggestions">
													<i class="la la-trash"></i>
												</button></form>
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

<!-- AI Input viewer (transcript sent to the AI for a run) -->
<div class="modal fade" id="faq_input_modal" tabindex="-1" role="dialog" aria-hidden="true">
	<div class="modal-dialog modal-lg modal-dialog-centered" role="document">
		<div class="modal-content">
			<div class="modal-header">
				<h5 class="modal-title"><i class="la la-file-alt"></i> AI Input <span id="faq_input_scope" class="text-muted font-weight-normal ml-2"></span></h5>
				<button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
			</div>
			<div class="modal-body">
				<p class="text-muted">This is the input sent to AI for this run, including any additional context supplied for re-evaluation.</p>
				<pre id="faq_input_body" style="white-space:pre-wrap; word-break:break-word; max-height:60vh; overflow:auto; background:#f7f9fc; border:1px solid #e4e6ef; border-radius:6px; padding:12px; font-size:13px;">Loading…</pre>
			</div>
			<div class="modal-footer">
				<button type="button" class="btn btn-light font-weight-bold" data-dismiss="modal">Close</button>
			</div>
		</div>
	</div>
</div>


<script>
	$('[data-toggle="tooltip"]').tooltip();


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
		if (r.Source === 'reevaluate') { return '<span class="text-muted">' + (r.RunState === 'error' ? 'Failed' : '1 re-evaluated') + '</span>'; }
		var html = '<span class="label label-inline label-pill label-light-dark font-weight-bold" data-toggle="tooltip" title="' + r.Created + ' stored of ' + r.Proposed + ' proposed">' + r.Created + '</span>';
		if (r.PendingCount > 0) { html += ' <span class="label label-inline label-pill label-light-warning font-weight-bold" data-toggle="tooltip" title="Suggestions awaiting approval or more information">' + r.PendingCount + ' open</span>'; }
		return html;
	}
	function faqCostHtml(r) {
		if (r.CostUsd === null || r.CostUsd === '') { return '<span class="text-muted">-</span>'; }
		return '<span data-toggle="tooltip" title="Total AI cost for this run">$' + Number(r.CostUsd).toFixed(4) + '</span>';
	}
	function faqInputHtml(r) {
		if (r.Source === 'pdf') { return '<span class="text-muted" data-toggle="tooltip" title="' + faqEsc(r.FileName) + '"><i class="la la-file-pdf"></i> Document</span>'; }
		if (r.HasInput) { return '<button type="button" class="btn btn-light-primary btn-sm font-weight-bold faq-input-btn" data-run-id="' + r.RunID + '" data-toggle="tooltip" title="View the transcript sent to the AI"><i class="la la-file-alt"></i> View</button>'; }
		return '<span class="text-muted">—</span>';
	}

	function faqRenderRuns(data) {
		(data.runs || []).forEach(function(r) {
			var $tr = $('tr[data-run-id="' + r.RunID + '"]');
			if (!$tr.length) return;
			$tr.find('.faq-run-status').html(faqStatusHtml(r));
			$tr.find('.faq-run-count').html(faqCountHtml(r));
			$tr.find('.faq-run-cost').html(faqCostHtml(r));
			$tr.find('.faq-run-input').html(faqInputHtml(r));
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

	// --- AI Input viewer: load the transcript on demand into the modal ---------
	$(document).on('click', '.faq-input-btn', function() {
		var runId = $(this).data('run-id');
		$('#faq_input_scope').text('');
		$('#faq_input_body').text('Loading…');
		$('#faq_input_modal').modal('show');
		$.getJSON('<?php echo base_url('Faq_Suggestion/Input?id='); ?>' + runId)
			.done(function(res) {
				if (res && res.ok) {
					$('#faq_input_scope').text(res.scope || '');
					$('#faq_input_body').text((res.input && res.input.length) ? res.input : 'No input text was stored for this run.');
				} else {
					$('#faq_input_body').text('Could not load the AI input.');
				}
			})
			.fail(function() { $('#faq_input_body').text('Could not load the AI input.'); });
	});
</script>
