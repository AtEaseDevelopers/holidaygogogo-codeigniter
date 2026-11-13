<?php

class Costing extends MY_Controller
{
    public function __construct()
    {
        parent::__construct();
        $this->load->model('Costing_Model');
    }

    /**
     * The package-listing filter set, read identically by the on-screen listing
     * and the Excel export so both scope to the exact same records.
     */
    private function Package_List_Filters()
    {
        return array(
            'search' => trim((string) $this->input->get('search')),
            'tour_code' => trim((string) $this->input->get('tour_code')),
            'status' => trim((string) $this->input->get('status')),
            'customer_name' => trim((string) $this->input->get('customer_name')),
            'customer_contact' => trim((string) $this->input->get('customer_contact')),
            'customer_email' => trim((string) $this->input->get('customer_email')),
            'sales_admin_id' => (int) $this->input->get('sales_admin_id'),
            'op_admin_id' => (int) $this->input->get('op_admin_id'),
            'created_from' => trim((string) $this->input->get('created_from')),
            'created_to' => trim((string) $this->input->get('created_to')),
            'validity_from' => trim((string) $this->input->get('validity_from')),
            'validity_to' => trim((string) $this->input->get('validity_to')),
        );
    }

    public function index()
    {
        $titles = array(
            'tab_title' => 'HolidayGoGoGo | Costing Packages',
            'breadcrumb_title' => 'Setting >> Costing >> Packages',
        );

        $filters = $this->Package_List_Filters();

        $array = $this->Costing_Model->Read_Packages_Dashboard($filters);
        $array['filters'] = $filters;
        // Listing filter lists only currently-active sales people.
        $array['sales_agents'] = $this->Costing_Model->Read_Active_Sales_Agents();

        $this->load->view('layout/header', $titles);
        $this->load->view('costing/index', $array);
        $this->load->view('layout/footer');
    }

    /**
     * Stream the whole (filtered) package listing to a multi-sheet .xlsx in the
     * SAME layout as the Import template (Packages / Cost Items / Combinations /
     * Hotels / Flights / Itinerary), plus a Tour Code column on every sheet, so an
     * export can be edited and re-uploaded through Import. The export helper reuses
     * the import column maps, so both are loaded.
     */
    public function Export_Packages()
    {
        $this->load->helper('costing_packages_import');
        $this->load->helper('costing_packages_export');
        $this->load->helper('costing_itinerary');
        $data = $this->Costing_Model->Read_Packages_For_Export($this->Package_List_Filters());
        costing_packages_export_stream($data, 'COSTING_PACKAGES_' . date('Ymd') . '.xlsx');
    }

    /**
     * The lookup lists the import template + parser need: currency codes/map,
     * active category codes (code => canonical) + a display map, and sales-person
     * names. Built once so Import_Template and Import stay in sync.
     */
    private function Import_Lookups()
    {
        $this->load->model('Costing_Category_Model');

        $currencies = $this->Costing_Model->Read_Currency_List();
        $currency_by_code = array();
        $currency_codes = array();
        foreach ($currencies as $cur) {
            $code = strtoupper(trim((string) $cur['code']));
            if ($code === '') { continue; }
            $currency_by_code[$code] = (int) $cur['id'];
            $currency_codes[] = $code;
        }

        // code => name display map, and a lowercased code => canonical-code lookup
        // the parser validates against.
        $category_map = $this->Costing_Category_Model->Read_Category_Map();
        $category_codes = array();
        foreach ($category_map as $code => $name) {
            $category_codes[strtolower((string) $code)] = (string) $code;
        }

        $sales_by_name = array();
        $sales_names = array();
        foreach ($this->Costing_Model->Read_Sales_Agents(0, true) as $agent) {
            $name = trim((string) $agent['Name']);
            if ($name === '') { continue; }
            $sales_by_name[strtolower($name)] = (int) $agent['AdminID'];
            $sales_names[] = $name;
        }

        return array(
            'currency_by_code' => $currency_by_code,
            'currency_codes'   => $currency_codes,
            'category_codes'   => $category_codes,
            'category_map'     => $category_map,
            'sales_by_name'    => $sales_by_name,
            'sales_names'      => $sales_names,
        );
    }

    /**
     * Download the multi-sheet Costing Package import template (Packages / Cost
     * Items / Combinations / Hotels / Flights + an Instructions sheet listing the
     * valid category/currency codes and sales-person names).
     */
    public function Import_Template()
    {
        $this->load->helper('costing_packages_import');
        costing_packages_import_template_stream(
            $this->Import_Lookups(),
            'COSTING_PKG_IMPORT_TEMPLATE_' . date('Ymd') . '.xlsx'
        );
    }

    /**
     * Bulk-create costing packages from an uploaded template. Each sheet is parsed
     * into records, assembled (pure) into per-package save payloads, then handed to
     * Costing_Model::Import_Package which reuses the exact wizard save paths
     * (Save_Package -> Generate_Booking_Snapshot -> Save_Quote_Details ->
     * Save_Itinerary_Days). Import is a TWO-step flow:
     *   Import()        validates + previews (shows a confirmation with per-package
     *                   counts + every error/warning) — creates NOTHING;
     *   Import_Commit() re-reads the same saved file and actually creates the
     *                   packages the user confirmed, one transaction each.
     */

    /** Backup dir for uploaded import files (kept to re-read on confirm). */
    private function Import_Dir()
    {
        $dir = FCPATH . 'assets/upload/costing_package_import/';
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        return $dir;
    }

    /**
     * Parse one saved import workbook into the assembled per-package payloads.
     * Returns null when the file cannot be read as a spreadsheet.
     */
    private function Import_Assemble_From_File($dest)
    {
        require_once FCPATH . 'vendor/autoload.php';
        // Assembly promotes the four "one bullet per line" note cells into
        // <ul><li> HTML via costing_itinerary_html_from_lines().
        $this->load->helper('costing_itinerary');
        try {
            $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($dest);
        } catch (\Exception $e) {
            return null;
        }

        $parsed = array();
        foreach (costing_import_sheets_meta() as $meta) {
            list($key, $title, $columns) = $meta;
            // The Packages sheet is parsed with a Tour-Code-aware column map so an
            // EXPORT's TOUR CODE column is captured (to match an existing revision
            // family). The template itself has no such column — header-aware parsing
            // then just leaves tour_code blank.
            if ($key === 'packages') {
                $columns = costing_import_package_columns_with_tour_code();
            }
            $ws = $spreadsheet->getSheetByName($title);
            $rows = $ws ? $ws->toArray(null, true, true, false) : array();
            $parsed[$key] = costing_import_sheet_to_records($rows, $columns);
        }

        return costing_import_assemble_packages($parsed, $this->Import_Lookups());
    }

    /**
     * Build the revision-family lookup the import "new vs revision" decision needs:
     * for every distinct TOUR CODE base in the uploaded packages, resolve the existing
     * family (root id, root name, next revision code) — or omit it when no package
     * carries that base. Keyed by lower-cased base code to match
     * costing_import_revision_decisions().
     */
    private function Import_Revision_Families($packages)
    {
        $this->load->helper('costing_tour_code');

        $families = array();
        foreach ((array) $packages as $pkg) {
            $code = trim((string) (isset($pkg['tour_code']) ? $pkg['tour_code'] : ''));
            if ($code === '') {
                continue;
            }
            $base = strtolower(costing_tour_code_base($code));
            if ($base === '' || isset($families[$base])) {
                continue;
            }
            $family = $this->Costing_Model->Read_Revision_Family_By_Base($code);
            if ($family !== null) {
                $families[$base] = $family;
            }
        }

        return $families;
    }

    /**
     * Step 1 — upload, validate and PREVIEW. Saves the workbook (newest 3 kept),
     * assembles it, and renders the confirmation screen listing what will be
     * created and every error/warning. Creates nothing.
     */
    public function Import()
    {
        $this->load->helper('costing_packages_import');

        if ($this->input->server('REQUEST_METHOD') !== 'POST' || empty($_FILES['import_file']['name'])) {
            redirect('Costing');
            return;
        }

        $file = $_FILES['import_file'];
        if ($file['error'] !== UPLOAD_ERR_OK) {
            $this->session->set_flashdata('message_error', 'Upload failed. Please try again.');
            redirect('Costing');
            return;
        }
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, array('xlsx', 'xls'), true)) {
            $this->session->set_flashdata('message_error', 'Please upload an Excel file (.xlsx or .xls).');
            redirect('Costing');
            return;
        }
        if ($file['size'] > 10 * 1024 * 1024) {
            $this->session->set_flashdata('message_error', 'File too large. Maximum size is 10MB.');
            redirect('Costing');
            return;
        }

        $dir = $this->Import_Dir();
        $basename = 'costing_package_import_' . time() . '.' . $ext;
        $dest = $dir . $basename;
        if (!move_uploaded_file($file['tmp_name'], $dest)) {
            $this->session->set_flashdata('message_error', 'Could not save the uploaded file.');
            redirect('Costing');
            return;
        }
        // Keep only the newest 3 uploads as backups.
        $stamped = array();
        foreach (glob($dir . 'costing_package_import_*') as $path) {
            $stamped[$path] = filemtime($path);
        }
        arsort($stamped);
        foreach (array_slice(array_keys($stamped), 3) as $old) {
            @unlink($old);
        }

        $assembled = $this->Import_Assemble_From_File($dest);
        if ($assembled === null) {
            @unlink($dest);
            $this->session->set_flashdata('message_error', 'Could not read the Excel file. Please use the downloaded template.');
            redirect('Costing');
            return;
        }

        $summary = costing_import_preview_summary($assembled);
        if ($summary['total'] === 0 && empty($summary['global_errors'])) {
            @unlink($dest);
            $this->session->set_flashdata('message_error', 'No package rows found in the file. Nothing to import.');
            redirect('Costing');
            return;
        }

        // Decide per package whether it creates a brand-new package or a revision of an
        // existing family (matched on the TOUR CODE column). The default (revision when
        // matched) is shown; the user confirms/overrides per row on the preview.
        $families = $this->Import_Revision_Families($assembled['packages']);
        $decisions = costing_import_revision_decisions($assembled['packages'], $families);
        foreach ($summary['rows'] as $i => $row) {
            $summary['rows'][$i]['revision'] = isset($decisions[$i]) ? $decisions[$i] : array(
                'mode' => 'new', 'has_match' => false, 'base_code' => '', 'root_id' => 0, 'root_name' => '', 'target_code' => '',
            );
        }

        $titles = array(
            'tab_title' => 'HolidayGoGoGo | Confirm Costing Import',
            'breadcrumb_title' => 'Setting >> Costing >> Import Preview',
        );
        $this->load->view('layout/header', $titles);
        $this->load->view('costing/import_preview', array(
            'summary' => $summary,
            'import_token' => $basename, // re-read this exact file on confirm
        ));
        $this->load->view('layout/footer');
    }

    /**
     * Step 2 — CONFIRM. Re-reads the previewed workbook (by its basename token),
     * re-assembles, and creates every package with no blocking errors. Reports the
     * result; warnings never block a package.
     */
    public function Import_Commit()
    {
        $this->load->helper('costing_packages_import');

        $token = (string) $this->input->post('import_token');
        $base = basename($token); // strip any path components
        if ($base === '' || !preg_match('/^costing_package_import_\d+\.(xlsx|xls)$/', $base)) {
            $this->session->set_flashdata('message_error', 'Import session expired. Please upload the file again.');
            redirect('Costing');
            return;
        }
        $dest = $this->Import_Dir() . $base;
        if (!is_file($dest)) {
            $this->session->set_flashdata('message_error', 'The uploaded file is no longer available. Please upload it again.');
            redirect('Costing');
            return;
        }

        $assembled = $this->Import_Assemble_From_File($dest);
        if ($assembled === null) {
            $this->session->set_flashdata('message_error', 'Could not read the Excel file. Please upload it again.');
            redirect('Costing');
            return;
        }

        // Re-resolve the new-vs-revision decision with the user's per-row choices
        // (posted as import_mode[<index>]). Indexes are stable because assembly is
        // deterministic for the same file.
        $families = $this->Import_Revision_Families($assembled['packages']);
        $posted_modes = (array) $this->input->post('import_mode');
        $decisions = costing_import_revision_decisions($assembled['packages'], $families, $posted_modes);

        $admin_id = (int) $this->session->userdata('admin_id');
        $created = 0;
        $lines = array();
        foreach ($assembled['global_errors'] as $err) {
            $lines[] = $err;
        }
        foreach ($assembled['packages'] as $i => $pkg) {
            if (!empty($pkg['errors'])) {
                $lines[] = 'Skipped "' . $pkg['name'] . '": ' . implode(' ', $pkg['errors']);
                continue;
            }

            $decision = isset($decisions[$i]) ? $decisions[$i] : array('mode' => 'new', 'root_id' => 0);
            $as_revision = ($decision['mode'] === 'revision') && !empty($decision['root_id']);

            $result = $as_revision
                ? $this->Costing_Model->Import_Package_As_Revision($pkg, (int) $decision['root_id'], $admin_id)
                : $this->Costing_Model->Import_Package($pkg, $admin_id);

            if (!empty($result['ok'])) {
                $created++;
                if ($as_revision) {
                    $lines[] = 'Imported "' . $pkg['name'] . '" as revision ' . $result['tour_code'] . '.';
                }
                foreach ((array) $pkg['warnings'] as $w) {
                    $lines[] = 'Imported "' . $pkg['name'] . '" with a note: ' . $w;
                }
            } else {
                $lines[] = 'Failed to save "' . $pkg['name'] . '".';
            }
        }

        if ($created > 0) {
            $this->session->set_flashdata('message_success', $created . ' costing package(s) imported.');
        } elseif (empty($lines)) {
            $this->session->set_flashdata('message_error', 'Nothing was imported.');
        }
        if (!empty($lines)) {
            $this->session->set_flashdata('import_result', $lines);
        }
        redirect('Costing');
    }

    public function Create()
    {
        // Brand-new package: open the wizard directly on step 1 with a blank form.
        // Saving step 1 creates the package and moves on to the cost step.
        $array = $this->Costing_Model->Read_Package_Workspace_Data(0, 0, 'MYR', true);
        $array['active_step'] = 'details';
        $array['has_snapshot'] = false;
        $array['wizard_steps'] = $this->Wizard_Steps();
        $array['op_assignees'] = $this->Package_OP_Assignees();
        $array['package']['op_admin_id'] = (int) $this->session->userdata('admin_id');

        $titles = array(
            'tab_title' => 'HolidayGoGoGo | New Costing Package',
            'breadcrumb_title' => 'Setting >> Costing >> New Package',
        );

        $this->load->view('layout/header', $titles);
        $this->load->view('costing/wizard', $array);
        $this->load->view('layout/footer');
    }

    public function Package($package_id = null)
    {
        $package_id = (int) $package_id;
        if ($package_id <= 0) {
            redirect('Costing');
            return;
        }

        $step = $this->Normalize_Wizard_Step($this->input->get('step', true));

        // One costing package holds at most ONE snapshot. When it exists we load
        // it so the cost step is pre-filled; otherwise the cost step starts from
        // the item master.
        $existing_booking_id = $this->Costing_Model->Existing_Booking_Id($package_id);
        $force_new_snapshot = $existing_booking_id <= 0;

        $array = $this->Costing_Model->Read_Package_Workspace_Data($package_id, $existing_booking_id, 'MYR', $force_new_snapshot);

        // A soft-deleted package is hidden from the listing, so there's no link to
        // it — but refuse a direct URL too, so it can't be opened or edited.
        if (empty($array['package']['id']) || strtolower((string) $array['package']['status']) === 'deleted') {
            $this->session->set_flashdata('message_error', 'Costing package not found.');
            redirect('Costing');
            return;
        }

        $array['active_step'] = $step;
        $array['has_snapshot'] = $existing_booking_id > 0;
        $array['wizard_steps'] = $this->Wizard_Steps();
        if ($step === 'details') {
            $array['op_assignees'] = $this->Package_OP_Assignees();
        }

        // A saved snapshot's quotation link is minted on demand (e.g. a duplicated
        // package carries no token). The wizard's final step needs it to show the
        // quotation, so ensure it here — otherwise a package WITH a cost template
        // wrongly shows the "add the cost template first" prompt.
        if ($existing_booking_id > 0 && empty($array['quotation_token'])) {
            $array['quotation_token'] = $this->Costing_Model->Ensure_Quotation_Token($existing_booking_id);
        }

        // Supplier names for the combination item "pick existing OR type new"
        // datalist. Optional free text — a typed-in name is never saved back here.
        $this->load->model('Supplier_Model');
        $array['supplier_names'] = $this->Supplier_Model->Read_Supplier_Names();

        // Supplier quotation attachments kept against this package, so the cost
        // step can list them and each cost item can link to its supplier's quote.
        $this->load->model('Costing_Quotation_File_Model');
        $this->load->helper('costing_quotation');
        $array['quotation_files'] = $this->Costing_Quotation_File_Model->Read_By_Package(
            $package_id,
            (int) $this->session->userdata('admin_id')
        );

        $titles = array(
            'tab_title' => 'HolidayGoGoGo | Costing Package',
            'breadcrumb_title' => 'Setting >> Costing >> Package',
        );

        $this->load->view('layout/header', $titles);
        $this->load->view('costing/wizard', $array);
        $this->load->view('layout/footer');
    }

    private function Package_OP_Assignees()
    {
        $this->load->model('Booking_Model');
        return array_column($this->Booking_Model->Read_Manual_Lead_Assignees(), 'Name', 'AdminID');
    }

    public function Save_Package()
    {
        $existing_package_id = (int) $this->input->post('package_id');
        $requested_op = $this->input->post('op_admin_id');
        if ($existing_package_id <= 0 && $requested_op === null) {
            $requested_op = $this->session->userdata('admin_id');
        }
        $op_admin_id = null;
        // An empty value on an edit preserves the stored assignment, including
        // an older assignment that no longer appears in the eligible staff list.
        if ($existing_package_id <= 0 || ($requested_op !== null && $requested_op !== '')) {
            $op_admin_id = filter_var($requested_op, FILTER_VALIDATE_INT, array('options' => array('min_range' => 1)));
            $assignees = $this->Package_OP_Assignees();
            if ($op_admin_id === false || !isset($assignees[$op_admin_id])) {
                $this->session->set_flashdata('message_error', 'Please select an eligible OP Person.');
                redirect($existing_package_id > 0 ? 'Costing/Package/' . $existing_package_id . '?step=details' : 'Costing/Create');
                return;
            }
        }

        $package_id = $this->Costing_Model->Save_Package(array(
            'id' => $existing_package_id,
            'name' => $this->input->post('name'),
            'customer_name' => $this->input->post('customer_name'),
            'customer_contact' => $this->input->post('customer_contact'),
            'customer_email' => $this->input->post('customer_email'),
            'sales_admin_id' => $this->input->post('sales_admin_id'),
            'sales_admin_mobile' => $this->input->post('sales_admin_mobile'),
            'related_tour_code' => $this->input->post('related_tour_code'),
            'op_admin_id' => $op_admin_id,
            'validity_date' => $this->input->post('validity_date'),
            'duration_days' => $this->input->post('duration_days'),
            'duration_nights' => $this->input->post('duration_nights'),
            'description' => $this->input->post('description'),
            'status' => $this->input->post('status'),
        ));

        if ($package_id > 0) {
            $this->session->set_flashdata('message_success', ($existing_package_id > 0)
                ? 'Package details saved.'
                : 'Package created. Continue with the costing.');
            $next = $this->Normalize_Wizard_Step($this->input->post('wizard_next'));
            redirect('Costing/Package/' . $package_id . '?step=' . $next);
            return;
        }

        $this->session->set_flashdata('message_error', 'Package name is required.');
        redirect($existing_package_id > 0 ? 'Costing/Package/' . $existing_package_id . '?step=details' : 'Costing');
    }

    public function Duplicate_Package()
    {
        $package_id = (int) $this->input->post('package_id');
        if ($package_id > 0) {
            $new_package_id = $this->Costing_Model->Duplicate_Package($package_id, (int) $this->session->userdata('admin_id'));
            if ($new_package_id > 0) {
                $this->session->set_flashdata('message_success', 'Package duplicated successfully.');
                redirect('Costing/Package/' . $new_package_id . '?step=details');
                return;
            }
            $this->session->set_flashdata('message_error', 'Unable to duplicate package.');
        }

        redirect('Costing');
    }

    public function Create_Revision()
    {
        $package_id = (int) $this->input->post('package_id');
        if ($package_id > 0) {
            $new_package_id = $this->Costing_Model->Create_Revision($package_id, (int) $this->session->userdata('admin_id'));
            if ($new_package_id > 0) {
                $this->session->set_flashdata('message_success', 'Revision created successfully.');
                redirect('Costing/Package/' . $new_package_id . '?step=details');
                return;
            }
            $this->session->set_flashdata('message_error', 'Unable to create revision.');
        }

        redirect('Costing');
    }

    public function Delete_Package()
    {
        $package_id = (int) $this->input->post('package_id');
        if ($package_id > 0) {
            $success = $this->Costing_Model->Delete_Package($package_id, (int) $this->session->userdata('admin_id'));
            $this->session->set_flashdata($success ? 'message_success' : 'message_error', $success
                ? 'Package deleted successfully.'
                : 'Unable to delete package.');
        }

        redirect('Costing');
    }

    /**
     * Wizard step 2. Build (or update) the package's single snapshot from the
     * posted cost rows + pax + margin, then advance to the itinerary step. Reuses
     * the model's Generate_Booking_Snapshot, which creates-or-updates the one
     * snapshot and freezes the currency rates.
     */
    public function Save_Cost_Step()
    {
        $package_id = (int) $this->input->post('package_id');
        $booking_id = $this->Costing_Model->Generate_Booking_Snapshot($package_id, array(
            'booking_id' => (int) $this->input->post('booking_id'),
            'travel_date' => $this->input->post('travel_date_start'),
            'travel_date_end' => $this->input->post('travel_date_end'),
            'adult_count' => $this->input->post('adult_count'),
            'child_count' => $this->input->post('child_count'),
            'status' => $this->input->post('status'),
            'rows' => $this->input->post('rows'),
            'combinations' => $this->input->post('combinations'),
            'margin_percentage' => $this->input->post('margin_percentage'),
            'commissionable_per_pax' => $this->input->post('commissionable_per_pax'),
            'ad_hoc_per_pax' => $this->input->post('ad_hoc_per_pax'),
        ));

        if ($booking_id > 0) {
            $this->session->set_flashdata('message_success', 'Cost template saved.');
            redirect('Costing/Package/' . $package_id . '?step=itinerary');
            return;
        }

        $this->session->set_flashdata('message_error', 'Add at least one cost item and make sure total pax is greater than zero.');
        redirect('Costing/Package/' . $package_id . '?step=cost');
    }

    /**
     * Wizard step 3. Replace-all save of the itinerary, then advance to the final
     * Save & Quotation step.
     */
    public function Save_Itinerary()
    {
        $package_id = (int) $this->input->post('package_id');
        // Four separate itinerary-wide rich-text sections, keyed by section key.
        $level_fields = array(
            'include'         => $this->input->post('itinerary_include'),
            'exclude'         => $this->input->post('itinerary_exclude'),
            'important_notes' => $this->input->post('itinerary_important_notes'),
            'terms'           => $this->input->post('itinerary_terms'),
        );
        // Standalone Tour Intro header (shown at the TOP of the Itinerary on the PDF).
        $tour_intro = $this->input->post('itinerary_tour_intro');
        if ($package_id > 0 && $this->Costing_Model->Save_Itinerary_Days($package_id, (array) $this->input->post('itinerary'), $level_fields, $tour_intro)) {
            $this->session->set_flashdata('message_success', 'Itinerary saved.');
        } else {
            $this->session->set_flashdata('message_error', 'Unable to save itinerary.');
        }

        redirect('Costing/Package/' . $package_id . '?step=logistics');
    }

    /**
     * Wizard step 4. Replace-all save of the hotel-pricing + flight-schedule
     * tables and their quote-level free-text (pricing basis, notes, flight
     * price/expiry), then advance to the final Save & Quotation step. These drive
     * the customer Quotation PDF.
     */
    public function Save_Logistics()
    {
        $package_id = (int) $this->input->post('package_id');
        $level_fields = array(
            'quote_pricing_basis'    => $this->input->post('quote_pricing_basis'),
            'quote_travel_date_note' => $this->input->post('quote_travel_date_note'),
            'quote_hotel_note'       => $this->input->post('quote_hotel_note'),
            // Legacy package-level flight fields — superseded by flight options, kept
            // for the prepare contract (posted blank by the new form).
            'quote_flight_title'     => $this->input->post('quote_flight_title'),
            'quote_flight_price'     => $this->input->post('quote_flight_price'),
            'quote_flight_fare_note' => $this->input->post('quote_flight_fare_note'),
            'quote_flight_expiry'    => $this->input->post('quote_flight_expiry'),
            'quote_footer_notes'     => $this->input->post('quote_footer_notes'),
            // 18 Sep 2026 rework: flight mode (4.3) + hotel pricing columns (4.2).
            'quote_flight_mode'      => $this->input->post('quote_flight_mode'),
            'quote_hotel_columns'    => (array) $this->input->post('quote_hotel_columns'),
            // 20 Oct 2026: per-column % used to auto-fill the 2nd+ columns from col 1.
            'quote_hotel_pcts'       => (array) $this->input->post('quote_hotel_pcts'),
            // 5 Oct 2026: first-column title (Hotel / Room Type) + Single Supp on/off.
            'quote_hotel_title_label' => $this->input->post('quote_hotel_title_label'),
            'quote_show_single_supp'  => $this->input->post('quote_show_single_supp'),
        );

        if ($package_id > 0 && $this->Costing_Model->Save_Quote_Details(
            $package_id,
            (array) $this->input->post('hotels'),
            (array) $this->input->post('flight_options'),
            $level_fields
        )) {
            $this->session->set_flashdata('message_success', 'Hotel & flight details saved.');
            redirect('Costing/Package/' . $package_id . '?step=done');
            return;
        }

        $this->session->set_flashdata('message_error', 'Unable to save hotel & flight details.');
        redirect('Costing/Package/' . $package_id . '?step=logistics');
    }

    public function Currency()
    {
        $titles = array(
            'tab_title' => 'HolidayGoGoGo | Costing Currency',
            'breadcrumb_title' => 'Setting >> Costing >> Currency',
        );

        $array = $this->Costing_Model->Read_Currency_Dashboard_Data();

        $this->load->view('layout/header', $titles);
        $this->load->view('costing/currency', $array);
        $this->load->view('layout/footer');
    }

    /**
     * Stream every currency's full MYR rate history to an .xlsx download.
     */
    public function Export_Currency_History()
    {
        $this->load->helper('costing_currency_export');
        $histories = $this->Costing_Model->Read_Exchange_Rate_History_For_Export();
        costing_currency_export_stream($histories, 'COSTING_CURRENCY_HISTORY_' . date('Ymd') . '.xlsx');
    }

    public function Save_Currency()
    {
        $active_tab = $this->Normalize_Currency_Tab($this->input->post('active_tab'));
        $currency_id = (int) $this->input->post('currency_id');
        $code = strtoupper(trim((string) $this->input->post('code')));
        $name = trim((string) $this->input->post('name'));
        $symbol = trim((string) $this->input->post('symbol'));

        if ($code === '' || strlen($code) !== 3 || $name === '') {
            $this->session->set_flashdata('message_error', 'Currency code must be 3 letters and name is required.');
            redirect('Costing/Currency?active_tab=' . $active_tab);
            return;
        }

        $success = $this->Costing_Model->Save_Currency(array(
            'id' => $currency_id,
            'code' => $code,
            'name' => $name,
            'symbol' => $symbol === '' ? null : $symbol,
        ));

        if ($success) {
            $this->session->set_flashdata('message_success', $currency_id > 0 ? 'Currency updated successfully.' : 'Currency created successfully.');
        } else {
            $this->session->set_flashdata('message_error', 'Unable to save currency. The code may already exist.');
        }

        redirect('Costing/Currency?active_tab=' . $active_tab);
    }

    public function Save_Exchange_Rate()
    {
        $active_tab = $this->Normalize_Currency_Tab($this->input->post('active_tab'));
        $exchange_rate_id = (int) $this->input->post('exchange_rate_id');
        $from_currency_id = (int) $this->input->post('from_currency_id');
        $to_currency_id = (int) $this->input->post('to_currency_id');
        $unit_amount = (float) $this->input->post('unit_amount');
        $converted_amount = (float) $this->input->post('converted_amount');
        $bank_charges_myr = (float) $this->input->post('bank_charges_myr');
        $buffer = (float) $this->input->post('buffer');
        $valid_from = trim((string) $this->input->post('valid_from'));

        if ($from_currency_id <= 0 || $to_currency_id <= 0 || $unit_amount <= 0 || $converted_amount <= 0 || $bank_charges_myr < 0 || $buffer < 0) {
            $this->session->set_flashdata('message_error', 'From currency, to currency, unit amount, converted amount, and valid bank charges/buffer are required.');
            redirect('Costing/Currency?active_tab=' . $active_tab);
            return;
        }

        if ($from_currency_id === $to_currency_id) {
            $this->session->set_flashdata('message_error', 'Same-currency exchange-rate mapping is not allowed.');
            redirect('Costing/Currency?active_tab=' . $active_tab);
            return;
        }

        if ($valid_from === '') {
            $valid_from = date('Y-m-d H:i:s');
        } elseif (strlen($valid_from) === 16) {
            $valid_from .= ':00';
        }

        $success = $this->Costing_Model->Save_Exchange_Rate(array(
            'id' => $exchange_rate_id,
            'from_currency_id' => $from_currency_id,
            'to_currency_id' => $to_currency_id,
            'unit_amount' => $unit_amount,
            'converted_amount' => $converted_amount,
            'bank_charges_myr' => $bank_charges_myr,
            'buffer' => $buffer,
            'updated_by_admin_id' => (int) $this->session->userdata('admin_id'),
            'valid_from' => $valid_from,
        ));

        if ($success) {
            $this->session->set_flashdata('message_success', $exchange_rate_id > 0
                ? 'Exchange rate updated successfully.'
                : 'Exchange rate saved as the latest rate for this currency pair.');
        } else {
            $this->session->set_flashdata('message_error', 'Unable to save exchange rate.');
        }

        redirect('Costing/Currency?active_tab=' . $active_tab);
    }

    public function Delete_Currency()
    {
        $active_tab = $this->Normalize_Currency_Tab($this->input->post('active_tab'));
        $result = $this->Costing_Model->Delete_Currency((int) $this->input->post('currency_id'));

        if (!empty($result['success'])) {
            $this->session->set_flashdata('message_success', 'Currency deleted successfully.');
        } else {
            $this->session->set_flashdata('message_error', !empty($result['message']) ? $result['message'] : 'Unable to delete currency.');
        }

        redirect('Costing/Currency?active_tab=' . $active_tab);
    }

    public function Delete_Exchange_Rate()
    {
        $active_tab = $this->Normalize_Currency_Tab($this->input->post('active_tab'));
        $result = $this->Costing_Model->Delete_Exchange_Rate((int) $this->input->post('exchange_rate_id'));

        if (!empty($result['success'])) {
            $this->session->set_flashdata('message_success', 'Exchange rate deleted successfully.');
        } else {
            $this->session->set_flashdata('message_error', !empty($result['message']) ? $result['message'] : 'Unable to delete exchange rate.');
        }

        redirect('Costing/Currency?active_tab=' . $active_tab);
    }

    private function Normalize_Currency_Tab($active_tab)
    {
        $active_tab = strtolower(trim((string) $active_tab));
        return in_array($active_tab, array('currencies', 'rates'), true) ? $active_tab : 'currencies';
    }

    /**
     * AJAX: upload a supplier quotation (PDF / Word / Excel / image) and attach
     * it to a costing package as a reference for the Cost Template & Margin step.
     * Files land in a deny-all protected directory and are only ever served back
     * through Quotation_File() below — never a public asset URL.
     */
    public function Upload_Quotation()
    {
        $out = function ($data) {
            $this->output->set_content_type('application/json')->set_output(json_encode($data));
        };

        $package_id = (int) $this->input->post('package_id');
        if ($package_id <= 0 || !$this->Costing_Model->Package_Exists($package_id)) {
            return $out(array('ok' => false, 'message' => 'Costing package not found.'));
        }

        $this->load->helper('costing_quotation');

        // An entry may be a FILE, a NOTE, or both. A file input left empty sends
        // nothing (our hidden input has no name) or UPLOAD_ERR_NO_FILE — treat
        // either as "no file" rather than an error, so a note-only save is valid.
        $has_file = isset($_FILES['quotation_file'])
            && isset($_FILES['quotation_file']['error'])
            && (int) $_FILES['quotation_file']['error'] !== UPLOAD_ERR_NO_FILE
            && (string) $_FILES['quotation_file']['name'] !== '';

        $note = trim((string) $this->input->post('note'));

        $original  = '';
        $rel_path  = '';
        $file_size = null;

        if ($has_file) {
            $upload_err = (int) $_FILES['quotation_file']['error'];
            if ($upload_err !== UPLOAD_ERR_OK) {
                // Distinguish "too big for the server" from "nothing selected" so the
                // user gets an actionable message (our own 20MB cap may be below the
                // php.ini upload_max_filesize / post_max_size).
                if (in_array($upload_err, array(UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE), true)) {
                    return $out(array('ok' => false, 'message' => 'File is too large to upload.'));
                }
                if ($upload_err === UPLOAD_ERR_PARTIAL) {
                    return $out(array('ok' => false, 'message' => 'Upload was interrupted. Please try again.'));
                }
                return $out(array('ok' => false, 'message' => 'No file was uploaded.'));
            }

            $original = (string) $_FILES['quotation_file']['name'];
            if (!costing_quotation_is_allowed_file($original)) {
                return $out(array('ok' => false, 'message' => 'Unsupported file type. Allowed: PDF, Word, Excel, image.'));
            }

            $config = array(
                'upload_path'   => costing_quotation_ensure_upload_dir(),
                'allowed_types' => costing_quotation_allowed_types(),
                'max_size'      => costing_quotation_max_size_kb(),
                'encrypt_name'  => true,
            );
            $this->load->library('upload', $config);
            $this->upload->initialize($config);

            if (!$this->upload->do_upload('quotation_file')) {
                $error = trim(strip_tags($this->upload->display_errors('', '')));
                return $out(array('ok' => false, 'message' => 'Upload failed: ' . $error));
            }

            $data = $this->upload->data();
            $rel_path  = costing_quotation_upload_reldir() . $data['file_name'];
            $file_size = (int) round(((float) $data['file_size']) * 1024); // CI reports KB (2dp)
        } elseif ($note === '') {
            // Neither a file nor a note — nothing to save.
            return $out(array('ok' => false, 'message' => 'Attach a file or enter a note.'));
        }

        $this->load->model('Costing_Quotation_File_Model');
        $id = $this->Costing_Quotation_File_Model->Add(array(
            'costing_package_id' => $package_id,
            'supplier'           => $this->input->post('supplier'),
            'title'              => $this->input->post('title'),
            'note'               => $note,
            'original_name'      => $original,
            'stored_path'        => $rel_path,
            'file_size'          => $file_size,
            'created_by'         => $this->session->userdata('admin_id'),
        ));

        if ($id <= 0) {
            // Persist failed — reclaim the orphaned upload rather than leak it.
            if ($rel_path !== '') {
                costing_quotation_delete_file($rel_path);
            }
            return $out(array('ok' => false, 'message' => 'Could not save the attachment. Please try again.'));
        }

        return $out(array(
            'ok'   => true,
            'file' => array(
                'id'            => $id,
                'supplier'      => trim((string) $this->input->post('supplier')),
                'title'         => trim((string) $this->input->post('title')),
                'note'          => $note,
                'original_name' => $original,
                'icon'          => costing_quotation_entry_icon($original),
                'kind'          => costing_quotation_entry_kind($original),
                // A note-only entry has no file to stream, so no view URL.
                'view_url'      => $original !== '' ? base_url('Costing/Quotation_File/' . $id) : '',
                'uploaded_label' => costing_quotation_format_upload_date(date('Y-m-d H:i:s')),
                'can_delete'    => true,
            ),
        ));
    }

    /**
     * Stream a quotation attachment inline to any logged-in staff who can open
     * the costing package. The file is read from a protected directory with a
     * realpath containment check (defends against a tampered stored_path), and
     * is never exposed at a public asset URL.
     */
    public function Quotation_File($id = null)
    {
        $this->load->model('Costing_Quotation_File_Model');
        $this->load->helper('costing_quotation');

        $row = $this->Costing_Quotation_File_Model->Get((int) $id);
        if (empty($row) || empty($row['stored_path'])) {
            show_404();
            return;
        }

        $base = realpath(costing_quotation_upload_dir());
        $real = realpath(FCPATH . $row['stored_path']);
        if ($real === false || $base === false
            || strpos($real, rtrim($base, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR) !== 0) {
            show_404();
            return;
        }

        $ext = strtolower(pathinfo($real, PATHINFO_EXTENSION));
        $download_name = 'quotation_' . (int) $row['id'] . '.' . $ext;
        header('Content-Type: ' . costing_quotation_file_mime($real));
        header('Content-Disposition: inline; filename="' . $download_name . '"');
        header('Content-Length: ' . filesize($real));
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, max-age=0, no-cache');
        readfile($real);
        exit;
    }

    /**
     * AJAX: soft-delete a quotation attachment (uploader-only) and unlink the
     * file from disk.
     */
    public function Delete_Quotation()
    {
        $out = function ($data) {
            $this->output->set_content_type('application/json')->set_output(json_encode($data));
        };

        $id = (int) $this->input->post('id');
        if ($id <= 0) {
            return $out(array('ok' => false, 'message' => 'Missing attachment reference.'));
        }

        $this->load->model('Costing_Quotation_File_Model');
        $this->load->helper('costing_quotation');

        $row = $this->Costing_Quotation_File_Model->Delete($id, (int) $this->session->userdata('admin_id'));
        if (empty($row)) {
            return $out(array('ok' => false, 'message' => 'You can only delete attachments you uploaded.'));
        }

        costing_quotation_delete_file($row['stored_path']);
        return $out(array('ok' => true));
    }

    private function Wizard_Steps()
    {
        return array(
            'details'   => 'Package Details',
            'cost'      => 'Cost Template & Margin',
            'itinerary' => 'Itinerary',
            'logistics' => 'Hotel & Flights',
            'done'      => 'Save & Quotation',
        );
    }

    private function Normalize_Wizard_Step($step)
    {
        $step = strtolower(trim((string) $step));
        return array_key_exists($step, $this->Wizard_Steps()) ? $step : 'details';
    }
}
