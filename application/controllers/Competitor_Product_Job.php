<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Competitor_Product_Job — the background worker for Competitor Product. Spawned as a
 * detached CLI process:  php index.php Competitor_Product_Job run <job_id>
 * (exact case — Linux filesystems are case-sensitive; lowercase 404s there.)
 *
 * Two modes (from the queued status file's `mode`):
 *   - 'crawl' (default): discover + read every product into per-product {url,text}
 *     (jobs/<id>.items.json) and stop. The user then reviews the crawled products
 *     and picks which to analyse.
 *   - 'analyse': analyse a SELECTED subset (indices) of a source crawl's items with
 *     OpenAI (reusing the crawled text) and save one history row.
 * The status file is updated through the phases (discovering→reading, or
 * analysing→done); the web side polls it. CLI-only.
 */
class Competitor_Product_Job extends CI_Controller
{
	public function run($job_id = '')
	{
		if ( ! is_cli()) {
			show_404();
			return;
		}
		$this->load->helper('competitor_analysis');

		$job_id = preg_replace('/[^A-Za-z0-9_]/', '', (string) $job_id);
		if ($job_id === '') {
			return;
		}
		$status_file = APPPATH . 'logs/competitor_crawl/jobs/' . $job_id . '.json';
		if ( ! is_file($status_file)) {
			return;
		}
		$job = json_decode((string) file_get_contents($status_file), true);
		if ( ! is_array($job)) {
			return;
		}

		$write = function ($data) use ($status_file, $job) {
			$base = array(
				'job'          => isset($job['job']) ? $job['job'] : '',
				'url'          => isset($job['url']) ? $job['url'] : '',
				'mode'         => isset($job['mode']) ? $job['mode'] : 'crawl',
				'keyword'      => isset($job['keyword']) ? $job['keyword'] : '',          // persist chips
				'ai_crawl'     => ! empty($job['ai_crawl']) ? 1 : 0,
				'competitor_name' => isset($job['competitor_name']) ? $job['competitor_name'] : '', // user label (persist across writes)
				'created'      => isset($job['created']) ? $job['created'] : date('Y-m-d H:i:s'),   // fixed submit time
				'src'          => isset($job['src']) ? $job['src'] : '',   // discovery source (persist for resume headless gating)
				'ts'           => date('Y-m-d H:i:s'),
			);
			@file_put_contents($status_file, json_encode(array_merge($base, $data), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
		};

		$this->load->library('CompetitorAnalysisService');
		$mode = isset($job['mode']) ? $job['mode'] : 'crawl';
		if ($mode === 'analyse') {
			$this->run_analyse($job, $write);
		} elseif ($mode === 'paste') {
			$this->run_paste($job, $write);
		} elseif ($mode === 'upload') {
			$this->run_upload($job, $write);
		} elseif ($mode === 'translate') {
			$this->run_translate($job, $write);
		} else {
			$this->run_crawl($job, $write);
		}
	}

	/**
	 * Analyse an UPLOADED PDF/image in the background — the same work the old
	 * synchronous path did, moved here so a slow OpenAI vision call can't hit the
	 * web server's read timeout and show a false "Analysis Failed" while the
	 * analysis actually completes. Reads the file the controller staged (file_path),
	 * saves one history row, then deletes the staged file. On error the job file
	 * carries the message (shown as an error row); a finished row is folded in from
	 * the DB by Jobs_List.
	 */
	private function run_upload($job, $write)
	{
		@set_time_limit(0);
		$file  = isset($job['file_path']) ? (string) $job['file_path'] : '';
		$ext   = isset($job['file_ext']) ? (string) $job['file_ext'] : '';
		$label = isset($job['url']) && $job['url'] !== '' ? (string) $job['url'] : 'uploaded file';
		$write(array('state' => 'running', 'phase' => 'analysing', 'done' => 0, 'total' => 1));
		$this->load->model('Product_Model');
		$this->load->helper('product_tour_fields');
		$this->load->model('Competitor_Analysis_Model');
		try {
			if ($file === '' || ! is_file($file)) {
				throw new Exception('Uploaded file is no longer available.');
			}
			$our    = competitor_format_our_products($this->Product_Model->Read_For_Comparison());
			$record = $this->competitoranalysisservice->analyze_file($file, $ext, $our);
			$record['url']             = $label;
			$record['source']          = 'upload';
			$record['status']          = 'done';
			$record['competitor_name'] = isset($job['competitor_name']) ? $job['competitor_name'] : null;
			$record['created_by']      = isset($job['created_by']) ? $job['created_by'] : null;
			$id = (int) $this->Competitor_Analysis_Model->Create($record);
			@unlink($file);
			$write(array('state' => 'done', 'phase' => 'analysing', 'done' => 1, 'total' => 1,
				'count' => 1, 'analysis_id' => $id, 'cost_total' => (float) $record['cost_usd']));
		} catch (Exception $e) {
			@unlink($file);
			log_message('error', 'Competitor_Product_Job upload failed: ' . $e->getMessage());
			$write(array('state' => 'error', 'message' => $e->getMessage()));
		}
	}

	/**
	 * Analyse PASTED TEXT (notes + links) in the background — the same work the old
	 * synchronous path did, moved here so a slow paste (link fetches + an OpenAI
	 * web_search browse) can't hit the web server's read timeout and show a false
	 * "Analysis Failed" while the analysis actually completes. Saves one history row
	 * and records its id on the job so the results table can link to it.
	 */
	private function run_paste($job, $write)
	{
		@set_time_limit(0);
		$paste = isset($job['paste_text']) ? (string) $job['paste_text'] : '';
		$write(array('state' => 'running', 'phase' => 'analysing', 'done' => 0, 'total' => 1));
		$this->load->model('Product_Model');
		$this->load->helper('product_tour_fields');
		$this->load->model('Competitor_Analysis_Model');
		try {
			if (trim($paste) === '') {
				throw new Exception('No text to analyse.');
			}
			$our    = competitor_format_our_products($this->Product_Model->Read_For_Comparison());
			$record = $this->competitoranalysisservice->analyze_paste($paste, $our);
			$record['url']             = competitor_paste_source_label($paste);
			$record['source']          = 'paste';
			$record['status']          = 'done';
			$record['competitor_name'] = isset($job['competitor_name']) ? $job['competitor_name'] : null;
			$record['created_by']      = isset($job['created_by']) ? $job['created_by'] : null;
			$id = (int) $this->Competitor_Analysis_Model->Create($record);
			$write(array('state' => 'done', 'phase' => 'analysing', 'done' => 1, 'total' => 1,
				'count' => 1, 'analysis_id' => $id, 'cost_total' => (float) $record['cost_usd']));
		} catch (Exception $e) {
			log_message('error', 'CompetitorJob paste failed: ' . $e->getMessage());
			$write(array('state' => 'error', 'message' => $e->getMessage()));
		}
	}

	/**
	 * Translate a saved analysis into $lang in the BACKGROUND and cache it
	 * (competitor_analyses.translations_json) — the same work the old synchronous
	 * Translate() did, moved here so the View page's language toggle never blocks on
	 * the slow OpenAI call. The View/PDF read the cached overlay once this is done.
	 */
	private function run_translate($job, $write)
	{
		@set_time_limit(0);
		$id   = isset($job['analysis_id']) ? (int) $job['analysis_id'] : 0;
		$lang = competitor_normalize_lang(isset($job['lang']) ? $job['lang'] : 'en');
		$write(array('state' => 'running', 'phase' => 'translating', 'done' => 0, 'total' => 1));
		$this->load->model('Competitor_Analysis_Model');
		try {
			if ($id <= 0 || $lang === 'en') {
				throw new Exception('Nothing to translate.');
			}
			$analysis = $this->Competitor_Analysis_Model->Read_One($id);
			if ( ! $analysis) {
				throw new Exception('Analysis not found.');
			}
			// Skip the OpenAI call if another request already cached it.
			if ($this->Competitor_Analysis_Model->Read_Translation($id, $lang) === null) {
				$overlay = $this->competitoranalysisservice->translate_analysis(
					competitor_display_products($analysis), $lang
				);
				$this->Competitor_Analysis_Model->Save_Translation($id, $lang, $overlay);
			}
			$write(array('state' => 'done', 'phase' => 'translating', 'done' => 1, 'total' => 1, 'analysis_id' => $id));
		} catch (Exception $e) {
			log_message('error', 'Competitor_Product_Job translate failed: ' . $e->getMessage());
			$write(array('state' => 'error', 'message' => $e->getMessage()));
		}
	}

	/** Crawl the products to per-product {url,text} and stop (user reviews next). */
	private function run_crawl($job, $write)
	{
		$url = isset($job['url']) ? (string) $job['url'] : '';
		if ($url === '') {
			return;
		}
		// Pace between chunks: on a CONTINUATION run (a .done.txt already exists), pause briefly
		// BEFORE taking the lock so a big site gets a breather between chunks and is less likely
		// to rate-limit/block us. First run has no done-file → no pause. Sleep before the lock so
		// we don't hold up any other queued crawl while waiting.
		$done_file = APPPATH . 'logs/competitor_crawl/jobs/' . $job['job'] . '.done.txt';
		if (is_file($done_file) && filesize($done_file) > 0) {
			$pause = get_env('COMPETITOR_CHUNK_PAUSE');
			$pause = ($pause === '' || $pause === null) ? 2 : (int) $pause;
			if ($pause > 0) { sleep(min($pause, 60)); }
		}
		// ONE crawl at a time. A crawl spawns heavy work (discovery + headless renders);
		// running several at once exhausts the machine. So a second crawl QUEUES here and
		// WAITS for the current one to finish, then runs. flock auto-releases if the holder
		// dies, so a crashed crawl can't wedge the queue. (Waiting workers just sleep — no
		// heavy work runs until the lock is theirs.)
		$lock = $this->acquire_crawl_lock($write);
		$write(array('state' => 'running', 'phase' => 'discovering', 'done' => 0, 'total' => 0));
		try {
			// Stamp when the READING phase begins so the UI can show a live ETA
			// (discovery has no known total; reading does).
			$read_start = 0;
			$progress = function ($phase, $done, $total, $label) use ($write, &$read_start) {
				if ($phase === 'reading' && $read_start === 0) {
					$read_start = time();
				}
				$data = array('state' => 'running', 'phase' => $phase, 'done' => (int) $done, 'total' => (int) $total, 'label' => (string) $label);
				if ($read_start > 0) {
					$data['read_start'] = date('Y-m-d H:i:s', $read_start);
				}
				$write($data);
			};
			$keyword  = isset($job['keyword']) ? (string) $job['keyword'] : '';
			$ai_crawl = ! empty($job['ai_crawl']);
			// Optional cap on how many products to read (0 = unbounded, the default). Lets
			// a caller run a fast bounded crawl (e.g. batch verification) without reading
			// an entire large catalogue.
			$limit = isset($job['limit']) ? (int) $job['limit'] : 0;
			// Persist the discovered URL list to a per-crawl file (truncated here, so each new
			// crawl starts clean). Bounds discovery memory + gives an inspectable record.
			$this->competitoranalysisservice->set_discovery_url_file(
				APPPATH . 'logs/competitor_crawl/jobs/' . $job['job'] . '.urls.txt');
			// Checkpoint read progress to the items file every chunk, so a crash/kill/block
			// mid-read keeps everything read so far (not all-or-nothing).
			$items_file = APPPATH . 'logs/competitor_crawl/jobs/' . $job['job'] . '.items.json';
			$this->competitoranalysisservice->set_items_checkpoint_file($items_file);
			// Resumable chunking: track attempted URLs so each run reads only the next chunk and
			// carries prior progress. When a chunk finishes with more remaining, we re-spawn.
			$this->competitoranalysisservice->set_done_file(
				APPPATH . 'logs/competitor_crawl/jobs/' . $job['job'] . '.done.txt');
			// Restore the discovery source recorded on the first run so a continuation keeps the
			// same headless gating (an ICE/sitemap site must not headless-render on resume).
			if ( ! empty($job['src'])) {
				$this->competitoranalysisservice->set_forced_source((string) $job['src']);
			}
			$items = $this->competitoranalysisservice->crawl_to_text($url, $limit, $progress, $keyword, $ai_crawl);
			@file_put_contents($items_file, json_encode($items, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

			// AI-crawl discovery (web_search) spends tokens that aren't tied to any saved
			// product — seed the crawl's running cost with it so the AI Cost column reflects
			// it (later analyses add on top via merge_crawl_analysed).
			$crawl_cost = (float) $this->competitoranalysisservice->last_run_cost();
			$n = count($items);
			if ($this->competitoranalysisservice->crawl_has_more()) {
				// More discovered URLs remain unread — keep the job visible as running and
				// RE-SPAWN it to read the next chunk. The re-spawn writes the new worker's PID
				// (so the crash-watchdog sees a live worker), then this run releases the lock so
				// the continuation can acquire it. Progress (.done.txt + .items.json) persists.
				$write(array('state' => 'running', 'phase' => 'reading', 'done' => $n, 'total' => $n,
					'count' => $n, 'items_file' => $items_file, 'cost_total' => round($crawl_cost, 6),
					'src' => $this->competitoranalysisservice->discovery_source(),   // persist for the next chunk
					'label' => 'Read ' . $n . ' so far — continuing next chunk…'));
				$this->respawn_continuation($job['job']);
			} else {
				$write(array('state' => 'done', 'phase' => 'reading', 'done' => $n, 'total' => $n,
					'count' => $n, 'items_file' => $items_file, 'cost_total' => round($crawl_cost, 6)));
			}
		} catch (Exception $e) {
			$write(array('state' => 'error', 'message' => $e->getMessage()));
		} finally {
			$this->release_crawl_lock($lock);   // let the next queued crawl proceed
		}
	}

	/**
	 * Acquire the global single-crawl lock, WAITING until it's free so crawls run strictly
	 * one at a time. Uses a MySQL NAMED lock (GET_LOCK) rather than a file: it is held by
	 * this DB connection, released automatically if the worker dies (connection drops), and
	 * — unlike a lock file — cannot be broken by clearing the logs directory. Each GET_LOCK
	 * call waits up to 3s; between waits it heartbeats a 'queued' status so the UI shows the
	 * job is queued behind the running crawl. Returns true when held (release via
	 * release_crawl_lock), or null if there's no DB (fail-open — don't block crawling).
	 */
	private function acquire_crawl_lock($write)
	{
		if ( ! isset($this->db)) { @$this->load->database(); }
		if ( ! isset($this->db)) {
			return null;   // no DB → don't block crawling
		}
		$start = time();
		while (true) {
			$row = $this->db->query("SELECT GET_LOCK('competitor_crawl', 3) AS g")->row();
			if ($row && (int) $row->g === 1) {
				return true;   // lock held on this connection
			}
			// Another crawl holds it — heartbeat 'queued' (keeps the watchdog happy too), retry.
			$write(array('state' => 'queued', 'phase' => 'queued', 'done' => 0, 'total' => 0,
				'label' => 'Queued — waiting for the current crawl to finish…'));
			if (time() - $start > 7200) {
				return true;   // 2h safety valve — proceed rather than wait forever
			}
		}
	}

	/**
	 * Re-spawn THIS crawl job (same id) as a detached worker to read the next chunk. The child
	 * queues on the single-crawl lock until this run exits and releases it, then continues from
	 * the persisted .done.txt / .items.json. Writes the child's PID so the crash-watchdog sees a
	 * live worker (not a dead one). Best-effort — if it can't spawn, the job just stops with its
	 * partial progress saved (a manual re-run would resume it).
	 */
	private function respawn_continuation($job_id)
	{
		if ( ! function_exists('exec')) {
			return;
		}
		$dir   = APPPATH . 'logs/competitor_crawl/jobs/';
		$php   = (defined('PHP_BINARY') && PHP_BINARY) ? PHP_BINARY : 'php';
		$index = FCPATH . 'index.php';
		$out   = $dir . $job_id . '.out';
		$cmd = escapeshellarg($php) . ' -d pcre.jit=0 -d memory_limit=768M ' . escapeshellarg($index)
			. ' Competitor_Product_Job run ' . escapeshellarg($job_id)
			. ' > ' . escapeshellarg($out) . ' 2>&1 & echo $!';
		$pid = (int) @exec($cmd);
		if ($pid > 0) {
			@file_put_contents($dir . $job_id . '.pid', $pid);
		}
	}

	/** Release the single-crawl lock so the next queued crawl can start. */
	private function release_crawl_lock($held)
	{
		if ($held === true && isset($this->db)) {
			@$this->db->query("SELECT RELEASE_LOCK('competitor_crawl')");
		}
	}

	/** Analyse the SELECTED items of a source crawl with OpenAI; save one history row. */
	private function run_analyse($job, $write)
	{
		$url        = isset($job['url']) ? (string) $job['url'] : '';
		$items_file = isset($job['items_file']) ? (string) $job['items_file'] : '';
		$all = ($items_file !== '' && is_file($items_file))
			? json_decode((string) file_get_contents($items_file), true) : null;
		if ( ! is_array($all) || empty($all)) {
			$write(array('state' => 'error', 'message' => 'Crawled products are no longer available.'));
			return;
		}
		// Pick the selected indices (default: all), keyed by original index.
		$indices = isset($job['indices']) && is_array($job['indices']) ? $job['indices'] : array_keys($all);
		$sel = array();
		foreach ($indices as $i) {
			if (isset($all[(int) $i])) { $sel[(int) $i] = $all[(int) $i]; }
		}
		if (empty($sel)) {
			$write(array('state' => 'error', 'message' => 'No products selected.'));
			return;
		}

		$n = count($sel);
		$write(array('state' => 'running', 'phase' => 'analysing', 'done' => 0, 'total' => $n));
		$this->load->model('Product_Model');
		$this->load->helper('product_tour_fields');
		$this->load->model('Competitor_Analysis_Model');
		try {
			$our = competitor_format_our_products($this->Product_Model->Read_For_Comparison());
			$results = array();   // index => {id, cost}
			$total_cost = 0.0;
			$done = 0;
			foreach ($sel as $i => $item) {
				$write(array('state' => 'running', 'phase' => 'analysing', 'done' => $done, 'total' => $n, 'label' => isset($item['url']) ? $item['url'] : ''));
				try {
					$site = $this->competitoranalysisservice->analyze_texts(array($item), $our);
					if ( ! empty($site['products'])) {
						$rec = $site['products'][0];
						$rec['status']          = 'done';
						$rec['competitor_name'] = isset($job['competitor_name']) ? $job['competitor_name'] : null;
						$rec['created_by']      = isset($job['created_by']) ? $job['created_by'] : null;
						$id = (int) $this->Competitor_Analysis_Model->Create($rec);
						$results[(string) $i] = array('id' => $id, 'cost' => (float) $site['cost_usd'], 'at' => date('Y-m-d H:i:s'));
						$total_cost += (float) $site['cost_usd'];
					}
				} catch (Exception $e) {
					log_message('error', 'CompetitorJob analyse item failed: ' . $e->getMessage());
				}
				$write(array('state' => 'running', 'phase' => 'analysing', 'done' => ++$done, 'total' => $n));
			}
			$this->merge_crawl_analysed($job, $results, $total_cost);
			// Carry the per-index results ({id,cost,at}) on the done status so the Review
			// page can update just the analysed rows in place (no full reload) after it
			// polls Job_State — the user may have kept the page open in the background.
			$write(array('state' => 'done', 'phase' => 'analysing', 'done' => $n, 'total' => $n, 'count' => count($results), 'results' => $results));
		} catch (Exception $e) {
			$write(array('state' => 'error', 'message' => $e->getMessage()));
		}
	}

	/** Merge newly-analysed items (index → {id,cost}) and accumulated cost onto the source crawl. */
	private function merge_crawl_analysed($job, $results, $add_cost)
	{
		$src = isset($job['src_job']) ? preg_replace('/[^A-Za-z0-9_]/', '', (string) $job['src_job']) : '';
		if ($src === '' || empty($results)) {
			return;
		}
		$file = APPPATH . 'logs/competitor_crawl/jobs/' . $src . '.json';
		if ( ! is_file($file)) {
			return;
		}
		$s = json_decode((string) file_get_contents($file), true);
		if ( ! is_array($s)) {
			return;
		}
		$analysed = isset($s['analysed']) && is_array($s['analysed']) ? $s['analysed'] : array();
		foreach ($results as $idx => $r) {
			$analysed[(string) $idx] = $r;
		}
		$s['analysed']   = $analysed;
		$s['cost_total'] = round((isset($s['cost_total']) ? (float) $s['cost_total'] : 0.0) + (float) $add_cost, 6);
		@file_put_contents($file, json_encode($s, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
	}
}
