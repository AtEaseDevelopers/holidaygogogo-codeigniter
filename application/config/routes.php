<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/*
| -------------------------------------------------------------------------
| URI ROUTING
| -------------------------------------------------------------------------
| This file lets you re-map URI requests to specific controller functions.
|
| Typically there is a one-to-one relationship between a URL string
| and its corresponding controller class/method. The segments in a
| URL normally follow this pattern:
|
|	example.com/class/method/id/
|
| In some instances, however, you may want to remap this relationship
| so that a different class/function is called than the one
| corresponding to the URL.
|
| Please see the user guide for complete details:
|
|	https://codeigniter.com/userguide3/general/routing.html
|
| -------------------------------------------------------------------------
| RESERVED ROUTES
| -------------------------------------------------------------------------
|
| There are three reserved routes:
|
|	$route['default_controller'] = 'welcome';
|
| This route indicates which controller class should be loaded if the
| URI contains no data. In the above example, the "welcome" class
| would be loaded.
|
|	$route['404_override'] = 'errors/page_missing';
|
| This route will tell the Router which controller/method to use if those
| provided in the URL cannot be matched to a valid route.
|
|	$route['translate_uri_dashes'] = FALSE;
|
| This is not exactly a route, but allows you to automatically route
| controller and method names that contain dashes. '-' isn't a valid
| class or method name character, so it requires translation.
| When you set this option to TRUE, it will replace ALL dashes in the
| controller and method URI segments.
|
| Examples:	my-controller/index	-> my_controller/index
|		my-controller/my-method	-> my_controller/my_method
*/

$env = [];
if (file_exists(FCPATH . '.env')) {
    $lines = file(FCPATH . '.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        list($key, $value) = explode('=', $line, 2);
        $env[trim($key)] = trim($value);
    }
}

$baseUrl = $env['BASE_URL'];
$domain = parse_url($baseUrl, PHP_URL_HOST);

if(isset($_SERVER['SERVER_NAME'])){
    if ( $_SERVER['SERVER_NAME'] === $domain && isset($_GET['gl']) ) {
		$route['default_controller'] = 'Guest_List';
	} else {
		$route['default_controller'] = 'Dashboard';
	}
}


$route['404_override'] = '';
$route['translate_uri_dashes'] = FALSE;
$route['customer/search'] = 'customer/search';
$route['customer/check_duplicate'] = 'customer/check_duplicate';
$route['ghl-details'] = 'Ghl_Details';
$route['ghl-custom-field-viewer'] = 'Ghl_Custom_Field_Viewer';
$route['message-log'] = 'Message_Log';
$route['message-log-csv'] = 'Message_Log/csv';

// Public, token-gated customer Quotation PDF under the /Costing namespace. Maps to
// the standalone Costing_Quotation controller (no staff login) — the real Costing
// controller is auth-gated, so a customer link must resolve here instead.
// Addressed by the unguessable 32-hex quotation_token (never the sequential package
// id) so links can't be enumerated — numeric ids fall through and 404.
$route['Costing/Quotation/([a-f0-9]{32})'] = 'Costing_Quotation/index/$1';
$route['costing/quotation/([a-f0-9]{32})'] = 'Costing_Quotation/index/$1';
$route['Costing/Quotation'] = 'Costing_Quotation';
$route['costing/quotation'] = 'Costing_Quotation';

// Map lowercase CLI URIs to the mixed-case controller class so they resolve on
// case-sensitive (Linux) prod filesystems, not just case-insensitive local ones.
$route['backfill_team_snapshot_cutoff'] = 'Backfill_Team_Snapshot_Cutoff';
$route['backfill_team_snapshot_cutoff/(:any)'] = 'Backfill_Team_Snapshot_Cutoff/$1';



/*
|--------------------------------------------------------------------------
| Customer Portal
|--------------------------------------------------------------------------
|
*/
$route['customer/booking/(:any)/invoice-split/save'] = 'Customer_Portal/save_invoice_split/$1';
$route['customer/booking/(:any)/invoice-split/submit'] = 'Customer_Portal/submit_invoice_split/$1';
$route['customer/booking/(:any)/invoice-split'] = 'Customer_Portal/get_invoice_split/$1';
$route['customer/booking/(:any)/review'] = 'Customer_Portal/submit_review/$1';
$route['customer/booking/(:any)/remarks'] = 'Customer_Portal/get_customer_remarks/$1';
$route['customer/booking/(:any)/remark'] = 'Customer_Portal/add_customer_remark/$1';
$route['customer/booking/(:any)'] = 'Customer_Portal/booking_details/$1';
$route['customer/(:any)/verify'] = 'Customer_Portal/verify_phone/$1';
$route['customer/(:any)'] = 'Customer_Portal/dashboard/$1';

/*
|--------------------------------------------------------------------------
| FAQ per-FAQ page
|--------------------------------------------------------------------------
| Each FAQ gets its own page at /faq/<slug>, served by Faq::Page() and
| login-protected via MY_Controller. The admin actions live under capital-F
| /Faq/... and are unaffected by this lowercase catch-all.
*/
$route['faq/(:any)'] = 'Faq/Page/$1';
