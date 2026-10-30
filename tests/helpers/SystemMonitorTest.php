<?php
/**
 * Run with: php tests/helpers/SystemMonitorTest.php
 *
 * Locks the pure System Monitor helpers that turn raw OS probes (sys_getloadavg,
 * /proc/meminfo, macOS vm_stat, disk_*_space) into the CPU / memory / disk
 * utilisation figures shown on the owner-only /system-monitor page.
 *
 * Guards against: (1) "CPU usage" must be load-per-core, not raw load — a
 * 4-core box at load 2.0 is 50%, not 200%; (2) status thresholds colour the
 * cards consistently (>=90 critical, >=70 warning); (3) Linux MemAvailable is
 * preferred over a free+cache sum; (4) used figures are clamped to [0, total]
 * so a bad probe can never render a negative or >100% bar.
 */

if (!defined('BASEPATH')) {
    define('BASEPATH', __DIR__);
}

require_once __DIR__ . '/../../application/helpers/system_monitor_helper.php';

$failures = 0;
function check($label, $expected, $actual)
{
    global $failures;
    if ($expected === $actual) {
        echo "PASS: $label\n";
    } else {
        $failures++;
        echo "FAIL: $label\n  expected: " . var_export($expected, true) . "\n  actual:   " . var_export($actual, true) . "\n";
    }
}

// ---- status thresholds ----
check('status ok below 70', 'ok', system_monitor_status_from_percent(69.9));
check('status warning at 70', 'warning', system_monitor_status_from_percent(70));
check('status warning below 90', 'warning', system_monitor_status_from_percent(89.9));
check('status critical at 90', 'critical', system_monitor_status_from_percent(90));
check('status critical over 100', 'critical', system_monitor_status_from_percent(140));

// ---- cpu count from /proc/cpuinfo ----
$cpuinfo = "processor\t: 0\nvendor_id\t: GenuineIntel\n\nprocessor\t: 1\nvendor_id\t: GenuineIntel\n";
check('cpuinfo counts logical cpus', 2, system_monitor_cpu_count_from_cpuinfo($cpuinfo));
check('cpuinfo empty is 0', 0, system_monitor_cpu_count_from_cpuinfo(''));

// ---- load metrics: the core "CPU usage %" math ----
$cpu = system_monitor_load_metrics(array(2.0, 1.0, 0.5), 4);
check('load percent is per-core (2.0/4 cores = 50%)', 50.0, $cpu['percent']);
check('load percent_5', 25.0, $cpu['percent_5']);
check('load percent_15', 12.5, $cpu['percent_15']);
check('load keeps raw 1-min', 2.0, $cpu['load_1']);
check('load 50% is ok status', 'ok', $cpu['status']);

$busy = system_monitor_load_metrics(array(4.0, 4.0, 4.0), 4);
check('fully loaded box is 100%', 100.0, $busy['percent']);
check('fully loaded is critical', 'critical', $busy['status']);

$over = system_monitor_load_metrics(array(8.0, 0, 0), 4);
check('load can exceed 100% (overloaded)', 200.0, $over['percent']);

// cores coerced to >= 1 so we never divide by zero
$zero = system_monitor_load_metrics(array(1.0, 0, 0), 0);
check('zero cores coerced to 1', 1, $zero['cores']);
check('zero cores -> 100%', 100.0, $zero['percent']);

// missing loadavg entries default to 0
$empty = system_monitor_load_metrics(array(), 2);
check('empty loadavg -> 0%', 0.0, $empty['percent']);

// ---- memory metrics clamping ----
$mem = system_monitor_memory_metrics(1000, 250);
check('memory percent from used', 25.0, $mem['percent']);
check('memory available filled in', 750.0, $mem['available_bytes']);

$fromAvail = system_monitor_memory_metrics(1000, null, 400);
check('memory used derived from available', 600.0, $fromAvail['used_bytes']);

check('memory total 0 -> null', null, system_monitor_memory_metrics(0, 10));
$neg = system_monitor_memory_metrics(1000, -50);
check('negative used clamped to 0', 0.0, $neg['used_bytes']);
$overused = system_monitor_memory_metrics(1000, 5000);
check('used over total clamped to total', 1000.0, $overused['used_bytes']);
check('clamped used -> 100%', 100.0, $overused['percent']);

// ---- /proc/meminfo parsing ----
$meminfo = "MemTotal:       16384000 kB\nMemFree:         1000000 kB\nMemAvailable:    8192000 kB\nBuffers:          500000 kB\nCached:          4000000 kB\n";
$pm = system_monitor_parse_meminfo($meminfo);
check('meminfo total bytes', 16384000.0 * 1024, $pm['total_bytes']);
// prefers MemAvailable (8192000kB) => used = total - available = 8192000kB
check('meminfo used prefers MemAvailable', 8192000.0 * 1024, $pm['used_bytes']);
check('meminfo percent', 50.0, $pm['percent']);

// falls back to free+buffers+cached when MemAvailable absent
$meminfo2 = "MemTotal:       10000 kB\nMemFree:         1000 kB\nBuffers:          500 kB\nCached:          2500 kB\n";
$pm2 = system_monitor_parse_meminfo($meminfo2);
// available = 1000+500+2500 = 4000 => used = 6000
check('meminfo fallback used', 6000.0 * 1024, $pm2['used_bytes']);

check('meminfo no MemTotal -> null', null, system_monitor_parse_meminfo("Foo: 1 kB\n"));
check('meminfo empty -> null', null, system_monitor_parse_meminfo(''));

// ---- macOS vm_stat parsing ----
$vmstat = "Mach Virtual Memory Statistics: (page size of 4096 bytes)\n"
    . "Pages free:                          100.\n"
    . "Pages active:                        200.\n"
    . "Pages inactive:                      150.\n"
    . "Pages speculative:                    50.\n"
    . "Pages wired down:                    300.\n"
    . "Pages occupied by compressor:        100.\n";
// used = (wired 300 + active 200 + compressor 100) * 4096 = 600 * 4096
$vm = system_monitor_parse_vm_stat($vmstat, 10000 * 4096);
check('vm_stat used bytes', 600.0 * 4096, $vm['used_bytes']);
check('vm_stat no page size -> null', null, system_monitor_parse_vm_stat("Pages free: 1.\n", 4096));
check('vm_stat no total -> null', null, system_monitor_parse_vm_stat($vmstat, 0));

// ---- disk metrics ----
$disk = system_monitor_disk_metrics(1000, 250);
check('disk used = total - free', 750.0, $disk['used_bytes']);
check('disk percent', 75.0, $disk['percent']);
check('disk warning status', 'warning', $disk['status']);
check('disk total 0 -> null', null, system_monitor_disk_metrics(0, 0));
$diskbad = system_monitor_disk_metrics(1000, 5000); // free > total
check('free over total clamped -> 0% used', 0.0, $diskbad['percent']);

// ---- formatting ----
check('format 0 bytes', '0 B', system_monitor_format_bytes(0));
check('format bytes', '512 B', system_monitor_format_bytes(512));
check('format KB', '1 KB', system_monitor_format_bytes(1024));
check('format MB', '1.5 MB', system_monitor_format_bytes(1024 * 1024 * 1.5));
check('format GB', '2 GB', system_monitor_format_bytes(1024 * 1024 * 1024 * 2));

check('uptime zero', '—', system_monitor_format_uptime(0));
check('uptime minutes only', '5m', system_monitor_format_uptime(5 * 60));
check('uptime hours+mins', '2h 5m', system_monitor_format_uptime(2 * 3600 + 5 * 60));
check('uptime days+hours+mins', '3d 4h 12m', system_monitor_format_uptime(3 * 86400 + 4 * 3600 + 12 * 60));

// ---- human-readable duration (processlist Time column) ----
check('duration zero', '0s', system_monitor_format_duration(0));
check('duration seconds', '5s', system_monitor_format_duration(5));
check('duration under a minute stays seconds', '59s', system_monitor_format_duration(59));
check('duration minutes+seconds', '2m 5s', system_monitor_format_duration(125));
check('duration hours+mins', '1h 1m', system_monitor_format_duration(3665));
check('duration days+hours', '6d 0h', system_monitor_format_duration(520371));

// ---- SQL: SHOW GLOBAL STATUS pairs -> map ----
$pairs = array(
    array('Variable_name' => 'Uptime', 'Value' => '3600'),
    array('Variable_name' => 'Questions', 'Value' => '7200'),
    array('Variable_name' => 'Queries', 'Value' => '9000'),
    array('Variable_name' => 'Threads_connected', 'Value' => '12'),
    array('Variable_name' => 'Threads_running', 'Value' => '3'),
    array('Variable_name' => 'Slow_queries', 'Value' => '42'),
);
$map = system_monitor_status_pairs_to_map($pairs);
check('status map folds pairs', '9000', $map['Queries']);
// case-insensitive column names (some drivers upper-case them)
$mapU = system_monitor_status_pairs_to_map(array(array('VARIABLE_NAME' => 'Uptime', 'VALUE' => '10')));
check('status map case-insensitive cols', '10', $mapU['Uptime']);
check('status map non-array -> empty', array(), system_monitor_status_pairs_to_map('nope'));

// ---- SQL metrics ----
$sql = system_monitor_sql_metrics($map);
check('sql uptime', 3600, $sql['uptime']);
check('sql prefers Queries over Questions for total', 9000, $sql['total']);
check('sql qps_avg = total/uptime', 2.5, $sql['qps_avg']);
check('sql threads_connected', 12, $sql['threads_connected']);
check('sql threads_running', 3, $sql['threads_running']);
check('sql slow_queries', 42, $sql['slow_queries']);

// falls back to Questions when Queries absent
$sql2 = system_monitor_sql_metrics(array('Uptime' => '100', 'Questions' => '500'));
check('sql total falls back to Questions', 500, $sql2['total']);
check('sql qps_avg from Questions', 5.0, $sql2['qps_avg']);

// zero uptime must not divide by zero
$sql3 = system_monitor_sql_metrics(array('Questions' => '10'));
check('sql qps_avg 0 when uptime 0', 0.0, $sql3['qps_avg']);

// ---- processlist normalisation / sorting ----
$raw = array(
    array('Id' => 1, 'User' => 'app', 'db' => 'hol', 'Command' => 'Sleep', 'Time' => 200, 'State' => '', 'Info' => null),
    array('Id' => 2, 'User' => 'app', 'db' => 'hol', 'Command' => 'Query', 'Time' => 5, 'State' => 'Sending data', 'Info' => 'SELECT * FROM booking'),
    array('Id' => 3, 'User' => 'app', 'db' => 'hol', 'Command' => 'Query', 'Time' => 30, 'State' => 'Sorting', 'Info' => 'SELECT * FROM payment'),
    array('Id' => 9, 'User' => 'app', 'db' => 'hol', 'Command' => 'Query', 'Time' => 0, 'State' => 'executing', 'Info' => 'SHOW FULL PROCESSLIST'),
);
$norm = system_monitor_processlist_rows($raw);
check('processlist drops own PROCESSLIST query', 3, count($norm));
// active (non-Sleep) first, longest-running first => id 3 (30s), id 2 (5s), then Sleep id 1
check('processlist sorts active-then-time: first id', '3', $norm[0]['id']);
check('processlist second id', '2', $norm[1]['id']);
check('processlist sleeping sorts last', '1', $norm[2]['id']);
check('processlist null info -> empty string', '', $norm[2]['info']);
check('processlist non-array -> empty', array(), system_monitor_processlist_rows('x'));

// ---- active count ----
check('active count ignores Sleep', 2, system_monitor_sql_active_count($norm));
check('active count empty', 0, system_monitor_sql_active_count(array()));

// ---- per-project: processlist filtered to this DB ----
$byDb = system_monitor_filter_processlist_by_db($norm, 'HOL'); // case-insensitive
check('filter by db keeps matching rows', 3, count($byDb));
$plistMixed = system_monitor_processlist_rows(array(
    array('Id' => 1, 'db' => 'holidaybooking', 'Command' => 'Query', 'Time' => 2, 'Info' => 'SELECT 1'),
    array('Id' => 2, 'db' => 'otherproject',   'Command' => 'Query', 'Time' => 9, 'Info' => 'SELECT 2'),
));
$mine = system_monitor_filter_processlist_by_db($plistMixed, 'holidaybooking');
check('filter by db excludes other projects', 1, count($mine));
check('filter by db keeps the right row', '1', $mine[0]['id']);
check('filter by blank db returns all', 2, count(system_monitor_filter_processlist_by_db($plistMixed, '')));

// ---- per-project: DB size row ----
$sz = system_monitor_db_size_from_row(array('bytes' => '10485760', 'tables' => '42'));
check('db size bytes', 10485760.0, $sz['bytes']);
check('db size tables', 42, $sz['tables']);
$szU = system_monitor_db_size_from_row(array('BYTES' => '100', 'TABLES' => '3')); // upper-case cols
check('db size case-insensitive cols', 100.0, $szU['bytes']);
$szNull = system_monitor_db_size_from_row(array('bytes' => null, 'tables' => 0)); // empty schema
check('db size null bytes -> 0', 0.0, $szNull['bytes']);

// ---- per-project: sum processes from ps output ----
$ps = "  10 www      5.0  10240 php-fpm: pool holidaygogogo\n"
    . "  11 www      3.0  20480 php-fpm: pool holidaygogogo\n"
    . "  12 www      9.0  51200 php-fpm: pool othersite\n"
    . "  13 root     1.0   4096 /usr/sbin/cron\n";
// match by project string: only the two holidaygogogo workers
$byMatch = system_monitor_sum_processes($ps, 'holidaygogogo', 'www');
check('sum by match mode', 'match', $byMatch['mode']);
check('sum by match count', 2, $byMatch['count']);
check('sum by match cpu', 8.0, $byMatch['cpu_percent']);
check('sum by match mem (KiB->bytes)', (10240.0 + 20480.0) * 1024, $byMatch['mem_bytes']);
// match by user: all three www rows, not root
$byUser = system_monitor_sum_processes($ps, '', 'www');
check('sum by user mode', 'user', $byUser['mode']);
check('sum by user count', 3, $byUser['count']);
check('sum by user cpu', 17.0, $byUser['cpu_percent']);
// no discriminator at all
check('sum no mode when nothing to match', 'none', system_monitor_sum_processes($ps, '', '')['mode']);
check('sum empty output', 0, system_monitor_sum_processes('', 'x', 'y')['count']);

// ---- per-project: CPU % of host cores ----
$pcpu = system_monitor_project_cpu(16.0, 8, 4); // 16% summed over 8 cores = 2%
check('project cpu percent of cores', 2.0, $pcpu['percent']);
check('project cpu status', 'ok', $pcpu['status']);
check('project cpu null when no processes', null, system_monitor_project_cpu(16.0, 8, 0));
$pcpuBusy = system_monitor_project_cpu(720.0, 8, 10); // 720/8 = 90%
check('project cpu critical', 'critical', $pcpuBusy['status']);

// ---- per-project: du parsing ----
check('du kb -> bytes', 560668.0 * 1024, system_monitor_parse_du_kb("560668\t/path/to/project"));
check('du leading spaces', 1024.0 * 1024, system_monitor_parse_du_kb("   1024  /x"));
check('du non-numeric -> null', null, system_monitor_parse_du_kb("du: cannot access"));
check('du empty -> null', null, system_monitor_parse_du_kb(''));

echo "\n" . ($failures === 0 ? "ALL PASS" : "$failures FAILURE(S)") . "\n";
exit($failures === 0 ? 0 : 1);
