<?php if($can_edit) {
	$today      = date('Y-m-d');
	$week_start = date('Y-m-d', strtotime('-7 days'));
?>
<!-- Generate from chats -->
<div class="modal fade" id="faq_sugg_generate_modal" tabindex="-1" role="dialog" aria-hidden="true">
	<div class="modal-dialog modal-dialog-centered" role="document">
		<form method="post" action="<?php echo base_url('Faq_Suggestion/Generate'); ?>" id="faq_sugg_generate_form">
			<?php echo faq_workspace_csrf_field($this->session); ?>
			<div class="modal-content">
				<div class="modal-header">
					<h5 class="modal-title"><i class="la la-magic"></i> Generate from Chats</h5>
					<button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
				</div>
				<div class="modal-body">
					<p class="text-muted">Analyse WhatsApp &amp; GHL chats within a date range and propose new FAQs. Leave the mobile number blank to include everyone. AI identifies topics, drafts answers from the chats and marks each suggestion Pending Approval or Needs Information. Attach a destination and re-evaluate to use its Knowledge Sources.</p>
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
			<?php echo faq_workspace_csrf_field($this->session); ?>
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
			<?php echo faq_workspace_csrf_field($this->session); ?>
			<div class="modal-content">
				<div class="modal-header">
					<h5 class="modal-title"><i class="la la-comments"></i> Generate from Chat File</h5>
					<button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
				</div>
				<div class="modal-body">
					<p class="text-muted">Upload a chat export as <strong>.txt</strong> (text only, without media), or a <strong>.zip</strong> bundling several exports. AI drafts FAQs from the chats and marks each suggestion Pending Approval or Needs Information. Attach a destination and re-evaluate to use its Knowledge Sources. Max 5 MB per .txt, 30 MB per .zip.</p>
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
$('#faq_sugg_generate_form, #faq_sugg_pdf_form, #faq_sugg_chatfile_form').on('submit', function() {
    $(this).find('button[type="submit"]').prop('disabled', true).text('Starting…');
});
</script>
