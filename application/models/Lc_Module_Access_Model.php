<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Store for per-admin, per-page view/edit access to the "Leads/Customer" tab
 * (table lc_module_access). Owner (level 10) is implicit full access and is never
 * stored. See application/helpers/leads_customer_access_helper.php.
 */
class Lc_Module_Access_Model extends CI_Model
{
    /**
     * Granted rows for one admin, keyed by module:
     *   ['customer' => ['CanView'=>1,'CanEdit'=>0], ...]
     */
    function Read_For_Admin($admin_id)
    {
        $rows = $this->db
            ->select('Module, CanView, CanEdit')
            ->where('AdminID', (int) $admin_id)
            ->get('lc_module_access')
            ->result_array();

        $out = array();
        foreach ($rows as $r) {
            $out[$r['Module']] = array(
                'CanView' => (int) $r['CanView'],
                'CanEdit' => (int) $r['CanEdit'],
            );
        }
        return $out;
    }

    /**
     * Every grantable admin (active only, excluding OWNER + system) with their
     * current flags folded in, for the Access Settings grid. Owner is excluded
     * because it always has full access; disabled admins are excluded too.
     */
    function Read_Grid()
    {
        $admins = $this->db
            ->select('AdminID, Name, Level, Status')
            ->where('AdminID !=', 8)
            ->where('Level !=', 10)
            ->where('Status', 'Y')
            ->order_by('Name', 'ASC')
            ->get('admin')
            ->result();

        $all = $this->db
            ->select('AdminID, Module, CanView, CanEdit')
            ->get('lc_module_access')
            ->result();

        $by_admin = array();
        foreach ($all as $r) {
            $by_admin[(int) $r->AdminID][$r->Module] = $r;
        }

        foreach ($admins as $a) {
            $a->access = isset($by_admin[(int) $a->AdminID]) ? $by_admin[(int) $a->AdminID] : array();
        }
        return $admins;
    }

    /**
     * Persist the posted grid. Expects post 'access' as:
     *   [ AdminID => [ module => ['view'=>0/1, 'edit'=>0/1], ... ], ... ]
     * Replaces the whole table (owner never appears here). Edit implies view.
     */
    function Save()
    {
        $posted   = $this->input->post('access');
        $admin_id = $this->session->userdata('admin_id');
        $now      = date('Y-m-d H:i:s');
        $modules  = array('customer', 'guests', 'ghl_leads', 'manual_leads', 'campaign', 'lead_status', 'nature_of_business', 'customer_profile');

        $this->db->trans_start();

        // Rebuild from scratch — simplest correct upsert for a small grid.
        $this->db->truncate('lc_module_access');

        $batch = array();
        if (is_array($posted)) {
            foreach ($posted as $aid => $mods) {
                if ((int) $aid <= 0 || ! is_array($mods)) {
                    continue;
                }
                foreach ($modules as $m) {
                    $view = ! empty($mods[$m]['view']) ? 1 : 0;
                    $edit = ! empty($mods[$m]['edit']) ? 1 : 0;
                    if ($edit) {
                        $view = 1; // edit implies view
                    }
                    if ( ! $view && ! $edit) {
                        continue; // no grant -> no row
                    }
                    $batch[] = array(
                        'AdminID'    => (int) $aid,
                        'Module'     => $m,
                        'CanView'    => $view,
                        'CanEdit'    => $edit,
                        'InsertBy'   => $admin_id,
                        'InsertDate' => $now,
                    );
                }
            }
        }

        if ( ! empty($batch)) {
            $this->db->insert_batch('lc_module_access', $batch);
        }

        $this->db->trans_complete();
        return $this->db->trans_status();
    }
}
