<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Faq_Knowledge_Import_Job extends CI_Controller
{
    function run($id='')
    {
        if (!$this->input->is_cli_request()) { show_404(); return; }
        if (!preg_match('/^[a-f0-9]{64}$/D',$id)) { return; }
        @set_time_limit(0);
        $this->load->model('Faq_Knowledge_Import_Model');
        register_shutdown_function(function() use ($id) {
            $error=error_get_last();
            if ($error && in_array($error['type'],array(E_ERROR,E_PARSE,E_CORE_ERROR,E_COMPILE_ERROR),true)) {
                $this->Faq_Knowledge_Import_Model->Fail($id,'Source extraction stopped unexpectedly. Please upload the source again.');
            }
        });
        $this->Faq_Knowledge_Import_Model->Process($id);
    }
}
