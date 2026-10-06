<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Supplier quotation attachments for a costing package (the rate sheets a
 * supplier sends us, kept as a reference for the Cost Template & Margin step).
 *
 * Keyed to costing_packages.id — the stable anchor — because the cost items
 * themselves are wiped and re-inserted on every save. Files are soft-deleted
 * (status 'N') and author-tracked, mirroring the chat-history / supplier-invoice
 * conventions used elsewhere.
 */
class Costing_Quotation_File_Model extends CI_Model
{
    /**
     * Insert a quotation attachment row. Returns the new id (0 on failure).
     *
     * @param array $data keys: costing_package_id, supplier, title,
     *                    original_name, stored_path, file_size, created_by
     */
    public function Add($data)
    {
        $row = array(
            'costing_package_id' => (int) (isset($data['costing_package_id']) ? $data['costing_package_id'] : 0),
            'supplier'           => isset($data['supplier']) ? (trim((string) $data['supplier']) ?: null) : null,
            'title'              => isset($data['title']) ? (trim((string) $data['title']) ?: null) : null,
            'original_name'      => (string) (isset($data['original_name']) ? $data['original_name'] : ''),
            'stored_path'        => (string) (isset($data['stored_path']) ? $data['stored_path'] : ''),
            'file_size'          => isset($data['file_size']) && $data['file_size'] !== null ? (int) $data['file_size'] : null,
            'status'             => 'Y',
            'created_by'         => isset($data['created_by']) && $data['created_by'] !== null ? (int) $data['created_by'] : null,
        );

        if ($row['costing_package_id'] <= 0 || $row['original_name'] === '' || $row['stored_path'] === '') {
            return 0;
        }

        $this->db->insert('costing_quotation_files', $row);
        return (int) $this->db->insert_id();
    }

    /**
     * Active quotation attachments for a package, newest first. Each row carries
     * a CanDelete flag (only the uploader may delete) for the current admin.
     *
     * @param int $package_id
     * @param int $admin_id current admin (for CanDelete); 0 = none
     * @return array
     */
    public function Read_By_Package($package_id, $admin_id = 0)
    {
        $package_id = (int) $package_id;
        if ($package_id <= 0) {
            return array();
        }

        $this->db->select('id, costing_package_id, supplier, title, original_name, stored_path, file_size, created_by, created_at');
        $this->db->where('costing_package_id', $package_id);
        $this->db->where('status', 'Y');
        $this->db->order_by('id', 'DESC');
        $rows = $this->db->get('costing_quotation_files')->result_array();

        $admin_id = (int) $admin_id;
        foreach ($rows as &$row) {
            $row['CanDelete'] = ($admin_id > 0 && (int) $row['created_by'] === $admin_id);
        }
        unset($row);

        return $rows;
    }

    /**
     * Single active attachment by id (null if missing / deleted).
     */
    public function Get($id)
    {
        $id = (int) $id;
        if ($id <= 0) {
            return null;
        }
        $this->db->where('id', $id);
        $this->db->where('status', 'Y');
        return $this->db->get('costing_quotation_files')->row_array();
    }

    /**
     * Soft-delete an attachment. Only the uploader may delete. Returns the
     * deleted row (so the caller can unlink the file on disk), or null if the
     * row was missing, already deleted, or not owned by $admin_id.
     */
    public function Delete($id, $admin_id)
    {
        $row = $this->Get($id);
        if (empty($row)) {
            return null;
        }
        // Uploader-only. A missing deleter identity or an unowned (NULL uploader)
        // row must never fall open via 0 === 0, so both ids must be real and equal.
        $admin_id = (int) $admin_id;
        $owner_id = (int) $row['created_by'];
        if ($admin_id <= 0 || $owner_id <= 0 || $owner_id !== $admin_id) {
            return null;
        }

        $this->db->where('id', (int) $id);
        $this->db->update('costing_quotation_files', array('status' => 'N'));

        return $row;
    }
}
