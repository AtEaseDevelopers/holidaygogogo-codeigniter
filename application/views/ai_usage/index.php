<?php
/**
 * AI Cost & Usage — owner-only dashboard summarising OpenAI spend and token usage
 * across every AI feature. Data comes from ai_usage_log (one row per call) rolled
 * up by ai_usage_helper. Read-only.
 */
$f   = $filters;
$has_filter = ($f['date_from'] !== '' || $f['date_to'] !== '' || $f['feature'] !== '');

if ( ! function_exists('aiu_breakdown_table')) {
function aiu_breakdown_table($groups, $key_label, $total_cost)
{
	if (empty($groups)) {
		echo '<div class="text-muted text-center py-5" style="font-size:12px;">No data.</div>';
		return;
	}
	?>
	<div class="table-responsive">
		<table class="table table-head-custom table-vertical-center mb-0">
			<thead>
				<tr class="text-uppercase text-muted" style="font-size:11px;">
					<th style="min-width:140px;"><?php echo htmlspecialchars($key_label); ?></th>
					<th class="text-right" style="min-width:70px;">Calls</th>
					<th class="text-right" style="min-width:100px;">Cost (USD)</th>
					<th style="min-width:120px;">Share</th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ($groups as $g) {
					$pct = $total_cost > 0 ? round(($g['cost_usd'] / $total_cost) * 100) : 0;
				?>
					<tr>
						<td class="font-weight-bolder text-dark-75" style="font-size:12px;"><?php echo htmlspecialchars($g['key']); ?></td>
						<td class="text-right text-dark-75" style="font-size:12px;"><?php echo ai_usage_fmt_int($g['calls']); ?></td>
						<td class="text-right font-weight-bolder text-dark-75" style="font-size:12px;"><?php echo ai_usage_fmt_usd($g['cost_usd']); ?></td>
						<td>
							<div class="d-flex align-items-center">
								<div class="progress progress-xs w-100 mr-2" style="height:5px;">
									<div class="progress-bar bg-primary" role="progressbar" style="width:<?php echo $pct; ?>%;"></div>
								</div>
								<span class="text-muted" style="font-size:11px; min-width:30px;"><?php echo $pct; ?>%</span>
							</div>
						</td>
					</tr>
				<?php } ?>
			</tbody>
		</table>
	</div>
	<?php
}
}

if ( ! function_exists('aiu_page_url')) {
function aiu_page_url($p, $filters)
{
	$q = array();
	foreach (array('date_from', 'date_to', 'feature') as $k) {
		if (isset($filters[$k]) && $filters[$k] !== '') {
			$q[$k] = $filters[$k];
		}
	}
	if ($p > 1) {
		$q['page'] = $p;
	}
	$qs = http_build_query($q);
	return base_url('Ai_Usage') . ($qs !== '' ? '?' . $qs : '');
}
}

$avg_cost = $summary['calls'] > 0 ? $summary['cost_usd'] / $summary['calls'] : 0;
?>

<div class="d-flex flex-column-fluid">
	<div class="container-fluid">
		<div class="card card-custom">
			<div class="card-header flex-wrap py-3" style="background-color:#D7E2F2;">
				<div class="card-title">
					<h3 class="card-label" style="color:#6082B6;">
						<i class="la la-robot" style="color:#6082B6;"></i>
						<strong>AI Cost &amp; Usage</strong>
						<span class="text-dark-50 font-weight-normal ml-2" style="font-size:12px;">OpenAI spend across all AI features</span>
					</h3>
				</div>
			</div>
			<div class="card-body">

				<!-- Filter -->
				<div class="accordion accordion-solid accordion-toggle-plus mb-5">
					<div class="card">
						<div class="card-header">
							<div id="aiu_filter_header" data-toggle="collapse" data-target="#aiu_filter" class="card-title <?php echo $has_filter ? '' : 'collapsed'; ?>" style="font-size:13px;">Filter</div>
						</div>
						<div id="aiu_filter" class="collapse <?php echo $has_filter ? 'show' : ''; ?>">
							<div class="card-body">
								<form action="<?php echo base_url('Ai_Usage'); ?>" method="get" class="form">
									<div class="row">
										<div class="col-md-4">
											<div class="form-group">
												<label>From</label>
												<input type="date" name="date_from" value="<?php echo htmlspecialchars($f['date_from'], ENT_QUOTES); ?>" class="form-control">
											</div>
										</div>
										<div class="col-md-4">
											<div class="form-group">
												<label>To</label>
												<input type="date" name="date_to" value="<?php echo htmlspecialchars($f['date_to'], ENT_QUOTES); ?>" class="form-control">
											</div>
										</div>
										<div class="col-md-4">
											<div class="form-group">
												<label>Feature</label>
												<select name="feature" class="form-control">
													<option value="">All features</option>
													<?php foreach ($all_features as $ft) { ?>
														<option value="<?php echo htmlspecialchars($ft, ENT_QUOTES); ?>" <?php if ($f['feature'] === $ft) echo 'selected'; ?>><?php echo htmlspecialchars($ft); ?></option>
													<?php } ?>
												</select>
											</div>
										</div>
									</div>
									<input type="submit" value="Filter" class="btn btn-light-success font-weight-bold" style="width:90px;">
									<a href="<?php echo base_url('Ai_Usage'); ?>" class="btn btn-light-primary font-weight-bold" style="width:90px;">Reset</a>
								</form>
							</div>
						</div>
					</div>
				</div>

				<!-- KPI cards -->
				<div class="row mb-2">
					<div class="col-md-4">
						<div class="card card-custom bg-light-primary mb-5">
							<div class="card-body p-5">
								<span class="text-primary font-weight-bolder d-block" style="font-size:26px; line-height:1;"><?php echo ai_usage_fmt_usd($summary['cost_usd']); ?></span>
								<span class="text-muted font-weight-bold mt-2 d-block" style="font-size:12px;">Total Cost (USD)</span>
							</div>
						</div>
					</div>
					<div class="col-md-4">
						<div class="card card-custom bg-light-info mb-5">
							<div class="card-body p-5">
								<span class="text-info font-weight-bolder d-block" style="font-size:26px; line-height:1;"><?php echo ai_usage_fmt_int($summary['calls']); ?></span>
								<span class="text-muted font-weight-bold mt-2 d-block" style="font-size:12px;">AI Calls</span>
							</div>
						</div>
					</div>
					<div class="col-md-4">
						<div class="card card-custom bg-light-success mb-5">
							<div class="card-body p-5">
								<span class="text-success font-weight-bolder d-block" style="font-size:26px; line-height:1;"><?php echo ai_usage_fmt_usd($avg_cost); ?></span>
								<span class="text-muted font-weight-bold mt-2 d-block" style="font-size:12px;">Avg Cost / Call</span>
							</div>
						</div>
					</div>
				</div>

				<!-- Breakdowns -->
				<div class="row">
					<div class="col-lg-6">
						<div class="mb-3 font-weight-bolder text-dark-75" style="font-size:13px;"><i class="la la-th-large mr-1"></i> By Feature</div>
						<?php aiu_breakdown_table($by_feature, 'Feature', $summary['cost_usd']); ?>
					</div>
					<div class="col-lg-6">
						<div class="mb-3 font-weight-bolder text-dark-75" style="font-size:13px;"><i class="la la-calendar mr-1"></i> By Month</div>
						<?php aiu_breakdown_table($by_month, 'Month', $summary['cost_usd']); ?>
					</div>
				</div>

				<!-- Detailed log -->
				<div class="separator separator-dashed my-7"></div>
				<div class="mb-3 font-weight-bolder text-dark-75" style="font-size:13px;">
					<i class="la la-list mr-1"></i> Call Log
					<span class="text-muted font-weight-normal ml-1" style="font-size:11px;">(<?php echo ai_usage_fmt_int($summary['calls']); ?> calls)</span>
				</div>
				<?php if ($total == 0) { ?>
					<div class="text-center text-muted py-10">
						<i class="la la-inbox" style="font-size:38px; opacity:.4;"></i>
						<div class="mt-2 font-weight-bold">No AI usage recorded<?php echo $has_filter ? ' for this filter' : ' yet'; ?></div>
						<div style="font-size:12px;">AI calls are logged automatically as features are used.</div>
					</div>
				<?php } else { ?>
					<div class="table-responsive">
						<table class="table table-head-custom table-vertical-center">
							<thead>
								<tr class="text-uppercase text-muted" style="font-size:11px;">
									<th style="min-width:140px;">When</th>
									<th style="min-width:150px;">Feature</th>
									<th style="min-width:120px;">User</th>
									<th class="text-right" style="min-width:100px;">Cost (USD)</th>
									<th style="min-width:70px;">Status</th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ($log_rows as $r) {
									$status = isset($r['status']) ? (string) $r['status'] : 'ok';
									$badge  = $status === 'ok' ? 'label-light-success' : 'label-light-danger';
									$uname  = isset($r['created_by_name']) ? trim((string) $r['created_by_name']) : '';
								?>
									<tr>
										<td class="text-muted" style="font-size:12px;"><?php echo date('d M Y, H:i', strtotime($r['created_at'])); ?></td>
										<td class="font-weight-bold text-dark-75" style="font-size:12px;"><?php echo htmlspecialchars($r['feature']); ?></td>
										<td class="text-dark-75" style="font-size:12px;"><?php echo htmlspecialchars($uname !== '' ? $uname : '—'); ?></td>
										<td class="text-right font-weight-bolder text-dark-75" style="font-size:12px;"><?php echo ai_usage_fmt_usd($r['cost_usd']); ?></td>
										<td><span class="label <?php echo $badge; ?> label-inline font-weight-bold" style="font-size:10px;"><?php echo htmlspecialchars($status); ?></span></td>
									</tr>
								<?php } ?>
							</tbody>
						</table>
					</div>
					<?php if ($total_pages > 1) {
						$win_start = max(1, $page - 3);
						$win_end   = min($total_pages, $page + 3);
					?>
						<div class="d-flex justify-content-between align-items-center mt-3 flex-wrap">
							<span class="text-muted" style="font-size:12px;">
								Showing <?php echo ai_usage_fmt_int($offset + 1); ?>–<?php echo ai_usage_fmt_int(min($offset + $per_page, $total)); ?> of <?php echo ai_usage_fmt_int($total); ?>
							</span>
							<ul class="pagination pagination-sm mb-0">
								<li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>">
									<a class="page-link" href="<?php echo aiu_page_url($page - 1, $f); ?>">‹</a>
								</li>
								<?php if ($win_start > 1) { ?>
									<li class="page-item"><a class="page-link" href="<?php echo aiu_page_url(1, $f); ?>">1</a></li>
									<?php if ($win_start > 2) { ?><li class="page-item disabled"><span class="page-link">…</span></li><?php } ?>
								<?php } ?>
								<?php for ($p = $win_start; $p <= $win_end; $p++) { ?>
									<li class="page-item <?php echo $p == $page ? 'active' : ''; ?>">
										<a class="page-link" href="<?php echo aiu_page_url($p, $f); ?>"><?php echo $p; ?></a>
									</li>
								<?php } ?>
								<?php if ($win_end < $total_pages) { ?>
									<?php if ($win_end < $total_pages - 1) { ?><li class="page-item disabled"><span class="page-link">…</span></li><?php } ?>
									<li class="page-item"><a class="page-link" href="<?php echo aiu_page_url($total_pages, $f); ?>"><?php echo $total_pages; ?></a></li>
								<?php } ?>
								<li class="page-item <?php echo $page >= $total_pages ? 'disabled' : ''; ?>">
									<a class="page-link" href="<?php echo aiu_page_url($page + 1, $f); ?>">›</a>
								</li>
							</ul>
						</div>
					<?php } ?>
				<?php } ?>

			</div>
		</div>
	</div>
</div>
