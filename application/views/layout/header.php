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
						<ul class="menu-nav">
							<?php if(!in_array((int)$this->session->level, [20, 60])) { ?>
								<?php if($this->session->level != 40 && $this->session->level != 45) { ?>
								<li class="menu-item <?php if($this->router->class == 'Dashboard') { echo 'menu-item-active'; } ?>">
									<a href="<?php echo base_url('Dashboard'); ?>" class="menu-link">
										<span class="svg-icon menu-icon">
											<svg>
												<g>
													<path d="M12.9336061, 16.072447 L19.36, 10.9564761 L19.5181585, 10.8312381 C20.1676248, 10.3169571 20.2772143, 9.3735535 19.7629333, 8.72408713 C19.6917232, 8.63415859 19.6104327, 8.55269514 19.5206557, 8.48129411 L12.9336854, 3.24257445 C12.3871201, 2.80788259 11.6128799, 2.80788259 11.0663146, 3.24257445 L4.47482784, 8.48488609 C3.82645598, 9.00054628 3.71887192, 9.94418071 4.23453211, 10.5925526 C4.30500305, 10.6811601 4.38527899, 10.7615046 4.47382636, 10.8320511 L4.63, 10.9564761 L11.0659024, 16.0730648 C11.6126744, 16.5077525 12.3871218, 16.5074963 12.9336061, 16.072447 Z" fill="#000000"></path>
													<path d="M11.0563554, 18.6706981 L5.33593024, 14.122919 C4.94553994, 13.8125559 4.37746707, 13.8774308 4.06710397, 14.2678211 C4.06471678, 14.2708238 4.06234874, 14.2738418 4.06, 14.2768747 L4.06, 14.2768747 C3.75257288, 14.6738539 3.82516916, 15.244888 4.22214834, 15.5523151 C4.22358765, 15.5534297 4.2250303, 15.55454 4.22647627, 15.555646 L11.0872776, 20.8031356 C11.6250734, 21.2144692 12.371757, 21.2145375 12.909628, 20.8033023 L19.7677785, 15.559828 C20.1693192, 15.2528257 20.2459576, 14.6784381 19.9389553, 14.2768974 C19.9376429, 14.2751809 19.9363245, 14.2734691 19.935, 14.2717619 L19.935, 14.2717619 C19.6266937, 13.8743807 19.0546209, 13.8021712 18.6572397, 14.1104775 C18.654352, 14.112718 18.6514778, 14.1149757 18.6486172, 14.1172508 L12.9235044, 18.6705218 C12.377022, 19.1051477 11.6029199, 19.1052208 11.0563554, 18.6706981 Z" fill="#000000" opacity="0.3"></path>
												</g>
											</svg>
										</span>
										<span class="menu-text">Dashboard</span>
									</a>
								</li>
								<?php if($this->session->level != 25) { ?>
								<li class="menu-item <?php if($this->router->class == 'Admin') { echo 'menu-item-active'; } ?>">
									<a href="<?php echo base_url('Admin'); ?>" class="menu-link">
										<span class="svg-icon menu-icon">
											<svg>
												<g>
													<path d="M12.9336061, 16.072447 L19.36, 10.9564761 L19.5181585, 10.8312381 C20.1676248, 10.3169571 20.2772143, 9.3735535 19.7629333, 8.72408713 C19.6917232, 8.63415859 19.6104327, 8.55269514 19.5206557, 8.48129411 L12.9336854, 3.24257445 C12.3871201, 2.80788259 11.6128799, 2.80788259 11.0663146, 3.24257445 L4.47482784, 8.48488609 C3.82645598, 9.00054628 3.71887192, 9.94418071 4.23453211, 10.5925526 C4.30500305, 10.6811601 4.38527899, 10.7615046 4.47382636, 10.8320511 L4.63, 10.9564761 L11.0659024, 16.0730648 C11.6126744, 16.5077525 12.3871218, 16.5074963 12.9336061, 16.072447 Z" fill="#000000"></path>
													<path d="M11.0563554, 18.6706981 L5.33593024, 14.122919 C4.94553994, 13.8125559 4.37746707, 13.8774308 4.06710397, 14.2678211 C4.06471678, 14.2708238 4.06234874, 14.2738418 4.06, 14.2768747 L4.06, 14.2768747 C3.75257288, 14.6738539 3.82516916, 15.244888 4.22214834, 15.5523151 C4.22358765, 15.5534297 4.2250303, 15.55454 4.22647627, 15.555646 L11.0872776, 20.8031356 C11.6250734, 21.2144692 12.371757, 21.2145375 12.909628, 20.8033023 L19.7677785, 15.559828 C20.1693192, 15.2528257 20.2459576, 14.6784381 19.9389553, 14.2768974 C19.9376429, 14.2751809 19.9363245, 14.2734691 19.935, 14.2717619 L19.935, 14.2717619 C19.6266937, 13.8743807 19.0546209, 13.8021712 18.6572397, 14.1104775 C18.654352, 14.112718 18.6514778, 14.1149757 18.6486172, 14.1172508 L12.9235044, 18.6705218 C12.377022, 19.1051477 11.6029199, 19.1052208 11.0563554, 18.6706981 Z" fill="#000000" opacity="0.3"></path>
												</g>
											</svg>
										</span>
										<span class="menu-text">Admin</span>
									</a>
								</li>
								<?php } ?>
								<?php } ?>
								<?php if($this->session->level == 10 || in_array('FV', (array)$this->session->access_control)) { ?>
								<li class="menu-item menu-item-submenu <?php if($this->router->class == 'Faq' || $this->router->class == 'Faq_Tag') { echo 'menu-item-active menu-item-open'; } ?>">
									<a href="javascript:;" class="menu-link menu-toggle">
										<span class="svg-icon menu-icon">
											<svg>
												<g>
													<path d="M12.9336061, 16.072447 L19.36, 10.9564761 L19.5181585, 10.8312381 C20.1676248, 10.3169571 20.2772143, 9.3735535 19.7629333, 8.72408713 C19.6917232, 8.63415859 19.6104327, 8.55269514 19.5206557, 8.48129411 L12.9336854, 3.24257445 C12.3871201, 2.80788259 11.6128799, 2.80788259 11.0663146, 3.24257445 L4.47482784, 8.48488609 C3.82645598, 9.00054628 3.71887192, 9.94418071 4.23453211, 10.5925526 C4.30500305, 10.6811601 4.38527899, 10.7615046 4.47382636, 10.8320511 L4.63, 10.9564761 L11.0659024, 16.0730648 C11.6126744, 16.5077525 12.3871218, 16.5074963 12.9336061, 16.072447 Z" fill="#000000"></path>
													<path d="M11.0563554, 18.6706981 L5.33593024, 14.122919 C4.94553994, 13.8125559 4.37746707, 13.8774308 4.06710397, 14.2678211 C4.06471678, 14.2708238 4.06234874, 14.2738418 4.06, 14.2768747 L4.06, 14.2768747 C3.75257288, 14.6738539 3.82516916, 15.244888 4.22214834, 15.5523151 C4.22358765, 15.5534297 4.2250303, 15.55454 4.22647627, 15.555646 L11.0872776, 20.8031356 C11.6250734, 21.2144692 12.371757, 21.2145375 12.909628, 20.8033023 L19.7677785, 15.559828 C20.1693192, 15.2528257 20.2459576, 14.6784381 19.9389553, 14.2768974 C19.9376429, 14.2751809 19.9363245, 14.2734691 19.935, 14.2717619 L19.935, 14.2717619 C19.6266937, 13.8743807 19.0546209, 13.8021712 18.6572397, 14.1104775 C18.654352, 14.112718 18.6514778, 14.1149757 18.6486172, 14.1172508 L12.9235044, 18.6705218 C12.377022, 19.1051477 11.6029199, 19.1052208 11.0563554, 18.6706981 Z" fill="#000000" opacity="0.3"></path>
												</g>
											</svg>
										</span>
										<span class="menu-text">FAQ</span>
										<i class="menu-arrow"></i>
									</a>
									<div class="menu-submenu">
										<i class="menu-arrow"></i>
										<ul class="menu-subnav">
											<?php if($this->session->level == 10 || in_array('FV', (array)$this->session->access_control)) { ?>
											<li class="menu-item <?php if($this->router->class == 'Faq') { echo 'menu-item-active'; } ?>">
												<a href="<?php echo base_url('Faq'); ?>" class="menu-link">
													<i class="menu-bullet menu-bullet-dot">
														<span></span>
													</i>
													<span class="menu-text">FAQ</span>
												</a>
											</li>
											<?php } ?>
											<?php if($this->session->level == 10 || in_array('FV', (array)$this->session->access_control)) { ?>
											<li class="menu-item <?php if($this->router->class == 'Faq_Tag') { echo 'menu-item-active'; } ?>">
												<a href="<?php echo base_url('Faq_Tag'); ?>" class="menu-link">
													<i class="menu-bullet menu-bullet-dot">
														<span></span>
													</i>
													<span class="menu-text">FAQ Tag</span>
												</a>
											</li>
											<?php } ?>
										</ul>
									</div>
								</li>
								<?php } ?>
							<?php } ?>
							<?php if(in_array('VB', $this->session->access_control)) { ?>
								<li class="menu-item <?php if($this->router->class == 'Booking') { echo 'menu-item-active'; } ?>">
									<a href="<?php echo base_url('Booking'); ?>" class="menu-link">
										<span class="svg-icon menu-icon">
											<svg>
												<g>
													<path d="M12.9336061, 16.072447 L19.36, 10.9564761 L19.5181585, 10.8312381 C20.1676248, 10.3169571 20.2772143, 9.3735535 19.7629333, 8.72408713 C19.6917232, 8.63415859 19.6104327, 8.55269514 19.5206557, 8.48129411 L12.9336854, 3.24257445 C12.3871201, 2.80788259 11.6128799, 2.80788259 11.0663146, 3.24257445 L4.47482784, 8.48488609 C3.82645598, 9.00054628 3.71887192, 9.94418071 4.23453211, 10.5925526 C4.30500305, 10.6811601 4.38527899, 10.7615046 4.47382636, 10.8320511 L4.63, 10.9564761 L11.0659024, 16.0730648 C11.6126744, 16.5077525 12.3871218, 16.5074963 12.9336061, 16.072447 Z" fill="#000000"></path>
													<path d="M11.0563554, 18.6706981 L5.33593024, 14.122919 C4.94553994, 13.8125559 4.37746707, 13.8774308 4.06710397, 14.2678211 C4.06471678, 14.2708238 4.06234874, 14.2738418 4.06, 14.2768747 L4.06, 14.2768747 C3.75257288, 14.6738539 3.82516916, 15.244888 4.22214834, 15.5523151 C4.22358765, 15.5534297 4.2250303, 15.55454 4.22647627, 15.555646 L11.0872776, 20.8031356 C11.6250734, 21.2144692 12.371757, 21.2145375 12.909628, 20.8033023 L19.7677785, 15.559828 C20.1693192, 15.2528257 20.2459576, 14.6784381 19.9389553, 14.2768974 C19.9376429, 14.2751809 19.9363245, 14.2734691 19.935, 14.2717619 L19.935, 14.2717619 C19.6266937, 13.8743807 19.0546209, 13.8021712 18.6572397, 14.1104775 C18.654352, 14.112718 18.6514778, 14.1149757 18.6486172, 14.1172508 L12.9235044, 18.6705218 C12.377022, 19.1051477 11.6029199, 19.1052208 11.0563554, 18.6706981 Z" fill="#000000" opacity="0.3"></path>
												</g>
											</svg>
										</span>
										<span class="menu-text">Booking</span>
									</a>
								</li>
							<?php } ?>
							<?php if(in_array('VP', $this->session->access_control)) { ?>
								<li class="menu-item menu-item-submenu <?php if($this->router->class == 'Payment') { echo 'menu-item-active menu-item-open'; } ?>">
									<a href="javascript:;" class="menu-link menu-toggle">
										<span class="svg-icon menu-icon">
											<svg>
												<g>
													<path d="M12.9336061, 16.072447 L19.36, 10.9564761 L19.5181585, 10.8312381 C20.1676248, 10.3169571 20.2772143, 9.3735535 19.7629333, 8.72408713 C19.6917232, 8.63415859 19.6104327, 8.55269514 19.5206557, 8.48129411 L12.9336854, 3.24257445 C12.3871201, 2.80788259 11.6128799, 2.80788259 11.0663146, 3.24257445 L4.47482784, 8.48488609 C3.82645598, 9.00054628 3.71887192, 9.94418071 4.23453211, 10.5925526 C4.30500305, 10.6811601 4.38527899, 10.7615046 4.47382636, 10.8320511 L4.63, 10.9564761 L11.0659024, 16.0730648 C11.6126744, 16.5077525 12.3871218, 16.5074963 12.9336061, 16.072447 Z" fill="#000000"></path>
													<path d="M11.0563554, 18.6706981 L5.33593024, 14.122919 C4.94553994, 13.8125559 4.37746707, 13.8774308 4.06710397, 14.2678211 C4.06471678, 14.2708238 4.06234874, 14.2738418 4.06, 14.2768747 L4.06, 14.2768747 C3.75257288, 14.6738539 3.82516916, 15.244888 4.22214834, 15.5523151 C4.22358765, 15.5534297 4.2250303, 15.55454 4.22647627, 15.555646 L11.0872776, 20.8031356 C11.6250734, 21.2144692 12.371757, 21.2145375 12.909628, 20.8033023 L19.7677785, 15.559828 C20.1693192, 15.2528257 20.2459576, 14.6784381 19.9389553, 14.2768974 C19.9376429, 14.2751809 19.9363245, 14.2734691 19.935, 14.2717619 L19.935, 14.2717619 C19.6266937, 13.8743807 19.0546209, 13.8021712 18.6572397, 14.1104775 C18.654352, 14.112718 18.6514778, 14.1149757 18.6486172, 14.1172508 L12.9235044, 18.6705218 C12.377022, 19.1051477 11.6029199, 19.1052208 11.0563554, 18.6706981 Z" fill="#000000" opacity="0.3"></path>
												</g>
											</svg>
										</span>
										<span class="menu-text">Payment</span>
										<i class="menu-arrow"></i>
									</a>
									<div class="menu-submenu">
										<i class="menu-arrow"></i>
										<ul class="menu-subnav">
											<li class="menu-item <?php if($this->router->class == 'Payment' && $this->input->get('view_mode') != 'upcoming_due') { echo 'menu-item-active'; } ?>">
												<a href="<?php echo base_url('Payment'); ?>" class="menu-link">
													<i class="menu-bullet menu-bullet-dot">
														<span></span>
													</i>
													<span class="menu-text">Payment Listing</span>
												</a>
											</li>
											<?php if($this->session->level == 10 || $this->session->level == 30) { ?>
												<?php
													$CI =& get_instance();
													$CI->load->model('Payment_Model');
													$upcoming_due_count = $CI->Payment_Model->Count_Upcoming_Due();
												?>
												<li class="menu-item <?php if($this->router->class == 'Payment' && $this->input->get('view_mode') == 'upcoming_due') { echo 'menu-item-active'; } ?>">
													<a href="<?php echo base_url('Payment/upcoming_due'); ?>" class="menu-link">
														<i class="menu-bullet menu-bullet-dot">
															<span></span>
														</i>
														<span class="menu-text">Pending Due Soon</span>
														<?php if($upcoming_due_count > 0) { ?>
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
							<?php if(in_array('VR', $this->session->access_control) || in_array('ML', $this->session->access_control) || $this->session->level == 10 || $this->session->level == 20) { ?>
									<li class="menu-item menu-item-submenu <?php if($this->router->class == 'Report') { echo 'menu-item-active menu-item-open'; } ?>">
										<a href="javascript:;" class="menu-link menu-toggle">
											<span class="svg-icon menu-icon">
												<svg>
													<g>
														<path d="M12.9336061, 16.072447 L19.36, 10.9564761 L19.5181585, 10.8312381 C20.1676248, 10.3169571 20.2772143, 9.3735535 19.7629333, 8.72408713 C19.6917232, 8.63415859 19.6104327, 8.55269514 19.5206557, 8.48129411 L12.9336854, 3.24257445 C12.3871201, 2.80788259 11.6128799, 2.80788259 11.0663146, 3.24257445 L4.47482784, 8.48488609 C3.82645598, 9.00054628 3.71887192, 9.94418071 4.23453211, 10.5925526 C4.30500305, 10.6811601 4.38527899, 10.7615046 4.47382636, 10.8320511 L4.63, 10.9564761 L11.0659024, 16.0730648 C11.6126744, 16.5077525 12.3871218, 16.5074963 12.9336061, 16.072447 Z" fill="#000000"></path>
														<path d="M11.0563554, 18.6706981 L5.33593024, 14.122919 C4.94553994, 13.8125559 4.37746707, 13.8774308 4.06710397, 14.2678211 C4.06471678, 14.2708238 4.06234874, 14.2738418 4.06, 14.2768747 L4.06, 14.2768747 C3.75257288, 14.6738539 3.82516916, 15.244888 4.22214834, 15.5523151 C4.22358765, 15.5534297 4.2250303, 15.55454 4.22647627, 15.555646 L11.0872776, 20.8031356 C11.6250734, 21.2144692 12.371757, 21.2145375 12.909628, 20.8033023 L19.7677785, 15.559828 C20.1693192, 15.2528257 20.2459576, 14.6784381 19.9389553, 14.2768974 C19.9376429, 14.2751809 19.9363245, 14.2734691 19.935, 14.2717619 L19.935, 14.2717619 C19.6266937, 13.8743807 19.0546209, 13.8021712 18.6572397, 14.1104775 C18.654352, 14.112718 18.6514778, 14.1149757 18.6486172, 14.1172508 L12.9235044, 18.6705218 C12.377022, 19.1051477 11.6029199, 19.1052208 11.0563554, 18.6706981 Z" fill="#000000" opacity="0.3"></path>
													</g>
												</svg>
											</span>
											<span class="menu-text">Report</span>
											<i class="menu-arrow"></i>
										</a>
										<div class="menu-submenu">
											<i class="menu-arrow"></i>
											<ul class="menu-subnav">
												<?php if(false && in_array('VR', $this->session->access_control)) { // hidden: Destination/City/State/Country/Product Sales, BC By Source, Guest By Country ?>
												<li class="menu-item <?php if($this->router->method == 'Destination_Sales') { echo 'menu-item-active'; } ?>">
													<a href="<?php echo base_url('Report/Destination_Sales'); ?>" class="menu-link">
														<i class="menu-bullet menu-bullet-dot">
															<span></span>
														</i>
														<span class="menu-text">Destination Sales</span>
													</a>
												</li>
												<li class="menu-item <?php if($this->router->method == 'City_Sales') { echo 'menu-item-active'; } ?>">
													<a href="<?php echo base_url('Report/City_Sales'); ?>" class="menu-link">
														<i class="menu-bullet menu-bullet-dot">
															<span></span>
														</i>
														<span class="menu-text">City Sales</span>
													</a>
												</li>
												<li class="menu-item <?php if($this->router->method == 'State_Sales') { echo 'menu-item-active'; } ?>">
													<a href="<?php echo base_url('Report/State_Sales'); ?>" class="menu-link">
														<i class="menu-bullet menu-bullet-dot">
															<span></span>
														</i>
														<span class="menu-text">State Sales</span>
													</a>
												</li>
												<li class="menu-item <?php if($this->router->method == 'Country_Sales') { echo 'menu-item-active'; } ?>">
													<a href="<?php echo base_url('Report/Country_Sales'); ?>" class="menu-link">
														<i class="menu-bullet menu-bullet-dot">
															<span></span>
														</i>
														<span class="menu-text">Country Sales</span>
													</a>
												</li>
												<li class="menu-item <?php if($this->router->method == 'Product_Sales') { echo 'menu-item-active'; } ?>">
													<a href="<?php echo base_url('Report/Product_Sales'); ?>" class="menu-link">
														<i class="menu-bullet menu-bullet-dot">
															<span></span>
														</i>
														<span class="menu-text">Product Sales</span>
													</a>
												</li>
												<li class="menu-item <?php if($this->router->method == 'BC_By_Source') { echo 'menu-item-active'; } ?>">
													<a href="<?php echo base_url('Report/BC_By_Source'); ?>" class="menu-link">
														<i class="menu-bullet menu-bullet-dot">
															<span></span>
														</i>
														<span class="menu-text">BC By Source</span>
													</a>
												</li>
												<li class="menu-item <?php if($this->router->method == 'Guest_By_Country') { echo 'menu-item-active'; } ?>">
													<a href="<?php echo base_url('Report/Guest_By_Country'); ?>" class="menu-link">
														<i class="menu-bullet menu-bullet-dot">
															<span></span>
														</i>
														<span class="menu-text">Guest By Country</span>
													</a>
												</li>
												<?php } ?>
												<li class="menu-item <?php if($this->router->method == 'PowerBI_Reports' || $this->router->method == 'PowerBI_View') { echo 'menu-item-active'; } ?>">
													<a href="<?php echo base_url('Report/PowerBI_Reports'); ?>" class="menu-link">
														<i class="menu-bullet menu-bullet-dot">
															<span></span>
														</i>
														<span class="menu-text">Power BI Reports</span>
													</a>
												</li>
												<li class="menu-item <?php if($this->router->method == 'PowerBI') { echo 'menu-item-active'; } ?>">
													<a href="<?php echo base_url('Report/PowerBI'); ?>" class="menu-link">
														<i class="menu-bullet menu-bullet-dot">
															<span></span>
														</i>
														<span class="menu-text">PowerBi Builder</span>
													</a>
												</li>
												<?php if($this->session->level == 10) { ?>
													<!-- <li class="menu-item <?php if($this->router->method == 'Lead_Dashboard') { echo 'menu-item-active'; } ?>">
														<a href="<?php echo base_url('Report/Lead_Dashboard'); ?>" class="menu-link">
															<i class="menu-bullet menu-bullet-dot">
																<span></span>
															</i>
															<span class="menu-text">Lead Dashboard</span>
														</a>
													</li> -->
													<!-- <li class="menu-item <?php if($this->router->method == 'Lead_Data') { echo 'menu-item-active'; } ?>">
														<a href="<?php echo base_url('Report/Lead_Data'); ?>" class="menu-link">
															<i class="menu-bullet menu-bullet-dot">
																<span></span>
															</i>
															<span class="menu-text">Lead Data</span>
														</a>
													</li> -->
													<li class="menu-item <?php if($this->router->method == 'Lead_Ownership_Dashboard') { echo 'menu-item-active'; } ?>">
														<a href="<?php echo base_url('Report/Lead_Ownership_Dashboard'); ?>" class="menu-link">
															<i class="menu-bullet menu-bullet-dot">
																<span></span>
															</i>
															<span class="menu-text">Lead Ownership</span>
														</a>
													</li>
													<li class="menu-item <?php if($this->router->method == 'Leads_By_Hour') { echo 'menu-item-active'; } ?>">
														<a href="<?php echo base_url('Report/Leads_By_Hour'); ?>" class="menu-link">
															<i class="menu-bullet menu-bullet-dot">
																<span></span>
															</i>
															<span class="menu-text">Leads By Hour</span>
														</a>
													</li>
													<!-- <li class="menu-item <?php if($this->router->method == 'Lead_Ownership_Data') { echo 'menu-item-active'; } ?>">
														<a href="<?php echo base_url('Report/Lead_Ownership_Data'); ?>" class="menu-link">
															<i class="menu-bullet menu-bullet-dot">
																<span></span>
															</i>
															<span class="menu-text">Lead Ownership Data</span>
														</a>
													</li> -->
													<?php } ?>
												<?php if($this->session->level == 10 || $this->session->level == 20) { ?>
												<li class="menu-item <?php if($this->router->method == 'Lead_Reply_Activity_Dashboard') { echo 'menu-item-active'; } ?>">
														<a href="<?php echo base_url('Report/Lead_Reply_Activity_Dashboard'); ?>" class="menu-link">
															<i class="menu-bullet menu-bullet-dot">
																<span></span>
															</i>
															<span class="menu-text">Lead Reply Activity</span>
														</a>
													</li>
												<?php } ?>
												<?php if($this->session->level == 10 || in_array('ML', $this->session->access_control)) { ?>
												<li class="menu-item <?php if($this->router->method == 'Ghl_Message_Log') { echo 'menu-item-active'; } ?>">
													<a href="<?php echo base_url('Report/Ghl_Message_Log'); ?>" class="menu-link">
														<i class="menu-bullet menu-bullet-dot">
															<span></span>
														</i>
														<span class="menu-text">Message Log</span>
													</a>
												</li>
												<?php } ?>
											</ul>
										</div>
									</li>
								<?php } ?>
							<?php
								// "Leads/Customer" tab — groups Customer, Guest List, GHL Leads,
								// Manual Leads, Campaign and Lead Status. Every page's visibility
								// is now per-page via lc_can_view() (owner always allowed;
								// everyone else only when granted). Access Settings (owner only)
								// manages who can view/edit each page.
								$lc_active = in_array($this->router->class, array('Customer', 'Guests', 'Ghl_Leads', 'Manual_Leads', 'Campaign', 'Lead_Status', 'Nature_Of_Business', 'Leads_Customer_Access', 'Merge_Duplicate_Customers', 'Hot_Cold_Customers'), true);
								$lc_show_campaign    = lc_can_view('campaign');
								$lc_show_lead_status = lc_can_view('lead_status');
								$lc_show_nature_of_business = lc_can_view('nature_of_business');
							?>
							<?php if(lc_any_view() || $lc_show_campaign || $lc_show_lead_status || $lc_show_nature_of_business) { ?>
								<li class="menu-item menu-item-submenu <?php if($lc_active) { echo 'menu-item-active menu-item-open'; } ?>">
									<a href="javascript:;" class="menu-link menu-toggle">
										<span class="svg-icon menu-icon">
											<svg>
												<g>
													<path d="M12.9336061, 16.072447 L19.36, 10.9564761 L19.5181585, 10.8312381 C20.1676248, 10.3169571 20.2772143, 9.3735535 19.7629333, 8.72408713 C19.6917232, 8.63415859 19.6104327, 8.55269514 19.5206557, 8.48129411 L12.9336854, 3.24257445 C12.3871201, 2.80788259 11.6128799, 2.80788259 11.0663146, 3.24257445 L4.47482784, 8.48488609 C3.82645598, 9.00054628 3.71887192, 9.94418071 4.23453211, 10.5925526 C4.30500305, 10.6811601 4.38527899, 10.7615046 4.47382636, 10.8320511 L4.63, 10.9564761 L11.0659024, 16.0730648 C11.6126744, 16.5077525 12.3871218, 16.5074963 12.9336061, 16.072447 Z" fill="#000000"></path>
													<path d="M11.0563554, 18.6706981 L5.33593024, 14.122919 C4.94553994, 13.8125559 4.37746707, 13.8774308 4.06710397, 14.2678211 C4.06471678, 14.2708238 4.06234874, 14.2738418 4.06, 14.2768747 L4.06, 14.2768747 C3.75257288, 14.6738539 3.82516916, 15.244888 4.22214834, 15.5523151 C4.22358765, 15.5534297 4.2250303, 15.55454 4.22647627, 15.555646 L11.0872776, 20.8031356 C11.6250734, 21.2144692 12.371757, 21.2145375 12.909628, 20.8033023 L19.7677785, 15.559828 C20.1693192, 15.2528257 20.2459576, 14.6784381 19.9389553, 14.2768974 C19.9376429, 14.2751809 19.9363245, 14.2734691 19.935, 14.2717619 L19.935, 14.2717619 C19.6266937, 13.8743807 19.0546209, 13.8021712 18.6572397, 14.1104775 C18.654352, 14.112718 18.6514778, 14.1149757 18.6486172, 14.1172508 L12.9235044, 18.6705218 C12.377022, 19.1051477 11.6029199, 19.1052208 11.0563554, 18.6706981 Z" fill="#000000" opacity="0.3"></path>
												</g>
											</svg>
										</span>
										<span class="menu-text">Leads/Customer</span>
										<i class="menu-arrow"></i>
									</a>
									<div class="menu-submenu">
										<i class="menu-arrow"></i>
										<ul class="menu-subnav">
											<?php if((int)$this->session->level === 10) { ?>
											<li class="menu-item <?php if($this->router->class == 'Hot_Cold_Customers') { echo 'menu-item-active'; } ?>">
												<a href="<?php echo base_url('Hot_Cold_Customers'); ?>" class="menu-link">
													<i class="menu-bullet menu-bullet-dot"><span></span></i>
													<span class="menu-text">Hot / Cold Customers</span>
												</a>
											</li>
											<?php } ?>
											<?php if(lc_can_view('customer')) { ?>
											<li class="menu-item <?php if($this->router->class == 'Customer') { echo 'menu-item-active'; } ?>">
												<a href="<?php echo base_url('Customer'); ?>" class="menu-link">
													<i class="menu-bullet menu-bullet-dot"><span></span></i>
													<span class="menu-text">Customer</span>
												</a>
											</li>
											<?php } ?>
											<?php if(lc_can_view('guests')) { ?>
											<li class="menu-item <?php if($this->router->class == 'Guests') { echo 'menu-item-active'; } ?>">
												<a href="<?php echo base_url('Guests'); ?>" class="menu-link">
													<i class="menu-bullet menu-bullet-dot"><span></span></i>
													<span class="menu-text">Guest List</span>
												</a>
											</li>
											<?php } ?>
											<?php if(lc_can_view('ghl_leads')) { ?>
											<li class="menu-item <?php if($this->router->class == 'Ghl_Leads') { echo 'menu-item-active'; } ?>">
												<a href="<?php echo base_url('Ghl_Leads'); ?>" class="menu-link">
													<i class="menu-bullet menu-bullet-dot"><span></span></i>
													<span class="menu-text">GHL Leads</span>
												</a>
											</li>
											<?php } ?>
											<?php if(lc_can_view('manual_leads')) { ?>
											<li class="menu-item <?php if($this->router->class == 'Manual_Leads') { echo 'menu-item-active'; } ?>">
												<a href="<?php echo base_url('Manual_Leads'); ?>" class="menu-link">
													<i class="menu-bullet menu-bullet-dot"><span></span></i>
													<span class="menu-text">Manual Leads</span>
												</a>
											</li>
											<?php } ?>
											<?php if($lc_show_campaign) { ?>
											<li class="menu-item <?php if($this->router->class == 'Campaign') { echo 'menu-item-active'; } ?>">
												<a href="<?php echo base_url('Campaign'); ?>" class="menu-link">
													<i class="menu-bullet menu-bullet-dot"><span></span></i>
													<span class="menu-text">Campaign</span>
												</a>
											</li>
											<?php } ?>
											<?php if($lc_show_lead_status) { ?>
											<li class="menu-item <?php if($this->router->class == 'Lead_Status') { echo 'menu-item-active'; } ?>">
												<a href="<?php echo base_url('Lead_Status'); ?>" class="menu-link">
													<i class="menu-bullet menu-bullet-dot"><span></span></i>
													<span class="menu-text">Lead Status</span>
												</a>
											</li>
											<?php } ?>
											<?php if($lc_show_nature_of_business) { ?>
											<li class="menu-item <?php if($this->router->class == 'Nature_Of_Business') { echo 'menu-item-active'; } ?>">
												<a href="<?php echo base_url('Nature_Of_Business'); ?>" class="menu-link">
													<i class="menu-bullet menu-bullet-dot"><span></span></i>
													<span class="menu-text">Nature of Business</span>
												</a>
											</li>
											<?php } ?>
											<?php if((int)$this->session->level === 10) { ?>
											<li class="menu-item <?php if($this->router->class == 'Leads_Customer_Access') { echo 'menu-item-active'; } ?>">
												<a href="<?php echo base_url('Leads_Customer_Access'); ?>" class="menu-link">
													<i class="menu-bullet menu-bullet-dot"><span></span></i>
													<span class="menu-text">Access Settings</span>
												</a>
											</li>
											<?php } ?>
										</ul>
									</div>
								</li>
							<?php } ?>
							<?php if((int)$this->session->level === 10) { ?>
								<li class="menu-item menu-item-submenu <?php if($this->router->class == 'Competitor_Product' || $this->router->class == 'Our_Product' || $this->router->class == 'Master_Product') { echo 'menu-item-active menu-item-open'; } ?>">
									<a href="javascript:;" class="menu-link menu-toggle">
										<span class="svg-icon menu-icon">
											<i class="la la-robot" style="font-size:20px;"></i>
										</span>
										<span class="menu-text">Product Analysis</span>
										<i class="menu-arrow"></i>
									</a>
									<div class="menu-submenu">
										<i class="menu-arrow"></i>
										<ul class="menu-subnav">
											<li class="menu-item <?php if($this->router->class == 'Master_Product') { echo 'menu-item-active'; } ?>">
												<a href="<?php echo base_url('Master_Product'); ?>" class="menu-link">
													<i class="menu-bullet menu-bullet-dot"><span></span></i>
													<span class="menu-text">Master Product</span>
												</a>
											</li>
											<li class="menu-item <?php if($this->router->class == 'Competitor_Product') { echo 'menu-item-active'; } ?>">
												<a href="<?php echo base_url('Competitor_Product'); ?>" class="menu-link">
													<i class="menu-bullet menu-bullet-dot"><span></span></i>
													<span class="menu-text">Competitor Product</span>
												</a>
											</li>
											<li class="menu-item <?php if($this->router->class == 'Our_Product') { echo 'menu-item-active'; } ?>">
												<a href="<?php echo base_url('Our_Product'); ?>" class="menu-link">
													<i class="menu-bullet menu-bullet-dot"><span></span></i>
													<span class="menu-text">Our Product</span>
												</a>
											</li>
										</ul>
									</div>
								</li>
							<?php } ?>
							<?php
								// Per-admin access to Product (an Owner-granted VPR flag overrides the
								// role block). Keep menu in sync with the MY_Controller gate so links
								// never dead-end. See setting_module_access_helper. Customer / Guest List
								// / GHL Leads / Manual Leads have moved to the "Leads/Customer" tab below.
								$sm_level = $this->session->level;
								$sm_ac    = (array) $this->session->access_control;
								$can_product  = admin_can_access_setting_module('product',  $sm_level, $sm_ac);
								// OP (level 40) only needs the Footer setting; hide all other Setting items.
								$op_footer_only = ((int)$this->session->level === 40);
							?>
							<?php if(!in_array((int)$this->session->level, [20, 60]) || $can_product) { ?>
								<li class="menu-item menu-item-submenu <?php if($this->router->class == 'Category_Code' || $this->router->class == 'Category' || $this->router->class == 'Supplier' || $this->router->class == 'Product' || $this->router->class == 'Costing' || $this->router->class == 'Costing_Item' || $this->router->class == 'Footer' || $this->router->class == 'Country_Code' || $this->router->class == 'Tag' || $this->router->class == 'Source' || $this->router->class == 'Package_Checklist' || $this->router->class == 'Product_Package_Checklist' || $this->router->class == 'Cancellation_Reason' || $this->router->class == 'Slow_Conversion_Reason' || $this->router->class == 'Customer_Type' || $this->router->class == 'Lead_Status' || $this->router->class == 'Campaign' || $this->router->class == 'Quick_Filter' || $this->router->class == 'Agent_Score_Setting' || $this->router->class == 'Card_Visibility_Setting' || $this->router->class == 'Team') { echo 'menu-item-active menu-item-open'; } ?>">
									<a href="javascript:;" class="menu-link menu-toggle">
										<span class="svg-icon menu-icon">
											<svg>
												<g>
													<path d="M12.9336061, 16.072447 L19.36, 10.9564761 L19.5181585, 10.8312381 C20.1676248, 10.3169571 20.2772143, 9.3735535 19.7629333, 8.72408713 C19.6917232, 8.63415859 19.6104327, 8.55269514 19.5206557, 8.48129411 L12.9336854, 3.24257445 C12.3871201, 2.80788259 11.6128799, 2.80788259 11.0663146, 3.24257445 L4.47482784, 8.48488609 C3.82645598, 9.00054628 3.71887192, 9.94418071 4.23453211, 10.5925526 C4.30500305, 10.6811601 4.38527899, 10.7615046 4.47382636, 10.8320511 L4.63, 10.9564761 L11.0659024, 16.0730648 C11.6126744, 16.5077525 12.3871218, 16.5074963 12.9336061, 16.072447 Z" fill="#000000"></path>
													<path d="M11.0563554, 18.6706981 L5.33593024, 14.122919 C4.94553994, 13.8125559 4.37746707, 13.8774308 4.06710397, 14.2678211 C4.06471678, 14.2708238 4.06234874, 14.2738418 4.06, 14.2768747 L4.06, 14.2768747 C3.75257288, 14.6738539 3.82516916, 15.244888 4.22214834, 15.5523151 C4.22358765, 15.5534297 4.2250303, 15.55454 4.22647627, 15.555646 L11.0872776, 20.8031356 C11.6250734, 21.2144692 12.371757, 21.2145375 12.909628, 20.8033023 L19.7677785, 15.559828 C20.1693192, 15.2528257 20.2459576, 14.6784381 19.9389553, 14.2768974 C19.9376429, 14.2751809 19.9363245, 14.2734691 19.935, 14.2717619 L19.935, 14.2717619 C19.6266937, 13.8743807 19.0546209, 13.8021712 18.6572397, 14.1104775 C18.654352, 14.112718 18.6514778, 14.1149757 18.6486172, 14.1172508 L12.9235044, 18.6705218 C12.377022, 19.1051477 11.6029199, 19.1052208 11.0563554, 18.6706981 Z" fill="#000000" opacity="0.3"></path>
												</g>
											</svg>
										</span>
										<span class="menu-text">Setting</span>
										<i class="menu-arrow"></i>
									</a>
									<div class="menu-submenu">
										<i class="menu-arrow"></i>
										<ul class="menu-subnav">
											<?php if($this->session->level == 10) { ?>
											<li class="menu-item <?php if($this->router->class == 'Agent_Score_Setting') { echo 'menu-item-active'; } ?>">
												<a href="<?php echo base_url('Agent_Score_Setting'); ?>" class="menu-link">
													<i class="menu-bullet menu-bullet-dot">
														<span></span>
													</i>
													<span class="menu-text">Agent Score</span>
												</a>
											</li>
											<li class="menu-item <?php if($this->router->class == 'Card_Visibility_Setting') { echo 'menu-item-active'; } ?>">
												<a href="<?php echo base_url('Card_Visibility_Setting'); ?>" class="menu-link">
													<i class="menu-bullet menu-bullet-dot">
														<span></span>
													</i>
													<span class="menu-text">Card Visibility</span>
												</a>
											</li>
											<li class="menu-item <?php if($this->router->class == 'Team') { echo 'menu-item-active'; } ?>">
												<a href="<?php echo base_url('Team'); ?>" class="menu-link">
													<i class="menu-bullet menu-bullet-dot">
														<span></span>
													</i>
													<span class="menu-text">Team</span>
												</a>
											</li>
											<?php } ?>
											<?php if(!in_array((int)$this->session->level, [20, 60]) && !$op_footer_only) { ?>
											<li class="menu-item <?php if($this->router->class == 'Category_Code') { echo 'menu-item-active'; } ?>">
												<a href="<?php echo base_url('Category_Code'); ?>" class="menu-link">
													<i class="menu-bullet menu-bullet-dot">
														<span></span>
													</i>
													<span class="menu-text">Category Code</span>
												</a>
											</li>
											<?php } ?>
											<?php // OP (40) is allowed Category (Footer-only otherwise); still hidden from Sales Agent (20) and Marketing (60) ?>
											<?php if(!in_array((int)$this->session->level, [20, 60])) { ?>
											<li class="menu-item <?php if($this->router->class == 'Category') { echo 'menu-item-active'; } ?>">
												<a href="<?php echo base_url('Category'); ?>" class="menu-link">
													<i class="menu-bullet menu-bullet-dot">
														<span></span>
													</i>
													<span class="menu-text">Category</span>
												</a>
											</li>
											<?php } ?>
											<?php if (($this->session->level == '10' || $this->session->level == '30' || $this->session->level == '40') && !$op_footer_only) { ?>
												<li class="menu-item <?php if($this->router->class == 'Supplier') { echo 'menu-item-active'; } ?>">
													<a href="<?php echo base_url('Supplier'); ?>" class="menu-link">
														<i class="menu-bullet menu-bullet-dot">
															<span></span>
														</i>
														<span class="menu-text">Supplier</span>
													</a>
												</li>
											<?php } ?>
											<?php // Customer / Guest List / GHL Leads / Manual Leads / Campaign / Lead Status moved to the "Leads/Customer" tab. ?>
											<?php if($can_product && !$op_footer_only) { ?>
												<li class="menu-item <?php if($this->router->class == 'Product') { echo 'menu-item-active'; } ?>">
													<a href="<?php echo base_url('Product'); ?>" class="menu-link">
														<i class="menu-bullet menu-bullet-dot">
															<span></span>
														</i>
														<span class="menu-text">Product</span>
													</a>
												</li>
											<?php } ?>
												<?php if($this->session->level == 10 || (int)$this->session->admin_id === 28 /* Ema */) { ?>
													<li class="menu-item <?php if($this->router->class == 'Costing' && $this->router->method != 'Currency') { echo 'menu-item-active'; } ?>">
														<a href="<?php echo base_url('Costing'); ?>" class="menu-link">
															<i class="menu-bullet menu-bullet-dot">
																<span></span>
															</i>
															<span class="menu-text">Costing Packages</span>
														</a>
													</li>
													<li class="menu-item <?php if($this->router->class == 'Costing_Item') { echo 'menu-item-active'; } ?>">
														<a href="<?php echo base_url('Costing_Item'); ?>" class="menu-link">
															<i class="menu-bullet menu-bullet-dot">
																<span></span>
															</i>
															<span class="menu-text">Costing Item</span>
														</a>
													</li>
													<li class="menu-item <?php if($this->router->class == 'Costing_Category') { echo 'menu-item-active'; } ?>">
														<a href="<?php echo base_url('Costing_Category'); ?>" class="menu-link">
															<i class="menu-bullet menu-bullet-dot">
																<span></span>
															</i>
															<span class="menu-text">Costing Category</span>
														</a>
													</li>
													<li class="menu-item <?php if($this->router->class == 'Costing' && $this->router->method == 'Currency') { echo 'menu-item-active'; } ?>">
														<a href="<?php echo base_url('Costing/Currency'); ?>" class="menu-link">
															<i class="menu-bullet menu-bullet-dot">
																<span></span>
															</i>
															<span class="menu-text">Costing Currency</span>
														</a>
													</li>
												<?php } ?>
												<?php if(!in_array((int)$this->session->level, [20, 60])) { ?>
												<li class="menu-item <?php if($this->router->class == 'Footer') { echo 'menu-item-active'; } ?>">
													<a href="<?php echo base_url('Footer'); ?>" class="menu-link">
														<i class="menu-bullet menu-bullet-dot">
															<span></span>
														</i>
													<span class="menu-text">Footer</span>
												</a>
											</li>
											<?php if(!$op_footer_only) { ?>
											<li class="menu-item <?php if($this->router->class == 'Country_Code') { echo 'menu-item-active'; } ?>">
												<a href="<?php echo base_url('Country_Code'); ?>" class="menu-link">
													<i class="menu-bullet menu-bullet-dot">
														<span></span>
													</i>
													<span class="menu-text">Country Code</span>
												</a>
											</li>
											<li class="menu-item <?php if($this->router->class == 'Tag') { echo 'menu-item-active'; } ?>">
												<a href="<?php echo base_url('Tag'); ?>" class="menu-link">
													<i class="menu-bullet menu-bullet-dot">
														<span></span>
													</i>
													<span class="menu-text">Tag</span>
												</a>
											</li>
											<li class="menu-item <?php if($this->router->class == 'Source') { echo 'menu-item-active'; } ?>">
												<a href="<?php echo base_url('Source'); ?>" class="menu-link">
													<i class="menu-bullet menu-bullet-dot">
														<span></span>
													</i>
													<span class="menu-text">Source</span>
												</a>
											</li>
											<li class="menu-item <?php if($this->router->class == 'Package_Checklist') { echo 'menu-item-active'; } ?>">
												<a href="<?php echo base_url('Package_Checklist'); ?>" class="menu-link">
													<i class="menu-bullet menu-bullet-dot">
														<span></span>
													</i>
													<span class="menu-text">Package Checklist</span>
												</a>
											</li>
											<li class="menu-item <?php if($this->router->class == 'Cancellation_Reason') { echo 'menu-item-active'; } ?>">
												<a href="<?php echo base_url('Cancellation_Reason'); ?>" class="menu-link">
													<i class="menu-bullet menu-bullet-dot">
														<span></span>
													</i>
													<span class="menu-text">Cancellation Reason</span>
												</a>
											</li>
											<li class="menu-item <?php if($this->router->class == 'Slow_Conversion_Reason') { echo 'menu-item-active'; } ?>">
												<a href="<?php echo base_url('Slow_Conversion_Reason'); ?>" class="menu-link">
													<i class="menu-bullet menu-bullet-dot">
														<span></span>
													</i>
													<span class="menu-text">Slow Conversion Reason</span>
												</a>
											</li>
											<li class="menu-item <?php if($this->router->class == 'Customer_Type') { echo 'menu-item-active'; } ?>">
												<a href="<?php echo base_url('Customer_Type'); ?>" class="menu-link">
													<i class="menu-bullet menu-bullet-dot">
														<span></span>
													</i>
													<span class="menu-text">Customer Type</span>
												</a>
											</li>
											<li class="menu-item <?php if($this->router->class == 'Quick_Filter') { echo 'menu-item-active'; } ?>">
												<a href="<?php echo base_url('Quick_Filter'); ?>" class="menu-link">
													<i class="menu-bullet menu-bullet-dot">
														<span></span>
													</i>
													<span class="menu-text">Quick Filter</span>
												</a>
											</li>
											<?php } ?>
												<?php } ?>
										</ul>
									</div>
								</li>
							<?php } ?>
						</ul>
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
					</div>
				</div>
