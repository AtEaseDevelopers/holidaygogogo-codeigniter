<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * System_Monitor — a PUBLIC page reporting the host machine's CPU load, memory
 * and disk usage for the running project, plus the PHP process's own footprint.
 * Read-only — it never changes anything.
 *
 * Unlike the rest of the back-office this extends CI_Controller (no staff
 * login) so it can be reached without a session — e.g. by an external uptime
 * check. Access is guarded SOLELY by a shared secret: SYSTEM_MONITOR_ACCESS_CODE
 * in .env — everyone, including logged-in admins, must enter the code. A visitor
 * supplies it once (?code= / the gate form); it is then kept in an httponly
 * cookie so the page and its live-refresh poll keep working. If the env code is
 * blank the page is locked for everyone (fail-closed).
 *
 *   /system-monitor            -> gate form, or the dashboard once unlocked
 *   /system-monitor?code=XXX   -> unlock with the code
 *   /system-monitor/stats      -> JSON snapshot for the live poll (same gate)
 *   /system-monitor/lock       -> forget the code; next visit must re-enter it
 *
 * All the OS probing (sys_getloadavg, /proc, sysctl, vm_stat, disk_*_space)
 * lives here; the arithmetic and parsing are pure functions in
 * system_monitor_helper so they stay testable.
 */
class System_Monitor extends CI_Controller
{
	/** Cookie that remembers a correct code so refreshes/polls don't re-prompt. */
	const CODE_COOKIE = 'sysmon_code';

	function __construct()
	{
		parent::__construct();
		$this->load->helper('system_monitor');
	}

	function index()
	{
		// A fresh ?code= / form submission is validated and persisted to a cookie
		// here, so the dashboard and the AJAX poll don't need it in every URL.
		$this->remember_code();

		if ( ! $this->authorized()) {
			$this->output->set_status_header(401);
			$this->load->view('system_monitor/gate', array(
				'attempted' => ($this->provided_code() !== ''),
				'locked'    => ($this->configured_code() === ''),
			));
			return;
		}

		$this->load->view('system_monitor/index', array('metrics' => $this->collect_metrics()));
	}

	/**
	 * Clear the remembered-code cookie and bounce back to the gate, so the next
	 * visit must re-enter the access code. The "Lock" button on the dashboard.
	 */
	function lock()
	{
		$this->forget_code();
		redirect('system-monitor');
	}

	/** JSON snapshot for the live-refresh poll. 403 when not authorised. */
	function stats()
	{
		if ( ! $this->authorized()) {
			$this->output
				->set_status_header(403)
				->set_content_type('application/json')
				->set_output(json_encode(array('error' => 'forbidden')));
			return;
		}
		$this->output
			->set_content_type('application/json')
			->set_output(json_encode($this->collect_metrics()));
	}

	// ---- access gate --------------------------------------------------------

	/** The secret configured in .env (blank = feature locked). */
	private function configured_code()
	{
		return (string) get_env('SYSTEM_MONITOR_ACCESS_CODE');
	}

	/** Code supplied this request: POST form, ?code= query, or remembered cookie. */
	private function provided_code()
	{
		$code = (string) $this->input->post('code');
		if ($code === '') {
			$code = (string) $this->input->get('code');
		}
		if ($code === '') {
			$code = (string) $this->input->cookie(self::CODE_COOKIE);
		}
		return $code;
	}

	/**
	 * Access requires a correct code — no exceptions, not even a logged-in
	 * admin session. Constant-time compare; a blank configured code locks the
	 * page for everyone (fail-closed).
	 */
	private function authorized()
	{
		$configured = $this->configured_code();
		if ($configured === '') {
			return false;
		}
		return hash_equals($configured, $this->provided_code());
	}

	/** On a valid fresh code, stash it in an httponly cookie for 12 hours. */
	private function remember_code()
	{
		$configured = $this->configured_code();
		$supplied = (string) $this->input->post('code');
		if ($supplied === '') {
			$supplied = (string) $this->input->get('code');
		}
		if ($configured !== '' && $supplied !== '' && hash_equals($configured, $supplied)) {
			$secure = (strtolower((string) parse_url((string) base_url(), PHP_URL_SCHEME)) === 'https');
			setcookie(self::CODE_COOKIE, $supplied, array(
				'expires'  => time() + 43200,
				'path'     => '/',
				'secure'   => $secure,
				'httponly' => true,
				'samesite' => 'Lax',
			));
			// Make it visible to authorized() within this same request too.
			$_COOKIE[self::CODE_COOKIE] = $supplied;
		}
	}

	/** Expire the remembered-code cookie so the next visit re-prompts. */
	private function forget_code()
	{
		$secure = (strtolower((string) parse_url((string) base_url(), PHP_URL_SCHEME)) === 'https');
		setcookie(self::CODE_COOKIE, '', array(
			'expires'  => time() - 3600,
			'path'     => '/',
			'secure'   => $secure,
			'httponly' => true,
			'samesite' => 'Lax',
		));
		unset($_COOKIE[self::CODE_COOKIE]);
	}

	// ---- metric collection --------------------------------------------------

	/**
	 * Probe the OS and roll the raw numbers up through the pure helpers.
	 * Everything is best-effort — any probe that isn't available on this host
	 * (e.g. /proc on macOS) simply yields null and the view hides that card.
	 */
	private function collect_metrics()
	{
		$cores   = $this->detect_cores();
		$loadavg = function_exists('sys_getloadavg') ? sys_getloadavg() : array(0, 0, 0);
		$host_mem = $this->detect_memory();

		$php_mem = array(
			'current_bytes' => memory_get_usage(true),
			'peak_bytes'    => memory_get_peak_usage(true),
			'limit'         => ini_get('memory_limit'),
		);

		return array(
			'host_cpu'   => system_monitor_load_metrics($loadavg, $cores),
			'host_memory'=> $host_mem,
			'host_disk'  => $this->detect_disk(),
			'project'    => $this->detect_project($cores, $host_mem),
			'sql'        => $this->detect_sql(),
			'php_memory' => $php_mem,
			'uptime'     => $this->detect_uptime(),
			'php_version'=> PHP_VERSION,
			'hostname'   => php_uname('n'),
			'os'         => php_uname('s') . ' ' . php_uname('r'),
			'generated'  => date('Y-m-d H:i:s'),
		);
	}

	/**
	 * This project's own footprint, isolated from the other projects sharing the
	 * host: CPU% and memory summed across its processes, and its folder size on
	 * disk. Processes are matched by SYSTEM_MONITOR_PROC_MATCH (a string looked
	 * for in each process's command line) when set, else by the OS user the app
	 * runs as. When nothing matches, 'cpu'/'memory' are null and the view falls
	 * back to showing host-wide figures labelled "shared".
	 */
	private function detect_project($cores, $host_mem)
	{
		$match = (string) get_env('SYSTEM_MONITOR_PROC_MATCH');
		$user  = $this->current_os_user();

		$ps = $this->run('ps -eo pid=,user=,pcpu=,rss=,command=');
		$procs = system_monitor_sum_processes((string) $ps, $match, $user);

		$host_total = is_array($host_mem) ? $host_mem['total_bytes'] : 0;

		return array(
			'root'       => FCPATH,
			'match'      => $match,
			'user'       => $user,
			'mode'       => $procs['mode'],
			'proc_count' => $procs['count'],
			'cpu'        => system_monitor_project_cpu($procs['cpu_percent'], $cores, $procs['count']),
			'memory'     => $procs['count'] > 0
				? system_monitor_memory_metrics($host_total, $procs['mem_bytes'])
				: null,
			'disk_bytes' => $this->detect_project_disk(),
		);
	}

	/** OS user the web process runs as (for per-project process matching). */
	private function current_os_user()
	{
		if (function_exists('posix_geteuid') && function_exists('posix_getpwuid')) {
			$info = @posix_getpwuid(posix_geteuid());
			if (is_array($info) && ! empty($info['name'])) {
				return (string) $info['name'];
			}
		}
		$who = $this->run('whoami');
		return $who !== null ? $who : '';
	}

	/** Size of this project's directory tree in bytes (du), or null. */
	private function detect_project_disk()
	{
		$out = $this->run('du -sk ' . escapeshellarg(FCPATH) . ' 2>/dev/null');
		return $out !== null ? system_monitor_parse_du_kb($out) : null;
	}

	/**
	 * MySQL health scoped to THIS project's database: its on-disk size and table
	 * count (information_schema), plus the connections / active queries / live
	 * processlist filtered to this database only — so activity from other
	 * projects sharing the server is excluded. Best-effort: if the app user
	 * lacks the PROCESS privilege it only sees its own (this project's) threads,
	 * which is exactly the scope we want anyway. Null on total failure.
	 */
	private function detect_sql()
	{
		if (empty($this->db) || empty($this->db->database)) {
			return null;
		}

		// Some of these probes (information_schema, performance_schema, SHOW
		// PROCESSLIST) need privileges the app's DB user may not have. CI renders
		// a fatal error page on a failed query whenever db_debug is on (i.e. off
		// production), so turn it off for the duration: a denied query then
		// returns FALSE and we just skip that section instead of halting.
		$prev_debug = $this->db->db_debug;
		$this->db->db_debug = FALSE;

		$result = null;
		try {
			$db = (string) $this->db->database;

			// This database's size + table count.
			$size_rows = $this->safe_rows(
				'SELECT SUM(data_length + index_length) AS bytes, COUNT(*) AS `tables`
				 FROM information_schema.TABLES WHERE TABLE_SCHEMA = ?',
				array($db)
			);
			$size = system_monitor_db_size_from_row(isset($size_rows[0]) ? $size_rows[0] : array());

			// Connections for this database (filtered from the processlist).
			$plist = system_monitor_filter_processlist_by_db(
				system_monitor_processlist_rows($this->safe_rows('SHOW FULL PROCESSLIST')),
				$db
			);

			// Query history for this DB — per-statement-pattern stats that MySQL
			// keeps in performance_schema (needs it enabled + SELECT granted).
			$history = system_monitor_query_history_rows($this->safe_rows(
				'SELECT DIGEST_TEXT, COUNT_STAR, AVG_TIMER_WAIT, MAX_TIMER_WAIT,
				        SUM_ROWS_EXAMINED, SUM_ROWS_SENT, LAST_SEEN
				 FROM performance_schema.events_statements_summary_by_digest
				 WHERE SCHEMA_NAME = ? AND DIGEST_TEXT IS NOT NULL
				 ORDER BY LAST_SEEN DESC LIMIT 25',
				array($db)
			));

			// Relative "ago" label, computed server-side so it's timezone-safe
			// and refreshes with every poll.
			$now = time();
			foreach ($history as &$h) {
				$h['last_seen_ago'] = system_monitor_relative_time($h['last_seen'], $now);
			}
			unset($h);

			$result = array(
				'database'       => $db,
				'server_version' => $this->db->version(),
				'size_bytes'     => $size['bytes'],
				'tables'         => $size['tables'],
				'connections'    => count($plist),
				'history'        => array_slice($history, 0, 25),
			);
		} catch (Throwable $e) {
			$result = null;
		}

		$this->db->db_debug = $prev_debug;
		return $result;
	}

	/**
	 * Run a read query with db_debug already suppressed, returning its rows or an
	 * empty array when it is denied / fails (CI returns FALSE instead of a result
	 * object in that case). Never throws a DB error page.
	 */
	private function safe_rows($sql, $binds = array())
	{
		try {
			$res = $this->db->query($sql, $binds);
			return is_object($res) ? $res->result_array() : array();
		} catch (Throwable $e) {
			return array();
		}
	}

	/** Logical CPU count: /proc/cpuinfo → nproc → sysctl → 1. */
	private function detect_cores()
	{
		if (is_readable('/proc/cpuinfo')) {
			$n = system_monitor_cpu_count_from_cpuinfo(@file_get_contents('/proc/cpuinfo'));
			if ($n > 0) {
				return $n;
			}
		}
		foreach (array('nproc 2>/dev/null', 'sysctl -n hw.ncpu 2>/dev/null') as $cmd) {
			$out = $this->run($cmd);
			if ($out !== null && (int) $out > 0) {
				return (int) $out;
			}
		}
		return 1;
	}

	/** System memory via /proc/meminfo (Linux) or vm_stat + sysctl (macOS). */
	private function detect_memory()
	{
		if (is_readable('/proc/meminfo')) {
			$m = system_monitor_parse_meminfo(@file_get_contents('/proc/meminfo'));
			if ($m !== null) {
				return $m;
			}
		}
		$total = (int) $this->run('sysctl -n hw.memsize 2>/dev/null');
		$vmstat = $this->run('vm_stat 2>/dev/null');
		if ($total > 0 && $vmstat !== null) {
			return system_monitor_parse_vm_stat($vmstat, $total);
		}
		return null;
	}

	/** Disk usage of the filesystem holding the project root. */
	private function detect_disk()
	{
		$total = @disk_total_space(FCPATH);
		$free  = @disk_free_space(FCPATH);
		if ($total === false || $free === false) {
			return null;
		}
		return system_monitor_disk_metrics($total, $free);
	}

	/** Host uptime in seconds via /proc/uptime (Linux) or sysctl (macOS). */
	private function detect_uptime()
	{
		if (is_readable('/proc/uptime')) {
			$raw = trim((string) @file_get_contents('/proc/uptime'));
			if ($raw !== '' && preg_match('/^([\d.]+)/', $raw, $m)) {
				return (int) $m[1];
			}
		}
		$boot = $this->run("sysctl -n kern.boottime 2>/dev/null");
		if ($boot !== null && preg_match('/sec\s*=\s*(\d+)/', $boot, $m)) {
			return max(0, time() - (int) $m[1]);
		}
		return 0;
	}

	/**
	 * Run a shell probe, returning trimmed output or null if unavailable.
	 * php-fpm often runs with an empty PATH, so sysctl/vm_stat/nproc wouldn't
	 * resolve by bare name — prepend the usual system bin dirs so they do.
	 */
	private function run($cmd)
	{
		if ( ! function_exists('shell_exec')) {
			return null;
		}
		$disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));
		if (in_array('shell_exec', $disabled, true)) {
			return null;
		}
		$out = @shell_exec('PATH=/usr/sbin:/usr/bin:/sbin:/bin ' . $cmd);
		return $out === null ? null : trim($out);
	}
}
