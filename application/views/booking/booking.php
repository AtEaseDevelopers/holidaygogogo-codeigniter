
<script>
    // Endpoint for TinyMCE inline image upload in the voucher editors
    // (#kt-tinymce-4..7). Set before tinymce.js runs so the init can read it.
    window.TINYMCE_IMAGE_UPLOAD_URL = '<?php echo base_url("Booking/Upload_Voucher_Image"); ?>';
</script>

<script>
    // Install error suppressors at capture phase before any other script runs, so the
    // known Shopify draggable.bundle.js MutationObserver flush race cannot surface as
    // an unhandled rejection / uncaught error that blocks unrelated logic.
    (function() {
        function isDraggableFlushError(msg, stack, filename) {
            if(!msg) return false;
            if(msg.indexOf('hasOwnProperty') === -1) return false;
            if(stack && stack.indexOf('draggable.bundle.js') !== -1) return true;
            if(filename && filename.indexOf('draggable.bundle.js') !== -1) return true;
            if(stack && stack.indexOf('MutationObserver') !== -1) return true;
            return true; // message alone is specific enough
        }
        window.addEventListener('unhandledrejection', function(e) {
            var r = e && e.reason;
            var msg = r && r.message ? String(r.message) : String(r || '');
            var stack = r && r.stack ? String(r.stack) : '';
            if(isDraggableFlushError(msg, stack, '')) { e.preventDefault(); e.stopImmediatePropagation && e.stopImmediatePropagation(); }
        }, true);
        window.addEventListener('error', function(e) {
            var msg = e && e.message ? String(e.message) : '';
            var src = e && e.filename ? String(e.filename) : '';
            var stack = e && e.error && e.error.stack ? String(e.error.stack) : '';
            if(isDraggableFlushError(msg, stack, src)) { e.preventDefault(); e.stopImmediatePropagation && e.stopImmediatePropagation(); return true; }
        }, true);
    })();
</script>

<style>
    /* Merged Phone Input Styles (booking customer contact) */
    .phone-input-wrapper { position: relative; display: flex; align-items: stretch; border: 1px solid #e4e6ef; border-radius: 0.42rem; background-color: #fff; transition: border-color 0.15s ease-in-out, box-shadow 0.15s ease-in-out; }
    .phone-input-wrapper:focus-within { border-color: #5e72e4; box-shadow: 0 0 0 0.2rem rgba(94, 114, 228, 0.25); }
    .phone-input-wrapper.disabled { background-color: #f3f6f9; opacity: 0.6; cursor: not-allowed; }
    .phone-country-selector { position: relative; display: flex; align-items: center; padding: 0.75rem 0.75rem; background-color: #f7f8fa; border-right: 1px solid #e4e6ef; cursor: pointer; min-width: 120px; user-select: none; }
    .phone-country-selector.disabled { cursor: not-allowed; }
    .phone-country-flag { font-size: 1.25rem; margin-right: 0.5rem; line-height: 1; }
    .phone-country-code { font-weight: 500; color: #3f4254; font-size: 0.95rem; margin-right: 0.25rem; }
    .phone-country-arrow { margin-left: auto; color: #7e8299; font-size: 0.75rem; transition: transform 0.2s; }
    .phone-country-selector.open .phone-country-arrow { transform: rotate(180deg); }
    .phone-input-field { flex: 1; border: none; padding: 0.75rem 1rem; font-size: 0.95rem; background: transparent; outline: none; }
    .phone-input-field:disabled { background-color: transparent; cursor: not-allowed; }
    .phone-dropdown { position: absolute; top: 100%; left: 0; right: 0; background: #fff; border: 1px solid #e4e6ef; border-radius: 0.42rem; box-shadow: 0 0.5rem 1rem rgba(0, 0, 0, 0.15); z-index: 1000; max-height: 300px; overflow-y: auto; display: none; margin-top: 0.25rem; }
    .phone-dropdown.show { display: block; }
    .phone-dropdown-search { padding: 0.75rem; border-bottom: 1px solid #e4e6ef; position: sticky; top: 0; background: #fff; z-index: 1; }
    .phone-dropdown-search input { width: 100%; padding: 0.5rem; border: 1px solid #e4e6ef; border-radius: 0.25rem; font-size: 0.9rem; }
    .phone-dropdown-list { padding: 0.25rem 0; max-height: 250px; overflow-y: auto; }
    .phone-dropdown-item { display: flex; align-items: center; padding: 0.75rem; cursor: pointer; transition: background-color 0.15s; }
    .phone-dropdown-item:hover { background-color: #f7f8fa; }
    .phone-dropdown-item.selected { background-color: #e4e6ef; }
    .phone-dropdown-item-flag { font-size: 1.25rem; margin-right: 0.75rem; line-height: 1; width: 24px; text-align: center; }
    .phone-dropdown-item-name { flex: 1; color: #3f4254; font-size: 0.9rem; }
    .phone-dropdown-item-code { color: #7e8299; font-size: 0.85rem; font-weight: 500; margin-left: 0.5rem; }
    .phone-hidden-field { display: none !important; }
</style>

<div class="d-flex flex-column-fluid">

    <div class="container-fluid">

        <div class="card card-custom mb-5">

            <div class="card-header flex-wrap py-3" style="background-color:#D7E2F2;">

                <div class="card-title d-flex justify-content-between align-items-center w-100">

                    <h3 class="card-label" style="color:#6082B6; margin: 0;">

                        <strong><?php if(current_url() == base_url('Booking/Create') || current_url() == base_url('Booking/Duplicate')) { echo 'New Booking Record'; } else { echo 'Booking Record : ' . $BookingNumber; } ?></strong>

                    </h3>

                    <?php if(current_url() == base_url('Booking/Update') && !empty($display_status)): ?>
                        <?php 
                        // Load helper if not already loaded
                        $this->load->helper('booking_flow');
                        
                        $status = $display_status;
                        $status_code = $status['status_code'];
                        $status_text = $status['status_text'];
                        $status_color = $status['status_color'];
                        $all_statuses = !empty($status['all_statuses']) ? $status['all_statuses'] : [$status_code];
                        $status_info = get_booking_status_info();
                        ?>
                        <div class="booking-status-display">
                            <!-- Primary Status Badge -->
                            <span class="badge badge-lg" style="background-color: <?php echo $status_color; ?>; color: #fff; font-size: 0.9rem; font-weight: 600; padding: 8px 16px; border-radius: 4px;">
                                <?php echo htmlspecialchars($status_text); ?>
                            </span>
                            
                            <?php if(count($all_statuses) > 1): ?>
                                <!-- Multiple Statuses Indicator -->
                                <?php
                                $status_labels = array();
                                foreach($all_statuses as $s) {
                                    $status_labels[] = isset($status_info['texts'][$s]) ? $status_info['texts'][$s] : $s;
                                }
                                ?>
                                <span class="badge badge-secondary ml-2" style="font-size: 0.75rem; padding: 4px 8px; cursor: help;" 
                                      data-toggle="tooltip" 
                                      data-placement="left" 
                                      title="Multiple statuses: <?php echo htmlspecialchars(implode(', ', $status_labels)); ?>">
                                    <i class="la la-info-circle"></i> <?php echo count($all_statuses); ?> status<?php echo count($all_statuses) > 1 ? 'es' : ''; ?>
                                </span>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>

                </div>

            </div>

            <div class="card-body">

                <form id="form">

                    <?php if(current_url() == base_url('Booking/Create')) { ?>
                        <div class="d-flex align-items-center justify-content-between mb-5 p-4" style="background:#C5D6EF; border:1px solid #A9C2E6; border-radius:8px;">
                            <div class="mr-4">
                                <div style="font-weight:700; color:#1c3d5a; font-size:14px;">Save as draft</div>
                                <div style="font-size:13px; color:#3a4256; margin-top:2px;">Park this booking as a draft. Only the basics stay editable until it is approved and graduated to Pending BC.</div>
                            </div>
                            <span class="switch switch-sm">
                                <label class="mb-0">
                                    <input type="checkbox" id="is_draft_intake_toggle">
                                    <span></span>
                                </label>
                            </span>
                        </div>
                        <input type="hidden" id="is_draft_intake" name="is_draft_intake" value="0">
                    <?php } ?>

                    <strong>Booking Information :</strong>

                    <br><br>

                    <div class="row">

                        <div class="col-md-6">

                            <div class="form-group">

                                <label>Booking Number</label>

                                <div class="input-icon">

                                    <input type="text" id="booking_number" <?php if(current_url() == base_url('Booking/Update')) { ?> disabled value="<?php echo $BookingNumber; ?>" <?php } ?> autocomplete="off" class="form-control">

                                    <span>

                                        <i class="la la-suitcase"></i>

                                    </span>

                                </div>

                            </div>

                            <div class="form-group">

                                <label>Reservation Number

                                    <span style="color:red;">*</span>

                                </label>

                                <div class="input-icon">

                                    <input type="text" id="ReservationNumber" <?php if(current_url() == base_url('Booking/Update') || current_url() == base_url('Booking/Duplicate')) { ?> value="<?php echo $ReservationNumber; ?>" <?php } ?> maxlength="20" autocomplete="off" class="form-control">

                                    <span>

                                        <i class="la la-suitcase"></i>

                                    </span>

                                </div>
                                <small id="ReservationNumberError" class="text-danger"></small>

                            </div>

                            <div class="form-group">

                                <label>Sales Agent 1 (After Sales)

                                    <?php if($this->session->userdata('level') != 20 && current_url() == base_url('Booking/Create')) { ?><span style="color:red;">*</span><?php } ?>

                                </label>

                                <?php if($this->session->userdata('level') != 20) { ?>

                                    <select id="SalesAgent" data-live-search="true" class="form-control selectpicker">

                                        <option selected disabled data-icon="la la-user-alt font-size-lg bs-icon" value="">--SELECT SALES AGENT 1 (AFTER SALES)--</option>

                                        <?php foreach($admins as $admin) {
                                            $isSelected = (current_url() == base_url('Booking/Update') || current_url() == base_url('Booking/Duplicate')) && $admin->AdminID == $SalesAgent;
                                            if ($admin->Status == 'D' && !$isSelected) continue;
                                        ?>

                                            <option <?php if($isSelected) { echo 'selected'; } ?> data-icon="la la-user-alt font-size-lg bs-icon" value="<?php echo $admin->AdminID; ?>"><?php echo $admin->Name; ?></option>

                                        <?php } ?>

                                    </select>

                                <?php } else { ?>

                                    <div class="input-icon">

                                        <input disabled type="text" value="<?php if(current_url() == base_url('Booking/Create')) { echo $this->session->userdata('name'); } else { echo $SalesAgentName; } ?>" class="form-control">

                                        <span>

                                            <i class="la la-user-alt"></i>

                                        </span>

                                    </div>

                                <?php } ?>

                                <?php
                                // "Booking PIC" ticks: whoever is ticked prints on the BC & TV
                                // PIC line. Default = Sales Agent 1 only. On edit, reflect the
                                // stored ticks; a legacy booking (both unset) keeps the default.
                                $is_pic_edit_form = (current_url() == base_url('Booking/Update') || current_url() == base_url('Booking/Duplicate'));
                                $sa1_pic_checked = true;
                                $sa2_pic_checked = false;
                                if ($is_pic_edit_form) {
                                    if (isset($SalesAgentIsPIC) && $SalesAgentIsPIC !== null && $SalesAgentIsPIC !== '') {
                                        $sa1_pic_checked = ((int)$SalesAgentIsPIC === 1);
                                    }
                                    if (isset($SalesAgent2IsPIC) && $SalesAgent2IsPIC !== null && $SalesAgent2IsPIC !== '') {
                                        $sa2_pic_checked = ((int)$SalesAgent2IsPIC === 1);
                                    }
                                }
                                ?>

                                <div class="mt-2">
                                    <label style="font-size:13px; color:#1c3d5a; font-weight:600; cursor:pointer;">
                                        <input type="checkbox" id="SalesAgentIsPIC" style="vertical-align:middle; width:16px; height:16px; margin-right:6px;" <?php echo $sa1_pic_checked ? 'checked' : ''; ?> data-initial="<?php echo $sa1_pic_checked ? 1 : 0; ?>">
                                        Show as Booking PIC on BC, TV &amp; Guest List
                                    </label>
                                </div>

                            </div>

                            <div class="form-group">

                                <label>Sales Agent 2 (Pre Sales)

                                    <?php if(current_url() == base_url('Booking/Create')) { ?><span style="color:red;">*</span><?php } ?>

                                </label>

                                <select id="SalesAgent2" data-live-search="true" class="form-control selectpicker">

                                    <option data-icon="la la-user-alt font-size-lg bs-icon" value="">--SELECT SALES AGENT 2 (PRE SALES)--</option>

                                    <?php foreach($admins as $admin) {
                                        $isSelected2 = (current_url() == base_url('Booking/Update') || current_url() == base_url('Booking/Duplicate')) && $admin->AdminID == $SalesAgent2;
                                        if ($admin->Status == 'D' && !$isSelected2) continue;
                                    ?>

                                        <option <?php if($isSelected2) { echo 'selected'; } ?> data-icon="la la-user-alt font-size-lg bs-icon" value="<?php echo $admin->AdminID; ?>"><?php echo $admin->Name; ?></option>

                                    <?php } ?>

                                </select>

                                <div class="mt-2">
                                    <label style="font-size:13px; color:#1c3d5a; font-weight:600; cursor:pointer;">
                                        <input type="checkbox" id="SalesAgent2IsPIC" style="vertical-align:middle; width:16px; height:16px; margin-right:6px;" <?php echo $sa2_pic_checked ? 'checked' : ''; ?> data-initial="<?php echo $sa2_pic_checked ? 1 : 0; ?>">
                                        Show as Booking PIC on BC, TV &amp; Guest List
                                    </label>
                                </div>

                            </div>

                            <div class="form-group">

                                <label>Booking OP</label>

                                <select id="BookingOP" data-live-search="true" class="form-control selectpicker">

                                    <option data-icon="la la-user-cog font-size-lg bs-icon" value="">--SELECT BOOKING OP--</option>

                                    <?php foreach($booking_op_admins as $admin) { ?>

                                        <option <?php if((current_url() == base_url('Booking/Update') || current_url() == base_url('Booking/Duplicate')) && $admin->AdminID == $BookingOP) { echo 'selected'; } ?> data-icon="la la-user-cog font-size-lg bs-icon" value="<?php echo $admin->AdminID; ?>"><?php echo $admin->Name; ?></option>

                                    <?php } ?>

                                </select>

                            </div>

                            <div class="form-group">

                                <label>Remark</label>

                                <div class="input-icon">

                                    <input type="text" id="BookingRemark" <?php if(current_url() == base_url('Booking/Update') || current_url() == base_url('Booking/Duplicate')) { ?> value="<?php echo $BookingRemark; ?>" <?php } ?> autocomplete="off" class="form-control">

                                    <span>

                                        <i class="la la-pencil-alt"></i>

                                    </span>

                                </div>

                            </div>

                            <div class="form-group">

                                <label>Source

                                    <span style="color:red;">*</span>

                                </label>

                                <select id="Source" data-live-search="true" class="form-control selectpicker">

                                    <option selected disabled data-icon="la la-clipboard-list font-size-lg bs-icon" value="">--SELECT SOURCE--</option>

                                    <?php foreach($sources as $source) { ?>

                                        <option <?php if((current_url() == base_url('Booking/Update') || current_url() == base_url('Booking/Duplicate')) && $source->SourceID == $Source) { echo 'selected'; } ?> data-icon="<?php if($source->Name == 'WHATSAPP') { echo 'la la-whatsapp'; } else if($source->Name == 'WECHAT') { echo 'la la-wechat'; } else if($source->Name == 'EMAIL') { echo 'la la-envelope'; } else if($source->Name == 'CALL') { echo 'la la-phone-volume'; } else if($source->Name == 'TELEGRAM') { echo 'la la-telegram'; } else if($source->Name == 'FACEBOOK') { echo 'la la-facebook'; } else { echo 'la la-clipboard-list'; } ?> font-size-lg bs-icon" value="<?php echo $source->SourceID; ?>"><?php echo $source->Name; ?></option>

                                    <?php } ?>

                                </select>

                            </div>

                            <div class="form-group">

                                <label>Chat Language

                                    <span style="color:red;">*</span>

                                </label>

                                <select id="ChatLanguage" class="form-control selectpicker">

                                    <option selected disabled data-icon="la la-language font-size-lg bs-icon" value="">--SELECT CHAT LANGUAGE--</option>

                                    <?php foreach(unserialize(CHAT_LANGUAGE) as $key => $value) { ?>

                                        <option <?php if((current_url() == base_url('Booking/Update') || current_url() == base_url('Booking/Duplicate')) && $key == $ChatLanguage) { echo 'selected'; } ?> data-icon="la la-language font-size-lg bs-icon" value="<?php echo $key; ?>"><?php echo $value; ?></option>

                                    <?php } ?>

                                </select>

                            </div>

                            <div class="form-group">

                                <label>BC Title

                                    <span style="color:red;">*</span>

                                </label>

                                <select id="BookingConfirmationTitle" class="form-control selectpicker">

                                    <option selected disabled data-icon="la la-heading font-size-lg bs-icon" value="">--SELECT BC TITLE--</option>

                                    <?php foreach(unserialize(BC_TITLE) as $key => $value) { ?>

                                        <option <?php if((current_url() == base_url('Booking/Update') || current_url() == base_url('Booking/Duplicate')) && $key == $BookingConfirmationTitle) { echo 'selected'; } ?> data-icon="la la-heading font-size-lg bs-icon" value="<?php echo $key; ?>"><?php echo $value; ?></option>

                                    <?php } ?>

                                </select>

                            </div>

                            <div class="form-group">

                                <label>BC Tag

                                    <a onclick="Reset_Tag()" class="btn btn-icon btn-light-warning btn-xs">

                                        <i class="la la-undo"></i>

                                    </a>

                                </label>

                                <select title="--SELECT BC TAG--" id="Tag" multiple data-live-search="true" class="form-control selectpicker">

                                    <?php foreach($tags as $tag) { ?>

                                        <option <?php if((current_url() == base_url('Booking/Update') || current_url() == base_url('Booking/Duplicate')) && in_array($tag->TagID, $Tag)) { echo 'selected'; } ?> data-icon="la la-tags font-size-lg bs-icon" value="<?php echo $tag->TagID; ?>"><?php echo $tag->Name; ?></option>

                                    <?php } ?>

                                </select>

                            </div>

                        </div>

                        <div class="col-md-6">

                            <div class="form-group">
                                <label>
                                    Customer <span style="color:red;">*</span>
                                    <small id="customerInfo" class="text-muted ml-2">&laquo; New Customer &raquo;</small>
                                </label>

                                <div class="input-icon position-relative">
                                    <input type="text"
                                        id="Customer"
                                        name="Customer"
                                        <?php if (current_url() == base_url('Booking/Update') || current_url() == base_url('Booking/Duplicate')) { ?>
                                            value="<?php echo htmlspecialchars($Customer, ENT_QUOTES); ?>"
                                            data-selected-code="<?php echo htmlspecialchars(!empty($CustomerCode) ? $CustomerCode : '', ENT_QUOTES); ?>"
                                        <?php } ?>
                                        autocomplete="off"
                                        class="form-control"
                                        placeholder="Search or select customer">

                                    <!-- Hidden field to detect existing customer -->
                                    <?php
                                    $is_edit_page = in_array(current_url(), [base_url('Booking/Update'), base_url('Booking/Duplicate')]);
                                    $customer_id_value = ($is_edit_page && !empty($CustomerID)) ? $CustomerID : '';
                                    ?>
                                    <input type="hidden" id="CustomerID" name="CustomerID" value="<?= htmlspecialchars($customer_id_value, ENT_QUOTES) ?>">
                                    <!-- Set to 1 by the "Existing Customer Found" dialog when the agent
                                         chooses "Create new customer anyway", forcing the server to make a
                                         brand-new customer instead of reusing the same-phone match. -->
                                    <input type="hidden" id="force_new_customer" name="force_new_customer" value="">


                                    <span><i class="la la-user"></i></span>

                                    <div id="customerResults"
                                        class="list-group position-absolute w-100 shadow-sm"
                                        style="z-index:1000; display:none; top:100%; left:0; max-height:200px; overflow-y:auto;">
                                    </div>
                                </div>
                            </div>

                            <div class="form-group">
                                <label>Alt Name</label>
                                <div class="input-icon">
                                    <input type="text"
                                        id="AltName"
                                        name="AltName"
                                        <?php if (current_url() == base_url('Booking/Update') || current_url() == base_url('Booking/Duplicate')) { ?>
                                            value="<?php echo htmlspecialchars(isset($CustomerAltName) ? $CustomerAltName : '', ENT_QUOTES); ?>"
                                        <?php } ?>
                                        autocomplete="off"
                                        class="form-control"
                                        placeholder="Enter alternate name">
                                    <span><i class="la la-user-friends"></i></span>
                                </div>
                            </div>

                            <div class="form-group">

                                <label>Mobile <span style="color:red;">*</span></label>

                                <?php
                                    $selected_booking_country_code = null;
                                    $malaysia_booking_phone_id = null;
                                    foreach($country_codes as $cc) {
                                        if(strtoupper($cc->Country) == 'MALAYSIA') { $malaysia_booking_phone_id = $cc->CountryCodeID; break; }
                                    }
                                    $existing_booking_country = ((current_url() == base_url('Booking/Update') || current_url() == base_url('Booking/Duplicate')) && !empty($CustomerCountryCode)) ? $CustomerCountryCode : null;
                                    $default_booking_country = $existing_booking_country ? $existing_booking_country : $malaysia_booking_phone_id;
                                ?>

                                <div class="phone-input-wrapper" id="phone-wrapper-main">
                                    <div class="phone-country-selector" id="phone-selector-main">
                                        <span class="phone-country-flag" id="phone-flag-main">🌐</span>
                                        <span class="phone-country-code" id="phone-code-main">--</span>
                                        <span class="phone-country-arrow">▼</span>
                                    </div>
                                    <input type="text" id="Mobile" <?php if(current_url() == base_url('Booking/Update') || current_url() == base_url('Booking/Duplicate')) { ?> value="<?php echo $CustomerMobile; ?>" <?php } ?> autocomplete="off" class="phone-input-field" placeholder="Enter phone number">
                                    <select id="CountryCodeID" class="phone-hidden-field" onchange="updatePhoneFromSelectMain();">
                                        <option value="">--SELECT COUNTRY CODE--</option>
                                        <?php foreach($country_codes as $country_code) {
                                            $selected = '';
                                            if($country_code->CountryCodeID == $default_booking_country) {
                                                $selected = 'selected';
                                                $selected_booking_country_code = $country_code;
                                            }
                                        ?>
                                            <option <?php echo $selected; ?> value="<?php echo $country_code->CountryCodeID; ?>" data-country="<?php echo htmlspecialchars($country_code->Country); ?>" data-code="<?php echo htmlspecialchars($country_code->CountryCode); ?>"><?php echo $country_code->Country . ' ' . $country_code->CountryCode; ?></option>
                                        <?php } ?>
                                    </select>
                                    <div class="phone-dropdown" id="phone-dropdown-main">
                                        <div class="phone-dropdown-search">
                                            <input type="text" placeholder="Search country..." id="phone-search-main">
                                        </div>
                                        <div class="phone-dropdown-list" id="phone-list-main">
                                            <?php foreach($country_codes as $country_code) { ?>
                                                <div class="phone-dropdown-item" data-country-id="<?php echo $country_code->CountryCodeID; ?>" data-country="<?php echo htmlspecialchars(strtolower($country_code->Country)); ?>" data-code="<?php echo htmlspecialchars($country_code->CountryCode); ?>" data-country-name="<?php echo htmlspecialchars($country_code->Country); ?>">
                                                    <span class="phone-dropdown-item-flag">🌐</span>
                                                    <span class="phone-dropdown-item-name"><?php echo $country_code->Country; ?></span>
                                                    <span class="phone-dropdown-item-code"><?php echo $country_code->CountryCode; ?></span>
                                                </div>
                                            <?php } ?>
                                        </div>
                                    </div>
                                </div>
                                <small id="MobileError" class="text-danger"></small>

                                <script>
                                $(document).ready(function() {
                                    var selectedCountry = <?php echo !empty($selected_booking_country_code) ? json_encode(['CountryCodeID' => $selected_booking_country_code->CountryCodeID, 'Country' => $selected_booking_country_code->Country, 'CountryCode' => $selected_booking_country_code->CountryCode]) : 'null'; ?>;
                                    initPhoneInputMain(selectedCountry);
                                });
                                </script>

                            </div>

                            <div class="form-group">
                                <label>Email</label>
                                <div class="input-icon">
                                    <input type="email"
                                        id="Email"
                                        name="Email"
                                        <?php if (current_url() == base_url('Booking/Update') || current_url() == base_url('Booking/Duplicate')) { ?>
                                            value="<?php echo htmlspecialchars(isset($CustomerPrimaryEmail) ? $CustomerPrimaryEmail : '', ENT_QUOTES); ?>"
                                        <?php } ?>
                                        autocomplete="off"
                                        class="form-control"
                                        placeholder="Enter email address">
                                    <span><i class="la la-envelope"></i></span>
                                </div>
                            </div>

                            <div class="form-group">
                                <label>IC / Passport / SSM No. <span style="color:red;">*</span></label>
                                <div class="input-icon">
                                    <input type="text"
                                        id="ic_passport_no"
                                        name="ic_passport_no"
                                        value="<?php echo isset($ic_passport_no) ? htmlspecialchars($ic_passport_no, ENT_QUOTES) : ''; ?>"
                                        autocomplete="off"
                                        class="form-control"
                                        placeholder="Enter IC or Passport number">
                                    <span><i class="la la-id-card"></i></span>
                                </div>
                            </div>

                            <div class="form-group">
                                <label>TIN No.</label>
                                <div class="input-icon">
                                    <input type="text"
                                        id="tin_no"
                                        name="tin_no"
                                        value="<?php echo isset($tin_no) ? htmlspecialchars($tin_no, ENT_QUOTES) : ''; ?>"
                                        autocomplete="off"
                                        class="form-control"
                                        placeholder="Enter Tax Identification Number">
                                    <span><i class="la la-file-invoice"></i></span>
                                </div>
                            </div>

                            <div class="form-group">
                                <label>Customer Type <span style="color:red;">*</span></label>
                                <select id="customer_type" name="customer_type[]" class="form-control selectpicker" multiple data-actions-box="true" data-live-search="true" title="--SELECT CUSTOMER TYPE--" required>
                                    <?php
                                        $selected_customer_types = isset($customer_types_selected) && is_array($customer_types_selected) ? $customer_types_selected : array();
                                        if(!empty($customer_types)) { foreach($customer_types as $ct) { ?>
                                        <option <?php if(in_array($ct->Name, $selected_customer_types, true)) { echo 'selected'; } ?> data-icon="la la-user-tag font-size-lg bs-icon" value="<?php echo $ct->CustomerTypeID; ?>"><?php echo $ct->Name; ?></option>
                                    <?php } } ?>
                                </select>
                            </div>

                            <div class="form-group">
                                <label>
                                    Alternate contact person
                                    <small id="customerInfo2" class="text-muted ml-2">&laquo; New Customer &raquo;</small>
                                </label>

                                <div class="input-icon position-relative">
                                    <input type="text"
                                        id="Customer2"
                                        name="Customer2"
                                        <?php if (current_url() == base_url('Booking/Update') || current_url() == base_url('Booking/Duplicate')) { ?>
                                            value="<?php echo isset($Customer2) ? htmlspecialchars($Customer2, ENT_QUOTES) : ''; ?>"
                                        <?php } ?>
                                        autocomplete="off"
                                        class="form-control"
                                        placeholder="Optional — search or type a second contact">

                                    <?php
                                    $customer_id_2_value = ($is_edit_page && !empty($CustomerID2)) ? $CustomerID2 : '';
                                    ?>
                                    <input type="hidden" id="CustomerID2" name="CustomerID2" value="<?= htmlspecialchars($customer_id_2_value, ENT_QUOTES) ?>">

                                    <span><i class="la la-user"></i></span>

                                    <div id="customerResults2"
                                        class="list-group position-absolute w-100 shadow-sm"
                                        style="z-index:1000; display:none; top:100%; left:0; max-height:200px; overflow-y:auto;">
                                    </div>
                                </div>
                            </div>

                            <div class="form-group">

                                <label>Alternate mobile</label>

                                <?php
                                    $selected_booking_country_code_2 = null;
                                    $existing_booking_country_2 = ((current_url() == base_url('Booking/Update') || current_url() == base_url('Booking/Duplicate')) && !empty($CustomerCountryCode2)) ? $CustomerCountryCode2 : null;
                                    $default_booking_country_2 = $existing_booking_country_2 ? $existing_booking_country_2 : $malaysia_booking_phone_id;
                                ?>

                                <div class="phone-input-wrapper" id="phone-wrapper-second">
                                    <div class="phone-country-selector" id="phone-selector-second">
                                        <span class="phone-country-flag" id="phone-flag-second">🌐</span>
                                        <span class="phone-country-code" id="phone-code-second">--</span>
                                        <span class="phone-country-arrow">▼</span>
                                    </div>
                                    <input type="text" id="Mobile2" name="Mobile2" <?php if(current_url() == base_url('Booking/Update') || current_url() == base_url('Booking/Duplicate')) { ?> value="<?php echo isset($CustomerMobile2) ? htmlspecialchars($CustomerMobile2, ENT_QUOTES) : ''; ?>" <?php } ?> autocomplete="off" class="phone-input-field" placeholder="Optional — enter second phone number">
                                    <select id="CountryCodeID2" name="CountryCodeID2" class="phone-hidden-field" onchange="updatePhoneFromSelectSecond();">
                                        <option value="">--SELECT COUNTRY CODE--</option>
                                        <?php foreach($country_codes as $country_code) {
                                            $selected = '';
                                            if($country_code->CountryCodeID == $default_booking_country_2) {
                                                $selected = 'selected';
                                                $selected_booking_country_code_2 = $country_code;
                                            }
                                        ?>
                                            <option <?php echo $selected; ?> value="<?php echo $country_code->CountryCodeID; ?>" data-country="<?php echo htmlspecialchars($country_code->Country); ?>" data-code="<?php echo htmlspecialchars($country_code->CountryCode); ?>"><?php echo $country_code->Country . ' ' . $country_code->CountryCode; ?></option>
                                        <?php } ?>
                                    </select>
                                    <div class="phone-dropdown" id="phone-dropdown-second">
                                        <div class="phone-dropdown-search">
                                            <input type="text" placeholder="Search country..." id="phone-search-second">
                                        </div>
                                        <div class="phone-dropdown-list" id="phone-list-second">
                                            <?php foreach($country_codes as $country_code) { ?>
                                                <div class="phone-dropdown-item" data-country-id="<?php echo $country_code->CountryCodeID; ?>" data-country="<?php echo htmlspecialchars(strtolower($country_code->Country)); ?>" data-code="<?php echo htmlspecialchars($country_code->CountryCode); ?>" data-country-name="<?php echo htmlspecialchars($country_code->Country); ?>">
                                                    <span class="phone-dropdown-item-flag">🌐</span>
                                                    <span class="phone-dropdown-item-name"><?php echo $country_code->Country; ?></span>
                                                    <span class="phone-dropdown-item-code"><?php echo $country_code->CountryCode; ?></span>
                                                </div>
                                            <?php } ?>
                                        </div>
                                    </div>
                                </div>
                                <small id="Mobile2Error" class="text-danger"></small>

                                <script>
                                $(document).ready(function() {
                                    var selectedCountry2 = <?php echo !empty($selected_booking_country_code_2) ? json_encode(['CountryCodeID' => $selected_booking_country_code_2->CountryCodeID, 'Country' => $selected_booking_country_code_2->Country, 'CountryCode' => $selected_booking_country_code_2->CountryCode]) : 'null'; ?>;
                                    initPhoneInputSecond(selectedCountry2);
                                });
                                </script>

                            </div>

                            <div class="form-group">

                                <label>Destination

                                    <?php if(current_url() == base_url('Booking/Create')) { ?><span style="color:red;">*</span><?php } ?>

                                </label>

                                <select id="Destination" data-live-search="true" data-live-search-style="contains" data-live-search-normalize="true" class="form-control selectpicker">

                                    <option selected disabled data-icon="la la-map-pin font-size-lg bs-icon" value="">--SELECT DESTINATION--</option>

                                    <?php foreach($categories as $category) { ?>

                                        <option <?php if((current_url() == base_url('Booking/Update') || current_url() == base_url('Booking/Duplicate')) && $category->CategoryID == $Destination) { echo 'selected'; } ?> data-icon="la la-map-pin font-size-lg bs-icon" value="<?php echo $category->CategoryID; ?>"><?php echo $category->Name; ?></option>

                                    <?php } ?>

                                </select>

                            </div>

                            <div class="form-group">

                                <label>Travel Date

                                    <span style="color:red;">*</span>

                                </label>

                                <div id="kt_daterangepicker_4" class="input-icon">

                                    <input readonly type="text" id="TravelDate" <?php if(current_url() == base_url('Booking/Update') || current_url() == base_url('Booking/Duplicate')) { ?> value="<?php echo $TravelDate; ?>" <?php } ?> autocomplete="off" class="form-control">

                                    <span>

                                        <i class="la la-calendar"></i>

                                    </span>

                                </div>

                            </div>

                            <div class="form-group">

                                <label>Deposit Deadline

                                    <a onclick="Reset_Deposit_Deadline()" class="btn btn-icon btn-light-warning btn-xs">

                                        <i class="la la-undo"></i>

                                    </a>

                                </label>

                                <div class="input-icon">

                                    <input readonly type="text" name="DepositDeadline" id="kt_datepicker_4_3" <?php if(current_url() == base_url('Booking/Update') || current_url() == base_url('Booking/Duplicate')) { ?> value="<?php echo $DepositDeadline; ?>" <?php } ?> autocomplete="off" class="form-control">

                                    <span>

                                        <i class="la la-calendar"></i>

                                    </span>

                                </div>

                            </div>

                            <div class="form-group">

                                <label>Full Payment Deadline</label>

                                <div class="input-icon">

                                    <input readonly type="text" name="FullPaymentDeadline" id="kt_datepicker_4_4" <?php if(current_url() == base_url('Booking/Update') || current_url() == base_url('Booking/Duplicate')) { ?> value="<?php echo $FullPaymentDeadline; ?>" <?php } ?> autocomplete="off" class="form-control">

                                    <span>

                                        <i class="la la-calendar"></i>

                                    </span>

                                </div>

                            </div>

                        </div>

                        <?php if(current_url() == base_url('Booking/Create')) { ?>
                            <div class="col-lg-6 col-md-12">
                                <?php print_allow_review($AllowReview); ?>
                            </div>
                        <?php } ?>

                        <div class="col-md-6 d-none">

                            <div class="form-group">

                                <label>Adult</label>

                                <div class="input-icon">

                                    <input <?php if(current_url() == base_url('Booking/Update')) { echo 'disabled'; } ?> type="text" id="Adult" <?php if(current_url() == base_url('Booking/Update') || current_url() == base_url('Booking/Duplicate')) { ?> value="<?php echo $Adult; ?>" <?php } ?> autocomplete="off" class="form-control">

                                    <span>

                                        <i class="la la-male"></i>

                                    </span>

                                </div>

                            </div>

                        </div>

                        <div class="col-md-6 d-none">

                            <div class="form-group">

                                <label>Children</label>

                                <div class="input-icon">

                                    <input <?php if(current_url() == base_url('Booking/Update')) { echo 'disabled'; } ?> type="text" id="Children" <?php if(current_url() == base_url('Booking/Update') || current_url() == base_url('Booking/Duplicate')) { ?> value="<?php echo $Children; ?>" <?php } ?> autocomplete="off" class="form-control">

                                    <span>

                                        <i class="la la-child"></i>

                                    </span>

                                </div>

                            </div>

                        </div>

                        <div class="col-md-6 d-none">

                            <div class="form-group">

                                <label>Infant</label>

                                <div class="input-icon">

                                    <input <?php if(current_url() == base_url('Booking/Update')) { echo 'disabled'; } ?> type="text" id="Infant" <?php if(current_url() == base_url('Booking/Update') || current_url() == base_url('Booking/Duplicate')) { ?> value="<?php echo $Infant; ?>" <?php } ?> autocomplete="off" class="form-control">

                                    <span>

                                        <i class="la la-baby-carriage"></i>

                                    </span>

                                </div>

                            </div>

                        </div>

                    </div>

                    <div class="d-flex justify-content-between border-top pt-5"></div>

                    <strong>Product Information :</strong>

                    <?php if(current_url() == base_url('Booking/Update')) { ?>

                        <div class="row">

                            <div class="col-md-6">

                                <select id="product_action" class="form-control selectpicker mt-3">

                                    <option selected data-icon="la la-product-hunt font-size-lg bs-icon" value="INSERT PRODUCT">INSERT PRODUCT</option>

                                    <option data-icon="la la-product-hunt font-size-lg bs-icon" value="ORGANIZE PRODUCT">ORGANIZE PRODUCT</option>

                                </select>

                            </div>

                        </div>

                    <?php } ?>

                    <div class="form-group">
                        <label>Booking Form</label>
                        <textarea id="BookingFormText" rows="6" autocomplete="off" class="form-control" placeholder="Free-text booking details (editable while the booking is a draft)"><?php if(current_url() == base_url('Booking/Update') || current_url() == base_url('Booking/Duplicate')) { echo isset($BookingFormText) ? htmlspecialchars($BookingFormText, ENT_QUOTES) : ''; } ?></textarea>
                    </div>

                    <div class="form-group">
                        <label>Chat Summary</label>
                        <textarea id="ChatSummary" rows="6" autocomplete="off" class="form-control" placeholder="Summary of the customer chat (editable while the booking is a draft)"><?php if(current_url() == base_url('Booking/Update') || current_url() == base_url('Booking/Duplicate')) { echo isset($ChatSummary) ? htmlspecialchars($ChatSummary, ENT_QUOTES) : ''; } ?></textarea>
                    </div>

                    <br><br>

                    <div id="benchmark" class="row draggable-zone"></div>

                    <a id="create_booking_product" class="btn btn-light-success btn-sm mt-2" style="width:180px;">

                        <i class="la la-plus-circle"></i>Insert Product

                    </a>

                    <br><br>

                    <div class="row">

                        <div class="col-md-12 pt-3 pb-3" style="background-color:white; border:3px solid #D7E2F2; border-radius:8px;">

                            <div class="row">

                                <div class="col-md-4 mb-7 mb-md-0">

                                    <label style="color:#088F8F;">Subtotal (RM)</label>

                                    <div class="input-icon">

                                        <input disabled type="text" id="Subtotal" <?php if(current_url() == base_url('Booking/Create')) { ?> value="0.00" <?php } else { ?> value="<?php echo $Subtotal; ?>" <?php } ?> class="form-control" style="text-align:right;">

                                        <span>

                                            <i class="la la-dollar"></i>

                                        </span>

                                    </div>

                                </div>

                                <div class="col-md-4 mb-7 mb-md-0">

                                    <label style="color:#2AAA8A;">- Discount (RM)</label>

                                    <div class="input-icon">

                                        <input type="text" id="Discount" <?php if(current_url() == base_url('Booking/Create')) { ?> value="" <?php } else { ?> value="<?php echo $Discount; ?>" <?php } ?> autocomplete="off" class="form-control" style="text-align:right;">

                                        <span>

                                            <i class="la la-dollar"></i>

                                        </span>

                                    </div>

                                </div>

                                <div class="col-md-4">

                                    <label style="color:#50C878;">= Net Total (RM)</label>

                                    <div class="input-icon">

                                        <input disabled type="text" id="NetTotal" <?php if(current_url() == base_url('Booking/Create')) { ?> value="0.00" <?php } else { ?> value="<?php echo $NetTotal; ?>" <?php } ?> class="form-control" style="text-align:right;">

                                        <span>

                                            <i class="la la-dollar"></i>

                                        </span>

                                    </div>

                                </div>

                                <div class="col-12">
                                    <?php
                                        $hide_deposit_container = false;
                                        if(current_url() == base_url('Booking/Update') || current_url() == base_url('Booking/Duplicate')) {
                                            $deposit_deadline_value = isset($DepositDeadline) ? $DepositDeadline : '';
                                            if(empty($deposit_deadline_value)) {
                                                $hide_deposit_container = true;
                                            }
                                        }
                                    ?>
                                    <div class="row" id="deposit_container" <?php if($hide_deposit_container) { echo 'style="display: none;"'; } ?>>
                                        <?php
                                            $deposit_mode_value = 'percentage';
                                            if(current_url() == base_url('Booking/Update') || current_url() == base_url('Booking/Duplicate')) {
                                                $deposit_mode_value = isset($DepositMode) ? $DepositMode : 'percentage';
                                            }
                                            $deposit_fixed_amount_value = 0;
                                            if(current_url() == base_url('Booking/Update') || current_url() == base_url('Booking/Duplicate')) {
                                                $deposit_fixed_amount_value = isset($DepositFixedAmount) ? $DepositFixedAmount : 0;
                                            }
                                        ?>
                                        <div class="col-md-3 mt-5">
                                            <label style="color:#50C878;">Deposit Mode</label>
                                            <select id="DepositMode" class="form-control">
                                                <option value="percentage" <?php echo $deposit_mode_value == 'percentage' ? 'selected' : ''; ?>>Percentage</option>
                                                <option value="fixed" <?php echo $deposit_mode_value == 'fixed' ? 'selected' : ''; ?>>Fixed Amount</option>
                                            </select>
                                        </div>
                                        <div class="col-md-3 mt-5">
                                            <label id="DepositValueLabel" style="color:#50C878;"><?php echo $deposit_mode_value == 'fixed' ? 'Deposit Amount (RM)' : 'Deposit Percentage'; ?></label>
                                            <div class="input-icon">
                                                <?php if($deposit_mode_value == 'fixed') { ?>
                                                    <input type="number" id="DepositPercentage" class="form-control" style="text-align:right; display:none;" min="0" max="100" <?php if(current_url() == base_url('Booking/Create')) { ?> value="50" <?php } else { ?> value="<?php echo isset($DepositPercentage) ? $DepositPercentage : 0; ?>" <?php } ?>>
                                                    <input type="number" id="DepositFixedAmount" class="form-control" style="text-align:right;" min="0" step="0.01" value="<?php echo $deposit_fixed_amount_value; ?>">
                                                <?php } else { ?>
                                                    <input type="number" id="DepositPercentage" class="form-control" style="text-align:right;" min="0" max="100" <?php if(current_url() == base_url('Booking/Create')) { ?> value="50" <?php } else { ?> value="<?php echo isset($DepositPercentage) ? $DepositPercentage : 0; ?>" <?php } ?>>
                                                    <input type="number" id="DepositFixedAmount" class="form-control" style="text-align:right; display:none;" min="0" step="0.01" value="<?php echo $deposit_fixed_amount_value; ?>">
                                                <?php } ?>
                                                <span id="DepositValueIcon">
                                                    <i class="la <?php echo $deposit_mode_value == 'fixed' ? 'la-dollar' : 'la-percentage'; ?>"></i>
                                                </span>
                                            </div>
                                        </div>
                                        <div class="col-md-3 mt-5">
                                            <label style="color:#50C878;">Deposit Total (RM)</label>
                                            <div class="input-icon">
                                                <input disabled type="text" id="DepositTotal" value="0.00" class="form-control" style="text-align:right;">
                                                <span>
                                                    <i class="la la-dollar"></i>
                                                </span>
                                            </div>
                                        </div>
                                        <div class="col-md-3 mt-5">
                                            <label style="color:#50C878;">Deposit Paid (RM)</label>
                                            <div class="input-icon">
                                                <input disabled type="text" id="DepositPaid" value="<?php echo isset($DepositPaidDisplay) ? $DepositPaidDisplay : '0.00'; ?>" class="form-control" style="text-align:right; <?php echo (isset($DepositPaidColor) && ($DepositPaidColor == '#FF6B6B' || $DepositPaidColor == '#FFA500')) ? 'color: ' . $DepositPaidColor . '; font-weight: bold;' : ''; ?>">
                                                <span>
                                                    <i class="la la-dollar"></i>
                                                </span>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                            </div>

                        </div>

                    </div>

                    <br>

                    <?php if(current_url() == base_url('Booking/Update') || current_url() == base_url('Booking/Create') || current_url() == base_url('Booking/Duplicate')) { ?>
                    <div class="d-flex justify-content-between border-top pt-5"></div>

                    <strong>Room Management :</strong>

                    <br><br>

                    <div class="row">
                        <div class="col-md-12">
                            <div class="card card-custom mb-5" style="border: 1px solid #D7E2F2;">
                                <div class="card-header flex-wrap py-3" style="background-color:#D7E2F2;">
                                    <div class="card-title">
                                        <h3 class="card-label" style="color:#6082B6;">
                                            <strong>Room Management</strong>
                                        </h3>
                                    </div>
                                    <div class="card-toolbar">
                                        <?php
                                        $show_gl_toggle = (current_url() == base_url('Booking/Update'))
                                            && !empty($this->session->userdata('admin_id'))
                                            && !empty($BookingID) && $BookingID !== 'NA'
                                            && !empty($Token);
                                        ?>
                                        <?php if(isset($LockStatus) && $LockStatus == 'Y') { ?>
                                            <span class="badge badge-danger font-weight-bold mr-2"><i class="la la-lock"></i> GL Locked</span>
                                            <?php if($show_gl_toggle) { ?>
                                                <a href="<?php echo base_url('Booking/Update_Lock_Status?booking_id=') . $BookingID . '&current_lock_status=Y&new_lock_status=N&gl=' . $Token . '&return_to=booking'; ?>"
                                                   class="btn btn-sm btn-light-success font-weight-bold"
                                                   onclick="return confirm('Unlock Guest List for this booking?');">
                                                    <i class="la la-unlock"></i> Unlock GL
                                                </a>
                                            <?php } ?>
                                        <?php } else { ?>
                                            <?php if($show_gl_toggle) { ?>
                                                <a href="<?php echo base_url('Booking/Update_Lock_Status?booking_id=') . $BookingID . '&current_lock_status=N&new_lock_status=Y&gl=' . $Token . '&return_to=booking'; ?>"
                                                   class="btn btn-sm btn-light-danger font-weight-bold mr-2"
                                                   onclick="return confirm('Lock Guest List? If conditions are met, status will auto-advance to PTV.');">
                                                    <i class="la la-lock"></i> Lock GL
                                                </a>
                                            <?php } ?>
                                            <button type="button" id="create_room_btn" class="btn btn-sm btn-light-success font-weight-bold">
                                                <i class="la la-plus"></i> Add Room
                                            </button>
                                        <?php } ?>
                                    </div>
                                </div>
                                <div class="card-body">
                                    <div class="mb-3">
                                        <small class="text-muted">
                                            Total Room Pax — Adult: <strong id="allocated_adult">0</strong>,
                                            Children: <strong id="allocated_child">0</strong>,
                                            Infant: <strong id="allocated_infant">0</strong>
                                        </small>
                                    </div>
                                    <div class="table-responsive">
                                        <table class="table table-bordered table-head-solid" id="rooms_table">
                                            <thead>
                                                <tr>
                                                    <th>Room Name</th>
                                                    <th class="text-center" style="width:130px;">Adult</th>
                                                    <th class="text-center" style="width:130px;">Child</th>
                                                    <th class="text-center" style="width:130px;">Infant</th>
                                                    <th class="text-center" style="width:120px;">Actions</th>
                                                </tr>
                                            </thead>
                                            <tbody id="rooms_list">
                                                <tr id="no_rooms_row"><td colspan="5" class="text-muted text-center">No rooms created yet. Click "Add Room" to create one.</td></tr>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <br>
                    <?php } ?>

                    <div class="d-flex justify-content-between border-top pt-5"></div>

                    <strong>Footer Information :</strong>

                    <br><br>

                    <div class="row">

                        <div class="col-md-6">

                            <div class="form-group">

                                <label>Booking Confirmation</label>

                                <select id="booking_confirmation_footer" data-live-search="true" class="form-control selectpicker">

                                    <option selected data-icon="la la-clipboard-list font-size-lg bs-icon" value="">--SELECT BC FOOTER--</option>

                                    <?php foreach($footers as $footer) { ?>

                                        <option <?php if((current_url() == base_url('Booking/Update') || current_url() == base_url('Booking/Duplicate')) && $footer->FooterID == $BookingConfirmationFooterID) { echo 'selected'; } ?> data-icon="la la-clipboard-list font-size-lg bs-icon" value="<?php echo $footer->FooterID; ?>"><?php echo $footer->BookingConfirmationTitle; ?></option>

                                    <?php } ?>

                                </select>

                            </div>

                            <div id="booking_confirmation_content" class="alert alert-custom alert-light-info fade show mb-5" style="overflow-x:auto;">

                                <div class="alert-text">

                                    <label style="color:#3F4254;">Content :</label>

                                    <textarea id="kt-tinymce-4" autocomplete="off" class="tox-target"><?php if(current_url() == base_url('Booking/Update') || current_url() == base_url('Booking/Duplicate')) { echo $BookingConfirmationFooter; } ?></textarea>

                                </div>

                            </div>

                        </div>

                        <div class="col-md-6">

                            <div class="form-group">

                                <label>Travel Voucher</label>

                                <select id="travel_voucher_footer" data-live-search="true" class="form-control selectpicker">

                                    <option selected data-icon="la la-clipboard-list font-size-lg bs-icon" value="">--SELECT TV FOOTER--</option>

                                    <?php foreach($footers as $footer) { ?>

                                        <option <?php if((current_url() == base_url('Booking/Update') || current_url() == base_url('Booking/Duplicate')) && $footer->FooterID == $TravelVoucherFooterID) { echo 'selected'; } ?> data-icon="la la-clipboard-list font-size-lg bs-icon" value="<?php echo $footer->FooterID; ?>"><?php echo $footer->TravelVoucherTitle; ?></option>

                                    <?php } ?>

                                </select>

                            </div>

                            <div id="travel_voucher_content" class="alert alert-custom alert-light-info fade show mb-5" style="overflow-x:auto;">

                                <div class="alert-text">

                                    <label style="color:#3F4254;">Content :</label>

                                    <textarea id="kt-tinymce-5" autocomplete="off" class="tox-target"><?php if(current_url() == base_url('Booking/Update') || current_url() == base_url('Booking/Duplicate')) { echo $TravelVoucherFooter; } ?></textarea>

                                </div>

                            </div>

                            <div id="travel_voucher_key_contacts" class="alert alert-custom alert-light-info fade show mb-5" style="overflow-x:auto;">

                                <div class="alert-text">

                                    <label style="color:#3F4254;">Key Contacts :</label>

                                    <textarea id="kt-tinymce-6" autocomplete="off" class="tox-target"><?php if(current_url() == base_url('Booking/Update') || current_url() == base_url('Booking/Duplicate')) { echo isset($KeyContacts) ? $KeyContacts : ''; } ?></textarea>

                                </div>

                            </div>

                            <div id="travel_voucher_special_remarks" class="alert alert-custom alert-light-info fade show mb-5" style="overflow-x:auto;">

                                <div class="alert-text">

                                    <label style="color:#3F4254;">Special Remarks :</label>

                                    <textarea id="kt-tinymce-7" autocomplete="off" class="tox-target"><?php if(current_url() == base_url('Booking/Update') || current_url() == base_url('Booking/Duplicate')) { echo isset($SpecialRemarks) ? $SpecialRemarks : ''; } ?></textarea>

                                </div>

                            </div>

                        </div>

                    </div>

                    <div class="d-flex justify-content-between border-top pt-5" style="overflow-x:auto;">

                        <?php
                            // Draft lifecycle controls (Update page). The button set
                            // depends on the draft state:
                            //   SAD, not approved -> Save as Draft + Approve (form locked)
                            //   SAD approved, or PB -> Save as Pending BC + Pending BC Confirmation
                            // A single hidden #draft_save_mode carries the chosen action.
                            $is_update_page  = (current_url() == base_url('Booking/Update'));
                            $is_draft_booking = isset($Status) && $Status === 'SAD';
                            $is_pending_bc    = isset($Status) && $Status === 'PB';
                            $draft_approved   = !empty($DraftApproved);

                            $show_graduate    = $is_update_page && (($is_draft_booking && $draft_approved) || $is_pending_bc);
                            $show_approve     = $is_update_page && $is_draft_booking && !$draft_approved;
                            // Hide the default Update button whenever draft-specific controls take over.
                            $hide_main_btn    = $show_graduate || $show_approve;
                        ?>
                        <input type="hidden" id="draft_save_mode" name="draft_save_mode" value="">

                        <input type="button" id="main_submit_btn" value="<?php if(current_url() == base_url('Booking/Create') || current_url() == base_url('Booking/Duplicate')) { echo 'Create Booking'; } else { echo 'Update Booking'; } ?>" class="btn btn-success font-weight-bold px-9 py-4" style="width:180px; margin-left:auto;<?php if($hide_main_btn) { echo ' display:none;'; } ?>">

                        <?php if($show_approve) { ?>
                            <button type="button" id="draft_save_btn" class="btn btn-light-primary font-weight-bold px-6 py-4" style="margin-left:auto;">Save as Draft</button>
                            <button type="button" id="draft_approve_btn" class="btn btn-success font-weight-bold px-6 py-4 ml-1">Approve</button>
                        <?php } elseif($show_graduate) { ?>
                            <button type="button" id="graduate_pb_btn" class="btn btn-light-warning font-weight-bold px-6 py-4" style="margin-left:auto;">Save as Pending BC</button>
                            <button type="button" id="graduate_pbc_btn" class="btn btn-success font-weight-bold px-6 py-4 ml-1">Save as Pending BC Confirmation</button>
                        <?php } ?>

                        <?php if(current_url() == base_url('Booking/Update')) { ?>

                            <?php
                                $this->load->helper('booking_flow');
                                $sa_blocked_gl = isset($Status, $AfterSalesService) && is_sa_blocked_from_completed_booking($this->session->userdata('level'), $Status, $AfterSalesService);
                            ?>
                            <?php if(!$sa_blocked_gl) { ?>
                                <a href="<?php echo base_url('Guest_List?gl=') . $Token; ?>" class="btn btn-light-primary font-weight-bold px-9 py-4 ml-1" style="width:180px;">GL</a>
                            <?php } ?>

                            <?php if(in_array('VP', $this->session->access_control)) { ?>

                                <a href="<?php echo base_url('Payment?booking_number=') . $BookingNumber . '&customer=' . urlencode($Customer); ?>" class="btn btn-light-warning font-weight-bold px-9 py-4 ml-1" style="width:180px;">Payments</a>

                            <?php } ?>

                        <?php } ?>

                    </div>

                </form>

            </div>

        </div>

        <?php if(current_url() == base_url('Booking/Update')) { ?>

            <!-- Supplier Invoices Section -->
            <div class="row mt-5 mb-5">
                <div class="col">
                    <div class="card card-custom" id="supplier-invoices">
                        <div class="card-header flex-wrap py-2" style="background-color:#FFF3E0;">
                            <div class="card-title">
                                <h4 class="card-label mb-0" style="color:#E65100; font-size: 1.1rem;">
                                    <strong>Supplier Invoices</strong>
                                </h4>
                            </div>
                            <div class="card-toolbar">
                                <button type="button" id="add-supplier-invoice-btn" class="btn btn-primary btn-sm font-weight-bold">
                                    <i class="la la-plus"></i> Add Invoice
                                </button>
                            </div>
                        </div>
                        <div class="card-body p-3">
                            <div class="table-responsive">
                                <table id="supplier-invoices-table" class="table table-sm table-bordered mb-0" style="font-size: 0.875rem;">
                                    <thead style="background-color:#F5F5F5;">
                                        <tr>
                                            <th style="min-width:180px;">Supplier</th>
                                            <th style="min-width:140px;">Invoice #</th>
                                            <th style="min-width:120px; text-align:right;">Invoice Amount (RM)</th>
                                            <th style="min-width:140px;">Payment Deadline</th>
                                            <th style="min-width:160px;">Remark</th>
                                            <th style="min-width:110px; text-align:right;">Paid (RM)</th>
                                            <th style="min-width:110px; text-align:right;">Balance (RM)</th>
                                            <th style="min-width:110px; text-align:center;">Invoice File</th>
                                            <th style="width:50px; text-align:center;">&nbsp;</th>
                                        </tr>
                                    </thead>
                                    <tbody id="supplier-invoices-tbody">
                                        <?php if (!empty($supplier_invoices)) { foreach ($supplier_invoices as $inv) {
                                            $deadline_display = !empty($inv->PaymentDeadline) ? date('d/m/Y', strtotime($inv->PaymentDeadline)) : '';
                                        ?>
                                            <tr class="supplier-invoice-row"
                                                data-supplier-invoice-id="<?php echo (int)$inv->SupplierInvoiceID; ?>"
                                                data-initial-supplier-id="<?php echo (int)$inv->SupplierID; ?>"
                                                data-initial-invoice-number="<?php echo htmlspecialchars($inv->InvoiceNumber, ENT_QUOTES); ?>"
                                                data-initial-invoice-amount="<?php echo htmlspecialchars($inv->InvoiceAmount, ENT_QUOTES); ?>"
                                                data-initial-payment-deadline="<?php echo htmlspecialchars((string)$inv->PaymentDeadline, ENT_QUOTES); ?>"
                                                data-initial-remark="<?php echo htmlspecialchars((string)$inv->Remark, ENT_QUOTES); ?>"
                                                data-file-path="<?php echo htmlspecialchars((string)$inv->InvoiceFilePath, ENT_QUOTES); ?>"
                                                data-initial-file-path="<?php echo htmlspecialchars((string)$inv->InvoiceFilePath, ENT_QUOTES); ?>"
                                                data-deleted="0">
                                                <td>
                                                    <select class="supplier-invoice-supplier form-control form-control-sm">
                                                        <option value="">-- Select Supplier --</option>
                                                        <?php foreach ($supplier_invoice_suppliers as $sup) { ?>
                                                            <option value="<?php echo (int)$sup->SupplierID; ?>" <?php if ($sup->SupplierID == $inv->SupplierID) echo 'selected'; ?>>
                                                                <?php echo htmlspecialchars($sup->Name); ?>
                                                            </option>
                                                        <?php } ?>
                                                    </select>
                                                </td>
                                                <td>
                                                    <input type="text" class="supplier-invoice-number form-control form-control-sm" value="<?php echo htmlspecialchars($inv->InvoiceNumber); ?>">
                                                </td>
                                                <td>
                                                    <input type="number" step="0.01" min="0" class="supplier-invoice-amount form-control form-control-sm" style="text-align:right;" value="<?php echo htmlspecialchars($inv->InvoiceAmount); ?>">
                                                </td>
                                                <td>
                                                    <input type="text" readonly class="supplier-invoice-deadline form-control form-control-sm" autocomplete="off" value="<?php echo $deadline_display; ?>">
                                                </td>
                                                <td>
                                                    <input type="text" class="supplier-invoice-remark form-control form-control-sm" value="<?php echo htmlspecialchars((string)$inv->Remark); ?>">
                                                </td>
                                                <td style="text-align:right; vertical-align:middle; color:#388E3C;">
                                                    <?php echo number_format((float)$inv->PaidAmount, 2, '.', ','); ?>
                                                </td>
                                                <td style="text-align:right; vertical-align:middle; color:<?php echo ((float)$inv->BalanceDue > 0 ? '#C62828' : '#9E9E9E'); ?>;">
                                                    <strong><?php echo number_format((float)$inv->BalanceDue, 2, '.', ','); ?></strong>
                                                </td>
                                                <td class="supplier-invoice-file-cell" style="text-align:center; vertical-align:middle;">
                                                    <input type="file" class="supplier-invoice-file-input" accept=".pdf,.jpg,.jpeg,.png,.doc,.docx,.xls,.xlsx" style="display:none;">
                                                    <span class="supplier-invoice-file-ui"></span>
                                                </td>
                                                <td style="text-align:center; vertical-align:middle;">
                                                    <button type="button" class="supplier-invoice-remove btn btn-danger btn-xs" data-toggle="tooltip" title="Remove invoice">
                                                        <i class="la la-trash"></i>
                                                    </button>
                                                </td>
                                            </tr>
                                        <?php } } ?>
                                    </tbody>
                                </table>
                            </div>
                            <?php if (empty($supplier_invoices)) { ?>
                                <div id="supplier-invoices-empty" class="text-center text-muted py-3" style="font-size:0.875rem;">No supplier invoices yet. Click "Add Invoice" above to enter one.</div>
                            <?php } ?>
                        </div>
                    </div>
                </div>
            </div>

            <div class="row">
                <!-- Internal Comments Section -->
                <div class="col-lg-6 col-md-12">
                    <div class="row">
                        <div class="col">
                            <div class="card card-custom" id="internal-comments">
                                <div class="card-header flex-wrap py-2" style="background-color:#D7E2F2;">
                                    <div class="card-title">
                                        <h4 class="card-label mb-0" style="color:#6082B6; font-size: 1.1rem;">
                                            <strong>Internal Comments</strong>
                                        </h4>
                                    </div>
                                </div>
                                <div class="card-body">
                                    <!-- Comments List -->
                                    <div id="internal-comments-list" class="mb-2">
                                        <div class="text-center text-muted py-2" style="font-size: 0.8125rem;">
                                            <i class="la la-spinner la-spin"></i> Loading comments...
                                        </div>
                                    </div>

                                    <!-- Add New Comment Form -->
                                    <div class="border-top pt-2">
                                        <div class="form-group mb-2 mention-wrapper" style="position: relative;">
                                            <textarea id="new-comment-content" class="form-control" rows="2" placeholder="Enter your comment here... Type @ to mention a user" style="font-size: 0.8125rem;"></textarea>
                                        </div>
                                        <button type="button" id="add-comment-btn" class="btn btn-primary btn-sm font-weight-bold mt-2 mb-2">
                                            <i class="la la-comment"></i> Add Comment
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="row mt-5">
                        <div class="col">
                            <div class="card card-custom" id="customer-remarks">
                                <div class="card-header flex-wrap py-2" style="background-color:#E8F5E9;">
                                    <div class="card-title">
                                        <h4 class="card-label mb-0" style="color:#4CAF50; font-size: 1.1rem;">
                                            <strong>Customer Remarks</strong>
                                        </h4>
                                    </div>
                                </div>
                                <div class="card-body">
                                    <!-- Customer Remarks List -->
                                    <div id="customer-remarks-list" class="mb-2">
                                        <div class="text-center text-muted py-2" style="font-size: 0.8125rem;">
                                            <i class="la la-spinner la-spin"></i> Loading customer remarks...
                                        </div>
                                    </div>

                                    <!-- Add Admin Remark Form (for responding to customer) -->
                                    <div class="border-top pt-2">
                                        <div class="form-group mb-2">
                                            <textarea id="new-admin-remark-content" class="form-control" rows="2" placeholder="Add your response or remark here..." style="font-size: 0.8125rem;"></textarea>
                                        </div>
                                        <button type="button" id="add-admin-remark-btn" class="btn btn-primary btn-sm font-weight-bold mt-2 mb-2">
                                            <i class="la la-comment"></i> Add Remark
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="row mt-5">
                        <div class="col">
                            <div class="card card-custom">
                                <div class="card-header flex-wrap py-2" style="background-color:#D7E2F2;">
                                    <div class="card-title">
                                        <h4 class="card-label mb-0" style="color:#6082B6; font-size: 1.1rem;">
                                            <strong>Custom Uploads</strong>
                                        </h4>
                                    </div>
                                </div>
                                <div class="card-body">
                                    <div class="row">
                                        <div class="col-md-12">
                                            <form id="custom_upload_form" enctype="multipart/form-data" style="border-bottom:1px solid #D7E2F2; padding-bottom:15px;">
                                                <input type="hidden" id="upload_booking_id" value="<?php echo $BookingID; ?>">
                                                
                                                <div class="row">
                                                    <div class="col">
                                                        <div class="form-group">
                                                            <label>Document Name <span style="color:red;">*</span></label>
                                                            <input type="text" id="upload_name" class="form-control" placeholder="Enter document name" required>
                                                        </div>
                                                    </div>
                                                    <div class="col">
                                                        <div class="form-group">
                                                            <label>Select File <span style="color:red;">*</span></label>
                                                            <div class="custom-file">
                                                                <input type="file" class="custom-file-input" id="upload_file" accept=".pdf,.jpg,.jpeg,.png,.gif,.doc,.docx,.xls,.xlsx,.txt" required>
                                                                <label class="custom-file-label" for="upload_file">Choose file</label>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                                <div class="row">
                                                    <div class="col-auto">
                                                        <button type="submit" class="btn btn-primary btn-block btn-sm" id="upload_btn">
                                                            <i class="la la-upload"></i> Upload
                                                        </button>
                                                    </div>
                                                </div>
                                            </form>

                                             <div id="custom_uploads_list" class="mt-4">
                                                 <strong class="mb-3 d-block">Uploaded Documents</strong>
                                                 <div id="uploads_container">
                                                     <?php if (!empty($custom_uploads)): ?>
                                                         <?php foreach ($custom_uploads as $upload): ?>
                                                             <div class="upload-item mb-3 border rounded" data-upload-id="<?php echo $upload['id']; ?>" style="background: #fff; transition: all 0.2s;">
                                                                 <div class="p-3 d-flex justify-content-between align-items-end">
                                                                     <div class="flex-grow-1 min-w-0 mr-3">
                                                                         <div class="mb-1">
                                                                             <strong class="d-block text-truncate" style="font-size: 0.95rem; color: #212529;"><?php echo htmlspecialchars($upload['upload_name']); ?></strong>
                                                                         </div>
                                                                         <small class="text-muted d-block" style="font-size: 0.75rem; line-height: 1.4;">
                                                                             <i class="la la-user-circle"></i> <?php echo htmlspecialchars($upload['CreatedByName']); ?><br>
                                                                             <i class="la la-clock"></i> <?php echo return_timestamp_output($upload['created_at']); ?>
                                                                         </small>
                                                                     </div>
                                                                     <div class="d-flex align-items-center flex-shrink-0">
                                                                         <a href="<?php echo base_url($upload['upload_content']); ?>" target="_blank" class="btn btn-sm btn-light-primary mr-2" title="View Document" style="min-width: 50px; display: flex; align-items: center; justify-content: center;">
                                                                             <i class="la la-eye"></i>
                                                                         </a>
                                                                         <a href="<?php echo base_url($upload['upload_content']); ?>" download class="btn btn-sm btn-light-success mr-2" title="Download Document" style="min-width: 50px; display: flex; align-items: center; justify-content: center;">
                                                                             <i class="la la-download"></i>
                                                                         </a>
                                                                         <?php if ($upload['created_by'] == $this->session->userdata('admin_id')) { ?>
                                                                             <button type="button" class="btn btn-sm btn-light-danger delete-upload" data-upload-id="<?php echo $upload['id']; ?>" title="Delete Document" style="min-width: 50px; display: flex; align-items: center; justify-content: center;">
                                                                                 <i class="la la-trash"></i>
                                                                             </button>
                                                                         <?php } ?>
                                                                     </div>
                                                                 </div>
                                                             </div>
                                                         <?php endforeach; ?>
                                                     <?php else: ?>
                                                         <div class="text-center text-muted py-5">
                                                             <i class="la la-inbox" style="font-size: 3rem; opacity: 0.3;"></i>
                                                             <p class="mt-3 mb-0" style="font-size: 0.9rem;">No documents uploaded yet</p>
                                                         </div>
                                                     <?php endif; ?>
                                                 </div>
                                             </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php $dp_bd = isset($draft_payment_breakdown) ? $draft_payment_breakdown : null; ?>
                    <?php if (!empty($dp_bd) && ($dp_bd['total'] !== null || $dp_bd['draft_to_pb'] !== null || $dp_bd['pb_to_pbc'] !== null || $dp_bd['pbc_to_p'] !== null)): ?>
                    <div class="row mt-5">
                        <div class="col">
                            <div class="card card-custom">
                                <div class="card-header flex-wrap py-2" style="background-color:#D7E2F2;">
                                    <div class="card-title">
                                        <h4 class="card-label mb-0" style="color:#6082B6; font-size: 1.1rem;">
                                            <strong>Draft &rarr; Payment Response Time</strong>
                                        </h4>
                                    </div>
                                </div>
                                <div class="card-body">
                                    <div class="d-flex justify-content-between align-items-center mb-3 p-3" style="background:#EDF4FC; border:1px solid #BFD4EF; border-radius:8px;">
                                        <div style="font-weight:700; color:#1c3d5a;">Saved as draft &rarr; Pending Payment</div>
                                        <div style="font-size:18px; font-weight:700; color:#1c3d5a;"><?php echo htmlspecialchars(format_response_duration($dp_bd['total'])); ?></div>
                                    </div>
                                    <div style="font-size:13px; color:#4a5266; margin-bottom:8px;">Breakdown by stage</div>
                                    <table class="table table-sm mb-0">
                                        <tbody>
                                            <tr>
                                                <td style="color:#3a4256;">Draft &rarr; Pending BC</td>
                                                <td class="text-right font-weight-bold" style="color:#1c3d5a;"><?php echo htmlspecialchars(format_response_duration($dp_bd['draft_to_pb'])); ?></td>
                                            </tr>
                                            <tr>
                                                <td style="color:#3a4256;">Pending BC &rarr; Pending BC Confirmation</td>
                                                <td class="text-right font-weight-bold" style="color:#1c3d5a;"><?php echo htmlspecialchars(format_response_duration($dp_bd['pb_to_pbc'])); ?></td>
                                            </tr>
                                            <tr>
                                                <td style="color:#3a4256;">Pending BC Confirmation &rarr; Pending Payment</td>
                                                <td class="text-right font-weight-bold" style="color:#1c3d5a;"><?php echo htmlspecialchars(format_response_duration($dp_bd['pbc_to_p'])); ?></td>
                                            </tr>
                                        </tbody>
                                    </table>
                                    <small class="text-muted d-block mt-2" style="font-size:0.8125rem;">A dash (&mdash;) means the booking skipped that stage or hasn&rsquo;t reached it yet.</small>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                    <?php if (!empty($is_slow_conversion)):
                        $scr_selected = isset($slow_conversion_reason_selected) && is_array($slow_conversion_reason_selected) ? $slow_conversion_reason_selected : array();
                    ?>
                    <div class="row mt-5">
                        <div class="col">
                            <div class="card card-custom">
                                <div class="card-header flex-wrap py-2" style="background-color:#FBE6D4;">
                                    <div class="card-title">
                                        <h4 class="card-label mb-0" style="color:#C0631B; font-size: 1.1rem;">
                                            <strong><i class="la la-exclamation-triangle"></i> Slow Conversion Reasons</strong>
                                        </h4>
                                    </div>
                                </div>
                                <div class="card-body">
                                    <div class="alert alert-light-warning d-flex align-items-center mb-4" style="border:1px solid #F0C99B; background:#FDF3E9; color:#8A4B12; font-size:0.9rem;">
                                        <i class="la la-clock mr-2" style="font-size:1.2rem;"></i>
                                        This booking took over 24 hours from draft to payment. Tag the reason(s) why.
                                    </div>
                                    <div class="form-group">
                                        <label>Reason(s) <span class="text-muted" style="font-size:0.8rem;">(select all that apply)</span></label>
                                        <select id="slow_conversion_reasons_select" class="form-control selectpicker" multiple data-actions-box="true" data-live-search="true" title="-- SELECT REASON(S) --">
                                            <?php foreach ($slow_conversion_reasons as $reason): ?>
                                                <option value="<?php echo (int) $reason->SlowConversionReasonID; ?>" <?php echo in_array((int) $reason->SlowConversionReasonID, $scr_selected) ? 'selected' : ''; ?>>
                                                    <?php echo htmlspecialchars($reason->Name); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <?php if (empty($slow_conversion_reasons)): ?>
                                            <small class="text-muted d-block mt-2">No reasons configured yet. Add them under <a href="<?php echo base_url('Slow_Conversion_Reason'); ?>" target="_blank">Settings &rarr; Slow Conversion Reason</a>.</small>
                                        <?php endif; ?>
                                    </div>
                                    <button type="button" id="save_slow_conversion_reasons_btn" class="btn btn-primary font-weight-bold btn-sm" <?php echo empty($slow_conversion_reasons) ? 'disabled' : ''; ?>>
                                        <i class="la la-save"></i> Save Reasons
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
                <div class="col-lg-6 col-md-12  mt-md-6 mt-lg-0">
                    <div class="row">
                        <div class="col">
                            <div class="card card-custom">
                                <div class="card-header flex-wrap py-2" style="background-color:#D7E2F2;">
                                    <div class="card-title">
                                        <h4 class="card-label mb-0" style="color:#6082B6; font-size: 1.1rem;">
                                            <strong>Customer Review</strong>
                                        </h4>
                                    </div>
                                </div>
                                <div class="card-body">
                                    <div class="row">
                                        <div class="col-md-12">
                                            <div class="form-group">
                                                <label>Customer Review</label>
                                                <textarea id="CustomerReview" class="form-control auto-resize-textarea" disabled style="overflow: scroll;"><?php echo $CustomerReview; ?></textarea>
                                                <small class="font-italic" style="display: <?php echo isset($CustomerReviewTimestamp) && !empty($CustomerReviewTimestamp) ? 'block' : 'none'; ?>;"> Review provided on <?php echo date('d/m/Y h:i:s A', strtotime($CustomerReviewTimestamp)); ?></small>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="row">
                                        <div class="col">
                                            <?php print_allow_review($AllowReview); ?>
                                        </div>
                                    </div>

                                    <button type="button" id="update_allow_review_btn" class="btn btn-primary font-weight-bold btn-sm">
                                        <i class="la la-save"></i> Update
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col">
                            <!-- Booking Checklist Section -->
                            <?php if(isset($booking_checklists) && !empty($booking_checklists['groups'])) {
                                $is_multi = $booking_checklists['is_multi_product'];
                                $groups = $booking_checklists['groups'];
                                $total_count = $booking_checklists['total_count'];
                                $completion_map = isset($completion_map) ? $completion_map : array();
                                $checked_count = 0;
                            ?>
                                <div class="row mt-5">
                                    <div class="col-12">
                                        <div class="card card-custom">
                                            <div class="card-header flex-wrap py-2" style="background-color:#D7E2F2;">
                                                <div class="card-title">
                                                    <h4 class="card-label mb-0" style="color:#6082B6; font-size: 1.1rem;">
                                                        <strong>Booking Checklist</strong>
                                                    </h4>
                                                </div>
                                            </div>
                                            <div class="card-body">
                                                <?php foreach($groups as $group_index => $group) { ?>
                                                    <?php if($is_multi && $group_index > 0) { ?>
                                                        <div style="margin: 1rem 0;"></div>
                                                    <?php } ?>
                                                    <h6 class="font-weight-bold mb-3" style="color:#6082B6;">
                                                        <?php echo htmlspecialchars($group['product_name']); ?>
                                                    </h6>
                                                    <?php if(!empty($group['PaymentOutSupplierDeposit']) || !empty($group['PaymentOutSupplierFull'])) { ?>
                                                        <div class="mb-3" style="margin-top:-0.5rem;">
                                                            <?php if(!empty($group['PaymentOutSupplierDeposit'])) { ?>
                                                                <span class="label label-inline label-light-warning font-weight-bold mr-2">
                                                                    <i class="la la-calendar-check-o mr-1" style="font-size:14px;"></i>Supplier Deposit: <?php echo $group['PaymentOutSupplierDeposit']; ?>
                                                                </span>
                                                            <?php } ?>
                                                            <?php if(!empty($group['PaymentOutSupplierFull'])) { ?>
                                                                <span class="label label-inline label-light-primary font-weight-bold">
                                                                    <i class="la la-calendar-check-o mr-1" style="font-size:14px;"></i>Supplier Full: <?php echo $group['PaymentOutSupplierFull']; ?>
                                                                </span>
                                                            <?php } ?>
                                                        </div>
                                                    <?php } ?>
                                                    <div class="checklist-container">
                                                        <?php foreach($group['checklists'] as $checklist) {
                                                            $is_checked = isset($completion_map[$group['product_id']][$checklist->ID]);
                                                            $completion_info = $is_checked ? $completion_map[$group['product_id']][$checklist->ID] : null;
                                                            if($is_checked) {
                                                                $checked_count++;
                                                            }
                                                        ?>
                                                            <div class="checklist-item <?php echo $is_checked ? 'checked' : ''; ?>" data-checklist-id="<?php echo $checklist->ID; ?>">
                                                                <div class="checklist-item-content">
                                                                    <div class="checklist-checkbox-wrapper">
                                                                        <input class="form-check-input checklist-checkbox" type="checkbox"
                                                                            id="checklist_<?php echo $group['product_id']; ?>_<?php echo $checklist->ID; ?>"
                                                                            value="<?php echo $group['product_id'] . '_' . $checklist->ID; ?>"
                                                                            <?php echo $is_checked ? 'checked' : ''; ?>
                                                                            <?php echo empty($can_modify_checklist) ? 'disabled' : ''; ?>>
                                                                        <label class="checklist-checkbox-label" for="checklist_<?php echo $group['product_id']; ?>_<?php echo $checklist->ID; ?>"></label>
                                                                    </div>
                                                                    <div class="checklist-details">
                                                                        <div class="checklist-name-wrapper">
                                                                            <label class="checklist-name" for="checklist_<?php echo $group['product_id']; ?>_<?php echo $checklist->ID; ?>">
                                                                                <?php echo htmlspecialchars($checklist->name); ?>
                                                                            </label>
                                                                        </div>
                                                                        <?php if($is_checked && $completion_info) { ?>
                                                                            <div class="checklist-completion-info">
                                                                                <i class="la la-user-circle text-primary"></i>
                                                                                <span class="completion-text">
                                                                                    Completed by <strong><?php echo htmlspecialchars($completion_info['created_by_name']); ?></strong>
                                                                                    <span class="completion-separator">•</span>
                                                                                    <span class="completion-date"><?php echo return_timestamp_output($completion_info['created_at']); ?></span>
                                                                                </span>
                                                                            </div>
                                                                        <?php } ?>
                                                                    </div>
                                                                </div>
                                                            </div>
                                                        <?php } ?>
                                                    </div>
                                                <?php } ?>

                                                <div class="checklist-footer">
                                                    <div class="checklist-progress">
                                                        <div class="progress-info">
                                                            <span class="progress-text">
                                                                <strong><?php echo $checked_count; ?></strong> of <strong><?php echo $total_count; ?></strong> items completed
                                                            </span>
                                                            <span class="progress-percentage"><?php echo $total_count > 0 ? round(($checked_count / $total_count) * 100) : 0; ?>%</span>
                                                        </div>
                                                        <div class="progress-bar-wrapper">
                                                            <div class="progress" style="height: 8px; background-color: #e9ecef; border-radius: 4px;">
                                                                <div class="progress-bar bg-success" role="progressbar"
                                                                     style="width: <?php echo $total_count > 0 ? ($checked_count / $total_count) * 100 : 0; ?>%;"
                                                                     aria-valuenow="<?php echo $checked_count; ?>"
                                                                     aria-valuemin="0"
                                                                     aria-valuemax="<?php echo $total_count; ?>">
                                                                </div>
                                                            </div>
                                                        </div>
                                                    </div>
                                                    <?php if(!empty($can_modify_checklist)) { ?>
                                                    <button type="button" id="update_checklist_btn" class="btn btn-primary btn-sm font-weight-bold">
                                                        <i class="la la-save"></i> Save Changes
                                                    </button>
                                                    <?php } else { ?>
                                                    <div class="text-muted small mt-2">
                                                        <i class="la la-lock"></i> Only this booking's TC1, OP, and their Team Lead can update the checklist.
                                                    </div>
                                                    <?php } ?>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            <?php } ?>

                            <!-- E-Invoice Request by Pax (Admin editable when submitted) -->
                            <?php if(!empty($invoice_split)) {
                                $einvoice_can_admin_edit = !empty($this->session->userdata('admin_id'));
                                $einvoice_is_submitted   = !empty($einvoice_submit_status) && $einvoice_submit_status === 'S';
                            ?>
                                <div class="row mt-5">
                                    <div class="col-12">
                                        <div class="card card-custom" id="invoice-split-section">
                                            <div class="card-header flex-wrap py-2" style="background-color:#D7E2F2;">
                                                <div class="card-title d-flex justify-content-between align-items-center" style="width:100%;">
                                                    <h4 class="card-label mb-0" style="color:#6082B6; font-size: 1.1rem;">
                                                        <strong>E-Invoice Request by Pax</strong>
                                                        <?php if($einvoice_is_submitted) { ?>
                                                            <span class="ml-2" style="display:inline-block; font-size: 11px; font-weight: 700; background: #28a745; color: #fff; padding: 3px 10px; border-radius: 12px; letter-spacing: 0.3px;">SUBMITTED</span>
                                                        <?php } elseif(!empty($einvoice_submit_status) && $einvoice_submit_status === 'D') { ?>
                                                            <span class="ml-2" style="display:inline-block; font-size: 11px; font-weight: 700; background: #6c757d; color: #fff; padding: 3px 10px; border-radius: 12px; letter-spacing: 0.3px;">DRAFT</span>
                                                        <?php } ?>
                                                    </h4>
                                                    <?php if($einvoice_can_admin_edit && $einvoice_is_submitted) { ?>
                                                        <button type="button" id="toggle-admin-split-form" class="btn btn-primary btn-sm font-weight-bold" title="Edit submitted e-invoice details">
                                                            <i class="la la-edit"></i> Edit E-Invoice
                                                        </button>
                                                    <?php } ?>
                                                </div>
                                            </div>
                                            <div class="card-body">
                                                <?php if($einvoice_is_submitted || !empty($einvoice_last_edited_date)) { ?>
                                                    <div class="mb-3 p-2 rounded" style="background:#f0f7ff; border:1px solid #cce5ff; font-size:0.82rem; color:#004085;">
                                                        <?php if(!empty($einvoice_submitted_date)) { ?>
                                                            <span><i class="la la-paper-plane"></i> Submitted on <?php echo htmlspecialchars(date('Y-m-d H:i', strtotime($einvoice_submitted_date))); ?></span>
                                                        <?php } ?>
                                                        <?php if(!empty($einvoice_last_edited_date)) { ?>
                                                            <span class="ml-3"><i class="la la-edit"></i> Last edited by
                                                                <strong><?php echo htmlspecialchars($einvoice_last_editor_name ?: ('Admin #' . $einvoice_last_edited_by)); ?></strong>
                                                                on <?php echo htmlspecialchars(date('Y-m-d H:i', strtotime($einvoice_last_edited_date))); ?>
                                                            </span>
                                                        <?php } ?>
                                                    </div>
                                                <?php } ?>

                                                <!-- Read-only display -->
                                                <div id="admin-split-display-readonly">
                                                    <?php
                                                    $grand_subtotal = 0;
                                                    $grand_discount = 0;
                                                    $grand_net = 0;
                                                    foreach($invoice_split as $idx => $pax) {
                                                        $grand_subtotal += $pax['SubtotalAmount'];
                                                        $grand_discount += $pax['DiscountAmount'];
                                                        $grand_net += $pax['NetAmount'];
                                                    ?>
                                                    <div style="background: #f8f9fa; border-radius: 6px; padding: 12px; margin-bottom: 10px; border: 1px solid #e8e8e8;">
                                                        <div class="d-flex justify-content-between align-items-center mb-1">
                                                            <strong>Pax <?php echo $idx + 1; ?>: <?php echo htmlspecialchars($pax['PaxName']); ?></strong>
                                                            <?php if(!empty($pax['TIN'])) { ?>
                                                                <span class="text-muted" style="font-size: 0.85rem;">TIN: <?php echo htmlspecialchars($pax['TIN']); ?></span>
                                                            <?php } ?>
                                                        </div>
                                                        <div class="text-muted mb-2" style="font-size: 0.82rem;">
                                                            <?php if(!empty($pax['Email'])) { ?>
                                                                <span class="mr-3">Email: <?php echo htmlspecialchars($pax['Email']); ?></span>
                                                            <?php } ?>
                                                            <?php if(!empty($pax['PhoneNumber'])) { ?>
                                                                <span>Phone: <?php echo htmlspecialchars($pax['PhoneNumber']); ?></span>
                                                            <?php } ?>
                                                            <?php if(!empty($pax['Address'])) { ?>
                                                                <div>Address: <?php echo htmlspecialchars($pax['Address']); ?></div>
                                                            <?php } ?>
                                                        </div>
                                                        <table class="table table-sm table-bordered mb-2" style="font-size: 0.85rem;">
                                                            <thead style="background: #e9ecef;">
                                                                <tr>
                                                                    <th>Product</th>
                                                                    <th class="text-center" style="width:80px;">Qty</th>
                                                                    <th class="text-right" style="width:120px;">Unit Price</th>
                                                                    <th class="text-right" style="width:120px;">Amount</th>
                                                                </tr>
                                                            </thead>
                                                            <tbody>
                                                                <?php foreach($pax['products'] as $prod) {
                                                                    $line_discount = isset($prod['DiscountAmount']) ? floatval($prod['DiscountAmount']) : 0;
                                                                    $line_net = floatval($prod['Amount']) - $line_discount;
                                                                ?>
                                                                    <tr<?php echo $line_discount > 0 ? ' style="background:#fff8e1;"' : ''; ?>>
                                                                        <td>
                                                                            <?php echo htmlspecialchars($prod['ProductName']); ?>
                                                                            <?php if($line_discount > 0) { ?>
                                                                                <span class="label label-warning ml-2" style="font-size:0.7rem; background:#f0ad4e; color:#fff; padding:2px 6px; border-radius:3px;">BC Discount Applied</span>
                                                                            <?php } ?>
                                                                        </td>
                                                                        <td class="text-center"><?php echo rtrim(rtrim(number_format($prod['Quantity'], 2), '0'), '.'); ?></td>
                                                                        <td class="text-right">RM <?php echo number_format($prod['UnitPrice'], 2); ?></td>
                                                                        <td class="text-right">
                                                                            RM <?php echo number_format($prod['Amount'], 2); ?>
                                                                            <?php if($line_discount > 0) { ?>
                                                                                <div class="text-danger" style="font-size:0.78rem;">- RM <?php echo number_format($line_discount, 2); ?> discount</div>
                                                                                <div style="font-size:0.78rem;">Net: RM <?php echo number_format($line_net, 2); ?></div>
                                                                            <?php } ?>
                                                                        </td>
                                                                    </tr>
                                                                <?php } ?>
                                                            </tbody>
                                                        </table>
                                                        <div class="text-right" style="font-size: 0.85rem;">
                                                            <span>Subtotal: <strong>RM <?php echo number_format($pax['SubtotalAmount'], 2); ?></strong></span>
                                                            <?php if(floatval($pax['DiscountAmount']) > 0) { ?>
                                                                <span class="ml-3 text-danger">Discount: <strong>- RM <?php echo number_format($pax['DiscountAmount'], 2); ?></strong></span>
                                                            <?php } ?>
                                                            <span class="ml-3" style="color:#6082B6;">Net: <strong>RM <?php echo number_format($pax['NetAmount'], 2); ?></strong></span>
                                                        </div>
                                                    </div>
                                                    <?php } ?>
                                                    <div class="text-right mt-3 p-3" style="background: #D7E2F2; border-radius: 6px; font-size: 0.9rem;">
                                                        <span>Grand Subtotal: <strong>RM <?php echo number_format($grand_subtotal, 2); ?></strong></span>
                                                        <?php if($grand_discount > 0) { ?>
                                                            <span class="ml-3">Discount: <strong>- RM <?php echo number_format($grand_discount, 2); ?></strong></span>
                                                        <?php } ?>
                                                        <span class="ml-3"><strong>Net Total: RM <?php echo number_format($grand_net, 2); ?></strong></span>
                                                    </div>
                                                </div>

                                                <?php if($einvoice_can_admin_edit && $einvoice_is_submitted) {
                                                    // Pax count cap mirrors the customer portal: counted from the
                                                    // rooms/guest list first (Compute_Pax_Counts), then falling back
                                                    // to the invoice_split row count. Computed in the controller so
                                                    // NULL booking header pax no longer pins the cap at 0/1.
                                                    $admin_max_pax = isset($einvoice_admin_max_pax) ? (int)$einvoice_admin_max_pax : count($invoice_split);
                                                    // Normalize booking_products into the same shape the JS expects.
                                                    $admin_bp_for_js = [];
                                                    if (!empty($booking_products)) {
                                                        foreach ($booking_products as $bp) {
                                                            if (is_object($bp) && !empty($bp->BookingProductID)) {
                                                                $price = is_string($bp->Price) ? floatval(str_replace(',', '', $bp->Price)) : floatval($bp->Price);
                                                                $admin_bp_for_js[] = [
                                                                    'BookingProductID' => $bp->BookingProductID,
                                                                    'Name'             => $bp->Name,
                                                                    'Price'            => $price,
                                                                    'Quantity'         => $bp->Quantity,
                                                                ];
                                                            }
                                                        }
                                                    }
                                                ?>
                                                <?php
                                                    // $Subtotal / $Discount arrive as comma-formatted strings (see
                                                    // Booking.php:1622), so strip commas before floatval.
                                                    $admin_subtotal_num = floatval(str_replace(',', '', (string)(isset($Subtotal) ? $Subtotal : 0)));
                                                    $admin_discount_num = floatval(str_replace(',', '', (string)(isset($Discount) ? $Discount : 0)));
                                                ?>
                                                <!-- Admin edit form (hidden by default) -->
                                                <div id="admin-split-form-container" style="display:none; margin-top:16px;">
                                                    <div id="admin-split-summary-bar" style="background:#f8f9fa; border-radius:8px; padding:10px 14px; margin-bottom:14px; border:2px solid #e0e0e0; font-size:13px;">
                                                        <div class="d-flex justify-content-between flex-wrap" style="gap:10px;">
                                                            <div><strong>Total Allocated:</strong> <span id="admin-split-total-allocated">RM 0.00</span></div>
                                                            <div><strong>Booking Subtotal:</strong> RM <?php echo number_format($admin_subtotal_num, 2); ?></div>
                                                            <div><strong>Remaining:</strong> <span id="admin-split-remaining">RM <?php echo number_format($admin_subtotal_num, 2); ?></span></div>
                                                        </div>
                                                    </div>
                                                    <div id="admin-pax-cards-container"></div>
                                                    <div style="margin-top:12px; display:flex; gap:10px; flex-wrap:wrap;">
                                                        <button type="button" id="admin-add-pax-btn" class="btn btn-success btn-sm font-weight-bold">
                                                            <i class="la la-plus"></i> Add Pax
                                                        </button>
                                                        <button type="button" id="admin-save-split-btn" class="btn btn-primary btn-sm font-weight-bold">
                                                            <i class="la la-save"></i> Save Changes
                                                        </button>
                                                        <button type="button" id="admin-cancel-split-btn" class="btn btn-secondary btn-sm font-weight-bold">
                                                            Cancel
                                                        </button>
                                                    </div>
                                                </div>

                                                <script>
                                                (function() {
                                                    var adminEinvCfg = {
                                                        save_url:         '<?php echo site_url('Booking/save_invoice_split_admin/' . $BookingID); ?>',
                                                        booking_products: <?php echo json_encode($admin_bp_for_js); ?>,
                                                        booking_subtotal: <?php echo json_encode($admin_subtotal_num); ?>,
                                                        booking_discount: <?php echo json_encode($admin_discount_num); ?>,
                                                        existing_split:   <?php echo json_encode($invoice_split); ?>,
                                                        max_pax:          <?php echo (int)$admin_max_pax; ?>
                                                    };

                                                    jQuery(function($) {
                                                        var bookingProducts = adminEinvCfg.booking_products || [];
                                                        var bookingSubtotal = parseFloat(adminEinvCfg.booking_subtotal) || 0;
                                                        var bookingDiscount = parseFloat(adminEinvCfg.booking_discount) || 0;
                                                        var existingSplit   = adminEinvCfg.existing_split || [];
                                                        var maxPax          = parseInt(adminEinvCfg.max_pax, 10) || 0;
                                                        var paxCounter      = 0;

                                                        function formatCurrency(v) {
                                                            return 'RM ' + parseFloat(v).toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
                                                        }

                                                        function getMaxQtyForBp(bpId) {
                                                            for (var i = 0; i < bookingProducts.length; i++) {
                                                                if (bookingProducts[i].BookingProductID == bpId) {
                                                                    return parseFloat(bookingProducts[i].Quantity) || 0;
                                                                }
                                                            }
                                                            return 0;
                                                        }

                                                        function getProductOptions(selectedBpId) {
                                                            var html = '<option value="">-- Select Product --</option>';
                                                            for (var i = 0; i < bookingProducts.length; i++) {
                                                                var bp = bookingProducts[i];
                                                                var sel = (bp.BookingProductID == selectedBpId) ? ' selected' : '';
                                                                html += '<option value="' + bp.BookingProductID + '"' + sel + ' data-price="' + bp.Price + '" data-max-qty="' + bp.Quantity + '">' + bp.Name + ' (Qty: ' + bp.Quantity + ' × RM ' + parseFloat(bp.Price).toFixed(2) + ')</option>';
                                                            }
                                                            return html;
                                                        }

                                                        // Remaining capacity for a row, accounting for other rows
                                                        // allocating the same BookingProductID across pax.
                                                        function computeRowRemaining($row) {
                                                            var bpId = parseInt($row.find('.adm-split-product-select').val(), 10) || 0;
                                                            if (bpId <= 0) return null;
                                                            var bookingQty = getMaxQtyForBp(bpId);
                                                            var otherSum = 0;
                                                            $('#admin-pax-cards-container .adm-product-row').each(function() {
                                                                if (this === $row[0]) return;
                                                                var otherBp = parseInt($(this).find('.adm-split-product-select').val(), 10) || 0;
                                                                if (otherBp === bpId) {
                                                                    otherSum += parseFloat($(this).find('.adm-split-qty').val()) || 0;
                                                                }
                                                            });
                                                            var rem = Math.round((bookingQty - otherSum) * 100) / 100;
                                                            return Math.max(0, rem);
                                                        }

                                                        function applyRowCap($row) {
                                                            var rem = computeRowRemaining($row);
                                                            var $qty = $row.find('.adm-split-qty');
                                                            if (rem === null) { $qty.removeAttr('max'); return; }
                                                            $qty.attr('max', rem);
                                                            var v = parseFloat($qty.val());
                                                            if (!isNaN(v) && v > rem) $qty.val(rem);
                                                        }

                                                        function addProductRow(paxIdx, product) {
                                                            var bpId = product ? product.BookingProductID : '';
                                                            var qty = product ? product.Quantity : '';
                                                            var price = product ? parseFloat(product.UnitPrice).toFixed(2) : '0.00';
                                                            var amount = product ? parseFloat(product.Amount).toFixed(2) : '0.00';
                                                            var maxAttr = '';
                                                            if (bpId) {
                                                                var mq = getMaxQtyForBp(bpId);
                                                                if (mq > 0) maxAttr = ' max="' + mq + '"';
                                                            }
                                                            var html = '<tr class="adm-product-row" data-pax="' + paxIdx + '">';
                                                            html += '<td><select class="adm-split-product-select form-control form-control-sm">' + getProductOptions(bpId) + '</select></td>';
                                                            html += '<td><input type="number" class="adm-split-qty form-control form-control-sm" value="' + qty + '" min="0.1" step="0.1"' + maxAttr + ' style="width:90px;"></td>';
                                                            html += '<td class="adm-split-price text-right">RM ' + price + '</td>';
                                                            html += '<td class="adm-split-amount-cell text-right"><div class="adm-split-amount">RM ' + amount + '</div></td>';
                                                            html += '<td><button type="button" class="adm-remove-product-row btn btn-danger btn-xs" title="Remove product"><i class="la la-trash"></i></button></td>';
                                                            html += '</tr>';
                                                            return html;
                                                        }

                                                        function updateAddPaxBtnState() {
                                                            var n = $('#admin-pax-cards-container .adm-pax-card').length;
                                                            var $b = $('#admin-add-pax-btn');
                                                            if (n >= maxPax) {
                                                                $b.prop('disabled', true).css({opacity:0.5, cursor:'not-allowed'}).attr('title', 'Maximum ' + maxPax + ' pax reached for this booking');
                                                            } else {
                                                                $b.prop('disabled', false).css({opacity:'', cursor:'pointer'}).removeAttr('title');
                                                            }
                                                        }

                                                        function addPaxCard(paxData) {
                                                            paxCounter++;
                                                            var idx = paxCounter;
                                                            var name = paxData ? (paxData.PaxName || '') : '';
                                                            var tin = paxData ? (paxData.TIN || '') : '';
                                                            var email = paxData ? (paxData.Email || '') : '';
                                                            var address = paxData ? (paxData.Address || '') : '';
                                                            var phone = paxData ? (paxData.PhoneNumber || '') : '';
                                                            var sameAsBooker = paxData ? (parseInt(paxData.SameAsBooker, 10) === 1) : false;
                                                            var esc = function(s) { return String(s).replace(/"/g, '&quot;'); };
                                                            var escTa = function(s) { return String(s).replace(/</g, '&lt;').replace(/>/g, '&gt;'); };

                                                            var html = '<div class="adm-pax-card" data-pax-idx="' + idx + '" style="background:#f8f9fa;border:1px solid #e0e0e0;border-radius:8px;padding:14px;margin-bottom:12px;">';
                                                            html += '<div class="d-flex justify-content-between align-items-center mb-2">';
                                                            html += '<strong>Pax #' + idx + '</strong>';
                                                            html += '<button type="button" class="adm-remove-pax-btn btn btn-danger btn-xs" title="Remove pax"><i class="la la-trash"></i> Remove</button>';
                                                            html += '</div>';
                                                            html += '<div class="form-row">';
                                                            html += '<div class="form-group col-md-6"><label class="font-weight-bold" style="font-size:12px;">Pax Name <span class="text-danger">*</span></label><input type="text" class="adm-pax-name form-control form-control-sm" value="' + esc(name) + '" placeholder="Full Name"></div>';
                                                            html += '<div class="form-group col-md-6"><label class="font-weight-bold" style="font-size:12px;">TIN (Tax ID) <span class="text-danger">*</span></label><input type="text" class="adm-pax-tin form-control form-control-sm" value="' + esc(tin) + '" placeholder="Tax Identification Number"></div>';
                                                            html += '</div>';
                                                            html += '<div class="form-row">';
                                                            html += '<div class="form-group col-md-6"><label class="font-weight-bold" style="font-size:12px;">Email <span class="text-danger">*</span></label><input type="email" class="adm-pax-email form-control form-control-sm" value="' + esc(email) + '" placeholder="Email Address"></div>';
                                                            html += '<div class="form-group col-md-6"><label class="font-weight-bold" style="font-size:12px;">Phone Number <span class="text-danger">*</span></label><input type="text" class="adm-pax-phone form-control form-control-sm" value="' + esc(phone) + '" placeholder="Phone Number"></div>';
                                                            html += '</div>';
                                                            html += '<div class="form-group mb-2"><label class="mb-0" style="font-size:12px;cursor:pointer;"><input type="checkbox" class="adm-pax-same-as-booker" ' + (sameAsBooker ? 'checked' : '') + '> <span class="font-weight-bold">Name is same as the booker</span> <span class="text-muted">(match by phone only — no separate customer)</span></label></div>';
                                                            html += '<div class="form-group"><label class="font-weight-bold" style="font-size:12px;">Address <span class="text-danger">*</span></label><textarea class="adm-pax-address form-control form-control-sm" placeholder="Full Address" style="min-height:60px;">' + escTa(address) + '</textarea></div>';
                                                            html += '<table class="table table-sm table-bordered mb-1" style="font-size:13px;">';
                                                            html += '<thead style="background:#e9ecef;"><tr><th>Product</th><th class="text-center" style="width:100px;">Qty</th><th class="text-right" style="width:110px;">Unit Price</th><th class="text-right" style="width:120px;">Amount</th><th style="width:40px;"></th></tr></thead>';
                                                            html += '<tbody class="adm-pax-products-body">';
                                                            if (paxData && paxData.products && paxData.products.length) {
                                                                for (var i = 0; i < paxData.products.length; i++) html += addProductRow(idx, paxData.products[i]);
                                                            } else {
                                                                html += addProductRow(idx, null);
                                                            }
                                                            html += '</tbody></table>';
                                                            html += '<div class="d-flex justify-content-between align-items-center">';
                                                            html += '<div><button type="button" class="adm-add-product-btn btn btn-info btn-xs font-weight-bold" data-pax="' + idx + '" title="Add product to this pax"><i class="la la-plus"></i> Add Product</button>';
                                                            html += '<button type="button" class="adm-add-all-products-btn btn btn-success btn-xs font-weight-bold ml-2" data-pax="' + idx + '" title="Add all booking products"><i class="la la-plus-square"></i> Add All</button></div>';
                                                            html += '<div class="adm-pax-subtotal font-weight-bold">Subtotal: RM 0.00</div>';
                                                            html += '</div>';
                                                            html += '</div>';
                                                            $('#admin-pax-cards-container').append(html);
                                                            recalculate();
                                                            updateAddPaxBtnState();
                                                        }

                                                        function recalculate() {
                                                            var totalAllocated = 0;
                                                            var aggregate = {};
                                                            var rowsInOrder = [];
                                                            $('#admin-pax-cards-container .adm-pax-card').each(function() {
                                                                var paxSubtotal = 0;
                                                                $(this).find('.adm-product-row').each(function() {
                                                                    var $row = $(this);
                                                                    var $select = $row.find('.adm-split-product-select');
                                                                    var $qty = $row.find('.adm-split-qty');
                                                                    var bpId = parseInt($select.val(), 10) || 0;
                                                                    var price = parseFloat($select.find('option:selected').data('price')) || 0;
                                                                    var qty = parseFloat($qty.val()) || 0;
                                                                    var amount = Math.round(price * qty * 100) / 100;
                                                                    $row.find('.adm-split-price').text('RM ' + price.toFixed(2));
                                                                    $row.find('.adm-split-amount').text('RM ' + amount.toFixed(2));
                                                                    $row.css('background', '');
                                                                    $row.next('.adm-split-discount-row').remove();
                                                                    if (bpId > 0) {
                                                                        aggregate[bpId] = Math.round(((aggregate[bpId] || 0) + amount) * 100) / 100;
                                                                        rowsInOrder.push({ bpId: bpId, amount: amount, $row: $row });
                                                                    }
                                                                    paxSubtotal += amount;
                                                                });
                                                                $(this).find('.adm-pax-subtotal').text('Subtotal: ' + formatCurrency(paxSubtotal));
                                                                totalAllocated += paxSubtotal;
                                                            });
                                                            // Highlight the first product line whose amount can absorb
                                                            // the full booking discount (same rule as the backend).
                                                            if (bookingDiscount > 0) {
                                                                var targetBp = null;
                                                                Object.keys(aggregate).forEach(function(k) {
                                                                    var bp = parseInt(k, 10);
                                                                    if (aggregate[k] >= bookingDiscount) {
                                                                        if (targetBp === null || bp < targetBp) targetBp = bp;
                                                                    }
                                                                });
                                                                if (targetBp !== null) {
                                                                    for (var i = 0; i < rowsInOrder.length; i++) {
                                                                        if (rowsInOrder[i].bpId === targetBp) {
                                                                            var amt = rowsInOrder[i].amount;
                                                                            var net = Math.round((amt - bookingDiscount) * 100) / 100;
                                                                            rowsInOrder[i].$row.css('background', '#fff8e1');
                                                                            rowsInOrder[i].$row.after(
                                                                                '<tr class="adm-split-discount-row" style="background:#fff8e1;">' +
                                                                                    '<td colspan="5" style="padding:4px 8px 8px;">' +
                                                                                        '<span style="display:inline-block;font-size:10px;background:#f0ad4e;color:#fff;padding:2px 6px;border-radius:3px;margin-right:8px;">BC Discount Applied</span>' +
                                                                                        '<span style="color:#dc3545;font-size:11px;margin-right:8px;">- ' + formatCurrency(bookingDiscount) + ' discount</span>' +
                                                                                        '<span style="font-size:11px;">Net: ' + formatCurrency(net) + '</span>' +
                                                                                    '</td>' +
                                                                                '</tr>'
                                                                            );
                                                                            break;
                                                                        }
                                                                    }
                                                                }
                                                            }
                                                            var remaining = bookingSubtotal - totalAllocated;
                                                            $('#admin-split-total-allocated').text(formatCurrency(totalAllocated));
                                                            $('#admin-split-remaining').text(formatCurrency(remaining));
                                                            var $bar = $('#admin-split-summary-bar');
                                                            if (Math.abs(remaining) < 0.02) {
                                                                $bar.css({borderColor:'#28a745', background:'#d4edda'});
                                                            } else {
                                                                $bar.css({borderColor:'#dc3545', background:'#f8d7da'});
                                                            }
                                                            $('#admin-pax-cards-container .adm-product-row').each(function() {
                                                                var $row = $(this);
                                                                var rem = computeRowRemaining($row);
                                                                var $qty = $row.find('.adm-split-qty');
                                                                if (rem === null) $qty.removeAttr('max');
                                                                else $qty.attr('max', rem);
                                                            });
                                                        }

                                                        // Scope events to the admin form only — using class selectors
                                                        // prefixed with `adm-` avoids any clash if the customer-portal
                                                        // markup ever shares a page (it doesn't today, but defends
                                                        // against future regressions).
                                                        $(document).on('change', '#admin-pax-cards-container .adm-split-product-select', function() { applyRowCap($(this).closest('.adm-product-row')); recalculate(); });
                                                        $(document).on('input change', '#admin-pax-cards-container .adm-split-qty', function() { applyRowCap($(this).closest('.adm-product-row')); recalculate(); });
                                                        $(document).on('click', '#admin-pax-cards-container .adm-add-product-btn', function() {
                                                            var idx = $(this).data('pax');
                                                            $(this).closest('.adm-pax-card').find('.adm-pax-products-body').append(addProductRow(idx, null));
                                                            recalculate();
                                                        });
                                                        $(document).on('click', '#admin-pax-cards-container .adm-add-all-products-btn', function() {
                                                            var idx = $(this).data('pax');
                                                            var $body = $(this).closest('.adm-pax-card').find('.adm-pax-products-body');
                                                            $body.empty();
                                                            for (var i = 0; i < bookingProducts.length; i++) {
                                                                var bp = bookingProducts[i];
                                                                $body.append(addProductRow(idx, {
                                                                    BookingProductID: bp.BookingProductID,
                                                                    Quantity: bp.Quantity,
                                                                    UnitPrice: bp.Price,
                                                                    Amount: (parseFloat(bp.Price) * parseFloat(bp.Quantity)).toFixed(2)
                                                                }));
                                                            }
                                                            recalculate();
                                                        });
                                                        $(document).on('click', '#admin-pax-cards-container .adm-remove-product-row', function() {
                                                            $(this).closest('.adm-product-row').remove();
                                                            recalculate();
                                                        });
                                                        $(document).on('click', '#admin-pax-cards-container .adm-remove-pax-btn', function() {
                                                            $(this).closest('.adm-pax-card').remove();
                                                            recalculate();
                                                            updateAddPaxBtnState();
                                                        });

                                                        $('#toggle-admin-split-form').on('click', function() {
                                                            $('#admin-split-display-readonly').hide();
                                                            $('#admin-split-form-container').show();
                                                            $(this).hide();
                                                            if ($('#admin-pax-cards-container').children().length === 0) {
                                                                if (existingSplit && existingSplit.length) {
                                                                    var lim = Math.min(existingSplit.length, maxPax);
                                                                    for (var i = 0; i < lim; i++) addPaxCard(existingSplit[i]);
                                                                } else if (maxPax > 0) {
                                                                    addPaxCard(null);
                                                                }
                                                            }
                                                            recalculate();
                                                            updateAddPaxBtnState();
                                                        });

                                                        $('#admin-cancel-split-btn').on('click', function() {
                                                            $('#admin-split-form-container').hide();
                                                            $('#admin-split-display-readonly').show();
                                                            $('#toggle-admin-split-form').show();
                                                        });

                                                        $('#admin-add-pax-btn').on('click', function() {
                                                            if ($('#admin-pax-cards-container .adm-pax-card').length >= maxPax) {
                                                                if (typeof Swal !== 'undefined') {
                                                                    Swal.fire('Limit reached', 'This booking has ' + maxPax + ' pax. You cannot add more.', 'info');
                                                                } else {
                                                                    alert('Limit reached: this booking has ' + maxPax + ' pax.');
                                                                }
                                                                return;
                                                            }
                                                            addPaxCard(null);
                                                        });

                                                        function collectAndValidate() {
                                                            var paxList = [];
                                                            var hadError = false;
                                                            $('#admin-pax-cards-container .adm-pax-card').each(function() {
                                                                var name = $(this).find('.adm-pax-name').val().trim();
                                                                var tin = $(this).find('.adm-pax-tin').val().trim();
                                                                var em = $(this).find('.adm-pax-email').val().trim();
                                                                var ad = $(this).find('.adm-pax-address').val().trim();
                                                                var ph = $(this).find('.adm-pax-phone').val().trim();
                                                                var sab = $(this).find('.adm-pax-same-as-booker').is(':checked') ? 1 : 0;
                                                                if (!name) { hadError = true; Swal.fire('Error', 'Each pax must have a name.', 'error'); return false; }
                                                                if (!tin)  { hadError = true; Swal.fire('Error', 'Each pax must have a TIN.', 'error'); return false; }
                                                                if (!em)   { hadError = true; Swal.fire('Error', 'Each pax must have an Email.', 'error'); return false; }
                                                                if (!ad)   { hadError = true; Swal.fire('Error', 'Each pax must have an Address.', 'error'); return false; }
                                                                if (!ph)   { hadError = true; Swal.fire('Error', 'Each pax must have a Phone Number.', 'error'); return false; }
                                                                var products = [];
                                                                $(this).find('.adm-product-row').each(function() {
                                                                    var bp = $(this).find('.adm-split-product-select').val();
                                                                    var q = parseFloat($(this).find('.adm-split-qty').val()) || 0;
                                                                    if (bp && q > 0) products.push({ BookingProductID: parseInt(bp, 10), Quantity: q });
                                                                });
                                                                if (products.length === 0) {
                                                                    hadError = true;
                                                                    Swal.fire('Error', 'Pax "' + name + '" must have at least one product.', 'error');
                                                                    return false;
                                                                }
                                                                paxList.push({ PaxName: name, TIN: tin, Email: em, Address: ad, PhoneNumber: ph, SameAsBooker: sab, products: products });
                                                            });
                                                            if (hadError) return null;
                                                            // Full-allocation check (admin edit mirrors customer Submit).
                                                            var map = {};
                                                            for (var i = 0; i < bookingProducts.length; i++) {
                                                                map[bookingProducts[i].BookingProductID] = { name: bookingProducts[i].Name, expected: parseFloat(bookingProducts[i].Quantity), allocated: 0 };
                                                            }
                                                            for (var p = 0; p < paxList.length; p++) {
                                                                for (var pr = 0; pr < paxList[p].products.length; pr++) {
                                                                    var bid = paxList[p].products[pr].BookingProductID;
                                                                    if (map[bid]) map[bid].allocated += paxList[p].products[pr].Quantity;
                                                                }
                                                            }
                                                            for (var k in map) {
                                                                if (Math.abs(map[k].expected - map[k].allocated) > 0.01) {
                                                                    Swal.fire('Allocation Error', 'Product "' + map[k].name + '" requires total quantity of ' + map[k].expected + ' but ' + map[k].allocated.toFixed(2) + ' was allocated.', 'error');
                                                                    return null;
                                                                }
                                                            }
                                                            return paxList;
                                                        }

                                                        $('#admin-save-split-btn').on('click', function() {
                                                            var paxList = collectAndValidate();
                                                            if (!paxList) return;
                                                            var $btn = $(this);
                                                            Swal.fire({
                                                                icon: 'warning',
                                                                title: 'Save changes to submitted e-invoice?',
                                                                text: 'Finance team will be re-notified by email.',
                                                                showCancelButton: true,
                                                                confirmButtonText: 'Yes, save',
                                                                cancelButtonText: 'Cancel',
                                                                confirmButtonColor: '#162447'
                                                            }).then(function(result) {
                                                                if (!result.value) return;
                                                                var originalHtml = '<i class="la la-save"></i> Save Changes';
                                                                $btn.prop('disabled', true).html('<i class="la la-spinner la-spin"></i> Saving...');
                                                                $.ajax({
                                                                    url: adminEinvCfg.save_url,
                                                                    type: 'POST',
                                                                    contentType: 'application/json',
                                                                    data: JSON.stringify({ pax: paxList }),
                                                                    dataType: 'json',
                                                                    success: function(resp) {
                                                                        if (resp && resp.success) {
                                                                            Swal.fire('Success', resp.message || 'E-Invoice updated.', 'success').then(function() { location.reload(); });
                                                                        } else {
                                                                            Swal.fire('Error', (resp && resp.message) || 'Failed to save.', 'error');
                                                                            $btn.prop('disabled', false).html(originalHtml);
                                                                        }
                                                                    },
                                                                    error: function() {
                                                                        Swal.fire('Error', 'An unexpected error occurred.', 'error');
                                                                        $btn.prop('disabled', false).html(originalHtml);
                                                                    }
                                                                });
                                                            });
                                                        });
                                                    });
                                                })();
                                                </script>
                                                <?php } // end admin edit form ?>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            <?php } ?>

                             <!-- Booking Status Log Timeline -->
                            <?php if(!empty($status_logs)) { ?>
                                <div class="row mt-5">
                                    <div class="col-12">
                                        <div class="card card-custom">
                                            <div class="card-header flex-wrap py-2" style="background-color:#D7E2F2;">
                                                <div class="card-title">
                                                    <h4 class="card-label mb-0" style="color:#6082B6; font-size: 1.1rem;">
                                                        <strong><i class="la la-history"></i> Status History</strong>
                                                    </h4>
                                                </div>
                                            </div>
                                            <div class="card-body">
                                                <!-- <div class="timeline-container" style="max-height: 500px; overflow-y: auto;"> -->
                                                <div class="timeline-container">
                                                    <?php foreach($status_logs as $log): ?>
                                                        <div class="timeline-item status-log-item <?php echo $log['status']; ?>" style="padding: 12px 0; border-left: 2px solid #e0e0e0; padding-left: 20px; margin-left: 15px; position: relative;">
                                                            <div class="timeline-marker" style="position: absolute; left: -7px; top: 15px; width: 12px; height: 12px; border-radius: 50%; background-color: <?php echo $log['status'] == 'completed' ? '#50C878' : ($log['status'] == 'cancelled' ? '#FF69B4' : '#FFBF00'); ?>; border: 2px solid white; box-shadow: 0 0 0 2px <?php echo $log['status'] == 'completed' ? '#50C878' : ($log['status'] == 'cancelled' ? '#FF69B4' : '#FFBF00'); ?>;"></div>
                                                            <div class="timeline-content">
                                                                <div class="d-flex justify-content-between align-items-start mb-1">
                                                                    <div class="timeline-title" style="font-weight: 600; color: #212529; font-size: 0.95rem;">
                                                                        <i class="<?php echo $log['icon']; ?>" style="margin-right: 6px; color: #6082B6;"></i>
                                                                        <?php echo htmlspecialchars($log['title']); ?>
                                                                    </div>
                                                                    <div class="timeline-date" style="font-size: 0.8125rem; color: #6c757d;">
                                                                        <?php echo return_timestamp_output($log['date_raw']); ?>
                                                                    </div>
                                                                </div>
                                                                <div class="timeline-description" style="font-size: 0.875rem; color: #495057; margin-top: 4px;">
                                                                    <?php echo htmlspecialchars($log['description']); ?>
                                                                </div>
                                                                <?php if(!empty($log['created_by']) && $log['created_by'] != 'System'): ?>
                                                                <div class="timeline-meta" style="font-size: 0.75rem; color: #868e96; margin-top: 6px;">
                                                                    <i class="la la-user"></i> Changed by: <?php echo htmlspecialchars($log['created_by']); ?>
                                                                </div>
                                                                <?php endif; ?>
                                                            </div>
                                                        </div>
                                                    <?php endforeach; ?>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            <?php } ?>

                            <?php if(!empty($booking_logs)) { ?>
                                <div class="row mt-5">
                                    <div class="col-12">
                                        <div class="card card-custom">
                                        <div class="card-header flex-wrap py-2" style="background-color:#D7E2F2;">
                                            <div class="card-title">
                                                <h4 class="card-label mb-0" style="color:#6082B6; font-size: 1.1rem;">
                                                    <i class="la la-file-alt"></i> <strong>Audit Log</strong>
                                                </h4>
                                            </div>
                                        </div>
                                        <div class="card-body p-0">
                                            <div style="max-height: 500px; overflow-y: auto;">
                                                <table class="table table-striped table-bordered mb-0" style="font-size: 0.85rem;">
                                                    <thead style="position: sticky; top: 0; background: #f8f9fa; z-index: 1;">
                                                        <tr>
                                                            <th style="min-width: 140px;">Date/Time</th>
                                                            <th style="min-width: 120px;">Changed By</th>
                                                            <th style="min-width: 120px;">Field</th>
                                                            <th>Old Value</th>
                                                            <th>New Value</th>
                                                            <th style="min-width: 90px;">Snapshot</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                        <?php foreach($booking_logs as $log): ?>
                                                        <tr>
                                                            <td><?php echo date('d M Y H:i', strtotime($log['InsertDate'])); ?></td>
                                                            <td><?php echo htmlspecialchars($log['AdminName'] ?? '-'); ?></td>
                                                            <td><?php echo audit_log_field_label($log['Column']); ?></td>
                                                            <td><?php echo audit_log_value($log['Column'], $log['CurrentData']); ?></td>
                                                            <td><?php echo audit_log_value($log['Column'], $log['NewData']); ?></td>
                                                            <td>
                                                                <?php if (!empty($log['SnapshotPDF'])): ?>
                                                                    <a href="<?php echo base_url('Booking/View_Snapshot?file=' . basename($log['SnapshotPDF'])); ?>" target="_blank" title="View previous booking confirmation">
                                                                        <i class="la la-file-pdf" style="font-size: 1.2rem;"></i> View PDF
                                                                    </a>
                                                                <?php else: ?>
                                                                    -
                                                                <?php endif; ?>
                                                            </td>
                                                        </tr>
                                                        <?php endforeach; ?>
                                                    </tbody>
                                                </table>
                                            </div>
                                        </div>
                                        </div>
                                    </div>
                                </div>
                            <?php } ?>

                            <?php if(!empty($payment_logs)) { ?>
                                <div class="row mt-5">
                                    <div class="col-12">
                                        <div class="card card-custom">
                                        <div class="card-header flex-wrap py-2" style="background-color:#D7E2F2;">
                                            <div class="card-title">
                                                <h4 class="card-label mb-0" style="color:#6082B6; font-size: 1.1rem;">
                                                    <i class="la la-money-bill"></i> <strong>Payment Changes Log</strong>
                                                </h4>
                                            </div>
                                        </div>
                                        <div class="card-body p-0">
                                            <div style="max-height: 500px; overflow-y: auto;">
                                                <table class="table table-striped table-bordered mb-0" style="font-size: 0.85rem;">
                                                    <thead style="position: sticky; top: 0; background: #f8f9fa; z-index: 1;">
                                                        <tr>
                                                            <th style="min-width: 140px;">Date/Time</th>
                                                            <th style="min-width: 120px;">Changed By</th>
                                                            <th style="min-width: 140px;">Payment (Type)</th>
                                                            <th style="min-width: 120px;">Field</th>
                                                            <th>Old Value</th>
                                                            <th>New Value</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                        <?php foreach($payment_logs as $log): ?>
                                                        <tr>
                                                            <td><?php echo date('d M Y H:i', strtotime($log['InsertDate'])); ?></td>
                                                            <td><?php echo htmlspecialchars($log['AdminName'] ?? '-'); ?></td>
                                                            <td><?php echo '#' . $log['PaymentID'] . ' (' . htmlspecialchars($log['PaymentType'] ?? '-') . ')'; ?></td>
                                                            <td><?php echo payment_log_field_label($log['Column']); ?></td>
                                                            <td><?php echo audit_log_value($log['Column'], $log['CurrentData']); ?></td>
                                                            <td><?php echo audit_log_value($log['Column'], $log['NewData']); ?></td>
                                                        </tr>
                                                        <?php endforeach; ?>
                                                    </tbody>
                                                </table>
                                            </div>
                                        </div>
                                        </div>
                                    </div>
                                </div>
                            <?php } ?>
                        </div>
                    </div>
                </div>
            </div>
        <?php } ?>

    </div>
</div>

<?php function print_allow_review($AllowReview) { ?>
    <div class="form-group">
        <label>Allow Customer Review</label>
        <select id="AllowReview" class="form-control selectpicker">
            <option value="1" <?php if((isset($AllowReview) && $AllowReview == 1) || !isset($AllowReview)) { echo 'selected'; } ?>>Yes</option>
            <option value="0" <?php if(isset($AllowReview) && $AllowReview == 0) { echo 'selected'; } ?>>No</option>
        </select>
    </div>
<?php }?>

<?php function audit_log_field_label($column) {
    $labels = array(
        'Status' => 'Status',
        'NetTotal' => 'Net Total',
        'GrossProfit' => 'Gross Profit',
        'StartDate' => 'Start Date',
        'EndDate' => 'End Date',
        'BookingConfirmationFooter' => 'Booking Confirmation Footer',
        'TravelVoucherFooter' => 'Travel Voucher Footer',
        'KeyContacts' => 'Key Contacts',
        'SpecialRemarks' => 'Special Remarks',
        'PaymentDueDate' => 'Payment Due Date',
        'CustomerID' => 'Customer',
        'SalesInCharge' => 'Sales In Charge',
        'OverallStatus' => 'Overall Status',
        'AllowReview' => 'Allow Customer Review',
        'CancellationReasonID' => 'Cancellation Reason',
    );
    return isset($labels[$column]) ? $labels[$column] : ucwords(str_replace('_', ' ', preg_replace('/([a-z])([A-Z])/', '$1 $2', $column)));
} ?>

<?php function audit_log_value($column, $value) {
    if ($value === null || $value === '') return '-';

    $html_fields = array('BookingConfirmationFooter', 'TravelVoucherFooter', 'KeyContacts', 'SpecialRemarks');

    if (in_array($column, $html_fields)) {
        $plain = trim(strip_tags($value));
        if ($plain === '') return '-';
        if (strlen($plain) > 80) {
            $id = 'audit_' . uniqid();
            return '<span id="' . $id . '_short">' . htmlspecialchars(substr($plain, 0, 80)) . '... <a href="javascript:void(0)" onclick="document.getElementById(\'' . $id . '_short\').style.display=\'none\';document.getElementById(\'' . $id . '_full\').style.display=\'inline\';">show more</a></span>'
                 . '<span id="' . $id . '_full" style="display:none;">' . htmlspecialchars($plain) . ' <a href="javascript:void(0)" onclick="document.getElementById(\'' . $id . '_full\').style.display=\'none\';document.getElementById(\'' . $id . '_short\').style.display=\'inline\';">show less</a></span>';
        }
        return htmlspecialchars($plain);
    }

    $escaped = htmlspecialchars($value);
    if (strlen($value) > 100) {
        return htmlspecialchars(substr($value, 0, 100)) . '...';
    }
    return $escaped;
} ?>

<?php function payment_log_field_label($column) {
    $labels = array(
        'Created' => '(Record Created)',
        'Status' => 'Status',
        'Credit' => 'Credit (RM)',
        'Debit' => 'Debit (RM)',
        'Date' => 'Date',
        'Type' => 'Type',
        'ReferenceNumber' => 'Reference Number',
        'Deadline' => 'Deadline',
        'Bank' => 'Bank',
        'BankAccount' => 'Bank Account',
        'BankHolder' => 'Bank Holder',
        'BankSlip' => 'Bank Slip',
        'Quotation' => 'Quotation',
        'Invoice' => 'Invoice',
        'QuotationNumber' => 'Quotation Number',
        'InvoiceNumber' => 'Invoice Number',
        'Currency' => 'Currency',
        'ForeignCurrency' => 'Foreign Currency',
        'SupplierID' => 'Supplier',
        'BookingProductID' => 'Booking Product',
        'DebitRemark' => 'Debit Remark',
        'PaymentRemark' => 'Payment Remark',
    );
    return isset($labels[$column]) ? $labels[$column] : ucwords(str_replace('_', ' ', preg_replace('/([a-z])([A-Z])/', '$1 $2', $column)));
} ?>

<script>

    $('.alert-light-info').hide();

    <?php if((current_url() == base_url('Booking/Update') || current_url() == base_url('Booking/Duplicate')) && isset($BookingConfirmationFooterID) && !empty($BookingConfirmationFooterID)) { ?>

        $('#booking_confirmation_content').show();

    <?php } ?>

    <?php if((current_url() == base_url('Booking/Update') || current_url() == base_url('Booking/Duplicate')) && isset($TravelVoucherFooterID) && !empty($TravelVoucherFooterID)) { ?>

        $('#travel_voucher_content').show();

        $('#travel_voucher_key_contacts').show();

        $('#travel_voucher_special_remarks').show();

        <?php if(empty($KeyContacts) || empty($SpecialRemarks)) { ?>
            $(document).ready(function() {
                setTimeout(function() {
                    $.ajax({
                        url: '<?php echo base_url('Footer/Read') ?>',
                        type: 'get',
                        data: { footer_id: '<?php echo $TravelVoucherFooterID; ?>' },
                        dataType: 'json',
                        success: function(array) {
                            <?php if(empty($KeyContacts)) { ?>
                                if(array.KeyContacts && array.KeyContacts.trim() != '') {
                                    tinyMCE.editors[2].setContent(array.KeyContacts);
                                }
                            <?php } ?>
                            <?php if(empty($SpecialRemarks)) { ?>
                                if(array.SpecialRemarks && array.SpecialRemarks.trim() != '') {
                                    tinyMCE.editors[3].setContent(array.SpecialRemarks);
                                }
                            <?php } ?>
                        }
                    });
                }, 500);
            });
        <?php } ?>

    <?php } ?>

    var products = <?php echo json_encode($products) ?>;

    var label = null;

    var array = [];

    for(var i = 0; i < products.length; i++) {

        if(products[i].Category == label) {

            array.push('<option data-icon="la la-product-hunt font-size-lg bs-icon" value="'+ products[i].ProductID +'">'+ products[i].Product + ' (' + products[i].ProductCode +')</option>');

        } else {

            array.push('<optgroup label="'+ products[i].Category +'"><option data-icon="la la-product-hunt font-size-lg bs-icon" value="'+ products[i].ProductID +'">'+ products[i].Product + ' (' + products[i].ProductCode +')</option>');

            label = products[i].Category;

        }

    }

    var count = 0;

    var booking_products = <?php echo json_encode($booking_products) ?>;

    // Guard against empty booking_products: SAD drafts have no
    // products yet, so booking_products[0] would be undefined and reading
    // .BookingProductID on it throws a TypeError that halts the rest of this
    // <script> block — leaving every later click handler (Insert Product,
    // Update Booking, etc.) unbound. Fall back to $BookingProductID (the next
    // available product id, populated by the controller for both Create and
    // Update GET branches) when the array is empty.
    var booking_product_id = (window.location.href.split('?')[0] == '<?php echo base_url('Booking/Update'); ?>' && booking_products.length > 0) ? parseInt(booking_products[0].BookingProductID) : parseInt(<?php echo $BookingProductID; ?>);

    var booking_product_ids = [];

    var initial_booking_confirmation_footer = $('#kt-tinymce-4').val();

    var initial_travel_voucher_footer = $('#kt-tinymce-5').val();

    var initial_key_contacts = $('#kt-tinymce-6').val();

    var initial_special_remarks = $('#kt-tinymce-7').val();

    var product_sequence = [];



    // Legacy bookings (created on/before 2026-04-06) must never show the deposit container
    var is_legacy_deposit_booking = <?php echo !empty($is_legacy_deposit_booking) ? 'true' : 'false'; ?>;

    function Reset_Deposit_Deadline() {
        $('input[name="DepositDeadline"]').val('');
        // Hide deposit container when Deposit Deadline is reset
        $('#deposit_container').hide();
    }

    // Function to toggle deposit container based on Deposit Deadline value
    function toggleDepositContainer() {
        if (is_legacy_deposit_booking) {
            $('#deposit_container').hide();
            return;
        }
        var depositDeadline = $('input[name="DepositDeadline"]').val();
        if (depositDeadline && depositDeadline.trim() !== '') {
            $('#deposit_container').show();
        } else {
            $('#deposit_container').hide();
        }
    }
    
    // Check deposit deadline on page load
    $(document).ready(function() {
        toggleDepositContainer();
        
        // Listen for changes on Deposit Deadline field
        $('input[name="DepositDeadline"]').on('change', function() {
            toggleDepositContainer();
        });
        
        // Also listen for datepicker change events (if using datepicker)
        $('input[name="DepositDeadline"]').on('changeDate', function() {
            toggleDepositContainer();
        });
        
        // Listen for input events (when manually typing or clearing)
        $('input[name="DepositDeadline"]').on('input', function() {
            toggleDepositContainer();
        });
    });



    function Reset_Tag() {

        $('#Tag').val('').change();

    }



    function Reset_ProductSupplierDeposit(booking_product_id) {

        $(`#PaymentOutSupplierDeposit-${booking_product_id}`).val('');

    }

    function Toggle_PaymentOutFields(booking_product_id) {

        if($(`#DisableChecklistPaymentOut-${booking_product_id}`).is(':checked')) {

            $(`#PaymentOutSection-${booking_product_id}`).hide();

            $(`#PaymentOutSupplierFull-${booking_product_id}`).val('');

            $(`#PaymentOutSupplierDeposit-${booking_product_id}`).val('');

        } else {

            $(`#PaymentOutSection-${booking_product_id}`).show();

        }

    }

    

    var current_date = (new Date()).toLocaleDateString();

    $('#kt_daterangepicker_4').on('apply.daterangepicker', function(event, daterange) {

        var start_date = (new Date(daterange.startDate._d)).toLocaleDateString();

        var end_date = (new Date(daterange.endDate._d)).toLocaleDateString();

        if(start_date == current_date && end_date == current_date) {

            $('#TravelDate').val(moment().format('DD/MM/YYYY') + ' - ' + moment().format('DD/MM/YYYY'));

        }

    });



    $('#booking_confirmation_footer').change(function() {

        var footer_id = $('#booking_confirmation_footer').val();

        if(footer_id == '') {

            $('#booking_confirmation_content').hide();

            tinyMCE.editors[0].setContent('');

        } else {

            $('#booking_confirmation_content').show();

            $.ajax({

                url: '<?php echo base_url('Footer/Read') ?>',

                type: 'get',

                data: {

                    footer_id: footer_id

                },

                dataType: 'json',

                success: function(array) {

                    tinyMCE.editors[0].setContent(array.BookingConfirmationContent);

                }

            });

        }

    });



    $('#travel_voucher_footer').change(function() {

        var footer_id = $('#travel_voucher_footer').val();

        if(footer_id == '') {

            $('#travel_voucher_content').hide();

            $('#travel_voucher_key_contacts').hide();

            $('#travel_voucher_special_remarks').hide();

            tinyMCE.editors[1].setContent('');

            tinyMCE.editors[2].setContent('');

            tinyMCE.editors[3].setContent('');

        } else {

            $('#travel_voucher_content').show();

            $.ajax({

                url: '<?php echo base_url('Footer/Read') ?>',

                type: 'get',

                data: {

                    footer_id: footer_id

                },

                dataType: 'json',

                success: function(array) {

                    tinyMCE.editors[1].setContent(array.TravelVoucherContent);

                    // Populate Key Contacts and Special Remarks editors with footer values
                    tinyMCE.editors[2].setContent(array.KeyContacts || '');

                    $('#travel_voucher_key_contacts').show();

                    tinyMCE.editors[3].setContent(array.SpecialRemarks || '');

                    $('#travel_voucher_special_remarks').show();

                }

            });

        }

    });



    $('#booking_number').change(function() {

        var booking_number = $('#booking_number').val();

        $.ajax({

            url: '<?php echo base_url('Booking/Detect') ?>',

            type: 'post',

            data: {

                booking_number: booking_number

            },

            dataType: 'json',

            success: function(redundant_booking_number) {

                if(redundant_booking_number) {

                    Display_Message('<?php echo base_url('assets/image/sweetalert.jpg') ?>', 'Redundant Booking Number Detected', null);

                    $('#booking_number').val('');

                }

            }

        });

    });



    $('#create_booking_product').click(function() {

        count++;

        $('#benchmark').append('<div id="'+ booking_product_id +'" class="col-md-12 pt-3 pb-3 mb-5 draggable" style="background-color:white; border:3px solid #D7E2F2; border-radius:8px;">' +

            '<a onclick="Delete_Booking_Product('+ booking_product_id +')" class="btn btn-icon btn-light-danger">' +

                '<i class="la la-times"></i>' +

            '</a>' +

            '<a class="btn btn-icon btn-sm btn-hover-light-primary draggable-handle" style="float:right;">' +

                '<i class="ki ki-menu"></i>' +

			'</a>' +

            '<br><br>' +

            '<div class="form-group">' +

                '<div class="row">' +

                    '<div class="col-md-12">' +

                        '<label>Product <span style="color:red;">*</span></label>' +

                        '<select id="ProductID-'+ booking_product_id +'" data-live-search="true" onchange="Update_Booking_Product('+ '/Product/' + ',' + booking_product_id +')" class="form-control selectpicker">' +

                            '<option selected disabled data-icon="la la-product-hunt font-size-lg bs-icon" value="">--SELECT PRODUCT--</option>' + array +

                        '</select>' +

                    '</div>' +

                '</div>' +

                '<br>' +

                '<div class="row">' +

                    '<div class="col-md-6 mb-7 mb-md-0">' +

                        '<label>Product Code</label>' +

                        '<div class="input-icon">' +

                            '<input disabled type="text" id="ProductCode-'+ booking_product_id +'" class="form-control">' +

                            '<span>' +

                                '<i class="la la-product-hunt"></i>' +

                            '</span>' +

                        '</div>' +

                    '</div>' +

                    '<div class="col-md-6">' +

                        '<label>Description</label>' +

                        '<div class="input-icon">' +

                            '<input disabled type="text" id="Description-'+ booking_product_id +'" maxlength="80" autocomplete="off" class="form-control">' +

                            '<span>' +

                                '<i class="la la-pencil-alt"></i>' +

                            '</span>' +

                        '</div>' +

                        '<small id="Description-'+ booking_product_id +'Error" class="text-danger"></small>' +

                    '</div>' +

                '</div>' +

                '<br>' +

                '<div class="row">' +

                    '<div class="col-md-6 mb-7 mb-md-0">' +

                        '<label>Quantity <span style="color:red;">*</span></label>' +

                        '<div class="input-icon">' +

                            '<input disabled type="text" id="Quantity-'+ booking_product_id +'" oninput="Update_Booking_Product('+ '/Quantity/' + ',' + booking_product_id +')" autocomplete="off" class="form-control">' +

                            '<span>' +

                                '<i class="la la-calculator"></i>' +

                            '</span>' +

                        '</div>' +

                    '</div>' +

                    '<div class="col-md-6">' +

                        '<label>Price <span style="color:red;">*</span></label>' +

                        '<div class="input-icon">' +

                            '<input disabled type="text" id="Price-'+ booking_product_id +'" onchange="Update_Booking_Product('+ '/Price/' + ',' + booking_product_id +')" autocomplete="off" class="form-control" style="text-align:right;">' +

                            '<span>' +

                                '<i class="la la-dollar"></i>' +

                            '</span>' +

                        '</div>' +

                    '</div>' +

                '</div>' +

                '<br>' +

                '<div class="row">' +

                    '<div class="col-md-6">' +

                        '<label>Total</label>' +

                        '<div class="input-icon">' +

                            '<input disabled type="text" id="Total-'+ booking_product_id +'" value="0.00" class="form-control" style="text-align:right;">' +

                            '<span>' +

                                '<i class="la la-dollar"></i>' +

                            '</span>' +

                        '</div>' +

                    '</div>' +

                '</div>' +

                '<br>' +

                '<div class="row">' +

                    '<div class="col-md-12">' +

                        '<label class="checkbox checkbox-outline checkbox-primary">' +

                            '<input disabled type="checkbox" id="DisableChecklistPaymentOut-'+ booking_product_id +'" onchange="Toggle_PaymentOutFields('+ booking_product_id +')">' +

                            '<span></span>' +

                            '&nbsp;Disable Checklist & Payment Out' +

                        '</label>' +

                    '</div>' +

                '</div>' +

                '<div id="PaymentOutSection-'+ booking_product_id +'">' +

                '<br>' +

                '<div class="row">' +

                    '<div class="col-md-6 mb-7 mb-md-0">' +

                        '<label>Payment Out to Supplier (Deposit)' +

                            '<a onclick="Reset_ProductSupplierDeposit('+ booking_product_id +')" class="btn btn-icon btn-light-warning btn-xs">' +

                                '<i class="la la-undo"></i>' +

                            '</a>' +

                        '</label>' +

                        '<div class="input-icon">' +

                            '<input disabled readonly type="text" id="PaymentOutSupplierDeposit-'+ booking_product_id +'" autocomplete="off" class="form-control">' +

                            '<span>' +

                                '<i class="la la-calendar"></i>' +

                            '</span>' +

                        '</div>' +

                    '</div>' +

                    '<div class="col-md-6">' +

                        '<label>Payment Out to Supplier (Full) <span style="color:red;">*</span></label>' +

                        '<div class="input-icon">' +

                            '<input disabled readonly type="text" id="PaymentOutSupplierFull-'+ booking_product_id +'" autocomplete="off" class="form-control">' +

                            '<span>' +

                                '<i class="la la-calendar"></i>' +

                            '</span>' +

                        '</div>' +

                    '</div>' +

                '</div>' +

                '</div>' +

            '</div>' +

        '</div>');

        if(window.location.href == '<?php echo base_url('Booking/Create'); ?>' || window.location.href.split('?')[0] == '<?php echo base_url('Booking/Create'); ?>' || (window.location.href.split('?')[0] == '<?php echo base_url('Booking/Update'); ?>' || window.location.href.split('?')[0] == '<?php echo base_url('Booking/Duplicate'); ?>') && count > booking_products.length) {

            $(`#ProductID-${booking_product_id}`).selectpicker();

            $(`#PaymentOutSupplierFull-${booking_product_id}`).datepicker({format: 'dd/mm/yyyy', autoclose: true, todayHighlight: true});

            $(`#PaymentOutSupplierDeposit-${booking_product_id}`).datepicker({format: 'dd/mm/yyyy', autoclose: true, todayHighlight: true});

        }

        // Cap the line description to AutoCount's quotation limit so an over-long
        // value doesn't fail the whole booking sync (mirrors BookingSync MAX_DESCRIPTION).
        // Guarded: the Update/Duplicate prefill loop fires this click handler before the
        // validateLength helper's <script> block is parsed; native maxlength still caps.
        if (typeof validateLength === 'function') {
            validateLength('Description-' + booking_product_id, 'Description-' + booking_product_id + 'Error', 80);
        }

        booking_product_ids.push(booking_product_id);

        booking_product_id++;

        if(window.location.href.split('?')[0] == '<?php echo base_url('Booking/Update'); ?>' && count > booking_products.length) {

            $('#product_action').prop('disabled', true);

        }

    });

    

    function Create_Booking_Products()

    {

        var booking_products = [];

        for(var i = 0; i < product_sequence.length; i++) {

            var product_id = $(`#ProductID-${product_sequence[i]}`).val();

            var index = products.findIndex(value => value.ProductID == product_id);

            var product_code = products[index].ProductCode;

            var name = $(`#ProductID-${product_sequence[i]} option:selected`).text();

            var description = ($(`#Description-${product_sequence[i]}`).val()).toUpperCase();

            var quantity = parseInt($(`#Quantity-${product_sequence[i]}`).val());

            var price = parseFloat(($(`#Price-${product_sequence[i]}`).val()).replace(/,/g, ''));

            var total = quantity * price;

            var disable_checklist = $(`#DisableChecklistPaymentOut-${product_sequence[i]}`).is(':checked') ? 1 : 0;
            var supplier_full = null;
            var supplier_deposit = null;
            if(!disable_checklist) {
                supplier_full = $(`#PaymentOutSupplierFull-${product_sequence[i]}`).val();
                supplier_deposit = $(`#PaymentOutSupplierDeposit-${product_sequence[i]}`).val();
                if(supplier_full && supplier_full != '') {
                    var parts = supplier_full.split('/');
                    supplier_full = `${parts[2]}-${parts[1]}-${parts[0]}`;
                } else {
                    supplier_full = null;
                }
                if(supplier_deposit && supplier_deposit != '') {
                    var parts = supplier_deposit.split('/');
                    supplier_deposit = `${parts[2]}-${parts[1]}-${parts[0]}`;
                } else {
                    supplier_deposit = null;
                }
            }

            if(window.location.href == '<?php echo base_url('Booking/Create'); ?>' || window.location.href.split('?')[0] == '<?php echo base_url('Booking/Create'); ?>' || window.location.href.split('?')[0] == '<?php echo base_url('Booking/Duplicate'); ?>' || (window.location.href.split('?')[0] == '<?php echo base_url('Booking/Update'); ?>' && jQuery.inArray(product_sequence[i], array) == -1)) {

                booking_products.push({ProductID:product_id, ProductCode:product_code, Name:name, Description:description, Quantity:quantity, Price:price, Total:total, PaymentOutSupplierFull:supplier_full, PaymentOutSupplierDeposit:supplier_deposit, disable_checklist_payment_out:disable_checklist, InsertBy:<?php echo $this->session->userdata('admin_id') ?>, InsertDate:'<?php echo date('Y-m-d H:i:s') ?>'});

            }

        }

        return booking_products;

    }



    function Update_Booking_Product(value, booking_product_id)

    {

        if(value == '/Product/') {

            $(`#Description-${booking_product_id}`).removeAttr('disabled');

            $(`#Quantity-${booking_product_id}`).removeAttr('disabled');

            $(`#Price-${booking_product_id}`).removeAttr('disabled');

            $(`#PaymentOutSupplierFull-${booking_product_id}`).removeAttr('disabled');

            $(`#PaymentOutSupplierDeposit-${booking_product_id}`).removeAttr('disabled');

            $(`#DisableChecklistPaymentOut-${booking_product_id}`).removeAttr('disabled');

        }

        var product_id = $(`#ProductID-${booking_product_id}`).val();

        var index = products.findIndex(value => value.ProductID == product_id);

        var product_code = products[index].ProductCode;

        $(`#ProductCode-${booking_product_id}`).val(product_code);

        var quantity = $(`#Quantity-${booking_product_id}`).val();

        if(value == '/Product/') {

            $(`#Price-${booking_product_id}`).val('');

        }

        var price = ($(`#Price-${booking_product_id}`).val()).replace(/,/g, '');

        if(quantity.match(/^[1-9][\d]{0,4}$/) && price.match(/^[0-9][\d]{0,9}([\.][\d]{0,2})?$/)) {

            var total = parseInt(quantity) * parseFloat(price);

            $(`#Price-${booking_product_id}`).val(parseFloat(price).toLocaleString('en-US', {minimumFractionDigits: 2}));

            $(`#Total-${booking_product_id}`).val(total.toLocaleString('en-US', {minimumFractionDigits: 2}));

        } else {

            if(quantity.match(/^[1-9][\d]{0,4}$/) == null) {

                $(`#Quantity-${booking_product_id}`).val('');

            }

            if(price.match(/^[0-9][\d]{0,9}([\.][\d]{0,2})?$/) == null) {

                $(`#Price-${booking_product_id}`).val('');

            }

            quantity = $(`#Quantity-${booking_product_id}`).val() == '' ? 0 : parseInt($(`#Quantity-${booking_product_id}`).val());

            price = $(`#Price-${booking_product_id}`).val() == '' ? 0 : parseFloat(($(`#Price-${booking_product_id}`).val()).replace(/,/g, ''));

            var total = quantity * price;

            $(`#Total-${booking_product_id}`).val(total.toLocaleString('en-US', {minimumFractionDigits: 2}));

        }

        Calculate_Subtotal();

    }



    function Delete_Booking_Product(booking_product_id)

    {

        booking_product_ids = booking_product_ids.filter(function(value) {

            return value != booking_product_id;

        });

        $(`#${booking_product_id}`).remove();

        Calculate_Subtotal();

    }



    function Calculate_Subtotal()

    {

        var total = 0;

        var subtotal = 0;

        if(booking_product_ids.length > 0) {

            for(var i = 0; i < booking_product_ids.length; i++) {

                total += parseFloat(($(`#Total-${booking_product_ids[i]}`).val()).replace(/,/g, ''));

            }

        }

        subtotal += total;

        $('#Subtotal').val(subtotal.toLocaleString('en-US', {minimumFractionDigits: 2}));

        var discount = $('#Discount').val() == '' ? 0 : parseFloat(($('#Discount').val()).replace(/,/g, ''));

        $('#NetTotal').val((subtotal - discount).toLocaleString('en-US', {minimumFractionDigits: 2}));

        // Recalculate deposit amount when NetTotal changes
        Calculate_Deposit_Amount();

    }



    $('#product_action').change(function() {

        var action = $('#product_action').val();

        var sequence = [];

        $('#benchmark').children().each((index, element) => {

            sequence.push(parseInt(element.id));

        });

        if(action == 'INSERT PRODUCT') {

            if(sequence.toString() != '<?php echo implode(',', $ProductSequence); ?>') {

                $('#product_action').val('ORGANIZE PRODUCT').change();

            } else {

                $('.draggable-handle').hide();

                $('#create_booking_product').show();

            }

        } else {

            $('.draggable-handle').show();

            $('#create_booking_product').hide();

        }

    });

    

    $('#Discount').change(function() {

        var subtotal = $('#Subtotal').val();

        var discount = $('#Discount').val();

        if(subtotal != '0.00') {

            if(discount.match(/^[0-9][\d]{0,9}([\.][\d]{0,2})?$/)) {

                $('#Discount').val(parseFloat(discount).toLocaleString('en-US', {minimumFractionDigits: 2}));

                var net_total = parseFloat(subtotal.replace(/,/g, '')) - parseFloat(discount.replace(/,/g, ''));

                $('#NetTotal').val(net_total.toLocaleString('en-US', {minimumFractionDigits: 2}));

                // Recalculate deposit amount when NetTotal changes
                Calculate_Deposit_Amount();

            } else {

                $('#Discount').val('');

                $('#NetTotal').val(parseFloat(subtotal.replace(/,/g, '')).toLocaleString('en-US', {minimumFractionDigits: 2}));

                // Recalculate deposit amount when NetTotal changes
                Calculate_Deposit_Amount();

            }

        } else {

            $('#Discount').val('');

        }

    });

    // Store deposit paid value from PHP (loaded on page load)
    var deposit_paid_value = 0;
    $(document).ready(function() {
        // Get deposit paid value from the input field (set by PHP)
        // Extract numeric value (may include status text in parentheses)
        var deposit_paid_text = $('#DepositPaid').val();
        if(deposit_paid_text) {
            // Remove any text in parentheses and extract just the number
            var numeric_part = deposit_paid_text.split(' (')[0];
            deposit_paid_value = parseFloat(numeric_part.replace(/,/g, '')) || 0;
        }
        // Calculate deposit amount on page load
        Calculate_Deposit_Amount();
    });

    // Function to calculate deposit amount and status based on mode (percentage or fixed)
    function Calculate_Deposit_Amount() {
        var deposit_mode = $('#DepositMode').val();
        var net_total = $('#NetTotal').val();
        var deposit_total = 0;

        if(deposit_mode == 'fixed') {
            var fixed_amount = parseFloat($('#DepositFixedAmount').val());
            if(!isNaN(fixed_amount) && fixed_amount >= 0) {
                deposit_total = fixed_amount;
            }
        } else {
            var deposit_percentage = $('#DepositPercentage').val();
            if(net_total && deposit_percentage) {
                var net_total_value = parseFloat(net_total.replace(/,/g, ''));
                var percentage_value = parseFloat(deposit_percentage);
                if(!isNaN(net_total_value) && !isNaN(percentage_value) && percentage_value >= 0 && percentage_value <= 100) {
                    deposit_total = (net_total_value * percentage_value / 100);
                    deposit_total = Math.ceil(deposit_total);
                }
            }
        }

        $('#DepositTotal').val(deposit_total.toLocaleString('en-US', {minimumFractionDigits: 2}));

        // Calculate deposit status and update Deposit Paid field
        var deposit_difference = deposit_paid_value - deposit_total;
        var deposit_paid_display = '';
        var deposit_paid_color = '';

        if (deposit_paid_value > 0) {
            deposit_paid_display = deposit_paid_value.toLocaleString('en-US', {minimumFractionDigits: 2});

            if (deposit_difference < -0.01) {
                deposit_paid_display += ' (Underpaid: RM ' + Math.abs(deposit_difference).toLocaleString('en-US', {minimumFractionDigits: 2}) + ')';
                deposit_paid_color = '#FFA500';
            } else {
                deposit_paid_color = '';
            }
        } else {
            deposit_paid_display = '0.00';
            deposit_paid_color = '';
        }

        $('#DepositPaid').val(deposit_paid_display);
        if (deposit_paid_color) {
            $('#DepositPaid').css('color', deposit_paid_color);
            $('#DepositPaid').css('font-weight', 'bold');
        } else {
            $('#DepositPaid').css('color', '');
            $('#DepositPaid').css('font-weight', '');
        }
    }

    // Toggle deposit mode: show/hide percentage vs fixed amount input
    $('#DepositMode').on('change', function() {
        var mode = $(this).val();
        if(mode == 'fixed') {
            $('#DepositPercentage').hide();
            $('#DepositFixedAmount').show();
            $('#DepositValueLabel').text('Deposit Amount (RM)');
            $('#DepositValueIcon').html('<i class="la la-dollar"></i>');
        } else {
            $('#DepositPercentage').show();
            $('#DepositFixedAmount').hide();
            $('#DepositValueLabel').text('Deposit Percentage');
            $('#DepositValueIcon').html('<i class="la la-percentage"></i>');
        }
        Calculate_Deposit_Amount();
    });

    // Calculate deposit amount when percentage changes
    $('#DepositPercentage').on('input change', function() {
        var percentage = $(this).val();

        // Validate percentage is between 0 and 100
        if(percentage < 0) {
            $(this).val(0);
        } else if(percentage > 100) {
            $(this).val(100);
        }

        Calculate_Deposit_Amount();
    });

    // Calculate deposit amount when fixed amount changes
    $('#DepositFixedAmount').on('input change', function() {
        var amount = parseFloat($(this).val());
        if(amount < 0) {
            $(this).val(0);
        }
        Calculate_Deposit_Amount();
    });

    // Calculate deposit amount when NetTotal changes (also triggered by Discount change)
    $('#NetTotal').on('change', function() {
        Calculate_Deposit_Amount();
    });



    // Products each destination (category) expects a booking to cover, keyed by
    // CategoryID. Rendered from the category_product link table. Empty when the
    // page wasn't given the map (e.g. an unrelated render path).
    <?php
        $__category_products_map = array();
        if(!empty($category_products)) {
            foreach($category_products as $__cp) {
                $__category_products_map[$__cp->CategoryID][] = array(
                    'ProductID'   => $__cp->ProductID,
                    'Product'     => $__cp->Product,
                    'ProductCode' => $__cp->ProductCode
                );
            }
        }
    ?>
    var CATEGORY_PRODUCTS = <?php echo json_encode($__category_products_map); ?>;

    // Return the list of products the selected destination expects but that are
    // not present in the booking's inserted products. Empty array = fully covered
    // (or nothing to check). Draft saves are exempt and never warn.
    function Booking_Coverage_Gap() {
        var saveMode = $('#draft_save_mode').length ? $('#draft_save_mode').val() : '';
        var lenient = (saveMode === 'draft' || saveMode === 'approve' || saveMode === 'PB');
        var isDraftToggle = ($('#is_draft_intake_toggle').length && $('#is_draft_intake_toggle').is(':checked'));
        if (lenient || isDraftToggle) { return []; }

        var destination = $('#Destination').val();
        if (!destination) { return []; }

        var required = CATEGORY_PRODUCTS[destination] || [];
        if (!required.length) { return []; }

        var inserted = {};
        for (var i = 0; i < booking_product_ids.length; i++) {
            var pid = $(`#ProductID-${booking_product_ids[i]}`).val();
            if (pid != null && pid !== '') { inserted[String(pid)] = true; }
        }

        var missing = [];
        for (var j = 0; j < required.length; j++) {
            if (!inserted[String(required[j].ProductID)]) {
                missing.push(required[j].ProductCode + ' - ' + required[j].Product);
            }
        }
        return missing;
    }

    $('input[type="button"]').click(function() {

        const swalWithBootstrapButtons = Swal.mixin({

            customClass: {

                confirmButton: 'btn btn-light-success m-2',

                cancelButton: 'btn btn-danger m-2'

            },

            buttonsStyling: true

        });

        const runBookingSave = () => {

        swalWithBootstrapButtons.fire({

            width: 550,

            background: 'url(<?php echo base_url('assets/image/sweetalert.jpg') ?>)',

            icon: 'warning',

            title: <?php if(current_url() == base_url('Booking/Create') || current_url() == base_url('Booking/Duplicate')) { ?> 'Create New Booking Record ?' <?php } else { ?> '<?php echo 'Update Booking Record : ' . $BookingNumber . ' ?'; ?>' <?php } ?>,

            confirmButtonText: 'Confirm',

            cancelButtonText: 'Cancel',

            showCancelButton: true

        }).then((action) => {

            if(action.isConfirmed) {

                // Draft (Create page): park the booking with only the basics.
                // Every other field is optional here and only becomes required
                // when staff complete + graduate the booking. Bypass the full
                // required-field validation.
                var __isDraft = ($('#is_draft_intake_toggle').length && $('#is_draft_intake_toggle').is(':checked'));
                if (__isDraft) {
                    submitDraftBooking();
                    return;
                }

                function submitDraftBooking() {
                    var admin_id = <?php echo (int) $this->session->userdata('admin_id'); ?>;
                    var now   = '<?php echo date('Y-m-d H:i:s'); ?>';

                    // NOT NULL columns (strict SQL mode) need valid values even for
                    // a draft, so empties fall back to safe placeholders the staff
                    // overwrite when completing the booking.
                    var booking = [{
                        InsertBy: admin_id, InsertDate: now, UpdateBy: admin_id, UpdateDate: now,
                        ReservationNumber: ($('#ReservationNumber').val() || '').toUpperCase(),
                        Customer: ($('#Customer').val() || '').toUpperCase(),
                        Mobile: $('#Mobile').val() || '',
                        CountryCodeID: $('#CountryCodeID').val(),
                        Destination: $('#Destination').val() || 0,
                        // TC1 (Sales Agent 1 / After Sales) is assigned later when the
                        // draft graduates, so leave it empty (0 -> no admin join match,
                        // blank in the booking list). The creating TC is the TC2, set
                        // below. SalesAgent is NOT NULL, so 0 stands in for "unassigned".
                        SalesAgent: ($('#SalesAgent').length && $('#SalesAgent').val()) ? $('#SalesAgent').val() : 0,
                        ChatLanguage: $('#ChatLanguage').val() || 'EN',
                        BookingConfirmationTitle: $('#BookingConfirmationTitle').val() || 'BOOKING CONFIRMATION'
                        // Full Payment Deadline stays blank for a draft (column is nullable);
                        // staff set it later. Only sent below if they typed one.
                    }];

                    // TC2 (Sales Agent 2 / Pre Sales) is the TC who creates the draft.
                    // Honour an explicit pick, otherwise default to the creator.
                    var sa2 = $('#SalesAgent2').val();
                    booking[0]['SalesAgent2'] = sa2 ? sa2 : admin_id;

                    var src = $('#Source').val();
                    if (src) { booking[0]['Source'] = src; }

                    var td = $('#TravelDate').val();
                    if (td && td.indexOf(' - ') !== -1) {
                        var p = td.split(' - ');
                        var sd = p[0].split('/');
                        var ed = p[1].split('/');
                        booking[0]['StartDate'] = `${sd[2]}-${sd[1]}-${sd[0]}`;
                        booking[0]['EndDate']   = `${ed[2]}-${ed[1]}-${ed[0]}`;
                    }

                    var dd = $('input[name="DepositDeadline"]').val();
                    if (dd) { var d = dd.split('/'); booking[0]['DepositDeadline'] = `${d[2]}-${d[1]}-${d[0]}`; }
                    var fpd = $('input[name="FullPaymentDeadline"]').val();
                    if (fpd) { var f = fpd.split('/'); booking[0]['FullPaymentDeadline'] = `${f[2]}-${f[1]}-${f[0]}`; }

                    if ($('#BookingFormText').length) { booking[0]['BookingFormText'] = $('#BookingFormText').val(); }

                    if ($('#ChatSummary').length) { booking[0]['ChatSummary'] = $('#ChatSummary').val(); }

                    var br = ($('#BookingRemark').val() || '').toUpperCase();
                    if (br) { booking[0]['BookingRemark'] = br; }

                    var booking_number = ($('#booking_number').val() || '').toUpperCase();
                    if (booking_number) { booking[0]['BookingNumber'] = booking_number; }

                    var CustomerID = $('input[name="CustomerID"]').val();

                    // Send NO rooms for a draft: staff add the real rooms after the
                    // draft is graduated. Sending the form's default "ROOM 1"
                    // placeholder here would leave a stray room behind in Room
                    // Management once the booking is completed.
                    Submit_Booking('<?php echo base_url('Booking/Create') ?>', null, booking_number, booking, null, [], CustomerID, []);
                }

                var booking_confirmation_footer = $('#booking_confirmation_footer').val();

                var travel_voucher_footer = $('#travel_voucher_footer').val();

                var country_code = $('#CountryCodeID').val();

                var reservation_number = ($('#ReservationNumber').val()).toUpperCase();

                var customer = ($('#Customer').val()).toUpperCase();

                var ic_passport_no = ($('#ic_passport_no').val() || '').toUpperCase();

                var customer_type_ids = $('#customer_type').val() || [];
                var customer_type_names = $('#customer_type option:selected').map(function() { return $(this).text().trim(); }).get();
                var customer_type = customer_type_names.join(', ');

                var CustomerID = $('input[name="CustomerID"]').val();

                var mobile = $('#Mobile').val();

                var customer_2 = ($('#Customer2').val() || '').toUpperCase();

                var CustomerID2 = $('input[name="CustomerID2"]').val();

                var mobile_2 = $('#Mobile2').val();

                var country_code_2 = $('#CountryCodeID2').val();

                var travel_date = $('#TravelDate').val();

                var adult = $('#Adult').val();

                var children = $('#Children').val();

                var infant = $('#Infant').val();

                var destination = $('#Destination').val();

                var sales_agent = $('#SalesAgent').hasOwnProperty('length') ? $('#SalesAgent').val() : <?php echo $this->session->userdata('admin_id') ?>;

                var booking_op = $('#BookingOP').val();

                var sales_agent_2 = $('#SalesAgent2').val();

                var chat_language = $('#ChatLanguage').val();

                var source = $('#Source').val();

                var bc_title = $('#BookingConfirmationTitle').val();

                 // Apply validation
                validateLength('ReservationNumber', 'ReservationNumberError', 20);
                validateLength('Mobile', 'MobileError', 25, true);
                if (mobile_2 && mobile_2 !== '') {
                    validateLength('Mobile2', 'Mobile2Error', 25, true);
                }

                const fields = {
                    'Country code': country_code,
                    'Reservation number': reservation_number,
                    'Customer': customer,
                    'IC / Passport / SSM No.': ic_passport_no,
                    'Customer Type': customer_type,
                    'Mobile': mobile,
                    'Travel date': travel_date,
                    'Destination': destination,
                    'Sales agent': sales_agent,
                    'Sales agent 2': sales_agent_2,
                    'Chat language': chat_language,
                    'Source': source,
                    'BC title': bc_title,
                };

                // Draft lifecycle saves (Save as Draft / Approve / Save as Pending BC)
                // are lenient — only "Save as Pending BC Confirmation" (PBC), which
                // enters the real booking flow, requires a complete booking. Normal
                // bookings (empty mode) always run full validation.
                var __saveMode = $('#draft_save_mode').length ? $('#draft_save_mode').val() : '';
                var __lenient = (__saveMode === 'draft' || __saveMode === 'approve' || __saveMode === 'PB');

                const missing = Object.keys(fields).filter(
                    key => fields[key] === null || fields[key] === ''
                );

                if (missing.length && !__lenient) {
                    Display_Message(
                        '<?= base_url("assets/image/sweetalert.jpg") ?>',
                        `Please insert: ${missing.join(', ')}`,
                        null,
                        true
                    );
                } else {

                        if((booking_confirmation_footer != '' && tinyMCE.editors[0].getContent() == '') || (travel_voucher_footer != '' && tinyMCE.editors[1].getContent() == '')) {

                            Display_Message('<?php echo base_url('assets/image/sweetalert.jpg') ?>', 'Please Insert Footer Content Upon Selection', null);

                        } else {

                            if(booking_product_ids.length == 0 && !__lenient) {

                                Display_Message('<?php echo base_url('assets/image/sweetalert.jpg') ?>', 'Please Insert Product', null);

                            } else {

                                for(var i = 0; i < booking_product_ids.length && !__lenient; i++) {

                                    if($(`#ProductID-${booking_product_ids[i]}`).val() == null || $(`#Quantity-${booking_product_ids[i]}`).val() == '' || $(`#Price-${booking_product_ids[i]}`).val() == '' || $(`#Price-${booking_product_ids[i]}`).val() == '0.00') {

                                        Display_Message('<?php echo base_url('assets/image/sweetalert.jpg') ?>', 'Please Insert All Required Product Information', null);

                                        return;

                                    }

                                    if(!$(`#DisableChecklistPaymentOut-${booking_product_ids[i]}`).is(':checked')) {

                                        if($(`#PaymentOutSupplierFull-${booking_product_ids[i]}`).val() == null || $(`#PaymentOutSupplierFull-${booking_product_ids[i]}`).val() == '') {

                                            Display_Message('<?php echo base_url('assets/image/sweetalert.jpg') ?>', 'Please Insert Payment Out to Supplier (Full) For All Products', null);

                                            return;

                                        }

                                    }

                                }

                                // Validate room management - at least 1 room required
                                var hasRooms = false;
                                if(window.location.href == '<?php echo base_url('Booking/Create'); ?>' || window.location.href.split('?')[0] == '<?php echo base_url('Booking/Create'); ?>' || window.location.href.split('?')[0] == '<?php echo base_url('Booking/Duplicate'); ?>') {
                                    hasRooms = (typeof roomsList !== 'undefined') && roomsList.length > 0;
                                } else {
                                    hasRooms = $('#rooms_table tbody tr').length > 0 && $('#no_rooms_row').length === 0;
                                }
                                if(!hasRooms && !__lenient) {
                                    Display_Message('<?php echo base_url('assets/image/sweetalert.jpg') ?>', 'Please set up at least 1 room in Room Management', null);
                                    return;
                                }

                                $('#benchmark').children().each((index, element) => {

                                    product_sequence.push(parseInt(element.id));

                                });

                                if(window.location.href == '<?php echo base_url('Booking/Create'); ?>' || window.location.href.split('?')[0] == '<?php echo base_url('Booking/Create'); ?>' || window.location.href.split('?')[0] == '<?php echo base_url('Booking/Duplicate'); ?>') {

                                    var booking = [];

                                    // Pending BC (Booking/Create) only: don't auto-fill the travel
                                    // date — leave the field blank and warn the staff if they save
                                    // a Pending BC without setting one (drafts may stay blank).
                                    // Duplicate keeps its original fallback behaviour.
                                    var is_pending_bc = window.location.href == '<?php echo base_url('Booking/Create'); ?>' || window.location.href.split('?')[0] == '<?php echo base_url('Booking/Create'); ?>';
                                    var full_payment_deadline_raw = $('input[name="FullPaymentDeadline"]').val();
                                    var full_payment_deadline_iso;
                                    if (full_payment_deadline_raw) {
                                        var __fpd = full_payment_deadline_raw.split('/');
                                        full_payment_deadline_iso = `${__fpd[2]}-${__fpd[1]}-${__fpd[0]}`;
                                    } else if (is_pending_bc) {
                                        Display_Message('<?php echo base_url('assets/image/sweetalert.jpg') ?>', 'Please set the Full Payment Deadline', null);
                                        return;
                                    } else if (travel_date) {
                                        var __tds = travel_date.split(' - ')[0].split('/');
                                        full_payment_deadline_iso = `${__tds[2]}-${__tds[1]}-${__tds[0]}`;
                                    } else {
                                        full_payment_deadline_iso = '<?php echo date('Y-m-d') ?>';
                                    }

                                    var subtotal = ($('#Subtotal').val()).replace(/,/g, '');

                                    var net_total = ($('#NetTotal').val()).replace(/,/g, '');

                                    var deposit_percentage = $('#DepositPercentage').val() != '' ? parseInt($('#DepositPercentage').val()) : 50;
                                    var deposit_mode = $('#DepositMode').val();
                                    var deposit_fixed_amount = $('#DepositFixedAmount').val() != '' ? parseFloat($('#DepositFixedAmount').val()) : 0;

                                    booking.push({CountryCodeID:country_code, ReservationNumber:reservation_number, FullPaymentDeadline:full_payment_deadline_iso, Customer:customer, Mobile:mobile, Destination:destination, SalesAgent:sales_agent, Source:source, Subtotal:subtotal, NetTotal:net_total, DepositPercentage:deposit_percentage, DepositMode:deposit_mode, DepositFixedAmount:deposit_fixed_amount, ChatLanguage:chat_language, BookingConfirmationTitle:bc_title, InsertBy:<?php echo $this->session->userdata('admin_id') ?>, InsertDate:'<?php echo date('Y-m-d H:i:s') ?>', UpdateBy:<?php echo $this->session->userdata('admin_id') ?>, UpdateDate:'<?php echo date('Y-m-d H:i:s') ?>'});

                                    if(booking_op != '') {

                                        booking[0]['BookingOP'] = booking_op;

                                    }

                                    if(sales_agent_2 != '') {

                                        booking[0]['SalesAgent2'] = sales_agent_2;

                                    }

                                    // Booking PIC ticks (who prints on BC & TV). Always stored
                                    // on create so the booking starts in explicit manual mode.
                                    booking[0]['SalesAgentIsPIC'] = $('#SalesAgentIsPIC').is(':checked') ? 1 : 0;
                                    booking[0]['SalesAgent2IsPIC'] = $('#SalesAgent2IsPIC').is(':checked') ? 1 : 0;

                                    if(customer_2 !== '') { booking[0]['Customer2'] = customer_2; }
                                    if(CustomerID2) { booking[0]['CustomerID2'] = CustomerID2; }
                                    if(mobile_2 !== '') { booking[0]['Mobile2'] = mobile_2; }
                                    if(country_code_2) { booking[0]['CountryCodeID2'] = country_code_2; }

                                    if(booking_confirmation_footer != '') {

                                        booking[0]['BookingConfirmationFooterID'] = booking_confirmation_footer;

                                    }

                                    if(travel_voucher_footer != '') {

                                        booking[0]['TravelVoucherFooterID'] = travel_voucher_footer;

                                    }

                                    var booking_number = ($('#booking_number').val()).toUpperCase();

                                    if(booking_number != '') {

                                        booking[0]['BookingNumber'] = booking_number;

                                    }

                                    var CustomerID = $('input[name="CustomerID"]').val();

                                    var deposit_deadline = $('input[name="DepositDeadline"]').val();

                                    if(deposit_deadline != '') {

                                        deposit_deadline = deposit_deadline.split('/');

                                        booking[0]['DepositDeadline'] = `${deposit_deadline[2]}-${deposit_deadline[1]}-${deposit_deadline[0]}`;

                                    }

                                    if(travel_date != '') {

                                        travel_date = travel_date.split(' - ');

                                        var start_date = travel_date[0].split('/');

                                        var end_date = travel_date[1].split('/');

                                        booking[0]['StartDate'] = `${start_date[2]}-${start_date[1]}-${start_date[0]}`;

                                        booking[0]['EndDate'] = `${end_date[2]}-${end_date[1]}-${end_date[0]}`;

                                    }

                                    if(adult != '') {

                                        booking[0]['Adult'] = adult;

                                    }

                                    if(children != '') {

                                        booking[0]['Children'] = children;

                                    }

                                    if(infant != '') {

                                        booking[0]['Infant'] = infant;

                                    }

                                    var tag = ($('#Tag').val()).toString();

                                    if(tag != '') {

                                        booking[0]['Tag'] = tag;

                                    }

                                    var booking_remark = ($('#BookingRemark').val()).toUpperCase();

                                    if(booking_remark != '') {

                                        booking[0]['BookingRemark'] = booking_remark;

                                    }

                                    var discount = $('#Discount').val();

                                    if(discount != '') {

                                        booking[0]['Discount'] = discount.replace(/,/g, '');;

                                    }

                                    if(tinyMCE.editors[0].getContent() != '') {

                                        booking[0]['BookingConfirmationFooter'] = tinyMCE.editors[0].getContent();

                                    }

                                    if(tinyMCE.editors[1].getContent() != '') {

                                        booking[0]['TravelVoucherFooter'] = tinyMCE.editors[1].getContent();

                                    }

                                    booking[0]['KeyContacts'] = tinyMCE.editors[2].getContent();

                                    booking[0]['SpecialRemarks'] = tinyMCE.editors[3].getContent();

                                    if($('#BookingFormText').length) {

                                        booking[0]['BookingFormText'] = $('#BookingFormText').val();

                                    }

                                    if($('#ChatSummary').length) {

                                        booking[0]['ChatSummary'] = $('#ChatSummary').val();

                                    }



                                    booking_products = Create_Booking_Products();



                                    var createRooms = (typeof roomsList !== 'undefined') ? roomsList : [];
                                    Submit_Booking('<?php echo base_url('Booking/Create') ?>', null, booking_number, booking, null, booking_products, CustomerID, createRooms);

                                } else {

                                    array = [];

                                    for(var i = 0; i < booking_products.length; i++) {

                                        array.push(parseInt(booking_products[i].BookingProductID));

                                    }



                                    var booking = [{BookingID:<?php echo $BookingID ?>, UpdateBy:<?php echo $this->session->userdata('admin_id') ?>, UpdateDate:'<?php echo date('Y-m-d H:i:s') ?>'}];

                                    var booking_log = [];



                                    booking_products = [];

                                    booking_products[0] = [];

                                    booking_products[1] = [];

                                    booking_products[2] = [];



                                    // Booking Product

                                    // Action : Create

                                    booking_products[0] = Create_Booking_Products();



                                    var dirty_fields = $('#form').dirty('showDirtyFields');

                                    var admins = <?php echo json_encode($admins) ?>;
                                    var booking_op_admins = <?php echo json_encode($booking_op_admins) ?>;

                                    var sources = <?php echo json_encode($sources) ?>;

                                    var bc_titles = <?php echo json_encode(unserialize(BC_TITLE)) ?>;

                                    var categories = <?php echo json_encode($categories) ?>;

                                    var country_codes = <?php echo json_encode($country_codes) ?>;

                                    // Safe accessor: Object.values(el)[idx] may be undefined when
                                    // PHP-side arrays (admins, country_codes, ...) diverge from the
                                    // rendered DOM option counts (e.g. after filtering deactivated
                                    // salesagents). Calling .hasOwnProperty on undefined throws and
                                    // blows up the entire save flow — wrap the lookup defensively.
                                    // jQuery Dirty plugin stores the initial value as an expando
                                    // `dirtyInitialValue` on the element itself (or on jQuery data).
                                    // Read it directly instead of the old Object.values(el)[idx] magic,
                                    // which broke after $admins was filtered (length mismatch with DOM).
                                    var hasDirtyInitial = function(el) {
                                        if(el == null) return false;
                                        if(el.dirtyInitialValue !== undefined) return true;
                                        try {
                                            var d = $(el).data('dirtyInitialValue');
                                            if(d !== undefined) return true;
                                        } catch(e) {}
                                        return false;
                                    };
                                    var getDirtyInitial = function(el) {
                                        if(el == null) return null;
                                        if(el.dirtyInitialValue !== undefined) return el.dirtyInitialValue;
                                        try {
                                            var d = $(el).data('dirtyInitialValue');
                                            if(d !== undefined) return d;
                                        } catch(e) {}
                                        return null;
                                    };

                                    if(dirty_fields.length > 0) {

                                        for(var i = 0; i < dirty_fields.length; i++) {

                                            if(dirty_fields[i].localName == 'select' || hasDirtyInitial(dirty_fields[i])) {

                                                var key = dirty_fields[i].id == 'kt_datepicker_4_3' || dirty_fields[i].id == 'kt_datepicker_4_4' ? dirty_fields[i].name : dirty_fields[i].id;

                                                var value = dirty_fields[i].id == 'kt_datepicker_4_3' || dirty_fields[i].id == 'kt_datepicker_4_4' ? `${((dirty_fields[i].value).split('/'))[2]}-${((dirty_fields[i].value).split('/'))[1]}-${((dirty_fields[i].value).split('/'))[0]}` : ((dirty_fields[i].id == 'BookingFormText' || dirty_fields[i].id == 'ChatSummary') ? dirty_fields[i].value : (dirty_fields[i].value).toUpperCase());

                                                // Booking

                                                // Action : Update

                                                if(key == 'CountryCodeID' || key == 'ReservationNumber' || key == 'DepositDeadline' || key == 'FullPaymentDeadline' || key == 'Customer' || key == 'Mobile' || key == 'Destination' || key == 'SalesAgent' || key == 'SalesAgent2' || key == 'BookingRemark' || key == 'BookingFormText' || key == 'ChatSummary' || key == 'ChatLanguage' || key == 'Source' || key == 'BookingConfirmationTitle' || key == 'BookingOP') {

                                                    if(key == 'BookingOP' && value == '') {

                                                        value = null;

                                                    }

                                                    if(key == 'SalesAgent2' && value == '') {

                                                        value = null;

                                                    }

                                                    booking[0][key] = value;



                                                    //Insert Booking Log

                                                    var default_value = null;

                                                    switch(key) {

                                                        case 'CountryCodeID':

                                                            default_value = getDirtyInitial(dirty_fields[i]);

                                                            break;

                                                        case 'Destination':

                                                            default_value = getDirtyInitial(dirty_fields[i]);

                                                            break;

                                                        case 'SalesAgent':

                                                            default_value = getDirtyInitial(dirty_fields[i]);

                                                            break;

                                                        case 'SalesAgent2':

                                                            default_value = getDirtyInitial(dirty_fields[i]);

                                                            break;

                                                        case 'BookingOP':

                                                            default_value = getDirtyInitial(dirty_fields[i]);

                                                            break;

                                                        case 'ChatLanguage':

                                                            default_value = getDirtyInitial(dirty_fields[i]);

                                                            break;

                                                        case 'Source':

                                                            default_value = getDirtyInitial(dirty_fields[i]);

                                                            break;

                                                        case 'BookingConfirmationTitle':

                                                            default_value = getDirtyInitial(dirty_fields[i]);

                                                            break;

                                                        default:

                                                            if(key == 'DepositDeadline' || key == 'FullPaymentDeadline') {

                                                                var initial_raw = getDirtyInitial(dirty_fields[i]);
                                                                if(initial_raw) {
                                                                    var date = initial_raw.split('/');
                                                                    default_value = `${date[2]}-${date[1]}-${date[0]}`;
                                                                } else {
                                                                    default_value = null;
                                                                }

                                                            } else {

                                                                default_value = getDirtyInitial(dirty_fields[i]);

                                                            }

                                                    }

                                                    booking_log.push({BookingID:<?php echo $BookingID ?>, Column:key, CurrentData:default_value, NewData:value, InsertBy:<?php echo $this->session->userdata('admin_id') ?>, InsertDate:'<?php echo date('Y-m-d H:i:s') ?>'});

                                                } else {

                                                    if(key == 'TravelDate') {

                                                        var _td_raw = getDirtyInitial(dirty_fields[i]) || '';
                                                        var initial_travel_date = _td_raw.split(' - ');

                                                        var initial_start_date = initial_travel_date[0];

                                                        var initial_end_date = initial_travel_date[1];

                                                        if(value != '') {

                                                            var updated_travel_date = value.split(' - ');

                                                            var updated_start_date = updated_travel_date[0];

                                                            var updated_end_date = updated_travel_date[1];

                                                            if(updated_start_date != initial_start_date) {

                                                                initial_start_date = initial_travel_date[0].split('/');

                                                                updated_start_date = updated_travel_date[0].split('/');

                                                                booking[0]['StartDate'] = `${updated_start_date[2]}-${updated_start_date[1]}-${updated_start_date[0]}`;

                                                                booking_log.push({BookingID:<?php echo $BookingID ?>, Column:'StartDate', CurrentData:`${initial_start_date[2]}-${initial_start_date[1]}-${initial_start_date[0]}`, NewData:`${updated_start_date[2]}-${updated_start_date[1]}-${updated_start_date[0]}`, InsertBy:<?php echo $this->session->userdata('admin_id') ?>, InsertDate:'<?php echo date('Y-m-d H:i:s') ?>'});

                                                            }

                                                            if(updated_end_date != initial_end_date) {

                                                                initial_end_date = initial_travel_date[1].split('/');

                                                                updated_end_date = updated_travel_date[1].split('/');

                                                                booking[0]['EndDate'] = `${updated_end_date[2]}-${updated_end_date[1]}-${updated_end_date[0]}`;

                                                                booking_log.push({BookingID:<?php echo $BookingID ?>, Column:'EndDate', CurrentData:`${initial_end_date[2]}-${initial_end_date[1]}-${initial_end_date[0]}`, NewData:`${updated_end_date[2]}-${updated_end_date[1]}-${updated_end_date[0]}`, InsertBy:<?php echo $this->session->userdata('admin_id') ?>, InsertDate:'<?php echo date('Y-m-d H:i:s') ?>'});

                                                            }

                                                        } else {

                                                            booking[0]['StartDate'] = '';

                                                            booking[0]['EndDate'] = '';

                                                            booking_log.push({BookingID:<?php echo $BookingID ?>, Column:'StartDate', CurrentData:`${initial_start_date[2]}-${initial_start_date[1]}-${initial_start_date[0]}`, NewData:'', InsertBy:<?php echo $this->session->userdata('admin_id') ?>, InsertDate:'<?php echo date('Y-m-d H:i:s') ?>'});

                                                            booking_log.push({BookingID:<?php echo $BookingID ?>, Column:'EndDate', CurrentData:`${initial_end_date[2]}-${initial_end_date[1]}-${initial_end_date[0]}`, NewData:'', InsertBy:<?php echo $this->session->userdata('admin_id') ?>, InsertDate:'<?php echo date('Y-m-d H:i:s') ?>'});

                                                        }

                                                    } else {

                                                        // Booking Product

                                                        // Action : Update

                                                        key = key.split('-');

                                                        if(key[0] == 'ProductID' && hasDirtyInitial(dirty_fields[i]) || key[0] == 'ProductCode' || key[0] == 'Description' || key[0] == 'Quantity' || key[0] == 'Price' || key[0] == 'Total' || key[0] == 'PaymentOutSupplierFull' || key[0] == 'PaymentOutSupplierDeposit') {

                                                            if(key[0] == 'Price' || key[0] == 'Total') {

                                                                value = value.replace(/,/g, '');

                                                            }

                                                            if(key[0] == 'PaymentOutSupplierFull' || key[0] == 'PaymentOutSupplierDeposit') {
                                                                if(value && value != '') {
                                                                    var date_parts = value.split('/');
                                                                    value = `${date_parts[2]}-${date_parts[1]}-${date_parts[0]}`;
                                                                } else {
                                                                    value = null;
                                                                }
                                                            }

                                                            booking_products[1].push({BookingProductID:key[1], [key[0]]:value, UpdateBy:<?php echo $this->session->userdata('admin_id') ?>, UpdateDate:'<?php echo date('Y-m-d H:i:s') ?>'});

                                                            if(key[0] == 'ProductID' && hasDirtyInitial(dirty_fields[i])) {

                                                                var name = $(`#ProductID-${key[1]} option:selected`).text();

                                                                booking_products[1].push({BookingProductID:key[1], Name:name, UpdateBy:<?php echo $this->session->userdata('admin_id') ?>, UpdateDate:'<?php echo date('Y-m-d H:i:s') ?>'});

                                                            }

                                                        }

                                                    }

                                                }

                                            }

                                        }

                                    }


                                    // Collect disable_checklist_payment_out checkbox state for all existing booking products
                                    var existing_bp_ids = <?php echo json_encode(array_map(function($bp) { return $bp->BookingProductID; }, $booking_products ?? [])); ?>;
                                    for(var bp = 0; bp < existing_bp_ids.length; bp++) {
                                        var bp_id = existing_bp_ids[bp];
                                        var is_disabled = $(`#DisableChecklistPaymentOut-${bp_id}`).is(':checked') ? 1 : 0;
                                        booking_products[1].push({BookingProductID:bp_id, disable_checklist_payment_out:is_disabled, UpdateBy:<?php echo $this->session->userdata('admin_id') ?>, UpdateDate:'<?php echo date('Y-m-d H:i:s') ?>'});
                                    }

                                    var CustomerID = $('input[name="CustomerID"]').val();

                                    // Secondary contact (Customer 2 / Mobile 2) — hidden/select fields the
                                    // jQuery Dirty plugin doesn't reliably track, so diff against initial here.
                                    var initial_customer_2 = '<?php echo isset($Customer2) ? addslashes(strtoupper($Customer2)) : ''; ?>';
                                    var initial_customer_id_2 = '<?php echo isset($CustomerID2) ? $CustomerID2 : ''; ?>';
                                    var initial_mobile_2 = '<?php echo isset($CustomerMobile2) ? addslashes($CustomerMobile2) : ''; ?>';
                                    var initial_country_code_id_2 = '<?php echo isset($CustomerCountryCode2) ? $CustomerCountryCode2 : ''; ?>';

                                    var current_customer_2 = ($('#Customer2').val() || '').toUpperCase();
                                    var current_customer_id_2 = $('#CustomerID2').val() || '';
                                    var current_mobile_2 = $('#Mobile2').val() || '';
                                    var current_country_code_id_2 = $('#CountryCodeID2').val() || '';

                                    if (current_customer_2 !== initial_customer_2) {
                                        booking[0]['Customer2'] = current_customer_2 === '' ? null : current_customer_2;
                                        booking_log.push({BookingID:<?php echo $BookingID ?>, Column:'Customer2', CurrentData:initial_customer_2, NewData:current_customer_2, InsertBy:<?php echo $this->session->userdata('admin_id') ?>, InsertDate:'<?php echo date('Y-m-d H:i:s') ?>'});
                                    }
                                    if (current_customer_id_2 !== initial_customer_id_2) {
                                        booking[0]['CustomerID2'] = current_customer_id_2 === '' ? null : current_customer_id_2;
                                    }
                                    if (current_mobile_2 !== initial_mobile_2) {
                                        booking[0]['Mobile2'] = current_mobile_2 === '' ? null : current_mobile_2;
                                        booking_log.push({BookingID:<?php echo $BookingID ?>, Column:'Mobile2', CurrentData:initial_mobile_2, NewData:current_mobile_2, InsertBy:<?php echo $this->session->userdata('admin_id') ?>, InsertDate:'<?php echo date('Y-m-d H:i:s') ?>'});
                                    }
                                    if (current_country_code_id_2 !== initial_country_code_id_2) {
                                        booking[0]['CountryCodeID2'] = current_country_code_id_2 === '' ? null : current_country_code_id_2;
                                        booking_log.push({BookingID:<?php echo $BookingID ?>, Column:'CountryCodeID2', CurrentData:initial_country_code_id_2, NewData:current_country_code_id_2, InsertBy:<?php echo $this->session->userdata('admin_id') ?>, InsertDate:'<?php echo date('Y-m-d H:i:s') ?>'});
                                    }

                                    // Booking

                                    // Action : Update Booking PIC ticks (Sales Agent 1 / 2)
                                    // Checkboxes aren't tracked by the dirty plugin, so diff
                                    // against the initial rendered state. When either tick
                                    // changes, materialise BOTH flags so a legacy booking never
                                    // ends up half-set (which would confuse the fallback logic).
                                    var initial_sa1_pic = '<?php echo $sa1_pic_checked ? 1 : 0; ?>';
                                    var initial_sa2_pic = '<?php echo $sa2_pic_checked ? 1 : 0; ?>';
                                    var current_sa1_pic = $('#SalesAgentIsPIC').is(':checked') ? '1' : '0';
                                    var current_sa2_pic = $('#SalesAgent2IsPIC').is(':checked') ? '1' : '0';
                                    if (current_sa1_pic !== initial_sa1_pic || current_sa2_pic !== initial_sa2_pic) {
                                        booking[0]['SalesAgentIsPIC'] = parseInt(current_sa1_pic);
                                        booking[0]['SalesAgent2IsPIC'] = parseInt(current_sa2_pic);
                                        if (current_sa1_pic !== initial_sa1_pic) {
                                            booking_log.push({BookingID:<?php echo $BookingID ?>, Column:'SalesAgentIsPIC', CurrentData:initial_sa1_pic, NewData:current_sa1_pic, InsertBy:<?php echo $this->session->userdata('admin_id') ?>, InsertDate:'<?php echo date('Y-m-d H:i:s') ?>'});
                                        }
                                        if (current_sa2_pic !== initial_sa2_pic) {
                                            booking_log.push({BookingID:<?php echo $BookingID ?>, Column:'SalesAgent2IsPIC', CurrentData:initial_sa2_pic, NewData:current_sa2_pic, InsertBy:<?php echo $this->session->userdata('admin_id') ?>, InsertDate:'<?php echo date('Y-m-d H:i:s') ?>'});
                                        }
                                    }

                                    // Booking

                                    // Action : Update Tag

                                    var tag = ($('#Tag').val()).toString();

                                    if(tag != '<?php echo implode(',', $Tag); ?>') {

                                        booking[0]['Tag'] = tag;

                                        booking_log.push({BookingID:<?php echo $BookingID ?>, Column:'Tag', CurrentData:'<?php echo implode(',', $Tag); ?>', NewData:tag, InsertBy:<?php echo $this->session->userdata('admin_id') ?>, InsertDate:'<?php echo date('Y-m-d H:i:s') ?>'});

                                    }



                                    // Booking

                                    // Action : Update Discount

                                    var discount = $('#Discount').val();

                                    if(discount != '<?php echo $Discount; ?>') {

                                        booking[0]['Discount'] = discount.replace(/,/g, '');

                                        booking_log.push({BookingID:<?php echo $BookingID ?>, Column:'Discount', CurrentData:'<?php echo $Discount; ?>', NewData:discount.replace(/,/g, ''), InsertBy:<?php echo $this->session->userdata('admin_id') ?>, InsertDate:'<?php echo date('Y-m-d H:i:s') ?>'});

                                    }



                                    // Booking

                                    // Action : Update NetTotal

                                    var net_total = $('#NetTotal').val();

                                    if(net_total != '<?php echo $NetTotal; ?>') {

                                        booking[0]['NetTotal'] = net_total.replace(/,/g, '');

                                        booking_log.push({BookingID:<?php echo $BookingID ?>, Column:'NetTotal', CurrentData:'<?php echo $NetTotal; ?>', NewData:net_total.replace(/,/g, ''), InsertBy:<?php echo $this->session->userdata('admin_id') ?>, InsertDate:'<?php echo date('Y-m-d H:i:s') ?>'});

                                    }



                                    // Booking

                                    // Action : Update DepositPercentage

                                    var deposit_percentage = $('#DepositPercentage').val() != '' ? parseInt($('#DepositPercentage').val()) : 50;

                                    // Use original DB values for comparison (not the displayed values)
                                    var current_deposit_percentage = '<?php echo isset($DepositPercentageOriginal) ? $DepositPercentageOriginal : 0; ?>';

                                    if(deposit_percentage != current_deposit_percentage) {

                                        booking[0]['DepositPercentage'] = deposit_percentage;

                                        booking_log.push({BookingID:<?php echo $BookingID ?>, Column:'DepositPercentage', CurrentData:current_deposit_percentage, NewData:deposit_percentage.toString(), InsertBy:<?php echo $this->session->userdata('admin_id') ?>, InsertDate:'<?php echo date('Y-m-d H:i:s') ?>'});

                                    }

                                    // Action : Update DepositMode

                                    var deposit_mode = $('#DepositMode').val();

                                    var current_deposit_mode = '<?php echo isset($DepositModeOriginal) ? $DepositModeOriginal : "percentage"; ?>';

                                    if(deposit_mode != current_deposit_mode) {

                                        booking[0]['DepositMode'] = deposit_mode;

                                        booking_log.push({BookingID:<?php echo $BookingID ?>, Column:'DepositMode', CurrentData:current_deposit_mode, NewData:deposit_mode, InsertBy:<?php echo $this->session->userdata('admin_id') ?>, InsertDate:'<?php echo date('Y-m-d H:i:s') ?>'});

                                    }

                                    // Action : Update DepositFixedAmount

                                    var deposit_fixed_amount = $('#DepositFixedAmount').val() != '' ? parseFloat($('#DepositFixedAmount').val()) : 0;

                                    var current_deposit_fixed_amount = '<?php echo isset($DepositFixedAmountOriginal) ? $DepositFixedAmountOriginal : 0; ?>';

                                    if(deposit_fixed_amount != parseFloat(current_deposit_fixed_amount)) {

                                        booking[0]['DepositFixedAmount'] = deposit_fixed_amount;

                                        booking_log.push({BookingID:<?php echo $BookingID ?>, Column:'DepositFixedAmount', CurrentData:current_deposit_fixed_amount, NewData:deposit_fixed_amount.toString(), InsertBy:<?php echo $this->session->userdata('admin_id') ?>, InsertDate:'<?php echo date('Y-m-d H:i:s') ?>'});

                                    }



                                    // Booking

                                    // Action : Update Booking Confirmation Footer ID

                                    var booking_confirmation_footer_id = $('#booking_confirmation_footer').val();

                                    if(booking_confirmation_footer_id != '<?php echo $BookingConfirmationFooterID; ?>') {

                                        booking[0]['BookingConfirmationFooterID'] = booking_confirmation_footer_id;

                                        booking_log.push({BookingID:<?php echo $BookingID ?>, Column:'BookingConfirmationFooterID', CurrentData:'<?php echo $BookingConfirmationFooterID; ?>', NewData:booking_confirmation_footer_id, InsertBy:<?php echo $this->session->userdata('admin_id') ?>, InsertDate:'<?php echo date('Y-m-d H:i:s') ?>'});

                                    }



                                    // Booking

                                    // Action : Update Travel Voucher Footer ID

                                    var travel_voucher_footer_id = $('#travel_voucher_footer').val();

                                    if(travel_voucher_footer_id != '<?php echo isset($TravelVoucherFooterID) ? $TravelVoucherFooterID : ''; ?>') {

                                        booking[0]['TravelVoucherFooterID'] = travel_voucher_footer_id;

                                        booking_log.push({BookingID:<?php echo $BookingID ?>, Column:'TravelVoucherFooterID', CurrentData:'<?php echo isset($TravelVoucherFooterID) ? $TravelVoucherFooterID : ''; ?>', NewData:travel_voucher_footer_id, InsertBy:<?php echo $this->session->userdata('admin_id') ?>, InsertDate:'<?php echo date('Y-m-d H:i:s') ?>'});

                                    }



                                    // Booking

                                    // Action : Update Booking Confirmation Footer

                                    var updated_booking_confirmation_footer = Decode_HTML(tinyMCE.editors[0].getContent());

                                    if(updated_booking_confirmation_footer != initial_booking_confirmation_footer) {

                                        booking[0]['BookingConfirmationFooter'] = tinyMCE.editors[0].getContent();

                                        booking_log.push({BookingID:<?php echo $BookingID ?>, Column:'BookingConfirmationFooter', CurrentData:initial_booking_confirmation_footer, NewData:tinyMCE.editors[0].getContent(), InsertBy:<?php echo $this->session->userdata('admin_id') ?>, InsertDate:'<?php echo date('Y-m-d H:i:s') ?>'});

                                    }



                                    // Booking

                                    // Action : Update Travel Voucher Footer

                                    var updated_travel_voucher_footer = Decode_HTML(tinyMCE.editors[1].getContent());

                                    if(updated_travel_voucher_footer != initial_travel_voucher_footer) {

                                        booking[0]['TravelVoucherFooter'] = tinyMCE.editors[1].getContent();

                                        booking_log.push({BookingID:<?php echo $BookingID ?>, Column:'TravelVoucherFooter', CurrentData:initial_travel_voucher_footer, NewData:tinyMCE.editors[1].getContent(), InsertBy:<?php echo $this->session->userdata('admin_id') ?>, InsertDate:'<?php echo date('Y-m-d H:i:s') ?>'});

                                    }



                                    // Booking

                                    // Action : Update Key Contacts

                                    var updated_key_contacts = Decode_HTML(tinyMCE.editors[2].getContent());

                                    if(updated_key_contacts != initial_key_contacts) {

                                        booking[0]['KeyContacts'] = tinyMCE.editors[2].getContent();

                                        booking_log.push({BookingID:<?php echo $BookingID ?>, Column:'KeyContacts', CurrentData:initial_key_contacts, NewData:tinyMCE.editors[2].getContent(), InsertBy:<?php echo $this->session->userdata('admin_id') ?>, InsertDate:'<?php echo date('Y-m-d H:i:s') ?>'});

                                    }



                                    // Booking

                                    // Action : Update Special Remarks

                                    var updated_special_remarks = Decode_HTML(tinyMCE.editors[3].getContent());

                                    if(updated_special_remarks != initial_special_remarks) {

                                        booking[0]['SpecialRemarks'] = tinyMCE.editors[3].getContent();

                                        booking_log.push({BookingID:<?php echo $BookingID ?>, Column:'SpecialRemarks', CurrentData:initial_special_remarks, NewData:tinyMCE.editors[3].getContent(), InsertBy:<?php echo $this->session->userdata('admin_id') ?>, InsertDate:'<?php echo date('Y-m-d H:i:s') ?>'});

                                    }



                                    // Booking

                                    // Action : Update Product Sequence

                                    if(window.location.href.split('?')[0] == '<?php echo base_url('Booking/Update'); ?>') {

                                        if(product_sequence.toString() != '<?php echo implode(',', $ProductSequence); ?>') {

                                            booking[0]['ProductSequence'] = product_sequence.toString();

                                            booking_log.push({BookingID:<?php echo $BookingID ?>, Column:'ProductSequence', CurrentData:'<?php echo implode(',', $ProductSequence); ?>', NewData:product_sequence.toString(), InsertBy:<?php echo $this->session->userdata('admin_id') ?>, InsertDate:'<?php echo date('Y-m-d H:i:s') ?>'});

                                        }

                                    }

                                    

                                    function Decode_HTML(html) {

                                        var plain_text = document.createElement('textarea');

                                        plain_text.innerHTML = html;

                                        return plain_text.value;

                                    }

                                    

                                    // Booking Product

                                    // Action : Delete

                                    for(var i = 0; i < array.length; i++) {

                                        if(!$(`#${array[i]}`).hasOwnProperty('length')) {

                                            booking_products[2].push({BookingProductID:array[i], Status:'N', UpdateBy:<?php echo $this->session->userdata('admin_id') ?>, UpdateDate:'<?php echo date('Y-m-d H:i:s') ?>'});

                                        }

                                    }



                                    count = 0;

                                    $.each(booking[0], function() {

                                        count++;

                                    });

                                    // A draft lifecycle action (Save as Draft / Approve /
                                    // Save as Pending BC[ Confirmation]) is itself a change,
                                    // so always submit even when no field was edited —
                                    // otherwise "no changes detected" would swallow it.
                                    if(__saveMode === '' && count == 3 && booking_products[0].length == 0 && booking_products[1].length == 0 && booking_products[2].length == 0) {

                                        <?php if(current_url() == base_url('Booking/Update')) { ?>

                                            <?php 

                                                $current_url = base_url($_SERVER['REQUEST_URI']);

                                                if(!empty($this->input->get('booking_number')) || !empty($this->input->get('reservation_number')) || !empty($this->input->get('deadline')) || !empty($this->input->get('customer')) || !empty($this->input->get('mobile')) || !empty($this->input->get('travel_date')) || !empty($this->input->get('destination')) || !empty($this->input->get('sales_agent')) || !empty($this->input->get('sales_agent_2')) || !empty($this->input->get('tag')) || !empty($this->input->get('chat_language')) || !empty($this->input->get('source')) || !empty($this->input->get('booking_confirmation_title')) || !empty($this->input->get('status')) || !empty($this->input->get('booking_date'))) {

                                                    $url = base_url('Booking?') . (explode('?booking_id=' . $this->input->get('booking_id') . '&', $current_url))[1];

                                                } else {

                                                    $url = base_url('Booking');

                                                }

                                            ?>

                                            Display_Message('<?php echo base_url('assets/image/sweetalert.jpg') ?>', '<?php echo 'No Changes Detected In Booking Record : ' . $BookingNumber; ?>', '<?php echo $url ?>');

                                        <?php } ?>

                                    } else {

                                        Submit_Booking('<?php echo base_url('Booking/Update') ?>', booking[0].BookingID, null, booking, booking_log, booking_products, CustomerID);

                                    }

                                }

                            }

                        }

                }

            }

        });

        };

        // Soft gate: if the chosen Destination (category) defines expected
        // products that this booking does not fully cover, warn first and let the
        // user proceed anyway. Skipped for draft saves (handled inside the helper).
        const proceedToConfirm = () => {
            var __coverageGap = Booking_Coverage_Gap();
            if (__coverageGap.length > 0) {
                swalWithBootstrapButtons.fire({
                    width: 550,
                    background: 'url(<?php echo base_url('assets/image/sweetalert.jpg') ?>)',
                    icon: 'warning',
                    title: 'Incomplete Destination Products',
                    html: 'These products are defined for this destination but are not in the booking:<br><br><strong>'
                        + __coverageGap.join('<br>')
                        + '</strong><br><br>Proceed anyway?',
                    confirmButtonText: 'Proceed Anyway',
                    cancelButtonText: 'Cancel',
                    showCancelButton: true
                }).then((action) => {
                    if (action.isConfirmed) {
                        runBookingSave();
                    }
                });
            } else {
                runBookingSave();
            }
        };

        // Pre-submit duplicate-customer check: only when no CustomerID is bound
        // (i.e. the agent typed a name manually) and a mobile is present. Matching
        // is by PHONE only (normalised) — a namesake with a different phone is a
        // different person, so name is not part of the check.
        const _custName = ($('#Customer').val() || '').trim();
        const _mobile   = ($('#Mobile').val() || '').trim();
        const _custId   = $('#CustomerID').val();

        if (!_custId && _mobile) {
            $.ajax({
                url: '<?= base_url('customer/check_duplicate'); ?>',
                type: 'GET',
                data: { phone: _mobile },
                dataType: 'json'
            }).done(function(matches) {
                if (matches && matches.length > 0) {
                    const m = matches[0];
                    const esc = function(s) { return $('<div>').text(s == null ? '' : String(s)).html(); };
                    // Hard block: a customer with this phone already exists, so no
                    // duplicate will be created. The booking is bound to the
                    // existing customer (the server reuses it either way).
                    Swal.fire({
                        icon: 'warning',
                        title: 'Existing Customer Found',
                        html: 'A customer with this phone number already exists:<br><br>'
                            + '<strong>' + esc(m.name) + '</strong><br>'
                            + 'Code: <strong>' + esc(m.CustomerCode || '—') + '</strong><br>'
                            + 'Phone: ' + esc(m.phone_number)
                            + '<br><br>Use it for this booking, or create a new customer anyway?',
                        showCancelButton: true,
                        showDenyButton: true,
                        confirmButtonText: 'Use existing customer',
                        denyButtonText: 'Create new customer anyway',
                        cancelButtonText: 'Back to edit',
                        customClass: {
                            confirmButton: 'btn btn-light-success m-2',
                            denyButton: 'btn btn-light-primary m-2',
                            cancelButton: 'btn btn-secondary m-2'
                        },
                        buttonsStyling: true
                    }).then(function(res) {
                        if (res.isConfirmed) {
                            $('#force_new_customer').val('');
                            $('#CustomerID').val(m.CustomerID);
                            $('#Customer').val(m.name);
                            $('#Customer').data('selectedCode', m.CustomerCode || '');
                            $('#customerInfo')
                                .removeClass('text-muted text-primary')
                                .addClass('text-success')
                                .html('<i class="la la-check-circle"></i> Existing Customer — <strong>' + esc(m.CustomerCode || 'Empty Customer Code ') + '</strong> (' + esc(m.phone_number || 'No phone number') + ')');
                            proceedToConfirm();
                        } else if (res.isDenied) {
                            // Keep CustomerID blank and flag the server to force-create a
                            // brand-new customer despite the same-phone match.
                            $('#CustomerID').val('');
                            $('#force_new_customer').val('1');
                            proceedToConfirm();
                        }
                    });
                } else {
                    proceedToConfirm();
                }
            }).fail(function() {
                proceedToConfirm();
            });
        } else {
            proceedToConfirm();
        }

    });



    function Submit_Booking(url, booking_id, booking_number, booking, booking_log, booking_products, CustomerID, booking_rooms)

    {

        var postData = {

                booking_id: booking_id,

                booking_number : booking_number,

                booking: booking,

                booking_log: booking_log,

                booking_products: booking_products,

                CustomerID: CustomerID,

                ic_passport_no: ($('#ic_passport_no').val() || '').toUpperCase(),

                tin_no: ($('#tin_no').val() || '').toUpperCase(),

                AltName: ($('#AltName').val() || '').toUpperCase(),

                PrimaryEmail: ($('#Email').val() || '').trim(),

                customer_type: $('#customer_type').val() || [],

                force_new_customer: $('#force_new_customer').val() || '',

            };

        if (booking_rooms && booking_rooms.length > 0) {
            postData.booking_rooms = booking_rooms;
        }

        // Draft toggle (Create page only). Reads the checkbox beside the Create
        // button so the controller can park the new booking in SAD
        // ("SAVE AS DRAFT") status.
        if ($('#is_draft_intake_toggle').length && $('#is_draft_intake_toggle').is(':checked')) {
            postData.is_draft_intake = '1';
        }

        // Draft save mode set by the Save as Draft / Approve / Save as Pending BC /
        // Pending BC Confirmation buttons (draft bookings only). Empty for a normal save.
        var draft_save_mode = $('#draft_save_mode').length ? $('#draft_save_mode').val() : '';
        if (draft_save_mode) {
            postData.draft_save_mode = draft_save_mode;
        }

        // Supplier invoices can be attached with a file only — supplier / invoice
        // number / amount are all optional now, so there is nothing to validate
        // before saving. Blank rows are skipped by Collect_Supplier_Invoices().
        if (typeof Collect_Supplier_Invoices === 'function') {
            var supplier_invoices_payload = Collect_Supplier_Invoices();
            if (supplier_invoices_payload && (supplier_invoices_payload[0].length || supplier_invoices_payload[1].length || supplier_invoices_payload[2].length)) {
                postData.booking_supplier_invoices = supplier_invoices_payload;
            }
        }

        $.ajax({

            url: url,

            type: 'post',

            data: postData,

            success: function() {

                var base_url = '<?php echo base_url('Booking') ?>';

                if(window.location.href.split('?')[0] == '<?php echo base_url('Booking/Update'); ?>' || window.location.href.split('?')[0] == '<?php echo base_url('Booking/Duplicate'); ?>') {

                    <?php if(!empty($this->input->get('booking_number')) || !empty($this->input->get('reservation_number')) || !empty($this->input->get('deadline')) || !empty($this->input->get('customer')) || !empty($this->input->get('mobile')) || !empty($this->input->get('travel_date')) || !empty($this->input->get('destination')) || !empty($this->input->get('sales_agent')) || !empty($this->input->get('sales_agent_2')) || !empty($this->input->get('tag')) || !empty($this->input->get('chat_language')) || !empty($this->input->get('source')) || !empty($this->input->get('booking_confirmation_title')) || !empty($this->input->get('status')) || !empty($this->input->get('booking_date'))) { ?>

                        var url = base_url + '?' + window.location.href.split('?booking_id=' + <?php echo $this->input->get('booking_id') ?> + '&')[1];

                    <?php } else { ?>

                        var url = base_url;

                    <?php } ?>

                } else {

                    <?php if(!empty($this->input->get('booking_number')) || !empty($this->input->get('reservation_number')) || !empty($this->input->get('deadline')) || !empty($this->input->get('customer')) || !empty($this->input->get('mobile')) || !empty($this->input->get('travel_date')) || !empty($this->input->get('destination')) || !empty($this->input->get('sales_agent')) || !empty($this->input->get('sales_agent_2')) || !empty($this->input->get('tag')) || !empty($this->input->get('chat_language')) || !empty($this->input->get('source')) || !empty($this->input->get('booking_confirmation_title')) || !empty($this->input->get('status')) || !empty($this->input->get('booking_date'))) { ?>

                        var url = base_url + '?' + window.location.href.split('?')[1];

                    <?php } else { ?>

                        var url = base_url;

                    <?php } ?>

                }

                Display_Message('<?php echo base_url('assets/image/sweetalert.jpg') ?>', '<?php if(current_url() == base_url('Booking/Create') || current_url() == base_url('Booking/Duplicate')) { echo 'New'; } ?> Booking Record <?php if(current_url() == base_url('Booking/Update')) { echo ': ' . $BookingNumber; } ?> Successfully <?php if(current_url() == base_url('Booking/Create') || current_url() == base_url('Booking/Duplicate')) { echo 'Created'; } else { echo 'Updated'; } ?>', url);

            },

            error: function() {

                Display_Message('<?php echo base_url('assets/image/sweetalert.jpg') ?>', '<?php if(current_url() == base_url('Booking/Create') || current_url() == base_url('Booking/Duplicate')) { echo 'New'; } ?> Booking Record <?php if(current_url() == base_url('Booking/Update')) { echo ': ' . $BookingNumber; } ?> Could Not Be <?php if(current_url() == base_url('Booking/Create') || current_url() == base_url('Booking/Duplicate')) { echo 'Created'; } else { echo 'Updated'; } ?>', null);

            }

        });

    }



    if(window.location.href.split('?')[0] == '<?php echo base_url('Booking/Update'); ?>' || window.location.href.split('?')[0] == '<?php echo base_url('Booking/Duplicate'); ?>') {

        for(var i = 0; i < booking_products.length; i++) {

            var product_id = booking_products[i].ProductID;

            var product_code = booking_products[i].ProductCode;

            var description = booking_products[i].Description;

            var quantity = booking_products[i].Quantity;

            var price = booking_products[i].Price;

            var total = booking_products[i].Total;

            $('#create_booking_product').click();

            if(window.location.href.split('?')[0] == '<?php echo base_url('Booking/Update'); ?>') {

                $(`#ProductID-${booking_products[i].BookingProductID}`).val(product_id);

                $(`#ProductCode-${booking_products[i].BookingProductID}`).val(product_code);

                $(`#Description-${booking_products[i].BookingProductID}`).val(description);

                $(`#Description-${booking_products[i].BookingProductID}`).removeAttr('disabled');

                $(`#Quantity-${booking_products[i].BookingProductID}`).val(quantity);

                $(`#Quantity-${booking_products[i].BookingProductID}`).removeAttr('disabled');

                $(`#Price-${booking_products[i].BookingProductID}`).val(price);

                $(`#Price-${booking_products[i].BookingProductID}`).removeAttr('disabled');

                $(`#Total-${booking_products[i].BookingProductID}`).val(total);

                // Set disable checklist checkbox state
                $(`#DisableChecklistPaymentOut-${booking_products[i].BookingProductID}`).removeAttr('disabled');
                if(booking_products[i].disable_checklist_payment_out == 1) {
                    $(`#DisableChecklistPaymentOut-${booking_products[i].BookingProductID}`).prop('checked', true);
                    $(`#PaymentOutSection-${booking_products[i].BookingProductID}`).hide();
                }

                if(booking_products[i].PaymentOutSupplierFull) {
                    $(`#PaymentOutSupplierFull-${booking_products[i].BookingProductID}`).val(booking_products[i].PaymentOutSupplierFull);
                }
                if(booking_products[i].PaymentOutSupplierDeposit) {
                    $(`#PaymentOutSupplierDeposit-${booking_products[i].BookingProductID}`).val(booking_products[i].PaymentOutSupplierDeposit);
                }
                $(`#PaymentOutSupplierFull-${booking_products[i].BookingProductID}`).removeAttr('disabled');
                $(`#PaymentOutSupplierDeposit-${booking_products[i].BookingProductID}`).removeAttr('disabled');
                $(`#PaymentOutSupplierFull-${booking_products[i].BookingProductID}`).datepicker({format: 'dd/mm/yyyy', autoclose: true, todayHighlight: true});
                $(`#PaymentOutSupplierDeposit-${booking_products[i].BookingProductID}`).datepicker({format: 'dd/mm/yyyy', autoclose: true, todayHighlight: true});

                if((i + 1) < booking_products.length) {

                    booking_product_id = parseInt(booking_products[i + 1].BookingProductID);

                } else {

                    booking_product_id = parseInt(<?php echo $BookingProductID; ?>);

                }

            } else {

                $(`#ProductID-${booking_product_id - 1}`).val(product_id);

                $(`#ProductCode-${booking_product_id - 1}`).val(product_code);

                $(`#Description-${booking_product_id - 1}`).val(description);

                $(`#Description-${booking_product_id - 1}`).removeAttr('disabled');

                $(`#Quantity-${booking_product_id - 1}`).val(quantity);

                $(`#Quantity-${booking_product_id - 1}`).removeAttr('disabled');

                $(`#Price-${booking_product_id - 1}`).val(price);

                $(`#Price-${booking_product_id - 1}`).removeAttr('disabled');

                $(`#Total-${booking_product_id - 1}`).val(total);

                // Set disable checklist checkbox state for Duplicate
                $(`#DisableChecklistPaymentOut-${booking_product_id - 1}`).removeAttr('disabled');
                if(booking_products[i].disable_checklist_payment_out == 1) {
                    $(`#DisableChecklistPaymentOut-${booking_product_id - 1}`).prop('checked', true);
                    $(`#PaymentOutSection-${booking_product_id - 1}`).hide();
                }

                if(booking_products[i].PaymentOutSupplierFull) {
                    $(`#PaymentOutSupplierFull-${booking_product_id - 1}`).val(booking_products[i].PaymentOutSupplierFull);
                }
                if(booking_products[i].PaymentOutSupplierDeposit) {
                    $(`#PaymentOutSupplierDeposit-${booking_product_id - 1}`).val(booking_products[i].PaymentOutSupplierDeposit);
                }
                $(`#PaymentOutSupplierFull-${booking_product_id - 1}`).removeAttr('disabled');
                $(`#PaymentOutSupplierDeposit-${booking_product_id - 1}`).removeAttr('disabled');
                $(`#PaymentOutSupplierFull-${booking_product_id - 1}`).datepicker({format: 'dd/mm/yyyy', autoclose: true, todayHighlight: true});
                $(`#PaymentOutSupplierDeposit-${booking_product_id - 1}`).datepicker({format: 'dd/mm/yyyy', autoclose: true, todayHighlight: true});

            }

        }

    }



    $('#form').dirty('isClean');



    // Override Metronic's global KTCardDraggable (from assets/js/pages/features/cards/draggable.js)
    // to prevent Sortable from initializing on this page. The shipped draggable.bundle.js has a
    // MutationObserver flush race that throws "Cannot read properties of undefined (reading
    // 'hasOwnProperty')" during save, which poisons unrelated promise chains.
    var KTCardDraggable = function() {

		var instance = null;
		var tracked_count = 0;
		var pending = false;
		return {

			init: function() {

				var containers = document.querySelectorAll('.draggable-zone');

				if(containers.length === 0) {

					return false;

				}

				if(instance && tracked_count === containers.length) {

					return;

				}

				if(instance) {

					try { instance.destroy(); } catch(e) {}

					instance = null;

				}

				if(pending) { return; }
				pending = true;

				var schedule = (typeof window.requestAnimationFrame === 'function') ? window.requestAnimationFrame : function(cb) { return setTimeout(cb, 16); };

				schedule(function() {

					pending = false;

					try {

						var current = document.querySelectorAll('.draggable-zone');

						if(current.length === 0) { return; }

						instance = new Sortable.default(current, {

							draggable: '.draggable',

							handle: '.draggable .draggable-handle',

							mirror: {

								appendTo: 'body',

								constrainDimensions: true

							}

						});

						tracked_count = current.length;

					} catch(e) {

						instance = null;
						tracked_count = 0;

					}

				});

			}

		};

	}();

	jQuery(document).ready(function() {

		KTCardDraggable.init();

	});

	// Swallow known Sortable/draggable MutationObserver flush race so it cannot
	// poison unrelated save-flow promise chains.
	window.addEventListener('unhandledrejection', function(e) {

		var reason = e.reason;
		var msg = reason && reason.message ? String(reason.message) : String(reason || '');

		if(msg.indexOf("hasOwnProperty") !== -1) {

			e.preventDefault();

		}

	});
	window.addEventListener('error', function(e) {

		var msg = e && e.message ? String(e.message) : '';
		var src = e && e.filename ? String(e.filename) : '';

		if(msg.indexOf("hasOwnProperty") !== -1 && src.indexOf('draggable.bundle.js') !== -1) {

			e.preventDefault();

		}

	});

</script>

<script>
    function validateLength(inputId, errorId, maxLength, onlyNumber = false) {
        const input = document.getElementById(inputId);
        const error = document.getElementById(errorId);

        if (!input) return; // safety check

        input.addEventListener('input', function () {
            let value = this.value;

            // Numbers only
            if (onlyNumber) {
                value = value.replace(/\D/g, '');
            }

            // Length validation
            if (value.length > maxLength) {
                error.textContent = `Length can't be more than ${maxLength} characters`;
                value = value.substring(0, maxLength);
            } else {
                error.textContent = '';
            }

            this.value = value;
        });
    }

    // Run when page is fully loaded
    document.addEventListener('DOMContentLoaded', function () {
        validateLength('ReservationNumber', 'ReservationNumberError', 20);
        validateLength('Mobile', 'MobileError', 25, true);
    });

// $(document).ready(function() {
//     // Hardcoded customer list (simulate DB)
//     const customers = [
//         "Alice Travel",
//         "Bob Adventures",
//         "Charlie Holiday",
//         "Delta Tours",
//         "Echo Getaway",
//         "Foxtrot Trips",
//         "Golf Tours",
//         "Hotel Booking Co",
//         "India Travels",
//         "Juliet Vacations",
//         "Kilo Holidays",
//         "Lima Adventures",
//         "Mike Travel",
//         "November Trips",
//         "Oscar Getaway",
//         "Papa Tours",
//         "Quebec Holidays",
//         "Romeo Travels",
//         "Sierra Adventures",
//         "Tango Tours",
//         "Uniform Getaway",
//         "Victor Holidays",
//         "Whiskey Travels",
//         "Xray Adventures",
//         "Yankee Tours",
//         "Zulu Getaway",
//         "Extra Travel 1",
//         "Extra Travel 2",
//         "Extra Travel 3",
//         "Extra Travel 4",
//         "Extra Travel 5"
//     ];

//     const MAX_DISPLAY = 5; // show first 30 items
//     const container = $('#customerResults');

//     // Function to render results using DocumentFragment
//     function showResults(filtered) {
//         container.empty();
//         if (filtered.length === 0) {
//             container.hide();
//             return;
//         }

//         // Create a fragment
//         const fragment = $(document.createDocumentFragment());
//         filtered.slice(0, MAX_DISPLAY).forEach(r => {
//             fragment.append(`<button type="button" class="list-group-item list-group-item-action">${r}</button>`);
//         });

//         container.append(fragment);
//         container.show();
//     }

//     // Click/focus shows first 30 items
//     $('#Customer').on('focus click', function() {
//         showResults(customers);
//     });

//     // Filter while typing
//     $('#Customer').on('input', function() {
//         const query = $(this).val().toLowerCase();
//         if (query === "") {
//             showResults(customers);
//             return;
//         }
//         const filtered = customers.filter(c => c.toLowerCase().includes(query));
//         showResults(filtered);
//     });

//     // Select an item
//     $(document).on('click', '#customerResults button', function() {
//         $('#Customer').val($(this).text());
//         container.hide();
//     });

//     // Hide dropdown when clicking outside
//     $(document).click(function(e) {
//         if (!$(e.target).closest('#Customer, #customerResults').length) {
//             container.hide();
//         }
//     });
// });

$(document).ready(function() {
    const MAX_DISPLAY = 5; // visible items in dropdown
    const MAX_CUSTOMER = 'INFINITE';
    const container = $('#customerResults');
    const hiddenCustomerId = $('#CustomerID');
    const infoSpan = $('#customerInfo'); // new info span

    // Function to render results
    function showResults(customers) {
        container.empty();
        if (customers.length === 0) {
            container.hide();
            return;
        }

        const fragment = $(document.createDocumentFragment());
        customers.forEach(c => {
            fragment.append(`
                <button type="button"
                    class="list-group-item list-group-item-action"
                    data-id="${c.CustomerID}"
                    data-name="${c.name}"
                    data-phone="${c.phone_number}"
                    data-altname="${c.AltName ?? ''}"
                    data-code="${c.CustomerCode ?? ''}">
                    ${c.name} (${c.phone_number})${c.CustomerCode ? ' - ' + c.CustomerCode : ''}
                </button>
            `);
        });
        container.append(fragment);
        container.show();

        // Fix height for MAX_DISPLAY
        const itemHeight = container.find('button').first().outerHeight() || 40;
        container.css({
            'max-height': itemHeight * MAX_DISPLAY + 'px',
            'overflow-y': 'auto'
        });
    }

    // Fetch from backend
    function fetchCustomers(query = '') {
        $.ajax({
            url: "<?= base_url('customer/search'); ?>",
            type: "GET",
            data: { q: query, limit: MAX_CUSTOMER },
            dataType: "json",
            success: function(data) {
                showResults(data);
            }
        });
    }

    // Show top items on focus/click
    $('#Customer').on('focus click', function() {
        fetchCustomers('');
    });

    // Filter while typing
    $('#Customer').on('input', function() {
        const query = $(this).val();
        hiddenCustomerId.val(''); // clear selection if typing
        $('#Customer').data('selectedCode', ''); // forget previously-appended code
        fetchCustomers(query);
        updateInfo(false); // reset to new customer message
    });

    // Click to select existing customer
    $(document).on('click', '#customerResults button', function() {
        const name = $(this).data('name');
        const id = $(this).data('id');
        const code = $(this).data('code');
        const phone = $(this).data('phone');
        const altName = $(this).data('altname');

        $('#Customer').val(name);
        $('#Customer').data('selectedCode', code || '');
        $('#AltName').val(altName || '');
        hiddenCustomerId.val(id);
        container.hide();

        updateInfo(true, { code, phone });
    });

    // Hide dropdown when clicking outside
    $(document).click(function(e) {
        if (!$(e.target).closest('#Customer, #customerResults').length) {
            container.hide();
        }
    });

    // Update info span
    function updateInfo(isExisting, data = {}) {
        if (isExisting) {
            infoSpan
                .removeClass('text-muted text-primary')
                .addClass('text-success')
                .html(`<i class="la la-check-circle"></i> Existing Customer — <strong>${data.code || 'Empty Customer Code '}</strong> (${data.phone || 'No phone number'})`);
        } else {
            infoSpan
                .removeClass('text-success')
                .addClass('text-muted')
                .html('&laquo; New Customer &raquo;');
        }
    }

    // Initial setup (for update page)
    if (hiddenCustomerId.val()) {
        updateInfo(true, {
            code: "<?= isset($CustomerCode) ? $CustomerCode : 'N/A'; ?>",
            phone: "<?= isset($CustomerMobile) ? $CustomerMobile : 'N/A'; ?>"
        });
        // Prime selectedCode from PHP-rendered data attr so submit-strip works on Update/Duplicate
        const $cust = $('#Customer');
        const initialCode = $cust.attr('data-selected-code') || '';
        if (initialCode) $cust.data('selectedCode', initialCode);
    } else {
        updateInfo(false);
    }

    // Strip the " - CODE" suffix from Customer before form submit so DB stores clean name.
    // Runs only when an existing customer was selected (CustomerID set) and we know the exact code.
    $('form').on('submit', function() {
        const $cust = $('#Customer');
        const code = $cust.data('selectedCode');
        const id = hiddenCustomerId.val();
        if (id && code) {
            const suffix = ' - ' + code;
            const v = $cust.val();
            if (v.endsWith(suffix)) {
                $cust.val(v.slice(0, -suffix.length));
            }
        }
    });

    // --- Customer 2 autocomplete (optional secondary contact) ---
    const container2 = $('#customerResults2');
    const hiddenCustomerId2 = $('#CustomerID2');
    const infoSpan2 = $('#customerInfo2');

    function showResults2(customers) {
        container2.empty();
        if (customers.length === 0) { container2.hide(); return; }
        const fragment = $(document.createDocumentFragment());
        customers.forEach(c => {
            fragment.append(`
                <button type="button"
                    class="list-group-item list-group-item-action"
                    data-id="${c.CustomerID}"
                    data-name="${c.name}"
                    data-phone="${c.phone_number}"
                    data-code="${c.CustomerCode ?? ''}">
                    ${c.name} (${c.phone_number})
                </button>
            `);
        });
        container2.append(fragment);
        container2.show();
        const itemHeight = container2.find('button').first().outerHeight() || 40;
        container2.css({ 'max-height': itemHeight * MAX_DISPLAY + 'px', 'overflow-y': 'auto' });
    }

    function fetchCustomers2(query = '') {
        $.ajax({
            url: "<?= base_url('customer/search'); ?>",
            type: "GET",
            data: { q: query, limit: MAX_CUSTOMER },
            dataType: "json",
            success: function(data) { showResults2(data); }
        });
    }

    $('#Customer2').on('focus click', function() { fetchCustomers2(''); });

    $('#Customer2').on('input', function() {
        const query = $(this).val();
        hiddenCustomerId2.val('');
        fetchCustomers2(query);
        updateInfo2(false);
    });

    $(document).on('click', '#customerResults2 button', function() {
        const name = $(this).data('name');
        const id = $(this).data('id');
        const code = $(this).data('code');
        const phone = $(this).data('phone');
        $('#Customer2').val(name);
        hiddenCustomerId2.val(id);
        container2.hide();
        updateInfo2(true, { code, phone });
    });

    $(document).click(function(e) {
        if (!$(e.target).closest('#Customer2, #customerResults2').length) {
            container2.hide();
        }
    });

    function updateInfo2(isExisting, data = {}) {
        if (isExisting) {
            infoSpan2
                .removeClass('text-muted text-primary')
                .addClass('text-success')
                .html(`<i class="la la-check-circle"></i> Existing Customer — <strong>${data.code || 'Empty Customer Code '}</strong> (${data.phone || 'No phone number'})`);
        } else {
            infoSpan2
                .removeClass('text-success')
                .addClass('text-muted')
                .html('&laquo; New Customer &raquo;');
        }
    }

    if (hiddenCustomerId2.val()) {
        updateInfo2(true, {
            code: "<?= isset($CustomerCode2) ? $CustomerCode2 : 'N/A'; ?>",
            phone: "<?= isset($CustomerMobile2) ? $CustomerMobile2 : 'N/A'; ?>"
        });
    } else {
        updateInfo2(false);
    }

    // Quick Update Allow Review Button
    <?php if(current_url() == base_url('Booking/Update')) { ?>
    $('#save_slow_conversion_reasons_btn').on('click', function() {
        var $btn = $(this);
        var reasonIds = $('#slow_conversion_reasons_select').val() || [];
        var originalText = $btn.html();
        $btn.prop('disabled', true).html('<i class="la la-spinner la-spin"></i> Saving...');

        $.ajax({
            url: '<?php echo base_url('Booking/UpdateSlowConversionReasons'); ?>',
            type: 'post',
            dataType: 'json',
            data: {
                booking_id: <?php echo $BookingID; ?>,
                reason_ids: reasonIds
            },
            success: function(resp) {
                $btn.prop('disabled', false).html(originalText);
                if(resp && resp.success) {
                    Display_Message('<?php echo base_url('assets/image/sweetalert.jpg') ?>', 'Slow Conversion Reasons Successfully Saved', window.location.href);
                } else {
                    Display_Message('<?php echo base_url('assets/image/sweetalert.jpg') ?>', (resp && resp.message) ? resp.message : 'Slow Conversion Reasons Could Not Be Saved', null);
                }
            },
            error: function() {
                $btn.prop('disabled', false).html(originalText);
                Display_Message('<?php echo base_url('assets/image/sweetalert.jpg') ?>', 'Slow Conversion Reasons Could Not Be Saved', null);
            }
        });
    });

    $('#update_allow_review_btn').on('click', function() {
        var allowReview = $('#AllowReview').val();
        var bookingId = <?php echo $BookingID; ?>;
        var currentAllowReview = '<?php echo isset($AllowReview) && $AllowReview !== null ? $AllowReview : 1; ?>';

        console.log('allowReview: ', allowReview);
        console.log('currentAllowReview: ', currentAllowReview);
        
        // Check if value changed
        if(allowReview == currentAllowReview) {
            Display_Message('<?php echo base_url('assets/image/sweetalert.jpg') ?>', 'No changes detected for Allow Review', null);
            return;
        }

        // Disable button during update
        var $btn = $(this);
        var originalText = $btn.html();
        $btn.prop('disabled', true).html('<i class="la la-spinner la-spin"></i> Updating...');

        // Prepare booking data with only AllowReview
        var booking = [{
            BookingID: bookingId,
            AllowReview: parseInt(allowReview),
            UpdateBy: <?php echo $this->session->userdata('admin_id'); ?>,
            UpdateDate: '<?php echo date('Y-m-d H:i:s'); ?>'
        }];

        // Prepare booking log
        var booking_log = [{
            BookingID: bookingId,
            Column: 'AllowReview',
            CurrentData: currentAllowReview,
            NewData: allowReview,
            InsertBy: <?php echo $this->session->userdata('admin_id'); ?>,
            InsertDate: '<?php echo date('Y-m-d H:i:s'); ?>'
        }];

        $.ajax({
            url: '<?php echo base_url('Booking/UpdateAllowReview'); ?>',
            type: 'post',
            data: {
                booking_id: bookingId,
                booking: booking,
                booking_log: booking_log,
                allow_review: allowReview,
                CustomerID: $('input[name="CustomerID"]').val() || ''
            },
            success: function() {
                $btn.prop('disabled', false).html(originalText);
                // Get current URL to reload after success
                var currentUrl = window.location.href;
                Display_Message('<?php echo base_url('assets/image/sweetalert.jpg') ?>', 'Allow Review Successfully Updated', currentUrl);
            },
            error: function(xhr, status, error) {
                $btn.prop('disabled', false).html(originalText);
                console.error('Update Error:', error);
                Display_Message('<?php echo base_url('assets/image/sweetalert.jpg') ?>', 'Allow Review Could Not Be Updated', null);
            }
        });
    });
    <?php } ?>

    // Auto-resize Customer Review textarea
    function autoResizeTextarea() {
        var textarea = $('#CustomerReview');
        if (textarea.length) {
            // Reset height to auto to get the correct scrollHeight
            textarea.css('height', 'auto');
            // Set height to scrollHeight to fit all content
            textarea.css('height', textarea[0].scrollHeight + 'px');
        }
    }

    // Run on page load
    autoResizeTextarea();

    // Also run after a short delay to ensure content is fully loaded
    setTimeout(autoResizeTextarea, 100);

    // Internal Comments functionality
    <?php if(current_url() == base_url('Booking/Update')) { ?>
    var bookingId = <?php echo $BookingID; ?>;

    // Load customer remarks on page load
    function loadCustomerRemarks() {
        $.ajax({
            url: '<?php echo base_url('Booking/Get_Customer_Remarks'); ?>',
            type: 'get',
            data: { booking_id: bookingId },
            dataType: 'json',
            success: function(response) {
                var remarksList = $('#customer-remarks-list');
                remarksList.empty();

                if (response.success && response.remarks && response.remarks.length > 0) {
                    response.remarks.forEach(function(remark) {
                        // Get first letter for avatar color
                        var avatarColor = ['primary', 'success', 'info', 'warning', 'danger'][remark.commenter_name.charCodeAt(0) % 5];
                        
                        var remarkHtml = '<div class="comment-item d-flex mb-2 mx-2 pb-2 pl-1" style="border-bottom: 1px solid #e4e6eb;">' +
                            // Avatar
                            '<div class="flex-shrink-0 mr-2">' +
                            '<div class="symbol symbol-32 symbol-circle symbol-light-' + avatarColor + '">' +
                            '<span class="symbol-label font-weight-bold" style="font-size: 0.75rem;">' + (remark.commenter_initials || remark.commenter_name.substring(0, 2).toUpperCase()) + '</span>' +
                            '</div>' +
                            '</div>' +
                            // Comment content
                            '<div class="flex-grow-1" style="min-width: 0;">' +
                            '<div class="d-flex align-items-baseline mb-1">' +
                            '<strong class="mr-2" style="font-size: 0.8125rem; color: #050505;">' + escapeHtml(remark.commenter_name) + '</strong>' +
                            '<span class="text-muted" style="font-size: 0.75rem; color: #65676b;">' + remark.created_at + (remark.created_at_relative ? ' <span style="margin: 0 4px;">•</span> ' + remark.created_at_relative : '') + '</span>' +
                            '</div>' +
                            '<div class="comment-text" style="font-size: 0.8125rem; color: #050505; line-height: 1.3; white-space: pre-wrap; word-wrap: break-word;">' + escapeHtml(remark.content) + '</div>' +
                            '</div>' +
                            '</div>';
                        remarksList.append(remarkHtml);
                    });
                } else {
                    remarksList.html('<div class="text-center text-muted py-3" style="font-size: 0.8125rem; color: #65676b;">No customer remarks yet.</div>');
                }
            },
            error: function() {
                $('#customer-remarks-list').html('<div class="text-center text-danger py-3" style="font-size: 0.8125rem;">Error loading customer remarks. Please refresh the page.</div>');
            }
        });
    }

    // Load comments on page load
    function loadComments() {
        $.ajax({
            url: '<?php echo base_url('Booking/Get_Remarks'); ?>',
            type: 'get',
            data: { booking_id: bookingId },
            dataType: 'json',
            success: function(response) {
                var commentsList = $('#internal-comments-list');
                commentsList.empty();

                if (response.success && response.remarks && response.remarks.length > 0) {
                    response.remarks.forEach(function(remark) {
                        // Get first letter for avatar color
                        var avatarColor = ['primary', 'success', 'info', 'warning', 'danger'][remark.commenter_name.charCodeAt(0) % 5];

                        var commentHtml = '<div class="comment-item d-flex mb-2 mx-2 pb-2 pl-1" style="border-bottom: 1px solid #e4e6eb; position: relative;">' +
                            // Avatar
                            '<div class="flex-shrink-0 mr-2">' +
                            '<div class="symbol symbol-32 symbol-circle symbol-light-' + avatarColor + '">' +
                            '<span class="symbol-label font-weight-bold" style="font-size: 0.75rem;">' + (remark.commenter_initials || remark.commenter_name.substring(0, 2).toUpperCase()) + '</span>' +
                            '</div>' +
                            '</div>' +
                            // Comment content
                            '<div class="flex-grow-1" style="min-width: 0;">' +
                            '<div class="d-flex align-items-baseline mb-1">' +
                            '<strong class="mr-2" style="font-size: 0.8125rem; color: #050505; cursor: pointer;">' + escapeHtml(remark.commenter_name) + '</strong>' +
                            '<span class="text-muted" style="font-size: 0.75rem; color: #65676b;">' + remark.created_at + (remark.created_at_relative ? ' <span style="margin: 0 4px;">•</span> ' + remark.created_at_relative : '') + '</span>' +
                            '</div>' +
                            '<div class="comment-text" style="font-size: 0.8125rem; color: #050505; line-height: 1.3; white-space: pre-wrap; word-wrap: break-word;">' + renderMentionedContent(remark.content) + '</div>' +
                            '</div>' +
                            // Delete button (only show if user is the owner)
                            (remark.is_owner ? '<button type="button" class="btn btn-sm btn-link text-muted delete-comment-btn comment-delete-btn" data-remark-id="' + remark.RemarkID + '" style="position: absolute; top: 0; right: 0; opacity: 1; padding: 2px 6px; font-size: 0.75rem; background: transparent !important;" title="Delete comment">' +
                            '<i class="la la-trash" style="color: #65676b; font-size: 0.875rem;"></i>' +
                            '</button>' : '') +
                            '</div>';
                        commentsList.append(commentHtml);
                    });
                } else {
                    commentsList.html('<div class="text-center text-muted py-3" style="font-size: 0.8125rem; color: #65676b;">No comments yet. Be the first to add a comment!</div>');
                }
            },
            error: function() {
                $('#internal-comments-list').html('<div class="text-center text-danger py-3" style="font-size: 0.8125rem;">Error loading comments. Please refresh the page.</div>');
            }
        });
    }

    // Add new comment
    $('#add-comment-btn').on('click', function() {
        var content = $('#new-comment-content').val().trim();
        
        if (!content) {
            Display_Message('<?php echo base_url('assets/image/sweetalert.jpg') ?>', 'Please enter a comment', null);
            return;
        }

        var $btn = $(this);
        var originalText = $btn.html();
        $btn.prop('disabled', true).html('<i class="la la-spinner la-spin"></i> Adding...');

        $.ajax({
            url: '<?php echo base_url('Booking/Add_Remark'); ?>',
            type: 'post',
            data: {
                booking_id: bookingId,
                content: content
            },
            dataType: 'json',
            success: function(response) {
                $btn.prop('disabled', false).html(originalText);

                if (response.success) {
                    $('#new-comment-content').val('');
                    loadComments(); // Reload comments to show the new one
                    // Don't redirect, just show success message
                    Swal.fire({
                        width: 550,
                        background: 'url(<?php echo base_url('assets/image/sweetalert.jpg') ?>)',
                        icon: 'success',
                        title: 'Comment added Successfully',
                        showConfirmButton: false,
                        timer: 2200
                    });
                } else {
                    Display_Message('<?php echo base_url('assets/image/sweetalert.jpg') ?>', response.message || 'Failed to add comment', null);
                }
            },
            error: function() {
                $btn.prop('disabled', false).html(originalText);
                Display_Message('<?php echo base_url('assets/image/sweetalert.jpg') ?>', 'Error adding comment. Please try again.', null);
            }
        });
    });

    // Allow Enter key to submit (Ctrl+Enter or Shift+Enter)
    $('#new-comment-content').on('keydown', function(e) {
        if ((e.ctrlKey || e.shiftKey) && e.keyCode === 13) {
            e.preventDefault();
            $('#add-comment-btn').click();
        }
    });

    // Delete comment handler (using event delegation for dynamically added buttons)
    $(document).on('click', '.delete-comment-btn', function() {
        var remarkId = $(this).data('remark-id');
        var $commentDiv = $(this).closest('.comment-item');
        
        if (!remarkId) {
            return;
        }

        // Confirm deletion
        Swal.fire({
            width: 550,
            background: 'url(<?php echo base_url('assets/image/sweetalert.jpg') ?>)',
            icon: 'warning',
            title: 'Delete Comment?',
            text: 'Are you sure you want to delete this comment?',
            showCancelButton: true,
            confirmButtonText: 'Delete',
            cancelButtonText: 'Cancel',
            confirmButtonColor: '#d33',
            cancelButtonColor: '#3085d6'
        }).then((result) => {
            if (result.isConfirmed) {
                // Show loading state
                $commentDiv.css('opacity', '0.5');
                
                $.ajax({
                    url: '<?php echo base_url('Booking/Delete_Remark'); ?>',
                    type: 'post',
                    data: {
                        remark_id: remarkId
                    },
                    dataType: 'json',
                    success: function(response) {
                        if (response.success) {
                            // Remove the comment from DOM
                            $commentDiv.fadeOut(300, function() {
                                $(this).remove();
                                // Reload comments to ensure sync
                                loadComments();
                            });
                            
                            Swal.fire({
                                width: 550,
                                background: 'url(<?php echo base_url('assets/image/sweetalert.jpg') ?>)',
                                icon: 'success',
                                title: 'Comment deleted Successfully',
                                showConfirmButton: false,
                                timer: 1500
                            });
                        } else {
                            $commentDiv.css('opacity', '1');
                            Display_Message('<?php echo base_url('assets/image/sweetalert.jpg') ?>', response.message || 'Failed to delete comment', null);
                        }
                    },
                    error: function() {
                        $commentDiv.css('opacity', '1');
                        Display_Message('<?php echo base_url('assets/image/sweetalert.jpg') ?>', 'Error deleting comment. Please try again.', null);
                    }
                });
            }
        });
    });

    // Helper function to escape HTML
    function escapeHtml(text) {
        var map = {
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#039;'
        };
        return text.replace(/[&<>"']/g, function(m) { return map[m]; });
    }

    window.ADMIN_HANDLE_MAP = <?php
        $__map = array();
        if (!empty($notify_admins)) {
            foreach ($notify_admins as $__a) {
                if ($__a->Status === 'Y' && !empty($__a->handle)) {
                    $__map[] = array(
                        'AdminID' => (int)$__a->AdminID,
                        'Name' => $__a->Name,
                        'handle' => $__a->handle,
                    );
                }
            }
        }
        echo json_encode($__map);
    ?>;

    function renderMentionedContent(text) {
        if (text == null) return '';
        var safe = escapeHtml(String(text));
        var nameByHandle = {};
        (window.ADMIN_HANDLE_MAP || []).forEach(function(a) { nameByHandle[a.handle] = a.Name; });
        return safe.replace(/@([a-z0-9]+)/g, function(full, handle) {
            if (nameByHandle[handle]) {
                return '<span class="mention" style="color:#1877f2;font-weight:600;background:#e7f3ff;padding:1px 4px;border-radius:3px;">@' + escapeHtml(nameByHandle[handle]) + '</span>';
            }
            return full;
        });
    }

    function initMentionAutocomplete(textareaSelector) {
        var $ta = $(textareaSelector);
        if (!$ta.length || $ta.data('mention-init')) return;
        $ta.data('mention-init', true);

        var admins = (window.ADMIN_HANDLE_MAP || []).slice();
        var $dd = $('<ul class="mention-dropdown"></ul>').css({
            position: 'absolute', zIndex: 1070, background: '#fff',
            border: '1px solid #d0d7de', borderRadius: '4px', padding: '4px 0',
            margin: 0, listStyle: 'none', maxHeight: '180px', overflowY: 'auto',
            minWidth: '180px', boxShadow: '0 4px 12px rgba(0,0,0,0.12)',
            display: 'none', fontSize: '0.8125rem'
        });
        $ta.closest('.mention-wrapper').append($dd);

        var activeIdx = 0;
        var queryStart = -1;

        function hide() { $dd.hide().empty(); queryStart = -1; }

        function filter(q) {
            q = q.toLowerCase();
            return admins.filter(function(a) {
                return a.handle.indexOf(q) === 0 || a.Name.toLowerCase().indexOf(q) !== -1;
            }).slice(0, 8);
        }

        function render(list) {
            $dd.empty();
            if (!list.length) { hide(); return; }
            list.forEach(function(a, i) {
                var $li = $('<li></li>').css({
                    padding: '6px 10px', cursor: 'pointer',
                    background: i === activeIdx ? '#e7f3ff' : 'transparent'
                }).attr('data-handle', a.handle);
                $li.html('<strong>' + escapeHtml(a.Name) + '</strong> <span style="color:#65676b;font-size:0.75rem;">@' + escapeHtml(a.handle) + '</span>');
                $li.on('mousedown', function(e) { e.preventDefault(); pick(a.handle); });
                $li.on('mouseenter', function() { activeIdx = i; render(list); });
                $dd.append($li);
            });
            $dd.show();
        }

        function currentMatch() {
            var val = $ta.val();
            var pos = $ta[0].selectionStart;
            var before = val.slice(0, pos);
            var m = before.match(/(?:^|\s)@([a-z0-9]*)$/i);
            if (!m) return null;
            return { query: m[1].toLowerCase(), start: pos - m[1].length - 1, end: pos };
        }

        function pick(handle) {
            var match = currentMatch();
            if (!match) { hide(); return; }
            var val = $ta.val();
            var newVal = val.slice(0, match.start) + '@' + handle + ' ' + val.slice(match.end);
            var newPos = match.start + handle.length + 2;
            $ta.val(newVal);
            $ta[0].setSelectionRange(newPos, newPos);
            $ta.trigger('focus');
            hide();
        }

        $ta.on('input click keyup', function(e) {
            if (e.type === 'keyup' && (e.keyCode === 38 || e.keyCode === 40 || e.keyCode === 13 || e.keyCode === 27 || e.keyCode === 9)) return;
            var match = currentMatch();
            if (!match) { hide(); return; }
            queryStart = match.start;
            activeIdx = 0;
            var list = filter(match.query);
            if (!list.length) { hide(); return; }
            $dd.css({ top: ($ta.outerHeight() + 2) + 'px', left: '0px', right: 'auto' });
            render(list);
            $dd.data('current-list', list);
        });

        $ta.on('keydown', function(e) {
            if (!$dd.is(':visible')) return;
            var list = $dd.data('current-list') || [];
            if (e.keyCode === 38) { // up
                e.preventDefault();
                activeIdx = (activeIdx - 1 + list.length) % list.length;
                render(list);
            } else if (e.keyCode === 40) { // down
                e.preventDefault();
                activeIdx = (activeIdx + 1) % list.length;
                render(list);
            } else if (e.keyCode === 13 || e.keyCode === 9) { // enter/tab
                if (list[activeIdx]) {
                    e.preventDefault();
                    e.stopPropagation();
                    pick(list[activeIdx].handle);
                }
            } else if (e.keyCode === 27) { // esc
                e.preventDefault();
                hide();
            }
        });

        $ta.on('blur', function() { setTimeout(hide, 150); });
    }

    // Add admin remark (internal remark in response to customer)
    $('#add-admin-remark-btn').on('click', function() {
        var content = $('#new-admin-remark-content').val().trim();
        
        if (!content) {
            Display_Message('<?php echo base_url('assets/image/sweetalert.jpg') ?>', 'Please enter a remark', null);
            return;
        }

        var $btn = $(this);
        var originalText = $btn.html();
        $btn.prop('disabled', true).html('<i class="la la-spinner la-spin"></i> Adding...');

        $.ajax({
            url: '<?php echo base_url('Booking/Add_Remark'); ?>',
            type: 'post',
            data: {
                booking_id: bookingId,
                content: content,
                remark_type: '2' // CUSTOMER type when adding from Customer Remarks section
            },
            dataType: 'json',
            success: function(response) {
                $btn.prop('disabled', false).html(originalText);
                
                if (response.success) {
                    $('#new-admin-remark-content').val('');
                    loadCustomerRemarks(); // Reload customer remarks to show the new one
                    // Don't redirect, just show success message
                    Swal.fire({
                        width: 550,
                        background: 'url(<?php echo base_url('assets/image/sweetalert.jpg') ?>)',
                        icon: 'success',
                        title: 'Remark added Successfully',
                        showConfirmButton: false,
                        timer: 2200
                    });
                } else {
                    Display_Message('<?php echo base_url('assets/image/sweetalert.jpg') ?>', response.message || 'Failed to add remark', null);
                }
            },
            error: function() {
                $btn.prop('disabled', false).html(originalText);
                Display_Message('<?php echo base_url('assets/image/sweetalert.jpg') ?>', 'Error adding remark. Please try again.', null);
            }
        });
    });

    // Allow Enter key to submit (Ctrl+Enter or Shift+Enter) for admin remark
    $('#new-admin-remark-content').on('keydown', function(e) {
        if ((e.ctrlKey || e.shiftKey) && e.keyCode === 13) {
            e.preventDefault();
            $('#add-admin-remark-btn').click();
        }
    });

    // Load comments when page is ready
    loadComments();
    loadCustomerRemarks();
    initMentionAutocomplete('#new-comment-content');
    <?php } ?>
    
    // Booking Checklist functionality
    <?php if(isset($booking_checklists) && !empty($booking_checklists['groups']) && current_url() == base_url('Booking/Update')) { ?>
    var bookingId = <?php echo $BookingID; ?>;
    var checklistCompletions = [];
    var totalChecklistItems = <?php echo $booking_checklists['total_count']; ?>;
    
    // Update checklist completions array and UI when checkbox changes
    $('.checklist-checkbox').on('change', function() {
        var $checkbox = $(this);
        var $item = $checkbox.closest('.checklist-item');
        var checklistId = $checkbox.val();
        
        // Update item visual state
        if($checkbox.is(':checked')) {
            $item.addClass('checked');
        } else {
            $item.removeClass('checked');
            // Remove completion info if exists
            $item.find('.checklist-completion-info').remove();
        }
        
        updateChecklistCompletions();
        updateProgressBar();
    });
    
    function updateChecklistCompletions() {
        checklistCompletions = [];
        $('.checklist-checkbox:checked').each(function() {
            checklistCompletions.push($(this).val());
        });
    }
    
    function updateProgressBar() {
        var checkedCount = checklistCompletions.length;
        var percentage = totalChecklistItems > 0 ? Math.round((checkedCount / totalChecklistItems) * 100) : 0;
        
        // Update progress text
        $('.progress-text').html('<strong>' + checkedCount + '</strong> of <strong>' + totalChecklistItems + '</strong> items completed');
        $('.progress-percentage').text(percentage + '%');
        
        // Update progress bar
        $('.progress-bar').css('width', percentage + '%').attr('aria-valuenow', checkedCount);
    }
    
    // Update Checklist Button Click Handler
    $('#update_checklist_btn').on('click', function() {
        updateChecklistCompletions();
        
        // Disable button during update
        var $btn = $(this);
        var originalText = $btn.html();
        $btn.prop('disabled', true).html('<i class="la la-spinner la-spin"></i> Updating...');
        
        // Prepare data - CodeIgniter expects checklist_completions[] format
        var postData = 'booking_id=' + bookingId;
        
        // Add each checklist ID as checklist_completions[]
        if(checklistCompletions.length === 0) {
            postData += '&checklist_completions=';
        }
        $.each(checklistCompletions, function(index, value) {
            postData += '&checklist_completions[]=' + encodeURIComponent(value);
        });
        
        console.log('Sending checklist completions:', checklistCompletions);
        console.log('Post data string:', postData);
        
        $.ajax({
            url: '<?php echo base_url('Booking/Update'); ?>',
            type: 'POST',
            data: postData,
            contentType: 'application/x-www-form-urlencoded; charset=UTF-8',
            success: function(response) {
                $btn.prop('disabled', false).html(originalText);
                console.log('Response received');
                Swal.fire({
                    width: 550,
                    background: 'url(<?php echo base_url('assets/image/sweetalert.jpg') ?>)',
                    icon: 'success',
                    title: 'Booking Checklist Successfully Updated',
                    showConfirmButton: false,
                    timer: 1500
                }).then(function() {
                    // Reload page to show updated "checked by" information
                    window.location.reload();
                });
            },
            error: function(xhr, status, error) {
                $btn.prop('disabled', false).html(originalText);
                console.error('Update Error:', error);
                Swal.fire({
                    width: 550,
                    background: 'url(<?php echo base_url('assets/image/sweetalert.jpg') ?>)',
                    icon: 'error',
                    title: 'Booking Checklist Could Not Be Updated',
                    showConfirmButton: false,
                    timer: 3000
                });
            }
        });
    });
    
    // Save checklist completions when form is submitted
    $('form').on('submit', function(e) {
        updateChecklistCompletions();
        // Remove any existing hidden inputs
        $('input[name="checklist_completions[]"]').remove();
        // Add checklist completions to form data
        $.each(checklistCompletions, function(index, value) {
            $('<input>').attr({
                type: 'hidden',
                name: 'checklist_completions[]',
                value: value
            }).appendTo($('form'));
        });
    });
    
    // Initialize
    updateChecklistCompletions();
    updateProgressBar();
    <?php } ?>
});

// Custom Upload Functionality
<?php if(current_url() == base_url('Booking/Update')) { ?>
$(document).ready(function() {
    // Update file input label when file is selected
    $('#upload_file').on('change', function() {
        var fileName = $(this).val().split('\\').pop();
        $(this).next('.custom-file-label').html(fileName);
    });

    // Handle upload form submission
    $('#custom_upload_form').on('submit', function(e) {
        e.preventDefault();
        
        var bookingId = $('#upload_booking_id').val();
        var uploadName = $('#upload_name').val();
        var uploadFile = $('#upload_file')[0].files[0];

        if (!uploadName || !uploadFile) {
            Swal.fire({
                width: 550,
                background: 'url(<?php echo base_url('assets/image/sweetalert.jpg') ?>)',
                icon: 'warning',
                title: 'Please provide both document name and file',
                showConfirmButton: true
            });
            return;
        }

        var formData = new FormData();
        formData.append('booking_id', bookingId);
        formData.append('upload_name', uploadName);
        formData.append('upload_file', uploadFile);

        $('#upload_btn').prop('disabled', true).html('<i class="la la-spinner la-spin"></i> Uploading...');

        $.ajax({
            url: '<?php echo base_url('Booking/Upload_Custom_File'); ?>',
            type: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            success: function(response) {
                var result = typeof response === 'string' ? JSON.parse(response) : response;
                if (result.success) {
                    // Add new upload to list
                    var uploadHtml = '<div class="upload-item mb-3 border rounded" data-upload-id="' + result.upload.id + '" style="background: #fff; transition: all 0.2s;">' +
                        '<div class="p-3">' +
                        '<div class="d-flex align-items-start">' +
                        '<div class="flex-shrink-0 mr-3">' +
                        '<div class="d-flex align-items-center justify-content-center" style="width: 48px; height: 48px; background: #f0f4ff; border-radius: 8px;">' +
                        '<i class="la la-file-alt text-primary" style="font-size: 1.5rem;"></i>' +
                        '</div>' +
                        '</div>' +
                        '<div class="flex-grow-1 min-w-0">' +
                        '<div class="mb-1">' +
                        '<strong class="d-block text-truncate" style="font-size: 0.95rem; color: #212529;">' + escapeHtml(result.upload.upload_name) + '</strong>' +
                        '</div>' +
                        '<small class="text-muted d-block" style="font-size: 0.75rem; line-height: 1.4;">' +
                        '<i class="la la-user-circle"></i> ' + escapeHtml(result.upload.created_by) + '<br>' +
                        '<i class="la la-clock"></i> ' + result.upload.created_at + (result.upload.created_at_relative ? ' <span style="margin: 0 4px;">•</span> ' + result.upload.created_at_relative : '') +
                        '</small>' +
                        '</div>' +
                        '</div>' +
                        '<div class="mt-3 pt-3 border-top">' +
                        '<div class="d-flex flex-wrap align-items-center">' +
                        '<a href="' + result.upload.upload_content + '" target="_blank" class="btn btn-sm btn-light-primary mr-2 mb-2" title="View Document" style="flex: 1; min-width: 80px;">' +
                        '<i class="la la-eye mr-1"></i> <span class="d-none d-md-inline">View</span></a>' +
                        '<a href="' + result.upload.upload_content + '" download class="btn btn-sm btn-light-success mr-2 mb-2" title="Download Document" style="flex: 1; min-width: 80px;">' +
                        '<i class="la la-download mr-1"></i> <span class="d-none d-md-inline">Download</span></a>' +
                        '<button type="button" class="btn btn-sm btn-light-danger delete-upload mb-2" data-upload-id="' + result.upload.id + '" title="Delete Document" style="min-width: 50px;">' +
                        '<i class="la la-trash"></i></button>' +
                        '</div>' +
                        '</div>' +
                        '</div>' +
                        '</div>';

                    if ($('#uploads_container .text-center').length > 0) {
                        $('#uploads_container').html(uploadHtml);
                    } else {
                        $('#uploads_container').prepend(uploadHtml);
                    }

                    // Reset form
                    $('#custom_upload_form')[0].reset();
                    $('#upload_file').next('.custom-file-label').html('Choose file');
                    $('#upload_btn').prop('disabled', false).html('<i class="la la-upload"></i> Upload');

                    // Show success message
                    Swal.fire({
                        width: 550,
                        background: 'url(<?php echo base_url('assets/image/sweetalert.jpg') ?>)',
                        icon: 'success',
                        title: 'File uploaded successfully!',
                        showConfirmButton: false,
                        timer: 2000
                    });
                } else {
                    Swal.fire({
                        width: 550,
                        background: 'url(<?php echo base_url('assets/image/sweetalert.jpg') ?>)',
                        icon: 'error',
                        title: 'Error: ' + result.message,
                        showConfirmButton: true
                    });
                    $('#upload_btn').prop('disabled', false).html('<i class="la la-upload"></i> Upload');
                }
            },
            error: function(xhr, status, error) {
                Swal.fire({
                    width: 550,
                    background: 'url(<?php echo base_url('assets/image/sweetalert.jpg') ?>)',
                    icon: 'error',
                    title: 'Upload failed: ' + error,
                    showConfirmButton: true
                });
                $('#upload_btn').prop('disabled', false).html('<i class="la la-upload"></i> Upload');
            }
        });
    });

    // Handle delete upload
    $(document).on('click', '.delete-upload', function() {
        var uploadId = $(this).data('upload-id');
        var $uploadItem = $(this).closest('.upload-item');

        Swal.fire({
            width: 550,
            background: 'url(<?php echo base_url('assets/image/sweetalert.jpg') ?>)',
            icon: 'warning',
            title: 'Are you sure you want to delete this upload?',
            showCancelButton: true,
            confirmButtonText: 'Yes, delete it!',
            cancelButtonText: 'Cancel',
            confirmButtonColor: '#d33',
            cancelButtonColor: '#3085d6'
        }).then((result) => {
            if (result.isConfirmed) {
                $.ajax({
                    url: '<?php echo base_url('Booking/Delete_Custom_Upload'); ?>',
                    type: 'POST',
                    data: { upload_id: uploadId },
                    dataType: 'json',
                    success: function(response) {
                        if (response.success) {
                            $uploadItem.fadeOut(300, function() {
                                $(this).remove();
                                if ($('#uploads_container .upload-item').length === 0) {
                                    $('#uploads_container').html(
                                        '<div class="text-center text-muted py-4">' +
                                        '<i class="la la-inbox" style="font-size: 3rem;"></i>' +
                                        '<p class="mt-2">No documents uploaded yet</p>' +
                                        '</div>'
                                    );
                                }
                            });
                            Swal.fire({
                                width: 550,
                                background: 'url(<?php echo base_url('assets/image/sweetalert.jpg') ?>)',
                                icon: 'success',
                                title: 'Upload deleted successfully!',
                                showConfirmButton: false,
                                timer: 2000
                            });
                        } else {
                            Swal.fire({
                                width: 550,
                                background: 'url(<?php echo base_url('assets/image/sweetalert.jpg') ?>)',
                                icon: 'error',
                                title: 'Error: ' + response.message,
                                showConfirmButton: true
                            });
                        }
                    },
                    error: function() {
                        Swal.fire({
                            width: 550,
                            background: 'url(<?php echo base_url('assets/image/sweetalert.jpg') ?>)',
                            icon: 'error',
                            title: 'Failed to delete upload',
                            showConfirmButton: true
                        });
                    }
                });
            }
        });
    });

    function escapeHtml(text) {
        var map = {
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#039;'
        };
        return text.replace(/[&<>"']/g, function(m) { return map[m]; });
    }
});
<?php } ?>

</script>

<style>
.comment-item {
    transition: background-color 0.2s;
    padding: 6px 0;
    border-radius: 4px;
}

.comment-item:hover {
    background-color: #f2f3f5;
    transition: background-color 0.2s;
}

.comment-item:last-child {
    border-bottom: none !important;
    margin-bottom: 0 !important;
    padding-bottom: 0 !important;
}

.comment-text {
    margin-top: 1px;
}

.comment-delete-btn {
    color: #65676b !important;
    transition: all 0.2s ease;
}

.comment-delete-btn:hover {
    color: #e41e3f !important;
    transform: scale(1.15);
}

.comment-delete-btn:hover i {
    color: #e41e3f !important;
}

.symbol-circle {
    border-radius: 50% !important;
}

#internal-comments-list {
    padding: 2px 0;
}

#internal-comments-list::-webkit-scrollbar {
    width: 6px;
}

#internal-comments-list::-webkit-scrollbar-track {
    background: #f1f1f1;
    border-radius: 10px;
}

#internal-comments-list::-webkit-scrollbar-thumb {
    background: #888;
    border-radius: 10px;
}

#internal-comments-list::-webkit-scrollbar-thumb:hover {
    background: #555;
}
</style>

<style>
    .auto-resize-textarea {
        resize: none;
        overflow: hidden;
        min-height: 60px;
    }

    /* Draft lock: clearly grey out disabled fields (e.g. Deposit /
       Full Payment Deadline while saving as a draft). */
    #form input.form-control:disabled,
    #form select.form-control:disabled,
    #form textarea.form-control:disabled,
    #form input.form-control[disabled],
    #form select.form-control[disabled],
    #form textarea.form-control[disabled] {
        background-color: #F3F6F9;
        color: #6c757d;
        cursor: not-allowed;
        opacity: 1;
    }

    /* Booking Checklist Professional Styling */
    .checklist-container {
        padding: 0;
    }

    .checklist-item {
        padding: 10px 12px;
        margin-bottom: 8px;
        background-color: #ffffff;
        border: 1px solid #e4e6eb;
        border-radius: 6px;
        transition: all 0.2s ease;
    }

    .checklist-item:hover {
        border-color: #c1c7d0;
        box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
    }

    .checklist-item.checked {
        background-color: #f8f9fa;
        border-color: #d4edda;
    }

    .checklist-item.checked:hover {
        border-color: #c3e6cb;
    }

    .checklist-item-content {
        display: flex;
        align-items: flex-start;
        gap: 28px;
    }

    .checklist-checkbox-wrapper {
        position: relative;
        flex-shrink: 0;
        margin-top: 2px;
    }

    .checklist-checkbox {
        width: 18px;
        height: 18px;
        cursor: pointer;
        margin: 0;
        accent-color: #6082B6;
    }

    .checklist-checkbox-label {
        position: absolute;
        top: 0;
        left: 0;
        width: 18px;
        height: 18px;
        cursor: pointer;
        margin: 0;
    }

    .checklist-details {
        flex: 1;
        min-width: 0;
    }

    .checklist-name-wrapper {
        margin-bottom: 4px;
    }

    .checklist-name {
        font-size: 0.875rem;
        font-weight: 500;
        color: #212529;
        cursor: pointer;
        margin: 0;
        line-height: 1.3;
    }

    .checklist-completion-info {
        display: flex;
        align-items: center;
        gap: 4px;
        margin-top: 6px;
        padding-top: 6px;
        border-top: 1px solid #e9ecef;
        font-size: 0.75rem;
        color: #6c757d;
    }

    .checklist-completion-info i {
        font-size: 0.875rem;
        color: #6082B6;
    }

    .completion-text {
        display: flex;
        align-items: center;
        gap: 6px;
        flex-wrap: wrap;
    }

    .completion-separator {
        color: #adb5bd;
        margin: 0 4px;
    }

    .completion-date {
        color: #868e96;
    }

    .checklist-footer {
        margin-top: 16px;
        padding-top: 14px;
        border-top: 2px solid #e4e6eb;
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 16px;
        flex-wrap: wrap;
    }

    .checklist-progress {
        flex: 1;
        min-width: 200px;
    }

    .progress-info {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 6px;
    }

    .progress-text {
        font-size: 0.8125rem;
        color: #495057;
    }

    .progress-text strong {
        color: #212529;
    }

    .progress-percentage {
        font-size: 0.8125rem;
        font-weight: 600;
        color: #6082B6;
    }

    .progress-bar-wrapper {
        width: 100%;
    }

    .progress-bar-wrapper .progress {
        height: 6px;
        border-radius: 3px;
        overflow: hidden;
    }

    .progress-bar-wrapper .progress-bar {
        transition: width 0.3s ease;
    }

    @media (max-width: 768px) {
        .checklist-footer {
            flex-direction: column;
            align-items: stretch;
        }

        .checklist-footer .btn {
            width: 100%;
        }
    }

    /* Mobile-friendly upload items */
    .upload-item {
        box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
    }

    .upload-item:hover {
        box-shadow: 0 2px 6px rgba(0, 0, 0, 0.15);
    }

    @media (max-width: 576px) {
        .upload-item .p-3 {
            flex-direction: column !important;
            align-items: flex-start !important;
        }

        .upload-item .flex-shrink-0 {
            width: 100%;
            margin-top: 12px;
            justify-content: flex-start;
        }

        .upload-item .flex-shrink-0 .btn {
            flex: 1;
        }
    }
</style>
<script>
    // Scroll to hash anchor on page load (for remarks panel redirect)
    $(document).ready(function() {
        if (window.location.hash) {
            var target = $(window.location.hash);
            if (target.length) {
                setTimeout(function() {
                    $('html, body').animate({ scrollTop: target.offset().top - 100 }, 400);
                }, 500);
            }
        }
    });

    <?php if(current_url() == base_url('Booking/Update') || current_url() == base_url('Booking/Create') || current_url() == base_url('Booking/Duplicate')) { ?>
    // Room Management Functions
    var roomsList = [];
    var guestsList = [];
    // On Duplicate: keep roomBookingId null so all room/guest mutations stay client-side
    // (temp rooms) and don't touch the SOURCE booking's rows. Source rooms are seeded
    // once into roomsList by loadRooms() via sourceBookingId below.
    var isDuplicate = <?php echo current_url() == base_url('Booking/Duplicate') ? 'true' : 'false'; ?>;
    var sourceBookingId = <?php echo (current_url() == base_url('Booking/Duplicate') && isset($BookingID) && $BookingID !== 'NA') ? (int)$BookingID : 'null'; ?>;
    var roomBookingId = <?php echo (isset($BookingID) && $BookingID !== 'NA' && current_url() != base_url('Booking/Duplicate')) ? (int)$BookingID : 'null'; ?>;
    var glLocked = <?php echo (isset($LockStatus) && $LockStatus == 'Y') ? 'true' : 'false'; ?>;
    // Drafts (SAD) are deliberately roomless until they graduate; everything past
    // draft must carry at least one room (see roomless-seed in loadRooms()).
    var bookingStatus = '<?php echo isset($Status) ? $Status : ''; ?>';
    var roomSeedAttempted = false;
    var tempRoomCounter = 0;

    function getAllocatedPax(excludeRoomId) {
        var totals = { adult: 0, child: 0, infant: 0 };
        roomsList.forEach(function(room) {
            if (excludeRoomId && room.id == excludeRoomId) return;
            totals.adult += parseInt(room.adult_count) || 0;
            totals.child += parseInt(room.child_count) || 0;
            totals.infant += parseInt(room.infant_count) || 0;
        });
        return totals;
    }

    function updateAllocatedDisplay() {
        var totals = getAllocatedPax();
        $('#allocated_adult').text(totals.adult);
        $('#allocated_child').text(totals.child);
        $('#allocated_infant').text(totals.infant);
    }

    function getGuestTypeBadge(type) {
        var badges = { 'ADULT': 'primary', 'CHILD': 'info', 'INFANT': 'warning' };
        return '<span class="label label-inline label-light-' + (badges[type] || 'secondary') + ' font-weight-bold">' + type + '</span>';
    }

    function renderGuestRows(roomId) {
        var roomGuests = guestsList.filter(function(g) {
            return roomId === null ? !g.guest_list_room_id : g.guest_list_room_id == roomId;
        });
        if (roomGuests.length === 0) return '';

        var html = '<tr class="guest-sub-row" data-parent-room="' + (roomId || 'unassigned') + '">' +
            '<td colspan="5" style="padding:0; border-top:0;">' +
            '<table class="table table-sm mb-0" style="background:#f9fbfd;">';

        roomGuests.forEach(function(guest) {
            var guestName = $('<span>').text((guest.Name || '') + ' ' + (guest.LastName || '')).html().trim() || '<em class="text-muted">No Name</em>';
            var deleteBtn = glLocked ? '' :
                '<button type="button" class="btn btn-xs btn-icon btn-light-danger delete-guest-btn ml-2" data-guest-id="' + guest.GuestListID + '" title="Delete Guest"><i class="la la-trash"></i></button>';
            var typeControl;
            if (glLocked) {
                typeControl = getGuestTypeBadge(guest.Type);
            } else {
                typeControl = '<select class="form-control form-control-sm d-inline-block guest-type-select ml-2"' +
                    ' style="width:auto;padding:2px 6px;height:auto;font-size:0.85rem;"' +
                    ' data-guest-id="' + guest.GuestListID + '"' +
                    ' data-current-type="' + guest.Type + '">' +
                    ['ADULT', 'CHILD', 'INFANT'].map(function(t) {
                        return '<option value="' + t + '"' + (t === guest.Type ? ' selected' : '') + '>' + t + '</option>';
                    }).join('') +
                    '</select>';
            }
            var currentRoomVal = guest.guest_list_room_id ? String(guest.guest_list_room_id) : '';
            var roomControl;
            if (glLocked) {
                var currentRoomName = '';
                if (guest.guest_list_room_id) {
                    var assignedRoom = roomsList.find(function(r) { return String(r.id) === currentRoomVal; });
                    currentRoomName = assignedRoom ? assignedRoom.room_name : '';
                }
                roomControl = currentRoomName
                    ? '<span class="label label-inline label-light-secondary ml-2">' + $('<span>').text(currentRoomName).html() + '</span>'
                    : '';
            } else {
                var optionsHtml = '<option value="">— Unassigned —</option>';
                roomsList.forEach(function(r) {
                    var rid = String(r.id);
                    var name = $('<span>').text(r.room_name).html();
                    optionsHtml += '<option value="' + rid + '"' + (rid === currentRoomVal ? ' selected' : '') + '>' + name + '</option>';
                });
                roomControl = '<select class="form-control form-control-sm d-inline-block guest-room-select ml-2"' +
                    ' style="width:auto;padding:2px 6px;height:auto;font-size:0.85rem;"' +
                    ' data-guest-id="' + guest.GuestListID + '"' +
                    ' data-current-room="' + currentRoomVal + '">' +
                    optionsHtml +
                    '</select>';
            }
            html += '<tr>' +
                '<td style="padding:4px 12px;">' +
                '<i class="la la-user text-muted mr-1"></i>' + guestName +
                ' ' + typeControl +
                ' ' + roomControl +
                deleteBtn +
                '</td>' +
                '</tr>';
        });

        html += '</table></td></tr>';
        return html;
    }

    function renderRoomsTable() {
        var tbody = $('#rooms_list');
        tbody.empty();
        if (roomsList.length === 0) {
            tbody.html('<tr id="no_rooms_row"><td colspan="5" class="text-muted text-center">No rooms created yet. Click "Add Room" to create one.</td></tr>');
        } else {
            roomsList.forEach(function(room) {
                var actionsTd = glLocked
                    ? '<td class="text-center text-muted">—</td>'
                    : '<td class="text-center" style="white-space:nowrap">' +
                      '<button type="button" class="btn btn-sm btn-icon btn-light-primary edit-room-btn mr-1" data-room-id="' + room.id + '"><i class="la la-edit"></i></button>' +
                      '<button type="button" class="btn btn-sm btn-icon btn-light-warning duplicate-room-btn mr-1" data-room-id="' + room.id + '" title="Duplicate"><i class="la la-copy"></i></button>' +
                      '<button type="button" class="btn btn-sm btn-icon btn-light-danger delete-room-btn" data-room-id="' + room.id + '"><i class="la la-trash"></i></button>' +
                      '</td>';
                var addBtn = function(roomId, type) {
                    return glLocked ? '' : ' &nbsp;<button type="button" class="btn btn-xs btn-icon btn-light-success add-count-btn ml-2" data-room-id="' + roomId + '" data-type="' + type + '" title="Add 1"><i class="la la-plus"></i></button>';
                };
                var subBtn = function(roomId, type) {
                    return glLocked ? '' : ' <button type="button" class="btn btn-xs btn-icon btn-light-danger subtract-count-btn ml-1" data-room-id="' + roomId + '" data-type="' + type + '" title="Subtract 1"><i class="la la-minus"></i></button>';
                };
                tbody.append(
                    '<tr data-room-id="' + room.id + '">' +
                    '<td class="font-weight-bold">' + $('<span>').text(room.room_name).html() + '</td>' +
                    '<td class="text-center" style="padding:8px 12px;">' + (room.adult_count || 0) + subBtn(room.id, 'adult_count') + addBtn(room.id, 'adult_count') + '</td>' +
                    '<td class="text-center" style="padding:8px 12px;">' + (room.child_count || 0) + subBtn(room.id, 'child_count') + addBtn(room.id, 'child_count') + '</td>' +
                    '<td class="text-center" style="padding:8px 12px;">' + (room.infant_count || 0) + subBtn(room.id, 'infant_count') + addBtn(room.id, 'infant_count') + '</td>' +
                    actionsTd +
                    '</tr>' +
                    renderGuestRows(room.id)
                );
            });

            // Show unassigned guests
            var unassignedHtml = renderGuestRows(null);
            if (unassignedHtml) {
                tbody.append(
                    '<tr class="bg-light-warning">' +
                    '<td class="font-weight-bold text-muted" colspan="4"><i class="la la-exclamation-circle text-warning mr-1"></i>Unassigned Guests</td>' +
                    '<td></td>' +
                    '</tr>' +
                    unassignedHtml
                );
            }
        }
        updateAllocatedDisplay();
        attachRoomEventHandlers();
    }

    function loadRooms() {
        if (roomBookingId) {
            $.ajax({
                url: '<?php echo base_url("Guest_List_Room/Read"); ?>',
                type: 'get',
                data: { booking_id: roomBookingId },
                dataType: 'json',
                success: function(data) {
                    roomsList = data || [];
                    // A booking that has graduated out of draft (SAD) must always
                    // have at least one room. Drafts are intentionally roomless,
                    // so they are left alone; but once graduated to Pending BC or
                    // beyond a roomless booking is a leftover of the draft flow —
                    // seed a real ROOM 1 once so it shows and the +/- / edit
                    // controls work against a persisted row.
                    if (roomsList.length === 0 && bookingStatus !== 'SAD' && !glLocked && !roomSeedAttempted) {
                        roomSeedAttempted = true;
                        $.ajax({
                            url: '<?php echo base_url("Guest_List_Room/Create"); ?>',
                            type: 'post',
                            data: { booking_id: roomBookingId, room_name: 'ROOM 1', adult_count: 0, child_count: 0, infant_count: 0 },
                            dataType: 'json',
                            success: function() { loadRooms(); },
                            error: function() { renderRoomsTable(); }
                        });
                        return;
                    }
                    // Also fetch guests
                    $.ajax({
                        url: '<?php echo base_url("Guest_List_Room/Read_Guests"); ?>',
                        type: 'get',
                        data: { booking_id: roomBookingId },
                        dataType: 'json',
                        success: function(guests) {
                            guestsList = guests || [];
                            renderRoomsTable();
                        }
                    });
                }
            });
        } else if (isDuplicate && sourceBookingId) {
            // Seed roomsList from the SOURCE booking's rooms once, but as temp client-side
            // rows. Subsequent edits stay in roomsList; on submit they are POSTed as
            // booking_rooms and inserted under the new booking_id by Booking::Create.
            // Source guests are intentionally NOT pre-loaded — Create_GL_From_Rooms
            // regenerates blank guest rows from room counts after creation.
            $.ajax({
                url: '<?php echo base_url("Guest_List_Room/Read"); ?>',
                type: 'get',
                data: { booking_id: sourceBookingId },
                dataType: 'json',
                success: function(data) {
                    roomsList = (data || []).map(function(r) {
                        tempRoomCounter++;
                        return {
                            id: 'temp_' + tempRoomCounter,
                            room_name: r.room_name,
                            adult_count: parseInt(r.adult_count) || 0,
                            child_count: parseInt(r.child_count) || 0,
                            infant_count: parseInt(r.infant_count) || 0
                        };
                    });
                    if (roomsList.length === 0) {
                        tempRoomCounter++;
                        roomsList.push({
                            id: 'temp_' + tempRoomCounter,
                            room_name: 'ROOM 1',
                            adult_count: 0,
                            child_count: 0,
                            infant_count: 0
                        });
                    }
                    renderRoomsTable();
                },
                error: function() {
                    tempRoomCounter++;
                    roomsList.push({
                        id: 'temp_' + tempRoomCounter,
                        room_name: 'ROOM 1',
                        adult_count: 0,
                        child_count: 0,
                        infant_count: 0
                    });
                    renderRoomsTable();
                }
            });
        } else {
            // Add default room for new bookings
            tempRoomCounter++;
            roomsList.push({
                id: 'temp_' + tempRoomCounter,
                room_name: 'ROOM 1',
                adult_count: 0,
                child_count: 0,
                infant_count: 0
            });
            renderRoomsTable();
        }
    }

    function getRoomFormHtml() {
        return '<div class="row">' +
            '<div class="col-12 mb-3">' +
            '<label class="font-weight-bold">Room Name <span style="color:red;">*</span></label>' +
            '<input type="text" id="swal_room_name" class="form-control" placeholder="e.g. Room 101">' +
            '</div>' +
            '<div class="col-4">' +
            '<label class="font-weight-bold">Adult</label>' +
            '<input type="number" id="swal_adult_count" class="form-control" min="0" value="0">' +
            '</div>' +
            '<div class="col-4">' +
            '<label class="font-weight-bold">Child</label>' +
            '<input type="number" id="swal_child_count" class="form-control" min="0" value="0">' +
            '</div>' +
            '<div class="col-4">' +
            '<label class="font-weight-bold">Infant</label>' +
            '<input type="number" id="swal_infant_count" class="form-control" min="0" value="0">' +
            '</div>' +
            '</div>';
    }

    function attachRoomEventHandlers() {
        $('.add-count-btn').off('click').on('click', function() {
            var roomId = $(this).data('room-id');
            var type = $(this).data('type');
            if (roomBookingId) {
                $.ajax({
                    url: '<?php echo base_url("Guest_List_Room/Add_Count"); ?>',
                    type: 'post',
                    data: { room_id: roomId, type: type },
                    dataType: 'json',
                    success: function(response) {
                        if (response.success) {
                            loadRooms();
                        } else {
                            Swal.fire('Error!', response.message, 'error');
                        }
                    },
                    error: function() {
                        Swal.fire('Error!', 'Failed to add count', 'error');
                    }
                });
            } else {
                var room = roomsList.find(function(r) { return r.id == roomId; });
                if (room) {
                    room[type] = (parseInt(room[type]) || 0) + 1;
                    renderRoomsTable();
                }
            }
        });

        $('.subtract-count-btn').off('click').on('click', function() {
            var roomId = $(this).data('room-id');
            var type = $(this).data('type');
            if (roomBookingId) {
                $.ajax({
                    url: '<?php echo base_url("Guest_List_Room/Subtract_Count"); ?>',
                    type: 'post',
                    data: { room_id: roomId, type: type },
                    dataType: 'json',
                    success: function(response) {
                        if (response.success) {
                            loadRooms();
                        } else {
                            Swal.fire('Error!', response.message, 'error');
                        }
                    },
                    error: function() {
                        Swal.fire('Error!', 'Failed to subtract count', 'error');
                    }
                });
            } else {
                var room = roomsList.find(function(r) { return r.id == roomId; });
                if (room) {
                    room[type] = Math.max(0, (parseInt(room[type]) || 0) - 1);
                    renderRoomsTable();
                }
            }
        });

        $('.edit-room-btn').off('click').on('click', function() {
            var roomId = $(this).data('room-id');
            var room = roomsList.find(function(r) { return r.id == roomId; });
            if (!room) return;

            Swal.fire({
                title: 'Edit Room',
                html: getRoomFormHtml(),
                showCancelButton: true,
                confirmButtonText: 'Update',
                cancelButtonText: 'Cancel',
                didOpen: function() {
                    $('#swal_room_name').val(room.room_name);
                    $('#swal_adult_count').val(room.adult_count || 0);
                    $('#swal_child_count').val(room.child_count || 0);
                    $('#swal_infant_count').val(room.infant_count || 0);
                },
                preConfirm: function() {
                    var roomName = $('#swal_room_name').val().trim();
                    var adultCount = parseInt($('#swal_adult_count').val()) || 0;
                    var childCount = parseInt($('#swal_child_count').val()) || 0;
                    var infantCount = parseInt($('#swal_infant_count').val()) || 0;
                    if (!roomName) {
                        Swal.showValidationMessage('Room name is required!');
                        return false;
                    }
                    return { room_name: roomName, adult_count: adultCount, child_count: childCount, infant_count: infantCount };
                }
            }).then(function(result) {
                if (result.isConfirmed) {
                    if (roomBookingId) {
                        $.ajax({
                            url: '<?php echo base_url("Guest_List_Room/Update"); ?>',
                            type: 'post',
                            data: {
                                room_id: roomId,
                                room_name: result.value.room_name,
                                adult_count: result.value.adult_count,
                                child_count: result.value.child_count,
                                infant_count: result.value.infant_count
                            },
                            dataType: 'json',
                            success: function(response) {
                                if (response.success) {
                                    Swal.fire('Success!', response.message, 'success');
                                    loadRooms();
                                } else {
                                    Swal.fire('Error!', response.message, 'error');
                                }
                            },
                            error: function() {
                                Swal.fire('Error!', 'Failed to update room', 'error');
                            }
                        });
                    } else {
                        var idx = roomsList.findIndex(function(r) { return r.id == roomId; });
                        if (idx !== -1) {
                            roomsList[idx].room_name = result.value.room_name;
                            roomsList[idx].adult_count = result.value.adult_count;
                            roomsList[idx].child_count = result.value.child_count;
                            roomsList[idx].infant_count = result.value.infant_count;
                            renderRoomsTable();
                            Swal.fire('Success!', 'Room updated', 'success');
                        }
                    }
                }
            });
        });

        $('.delete-room-btn').off('click').on('click', function() {
            var roomId = $(this).data('room-id');
            Swal.fire({
                title: 'Are you sure?',
                text: 'This will delete the room.' + (roomBookingId ? ' Guests assigned to this room will be unassigned.' : ''),
                icon: 'warning',
                showCancelButton: true,
                confirmButtonText: 'Yes, delete it!',
                cancelButtonText: 'Cancel'
            }).then(function(result) {
                if (result.isConfirmed) {
                    if (roomBookingId) {
                        $.ajax({
                            url: '<?php echo base_url("Guest_List_Room/Delete"); ?>',
                            type: 'get',
                            data: { room_id: roomId },
                            dataType: 'json',
                            success: function(response) {
                                if (response.success) {
                                    Swal.fire('Deleted!', response.message, 'success');
                                    loadRooms();
                                } else {
                                    Swal.fire('Error!', response.message, 'error');
                                }
                            },
                            error: function() {
                                Swal.fire('Error!', 'Failed to delete room', 'error');
                            }
                        });
                    } else {
                        roomsList = roomsList.filter(function(r) { return r.id != roomId; });
                        renderRoomsTable();
                        Swal.fire('Deleted!', 'Room removed', 'success');
                    }
                }
            });
        });

        $('.duplicate-room-btn').off('click').on('click', function() {
            var roomId = $(this).data('room-id');
            var room = roomsList.find(function(r) { return r.id == roomId; });
            if (!room) return;

            var srcMatch = (room.room_name || '').match(/^(.*?)(\d+)\s*$/);
            var newName;
            if (srcMatch) {
                var prefix = srcMatch[1];
                var maxIdx = 0;
                var prefixRe = new RegExp('^' + prefix.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + '(\\d+)\\s*$', 'i');
                roomsList.forEach(function(r) {
                    var m = (r.room_name || '').match(prefixRe);
                    if (m) {
                        var n = parseInt(m[1], 10);
                        if (n > maxIdx) maxIdx = n;
                    }
                });
                newName = prefix + (maxIdx + 1);
            } else {
                newName = room.room_name + ' (COPY)';
            }
            var dupData = {
                room_name: newName,
                adult_count: room.adult_count || 0,
                child_count: room.child_count || 0,
                infant_count: room.infant_count || 0
            };

            if (roomBookingId) {
                $.ajax({
                    url: '<?php echo base_url("Guest_List_Room/Create"); ?>',
                    type: 'post',
                    data: $.extend({ booking_id: roomBookingId }, dupData),
                    dataType: 'json',
                    success: function(response) {
                        if (response.success) {
                            Swal.fire('Success!', 'Room duplicated', 'success');
                            loadRooms();
                        } else {
                            Swal.fire('Error!', response.message, 'error');
                        }
                    },
                    error: function() {
                        Swal.fire('Error!', 'Failed to duplicate room', 'error');
                    }
                });
            } else {
                tempRoomCounter++;
                roomsList.push({
                    id: 'temp_' + tempRoomCounter,
                    room_name: dupData.room_name,
                    adult_count: dupData.adult_count,
                    child_count: dupData.child_count,
                    infant_count: dupData.infant_count
                });
                renderRoomsTable();
                Swal.fire('Success!', 'Room duplicated', 'success');
            }
        });

        $('.delete-guest-btn').off('click').on('click', function() {
            var guestId = $(this).data('guest-id');
            Swal.fire({
                title: 'Are you sure?',
                text: 'This will delete the guest entry.',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonText: 'Yes, delete it!',
                cancelButtonText: 'Cancel'
            }).then(function(result) {
                if (result.isConfirmed) {
                    $.ajax({
                        url: '<?php echo base_url("Guest_List_Room/Delete_Guest"); ?>',
                        type: 'get',
                        data: { guest_list_id: guestId },
                        dataType: 'json',
                        success: function(response) {
                            if (response.success) {
                                Swal.fire('Deleted!', response.message, 'success');
                                loadRooms();
                            } else {
                                Swal.fire('Error!', response.message, 'error');
                            }
                        },
                        error: function() {
                            Swal.fire('Error!', 'Failed to delete guest', 'error');
                        }
                    });
                }
            });
        });

        $('.guest-type-select').off('change').on('change', function() {
            var $sel = $(this);
            var guestId = $sel.data('guest-id');
            var newType = $sel.val();
            var prevType = $sel.data('current-type');
            if (newType === prevType) return;
            $.ajax({
                url: '<?php echo base_url("Guest_List_Room/Update_Guest_Type"); ?>',
                type: 'post',
                data: { guest_list_id: guestId, type: newType },
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        loadRooms();
                    } else {
                        Swal.fire('Error!', response.message, 'error');
                        $sel.val(prevType);
                    }
                },
                error: function() {
                    Swal.fire('Error!', 'Failed to update guest type', 'error');
                    $sel.val(prevType);
                }
            });
        });

        $('.guest-room-select').off('change').on('change', function() {
            var $sel = $(this);
            var guestId = $sel.data('guest-id');
            var newRoomId = $sel.val();
            var currentRoomId = String($sel.data('current-room') || '');
            if (String(newRoomId) === currentRoomId) return;
            $.ajax({
                url: '<?php echo base_url("Guest_List_Room/Update_Guest_Room"); ?>',
                type: 'post',
                data: { guest_list_id: guestId, room_id: newRoomId },
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        loadRooms();
                    } else {
                        Swal.fire('Error!', response.message, 'error');
                        $sel.val(currentRoomId);
                    }
                },
                error: function() {
                    Swal.fire('Error!', 'Failed to move guest', 'error');
                    $sel.val(currentRoomId);
                }
            });
        });
    }

    $('#create_room_btn').click(function() {
        Swal.fire({
            title: 'Create New Room',
            html: getRoomFormHtml(),
            showCancelButton: true,
            confirmButtonText: 'Create',
            cancelButtonText: 'Cancel',
            preConfirm: function() {
                var roomName = $('#swal_room_name').val().trim();
                var adultCount = parseInt($('#swal_adult_count').val()) || 0;
                var childCount = parseInt($('#swal_child_count').val()) || 0;
                var infantCount = parseInt($('#swal_infant_count').val()) || 0;
                if (!roomName) {
                    Swal.showValidationMessage('Room name is required!');
                    return false;
                }
                return { room_name: roomName, adult_count: adultCount, child_count: childCount, infant_count: infantCount };
            }
        }).then(function(result) {
            if (result.isConfirmed) {
                if (roomBookingId) {
                    $.ajax({
                        url: '<?php echo base_url("Guest_List_Room/Create"); ?>',
                        type: 'post',
                        data: {
                            booking_id: roomBookingId,
                            room_name: result.value.room_name,
                            adult_count: result.value.adult_count,
                            child_count: result.value.child_count,
                            infant_count: result.value.infant_count
                        },
                        dataType: 'json',
                        success: function(response) {
                            if (response.success) {
                                Swal.fire('Success!', response.message, 'success');
                                loadRooms();
                            } else {
                                Swal.fire('Error!', response.message, 'error');
                            }
                        },
                        error: function() {
                            Swal.fire('Error!', 'Failed to create room', 'error');
                        }
                    });
                } else {
                    tempRoomCounter++;
                    roomsList.push({
                        id: 'temp_' + tempRoomCounter,
                        room_name: result.value.room_name,
                        adult_count: result.value.adult_count,
                        child_count: result.value.child_count,
                        infant_count: result.value.infant_count
                    });
                    renderRoomsTable();
                    Swal.fire('Success!', 'Room added', 'success');
                }
            }
        });
    });

    // Load rooms on page load
    $(document).ready(function() {
        loadRooms();
    });
    <?php } ?>
</script>

<script>
    // Merged phone input helpers for booking customer contact
    (function() {
        var country_codes_main = <?php echo json_encode($country_codes) ?>;

        function getCountryFlagMain(countryName) {
            if(!countryName) return '🌐';
            var country = countryName.toUpperCase().trim();
            var flags = {
                'MALAYSIA': '🇲🇾', 'SINGAPORE': '🇸🇬', 'THAILAND': '🇹🇭', 'INDONESIA': '🇮🇩',
                'PHILIPPINES': '🇵🇭', 'VIETNAM': '🇻🇳', 'CAMBODIA': '🇰🇭', 'MYANMAR': '🇲🇲', 'BURMA': '🇲🇲',
                'LAOS': '🇱🇦', 'BRUNEI': '🇧🇳', 'BRUNEI DARUSSALAM': '🇧🇳', 'EAST TIMOR': '🇹🇱', 'TIMOR-LESTE': '🇹🇱',
                'CHINA': '🇨🇳', 'JAPAN': '🇯🇵', 'SOUTH KOREA': '🇰🇷', 'KOREA': '🇰🇷', 'NORTH KOREA': '🇰🇵',
                'HONG KONG': '🇭🇰', 'MACAU': '🇲🇴', 'TAIWAN': '🇹🇼', 'MONGOLIA': '🇲🇳',
                'INDIA': '🇮🇳', 'PAKISTAN': '🇵🇰', 'BANGLADESH': '🇧🇩', 'SRI LANKA': '🇱🇰',
                'NEPAL': '🇳🇵', 'BHUTAN': '🇧🇹', 'MALDIVES': '🇲🇻', 'AFGHANISTAN': '🇦🇫',
                'AUSTRALIA': '🇦🇺', 'NEW ZEALAND': '🇳🇿', 'FIJI': '🇫🇯', 'PAPUA NEW GUINEA': '🇵🇬',
                'NEW CALEDONIA': '🇳🇨', 'FRENCH POLYNESIA': '🇵🇫', 'SAMOA': '🇼🇸', 'TONGA': '🇹🇴',
                'UNITED STATES': '🇺🇸', 'USA': '🇺🇸', 'CANADA': '🇨🇦', 'MEXICO': '🇲🇽',
                'GUATEMALA': '🇬🇹', 'BELIZE': '🇧🇿', 'EL SALVADOR': '🇸🇻', 'HONDURAS': '🇭🇳',
                'NICARAGUA': '🇳🇮', 'COSTA RICA': '🇨🇷', 'PANAMA': '🇵🇦', 'CUBA': '🇨🇺',
                'JAMAICA': '🇯🇲', 'HAITI': '🇭🇹', 'DOMINICAN REPUBLIC': '🇩🇴', 'BAHAMAS': '🇧🇸',
                'BARBADOS': '🇧🇧', 'TRINIDAD AND TOBAGO': '🇹🇹', 'PUERTO RICO': '🇵🇷',
                'BRAZIL': '🇧🇷', 'ARGENTINA': '🇦🇷', 'CHILE': '🇨🇱', 'COLOMBIA': '🇨🇴',
                'PERU': '🇵🇪', 'VENEZUELA': '🇻🇪', 'ECUADOR': '🇪🇨', 'BOLIVIA': '🇧🇴',
                'PARAGUAY': '🇵🇾', 'URUGUAY': '🇺🇾', 'GUYANA': '🇬🇾', 'SURINAME': '🇸🇷',
                'UNITED KINGDOM': '🇬🇧', 'UK': '🇬🇧', 'IRELAND': '🇮🇪', 'FRANCE': '🇫🇷',
                'GERMANY': '🇩🇪', 'ITALY': '🇮🇹', 'SPAIN': '🇪🇸', 'PORTUGAL': '🇵🇹',
                'NETHERLANDS': '🇳🇱', 'BELGIUM': '🇧🇪', 'SWITZERLAND': '🇨🇭', 'AUSTRIA': '🇦🇹',
                'LUXEMBOURG': '🇱🇺', 'MONACO': '🇲🇨', 'LIECHTENSTEIN': '🇱🇮', 'ANDORRA': '🇦🇩',
                'SAN MARINO': '🇸🇲', 'VATICAN CITY': '🇻🇦', 'MALTA': '🇲🇹',
                'SWEDEN': '🇸🇪', 'NORWAY': '🇳🇴', 'DENMARK': '🇩🇰', 'FINLAND': '🇫🇮',
                'ICELAND': '🇮🇸', 'ESTONIA': '🇪🇪', 'LATVIA': '🇱🇻', 'LITHUANIA': '🇱🇹',
                'RUSSIA': '🇷🇺', 'POLAND': '🇵🇱', 'CZECH REPUBLIC': '🇨🇿', 'HUNGARY': '🇭🇺',
                'ROMANIA': '🇷🇴', 'BULGARIA': '🇧🇬', 'CROATIA': '🇭🇷', 'SERBIA': '🇷🇸',
                'SLOVAKIA': '🇸🇰', 'SLOVENIA': '🇸🇮', 'BOSNIA AND HERZEGOVINA': '🇧🇦',
                'MACEDONIA': '🇲🇰', 'ALBANIA': '🇦🇱', 'MONTENEGRO': '🇲🇪', 'KOSOVO': '🇽🇰',
                'BELARUS': '🇧🇾', 'UKRAINE': '🇺🇦', 'MOLDOVA': '🇲🇩', 'GEORGIA': '🇬🇪',
                'ARMENIA': '🇦🇲', 'AZERBAIJAN': '🇦🇿',
                'GREECE': '🇬🇷', 'TURKEY': '🇹🇷', 'CYPRUS': '🇨🇾',
                'SAUDI ARABIA': '🇸🇦', 'UNITED ARAB EMIRATES': '🇦🇪', 'UAE': '🇦🇪',
                'QATAR': '🇶🇦', 'KUWAIT': '🇰🇼', 'BAHRAIN': '🇧🇭', 'OMAN': '🇴🇲',
                'YEMEN': '🇾🇪', 'IRAQ': '🇮🇶', 'IRAN': '🇮🇷', 'ISRAEL': '🇮🇱',
                'PALESTINE': '🇵🇸', 'JORDAN': '🇯🇴', 'LEBANON': '🇱🇧', 'SYRIA': '🇸🇾',
                'EGYPT': '🇪🇬', 'LIBYA': '🇱🇾', 'TUNISIA': '🇹🇳', 'ALGERIA': '🇩🇿',
                'MOROCCO': '🇲🇦', 'SUDAN': '🇸🇩', 'SOUTH SUDAN': '🇸🇸', 'ETHIOPIA': '🇪🇹',
                'SOUTH AFRICA': '🇿🇦', 'KENYA': '🇰🇪', 'TANZANIA': '🇹🇿', 'UGANDA': '🇺🇬',
                'RWANDA': '🇷🇼', 'GHANA': '🇬🇭', 'NIGERIA': '🇳🇬', 'SENEGAL': '🇸🇳',
                'IVORY COAST': '🇨🇮', 'CAMEROON': '🇨🇲', 'GABON': '🇬🇦', 'CONGO': '🇨🇬',
                'ANGOLA': '🇦🇴', 'MOZAMBIQUE': '🇲🇿', 'MADAGASCAR': '🇲🇬', 'MAURITIUS': '🇲🇺',
                'SEYCHELLES': '🇸🇨', 'ZIMBABWE': '🇿🇼', 'BOTSWANA': '🇧🇼', 'NAMIBIA': '🇳🇦',
                'KAZAKHSTAN': '🇰🇿', 'UZBEKISTAN': '🇺🇿', 'TURKMENISTAN': '🇹🇲', 'KYRGYZSTAN': '🇰🇬',
                'TAJIKISTAN': '🇹🇯'
            };
            return flags[country] || '🌐';
        }

        function updatePhoneDisplayMain(country, code, countryId) {
            $('#phone-flag-main').text(getCountryFlagMain(country));
            $('#phone-code-main').text(code || '--');
            $('#phone-selector-main').removeClass('open');
            $('#phone-list-main .phone-dropdown-item').removeClass('selected');
            $('#phone-list-main .phone-dropdown-item[data-country-id="' + countryId + '"]').addClass('selected');
        }

        window.updatePhoneFromSelectMain = function() {
            var select = $('#CountryCodeID');
            var selectedOption = select.find('option:selected');
            if(selectedOption.length && selectedOption.val()) {
                var country = selectedOption.data('country') || selectedOption.text().split(' ')[0];
                var code = selectedOption.data('code') || selectedOption.text().split(' ').pop();
                updatePhoneDisplayMain(country, code, selectedOption.val());
            } else {
                $('#phone-flag-main').text('🌐');
                $('#phone-code-main').text('--');
            }
        };

        window.initPhoneInputMain = function(selectedCountry) {
            var selector = $('#phone-selector-main');
            var dropdown = $('#phone-dropdown-main');
            var searchInput = $('#phone-search-main');
            var countrySelect = $('#CountryCodeID');

            $('#phone-list-main .phone-dropdown-item').each(function() {
                var countryName = $(this).data('country-name') || $(this).find('.phone-dropdown-item-name').text();
                $(this).find('.phone-dropdown-item-flag').text(getCountryFlagMain(countryName));
            });

            if(selectedCountry) {
                updatePhoneDisplayMain(selectedCountry.Country, selectedCountry.CountryCode, selectedCountry.CountryCodeID);
            }

            selector.on('click', function(e) {
                if(selector.hasClass('disabled')) return;
                e.stopPropagation();
                dropdown.toggleClass('show');
                if(dropdown.hasClass('show')) { searchInput.focus(); }
            });

            searchInput.on('input', function() {
                var searchTerm = $(this).val().toLowerCase();
                $('#phone-list-main .phone-dropdown-item').each(function() {
                    var country = $(this).data('country') || '';
                    var code = ($(this).data('code') || '').toString();
                    var name = $(this).find('.phone-dropdown-item-name').text().toLowerCase();
                    if(name.indexOf(searchTerm) !== -1 || code.indexOf(searchTerm) !== -1 || country.indexOf(searchTerm) !== -1) {
                        $(this).show();
                    } else {
                        $(this).hide();
                    }
                });
            });

            $(document).on('click', '#phone-list-main .phone-dropdown-item', function() {
                var countryId = $(this).data('country-id');
                var country = $(this).find('.phone-dropdown-item-name').text();
                var code = $(this).data('code');
                updatePhoneDisplayMain(country, code, countryId);
                countrySelect.val(countryId).trigger('change');
                dropdown.removeClass('show');
                searchInput.val('');
                $('#phone-list-main .phone-dropdown-item').show();
            });

            $(document).on('click', function(e) {
                if(!$(e.target).closest('#phone-wrapper-main').length) {
                    dropdown.removeClass('show');
                }
            });
        };

        function updatePhoneDisplaySecond(country, code, countryId) {
            $('#phone-flag-second').text(getCountryFlagMain(country));
            $('#phone-code-second').text(code || '--');
            $('#phone-selector-second').removeClass('open');
            $('#phone-list-second .phone-dropdown-item').removeClass('selected');
            $('#phone-list-second .phone-dropdown-item[data-country-id="' + countryId + '"]').addClass('selected');
        }

        window.updatePhoneFromSelectSecond = function() {
            var select = $('#CountryCodeID2');
            var selectedOption = select.find('option:selected');
            if(selectedOption.length && selectedOption.val()) {
                var country = selectedOption.data('country') || selectedOption.text().split(' ')[0];
                var code = selectedOption.data('code') || selectedOption.text().split(' ').pop();
                updatePhoneDisplaySecond(country, code, selectedOption.val());
            } else {
                $('#phone-flag-second').text('🌐');
                $('#phone-code-second').text('--');
            }
        };

        window.initPhoneInputSecond = function(selectedCountry) {
            var selector = $('#phone-selector-second');
            var dropdown = $('#phone-dropdown-second');
            var searchInput = $('#phone-search-second');
            var countrySelect = $('#CountryCodeID2');

            $('#phone-list-second .phone-dropdown-item').each(function() {
                var countryName = $(this).data('country-name') || $(this).find('.phone-dropdown-item-name').text();
                $(this).find('.phone-dropdown-item-flag').text(getCountryFlagMain(countryName));
            });

            if(selectedCountry) {
                updatePhoneDisplaySecond(selectedCountry.Country, selectedCountry.CountryCode, selectedCountry.CountryCodeID);
            }

            selector.on('click', function(e) {
                if(selector.hasClass('disabled')) return;
                e.stopPropagation();
                dropdown.toggleClass('show');
                if(dropdown.hasClass('show')) { searchInput.focus(); }
            });

            searchInput.on('input', function() {
                var searchTerm = $(this).val().toLowerCase();
                $('#phone-list-second .phone-dropdown-item').each(function() {
                    var country = $(this).data('country') || '';
                    var code = ($(this).data('code') || '').toString();
                    var name = $(this).find('.phone-dropdown-item-name').text().toLowerCase();
                    if(name.indexOf(searchTerm) !== -1 || code.indexOf(searchTerm) !== -1 || country.indexOf(searchTerm) !== -1) {
                        $(this).show();
                    } else {
                        $(this).hide();
                    }
                });
            });

            $(document).on('click', '#phone-list-second .phone-dropdown-item', function() {
                var countryId = $(this).data('country-id');
                var country = $(this).find('.phone-dropdown-item-name').text();
                var code = $(this).data('code');
                updatePhoneDisplaySecond(country, code, countryId);
                countrySelect.val(countryId).trigger('change');
                dropdown.removeClass('show');
                searchInput.val('');
                $('#phone-list-second .phone-dropdown-item').show();
            });

            $(document).on('click', function(e) {
                if(!$(e.target).closest('#phone-wrapper-second').length) {
                    dropdown.removeClass('show');
                }
            });
        };
    })();

    // ============================================================
    // Supplier Invoices (per booking) — Update-mode section only.
    // POSTed as booking_supplier_invoices = [create_rows, update_rows, delete_rows]
    // ============================================================
    (function() {
        if ($('#supplier-invoices').length === 0) return;

        var supplierOptionsHtml = '<option value="">-- Select Supplier --</option>';
        <?php if (!empty($supplier_invoice_suppliers)) { foreach ($supplier_invoice_suppliers as $sup) { ?>
            supplierOptionsHtml += '<option value="<?php echo (int)$sup->SupplierID; ?>"><?php echo addslashes(htmlspecialchars($sup->Name)); ?></option>';
        <?php } } ?>

        function initDeadlinePicker($input) {
            $input.datepicker({format: 'dd/mm/yyyy', autoclose: true, todayHighlight: true});
        }

        function buildEmptyRowHtml() {
            return '<tr class="supplier-invoice-row" data-supplier-invoice-id="" data-file-path="" data-initial-file-path="" data-deleted="0">' +
                '<td><select class="supplier-invoice-supplier form-control form-control-sm">' + supplierOptionsHtml + '</select></td>' +
                '<td><input type="text" class="supplier-invoice-number form-control form-control-sm"></td>' +
                '<td><input type="number" step="0.01" min="0" class="supplier-invoice-amount form-control form-control-sm" style="text-align:right;"></td>' +
                '<td><input type="text" readonly class="supplier-invoice-deadline form-control form-control-sm" autocomplete="off"></td>' +
                '<td><input type="text" class="supplier-invoice-remark form-control form-control-sm"></td>' +
                '<td style="text-align:right; vertical-align:middle; color:#9E9E9E;">&mdash;</td>' +
                '<td style="text-align:right; vertical-align:middle; color:#9E9E9E;">&mdash;</td>' +
                '<td class="supplier-invoice-file-cell" style="text-align:center; vertical-align:middle;">' +
                    '<input type="file" class="supplier-invoice-file-input" accept=".pdf,.jpg,.jpeg,.png,.doc,.docx,.xls,.xlsx" style="display:none;">' +
                    '<span class="supplier-invoice-file-ui"></span>' +
                '</td>' +
                '<td style="text-align:center; vertical-align:middle;">' +
                    '<button type="button" class="supplier-invoice-remove btn btn-danger btn-xs" data-toggle="tooltip" title="Remove invoice">' +
                        '<i class="la la-trash"></i>' +
                    '</button>' +
                '</td>' +
            '</tr>';
        }

        // Invoice attachments are never linked to directly — the View action hits
        // the auth-gated Booking/Supplier_Invoice_File/<id> endpoint.
        var supplierInvoiceBaseUrl = '<?php echo base_url(); ?>';
        var supplierInvoiceUploadUrl = '<?php echo base_url('Booking/Upload_Supplier_Invoice_File'); ?>';

        // (Re)render the per-row file controls from the row's data-file-path.
        function renderInvoiceFileUi($row) {
            var id   = $row.data('supplier-invoice-id');
            var path = $row.attr('data-file-path') || '';
            var $ui  = $row.find('.supplier-invoice-file-ui');
            var html;
            if (path) {
                html = '<div class="d-inline-flex align-items-center">';
                if (id) {
                    html += '<a href="' + supplierInvoiceBaseUrl + 'Booking/Supplier_Invoice_File/' + id + '" target="_blank" rel="noopener" class="btn btn-light-primary btn-xs mr-1 supplier-invoice-file-view" data-toggle="tooltip" title="View invoice file"><i class="la la-file-alt"></i></a>';
                } else {
                    html += '<span class="text-muted mr-1" style="font-size:0.75rem;" data-toggle="tooltip" title="Attached — save the booking to view">attached</span>';
                }
                html += '<button type="button" class="btn btn-light-warning btn-xs mr-1 supplier-invoice-file-attach" data-toggle="tooltip" title="Replace file"><i class="la la-sync-alt"></i></button>';
                html += '<button type="button" class="btn btn-light-danger btn-xs supplier-invoice-file-remove" data-toggle="tooltip" title="Remove file"><i class="la la-times"></i></button>';
                html += '</div>';
            } else {
                html = '<button type="button" class="btn btn-light-primary btn-xs supplier-invoice-file-attach" data-toggle="tooltip" title="Attach invoice file"><i class="la la-paperclip"></i></button>';
            }
            $ui.html(html);
            $ui.find('[data-toggle="tooltip"]').tooltip({ container: 'body', boundary: 'viewport', trigger: 'hover' });
        }

        $('#add-supplier-invoice-btn').on('click', function() {
            $('#supplier-invoices-empty').remove();
            var $row = $(buildEmptyRowHtml());
            $('#supplier-invoices-tbody').append($row);
            initDeadlinePicker($row.find('.supplier-invoice-deadline'));
            renderInvoiceFileUi($row);
            $row.find('[data-toggle="tooltip"]').tooltip({ container: 'body', boundary: 'viewport', trigger: 'hover' });
        });

        $('#supplier-invoices-tbody').on('click', '.supplier-invoice-remove', function() {
            var $row = $(this).closest('tr');
            if (!$row.data('supplier-invoice-id')) {
                $row.remove();
            } else {
                $row.attr('data-deleted', '1').hide();
            }
        });

        // Attach / Replace -> open the row's hidden file picker.
        $('#supplier-invoices-tbody').on('click', '.supplier-invoice-file-attach', function() {
            $(this).closest('tr').find('.supplier-invoice-file-input').trigger('click');
        });

        // Remove -> clear the attachment. For a saved row the empty path is sent
        // on the next booking save and the controller nulls InvoiceFilePath.
        $('#supplier-invoices-tbody').on('click', '.supplier-invoice-file-remove', function() {
            var $row = $(this).closest('tr');
            $row.attr('data-file-path', '');
            renderInvoiceFileUi($row);
        });

        // File chosen -> upload immediately (the main save posts url-encoded JSON
        // and cannot carry files).
        $('#supplier-invoices-tbody').on('change', '.supplier-invoice-file-input', function() {
            var input = this;
            if (!input.files || !input.files.length) { return; }
            var $row = $(input).closest('tr');
            var $attachBtn = $row.find('.supplier-invoice-file-attach');
            var fd = new FormData();
            fd.append('booking_id', $('#upload_booking_id').val() || '<?php echo isset($BookingID) ? (int)$BookingID : ''; ?>');
            fd.append('supplier_invoice_id', $row.data('supplier-invoice-id') || 0);
            fd.append('invoice_file', input.files[0]);

            $attachBtn.prop('disabled', true).html('<i class="la la-spinner la-spin"></i>');
            $.ajax({
                url: supplierInvoiceUploadUrl,
                type: 'post',
                data: fd,
                processData: false,
                contentType: false,
                dataType: 'json',
                success: function(res) {
                    if (res && res.success) {
                        $row.attr('data-file-path', res.file_path);
                        renderInvoiceFileUi($row);
                    } else {
                        alert((res && res.message) ? res.message : 'Upload failed');
                        renderInvoiceFileUi($row);
                    }
                },
                error: function() {
                    alert('Upload failed. Please try again.');
                    renderInvoiceFileUi($row);
                },
                complete: function() {
                    input.value = ''; // allow re-selecting the same file
                }
            });
        });

        // Activate datepicker + file controls on any server-rendered rows.
        $('#supplier-invoices-tbody tr.supplier-invoice-row').each(function() {
            initDeadlinePicker($(this).find('.supplier-invoice-deadline'));
            renderInvoiceFileUi($(this));
        });
        $('#supplier-invoices-tbody [data-toggle="tooltip"]').tooltip({ container: 'body', boundary: 'viewport', trigger: 'hover' });
    })();

    function _supplierInvoiceFormatDeadline(displayValue) {
        if (!displayValue) return null;
        var parts = displayValue.split('/');
        if (parts.length !== 3) return null;
        return parts[2] + '-' + parts[1] + '-' + parts[0];
    }

    window.Collect_Supplier_Invoices = function() {
        var createRows = [];
        var updateRows = [];
        var deleteRows = [];
        $('#supplier-invoices-tbody tr.supplier-invoice-row').each(function() {
            var $row = $(this);
            var existingId = $row.data('supplier-invoice-id');
            var isDeleted = $row.attr('data-deleted') === '1';

            if (isDeleted) {
                if (existingId) {
                    deleteRows.push({SupplierInvoiceID: existingId, Status: 'N'});
                }
                return; // new row marked deleted -> just drop it
            }

            var supplier_id    = $row.find('.supplier-invoice-supplier').val();
            var invoice_number = ($row.find('.supplier-invoice-number').val() || '').trim();
            var invoice_amount = $row.find('.supplier-invoice-amount').val();
            var deadline_disp  = $row.find('.supplier-invoice-deadline').val();
            var remark         = $row.find('.supplier-invoice-remark').val() || '';
            var file_path      = $row.attr('data-file-path') || '';

            // Skip blank new rows (everything empty, including no attachment).
            if (!existingId && !supplier_id && !invoice_number && !invoice_amount && !deadline_disp && !remark && !file_path) {
                return;
            }

            var payload = {
                SupplierID: supplier_id ? parseInt(supplier_id, 10) : null,
                InvoiceNumber: invoice_number,
                InvoiceAmount: invoice_amount === '' ? 0 : parseFloat(invoice_amount),
                PaymentDeadline: _supplierInvoiceFormatDeadline(deadline_disp),
                Remark: remark,
                InvoiceFilePath: file_path
            };

            if (existingId) {
                payload.SupplierInvoiceID = existingId;
                var initialSupplier = String($row.data('initial-supplier-id') || '');
                var initialNumber   = String($row.data('initial-invoice-number') || '');
                var initialAmount   = String($row.data('initial-invoice-amount') || '');
                var initialDeadline = String($row.data('initial-payment-deadline') || '');
                var initialRemark   = String($row.data('initial-remark') || '');
                var initialFile     = String($row.attr('data-initial-file-path') || '');
                var changed =
                    String(payload.SupplierID || '')      !== initialSupplier
                    || String(payload.InvoiceNumber)      !== initialNumber
                    || String(payload.InvoiceAmount)      !== String(initialAmount === '' ? 0 : parseFloat(initialAmount))
                    || String(payload.PaymentDeadline || '') !== initialDeadline
                    || String(payload.Remark)             !== initialRemark
                    || String(file_path)                  !== initialFile;
                if (changed) {
                    updateRows.push(payload);
                }
            } else {
                createRows.push(payload);
            }
        });
        return [createRows, updateRows, deleteRows];
    };
</script>

<script>
// Draft: admin booking form field locking + graduate buttons.
//
// While a booking sits in SAD ("SAVE AS DRAFT") only a small whitelist of fields
// is editable; everything else is disabled (a UX guardrail — the controller also
// strips non-whitelisted columns server-side). Approving then graduating the
// draft replaces the single save button with the graduate buttons.
$(function() {
    // Element IDs the admin may edit on a draft. Travel date is the #TravelDate
    // picker; it posts as StartDate/EndDate. CustomerID is the hidden companion
    // to the Customer search and must stay readable.
    // A draft has no products/pricing yet, so the Deposit Deadline
    // (kt_datepicker_4_3) and Full Payment Deadline (kt_datepicker_4_4) are
    // intentionally left OUT of this whitelist — they grey out while in draft.
    var DRAFT_EDITABLE_IDS = ['is_draft_intake_toggle', 'Customer', 'CustomerID', 'Mobile', 'CountryCodeID', 'SalesAgent2', 'Destination', 'TravelDate', 'BookingFormText', 'ChatSummary', 'ic_passport_no', 'customer_type', 'Source', 'ChatLanguage'];
    var CURRENT_ADMIN_ID = '<?php echo (int) $this->session->userdata('admin_id'); ?>';

    function applyDraftLock(on) {
        $('#form').find('input, select, textarea').each(function() {
            var t = (this.type || '').toLowerCase();
            if (t === 'button' || t === 'submit' || t === 'hidden') { return; }
            // bootstrap-select's live-search inputs have no id and are managed by
            // the plugin based on the parent select's disabled state. Leave them
            // alone so whitelisted selects (e.g. Destination) keep a usable search.
            if ($(this).closest('.bs-searchbox').length) { return; }
            // Supplier Invoices stay editable on a parked draft so staff can
            // upload invoices before the BC is approved. Incomplete rows (no
            // supplier / no number) are dropped server-side, so this can't
            // break the Approve save with a NOT NULL violation.
            if ($(this).closest('#supplier-invoices').length) { return; }
            if (DRAFT_EDITABLE_IDS.indexOf(this.id) !== -1) { return; }
            $(this).prop('disabled', !!on);
            if ($(this).hasClass('selectpicker')) {
                $(this).selectpicker('refresh');
            }
        });
    }

    // "presales auto select": default Sales Agent 2 (Pre Sales) to the logged-in
    // admin when it hasn't been set yet.
    function autoSelectPresales() {
        var $sa2 = $('#SalesAgent2');
        if ($sa2.length && !$sa2.val() && CURRENT_ADMIN_ID) {
            $sa2.val(CURRENT_ADMIN_ID);
            if ($sa2.hasClass('selectpicker')) { $sa2.selectpicker('refresh'); }
        }
    }

    <?php if (current_url() == base_url('Booking/Update') && $is_draft_booking) { ?>
        // Parked draft (SAD) — lock to the whitelist. The form unlocks once the
        // draft graduates out of SAD (Save as Pending BC / PBC), where the
        // graduate buttons enforce the normal required-field rules.
        applyDraftLock(true);
        autoSelectPresales();
    <?php } ?>

    // Create page: ticking the draft toggle restricts the form live.
    $('#is_draft_intake_toggle').on('change', function() {
        var on = $(this).is(':checked');
        applyDraftLock(on);
        if (on) { autoSelectPresales(); }
    });

    // Draft lifecycle buttons set the save mode then run the normal save path.
    function runDraftSave(mode) {
        $('#draft_save_mode').val(mode);
        $('#main_submit_btn').trigger('click');
    }
    $('#draft_save_btn').on('click',    function() { runDraftSave('draft'); });
    $('#draft_approve_btn').on('click', function() { runDraftSave('approve'); });
    $('#graduate_pb_btn').on('click',   function() { runDraftSave('PB'); });
    $('#graduate_pbc_btn').on('click',  function() { runDraftSave('PBC'); });
});
</script>
