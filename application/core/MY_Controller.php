<?php
class MY_Controller extends CI_Controller
{
	function __construct()
	{
		parent::__construct();
		if($this->session->has_userdata('admin_id') && $this->session->has_userdata('level')) {
			if (function_exists('nav_apply_request_flags')) {
				nav_apply_request_flags();
			}
			$nav_module = function_exists('nav_module_for_request') ? nav_module_for_request() : null;
			$nav_lookup_exempt = ($this->router->class === 'Customer'
				&& function_exists('lc_customer_lookup_method')
				&& lc_customer_lookup_method($this->router->fetch_method()));
			if ($nav_module && ! $nav_lookup_exempt && (int) $this->session->level !== 10) {
				if ( ! nav_can_view($nav_module)) {
					redirect(nav_home_url());
				}
				if (nav_is_write_request() && ! nav_can_edit($nav_module)) {
					if ($this->input->is_ajax_request()) {
						$this->output->set_status_header(403);
						echo json_encode(false);
						exit;
					}
					redirect(nav_home_url());
				}
			}
			if($nav_module === null && in_array($this->session->level, [20, 25, 45, 60])) {
				// Product is gated per-admin: an Owner-granted AccessControl flag
				// (VPR) overrides the level block. See setting_module_access_helper.
				// Customer / Guest List / GHL Leads / Manual Leads have moved to the
				// "Leads/Customer" tab and are gated per-page by lc_can_view() inside
				// each controller (leads_customer_access_helper), not here.
				$level = $this->session->level;
				$access_control = (array) $this->session->access_control;
				switch($this->router->class) {
					case 'Category':
						// OP TEAM LEAD (45) may access Category; SALES AGENT (20), TEAM LEAD (25) and MARKETING (60) stay blocked
						if(in_array($this->session->level, [20, 25, 60])) {
							redirect('Dashboard');
						}
						break;
					case 'Product':
						if( ! admin_can_access_setting_module('product', $level, $access_control)) {
							redirect('Dashboard');
						}
						break;
					// Lead_Status moved to the "Leads/Customer" tab; it is now gated
					// per-page by lc_can_view('lead_status') inside the controller
					// (leads_customer_access_helper), not by level here.
					case 'Company':
					case 'Admin':
					case 'Category_Code':
					case 'Supplier':
					case 'Country_Code':
					case 'Tag':
					case 'Source':
					case 'Customer_Type':
					case 'Quick_Filter':
						redirect('Dashboard');
						break;
					default:
				}
			}
		} else {
			redirect('Login');
		}
	}
}