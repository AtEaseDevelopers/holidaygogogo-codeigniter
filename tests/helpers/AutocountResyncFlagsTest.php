<?php
/**
 * Run with: php tests/helpers/AutocountResyncFlagsTest.php
 *
 * Locks autocount_resync_flags() — the decision for how a booking is re-flagged
 * when its pushed content changes (guest list OR room pax edited) so the next
 * AutoCount sync re-pushes it.
 *
 * The bug this guards against: room edits (the authoritative source of the
 * e-invoice "Remark 3" pax line) never re-flagged the booking for re-sync, so a
 * booking synced while pax was empty kept "0 A, 0 C, 0 IN" in AutoCount even
 * after its rooms were filled in. Room endpoints now call this via
 * Booking_Model::Flag_Autocount_Resync.
 *
 * Rule:
 *   - Already synced ('S') via a Create/Update action -> queue Update + Pending.
 *   - Anything else -> just reset status to Pending, keep the queued action.
 */

if (!defined('BASEPATH')) {
    define('BASEPATH', __DIR__);
}

require_once __DIR__ . '/../../application/helpers/autocount_booking_helper.php';

$failures = 0;
function check($label, $expected, $actual)
{
    global $failures;
    if ($expected === $actual) {
        echo "PASS: $label\n";
    } else {
        $failures++;
        echo "FAIL: $label\n  expected: " . var_export($expected, true) . "\n  actual:   " . var_export($actual, true) . "\n";
    }
}

$pending = array('AutocountSyncStatus' => 'P');
$requeue = array('AutocountSyncAction' => 'U', 'AutocountSyncStatus' => 'P');

// Already-synced bookings (the real-world case: synced early, rooms edited
// later) must be re-queued as an Update so remark3 is recomputed and re-pushed.
check('synced create -> requeue update', $requeue, autocount_resync_flags('C', 'S'));
check('synced update -> requeue update', $requeue, autocount_resync_flags('U', 'S'));

// Not-yet-pushed / in-flight bookings: just ensure Pending, keep their action.
check('create still pending -> pending', $pending, autocount_resync_flags('C', 'P'));
check('update still pending -> pending', $pending, autocount_resync_flags('U', 'P'));
check('last sync failed -> pending', $pending, autocount_resync_flags('U', 'F'));

// A queued Delete that was already synced must NOT be flipped to Update — it
// falls through to the plain Pending branch, preserving the Delete action.
check('synced delete -> pending (keep delete action)', $pending, autocount_resync_flags('D', 'S'));
check('delete pending -> pending', $pending, autocount_resync_flags('D', 'P'));

// Defensive: unknown / null values never produce a requeue.
check('null action/status -> pending', $pending, autocount_resync_flags(null, null));
check('empty strings -> pending', $pending, autocount_resync_flags('', ''));

// ---------------------------------------------------------------------------
// End-to-end guarantee: "with edit" vs "without edit" select correctly.
//
// A booking is actually re-pushed only if getPendingBookingsWithDetails() picks
// it up. That query (defaults) requires: a CustomerCode, status IN ['P'], title
// = 'BOOKING CONFIRMATION', and AutocountSyncAction IS NOT NULL. This section
// mirrors that predicate so the two guarantees are locked together:
//   - WITHOUT an edit  -> state unchanged -> never NEWLY scheduled.
//   - WITH an edit     -> re-flagged      -> scheduled IFF it is a sync candidate
//                                            (never resurrects a non-candidate).
function selected_for_sync($action, $status, $title, $code)
{
    return ($code !== null && $code !== '')
        && in_array($status, array('P'), true)
        && in_array($title, array('BOOKING CONFIRMATION'), true)
        && ($action !== null);
}

// Apply a room edit: re-flag, then see if the booking becomes selectable.
function after_edit_selected($action, $status, $title, $code)
{
    $f = autocount_resync_flags($action, $status);
    $new_action = isset($f['AutocountSyncAction']) ? $f['AutocountSyncAction'] : $action;
    $new_status = $f['AutocountSyncStatus'];
    return selected_for_sync($new_action, $new_status, $title, $code);
}

$T = 'BOOKING CONFIRMATION';
$C = '300-A906'; // a real CustomerCode

// A synced booking (the screenshot case): no edit -> idle; edit -> re-synced.
check('synced + no edit -> NOT re-pushed', false, selected_for_sync('U', 'S', $T, $C));
check('synced + room edit -> re-pushed',   true,  after_edit_selected('U', 'S', $T, $C));
check('synced create + edit -> re-pushed', true,  after_edit_selected('C', 'S', $T, $C));

// Already-pending create (never synced yet): stays pending either way, no harm.
check('pending create + no edit -> already queued', true, selected_for_sync('C', 'P', $T, $C));
check('pending create + edit -> still queued',       true, after_edit_selected('C', 'P', $T, $C));

// Failed last sync: retries, edit keeps it queued.
check('failed + edit -> queued for retry', true, after_edit_selected('U', 'F', $T, $C));

// Non-candidate must NOT be resurrected by an edit:
check('null action + edit -> still excluded (no spurious sync)', false, after_edit_selected(null, 'S', $T, $C));
check('missing CustomerCode + edit -> still excluded',           false, after_edit_selected('U', 'S', $T, ''));
check('wrong title + edit -> still excluded',                    false, after_edit_selected('U', 'S', 'QUOTATION', $C));

echo $failures === 0 ? "\nALL PASS\n" : "\n$failures FAILURE(S)\n";
exit($failures === 0 ? 0 : 1);
