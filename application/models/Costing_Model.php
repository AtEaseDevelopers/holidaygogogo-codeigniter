<?php

class Costing_Model extends CI_Model
{
    /** Cached "no multiply" category codes (Total = Cost); null until first read. */
    private $no_multiply_codes = null;

    /**
     * Active "no multiply" category codes (Total = Cost, flat in quotation), read
     * once per request from the category master and cached. Rows in these categories
     * are not multiplied by the day/pax count and are carried flat on the quotation.
     *
     * @return string[] lowercased category codes
     */
    private function No_Multiply_Codes()
    {
        if ($this->no_multiply_codes === null) {
            $this->load->model('Costing_Category_Model');
            $this->no_multiply_codes = $this->Costing_Category_Model->Read_No_Multiply_Codes();
        }
        return $this->no_multiply_codes;
    }

    /**
     * Build the shared WHERE clause for the package listing/export from the
     * common filter set (search / tour code / status / sales person / customer
     * name-contact-email). Returns the full "WHERE ..." string (or '' when no
     * filter is active) so both the dashboard and the Excel export filter the
     * exact same way. Table alias is cp.
     */
    private function Build_Package_Filter_Where($filters = array())
    {
        $where = array();

        // Soft-deleted packages never surface anywhere this builder feeds — the
        // dashboard listing and the Excel export both call it. Unconditional, so
        // it holds regardless of the active/inactive status dropdown filter.
        $where[] = "cp.status != 'deleted'";

        // Free-text search box: broad, token-based match (whitespace splits
        // into tokens; each token must hit at least one column) across every
        // meaningful column — package name/tour code/description PLUS the
        // customer name/contact/email and the sales person's name — so a
        // customer like "Mr. Anthony" is findable from the single Search box.
        // sales_admin.Name is joined by both the dashboard and export queries.
        $this->load->helper('costing_package_search');
        $search = isset($filters['search']) ? trim((string) $filters['search']) : '';
        if ($search !== '') {
            $search_clause = costing_package_search_clause(
                $search,
                array(
                    'cp.name',
                    'cp.tour_code',
                    'cp.related_tour_code',
                    'cp.description',
                    'cp.customer_name',
                    'cp.customer_contact',
                    'cp.customer_email',
                    'sales_admin.Name',
                ),
                array($this->db, 'escape_like_str')
            );
            if ($search_clause !== '') {
                $where[] = '(' . $search_clause . ')';
            }
        }

        // The Tour Code filter cross-matches BOTH the auto tour_code AND the
        // manually keyed related_tour_code, so typing one code surfaces that
        // package plus every package that lists it as a related tour code — the
        // whole related group in one result set.
        $tour_code = isset($filters['tour_code']) ? trim((string) $filters['tour_code']) : '';
        if ($tour_code !== '') {
            $like = $this->db->escape_like_str($tour_code);
            $where[] = "(cp.tour_code LIKE '%" . $like . "%' OR cp.related_tour_code LIKE '%" . $like . "%')";
        }

        $status = isset($filters['status']) ? trim((string) $filters['status']) : '';
        if ($status !== '') {
            $where[] = "cp.status = " . $this->db->escape($status);
        }

        $customer_name = isset($filters['customer_name']) ? trim((string) $filters['customer_name']) : '';
        if ($customer_name !== '') {
            $where[] = "cp.customer_name LIKE '%" . $this->db->escape_like_str($customer_name) . "%'";
        }

        $customer_contact = isset($filters['customer_contact']) ? trim((string) $filters['customer_contact']) : '';
        if ($customer_contact !== '') {
            $where[] = "cp.customer_contact LIKE '%" . $this->db->escape_like_str($customer_contact) . "%'";
        }

        $customer_email = isset($filters['customer_email']) ? trim((string) $filters['customer_email']) : '';
        if ($customer_email !== '') {
            $where[] = "cp.customer_email LIKE '%" . $this->db->escape_like_str($customer_email) . "%'";
        }

        // #8 Filter by the assigned sales person (admin user).
        $sales_admin_id = isset($filters['sales_admin_id']) ? (int) $filters['sales_admin_id'] : 0;
        if ($sales_admin_id > 0) {
            $where[] = "cp.sales_admin_id = " . $sales_admin_id;
        }

        // Filter by the package's assigned OP person.
        $op_admin_id = isset($filters['op_admin_id']) ? (int) $filters['op_admin_id'] : 0;
        if ($op_admin_id > 0) {
            $where[] = "cp.op_admin_id = " . $op_admin_id;
        }

        // Filter by the package creation date (cp.created_at, auto-stamped on
        // insert for both manual wizard creation and Excel import). Dates are an
        // inclusive day range: >= from 00:00:00 and <= to 23:59:59.
        $created_from = isset($filters['created_from']) ? trim((string) $filters['created_from']) : '';
        if ($created_from !== '') {
            $where[] = "cp.created_at >= '" . $this->db->escape_str($created_from) . " 00:00:00'";
        }
        $created_to = isset($filters['created_to']) ? trim((string) $filters['created_to']) : '';
        if ($created_to !== '') {
            $where[] = "cp.created_at <= '" . $this->db->escape_str($created_to) . " 23:59:59'";
        }

        // Filter by the quotation validity date (cp.validity_date, a DATE column).
        // Inclusive day range; NULL validity dates never match a range filter.
        $validity_from = isset($filters['validity_from']) ? trim((string) $filters['validity_from']) : '';
        if ($validity_from !== '') {
            $where[] = "cp.validity_date >= '" . $this->db->escape_str($validity_from) . "'";
        }
        $validity_to = isset($filters['validity_to']) ? trim((string) $filters['validity_to']) : '';
        if ($validity_to !== '') {
            $where[] = "cp.validity_date <= '" . $this->db->escape_str($validity_to) . "'";
        }

        return !empty($where) ? ('WHERE ' . implode(' AND ', $where)) : '';
    }

    public function Read_Packages_Dashboard($filters = array())
    {
        $where_sql = $this->Build_Package_Filter_Where($filters);

        $packages = $this->db->query("
            SELECT
                cp.id,
                cp.name,
                cp.customer_name,
                cp.customer_contact,
                cp.customer_email,
                cp.sales_admin_id,
                sales_admin.Name AS sales_admin_name,
                cp.op_admin_id,
                op_admin.Name AS op_admin_name,
                cp.tour_code,
                cp.related_tour_code,
                cp.revision_root_id,
                cp.revision_number,
                cp.duration_days,
                cp.duration_nights,
                cp.description,
                cp.status,
                cp.validity_date,
                cp.created_at,
                cp.updated_at,
                (
                    SELECT COUNT(*)
                    FROM costing_package_items cpi
                    WHERE cpi.package_id = cp.id
                ) AS template_item_count,
                (
                    SELECT COUNT(*)
                    FROM costing_bookings cb_count
                    WHERE cb_count.package_id = cp.id
                ) AS booking_count,
                cb.id AS latest_booking_id,
                cb.travel_date AS latest_travel_date,
                cb.total_pax AS latest_total_pax,
                cb.status AS latest_booking_status,
                cb.quotation_token AS latest_quotation_token
            FROM costing_packages cp
            LEFT JOIN admin sales_admin ON sales_admin.AdminID = cp.sales_admin_id
            LEFT JOIN admin op_admin ON op_admin.AdminID = cp.op_admin_id
            LEFT JOIN costing_bookings cb ON cb.id = (
                SELECT cb_latest.id
                FROM costing_bookings cb_latest
                WHERE cb_latest.package_id = cp.id
                ORDER BY cb_latest.id DESC
                LIMIT 1
            )
            " . $where_sql . "
            ORDER BY cp.updated_at DESC, cp.id DESC
        ")->result_array();

        $package_ids = array();
        foreach ($packages as $package_row) {
            $package_ids[] = (int) $package_row['id'];
        }

        $items_by_package = $this->Read_Dashboard_Package_Items($package_ids);
        $bookings_by_package = $this->Read_Dashboard_Package_Bookings($package_ids);
        $cost_types = $this->Read_Cost_Types();
        $total_template_rows = 0;
        $total_booking_snapshots = 0;

        foreach ($packages as &$package) {
            $package['duration_days'] = (int) $package['duration_days'];
            $package['duration_nights'] = (int) $package['duration_nights'];
            $package['template_item_count'] = (int) $package['template_item_count'];
            $package['booking_count'] = (int) $package['booking_count'];
            $package['latest_total_pax'] = isset($package['latest_total_pax']) ? (int) $package['latest_total_pax'] : 0;
            $package['status_label'] = ucfirst((string) $package['status']);
            $package['latest_booking_label'] = !empty($package['latest_booking_id'])
                ? $this->Build_Booking_Number(array(
                    'id' => $package['latest_booking_id'],
                    'travel_date' => $package['latest_travel_date'],
                ))
                : 'No booking yet';
            $package['items'] = isset($items_by_package[(int) $package['id']]) ? $items_by_package[(int) $package['id']] : array();
            $package['bookings'] = isset($bookings_by_package[(int) $package['id']]) ? $bookings_by_package[(int) $package['id']] : array();
            $package['category_count'] = count(array_unique(array_filter(array_map(function ($item) {
                return isset($item['category']) ? trim((string) $item['category']) : '';
            }, $package['items']))));
            $package['cost_type_summary'] = array();
            foreach ($package['items'] as $item) {
                $cost_type = isset($item['cost_type']) ? (string) $item['cost_type'] : '';
                $label = isset($cost_types[$cost_type]) ? $cost_types[$cost_type] : ucfirst(str_replace('_', ' ', $cost_type));
                $package['cost_type_summary'][$label] = true;
            }
            $package['cost_type_summary'] = array_keys($package['cost_type_summary']);
            $package['revision_number'] = (int) $package['revision_number'];
            $total_template_rows += $package['template_item_count'];
            $total_booking_snapshots += $package['booking_count'];
        }
        unset($package);

        // Group packages into revision families (latest row per family + its history).
        $this->load->helper('costing_package_revision');
        $grouped = costing_group_package_revisions($packages);

        return array(
            'packages' => $grouped,
            'total_packages' => count($packages),
            'total_template_rows' => $total_template_rows,
            'total_booking_snapshots' => $total_booking_snapshots,
            'total_currencies' => (int) $this->db->count_all('costing_currencies'),
            'latest_rate_count' => count($this->Read_Latest_Exchange_Rates()),
        );
    }

    /**
     * Flat, detail-rich data set for the Costing Package listing Excel export.
     * Honours the same filter set as Read_Packages_Dashboard (via the shared
     * Build_Package_Filter_Where) but returns EVERY matching package row flat
     * (no revision-family grouping) plus the underlying detail batch-read for
     * the whole set: the latest booking's cost line items + combinations, and
     * the package-level hotel/flight quotation rows.
     *
     * @return array {packages, items, hotels, flights, combinations}
     */
    /**
     * Read the (filtered) package listing for the Excel export in the SAME shape
     * the Import template uses, so an export can be edited and re-imported
     * (round-trip). Returns one bucket per import sheet — packages / cost_items /
     * combinations / hotels / flights / itinerary — with every detail row stamped
     * with its owning package's name + tour_code. Detail (items/combinations) come
     * from each package's LATEST booking snapshot; hotels/flights/itinerary are
     * per-package. The export helper (costing_packages_export_helper.php) formats
     * these into the import-keyed, round-trip-safe cells.
     */
    public function Read_Packages_For_Export($filters = array())
    {
        $where_sql = $this->Build_Package_Filter_Where($filters);

        $packages = $this->db->query("
            SELECT
                cp.id,
                cp.name,
                cp.customer_name,
                cp.customer_contact,
                cp.customer_email,
                cp.sales_admin_id,
                sales_admin.Name AS sales_admin_name,
                cp.sales_admin_mobile,
                cp.tour_code,
                cp.duration_days,
                cp.duration_nights,
                cp.status,
                cp.validity_date,
                cp.description,
                cp.itinerary_notes,
                cp.itinerary_includes,
                cp.itinerary_excludes,
                cp.itinerary_important_notes,
                cp.itinerary_terms_and_conditions,
                cp.itinerary_tour_intro,
                cp.quote_flight_mode,
                cp.quote_hotel_columns,
                cp.quote_hotel_title_label,
                cp.quote_show_single_supp,
                cp.updated_at,
                cb.id AS latest_booking_id,
                cb.travel_date AS latest_travel_date,
                cb.travel_date_end AS latest_travel_date_end,
                cb.adult_count AS latest_adult_count,
                cb.child_count AS latest_child_count,
                cb.total_pax AS latest_total_pax,
                cb.status AS latest_booking_status,
                cbf.margin_percentage,
                cbf.commissionable_per_pax,
                cbf.ad_hoc_per_pax,
                cbf.selling_price_per_pax,
                cbf.total_revenue,
                cbf.total_cost,
                cbf.total_profit
            FROM costing_packages cp
            LEFT JOIN admin sales_admin ON sales_admin.AdminID = cp.sales_admin_id
            LEFT JOIN costing_bookings cb ON cb.id = (
                SELECT cb_latest.id
                FROM costing_bookings cb_latest
                WHERE cb_latest.package_id = cp.id
                ORDER BY cb_latest.id DESC
                LIMIT 1
            )
            LEFT JOIN costing_booking_financials cbf ON cbf.booking_id = cb.id
            " . $where_sql . "
            ORDER BY cp.updated_at DESC, cp.id DESC
        ")->result_array();

        $package_ids = array();
        $booking_ids = array();
        $package_meta = array();          // package_id  => [name, tour_code]
        $booking_to_package = array();    // booking_id  => package_id
        foreach ($packages as &$package) {
            $package_id = (int) $package['id'];
            $package_ids[] = $package_id;
            $package['latest_booking_label'] = !empty($package['latest_booking_id'])
                ? $this->Build_Booking_Number(array(
                    'id' => $package['latest_booking_id'],
                    'travel_date' => $package['latest_travel_date'],
                ))
                : 'No booking yet';
            $package_meta[$package_id] = array(
                'name' => (string) $package['name'],
                'tour_code' => (string) $package['tour_code'],
            );
            if (!empty($package['latest_booking_id'])) {
                $bid = (int) $package['latest_booking_id'];
                $booking_ids[] = $bid;
                $booking_to_package[$bid] = $package_id;
            }
        }
        unset($package);

        return array(
            'packages' => $packages,
            'cost_items' => $this->Read_Export_Booking_Items($booking_ids, $booking_to_package, $package_meta),
            'combinations' => $this->Read_Export_Combinations($booking_ids, $booking_to_package, $package_meta),
            'hotels' => $this->Read_Export_Package_Rows('costing_quote_hotels', $package_ids, $package_meta),
            'flights' => $this->Read_Export_Flights($package_ids, $package_meta),
            'itinerary' => $this->Read_Export_Itinerary($package_ids, $package_meta),
        );
    }

    /**
     * Batch-read the cost line items of a set of booking snapshots, stamping each
     * row with its owning package's name + tour_code for the export sheet. Carries
     * the owning combination's NAME (blank for a top-level row) plus the fields the
     * import needs (multiplier_type / unit_count). Ordered by booking, then the
     * manual sort order, then id.
     */
    private function Read_Export_Booking_Items($booking_ids, $booking_to_package, $package_meta)
    {
        if (empty($booking_ids)) {
            return array();
        }

        $rows = $this->db
            ->select('
                costing_booking_items.booking_id,
                costing_combinations.name AS combination,
                costing_booking_items.category,
                costing_booking_items.name,
                costing_booking_items.supplier,
                costing_booking_items.pax_type,
                costing_booking_items.multiplier_type,
                costing_booking_items.quantity,
                costing_booking_items.unit_count,
                costing_booking_items.unit_price,
                costing_booking_items.total_amount,
                costing_booking_items.myr_per_unit,
                costing_currencies.code AS currency
            ')
            ->join('costing_currencies', 'costing_currencies.id = costing_booking_items.currency_id', 'left')
            ->join('costing_combinations', 'costing_combinations.id = costing_booking_items.combination_id', 'left')
            ->where_in('costing_booking_items.booking_id', $booking_ids)
            ->order_by('costing_booking_items.booking_id', 'ASC')
            ->order_by('costing_booking_items.sort_order', 'ASC')
            ->order_by('costing_booking_items.id', 'ASC')
            ->get('costing_booking_items')
            ->result_array();

        return $this->Stamp_Export_Package_Meta($rows, $booking_to_package, $package_meta, 'booking_id');
    }

    /**
     * Batch-read the customer-facing combinations of a set of booking snapshots,
     * stamped with package name + tour_code (incl. is_general for round-trip).
     */
    private function Read_Export_Combinations($booking_ids, $booking_to_package, $package_meta)
    {
        if (empty($booking_ids)) {
            return array();
        }

        $rows = $this->db
            ->select('costing_booking_id, name, is_general, selling_price_per_pax')
            ->where_in('costing_booking_id', $booking_ids)
            ->order_by('costing_booking_id', 'ASC')
            ->order_by('sort_order', 'ASC')
            ->order_by('id', 'ASC')
            ->get('costing_combinations')
            ->result_array();

        return $this->Stamp_Export_Package_Meta($rows, $booking_to_package, $package_meta, 'costing_booking_id');
    }

    /**
     * Batch-read per-package flight OPTIONS flattened into one row per schedule
     * leg (each leg carries its option's title/airline/price so the import can
     * regroup them). An option with no legs still emits one blank-leg row so its
     * pricing round-trips; legs whose option is missing (legacy rows) emit with a
     * blank option. Stamped with package name + tour_code.
     */
    private function Read_Export_Flights($package_ids, $package_meta)
    {
        if (empty($package_ids)) {
            return array();
        }

        $options = $this->db
            ->where_in('package_id', $package_ids)
            ->order_by('package_id', 'ASC')
            ->order_by('sort_order', 'ASC')
            ->order_by('id', 'ASC')
            ->get('costing_quote_flight_options')
            ->result_array();

        $legs = $this->db
            ->where_in('package_id', $package_ids)
            ->order_by('package_id', 'ASC')
            ->order_by('sort_order', 'ASC')
            ->order_by('id', 'ASC')
            ->get('costing_quote_flights')
            ->result_array();

        $legs_by_option = array();
        foreach ($legs as $leg) {
            $legs_by_option[(int) (isset($leg['option_id']) ? $leg['option_id'] : 0)][] = $leg;
        }

        $leg_row = function ($package_id, $option, $leg) {
            return array(
                'package_id'   => (int) $package_id,
                'option_title' => (string) (isset($option['title']) ? $option['title'] : ''),
                'airline'      => (string) (isset($option['airline']) ? $option['airline'] : ''),
                'option_price' => isset($option['price']) ? $option['price'] : '',
                'travel_date'  => (string) (isset($leg['travel_date']) ? $leg['travel_date'] : ''),
                'sector'       => (string) (isset($leg['sector']) ? $leg['sector'] : ''),
                'flight_no'    => (string) (isset($leg['flight_no']) ? $leg['flight_no'] : ''),
                'timing'       => (string) (isset($leg['timing']) ? $leg['timing'] : ''),
                'duration'     => (string) (isset($leg['duration']) ? $leg['duration'] : ''),
            );
        };

        $rows = array();
        $known_option_ids = array();
        foreach ($options as $option) {
            $oid = (int) $option['id'];
            $known_option_ids[$oid] = true;
            $option_legs = isset($legs_by_option[$oid]) ? $legs_by_option[$oid] : array();
            if (empty($option_legs)) {
                $rows[] = $leg_row((int) $option['package_id'], $option, array());
                continue;
            }
            foreach ($option_legs as $leg) {
                $rows[] = $leg_row((int) $option['package_id'], $option, $leg);
            }
        }
        // Orphan legs (no matching option row) — carry a blank option.
        foreach ($legs as $leg) {
            $oid = (int) (isset($leg['option_id']) ? $leg['option_id'] : 0);
            if (!isset($known_option_ids[$oid])) {
                $rows[] = $leg_row((int) $leg['package_id'], array(), $leg);
            }
        }

        $map = array();
        foreach ($package_ids as $pid) {
            $map[(int) $pid] = (int) $pid;
        }

        return $this->Stamp_Export_Package_Meta($rows, $map, $package_meta, 'package_id');
    }

    /**
     * Batch-read per-package itinerary day rows (day number / title / description /
     * meal plan), stamped with package name + tour_code. meal_plan is the stored
     * comma-separated slug string, which the import parser reads back verbatim.
     */
    private function Read_Export_Itinerary($package_ids, $package_meta)
    {
        if (empty($package_ids)) {
            return array();
        }

        $rows = $this->db
            ->select('package_id, day_number, title, description, meal_plan')
            ->where_in('package_id', $package_ids)
            ->order_by('package_id', 'ASC')
            ->order_by('day_number', 'ASC')
            ->order_by('id', 'ASC')
            ->get('costing_itinerary_days')
            ->result_array();

        $map = array();
        foreach ($package_ids as $pid) {
            $map[(int) $pid] = (int) $pid;
        }

        return $this->Stamp_Export_Package_Meta($rows, $map, $package_meta, 'package_id');
    }

    /**
     * Batch-read a per-package quotation table (hotels / flights), stamped with
     * package name + tour_code. Rows are keyed directly by package_id.
     */
    private function Read_Export_Package_Rows($table, $package_ids, $package_meta)
    {
        if (empty($package_ids)) {
            return array();
        }

        $rows = $this->db
            ->where_in('package_id', $package_ids)
            ->order_by('package_id', 'ASC')
            ->order_by('sort_order', 'ASC')
            ->order_by('id', 'ASC')
            ->get($table)
            ->result_array();

        $map = array();
        foreach ($package_ids as $pid) {
            $map[(int) $pid] = (int) $pid;
        }

        return $this->Stamp_Export_Package_Meta($rows, $map, $package_meta, 'package_id');
    }

    /**
     * Attach package_name + tour_code to each detail row by resolving its owning
     * package through $key (a booking-id or package-id column) -> $id_to_package
     * -> $package_meta. Pure array transform (no DB).
     */
    private function Stamp_Export_Package_Meta($rows, $id_to_package, $package_meta, $key)
    {
        foreach ($rows as &$row) {
            $owner = isset($row[$key]) ? (int) $row[$key] : 0;
            $package_id = isset($id_to_package[$owner]) ? $id_to_package[$owner] : 0;
            $meta = isset($package_meta[$package_id]) ? $package_meta[$package_id] : array('name' => '', 'tour_code' => '');
            $row['package_id'] = $package_id;
            $row['package_name'] = $meta['name'];
            $row['tour_code'] = $meta['tour_code'];
        }
        unset($row);

        return $rows;
    }

    public function Read_Package_Workspace_Data($package_id, $booking_id = null, $base_currency_code = 'MYR', $force_new_snapshot = false)
    {
        $this->load->helper('costing_itinerary');
        $base_currency = $this->Read_Base_Currency($base_currency_code);
        if (!$base_currency) {
            $base_currency = array('id' => 0, 'code' => $base_currency_code);
        }

        $package = $this->Read_Package($package_id);
        if (!$package) {
            return $this->Empty_Workspace_Data($base_currency['code']);
        }

        $booking = $force_new_snapshot
            ? null
            : ($booking_id > 0
                ? $this->Read_Booking($booking_id, $package_id)
                : $this->Read_Latest_Booking_For_Package($package_id));

        $bookings = $this->Read_Bookings_For_Package($package_id);
        $package_items = $this->Read_Package_Items($package_id);
        $booking_items = array();
        $financials = $this->Empty_Financials();

        if ($booking) {
            $booking_items = $this->Read_Booking_Items($booking['id'], (int) $base_currency['id'], $booking['travel_date']);
            $financial_rows = $this->Read_Cost_Rows_For_Financials($booking['id'], (int) $base_currency['id'], $booking['travel_date']);
            $financials = $this->Calculate_Financials_From_Booking($booking, $financial_rows, (int) $base_currency['id']);
        }

        return array(
            'package' => array(
                'id' => (int) $package['id'],
                'name' => $package['name'],
                'customer_name' => isset($package['customer_name']) ? $package['customer_name'] : '',
                'customer_contact' => isset($package['customer_contact']) ? $package['customer_contact'] : '',
                'customer_email' => isset($package['customer_email']) ? $package['customer_email'] : '',
                'sales_admin_id' => isset($package['sales_admin_id']) ? (int) $package['sales_admin_id'] : 0,
                'sales_admin_mobile' => isset($package['sales_admin_mobile']) ? (string) $package['sales_admin_mobile'] : '',
                'op_admin_id' => isset($package['op_admin_id']) ? (int) $package['op_admin_id'] : 0,
                'op_admin_name' => isset($package['op_admin_name']) ? (string) $package['op_admin_name'] : '',
                'validity_date' => isset($package['validity_date']) ? (string) $package['validity_date'] : '',
                'tour_code' => isset($package['tour_code']) ? $package['tour_code'] : '',
                'duration_days' => (int) $package['duration_days'],
                'duration_nights' => (int) $package['duration_nights'],
                'description' => $package['description'],
                'status' => ucfirst($package['status']),
                'status_value' => (string) $package['status'],
            ),
            'booking' => $booking ? array(
                'id' => (int) $booking['id'],
                'booking_number' => $this->Build_Booking_Number($booking),
                'travel_date' => $booking['travel_date'],
                'travel_date_end' => isset($booking['travel_date_end']) ? $booking['travel_date_end'] : '',
                'adult_count' => (int) $booking['adult_count'],
                'child_count' => (int) $booking['child_count'],
                'total_pax' => (int) $booking['total_pax'],
                'status' => ucfirst($booking['booking_status']),
                'status_value' => (string) $booking['booking_status'],
            ) : $this->Empty_Booking_Data(),
            'bookings' => $bookings,
            'package_items' => $package_items,
            'booking_items' => $booking_items,
            'selected_package_item_ids' => $this->Extract_Selected_Package_Item_Ids($booking_items),
            'category_suggestions' => $this->Build_Category_Suggestions($package_items, $booking_items),
            'financials' => $financials,
            'base_currency' => $base_currency['code'],
            'has_booking' => !empty($booking),
            'has_bookings' => !empty($bookings),
            'currencies' => $this->Read_Currencies(),
            'statuses' => array('active', 'inactive'),
            'cost_types' => $this->Read_Cost_Types(),
            'latest_exchange_rates' => $this->Read_Latest_Exchange_Rates(),
            'snapshot_panel' => $booking ? $this->Read_Snapshot_Panel((int) $booking['id']) : array(),
            'itinerary_days' => $this->Read_Itinerary_Days((int) $package['id']),
            // Itinerary-wide sections — four separate rich-text blocks per package
            // (Include / Exclude / Important Notes / Terms & Conditions), with a
            // split-on-the-fly fallback for legacy merged notes.
            'itinerary_meta' => costing_itinerary_sections_from_row($package),
            // Standalone Tour Intro header (separate from the four sections above).
            'itinerary_tour_intro' => isset($package['itinerary_tour_intro']) ? (string) $package['itinerary_tour_intro'] : '',
            // Hotel-pricing + flight-schedule tables and their quote-level free-text,
            // shown on the customer Quotation PDF and edited on the wizard's
            // "Hotel & Flights" step.
            'quote_hotels'  => $this->Read_Quote_Hotels((int) $package['id']),
            'quote_flights' => $this->Read_Quote_Flights((int) $package['id']),
            // 18 Sep 2026 rework: flight options (item 3 + 4.4) — each with its own
            // airline + schedule + pricing.
            'quote_flight_options' => $this->Read_Quote_Flight_Options((int) $package['id']),
            'quote_meta'    => array(
                'quote_pricing_basis'    => isset($package['quote_pricing_basis']) ? $package['quote_pricing_basis'] : '',
                'quote_travel_date_note' => isset($package['quote_travel_date_note']) ? $package['quote_travel_date_note'] : '',
                'quote_hotel_note'       => isset($package['quote_hotel_note']) ? $package['quote_hotel_note'] : '',
                'quote_flight_title'     => isset($package['quote_flight_title']) ? $package['quote_flight_title'] : '',
                'quote_flight_price'     => isset($package['quote_flight_price']) ? $package['quote_flight_price'] : '',
                'quote_flight_fare_note' => isset($package['quote_flight_fare_note']) ? $package['quote_flight_fare_note'] : '',
                'quote_flight_expiry'    => isset($package['quote_flight_expiry']) ? $package['quote_flight_expiry'] : '',
                // Keep NULL distinct from '' so the editor/PDF can tell "never
                // saved" (→ default boilerplate) from "deliberately cleared".
                'quote_footer_notes'     => array_key_exists('quote_footer_notes', $package) ? $package['quote_footer_notes'] : null,
                // 18 Sep 2026: flight mode (4.3) + hotel pricing columns (4.2).
                'quote_flight_mode'      => isset($package['quote_flight_mode']) ? $package['quote_flight_mode'] : '',
                'quote_hotel_columns'    => isset($package['quote_hotel_columns']) ? $package['quote_hotel_columns'] : '',
                // 20 Oct 2026: per-column % for auto-filling the 2nd+ price columns.
                'quote_hotel_pcts'       => isset($package['quote_hotel_pcts']) ? $package['quote_hotel_pcts'] : '',
                // 5 Oct 2026: first-column title (Hotel / Room Type) + Single Supp on/off.
                'quote_hotel_title_label' => isset($package['quote_hotel_title_label']) ? $package['quote_hotel_title_label'] : '',
                'quote_show_single_supp'  => isset($package['quote_show_single_supp']) ? $package['quote_show_single_supp'] : '',
            ),
            // 4.1: combination name + per-pax selling price, so the Hotel step can
            // auto-fill the hotel table from the Costing Template's combinations.
            'combination_pricing' => $booking
                ? $this->Combination_Pricing_List((int) $booking['id'], (int) $base_currency['id'], $booking['travel_date'], (float) $financials['margin_percentage'], (int) $booking['total_pax'])
                : array(),
            'sales_agents' => $this->Read_Sales_Agents(isset($package['sales_admin_id']) ? (int) $package['sales_admin_id'] : 0),
            'quotation_token' => $booking && !empty($booking['quotation_token']) ? $booking['quotation_token'] : '',
            'item_master' => $this->Read_Item_Master(),
            // Feedback 18 Sep 2026 (2.4): the cost step previews (and freezes on save)
            // the LATEST exchange rate, so amending/recalculating a package always
            // picks up the current rate instead of the one captured at creation.
            'currency_rate_map' => $this->Read_Currency_Rate_Map(null, (int) $base_currency['id']),
            'combinations' => $booking ? $this->Read_Combinations((int) $booking['id'], (int) $base_currency['id'], $booking['travel_date']) : array(),
        );
    }

    /**
     * currency_id => { code, rate_to_myr, bank_charges_myr } for the cost step's
     * live "MYR (convert)" preview. Pulls the latest foreign->MYR rate + bank
     * charge maintained on the Costing Currency page (costing_exchange_rates).
     * The base currency (MYR) is rate 1 with no charge. Server-side saving still
     * freezes the confirmed snapshot rates, so this map is only a UI convenience.
     */
    public function Read_Currency_Rate_Map($travel_date = null, $base_currency_id = 0)
    {
        if ($base_currency_id <= 0) {
            $base = $this->Read_Base_Currency('MYR');
            $base_currency_id = $base ? (int) $base['id'] : 0;
        }

        $map = array();
        foreach ($this->Read_Currencies() as $currency) {
            $currency_id = (int) $currency['id'];
            if ($currency_id === $base_currency_id) {
                $map[$currency_id] = array('code' => strtoupper((string) $currency['code']), 'rate_to_myr' => 1.0, 'bank_charges_myr' => 0.0);
                continue;
            }
            $details = $this->Resolve_Exchange_Rate_Details($currency_id, $base_currency_id, $travel_date);
            $map[$currency_id] = array(
                'code' => strtoupper((string) $currency['code']),
                'rate_to_myr' => (float) $details['rate'],
                'bank_charges_myr' => (float) $details['bank_charges_myr'],
            );
        }

        return $map;
    }

    public function Read_Currency_Dashboard_Data()
    {
        $active_tab = (string) $this->input->get('active_tab');
        $active_tab = in_array($active_tab, array('currencies', 'rates'), true) ? $active_tab : 'currencies';

        $currency_filters = array(
            'code' => trim((string) $this->input->get('currency_code')),
            'name' => trim((string) $this->input->get('currency_name')),
            'symbol' => trim((string) $this->input->get('currency_symbol')),
        );

        $rate_filters = array(
            'from_currency_id' => (int) $this->input->get('rate_from_currency_id'),
            'to_currency_id' => (int) $this->input->get('rate_to_currency_id'),
            'valid_from' => trim((string) $this->input->get('rate_valid_from')),
        );

        return array(
            'active_tab' => $active_tab,
            'currency_filters' => $currency_filters,
            'rate_filters' => $rate_filters,
            'currencies' => $this->Read_Currencies($currency_filters),
            'exchange_rates' => $this->Read_Latest_Exchange_Rates($rate_filters),
            'exchange_rate_histories' => $this->Read_Exchange_Rate_History(),
            'currency_options' => $this->Read_Currencies(),
        );
    }

    public function Save_Package($package)
    {
        $sales_admin_id = (int) (isset($package['sales_admin_id']) ? $package['sales_admin_id'] : 0);
        $sales_admin_mobile = trim((string) (isset($package['sales_admin_mobile']) ? $package['sales_admin_mobile'] : ''));
        $op_admin_id = (int) (isset($package['op_admin_id']) ? $package['op_admin_id'] : 0);
        $validity_date = trim((string) (isset($package['validity_date']) ? $package['validity_date'] : ''));
        $data = array(
            'name' => trim((string) $package['name']),
            'customer_name' => trim((string) $package['customer_name']),
            'customer_contact' => trim((string) $package['customer_contact']),
            'customer_email' => trim((string) $package['customer_email']),
            'sales_admin_id' => $sales_admin_id > 0 ? $sales_admin_id : null,
            // Per-package override of the sales person's contact number (prefilled
            // from admin.Mobile on the form, but editable). NULL when left blank.
            'sales_admin_mobile' => $sales_admin_mobile !== '' ? $sales_admin_mobile : null,
            // Quotation "valid until" date; stored as Y-m-d, NULL when left blank.
            'validity_date' => ($validity_date !== '' && strtotime($validity_date)) ? date('Y-m-d', strtotime($validity_date)) : null,
            'duration_days' => max(1, (int) $package['duration_days']),
            'duration_nights' => max(0, (int) $package['duration_nights']),
            'description' => trim((string) $package['description']),
            'status' => $this->Normalize_Status($package['status']),
            // Manually keyed code linking this package to a related one. Unlike the
            // auto tour_code, this is editable on BOTH create and edit. NULL when blank.
            'related_tour_code' => (isset($package['related_tour_code']) && trim((string) $package['related_tour_code']) !== '')
                ? trim((string) $package['related_tour_code'])
                : null,
        );

        if ($data['name'] === '') {
            return 0;
        }

        if (isset($package['op_admin_id'])) {
            $data['op_admin_id'] = $op_admin_id > 0 ? $op_admin_id : null;
        }

        // Tour code is fully auto: assigned once on create (QU-YYMM-XXXX), never
        // touched on edit (there is no tour_code field on the form anymore).
        if (!empty($package['id'])) {
            $this->db->where('id', (int) $package['id'])->update('costing_packages', $data);
            return (int) $package['id'];
        }

        $data['tour_code'] = $this->Next_Tour_Code();
        $this->db->insert('costing_packages', $data);
        return (int) $this->db->insert_id();
    }

    /**
     * Active currencies (public reader for the Excel import template / parser).
     * Thin wrapper over the private Read_Currencies so no query logic is duplicated.
     *
     * @return array list of ['id','code','name','symbol']
     */
    public function Read_Currency_List()
    {
        return $this->Read_Currencies();
    }

    /**
     * Create ONE package from an assembled Excel-import payload (the shape
     * costing_import_assemble_packages() produces). Wraps the three existing save
     * paths in a single transaction so a package is all-or-nothing:
     *   Save_Package -> Generate_Booking_Snapshot (items/combos/financials/rates)
     *   -> Save_Quote_Details (hotels/flights/quote-level) -> Save_Itinerary_Days
     *   (per-day rows + package itinerary notes).
     * The inner methods open their own nested transactions; CI counts the depth so
     * the outer trans_complete() is the real commit point. Returns
     * ['ok'=>bool, 'package_id'=>int].
     *
     * @param array $pkg {save_package, snapshot_payload, hotels, flight_options, quote_level}
     * @param int $op_admin_id The admin running the import; stamped as the OP person
     *                         (creator) on the new package. 0 leaves it unset (NULL).
     * @return array
     */
    public function Import_Package($pkg, $op_admin_id = 0)
    {
        // Guard the one condition under which Generate_Booking_Snapshot would bail
        // out BEFORE opening any transaction, so we never insert a package row that
        // we would then be unable to cleanly roll back (it bails before its own
        // trans_start, i.e. without a query error for CI to detect). Cost items are
        // optional for an import: the snapshot payload carries allow_empty_items so
        // Generate_Booking_Snapshot still creates the booking with an empty cost
        // list. Pax is still required (it drives the snapshot's per-pax maths).
        $sp = (array) $pkg['snapshot_payload'];
        $has_pax = (max(0, (int) $sp['adult_count']) + max(0, (int) $sp['child_count'])) > 0;
        if (!$has_pax) {
            return array('ok' => false, 'package_id' => 0);
        }

        $this->db->trans_start();

        // Default the imported package's OP person to the admin running the import.
        $save_package = (array) $pkg['save_package'];
        $save_package['op_admin_id'] = (int) $op_admin_id;
        $package_id = $this->Save_Package($save_package);
        if ($package_id > 0) {
            $this->Generate_Booking_Snapshot($package_id, $pkg['snapshot_payload']);
            $this->Save_Quote_Details(
                $package_id,
                isset($pkg['hotels']) ? (array) $pkg['hotels'] : array(),
                isset($pkg['flight_options']) ? (array) $pkg['flight_options'] : array(),
                isset($pkg['quote_level']) ? (array) $pkg['quote_level'] : array()
            );
            $this->Save_Itinerary_Days(
                $package_id,
                isset($pkg['itinerary']) ? (array) $pkg['itinerary'] : array(),
                isset($pkg['itinerary_level']) ? (array) $pkg['itinerary_level'] : array(),
                isset($pkg['itinerary_tour_intro']) ? $pkg['itinerary_tour_intro'] : null
            );
        }

        $this->db->trans_complete();

        $ok = ($package_id > 0) && (bool) $this->db->trans_status();
        return array('ok' => $ok, 'package_id' => $ok ? $package_id : 0);
    }

    /**
     * Active admin users for the "Sales Person" dropdown (package form +
     * quotation). Mirrors Booking_Model::Read_Admins (excludes the system account).
     * Only currently-active ('Y') admins are listed so deactivated staff don't
     * clutter the picker. $keep_admin_id lets an EDIT keep the package's already-
     * assigned sales person visible even if they've since been deactivated, so the
     * selection isn't silently dropped on save.
     *
     * @param int  $keep_admin_id admin to include regardless of status (0 = none)
     * @param bool $include_deactivated when true, also list deactivated ('D')
     *             staff — used by the export/import name→id mapping so a round-trip
     *             of a package assigned to a since-deactivated person still resolves.
     * @return array list of ['AdminID','Name','Mobile','CountryCode']
     */
    public function Read_Sales_Agents($keep_admin_id = 0, $include_deactivated = false)
    {
        $keep_admin_id = (int) $keep_admin_id;

        // Mobile + country code come along so the package form can auto-prefill the
        // sales person's contact when one is picked (editable override thereafter).
        $this->db
            ->select('a.AdminID, a.Name, a.Mobile, cc.CountryCode AS CountryCode')
            ->from('admin a')
            ->join('country_code cc', 'cc.CountryCodeID = a.CountryCodeID', 'left')
            ->where('a.AdminID !=', 8);

        if ($include_deactivated) {
            $this->db->where_in('a.Status', array('Y', 'D'));
        } elseif ($keep_admin_id > 0) {
            $this->db->group_start()
                ->where('a.Status', 'Y')
                ->or_where('a.AdminID', $keep_admin_id)
                ->group_end();
        } else {
            $this->db->where('a.Status', 'Y');
        }

        return $this->db
            ->order_by('a.Name', 'ASC')
            ->get()
            ->result_array();
    }

    /**
     * Only currently-active (Status = 'Y') admins — deactivated ('D') and deleted
     * ('N') users are excluded. Used for the listing's Sales Person filter so it
     * lists live sales people only; the package form keeps Read_Sales_Agents so an
     * existing package assigned to a since-deactivated person still shows them.
     *
     * @return array list of ['AdminID','Name']
     */
    public function Read_Active_Sales_Agents()
    {
        return $this->db
            ->select('AdminID, Name')
            ->where('AdminID !=', 8)
            ->where('Status', 'Y')
            ->order_by('Name', 'ASC')
            ->get('admin')
            ->result_array();
    }

    /**
     * Next auto tour code (QU-YYMM-XXXX) for the current month. Sequence = highest
     * existing code in this YYMM period + 1; hand-typed / other-period codes are
     * ignored. Format/parse live in the pure costing_tour_code helper.
     */
    public function Next_Tour_Code()
    {
        $this->load->helper('costing_tour_code');
        $period = date('ym');

        $like = costing_tour_code_prefix() . '-' . $period . '-';
        $rows = $this->db
            ->select('tour_code')
            ->like('tour_code', $like, 'after')
            ->get('costing_packages')
            ->result_array();

        $existing = array();
        foreach ($rows as $row) {
            $existing[] = (string) $row['tour_code'];
        }

        return costing_tour_code_next($existing, $period);
    }

    /**
     * Soft-delete a costing package: flag status='deleted' and stamp the audit
     * columns (who/when). The package then disappears from the listing, the
     * Excel export (both filter out status='deleted' via Build_Package_Filter_Where)
     * and its public quotation URL (costing_quotation_status_accessible), but
     * every row — bookings/items/financials/combinations/quotation token — is
     * kept intact and recoverable via the DB. No in-app restore UI.
     */
    public function Delete_Package($package_id, $deleted_by = 0)
    {
        $package_id = (int) $package_id;
        if ($package_id <= 0 || !$this->Read_Package($package_id)) {
            return false;
        }

        $now = date('Y-m-d H:i:s');
        $this->db->where('id', $package_id)->update('costing_packages', array(
            'status'     => 'deleted',
            'deleted_at' => $now,
            'deleted_by' => ((int) $deleted_by > 0) ? (int) $deleted_by : null,
            'updated_at' => $now,
        ));

        return $this->db->affected_rows() >= 0;
    }

    /**
     * Deep-copy a costing package and every record hanging off it into a brand-new
     * package, returning the new package id (0 on failure). The clone gets a fresh
     * auto tour code and a new name ("<name> (Copy)"); its snapshot gets no
     * quotation token (a fresh one is minted on demand), so the original's public
     * quotation link is never shared. Supplier quotation attachments
     * (costing_quotation_files) are deliberately NOT copied — the uploaded files
     * are physical, uploader-owned references to the original package.
     */
    public function Duplicate_Package($package_id, $op_admin_id = 0)
    {
        $package_id = (int) $package_id;
        $source = $package_id > 0 ? $this->Read_Package($package_id) : null;
        if (empty($source)) {
            return 0;
        }

        // A plain duplicate is a brand-new, independent package: fresh monthly tour
        // code, "(Copy)" name, and its own revision family (reset the revision columns
        // so it does not inherit the source's lineage).
        $overrides = array(
            'name' => trim((string) $source['name']) . ' (Copy)',
            'tour_code' => $this->Next_Tour_Code(),
            'revision_root_id' => null,
            'revision_number' => 0,
        );
        // The duplicate is created by whoever clicked Duplicate — re-stamp the OP person.
        if ((int) $op_admin_id > 0) {
            $overrides['op_admin_id'] = (int) $op_admin_id;
        }
        return $this->Deep_Copy_Package($source, $overrides);
    }

    /**
     * Create the next revision of a costing package. A revision keeps the base
     * quotation number and appends "-REVnn" (QU-YYMM-XXXX-REV01, -REV02, ...), stays
     * linked to the family via revision_root_id, and is a full, independently-editable
     * deep copy. Revisions always branch from the family's latest version (linear
     * history), regardless of which member was passed in. Returns the new package id
     * (0 on failure).
     */
    public function Create_Revision($package_id, $op_admin_id = 0)
    {
        $state = $this->Revision_Family_State($package_id);
        if ($state === null) {
            return 0;
        }

        // Copy from the latest member (re-read via Read_Package so the row shape matches
        // Deep_Copy_Package's expectations).
        $copy_source = $this->Read_Package((int) $state['latest']['id']);
        if (empty($copy_source)) {
            return 0;
        }

        $overrides = array(
            // Keep the base package name — a revision is the same quote, not a copy.
            'name' => trim((string) $state['root']['name']),
            'tour_code' => $state['next_code'],
            'revision_root_id' => $state['root_id'],
            'revision_number' => $state['next'],
        );
        // The revision is created by whoever clicked Create Revision — re-stamp the OP person.
        if ((int) $op_admin_id > 0) {
            $overrides['op_admin_id'] = (int) $op_admin_id;
        }
        return $this->Deep_Copy_Package($copy_source, $overrides);
    }

    /**
     * Resolve the revision-family state for any member of a family: its root row, the
     * base tour code, the latest member (highest revision number), and the next
     * revision number + code. Returns null when the package (or its root) is missing.
     * Shared by Create_Revision (deep-copy source) and Import_Package_As_Revision
     * (import a new member) so revision numbering stays in one place.
     *
     * @param int $package_id any member of the family (root or a revision).
     * @return array|null ['root','root_id','base_code','latest','next','next_code']
     */
    private function Revision_Family_State($package_id)
    {
        $this->load->helper('costing_tour_code');

        $package_id = (int) $package_id;
        $source = $package_id > 0 ? $this->Read_Package($package_id) : null;
        if (empty($source)) {
            return null;
        }

        // The family root owns the base tour code. The base itself has no root set, so
        // it is its own root.
        $root_id = !empty($source['revision_root_id']) ? (int) $source['revision_root_id'] : (int) $source['id'];
        $root = $this->Read_Package($root_id);
        if (empty($root)) {
            return null;
        }
        $base_code = costing_tour_code_base((string) $root['tour_code']);

        // Gather every member of the family (root + its revisions) to find both the
        // latest version and the next revision number.
        $members = $this->db
            ->where('id', $root_id)
            ->or_where('revision_root_id', $root_id)
            ->get('costing_packages')
            ->result_array();
        if (empty($members)) {
            return null;
        }

        $latest = null;
        $existing_codes = array();
        foreach ($members as $member) {
            $existing_codes[] = (string) $member['tour_code'];
            if ($latest === null || (int) $member['revision_number'] > (int) $latest['revision_number']) {
                $latest = $member;
            }
        }

        $next = costing_tour_code_next_revision($existing_codes, $base_code);

        return array(
            'root'      => $root,
            'root_id'   => $root_id,
            'base_code' => $base_code,
            'latest'    => $latest,
            'next'      => $next,
            'next_code' => costing_tour_code_revision($base_code, $next),
        );
    }

    /**
     * Look up the revision family a given (base) tour code belongs to, for the Excel
     * import's "import as revision" matching. Accepts a base OR a -REVnn code; the base
     * is recovered either way. Returns the family's root id, root name and the next
     * revision code it would mint, or null when no package carries that base code.
     *
     * @param string $tour_code a tour code from the uploaded file.
     * @return array|null ['root_id','root_name','next_code']
     */
    public function Read_Revision_Family_By_Base($tour_code)
    {
        $this->load->helper('costing_tour_code');

        $base_code = costing_tour_code_base((string) $tour_code);
        if ($base_code === '') {
            return null;
        }

        // 'QU-YYMM-XXXX%' matches the base itself and its -REVnn members only (the
        // sequence is fixed-width, so it never bleeds into a neighbouring code). Confirm
        // the base in PHP to be safe.
        $rows = $this->db
            ->select('id, tour_code')
            ->like('tour_code', $base_code, 'after')
            ->get('costing_packages')
            ->result_array();

        $match_id = 0;
        foreach ($rows as $row) {
            if (costing_tour_code_base((string) $row['tour_code']) === $base_code) {
                $match_id = (int) $row['id'];
                break;
            }
        }
        if ($match_id <= 0) {
            return null;
        }

        $state = $this->Revision_Family_State($match_id);
        if ($state === null) {
            return null;
        }

        return array(
            'root_id'   => $state['root_id'],
            'root_name' => trim((string) $state['root']['name']),
            'next_code' => $state['next_code'],
        );
    }

    /**
     * Import ONE assembled package as a new REVISION of an existing family, rather than
     * a brand-new package. Mirrors Import_Package's save pipeline, but the new package
     * row is stamped with the family's next revision tour code + lineage and keeps the
     * family's base name. $root_id is any member of the target family (resolved to its
     * root). Returns ['ok'=>bool,'package_id'=>int,'tour_code'=>string].
     *
     * @param array $pkg assembled package (costing_import_assemble_packages).
     * @param int   $root_id a member of the family to revise.
     * @param int   $op_admin_id admin running the import (stamped as OP/creator).
     * @return array
     */
    public function Import_Package_As_Revision($pkg, $root_id, $op_admin_id = 0)
    {
        // Same pax guard as Import_Package — Generate_Booking_Snapshot needs pax.
        $sp = (array) $pkg['snapshot_payload'];
        $has_pax = (max(0, (int) $sp['adult_count']) + max(0, (int) $sp['child_count'])) > 0;
        if (!$has_pax) {
            return array('ok' => false, 'package_id' => 0, 'tour_code' => '');
        }

        // Resolve the lineage FRESH at commit time so the revision number reflects any
        // family members added since the preview was shown.
        $state = $this->Revision_Family_State((int) $root_id);
        if ($state === null) {
            return array('ok' => false, 'package_id' => 0, 'tour_code' => '');
        }

        $this->db->trans_start();

        $save_package = (array) $pkg['save_package'];
        $save_package['op_admin_id'] = (int) $op_admin_id;
        // Unlike the "Create Revision" button (which supplies no name, so it keeps
        // the family's base name), an Excel import carries an explicit PACKAGE NAME
        // the user may have updated — honour it, falling back to the root's name
        // only when the imported cell is blank.
        $this->load->helper('costing_packages_import');
        $save_package['name'] = costing_import_revision_name(
            isset($save_package['name']) ? $save_package['name'] : '',
            isset($state['root']['name']) ? $state['root']['name'] : ''
        );
        $package_id = $this->Save_Package($save_package);
        if ($package_id > 0) {
            // Save_Package mints a throwaway monthly code on insert; overwrite it with
            // the real revision code and link the row into the family.
            $this->db->where('id', $package_id)->update('costing_packages', array(
                'tour_code'        => $state['next_code'],
                'revision_root_id' => $state['root_id'],
                'revision_number'  => $state['next'],
            ));
            $this->Generate_Booking_Snapshot($package_id, $pkg['snapshot_payload']);
            $this->Save_Quote_Details(
                $package_id,
                isset($pkg['hotels']) ? (array) $pkg['hotels'] : array(),
                isset($pkg['flight_options']) ? (array) $pkg['flight_options'] : array(),
                isset($pkg['quote_level']) ? (array) $pkg['quote_level'] : array()
            );
            $this->Save_Itinerary_Days(
                $package_id,
                isset($pkg['itinerary']) ? (array) $pkg['itinerary'] : array(),
                isset($pkg['itinerary_level']) ? (array) $pkg['itinerary_level'] : array(),
                isset($pkg['itinerary_tour_intro']) ? $pkg['itinerary_tour_intro'] : null
            );
        }

        $this->db->trans_complete();

        $ok = ($package_id > 0) && (bool) $this->db->trans_status();
        return array(
            'ok'         => $ok,
            'package_id' => $ok ? $package_id : 0,
            'tour_code'  => $ok ? $state['next_code'] : '',
        );
    }

    /**
     * Deep-copy a costing package and every record hanging off it into a new package,
     * applying $header_overrides onto the package-header row. Shared by
     * Duplicate_Package (fresh family) and Create_Revision (linked family). Returns the
     * new package id (0 on failure). The snapshot gets no quotation token (minted on
     * demand), so the source's public quotation link is never shared. Supplier
     * quotation attachments (costing_quotation_files) are deliberately NOT copied — the
     * uploaded files are physical, uploader-owned references to the original package.
     */
    private function Deep_Copy_Package($source, $header_overrides = array())
    {
        $package_id = (int) $source['id'];

        $this->db->trans_start();

        // 1) The package row itself. Drop the identity/timestamp columns, apply the
        //    caller's header overrides (name, tour code, revision lineage); everything
        //    else (customer, sales person, itinerary notes, quote-level fields) carries
        //    over verbatim.
        $package_data = $source;
        // op_admin_name is a joined display column (see Read_Package), not a real
        // costing_packages column — drop it so the insert stays valid.
        unset($package_data['id'], $package_data['created_at'], $package_data['updated_at'], $package_data['op_admin_name']);
        foreach ($header_overrides as $column => $value) {
            $package_data[$column] = $value;
        }
        $this->db->insert('costing_packages', $package_data);
        $new_package_id = (int) $this->db->insert_id();

        if ($new_package_id <= 0) {
            $this->db->trans_complete();
            return 0;
        }

        // 2) Item master for the package.
        $this->Clone_Rows('costing_package_items', array('package_id' => $package_id), array('package_id' => $new_package_id));

        // 3) Itinerary days + quote hotels (both keyed directly by package_id).
        $this->Clone_Rows('costing_itinerary_days', array('package_id' => $package_id), array('package_id' => $new_package_id));
        $this->Clone_Rows('costing_quote_hotels', array('package_id' => $package_id), array('package_id' => $new_package_id));

        // 4) Flight options first (flights reference them) — keep an old→new map so
        //    each flight row can be re-pointed at its cloned option.
        $flight_options = $this->db->where('package_id', $package_id)->get('costing_quote_flight_options')->result_array();
        $option_map = array();
        foreach ($flight_options as $option) {
            $old_option_id = (int) $option['id'];
            unset($option['id'], $option['created_at'], $option['updated_at']);
            $option['package_id'] = $new_package_id;
            $this->db->insert('costing_quote_flight_options', $option);
            $option_map[$old_option_id] = (int) $this->db->insert_id();
        }

        $flights = $this->db->where('package_id', $package_id)->get('costing_quote_flights')->result_array();
        foreach ($flights as $flight) {
            unset($flight['id'], $flight['created_at'], $flight['updated_at']);
            $flight['package_id'] = $new_package_id;
            $old_option_id = (int) $flight['option_id'];
            $flight['option_id'] = ($old_option_id > 0 && isset($option_map[$old_option_id])) ? $option_map[$old_option_id] : null;
            $this->db->insert('costing_quote_flights', $flight);
        }

        // 5) The snapshot(s). One package normally holds a single snapshot, but loop
        //    defensively. Each snapshot drags its combinations, items, financials and
        //    frozen rates — combination ids are remapped so cloned items stay linked.
        $bookings = $this->db->where('package_id', $package_id)->get('costing_bookings')->result_array();
        foreach ($bookings as $booking) {
            $old_booking_id = (int) $booking['id'];
            unset($booking['id'], $booking['created_at'], $booking['updated_at']);
            $booking['package_id'] = $new_package_id;
            // Never share the original's public quotation link — mint on demand.
            $booking['quotation_token'] = null;
            $this->db->insert('costing_bookings', $booking);
            $new_booking_id = (int) $this->db->insert_id();

            $combinations = $this->db->where('costing_booking_id', $old_booking_id)->get('costing_combinations')->result_array();
            $combination_map = array();
            foreach ($combinations as $combination) {
                $old_combination_id = (int) $combination['id'];
                unset($combination['id'], $combination['created_at'], $combination['updated_at']);
                $combination['costing_booking_id'] = $new_booking_id;
                $this->db->insert('costing_combinations', $combination);
                $combination_map[$old_combination_id] = (int) $this->db->insert_id();
            }

            $items = $this->db->where('booking_id', $old_booking_id)->get('costing_booking_items')->result_array();
            foreach ($items as $item) {
                unset($item['id'], $item['created_at'], $item['updated_at']);
                $item['booking_id'] = $new_booking_id;
                $old_combination_id = (int) $item['combination_id'];
                $item['combination_id'] = ($old_combination_id > 0 && isset($combination_map[$old_combination_id]))
                    ? $combination_map[$old_combination_id]
                    : null;
                // package_item_id points at the cloned item master only loosely; the
                // snapshot item already carries its own frozen name/price, so a stale
                // reference is harmless. Null it to avoid pointing at the original.
                $item['package_item_id'] = null;
                $this->db->insert('costing_booking_items', $item);
            }

            $this->Clone_Rows('costing_booking_financials', array('booking_id' => $old_booking_id), array('booking_id' => $new_booking_id));
            $this->Clone_Rows('costing_snapshot_rates', array('costing_booking_id' => $old_booking_id), array('costing_booking_id' => $new_booking_id));
        }

        $this->db->trans_complete();

        return $this->db->trans_status() ? $new_package_id : 0;
    }

    /**
     * Copy every row matching $where from $table into the same table, stripping the
     * identity/timestamp columns and applying $overrides (the remapped foreign
     * keys). Used by Duplicate_Package for the straight parent→child copies.
     */
    private function Clone_Rows($table, $where, $overrides)
    {
        $rows = $this->db->where($where)->get($table)->result_array();
        foreach ($rows as $row) {
            unset($row['id'], $row['created_at'], $row['updated_at']);
            foreach ($overrides as $column => $value) {
                $row[$column] = $value;
            }
            $this->db->insert($table, $row);
        }
    }

    public function Save_Package_Item($item)
    {
        $data = array(
            'package_id' => (int) $item['package_id'],
            'name' => trim((string) $item['name']),
            'description' => trim((string) $item['description']),
            'category' => trim((string) $item['category']),
            'cost_type' => $this->Normalize_Cost_Type($item['cost_type']),
            'default_unit_price' => round((float) $item['default_unit_price'], 2),
            'currency_id' => (int) $item['currency_id'],
        );

        if ($data['package_id'] <= 0 || $data['currency_id'] <= 0 || $data['name'] === '' || $data['category'] === '') {
            return false;
        }

        if (!empty($item['id'])) {
            return $this->db
                ->where('id', (int) $item['id'])
                ->where('package_id', $data['package_id'])
                ->update('costing_package_items', $data);
        }

        return $this->db->insert('costing_package_items', $data);
    }

    public function Delete_Package_Item($package_id, $item_id)
    {
        return $this->db
            ->where('id', (int) $item_id)
            ->where('package_id', (int) $package_id)
            ->delete('costing_package_items');
    }

    public function Generate_Booking_Snapshot($package_id, $payload)
    {
        $package_id = (int) $package_id;
        $posted_rows = isset($payload['rows']) ? (array) $payload['rows'] : array();
        $adult_count = max(0, (int) $payload['adult_count']);
        $child_count = max(0, (int) $payload['child_count']);
        $total_pax = $adult_count + $child_count;
        $travel_date = !empty($payload['travel_date']) ? $payload['travel_date'] : date('Y-m-d');
        // #2 Travel date is a start-end range. End defaults to the start when blank.
        $travel_date_end = !empty($payload['travel_date_end']) ? $payload['travel_date_end'] : $travel_date;
        $booking_status = $this->Normalize_Status($payload['status']);
        $booking_id = (int) $payload['booking_id'];

        if ($package_id <= 0 || $total_pax <= 0) {
            return 0;
        }

        // One snapshot per package: if this package already has one, reuse (update)
        // it instead of creating a second.
        if ($booking_id <= 0) {
            $booking_id = $this->Existing_Booking_Id($package_id);
        }

        // The cost step is combinations-only: internal (combination_id IS NULL)
        // rows exist solely for legacy scenarios that still post `rows`. New
        // scenarios keep every cost line inside a combination, so nothing is
        // auto-seeded from the package template here.
        $snapshot_rows = array();
        if (!empty($posted_rows)) {
            $selected_rows = array();
            foreach ($posted_rows as $row) {
                if (!empty($row['include'])) {
                    $selected_rows[] = $row;
                }
            }
            $snapshot_rows = $this->Normalize_Booking_Rows($selected_rows);
        }

        // Customer combinations (each = a named bundle of its own cost rows).
        // Normalised here so an all-empty scenario (no template rows AND no
        // combination rows) can still be rejected below.
        $combinations = $this->Prepare_Combinations(isset($payload['combinations']) ? (array) $payload['combinations'] : array());

        // Normally an all-empty scenario (no cost rows AND no combinations) is
        // rejected. The Excel importer sets allow_empty_items so a package with no
        // cost items is still saved (empty cost list, financials all zero) — only
        // the information actually given is kept.
        if (empty($snapshot_rows) && empty($combinations) && empty($payload['allow_empty_items'])) {
            return 0;
        }

        $this->db->trans_start();

        $booking_data = array(
            'package_id' => $package_id,
            'travel_date' => $travel_date,
            'travel_date_end' => $travel_date_end,
            'adult_count' => $adult_count,
            'child_count' => $child_count,
            'total_pax' => $total_pax,
            'status' => $booking_status,
        );

        if ($booking_id > 0 && $this->Read_Booking($booking_id, $package_id)) {
            $this->db->where('id', $booking_id)->update('costing_bookings', $booking_data);
        } else {
            $this->db->insert('costing_bookings', $booking_data);
            $booking_id = (int) $this->db->insert_id();
        }

        $this->Sync_Quote_Hotel_Names($package_id, $booking_id, $combinations);

        $this->db->where('booking_id', $booking_id)->delete('costing_booking_items');

        foreach ($snapshot_rows as $row) {
            if (!isset($row['bank_charges_myr'])) {
                $row['bank_charges_myr'] = $this->Resolve_Bank_Charges_Myr((int) $row['currency_id'], $travel_date);
            }
            $row['booking_id'] = $booking_id;
            $row['combination_id'] = null;
            $row['total_amount'] = round((float) $row['quantity'] * (float) $row['unit_count'] * (float) $row['unit_price'], 2);
            $this->db->insert('costing_booking_items', $row);
        }

        // Replace-all the combinations for this scenario. Its cost rows were already
        // cleared above (delete by booking_id covers combination rows too); now
        // re-create each combination and insert its rows tagged with the new id.
        $this->db->where('costing_booking_id', $booking_id)->delete('costing_combinations');
        $sort = 0;
        foreach ($combinations as $combo) {
            $this->db->insert('costing_combinations', array(
                'costing_booking_id' => $booking_id,
                'name' => $combo['name'],
                // #5 Manual per-pax selling price (NULL = use the suggested markup).
                'selling_price_per_pax' => isset($combo['selling_price_per_pax']) ? $combo['selling_price_per_pax'] : null,
                // General combination (item 6): its cost folds into every other combo.
                'is_general' => !empty($combo['is_general']) ? 1 : 0,
                // Per-combination, per-category headcount counts (JSON map or NULL).
                'headcount_json' => isset($combo['headcount_json']) ? $combo['headcount_json'] : null,
                'sort_order' => $sort,
            ));
            $combination_id = (int) $this->db->insert_id();
            foreach ($combo['rows'] as $row) {
                if (!isset($row['bank_charges_myr'])) {
                    $row['bank_charges_myr'] = $this->Resolve_Bank_Charges_Myr((int) $row['currency_id'], $travel_date);
                }
                $row['booking_id'] = $booking_id;
                $row['combination_id'] = $combination_id;
                $row['total_amount'] = round((float) $row['quantity'] * (float) $row['unit_count'] * (float) $row['unit_price'], 2);
                $this->db->insert('costing_booking_items', $row);
            }
            $sort++;
        }

        $this->Upsert_Booking_Financials($booking_id, array(
            'margin_percentage' => isset($payload['margin_percentage']) ? $payload['margin_percentage'] : 0,
            'commissionable_per_pax' => isset($payload['commissionable_per_pax']) ? $payload['commissionable_per_pax'] : 0,
            'ad_hoc_per_pax' => isset($payload['ad_hoc_per_pax']) ? $payload['ad_hoc_per_pax'] : 0,
            'selling_price_per_pax' => isset($payload['selling_price_per_pax']) ? $payload['selling_price_per_pax'] : 0,
        ));

        $this->db->trans_complete();

        if (!$this->db->trans_status()) {
            return 0;
        }

        $this->Ensure_Quotation_Token($booking_id);

        // Seed the frozen snapshot from the daily feed (or the posted rates) so a
        // brand-new scenario already has one rate row per currency to confirm.
        if (!empty($payload['snapshot'])) {
            $this->Save_Snapshot_Rates($booking_id, (array) $payload['snapshot']);
        } else {
            // Feedback 18 Sep 2026 (2.4): every save (create OR amend) re-captures the
            // LATEST exchange rate rather than re-freezing the previously saved (stale)
            // snapshot. The frozen snapshot still protects a saved quote from drifting
            // until it is next amended/recalculated — it just refreshes on that action.
            $latest = $this->Read_Currency_Rate_Map(null, 0);
            $seed = array();
            foreach ($this->Read_Snapshot_Panel($booking_id) as $panel_row) {
                $currency_id = (int) $panel_row['currency_id'];
                $seed[$currency_id] = array(
                    'rate_to_myr' => isset($latest[$currency_id]['rate_to_myr'])
                        ? $latest[$currency_id]['rate_to_myr']
                        : $panel_row['rate_to_myr'],
                    'remark'      => $panel_row['remark'],
                );
            }
            $this->Save_Snapshot_Rates($booking_id, $seed);
        }

        $this->Recalculate_Booking_Financials($booking_id);

        return $booking_id;
    }

    public function Save_Booking_Snapshot($package_id, $payload)
    {
        $package_id = (int) $package_id;
        $booking_id = (int) $payload['booking_id'];
        $adult_count = max(0, (int) $payload['adult_count']);
        $child_count = max(0, (int) $payload['child_count']);
        $total_pax = $adult_count + $child_count;
        $travel_date = !empty($payload['travel_date']) ? $payload['travel_date'] : date('Y-m-d');
        $booking_status = $this->Normalize_Status($payload['status']);

        if ($package_id <= 0 || $booking_id <= 0 || $total_pax <= 0 || !$this->Read_Booking($booking_id, $package_id)) {
            return false;
        }

        $rows = $this->Normalize_Booking_Rows(isset($payload['rows']) ? $payload['rows'] : array());

        $this->db->trans_start();

        $this->db->where('id', $booking_id)->update('costing_bookings', array(
            'travel_date' => $travel_date,
            'adult_count' => $adult_count,
            'child_count' => $child_count,
            'total_pax' => $total_pax,
            'status' => $booking_status,
        ));

        $this->db->where('booking_id', $booking_id)->delete('costing_booking_items');

        foreach ($rows as $row) {
            if (!isset($row['bank_charges_myr'])) {
                $row['bank_charges_myr'] = $this->Resolve_Bank_Charges_Myr((int) $row['currency_id'], $travel_date);
            }
            $row['booking_id'] = $booking_id;
            $row['total_amount'] = round($row['quantity'] * $row['unit_count'] * $row['unit_price'], 2);
            $this->db->insert('costing_booking_items', $row);
        }

        $this->Upsert_Booking_Financials($booking_id, array(
            'margin_percentage' => $payload['margin_percentage'],
            'commissionable_per_pax' => $payload['commissionable_per_pax'],
            'ad_hoc_per_pax' => $payload['ad_hoc_per_pax'],
            'selling_price_per_pax' => 0,
        ));

        // Freeze the confirmed currency snapshot (rates + remarks) for this scenario.
        $this->Save_Snapshot_Rates($booking_id, isset($payload['snapshot']) ? (array) $payload['snapshot'] : array());

        $this->db->trans_complete();

        if (!$this->db->trans_status()) {
            return false;
        }

        $this->Ensure_Quotation_Token($booking_id);
        $this->Recalculate_Booking_Financials($booking_id);

        return true;
    }

    public function Delete_Booking_Snapshot($package_id, $booking_id)
    {
        $package_id = (int) $package_id;
        $booking_id = (int) $booking_id;

        if ($package_id <= 0 || $booking_id <= 0 || !$this->Read_Booking($booking_id, $package_id)) {
            return false;
        }

        return (bool) $this->db
            ->where('id', $booking_id)
            ->where('package_id', $package_id)
            ->delete('costing_bookings');
    }

    public function Save_Currency($currency)
    {
        $data = array(
            'code' => strtoupper(trim((string) $currency['code'])),
            'name' => trim((string) $currency['name']),
            'symbol' => $currency['symbol'],
        );

        if (!empty($currency['id'])) {
            return $this->db
                ->where('id', (int) $currency['id'])
                ->update('costing_currencies', $data);
        }

        return $this->db->insert('costing_currencies', $data);
    }

    public function Delete_Currency($currency_id)
    {
        $currency_id = (int) $currency_id;
        if ($currency_id <= 0) {
            return array('success' => false, 'message' => 'Invalid currency selected.');
        }

        $usage_count = 0;
        $usage_count += (int) $this->db->where('currency_id', $currency_id)->count_all_results('costing_package_items');
        $usage_count += (int) $this->db->where('currency_id', $currency_id)->count_all_results('costing_booking_items');
        $usage_count += (int) $this->db->group_start()
            ->where('from_currency_id', $currency_id)
            ->or_where('to_currency_id', $currency_id)
            ->group_end()
            ->count_all_results('costing_exchange_rates');

        if ($usage_count > 0) {
            return array('success' => false, 'message' => 'This currency is already used in costing records and cannot be deleted.');
        }

        return $this->Run_Safe_Delete('costing_currencies', 'id', $currency_id, 'Unable to delete currency.');
    }

    public function Save_Exchange_Rate($exchange_rate)
    {
        if ((int) $exchange_rate['from_currency_id'] === (int) $exchange_rate['to_currency_id']) {
            return false;
        }

        $unit_amount = round((float) $exchange_rate['unit_amount'], 8);
        $converted_amount = round((float) $exchange_rate['converted_amount'], 8);
        $bank_charges_myr = round((float) (isset($exchange_rate['bank_charges_myr']) ? $exchange_rate['bank_charges_myr'] : 0), 2);
        // Additive safety margin on the rate (MYR per 1 foreign unit); never negative.
        $buffer = round(max(0, (float) (isset($exchange_rate['buffer']) ? $exchange_rate['buffer'] : 0)), 8);
        if ($unit_amount <= 0 || $converted_amount <= 0) {
            return false;
        }

        $data = array(
            'from_currency_id' => (int) $exchange_rate['from_currency_id'],
            'to_currency_id' => (int) $exchange_rate['to_currency_id'],
            'unit_amount' => $unit_amount,
            'rate' => round($converted_amount / $unit_amount, 8),
            'buffer' => $buffer,
            'bank_charges_myr' => max(0, $bank_charges_myr),
            'updated_by_admin_id' => !empty($exchange_rate['updated_by_admin_id']) ? (int) $exchange_rate['updated_by_admin_id'] : null,
            'valid_from' => $exchange_rate['valid_from'],
        );

        if (!empty($exchange_rate['id'])) {
            return $this->db
                ->where('id', (int) $exchange_rate['id'])
                ->update('costing_exchange_rates', $data);
        }

        return $this->db->insert('costing_exchange_rates', $data);
    }

    /**
     * Auto-update the foreign -> MYR rates on /Costing/Currency from a MYR-based
     * API rates map (the nightly Cron::fetchExchangeRates feed). One row per
     * currency per day; a currency that already has a rate dated $rate_date is
     * left untouched (manual same-day override wins), and bank charges are
     * carried forward. Planning is pure (currency_rate_costing_updates); this
     * only reads current state and writes the resulting rows.
     *
     * @param array  $rates_map  [ 'USD' => 0.2234, ... ] MYR->foreign, positive
     * @param string $rate_date  Y-m-d the rates are dated
     * @return array { updated: string[], skipped: string[] }
     */
    public function Auto_Update_Rates_From_Feed($rates_map, $rate_date, $base_currency_code = 'MYR')
    {
        $this->load->helper('currency_rate');

        $currencies = $this->Read_Currencies();

        // Latest FOREIGN -> MYR row per pair, so we can honour a same-day manual
        // rate and carry its bank charges forward.
        $base = $this->Read_Base_Currency($base_currency_code);
        $existing_by_code = array();
        if ($base && !empty($base['id'])) {
            $latest = $this->Read_Latest_Exchange_Rates(array('to_currency_id' => (int) $base['id']));
            foreach ($latest as $row) {
                $existing_by_code[strtoupper($row['from_currency_code'])] = array(
                    'valid_from'       => $row['valid_from'],
                    'bank_charges_myr' => $row['bank_charges_myr'],
                    'buffer'           => isset($row['buffer']) ? $row['buffer'] : 0,
                );
            }
        }

        $plan = currency_rate_costing_updates($rates_map, $currencies, $base_currency_code, $existing_by_code, $rate_date);

        foreach ($plan['updates'] as $payload) {
            $this->Save_Exchange_Rate($payload);
        }

        return array('updated' => $plan['updated'], 'skipped' => $plan['skipped']);
    }

    public function Delete_Exchange_Rate($exchange_rate_id)
    {
        $exchange_rate_id = (int) $exchange_rate_id;
        if ($exchange_rate_id <= 0) {
            return array('success' => false, 'message' => 'Invalid exchange rate selected.');
        }

        return $this->Run_Safe_Delete('costing_exchange_rates', 'id', $exchange_rate_id, 'Unable to delete exchange rate.');
    }

    public function Recalculate_Booking_Financials($booking_id, $base_currency_code = 'MYR')
    {
        $booking = $this->Read_Booking((int) $booking_id);
        if (!$booking) {
            return false;
        }

        $base_currency = $this->Read_Base_Currency($base_currency_code);
        $base_currency_id = $base_currency ? (int) $base_currency['id'] : 0;
        $booking_items = $this->Read_Cost_Rows_For_Financials((int) $booking_id, $base_currency_id, $booking['travel_date']);
        $financials = $this->Calculate_Financials_From_Booking($booking, $booking_items, $base_currency_id);

        return $this->db
            ->where('booking_id', (int) $booking_id)
            ->update('costing_booking_financials', array(
                'total_cost' => $financials['total_cost'],
                'cost_per_pax' => $financials['cost_per_pax'],
                'markup_amount_total' => $financials['markup_amount_total'],
                'price_per_pax' => $financials['price_per_pax'],
                'total_per_pax' => $financials['total_per_pax'],
                'selling_price_per_pax' => $financials['selling_price_per_pax'],
                'total_revenue' => $financials['total_revenue'],
                'gross_profit' => $financials['gross_profit'],
                'total_profit' => $financials['total_profit'],
            ));
    }

    /**
     * currency_id => rate_to_myr for a scenario's frozen snapshot.
     */
    public function Read_Snapshot_Rate_Map($booking_id)
    {
        $rows = $this->db
            ->select('currency_id, rate_to_myr')
            ->where('costing_booking_id', (int) $booking_id)
            ->get('costing_snapshot_rates')
            ->result_array();

        $map = array();
        foreach ($rows as $row) {
            $map[(int) $row['currency_id']] = (float) $row['rate_to_myr'];
        }
        return $map;
    }

    /**
     * The currency snapshot panel for a scenario: one row per distinct currency
     * used by its cost rows, prefilled from any saved snapshot, else the latest
     * foreign->MYR rate maintained on the Costing Currency page
     * (costing_exchange_rates), else blank for manual entry. MYR is always 1.
     *
     * @return array list of currency_id, currency_code, rate_to_myr, remark, has_saved
     */
    public function Read_Snapshot_Panel($booking_id)
    {
        $this->load->helper('costing_calc');

        $booking = $this->db->select('travel_date')->where('id', (int) $booking_id)->get('costing_bookings')->row_array();
        $travel_date = $booking ? $booking['travel_date'] : null;
        $base_currency = $this->Read_Base_Currency('MYR');
        $base_currency_id = $base_currency ? (int) $base_currency['id'] : 0;

        $currencies = $this->db
            ->select('DISTINCT costing_booking_items.currency_id AS currency_id, costing_currencies.code AS code', false)
            ->join('costing_currencies', 'costing_currencies.id = costing_booking_items.currency_id')
            ->where('costing_booking_items.booking_id', (int) $booking_id)
            ->order_by('costing_currencies.code', 'ASC')
            ->get('costing_booking_items')
            ->result_array();

        $saved = $this->db
            ->where('costing_booking_id', (int) $booking_id)
            ->get('costing_snapshot_rates')
            ->result_array();
        $saved_by_id = array();
        foreach ($saved as $row) {
            $saved_by_id[(int) $row['currency_id']] = $row;
        }

        $panel = array();
        foreach ($currencies as $currency) {
            $currency_id = (int) $currency['currency_id'];
            $code = strtoupper((string) $currency['code']);
            if (isset($saved_by_id[$currency_id])) {
                $rate = (float) $saved_by_id[$currency_id]['rate_to_myr'];
                $remark = (string) $saved_by_id[$currency_id]['remark'];
                $has_saved = true;
            } else {
                // Prefill from the Costing Currency page's latest foreign->MYR rate.
                $rate = $code === 'MYR' ? 1.0 : $this->Resolve_Exchange_Rate($currency_id, $base_currency_id, $travel_date);
                $remark = '';
                $has_saved = false;
            }
            $panel[] = array(
                'currency_id'   => $currency_id,
                'currency_code' => $code,
                'rate_to_myr'   => costing_normalize_rate($code, $rate),
                'remark'        => $remark,
                'has_saved'     => $has_saved,
            );
        }

        return $panel;
    }

    /**
     * Freeze the posted snapshot rates (+ remarks) for a scenario. Replace-all.
     * MYR is forced to 1.0 and non-positive rates are stored as 0 (manual entry).
     *
     * @param int   $booking_id
     * @param array $posted list of currency_id => [rate_to_myr, remark]
     */
    public function Save_Snapshot_Rates($booking_id, $posted)
    {
        $this->load->helper('costing_calc');
        $booking_id = (int) $booking_id;

        $this->db->where('costing_booking_id', $booking_id)->delete('costing_snapshot_rates');

        if (empty($posted) || !is_array($posted)) {
            return;
        }

        foreach ($posted as $currency_id => $data) {
            $currency_id = (int) $currency_id;
            if ($currency_id <= 0) {
                continue;
            }
            $currency = $this->db->select('code')->where('id', $currency_id)->get('costing_currencies')->row_array();
            if (!$currency) {
                continue;
            }
            $code = strtoupper((string) $currency['code']);
            $this->db->insert('costing_snapshot_rates', array(
                'costing_booking_id' => $booking_id,
                'currency_id'        => $currency_id,
                'currency_code'      => $code,
                'rate_to_myr'        => costing_normalize_rate($code, isset($data['rate_to_myr']) ? $data['rate_to_myr'] : 0),
                'remark'             => isset($data['remark']) ? trim((string) $data['remark']) : null,
            ));
        }
    }

    /**
     * Per-day itinerary for a package (drives the Quotation PDF).
     */
    public function Read_Itinerary_Days($package_id)
    {
        return $this->db
            ->where('package_id', (int) $package_id)
            ->order_by('day_number', 'ASC')
            ->order_by('id', 'ASC')
            ->get('costing_itinerary_days')
            ->result_array();
    }

    /**
     * Replace-all save of a package's itinerary. Each DAY row carries a
     * plain-text title plus two rich-text (TinyMCE) HTML blocks — description and
     * meal plan. Include, Exclude, Important Notes and Terms & Conditions apply to
     * the whole itinerary and are stored once on costing_packages via $level_fields.
     *
     * @param array  $rows         list of [day_number, title, description, meal_plan]
     * @param array  $level_fields posted [includes, excludes, important_notes, terms_and_conditions]
     * @param ?string $tour_intro  standalone Tour Intro header HTML; null = leave column untouched
     */
    public function Save_Itinerary_Days($package_id, $rows, $level_fields = array(), $tour_intro = null)
    {
        $this->load->helper('costing_itinerary');
        $package_id = (int) $package_id;
        if ($package_id <= 0) {
            return false;
        }

        // The itinerary rows FK back to costing_packages (ON DELETE CASCADE). If the
        // parent package has gone — e.g. deleted in another tab while this wizard page
        // stayed open — the delete/insert below would blow up on a 1452 FK violation
        // and dump a raw DB error page. Bail gracefully so the controller flashes
        // "Unable to save itinerary." instead.
        if (!$this->db->where('id', $package_id)->count_all_results('costing_packages')) {
            return false;
        }

        $this->db->trans_start();
        $this->db->where('package_id', $package_id)->delete('costing_itinerary_days');

        $day = 1;
        foreach ((array) $rows as $row) {
            $prepared = costing_itinerary_prepare_row($row);
            if ($prepared['is_empty']) {
                continue;
            }
            unset($prepared['is_empty']);
            $prepared['package_id'] = $package_id;
            $prepared['day_number'] = $prepared['day_number'] > 0 ? $prepared['day_number'] : $day;
            $this->db->insert('costing_itinerary_days', $prepared);
            $day++;
        }

        // Itinerary-wide notes/special remark/terms live on the package itself.
        $package_update = costing_itinerary_prepare_level($level_fields);
        // Standalone Tour Intro header — fold into the same update, nulling empty
        // TinyMCE markup (matches prepare_level's blank handling). Only touched when
        // a value is passed, so callers that omit it leave the column untouched.
        if ($tour_intro !== null) {
            $intro = trim((string) $tour_intro);
            if ($intro !== '' && costing_itinerary_html_is_blank($intro)) {
                $intro = '';
            }
            $package_update['itinerary_tour_intro'] = $intro !== '' ? $intro : null;
        }
        $this->db->where('id', $package_id)
            ->update('costing_packages', $package_update);

        $this->db->trans_complete();
        return (bool) $this->db->trans_status();
    }

    /**
     * The per-package hotel pricing rows shown on the Quotation PDF.
     */
    public function Read_Quote_Hotels($package_id)
    {
        return $this->db
            ->where('package_id', (int) $package_id)
            ->order_by('sort_order', 'ASC')
            ->order_by('id', 'ASC')
            ->get('costing_quote_hotels')
            ->result_array();
    }

    private function Sync_Quote_Hotel_Names($package_id, $booking_id, $combinations)
    {
        $submitted_names = array();
        foreach ($combinations as $combo) {
            if ($combo['id'] > 0 && !$combo['is_general']) {
                $submitted_names[$combo['id']] = $combo['name'];
            }
        }
        if (!$submitted_names) {
            return;
        }

        // Pull from Costing copies the name without a source ID. Only rename
        // hotel cells that still exactly match an unambiguous old option name.
        $previous = $this->db->select('id, name')
            ->where('costing_booking_id', $booking_id)
            ->where('is_general', 0)
            ->get('costing_combinations')
            ->result_array();
        $name_counts = array_count_values(array_column($previous, 'name'));
        $renames = array();
        foreach ($previous as $combo) {
            $id = $combo['id'];
            $name = $combo['name'];
            if (isset($submitted_names[$id]) && $name_counts[$name] === 1 && $submitted_names[$id] !== $name) {
                $renames[$name] = $submitted_names[$id];
            }
        }
        if (!$renames) {
            return;
        }

        foreach ($this->Read_Quote_Hotels($package_id) as $hotel) {
            $name = (string) $hotel['hotel_name'];
            if (isset($renames[$name])) {
                $this->db->where('package_id', $package_id)
                    ->where('id', $hotel['id'])
                    ->where('hotel_name', $name)
                    ->update('costing_quote_hotels', array('hotel_name' => $renames[$name]));
            }
        }
    }

    /**
     * The per-package flight schedule rows shown on the Quotation PDF.
     */
    public function Read_Quote_Flights($package_id)
    {
        return $this->db
            ->where('package_id', (int) $package_id)
            ->order_by('sort_order', 'ASC')
            ->order_by('id', 'ASC')
            ->get('costing_quote_flights')
            ->result_array();
    }

    /**
     * Flight OPTIONS for a package (feedback 18 Sep 2026, item 3 + 4.4 — multiple
     * airline options compared in one quotation). Each option carries its meta
     * (title/airline/price/fare) and its own nested schedule rows ('flights').
     *
     * @return array list of option rows, each with a 'flights' array
     */
    public function Read_Quote_Flight_Options($package_id)
    {
        $package_id = (int) $package_id;
        $options = $this->db
            ->where('package_id', $package_id)
            ->order_by('sort_order', 'ASC')
            ->order_by('id', 'ASC')
            ->get('costing_quote_flight_options')
            ->result_array();

        if (empty($options)) {
            return array();
        }

        $flights = $this->db
            ->where('package_id', $package_id)
            ->order_by('sort_order', 'ASC')
            ->order_by('id', 'ASC')
            ->get('costing_quote_flights')
            ->result_array();

        $by_option = array();
        foreach ($flights as $flight) {
            $by_option[(int) $flight['option_id']][] = $flight;
        }

        foreach ($options as &$option) {
            $oid = (int) $option['id'];
            $option['flights'] = isset($by_option[$oid]) ? $by_option[$oid] : array();
        }
        unset($option);

        return $options;
    }

    /**
     * Replace-all save of a package's hotel + flight quote tables plus the
     * quote-level free-text fields (pricing basis, notes, flight price/expiry).
     * Mirrors Save_Itinerary_Days: wipe the package's child rows, re-insert the
     * non-empty ones in posted order, then update the scalar fields on the package.
     *
     * @param int   $package_id
     * @param array $hotels         list of [hotel_name, prices[], single_supp_price]
     * @param array $flight_options list of options, each [title, airline, price,
     *                              fare_includes, fare_expiry, rows[]] (18 Sep 2026)
     * @param array $level_fields   posted quote-level fields (8 legacy + flight mode
     *                              + hotel columns; see costing_quote_prepare_level /
     *                              costing_quote_prepare_extra_level)
     * @return bool
     */
    public function Save_Quote_Details($package_id, $hotels, $flight_options, $level_fields = array())
    {
        $this->load->helper('costing_quote');
        $package_id = (int) $package_id;
        if ($package_id <= 0) {
            return false;
        }

        // Hotel pricing columns drive how many per-column prices each hotel row keeps.
        $columns = costing_quote_hotel_columns_normalize(
            isset($level_fields['quote_hotel_columns']) ? $level_fields['quote_hotel_columns'] : null
        );
        $col_count = count($columns);

        $this->db->trans_start();

        // ---- Hotels (per-column prices) -------------------------------------
        $this->db->where('package_id', $package_id)->delete('costing_quote_hotels');
        $sort = 0;
        foreach ((array) $hotels as $row) {
            $prepared = costing_quote_hotel_prepare_row($row, $col_count);
            if ($prepared['is_empty']) {
                continue;
            }
            unset($prepared['is_empty']);
            $prepared['package_id'] = $package_id;
            $prepared['sort_order'] = $sort++;
            $this->db->insert('costing_quote_hotels', $prepared);
        }

        // ---- Flight options + their schedule rows (replace-all) -------------
        $this->db->where('package_id', $package_id)->delete('costing_quote_flights');
        $this->db->where('package_id', $package_id)->delete('costing_quote_flight_options');
        $opt_sort = 0;
        $row_sort = 0;
        foreach ((array) $flight_options as $option) {
            $option = (array) $option;
            $prepared_opt = costing_quote_flight_option_prepare($option);

            $prepared_rows = array();
            foreach ((array) (isset($option['rows']) ? $option['rows'] : array()) as $row) {
                $prow = costing_quote_flight_prepare_row($row);
                if ($prow['is_empty']) {
                    continue;
                }
                unset($prow['is_empty']);
                $prepared_rows[] = $prow;
            }

            // Skip an option only when it has neither meta nor a single schedule row.
            if ($prepared_opt['is_empty'] && empty($prepared_rows)) {
                continue;
            }
            unset($prepared_opt['is_empty']);
            $prepared_opt['package_id'] = $package_id;
            $prepared_opt['sort_order'] = $opt_sort++;
            $this->db->insert('costing_quote_flight_options', $prepared_opt);
            $option_id = (int) $this->db->insert_id();

            foreach ($prepared_rows as $prow) {
                $prow['package_id'] = $package_id;
                $prow['option_id']  = $option_id;
                $prow['sort_order'] = $row_sort++;
                $this->db->insert('costing_quote_flights', $prow);
            }
        }

        // ---- Package-level fields (8 legacy + flight mode + hotel columns) --
        $update = array_merge(
            costing_quote_prepare_level($level_fields),
            costing_quote_prepare_extra_level($level_fields)
        );
        $this->db->where('id', $package_id)->update('costing_packages', $update);

        $this->db->trans_complete();
        return (bool) $this->db->trans_status();
    }

    /**
     * Ensure a scenario has a shareable quotation token; return it.
     */
    public function Ensure_Quotation_Token($booking_id)
    {
        $booking_id = (int) $booking_id;
        $row = $this->db->select('quotation_token')->where('id', $booking_id)->get('costing_bookings')->row_array();
        if ($row && !empty($row['quotation_token'])) {
            return $row['quotation_token'];
        }
        $token = md5(uniqid((string) $booking_id, true));
        $this->db->where('id', $booking_id)->update('costing_bookings', array('quotation_token' => $token));
        return $token;
    }

    /**
     * Quotation data for a package (its single snapshot). One package = one
     * snapshot, so the quotation is addressed per package. Ensures the token
     * exists so old token-based links keep working too.
     */
    public function Read_Quotation_By_Package($package_id)
    {
        $booking_id = $this->Existing_Booking_Id($package_id);
        if ($booking_id <= 0) {
            return null;
        }
        $token = $this->Ensure_Quotation_Token($booking_id);
        return $this->Read_Quotation_By_Token($token);
    }

    /**
     * Read the full data needed to render a Quotation PDF, by scenario token.
     * Customer-facing: package + itinerary + selling total only.
     */
    public function Read_Quotation_By_Token($token)
    {
        $this->load->helper('costing_itinerary');
        $token = trim((string) $token);
        if ($token === '') {
            return null;
        }

        $booking = $this->db
            ->select('cb.*, cp.name AS package_name, cp.status AS package_status, cp.tour_code, cp.customer_name, cp.description AS package_description, cp.sales_admin_id, cp.sales_admin_mobile AS package_sales_mobile, cp.validity_date, sales_admin.Name AS sales_admin_name, sales_admin.Mobile AS sales_admin_mobile, sales_cc.CountryCode AS sales_admin_country_code, cp.duration_days, cp.duration_nights, cp.itinerary_notes, cp.itinerary_includes, cp.itinerary_excludes, cp.itinerary_important_notes, cp.itinerary_terms_and_conditions, cp.itinerary_tour_intro, cp.quote_pricing_basis, cp.quote_travel_date_note, cp.quote_hotel_note, cp.quote_flight_title, cp.quote_flight_price, cp.quote_flight_fare_note, cp.quote_flight_expiry, cp.quote_footer_notes, cp.quote_flight_mode, cp.quote_hotel_columns, cp.quote_hotel_title_label, cp.quote_show_single_supp')
            ->from('costing_bookings cb')
            ->join('costing_packages cp', 'cp.id = cb.package_id')
            ->join('admin sales_admin', 'sales_admin.AdminID = cp.sales_admin_id', 'left')
            ->join('country_code sales_cc', 'sales_cc.CountryCodeID = sales_admin.CountryCodeID', 'left')
            ->where('cb.quotation_token', $token)
            ->get()
            ->row_array();

        if (!$booking) {
            return null;
        }

        // Access gate: an inactive package's quotation is no longer served (the
        // public URL 404s). Covers both the token path and the legacy ?token= path.
        $this->load->helper('costing_quotation_access');
        if (!costing_quotation_status_accessible(isset($booking['package_status']) ? $booking['package_status'] : '')) {
            return null;
        }

        $base_currency = $this->Read_Base_Currency('MYR');
        $base_currency_id = $base_currency ? (int) $base_currency['id'] : 0;
        $booking_items = $this->Read_Booking_Items((int) $booking['id'], $base_currency_id, $booking['travel_date']);
        $financial_rows = $this->Read_Cost_Rows_For_Financials((int) $booking['id'], $base_currency_id, $booking['travel_date']);
        $financials = $this->Calculate_Financials_From_Booking($booking, $financial_rows, $base_currency_id);

        // Customer-facing line items: name + selling price (MYR cost incl. bank
        // charges, marked up by the same margin). Raw cost/margin/profit stay hidden.
        // Kept as a fallback for legacy scenarios that have no combinations yet.
        $this->load->helper('costing_calc');
        $margin = max(0, (float) $financials['margin_percentage']);
        $items = array();
        foreach ($booking_items as $item) {
            $items[] = array(
                'name'    => $item['name'],
                'selling' => costing_cost_after_markup((float) $item['base_total'], $margin),
            );
        }

        // Customer combinations: each is an alternative package option — its name +
        // item names + its own selling price. The customer picks ONE, so prices are
        // NOT summed. When present, the PDF shows these options instead of the
        // internal item list.
        // Fold the General Combination's cost into every combination (item 6) — its
        // common items apply to all and it is never shown as its own option.
        $folded_combos = costing_fold_general_combination(
            $this->Read_Combinations((int) $booking['id'], $base_currency_id, $booking['travel_date']),
            $this->No_Multiply_Codes()
        );
        $combo_input = array();
        foreach ($folded_combos as $combo) {
            $names = array();
            foreach ($combo['items'] as $combo_item) {
                $names[] = $combo_item['name'];
            }
            $combo_input[] = array(
                'name'                  => $combo['name'],
                'item_names'            => $names,
                'cost_myr'              => $combo['cost_myr'],
                'flat_cost_myr'         => isset($combo['flat_cost_myr']) ? $combo['flat_cost_myr'] : 0,
                'selling_price_per_pax' => isset($combo['selling_price_per_pax']) ? $combo['selling_price_per_pax'] : null,
            );
        }
        $combo_summary = costing_combination_summary($combo_input, $margin, (int) $booking['total_pax']);
        $has_combinations = !empty($combo_summary['combinations']);

        return array(
            'booking'    => $booking,
            'package'    => array(
                'name'            => $booking['package_name'],
                'tour_code'       => isset($booking['tour_code']) ? $booking['tour_code'] : '',
                'customer_name'   => isset($booking['customer_name']) ? (string) $booking['customer_name'] : '',
                // Step 1 free-text description, shown under the Pricing per person section.
                'description'     => isset($booking['package_description']) ? (string) $booking['package_description'] : '',
                'sales_person'    => isset($booking['sales_admin_name']) ? (string) $booking['sales_admin_name'] : '',
                // Sales person's contact shown beside the name on the quotation so
                // the customer can reach them directly. The per-package override
                // (Step 1) wins when set; otherwise fall back to the admin record's
                // country code + mobile.
                'sales_person_contact' => trim((string) $booking['package_sales_mobile']) !== ''
                    ? trim((string) $booking['package_sales_mobile'])
                    : trim(
                        (isset($booking['sales_admin_country_code']) ? (string) $booking['sales_admin_country_code'] : '')
                        . (isset($booking['sales_admin_mobile']) ? (string) $booking['sales_admin_mobile'] : '')
                    ),
                // Quotation "valid until" date (Y-m-d or '' when not set).
                'validity_date'   => isset($booking['validity_date']) ? (string) $booking['validity_date'] : '',
                'duration_days'   => (int) $booking['duration_days'],
                'duration_nights' => (int) $booking['duration_nights'],
            ),
            'itinerary'     => $this->Read_Itinerary_Days((int) $booking['package_id']),
            'itinerary_meta' => costing_itinerary_sections_from_row($booking),
            // Standalone Tour Intro header (separate from the four sections above).
            'itinerary_tour_intro' => isset($booking['itinerary_tour_intro']) ? (string) $booking['itinerary_tour_intro'] : '',
            // Hotel-pricing + flight-schedule tables and quote-level free-text —
            // the customer Quotation PDF's primary content (screenshot layout).
            'quote_hotels'  => $this->Read_Quote_Hotels((int) $booking['package_id']),
            'quote_flights' => $this->Read_Quote_Flights((int) $booking['package_id']),
            'quote_flight_options' => $this->Read_Quote_Flight_Options((int) $booking['package_id']),
            'quote_meta'    => array(
                'quote_pricing_basis'    => isset($booking['quote_pricing_basis']) ? $booking['quote_pricing_basis'] : '',
                'quote_travel_date_note' => isset($booking['quote_travel_date_note']) ? $booking['quote_travel_date_note'] : '',
                'quote_hotel_note'       => isset($booking['quote_hotel_note']) ? $booking['quote_hotel_note'] : '',
                'quote_flight_title'     => isset($booking['quote_flight_title']) ? $booking['quote_flight_title'] : '',
                'quote_flight_price'     => isset($booking['quote_flight_price']) ? $booking['quote_flight_price'] : null,
                'quote_flight_fare_note' => isset($booking['quote_flight_fare_note']) ? $booking['quote_flight_fare_note'] : '',
                'quote_flight_expiry'    => isset($booking['quote_flight_expiry']) ? $booking['quote_flight_expiry'] : '',
                // Keep NULL distinct from '' so the PDF can tell "never saved"
                // (→ default boilerplate) from "deliberately cleared" (no footer).
                'quote_footer_notes'     => array_key_exists('quote_footer_notes', $booking) ? $booking['quote_footer_notes'] : null,
                'quote_flight_mode'      => isset($booking['quote_flight_mode']) ? $booking['quote_flight_mode'] : '',
                'quote_hotel_columns'    => isset($booking['quote_hotel_columns']) ? $booking['quote_hotel_columns'] : '',
                'quote_hotel_title_label' => isset($booking['quote_hotel_title_label']) ? $booking['quote_hotel_title_label'] : '',
                'quote_show_single_supp'  => isset($booking['quote_show_single_supp']) ? $booking['quote_show_single_supp'] : '',
            ),
            'items'         => $items,
            'combinations'  => $combo_summary['combinations'],
            // Only used by the legacy flat item-list footer (no combinations). With
            // combination options each is priced on its own and never summed, so the
            // PDF ignores this value when combinations exist.
            'total_selling' => $has_combinations ? $combo_summary['total_selling'] : (float) $financials['total_revenue'],
            'financials'    => $financials,
        );
    }

    private function Read_Base_Currency($base_currency_code)
    {
        $this->db->select('id, code');
        $this->db->where('code', strtoupper($base_currency_code));
        $currency = $this->db->get('costing_currencies')->row_array();

        if ($currency) {
            return $currency;
        }

        return $this->db->select('id, code')->order_by('id', 'ASC')->get('costing_currencies')->row_array();
    }

    private function Read_Dashboard_Package_Items($package_ids)
    {
        if (empty($package_ids)) {
            return array();
        }

        $rows = $this->db
            ->select('
                costing_package_items.id,
                costing_package_items.package_id,
                costing_package_items.name,
                costing_package_items.description,
                costing_package_items.category,
                costing_package_items.cost_type,
                costing_package_items.default_unit_price,
                costing_currencies.code AS currency
            ')
            ->join('costing_currencies', 'costing_currencies.id = costing_package_items.currency_id')
            ->where_in('costing_package_items.package_id', $package_ids)
            ->order_by('costing_package_items.package_id', 'ASC')
            ->order_by('costing_package_items.id', 'ASC')
            ->get('costing_package_items')
            ->result_array();

        $grouped = array();
        foreach ($rows as $row) {
            $package_id = (int) $row['package_id'];
            if (!isset($grouped[$package_id])) {
                $grouped[$package_id] = array();
            }
            $grouped[$package_id][] = $row;
        }

        return $grouped;
    }

    private function Read_Dashboard_Package_Bookings($package_ids)
    {
        if (empty($package_ids)) {
            return array();
        }

        $rows = $this->db
            ->select('
                costing_bookings.id,
                costing_bookings.package_id,
                costing_bookings.travel_date,
                costing_bookings.adult_count,
                costing_bookings.child_count,
                costing_bookings.total_pax,
                costing_bookings.status,
                costing_booking_financials.margin_percentage,
                costing_booking_financials.selling_price_per_pax,
                costing_booking_financials.total_revenue,
                costing_booking_financials.total_cost,
                costing_booking_financials.total_profit
            ')
            ->join('costing_booking_financials', 'costing_booking_financials.booking_id = costing_bookings.id', 'left')
            ->where_in('costing_bookings.package_id', $package_ids)
            ->order_by('costing_bookings.package_id', 'ASC')
            ->order_by('costing_bookings.id', 'DESC')
            ->get('costing_bookings')
            ->result_array();

        $grouped = array();
        foreach ($rows as $row) {
            $package_id = (int) $row['package_id'];
            $row['booking_number'] = $this->Build_Booking_Number($row);
            $row['status_label'] = ucfirst((string) $row['status']);
            $row['adult_count'] = (int) $row['adult_count'];
            $row['child_count'] = (int) $row['child_count'];
            $row['total_pax'] = (int) $row['total_pax'];
            $row['margin_percentage'] = round((float) $row['margin_percentage'], 2);
            $row['selling_price_per_pax'] = round((float) $row['selling_price_per_pax'], 2);
            $row['total_revenue'] = round((float) $row['total_revenue'], 2);
            $row['total_cost'] = round((float) $row['total_cost'], 2);
            $row['total_profit'] = round((float) $row['total_profit'], 2);

            if (!isset($grouped[$package_id])) {
                $grouped[$package_id] = array();
            }
            $grouped[$package_id][] = $row;
        }

        return $grouped;
    }

    private function Read_Package($package_id)
    {
        // Join the current OP name for the wizard, including legacy assignments.
        // Deep_Copy_Package strips it before re-inserting the row.
        return $this->db
            ->select('cp.*, op_admin.Name AS op_admin_name')
            ->from('costing_packages cp')
            ->join('admin op_admin', 'op_admin.AdminID = cp.op_admin_id', 'left')
            ->where('cp.id', (int) $package_id)
            ->get()
            ->row_array();
    }

    /**
     * Whether a costing package row exists. Used to validate the target before
     * accepting a supplier-quotation upload against it.
     */
    public function Package_Exists($package_id)
    {
        $package_id = (int) $package_id;
        if ($package_id <= 0) {
            return false;
        }
        return $this->db->where('id', $package_id)->count_all_results('costing_packages') > 0;
    }

    private function Read_Booking($booking_id, $package_id = null)
    {
        $this->db->select('
            costing_bookings.id,
            costing_bookings.package_id,
            costing_bookings.travel_date,
            costing_bookings.travel_date_end,
            costing_bookings.adult_count,
            costing_bookings.child_count,
            costing_bookings.total_pax,
            costing_bookings.status AS booking_status
        ');
        $this->db->where('costing_bookings.id', (int) $booking_id);

        if ($package_id !== null) {
            $this->db->where('costing_bookings.package_id', (int) $package_id);
        }

        return $this->db->get('costing_bookings')->row_array();
    }

    private function Read_Bookings_For_Package($package_id)
    {
        $rows = $this->db
            ->select('id, travel_date, total_pax, status, quotation_token')
            ->where('package_id', (int) $package_id)
            ->order_by('id', 'DESC')
            ->get('costing_bookings')
            ->result_array();

        foreach ($rows as &$row) {
            $row['booking_number'] = $this->Build_Booking_Number($row);
            $row['status_label'] = ucfirst((string) $row['status']);
        }
        unset($row);

        return $rows;
    }

    /**
     * Active global item master, with currency code + human category label,
     * for the "insert from Item Master" picker in the Cost Template modal.
     */
    public function Read_Item_Master()
    {
        $this->load->helper('costing_calc');
        $this->load->model('Costing_Category_Model');
        $labels = $this->Costing_Category_Model->Read_Category_Map();

        $multiplier_labels = costing_multiplier_types();

        $rows = $this->db
            ->select('ci.id, ci.name, ci.category, ci.multiplier_type, ci.default_currency_id, cc.code AS currency_code')
            ->from('costing_items ci')
            ->join('costing_currencies cc', 'cc.id = ci.default_currency_id', 'left')
            ->where('ci.Status', 'Y')
            ->order_by('ci.category', 'ASC')
            ->order_by('ci.name', 'ASC')
            ->get()
            ->result_array();

        foreach ($rows as &$row) {
            $key = (string) $row['category'];
            $row['category_label'] = isset($labels[$key]) ? $labels[$key] : ucwords(str_replace('_', ' ', $key));
            $row['multiplier_type'] = costing_normalize_multiplier_type(isset($row['multiplier_type']) ? $row['multiplier_type'] : 'fixed');
            $row['multiplier_label'] = isset($multiplier_labels[$row['multiplier_type']]) ? $multiplier_labels[$row['multiplier_type']] : 'Fixed';
        }
        unset($row);

        return $rows;
    }

    /**
     * The single snapshot id for a package (newest wins), or 0. A package holds
     * at most one snapshot, so this is the one to edit.
     */
    public function Existing_Booking_Id($package_id)
    {
        $row = $this->db
            ->select('id')
            ->where('package_id', (int) $package_id)
            ->order_by('id', 'DESC')
            ->limit(1)
            ->get('costing_bookings')
            ->row_array();

        return $row ? (int) $row['id'] : 0;
    }

    private function Read_Latest_Booking_For_Package($package_id)
    {
        $this->db->select('
            costing_bookings.id,
            costing_bookings.package_id,
            costing_bookings.travel_date,
            costing_bookings.travel_date_end,
            costing_bookings.adult_count,
            costing_bookings.child_count,
            costing_bookings.total_pax,
            costing_bookings.status AS booking_status
        ');
        $this->db->where('costing_bookings.package_id', (int) $package_id);
        $this->db->order_by('costing_bookings.id', 'DESC');

        return $this->db->get('costing_bookings')->row_array();
    }

    private function Read_Package_Items($package_id)
    {
        return $this->db
            ->select('
                costing_package_items.id,
                costing_package_items.package_id,
                costing_package_items.name,
                costing_package_items.description,
                costing_package_items.category,
                costing_package_items.cost_type,
                costing_package_items.default_unit_price,
                costing_package_items.currency_id,
                costing_currencies.code AS currency
            ')
            ->join('costing_currencies', 'costing_currencies.id = costing_package_items.currency_id')
            ->where('costing_package_items.package_id', (int) $package_id)
            ->order_by('costing_package_items.id', 'ASC')
            ->get('costing_package_items')
            ->result_array();
    }

    private function Read_Booking_Items($booking_id, $base_currency_id, $travel_date = null)
    {
        // Internal cost template only: rows NOT tied to a customer combination
        // (combination_id IS NULL). Combination rows are read separately so they
        // never inflate the internal cost/margin/profit summary.
        $items = $this->db
            ->select($this->Booking_Item_Select())
            ->join('costing_currencies', 'costing_currencies.id = costing_booking_items.currency_id')
            ->where('costing_booking_items.booking_id', (int) $booking_id)
            ->where('costing_booking_items.combination_id IS NULL', null, false)
            ->order_by('costing_booking_items.sort_order', 'ASC')
            ->order_by('costing_booking_items.id', 'ASC')
            ->get('costing_booking_items')
            ->result_array();

        return $this->Hydrate_Booking_Item_Rows($items, (int) $booking_id, $base_currency_id, $travel_date);
    }

    /**
     * Every cost row for the booking regardless of combination. Combinations are
     * now the sole cost source, so the internal Total Cost / Profit summary is the
     * aggregate of all combination rows (plus any legacy internal rows). Legacy
     * combination-less scenarios fall back to their internal rows unchanged.
     */
    private function Read_Cost_Rows_For_Financials($booking_id, $base_currency_id, $travel_date = null)
    {
        $items = $this->db
            ->select($this->Booking_Item_Select())
            ->join('costing_currencies', 'costing_currencies.id = costing_booking_items.currency_id')
            ->where('costing_booking_items.booking_id', (int) $booking_id)
            ->order_by('costing_booking_items.id', 'ASC')
            ->get('costing_booking_items')
            ->result_array();

        return $this->Hydrate_Booking_Item_Rows($items, (int) $booking_id, $base_currency_id, $travel_date);
    }

    /**
     * Shared SELECT list for a costing cost row (internal template or a
     * combination). Includes combination_id so callers can group.
     */
    private function Booking_Item_Select()
    {
        return '
            costing_booking_items.id,
            costing_booking_items.booking_id,
            costing_booking_items.combination_id,
            costing_booking_items.package_item_id,
            costing_booking_items.name,
            costing_booking_items.category,
            COALESCE(costing_booking_items.pax_type, "") AS pax_type,
            COALESCE(costing_booking_items.multiplier_type, "fixed") AS multiplier_type,
            costing_booking_items.quantity,
            costing_booking_items.unit_count,
            costing_booking_items.unit_price,
            costing_booking_items.currency_id,
            costing_booking_items.total_amount,
            costing_booking_items.bank_charges_myr,
            costing_booking_items.myr_per_unit AS frozen_myr_per_unit,
            COALESCE(costing_booking_items.remark, "") AS remark,
            COALESCE(costing_booking_items.supplier, "") AS supplier,
            costing_currencies.code AS currency
        ';
    }

    /**
     * Apply the frozen per-unit MYR (or live-rate fallback) to a set of raw cost
     * rows. Shared by the internal template read and the combination read so both
     * price identically. Sets exchange_rate, myr_per_unit and base_total per row.
     */
    private function Hydrate_Booking_Item_Rows($items, $booking_id, $base_currency_id, $travel_date = null)
    {
        // Frozen per-scenario snapshot wins over the live feed: a saved quotation's
        // numbers must never move. Fall back to the live rate only for currencies
        // that have no snapshot row yet (e.g. pre-snapshot legacy scenarios).
        $this->load->helper('costing_calc');
        $snapshot_map = $this->Read_Snapshot_Rate_Map((int) $booking_id);

        foreach ($items as &$item) {
            $currency_id = (int) $item['currency_id'];
            if (isset($snapshot_map[$currency_id])) {
                $item['exchange_rate'] = (float) $snapshot_map[$currency_id];
            } else {
                $exchange_rate = $this->Resolve_Exchange_Rate_Details($currency_id, $base_currency_id, $travel_date);
                $item['exchange_rate'] = (float) $exchange_rate['rate'];
            }
            $item['bank_charges_myr'] = (float) $item['bank_charges_myr'];

            // Cost template math: the frozen per-unit MYR saved with the row wins
            // (never re-pulled from the master); legacy rows with no frozen value
            // convert from the rate. base_total is a PURE conversion — the bank
            // charge is added once per currency at aggregation (costing_bank_total).
            $stored_myr = ($item['frozen_myr_per_unit'] === null) ? null : (float) $item['frozen_myr_per_unit'];
            unset($item['frozen_myr_per_unit']);
            $cost_foreign = (float) $item['unit_price'] * (float) $item['unit_count'];
            $myr = costing_row_totals($stored_myr, $cost_foreign, $item['exchange_rate'], (float) $item['quantity']);
            $item['myr_per_unit'] = $myr['myr_per_unit'];
            $item['base_total'] = $myr['total_myr'];
        }
        unset($item);

        return $items;
    }

    /**
     * A scenario's customer combinations (the bundles shown on the Quotation PDF).
     * Each combination groups its own cost items (costing_booking_items rows tagged
     * with its combination_id), priced with the same frozen/live MYR math as the
     * internal template. cost_myr = sum of the combination's item MYR totals.
     *
     * @return array list of ['id','name','cost_myr','items'=>[cost rows]]
     */
    /**
     * Combination name + effective per-pax selling price (manual override, else the
     * suggested cost-after-markup) for a booking. Powers the Hotel step's "Pull from
     * Costing" (feedback 4.1): the hotel name + twin/triple price come from each
     * combination's selling rate. Returns [ ['name','price_per_pax'], ... ].
     */
    public function Combination_Pricing_List($booking_id, $base_currency_id, $travel_date, $margin_percent, $total_pax)
    {
        $this->load->helper('costing_calc');
        // Fold the General Combination cost into every combination first (item 6);
        // each combination scales its own tour-leader cost by its No. of Tour Leaders.
        $folded = costing_fold_general_combination(
            $this->Read_Combinations((int) $booking_id, (int) $base_currency_id, $travel_date),
            $this->No_Multiply_Codes()
        );
        $combo_input = array();
        foreach ($folded as $combo) {
            $combo_input[] = array(
                'name'                  => $combo['name'],
                'item_names'            => array(),
                'cost_myr'              => $combo['cost_myr'],
                'flat_cost_myr'         => isset($combo['flat_cost_myr']) ? $combo['flat_cost_myr'] : 0,
                'selling_price_per_pax' => isset($combo['selling_price_per_pax']) ? $combo['selling_price_per_pax'] : null,
            );
        }
        $summary = costing_combination_summary($combo_input, max(0, (float) $margin_percent), (int) $total_pax);

        $out = array();
        foreach ($summary['combinations'] as $combo) {
            $out[] = array(
                'name'          => $combo['name'],
                'price_per_pax' => $combo['selling_price_per_pax'],
            );
        }
        return $out;
    }

    public function Read_Combinations($booking_id, $base_currency_id, $travel_date = null)
    {
        $booking_id = (int) $booking_id;

        $combos = $this->db
            ->select('id, name, selling_price_per_pax, is_general, headcount_json')
            ->where('costing_booking_id', $booking_id)
            ->order_by('sort_order', 'ASC')
            ->order_by('id', 'ASC')
            ->get('costing_combinations')
            ->result_array();

        if (empty($combos)) {
            return array();
        }

        $rows = $this->db
            ->select($this->Booking_Item_Select())
            ->join('costing_currencies', 'costing_currencies.id = costing_booking_items.currency_id')
            ->where('costing_booking_items.booking_id', $booking_id)
            ->where('costing_booking_items.combination_id IS NOT NULL', null, false)
            ->order_by('costing_booking_items.sort_order', 'ASC')
            ->order_by('costing_booking_items.id', 'ASC')
            ->get('costing_booking_items')
            ->result_array();

        $rows = $this->Hydrate_Booking_Item_Rows($rows, $booking_id, $base_currency_id, $travel_date);

        $rows_by_combo = array();
        foreach ($rows as $row) {
            $rows_by_combo[(int) $row['combination_id']][] = $row;
        }

        $out = array();
        foreach ($combos as $combo) {
            $combo_id = (int) $combo['id'];
            $items = isset($rows_by_combo[$combo_id]) ? $rows_by_combo[$combo_id] : array();
            $cost_myr = 0.0;
            foreach ($items as $item) {
                $cost_myr += (float) $item['base_total'];
            }
            // Bank (wire) charge once per distinct currency used by this combination.
            $cost_myr += costing_bank_total($items);
            $out[] = array(
                'id'       => $combo_id,
                'name'     => (string) $combo['name'],
                'cost_myr' => round($cost_myr, 2),
                'selling_price_per_pax' => ($combo['selling_price_per_pax'] === null || $combo['selling_price_per_pax'] === '')
                    ? null
                    : round((float) $combo['selling_price_per_pax'], 2),
                'is_general' => !empty($combo['is_general']) ? 1 : 0,
                // Per-combination, per-category headcount counts (category code =>
                // count) — scale those sections' cost in the summary + quotation PDF.
                'headcount' => costing_decode_headcount(isset($combo['headcount_json']) ? $combo['headcount_json'] : null),
                'items'    => $items,
            );
        }

        return $out;
    }

    private function Read_Currencies($filters = array())
    {
        $this->db->select('id, code, name, symbol');

        if (!empty($filters['code'])) {
            $this->db->like('code', $filters['code']);
        }

        if (!empty($filters['name'])) {
            $this->db->like('name', $filters['name']);
        }

        if ($filters && array_key_exists('symbol', $filters) && $filters['symbol'] !== '') {
            $this->db->like('symbol', $filters['symbol']);
        }

        return $this->db
            ->order_by('code', 'ASC')
            ->get('costing_currencies')
            ->result_array();
    }

    private function Read_Latest_Exchange_Rates($filters = array())
    {
        $where = "
            WHERE cer.from_currency_id <> cer.to_currency_id
                AND NOT EXISTS (
                    SELECT 1
                    FROM costing_exchange_rates newer
                    WHERE newer.from_currency_id = cer.from_currency_id
                        AND newer.to_currency_id = cer.to_currency_id
                        AND (
                            newer.valid_from > cer.valid_from
                            OR (newer.valid_from = cer.valid_from AND newer.id > cer.id)
                        )
                )
        ";

        $params = array();

        if (!empty($filters['from_currency_id'])) {
            $where .= " AND cer.from_currency_id = ? ";
            $params[] = (int) $filters['from_currency_id'];
        }

        if (!empty($filters['to_currency_id'])) {
            $where .= " AND cer.to_currency_id = ? ";
            $params[] = (int) $filters['to_currency_id'];
        }

        if (!empty($filters['valid_from'])) {
            $where .= " AND DATE(cer.valid_from) = ? ";
            $params[] = $filters['valid_from'];
        }

        return $this->db->query("
            SELECT
                cer.id,
                cer.from_currency_id,
                cer.to_currency_id,
                cer.unit_amount,
                cer.rate,
                cer.buffer,
                cer.bank_charges_myr,
                cer.updated_by_admin_id,
                ROUND(cer.unit_amount * cer.rate, 8) AS converted_amount,
                cer.valid_from,
                from_currency.code AS from_currency_code,
                to_currency.code AS to_currency_code,
                admin.Name AS updated_by_name
            FROM costing_exchange_rates cer
            INNER JOIN costing_currencies from_currency ON from_currency.id = cer.from_currency_id
            INNER JOIN costing_currencies to_currency ON to_currency.id = cer.to_currency_id
            LEFT JOIN admin ON admin.AdminID = cer.updated_by_admin_id
            " . $where . "
            ORDER BY from_currency.code ASC, to_currency.code ASC
        ", $params)->result_array();
    }

    /**
     * Public wrapper: every currency pair with its full rate history, newest
     * first, for the Costing Currency Excel export.
     */
    public function Read_Exchange_Rate_History_For_Export()
    {
        return $this->Read_Exchange_Rate_History();
    }

    private function Read_Exchange_Rate_History()
    {
        return $this->db->query("
            SELECT
                cer.id,
                cer.from_currency_id,
                cer.to_currency_id,
                cer.unit_amount,
                cer.rate,
                cer.buffer,
                cer.bank_charges_myr,
                cer.updated_by_admin_id,
                ROUND(cer.unit_amount * cer.rate, 8) AS converted_amount,
                cer.valid_from,
                from_currency.code AS from_currency_code,
                to_currency.code AS to_currency_code,
                admin.Name AS updated_by_name
            FROM costing_exchange_rates cer
            INNER JOIN costing_currencies from_currency ON from_currency.id = cer.from_currency_id
            INNER JOIN costing_currencies to_currency ON to_currency.id = cer.to_currency_id
            LEFT JOIN admin ON admin.AdminID = cer.updated_by_admin_id
            WHERE cer.from_currency_id <> cer.to_currency_id
            ORDER BY from_currency.code ASC, to_currency.code ASC, cer.valid_from DESC, cer.id DESC
        ")->result_array();
    }

    private function Run_Safe_Delete($table, $key, $value, $fallback_message)
    {
        $original_db_debug = $this->db->db_debug;
        $this->db->db_debug = false;

        try {
            $success = $this->db->where($key, $value)->delete($table);
        } catch (Exception $exception) {
            $this->db->db_debug = $original_db_debug;
            return array('success' => false, 'message' => $fallback_message);
        }

        $this->db->db_debug = $original_db_debug;

        if ($success) {
            return array('success' => true, 'message' => null);
        }

        return array('success' => false, 'message' => $fallback_message);
    }

    private function Resolve_Exchange_Rate($from_currency_id, $to_currency_id, $travel_date = null)
    {
        $exchange_rate = $this->Resolve_Exchange_Rate_Details($from_currency_id, $to_currency_id, $travel_date);
        return (float) $exchange_rate['rate'];
    }

    private function Resolve_Exchange_Rate_Details($from_currency_id, $to_currency_id, $travel_date = null)
    {
        $from_currency_id = (int) $from_currency_id;
        $to_currency_id = (int) $to_currency_id;

        if ($from_currency_id <= 0 || $to_currency_id <= 0) {
            return array('rate' => 0, 'bank_charges_myr' => 0);
        }

        if ($from_currency_id === $to_currency_id) {
            return array('rate' => 1, 'bank_charges_myr' => 0);
        }

        // The RATE is date-sensitive: pick the record in force on/before the travel
        // date, falling back to the most recent record when the trip predates every
        // rate. The buffer travels with the chosen rate row and is added on top.
        $this->db->select('rate, buffer');
        $this->db->where('from_currency_id', $from_currency_id);
        $this->db->where('to_currency_id', $to_currency_id);

        if (!empty($travel_date)) {
            $this->db->where('valid_from <=', $travel_date . ' 23:59:59');
        }

        $this->db->order_by('valid_from', 'DESC');
        $this->db->order_by('id', 'DESC');
        $row = $this->db->get('costing_exchange_rates')->row_array();

        if (!$row) {
            $row = $this->db
                ->select('rate, buffer')
                ->where('from_currency_id', $from_currency_id)
                ->where('to_currency_id', $to_currency_id)
                ->order_by('valid_from', 'DESC')
                ->order_by('id', 'DESC')
                ->get('costing_exchange_rates')
                ->row_array();
        }

        if (!$row) {
            return array('rate' => 0, 'bank_charges_myr' => 0);
        }

        // The effective rate = stored market rate + additive buffer (safety margin
        // on the exchange rate, set per currency on the Costing Currency page).
        $this->load->helper('costing_calc');

        // The BANK CHARGE is a fixed per-currency operational fee, NOT a market rate,
        // so it always follows the latest configured value — regardless of travel
        // date. Otherwise a charge added today would silently drop off any quote
        // whose travel date (and therefore rate) predates the charge (e.g. IDR set up
        // on 09 Sep but travelling 31 Jul showed RM 0.09 with no "incl. bank").
        return array(
            'rate' => costing_effective_rate($row['rate'], isset($row['buffer']) ? $row['buffer'] : 0),
            'bank_charges_myr' => $this->Read_Latest_Bank_Charges_Myr($from_currency_id, $to_currency_id),
        );
    }

    /**
     * Latest configured MYR bank charge for a currency pair, ignoring travel date.
     * Bank charges are a per-currency operational fee, so the newest record wins.
     */
    private function Read_Latest_Bank_Charges_Myr($from_currency_id, $to_currency_id)
    {
        $row = $this->db
            ->select('bank_charges_myr')
            ->where('from_currency_id', (int) $from_currency_id)
            ->where('to_currency_id', (int) $to_currency_id)
            ->order_by('valid_from', 'DESC')
            ->order_by('id', 'DESC')
            ->get('costing_exchange_rates')
            ->row_array();

        return $row ? round(max(0, (float) $row['bank_charges_myr']), 2) : 0.0;
    }

    /**
     * Normalise posted customer combinations. Each combination keeps a name and
     * its own normalised cost rows (same rules as the internal template). A row is
     * included unless its include flag is explicitly 0. A combination with no
     * usable rows is dropped; a nameless-but-non-empty one gets an auto name.
     *
     * @return array list of ['id'=>int, 'name'=>string, 'rows'=>array, ...]
     */
    private function Prepare_Combinations($posted)
    {
        $this->load->helper('costing_calc');
        // Categories flagged on the master as carrying a headcount multiplier — only
        // these may receive a per-combination count (guards against crafted input).
        $this->load->model('Costing_Category_Model');
        $headcount_codes = $this->Costing_Category_Model->Read_Headcount_Codes();

        $out = array();
        $seq = 0;

        foreach ((array) $posted as $combo) {
            $seq++;
            $name = trim((string) (isset($combo['name']) ? $combo['name'] : ''));
            $posted_rows = isset($combo['rows']) ? (array) $combo['rows'] : array();

            $selected = array();
            foreach ($posted_rows as $row) {
                if (!array_key_exists('include', $row) || !empty($row['include'])) {
                    $selected[] = $row;
                }
            }

            $rows = $this->Normalize_Booking_Rows($selected);
            if (empty($rows)) {
                continue;
            }

            // General Combination (18 Sep 2026, item 6): its items are common to all
            // and folded into every combination; it is never a customer option.
            $is_general = !empty($combo['is_general']) ? 1 : 0;

            if ($name === '') {
                $name = $is_general ? 'General' : ('Combination ' . $seq);
            }

            // #5 Optional manual selling price per pax. Blank/absent => NULL (the
            // quotation falls back to the suggested Cost after Markup). A general
            // combination is never priced on its own.
            $selling = isset($combo['selling_price_per_pax']) ? trim((string) $combo['selling_price_per_pax']) : '';
            $selling_price_per_pax = (!$is_general && $selling !== '' && is_numeric($selling)) ? round(max(0, (float) $selling), 2) : null;

            // Per-combination, per-category headcount counts (only for flagged
            // categories, only counts ≥ 2). Stored as a JSON map on the combination.
            $headcount = costing_normalize_headcount(isset($combo['headcount']) ? $combo['headcount'] : array(), $headcount_codes);
            $headcount_json = empty($headcount) ? null : json_encode($headcount);

            $out[] = array(
                'id' => isset($combo['id']) ? (int) $combo['id'] : 0,
                'name' => $name,
                'rows' => $rows,
                'selling_price_per_pax' => $selling_price_per_pax,
                'is_general' => $is_general,
                'headcount_json' => $headcount_json,
            );
        }

        return $out;
    }

    private function Normalize_Booking_Rows($rows)
    {
        $normalized = array();

        foreach ((array) $rows as $row) {
            $name = trim((string) (isset($row['name']) ? $row['name'] : ''));
            $category = trim((string) (isset($row['category']) ? $row['category'] : ''));
            $currency_id = (int) (isset($row['currency_id']) ? $row['currency_id'] : 0);

            if ($name === '' || $category === '' || $currency_id <= 0) {
                continue;
            }

            $this->load->helper('costing_calc');
            $normalized_row = array(
                'package_item_id' => !empty($row['package_item_id']) ? (int) $row['package_item_id'] : null,
                'name' => $name,
                'category' => $category,
                'pax_type' => in_array((string) (isset($row['pax_type']) ? $row['pax_type'] : ''), array('', 'adult', 'child'), true) ? (string) (isset($row['pax_type']) ? $row['pax_type'] : '') : '',
                'multiplier_type' => costing_normalize_multiplier_type(isset($row['multiplier_type']) ? $row['multiplier_type'] : 'fixed'),
                'quantity' => round(max(0, (float) $row['quantity']), 2),
                'unit_count' => round(max(0, (float) (isset($row['unit_count']) ? $row['unit_count'] : 1)), 2),
                'unit_price' => round(max(0, (float) $row['unit_price']), 2),
                'currency_id' => $currency_id,
                'remark' => trim((string) (isset($row['remark']) ? $row['remark'] : '')),
                // Optional per-item supplier. May be an existing supplier name the
                // user picked OR a brand-new name typed in; either way it is stored
                // as free text here and never written back to the supplier master.
                'supplier' => trim((string) (isset($row['supplier']) ? $row['supplier'] : '')),
            );

            if ((float) $normalized_row['unit_count'] <= 0) {
                $normalized_row['unit_count'] = 1.0;
            }

            // "No multiply" categories (Total = Cost): the day/pax count never scales
            // the row, so force quantity to 1 regardless of what the UI posted. The
            // read path then yields base_total = the MYR-converted cost (per unit).
            if (in_array(strtolower(trim($category)), $this->No_Multiply_Codes(), true)) {
                $normalized_row['quantity'] = 1.0;
            }

            if (array_key_exists('bank_charges_myr', $row)) {
                $normalized_row['bank_charges_myr'] = round(max(0, (float) $row['bank_charges_myr']), 2);
            }

            // Freeze the "MYR (convert)" figure the user saw (foreign cost -> MYR
            // with bank charge baked in) into its own column, so it never drifts
            // when the exchange-rate master moves after saving.
            if (array_key_exists('myr_per_unit', $row) && $row['myr_per_unit'] !== '') {
                $normalized_row['myr_per_unit'] = round(max(0, (float) $row['myr_per_unit']), 2);
            }

            $normalized[] = $normalized_row;
        }

        // Stamp the display order (0,1,2…) from the surviving rows' order — which
        // mirrors the on-screen / posted DOM order, so a drag rearrange persists.
        $this->load->helper('costing_calc');
        return costing_stamp_sort_order($normalized);
    }

    private function Resolve_Bank_Charges_Myr($currency_id, $travel_date = null)
    {
        $base_currency = $this->Read_Base_Currency('MYR');
        $base_currency_id = $base_currency ? (int) $base_currency['id'] : 0;
        $exchange_rate = $this->Resolve_Exchange_Rate_Details((int) $currency_id, $base_currency_id, $travel_date);

        return round(max(0, (float) $exchange_rate['bank_charges_myr']), 2);
    }

    private function Upsert_Booking_Financials($booking_id, $data)
    {
        $payload = array(
            'booking_id' => (int) $booking_id,
            'margin_percentage' => round(max(0, (float) $data['margin_percentage']), 2),
            'commissionable_per_pax' => round(max(0, (float) $data['commissionable_per_pax']), 2),
            'ad_hoc_per_pax' => round(max(0, (float) $data['ad_hoc_per_pax']), 2),
            'selling_price_per_pax' => round(max(0, (float) $data['selling_price_per_pax']), 2),
        );

        $exists = $this->db
            ->select('id')
            ->where('booking_id', (int) $booking_id)
            ->get('costing_booking_financials')
            ->row_array();

        if ($exists) {
            $this->db->where('booking_id', (int) $booking_id)->update('costing_booking_financials', $payload);
        } else {
            $payload = array_merge($this->Empty_Financials(), $payload);
            $this->db->insert('costing_booking_financials', $payload);
        }
    }

    private function Calculate_Financials_From_Booking($booking, $booking_items, $base_currency_id)
    {
        $this->load->helper('costing_calc');
        $existing = $this->db
            ->where('booking_id', (int) $booking['id'])
            ->get('costing_booking_financials')
            ->row_array();

        // Margin is a GROSS MARGIN: cost after markup = cost / (1 - margin%/100).
        // This booking-level roll-up is the aggregate of every cost row; per-
        // combination manual selling prices live on costing_combinations and drive
        // the customer quotation.
        $margin_percentage = $existing ? max(0, (float) $existing['margin_percentage']) : 0;
        $commissionable_per_pax = $existing ? (float) $existing['commissionable_per_pax'] : 0;
        $ad_hoc_per_pax = $existing ? (float) $existing['ad_hoc_per_pax'] : 0;
        $total_pax = max(1, (int) $booking['total_pax']);

        $total_cost = 0;
        foreach ($booking_items as $item) {
            $total_cost += (float) $item['base_total'];
        }
        // Bank (wire) charge once per distinct currency across the whole booking.
        $total_cost += costing_bank_total($booking_items);

        $total_cost = round($total_cost, 2);
        $cost_per_pax = round($total_cost / $total_pax, 2);
        $price_per_pax = costing_cost_after_markup($cost_per_pax, $margin_percentage);
        $markup_amount_total = round(($price_per_pax * $total_pax) - $total_cost, 2);
        $total_per_pax = $price_per_pax;
        // Selling price is derived from the gross margin, never entered by hand.
        $selling_price_per_pax = $price_per_pax;
        $total_revenue = round($selling_price_per_pax * $total_pax, 2);
        $gross_profit = round($total_revenue - $total_cost, 2);

        return array(
            'margin_percentage' => $margin_percentage,
            'commissionable_per_pax' => $commissionable_per_pax,
            'ad_hoc_per_pax' => $ad_hoc_per_pax,
            'total_cost' => $total_cost,
            'cost_per_pax' => $cost_per_pax,
            'markup_amount_total' => $markup_amount_total,
            'price_per_pax' => $price_per_pax,
            'total_per_pax' => $total_per_pax,
            'selling_price_per_pax' => $selling_price_per_pax,
            'total_revenue' => $total_revenue,
            'gross_profit' => $gross_profit,
            'total_profit' => $gross_profit,
            'base_currency_id' => $base_currency_id,
        );
    }

    private function Read_Cost_Types()
    {
        return array(
            'per_pax' => 'Per Pax',
            'per_adult' => 'Per Adult',
            'per_child' => 'Per Child',
            'per_unit' => 'Per Unit',
            'fixed' => 'Fixed',
        );
    }

    private function Normalize_Status($status)
    {
        $status = strtolower(trim((string) $status));
        return in_array($status, array('active', 'inactive'), true) ? $status : 'active';
    }

    private function Normalize_Cost_Type($cost_type)
    {
        $cost_type = strtolower(trim((string) $cost_type));
        return array_key_exists($cost_type, $this->Read_Cost_Types()) ? $cost_type : 'fixed';
    }

    private function Build_Booking_Number($booking)
    {
        if (empty($booking['id']) || empty($booking['travel_date'])) {
            return 'N/A';
        }

        $year = date('Y', strtotime($booking['travel_date']));
        return 'CT-' . $year . '-' . str_pad($booking['id'], 4, '0', STR_PAD_LEFT);
    }

    private function Extract_Selected_Package_Item_Ids($booking_items)
    {
        $ids = array();

        foreach ($booking_items as $item) {
            if (!empty($item['package_item_id'])) {
                $ids[] = (int) $item['package_item_id'];
            }
        }

        return array_values(array_unique($ids));
    }

    private function Build_Category_Suggestions($package_items, $booking_items)
    {
        $categories = array();

        foreach (array_merge($package_items, $booking_items) as $row) {
            $category = trim((string) (isset($row['category']) ? $row['category'] : ''));
            if ($category !== '') {
                $categories[$category] = $category;
            }
        }

        ksort($categories);
        return array_values($categories);
    }

    private function Empty_Workspace_Data($base_currency_code)
    {
        return array(
            'package' => array(
                'id' => 0,
                'name' => '',
                'sales_admin_id' => 0,
                'sales_admin_mobile' => '',
                'op_admin_id' => 0,
                'op_admin_name' => '',
                'validity_date' => '',
                'duration_days' => 1,
                'duration_nights' => 0,
                'description' => '',
                'status' => 'Active',
                'status_value' => 'active',
            ),
            'booking' => $this->Empty_Booking_Data(),
            'bookings' => array(),
            'package_items' => array(),
            'booking_items' => array(),
            'selected_package_item_ids' => array(),
            'category_suggestions' => array(),
            'financials' => $this->Empty_Financials(),
            'base_currency' => $base_currency_code,
            'has_booking' => false,
            'has_bookings' => false,
            'currencies' => $this->Read_Currencies(),
            'statuses' => array('active', 'inactive'),
            'cost_types' => $this->Read_Cost_Types(),
            'latest_exchange_rates' => $this->Read_Latest_Exchange_Rates(),
            'snapshot_panel' => array(),
            'itinerary_days' => array(),
            'itinerary_meta' => array('notes' => ''),
            'sales_agents' => $this->Read_Sales_Agents(),
            'quotation_token' => '',
            'item_master' => array(),
            'currency_rate_map' => array(),
        );
    }

    private function Empty_Booking_Data()
    {
        return array(
            'id' => 0,
            'booking_number' => 'No booking yet',
            'travel_date' => date('Y-m-d'),
            'travel_date_end' => '',
            'adult_count' => 2,
            'child_count' => 0,
            'total_pax' => 2,
            'status' => 'Active',
            'status_value' => 'active',
        );
    }

    private function Empty_Financials()
    {
        return array(
            'margin_percentage' => 0,
            'commissionable_per_pax' => 0,
            'ad_hoc_per_pax' => 0,
            'total_cost' => 0,
            'cost_per_pax' => 0,
            'markup_amount_total' => 0,
            'price_per_pax' => 0,
            'total_per_pax' => 0,
            'selling_price_per_pax' => 0,
            'total_revenue' => 0,
            'gross_profit' => 0,
            'total_profit' => 0,
        );
    }
}
