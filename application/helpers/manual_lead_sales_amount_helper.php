<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/** Format a single MYR amount without rounding or interpreting legacy notes. */
function manual_lead_format_sales_amount($value)
{
    $value = trim((string) $value);
    if (strlen($value) > 255 || !preg_match('/^(?:RM\s*)?([0-9]+|[0-9]{1,3}(?:,[0-9]{3})+)(?:\.([0-9]{1,2}))?$/iD', $value, $parts)) {
        return null;
    }

    $whole = ltrim(str_replace(',', '', $parts[1]), '0');
    $whole = preg_replace('/\B(?=(?:[0-9]{3})+$)/', ',', $whole === '' ? '0' : $whole);
    $cents = isset($parts[2]) ? str_pad($parts[2], 2, '0') : '00';
    $formatted = 'RM' . $whole . ($cents === '00' ? '' : '.' . $cents);
    return strlen($formatted) <= 255 ? $formatted : null;
}

function manual_lead_display_sales_amount($value)
{
    return manual_lead_format_sales_amount($value) ?? trim((string) $value);
}
