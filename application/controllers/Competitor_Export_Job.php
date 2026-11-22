<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/** CLI-only XLSX generation, independent of the browser request/session. */
class Competitor_Export_Job extends CI_Controller
{
    public function cleanup()
    {
        if (!is_cli()) { show_404(); return; }
        $this->load->library('CompetitorExportJobs');
        $this->competitorexportjobs->cleanup();
    }
    public function run($id = '')
    {
        if (!is_cli()) { show_404(); return; }
        if (!preg_match('/^[a-f0-9]{32}$/D', $id)) { return; }
        @set_time_limit(1800);
        @ini_set('memory_limit', '768M');
        $this->load->helper('competitor_analysis');
        $this->load->library('CompetitorExportJobs');
        $jobs = $this->competitorexportjobs;
        $lock = $jobs->worker_lock($id);
        if (!$lock) { return; }
        $finished = false;
        $generation_lock = false;
        $started = microtime(true);
        // Reserve enough memory to persist an error and remove partial files after OOM.
        $reserve = str_repeat('x', 262144);
        register_shutdown_function(function () use ($jobs, $id, $lock, &$finished, &$reserve) {
            $reserve = null;
            if (!$finished) {
                try { $jobs->finish($id, 'error', 'Export stopped unexpectedly. Please try again.'); } catch (Throwable $ignored) {}
            }
            $jobs->unlock($lock);
        });
        try {
            $row = $jobs->read($id);
            if ($row['state'] !== 'queued') {
                if ($jobs->cancelled($id)) { $jobs->finish($id, 'cancelled', 'Cancelled'); }
                $finished = true; return;
            }
            // Heavy generation is serialized across owners/tabs to protect the VPS.
            while (!($generation_lock = $jobs->generation_lock())) {
                if ($jobs->cancelled($id)) { throw new RuntimeException('Export cancelled.'); }
                if (microtime(true) - $started > 1800) { throw new RuntimeException('Export queue timed out.'); }
                $jobs->update($id, array('phase' => 'Waiting for another Excel export to finish'));
                sleep(1);
            }
            if ($jobs->cancelled($id)) { throw new RuntimeException('Export cancelled.'); }
            $jobs->update($id, array('state' => 'running', 'phase' => 'Loading saved products'));
            $last = 0;
            $progress = function ($phase, $done, $total) use ($jobs, $id, &$last, $started) {
                if ($jobs->cancelled($id)) { throw new RuntimeException('Export cancelled.'); }
                if (microtime(true) - $started > 1800) { throw new RuntimeException('Export exceeded its time budget.'); }
                if (microtime(true) - $last < 0.5 && $done !== $total) { return; }
                $last = microtime(true);
                $jobs->update($id, array('phase' => $phase, 'done' => $done, 'total' => $total,
                    'percent' => $total > 0 ? (int) floor(100 * $done / $total) : null));
            };
            // Confine XLSX writer scratch files to this job so cancellation/failure
            // and expiry cleanup never touch another request's temporary files.
            $tmp = $jobs->path($id, 'tmp');
            mkdir($tmp, 0700);
            putenv('TMPDIR=' . $tmp);
            putenv('TMP=' . $tmp);
            putenv('TEMP=' . $tmp);
            require_once APPPATH . 'libraries/CompetitorExportCellCache.php';
            $cache = new CompetitorExportCellCache($tmp . '/cells.cache');
            $previous_cache = \PhpOffice\PhpSpreadsheet\Settings::getCache();
            \PhpOffice\PhpSpreadsheet\Settings::setCache($cache);
            $this->load->library('CompetitorCrawlExportBuilder');
            $book = $this->competitorcrawlexportbuilder->build($row['job'], $row['indices'], $progress);
            $progress('Packaging Excel file', 0, 0);
            $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($book);
            $writer->setPreCalculateFormulas(false);
            $writer->setUseDiskCaching(true, $tmp);
            $writer->save($jobs->path($id, 'export.part.xlsx'));
            $book->disconnectWorksheets();
            unset($writer, $book);
            if ($jobs->cancelled($id)) { throw new RuntimeException('Export cancelled.'); }
            if (!rename($jobs->path($id, 'export.part.xlsx'), $jobs->path($id, 'export.xlsx'))) {
                throw new RuntimeException('Could not finish the Excel file.');
            }
            \PhpOffice\PhpSpreadsheet\Settings::setCache($previous_cache);
            unset($cache);
            // Writer has closed all scratch files; remove them before offering download.
            foreach (glob($tmp . '/*') ?: array() as $file) { if (is_file($file)) { @unlink($file); } }
            @rmdir($tmp);
            $jobs->update($id, array('state' => 'ready', 'phase' => 'Excel file ready', 'percent' => 100,
                'bytes' => filesize($jobs->path($id, 'export.xlsx'))));
            $finished = true;
        } catch (Throwable $e) {
            log_message('error', 'Competitor Excel export ' . $id . ': ' . $e->getMessage());
            $jobs->finish($id, 'error', 'Could not generate the Excel file. Please try again.');
            $finished = true;
        } finally {
            if (isset($book)) { $book->disconnectWorksheets(); }
            if (isset($previous_cache)) { \PhpOffice\PhpSpreadsheet\Settings::setCache($previous_cache); }
            if ($jobs->cancelled($id)) { $jobs->finish($id, 'cancelled', 'Cancelled'); }
            $jobs->unlock($generation_lock);
            $jobs->unlock($lock);
        }
    }
}
