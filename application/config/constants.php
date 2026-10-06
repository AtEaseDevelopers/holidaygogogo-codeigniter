<?php
defined('BASEPATH') or exit('No direct script access allowed');

/*
|--------------------------------------------------------------------------
| Display Debug backtrace
|--------------------------------------------------------------------------
|
| If set to TRUE, a backtrace will be displayed along with php errors. If
| error_reporting is disabled, the backtrace will not display, regardless
| of this setting
|
*/
defined('SHOW_DEBUG_BACKTRACE') or define('SHOW_DEBUG_BACKTRACE', TRUE);

/*
|--------------------------------------------------------------------------
| File and Directory Modes
|--------------------------------------------------------------------------
|
| These prefs are used when checking and setting modes when working
| with the file system.  The defaults are fine on servers with proper
| security, but you may wish (or even need) to change the values in
| certain environments (Apache running a separate process for each
| user, PHP under CGI with Apache suEXEC, etc.).  Octal values should
| always be used to set the mode correctly.
|
*/
defined('FILE_READ_MODE')  or define('FILE_READ_MODE', 0644);
defined('FILE_WRITE_MODE') or define('FILE_WRITE_MODE', 0666);
defined('DIR_READ_MODE')   or define('DIR_READ_MODE', 0755);
defined('DIR_WRITE_MODE')  or define('DIR_WRITE_MODE', 0755);

/*
|--------------------------------------------------------------------------
| File Stream Modes
|--------------------------------------------------------------------------
|
| These modes are used when working with fopen()/popen()
|
*/
defined('FOPEN_READ')                           or define('FOPEN_READ', 'rb');
defined('FOPEN_READ_WRITE')                     or define('FOPEN_READ_WRITE', 'r+b');
defined('FOPEN_WRITE_CREATE_DESTRUCTIVE')       or define('FOPEN_WRITE_CREATE_DESTRUCTIVE', 'wb'); // truncates existing file data, use with care
defined('FOPEN_READ_WRITE_CREATE_DESTRUCTIVE')  or define('FOPEN_READ_WRITE_CREATE_DESTRUCTIVE', 'w+b'); // truncates existing file data, use with care
defined('FOPEN_WRITE_CREATE')                   or define('FOPEN_WRITE_CREATE', 'ab');
defined('FOPEN_READ_WRITE_CREATE')              or define('FOPEN_READ_WRITE_CREATE', 'a+b');
defined('FOPEN_WRITE_CREATE_STRICT')            or define('FOPEN_WRITE_CREATE_STRICT', 'xb');
defined('FOPEN_READ_WRITE_CREATE_STRICT')       or define('FOPEN_READ_WRITE_CREATE_STRICT', 'x+b');

/*
|--------------------------------------------------------------------------
| Exit Status Codes
|--------------------------------------------------------------------------
|
| Used to indicate the conditions under which the script is exit()ing.
| While there is no universal standard for error codes, there are some
| broad conventions.  Three such conventions are mentioned below, for
| those who wish to make use of them.  The CodeIgniter defaults were
| chosen for the least overlap with these conventions, while still
| leaving room for others to be defined in future versions and user
| applications.
|
| The three main conventions used for determining exit status codes
| are as follows:
|
|    Standard C/C++ Library (stdlibc):
|       http://www.gnu.org/software/libc/manual/html_node/Exit-Status.html
|       (This link also contains other GNU-specific conventions)
|    BSD sysexits.h:
|       http://www.gsp.com/cgi-bin/man.cgi?section=3&topic=sysexits
|    Bash scripting:
|       http://tldp.org/LDP/abs/html/exitcodes.html
|
*/
defined('EXIT_SUCCESS')        or define('EXIT_SUCCESS', 0); // no errors
defined('EXIT_ERROR')          or define('EXIT_ERROR', 1); // generic error
defined('EXIT_CONFIG')         or define('EXIT_CONFIG', 3); // configuration error
defined('EXIT_UNKNOWN_FILE')   or define('EXIT_UNKNOWN_FILE', 4); // file not found
defined('EXIT_UNKNOWN_CLASS')  or define('EXIT_UNKNOWN_CLASS', 5); // unknown class
defined('EXIT_UNKNOWN_METHOD') or define('EXIT_UNKNOWN_METHOD', 6); // unknown class member
defined('EXIT_USER_INPUT')     or define('EXIT_USER_INPUT', 7); // invalid user input
defined('EXIT_DATABASE')       or define('EXIT_DATABASE', 8); // database error
defined('EXIT__AUTO_MIN')      or define('EXIT__AUTO_MIN', 9); // lowest automatically-assigned error code
defined('EXIT__AUTO_MAX')      or define('EXIT__AUTO_MAX', 125); // highest automatically-assigned error code

//Admin
defined('GENDER')              or define('GENDER', serialize(array('F' => 'FEMALE', 'M' => 'MALE')));
defined('LEVEL')               or define('LEVEL', serialize(array(10 => 'OWNER', 20 => 'SALES AGENT', 25 => 'TEAM LEAD', 30 => 'FINANCE', 40 => 'OP', 45 => 'OP TEAM LEAD', 50 => 'TC', 60 => 'MARKETING')));

// Bookings created on/after this date credit a lead-to-booking conversion to TC2
// (booking.SalesAgent2); earlier bookings stay with TC1 (booking.SalesAgent).
defined('LEAD_CONVERSION_TC2_CUTOFF_DATE') or define('LEAD_CONVERSION_TC2_CUTOFF_DATE', '2026-06-01');

// Source name marking a booking as self-generated (agent's own lead). Every
// other source — or NULL — is treated as company-generated on the TC LEAD
// "Self Gen vs Company" card. Update here if the team renames the row in the
// source table.
defined('SELF_GEN_SOURCE_NAME') or define('SELF_GEN_SOURCE_NAME', 'SELF GEN');
defined('ADMIN_STATUS')        or define('ADMIN_STATUS', serialize(array('Y' => 'ACTIVE', 'D' => 'DEACTIVATED')));
defined('ACCESS_CONTROL')      or define('ACCESS_CONTROL', serialize(array('GB' => 'GENERATE BOOKING', 'VB' => 'VIEW BOOKING', 'AB' => 'AMEND BOOKING', 'RB' => 'REMOVE BOOKING', 'GP' => 'GENERATE PAYMENT', 'VP' => 'VIEW PAYMENT', 'AP' => 'AMEND PAYMENT', 'RP' => 'REMOVE PAYMENT', 'VR' => 'VIEW REPORT', 'ML' => 'MESSAGE LOG', 'FV' => 'FAQ VIEW ACCESS', 'FE' => 'FAQ EDIT ACCESS', 'FKS' => 'FAQ KNOWLEDGE SOURCE MANAGER', 'VPR' => 'VIEW PRODUCT')));

//Booking
defined('BOOKING_STATUS')      or define('BOOKING_STATUS', serialize(array(
    'A'     => 'ALL STATUSES',
    'OG'    => 'ON-GOING',
    'PP'    => 'PARTIAL PAYMENT',
    'PO'    => 'PAYMENT OVERDUE',
    'PR'    => 'PENDING REVIEW',

    // current booking flow
    'SAD'   => 'SAVE AS DRAFT',
    'PB'    => 'PENDING BC',
    'PBC'   => 'PENDING BC CONFIRMATION',
    'P'     => 'PENDING PAYMENT',
    'PBO'   => 'PENDING BOOKING OPERATION',
    'PGL'   => 'PENDING GUEST LIST',
    'PTV'   => 'PENDING TRAVEL VOUCHER',
    'PT'    => 'PENDING TRAVEL',
    'Y'     => 'COMPLETED',
    'C'     => 'CANCELLED',
    // end current booking flow
)));
defined('BC_TITLE')            or define('BC_TITLE', serialize(array('BOOKING CONFIRMATION' => 'BOOKING CONFIRMATION', 'QUOTATION' => 'QUOTATION', 'PROFORMA INVOICE' => 'PROFORMA INVOICE')));
defined('CHAT_LANGUAGE')       or define('CHAT_LANGUAGE', serialize(array('CN' => 'CN', 'EN' => 'EN', 'ML' => 'ML')));
// Superseded by the `customer_type` table (managed via the Customer Type admin page). Retained as a fallback reference only — do not edit.
defined('CUSTOMER_TYPE')       or define('CUSTOMER_TYPE', serialize(array('Company' => 'Company', 'Chinese' => 'Chinese', 'Malay' => 'Malay', 'Indian' => 'Indian', 'Foreigner' => 'Foreigner')));

//Payment
defined('PAYMENT_STATUS')      or define('PAYMENT_STATUS', serialize(array('Y' => 'APPROVED', 'P' => 'PENDING', 'R' => 'REJECTED')));
defined('PAYMENT_TYPE')        or define('PAYMENT_TYPE', serialize(array('ADDITIONAL PAYMENT' => 'ADDITIONAL PAYMENT', 'AGENT COMMISSION' => 'AGENT COMMISSION', 'AGENT COMMISSION FROM SUPPLIER' => 'AGENT COMMISSION FROM SUPPLIER', 'BANK CHARGES' => 'BANK CHARGES', 'CUSTOMER REFUND' => 'CUSTOMER REFUND', 'DEPOSIT' => 'DEPOSIT', 'FULL' => 'FULL', 'ONE-TIME PAYMENT' => 'ONE-TIME PAYMENT', 'SUPPLIER PAYMENT (DEPOSIT)' => 'SUPPLIER PAYMENT (DEPOSIT)', 'SUPPLIER PAYMENT (FULL)' => 'SUPPLIER PAYMENT (FULL)', 'SUPPLIER PAYMENT (ADDITIONAL)' => 'SUPPLIER PAYMENT (ADDITIONAL)', 'SUPPLIER REFUND' => 'SUPPLIER REFUND', 'CREDIT CARD CHARGES' => 'CREDIT CARD CHARGES')));
defined('TRANSACTION_TYPE')    or define('TRANSACTION_TYPE', serialize(array('PAYMENT IN' => 'PAYMENT IN', 'PAYMENT OUT' => 'PAYMENT OUT')));

//Category
defined('IS_DESTINATION')      or define('IS_DESTINATION', serialize(array('NO' => 'NO', 'YES' => 'YES')));
defined('INCLUDE_FOUR_DIGITS') or define('INCLUDE_FOUR_DIGITS', serialize(array('YES' => 'YES', 'NO' => 'NO')));
