<?php
/** Run with: php tests/helpers/ManualLeadStatusChangesTest.php */
define('BASEPATH', __DIR__);
require __DIR__ . '/../../application/helpers/manual_lead_status_changes_helper.php';
function check($ok, $message) { if (!$ok) { throw new RuntimeException($message); } }
$existing = array((object) array('LogID' => 10, 'CreatedBy' => 7), (object) array('LogID' => 11, 'CreatedBy' => 8));
function prepare_changes($changes) {
    global $existing;
    return manual_lead_status_changes_prepare(json_encode($changes), $existing, 7);
}
$valid = prepare_changes(array(
    'add' => array(array('status_date' => '2026-11-05', 'lead_status' => 'FOLLOW UP 1', 'note' => ' Called guest ')),
    'notes' => array(array('id' => 11, 'note' => 'Added note after saving')),
    'delete' => array(10),
));
check($valid['ok'], 'Valid changes rejected');
check($valid['add'][0]['note'] === 'Called guest', 'New status note lost');
check($valid['notes'][0]['note'] === 'Added note after saving', 'Existing note edit lost');
check($valid['delete'] === array(10), 'Delete lost');
check(!prepare_changes(array('notes' => array(array('id' => 10, 'note' => ''))))['ok'], 'Blank note accepted');
check(!prepare_changes(array('notes' => array(array('id' => 999, 'note' => 'foreign lead'))))['ok'], 'Foreign status modified');
check(!prepare_changes(array('delete' => array(11)))['ok'], 'Other author entry deleted');
check(!prepare_changes(array('add' => array(array('status_date' => '2026-02-30', 'lead_status' => 'NEW', 'note' => ''))))['ok'], 'Impossible date accepted');
check(!prepare_changes(array('notes' => array(array('id' => 10, 'note' => str_repeat('a', 1001)))))['ok'], 'Long note accepted');
check(!manual_lead_status_changes_prepare('invalid-json', $existing, 7)['ok'], 'Invalid payload accepted');
check(manual_lead_status_changes_prepare(null, $existing, 7)['ok'], 'Old form requests without status edits rejected');
check(!manual_lead_status_seed_prepare(array(), 7)['ok'], 'Create accepted no status');
check(!manual_lead_status_seed_prepare(array('log_date' => array(''), 'log_status' => array('NEW'), 'log_note' => array('')), 7)['ok'], 'Create accepted status without date');
check(!manual_lead_status_seed_prepare(array('log_date' => array('2026-11-05'), 'log_status' => array(''), 'log_note' => array('Note')), 7)['ok'], 'Create accepted date/note without status');
$seed = manual_lead_status_seed_prepare(array('log_date' => array('2026-11-05'), 'log_status' => array('NEW'), 'log_note' => array('')), 7);
check(!$seed['ok'], 'Create accepted blank status note');
$seed = manual_lead_status_seed_prepare(array('log_date' => array('2026-11-05'), 'log_status' => array('NEW'), 'log_note' => array('Called guest')), 7);
check($seed['ok'], 'Create rejected status with note');
check(!manual_lead_status_changes_prepare('{}', array(), 7, true)['ok'], 'Edit accepted no status');
$dated = array((object) array('LogID' => 10, 'CreatedBy' => 7, 'LeadStatus' => 'NEW', 'StatusDate' => '2026-11-05', 'Note' => 'Called guest'));
check(manual_lead_status_changes_prepare('{}', $dated, 7, true)['ok'], 'Existing dated status rejected');
check(!manual_lead_status_changes_prepare('{"delete":[10]}', $dated, 7, true)['ok'], 'Last status deletion accepted');
$blank_history = array((object) array('LogID' => 10, 'CreatedBy' => 7, 'LeadStatus' => 'NEW', 'StatusDate' => '2026-11-05', 'Note' => ''));
check(!manual_lead_status_changes_prepare('{}', $blank_history, 7, true)['ok'], 'Existing empty note accepted');
check(manual_lead_status_changes_prepare('{"notes":[{"id":10,"note":"Added note"}]}', $blank_history, 7, true)['ok'], 'Existing empty note cannot be filled');
echo "OK: required status notes, additions, ownership and malformed input checked.\n";
