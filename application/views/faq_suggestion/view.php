<?php
	$can_edit = isset($can_edit) ? $can_edit : ((int)$this->session->level === 10); // OWNER or FAQ EDIT ACCESS (FE)
	$run_id   = (int)$run->RunID;
	$back_url = base_url('Faq_Suggestion/View?id=') . $run_id;
	$is_pdf   = (strtolower((string)$run->Source) === 'pdf');
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
	.faq-run-meta dt { color:#6082B6; font-weight:600; }
	.faq-run-meta dd { margin-bottom:8px; }
	.faq-evidence-item { border:1px solid #b6c9df; border-radius:7px; margin-top:14px; overflow:hidden; box-shadow:0 1px 2px rgba(50, 85, 125, .06); }
	.faq-evidence-head { background:#edf3fa; border-bottom:1px solid #b6c9df; padding:10px 14px; }
	.faq-evidence-grid { display:grid; grid-template-columns:repeat(2, minmax(0, 1fr)); gap:10px 18px; padding:12px 14px; }
	.faq-evidence-field { min-width:0; }
	.faq-evidence-field label { display:block; color:#7e8299; font-size:11px; font-weight:600; letter-spacing:.02em; margin:0 0 2px; text-transform:uppercase; }
	.faq-evidence-field div { font-size:13px; overflow-wrap:anywhere; }
	.faq-evidence-quote { background:#fcfcfd; border-top:1px solid #e4e6ef; color:#464e5f; padding:12px 14px; white-space:pre-wrap; word-break:break-word; }
	@media (max-width:575px) { .faq-evidence-grid { grid-template-columns:1fr; } }
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
						<strong>FAQ Suggestion Run</strong>
						<span class="label label-inline label-pill <?php echo $is_pdf ? 'label-light-info' : 'label-light-primary'; ?> font-weight-bold ml-2"><?php echo $is_pdf ? 'PDF' : 'Chats'; ?></span>
					</h3>
				</div>
				<div class="card-toolbar">
					<a href="<?php echo base_url('Faq_Suggestion'); ?>" class="btn btn-light font-weight-bold" data-toggle="tooltip" title="Back to all runs">
						<i class="la la-arrow-left"></i>Back to Runs
					</a>
				</div>
			</div>
			<div class="card-body">
				<div class="row faq-run-meta mb-3">
					<div class="col-md-6">
						<dl class="row mb-0">
							<dt class="col-5"><?php echo $is_pdf ? 'File' : 'Scope'; ?></dt>
							<dd class="col-7"><?php echo htmlspecialchars($run->Scope); ?></dd>
							<dt class="col-5">Generated</dt>
							<dd class="col-7"><?php echo htmlspecialchars($run->InsertDate ? date('j M Y, g:i A', strtotime($run->InsertDate)) : '-'); ?></dd>
							<dt class="col-5">AI Input</dt>
							<dd class="col-7">
								<?php if($is_pdf) { ?>
									<span class="text-muted"><i class="la la-file-pdf"></i> Uploaded document</span>
								<?php } elseif(!empty($run->InputText)) { ?>
									<button type="button" class="btn btn-light-primary btn-sm font-weight-bold" data-toggle="modal" data-target="#faq_input_modal" title="View the transcript sent to the AI">
										<i class="la la-file-alt"></i> View input
									</button>
								<?php } else { ?>
									<span class="text-muted">—</span>
								<?php } ?>
							</dd>
						</dl>
					</div>
					<div class="col-md-6">
						<dl class="row mb-0">
							<dt class="col-5">Suggestions</dt>
							<dd class="col-7"><span class="label label-inline label-pill label-light-dark font-weight-bold"><?php echo (int)$run->Created; ?></span> <span class="text-muted">of <?php echo (int)$run->Proposed; ?> proposed</span></dd>
							<dt class="col-5">AI Cost</dt>
							<dd class="col-7"><?php echo ($run->CostUsd === null || $run->CostUsd === '') ? '<span class="text-muted">-</span>' : ('$' . number_format((float)$run->CostUsd, 4)); ?><?php echo !empty($run->Model) ? ' <span class="text-muted">(' . htmlspecialchars($run->Model) . ')</span>' : ''; ?></dd>
						</dl>
					</div>
				</div>

				<?php $run_state = strtolower((string)$run->RunState); ?>
				<?php if($run_state === 'error') { ?>
					<div class="alert alert-light-danger" role="alert" style="border-left:4px solid #f64e60;">
						<strong>This run failed.</strong> <?php echo htmlspecialchars((string)$run->ErrorMessage); ?>
					</div>
				<?php } elseif($run_state === 'queued' || $run_state === 'running') { ?>
					<div class="alert alert-light-info" role="alert" style="border-left:4px solid #8950fc;">
						<i class="la la-spinner la-spin"></i> <strong><?php echo $run_state === 'queued' ? 'Queued…' : 'Generating…'; ?></strong> This run is still working. The page refreshes automatically.
					</div>
				<?php } ?>

				<div class="dataTables_wrapper dt-bootstrap4 no-footer" <?php if(empty($suggestions)) { echo 'style="overflow-x:auto;"'; } ?>>
					<table id="kt_datatable" class="table table-bordered table-head-custom table-checkable dataTable no-footer dtr-inline">
						<thead>
							<tr>
								<th style="text-align:center;">No.</th>
								<th style="text-align:center;">Title</th>
								<th style="text-align:center;">Questions</th>
								<th style="text-align:center;">Destination</th>
								<th style="text-align:center;">Detected from</th>
								<th style="text-align:center;">Status</th>
								<th style="text-align:center;">AI Cost</th>
								<th class="action" style="text-align:center;">Action</th>
							</tr>
						</thead>
						<tbody>
							<?php if(empty($suggestions)) { ?>
								<tr><td colspan="8" style="text-align:center; padding-top:10px; padding-bottom:10px;">No FAQ suggestions in this run.</td></tr>
							<?php } else { $count = 1; foreach($suggestions as $s) { ?>
								<tr>
									<td style="text-align:center; padding-top:15px; padding-bottom:15px;"><?php echo $count; ?></td>
									<td style="text-align:left;">
										<strong><?php echo htmlspecialchars($s->Title); ?></strong>
										<?php if(!empty($s->Reason)) { ?>
											<div class="text-muted mt-1" style="font-size:12px;"><i class="la la-info-circle"></i> <?php echo htmlspecialchars($s->Reason); ?></div>
										<?php } ?>
									</td>
									<td style="text-align:center;"><span class="label label-inline label-pill label-light-dark font-weight-bold"><?php echo (int)$s->QuestionCount; ?></span></td>
									<td style="text-align:center;">
										<?php
											$destination_names = ($s->Destinations === null || $s->Destinations === '') ? array() : explode('||', $s->Destinations);
											if(empty($destination_names)) {
												echo '<span class="text-muted">-</span>';
											} else {
												foreach($destination_names as $destination_name) {
													echo '<span class="label label-inline label-pill label-light-primary font-weight-bold mr-1 mb-1">' . htmlspecialchars($destination_name) . '</span>';
												}
											}
										?>
									</td>
									<td style="text-align:left;">
										<?php $evidence = isset($s->Evidence) && is_array($s->Evidence) ? $s->Evidence : array(); ?>
										<?php if(empty($evidence)) { ?>
											<span class="text-muted">—</span>
										<?php } else { ?>
											<button type="button" class="btn btn-light-primary btn-sm font-weight-bold" data-toggle="modal" data-target="#faq_evidence_modal_<?php echo (int)$s->SuggestionID; ?>">
												<i class="la la-comments"></i> View <?php echo count($evidence); ?> source<?php echo count($evidence) === 1 ? '' : 's'; ?>
											</button>
										<?php } ?>
									</td>
									<td style="text-align:center;">
										<?php
											if($s->State === 'accepted') {
												echo '<span class="label label-inline label-pill label-light-success font-weight-bold">Accepted</span>';
											} elseif($s->State === 'dismissed') {
												echo '<span class="label label-inline label-pill label-light-danger font-weight-bold">Dismissed</span>';
											} else {
												echo '<span class="label label-inline label-pill label-light-warning font-weight-bold">Pending</span>';
											}
										?>
									</td>
									<td style="text-align:center;">
										<?php if($s->CostUsd === null || $s->CostUsd === '') { ?>
											<span class="text-muted">-</span>
										<?php } else { ?>
											<span data-toggle="tooltip" title="This suggestion's share of the AI cost for this run">$<?php echo number_format((float)$s->CostUsd, 4); ?></span>
										<?php } ?>
									</td>
									<td style="text-align:center;">
										<div class="btn-group">
											<?php if($can_edit && $s->State === 'pending') { ?>
												<a href="<?php echo base_url('Faq_Suggestion/Update?id=') . (int)$s->SuggestionID; ?>" class="btn btn-icon btn-light-warning btn-sm" data-toggle="tooltip" title="Edit suggestion before accepting">
													<i class="la la-edit"></i>
												</a>
												<a href="<?php echo base_url('Faq_Suggestion/Accept?id=') . (int)$s->SuggestionID; ?>" onclick="return confirm('Create a real FAQ from this suggestion?');" class="btn btn-icon btn-light-success btn-sm ml-1" data-toggle="tooltip" title="Accept — create a FAQ from this suggestion">
													<i class="la la-check"></i>
												</a>
											<?php } ?>
											<?php if($s->State === 'accepted' && !empty($s->AcceptedFAQID)) { ?>
												<a href="<?php echo base_url('Faq/Update?faq_id=') . (int)$s->AcceptedFAQID; ?>" class="btn btn-icon btn-light-primary btn-sm ml-1" data-toggle="tooltip" title="Open the FAQ created from this suggestion">
													<i class="la la-external-link-alt"></i>
												</a>
											<?php } ?>
											<?php if($can_edit) { ?>
												<button onclick="Delete_Record('<?php echo base_url('assets/image/sweetalert.jpg'); ?>', '<?php echo 'Suggestion : ' . str_replace('\'', '', $s->Title); ?>', '<?php echo base_url('Faq_Suggestion/Delete'); ?>', 'id', <?php echo (int)$s->SuggestionID; ?>, 'Y', '<?php echo $back_url; ?>')" class="btn btn-icon btn-light-danger btn-sm ml-1" data-toggle="tooltip" title="Delete suggestion">
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

<?php foreach((isset($suggestions) ? $suggestions : array()) as $s) {
	$evidence = isset($s->Evidence) && is_array($s->Evidence) ? $s->Evidence : array();
	if(empty($evidence)) { continue; }
?>
<!-- Source evidence for one suggestion; kept outside the table so Bootstrap can render it correctly. -->
<div class="modal fade" id="faq_evidence_modal_<?php echo (int)$s->SuggestionID; ?>" tabindex="-1" role="dialog" aria-hidden="true">
	<div class="modal-dialog modal-lg modal-dialog-centered" role="document">
		<div class="modal-content">
			<div class="modal-header">
				<h5 class="modal-title"><i class="la la-comments"></i> <?php echo htmlspecialchars((string)$s->Title); ?></h5>
				<button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
			</div>
			<div class="modal-body">
				<div class="alert alert-light-info py-3 mb-4" role="alert">
					<i class="la la-info-circle mr-1"></i> Review the cited messages before accepting this FAQ. They show the evidence used by the AI, not necessarily the complete conversation.
				</div>
				<?php foreach($evidence as $source_index => $source) {
					$type = (string)$source->SourceType;
					$file_name = '';
					if($type === 'ghl_message') {
						$label = 'GHL WhatsApp conversation';
						$direction = strtolower((string)$source->MessageDirection);
						$customer = $direction === 'inbound' ? (string)$source->FromNumber : (string)$source->ToNumber;
						$conversation = (string)$source->ConversationID;
						$contact = (string)$source->ContactID;
						$when = !empty($source->MessageDate) ? date('j M Y, g:i A', strtotime($source->MessageDate)) : 'Not recorded';
						$message_label = ucfirst($direction ?: 'unknown') . ' · Message #' . (int)$source->GhlMessageID;
						$open_url = $customer !== '' ? base_url('Report/Ghl_Message_Log?all_dates=1&contact=') . urlencode($customer) : '';
					} elseif($type === 'whatsapp_history') {
						$label = 'Uploaded WhatsApp history';
						$customer = (string)$source->ChatContactKey; $conversation = 'Saved chat file'; $contact = ''; $when = 'Not recorded';
						$message_label = 'Message ' . (int)$source->MessageIndex;
						$file_name = !empty($source->ChatFileName) ? (string)$source->ChatFileName : 'Not recorded';
						$open_url = '';
					} else {
						$label = 'Directly uploaded chat file';
						$customer = ''; $conversation = 'Uploaded for this FAQ run'; $contact = ''; $when = 'Not recorded';
						$message_label = 'Message ' . (int)$source->MessageIndex;
						$file_name = (string)$run->FileName !== '' ? (string)$run->FileName : 'Uploaded chat';
						$open_url = '';
					}
				?>
					<div class="faq-evidence-item">
						<div class="faq-evidence-head d-flex justify-content-between align-items-center">
							<div><span class="label label-light-primary label-inline font-weight-bold mr-2">SOURCE <?php echo (int)$source_index + 1; ?></span><strong><?php echo htmlspecialchars($label); ?></strong></div>
							<?php if($open_url !== '') { ?><a href="<?php echo htmlspecialchars($open_url); ?>" target="_blank" class="btn btn-primary btn-sm py-1 px-3 text-nowrap"><i class="la la-external-link"></i> Open full chat</a><?php } ?>
						</div>
						<div class="faq-evidence-grid">
							<?php if($customer !== '') { ?><div class="faq-evidence-field"><label>Customer</label><div><?php echo htmlspecialchars($customer); ?></div></div><?php } ?>
							<div class="faq-evidence-field"><label>Conversation</label><div><?php echo htmlspecialchars($conversation); ?></div></div>
							<?php if($contact !== '') { ?><div class="faq-evidence-field"><label>Contact ID</label><div><?php echo htmlspecialchars($contact); ?></div></div><?php } ?>
							<?php if($file_name !== '') { ?><div class="faq-evidence-field"><label>Source file</label><div><?php echo htmlspecialchars($file_name); ?></div></div><?php } ?>
							<div class="faq-evidence-field"><label>When</label><div><?php echo htmlspecialchars($when); ?></div></div>
							<div class="faq-evidence-field"><label>Message</label><div><?php echo htmlspecialchars($message_label); ?></div></div>
						</div>
						<div class="faq-evidence-quote"><span class="text-muted font-weight-bold d-block mb-1" style="font-size:11px; letter-spacing:.02em;">CITED MESSAGE</span><?php echo htmlspecialchars((string)$source->SourceExcerpt); ?></div>
					</div>
				<?php } ?>
			</div>
			<div class="modal-footer"><button type="button" class="btn btn-light font-weight-bold" data-dismiss="modal">Close</button></div>
		</div>
	</div>
</div>
<?php } ?>

<?php if(!$is_pdf && !empty($run->InputText)) { ?>
<!-- AI Input viewer (transcript sent to the AI for this run) -->
<div class="modal fade" id="faq_input_modal" tabindex="-1" role="dialog" aria-hidden="true">
	<div class="modal-dialog modal-lg modal-dialog-centered" role="document">
		<div class="modal-content">
			<div class="modal-header">
				<h5 class="modal-title"><i class="la la-file-alt"></i> AI Input <span class="text-muted font-weight-normal ml-2"><?php echo htmlspecialchars($run->Scope); ?></span></h5>
				<button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
			</div>
			<div class="modal-body">
				<p class="text-muted">This is the exact conversation transcript that was sent to the AI to generate this run's suggestions.</p>
				<pre style="white-space:pre-wrap; word-break:break-word; max-height:60vh; overflow:auto; background:#f7f9fc; border:1px solid #e4e6ef; border-radius:6px; padding:12px; font-size:13px;"><?php echo htmlspecialchars($run->InputText); ?></pre>
			</div>
			<div class="modal-footer">
				<button type="button" class="btn btn-light font-weight-bold" data-dismiss="modal">Close</button>
			</div>
		</div>
	</div>
</div>
<?php } ?>

<script>
	$('[data-toggle="tooltip"]').tooltip();
	<?php if(in_array(strtolower((string)$run->RunState), array('queued','running'), true)) { ?>
	// The run is still processing in the background — refresh until it finishes.
	setTimeout(function() { location.reload(); }, 4000);
	<?php } ?>
</script>
