<?php
/**
 * Run with: php tests/helpers/DailyExchangeRateTest.php
 *
 * Locks the daily exchange-rate cron:
 *   - Cron::fetchExchangeRates() pulls Bank Negara Malaysia /public/exchange-rate once a day.
 *   - currency_rate_parse_bnm_all() turns the JSON into a clean rates map
 *     (middle_rate / unit, emitted as its MYR->foreign reciprocal).
 *   - currency_rate_parse_erapi_all() (legacy source) is kept + still tested.
 *   - currency_rate_costing_updates() plans the /Costing/Currency refresh
 *     (invert MYR->foreign to foreign->MYR, skip same-day, carry bank charges).
 *   - currency_rate_run_log_summary() flattens the result for the audit trail.
 *   - Exchange_Rate_Model logs each run to exchange_rate_run_log.
 *
 * Five halves:
 *   1.  Pure parse    (helper): the whole er-api rates map, success / failure shapes.
 *   1b. Pure parse    (helper): BNM rates map (unit division, middle_rate, round-trip).
 *   2.  Pure plan     (helper): inversion / skip-today / carry-forward.
 *   3.  Run log       (helper + SQLite): summary fields + start/finish rows.
 *   4.  Source contract: Cron / models / migrations / README wire it correctly.
 */

if (!defined('BASEPATH')) {
    define('BASEPATH', __DIR__);
}

require_once __DIR__ . '/../../application/helpers/currency_rate_helper.php';

$assertions = [];

/* ------------------------------------------------------------------ *
 * 1) PURE PARSE  (whole rates map)                                    *
 * ------------------------------------------------------------------ */

$multi = json_encode([
    'result'                => 'success',
    'base_code'             => 'MYR',
    'time_last_update_unix' => strtotime('2026-08-11 00:00:00 UTC'),
    'rates'                 => ['MYR' => 1, 'USD' => 0.25, 'SGD' => 0.30, 'THB' => 8.0, 'IDR' => 0, 'BAD' => -1],
]);
$all = currency_rate_parse_erapi_all($multi);
$assertions['all: ok + base + date']            = ($all['ok'] === true && $all['base'] === 'MYR' && $all['rate_date'] === '2026-08-11');
$assertions['all: drops non-positive rates']    = (!isset($all['rates']['IDR']) && !isset($all['rates']['BAD']));
$assertions['all: keeps positive rates']        = (abs($all['rates']['USD'] - 0.25) < 1e-9 && abs($all['rates']['THB'] - 8.0) < 1e-9);
$assertions['all: upper-cases codes']           = (isset($all['rates']['USD']));
$assertions['all: accepts decoded array too']   = (currency_rate_parse_erapi_all(json_decode($multi, true))['ok'] === true);
$assertions['all: error body -> not ok']        = (currency_rate_parse_erapi_all('{"result":"error","error-type":"unsupported-code"}')['ok'] === false);
$assertions['all: garbage json -> not ok']      = (currency_rate_parse_erapi_all('not json')['error'] === 'invalid_json');

// No timestamp -> dates to today (UTC).
$nots = currency_rate_parse_erapi_all(json_encode(['result' => 'success', 'base_code' => 'MYR', 'rates' => ['USD' => 0.22]]));
$assertions['all: no ts -> today (UTC)']         = ($nots['ok'] === true && $nots['rate_date'] === gmdate('Y-m-d'));

/* ------------------------------------------------------------------ *
 * 1b) PURE PARSE  (Bank Negara Malaysia feed)                         *
 * ------------------------------------------------------------------ */

$bnmJson = json_encode([
    'meta' => ['quote' => 'rm', 'session' => '1700', 'last_updated' => '2026-10-16 23:01:26', 'total_result' => 5],
    'data' => [
        ['currency_code' => 'USD', 'unit' => 1,   'rate' => ['date' => '2026-10-16', 'buying_rate' => 4.07, 'selling_rate' => 4.09, 'middle_rate' => 4.082]],
        ['currency_code' => 'JPY', 'unit' => 100, 'rate' => ['date' => '2026-10-16', 'buying_rate' => 2.57, 'selling_rate' => 2.60, 'middle_rate' => 2.5834]],
        ['currency_code' => 'BAD', 'unit' => 1,   'rate' => ['date' => '2026-10-16', 'buying_rate' => 0,    'selling_rate' => 0,    'middle_rate' => 0]],      // no middle -> skip
        ['currency_code' => 'ZUN', 'unit' => 0,   'rate' => ['date' => '2026-10-16', 'middle_rate' => 5.0]],                                                   // zero unit -> skip
    ],
]);
$bnm = currency_rate_parse_bnm_all($bnmJson);

$assertions['bnm: ok + base MYR']               = ($bnm['ok'] === true && $bnm['base'] === 'MYR');
$assertions['bnm: date from meta.last_updated'] = ($bnm['rate_date'] === '2026-10-16');
$assertions['bnm: unit=1 -> 1/middle']          = (abs($bnm['rates']['USD'] - (1 / 4.082)) < 1e-9);
$assertions['bnm: unit=100 -> 100/middle']      = (abs($bnm['rates']['JPY'] - (100 / 2.5834)) < 1e-9);
$assertions['bnm: ignores buying/selling']      = (abs($bnm['rates']['USD'] - (1 / 4.082)) < 1e-9); // would differ if buying/selling used
$assertions['bnm: drops zero middle_rate']      = (!isset($bnm['rates']['BAD']));
$assertions['bnm: drops zero unit']             = (!isset($bnm['rates']['ZUN']));
$assertions['bnm: accepts decoded array too']   = (currency_rate_parse_bnm_all(json_decode($bnmJson, true))['ok'] === true);
$assertions['bnm: garbage json -> not ok']      = (currency_rate_parse_bnm_all('not json')['error'] === 'invalid_json');
$assertions['bnm: empty data -> no_rates']      = (currency_rate_parse_bnm_all('{"meta":{},"data":[]}')['error'] === 'no_rates');
$assertions['bnm: no last_updated -> today']    = (currency_rate_parse_bnm_all(json_encode(['data' => [['currency_code' => 'USD', 'unit' => 1, 'rate' => ['middle_rate' => 4.0]]]]))['rate_date'] === gmdate('Y-m-d'));

// Round-trip: BNM map -> planner must store FOREIGN->MYR = middle/unit.
$rtCurrencies = [['id' => 1, 'code' => 'MYR'], ['id' => 2, 'code' => 'USD'], ['id' => 3, 'code' => 'JPY']];
$rtPlan = currency_rate_costing_updates($bnm['rates'], $rtCurrencies, 'MYR', [], '2026-10-16');
$rtByCode = [];
foreach ($rtPlan['updates'] as $u) { $rtByCode[$u['from_currency_id']] = $u; }
$assertions['bnm round-trip: USD->MYR = 4.082']  = (abs($rtByCode[2]['converted_amount'] - 4.082) < 1e-9);
$assertions['bnm round-trip: JPY->MYR = mid/100'] = (abs($rtByCode[3]['converted_amount'] - (2.5834 / 100)) < 1e-9);

/* ------------------------------------------------------------------ *
 * 2) PURE PLAN  (costing auto-feed)                                   *
 * ------------------------------------------------------------------ */

$currencies = [
    ['id' => 1, 'code' => 'MYR'],
    ['id' => 2, 'code' => 'USD'],
    ['id' => 3, 'code' => 'SGD'],
    ['id' => 4, 'code' => 'THB'],
    ['id' => 5, 'code' => 'JPY'], // present on page but NOT in the API map
];
$existing = [
    'SGD' => ['valid_from' => '2026-08-11 09:00:00', 'bank_charges_myr' => 5.00], // manual, dated TODAY -> skip
    'THB' => ['valid_from' => '2026-07-01 00:00:00', 'bank_charges_myr' => 2.50, 'buffer' => 0.03], // old -> update, carry 2.50 + buffer 0.03
];
$plan = currency_rate_costing_updates($all['rates'], $currencies, 'MYR', $existing, '2026-08-11');

$byCode = [];
foreach ($plan['updates'] as $u) { $byCode[$u['from_currency_id']] = $u; }

$assertions['plan: skips base MYR']             = !in_array('MYR', $plan['updated'], true);
$assertions['plan: skips currency not in feed'] = !in_array('JPY', $plan['updated'], true);
$assertions['plan: skips manual-rate-today']    = (in_array('SGD', $plan['skipped'], true) && !in_array('SGD', $plan['updated'], true));
$assertions['plan: updates USD (no prior row)'] = in_array('USD', $plan['updated'], true);
$assertions['plan: inverts MYR->USD to USD->MYR'] = (abs($byCode[2]['converted_amount'] - (1 / 0.25)) < 1e-9); // 1 USD = 4.0 MYR
$assertions['plan: to_currency is MYR id']      = ($byCode[2]['to_currency_id'] === 1);
$assertions['plan: unit_amount is 1']           = ((int) $byCode[2]['unit_amount'] === 1);
$assertions['plan: new USD bank charges = 0']   = (abs((float) $byCode[2]['bank_charges_myr']) < 1e-9);
$assertions['plan: THB carries prior charges']  = (abs((float) $byCode[4]['bank_charges_myr'] - 2.50) < 1e-9);
$assertions['plan: THB carries prior buffer']   = (abs((float) $byCode[4]['buffer'] - 0.03) < 1e-9);
$assertions['plan: new USD buffer = 0']         = (abs((float) $byCode[2]['buffer']) < 1e-9);
$assertions['plan: THB inverted']               = (abs($byCode[4]['converted_amount'] - (1 / 8.0)) < 1e-9);
$assertions['plan: auto rows are system-owned'] = ($byCode[2]['updated_by_admin_id'] === null);
$assertions['plan: valid_from dated rate_date'] = ($byCode[2]['valid_from'] === '2026-08-11 00:00:00');

// No home currency in the list -> nothing to do (safety).
$assertions['plan: no MYR present -> empty']    =
    (currency_rate_costing_updates($all['rates'], [['id' => 2, 'code' => 'USD']], 'MYR', [], '2026-08-11')['updated'] === []);

/* ------------------------------------------------------------------ *
 * 3) RUN LOG  (traceability: summary helper + start/finish rows)      *
 * ------------------------------------------------------------------ */

// Pure: costing result -> flat run-log fields.
$sum = currency_rate_run_log_summary(['updated' => ['USD', 'THB'], 'skipped' => ['SGD']]);
$assertions['sum: counts updated']              = ($sum['currencies_updated'] === 2);
$assertions['sum: counts skipped']              = ($sum['currencies_skipped'] === 1);
$assertions['sum: updated csv']                 = ($sum['updated_codes'] === 'USD,THB');
$assertions['sum: skipped csv']                 = ($sum['skipped_codes'] === 'SGD');
$empty = currency_rate_run_log_summary(null);
$assertions['sum: null-safe']                   = ($empty['currencies_updated'] === 0 && $empty['updated_codes'] === '');

// Behavioural: mirror Start_Run_Log (insert running) + Finish_Run_Log (update by run_id).
$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec("CREATE TABLE exchange_rate_run_log (
    id INTEGER PRIMARY KEY,
    run_id TEXT NOT NULL UNIQUE,
    source TEXT,
    status TEXT NOT NULL DEFAULT 'running',
    base_code TEXT, quote_code TEXT, rate REAL, rate_date TEXT,
    currencies_updated INTEGER NOT NULL DEFAULT 0,
    currencies_skipped INTEGER NOT NULL DEFAULT 0,
    updated_codes TEXT, skipped_codes TEXT, error TEXT,
    started_at TEXT NOT NULL, finished_at TEXT
)");

$startRun = function ($source) use ($pdo) {
    $run_id = 'exrate_' . $source . '_' . substr(md5($source . microtime(true)), 0, 8);
    $pdo->prepare("INSERT INTO exchange_rate_run_log (run_id, source, status, started_at) VALUES (?,?, 'running', '2026-08-11 03:30:00')")
        ->execute([$run_id, $source]);
    return $run_id;
};
$finishRun = function ($run_id, $status, $fields) use ($pdo) {
    $cols = array_merge(['status' => $status, 'finished_at' => '2026-08-11 03:30:02'], $fields);
    $set  = implode(', ', array_map(function ($k) { return "$k = :$k"; }, array_keys($cols)));
    $stmt = $pdo->prepare("UPDATE exchange_rate_run_log SET $set WHERE run_id = :rid");
    $cols['rid'] = $run_id;
    $stmt->execute($cols);
};

// Success run.
$r1 = $startRun('cli');
$openRow = $pdo->query("SELECT status, finished_at FROM exchange_rate_run_log WHERE run_id='$r1'")->fetch(PDO::FETCH_ASSOC);
$assertions['log: opens as running']            = ($openRow['status'] === 'running' && $openRow['finished_at'] === null);

$finishRun($r1, 'completed', array_merge(
    ['base_code' => 'MYR', 'quote_code' => 'USD', 'rate' => 0.2234, 'rate_date' => '2026-08-11'],
    currency_rate_run_log_summary(['updated' => ['USD', 'THB'], 'skipped' => ['SGD']])
));
$doneRow = $pdo->query("SELECT * FROM exchange_rate_run_log WHERE run_id='$r1'")->fetch(PDO::FETCH_ASSOC);
$assertions['log: closes as completed']         = ($doneRow['status'] === 'completed' && $doneRow['finished_at'] !== null);
$assertions['log: records headline rate']       = (abs((float) $doneRow['rate'] - 0.2234) < 1e-9 && $doneRow['rate_date'] === '2026-08-11');
$assertions['log: records counts + csv']        = ((int) $doneRow['currencies_updated'] === 2 && $doneRow['updated_codes'] === 'USD,THB' && $doneRow['skipped_codes'] === 'SGD');
$assertions['log: no error on success']         = ($doneRow['error'] === null);

// Failed run keeps its own row + reason.
$r2 = $startRun('cli');
$finishRun($r2, 'failed', ['error' => 'curl_error:timeout']);
$failRow = $pdo->query("SELECT status, error FROM exchange_rate_run_log WHERE run_id='$r2'")->fetch(PDO::FETCH_ASSOC);
$assertions['log: failed run recorded']         = ($failRow['status'] === 'failed' && $failRow['error'] === 'curl_error:timeout');
$assertions['log: one row per run']             = ((int) $pdo->query("SELECT COUNT(*) FROM exchange_rate_run_log")->fetchColumn() === 2);

// "Last completed run" read (mirrors Read_Last_Completed_Run) ignores the failed row.
$lastGood = $pdo->query("SELECT rate, rate_date FROM exchange_rate_run_log WHERE status='completed' AND rate IS NOT NULL ORDER BY finished_at DESC, id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$assertions['log: last-completed skips failed'] = (abs((float) $lastGood['rate'] - 0.2234) < 1e-9 && $lastGood['rate_date'] === '2026-08-11');

/* ------------------------------------------------------------------ *
 * 4) SOURCE CONTRACT                                                  *
 * ------------------------------------------------------------------ */

$cron = @file_get_contents(__DIR__ . '/../../application/controllers/Cron.php');
$assertions['cron: fetchExchangeRates() defined']  = (bool) preg_match('/function\s+fetchExchangeRates\s*\(/', (string) $cron);
$assertions['cron: CLI-gated']                     = (bool) preg_match('/fetchExchangeRates[\s\S]{0,400}is_cli\s*\(/', (string) $cron);
$assertions['cron: hits BNM exchange-rate']        = (strpos((string) $cron, 'api.bnm.gov.my/public/exchange-rate') !== false);
$assertions['cron: sends BNM Accept header']       = (strpos((string) $cron, 'application/vnd.BNM.API.v1+json') !== false);
$assertions['cron: parses full rates map']         = (strpos((string) $cron, 'currency_rate_parse_bnm_all(') !== false);
$assertions['cron: feeds Costing rates']           = (strpos((string) $cron, 'Auto_Update_Rates_From_Feed(') !== false);
// Rates are stored under the REAL run date, not the API's (lagging) date.
$assertions['cron: stamps run date']               = (bool) preg_match("/\\\$storeDate\s*=\s*date\('Y-m-d'\)/", (string) $cron);
$assertions['cron: feeds costing with run date']   = (strpos((string) $cron, 'Auto_Update_Rates_From_Feed($all[\'rates\'], $storeDate)') !== false);
$assertions['cron: does NOT feed costing api date'] = (strpos((string) $cron, "Auto_Update_Rates_From_Feed(\$all['rates'], \$all['rate_date'])") === false);
$assertions['cron: opens run log']                 = (strpos((string) $cron, 'Start_Run_Log(') !== false);
$assertions['cron: closes run log (completed)']    = (bool) preg_match("/Finish_Run_Log\([^;]*'completed'/s", (string) $cron);
$assertions['cron: closes run log (failed)']       = (bool) preg_match("/Finish_Run_Log\([^;]*'failed'/s", (string) $cron);
// daily_exchange_rate is fully gone.
$assertions['cron: no daily_exchange_rate']        = (strpos((string) $cron, 'Save_Daily_Rate') === false && stripos((string) $cron, 'daily_exchange_rate') === false);

$costing = @file_get_contents(__DIR__ . '/../../application/models/Costing_Model.php');
$assertions['costing: auto-update method']         = (bool) preg_match('/function\s+Auto_Update_Rates_From_Feed\s*\(/', (string) $costing);
$assertions['costing: uses the plan helper']       = (strpos((string) $costing, 'currency_rate_costing_updates(') !== false);
$assertions['costing: writes via Save_Exchange_Rate'] = (strpos((string) $costing, 'Save_Exchange_Rate(') !== false);

$model = @file_get_contents(__DIR__ . '/../../application/models/Exchange_Rate_Model.php');
$assertions['model: Start_Run_Log defined']        = (bool) preg_match('/function\s+Start_Run_Log\s*\(/', (string) $model);
$assertions['model: Finish_Run_Log defined']       = (bool) preg_match('/function\s+Finish_Run_Log\s*\(/', (string) $model);
$assertions['model: Read_Last_Completed_Run defined'] = (bool) preg_match('/function\s+Read_Last_Completed_Run\s*\(/', (string) $model);
$assertions['model: no daily-rate methods']        = (strpos((string) $model, 'Save_Daily_Rate') === false && stripos((string) $model, 'daily_exchange_rate') === false);

$helper = @file_get_contents(__DIR__ . '/../../application/helpers/currency_rate_helper.php');
$assertions['helper: no dead pick_latest']         = (strpos((string) $helper, 'currency_rate_pick_latest') === false);

$runmig = @file_get_contents(__DIR__ . '/../../application/sql/20260811_Create_Exchange_Rate_Run_Log.sql');
$assertions['migration: run-log table']            = (stripos((string) $runmig, 'CREATE TABLE') !== false && strpos((string) $runmig, 'exchange_rate_run_log') !== false);
$assertions['migration: run-log has status']       = (strpos((string) $runmig, "'running'") !== false && strpos((string) $runmig, "'completed'") !== false && strpos((string) $runmig, "'failed'") !== false);

$dropmig = @file_get_contents(__DIR__ . '/../../application/sql/20260811_Drop_Daily_Exchange_Rate.sql');
$assertions['migration: drops daily table']        = (stripos((string) $dropmig, 'DROP TABLE') !== false && strpos((string) $dropmig, 'daily_exchange_rate') !== false);
$assertions['migration: no create daily table']    = !is_file(__DIR__ . '/../../application/sql/20260811_Create_Daily_Exchange_Rate.sql');

$readme = @file_get_contents(__DIR__ . '/../../README.md');
$assertions['readme: documents the cron']          = (strpos((string) $readme, 'fetchExchangeRates') !== false);

/* ------------------------------------------------------------------ */

$fail = 0;
foreach ($assertions as $label => $ok) {
    echo ($ok ? 'PASS  ' : 'FAIL  ') . $label . "\n";
    if (!$ok) {
        $fail++;
    }
}
echo "\n" . (count($assertions) - $fail) . '/' . count($assertions) . " passed\n";
exit($fail === 0 ? 0 : 1);
