<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/** Private, short-lived export jobs. All state transitions serialize on a per-job lock. */
class CompetitorExportJobs
{
    private $root;
    public function __construct($config = array())
    {
        $this->root = isset($config['root']) ? $config['root'] : APPPATH . 'logs/competitor_exports/';
        $this->root = rtrim($this->root, '/') . '/';
        if (!is_dir($this->root) && !mkdir($this->root, 0700, true)) { throw new RuntimeException('Cannot create export directory.'); }
        file_put_contents($this->root . '.htaccess', "Require all denied\nDeny from all\n");
        file_put_contents($this->root . 'index.html', '');
    }
    public function path($id, $file = '')
    {
        if (!is_string($id) || !preg_match('/^[a-f0-9]{32}$/D', $id)) { throw new InvalidArgumentException('Invalid export.'); }
        return $this->root . $id . '/' . $file;
    }
    public function create($owner, $job, $indices)
    {
        $id = bin2hex(random_bytes(16));
        if (!mkdir($this->path($id), 0700)) { throw new RuntimeException('Cannot create export.'); }
        $this->change($id, function () use ($id, $owner, $job, $indices) {
            return array('id' => $id, 'owner' => (int) $owner, 'job' => $job, 'indices' => $indices,
                'state' => 'queued', 'phase' => 'Waiting for export worker', 'percent' => null,
                'created' => time(), 'updated' => time());
        });
        return $id;
    }
    private function change($id, $callback)
    {
        if (!is_dir($this->path($id))) { throw new RuntimeException('Export expired. Please generate it again.'); }
        $lock = fopen($this->path($id, 'state.lock'), 'c');
        if (!$lock || !flock($lock, LOCK_EX)) { throw new RuntimeException('Cannot lock export.'); }
        try {
            $row = json_decode((string) @file_get_contents($this->path($id, 'status.json')), true);
            $row = call_user_func($callback, is_array($row) ? $row : array());
            $row['updated'] = time();
            $tmp = $this->path($id, 'status.tmp');
            if (file_put_contents($tmp, json_encode($row), LOCK_EX) === false || !rename($tmp, $this->path($id, 'status.json'))) {
                throw new RuntimeException('Cannot save export status.');
            }
            return $row;
        } finally { flock($lock, LOCK_UN); fclose($lock); }
    }
    public function read($id, $owner = null)
    {
        $row = json_decode((string) @file_get_contents($this->path($id, 'status.json')), true);
        if (!$row || ($owner !== null && (int) $row['owner'] !== (int) $owner)) { throw new RuntimeException('Export not found or expired.'); }
        return $row;
    }
    public function update($id, $data)
    {
        return $this->change($id, function ($row) use ($data) {
            if (in_array($row['state'], array('cancelling', 'cancelled', 'error', 'downloaded'), true)) {
                throw new RuntimeException('Export stopped.');
            }
            return array_merge($row, $data);
        });
    }
    public function worker_lock($id)
    {
        $file = $this->path($id, 'worker.lock');
        if (!is_dir(dirname($file))) { return false; }
        $lock = fopen($file, 'c');
        if (!$lock) { return false; }
        if (!flock($lock, LOCK_EX | LOCK_NB)) { fclose($lock); return false; }
        return $lock;
    }
    public function unlock($lock) { if (is_resource($lock)) { flock($lock, LOCK_UN); fclose($lock); } }
    public function generation_lock()
    {
        $lock = fopen($this->root . 'generation.lock', 'c');
        if (!$lock) { throw new RuntimeException('Cannot lock export generator.'); }
        if (!flock($lock, LOCK_EX | LOCK_NB)) { fclose($lock); return false; }
        return $lock;
    }
    public function cancelled($id)
    {
        $row = $this->read($id);
        return in_array($row['state'], array('cancelling', 'cancelled'), true);
    }
    private function remove_tree($path)
    {
        if (is_link($path) || !is_dir($path)) { @unlink($path); return; }
        foreach (scandir($path) as $name) {
            if ($name !== '.' && $name !== '..') { $this->remove_tree($path . '/' . $name); }
        }
        @rmdir($path);
    }
    public function remove_payload($id)
    {
        foreach (scandir($this->path($id)) as $name) {
            if (!in_array($name, array('.', '..', 'status.json', 'state.lock', 'worker.lock'), true)) {
                $this->remove_tree($this->path($id, $name));
            }
        }
    }
    /** Worker holds worker.lock until it has stopped touching all payload files. */
    public function finish($id, $state, $message)
    {
        $this->remove_payload($id);
        return $this->change($id, function ($row) use ($state, $message) {
            $row['state'] = $row['state'] === 'cancelling' ? 'cancelled' : $state;
            $row['phase'] = $row['state'] === 'cancelled' ? 'Cancelled; temporary files removed' : $message;
            $row['percent'] = null;
            return $row;
        });
    }
    public function cancel($id, $owner)
    {
        $this->read($id, $owner);
        $this->change($id, function ($row) {
            if (!in_array($row['state'], array('downloaded', 'cancelled', 'error', 'downloading'), true)) {
                $row['state'] = 'cancelling'; $row['phase'] = 'Cancelling and removing temporary files';
            }
            return $row;
        });
        $lock = $this->worker_lock($id);
        if ($lock) {
            try {
                if ($this->cancelled($id)) { $this->finish($id, 'cancelled', 'Cancelled'); }
            } finally { $this->unlock($lock); }
        }
        return $this->read($id, $owner);
    }
    public function claim_download($id, $owner)
    {
        $this->read($id, $owner);
        return $this->change($id, function ($row) {
            if ($row['state'] !== 'ready') { throw new RuntimeException('This download is unavailable. Please generate it again.'); }
            $row['state'] = 'downloading'; $row['phase'] = 'Sending file';
            return $row;
        });
    }
    /** On-use sweep plus CLI cleanup for a regular server cron. Never deletes live work. */
    public function cleanup()
    {
        foreach (glob($this->root . '*', GLOB_ONLYDIR) ?: array() as $dir) {
            $id = basename($dir);
            if (!preg_match('/^[a-f0-9]{32}$/D', $id)) { continue; }
            $row = json_decode((string) @file_get_contents($dir . '/status.json'), true);
            $age = time() - ($row ? (int) $row['updated'] : (int) filemtime($dir));
            $ttl = $row && in_array($row['state'], array('queued', 'running', 'cancelling', 'downloading'), true) ? 7200 : 3600;
            if ($age < $ttl) { continue; }
            $lock = $this->worker_lock($id);
            if (!$lock) { continue; }
            try {
                // The worker may have completed between the first read and lock.
                $fresh = json_decode((string) @file_get_contents($dir . '/status.json'), true);
                if ($fresh && (time() - (int) $fresh['updated']) < $ttl) { continue; }
                $this->remove_tree($dir);
            } finally { $this->unlock($lock); }
        }
    }
}
