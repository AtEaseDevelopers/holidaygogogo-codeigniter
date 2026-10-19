<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Pure helpers for the daily exchange-rate cron.
 *
 * Source: open.er-api.com (free, no key, refreshes once a day). The Cron
 * method fetches the JSON; these functions turn it into a clean rates map, the
 * /Costing/Currency update plan, and the run-log summary. No DB / no HTTP in
 * here so it stays unit-testable.
 */

if (!function_exists('currency_rate_parse_erapi_all')) {
    /**
     * Parse an open.er-api.com "/v6/latest/{BASE}" response into a rates map,
     * used to auto-feed every currency on /Costing/Currency from one API call.
     *
     * @param mixed $response  Raw JSON string OR decoded array.
     * @return array {
     *   ok        bool
     *   error     string|null
     *   base      string          upper-cased base (e.g. MYR)
     *   rate_date string          Y-m-d (UTC) the rates are dated, '' when unknown
     *   rates     array           [ 'USD' => 0.2234, 'SGD' => 0.30, ... ] positive only
     * }
     */
    function currency_rate_parse_erapi_all($response)
    {
        $out = ['ok' => false, 'error' => null, 'base' => '', 'rate_date' => '', 'rates' => []];

        $data = is_array($response) ? $response : json_decode((string) $response, true);
        if (!is_array($data)) {
            $out['error'] = 'invalid_json';
            return $out;
        }
        if (!isset($data['result']) || $data['result'] !== 'success') {
            $out['error'] = 'api_error:' . (isset($data['error-type']) ? $data['error-type'] : (isset($data['result']) ? $data['result'] : 'unknown'));
            return $out;
        }
        if (empty($data['rates']) || !is_array($data['rates'])) {
            $out['error'] = 'no_rates';
            return $out;
        }

        $out['base'] = isset($data['base_code']) ? strtoupper((string) $data['base_code']) : '';
        $out['rate_date'] = !empty($data['time_last_update_unix'])
            ? gmdate('Y-m-d', (int) $data['time_last_update_unix'])
            : gmdate('Y-m-d');

        foreach ($data['rates'] as $code => $rate) {
            $rate = (float) $rate;
            if ($rate > 0) {
                $out['rates'][strtoupper((string) $code)] = $rate;
            }
        }

        $out['ok'] = !empty($out['rates']);
        if (!$out['ok']) {
            $out['error'] = 'no_positive_rates';
        }
        return $out;
    }
}

if (!function_exists('currency_rate_costing_updates')) {
    /**
     * Plan the /Costing/Currency auto-update from a MYR-based API rates map.
     *
     * Costing stores rates as FOREIGN -> MYR (e.g. 1 USD = 4.72 MYR), while the
     * API gives MYR -> FOREIGN, so each rate is inverted (1 / rate). A currency
     * that already has a rate dated $rate_date is left untouched (a human — or an
     * earlier run — set it today); its bank-charges value is carried forward.
     *
     * Pure: returns payloads for Costing_Model::Save_Exchange_Rate; no DB here.
     *
     * @param array  $rates_map        [ 'USD' => 0.2234, ... ] MYR->foreign, positive
     * @param array  $currencies       rows [ ['id'=>.., 'code'=>..], ... ] incl. base
     * @param string $base_code        the base/home currency code (e.g. MYR)
     * @param array  $existing_by_code [ 'USD' => ['valid_from'=>'Y-m-d H:i:s','bank_charges_myr'=>..,'buffer'=>..], .. ]
     * @param string $rate_date        Y-m-d these rates are dated (valid_from + "today" guard)
     * @return array { updates: payload[], updated: string[], skipped: string[] }
     */
    function currency_rate_costing_updates($rates_map, $currencies, $base_code, $existing_by_code, $rate_date)
    {
        $result = ['updates' => [], 'updated' => [], 'skipped' => []];

        $base = strtoupper(trim((string) $base_code));
        $base_id = null;
        foreach ((array) $currencies as $c) {
            if (isset($c['code']) && strtoupper((string) $c['code']) === $base) {
                $base_id = (int) $c['id'];
                break;
            }
        }
        if (!$base_id) {
            return $result; // no home currency -> nothing to convert into
        }

        $existing_by_code = is_array($existing_by_code) ? $existing_by_code : [];

        foreach ((array) $currencies as $c) {
            $code = isset($c['code']) ? strtoupper((string) $c['code']) : '';
            if ($code === '' || $code === $base) {
                continue;
            }
            if (!isset($rates_map[$code]) || (float) $rates_map[$code] <= 0) {
                continue; // API had no usable rate for this currency
            }

            $existing = isset($existing_by_code[$code]) ? $existing_by_code[$code] : null;
            if ($existing && isset($existing['valid_from']) && substr((string) $existing['valid_from'], 0, 10) === (string) $rate_date) {
                $result['skipped'][] = $code; // already has a rate dated today
                continue;
            }

            $converted = round(1.0 / (float) $rates_map[$code], 8); // foreign -> MYR
            $bank = ($existing && isset($existing['bank_charges_myr'])) ? (float) $existing['bank_charges_myr'] : 0.0;
            // The buffer is a per-currency manual setting, not market data, so carry
            // the previous value forward rather than letting the nightly feed wipe it.
            $buffer = ($existing && isset($existing['buffer'])) ? (float) $existing['buffer'] : 0.0;

            $result['updates'][] = [
                'from_currency_id'    => (int) $c['id'],
                'to_currency_id'      => $base_id,
                'unit_amount'         => 1,
                'converted_amount'    => $converted,
                'bank_charges_myr'    => $bank,
                'buffer'              => $buffer,
                'updated_by_admin_id' => null, // system / auto feed
                'valid_from'          => $rate_date . ' 00:00:00',
            ];
            $result['updated'][] = $code;
        }

        return $result;
    }
}

if (!function_exists('currency_rate_run_log_summary')) {
    /**
     * Turn a costing auto-update result into flat run-log fields (counts + csv).
     * Pure: keeps the Cron controller and the run-log write dumb.
     *
     * @param array $costing_result { updated: string[], skipped: string[] }
     * @return array { currencies_updated int, currencies_skipped int,
     *                 updated_codes string, skipped_codes string }
     */
    function currency_rate_run_log_summary($costing_result)
    {
        $updated = (is_array($costing_result) && isset($costing_result['updated']) && is_array($costing_result['updated']))
            ? array_values($costing_result['updated']) : [];
        $skipped = (is_array($costing_result) && isset($costing_result['skipped']) && is_array($costing_result['skipped']))
            ? array_values($costing_result['skipped']) : [];

        return [
            'currencies_updated' => count($updated),
            'currencies_skipped' => count($skipped),
            'updated_codes'      => implode(',', $updated),
            'skipped_codes'      => implode(',', $skipped),
        ];
    }
}
