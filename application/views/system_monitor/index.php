<?php
/**
 * System Monitor — PUBLIC, code-gated page (standalone "control-room" dark
 * dashboard, no admin chrome). Scoped to THIS project on a shared host. Each
 * metric has its own accent colour (CPU=cyan, Memory=violet, Disk=amber,
 * DB=emerald) so the cards stay visually distinct; health is shown separately
 * by an OK/WARNING/CRITICAL status pill. Cards live-refresh by polling
 * System_Monitor/stats. All formatting goes through system_monitor_helper.
 */
$m = $metrics;

if ( ! function_exists('sm_pill_class')) {
	function sm_pill_class($status)
	{
		switch ($status) {
			case 'critical': return 'pill-crit';
			case 'warning':  return 'pill-warn';
			default:         return 'pill-ok';
		}
	}
}

$project   = isset($m['project']) ? $m['project'] : array();
$host_cpu  = $m['host_cpu'];
$host_mem  = $m['host_memory'];
$host_disk = $m['host_disk'];
$sql       = isset($m['sql']) ? $m['sql'] : null;

$proj_cpu   = isset($project['cpu']) ? $project['cpu'] : null;
$proj_mem   = isset($project['memory']) ? $project['memory'] : null;
$proj_disk  = isset($project['disk_bytes']) ? $project['disk_bytes'] : null;
$proc_count = isset($project['proc_count']) ? (int) $project['proc_count'] : 0;

// CPU — project if processes could be attributed, else host (shared).
if ($proj_cpu) {
	$cpu = $proj_cpu; $cpu_scope = 'This Project';
	$cpu_meta = $proc_count . ' processes · host ' . $host_cpu['percent'] . '% of ' . $host_cpu['cores'] . ' cores';
} else {
	$cpu = $host_cpu; $cpu_scope = 'Host · shared';
	$cpu_meta = $host_cpu['cores'] . ' cores · load ' . $host_cpu['load_1'] . ' / ' . $host_cpu['load_5'] . ' / ' . $host_cpu['load_15'];
}

// Memory.
$host_mem_sub = is_array($host_mem)
	? system_monitor_format_bytes($host_mem['used_bytes']) . ' / ' . system_monitor_format_bytes($host_mem['total_bytes'])
	: 'n/a';
if ($proj_mem) {
	$mem = $proj_mem; $mem_scope = 'This Project';
	$mem_meta = system_monitor_format_bytes($proj_mem['used_bytes']) . ' · ' . $proc_count . ' procs · host ' . $host_mem_sub;
} else {
	$mem = $host_mem; $mem_scope = 'Host · shared';
	$mem_meta = is_array($host_mem) ? ($host_mem_sub . ' used') : 'unavailable on this host';
}

$disk_pct  = is_array($host_disk) ? (float) $host_disk['percent'] : 0;
$disk_meta = is_array($host_disk)
	? ('host disk ' . $host_disk['percent'] . '% · ' . system_monitor_format_bytes($host_disk['available_bytes']) . ' free of ' . system_monitor_format_bytes($host_disk['total_bytes']))
	: 'host disk info unavailable';
?>
<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
	<meta name="robots" content="noindex, nofollow">
	<title>System Monitor · HolidayGoGoGo</title>
	<link href="<?php echo base_url('assets/image/favicon.png'); ?>" rel="icon">
	<link href="<?php echo base_url('assets/css/plugins-bundle.css'); ?>" type="text/css" rel="stylesheet">
	<link rel="preconnect" href="https://fonts.googleapis.com">
	<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
	<link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Mono:wght@400;500;600;700&family=IBM+Plex+Sans:wght@400;500;600&display=swap" rel="stylesheet">
	<style>
		:root{
			--bg:#eef1f7; --panel:#ffffff; --panel-2:#f7f9fc; --line:#e6e9f1;
			--ink:#1e2635; --mut:#64748b; --mut-2:#94a3b8;
			--cyan:#0891b2; --violet:#7c3aed; --amber:#d97706; --emerald:#059669;
			--ok:#059669; --warn:#c2730b; --crit:#dc2626;
		}
		*{box-sizing:border-box;}
		html,body{margin:0;padding:0;}
		body{
			background:
				radial-gradient(1100px 560px at 85% -12%, rgba(8,145,178,.07), transparent 60%),
				radial-gradient(900px 520px at -5% -5%, rgba(124,58,237,.06), transparent 55%),
				var(--bg);
			background-attachment:fixed;
			color:var(--ink);
			font-family:'IBM Plex Sans',system-ui,sans-serif;
			-webkit-font-smoothing:antialiased;
			min-height:100vh;
		}
		body::before{ /* faint grid texture */
			content:'';position:fixed;inset:0;pointer-events:none;opacity:.6;
			background-image:linear-gradient(var(--line) 1px,transparent 1px),linear-gradient(90deg,var(--line) 1px,transparent 1px);
			background-size:46px 46px;mask-image:radial-gradient(circle at 50% 20%,#000,transparent 80%);
		}
		.mono{font-family:'IBM Plex Mono',ui-monospace,monospace;}
		.wrap{position:relative;max-width:1180px;margin:0 auto;padding:34px 22px 60px;}

		/* ---- header ---- */
		.top{display:flex;align-items:flex-start;justify-content:space-between;gap:20px;flex-wrap:wrap;margin-bottom:26px;}
		.brand h1{font-size:13px;letter-spacing:.42em;text-transform:uppercase;margin:0;color:var(--ink);font-weight:600;}
		.brand .sub{display:flex;align-items:center;gap:8px;margin-top:8px;color:var(--mut);font-size:12.5px;}
		.dot{width:8px;height:8px;border-radius:50%;background:var(--emerald);box-shadow:0 0 0 0 rgba(52,211,153,.6);animation:pulse 2s infinite;}
		@keyframes pulse{0%{box-shadow:0 0 0 0 rgba(52,211,153,.5);}70%{box-shadow:0 0 0 7px rgba(52,211,153,0);}100%{box-shadow:0 0 0 0 rgba(52,211,153,0);}}
		.actions{display:flex;gap:10px;}
		.btn{display:inline-flex;align-items:center;gap:7px;font-family:'IBM Plex Mono',monospace;font-size:11.5px;letter-spacing:.08em;text-transform:uppercase;
			padding:9px 15px;border-radius:9px;border:1px solid var(--line);background:var(--panel);color:var(--ink);cursor:pointer;text-decoration:none;transition:.16s;box-shadow:0 1px 2px rgba(15,23,42,.05);}
		.btn:hover{border-color:#cbd3e1;background:#f1f4f9;color:#0f172a;}
		.btn-lock{color:var(--crit);}
		.btn-lock:hover{border-color:rgba(220,38,38,.45);color:#b91c1c;background:rgba(220,38,38,.08);}
		.btn i{font-size:15px;}

		/* ---- meta strip ---- */
		.strip{display:grid;grid-template-columns:repeat(4,1fr);gap:1px;background:var(--line);border:1px solid var(--line);border-radius:12px;overflow:hidden;margin-bottom:22px;}
		.strip .cell{background:var(--panel-2);padding:14px 16px;}
		.strip .k{font-size:9.5px;letter-spacing:.2em;text-transform:uppercase;color:var(--mut-2);}
		.strip .v{font-size:13.5px;margin-top:4px;color:var(--ink);font-weight:500;}

		/* ---- cards ---- */
		.grid{display:grid;grid-template-columns:repeat(3,1fr);gap:16px;}
		.card{position:relative;background:linear-gradient(180deg,var(--panel),var(--panel-2));border:1px solid var(--line);border-radius:16px;padding:20px;overflow:hidden;box-shadow:0 1px 3px rgba(15,23,42,.05),0 10px 28px rgba(15,23,42,.05);}
		.card::before{content:'';position:absolute;top:0;left:0;right:0;height:3px;background:var(--accent);opacity:1;}
		.card::after{content:'';position:absolute;top:-60px;right:-60px;width:160px;height:160px;border-radius:50%;background:var(--accent);filter:blur(60px);opacity:.1;}
		.chead{display:flex;align-items:center;gap:12px;position:relative;z-index:1;}
		.chip{width:38px;height:38px;border-radius:11px;display:grid;place-items:center;font-size:19px;color:var(--accent);background:color-mix(in srgb,var(--accent) 16%,transparent);border:1px solid color-mix(in srgb,var(--accent) 34%,transparent);}
		.ctitle{font-size:14px;font-weight:600;letter-spacing:.02em;}
		.cscope{font-size:10.5px;letter-spacing:.14em;text-transform:uppercase;color:var(--mut-2);margin-top:2px;}
		.pill{margin-left:auto;font-family:'IBM Plex Mono',monospace;font-size:10px;letter-spacing:.12em;padding:5px 10px;border-radius:999px;font-weight:600;}
		.pill-ok{color:var(--ok);background:rgba(52,211,153,.12);border:1px solid rgba(52,211,153,.32);}
		.pill-warn{color:var(--warn);background:rgba(245,185,66,.12);border:1px solid rgba(245,185,66,.34);}
		.pill-crit{color:var(--crit);background:rgba(248,113,111,.14);border:1px solid rgba(248,113,111,.4);animation:blink 1.3s infinite;}
		@keyframes blink{50%{opacity:.55;}}

		/* ring gauge */
		.ring-wrap{display:flex;justify-content:center;margin:20px 0 14px;}
		.ring{--pct:0;position:relative;width:148px;height:148px;border-radius:50%;
			background:conic-gradient(var(--accent) calc(var(--pct)*1%), #e9edf4 0);
			transition:background .6s ease;}
		.ring::before{content:'';position:absolute;inset:12px;border-radius:50%;background:var(--panel);box-shadow:inset 0 0 14px rgba(15,23,42,.07);}
		.ring .inner{position:absolute;inset:0;display:grid;place-content:center;text-align:center;}
		.ring .num{font-family:'IBM Plex Mono',monospace;font-size:34px;font-weight:600;line-height:1;color:var(--ink);}
		.ring .unit{font-family:'IBM Plex Mono',monospace;font-size:15px;color:var(--mut);margin-left:1px;}

		/* disk big-number body */
		.bignum{text-align:center;margin:26px 0 12px;}
		.bignum .n{font-family:'IBM Plex Mono',monospace;font-size:40px;font-weight:600;color:var(--ink);line-height:1;}
		.bignum .l{font-size:11px;letter-spacing:.16em;text-transform:uppercase;color:var(--mut-2);margin-top:9px;}
		.bar{height:8px;border-radius:999px;background:#e9edf4;overflow:hidden;margin:14px 0 2px;}
		.bar>span{display:block;height:100%;border-radius:999px;background:var(--accent);transition:width .6s ease;}

		.cmeta{font-size:11.5px;color:var(--mut);text-align:center;line-height:1.5;position:relative;z-index:1;}
		.card--disk .cmeta{margin-top:10px;}

		/* ---- database panel ---- */
		.panel{margin-top:16px;background:var(--panel);border:1px solid var(--line);border-radius:16px;overflow:hidden;position:relative;box-shadow:0 1px 3px rgba(15,23,42,.05),0 10px 28px rgba(15,23,42,.05);}
		.panel::before{content:'';position:absolute;top:0;left:0;right:0;height:3px;background:var(--emerald);opacity:1;}
		.phead{display:flex;align-items:center;gap:12px;padding:18px 22px;border-bottom:1px solid var(--line);}
		.phead .srv{margin-left:auto;font-family:'IBM Plex Mono',monospace;font-size:11.5px;color:var(--mut);}
		.tiles{display:grid;grid-template-columns:repeat(3,1fr);gap:1px;background:var(--line);}
		.tile{background:var(--panel-2);padding:18px 20px;}
		.tile .k{font-size:9.5px;letter-spacing:.2em;text-transform:uppercase;color:var(--mut-2);}
		.tile .v{font-family:'IBM Plex Mono',monospace;font-size:26px;font-weight:600;margin-top:7px;color:var(--ink);}
		.qh{padding:16px 22px 6px;font-size:11px;letter-spacing:.14em;text-transform:uppercase;color:var(--mut);}
		.tbl-scroll{overflow-x:auto;padding:0 10px 10px;}
		table.q{width:100%;border-collapse:collapse;font-size:12px;}
		table.q th{text-align:left;font-family:'IBM Plex Mono',monospace;font-size:9.5px;letter-spacing:.14em;text-transform:uppercase;color:var(--mut-2);padding:8px 12px;border-bottom:1px solid var(--line);font-weight:500;}
		table.q td{padding:9px 12px;border-bottom:1px solid rgba(15,23,42,.05);color:var(--ink);vertical-align:top;}
		table.q tr.sleep td{color:var(--mut-2);}
		table.q td.t{text-align:right;font-family:'IBM Plex Mono',monospace;}
		table.q code{font-family:'IBM Plex Mono',monospace;font-size:11px;color:#0f766e;white-space:pre-wrap;word-break:break-word;}
		.empty{text-align:center;color:var(--mut-2);padding:26px;font-size:12.5px;}

		.note{margin-top:24px;font-size:11px;color:var(--mut);line-height:1.7;border-left:2px solid var(--line);padding-left:14px;}
		.note code{font-family:'IBM Plex Mono',monospace;color:#475569;background:rgba(15,23,42,.05);padding:1px 5px;border-radius:4px;}

		@media(max-width:860px){.grid{grid-template-columns:1fr;}.strip{grid-template-columns:repeat(2,1fr);}.tiles{grid-template-columns:repeat(2,1fr);}}
	</style>
</head>
<body>
<div class="wrap">

	<div class="top">
		<div class="brand">
			<h1>System&nbsp;Monitor</h1>
			<div class="sub"><span class="dot"></span> Live · scoped to this project on a shared host · updated <span id="gen" class="mono"><?php echo htmlspecialchars($m['generated']); ?></span></div>
		</div>
		<div class="actions">
			<a href="<?php echo base_url('system-monitor/lock'); ?>" class="btn btn-lock" title="Clear the saved code — you'll need to re-enter it"><i class="la la-lock"></i> Lock</a>
		</div>
	</div>

	<div class="strip">
		<div class="cell"><div class="k">Host</div><div class="v"><?php echo htmlspecialchars($m['hostname']); ?></div></div>
		<div class="cell"><div class="k">OS</div><div class="v"><?php echo htmlspecialchars($m['os']); ?></div></div>
		<div class="cell"><div class="k">PHP</div><div class="v"><?php echo htmlspecialchars($m['php_version']); ?></div></div>
		<div class="cell"><div class="k">Uptime</div><div class="v" id="uptime"><?php echo system_monitor_format_uptime($m['uptime']); ?></div></div>
	</div>

	<div class="grid">

		<!-- CPU -->
		<div class="card" style="--accent:var(--cyan);">
			<div class="chead">
				<span class="chip"><i class="la la-microchip"></i></span>
				<div><div class="ctitle">CPU</div><div class="cscope" id="cpu_scope"><?php echo htmlspecialchars($cpu_scope); ?></div></div>
				<span class="pill <?php echo sm_pill_class($cpu['status']); ?>" id="cpu_pill"><?php echo strtoupper($cpu['status']); ?></span>
			</div>
			<div class="ring-wrap">
				<div class="ring" id="cpu_ring" style="--pct:<?php echo min(100, (float) $cpu['percent']); ?>;">
					<div class="inner"><div><span class="num" id="cpu_num"><?php echo (float) $cpu['percent']; ?></span><span class="unit">%</span></div></div>
				</div>
			</div>
			<div class="cmeta" id="cpu_meta"><?php echo htmlspecialchars($cpu_meta); ?></div>
		</div>

		<!-- Memory -->
		<div class="card" style="--accent:var(--violet);">
			<div class="chead">
				<span class="chip"><i class="la la-memory"></i></span>
				<div><div class="ctitle">Memory</div><div class="cscope" id="mem_scope"><?php echo htmlspecialchars($mem_scope); ?></div></div>
				<span class="pill <?php echo is_array($mem) ? sm_pill_class($mem['status']) : 'pill-ok'; ?>" id="mem_pill"><?php echo is_array($mem) ? strtoupper($mem['status']) : 'N/A'; ?></span>
			</div>
			<div class="ring-wrap">
				<div class="ring" id="mem_ring" style="--pct:<?php echo is_array($mem) ? min(100, (float) $mem['percent']) : 0; ?>;">
					<div class="inner"><div><span class="num" id="mem_num"><?php echo is_array($mem) ? (float) $mem['percent'] : '—'; ?></span><span class="unit"><?php echo is_array($mem) ? '%' : ''; ?></span></div></div>
				</div>
			</div>
			<div class="cmeta" id="mem_meta"><?php echo htmlspecialchars($mem_meta); ?></div>
		</div>

		<!-- Disk (project folder, with host filesystem context) -->
		<div class="card card--disk" style="--accent:var(--amber);">
			<div class="chead">
				<span class="chip"><i class="la la-folder"></i></span>
				<div><div class="ctitle">Disk</div><div class="cscope">Project folder</div></div>
			</div>
			<div class="bignum">
				<span class="n" id="disk_num"><?php echo $proj_disk !== null ? system_monitor_format_bytes($proj_disk) : '—'; ?></span>
				<div class="l">on disk</div>
			</div>
			<div class="bar"><span id="disk_bar" style="width:<?php echo min(100, $disk_pct); ?>%;"></span></div>
			<div class="cmeta" id="disk_meta"><?php echo htmlspecialchars($disk_meta); ?></div>
		</div>

	</div>

	<!-- Database -->
	<div class="panel" id="db_card"<?php echo $sql ? '' : ' style="display:none;"'; ?>>
		<div class="phead">
			<span class="chip" style="--accent:var(--emerald);color:var(--emerald);background:rgba(52,211,153,.14);border-color:rgba(52,211,153,.32);"><i class="la la-database"></i></span>
			<div><div class="ctitle">Database</div><div class="cscope">This project schema</div></div>
			<span class="srv" id="db_server"><?php echo $sql ? htmlspecialchars($sql['server_version'] . ' · ' . $sql['database']) : ''; ?></span>
		</div>
		<div class="tiles">
			<div class="tile"><div class="k">DB Size</div><div class="v" id="db_size"><?php echo $sql ? system_monitor_format_bytes($sql['size_bytes']) : '—'; ?></div></div>
			<div class="tile"><div class="k">Tables</div><div class="v" id="db_tables"><?php echo $sql ? number_format($sql['tables']) : '—'; ?></div></div>
			<div class="tile"><div class="k">Connections</div><div class="v" id="db_conn"><?php echo $sql ? $sql['connections'] : '—'; ?></div></div>
		</div>

		<div class="qh">Recent query history · this database</div>
		<div class="tbl-scroll">
			<table class="q">
				<thead><tr><th>Query</th><th style="text-align:right;">Count</th><th style="text-align:right;">Avg</th><th style="text-align:right;">Max</th><th style="text-align:right;">Rows&nbsp;Exam.</th><th>Last&nbsp;Seen</th></tr></thead>
				<tbody id="db_hist">
					<?php
					$hist = ($sql && ! empty($sql['history'])) ? $sql['history'] : array();
					if (empty($hist)) {
						echo '<tr><td colspan="6" class="empty">No query history. (performance_schema disabled, not permitted, or no activity on this schema.)</td></tr>';
					} else {
						foreach ($hist as $h) {
							echo '<tr>';
							echo '<td><code>' . htmlspecialchars(mb_substr($h['query'], 0, 220)) . '</code></td>';
							echo '<td class="t">' . number_format($h['count']) . '</td>';
							echo '<td class="t">' . htmlspecialchars(system_monitor_format_ps_time($h['avg_ps'])) . '</td>';
							echo '<td class="t">' . htmlspecialchars(system_monitor_format_ps_time($h['max_ps'])) . '</td>';
							echo '<td class="t">' . number_format($h['rows_examined']) . '</td>';
							echo '<td style="white-space:nowrap;font-size:11.5px;" title="' . htmlspecialchars($h['last_seen']) . '">' . htmlspecialchars(isset($h['last_seen_ago']) ? $h['last_seen_ago'] : $h['last_seen']) . '</td>';
							echo '</tr>';
						}
					}
					?>
				</tbody>
			</table>
		</div>
	</div>

	<div class="note">
		CPU &amp; memory are summed from this project's processes
		(<?php echo (isset($project['mode']) && $project['mode'] === 'match')
			? 'matched by <code>SYSTEM_MONITOR_PROC_MATCH</code>'
			: ('user <code>' . htmlspecialchars(isset($project['user']) ? $project['user'] : '?') . '</code>'); ?>),
		disk is the project folder, and Database covers this project's schema only.
		When processes can't be isolated the CPU/Memory cards fall back to host-wide figures marked “shared”.
		Set <code>SYSTEM_MONITOR_PROC_MATCH</code> in <code>.env</code> to a string unique to this project's processes to pin the match.
	</div>

</div>

<script src="<?php echo base_url('assets/js/scripts-bundle.js'); ?>"></script>
<script>
(function () {
	var STATS_URL = '<?php echo base_url('System_Monitor/stats'); ?>';
	var PILL = { ok: 'pill-ok', warning: 'pill-warn', critical: 'pill-crit' };

	function fmtBytes(b){ b=Number(b)||0; if(b<=0) return '0 B';
		var u=['B','KB','MB','GB','TB','PB'],i=Math.floor(Math.log(b)/Math.log(1024));
		if(i<0)i=0; if(i>=u.length)i=u.length-1; var v=b/Math.pow(1024,i);
		return (i===0?Math.round(v):v.toFixed(1))+' '+u[i]; }
	function fmtUptime(s){ s=Math.max(0,parseInt(s,10)||0);
		var d=Math.floor(s/86400),h=Math.floor((s%86400)/3600),m=Math.floor((s%3600)/60),p=[];
		if(d)p.push(d+'d'); if(h)p.push(h+'h'); p.push(m+'m'); return p.join(' '); }
	function fmtDur(sec){ sec=Math.max(0,parseInt(sec,10)||0); if(sec<=0) return '0s';
		var d=Math.floor(sec/86400),h=Math.floor((sec%86400)/3600),m=Math.floor((sec%3600)/60),s=sec%60;
		if(d>0)return d+'d '+h+'h'; if(h>0)return h+'h '+m+'m'; if(m>0)return m+'m '+s+'s'; return s+'s'; }
	function fmtPs(ps){ ps=Number(ps)||0; if(ps<=0) return '0';
		if(ps<1e6) return Math.round(ps/1e3)+' ns';
		if(ps<1e9) return (ps/1e6).toFixed(1)+' µs';
		if(ps<1e12) return (ps/1e9).toFixed(1)+' ms';
		return (ps/1e12).toFixed(2)+' s'; }
	function esc(s){ return $('<div/>').text(s==null?'':s).html(); }

	function setPill(id, status){
		var el=$('#'+id).removeClass('pill-ok pill-warn pill-crit');
		el.addClass(PILL[status]||'pill-ok').text(status?status.toUpperCase():'N/A');
	}
	function setRing(ringId, numId, metric){
		var pct = metric ? Number(metric.percent) : 0;
		$('#'+ringId).css('--pct', Math.min(100, pct));
		$('#'+numId).text(metric ? metric.percent : '—');
	}

	function render(d){
		var hc=d.host_cpu, hm=d.host_memory, hd=d.host_disk, pr=d.project||{};
		var hostMem = hm ? (fmtBytes(hm.used_bytes)+' / '+fmtBytes(hm.total_bytes)) : 'n/a';
		var n = pr.proc_count||0;

		// CPU
		if(pr.cpu){ setRing('cpu_ring','cpu_num',pr.cpu); setPill('cpu_pill',pr.cpu.status);
			$('#cpu_scope').text('This Project');
			$('#cpu_meta').text(n+' processes · host '+hc.percent+'% of '+hc.cores+' cores'); }
		else { setRing('cpu_ring','cpu_num',hc); setPill('cpu_pill',hc.status);
			$('#cpu_scope').text('Host · shared');
			$('#cpu_meta').text(hc.cores+' cores · load '+hc.load_1+' / '+hc.load_5+' / '+hc.load_15); }

		// Memory
		if(pr.memory){ setRing('mem_ring','mem_num',pr.memory); setPill('mem_pill',pr.memory.status);
			$('#mem_scope').text('This Project');
			$('#mem_meta').text(fmtBytes(pr.memory.used_bytes)+' · '+n+' procs · host '+hostMem); }
		else if(hm){ setRing('mem_ring','mem_num',hm); setPill('mem_pill',hm.status);
			$('#mem_scope').text('Host · shared'); $('#mem_meta').text(hostMem+' used'); }
		else { setRing('mem_ring','mem_num',null); setPill('mem_pill',null);
			$('#mem_meta').text('unavailable on this host'); }

		// Disk
		$('#disk_num').text(pr.disk_bytes!=null ? fmtBytes(pr.disk_bytes) : '—');
		$('#disk_bar').css('width', (hd?Math.min(100,hd.percent):0)+'%');
		$('#disk_meta').text(hd ? ('host disk '+hd.percent+'% · '+fmtBytes(hd.available_bytes)+' free of '+fmtBytes(hd.total_bytes)) : 'host disk info unavailable');

		renderSql(d.sql);
		$('#uptime').text(fmtUptime(d.uptime));
		$('#gen').text(d.generated);
	}

	function renderSql(sql){
		if(!sql){ $('#db_card').hide(); return; }
		$('#db_card').show();
		$('#db_server').text(sql.server_version+' · '+sql.database);
		$('#db_size').text(fmtBytes(sql.size_bytes));
		$('#db_tables').text(Number(sql.tables).toLocaleString());
		$('#db_conn').text(sql.connections);

		var hist=sql.history||[];
		if(!hist.length){ $('#db_hist').html('<tr><td colspan="6" class="empty">No query history. (performance_schema disabled, not permitted, or no activity on this schema.)</td></tr>'); }
		else {
			var hh='';
			hist.forEach(function(h){
				hh+='<tr><td><code>'+esc(String(h.query).slice(0,220))+'</code></td>'
					+'<td class="t">'+Number(h.count).toLocaleString()+'</td>'
					+'<td class="t">'+esc(fmtPs(h.avg_ps))+'</td>'
					+'<td class="t">'+esc(fmtPs(h.max_ps))+'</td>'
					+'<td class="t">'+Number(h.rows_examined).toLocaleString()+'</td>'
					+'<td style="white-space:nowrap;font-size:11.5px;" title="'+esc(h.last_seen)+'">'+esc(h.last_seen_ago||h.last_seen)+'</td></tr>';
			});
			$('#db_hist').html(hh);
		}
	}

	function poll(){ $.getJSON(STATS_URL).done(render); }
	setInterval(poll, 5000);
})();
</script>
</body>
</html>
