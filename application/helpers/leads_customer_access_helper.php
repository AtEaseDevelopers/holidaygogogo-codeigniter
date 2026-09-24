<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Per-admin, per-page view/edit access for the "Leads/Customer" tab, which groups
 * these pages that used to live under Setting:
 *
 *   Module         Class          Page
 *   customer       Customer       Customer List
 *   guests         Guests         Guest List
 *   ghl_leads      Ghl_Leads      GHL Leads
 *   manual_leads   Manual_Leads   Manual Leads
 *   campaign       Campaign       Campaign
 *   lead_status    Lead_Status    Lead Status
 *   nature_of_business Nature_Of_Business  Nature of Business
 *   customer_profile   Customer_Analysis   Customer Profile (Action ▸ Customer Profile)
 *
 * The OWNER (level 10) always has full view+edit and is never stored. Every other
 * admin sees/edits nothing by default — the owner grants rights per page on the
 * Leads/Customer > Access Settings page (table lc_module_access). "Edit" gates all
 * write actions (inline edit, remarks, chat upload, create/import); "view" only
 * gates opening the page. Edit implies view.
 */

if ( ! defined('CUSTOMER_DELETE_ADMIN_ID')) {
    // ERNIDA (Finance, AdminID 7) is allowed to delete customers alongside the
    // OWNER. Kept here (autoloaded helper) so the Delete() controller guard and
    // both listing views agree on one source of truth. See can_delete_customer().
    define('CUSTOMER_DELETE_ADMIN_ID', 7);
}

if ( ! function_exists('lc_modules'))
{
    function lc_modules()
    {
        return array(
            'customer'     => array('class' => 'Customer',     'label' => 'Customer'),
            'guests'       => array('class' => 'Guests',       'label' => 'Guest List'),
            'ghl_leads'    => array('class' => 'Ghl_Leads',    'label' => 'GHL Leads'),
            'manual_leads' => array('class' => 'Manual_Leads', 'label' => 'Manual Leads'),
            'campaign'     => array('class' => 'Campaign',     'label' => 'Campaign'),
            'lead_status'  => array('class' => 'Lead_Status',  'label' => 'Lead Status'),
            'nature_of_business' => array('class' => 'Nature_Of_Business', 'label' => 'Nature of Business'),
            'customer_profile'   => array('class' => 'Customer_Analysis', 'label' => 'Customer Profile'),
        );
    }
}

/**
 * Pure resolver — no session/DB. Owner (10) => full access on any KNOWN module.
 * Everyone else is driven by $rows (keyed by module => ['CanView'=>, 'CanEdit'=>]).
 * Edit implies view. Unknown module => no access (even for owner).
 *
 * @param string $module
 * @param mixed  $level  session level (int or string)
 * @param array  $rows   granted rows keyed by module
 * @return array ['view'=>bool, 'edit'=>bool]
 */
if ( ! function_exists('lc_resolve_access'))
{
    function lc_resolve_access($module, $level, $rows)
    {
        $none = array('view' => false, 'edit' => false);

        // Guard typos / unknown modules for everyone.
        if ( ! array_key_exists($module, lc_modules())) {
            return $none;
        }

        // Owner is implicit full access.
        if ((int) $level === 10) {
            return array('view' => true, 'edit' => true);
        }

        if ( ! is_array($rows) || ! isset($rows[$module])) {
            return $none;
        }

        $row  = $rows[$module];
        $edit = ! empty($row['CanEdit']);
        $view = $edit || ! empty($row['CanView']); // edit implies view

        return array('view' => $view, 'edit' => $edit);
    }
}

/**
 * Load the current admin's granted rows (keyed by module), cached per request.
 */
if ( ! function_exists('lc_current_rows'))
{
    function lc_current_rows()
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }

        $CI = & get_instance();
        $admin_id = $CI->session->userdata('admin_id');
        if (empty($admin_id)) {
            return $cache = array();
        }

        $CI->load->model('Lc_Module_Access_Model');
        return $cache = $CI->Lc_Module_Access_Model->Read_For_Admin($admin_id);
    }
}

if ( ! function_exists('lc_customer_lookup_method'))
{
    /**
     * Lightweight Customer endpoints that Booking create/edit needs for EVERY
     * sales agent: the type-ahead customer search + the duplicate-phone check.
     * These are exempt from the page-level lc_can_view('customer') gate in the
     * Customer controller constructor, so an agent WITHOUT Customer-module view
     * access can still search and attach a customer while making a booking.
     * They only return name/phone/code — data already shown on the booking form.
     * Match is case-insensitive because CI method names can be called in any case.
     */
    function lc_customer_lookup_method($method)
    {
        $allowed = array('search', 'search1', 'check_duplicate');
        return in_array(strtolower((string) $method), $allowed, true);
    }
}

if ( ! function_exists('lc_can_view'))
{
    function lc_can_view($module)
    {
        $CI = & get_instance();
        return lc_resolve_access($module, $CI->session->userdata('level'), lc_current_rows())['view'];
    }
}

if ( ! function_exists('lc_can_edit'))
{
    function lc_can_edit($module)
    {
        $CI = & get_instance();
        return lc_resolve_access($module, $CI->session->userdata('level'), lc_current_rows())['edit'];
    }
}

/**
 * Guard for read/page endpoints. Returns TRUE (and emits a response) when the
 * current admin may NOT view $module, so a controller can
 * `if (lc_block_view(...)) return;`. AJAX gets 403; plain requests redirect.
 */
if ( ! function_exists('lc_block_view'))
{
    function lc_block_view($module)
    {
        if (lc_can_view($module)) {
            return false;
        }
        $CI = & get_instance();
        if ($CI->input->is_ajax_request()) {
            $CI->output->set_status_header(403);
            echo json_encode(false);
        } else {
            redirect(base_url('Booking'));
        }
        return true;
    }
}

/**
 * Resolve which Leads/Customer module a shared request came from. The shared
 * guests view (used by all 4 pages) posts a `module` field so the shared
 * Guests/* endpoints can authorize against the correct page. Falls back to
 * $default when the value is missing or not a known module.
 */
if ( ! function_exists('lc_request_module'))
{
    function lc_request_module($default = 'guests')
    {
        $CI = & get_instance();
        $m  = $CI->input->post('module');
        if ($m === null || $m === '') {
            $m = $CI->input->get('module');
        }
        return array_key_exists($m, lc_modules()) ? $m : $default;
    }
}

/**
 * Guard for write endpoints. Returns TRUE (and emits a response) when the current
 * admin may NOT edit $module, so the controller can `if (lc_block_edit(...)) return;`.
 * AJAX writes get a 403 + JSON false; plain requests are redirected to Booking.
 */
if ( ! function_exists('lc_block_edit'))
{
    function lc_block_edit($module)
    {
        if (lc_can_edit($module)) {
            return false;
        }
        $CI = & get_instance();
        if ($CI->input->is_ajax_request()) {
            $CI->output->set_status_header(403);
            echo json_encode(false);
        } else {
            redirect(base_url('Booking'));
        }
        return true;
    }
}

/**
 * Whether the current admin can view AT LEAST ONE of the menu pages — drives the
 * top-level "Leads/Customer" menu group visibility. 'customer_profile' is skipped:
 * it has no own menu entry (it is the Action ▸ Customer Profile button reached from
 * within the listings), so it must not reveal an otherwise-empty menu group.
 */
if ( ! function_exists('lc_any_view'))
{
    function lc_any_view()
    {
        foreach (array_keys(lc_modules()) as $m) {
            if ($m === 'customer_profile') {
                continue;
            }
            if (lc_can_view($m)) {
                return true;
            }
        }
        return false;
    }
}

/**
 * Whether this admin may delete (soft-delete) a customer master. The OWNER
 * (level 10) always may; ERNIDA (Finance, AdminID 7) was additionally granted
 * delete rights (2026-08-03). Everyone else may not, whatever their level.
 *
 * Pure — pass the session 'level' and 'admin_id'. Used by Customer::Delete()
 * and by the delete-button gate in the customer + guests listing views.
 *
 * @param mixed $level    session 'level'    (int or string)
 * @param mixed $admin_id session 'admin_id' (int or string)
 * @return bool
 */
if ( ! function_exists('can_delete_customer'))
{
    function can_delete_customer($level, $admin_id)
    {
        if ((int) $level === 10) {
            return true;
        }
        return $admin_id !== null && $admin_id !== ''
            && (int) $admin_id === CUSTOMER_DELETE_ADMIN_ID;
    }
}
