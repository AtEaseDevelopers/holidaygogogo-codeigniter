<?php
defined('BASEPATH') OR exit('No direct script access allowed');
require_once __DIR__ . '/guest_contact_helper.php';

/** Validate staged status edits against this visible lead's active history. */
function manual_lead_status_changes_prepare($json, $existing, $admin_id, $require_status = false)
{
    $out = array('ok' => false, 'error' => '', 'add' => array(), 'notes' => array(), 'delete' => array());
    $changes = ($json === null || $json === '') ? array() : json_decode((string) $json, true);
    if (!is_array($changes)) { $out['error'] = 'Invalid status changes. Please reopen the lead.'; return $out; }
    $by_id = array();
    foreach ($existing as $entry) { $by_id[(int) $entry->LogID] = $entry; }
    foreach (array('add', 'notes', 'delete') as $key) {
        $items = isset($changes[$key]) ? $changes[$key] : array();
        if (!is_array($items) || count($items) > 200) { $out['error'] = 'Invalid status changes.'; return $out; }
        foreach ($items as $item) {
            if ($key === 'delete') {
                $id = is_scalar($item) ? (int) $item : 0;
                if (!isset($by_id[$id]) || (int) $by_id[$id]->CreatedBy !== (int) $admin_id) {
                    $out['error'] = 'You can only delete your own status entry.'; return $out;
                }
                $out['delete'][] = $id;
                continue;
            }
            if (!is_array($item) || !isset($item['note']) || !is_string($item['note'])) {
                $out['error'] = 'Invalid status note.'; return $out;
            }
            $note = lead_status_log_validate_note($item['note']);
            if (!$note['ok']) { $out['error'] = $note['error']; return $out; }
            if ($note['value'] === '') { $out['error'] = 'A note is required for each lead status.'; return $out; }
            if ($key === 'notes') {
                $id = isset($item['id']) && is_scalar($item['id']) ? (int) $item['id'] : 0;
                if (!isset($by_id[$id])) { $out['error'] = 'Status entry not found. Please reopen the lead.'; return $out; }
                $out['notes'][] = array('id' => $id, 'note' => $note['value']);
            } else {
                $date = guest_remark_validate_date(isset($item['status_date']) && is_string($item['status_date']) ? $item['status_date'] : '', true, 'Date');
                $status = lead_status_log_validate_status(isset($item['lead_status']) && is_string($item['lead_status']) ? $item['lead_status'] : '');
                if ($date['ok'] && $date['value'] !== trim($item['status_date'])) { $date = array('ok' => false, 'error' => 'Enter a valid date.'); }
                if (!$date['ok'] || !$status['ok']) { $out['error'] = !$date['ok'] ? $date['error'] : $status['error']; return $out; }
                $out['add'][] = array('date' => $date['value'], 'status' => $status['value'], 'note' => $note['value']);
            }
        }
    }
    $out['delete'] = array_values(array_unique($out['delete']));
    if ($require_status) {
        $has_status = !empty($out['add']);
        foreach ($existing as $entry) {
            if (in_array((int) $entry->LogID, $out['delete'], true)) { continue; }
            $effective_note = isset($entry->Note) ? trim((string) $entry->Note) : '';
            foreach ($out['notes'] as $edit) {
                if ($edit['id'] === (int) $entry->LogID) { $effective_note = $edit['note']; }
            }
            if ($effective_note === '') { $out['error'] = 'A note is required for each lead status.'; return $out; }

            $date = guest_remark_validate_date(isset($entry->StatusDate) ? $entry->StatusDate : '', true, 'Status date');
            $status = lead_status_log_validate_status(isset($entry->LeadStatus) ? $entry->LeadStatus : '');
            if ($date['ok'] && $status['ok']) { $has_status = true; }
        }
        if (!$has_status) { $out['error'] = 'At least one lead status with a status date is required.'; return $out; }
    }
    $out['ok'] = true;
    return $out;
}

/** Prepare and validate the Create form's dated status rows before inserting anything. */
function manual_lead_status_seed_prepare($post, $admin_id)
{
    $add = array();
    $dates = isset($post['log_date']) && is_array($post['log_date']) ? $post['log_date'] : array();
    $statuses = isset($post['log_status']) && is_array($post['log_status']) ? $post['log_status'] : array();
    $notes = isset($post['log_note']) && is_array($post['log_note']) ? $post['log_note'] : array();
    foreach (array_unique(array_merge(array_keys($dates), array_keys($statuses), array_keys($notes))) as $i) {
        $date = isset($dates[$i]) && is_string($dates[$i]) ? trim($dates[$i]) : '';
        $status = isset($statuses[$i]) && is_string($statuses[$i]) ? trim($statuses[$i]) : '';
        $note = isset($notes[$i]) && is_string($notes[$i]) ? trim($notes[$i]) : '';
        if ($date === '' && $status === '' && $note === '') { continue; }
        $add[] = array('status_date' => $date, 'lead_status' => $status, 'note' => $note);
    }
    return manual_lead_status_changes_prepare(json_encode(array('add' => $add)), array(), $admin_id, true);
}
