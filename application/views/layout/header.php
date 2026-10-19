<?php 
$this->load->helper('utils');
$app_env = get_app_env();
$is_dev_env = ($app_env !== 'prod');
?>
<!DOCTYPE html>
<html lang="en">

<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
	<title><?php echo $tab_title; ?></title>
	<link href="<?php echo base_url('assets/image/favicon.png'); ?>" rel="icon">
    <link href="<?php echo base_url('assets/image/favicon.png'); ?>" rel="apple-touch-icon">
	<link href="https://fonts.googleapis.com/css?family=Poppins:300,400,500,600,700" rel="stylesheet">
	<link href="<?php echo base_url('assets/css/datatables-bundle.css?param=qiqithay'); ?>" type="text/css" rel="stylesheet">
	<link href="<?php echo base_url('assets/css/plugins-bundle.css'); ?>" type="text/css" rel="stylesheet">
	<link href="<?php echo base_url('assets/css/prismjs-bundle.css'); ?>" type="text/css" rel="stylesheet">
	<link href="<?php echo base_url('assets/css/style-bundle.css?param=kiriel'); ?>" type="text/css" rel="stylesheet">
	<link href="<?php echo base_url('assets/css/dark.css'); ?>" type="text/css" rel="stylesheet">
	<link href="<?php echo base_url('assets/css/skin.min.css'); ?>" type="text/css" rel="stylesheet">
	<script src="<?php echo base_url('assets/js/plugins-bundle.js?param=qiqi'); ?>"></script>
	<script src="<?php echo base_url('assets/js/jquery-dirty.js'); ?>"></script>
</head>

<style type="text/css">
	/*
	 * Fix: table "Action" dropdowns opening only once.
	 * Metronic's KTApp.initAbsoluteDropdown('.dataTables_wrapper') (datatables-bundle.js)
	 * detaches an open dropdown-menu to <body>, and on close calls $menu.hide() which
	 * leaves an inline style="display:none". It never restores display on the next open,
	 * so after the first open every later open adds .show but stays display:none (invisible).
	 * Let the .show state win over that stray inline display:none.
	 */
	.dropdown-menu.show {
		display: block !important;
	}

	input::-webkit-outer-spin-button,
	input::-webkit-inner-spin-button {
		-webkit-appearance: none;
		margin: 0;
	}

	input[type=number] {
		-moz-appearance: textfield;
	}

	/* Fix left menu scrolling */
	.aside-menu-wrapper {
		height: calc(100vh - 60px);
		overflow: hidden;
		display: flex;
		flex-direction: column;
	}

	#kt_aside_menu {
		overflow-y: auto !important;
		overflow-x: hidden !important;
		flex: 1;
		height: 100%;
		scrollbar-width: none; /* Firefox */
		-ms-overflow-style: none; /* IE and Edge */
	}

	#kt_aside_menu::-webkit-scrollbar {
		display: none; /* Chrome, Safari, Opera */
	}

	/* Notification dropdown: flex layout so header + "Mark all as read" footer stay pinned while only the list scrolls. Scoped to .show so Bootstrap's default display:none keeps the dropdown hidden on page load. */
	#notification-dropdown.show {
		display: flex !important;
		flex-direction: column;
	}

	/* Mobile: pin remarks + notification dropdowns to the viewport with equal gutters so they sit centered instead of overflowing at their fixed 400–420px widths or hugging one edge. position: fixed + transform: none overrides Popper.js's inline absolute placement. */
	@media (max-width: 576px) {
		#remarks-dropdown,
		#notification-dropdown {
			position: fixed !important;
			top: 60px !important;
			left: 10px !important;
			right: 10px !important;
			width: auto !important;
			max-width: none !important;
			transform: none !important;
		}
		#notification-dropdown {
			max-height: calc(100vh - 80px) !important;
		}
		#remarks-dropdown .tab-content {
			max-height: calc(100vh - 200px) !important;
		}
	}

	<?php if ($is_dev_env) { ?>
	/* Adjust header position when dev banner is visible */
	#dev-env-banner {
		height: auto;
		min-height: 40px;
	}
	.header.header-fixed {
		top: 40px !important;
	}
	.header-mobile.header-mobile-fixed {
		top: 40px !important;
	}
	/* Adjust aside menu height to account for banner */
	.aside-menu-wrapper {
		height: calc(100vh - 100px) !important;
	}
	/* Adjust wrapper to account for banner */
	.wrapper {
		margin-top: 0 !important;
	}
	<?php } ?>
</style>

<body class="header-fixed header-mobile-fixed subheader-enabled subheader-fixed aside-enabled aside-fixed aside-minimize-hoverable page-loading">
	<?php if ($is_dev_env) { ?>
	<div id="dev-env-banner" style="background-color: #FFA500; color: #000; text-align: center; padding: 10px; font-size: 14px; position: fixed; top: 0; left: 0; right: 0; z-index: 9999; box-shadow: 0 2px 4px rgba(0,0,0,0.2); line-height: 1.4;">
		This is a development environment. It is safe to make any changes here.
	</div>
	<?php } ?>
	<div class="header-mobile align-items-center header-mobile-fixed" style="background-color:black;">
		<a href="<?php echo base_url('Dashboard'); ?>">
			<img src="">
		</a>
		<div class="d-flex align-items-center">
			<button id="kt_aside_mobile_toggle" class="btn p-0 burger-icon burger-icon-left">
				<span></span>
			</button>
			<button id="kt_header_mobile_topbar_toggle" class="btn btn-hover-text-primary p-0 ml-2">
				<span class="svg-icon svg-icon-xl">
					<svg>
						<g>
							<path d="M12, 11 C9.790861, 11 8, 9.209139 8, 7 C8, 4.790861 9.790861, 3 12, 3 C14.209139, 3 16, 4.790861 16, 7 C16, 9.209139 14.209139, 11 12, 11 Z" fill="#000000" opacity="0.3"></path>
							<path d="M3.00065168, 20.1992055 C3.38825852, 15.4265159 7.26191235, 13 11.9833413, 13 C16.7712164, 13 20.7048837, 15.2931929 20.9979143, 20.2 C21.0095879, 20.3954741 20.9979143, 21 20.2466999, 21 C16.541124, 21 11.0347247, 21 3.72750223, 21 C3.47671215, 21 2.97953825, 20.45918 3.00065168, 20.1992055 Z" fill="#000000"></path>
						</g>
					</svg>
				</span>
			</button>
		</div>
	</div>
	<div class="d-flex flex-column flex-root">
		<div class="d-flex flex-row flex-column-fluid page">
			<div id="kt_aside" class="aside aside-left aside-fixed d-flex flex-column flex-row-auto">
				<div class="brand flex-column-auto mt-6">
					<a href="<?php echo base_url('Dashboard'); ?>" class="brand-logo">
						<img src="<?php echo base_url('assets/image/logo.png'); ?>" style="width: 100%;">
					</a>
					<button id="kt_aside_toggle" class="brand-toggle btn btn-sm px-0">
						<span class="svg-icon svg-icon-xl">
							<svg>
								<g>
									<path d="M5.29288961, 6.70710318 C4.90236532, 6.31657888 4.90236532, 5.68341391 5.29288961, 5.29288961 C5.68341391, 4.90236532 6.31657888, 4.90236532 6.70710318, 5.29288961 L12.7071032, 11.2928896 C13.0856821, 11.6714686 13.0989277, 12.281055 12.7371505, 12.675721 L7.23715054, 18.675721 C6.86395813, 19.08284 6.23139076, 19.1103429 5.82427177, 18.7371505 C5.41715278, 18.3639581 5.38964985, 17.7313908 5.76284226, 17.3242718 L10.6158586, 12.0300721 L5.29288961, 6.70710318 Z" fill="#000000" transform="translate(8.999997, 11.999999) scale(-1, 1) translate(-8.999997, -11.999999)"></path>
									<path d="M10.7071009, 15.7071068 C10.3165766, 16.0976311 9.68341162, 16.0976311 9.29288733, 15.7071068 C8.90236304, 15.3165825 8.90236304, 14.6834175 9.29288733, 14.2928932 L15.2928873, 8.29289322 C15.6714663, 7.91431428 16.2810527, 7.90106866 16.6757187, 8.26284586 L22.6757187, 13.7628459 C23.0828377, 14.1360383 23.1103407, 14.7686056 22.7371482, 15.1757246 C22.3639558, 15.5828436 21.7313885, 15.6103465 21.3242695, 15.2371541 L16.0300699, 10.3841378 L10.7071009, 15.7071068 Z" fill="#000000" opacity="0.3" transform="translate(15.999997, 11.999999) scale(-1, 1) rotate(-270.000000) translate(-15.999997, -11.999999)"></path>
								</g>
							</svg>
						</span>
					</button>
				</div>
				<div class="aside-menu-wrapper flex-column-fluid">
					<div id="kt_aside_menu" class="aside-menu my-4 scroll ps ps--active-y">
						<?php $this->load->view('layout/aside_menu'); ?>
					</div>
				</div>
			</div>
		</div>
		<div class="d-flex flex-column flex-row-fluid wrapper">
			<div class="header header-fixed" style="background-color:#EEF0F8;">
				<div class="container-fluid d-flex align-items-stretch justify-content-between">
					<div class="header-menu-wrapper header-menu-wrapper-left">
						<div class="header-menu header-menu-mobile header-menu-layout-default"></div>
					</div>
					<div class="topbar">
						<!-- Remarks/Messages Dropdown -->
						<div class="topbar-item position-relative">
							<div class="btn btn-icon btn-clean btn-lg mr-1 position-relative" id="kt_remarks_toggle" data-toggle="dropdown" data-offset="10px,10px">
								<i class="la la-comment-dots la-2x text-primary"></i>
								<span class="label label-lg label-light-danger label-inline label-rounded position-absolute" id="remarks-badge" style="top: -5px; right: -5px; display: none; min-width: 20px; padding: 2px 6px;">0</span>
							</div>
							<div class="dropdown-menu dropdown-menu-right p-0 m-0 dropdown-menu-anim-up dropdown-menu-lg" id="remarks-dropdown" style="width: 420px; right: 0; left: auto;">
								<div class="d-flex align-items-center justify-content-between p-5 border-bottom">
									<h5 class="mb-0">Messages</h5>
									<a href="javascript:;" class="btn btn-xs btn-icon btn-light btn-hover-primary" id="kt_remarks_close">
										<i class="ki ki-close icon-xs text-muted"></i>
									</a>
								</div>
								<ul class="nav nav-tabs nav-tabs-line nav-tabs-bold px-5 pt-2 mb-0" role="tablist">
									<li class="nav-item">
										<a class="nav-link active" data-toggle="tab" href="#remarks-tab-internal" role="tab" data-type="1">Internal Comments</a>
									</li>
									<li class="nav-item">
										<a class="nav-link" data-toggle="tab" href="#remarks-tab-customer" role="tab" data-type="2">Customer Remarks</a>
									</li>
								</ul>
								<div class="tab-content" style="max-height: 400px; overflow-y: auto;">
									<div class="tab-pane fade show active" id="remarks-tab-internal" role="tabpanel">
										<div class="remarks-list" data-type="1">
											<div class="text-center p-10">
												<div class="spinner spinner-primary spinner-lg"></div>
												<div class="mt-3">Loading...</div>
											</div>
										</div>
										<div class="text-center py-2 d-none" id="mark-read-internal">
											<a href="javascript:;" class="mark-tab-remarks-read text-primary font-weight-bold font-size-sm" data-type="1">Mark all as read</a>
										</div>
										<div class="text-center py-3 border-top d-none" id="load-more-internal">
											<a href="javascript:;" class="btn btn-sm btn-light-primary font-weight-bold load-more-remarks" data-type="1">Load More</a>
										</div>
									</div>
									<div class="tab-pane fade" id="remarks-tab-customer" role="tabpanel">
										<div class="remarks-list" data-type="2">
											<div class="text-center p-10">
												<div class="spinner spinner-primary spinner-lg"></div>
												<div class="mt-3">Loading...</div>
											</div>
										</div>
										<div class="text-center py-2 d-none" id="mark-read-customer">
											<a href="javascript:;" class="mark-tab-remarks-read text-primary font-weight-bold font-size-sm" data-type="2">Mark all as read</a>
										</div>
										<div class="text-center py-3 border-top d-none" id="load-more-customer">
											<a href="javascript:;" class="btn btn-sm btn-light-primary font-weight-bold load-more-remarks" data-type="2">Load More</a>
										</div>
									</div>
								</div>
							</div>
						</div>
						<!-- Notifications Dropdown -->
						<div class="topbar-item position-relative">
							<div class="btn btn-icon btn-clean btn-lg mr-1 position-relative" id="kt_notification_toggle" data-toggle="dropdown" data-offset="10px,10px">
								<i class="la la-bell la-2x text-primary"></i>
								<span class="label label-lg label-light-danger label-inline label-rounded position-absolute" id="notification-badge" style="top: -5px; right: -5px; display: none; min-width: 20px; padding: 2px 6px;">0</span>
							</div>
							<!-- Notification Dropdown -->
							<div class="dropdown-menu dropdown-menu-right p-0 m-0 dropdown-menu-anim-up dropdown-menu-lg" id="notification-dropdown" style="width: 400px; max-height: 500px; overflow: hidden;">
								<div class="d-flex align-items-center justify-content-between p-5 border-bottom" style="flex-shrink: 0;">
									<h5 class="mb-0">Notifications</h5>
									<a href="javascript:;" class="btn btn-xs btn-icon btn-light btn-hover-primary" id="kt_notification_close">
										<i class="ki ki-close icon-xs text-muted"></i>
									</a>
								</div>
								<div class="notification-list" id="notification-list" style="flex: 1 1 auto; overflow-y: auto; min-height: 0;">
									<div class="text-center p-10">
										<div class="spinner spinner-primary spinner-lg"></div>
										<div class="mt-3">Loading notifications...</div>
									</div>
								</div>
								<div class="d-flex align-items-center justify-content-between px-5 py-2 border-top" id="notification-dropdown-footer" style="flex-shrink: 0; background-color: #fff;">
									<a href="<?php echo base_url('Notification'); ?>" class="text-primary font-weight-bold font-size-sm">View all notifications</a>
									<a href="javascript:;" class="mark-all-notifications-read text-primary font-weight-bold font-size-sm d-none" id="mark-read-notifications">Mark all as read</a>
								</div>
							</div>
						</div>
						<div class="topbar-item">
							<div id="kt_quick_user_toggle" class="btn btn-icon btn-icon-mobile w-auto btn-clean d-flex align-items-center btn-lg px-2">
								<span class="text-muted font-weight-bold font-size-base d-md-inline mr-1">Welcome,</span>
								<span class="text-dark-50 font-weight-bolder font-size-base d-none d-md-inline mr-3"><?php echo $this->session->name; ?></span>
								<span class="symbol symbol-lg-35 symbol-25 symbol-light-success">
									<span class="symbol-label font-size-h5 font-weight-bold">
										<img src="<?php echo $this->session->profile_picture; ?>" class="w-100">
									</span>
								</span>
							</div>
						</div>
					</div>
				</div>
			</div>
			<div class="content d-flex flex-column flex-column-fluid">
				<div class="subheader py-2 py-lg-4 subheader-solid">
					<div class="container-fluid d-flex align-items-center justify-content-between flex-wrap flex-sm-nowrap">
						<div class="d-flex align-items-center flex-wrap mr-2">
							<h5 class="text-dark font-weight-bold mt-2 mb-2 mr-5"><?php echo $breadcrumb_title; ?></h5>
							<?php
							$nav_page = function_exists('nav_module_for_request') ? nav_module_for_request() : null;
							if ($nav_page && function_exists('nav_can_view') && nav_can_view($nav_page) && ! nav_can_edit($nav_page)) {
							?>
							<span class="label label-light-warning label-inline font-weight-bold ml-2">View only</span>
							<?php } ?>
						</div>
					</div>
				</div>
				<div id="kt_quick_user" class="offcanvas offcanvas-right p-10">
					<div class="offcanvas-header d-flex align-items-center justify-content-between pb-5">
						<h3 class="font-weight-bold m-0">Admin
							<a id="kt_quick_user_close" class="btn btn-xs btn-icon btn-light btn-hover-primary">
								<i class="ki ki-close icon-xs text-muted"></i>
							</a>
						</h3>
					</div>
					<div class="offcanvas-content pr-5 mr-n5 scroll ps ps--active-y">
						<div class="d-flex align-items-center mt-5">
							<div class="symbol symbol-100 mr-5">
								<div class="symbol-label" style="background-image:url(<?php echo $this->session->profile_picture; ?>)"></div>
								<i class="symbol-badge bg-success"></i>
							</div>
							<div class="d-flex flex-column">
								<a href="<?php echo base_url('Profile'); ?>" class="font-weight-bold font-size-h5 text-dark-75 text-hover-primary"><?php echo $this->session->name; ?></a>
								<div class="text-muted mt-1"><?php echo $this->session->priviledge; ?></div>
								<div class="navi mt-2">
									<a href="<?php echo base_url('Login/Logout'); ?>" class="btn btn-sm btn-light-primary font-weight-bold py-2 px-5" style="width:80px;">Logout</a>
								</div>
							</div>
						</div>
						<div class="separator separator-dashed mt-8 mb-5"></div>
						<div class="navi navi-spacer-x-0 p-0">
							<a href="<?php echo base_url('Profile'); ?>" class="navi-item">
								<div class="navi-link">
									<div class="symbol symbol-40 bg-light mr-3">
										<div class="symbol-label">
											<span class="svg-icon svg-icon-md svg-icon-success">
												<svg>
													<g>
														<path d="M13.2070325, 4 C13.0721672, 4.47683179 13, 4.97998812 13, 5.5 C13, 8.53756612 15.4624339, 11 18.5, 11 C19.0200119, 11 19.5231682, 10.9278328 20, 10.7929675 L20, 17 C20, 18.6568542 18.6568542, 20 17, 20 L7, 20 C5.34314575, 20 4, 18.6568542 4, 17 L4, 7 C4, 5.34314575 5.34314575, 4 7, 4 L13.2070325, 4 Z" fill="#000000"></path>
														<circle fill="#000000" opacity="0.3" cx="18.5" cy="5.5" r="2.5"></circle>
													</g>
												</svg>
											</span>
										</div>
									</div>
									<div class="navi-text">
										<div class="font-weight-bold">My Profile</div>
										<div class="text-muted">Information & Account Setting</div>
									</div>
								</div>
							</a>
						</div>
						<?php if(!in_array((int)$this->session->level, [20, 60])) { ?>
							<div class="navi navi-spacer-x-0 p-0">
								<a href="<?php echo base_url('Company'); ?>" class="navi-item">
									<div class="navi-link">
										<div class="symbol symbol-40 bg-light mr-3">
											<div class="symbol-label">
												<span class="svg-icon svg-icon-md svg-icon-success">
													<svg>
														<g>
															<path d="M13.2070325, 4 C13.0721672, 4.47683179 13, 4.97998812 13, 5.5 C13, 8.53756612 15.4624339, 11 18.5, 11 C19.0200119, 11 19.5231682, 10.9278328 20, 10.7929675 L20, 17 C20, 18.6568542 18.6568542, 20 17, 20 L7, 20 C5.34314575, 20 4, 18.6568542 4, 17 L4, 7 C4, 5.34314575 5.34314575, 4 7, 4 L13.2070325, 4 Z" fill="#000000"></path>
															<circle fill="#000000" opacity="0.3" cx="18.5" cy="5.5" r="2.5"></circle>
														</g>
													</svg>
												</span>
											</div>
										</div>
										<div class="navi-text">
											<div class="font-weight-bold">Company Profile</div>
											<div class="text-muted">Information</div>
										</div>
									</div>
								</a>
							</div>
						<?php } ?>
						<?php $this->load->helper('totp'); if(totp_two_factor_enabled()) { ?>
							<div class="navi navi-spacer-x-0 p-0">
								<a href="javascript:void(0)" onclick="Self_Reset_Two_Factor()" class="navi-item">
									<div class="navi-link">
										<div class="symbol symbol-40 bg-light mr-3">
											<div class="symbol-label">
												<span class="svg-icon svg-icon-md svg-icon-warning">
													<svg>
														<g>
															<path d="M13.2070325, 4 C13.0721672, 4.47683179 13, 4.97998812 13, 5.5 C13, 8.53756612 15.4624339, 11 18.5, 11 C19.0200119, 11 19.5231682, 10.9278328 20, 10.7929675 L20, 17 C20, 18.6568542 18.6568542, 20 17, 20 L7, 20 C5.34314575, 20 4, 18.6568542 4, 17 L4, 7 C4, 5.34314575 5.34314575, 4 7, 4 L13.2070325, 4 Z" fill="#000000"></path>
															<circle fill="#000000" opacity="0.3" cx="18.5" cy="5.5" r="2.5"></circle>
														</g>
													</svg>
												</span>
											</div>
										</div>
										<div class="navi-text">
											<div class="font-weight-bold">Reset 2FA</div>
											<div class="text-muted">Re-enrol Google Authenticator</div>
										</div>
									</div>
								</a>
							</div>
						<?php } ?>
					</div>
				</div>
				<?php if(function_exists('totp_two_factor_enabled') && totp_two_factor_enabled()) { ?>
					<script>
						function Self_Reset_Two_Factor() {
							const bg = '<?php echo base_url('assets/image/sweetalert.jpg'); ?>';
							Swal.mixin({ customClass: { confirmButton: 'btn btn-light-success m-2', cancelButton: 'btn btn-danger m-2' }, buttonsStyling: true })
								.fire({
									width: 550,
									background: `url(${bg})`,
									icon: 'warning',
									title: 'Reset your 2FA?',
									text: 'You will be logged out and must set up Google Authenticator again on your next login.',
									confirmButtonText: 'Confirm',
									cancelButtonText: 'Cancel',
									showCancelButton: true
								}).then((action) => {
									if(action.isConfirmed) {
										$.ajax({
											url: '<?php echo base_url('Profile/Reset_Two_Factor'); ?>',
											type: 'post',
											dataType: 'json',
											success: function(status) {
												if(status === true) {
													Display_Message(bg, '2FA Successfully Reset', '<?php echo base_url('Login/Logout'); ?>');
												} else {
													Display_Message(bg, '2FA Could Not Be Reset', null);
												}
											},
											error: function() {
												Display_Message(bg, '2FA Could Not Be Reset', null);
											}
										});
									}
								});
						}
					</script>
				<?php } ?>
