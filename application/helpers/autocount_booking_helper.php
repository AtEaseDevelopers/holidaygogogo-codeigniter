<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Enrich a booking row with the derived fields the AutoCount quotation sync
 * sends (validity, yourRef/remark2, pax cc/remark3, destination/remark4,
 * salesAgent name, CustomerCode).
 *
 * This is the SINGLE source of truth for that mapping so every entry point
 * agrees — the nightly Cron (Cron::syncBookings) and the interactive
 * "Sync Autocount" button (Booking::bulkSyncToAutocount). Previously the button
 * built its own payload and never computed remark3, so a re-sync could not fix
 * a wrong pax line.
 *
 * @param object $ci      CodeIgniter instance (controller/model) — used to load
 *                        the lookup models.
 * @param array  $booking Booking row as an associative array (must carry
 *                        BookingID; may carry SalesAgent, StartDate, EndDate,
 *                        ReservationNumber, Destination, CustomerID, and the
 *                        legacy Adult/Children/Infant columns).
 * @return array The same row with the derived AutoCount fields added.
 */
if (!function_exists('enrich_autocount_booking')) {
    function enrich_autocount_booking($ci, $booking)
    {
        $ci->load->helper('booking_pax');
        $ci->load->model('Admin_Model');
        $ci->load->model('Category_Model');
        $ci->load->model('Customer_Model');
        $ci->load->model('Guest_List_Room_Model');
        $ci->load->model('Guest_List_Model');

        // Sales agent name
        if (!empty($booking['SalesAgent'])) {
            $sale_agent = $ci->Admin_Model->find($booking['SalesAgent']);
            if ($sale_agent) {
                $booking['salesAgent'] = $sale_agent->Name;
            }
        }

        // Validity (travel date range) → also remark1
        if (!empty($booking['StartDate']) && !empty($booking['EndDate'])) {
            $booking['validity']     = $booking['StartDate'] . ' - ' . $booking['EndDate'];
            $booking['BokingRemark'] = $booking['StartDate'] . ' - ' . $booking['EndDate'];
        }

        // yourRef / remark2 (reservation number)
        if (!empty($booking['ReservationNumber'])) {
            $booking['yourRef'] = $booking['ReservationNumber'];
            $booking['remark2'] = $booking['ReservationNumber'];
        }

        // cc / remark3 — pax head-count for the e-invoice "Remark 3" line.
        // Authoritative source is Room Management (guest_list_room) with a
        // guest_list fallback, then the frozen booking.Adult/Children/Infant
        // columns for legacy bookings — NOT the frozen columns first (those are
        // stale when pax is set via rooms, see feedback_booking_pax). Mirrors the
        // Booking Confirmation / Travel Voucher logic.
        $rooms  = $ci->Guest_List_Room_Model->Read_Rooms_By_Booking_ID($booking['BookingID']);
        $guests = empty($rooms) ? $ci->Guest_List_Model->Read_Guests_By_Booking_ID($booking['BookingID']) : array();
        $booking['cc']      = booking_pax_remark($rooms, $guests, $booking);
        $booking['remark3'] = $booking['cc'];

        // deliveryTerm / remark4 (destination name)
        if (!empty($booking['Destination'])) {
            $Destination = $ci->Category_Model->find($booking['Destination']);
            if ($Destination) {
                $booking['Destination']  = $Destination->Name;
                $booking['deliveryTerm'] = $Destination->Name;
                $booking['remark4']      = $Destination->Name;
            }
        }

        // Debtor code
        if (!empty($booking['CustomerID'])) {
            $customer = $ci->Customer_Model->find($booking['CustomerID']);
            if (!empty($customer) && !empty($customer->CustomerCode)) {
                $booking['CustomerCode'] = $customer->CustomerCode;
            }
        }

        return $booking;
    }
}

/**
 * Decide the AutoCount sync flags to write when a booking's content changes
 * (guest list OR rooms edited) and must be pushed again.
 *
 * The pax head-count in the AutoCount "Remark 3" line comes from Room
 * Management, so a room edit must re-flag the booking for re-sync — otherwise
 * remark3 stays stale (e.g. "0 A" after rooms were filled in post-sync). This
 * is the single source of that decision so the guest-list and room edit paths
 * agree.
 *
 *   - Already synced ('S') via a Create/Update action → queue an Update ('U')
 *     and reset status to Pending ('P') so the Cron/button re-pushes it.
 *   - Otherwise (never synced yet, or a Delete pending) → just reset status to
 *     Pending, keeping whatever action is already queued.
 *
 * @param string|null $sync_action Current booking.AutocountSyncAction (C/U/S/D).
 * @param string|null $sync_status Current booking.AutocountSyncStatus (P/S/F).
 * @return array Fields to update on the booking row.
 */
if (!function_exists('autocount_resync_flags')) {
    function autocount_resync_flags($sync_action, $sync_status)
    {
        if (($sync_action === 'C' || $sync_action === 'U') && $sync_status === 'S') {
            return array(
                'AutocountSyncAction' => 'U',
                'AutocountSyncStatus' => 'P',
            );
        }

        return array('AutocountSyncStatus' => 'P');
    }
}
