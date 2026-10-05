<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Booking pax (Adult / Child / Infant) counting.
 *
 * The authoritative pax count for a booking lives in Room Management
 * (guest_list_room) with a fallback to the guest_list roster — NOT in the
 * booking.Adult / Children / Infant columns, which are intentionally frozen
 * when rooms change (see feedback_booking_pax). Everything that prints the
 * head-count (Booking Confirmation, Travel Voucher, Receipt and the e-invoice
 * "Remark 3" pax line) must agree, so the room-first / guest-list-fallback
 * rule is centralised here.
 *
 * Resolution order (first source with data wins):
 *   1. Rooms       — the current, authoritative pax.
 *   2. guest_list  — the roster, when rooms were never set up.
 *   3. $booking    — the frozen Adult/Children/Infant columns, a last resort
 *                    for legacy bookings created before Room Management, which
 *                    have neither rooms nor a roster. Without this they'd read
 *                    as 0; with it they keep their recorded pax.
 *
 * @param array $rooms   Rows from Guest_List_Room_Model::Read_Rooms_By_Booking_ID
 *                       (each carrying adult_count / child_count / infant_count).
 * @param array $guests  Rows from Guest_List_Model::Read_Guests_By_Booking_ID
 *                       (each carrying Type = ADULT|CHILD|INFANT). Used only
 *                       when the booking has no rooms set up yet.
 * @param array|object|null $booking Booking row carrying Adult / Children /
 *                       Infant. Used only when there are no rooms AND no roster.
 * @return array ['ADULT' => int, 'CHILD' => int, 'INFANT' => int]
 */
if (!function_exists('booking_pax_counts')) {
    function booking_pax_counts($rooms, $guests = array(), $booking = null)
    {
        $counts = array('ADULT' => 0, 'CHILD' => 0, 'INFANT' => 0);

        if (!empty($rooms)) {
            foreach ($rooms as $r) {
                $r = (object) $r;
                $counts['ADULT']  += (int) (isset($r->adult_count)  ? $r->adult_count  : 0);
                $counts['CHILD']  += (int) (isset($r->child_count)  ? $r->child_count  : 0);
                $counts['INFANT'] += (int) (isset($r->infant_count) ? $r->infant_count : 0);
            }
            return $counts;
        }

        if (!empty($guests)) {
            foreach ($guests as $g) {
                $g = (object) $g;
                $type = isset($g->Type) ? $g->Type : '';
                if (isset($counts[$type])) {
                    $counts[$type]++;
                }
            }
            return $counts;
        }

        if (!empty($booking)) {
            $b = (object) $booking;
            $counts['ADULT']  = isset($b->Adult)    ? (int) $b->Adult    : 0;
            $counts['CHILD']  = isset($b->Children)  ? (int) $b->Children  : 0;
            $counts['INFANT'] = isset($b->Infant)    ? (int) $b->Infant    : 0;
        }

        return $counts;
    }
}

/**
 * The e-invoice "Remark 3" pax line, e.g. "2 A, 0 C, 0 IN".
 *
 * @return string
 */
if (!function_exists('booking_pax_remark')) {
    function booking_pax_remark($rooms, $guests = array(), $booking = null)
    {
        $c = booking_pax_counts($rooms, $guests, $booking);
        return $c['ADULT'] . ' A, ' . $c['CHILD'] . ' C, ' . $c['INFANT'] . ' IN';
    }
}
