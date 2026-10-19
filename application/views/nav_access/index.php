<?php
	$level_names = unserialize(LEVEL);
?>
<div class="d-flex flex-column-fluid">
	<div class="container-fluid">
		<div class="card card-custom mb-5">
			<div class="card-header flex-wrap py-3" style="background-color:#D7E2F2;">
				<div class="card-title">
					<h3 class="card-label" style="color:#6082B6;">
						<strong>Navigation Access</strong>
					</h3>
				</div>
				<div class="card-toolbar">
					<button type="button" id="save_nav_access" class="btn btn-primary font-weight-bold" style="width:160px;">
						<i class="la la-save"></i>Save Changes
					</button>
				</div>
			</div>
			<div class="card-body">
				<p class="text-muted" style="font-size:13px;">
					Choose who can <strong>view</strong> (see the item on the sidebar and open the page) and
					<strong>edit</strong> (add/change/delete) each page. Checkboxes start as each staff
					member's current access. After you save, those choices become the source of truth.
					The Owner always has full access and is not listed. Ticking <strong>Edit</strong>
					automatically grants <strong>View</strong>. Unticking View hides that item from their sidebar.
				</p>
				<ul class="nav nav-tabs nav-tabs-line nav-tabs-bold" role="tablist">
					<?php $gi = 0; foreach ($groups as $group => $keys) { ?>
					<li class="nav-item">
						<a class="nav-link <?php if ($gi === 0) echo 'active'; ?>" data-toggle="tab" href="#nav-group-<?php echo $gi; ?>" role="tab">
							<?php echo htmlspecialchars($group, ENT_QUOTES); ?>
						</a>
					</li>
					<?php $gi++; } ?>
				</ul>
				<div class="tab-content pt-5">
					<?php $gi = 0; foreach ($groups as $group => $keys) { ?>
					<div class="tab-pane fade <?php if ($gi === 0) echo 'show active'; ?>" id="nav-group-<?php echo $gi; ?>" role="tabpanel">
						<div class="dataTables_wrapper dt-bootstrap4 no-footer" style="overflow-x:auto;">
							<table class="table table-bordered table-head-custom table-checkable" style="min-width:720px;">
								<thead>
									<tr>
										<th rowspan="2" style="text-align:center; vertical-align:middle;">Staff</th>
										<?php foreach ($keys as $mk) { ?>
										<th colspan="2" style="text-align:center;"><?php echo htmlspecialchars($modules[$mk]['label'], ENT_QUOTES); ?></th>
										<?php } ?>
									</tr>
									<tr>
										<?php foreach ($keys as $mk) { ?>
										<th style="text-align:center; font-weight:500;">View</th>
										<th style="text-align:center; font-weight:500;">Edit</th>
										<?php } ?>
									</tr>
								</thead>
								<tbody>
									<?php if (empty($admins)) { ?>
									<tr><td colspan="<?php echo 1 + count($keys) * 2; ?>" style="text-align:center; padding:15px;">No staff found.</td></tr>
									<?php } else { foreach ($admins as $a) {
										$aid = (int) $a->AdminID;
									?>
									<tr>
										<td style="padding-top:14px; padding-bottom:14px;">
											<span class="font-weight-bold"><?php echo htmlspecialchars($a->Name, ENT_QUOTES); ?></span>
											<span class="label label-light-primary label-inline ml-2" style="font-size:11px;"><?php echo isset($level_names[$a->Level]) ? $level_names[$a->Level] : $a->Level; ?></span>
										</td>
										<?php foreach ($keys as $mk) {
											$can_view = ! empty($a->access[$mk]['view']);
											$can_edit = ! empty($a->access[$mk]['edit']);
										?>
										<td style="text-align:center; vertical-align:middle;">
											<label class="checkbox checkbox-lg justify-content-center mb-0">
												<input type="checkbox" class="nav-view" data-aid="<?php echo $aid; ?>" data-mod="<?php echo $mk; ?>" <?php if ($can_view) echo 'checked'; ?>>
												<span></span>
											</label>
										</td>
										<td style="text-align:center; vertical-align:middle;">
											<label class="checkbox checkbox-lg justify-content-center mb-0">
												<input type="checkbox" class="nav-edit" data-aid="<?php echo $aid; ?>" data-mod="<?php echo $mk; ?>" <?php if ($can_edit) echo 'checked'; ?>>
												<span></span>
											</label>
										</td>
										<?php } ?>
									</tr>
									<?php } } ?>
								</tbody>
							</table>
						</div>
					</div>
					<?php $gi++; } ?>
				</div>
			</div>
		</div>
	</div>
</div>

<script>
	$(document).on('change', '.nav-edit', function() {
		if ($(this).is(':checked')) {
			var aid = $(this).data('aid'), mod = $(this).data('mod');
			$('.nav-view[data-aid="' + aid + '"][data-mod="' + mod + '"]').prop('checked', true);
		}
	});
	$(document).on('change', '.nav-view', function() {
		if (!$(this).is(':checked')) {
			var aid = $(this).data('aid'), mod = $(this).data('mod');
			$('.nav-edit[data-aid="' + aid + '"][data-mod="' + mod + '"]').prop('checked', false);
		}
	});

	$('#save_nav_access').click(function() {
		var access = {};
		$('.nav-view').each(function() {
			var aid = $(this).data('aid'), mod = $(this).data('mod');
			access[aid] = access[aid] || {};
			access[aid][mod] = access[aid][mod] || {};
			access[aid][mod]['view'] = $(this).is(':checked') ? 1 : 0;
		});
		$('.nav-edit').each(function() {
			var aid = $(this).data('aid'), mod = $(this).data('mod');
			access[aid] = access[aid] || {};
			access[aid][mod] = access[aid][mod] || {};
			access[aid][mod]['edit'] = $(this).is(':checked') ? 1 : 0;
		});

		$.ajax({
			url: '<?php echo base_url('Nav_Access/Save'); ?>',
			type: 'post',
			data: { access: access },
			success: function(resp) {
				var ok = (resp === true || resp === 'true' || resp === '1' || resp === 1);
				try { ok = ok || JSON.parse(resp) === true; } catch (e) {}
				if (ok) {
					Display_Message('<?php echo base_url('assets/image/sweetalert.jpg') ?>', 'Access Updated Successfully', '<?php echo base_url('Nav_Access') ?>');
				} else {
					Display_Message('<?php echo base_url('assets/image/sweetalert.jpg') ?>', 'Access Could Not Be Updated', null);
				}
			},
			error: function() {
				Display_Message('<?php echo base_url('assets/image/sweetalert.jpg') ?>', 'Access Could Not Be Updated', null);
			}
		});
	});
</script>
