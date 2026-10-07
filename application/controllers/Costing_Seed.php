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
        echo "Quotation : " . base_url('Costing/Quotation/' . $package_id) . "\n";
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
            // next to its label and scales that combo independently.
            array('name' => 'Standard', 'tour_leader_count' => 1, 'rows' => array($flight(), $hotel(3), $leader())),
            // Premium seeds 2 leaders so the effect is visible on first open.
            array('name' => 'Premium', 'tour_leader_count' => 2, 'rows' => array($flight(), $hotel(4), $leader())),
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
        echo "Quotation : " . base_url('Costing/Quotation/' . $package_id) . "\n";
        echo "Cleanup   : php index.php Costing_Seed/cleanup\n";
    }

    public function cleanup()
    {
        $pkg = $this->db->select('id')->where_in('name', array('Bali 4D3N Getaway (DUMMY)', 'Single-Snapshot Test', 'Tour Leader Multiplier (DUMMY)'))->get('costing_packages')->result_array();
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

        echo "Removed packages: {$pkg_removed}\n";
        echo "Removed items   : {$items_removed}\n";
        echo "Removed rates   : {$rates_removed}\n";
        echo "Currencies kept (shared master data).\n";
    }
}
