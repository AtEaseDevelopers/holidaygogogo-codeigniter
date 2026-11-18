<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Notification Category Helper
 *
 * Single source of truth for the user-facing notification category keys
 * and the underlying notification.type values they fold together.
 */

if (!function_exists('notification_category_map')) {
    function notification_category_map()
    {
        return array(
            'remark'               => array('remark'),
            'booking_updated'      => array('booking_updated'),
            'bc_reminder'          => array('draft_bc_reminder', 'pending_bc_reminder'),
            'review_submitted'     => array('review_submitted'),
            'supplier_reminder'    => array('supplier_reminder_full', 'supplier_reminder_deposit'),
            'payout_overdue'       => array('payout_overdue_full',    'payout_overdue_deposit'),
            'product_no_checklist' => array('product_no_checklist'),
        );
    }
}

if (!function_exists('notification_category_keys')) {
    function notification_category_keys()
    {
        return array_keys(notification_category_map());
    }
}

if (!function_exists('notification_category_to_types')) {
    function notification_category_to_types($category)
    {
        $map = notification_category_map();
        return isset($map[$category]) ? $map[$category] : array();
    }
}

if (!function_exists('notification_type_to_category')) {
    function notification_type_to_category($type)
    {
        foreach (notification_category_map() as $category => $types) {
            if (in_array($type, $types, true)) {
                return $category;
            }
        }
        return null;
    }
}

if (!function_exists('fold_category_counts')) {
    /**
     * Fold a [type => count] array into the user-facing category buckets.
     * Returns an associative array keyed by category, plus a 'total' key.
     */
    function fold_category_counts(array $rows_by_type)
    {
        $counts = array();
        $total  = 0;
        foreach (notification_category_map() as $category => $types) {
            $sum = 0;
            foreach ($types as $t) {
                if (isset($rows_by_type[$t])) {
                    $sum += (int)$rows_by_type[$t];
                }
            }
            $counts[$category] = $sum;
            $total += $sum;
        }
        $counts['total'] = $total;
        return $counts;
    }
}

if (!function_exists('notification_category_label')) {
    function notification_category_label($category)
    {
        static $labels = array(
            'all'                  => 'All',
            'remark'               => 'Remarks',
            'booking_updated'      => 'Booking Updated',
            'bc_reminder'          => 'Booking Reminders',
            'review_submitted'     => 'Reviews',
            'supplier_reminder'    => 'Supplier Reminders',
            'payout_overdue'       => 'Payout Overdue',
            'product_no_checklist' => 'Product Alerts',
        );
        return isset($labels[$category]) ? $labels[$category] : $category;
    }
}
