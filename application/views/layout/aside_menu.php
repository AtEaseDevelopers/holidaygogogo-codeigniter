<?php
defined('BASEPATH') OR exit('No direct script access allowed');

$sess_level = (int) $this->session->level;
$sess_ac    = (array) $this->session->access_control;
$router     = $this->router->class;
$method     = $this->router->method;
$is_owner   = ($sess_level === 10);

$can_dashboard = nav_can_view('dashboard');
$can_booking   = nav_can_view('booking');
$can_payment   = nav_can_view('payment') || nav_can_view('payment_due_soon');
$can_costing   = nav_can_view('costing') || nav_can_view('costing_item') || nav_can_view('costing_category') || nav_can_view('costing_currency');
$can_product   = nav_can_view('product');
$can_supplier  = nav_can_view('supplier');
$can_category  = nav_can_view('category');
$can_footer    = nav_can_view('footer');
$can_faq       = nav_can_view('faq') || nav_can_view('faq_tag');
$can_admin     = nav_can_view('admin');
$can_reports   = nav_can_view('powerbi_report') || nav_can_view('powerbi_builder') || nav_can_view('lead_ownership') || nav_can_view('leads_by_hour') || nav_can_view('lead_reply_activity') || nav_can_view('message_log');

$show_campaign    = nav_can_view('campaign');
$show_lead_status = nav_can_view('lead_status');
$show_nob         = nav_can_view('nature_of_business');
$show_customer    = nav_can_view('customer');
$show_guests      = nav_can_view('guests');
$show_ghl         = nav_can_view('ghl_leads');
$show_manual      = nav_can_view('manual_leads');
$show_customer_type = nav_can_view('customer_type');
$show_lc_master   = ($show_lead_status || $show_nob || $show_customer_type);
$show_lc = ($show_customer || $show_guests || $show_ghl || $show_manual || $show_lc_master || nav_can_view('hot_cold_customers'));

$lc_active = in_array($router, array('Customer', 'Guests', 'Ghl_Leads', 'Manual_Leads', 'Lead_Status', 'Nature_Of_Business', 'Customer_Type', 'Merge_Duplicate_Customers', 'Hot_Cold_Customers'), true);
$booking_master_classes = array('Source', 'Package_Checklist', 'Product_Package_Checklist', 'Cancellation_Reason', 'Slow_Conversion_Reason', 'Quick_Filter', 'Footer');
$booking_active = ($router === 'Booking' || in_array($router, $booking_master_classes, true));
$booking_master_active = in_array($router, $booking_master_classes, true);
$show_booking_masters = (
	nav_can_view('source') || nav_can_view('package_checklist') || nav_can_view('cancellation_reason')
	|| nav_can_view('slow_conversion_reason') || nav_can_view('quick_filter') || $can_footer
);
$show_booking = ($can_booking || $show_booking_masters);
$product_classes = array('Product', 'Supplier', 'Category', 'Category_Code', 'Tag', 'Country_Code');
$product_active = in_array($router, $product_classes, true);
$product_master_active = in_array($router, array('Category', 'Category_Code', 'Tag', 'Country_Code'), true);
$show_product_supplier = ($can_product || $can_supplier || $can_category || nav_can_view('category_code') || nav_can_view('product_tag') || nav_can_view('country_code'));
$costing_active = in_array($router, array('Costing', 'Costing_Item', 'Costing_Category'), true);
$costing_master_active = ($router === 'Costing_Item' || $router === 'Costing_Category' || ($router === 'Costing' && $method === 'Currency'));
$faq_active = ($router === 'Faq' || $router === 'Faq_Tag');
$dash_config_active = in_array($router, array('Agent_Score_Setting', 'Card_Visibility_Setting'), true);
$settings_active = in_array($router, array('Admin', 'Team', 'Company', 'Agent_Score_Setting', 'Card_Visibility_Setting', 'Ai_Usage', 'Nav_Access'), true);
$show_settings = ($can_admin || nav_can_view('team') || nav_can_view('agent_score') || nav_can_view('card_visibility') || nav_can_view('ai_usage') || $is_owner);
$analysis_active = in_array($router, array('Competitor_Product', 'Our_Product', 'Master_Product'), true);
$payment_upcoming = ($router === 'Payment' && $this->input->get('view_mode') === 'upcoming_due');

$menu_icon = '<span class="svg-icon menu-icon"><svg><g>'
	. '<path d="M12.9336061, 16.072447 L19.36, 10.9564761 L19.5181585, 10.8312381 C20.1676248, 10.3169571 20.2772143, 9.3735535 19.7629333, 8.72408713 C19.6917232, 8.63415859 19.6104327, 8.55269514 19.5206557, 8.48129411 L12.9336854, 3.24257445 C12.3871201, 2.80788259 11.6128799, 2.80788259 11.0663146, 3.24257445 L4.47482784, 8.48488609 C3.82645598, 9.00054628 3.71887192, 9.94418071 4.23453211, 10.5925526 C4.30500305, 10.6811601 4.38527899, 10.7615046 4.47382636, 10.8320511 L4.63, 10.9564761 L11.0659024, 16.0730648 C11.6126744, 16.5077525 12.3871218, 16.5074963 12.9336061, 16.072447 Z" fill="#000000"></path>'
	. '<path d="M11.0563554, 18.6706981 L5.33593024, 14.122919 C4.94553994, 13.8125559 4.37746707, 13.8774308 4.06710397, 14.2678211 C4.06471678, 14.2708238 4.06234874, 14.2738418 4.06, 14.2768747 L4.06, 14.2768747 C3.75257288, 14.6738539 3.82516916, 15.244888 4.22214834, 15.5523151 C4.22358765, 15.5534297 4.2250303, 15.55454 4.22647627, 15.555646 L11.0872776, 20.8031356 C11.6250734, 21.2144692 12.371757, 21.2145375 12.909628, 20.8033023 L19.7677785, 15.559828 C20.1693192, 15.2528257 20.2459576, 14.6784381 19.9389553, 14.2768974 C19.9376429, 14.2751809 19.9363245, 14.2734691 19.935, 14.2717619 L19.935, 14.2717619 C19.6266937, 13.8743807 19.0546209, 13.8021712 18.6572397, 14.1104775 C18.654352, 14.112718 18.6514778, 14.1149757 18.6486172, 14.1172508 L12.9235044, 18.6705218 C12.377022, 19.1051477 11.6029199, 19.1052208 11.0563554, 18.6706981 Z" fill="#000000" opacity="0.3"></path>'
	. '</g></svg></span>';
?>
<ul class="menu-nav">
	<?php if ($can_dashboard) { ?>
	<li class="menu-item <?php if ($router === 'Dashboard') { echo 'menu-item-active'; } ?>">
		<a href="<?php echo base_url('Dashboard'); ?>" class="menu-link">
			<?php echo $menu_icon; ?>
			<span class="menu-text">Dashboard</span>
		</a>
	</li>
	<?php } ?>

	<?php if ($show_lc) { ?>
	<li class="menu-item menu-item-submenu <?php if ($lc_active) { echo 'menu-item-active menu-item-open'; } ?>">
		<a href="javascript:;" class="menu-link menu-toggle">
			<?php echo $menu_icon; ?>
			<span class="menu-text">Lead &amp; Customer</span>
			<i class="menu-arrow"></i>
		</a>
		<div class="menu-submenu">
			<i class="menu-arrow"></i>
			<ul class="menu-subnav">
				<?php if (nav_can_view('hot_cold_customers')) { ?>
				<li class="menu-item <?php if ($router === 'Hot_Cold_Customers') { echo 'menu-item-active'; } ?>">
					<a href="<?php echo base_url('Hot_Cold_Customers'); ?>" class="menu-link">
						<i class="menu-bullet menu-bullet-dot"><span></span></i>
						<span class="menu-text">Hot / Cold Customers</span>
					</a>
				</li>
				<?php } ?>
				<?php if ($show_customer) { ?>
				<li class="menu-item <?php if ($router === 'Customer') { echo 'menu-item-active'; } ?>">
					<a href="<?php echo base_url('Customer'); ?>" class="menu-link">
						<i class="menu-bullet menu-bullet-dot"><span></span></i>
						<span class="menu-text">Customer</span>
					</a>
				</li>
				<?php } ?>
				<?php if ($show_guests) { ?>
				<li class="menu-item <?php if ($router === 'Guests') { echo 'menu-item-active'; } ?>">
					<a href="<?php echo base_url('Guests'); ?>" class="menu-link">
						<i class="menu-bullet menu-bullet-dot"><span></span></i>
						<span class="menu-text">Guest List</span>
					</a>
				</li>
				<?php } ?>
				<?php if ($show_ghl) { ?>
				<li class="menu-item <?php if ($router === 'Ghl_Leads') { echo 'menu-item-active'; } ?>">
					<a href="<?php echo base_url('Ghl_Leads'); ?>" class="menu-link">
						<i class="menu-bullet menu-bullet-dot"><span></span></i>
						<span class="menu-text">GHL Leads</span>
					</a>
				</li>
				<?php } ?>
				<?php if ($show_manual) { ?>
				<li class="menu-item <?php if ($router === 'Manual_Leads') { echo 'menu-item-active'; } ?>">
					<a href="<?php echo base_url('Manual_Leads'); ?>" class="menu-link">
						<i class="menu-bullet menu-bullet-dot"><span></span></i>
						<span class="menu-text">Manual Leads</span>
					</a>
				</li>
				<?php } ?>
				<?php if ($show_lc_master) { ?>
				<li class="menu-item menu-item-submenu <?php if (in_array($router, array('Lead_Status', 'Nature_Of_Business', 'Customer_Type'), true)) { echo 'menu-item-active menu-item-open'; } ?>">
					<a href="javascript:;" class="menu-link menu-toggle">
						<i class="menu-bullet menu-bullet-dot"><span></span></i>
						<span class="menu-text">Master Setup</span>
						<i class="menu-arrow"></i>
					</a>
					<div class="menu-submenu">
						<i class="menu-arrow"></i>
						<ul class="menu-subnav">
							<?php if ($show_lead_status) { ?>
							<li class="menu-item <?php if ($router === 'Lead_Status') { echo 'menu-item-active'; } ?>">
								<a href="<?php echo base_url('Lead_Status'); ?>" class="menu-link">
									<i class="menu-bullet menu-bullet-dot"><span></span></i>
									<span class="menu-text">Lead Status</span>
								</a>
							</li>
							<?php } ?>
							<?php if ($show_nob) { ?>
							<li class="menu-item <?php if ($router === 'Nature_Of_Business') { echo 'menu-item-active'; } ?>">
								<a href="<?php echo base_url('Nature_Of_Business'); ?>" class="menu-link">
									<i class="menu-bullet menu-bullet-dot"><span></span></i>
									<span class="menu-text">Nature of Business</span>
								</a>
							</li>
							<?php } ?>
							<?php if ($show_customer_type) { ?>
							<li class="menu-item <?php if ($router === 'Customer_Type') { echo 'menu-item-active'; } ?>">
								<a href="<?php echo base_url('Customer_Type'); ?>" class="menu-link">
									<i class="menu-bullet menu-bullet-dot"><span></span></i>
									<span class="menu-text">Customer Type</span>
								</a>
							</li>
							<?php } ?>
						</ul>
					</div>
				</li>
				<?php } ?>
			</ul>
		</div>
	</li>
	<?php } ?>

	<?php if ($show_campaign) { ?>
	<li class="menu-item <?php if ($router === 'Campaign') { echo 'menu-item-active'; } ?>">
		<a href="<?php echo base_url('Campaign'); ?>" class="menu-link">
			<?php echo $menu_icon; ?>
			<span class="menu-text">Campaign</span>
		</a>
	</li>
	<?php } ?>

	<?php if ($show_booking) { ?>
	<li class="menu-item menu-item-submenu <?php if ($booking_active) { echo 'menu-item-active menu-item-open'; } ?>">
		<a href="javascript:;" class="menu-link menu-toggle">
			<?php echo $menu_icon; ?>
			<span class="menu-text">Booking</span>
			<i class="menu-arrow"></i>
		</a>
		<div class="menu-submenu">
			<i class="menu-arrow"></i>
			<ul class="menu-subnav">
				<?php if ($can_booking) { ?>
				<li class="menu-item <?php if ($router === 'Booking') { echo 'menu-item-active'; } ?>">
					<a href="<?php echo base_url('Booking'); ?>" class="menu-link">
						<i class="menu-bullet menu-bullet-dot"><span></span></i>
						<span class="menu-text">Booking Listing</span>
					</a>
				</li>
				<?php } ?>
				<?php if ($show_booking_masters) { ?>
				<li class="menu-item menu-item-submenu <?php if ($booking_master_active) { echo 'menu-item-active menu-item-open'; } ?>">
					<a href="javascript:;" class="menu-link menu-toggle">
						<i class="menu-bullet menu-bullet-dot"><span></span></i>
						<span class="menu-text">Master Setup</span>
						<i class="menu-arrow"></i>
					</a>
					<div class="menu-submenu">
						<i class="menu-arrow"></i>
						<ul class="menu-subnav">
							<?php if (nav_can_view('source')) { ?>
							<li class="menu-item <?php if ($router === 'Source') { echo 'menu-item-active'; } ?>">
								<a href="<?php echo base_url('Source'); ?>" class="menu-link">
									<i class="menu-bullet menu-bullet-dot"><span></span></i>
									<span class="menu-text">Source</span>
								</a>
							</li>
							<?php } ?>
							<?php if (nav_can_view('package_checklist')) { ?>
							<li class="menu-item <?php if ($router === 'Package_Checklist') { echo 'menu-item-active'; } ?>">
								<a href="<?php echo base_url('Package_Checklist'); ?>" class="menu-link">
									<i class="menu-bullet menu-bullet-dot"><span></span></i>
									<span class="menu-text">Package Checklist</span>
								</a>
							</li>
							<?php } ?>
							<?php if (nav_can_view('cancellation_reason')) { ?>
							<li class="menu-item <?php if ($router === 'Cancellation_Reason') { echo 'menu-item-active'; } ?>">
								<a href="<?php echo base_url('Cancellation_Reason'); ?>" class="menu-link">
									<i class="menu-bullet menu-bullet-dot"><span></span></i>
									<span class="menu-text">Cancellation Reason</span>
								</a>
							</li>
							<?php } ?>
							<?php if (nav_can_view('slow_conversion_reason')) { ?>
							<li class="menu-item <?php if ($router === 'Slow_Conversion_Reason') { echo 'menu-item-active'; } ?>">
								<a href="<?php echo base_url('Slow_Conversion_Reason'); ?>" class="menu-link">
									<i class="menu-bullet menu-bullet-dot"><span></span></i>
									<span class="menu-text">Slow Conversion Reason</span>
								</a>
							</li>
							<?php } ?>
							<?php if (nav_can_view('quick_filter')) { ?>
							<li class="menu-item <?php if ($router === 'Quick_Filter') { echo 'menu-item-active'; } ?>">
								<a href="<?php echo base_url('Quick_Filter'); ?>" class="menu-link">
									<i class="menu-bullet menu-bullet-dot"><span></span></i>
									<span class="menu-text">Quick Filter Setup</span>
								</a>
							</li>
							<?php } ?>
							<?php if (nav_can_view('footer')) { ?>
							<li class="menu-item <?php if ($router === 'Footer') { echo 'menu-item-active'; } ?>">
								<a href="<?php echo base_url('Footer'); ?>" class="menu-link">
									<i class="menu-bullet menu-bullet-dot"><span></span></i>
									<span class="menu-text">Footer</span>
								</a>
							</li>
							<?php } ?>
						</ul>
					</div>
				</li>
				<?php } ?>
			</ul>
		</div>
	</li>
	<?php } ?>

	<?php if ($can_payment) { ?>
	<li class="menu-item menu-item-submenu <?php if ($router === 'Payment') { echo 'menu-item-active menu-item-open'; } ?>">
		<a href="javascript:;" class="menu-link menu-toggle">
			<?php echo $menu_icon; ?>
			<span class="menu-text">Payment</span>
			<i class="menu-arrow"></i>
		</a>
		<div class="menu-submenu">
			<i class="menu-arrow"></i>
			<ul class="menu-subnav">
				<?php if (nav_can_view('payment')) { ?>
				<li class="menu-item <?php if ($router === 'Payment' && !$payment_upcoming) { echo 'menu-item-active'; } ?>">
					<a href="<?php echo base_url('Payment'); ?>" class="menu-link">
						<i class="menu-bullet menu-bullet-dot"><span></span></i>
						<span class="menu-text">Payment Listing</span>
					</a>
				</li>
				<?php } ?>
				<?php if (nav_can_view('payment_due_soon')) { ?>
					<?php
						$CI =& get_instance();
						$CI->load->model('Payment_Model');
						$upcoming_due_count = $CI->Payment_Model->Count_Upcoming_Due();
					?>
				<li class="menu-item <?php if ($payment_upcoming) { echo 'menu-item-active'; } ?>">
					<a href="<?php echo base_url('Payment/upcoming_due'); ?>" class="menu-link">
						<i class="menu-bullet menu-bullet-dot"><span></span></i>
						<span class="menu-text">Payment Due Soon</span>
						<?php if ($upcoming_due_count > 0) { ?>
						<span class="menu-label ml-auto">
							<span class="label label-light-danger label-rounded"><?php echo $upcoming_due_count; ?></span>
						</span>
						<?php } ?>
					</a>
				</li>
				<?php } ?>
			</ul>
		</div>
	</li>
	<?php } ?>

	<?php if ($can_costing) { ?>
	<li class="menu-item menu-item-submenu <?php if ($costing_active) { echo 'menu-item-active menu-item-open'; } ?>">
		<a href="javascript:;" class="menu-link menu-toggle">
			<?php echo $menu_icon; ?>
			<span class="menu-text">Costing</span>
			<i class="menu-arrow"></i>
		</a>
		<div class="menu-submenu">
			<i class="menu-arrow"></i>
			<ul class="menu-subnav">
				<?php if (nav_can_view('costing')) { ?>
				<li class="menu-item <?php if ($router === 'Costing' && $method !== 'Currency') { echo 'menu-item-active'; } ?>">
					<a href="<?php echo base_url('Costing'); ?>" class="menu-link">
						<i class="menu-bullet menu-bullet-dot"><span></span></i>
						<span class="menu-text">Costing Package</span>
					</a>
				</li>
				<?php } ?>
				<?php if (nav_can_view('costing_item') || nav_can_view('costing_category') || nav_can_view('costing_currency')) { ?>
				<li class="menu-item menu-item-submenu <?php if ($costing_master_active) { echo 'menu-item-active menu-item-open'; } ?>">
					<a href="javascript:;" class="menu-link menu-toggle">
						<i class="menu-bullet menu-bullet-dot"><span></span></i>
						<span class="menu-text">Master Setup</span>
						<i class="menu-arrow"></i>
					</a>
					<div class="menu-submenu">
						<i class="menu-arrow"></i>
						<ul class="menu-subnav">
							<?php if (nav_can_view('costing_item')) { ?>
							<li class="menu-item <?php if ($router === 'Costing_Item') { echo 'menu-item-active'; } ?>">
								<a href="<?php echo base_url('Costing_Item'); ?>" class="menu-link">
									<i class="menu-bullet menu-bullet-dot"><span></span></i>
									<span class="menu-text">Costing Item</span>
								</a>
							</li>
							<?php } ?>
							<?php if (nav_can_view('costing_category')) { ?>
							<li class="menu-item <?php if ($router === 'Costing_Category') { echo 'menu-item-active'; } ?>">
								<a href="<?php echo base_url('Costing_Category'); ?>" class="menu-link">
									<i class="menu-bullet menu-bullet-dot"><span></span></i>
									<span class="menu-text">Costing Category</span>
								</a>
							</li>
							<?php } ?>
							<?php if (nav_can_view('costing_currency')) { ?>
							<li class="menu-item <?php if ($router === 'Costing' && $method === 'Currency') { echo 'menu-item-active'; } ?>">
								<a href="<?php echo base_url('Costing/Currency'); ?>" class="menu-link">
									<i class="menu-bullet menu-bullet-dot"><span></span></i>
									<span class="menu-text">Costing Currency</span>
								</a>
							</li>
							<?php } ?>
						</ul>
					</div>
				</li>
				<?php } ?>
			</ul>
		</div>
	</li>
	<?php } ?>

	<?php if ($show_product_supplier) { ?>
	<li class="menu-item menu-item-submenu <?php if ($product_active) { echo 'menu-item-active menu-item-open'; } ?>">
		<a href="javascript:;" class="menu-link menu-toggle">
			<?php echo $menu_icon; ?>
			<span class="menu-text">Product &amp; Supplier</span>
			<i class="menu-arrow"></i>
		</a>
		<div class="menu-submenu">
			<i class="menu-arrow"></i>
			<ul class="menu-subnav">
				<?php if ($can_product) { ?>
				<li class="menu-item <?php if ($router === 'Product') { echo 'menu-item-active'; } ?>">
					<a href="<?php echo base_url('Product'); ?>" class="menu-link">
						<i class="menu-bullet menu-bullet-dot"><span></span></i>
						<span class="menu-text">Product List</span>
					</a>
				</li>
				<?php } ?>
				<?php if ($can_supplier) { ?>
				<li class="menu-item <?php if ($router === 'Supplier') { echo 'menu-item-active'; } ?>">
					<a href="<?php echo base_url('Supplier'); ?>" class="menu-link">
						<i class="menu-bullet menu-bullet-dot"><span></span></i>
						<span class="menu-text">Supplier List</span>
					</a>
				</li>
				<?php } ?>
				<?php if (nav_can_view('category') || nav_can_view('category_code') || nav_can_view('product_tag') || nav_can_view('country_code')) { ?>
				<li class="menu-item menu-item-submenu <?php if ($product_master_active) { echo 'menu-item-active menu-item-open'; } ?>">
					<a href="javascript:;" class="menu-link menu-toggle">
						<i class="menu-bullet menu-bullet-dot"><span></span></i>
						<span class="menu-text">Master Setup</span>
						<i class="menu-arrow"></i>
					</a>
					<div class="menu-submenu">
						<i class="menu-arrow"></i>
						<ul class="menu-subnav">
							<?php if (nav_can_view('category')) { ?>
							<li class="menu-item <?php if ($router === 'Category') { echo 'menu-item-active'; } ?>">
								<a href="<?php echo base_url('Category'); ?>" class="menu-link">
									<i class="menu-bullet menu-bullet-dot"><span></span></i>
									<span class="menu-text">Category</span>
								</a>
							</li>
							<?php } ?>
							<?php if (nav_can_view('category_code')) { ?>
							<li class="menu-item <?php if ($router === 'Category_Code') { echo 'menu-item-active'; } ?>">
								<a href="<?php echo base_url('Category_Code'); ?>" class="menu-link">
									<i class="menu-bullet menu-bullet-dot"><span></span></i>
									<span class="menu-text">Category Code</span>
								</a>
							</li>
							<?php } ?>
							<?php if (nav_can_view('product_tag')) { ?>
							<li class="menu-item <?php if ($router === 'Tag') { echo 'menu-item-active'; } ?>">
								<a href="<?php echo base_url('Tag'); ?>" class="menu-link">
									<i class="menu-bullet menu-bullet-dot"><span></span></i>
									<span class="menu-text">Product Tag</span>
								</a>
							</li>
							<?php } ?>
							<?php if (nav_can_view('country_code')) { ?>
							<li class="menu-item <?php if ($router === 'Country_Code') { echo 'menu-item-active'; } ?>">
								<a href="<?php echo base_url('Country_Code'); ?>" class="menu-link">
									<i class="menu-bullet menu-bullet-dot"><span></span></i>
									<span class="menu-text">Country Code</span>
								</a>
							</li>
							<?php } ?>
						</ul>
					</div>
				</li>
				<?php } ?>
			</ul>
		</div>
	</li>
	<?php } ?>

	<?php if (nav_can_view('master_product') || nav_can_view('competitor_product') || nav_can_view('our_product')) { ?>
	<li class="menu-item menu-item-submenu <?php if ($analysis_active) { echo 'menu-item-active menu-item-open'; } ?>">
		<a href="javascript:;" class="menu-link menu-toggle">
			<?php echo $menu_icon; ?>
			<span class="menu-text">Product Analysis</span>
			<i class="menu-arrow"></i>
		</a>
		<div class="menu-submenu">
			<i class="menu-arrow"></i>
			<ul class="menu-subnav">
				<?php if (nav_can_view('master_product')) { ?>
				<li class="menu-item <?php if ($router === 'Master_Product') { echo 'menu-item-active'; } ?>">
					<a href="<?php echo base_url('Master_Product'); ?>" class="menu-link">
						<i class="menu-bullet menu-bullet-dot"><span></span></i>
						<span class="menu-text">Master Product</span>
					</a>
				</li>
				<?php } ?>
				<?php if (nav_can_view('competitor_product')) { ?>
				<li class="menu-item <?php if ($router === 'Competitor_Product') { echo 'menu-item-active'; } ?>">
					<a href="<?php echo base_url('Competitor_Product'); ?>" class="menu-link">
						<i class="menu-bullet menu-bullet-dot"><span></span></i>
						<span class="menu-text">Competitor Product</span>
					</a>
				</li>
				<?php } ?>
				<?php if (nav_can_view('our_product')) { ?>
				<li class="menu-item <?php if ($router === 'Our_Product') { echo 'menu-item-active'; } ?>">
					<a href="<?php echo base_url('Our_Product'); ?>" class="menu-link">
						<i class="menu-bullet menu-bullet-dot"><span></span></i>
						<span class="menu-text">Our Product</span>
					</a>
				</li>
				<?php } ?>
			</ul>
		</div>
	</li>
	<?php } ?>

	<?php if ($can_faq) { ?>
	<li class="menu-item menu-item-submenu <?php if ($faq_active) { echo 'menu-item-active menu-item-open'; } ?>">
		<a href="javascript:;" class="menu-link menu-toggle">
			<?php echo $menu_icon; ?>
			<span class="menu-text">FAQ</span>
			<i class="menu-arrow"></i>
		</a>
		<div class="menu-submenu">
			<i class="menu-arrow"></i>
			<ul class="menu-subnav">
				<?php if (nav_can_view('faq')) { ?>
				<li class="menu-item <?php if ($router === 'Faq') { echo 'menu-item-active'; } ?>">
					<a href="<?php echo base_url('Faq'); ?>" class="menu-link">
						<i class="menu-bullet menu-bullet-dot"><span></span></i>
						<span class="menu-text">FAQ</span>
					</a>
				</li>
				<?php } ?>
				<?php if (nav_can_view('faq_tag')) { ?>
				<li class="menu-item menu-item-submenu <?php if ($router === 'Faq_Tag') { echo 'menu-item-active menu-item-open'; } ?>">
					<a href="javascript:;" class="menu-link menu-toggle">
						<i class="menu-bullet menu-bullet-dot"><span></span></i>
						<span class="menu-text">Master Setup</span>
						<i class="menu-arrow"></i>
					</a>
					<div class="menu-submenu">
						<i class="menu-arrow"></i>
						<ul class="menu-subnav">
							<li class="menu-item <?php if ($router === 'Faq_Tag') { echo 'menu-item-active'; } ?>">
								<a href="<?php echo base_url('Faq_Tag'); ?>" class="menu-link">
									<i class="menu-bullet menu-bullet-dot"><span></span></i>
									<span class="menu-text">FAQ Tag</span>
								</a>
							</li>
						</ul>
					</div>
				</li>
				<?php } ?>
			</ul>
		</div>
	</li>
	<?php } ?>

	<?php if ($can_reports) { ?>
	<li class="menu-item menu-item-submenu <?php if ($router === 'Report') { echo 'menu-item-active menu-item-open'; } ?>">
		<a href="javascript:;" class="menu-link menu-toggle">
			<?php echo $menu_icon; ?>
			<span class="menu-text">Reports</span>
			<i class="menu-arrow"></i>
		</a>
		<div class="menu-submenu">
			<i class="menu-arrow"></i>
			<ul class="menu-subnav">
				<?php if (nav_can_view('powerbi_report')) { ?>
				<li class="menu-item <?php if ($method === 'PowerBI_Reports' || $method === 'PowerBI_View') { echo 'menu-item-active'; } ?>">
					<a href="<?php echo base_url('Report/PowerBI_Reports'); ?>" class="menu-link">
						<i class="menu-bullet menu-bullet-dot"><span></span></i>
						<span class="menu-text">Power BI Report</span>
					</a>
				</li>
				<?php } ?>
				<?php if (nav_can_view('powerbi_builder')) { ?>
				<li class="menu-item <?php if ($method === 'PowerBI') { echo 'menu-item-active'; } ?>">
					<a href="<?php echo base_url('Report/PowerBI'); ?>" class="menu-link">
						<i class="menu-bullet menu-bullet-dot"><span></span></i>
						<span class="menu-text">Power BI Builder</span>
					</a>
				</li>
				<?php } ?>
				<?php if (nav_can_view('lead_ownership')) { ?>
				<li class="menu-item <?php if ($method === 'Lead_Ownership_Dashboard') { echo 'menu-item-active'; } ?>">
					<a href="<?php echo base_url('Report/Lead_Ownership_Dashboard'); ?>" class="menu-link">
						<i class="menu-bullet menu-bullet-dot"><span></span></i>
						<span class="menu-text">Lead Ownership</span>
					</a>
				</li>
				<?php } ?>
				<?php if (nav_can_view('leads_by_hour')) { ?>
				<li class="menu-item <?php if ($method === 'Leads_By_Hour') { echo 'menu-item-active'; } ?>">
					<a href="<?php echo base_url('Report/Leads_By_Hour'); ?>" class="menu-link">
						<i class="menu-bullet menu-bullet-dot"><span></span></i>
						<span class="menu-text">Leads by Hour</span>
					</a>
				</li>
				<?php } ?>
				<?php if (nav_can_view('lead_reply_activity')) { ?>
				<li class="menu-item <?php if ($method === 'Lead_Reply_Activity_Dashboard') { echo 'menu-item-active'; } ?>">
					<a href="<?php echo base_url('Report/Lead_Reply_Activity_Dashboard'); ?>" class="menu-link">
						<i class="menu-bullet menu-bullet-dot"><span></span></i>
						<span class="menu-text">Lead Reply Activity</span>
					</a>
				</li>
				<?php } ?>
				<?php if (nav_can_view('message_log')) { ?>
				<li class="menu-item <?php if ($method === 'Ghl_Message_Log') { echo 'menu-item-active'; } ?>">
					<a href="<?php echo base_url('Report/Ghl_Message_Log'); ?>" class="menu-link">
						<i class="menu-bullet menu-bullet-dot"><span></span></i>
						<span class="menu-text">Message Log</span>
					</a>
				</li>
				<?php } ?>
			</ul>
		</div>
	</li>
	<?php } ?>

	<?php if ($show_settings) { ?>
	<li class="menu-item menu-item-submenu <?php if ($settings_active) { echo 'menu-item-active menu-item-open'; } ?>">
		<a href="javascript:;" class="menu-link menu-toggle">
			<?php echo $menu_icon; ?>
			<span class="menu-text">Settings</span>
			<i class="menu-arrow"></i>
		</a>
		<div class="menu-submenu">
			<i class="menu-arrow"></i>
			<ul class="menu-subnav">
				<?php if ($can_admin) { ?>
				<li class="menu-item <?php if ($router === 'Admin') { echo 'menu-item-active'; } ?>">
					<a href="<?php echo base_url('Admin'); ?>" class="menu-link">
						<i class="menu-bullet menu-bullet-dot"><span></span></i>
						<span class="menu-text">Admin</span>
					</a>
				</li>
				<?php } ?>
				<?php if (nav_can_view('team')) { ?>
				<li class="menu-item <?php if ($router === 'Team') { echo 'menu-item-active'; } ?>">
					<a href="<?php echo base_url('Team'); ?>" class="menu-link">
						<i class="menu-bullet menu-bullet-dot"><span></span></i>
						<span class="menu-text">Team</span>
					</a>
				</li>
				<?php } ?>
				<?php if (nav_can_view('agent_score') || nav_can_view('card_visibility')) { ?>
				<li class="menu-item menu-item-submenu <?php if ($dash_config_active) { echo 'menu-item-active menu-item-open'; } ?>">
					<a href="javascript:;" class="menu-link menu-toggle">
						<i class="menu-bullet menu-bullet-dot"><span></span></i>
						<span class="menu-text">Dashboard Configuration</span>
						<i class="menu-arrow"></i>
					</a>
					<div class="menu-submenu">
						<i class="menu-arrow"></i>
						<ul class="menu-subnav">
							<?php if (nav_can_view('agent_score')) { ?>
							<li class="menu-item <?php if ($router === 'Agent_Score_Setting') { echo 'menu-item-active'; } ?>">
								<a href="<?php echo base_url('Agent_Score_Setting'); ?>" class="menu-link">
									<i class="menu-bullet menu-bullet-dot"><span></span></i>
									<span class="menu-text">Agent Score</span>
								</a>
							</li>
							<?php } ?>
							<?php if (nav_can_view('card_visibility')) { ?>
							<li class="menu-item <?php if ($router === 'Card_Visibility_Setting') { echo 'menu-item-active'; } ?>">
								<a href="<?php echo base_url('Card_Visibility_Setting'); ?>" class="menu-link">
									<i class="menu-bullet menu-bullet-dot"><span></span></i>
									<span class="menu-text">Card Visibility</span>
								</a>
							</li>
							<?php } ?>
						</ul>
					</div>
				</li>
				<?php } ?>
				<?php if (nav_can_view('ai_usage')) { ?>
				<li class="menu-item <?php if ($router === 'Ai_Usage') { echo 'menu-item-active'; } ?>">
					<a href="<?php echo base_url('Ai_Usage'); ?>" class="menu-link">
						<i class="menu-bullet menu-bullet-dot"><span></span></i>
						<span class="menu-text">AI Cost &amp; Usage</span>
					</a>
				</li>
				<?php } ?>
				<?php if ($is_owner) { ?>
				<li class="menu-item <?php if ($router === 'Nav_Access') { echo 'menu-item-active'; } ?>">
					<a href="<?php echo base_url('Nav_Access'); ?>" class="menu-link">
						<i class="menu-bullet menu-bullet-dot"><span></span></i>
						<span class="menu-text">Navigation Access</span>
					</a>
				</li>
				<?php } ?>
			</ul>
		</div>
	</li>
	<?php } ?>
</ul>
