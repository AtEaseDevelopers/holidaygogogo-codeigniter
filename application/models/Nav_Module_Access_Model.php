<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Nav_Module_Access_Model extends CI_Model
{
	function Read_For_Admin($admin_id)
	{
		if ( ! $this->db->table_exists('nav_module_access')) {
			return array();
		}

		$rows = $this->db
			->select('Module, CanView, CanEdit')
			->where('AdminID', (int) $admin_id)
			->get('nav_module_access')
			->result_array();

		$out = array();
		foreach ($rows as $r) {
			$out[$r['Module']] = array(
				'CanView' => (int) $r['CanView'],
				'CanEdit' => (int) $r['CanEdit'],
			);
		}
		return $out;
	}

	function Read_Grid()
	{
		$admins = $this->db
			->select('AdminID, Name, Level, Status, AccessControl')
			->where('AdminID !=', 8)
			->where('Level !=', '10')
			->where('Status', 'Y')
			->order_by('Name', 'ASC')
			->get('admin')
			->result();

		$nav_by_admin = array();
		if ($this->db->table_exists('nav_module_access')) {
			$all = $this->db->select('AdminID, Module, CanView, CanEdit')->get('nav_module_access')->result();
			foreach ($all as $r) {
				$nav_by_admin[(int) $r->AdminID][$r->Module] = array(
					'CanView' => (int) $r->CanView,
					'CanEdit' => (int) $r->CanEdit,
				);
			}
		}

		$lc_by_admin = array();
		if ($this->db->table_exists('lc_module_access')) {
			$lc_all = $this->db->select('AdminID, Module, CanView, CanEdit')->get('lc_module_access')->result();
			foreach ($lc_all as $r) {
				$lc_by_admin[(int) $r->AdminID][$r->Module] = array(
					'CanView' => (int) $r->CanView,
					'CanEdit' => (int) $r->CanEdit,
				);
			}
		}

		foreach ($admins as $a) {
			$aid = (int) $a->AdminID;
			$nav_rows = isset($nav_by_admin[$aid]) ? $nav_by_admin[$aid] : array();
			$lc_rows  = isset($lc_by_admin[$aid]) ? $lc_by_admin[$aid] : array();
			$a->access = array();
			foreach (array_keys(nav_modules()) as $mod) {
				$a->access[$mod] = nav_resolve_access(
					$mod,
					$a->Level,
					$a->AccessControl,
					$aid,
					$nav_rows,
					$lc_rows
				);
			}
		}

		return $admins;
	}

	function Save()
	{
		if ( ! $this->db->table_exists('nav_module_access')) {
			return false;
		}

		$posted   = $this->input->post('access');
		$admin_id = $this->session->userdata('admin_id');
		$now      = date('Y-m-d H:i:s');
		$modules  = array_keys(nav_modules());

		$valid_admins = $this->db
			->select('AdminID')
			->where('AdminID !=', 8)
			->where('Level !=', '10')
			->where('Status', 'Y')
			->get('admin')
			->result();
		$valid_ids = array();
		foreach ($valid_admins as $row) {
			$valid_ids[(int) $row->AdminID] = true;
		}

		$this->db->trans_start();
		$this->db->truncate('nav_module_access');

		$batch = array();
		if (is_array($posted)) {
			foreach ($posted as $aid => $mods) {
				$aid = (int) $aid;
				if ($aid <= 0 || ! isset($valid_ids[$aid]) || ! is_array($mods)) {
					continue;
				}
				foreach ($modules as $m) {
					$view = ! empty($mods[$m]['view']) ? 1 : 0;
					$edit = ! empty($mods[$m]['edit']) ? 1 : 0;
					if ($edit) {
						$view = 1;
					}
					$batch[] = array(
						'AdminID'    => $aid,
						'Module'     => $m,
						'CanView'    => $view,
						'CanEdit'    => $edit,
						'InsertBy'   => $admin_id,
						'InsertDate' => $now,
					);
				}
			}
		}

		if ( ! empty($batch)) {
			foreach (array_chunk($batch, 200) as $chunk) {
				$this->db->insert_batch('nav_module_access', $chunk);
			}
		}

		$this->db->trans_complete();
		return $this->db->trans_status();
	}
}
