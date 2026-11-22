<?php
defined('BASEPATH') OR exit('No direct script access allowed');
require_once FCPATH . 'vendor/autoload.php';

/** Per-worker append-only cache: XLSX cell values live on disk, not in PHP RAM. */
class CompetitorExportCellCache implements \Psr\SimpleCache\CacheInterface
{
    private $stream;
    private $offsets = array();
    public function __construct($path)
    {
        $this->stream = fopen($path, 'w+b');
        if (!$this->stream) { throw new RuntimeException('Cannot create export cell cache.'); }
    }
    public function get($key, $default = null)
    {
        if (!isset($this->offsets[$key])) { return $default; }
        list($offset, $length) = $this->offsets[$key];
        if (fseek($this->stream, $offset) !== 0) { throw new RuntimeException('Cannot seek export cache.'); }
        $data = '';
        while (strlen($data) < $length) {
            $part = fread($this->stream, $length - strlen($data));
            if ($part === false || $part === '') { throw new RuntimeException('Cannot read export cache.'); }
            $data .= $part;
        }
        return unserialize($data);
    }
    public function set($key, $value, $ttl = null)
    {
        $data = serialize($value);
        if (fseek($this->stream, 0, SEEK_END) !== 0) { return false; }
        $offset = ftell($this->stream);
        $length = strlen($data);
        for ($written = 0; $written < $length; $written += $bytes) {
            $bytes = fwrite($this->stream, substr($data, $written));
            if ($bytes === false || $bytes === 0) { return false; }
        }
        $this->offsets[$key] = array($offset, $length);
        return true;
    }
    public function delete($key) { unset($this->offsets[$key]); return true; }
    public function has($key) { return isset($this->offsets[$key]); }
    public function clear() { $this->offsets = array(); return ftruncate($this->stream, 0); }
    public function getMultiple($keys, $default = null) { $out = array(); foreach ($keys as $key) { $out[$key] = $this->get($key, $default); } return $out; }
    public function setMultiple($values, $ttl = null) { foreach ($values as $key => $value) { if (!$this->set($key, $value, $ttl)) { return false; } } return true; }
    public function deleteMultiple($keys) { foreach ($keys as $key) { $this->delete($key); } return true; }
    public function __destruct() { if (is_resource($this->stream)) { fclose($this->stream); } }
}
