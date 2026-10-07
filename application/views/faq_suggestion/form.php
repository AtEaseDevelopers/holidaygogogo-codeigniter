<?php
	$submit_url = base_url('Faq_Suggestion/Update?id=') . (int)$suggestion->SuggestionID;
	$completed=in_array($suggestion->State,array('accepted','dismissed'),true);
	$editable=!empty($can_edit)&&!$completed;
	$esc=function($value){return htmlspecialchars((string)$value,ENT_QUOTES,'UTF-8');};
	$citations=json_decode((string)$suggestion->DraftSourcesJson,true);
?>
<div class="d-flex flex-column-fluid">
	<div class="container-fluid">
		<?php $this->load->view('faq/sections',array('active_section'=>'suggestions')); ?>
		<?php foreach(array('faq_success'=>'success','faq_error'=>'danger') as $key=>$color) { if($this->session->flashdata($key)) { ?>
			<div class="alert alert-light-<?php echo $color; ?>"><?php echo $esc($this->session->flashdata($key)); ?></div>
		<?php } } ?>
		<style>
			.faq-evidence-item { border:1px solid #b6c9df; border-radius:7px; margin-top:14px; overflow:hidden; box-shadow:0 1px 2px rgba(50, 85, 125, .06); }
			.faq-evidence-head { background:#edf3fa; border-bottom:1px solid #b6c9df; padding:10px 14px; }
			.faq-evidence-grid { display:grid; grid-template-columns:repeat(2, minmax(0, 1fr)); gap:10px 18px; padding:12px 14px; }
			.faq-evidence-field label { display:block; color:#7e8299; font-size:11px; font-weight:600; letter-spacing:.02em; margin:0 0 2px; text-transform:uppercase; }
			.faq-evidence-field div { font-size:13px; overflow-wrap:anywhere; }
			.faq-evidence-quote { background:#fcfcfd; border-top:1px solid #e4e6ef; color:#464e5f; padding:12px 14px; white-space:pre-wrap; word-break:break-word; }
			.faq-review-history { font-size:12px; }
			.faq-review-history .table th, .faq-review-history .table td { padding:6px 10px; line-height:1.35; vertical-align:top; }
			.faq-review-history .small { font-size:11px; }
			@media (max-width:575px) { .faq-evidence-grid { grid-template-columns:1fr; } }
		</style>
		<form id="faq_form" method="post" action="<?php echo $submit_url; ?>">
			<?php echo faq_workspace_csrf_field($this->session); ?>
			<input type="hidden" name="suggestion_id" value="<?php echo (int)$suggestion->SuggestionID; ?>">
			<input type="hidden" name="expected_version" value="<?php echo $esc(faq_workspace_review_version($suggestion)); ?>">

			<div class="card card-custom mb-5">
				<div class="card-header flex-wrap py-3" style="background-color:#D7E2F2;">
					<div class="card-title">
						<h3 class="card-label" style="color:#6082B6;">
							<strong>Update FAQ Suggestion</strong><small class="d-block mt-2">#<?php echo (int)$suggestion->SuggestionID; ?> <span class="label label-inline label-light-<?php echo $suggestion->State==='draft_ready'?'primary':($suggestion->State==='accepted'?'success':($suggestion->State==='dismissed'?'danger':'warning')); ?> font-weight-bold ml-2"><?php echo $esc(faq_workspace_review_label($suggestion->State)); ?></span></small>
						</h3>
					</div>
					<div class="card-toolbar">
						<a href="<?php echo isset($run_url) ? $run_url : base_url('Faq_Suggestion'); ?>" class="btn btn-light font-weight-bold" style="margin-right:6px;">
							<i class="la la-arrow-left"></i>Back
						</a>
						<?php if($editable) { ?>
							<button type="submit" name="review_action" value="save" class="btn btn-primary font-weight-bold mr-2"><i class="la la-save"></i>Save Changes</button>
							<button type="submit" name="review_action" value="approve" class="btn btn-success font-weight-bold mr-2"><i class="la la-check"></i>Save &amp; Approve</button>
							<button type="submit" name="review_action" value="reevaluate" class="btn btn-info font-weight-bold mr-2"><i class="la la-magic"></i>Save &amp; Re-evaluate</button>
							<button type="submit" form="faq-reject-form" class="btn btn-light-danger font-weight-bold">Reject</button>
						<?php } else { ?>
							<?php if(!empty($can_edit)) { ?><button type="submit" name="review_action" value="approve" class="btn btn-success font-weight-bold mr-2"><i class="la la-check"></i>Approve</button><?php } ?>
							<?php if(!empty($suggestion->AcceptedFAQID)) { ?><a class="btn btn-light-success" href="<?php echo !empty($can_edit)?base_url('Faq/Update?faq_id=').(int)$suggestion->AcceptedFAQID:base_url('Faq'); ?>">Open approved FAQ</a><?php } ?>
						<?php } ?>
					</div>
				</div>
				<div class="card-body">
					<?php if(!empty($suggestion->ReviewReason)) { ?><div class="alert alert-light-<?php echo !$completed&&$suggestion->State!=='draft_ready'?'warning':'info'; ?>"><?php echo $esc($suggestion->ReviewReason); ?></div><?php } ?>
					<div class="alert alert-light-info" role="alert" style="border-left:4px solid #8950fc;">
						<i class="la la-magic"></i> Review edits against the supporting evidence before approval. Re-evaluate if the answer needs additional information.
						<?php if(!empty($suggestion->Reason)) { ?>
							<div class="mt-2"><strong>Why suggested:</strong> <?php echo htmlspecialchars($suggestion->Reason); ?></div>
						<?php } ?>
					</div>
					<?php $evidence = isset($evidence) && is_array($evidence) ? $evidence : array(); ?>
					<?php if(!empty($faq_comparison['faq'])) {
						$related=$faq_comparison['faq'];
						$related_url=!empty($related->Slug)?base_url('faq/'.rawurlencode($related->Slug)):(!empty($can_edit)?base_url('Faq/Update?faq_id=').(int)$related->FAQID:base_url('Faq'));
						$comparison_labels=array('addition'=>'Adds information to','change'=>'Updates information in','conflict'=>'Conflicts with');
					?>
						<div class="alert alert-light-<?php echo ($faq_comparison['change_type']??'')==='conflict'?'warning':'info'; ?>">
							<strong><?php echo $esc($comparison_labels[$faq_comparison['change_type']??'']??'Related FAQ'); ?>:</strong>
							<a href="<?php echo $esc($related_url); ?>" target="_blank" rel="noopener"><?php echo $esc($related->Title); ?></a>
							<div class="mt-2">Compare the existing answer with this draft before approval.</div>
						</div>
					<?php } ?>
					<div class="mb-5">
						<strong><i class="la la-comments"></i> Detected from</strong>
						<?php if(empty($evidence)) { ?>
							<div class="text-muted mt-2">No source messages were saved for this suggestion.</div>
						<?php } else { ?>
							<button type="button" class="btn btn-light-primary btn-sm font-weight-bold ml-2" data-toggle="modal" data-target="#faq_evidence_modal">
								<i class="la la-comments"></i> View <?php echo count($evidence); ?> source<?php echo count($evidence) === 1 ? '' : 's'; ?>
							</button>
						<?php } ?>
					</div>
					<fieldset <?php echo $editable?'':'disabled'; ?>>
					<strong>FAQ Information :</strong>
					<br><br>
					<div class="row">
						<div class="col-md-4">
							<div class="form-group">
								<label>Title
									<span style="color:red;">*</span>
								</label>
								<div class="input-icon">
									<!-- Datalist: pick an existing FAQ title to fold this suggestion into
									     that FAQ on Accept, or type a new title if none fits. -->
									<input type="text" name="Title" list="faq_title_options" value="<?php echo htmlspecialchars((string)$suggestion->Title, ENT_QUOTES); ?>" autocomplete="off" class="form-control" placeholder="Choose an existing title or type a new one" required>
									<span><i class="la la-clipboard-list"></i></span>
								</div>
								<datalist id="faq_title_options">
									<?php foreach((isset($faq_titles) ? $faq_titles : array()) as $existing_title) { ?>
										<option value="<?php echo htmlspecialchars((string)$existing_title, ENT_QUOTES); ?>"></option>
									<?php } ?>
								</datalist>
								<small class="form-text text-muted">Select a relevant existing title, or type your own if none applies.</small>
							</div>
						</div>
						<div class="col-md-4">
							<div class="form-group">
								<label>Destination</label>
								<select name="Destinations[]" class="form-control selectpicker" multiple data-actions-box="true" data-live-search="true" data-live-search-style="contains" data-live-search-normalize="true" title="--SELECT DESTINATION--">
									<?php foreach($destinations as $d) { ?>
										<option data-icon="la la-map-pin font-size-lg bs-icon" value="<?php echo (int)$d->CategoryID; ?>" <?php if(in_array((int)$d->CategoryID, $selected_destination_ids, true)) { echo 'selected'; } ?>><?php echo htmlspecialchars($d->Name); ?></option>
									<?php } ?>
								</select>
							</div>
						</div>
						<div class="col-md-4">
							<div class="form-group">
								<label for="faq-review-status">Review status</label>
								<select id="faq-review-status" name="ReviewStatus" class="form-control">
									<?php if($completed) { ?>
										<option value="<?php echo $esc($suggestion->State); ?>" selected><?php echo $esc(faq_workspace_review_label($suggestion->State)); ?></option>
									<?php } else { ?>
										<option value="draft_ready" <?php echo $suggestion->State==='draft_ready'?'selected':''; ?>>Pending Approval</option>
										<option value="need_context" <?php echo $suggestion->State!=='draft_ready'?'selected':''; ?>>Needs Information</option>
									<?php } ?>
								</select>
								<?php if($editable) { ?><small class="form-text text-muted">Choose a status, then click Save Changes.</small><?php } ?>
							</div>
						</div>
						<div class="col-md-12">
							<div class="form-group">
								<div class="d-flex justify-content-between align-items-center mb-2">
									<label class="mb-0">Sub Questions &amp; Answers</label>
									<button type="button" id="faq-add-item" class="btn btn-light-primary font-weight-bold btn-sm">
										<i class="la la-plus"></i>Add Sub Q&amp;A
									</button>
								</div>
								<div id="faq-items">
									<?php foreach($items as $index => $item) { ?>
										<div class="faq-item card mb-3" style="border:1px solid #e4e6ef;">
											<div class="card-body py-3">
												<div class="d-flex justify-content-between align-items-center mb-2">
													<span class="font-weight-bold text-muted faq-item-index"></span>
													<div class="btn-group">
														<button type="button" class="btn btn-icon btn-light-danger btn-sm faq-item-remove" data-toggle="tooltip" title="Remove this sub Q&amp;A">
															<i class="la la-trash"></i>
														</button>
													</div>
												</div>
												<input type="text" name="sub_questions[]" class="form-control mb-2 faq-item-q" placeholder="Sub-question" autocomplete="off" value="<?php echo htmlspecialchars((string)$item['q'], ENT_QUOTES); ?>">
												<textarea name="sub_answers[]" rows="3" class="form-control faq-item-a" placeholder="Sub-answer"><?php echo htmlspecialchars((string)$item['a'], ENT_QUOTES); ?></textarea>
												<?php $item_tag_ids = (isset($item['tags']) && is_array($item['tags'])) ? $item['tags'] : array(); ?>
												<label class="text-muted mt-2 mb-1" style="font-size:12.5px;"><i class="la la-tag"></i> Tags for this Q&amp;A</label>
												<select name="sub_tags[<?php echo (int)$index; ?>][]" class="form-control selectpicker faq-item-tags" multiple data-actions-box="true" data-live-search="true" data-live-search-style="contains" data-live-search-normalize="true" title="--SELECT TAGS--">
													<?php foreach($tags as $t) { ?>
														<option data-icon="la la-tag font-size-lg bs-icon" value="<?php echo (int)$t->FAQTagID; ?>" <?php if(in_array((int)$t->FAQTagID, $item_tag_ids, true)) { echo 'selected'; } ?>><?php echo htmlspecialchars($t->Name); ?></option>
													<?php } ?>
												</select>
												<input type="hidden" name="sub_tags[<?php echo (int)$index; ?>][]" value="" class="faq-item-tags-empty">
												<?php $item_links = (isset($item['links']) && is_array($item['links'])) ? $item['links'] : array(); ?>
												<label class="text-muted mt-2 mb-1" style="font-size:12.5px;"><i class="la la-link"></i> Reference Links for this Q&amp;A</label>
												<div class="faq-item-links">
													<?php foreach($item_links as $lnk) { ?>
														<div class="faq-link-row d-flex align-items-center mb-2" style="gap:8px;">
															<input type="text" name="sub_link_labels[<?php echo (int)$index; ?>][]" class="form-control faq-link-label" placeholder="Link label (e.g. Booking Form)" autocomplete="off" value="<?php echo htmlspecialchars((string)(isset($lnk['l']) ? $lnk['l'] : ''), ENT_QUOTES); ?>" style="max-width:240px;">
															<input type="text" name="sub_link_urls[<?php echo (int)$index; ?>][]" class="form-control faq-link-url" placeholder="https://..." autocomplete="off" value="<?php echo htmlspecialchars((string)(isset($lnk['u']) ? $lnk['u'] : ''), ENT_QUOTES); ?>">
															<button type="button" class="btn btn-icon btn-light-danger faq-link-remove" data-toggle="tooltip" title="Remove this link"><i class="la la-times"></i></button>
														</div>
													<?php } ?>
												</div>
												<input type="hidden" name="sub_link_labels[<?php echo (int)$index; ?>][]" value="" class="faq-link-label-empty">
												<input type="hidden" name="sub_link_urls[<?php echo (int)$index; ?>][]" value="" class="faq-link-url-empty">
												<div class="mb-1">
													<button type="button" class="btn btn-light-primary btn-sm faq-link-add"><i class="la la-plus"></i>Add Link</button>
												</div>
											</div>
										</div>
									<?php } ?>
								</div>
								<small class="form-text text-muted">Each sub-question is required. Incomplete answers can be saved and re-evaluated.</small>
							</div>
						</div>
					</div>
					</fieldset>
					<?php if(!$completed) { ?><div class="mt-3 faq-knowledge-preview" data-mode="update" data-suggestion-id="<?php echo (int)$suggestion->SuggestionID; ?>" data-target="#faq-knowledge-preview" data-url="<?php echo base_url('Faq_Suggestion/Knowledge_Preview'); ?>" data-source-url="<?php echo base_url('Faq_Suggestion/Source_Detail?id='); ?>"><div id="faq-knowledge-preview" aria-live="polite">
					<?php $this->load->view('faq_suggestion/knowledge_selection',array('selection'=>$knowledge_preview??array('sources'=>array()),'open'=>true,'can_manage_sources'=>$can_manage_sources??false)); ?></div></div><?php } ?>
				</div>
			</div>
		</form>

		<?php if($editable) { ?><form id="faq-reject-form" method="post" action="<?php echo base_url('Faq_Suggestion/Dismiss'); ?>"><?php echo faq_workspace_csrf_field($this->session); ?><input type="hidden" name="suggestion_id" value="<?php echo (int)$suggestion->SuggestionID; ?>"></form><?php } ?>
		<div class="card card-custom mb-5"><div class="card-body"><h5>Supporting evidence</h5>
			<?php if(!empty($knowledge_last)) { $this->load->view('faq_suggestion/knowledge_selection',array('selection'=>$knowledge_last,'historical'=>true,'can_manage_sources'=>$can_manage_sources??false)); } ?>
			<?php foreach((array)$citations as $citation) { ?><div class="border rounded p-4 mb-3"><strong><?php echo $esc($citation['reference'].' · '.($citation['kind']==='knowledge'?'Approved Knowledge Source':'Draft evidence').' · '.$citation['title']); ?></strong><blockquote class="mt-3 mb-0" style="white-space:pre-wrap"><?php echo $esc($citation['excerpt']); ?></blockquote></div><?php } ?>
			<?php if(!$citations) { ?><p class="text-muted">Answer evidence has not been identified yet.</p><?php } ?>
			<?php if(!empty($can_manage_sources)) { ?><a class="btn btn-light-primary" href="<?php echo base_url('Faq?section=sources'); ?>">Review Knowledge Sources</a><?php } ?>
		</div></div>
		<details class="card card-custom mb-5 faq-review-history" open><summary class="px-3 py-2 font-weight-bold">Review history<span class="small text-muted font-weight-normal ml-2">Malaysia time · latest first</span></summary><div class="card-body px-3 pb-3 pt-0">
			<?php $review_history=faq_workspace_review_history($audit??array()); if($review_history) { ?>
				<div class="table-responsive">
					<table class="table table-sm table-bordered mb-0">
						<thead class="thead-light"><tr><th scope="col">Who</th><th scope="col">What happened</th><th scope="col">When</th></tr></thead>
						<tbody>
							<?php foreach($review_history as $entry) { ?>
								<tr>
									<td><strong><?php echo $esc($entry['who']); ?></strong><?php if($entry['requested_by']!=='') { ?><span class="small text-muted"> · Requested by <?php echo $esc($entry['requested_by']); ?></span><?php } ?></td>
									<td><?php echo $esc($entry['action']); ?><?php foreach($entry['details'] as $detail) { ?><span class="small text-muted"> · <?php echo $esc($detail); ?></span><?php } ?></td>
									<td class="text-nowrap"><?php echo $esc($entry['when']); ?></td>
								</tr>
							<?php } ?>
						</tbody>
					</table>
				</div>
			<?php } else { ?><p class="text-muted mb-0">No review actions have been recorded yet.</p><?php } ?>
		</div></details>

		<template id="faq-item-template">
			<div class="faq-item card mb-3" style="border:1px solid #e4e6ef;">
				<div class="card-body py-3">
					<div class="d-flex justify-content-between align-items-center mb-2">
						<span class="font-weight-bold text-muted faq-item-index"></span>
						<div class="btn-group">
							<button type="button" class="btn btn-icon btn-light-danger btn-sm faq-item-remove" data-toggle="tooltip" title="Remove this sub Q&amp;A">
								<i class="la la-trash"></i>
							</button>
						</div>
					</div>
					<input type="text" name="sub_questions[]" class="form-control mb-2 faq-item-q" placeholder="Sub-question" autocomplete="off">
					<textarea name="sub_answers[]" rows="3" class="form-control faq-item-a" placeholder="Sub-answer"></textarea>
					<label class="text-muted mt-2 mb-1" style="font-size:12.5px;"><i class="la la-tag"></i> Tags for this Q&amp;A</label>
					<select name="sub_tags[][]" class="form-control selectpicker faq-item-tags" multiple data-actions-box="true" data-live-search="true" data-live-search-style="contains" data-live-search-normalize="true" title="--SELECT TAGS--">
						<?php foreach($tags as $t) { ?>
							<option data-icon="la la-tag font-size-lg bs-icon" value="<?php echo (int)$t->FAQTagID; ?>"><?php echo htmlspecialchars($t->Name); ?></option>
						<?php } ?>
					</select>
					<input type="hidden" name="sub_tags[][]" value="" class="faq-item-tags-empty">
					<label class="text-muted mt-2 mb-1" style="font-size:12.5px;"><i class="la la-link"></i> Reference Links for this Q&amp;A</label>
					<div class="faq-item-links"></div>
					<input type="hidden" name="sub_link_labels[][]" value="" class="faq-link-label-empty">
					<input type="hidden" name="sub_link_urls[][]" value="" class="faq-link-url-empty">
					<div class="mb-1">
						<button type="button" class="btn btn-light-primary btn-sm faq-link-add"><i class="la la-plus"></i>Add Link</button>
					</div>
				</div>
			</div>
		</template>

		<template id="faq-link-template">
			<div class="faq-link-row d-flex align-items-center mb-2" style="gap:8px;">
				<input type="text" name="sub_link_labels[][]" class="form-control faq-link-label" placeholder="Link label (e.g. Booking Form)" autocomplete="off" style="max-width:240px;">
				<input type="text" name="sub_link_urls[][]" class="form-control faq-link-url" placeholder="https://..." autocomplete="off">
				<button type="button" class="btn btn-icon btn-light-danger faq-link-remove" data-toggle="tooltip" title="Remove this link"><i class="la la-times"></i></button>
			</div>
		</template>
	</div>
</div>

<?php if(!empty($evidence)) { ?>
<div class="modal fade" id="faq_evidence_modal" tabindex="-1" role="dialog" aria-hidden="true">
	<div class="modal-dialog modal-lg modal-dialog-centered" role="document">
		<div class="modal-content">
			<div class="modal-header">
				<h5 class="modal-title"><i class="la la-comments"></i> Detected from</h5>
				<button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
			</div>
			<div class="modal-body">
				<div class="alert alert-light-info py-3 mb-4" role="alert"><i class="la la-info-circle mr-1"></i> Review the cited messages used to generate this FAQ suggestion.</div>
				<?php foreach($evidence as $source_index => $source) {
					$type = (string)$source->SourceType;
					$file_name = '';
					if($type === 'ghl_message') {
						$label = 'GHL WhatsApp conversation';
						$direction = strtolower((string)$source->MessageDirection);
						$customer = $direction === 'inbound' ? (string)$source->FromNumber : (string)$source->ToNumber;
						$conversation = (string)$source->ConversationID; $contact = (string)$source->ContactID;
						$when = !empty($source->MessageDate) ? date('j M Y, g:i A', strtotime($source->MessageDate)) : 'Not recorded';
						$message_label = ucfirst($direction ?: 'unknown') . ' · Message #' . (int)$source->GhlMessageID;
						$open_url = $customer !== '' ? base_url('Report/Ghl_Message_Log?all_dates=1&contact=') . urlencode($customer) : '';
					} elseif($type === 'whatsapp_history') {
						$label = 'Uploaded WhatsApp history'; $customer = (string)$source->ChatContactKey; $conversation = 'Saved chat file'; $contact = ''; $when = 'Not recorded';
						$message_label = 'Message ' . (int)$source->MessageIndex; $file_name = !empty($source->ChatFileName) ? (string)$source->ChatFileName : 'Not recorded'; $open_url = '';
					} else {
						$label = 'Directly uploaded chat file'; $customer = ''; $conversation = 'Uploaded for this FAQ run'; $contact = ''; $when = 'Not recorded';
						$message_label = 'Message ' . (int)$source->MessageIndex; $file_name = isset($run) && $run !== null && (string)$run->FileName !== '' ? (string)$run->FileName : 'Uploaded chat'; $open_url = '';
					}
				?>
					<div class="faq-evidence-item">
						<div class="faq-evidence-head d-flex justify-content-between align-items-center"><div><span class="label label-light-primary label-inline font-weight-bold mr-2">SOURCE <?php echo (int)$source_index + 1; ?></span><strong><?php echo htmlspecialchars($label); ?></strong></div><?php if($open_url !== '') { ?><a href="<?php echo htmlspecialchars($open_url); ?>" target="_blank" class="btn btn-primary btn-sm py-1 px-3 text-nowrap"><i class="la la-external-link"></i> Open full chat</a><?php } ?></div>
						<div class="faq-evidence-grid">
							<?php if($customer !== '') { ?><div class="faq-evidence-field"><label>Customer</label><div><?php echo htmlspecialchars($customer); ?></div></div><?php } ?>
							<div class="faq-evidence-field"><label>Conversation</label><div><?php echo htmlspecialchars($conversation); ?></div></div>
							<?php if($contact !== '') { ?><div class="faq-evidence-field"><label>Contact ID</label><div><?php echo htmlspecialchars($contact); ?></div></div><?php } ?>
							<?php if($file_name !== '') { ?><div class="faq-evidence-field"><label>Source file</label><div><?php echo htmlspecialchars($file_name); ?></div></div><?php } ?>
							<div class="faq-evidence-field"><label>When</label><div><?php echo htmlspecialchars($when); ?></div></div><div class="faq-evidence-field"><label>Message</label><div><?php echo htmlspecialchars($message_label); ?></div></div>
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

<script>
	(function () {
		var list     = document.getElementById('faq-items');
		var template = document.getElementById('faq-item-template');
		var addBtn   = document.getElementById('faq-add-item');
		var linkTemplate = document.getElementById('faq-link-template');

		// Grow a sub-answer textarea to fit its full content so the whole answer is
		// always visible without an inner scrollbar. Capped so a very long answer
		// doesn't take over the page — beyond the cap it scrolls.
		function autoGrow(el) {
			if (!el) { return; }
			el.style.height = 'auto';
			var max = 600;
			el.style.height = Math.min(el.scrollHeight + 2, max) + 'px';
			el.style.overflowY = (el.scrollHeight + 2 > max) ? 'auto' : 'hidden';
		}
		function autoGrowAll(scope) {
			(scope || document).querySelectorAll('textarea.faq-item-a').forEach(autoGrow);
		}
		list.addEventListener('input', function (e) {
			if (e.target && e.target.classList.contains('faq-item-a')) { autoGrow(e.target); }
		});

		function renumber() {
			var items = list.querySelectorAll('.faq-item');
			items.forEach(function (item, i) {
				item.querySelector('.faq-item-index').textContent = '#' + (i + 1);
				item.querySelectorAll('select.faq-item-tags, .faq-item-tags-empty').forEach(function (el) {
					el.name = 'sub_tags[' + i + '][]';
				});
				item.querySelectorAll('.faq-link-label, .faq-link-label-empty').forEach(function (el) {
					el.name = 'sub_link_labels[' + i + '][]';
				});
				item.querySelectorAll('.faq-link-url, .faq-link-url-empty').forEach(function (el) {
					el.name = 'sub_link_urls[' + i + '][]';
				});
			});
		}

		function bindTooltips(scope) {
			$(scope).find('[data-toggle="tooltip"]').tooltip();
		}

		function addLink(card) {
			var wrap = card.querySelector('.faq-item-links');
			if (!wrap) { return null; }
			var node = linkTemplate.content.firstElementChild.cloneNode(true);
			wrap.appendChild(node);
			renumber();
			bindTooltips(node);
			return node;
		}

		function addItem() {
			var node = template.content.firstElementChild.cloneNode(true);
			list.appendChild(node);
			renumber();
			bindTooltips(node);
			$(node).find('.faq-item-tags').selectpicker();
			autoGrow(node.querySelector('textarea.faq-item-a'));
			node.querySelector('.faq-item-q').focus();
		}

		addBtn.addEventListener('click', addItem);

		list.addEventListener('click', function (e) {
			var linkAddBtn = e.target.closest('.faq-link-add');
			if (linkAddBtn) {
				var card = linkAddBtn.closest('.faq-item');
				var row  = addLink(card);
				if (row) { row.querySelector('.faq-link-label').focus(); }
				return;
			}
			var linkRemoveBtn = e.target.closest('.faq-link-remove');
			if (linkRemoveBtn) {
				linkRemoveBtn.closest('.faq-link-row').remove();
				renumber();
				return;
			}
			var removeBtn = e.target.closest('.faq-item-remove');
			if (removeBtn) {
				removeBtn.closest('.faq-item').remove();
				renumber();
				return;
			}
		});

		// Start with one empty row when a suggestion somehow has no items.
		if (!list.querySelector('.faq-item')) {
			addItem();
		} else {
			renumber();
			autoGrowAll(list);
		}
	})();

	$('[data-toggle="tooltip"]').tooltip();
</script>
<script src="<?php echo base_url('assets/js/faq_knowledge_preview.js?v=').filemtime(FCPATH.'assets/js/faq_knowledge_preview.js'); ?>"></script>
