<?php
defined('BASEPATH') OR exit('No direct script access allowed');

$config['enable_phone_verification'] = true;
$config['internal_access_code'] = 'hggg';
$config['show_guest_list'] = true;

// Payment-out supplier bell notifications (reminder + overdue) are gated off:
// superseded by the "Supplier Pay-out Checklist Due Soon" dashboard card.
// Flip to true to re-enable the cron-generated notifications.
$config['enable_payment_out_notifications'] = false;

// Default hours before the owner saves Booking Reminders in Settings.
$config['draft_bc_reminder_hours'] = 24;
$config['pending_bc_reminder_hours'] = 24;
