<?php

class Costing extends MY_Controller
{
    public function __construct()
    {
        parent::__construct();
        $this->load->model('Costing_Model');
    }

    public function index()
    {
        $titles = array(
            'tab_title' => 'HolidayGoGoGo | Costing Packages',
            'breadcrumb_title' => 'Setting >> Costing >> Packages',
        );

        $filters = array(
            'search' => trim((string) $this->input->get('search')),
            'status' => trim((string) $this->input->get('status')),
            'customer_name' => trim((string) $this->input->get('customer_name')),
            'customer_contact' => trim((string) $this->input->get('customer_contact')),
            'customer_email' => trim((string) $this->input->get('customer_email')),
            'sales_admin_id' => (int) $this->input->get('sales_admin_id'),
        );

        $array = $this->Costing_Model->Read_Packages_Dashboard($filters);
        $array['filters'] = $filters;
        $array['sales_agents'] = $this->Costing_Model->Read_Sales_Agents();

        $this->load->view('layout/header', $titles);
        $this->load->view('costing/index', $array);
        $this->load->view('layout/footer');
    }

    public function Create()
    {
        // Brand-new package: open the wizard directly on step 1 with a blank form.
        // Saving step 1 creates the package and moves on to the cost step.
        $array = $this->Costing_Model->Read_Package_Workspace_Data(0, 0, 'MYR', true);
        $array['active_step'] = 'details';
        $array['has_snapshot'] = false;
        $array['wizard_steps'] = $this->Wizard_Steps();

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

        if (empty($array['package']['id'])) {
            $this->session->set_flashdata('message_error', 'Costing package not found.');
            redirect('Costing');
            return;
        }

        $array['active_step'] = $step;
        $array['has_snapshot'] = $existing_booking_id > 0;
        $array['wizard_steps'] = $this->Wizard_Steps();

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

    public function Save_Package()
    {
        $package_id = $this->Costing_Model->Save_Package(array(
            'id' => (int) $this->input->post('package_id'),
            'name' => $this->input->post('name'),
            'customer_name' => $this->input->post('customer_name'),
            'customer_contact' => $this->input->post('customer_contact'),
            'customer_email' => $this->input->post('customer_email'),
            'sales_admin_id' => $this->input->post('sales_admin_id'),
            'duration_days' => $this->input->post('duration_days'),
            'duration_nights' => $this->input->post('duration_nights'),
            'description' => $this->input->post('description'),
            'status' => $this->input->post('status'),
        ));

        if ($package_id > 0) {
            $this->session->set_flashdata('message_success', ((int) $this->input->post('package_id') > 0)
                ? 'Package details saved.'
                : 'Package created. Continue with the costing.');
            $next = $this->Normalize_Wizard_Step($this->input->post('wizard_next'));
            redirect('Costing/Package/' . $package_id . '?step=' . $next);
            return;
        }

        $this->session->set_flashdata('message_error', 'Package name is required.');
        $existing_package_id = (int) $this->input->post('package_id');
        redirect($existing_package_id > 0 ? 'Costing/Package/' . $existing_package_id . '?step=details' : 'Costing');
    }

    public function Delete_Package()
    {
        $package_id = (int) $this->input->post('package_id');
        if ($package_id > 0) {
            $success = $this->Costing_Model->Delete_Package($package_id);
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
        $level_fields = array(
            'notes' => $this->input->post('itinerary_notes'),
        );
        if ($package_id > 0 && $this->Costing_Model->Save_Itinerary_Days($package_id, (array) $this->input->post('itinerary'), $level_fields)) {
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
        $valid_from = trim((string) $this->input->post('valid_from'));

        if ($from_currency_id <= 0 || $to_currency_id <= 0 || $unit_amount <= 0 || $converted_amount <= 0 || $bank_charges_myr < 0) {
            $this->session->set_flashdata('message_error', 'From currency, to currency, unit amount, converted amount, and valid bank charges are required.');
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

        if (!isset($_FILES['quotation_file'])) {
            return $out(array('ok' => false, 'message' => 'No file was uploaded.'));
        }
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

        $this->load->helper('costing_quotation');
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
        $rel_path = costing_quotation_upload_reldir() . $data['file_name'];

        $this->load->model('Costing_Quotation_File_Model');
        $id = $this->Costing_Quotation_File_Model->Add(array(
            'costing_package_id' => $package_id,
            'supplier'           => $this->input->post('supplier'),
            'title'              => $this->input->post('title'),
            'original_name'      => $original,
            'stored_path'        => $rel_path,
            'file_size'          => (int) round(((float) $data['file_size']) * 1024), // CI reports KB (2dp)
            'created_by'         => $this->session->userdata('admin_id'),
        ));

        if ($id <= 0) {
            // Persist failed — reclaim the orphaned upload rather than leak it.
            costing_quotation_delete_file($rel_path);
            return $out(array('ok' => false, 'message' => 'Could not save the attachment. Please try again.'));
        }

        return $out(array(
            'ok'   => true,
            'file' => array(
                'id'            => $id,
                'supplier'      => trim((string) $this->input->post('supplier')),
                'title'         => trim((string) $this->input->post('title')),
                'original_name' => $original,
                'icon'          => costing_quotation_file_icon($original),
                'kind'          => costing_quotation_file_kind($original),
                'view_url'      => base_url('Costing/Quotation_File/' . $id),
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
