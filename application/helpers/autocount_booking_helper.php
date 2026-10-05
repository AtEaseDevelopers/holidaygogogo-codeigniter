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
