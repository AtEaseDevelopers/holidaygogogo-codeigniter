<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Dynamic costing category master (table: costing_categories). Replaces the old
 * hardcoded five in costing_calc_helper::costing_categories(). Each row has a
 * stable `code` slug (immutable after create, so items keep resolving) and a
 * human `name`. Soft-deleted via Status='N'. 'miscellaneous' is the fallback
 * bucket and cannot be deleted.
 */
class Costing_Category_Model extends CI_Model
{
    /** The fallback bucket code that must always exist. */
    const FALLBACK_CODE = 'miscellaneous';

    /**
     * Active categories for the master listing, ordered by sort_order then name.
     *
     * @param array $filters optional name filter
     * @return array
     */
    public function Read_Categories($filters = array())
    {
        $this->db->from('costing_categories');
        $this->db->where('Status', 'Y');
        if (!empty($filters['name'])) {
            $this->db->like('name', $filters['name']);
        }
        $this->db->order_by('sort_order', 'ASC');
        $this->db->order_by('name', 'ASC');
        return $this->db->get()->result_array();
    }

    /**
     * Active categories as code => name, ready to drop into a picker or the wizard.
     * Falls back to the pure seed list when the master is empty (fresh install).
     *
     * @return array<string,string>
     */
    public function Read_Category_Map()
    {
        $rows = $this->Read_Categories();
        if (empty($rows)) {
            $this->load->helper('costing_calc');
            return costing_categories();
        }

        $map = array();
        foreach ($rows as $row) {
            $map[(string) $row['code']] = (string) $row['name'];
        }
        return $map;
    }

    /**
     * Active category codes flagged with a headcount multiplier (e.g. Tour Leader).
     * These are the categories whose section header shows a "No. of …" count in each
     * combination and whose cost is scaled by it in the summary / quotation PDF.
     *
     * @return string[] lowercased category codes
     */
    public function Read_Headcount_Codes()
    {
        $rows = $this->db
            ->select('code')
            ->where('Status', 'Y')
            ->where('has_headcount_multiplier', 1)
            ->get('costing_categories')
            ->result_array();

        $codes = array();
        foreach ($rows as $row) {
            $codes[] = strtolower(trim((string) $row['code']));
        }
        return $codes;
    }

    /**
     * One active category by id, or null.
     */
    public function Read_Category($id)
    {
        $this->db->where('id', (int) $id);
        $this->db->where('Status', 'Y');
        return $this->db->get('costing_categories')->row_array();
    }

    /**
     * Create or rename a category. New rows get a unique code slug from the name;
     * existing rows only rename (code stays put so items keep resolving).
     * Returns true on success.
     *
     * @param array $data id, name
     */
    public function Save_Category($data)
    {
        $this->load->helper('costing_calc');

        $id   = (int) (isset($data['id']) ? $data['id'] : 0);
        $name = trim((string) (isset($data['name']) ? $data['name'] : ''));
        if ($name === '') {
            return false;
        }

        // Does this category carry a headcount multiplier (e.g. No. of Tour Leaders)?
        $has_headcount = !empty($data['has_headcount_multiplier']) ? 1 : 0;

        if ($id > 0) {
            $this->db->where('id', $id);
            $this->db->update('costing_categories', array(
                'name' => $name,
                'has_headcount_multiplier' => $has_headcount,
            ));
            return true;
        }

        // New: slug the name, unique against every existing code (incl. inactive).
        $existing = array_column($this->db->select('code')->get('costing_categories')->result_array(), 'code');
        $code = costing_category_slug($name, $existing);
        $next_sort = (int) $this->db->select_max('sort_order', 'm')->get('costing_categories')->row('m') + 1;

        $this->db->insert('costing_categories', array(
            'code'       => $code,
            'name'       => $name,
            'sort_order' => $next_sort,
            'has_headcount_multiplier' => $has_headcount,
        ));
        return $this->db->insert_id() > 0;
    }

    /**
     * How many active items still use this category code (blocks deletion).
     */
    public function Count_Items_Using($code)
    {
        $this->db->where('category', (string) $code);
        $this->db->where('Status', 'Y');
        return (int) $this->db->count_all_results('costing_items');
    }

    /**
     * Soft-delete a category. Refuses the fallback bucket or any category still
     * used by active items. Returns [ok, message].
     */
    public function Delete_Category($id)
    {
        $row = $this->Read_Category($id);
        if (!$row) {
            return array(false, 'Category not found.');
        }
        if ((string) $row['code'] === self::FALLBACK_CODE) {
            return array(false, 'The Miscellaneous fallback category cannot be deleted.');
        }
        $in_use = $this->Count_Items_Using($row['code']);
        if ($in_use > 0) {
            return array(false, 'Cannot delete: ' . $in_use . ' item(s) still use this category.');
        }

        $this->db->where('id', (int) $id);
        $this->db->update('costing_categories', array('Status' => 'N'));
        return array(true, 'Category deleted successfully.');
    }
}
