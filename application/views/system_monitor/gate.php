<?php
/**
 * System Monitor access gate — shown when no valid code is present. Posts the
 * code to System_Monitor::index, validated against SYSTEM_MONITOR_ACCESS_CODE
 * (.env). Standalone dark document matching the dashboard.
 */
$attempted = ! empty($attempted);
$locked    = ! empty($locked);
?>
<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
	<meta name="robots" content="noindex, nofollow">
	<title>System Monitor · Access</title>
	<link href="<?php echo base_url('assets/image/favicon.png'); ?>" rel="icon">
	<link href="<?php echo base_url('assets/css/plugins-bundle.css'); ?>" type="text/css" rel="stylesheet">
	<link rel="preconnect" href="https://fonts.googleapis.com">
	<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
	<link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Mono:wght@400;500;600;700&family=IBM+Plex+Sans:wght@400;500;600&display=swap" rel="stylesheet">
	<style>
		:root{--bg:#eef1f7;--panel:#ffffff;--line:#e6e9f1;--ink:#1e2635;--mut:#64748b;--cyan:#0891b2;--crit:#dc2626;}
		*{box-sizing:border-box;}
		body{margin:0;min-height:100vh;display:grid;place-items:center;padding:24px;
			background:radial-gradient(1000px 520px at 50% -10%,rgba(8,145,178,.1),transparent 60%),var(--bg);
			color:var(--ink);font-family:'IBM Plex Sans',system-ui,sans-serif;-webkit-font-smoothing:antialiased;}
		.card{width:100%;max-width:400px;background:var(--panel);
			border:1px solid var(--line);border-radius:18px;padding:40px 34px;text-align:center;position:relative;overflow:hidden;
			box-shadow:0 2px 6px rgba(15,23,42,.06),0 18px 50px rgba(15,23,42,.1);}
		.card::before{content:'';position:absolute;top:0;left:0;right:0;height:3px;background:var(--cyan);}
		.ic{width:58px;height:58px;border-radius:16px;display:grid;place-items:center;margin:0 auto 18px;font-size:28px;
			color:var(--cyan);background:rgba(8,145,178,.1);border:1px solid rgba(8,145,178,.26);}
		h1{font-size:12px;letter-spacing:.4em;text-transform:uppercase;margin:0 0 6px;font-weight:600;}
		p{color:var(--mut);font-size:13px;line-height:1.6;margin:0 0 24px;}
		.err{background:rgba(220,38,38,.08);border:1px solid rgba(220,38,38,.28);color:var(--crit);
			font-size:12px;border-radius:10px;padding:10px 12px;margin-bottom:18px;text-align:left;line-height:1.5;}
		.err code{font-family:'IBM Plex Mono',monospace;}
		input{width:100%;background:#f7f9fc;border:1px solid var(--line);border-radius:11px;
			padding:13px 15px;color:var(--ink);font-family:'IBM Plex Mono',monospace;font-size:16px;letter-spacing:.3em;
			text-align:center;outline:none;transition:.16s;margin-bottom:14px;}
		input::placeholder{letter-spacing:.1em;color:var(--mut);}
		input:focus{border-color:var(--cyan);box-shadow:0 0 0 3px rgba(8,145,178,.15);background:#fff;}
		button{width:100%;background:var(--cyan);color:#fff;border:none;border-radius:11px;padding:13px;
			font-family:'IBM Plex Mono',monospace;font-size:12px;letter-spacing:.14em;text-transform:uppercase;font-weight:600;cursor:pointer;transition:.16s;box-shadow:0 4px 12px rgba(8,145,178,.3);}
		button:hover{filter:brightness(1.06);}
	</style>
</head>
<body>
	<div class="card">
		<div class="ic"><i class="la la-shield-alt"></i></div>
		<h1>System Monitor</h1>
		<p>Enter the access code to view host &amp; project metrics.</p>

		<?php if ($locked) { ?>
			<div class="err">This page is locked. Set <code>SYSTEM_MONITOR_ACCESS_CODE</code> in <code>.env</code> to enable it.</div>
		<?php } else { ?>
			<?php if ($attempted) { ?>
				<div class="err" style="text-align:center;">Incorrect code. Please try again.</div>
			<?php } ?>
			<form method="get" action="<?php echo base_url('System_Monitor'); ?>">
				<input type="password" name="code" placeholder="Access code" autocomplete="off" autofocus required>
				<button type="submit">Unlock</button>
			</form>
		<?php } ?>
	</div>
</body>
</html>
