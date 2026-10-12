<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Costing Category master — a Settings CRUD living beside the Costing module.
 * Dynamic replacement for the old five fixed categories. GET renders the list;
 * POST saves/deletes with flashdata feedback, mirroring the sibling Costing_Item
 * page. Access matches the Costing menu gate: Owner (level 10) or costing admin (id 28).
 */
class Costing_Category extends MY_Controller
{
    public function __construct()
    {
        parent::__construct();
        if (!((int) $this->session->level === 10 || (int) $this->session->admin_id === 28)) {
            redirect('Booking');
            return;
        }
        $this->load->model('Costing_Category_Model');
    }

    public function index()
    {
        $filters = array(
            'name' => trim((string) $this->input->get('category_name', true)),
        );

        $array = array(
            'categories'       => $this->Costing_Category_Model->Read_Categories($filters),
            'category_filters' => $filters,
            'fallback_code'    => Costing_Category_Model::FALLBACK_CODE,
        );

        $titles = array(
            'tab_title'        => 'HolidayGoGoGo | Costing Category',
            'breadcrumb_title' => 'Setting >> Costing >> Category',
        );

        $this->load->view('layout/header', $titles);
        $this->load->view('costing_category/index', $array);
        $this->load->view('layout/footer');
    }

    public function Save_Category()
    {
        $category_id = (int) $this->input->post('category_id');
        $success = $this->Costing_Category_Model->Save_Category(array(
            'id'   => $category_id,
            'name' => $this->input->post('name'),
            'has_headcount_multiplier' => $this->input->post('has_headcount_multiplier') ? 1 : 0,
        ));

        if ($success) {
            $this->session->set_flashdata('message_success', $category_id > 0 ? 'Category updated successfully.' : 'Category created successfully.');
        } else {
            $this->session->set_flashdata('message_error', 'Unable to save category. Name is required.');
        }

        redirect('Costing_Category');
    }

    public function Delete_Category()
    {
        $category_id = (int) $this->input->post('category_id');
        list($ok, $message) = $this->Costing_Category_Model->Delete_Category($category_id);
        $this->session->set_flashdata($ok ? 'message_success' : 'message_error', $message);
        redirect('Costing_Category');
    }
}
