<?php
/**
 * Run with: php tests/helpers/BookingPaxHelperTest.php
 *
 * Locks the pax (Adult/Child/Infant) count used by the Booking Confirmation,
 * Travel Voucher, Receipt and the e-invoice "Remark 3" line.
 *
 * The bug this guards against: the e-invoice Remark 3 used to read the frozen
 * booking.Adult / Children / Infant columns, which stay 0 when pax is set via
 * Room Management. A "2 ADULTS" booking then printed "0 A, 0 C, 0 IN". The
 * count must come from rooms first, falling back to the guest_list roster.
 */

if (!defined('BASEPATH')) {
    define('BASEPATH', __DIR__);
}

require_once __DIR__ . '/../../application/helpers/booking_pax_helper.php';

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

function room($a, $c, $i)
{
    return (object) array('adult_count' => $a, 'child_count' => $c, 'infant_count' => $i);
}

function guest($type)
{
    return (object) array('Type' => $type);
}

// --- rooms are authoritative -------------------------------------------------

check(
    '2-adult booking via one room counts 2 adults',
    array('ADULT' => 2, 'CHILD' => 0, 'INFANT' => 0),
    booking_pax_counts(array(room(2, 0, 0)), array())
);

check(
    'the reported bug: rooms give 2 adults, remark is "2 A, 0 C, 0 IN"',
    '2 A, 0 C, 0 IN',
    booking_pax_remark(array(room(2, 0, 0)), array())
);

check(
    'multiple rooms sum across all three types',
    array('ADULT' => 5, 'CHILD' => 3, 'INFANT' => 1),
    booking_pax_counts(array(room(2, 1, 0), room(3, 2, 1)), array())
);

check(
    'remark renders children and infants from rooms',
    '5 A, 3 C, 1 IN',
    booking_pax_remark(array(room(2, 1, 0), room(3, 2, 1)), array())
);

check(
    'children-only room: "0 A, 2 C, 0 IN"',
    '0 A, 2 C, 0 IN',
    booking_pax_remark(array(room(0, 2, 0)), array())
);

check(
    'infant-only room: "0 A, 0 C, 2 IN"',
    '0 A, 0 C, 2 IN',
    booking_pax_remark(array(room(0, 0, 2)), array())
);

check(
    'rooms win even when a guest roster also exists (no double count)',
    array('ADULT' => 2, 'CHILD' => 0, 'INFANT' => 0),
    booking_pax_counts(array(room(2, 0, 0)), array(guest('ADULT'), guest('ADULT'), guest('CHILD')))
);

// --- guest_list fallback when no rooms --------------------------------------

check(
    'no rooms: counts fall back to the guest_list roster',
    array('ADULT' => 2, 'CHILD' => 1, 'INFANT' => 0),
    booking_pax_counts(array(), array(guest('ADULT'), guest('ADULT'), guest('CHILD')))
);

check(
    'guest-list fallback counts CHILD and INFANT too',
    array('ADULT' => 1, 'CHILD' => 2, 'INFANT' => 1),
    booking_pax_counts(array(), array(guest('ADULT'), guest('CHILD'), guest('CHILD'), guest('INFANT')))
);

check(
    'guest-list fallback renders full remark with C and IN',
    '1 A, 2 C, 1 IN',
    booking_pax_remark(array(), array(guest('ADULT'), guest('CHILD'), guest('CHILD'), guest('INFANT')))
);

check(
    'no rooms, no guests: all zero',
    array('ADULT' => 0, 'CHILD' => 0, 'INFANT' => 0),
    booking_pax_counts(array(), array())
);

// --- legacy column fallback (pre-Room-Management bookings) -------------------

function legacy_booking($a, $c, $i)
{
    return (object) array('Adult' => $a, 'Children' => $c, 'Infant' => $i);
}

check(
    'no rooms + no roster: fall back to booking Adult/Children/Infant columns',
    array('ADULT' => 20, 'CHILD' => 0, 'INFANT' => 0),
    booking_pax_counts(array(), array(), legacy_booking(20, null, null))
);

check(
    'legacy fallback renders C and IN from columns',
    '8 A, 5 C, 1 IN',
    booking_pax_remark(array(), array(), legacy_booking(8, 5, 1))
);

check(
    'rooms still win over legacy columns (no regression for the reported bug)',
    '2 A, 0 C, 0 IN',
    booking_pax_remark(array(room(2, 0, 0)), array(), legacy_booking(99, 99, 99))
);

check(
    'roster still wins over legacy columns',
    array('ADULT' => 1, 'CHILD' => 0, 'INFANT' => 0),
    booking_pax_counts(array(), array(guest('ADULT')), legacy_booking(99, 99, 99))
);

check(
    'unknown guest type is ignored',
    array('ADULT' => 1, 'CHILD' => 0, 'INFANT' => 0),
    booking_pax_counts(array(), array(guest('ADULT'), guest('SENIOR')))
);

// --- robustness: array-shaped rows and missing keys -------------------------

check(
    'array-shaped room rows are accepted',
    array('ADULT' => 2, 'CHILD' => 0, 'INFANT' => 0),
    booking_pax_counts(array(array('adult_count' => 2, 'child_count' => 0, 'infant_count' => 0)), array())
);

check(
    'missing count keys default to 0',
    array('ADULT' => 1, 'CHILD' => 0, 'INFANT' => 0),
    booking_pax_counts(array((object) array('adult_count' => 1)), array())
);

echo "\n";
if ($failures === 0) {
    echo "All BookingPax tests passed.\n";
    exit(0);
}
echo "$failures assertion(s) failed.\n";
exit(1);
