<?php $can_edit = isset($can_edit) ? $can_edit : ((int)$this->session->level === 10); // OWNER or FAQ EDIT ACCESS (FE) ?>

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
	.faq-sugg-tabs .btn { margin-right: 6px; }
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
						<a href="<?php echo base_url('Faq_Suggestion/Generate'); ?>" id="faq_sugg_generate" class="btn btn-primary font-weight-bold ml-2" data-toggle="tooltip" title="Analyse the last few days of WhatsApp / GHL chats now and propose new FAQs">
							<i class="la la-magic"></i>Generate Now
						</a>
					<?php } ?>
				</div>
			</div>
			<div class="card-body">
				<p class="text-muted" style="margin-top:-6px;">
					These candidate FAQs are mined automatically every 3 days from the last few days of WhatsApp &amp; GHL conversations. Review, edit, then accept a suggestion to add it to the FAQ library.
				</p>

				<?php
					$tabs = array('pending' => 'Pending', 'accepted' => 'Accepted', 'all' => 'All');
				?>
				<div class="faq-sugg-tabs mb-4">
					<?php foreach($tabs as $key => $label) { ?>
						<a href="<?php echo base_url('Faq_Suggestion?state=') . $key; ?>" class="btn btn-sm font-weight-bold <?php echo ($state === $key) ? 'btn-primary' : 'btn-light'; ?>"><?php echo $label; ?></a>
					<?php } ?>
				</div>

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
								<th style="text-align:center;">Suggested</th>
								<th class="action" style="text-align:center;">Action</th>
							</tr>
						</thead>
						<tbody>
							<?php if(empty($suggestions)) { ?>
								<tr><td colspan="8" style="text-align:center; padding-top:10px; padding-bottom:10px;">No FAQ Suggestions Found</td></tr>
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
										<span data-toggle="tooltip" title="This suggestion's share of the AI cost for its generation run">$<?php echo number_format((float)$s->CostUsd, 4); ?></span>
									<?php } ?>
								</td>
								<td style="text-align:center;"><?php echo htmlspecialchars($s->InsertDate ? date('j M Y', strtotime($s->InsertDate)) : '-'); ?></td>
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
												<button onclick="Delete_Record('<?php echo base_url('assets/image/sweetalert.jpg'); ?>', '<?php echo 'Suggestion : ' . str_replace('\'', '', $s->Title); ?>', '<?php echo base_url('Faq_Suggestion/Delete'); ?>', 'id', <?php echo (int)$s->SuggestionID; ?>, 'Y', '<?php echo base_url('Faq_Suggestion?state=') . $state; ?>')" class="btn btn-icon btn-light-danger btn-sm ml-1" data-toggle="tooltip" title="Delete suggestion">
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

	// The manual "Generate Now" runs a synchronous OpenAI call that can take a
	// while — confirm once, then show a spinner so the user doesn't double-click.
	$('#faq_sugg_generate').on('click', function(e) {
		if(!window.confirm('Analyse the last few days of chats and propose new FAQs now? This can take up to a minute.')) {
			e.preventDefault();
			return false;
		}
		$(this).addClass('disabled').html('<i class="la la-spinner la-spin"></i>Generating...');
	});
</script>
