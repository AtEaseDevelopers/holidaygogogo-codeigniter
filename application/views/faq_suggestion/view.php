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
								<th style="text-align:center;">Status</th>
								<th style="text-align:center;">AI Cost</th>
								<th class="action" style="text-align:center;">Action</th>
							</tr>
						</thead>
						<tbody>
							<?php if(empty($suggestions)) { ?>
								<tr><td colspan="7" style="text-align:center; padding-top:10px; padding-bottom:10px;">No FAQ suggestions in this run.</td></tr>
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

<script>
	$('[data-toggle="tooltip"]').tooltip();
	<?php if(in_array(strtolower((string)$run->RunState), array('queued','running'), true)) { ?>
	// The run is still processing in the background — refresh until it finishes.
	setTimeout(function() { location.reload(); }, 4000);
	<?php } ?>
</script>
