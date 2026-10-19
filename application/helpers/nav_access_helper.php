<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Per-admin view/edit access for every sidebar item.
 *
 * Owner (level 10) always has full access and is never stored.
 * If a row exists in nav_module_access for (admin, module), that row wins.
 * If no row exists, current (legacy) menu/controller rules apply — so existing
 * staff keep the same access until the owner saves this page.
 *
 * Edit implies view. View without edit = open the page, no writes.
 */

if ( ! function_exists('nav_modules'))
{
	function nav_modules()
	{
		return array(
			'dashboard' => array('label' => 'Dashboard', 'group' => 'Dashboard', 'class' => 'Dashboard'),

			'customer' => array('label' => 'Customer', 'group' => 'Lead & Customer', 'class' => 'Customer', 'lc' => true),
			'guests' => array('label' => 'Guest List', 'group' => 'Lead & Customer', 'class' => 'Guests', 'lc' => true),
			'ghl_leads' => array('label' => 'GHL Leads', 'group' => 'Lead & Customer', 'class' => 'Ghl_Leads', 'lc' => true),
			'manual_leads' => array('label' => 'Manual Leads', 'group' => 'Lead & Customer', 'class' => 'Manual_Leads', 'lc' => true),
			'hot_cold_customers' => array('label' => 'Hot / Cold Customers', 'group' => 'Lead & Customer', 'class' => 'Hot_Cold_Customers'),
			'lead_status' => array('label' => 'Lead Status', 'group' => 'Lead & Customer', 'class' => 'Lead_Status', 'lc' => true),
			'nature_of_business' => array('label' => 'Nature of Business', 'group' => 'Lead & Customer', 'class' => 'Nature_Of_Business', 'lc' => true),
			'customer_type' => array('label' => 'Customer Type', 'group' => 'Lead & Customer', 'class' => 'Customer_Type'),

			'campaign' => array('label' => 'Campaign', 'group' => 'Campaign', 'class' => 'Campaign', 'lc' => true),

			'booking' => array('label' => 'Booking Listing', 'group' => 'Booking', 'class' => 'Booking'),
			'source' => array('label' => 'Source', 'group' => 'Booking', 'class' => 'Source'),
			'package_checklist' => array('label' => 'Package Checklist', 'group' => 'Booking', 'class' => 'Package_Checklist'),
			'cancellation_reason' => array('label' => 'Cancellation Reason', 'group' => 'Booking', 'class' => 'Cancellation_Reason'),
			'slow_conversion_reason' => array('label' => 'Slow Conversion Reason', 'group' => 'Booking', 'class' => 'Slow_Conversion_Reason'),
			'quick_filter' => array('label' => 'Quick Filter Setup', 'group' => 'Booking', 'class' => 'Quick_Filter'),
			'footer' => array('label' => 'Footer', 'group' => 'Booking', 'class' => 'Footer'),

			'payment' => array('label' => 'Payment Listing', 'group' => 'Payment', 'class' => 'Payment'),
			'payment_due_soon' => array('label' => 'Payment Due Soon', 'group' => 'Payment', 'class' => 'Payment', 'method' => 'upcoming_due'),

			'costing' => array('label' => 'Costing Package', 'group' => 'Costing', 'class' => 'Costing'),
			'costing_item' => array('label' => 'Costing Item', 'group' => 'Costing', 'class' => 'Costing_Item'),
			'costing_category' => array('label' => 'Costing Category', 'group' => 'Costing', 'class' => 'Costing_Category'),
			'costing_currency' => array('label' => 'Costing Currency', 'group' => 'Costing', 'class' => 'Costing', 'method' => 'Currency'),

			'product' => array('label' => 'Product List', 'group' => 'Product & Supplier', 'class' => 'Product'),
			'supplier' => array('label' => 'Supplier List', 'group' => 'Product & Supplier', 'class' => 'Supplier'),
			'category' => array('label' => 'Category', 'group' => 'Product & Supplier', 'class' => 'Category'),
			'category_code' => array('label' => 'Category Code', 'group' => 'Product & Supplier', 'class' => 'Category_Code'),
			'product_tag' => array('label' => 'Product Tag', 'group' => 'Product & Supplier', 'class' => 'Tag'),
			'country_code' => array('label' => 'Country Code', 'group' => 'Product & Supplier', 'class' => 'Country_Code'),

			'master_product' => array('label' => 'Master Product', 'group' => 'Product Analysis', 'class' => 'Master_Product'),
			'competitor_product' => array('label' => 'Competitor Product', 'group' => 'Product Analysis', 'class' => 'Competitor_Product'),
			'our_product' => array('label' => 'Our Product', 'group' => 'Product Analysis', 'class' => 'Our_Product'),

			'faq' => array('label' => 'FAQ', 'group' => 'FAQ', 'class' => 'Faq'),
			'faq_tag' => array('label' => 'FAQ Tag', 'group' => 'FAQ', 'class' => 'Faq_Tag'),

			'powerbi_report' => array('label' => 'Power BI Report', 'group' => 'Reports', 'class' => 'Report', 'method' => 'PowerBI_Reports'),
			'powerbi_builder' => array('label' => 'Power BI Builder', 'group' => 'Reports', 'class' => 'Report', 'method' => 'PowerBI'),
			'lead_ownership' => array('label' => 'Lead Ownership', 'group' => 'Reports', 'class' => 'Report', 'method' => 'Lead_Ownership_Dashboard'),
			'leads_by_hour' => array('label' => 'Leads by Hour', 'group' => 'Reports', 'class' => 'Report', 'method' => 'Leads_By_Hour'),
			'lead_reply_activity' => array('label' => 'Lead Reply Activity', 'group' => 'Reports', 'class' => 'Report', 'method' => 'Lead_Reply_Activity_Dashboard'),
			'message_log' => array('label' => 'Message Log', 'group' => 'Reports', 'class' => 'Report', 'method' => 'Ghl_Message_Log'),

			'admin' => array('label' => 'Admin', 'group' => 'Settings', 'class' => 'Admin'),
			'team' => array('label' => 'Team', 'group' => 'Settings', 'class' => 'Team'),
			'agent_score' => array('label' => 'Agent Score', 'group' => 'Settings', 'class' => 'Agent_Score_Setting'),
			'card_visibility' => array('label' => 'Card Visibility', 'group' => 'Settings', 'class' => 'Card_Visibility_Setting'),
			'ai_usage' => array('label' => 'AI Cost & Usage', 'group' => 'Settings', 'class' => 'Ai_Usage'),
		);
	}
}

if ( ! function_exists('nav_module_groups'))
{
	function nav_module_groups()
	{
		$groups = array();
		foreach (nav_modules() as $key => $meta) {
			$groups[$meta['group']][] = $key;
		}
		return $groups;
	}
}

if ( ! function_exists('nav_normalize_ac'))
{
	function nav_normalize_ac($access_control)
	{
		if (is_array($access_control)) {
			return $access_control;
		}
		if ($access_control === null || $access_control === '') {
			return array();
		}
		return explode(',', (string) $access_control);
	}
}

/**
 * Current (legacy) view/edit for one module. Pure — no session/DB.
 */
if ( ! function_exists('nav_legacy_access'))
{
	function nav_legacy_access($module, $level, $access_control, $admin_id, $lc_rows = array())
	{
		$none = array('view' => false, 'edit' => false);
		$mods = nav_modules();
		if ( ! isset($mods[$module])) {
			return $none;
		}

		$level = (int) $level;
		$ac    = nav_normalize_ac($access_control);
		$admin_id = (int) $admin_id;
		$is_owner = ($level === 10);
		$not_sales_or_mkt = ! in_array($level, array(20, 60), true);
		$op_footer_only = ($level === 40);
		$can_setting_masters = ($not_sales_or_mkt && ! $op_footer_only);

		if ( ! empty($mods[$module]['lc'])) {
			$resolved = lc_resolve_access($module, $level, $lc_rows);
			return $resolved;
		}

		$view = false;
		$edit = false;

		switch ($module) {
			case 'dashboard':
				$view = ($not_sales_or_mkt && ! in_array($level, array(40, 45), true));
				$edit = $view;
				break;
			case 'hot_cold_customers':
			case 'team':
			case 'agent_score':
			case 'card_visibility':
			case 'ai_usage':
			case 'master_product':
			case 'competitor_product':
			case 'our_product':
				$view = $is_owner;
				$edit = $is_owner;
				break;
			case 'customer_type':
			case 'source':
			case 'package_checklist':
			case 'cancellation_reason':
			case 'slow_conversion_reason':
			case 'quick_filter':
			case 'category_code':
			case 'product_tag':
			case 'country_code':
				$view = $can_setting_masters;
				$edit = $view;
				break;
			case 'footer':
			case 'category':
				$view = $not_sales_or_mkt;
				$edit = $view;
				break;
			case 'booking':
				$view = in_array('VB', $ac, true);
				$edit = (in_array('GB', $ac, true) || in_array('AB', $ac, true));
				break;
			case 'payment':
				$view = in_array('VP', $ac, true);
				$edit = (in_array('GP', $ac, true) || in_array('AP', $ac, true));
				break;
			case 'payment_due_soon':
				$view = (in_array('VP', $ac, true) && in_array($level, array(10, 30), true));
				$edit = ($view && (in_array('GP', $ac, true) || in_array('AP', $ac, true)));
				break;
			case 'costing':
			case 'costing_item':
			case 'costing_category':
			case 'costing_currency':
				$view = ($is_owner || $admin_id === 28);
				$edit = $view;
				break;
			case 'product':
				$view = (function_exists('admin_can_access_setting_module')
					&& admin_can_access_setting_module('product', $level, $ac)
					&& ! $op_footer_only);
				$edit = $view;
				break;
			case 'supplier':
				$view = (in_array((string) $level, array('10', '30', '40'), true) && ! $op_footer_only);
				$edit = $view;
				break;
			case 'faq':
			case 'faq_tag':
				$view = ($not_sales_or_mkt && ($is_owner || in_array('FV', $ac, true)));
				$edit = ($view && ($is_owner || in_array('FE', $ac, true)));
				break;
			case 'powerbi_report':
			case 'powerbi_builder':
				$view = (in_array('VR', $ac, true) || in_array('ML', $ac, true) || $is_owner || $level === 20);
				$edit = ($module === 'powerbi_builder') ? $view : false;
				break;
			case 'lead_ownership':
			case 'leads_by_hour':
				$view = $is_owner;
				$edit = false;
				break;
			case 'lead_reply_activity':
				$view = ($is_owner || $level === 20);
				$edit = false;
				break;
			case 'message_log':
				$view = ($is_owner || in_array('ML', $ac, true));
				$edit = false;
				break;
			case 'admin':
				$view = ($not_sales_or_mkt && ! in_array($level, array(25, 40, 45), true));
				$edit = $view;
				break;
			default:
				break;
		}

		if ($edit) {
			$view = true;
		}
		return array('view' => (bool) $view, 'edit' => (bool) $edit);
	}
}

if ( ! function_exists('nav_current_rows'))
{
	function nav_current_rows()
	{
		static $cache = null;
		if ($cache !== null) {
			return $cache;
		}
		$CI =& get_instance();
		$admin_id = $CI->session->userdata('admin_id');
		if (empty($admin_id) || ! $CI->db->table_exists('nav_module_access')) {
			return $cache = array();
		}
		$CI->load->model('Nav_Module_Access_Model');
		return $cache = $CI->Nav_Module_Access_Model->Read_For_Admin($admin_id);
	}
}

if ( ! function_exists('nav_override_for'))
{
	function nav_override_for($module, $admin_id = null, $rows = null)
	{
		if ( ! array_key_exists($module, nav_modules())) {
			return null;
		}
		if ($rows === null) {
			$CI =& get_instance();
			if ($admin_id === null) {
				$admin_id = $CI->session->userdata('admin_id');
			}
			if ((int) $admin_id === (int) $CI->session->userdata('admin_id')) {
				$rows = nav_current_rows();
			} else {
				if ( ! $CI->db->table_exists('nav_module_access')) {
					return null;
				}
				$CI->load->model('Nav_Module_Access_Model');
				$rows = $CI->Nav_Module_Access_Model->Read_For_Admin($admin_id);
			}
		}
		if ( ! is_array($rows) || ! isset($rows[$module])) {
			return null;
		}
		$edit = ! empty($rows[$module]['CanEdit']);
		$view = $edit || ! empty($rows[$module]['CanView']);
		return array('view' => $view, 'edit' => $edit);
	}
}

if ( ! function_exists('nav_resolve_access'))
{
	function nav_resolve_access($module, $level, $access_control, $admin_id, $nav_rows = null, $lc_rows = array())
	{
		$none = array('view' => false, 'edit' => false);
		if ( ! array_key_exists($module, nav_modules())) {
			return $none;
		}
		if ((int) $level === 10) {
			return array('view' => true, 'edit' => true);
		}
		$override = nav_override_for($module, $admin_id, $nav_rows);
		if ($override !== null) {
			return $override;
		}
		return nav_legacy_access($module, $level, $access_control, $admin_id, $lc_rows);
	}
}

if ( ! function_exists('nav_can_view'))
{
	function nav_can_view($module)
	{
		$CI =& get_instance();
		$resolved = nav_resolve_access(
			$module,
			$CI->session->userdata('level'),
			$CI->session->userdata('access_control_base') !== null
				? $CI->session->userdata('access_control_base')
				: $CI->session->userdata('access_control'),
			$CI->session->userdata('admin_id'),
			nav_current_rows(),
			function_exists('lc_current_rows') ? lc_current_rows() : array()
		);
		return $resolved['view'];
	}
}

if ( ! function_exists('nav_can_edit'))
{
	function nav_can_edit($module)
	{
		$CI =& get_instance();
		$resolved = nav_resolve_access(
			$module,
			$CI->session->userdata('level'),
			$CI->session->userdata('access_control_base') !== null
				? $CI->session->userdata('access_control_base')
				: $CI->session->userdata('access_control'),
			$CI->session->userdata('admin_id'),
			nav_current_rows(),
			function_exists('lc_current_rows') ? lc_current_rows() : array()
		);
		return $resolved['edit'];
	}
}

if ( ! function_exists('nav_module_for_request'))
{
	function nav_module_for_request($class = null, $method = null)
	{
		$CI =& get_instance();
		$class  = $class !== null ? $class : $CI->router->class;
		$method = $method !== null ? $method : $CI->router->method;

		if ($class === 'Payment' && ($method === 'upcoming_due' || $CI->input->get('view_mode') === 'upcoming_due')) {
			return 'payment_due_soon';
		}
		if ($class === 'Costing' && strtolower((string) $method) === 'currency') {
			return 'costing_currency';
		}
		if ($class === 'Report') {
			$map = array(
				'powerbi_reports' => 'powerbi_report',
				'powerbi_view' => 'powerbi_report',
				'powerbi' => 'powerbi_builder',
				'lead_ownership_dashboard' => 'lead_ownership',
				'leads_by_hour' => 'leads_by_hour',
				'lead_reply_activity_dashboard' => 'lead_reply_activity',
				'ghl_message_log' => 'message_log',
			);
			$key = strtolower((string) $method);
			return isset($map[$key]) ? $map[$key] : null;
		}

		foreach (nav_modules() as $mod => $meta) {
			if ($meta['class'] === $class && empty($meta['method'])) {
				return $mod;
			}
		}
		return null;
	}
}

if ( ! function_exists('nav_is_write_request'))
{
	function nav_is_write_request()
	{
		$CI =& get_instance();
		$method = strtolower((string) $CI->router->method);
		$write = array(
			'create', 'update', 'delete', 'save', 'insert', 'remove', 'reset',
			'reset_two_factor', 'enable', 'disable', 'import', 'upload',
			'set_hidden', 'set_excluded',
		);
		if (in_array($method, $write, true)) {
			return true;
		}
		foreach (array('create_', 'update_', 'delete_', 'save_', 'add_', 'remove_', 'set_', 'reset_') as $prefix) {
			if (strpos($method, $prefix) === 0) {
				return true;
			}
		}
		return false;
	}
}

if ( ! function_exists('nav_home_url'))
{
	function nav_home_url()
	{
		if (nav_can_view('dashboard')) {
			return base_url('Dashboard');
		}
		if (nav_can_view('booking')) {
			return base_url('Booking');
		}
		foreach (array_keys(nav_modules()) as $mod) {
			if (nav_can_view($mod)) {
				$meta = nav_modules()[$mod];
				$path = $meta['class'];
				if ( ! empty($meta['method'])) {
					$path .= '/' . $meta['method'];
				}
				return base_url($path);
			}
		}
		return base_url('Profile');
	}
}

if ( ! function_exists('nav_block_view'))
{
	function nav_block_view($module)
	{
		if (nav_can_view($module)) {
			return false;
		}
		$CI =& get_instance();
		if ($CI->input->is_ajax_request()) {
			$CI->output->set_status_header(403);
			echo json_encode(false);
		} else {
			redirect(nav_home_url());
		}
		return true;
	}
}

if ( ! function_exists('nav_block_edit'))
{
	function nav_block_edit($module)
	{
		if (nav_can_edit($module)) {
			return false;
		}
		$CI =& get_instance();
		if ($CI->input->is_ajax_request()) {
			$CI->output->set_status_header(403);
			echo json_encode(false);
		} else {
			redirect(nav_home_url());
		}
		return true;
	}
}

/**
 * Overlay VB/GB/AB (and payment/FAQ/product flags) for this request when a
 * nav_module_access row exists, so existing controller checks keep working.
 * Stores the original flags in access_control_base and does not write them back
 * to the admin row.
 */
if ( ! function_exists('nav_apply_request_flags'))
{
	function nav_apply_request_flags()
	{
		$CI =& get_instance();
		if ((int) $CI->session->userdata('level') === 10) {
			return;
		}

		$base = $CI->session->userdata('access_control_base');
		if ($base === null) {
			$base = nav_normalize_ac($CI->session->userdata('access_control'));
			$CI->session->set_userdata('access_control_base', $base);
		} else {
			$base = nav_normalize_ac($base);
		}

		$ac = $base;
		$pairs = array(
			'booking'  => array('view' => array('VB'), 'edit' => array('GB', 'AB', 'RB')),
			'payment'  => array('view' => array('VP'), 'edit' => array('GP', 'AP', 'RP')),
			'faq'      => array('view' => array('FV'), 'edit' => array('FE')),
			'product'  => array('view' => array('VPR'), 'edit' => array('VPR')),
			'powerbi_report' => array('view' => array('VR'), 'edit' => array()),
			'message_log'    => array('view' => array('ML'), 'edit' => array()),
		);

		foreach ($pairs as $module => $flags) {
			if (nav_override_for($module) === null) {
				continue;
			}
			if (nav_can_view($module)) {
				$ac = array_merge($ac, $flags['view']);
			} else {
				$ac = array_values(array_diff($ac, $flags['view'], $flags['edit']));
			}
			if (nav_can_edit($module)) {
				$ac = array_merge($ac, $flags['edit']);
			} else {
				$ac = array_values(array_diff($ac, $flags['edit']));
			}
		}

		$CI->session->set_userdata('access_control', array_values(array_unique($ac)));
	}
}
