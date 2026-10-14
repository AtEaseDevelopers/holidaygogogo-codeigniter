<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * TEMPORARY dev seeder for the Costing -> Quotation flow. CLI only.
 *   php index.php Costing_Seed             -> full package + snapshot + itinerary
 *   php index.php Costing_Seed/items       -> item master rows
 *   php index.php Costing_Seed/cleanup     -> remove everything this seeder made
 * Safe to delete after testing.
 */
class Costing_Seed extends CI_Controller
{
    public function __construct()
    {
        parent::__construct();
        if (!$this->input->is_cli_request()) {
            show_error('CLI only.', 403);
            return;
        }
        $this->load->model('Costing_Model');
        $this->load->model('Costing_Item_Model');
    }

    private function currency_id($code, $name)
    {
        $code = strtoupper($code);
        $row = $this->db->select('id')->where('code', $code)->get('costing_currencies')->row_array();
        if ($row) {
            return (int) $row['id'];
        }
        $this->db->insert('costing_currencies', array('code' => $code, 'name' => $name));
        return (int) $this->db->insert_id();
    }

    // Upsert a Costing Currency rate (costing_exchange_rates): 1 <from> = <rate> MYR.
    private function set_rate($from_id, $to_myr_id, $rate)
    {
        $this->db->where('from_currency_id', $from_id)->where('to_currency_id', $to_myr_id)->delete('costing_exchange_rates');
        $this->db->insert('costing_exchange_rates', array(
            'from_currency_id' => $from_id,
            'to_currency_id'   => $to_myr_id,
            'unit_amount'      => 1,
            'rate'             => $rate,
            'bank_charges_myr' => 0,
            'valid_from'       => date('Y-m-d H:i:s'),
        ));
    }

    public function items()
    {
        $myr = $this->currency_id('MYR', 'Malaysian Ringgit');
        $usd = $this->currency_id('USD', 'US Dollar');
        $sgd = $this->currency_id('SGD', 'Singapore Dollar');

        $items = array(
            array('Return Flight (Economy)', 'flight',        $usd),
            array('Domestic Transfer Flight', 'flight',        $myr),
            array('4-Star Hotel (per night)', 'accommodation', $sgd),
            array('Beach Resort (per night)',  'accommodation', $usd),
            array('Airport Transfer (van)',    'other',         $myr),
            array('Travel Insurance',          'other',         $myr),
            array('Tour Leader (per day)',     'tour_leader',   $myr),
            array('Local Guide (per day)',     'tour_leader',   $usd),
            array('SIM Card',                  'miscellaneous', $myr),
            array('Tips & Gratuities',         'miscellaneous', $myr),
        );
        $created = 0;
        foreach ($items as $it) {
            if ($this->Costing_Item_Model->Save_Item(array('id' => 0, 'name' => $it[0], 'category' => $it[1], 'default_currency_id' => $it[2]))) {
                $created++;
            }
        }
        echo "Seeded {$created} costing items.\n";
        echo "Listing : " . base_url('Costing_Item') . "\n";
    }

    public function index()
    {
        $myr = $this->currency_id('MYR', 'Malaysian Ringgit');
        $usd = $this->currency_id('USD', 'US Dollar');
        $sgd = $this->currency_id('SGD', 'Singapore Dollar');

        // Costing Currency rates (the snapshot now pre-fills from THESE).
        $this->set_rate($usd, $myr, 4.50); // 1 USD = 4.50 MYR
        $this->set_rate($sgd, $myr, 3.50); // 1 SGD = 3.50 MYR

        $this->items();

        $package_id = $this->Costing_Model->Save_Package(array(
            'id' => 0, 'name' => 'Bali 4D3N Getaway (DUMMY)', 'duration_days' => 4,
            'duration_nights' => 3, 'description' => 'Seeded dummy package for testing.', 'status' => 'active',
        ));

        $rows = array(
            array('include' => 1, 'name' => 'Return Flights', 'category' => 'Flight',        'pax_type' => '', 'quantity' => 2, 'unit_count' => 1, 'unit_price' => 300, 'currency_id' => $usd, 'remark' => ''),
            array('include' => 1, 'name' => 'Hotel 3 Nights', 'category' => 'Accommodation', 'pax_type' => '', 'quantity' => 3, 'unit_count' => 1, 'unit_price' => 150, 'currency_id' => $sgd, 'remark' => ''),
            array('include' => 1, 'name' => 'Tour Leader',    'category' => 'Tour Leader',   'pax_type' => '', 'quantity' => 1, 'unit_count' => 1, 'unit_price' => 400, 'currency_id' => $myr, 'remark' => ''),
            array('include' => 1, 'name' => 'Sundry',         'category' => 'Miscellaneous', 'pax_type' => '', 'quantity' => 1, 'unit_count' => 1, 'unit_price' => 120, 'currency_id' => $myr, 'remark' => ''),
        );

        $booking_id = $this->Costing_Model->Generate_Booking_Snapshot($package_id, array(
            'booking_id' => 0, 'travel_date' => date('Y-m-d', strtotime('+30 days')),
            'adult_count' => 2, 'child_count' => 0, 'status' => 'active', 'rows' => $rows,
            'margin_percentage' => 20, 'commissionable_per_pax' => 0, 'ad_hoc_per_pax' => 0,
        ));

        if ($booking_id <= 0) { echo "FAILED to generate snapshot.\n"; return; }

        $this->Costing_Model->Save_Itinerary_Days($package_id, array(
            array('day_number' => 1, 'title' => 'Arrival in Bali', 'description' => 'Airport pickup, hotel check-in, welcome dinner.'),
            array('day_number' => 2, 'title' => 'Ubud & Rice Terraces', 'description' => 'Full-day cultural tour with lunch.'),
            array('day_number' => 3, 'title' => 'Beach & Leisure', 'description' => 'Free morning, afternoon water sports.'),
            array('day_number' => 4, 'title' => 'Departure', 'description' => 'Breakfast and transfer to airport.'),
        ));

        $fin = $this->db->where('booking_id', $booking_id)->get('costing_booking_financials')->row_array();
        $snap = $this->db->where('costing_booking_id', $booking_id)->get('costing_snapshot_rates')->result_array();

        echo "=== Costing dummy seeded ===\n";
        echo "Package {$package_id} / Snapshot {$booking_id}\n";
        echo "Costing Currency rates set: 1 USD = 4.50 MYR, 1 SGD = 3.50 MYR\n";
        echo "Snapshot rates prefilled FROM Costing Currency:\n";
        foreach ($snap as $s) { echo "  - {$s['currency_code']} => {$s['rate_to_myr']} MYR\n"; }
        echo "Total cost (MYR)   : " . ($fin ? $fin['total_cost'] : '?') . "\n";
        echo "Total selling (MYR): " . ($fin ? $fin['total_revenue'] : '?') . "\n";
        echo "Total profit (MYR) : " . ($fin ? $fin['total_profit'] : '?') . "\n";
        echo "Workspace : " . base_url('Costing/Package/' . $package_id . '?tab=bookings') . "\n";
        echo "Quotation : " . base_url('Costing/Quotation/' . $this->Costing_Model->Ensure_Quotation_Token($booking_id)) . "\n";
    }

    /**
     * Dummy for the per-combination "No. of Tour Leaders". The count lives in each
     * combination's Tour Leader SECTION HEADER and scales only that section. Two
     * regular combinations each carry their own Tour Leader line (MYR 500, fixed):
     * Standard (1 leader) and Premium (2 leaders, seeded) — so each summary scales
     * independently. Open the Cost step and change each card's "No. of Tour Leaders"
     * (next to the Tour Leader section label) to watch only that combination's
     * summary (and the quotation PDF) scale while the item rows stay put.
     *   php index.php Costing_Seed/tourleader
     */
    public function tourleader()
    {
        $myr = $this->currency_id('MYR', 'Malaysian Ringgit');
        $usd = $this->currency_id('USD', 'US Dollar');
        $sgd = $this->currency_id('SGD', 'Singapore Dollar');
        $this->set_rate($usd, $myr, 4.50); // 1 USD = 4.50 MYR
        $this->set_rate($sgd, $myr, 3.50); // 1 SGD = 3.50 MYR

        $package_id = $this->Costing_Model->Save_Package(array(
            'id' => 0, 'name' => 'Tour Leader Multiplier (DUMMY)',
            'customer_name' => 'Dummy Customer', 'customer_contact' => '', 'customer_email' => '',
            'duration_days' => 4, 'duration_nights' => 3,
            'description' => 'Seeded dummy for the per-combination No. of Tour Leaders feature.', 'status' => 'active',
        ));

        $flight = function () use ($usd) { return array('include' => 1, 'name' => 'Return Flights', 'category' => 'flight', 'multiplier_type' => 'per_pax', 'quantity' => 2, 'unit_count' => 1, 'unit_price' => 300, 'currency_id' => $usd, 'remark' => ''); };
        $hotel  = function ($nights) use ($sgd) { return array('include' => 1, 'name' => 'Hotel ' . $nights . ' Nights', 'category' => 'accommodation', 'multiplier_type' => 'fixed', 'quantity' => $nights, 'unit_count' => 1, 'unit_price' => 150, 'currency_id' => $sgd, 'remark' => ''); };
        $leader = function () use ($myr) { return array('include' => 1, 'name' => 'Tour Leader', 'category' => 'tour_leader', 'multiplier_type' => 'fixed', 'quantity' => 1, 'unit_count' => 1, 'unit_price' => 500, 'currency_id' => $myr, 'remark' => 'Scaled by this combo\'s No. of Tour Leaders'); };

        $combinations = array(
            // Each combination owns its Tour Leader section, so the count input shows
            // next to its label and scales that combo independently. Headcount is a
            // per-category map keyed by category code (only flagged categories apply).
            array('name' => 'Standard', 'headcount' => array('tour_leader' => 1), 'rows' => array($flight(), $hotel(3), $leader())),
            // Premium seeds 2 leaders so the effect is visible on first open.
            array('name' => 'Premium', 'headcount' => array('tour_leader' => 2), 'rows' => array($flight(), $hotel(4), $leader())),
        );

        $booking_id = $this->Costing_Model->Generate_Booking_Snapshot($package_id, array(
            'booking_id' => 0, 'travel_date' => date('Y-m-d', strtotime('+30 days')),
            'adult_count' => 2, 'child_count' => 0, 'status' => 'active',
            'combinations' => $combinations,
            'margin_percentage' => 20, 'commissionable_per_pax' => 0, 'ad_hoc_per_pax' => 0,
        ));

        if ($booking_id <= 0) { echo "FAILED to generate snapshot.\n"; return; }

        echo "=== Tour Leader (per-combination) dummy seeded ===\n";
        echo "Package {$package_id} / Snapshot {$booking_id}\n";
        echo "Each combo owns a Tour Leader = MYR 500 (fixed); the count sits next to the section label.\n";
        echo "Standard (No. of Tour Leaders = 1): Flights 2700 + Hotel 1575 + TL 500 = RM 4,775.\n";
        echo "Premium  (No. of Tour Leaders = 2): Flights 2700 + Hotel 2100 + TL 500x2 = RM 5,800.\n";
        echo "Manual test:\n";
        echo "  1. Open the Cost step; Standard Total Cost = 4,775, Premium = 5,800.\n";
        echo "  2. Change each card's 'No. of Tour Leaders' (next to the Tour Leader\n";
        echo "     section label) -> only that combo's summary moves by MYR 500 per\n";
        echo "     extra leader; the Tour Leader ROW total stays MYR 500 (summary/PDF only).\n";
        echo "  3. Save & reopen -> each value persists; the Quotation PDF reflects it.\n";
        echo "Cost step : " . base_url('Costing/Package/' . $package_id . '?step=cost') . "\n";
        echo "Quotation : " . base_url('Costing/Quotation/' . $this->Costing_Model->Ensure_Quotation_Token($booking_id)) . "\n";
        echo "Cleanup   : php index.php Costing_Seed/cleanup\n";
    }

    /**
     * Dummy for the category "No multiply" (Total = Cost) flag. Creates a no-multiply
     * category "Handling Fee (DUMMY)" and a package with one combination holding a
     * normal per-pax Flight line + a flat Handling Fee line. The Handling Fee row is
     * seeded with a deliberately silly count (5) to prove it is IGNORED: its Total
     * stays = Cost (RM 300, count forced to 1 on save) and in the quotation it is
     * carried flat (marked up) instead of divided per pax.
     *   php index.php Costing_Seed/nomultiply
     */
    public function nomultiply()
    {
        $this->load->model('Costing_Category_Model');
        $myr = $this->currency_id('MYR', 'Malaysian Ringgit');

        // Create the no-multiply category (idempotent) and resolve its code slug.
        $existing = $this->db->select('id, code')->where('name', 'Handling Fee (DUMMY)')->get('costing_categories')->row_array();
        if ($existing) {
            $this->Costing_Category_Model->Save_Category(array('id' => (int) $existing['id'], 'name' => 'Handling Fee (DUMMY)', 'no_multiplier' => 1));
            $code = (string) $existing['code'];
        } else {
            $this->Costing_Category_Model->Save_Category(array('id' => 0, 'name' => 'Handling Fee (DUMMY)', 'no_multiplier' => 1));
            $code = (string) $this->db->select('code')->where('name', 'Handling Fee (DUMMY)')->order_by('id', 'DESC')->get('costing_categories')->row('code');
        }

        $package_id = $this->Costing_Model->Save_Package(array(
            'id' => 0, 'name' => 'No Multiply Flat Charge (DUMMY)',
            'customer_name' => 'Dummy Customer', 'customer_contact' => '', 'customer_email' => '',
            'duration_days' => 4, 'duration_nights' => 3,
            'description' => 'Seeded dummy for the category No-Multiply (Total = Cost) flag.', 'status' => 'active',
        ));

        $combinations = array(
            array('name' => 'Standard', 'rows' => array(
                // Normal per-pax line: scales with pax (2 pax -> RM 1,000).
                array('include' => 1, 'name' => 'Return Flights', 'category' => 'flight', 'multiplier_type' => 'per_pax', 'quantity' => 2, 'unit_count' => 1, 'unit_price' => 500, 'currency_id' => $myr, 'remark' => ''),
                // No-multiply line: count 5 is deliberate noise -> Total must stay = Cost
                // (RM 300). Server forces quantity to 1; carried flat in the quotation.
                array('include' => 1, 'name' => 'Handling Fee', 'category' => $code, 'multiplier_type' => 'per_pax', 'quantity' => 5, 'unit_count' => 1, 'unit_price' => 300, 'currency_id' => $myr, 'remark' => 'No multiply: Total = Cost, flat in quote'),
            )),
        );

        $booking_id = $this->Costing_Model->Generate_Booking_Snapshot($package_id, array(
            'booking_id' => 0, 'travel_date' => date('Y-m-d', strtotime('+30 days')),
            'adult_count' => 2, 'child_count' => 0, 'status' => 'active',
            'combinations' => $combinations,
            'margin_percentage' => 20, 'commissionable_per_pax' => 0, 'ad_hoc_per_pax' => 0,
        ));

        if ($booking_id <= 0) { echo "FAILED to generate snapshot.\n"; return; }

        // Read back the stored Handling Fee row to prove quantity was forced to 1.
        $hf = $this->db->select('quantity, unit_price')->where('booking_id', $booking_id)->where('category', $code)->get('costing_booking_items')->row_array();

        echo "=== No-Multiply (Total = Cost) dummy seeded ===\n";
        echo "Category  : Handling Fee (DUMMY) [code: {$code}] flagged no_multiplier=1\n";
        echo "Package {$package_id} / Snapshot {$booking_id} (2 pax, 20% margin)\n";
        echo "Handling Fee row stored quantity = " . ($hf ? $hf['quantity'] : '?') . " (seeded 5 -> forced to 1), unit_price = " . ($hf ? $hf['unit_price'] : '?') . "\n";
        echo "Expected on the Cost step (Standard):\n";
        echo "  - Return Flights (per pax) : RM 500 x 2 = RM 1,000\n";
        echo "  - Handling Fee (no multiply): Total = Cost = RM 300 (count has no effect)\n";
        echo "  - Total Cost = RM 1,300 ; Cost/Pax = RM 500 (flat excluded) ; Cost after Markup = RM 625\n";
        echo "  - Flat Charges box = RM 375 (300 marked up 20%) ; Total Revenue = RM 1,625 ; Total Profit = RM 325\n";
        echo "Manual test:\n";
        echo "  1. Open the Cost step; the Handling Fee row Total = RM 300 regardless of its count box.\n";
        echo "  2. Type any number in the Handling Fee 'No of Day / Pax' box -> its Total stays RM 300.\n";
        echo "  3. See the red 'Flat Charges' summary box = RM 375, and Cost/Pax = RM 500 (flat excluded).\n";
        echo "  4. Save & reopen -> count shows 1; numbers persist.\n";
        echo "Cost step : " . base_url('Costing/Package/' . $package_id . '?step=cost') . "\n";
        echo "Category  : " . base_url('Costing_Category') . "\n";
        echo "Cleanup   : php index.php Costing_Seed/cleanup\n";
    }

    public function cleanup()
    {
        $pkg = $this->db->select('id')->where_in('name', array('Bali 4D3N Getaway (DUMMY)', 'Single-Snapshot Test', 'Tour Leader Multiplier (DUMMY)', 'No Multiply Flat Charge (DUMMY)'))->get('costing_packages')->result_array();
        $pkg_removed = 0;
        foreach ($pkg as $p) { if ($this->Costing_Model->Delete_Package((int) $p['id'])) { $pkg_removed++; } }

        $item_names = array('Return Flight (Economy)', 'Domestic Transfer Flight', '4-Star Hotel (per night)', 'Beach Resort (per night)', 'Airport Transfer (van)', 'Travel Insurance', 'Tour Leader (per day)', 'Local Guide (per day)', 'SIM Card', 'Tips & Gratuities');
        $this->db->where_in('name', $item_names)->delete('costing_items');
        $items_removed = $this->db->affected_rows();

        // Remove the seeded USD/SGD -> MYR Costing Currency rates.
        $myr = $this->currency_id('MYR', 'Malaysian Ringgit');
        $usd = $this->currency_id('USD', 'US Dollar');
        $sgd = $this->currency_id('SGD', 'Singapore Dollar');
        $this->db->where('to_currency_id', $myr)->where_in('from_currency_id', array($usd, $sgd))->delete('costing_exchange_rates');
        $rates_removed = $this->db->affected_rows();

        // Soft-delete the no-multiply dummy category (Status='N').
        $this->db->where('name', 'Handling Fee (DUMMY)')->update('costing_categories', array('Status' => 'N'));
        $cats_removed = $this->db->affected_rows();

        echo "Removed packages: {$pkg_removed}\n";
        echo "Removed items   : {$items_removed}\n";
        echo "Removed rates   : {$rates_removed}\n";
        echo "Removed cats    : {$cats_removed}\n";
        echo "Currencies kept (shared master data).\n";
    }
}
