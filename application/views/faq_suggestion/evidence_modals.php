<style>
	.faq-evidence-item { border:1px solid #b6c9df; border-radius:7px; margin-top:14px; overflow:hidden; box-shadow:0 1px 2px rgba(50, 85, 125, .06); }
	.faq-evidence-head { background:#edf3fa; border-bottom:1px solid #b6c9df; padding:10px 14px; }
	.faq-evidence-grid { display:grid; grid-template-columns:repeat(2, minmax(0, 1fr)); gap:10px 18px; padding:12px 14px; }
	.faq-evidence-field { min-width:0; }
	.faq-evidence-field label { display:block; color:#7e8299; font-size:11px; font-weight:600; letter-spacing:.02em; margin:0 0 2px; text-transform:uppercase; }
	.faq-evidence-field div { font-size:13px; overflow-wrap:anywhere; }
	.faq-evidence-quote { background:#fcfcfd; border-top:1px solid #e4e6ef; color:#464e5f; padding:12px 14px; white-space:pre-wrap; word-break:break-word; }
	@media (max-width:575px) { .faq-evidence-grid { grid-template-columns:1fr; } }
</style>

<?php foreach((isset($suggestions) ? $suggestions : array()) as $s) {
	$evidence = isset($s->Evidence) && is_array($s->Evidence) ? $s->Evidence : array();
	if(empty($evidence)) { continue; }
?>
<!-- Source evidence for one suggestion; kept outside the table so Bootstrap can render it correctly. -->
<div class="modal fade faq-evidence-modal" id="faq_evidence_modal_<?php echo (int)$s->SuggestionID; ?>" tabindex="-1" role="dialog" aria-hidden="true">
	<div class="modal-dialog modal-lg modal-dialog-centered" role="document">
		<div class="modal-content">
			<div class="modal-header">
				<h5 class="modal-title"><i class="la la-comments"></i> <?php echo htmlspecialchars((string)$s->Title); ?></h5>
				<button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
			</div>
			<div class="modal-body">
				<div class="alert alert-light-info py-3 mb-4" role="alert">
					<i class="la la-info-circle mr-1"></i> Review the cited messages before accepting this FAQ. They show the cited evidence used by the AI.
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
					} elseif($type === 'staff_information' || $type === 'knowledge') {
						$label = $type === 'knowledge' ? 'Knowledge Source' : 'Staff-provided information';
						$customer = ''; $conversation = $type === 'knowledge' ? (string)$source->SourceTitle : 'Added during re-evaluation';
						$contact = ''; $when = 'Not recorded'; $message_label = (string)$source->SourceRef; $open_url = '';
					} else {
						$label = 'Directly uploaded chat file';
						$customer = ''; $conversation = 'Uploaded for this FAQ run'; $contact = ''; $when = 'Not recorded';
						$message_label = 'Message ' . (int)$source->MessageIndex;
						$file_name = !empty($source->RunFileName) ? (string)$source->RunFileName : (!empty($run->FileName) ? (string)$run->FileName : 'Uploaded chat');
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

