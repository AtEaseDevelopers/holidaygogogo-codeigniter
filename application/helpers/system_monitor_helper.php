<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * System Monitor — pure helpers for reporting the host machine's CPU load,
 * memory and disk usage for the running project.
 *
 * Per the project quality pattern, every bit of parsing / arithmetic lives here
 * as a pure function (no $this, no DB, no shell) so it can be unit-tested
 * against fixtures (tests/helpers/SystemMonitorTest.php). The controller
 * (System_Monitor) is the only place that touches the OS — it reads
 * sys_getloadavg(), /proc, sysctl, vm_stat etc. and feeds the raw strings and
 * numbers to these functions.
 *
 * "CPU usage" for a load-based OS metric is the 1-minute load average expressed
 * as a percentage of total core capacity: load / cores * 100. A box with 4
 * cores at load 4.0 is 100% busy; at load 2.0 it's 50%.
 */

if ( ! function_exists('system_monitor_status_from_percent'))
{
	/**
	 * Severity bucket for a utilisation percentage. Thresholds are shared by
	 * CPU, memory and disk so the UI colours them consistently.
	 */
	function system_monitor_status_from_percent($percent)
	{
		$percent = (float) $percent;
		if ($percent >= 90) {
			return 'critical';
		}
		if ($percent >= 70) {
			return 'warning';
		}
		return 'ok';
	}
}

if ( ! function_exists('system_monitor_cpu_count_from_cpuinfo'))
{
	/**
	 * Count logical CPUs from the contents of /proc/cpuinfo (Linux). Each
	 * logical processor is one "processor\t: N" line. Returns 0 when the input
	 * has none (so the caller can fall back to another detection method).
	 */
	function system_monitor_cpu_count_from_cpuinfo($cpuinfo)
	{
		if ( ! is_string($cpuinfo) || $cpuinfo === '') {
			return 0;
		}
		return preg_match_all('/^processor\s*:/mi', $cpuinfo);
	}
}

if ( ! function_exists('system_monitor_load_metrics'))
{
	/**
	 * Normalise the three load averages against the core count.
	 *
	 * @param array $loadavg [1min, 5min, 15min] as returned by sys_getloadavg()
	 * @param int   $cores   logical CPU count (coerced to >= 1)
	 * @return array load_1/5/15 (float, 2dp), cores, percent (1-min / cores),
	 *               percent_5, percent_15 and a status bucket.
	 */
	function system_monitor_load_metrics($loadavg, $cores)
	{
		$loadavg = is_array($loadavg) ? array_values($loadavg) : array();
		$l1 = isset($loadavg[0]) ? (float) $loadavg[0] : 0.0;
		$l5 = isset($loadavg[1]) ? (float) $loadavg[1] : 0.0;
		$l15 = isset($loadavg[2]) ? (float) $loadavg[2] : 0.0;

		$cores = (int) $cores;
		if ($cores < 1) {
			$cores = 1;
		}

		$pct = round(($l1 / $cores) * 100, 1);
		$pct5 = round(($l5 / $cores) * 100, 1);
		$pct15 = round(($l15 / $cores) * 100, 1);

		return array(
			'load_1'      => round($l1, 2),
			'load_5'      => round($l5, 2),
			'load_15'     => round($l15, 2),
			'cores'       => $cores,
			'percent'     => $pct,
			'percent_5'   => $pct5,
			'percent_15'  => $pct15,
			'status'      => system_monitor_status_from_percent($pct),
		);
	}
}

if ( ! function_exists('system_monitor_memory_metrics'))
{
	/**
	 * Build a used/total memory summary from raw byte counts. Accepts either a
	 * used figure or an available figure (whichever the OS gave us) and fills in
	 * the other. Returns null when total is unknown/zero so the UI can hide the
	 * card rather than show 0%.
	 */
	function system_monitor_memory_metrics($total_bytes, $used_bytes = null, $available_bytes = null)
	{
		$total = (float) $total_bytes;
		if ($total <= 0) {
			return null;
		}

		if ($used_bytes === null && $available_bytes !== null) {
			$used = $total - (float) $available_bytes;
		} else {
			$used = (float) $used_bytes;
		}
		if ($used < 0) {
			$used = 0.0;
		}
		if ($used > $total) {
			$used = $total;
		}
		$available = $total - $used;
		$percent = round(($used / $total) * 100, 1);

		return array(
			'total_bytes'     => $total,
			'used_bytes'      => $used,
			'available_bytes' => $available,
			'percent'         => $percent,
			'status'          => system_monitor_status_from_percent($percent),
		);
	}
}

if ( ! function_exists('system_monitor_parse_meminfo'))
{
	/**
	 * Parse Linux /proc/meminfo (values are in kB). Prefers MemAvailable (the
	 * kernel's own estimate of reclaimable memory) over a naive free+cache sum.
	 * Returns a memory metrics array, or null if MemTotal is missing.
	 */
	function system_monitor_parse_meminfo($contents)
	{
		if ( ! is_string($contents) || $contents === '') {
			return null;
		}
		$kb = array();
		foreach (preg_split('/\R/', $contents) as $line) {
			if (preg_match('/^(\w+):\s+(\d+)\s*kB/i', $line, $m)) {
				$kb[$m[1]] = (float) $m[2];
			}
		}
		if (empty($kb['MemTotal'])) {
			return null;
		}
		$total = $kb['MemTotal'] * 1024;

		if (isset($kb['MemAvailable'])) {
			$available = $kb['MemAvailable'] * 1024;
		} else {
			$free    = isset($kb['MemFree']) ? $kb['MemFree'] : 0;
			$buffers = isset($kb['Buffers']) ? $kb['Buffers'] : 0;
			$cached  = isset($kb['Cached']) ? $kb['Cached'] : 0;
			$available = ($free + $buffers + $cached) * 1024;
		}

		return system_monitor_memory_metrics($total, null, $available);
	}
}

if ( ! function_exists('system_monitor_parse_vm_stat'))
{
	/**
	 * Parse macOS `vm_stat` output into a memory metrics array, given the total
	 * physical memory in bytes (from `sysctl -n hw.memsize`). Used memory is the
	 * wired + active + compressed pages; the page size is read from the header
	 * line. Returns null if the page size can't be determined.
	 */
	function system_monitor_parse_vm_stat($output, $total_bytes)
	{
		if ( ! is_string($output) || $output === '' || (float) $total_bytes <= 0) {
			return null;
		}
		if ( ! preg_match('/page size of (\d+) bytes/i', $output, $m)) {
			return null;
		}
		$page = (float) $m[1];

		$pages = array();
		foreach (preg_split('/\R/', $output) as $line) {
			if (preg_match('/^(.+?):\s+(\d+)\.?\s*$/', $line, $mm)) {
				$pages[strtolower(trim($mm[1]))] = (float) $mm[2];
			}
		}
		$get = function ($k) use ($pages) {
			return isset($pages[$k]) ? $pages[$k] : 0.0;
		};

		$used = ($get('pages wired down')
			+ $get('pages active')
			+ $get('pages occupied by compressor')) * $page;

		return system_monitor_memory_metrics((float) $total_bytes, $used);
	}
}

if ( ! function_exists('system_monitor_disk_metrics'))
{
	/**
	 * Used/total disk summary from the free and total bytes of the filesystem
	 * holding the project (disk_free_space / disk_total_space). Null if total is
	 * unknown.
	 */
	function system_monitor_disk_metrics($total_bytes, $free_bytes)
	{
		$total = (float) $total_bytes;
		if ($total <= 0) {
			return null;
		}
		$free = (float) $free_bytes;
		if ($free < 0) {
			$free = 0.0;
		}
		if ($free > $total) {
			$free = $total;
		}
		return system_monitor_memory_metrics($total, $total - $free);
	}
}

if ( ! function_exists('system_monitor_format_bytes'))
{
	/** Human-readable byte size (B/KB/MB/GB/TB). */
	function system_monitor_format_bytes($bytes, $precision = 1)
	{
		$bytes = (float) $bytes;
		if ($bytes <= 0) {
			return '0 B';
		}
		$units = array('B', 'KB', 'MB', 'GB', 'TB', 'PB');
		$pow = (int) floor(log($bytes, 1024));
		if ($pow < 0) {
			$pow = 0;
		}
		if ($pow >= count($units)) {
			$pow = count($units) - 1;
		}
		$value = $bytes / pow(1024, $pow);
		return round($value, $pow === 0 ? 0 : $precision) . ' ' . $units[$pow];
	}
}

if ( ! function_exists('system_monitor_format_uptime'))
{
	/** Compact uptime from seconds, e.g. "3d 4h 12m". */
	function system_monitor_format_uptime($seconds)
	{
		$seconds = (int) $seconds;
		if ($seconds <= 0) {
			return '—';
		}
		$days = intdiv($seconds, 86400);
		$hours = intdiv($seconds % 86400, 3600);
		$mins = intdiv($seconds % 3600, 60);
		$parts = array();
		if ($days > 0) {
			$parts[] = $days . 'd';
		}
		if ($hours > 0) {
			$parts[] = $hours . 'h';
		}
		$parts[] = $mins . 'm';
		return implode(' ', $parts);
	}
}

/* -------------------------------------------------------------------------
 * Per-project scoping
 *
 * On a shared server the host CPU / memory / disk cover EVERY project. These
 * helpers attribute usage to THIS project: its own processes (summed from `ps`,
 * matched by command string or owning OS user), and its folder size on disk.
 * ---------------------------------------------------------------------- */

if ( ! function_exists('system_monitor_sum_processes'))
{
	/**
	 * Sum CPU% and RSS across this project's processes from `ps` output whose
	 * columns are: PID USER %CPU RSS COMMAND(+args). A process counts when
	 * $match is non-empty and appears in its command line; otherwise when it is
	 * owned by $current_user. Returns the summed cpu_percent (as ps reports it —
	 * 100% == one core), mem_bytes (RSS KiB -> bytes), count and the match mode.
	 */
	function system_monitor_sum_processes($output, $match, $current_user)
	{
		$res = array('cpu_percent' => 0.0, 'mem_bytes' => 0.0, 'count' => 0, 'mode' => 'none');
		if ( ! is_string($output) || $output === '') {
			return $res;
		}
		$match = trim((string) $match);
		$current_user = trim((string) $current_user);
		$by_match = ($match !== '');
		$res['mode'] = $by_match ? 'match' : ($current_user !== '' ? 'user' : 'none');
		if ($res['mode'] === 'none') {
			return $res;
		}

		foreach (preg_split('/\R/', $output) as $line) {
			$line = trim($line);
			if ($line === '') {
				continue;
			}
			// PID USER %CPU RSS COMMAND...
			if ( ! preg_match('/^(\d+)\s+(\S+)\s+([\d.]+)\s+(\d+)\s+(.*)$/', $line, $m)) {
				continue;
			}
			$user = $m[2];
			$pcpu = (float) $m[3];
			$rss_kb = (float) $m[4];
			$cmd = $m[5];

			if ($by_match) {
				if (stripos($cmd, $match) === false) {
					continue;
				}
			} elseif (strcasecmp($user, $current_user) !== 0) {
				continue;
			}

			$res['cpu_percent'] += $pcpu;
			$res['mem_bytes'] += $rss_kb * 1024;
			$res['count']++;
		}
		$res['cpu_percent'] = round($res['cpu_percent'], 1);
		return $res;
	}
}

if ( ! function_exists('system_monitor_project_cpu'))
{
	/**
	 * This project's CPU as a percentage of the whole host's capacity: the
	 * summed per-process %CPU (where one core == 100%) divided by the core
	 * count. Null when no processes were attributed.
	 */
	function system_monitor_project_cpu($sum_cpu_percent, $cores, $process_count)
	{
		if ((int) $process_count < 1) {
			return null;
		}
		$cores = (int) $cores;
		if ($cores < 1) {
			$cores = 1;
		}
		$pct = round(((float) $sum_cpu_percent) / $cores, 1);
		if ($pct < 0) {
			$pct = 0.0;
		}
		return array(
			'percent' => $pct,
			'status'  => system_monitor_status_from_percent($pct),
		);
	}
}

if ( ! function_exists('system_monitor_parse_du_kb'))
{
	/** Bytes from `du -sk <path>` output (leading KiB integer), or null. */
	function system_monitor_parse_du_kb($output)
	{
		if ( ! is_string($output) || $output === '') {
			return null;
		}
		if (preg_match('/^\s*(\d+)/', $output, $m)) {
			return (float) $m[1] * 1024;
		}
		return null;
	}
}

/* -------------------------------------------------------------------------
 * SQL / MySQL monitor
 *
 * The controller runs `SHOW GLOBAL STATUS` and `SHOW FULL PROCESSLIST` and
 * hands the raw rows to these pure functions. No DB access happens here.
 * ---------------------------------------------------------------------- */

if ( ! function_exists('system_monitor_status_pairs_to_map'))
{
	/**
	 * Fold `SHOW GLOBAL STATUS` rows ([{Variable_name, Value}, ...]) into a
	 * flat Variable_name => Value map. Case-insensitive to the column names so
	 * it works across MySQL versions. Accepts arrays or objects.
	 */
	function system_monitor_status_pairs_to_map($rows)
	{
		$map = array();
		if ( ! is_array($rows)) {
			return $map;
		}
		foreach ($rows as $row) {
			$row = (array) $row;
			$name = null;
			$value = null;
			foreach ($row as $k => $v) {
				$lk = strtolower((string) $k);
				if ($lk === 'variable_name') {
					$name = (string) $v;
				} elseif ($lk === 'value') {
					$value = $v;
				}
			}
			if ($name !== null) {
				$map[$name] = $value;
			}
		}
		return $map;
	}
}

if ( ! function_exists('system_monitor_sql_metrics'))
{
	/**
	 * Headline MySQL server counters from a global-status map. Average QPS is
	 * Queries (or Questions) over server Uptime — a cheap baseline; the view
	 * also derives a *live* QPS from the delta between polls. Missing counters
	 * default to 0.
	 */
	function system_monitor_sql_metrics($status_map)
	{
		$get = function ($key) use ($status_map) {
			return isset($status_map[$key]) ? (float) $status_map[$key] : 0.0;
		};

		$uptime    = (int) $get('Uptime');
		$queries   = $get('Queries');
		$questions = $get('Questions');
		// Queries counts statements run inside stored programs too; fall back to
		// Questions (client statements) when Queries isn't exposed.
		$total = $queries > 0 ? $queries : $questions;

		return array(
			'uptime'            => $uptime,
			'questions'         => (int) $questions,
			'queries'           => (int) $queries,
			'total'             => (int) $total,
			'qps_avg'           => $uptime > 0 ? round($total / $uptime, 1) : 0.0,
			'threads_connected' => (int) $get('Threads_connected'),
			'threads_running'   => (int) $get('Threads_running'),
			'slow_queries'      => (int) $get('Slow_queries'),
			'aborted_connects'  => (int) $get('Aborted_connects'),
		);
	}
}

if ( ! function_exists('system_monitor_processlist_rows'))
{
	/**
	 * Normalise `SHOW FULL PROCESSLIST` rows into a stable shape, drop the
	 * monitor's own PROCESSLIST query, and sort so active (non-Sleep) threads
	 * come first, then longest-running first. No slicing — the caller limits
	 * for display and counts active threads from the full list.
	 */
	function system_monitor_processlist_rows($rows)
	{
		$out = array();
		if ( ! is_array($rows)) {
			return $out;
		}
		foreach ($rows as $row) {
			$row = array_change_key_case((array) $row, CASE_LOWER);
			$info = isset($row['info']) ? trim((string) $row['info']) : '';
			// Skip the SHOW ... PROCESSLIST statement we issued to read this.
			if ($info !== '' && stripos($info, 'processlist') !== false) {
				continue;
			}
			$out[] = array(
				'id'      => isset($row['id']) ? (string) $row['id'] : '',
				'user'    => isset($row['user']) ? (string) $row['user'] : '',
				'db'      => isset($row['db']) ? (string) $row['db'] : '',
				'command' => isset($row['command']) ? (string) $row['command'] : '',
				'time'    => isset($row['time']) ? (int) $row['time'] : 0,
				'state'   => isset($row['state']) ? (string) $row['state'] : '',
				'info'    => $info,
			);
		}
		usort($out, function ($a, $b) {
			$a_sleep = strcasecmp($a['command'], 'Sleep') === 0 ? 1 : 0;
			$b_sleep = strcasecmp($b['command'], 'Sleep') === 0 ? 1 : 0;
			if ($a_sleep !== $b_sleep) {
				return $a_sleep - $b_sleep; // active (0) sorts before sleeping (1)
			}
			return $b['time'] - $a['time']; // longest-running first
		});
		return $out;
	}
}

if ( ! function_exists('system_monitor_filter_processlist_by_db'))
{
	/**
	 * Keep only the processlist rows belonging to this project's database
	 * (case-insensitive). A blank $db returns the list unchanged. Operates on
	 * already-normalised rows from system_monitor_processlist_rows().
	 */
	function system_monitor_filter_processlist_by_db($rows, $db)
	{
		if ( ! is_array($rows)) {
			return array();
		}
		$db = trim((string) $db);
		if ($db === '') {
			return $rows;
		}
		$out = array();
		foreach ($rows as $row) {
			$row_db = is_array($row) && isset($row['db']) ? (string) $row['db'] : '';
			if (strcasecmp($row_db, $db) === 0) {
				$out[] = $row;
			}
		}
		return $out;
	}
}

if ( ! function_exists('system_monitor_db_size_from_row'))
{
	/**
	 * Normalise the one-row result of the information_schema.TABLES size query
	 * (SUM(data_length+index_length) AS bytes, COUNT(*) AS tables) into ints.
	 */
	function system_monitor_db_size_from_row($row)
	{
		$row = (array) $row;
		$get = function ($key) use ($row) {
			foreach ($row as $k => $v) {
				if (strcasecmp((string) $k, $key) === 0) {
					return $v;
				}
			}
			return 0;
		};
		return array(
			'bytes'  => (float) $get('bytes'),
			'tables' => (int) $get('tables'),
		);
	}
}

