<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Lead & Customer Access Settings was removed from that menu. Grants now live
 * on Settings > Navigation Access. Keep this controller so old bookmarks
 * still land on the new page.
 */
class Leads_Customer_Access extends MY_Controller
{
	function __construct()
	{
		parent::__construct();
		redirect('Nav_Access');
	}

	function index()
	{
		redirect('Nav_Access');
	}

	function Save()
	{
		redirect('Nav_Access');
	}
}
