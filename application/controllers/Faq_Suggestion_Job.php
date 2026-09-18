<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Faq_Suggestion_Job — the background worker for the "FAQ AI Suggestion"
 * feature. The web controller (Faq_Suggestion::Generate / Generate_Pdf) queues a
 * run row (state 'queued') and spawns this worker detached:
 *
 *   php index.php Faq_Suggestion_Job run <run_id>
 *
 * The worker runs the (slow) OpenAI call and records the outcome back on the run
 * row (running → done / error), which the listing polls. CLI-only; the actual
 * work lives in Faq_Suggestion_Model::Process_Run().
 */
class Faq_Suggestion_Job extends CI_Controller
{
	public function run($run_id = '')
	{
		if (!is_cli()) {
			show_404();
			return;
		}
		@set_time_limit(0);

		$run_id = (int) preg_replace('/[^0-9]/', '', (string) $run_id);
		if ($run_id < 1) {
			return;
		}

		$this->load->model('Faq_Suggestion_Model');
		echo '[' . date('Y-m-d H:i:s') . "] FAQ suggestion run #{$run_id} start" . PHP_EOL;
		$summary = $this->Faq_Suggestion_Model->Process_Run($run_id);
		echo '  created=' . (int) $summary['created']
			. ' proposed=' . (int) $summary['proposed']
			. ($summary['reason'] !== '' ? ' reason=' . $summary['reason'] : '')
			. (isset($summary['error']) ? ' error=' . $summary['error'] : '')
			. PHP_EOL;
		echo '[' . date('Y-m-d H:i:s') . "] FAQ suggestion run #{$run_id} done" . PHP_EOL;
	}
}
