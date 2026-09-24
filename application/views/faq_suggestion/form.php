<?php
	$submit_url = base_url('Faq_Suggestion/Update?id=') . (int)$suggestion->SuggestionID;
	$accept_url = base_url('Faq_Suggestion/Accept?id=') . (int)$suggestion->SuggestionID;
?>
<div class="d-flex flex-column-fluid">
	<div class="container-fluid">
		<form id="faq_form" method="post" action="<?php echo $submit_url; ?>">
			<input type="hidden" name="suggestion_id" value="<?php echo (int)$suggestion->SuggestionID; ?>">

			<div class="card card-custom mb-5">
				<div class="card-header flex-wrap py-3" style="background-color:#D7E2F2;">
					<div class="card-title">
						<h3 class="card-label" style="color:#6082B6;">
							<strong>Edit FAQ Suggestion</strong>
						</h3>
					</div>
					<div class="card-toolbar">
						<a href="<?php echo isset($run_url) ? $run_url : base_url('Faq_Suggestion'); ?>" class="btn btn-light font-weight-bold" style="margin-right:6px;">
							<i class="la la-arrow-left"></i>Back
						</a>
						<button type="submit" class="btn btn-primary font-weight-bold" style="margin-right:6px;" data-toggle="tooltip" title="Save your edits to this suggestion">
							<i class="la la-save"></i>Save Changes
						</button>
						<a href="<?php echo $accept_url; ?>" onclick="return confirm('Save your changes first, then click Accept. Accept this suggestion as a real FAQ now?');" class="btn btn-success font-weight-bold" data-toggle="tooltip" title="Accept — create a FAQ from this suggestion">
							<i class="la la-check"></i>Accept as FAQ
						</a>
					</div>
				</div>
				<div class="card-body">
					<div class="alert alert-light-info" role="alert" style="border-left:4px solid #8950fc;">
						<i class="la la-magic"></i> This is an AI-suggested FAQ mined from recent chats. Edit it to your standards, <strong>Save Changes</strong>, then <strong>Accept as FAQ</strong> to publish it to the library.
						<?php if(!empty($suggestion->Reason)) { ?>
							<div class="mt-2"><strong>Why suggested:</strong> <?php echo htmlspecialchars($suggestion->Reason); ?></div>
						<?php } ?>
					</div>
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
								<small class="form-text text-muted">Each sub-question and its sub-answer are required.</small>
							</div>
						</div>
					</div>
				</div>
			</div>
		</form>

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
