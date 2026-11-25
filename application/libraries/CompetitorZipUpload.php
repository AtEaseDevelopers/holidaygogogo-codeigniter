<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/** Stage archive documents as independent uploads without trusting archive paths. */
class CompetitorZipUpload
{
	const MAX_FILES = 100;
	const MAX_FILE_BYTES = 20971520;
	const MAX_TOTAL_BYTES = 209715200;

	public function extract($archive, $directory, $progress = null)
	{
		if ( ! class_exists('ZipArchive')) {
			throw new Exception('ZIP uploads require the PHP ZIP extension.');
		}
		$zip = new ZipArchive();
		if ($zip->open($archive) !== true) {
			throw new Exception('The ZIP file is invalid or could not be opened.');
		}
		$files = array();
		$skipped = 0;
		$total = 0;
		$seen = array();
		try {
			if ($zip->numFiles > 1000) { throw new Exception('The ZIP contains too many entries (maximum 1,000).'); }
			if ( ! is_dir($directory) && ! @mkdir($directory, 0755, true)) {
				throw new Exception('Could not create the upload directory.');
			}
			for ($i = 0; $i < $zip->numFiles; $i++) {
				if ($progress) { call_user_func($progress, 'extracting', $i, $zip->numFiles); }
				$stat = $zip->statIndex($i);
				if ($stat === false) { throw new Exception('Could not read a ZIP entry.'); }
				$name = str_replace('\\', '/', $stat['name']);
				if (substr($name, -1) === '/') { continue; }
				if (strpos($name, "\0") !== false || preg_match('#(^/|^[A-Za-z]:|(^|/)\.\.(/|$))#', $name)) {
					throw new Exception('The ZIP contains an unsafe file path.');
				}
				$ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
				if (preg_match('#(^|/)(?:__MACOSX|\.[^/]*)(/|$)#', $name)
					|| ! in_array($ext, array('pdf', 'jpg', 'jpeg', 'png', 'gif', 'webp'), true)) {
					$skipped++;
					continue;
				}
				$opsys = $attributes = 0;
				if ($zip->getExternalAttributesIndex($i, $opsys, $attributes)
					&& (($attributes >> 16) & 0170000) === 0120000) {
					throw new Exception('Symbolic links are not allowed in ZIP uploads.');
				}
				if (count($files) >= self::MAX_FILES || $stat['size'] > self::MAX_FILE_BYTES
					|| $total + $stat['size'] > self::MAX_TOTAL_BYTES) {
					throw new Exception('ZIP limit exceeded: maximum 100 documents, 20 MB per file and 200 MB extracted in total.');
				}
				// Read at most the per-file limit, even if archive size metadata is false.
				if (isset($seen[$stat['name']])) { throw new Exception('The ZIP contains duplicate entry names.'); }
				$seen[$stat['name']] = true;
				$stream = $zip->getStream($stat['name']);
				if ($stream === false) { throw new Exception('Could not read a ZIP document (encrypted files are not supported).'); }
				$data = stream_get_contents($stream, self::MAX_FILE_BYTES + 1);
				fclose($stream);
				if ($data === false || strlen($data) !== (int) $stat['size'] || $data === '') {
					throw new Exception('A ZIP document is empty, damaged or exceeds the file size limit.');
				}
				if ($ext === 'pdf') {
					$valid = strncmp($data, '%PDF-', 5) === 0;
				} else {
					$info = @getimagesizefromstring($data);
					$mimes = array('jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif', 'webp' => 'image/webp');
					$valid = $info && isset($info['mime']) && $info['mime'] === $mimes[$ext];
				}
				if ( ! $valid) { throw new Exception('A ZIP document does not match its PDF or image file type: ' . basename($name)); }
				$path = rtrim($directory, '/') . '/' . bin2hex(random_bytes(16)) . '.' . $ext;
				$files[] = array('full_path' => $path, 'file_name' => basename($path), 'file_ext' => '.' . $ext, 'orig_name' => $name);
				if (file_put_contents($path, $data) !== strlen($data)) { throw new Exception('Could not save an extracted document.'); }
				$total += strlen($data);
			}
			if ($progress) { call_user_func($progress, 'extracting', $zip->numFiles, $zip->numFiles); }
			if (empty($files)) { throw new Exception('The ZIP contains no supported PDFs or images.'); }
		} catch (Exception $e) {
			foreach ($files as $file) { @unlink($file['full_path']); }
			$zip->close();
			throw $e;
		}
		$zip->close();
		return array('files' => $files, 'skipped' => $skipped);
	}
}
