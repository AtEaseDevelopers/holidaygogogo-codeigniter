<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Pure costing/quotation math. No DB, no HTTP, no date functions, so it stays
 * unit-testable on plain PHP + SQLite (see tests/helpers/CostingCalcHelperTest.php).
 *
 * A costing is built from cost rows priced in any currency. A frozen per-costing
 * rate snapshot (1 <CUR> = X MYR) converts every row to MYR.
 *
 * Customer pricing uses a GROSS MARGIN — the margin is a share of the SELLING
 * price, so the cost is grossed up (never a simple markup on cost):
 *
 *   cost after markup = cost / (1 - margin%/100)   (costing_cost_after_markup)
 *
 * Each combination may still override that suggested price with a manual selling
 * price entered on the cost step.
 */

if (!function_exists('costing_categories')) {
    /**
     * Default seed / fallback cost categories, code => human label. Categories are
     * now a dynamic master (table costing_categories, see Costing_Category_Model);
     * this list only seeds a fresh install and is the fallback when the master is
     * empty. Shared with the pure math below so tests stay DB-free.
     *
     * @return array<string,string>
     */
    function costing_categories()
    {
        return [
            'flight'        => 'Flight',
            'accommodation' => 'Accommodation',
            'other'         => 'Other',
            'tour_leader'   => 'Tour Leader',
            'miscellaneous' => 'Miscellaneous',
        ];
    }
}

if (!function_exists('costing_category_slug')) {
    /**
     * Turn a category name into a stable, unique code slug (lowercase a-z0-9_).
     * Used when creating a new category in the master. Pure so it stays testable.
     *
     * @param string   $name     human label, e.g. "Land Transport"
     * @param string[] $existing codes already taken (any case) to avoid clashes
     * @return string slug, e.g. "land_transport" (suffixed _2, _3... on clash);
     *                empty name falls back to "category".
     */
    function costing_category_slug($name, $existing = [])
    {
        $slug = strtolower(trim((string) $name));
        $slug = preg_replace('/[^a-z0-9]+/', '_', $slug);
        $slug = trim((string) $slug, '_');
        if ($slug === '') {
            $slug = 'category';
        }

        $taken = [];
        foreach ((array) $existing as $code) {
            $taken[strtolower(trim((string) $code))] = true;
        }

        if (!isset($taken[$slug])) {
            return $slug;
        }

        $n = 2;
        while (isset($taken[$slug . '_' . $n])) {
            $n++;
        }
        return $slug . '_' . $n;
    }
}

if (!function_exists('costing_line_to_myr')) {
    /**
     * Convert one cost row to MYR using the frozen snapshot rate map.
     *
     * @param array $line     needs currency_code, unit_cost, quantity
     * @param array $rate_map currency_code (upper) => rate_to_myr
     * @return float MYR total (unit_cost * quantity * rate), 2dp. Missing rate -> 0.
     */
    function costing_line_to_myr($line, $rate_map)
    {
        $code = strtoupper(trim((string) (isset($line['currency_code']) ? $line['currency_code'] : '')));
        $unit = (float) (isset($line['unit_cost']) ? $line['unit_cost'] : 0);
        $qty  = isset($line['quantity']) ? (float) $line['quantity'] : 1.0;

        if ($code === '' || !isset($rate_map[$code])) {
            return 0.0;
        }

        $rate = (float) $rate_map[$code];
        if ($rate <= 0) {
            return 0.0;
        }

        return round($unit * $qty * $rate, 2);
    }
}

if (!function_exists('costing_sum_by_category')) {
    /**
     * Sum cost rows to MYR, grouped by the five categories, plus a grand total.
     *
     * @param array $lines
     * @param array $rate_map
     * @return array flight/accommodation/other/tour_leader/miscellaneous/total (floats)
     */
    function costing_sum_by_category($lines, $rate_map)
    {
        $out = [];
        foreach (array_keys(costing_categories()) as $cat) {
            $out[$cat] = 0.0;
        }
        $out['total'] = 0.0;

        if (!is_array($lines)) {
            return $out;
        }

        foreach ($lines as $line) {
            $cat = strtolower(trim((string) (isset($line['category']) ? $line['category'] : '')));
            if (!array_key_exists($cat, $out) || $cat === 'total') {
                $cat = 'miscellaneous';
            }
            $myr = costing_line_to_myr($line, $rate_map);
            $out[$cat]   = round($out[$cat] + $myr, 2);
            $out['total'] = round($out['total'] + $myr, 2);
        }

        return $out;
    }
}

if (!function_exists('costing_apply_markup')) {
    /**
     * Gross-margin pricing. Margin is a percentage of the SELLING price
     * (20 => 20%), never negative. A margin of 100%+ is undefined (infinite
     * price) and falls back to cost.
     *
     * @param float $total_cost_myr
     * @param float $margin_percent
     * @return array cost, margin_percent, profit, selling (all MYR floats, 2dp)
     */
    function costing_apply_markup($total_cost_myr, $margin_percent)
    {
        $cost   = round((float) $total_cost_myr, 2);
        $margin = (float) $margin_percent;
        if ($margin < 0) {
            $margin = 0.0;
        }

        $selling = costing_cost_after_markup($cost, $margin);
        $profit  = round($selling - $cost, 2);

        return [
            'cost'           => $cost,
            'margin_percent' => $margin,
            'profit'         => $profit,
            'selling'        => $selling,
        ];
    }
}

if (!function_exists('costing_cost_after_markup')) {
    /**
     * Gross-margin pricing: the margin is a share of the SELLING price, so the
     * cost is grossed up.
     *
     *   cost after markup = cost / (1 - margin%/100)
     *
     * Margin is never negative (clamped to 0 => cost unchanged). A margin of
     * 100%+ is undefined (infinite price) and falls back to cost.
     *
     * @param float $cost           MYR cost
     * @param float $margin_percent  gross-margin percentage (0 <= m < 100)
     * @return float MYR selling price, 2dp
     */
    function costing_cost_after_markup($cost, $margin_percent)
    {
        $cost = round((float) $cost, 2);
        $margin = (float) $margin_percent;
        if ($margin < 0) {
            $margin = 0.0;
        }
        $divisor = 1 - ($margin / 100);
        if ($divisor <= 0) {
            return $cost;
        }
        return round($cost / $divisor, 2);
    }
}

if (!function_exists('costing_combination_summary')) {
    /**
     * Roll a scenario's combinations up for the customer quotation. Each
     * combination carries a name, its item names, and its MYR cost (the sum of its
     * item MYR totals). The customer price is driven per-pax:
     *
     *   cost after markup / pax = costing_cost_after_markup(cost/pax, margin)
     *   selling price / pax     = manual override, else the cost-after-markup
     *   combination total       = selling price / pax × pax
     *
     * Combinations are ALTERNATIVES the customer picks one of; total_selling is the
     * sum only for the legacy additive footer.
     *
     * @param array $combinations each: ['name','item_names','cost_myr',
     *                                    'selling_price_per_pax'(optional manual)]
     * @param float $margin_percent gross-margin percentage (never negative)
     * @param int   $total_pax      pax the price/pax is computed against (0 = none)
     * @return array [
     *   'combinations' => [ ['name','item_names','cost_myr','cost_per_pax',
     *                        'cost_after_markup_per_pax','selling_price_per_pax',
     *                        'profit_per_pax','selling','total_profit'], ... ],
     *   'total_cost'    => float,
     *   'total_selling' => float,
     * ]
     */
    function costing_combination_summary($combinations, $margin_percent, $total_pax = 0)
    {
        $margin = (float) $margin_percent;
        if ($margin < 0) {
            $margin = 0.0;
        }
        $pax = max(0, (int) $total_pax);

        $out = [];
        $total_cost = 0.0;
        $total_selling = 0.0;

        foreach ((array) $combinations as $combo) {
            $cost = round((float) (isset($combo['cost_myr']) ? $combo['cost_myr'] : 0), 2);

            $has_manual = isset($combo['selling_price_per_pax'])
                && $combo['selling_price_per_pax'] !== null
                && $combo['selling_price_per_pax'] !== '';
            $manual_pax = $has_manual ? max(0.0, round((float) $combo['selling_price_per_pax'], 2)) : null;

            if ($pax > 0) {
                $cost_per_pax = round($cost / $pax, 2);
            } else {
                $cost_per_pax = $cost;
            }
            $suggest_pax = costing_cost_after_markup($cost_per_pax, $margin);
            $price_pax   = $has_manual ? $manual_pax : $suggest_pax;
            $selling     = $pax > 0 ? round($price_pax * $pax, 2) : round($price_pax, 2);

            $names = [];
            foreach ((array) (isset($combo['item_names']) ? $combo['item_names'] : []) as $name) {
                $name = trim((string) $name);
                if ($name !== '') {
                    $names[] = $name;
                }
            }

            $out[] = [
                'name'                      => trim((string) (isset($combo['name']) ? $combo['name'] : '')),
                'item_names'                => $names,
                'cost_myr'                  => $cost,
                'cost_per_pax'              => $cost_per_pax,
                'cost_after_markup_per_pax' => $suggest_pax,
                'selling_price_per_pax'     => round($price_pax, 2),
                'profit_per_pax'            => round($price_pax - $cost_per_pax, 2),
                'selling'                   => $selling,
                'total_profit'              => round($selling - $cost, 2),
            ];

            $total_cost += $cost;
            $total_selling += $selling;
        }

        return [
            'combinations'  => $out,
            'total_cost'    => round($total_cost, 2),
            'total_selling' => round($total_selling, 2),
        ];
    }
}

if (!function_exists('costing_fold_general_combination')) {
    /**
     * Fold the "General Combination" cost into every other combination (feedback
     * 18 Sep 2026, item 6 / general request). The general combination holds the
     * cost items common to all packages (Tour Leader, Flight, …), entered once; its
     * cost is ADDED to each regular combination's cost_myr, and the general
     * combination itself is removed from the list (it is never a customer option).
     *
     * When more than one combination is flagged general their items all fold in. All
     * other keys on each regular combination (incl. its own item list) are preserved
     * untouched — only cost_myr is recomputed.
     *
     * Cost is rebuilt from the item rows (not summed cost_myr) so the bank (wire)
     * charge lands ONCE per distinct currency across the combination's own rows AND
     * the folded general rows together — a currency shared by both is charged once,
     * not twice (feedback 29 Sep 2026). Each item row carries 'base_total' (its pure
     * MYR conversion), 'currency_id' and 'bank_charges_myr'.
     *
     * @param array $combinations each: ['cost_myr', 'items'(rows), 'is_general'(opt), ...]
     * @return array regular combinations with cost_myr including the general cost + bank
     */
    function costing_fold_general_combination($combinations)
    {
        $general_items = array();
        $general_sum   = 0.0;
        foreach ((array) $combinations as $combo) {
            if (!empty($combo['is_general'])) {
                foreach ((array) (isset($combo['items']) ? $combo['items'] : array()) as $item) {
                    $general_items[] = $item;
                    $general_sum += (float) (isset($item['base_total']) ? $item['base_total'] : 0);
                }
            }
        }

        $out = array();
        foreach ((array) $combinations as $combo) {
            if (!empty($combo['is_general'])) {
                continue;
            }
            $own_items = (array) (isset($combo['items']) ? $combo['items'] : array());
            $own_sum = 0.0;
            foreach ($own_items as $item) {
                $own_sum += (float) (isset($item['base_total']) ? $item['base_total'] : 0);
            }
            $union = array_merge($own_items, $general_items);
            $combo['cost_myr'] = round($own_sum + $general_sum + costing_bank_total($union), 2);
            $out[] = $combo;
        }
        return $out;
    }
}

if (!function_exists('costing_build_snapshot_rows')) {
    /**
     * Build the distinct-currency snapshot skeleton from the cost rows, pre-filling
     * each rate from the daily feed. MYR is always forced to 1.0; a currency with
     * no feed value gets 0 (manual entry required). Remark starts blank.
     *
     * @param array $lines           cost rows, each with currency_code
     * @param array $currency_lookup currency_code (upper) => currency_id
     * @param array $prefill         currency_code (upper) => rate_to_myr from the feed
     * @return array list of ['currency_id','currency_code','rate_to_myr','remark']
     */
    function costing_build_snapshot_rows($lines, $currency_lookup, $prefill)
    {
        $lookup = [];
        foreach ((array) $currency_lookup as $code => $id) {
            $lookup[strtoupper(trim((string) $code))] = (int) $id;
        }
        $feed = [];
        foreach ((array) $prefill as $code => $rate) {
            $feed[strtoupper(trim((string) $code))] = (float) $rate;
        }

        $rows = [];
        $seen = [];
        foreach ((array) $lines as $line) {
            $code = strtoupper(trim((string) (isset($line['currency_code']) ? $line['currency_code'] : '')));
            if ($code === '' || isset($seen[$code])) {
                continue;
            }
            $seen[$code] = true;

            $rate = isset($feed[$code]) ? (float) $feed[$code] : 0.0;
            $rows[] = [
                'currency_id'   => isset($lookup[$code]) ? (int) $lookup[$code] : 0,
                'currency_code' => $code,
                'rate_to_myr'   => costing_normalize_rate($code, $rate),
                'remark'        => '',
            ];
        }

        return $rows;
    }
}

if (!function_exists('costing_multiplier_types')) {
    /**
     * How a cost line scales, code => human label. Stored on the item master and
     * copied onto each snapshot cost row. "Per Day" multiplies by the package
     * duration_days, "Per Pax" by the booking total_pax, "Per Day & Pax" by both
     * (duration_days × total_pax), "Fixed" by 1.
     *
     * @return array<string,string>
     */
    function costing_multiplier_types()
    {
        return [
            'per_day'     => 'Per Day',
            'per_pax'     => 'Per Pax',
            'per_day_pax' => 'Per Day & Pax',
            'fixed'       => 'Fixed',
        ];
    }
}

if (!function_exists('costing_normalize_multiplier_type')) {
    /**
     * Clamp a multiplier type to a known code, defaulting to 'fixed'.
     *
     * @param mixed $type
     * @return string one of per_day|per_pax|fixed
     */
    function costing_normalize_multiplier_type($type)
    {
        $type = strtolower(trim((string) $type));
        return array_key_exists($type, costing_multiplier_types()) ? $type : 'fixed';
    }
}

if (!function_exists('costing_multiplier_count')) {
    /**
     * The count a line is multiplied by, from its multiplier type. Per-day uses
     * the package duration, per-pax uses the booking pax, per-day-pax uses both
     * (duration × pax), fixed is always 1. Never below 0.
     *
     * @param string $multiplier_type
     * @param int    $duration_days
     * @param int    $total_pax
     * @return int
     */
    function costing_multiplier_count($multiplier_type, $duration_days, $total_pax)
    {
        switch (costing_normalize_multiplier_type($multiplier_type)) {
            case 'per_day':
                return max(0, (int) $duration_days);
            case 'per_pax':
                return max(0, (int) $total_pax);
            case 'per_day_pax':
                return max(0, (int) $duration_days) * max(0, (int) $total_pax);
            case 'fixed':
            default:
                return 1;
        }
    }
}

if (!function_exists('costing_row_myr')) {
    /**
     * Convert one cost line the way the cost template shows it: the foreign Cost is
     * converted to MYR per unit ("MYR (convert)"), multiplied by the count (No of
     * Day / No of pax). The line total is a PURE conversion — the bank (wire) charge
     * is NOT a per-line fee. It is levied once per distinct currency across the whole
     * package (one wire transfer per currency), so it is added at the aggregation
     * level via costing_bank_total(), never here (feedback 29 Sep 2026, supersedes
     * the earlier per-line rule).
     *
     *   myr_per_unit = round(cost_foreign * rate, 2)
     *   total_myr    = round(myr_per_unit * count, 2)
     *
     * @param float $cost_foreign unit cost in the row's currency
     * @param float $rate         rate_to_myr for that currency (>=0)
     * @param float $count        No of Day / No of pax / 1
     * @return array ['myr_per_unit','total_myr'] (floats, 2dp)
     */
    function costing_row_myr($cost_foreign, $rate, $count)
    {
        $rate = (float) $rate;
        if ($rate < 0) {
            $rate = 0.0;
        }
        $count = max(0.0, (float) $count);

        $myr_per_unit = round((float) $cost_foreign * $rate, 2);
        $total_myr    = round($myr_per_unit * $count, 2);

        return [
            'myr_per_unit' => $myr_per_unit,
            'total_myr'    => $total_myr,
        ];
    }
}

if (!function_exists('costing_supplier_payment_count')) {
    /**
     * How many wire transfers a supplier line is settled with: a DEPOSIT and then a
     * BALANCE payment = 2, each incurring the bank (wire) charge (feedback 29 Sep
     * 2026, "should always twice"). The bank charge is therefore levied twice per
     * distinct currency, not once.
     *
     * @return int
     */
    function costing_supplier_payment_count()
    {
        return 2;
    }
}

if (!function_exists('costing_bank_total')) {
    /**
     * Total bank (wire) charge for a set of cost lines: one charge per DISTINCT
     * currency that actually has a positive MYR amount, TIMES the number of supplier
     * payments (deposit + balance = 2). Every foreign supplier of a given currency is
     * paid with a deposit then a balance wire transfer, each incurring the fee, and
     * the fee is per currency for the whole package no matter how many lines use it
     * (feedback 29 Sep 2026: "for entire, if multiple then charges multiple" +
     * "should always twice").
     *
     *   total = Σ_currency (amount_owed > 0 ? bank_charges_myr × payment_count : 0)
     *
     * A currency whose lines sum to 0 MYR (nothing owed) incurs no charge. MYR /
     * local lines carry a 0 bank charge and never add anything.
     *
     * @param array $lines each: ['currency_id', 'bank_charges_myr',
     *                            'amount_myr' (or 'base_total') = the line's MYR total]
     * @return float total bank charge in MYR (2dp)
     */
    function costing_bank_total($lines)
    {
        $by_currency = array();
        foreach ((array) $lines as $line) {
            $cid  = isset($line['currency_id']) ? (int) $line['currency_id'] : 0;
            $bank = isset($line['bank_charges_myr']) ? max(0.0, (float) $line['bank_charges_myr']) : 0.0;
            if (array_key_exists('amount_myr', $line)) {
                $amount = (float) $line['amount_myr'];
            } else {
                $amount = isset($line['base_total']) ? (float) $line['base_total'] : 0.0;
            }

            if (!isset($by_currency[$cid])) {
                $by_currency[$cid] = array('bank' => 0.0, 'amount' => 0.0);
            }
            // The bank charge is a per-currency constant; guard against a 0 on an
            // empty row by keeping the largest seen for the currency.
            if ($bank > $by_currency[$cid]['bank']) {
                $by_currency[$cid]['bank'] = $bank;
            }
            $by_currency[$cid]['amount'] += $amount;
        }

        $payments = costing_supplier_payment_count();
        $total = 0.0;
        foreach ($by_currency as $entry) {
            if ($entry['amount'] > 0) {
                $total += $entry['bank'] * $payments;
            }
        }

        return round($total, 2);
    }
}

if (!function_exists('costing_row_totals')) {
    /**
     * Resolve a cost row's MYR figures, preferring a FROZEN per-unit value saved
     * with the row over recomputing from the (master/live) rate.
     *
     * The "MYR (convert)" column is the pure per-unit conversion; once a row is
     * saved we persist that per-unit figure in its own column so it never drifts
     * when the exchange-rate master changes later. When a frozen value is present
     * ($stored_myr_per_unit not null/''), it wins and the line total is that frozen
     * per-unit × count. The bank (wire) charge is NOT included here — it is levied
     * once per currency at the aggregation level via costing_bank_total() (feedback
     * 29 Sep 2026). When absent (legacy pre-freeze rows), fall back to converting
     * from the rate like costing_row_myr().
     *
     * @param float|null $stored_myr_per_unit frozen per-unit MYR, or null/'' to compute
     * @param float $cost_foreign
     * @param float $rate
     * @param float $count
     * @return array ['myr_per_unit','total_myr'] (floats, 2dp)
     */
    function costing_row_totals($stored_myr_per_unit, $cost_foreign, $rate, $count)
    {
        if ($stored_myr_per_unit !== null && $stored_myr_per_unit !== '') {
            $per_unit  = round(max(0.0, (float) $stored_myr_per_unit), 2);
            $converted = round($per_unit * max(0.0, (float) $count), 2);
            return [
                'myr_per_unit' => $per_unit,
                'total_myr'    => $converted,
            ];
        }

        return costing_row_myr($cost_foreign, $rate, $count);
    }
}

if (!function_exists('costing_currency_breakdown')) {
    /**
     * Roll the cost rows up per currency so the cost template can show, for each
     * currency actually used, its rate to MYR, the per-unit bank charge, and the
     * TOTAL owed in that currency (plus the MYR equivalent). Only rows flagged
     * as included count. Currencies are returned ordered by code.
     *
     * Row shape  : ['currency_id','unit_price','count','include'].
     * Rate map   : currency_id => ['code','rate_to_myr','bank_charges_myr'].
     * Each entry : ['currency_id','code','rate_to_myr','bank_charges_myr',
     *               'total_foreign','total_myr'] (2dp totals).
     *
     * @param array $rows
     * @param array $rate_map
     * @return array list of per-currency entries
     */
    function costing_currency_breakdown($rows, $rate_map)
    {
        $acc = array();
        foreach ((array) $rows as $row) {
            if (array_key_exists('include', $row) && !$row['include']) {
                continue;
            }
            $cid  = isset($row['currency_id']) ? (int) $row['currency_id'] : 0;
            $info = isset($rate_map[$cid]) ? $rate_map[$cid] : array();
            $rate = isset($info['rate_to_myr']) ? (float) $info['rate_to_myr'] : 0.0;
            if ($rate < 0) {
                $rate = 0.0;
            }
            $bank  = isset($info['bank_charges_myr']) ? max(0.0, (float) $info['bank_charges_myr']) : 0.0;
            $unit  = (float) (isset($row['unit_price']) ? $row['unit_price'] : 0);
            $count = max(0.0, (float) (isset($row['count']) ? $row['count'] : 0));

            $line = costing_row_myr($unit, $rate, $count);

            if (!isset($acc[$cid])) {
                $acc[$cid] = array(
                    'currency_id'      => $cid,
                    'code'             => isset($info['code']) ? $info['code'] : '',
                    'rate_to_myr'      => $rate,
                    'bank_charges_myr' => $bank,
                    'total_foreign'    => 0.0,
                    'total_myr'        => 0.0,
                );
            }
            $acc[$cid]['total_foreign'] += $unit * $count;
            $acc[$cid]['total_myr']     += $line['total_myr'];
        }

        $payments = costing_supplier_payment_count();
        foreach ($acc as &$entry) {
            $entry['total_foreign'] = round($entry['total_foreign'], 2);
            // Bank (wire) charge is levied per currency (deposit + balance = ×2), added
            // to the currency total — not per line — only when something is owed in it.
            $bank = $entry['total_myr'] > 0 ? ($entry['bank_charges_myr'] * $payments) : 0.0;
            $entry['total_myr']     = round($entry['total_myr'] + $bank, 2);
        }
        unset($entry);

        usort($acc, function ($a, $b) {
            return strcmp($a['code'], $b['code']);
        });

        return array_values($acc);
    }
}

if (!function_exists('costing_normalize_rate')) {
    /**
     * Normalise a user-entered / prefilled rate: MYR is always 1.0, non-positive
     * becomes 0.0, everything else is clamped to 8 decimal places.
     *
     * @param string $currency_code
     * @param mixed  $rate
     * @return float
     */
    function costing_normalize_rate($currency_code, $rate)
    {
        if (strtoupper(trim((string) $currency_code)) === 'MYR') {
            return 1.0;
        }
        $rate = (float) $rate;
        if ($rate <= 0) {
            return 0.0;
        }
        return round($rate, 8);
    }
}
