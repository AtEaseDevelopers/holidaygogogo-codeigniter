<?php
$currencies = isset($currencies) ? $currencies : array();
$exchange_rates = isset($exchange_rates) ? $exchange_rates : array();
$exchange_rate_histories = isset($exchange_rate_histories) ? $exchange_rate_histories : array();
$currency_options = isset($currency_options) ? $currency_options : array();
$currency_filters = isset($currency_filters) ? $currency_filters : array('code' => '', 'name' => '', 'symbol' => '');
$currency_filter_open = !empty($currency_filters['code']) || !empty($currency_filters['name']) || $currency_filters['symbol'] !== '';
$myr_currency_id = 0;
$latest_rates_by_currency = array();
$rate_histories_by_currency = array();

foreach ($currency_options as $currency) {
    if (isset($currency['code']) && $currency['code'] === 'MYR') {
        $myr_currency_id = (int) $currency['id'];
        break;
    }
}

foreach ($exchange_rates as $exchange_rate) {
    $to_is_myr = ((int) $exchange_rate['to_currency_id'] === $myr_currency_id) || (isset($exchange_rate['to_currency_code']) && $exchange_rate['to_currency_code'] === 'MYR');
    if ($to_is_myr) {
        $latest_rates_by_currency[(int) $exchange_rate['from_currency_id']] = $exchange_rate;
    }
}

foreach ($exchange_rate_histories as $exchange_rate) {
    $to_is_myr = ((int) $exchange_rate['to_currency_id'] === $myr_currency_id) || (isset($exchange_rate['to_currency_code']) && $exchange_rate['to_currency_code'] === 'MYR');
    if ($to_is_myr) {
        $currency_id = (int) $exchange_rate['from_currency_id'];
        if (!isset($rate_histories_by_currency[$currency_id])) {
            $rate_histories_by_currency[$currency_id] = array();
        }
        $rate_histories_by_currency[$currency_id][] = $exchange_rate;
    }
}

$display_currencies = array();
foreach ($currencies as $currency) {
    if (!isset($currency['code']) || $currency['code'] !== 'MYR') {
        $display_currencies[] = $currency;
    }
}

$currency_per_page_options = array(10, 25, 50, 100);
$currency_per_page = (int) $this->input->get('per_page');
if (!in_array($currency_per_page, $currency_per_page_options, true)) {
    $currency_per_page = 10;
}

$currency_total_rows = count($display_currencies);
$currency_total_pages = $currency_total_rows > 0 ? (int) ceil($currency_total_rows / $currency_per_page) : 1;
$currency_current_page = max(1, (int) $this->input->get('page'));
$currency_current_page = min($currency_current_page, $currency_total_pages);
$currency_offset = ($currency_current_page - 1) * $currency_per_page;
$paged_currencies = array_slice($display_currencies, $currency_offset, $currency_per_page);
$currency_start_row = $currency_total_rows > 0 ? $currency_offset + 1 : 0;
$currency_end_row = min($currency_offset + $currency_per_page, $currency_total_rows);
$currency_query_params = $this->input->get();

if (!function_exists('costing_currency_page_url')) {
    function costing_currency_page_url($page, $query_params)
    {
        $query_params['page'] = $page;
        return current_url() . '?' . http_build_query($query_params);
    }
}

if (!function_exists('costing_currency_display_date')) {
    function costing_currency_display_date($value)
    {
        if (empty($value)) {
            return 'No date';
        }

        $timestamp = strtotime($value);
        if (!$timestamp) {
            return $value;
        }

        return strtoupper(date('d M Y H:i', $timestamp));
    }
}
?>

<style>
    .currency-toolbar {
        gap: 10px;
    }

    .currency-summary {
        border: 1px solid #D7E2F2;
        background: #F7FAFF;
        border-radius: 6px;
        padding: 14px 16px;
    }

    .currency-code-mark {
        min-width: 74px;
        justify-content: center;
        letter-spacing: 0;
    }

    .currency-rate-card {
        min-width: 160px;
        text-align: left;
    }

    .currency-action-grid {
        display: flex;
        justify-content: center;
        flex-wrap: wrap;
        gap: 6px;
    }

    .currency-action-grid .btn {
        min-width: 62px;
        padding: 0.35rem 0.6rem;
        font-size: 11px;
        line-height: 1.2;
    }

    .rate-history-chart-wrap {
        border: 1px solid #D7E2F2;
        background: #F7FAFF;
        border-radius: 6px;
        padding: 14px;
        margin-bottom: 16px;
    }

    .rate-history-chart {
        display: block;
        width: 100%;
        height: 260px;
    }

    .rate-history-summary {
        display: grid;
        grid-template-columns: repeat(4, minmax(0, 1fr));
        gap: 10px;
        margin-bottom: 16px;
    }

    .rate-history-summary-item {
        border: 1px solid #D7E2F2;
        border-radius: 6px;
        padding: 10px 12px;
        background: #FFFFFF;
    }

    @media (max-width: 768px) {
        .rate-history-summary {
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }
    }

    @media (max-width: 768px) {
        .currency-action-grid {
            justify-content: flex-start;
        }

        .currency-action-grid .btn {
            flex: 1 1 46%;
        }
    }
</style>

<div class="d-flex flex-column-fluid">
    <div class="container-fluid">
        <div class="card card-custom mb-5">
            <div class="card-header flex-wrap py-3" style="background-color:#D7E2F2;">
                <div class="card-title">
                    <h3 class="card-label" style="color:#6082B6;">
                        <strong>Costing Currency Records</strong>
                    </h3>
                </div>
                <div class="card-toolbar currency-toolbar d-flex flex-wrap">
                    <a href="<?php echo base_url('Costing'); ?>" class="btn btn-light-primary font-weight-bold">
                        <i class="la la-arrow-left"></i>Back To Packages
                    </a>
                    <a href="<?php echo base_url('Costing/Export_Currency_History'); ?>" class="btn btn-light-success font-weight-bold">
                        <i class="la la-file-excel-o"></i>Export History
                    </a>
                    <button type="button" class="btn btn-primary font-weight-bold" id="add_currency_button" data-toggle="modal" data-target="#currency_modal">
                        <i class="la la-plus"></i>Add Currency
                    </button>
                </div>
            </div>
            <div class="card-body">
                <?php if ($this->session->flashdata('message_success')) { ?>
                    <div class="alert alert-success"><?php echo html_escape($this->session->flashdata('message_success')); ?></div>
                <?php } ?>
                <?php if ($this->session->flashdata('message_error')) { ?>
                    <div class="alert alert-danger"><?php echo html_escape($this->session->flashdata('message_error')); ?></div>
                <?php } ?>

                <div class="d-flex justify-content-between align-items-start flex-wrap mb-6">
                    <div class="mb-3">
                        <div class="font-size-h5 font-weight-bold text-dark mb-2">Currency Setup</div>
                        <div class="text-muted">Manage each unique currency, its latest MYR rate, bank charges, and rate history from one place.</div>
                    </div>
                    <div class="currency-summary">
                        <div class="text-muted font-size-sm">Base Currency</div>
                        <div class="font-weight-bold text-dark font-size-h5">MYR</div>
                    </div>
                </div>

                <div class="accordion accordion-solid accordion-toggle-plus mb-6">
                    <div class="card">
                        <div class="card-header">
                            <div id="currency_filter_header" data-toggle="collapse" data-target="#currency_filter_body" class="card-title <?php echo $currency_filter_open ? '' : 'collapsed'; ?>" style="font-size:13px;">
                                Filter Currency Records
                            </div>
                        </div>
                        <div id="currency_filter_body" class="collapse <?php echo $currency_filter_open ? 'show' : ''; ?>">
                            <div class="card-body">
                                <form action="<?php echo base_url('Costing/Currency'); ?>" method="get" class="form">
                                    <div class="row">
                                        <div class="col-md-3">
                                            <div class="form-group">
                                                <label>Code</label>
                                                <input type="text" name="currency_code" value="<?php echo html_escape($currency_filters['code']); ?>" autocomplete="off" class="form-control">
                                            </div>
                                        </div>
                                        <div class="col-md-4">
                                            <div class="form-group">
                                                <label>Name</label>
                                                <input type="text" name="currency_name" value="<?php echo html_escape($currency_filters['name']); ?>" autocomplete="off" class="form-control">
                                            </div>
                                        </div>
                                        <div class="col-md-3">
                                            <div class="form-group">
                                                <label>Symbol</label>
                                                <input type="text" name="currency_symbol" value="<?php echo html_escape($currency_filters['symbol']); ?>" autocomplete="off" class="form-control">
                                            </div>
                                        </div>
                                        <div class="col-md-2">
                                            <div class="form-group">
                                                <label>Per Page</label>
                                                <select name="per_page" class="form-control">
                                                    <?php foreach ($currency_per_page_options as $per_page_option) { ?>
                                                        <option value="<?php echo $per_page_option; ?>" <?php echo $currency_per_page === $per_page_option ? 'selected' : ''; ?>><?php echo $per_page_option; ?></option>
                                                    <?php } ?>
                                                </select>
                                            </div>
                                        </div>
                                    </div>
                                    <button type="submit" class="btn btn-light-success font-weight-bold" style="width:80px;">Filter</button>
                                    <a href="<?php echo base_url('Costing/Currency'); ?>" class="btn btn-light-primary font-weight-bold" style="width:80px;">Reset</a>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="d-flex justify-content-between align-items-center flex-wrap mb-5">
                    <div>
                        <h4 class="mb-1">Currencies</h4>
                        <div class="text-muted">Latest rate is shown as <strong>1 foreign currency = X MYR</strong>, with MYR bank charges tracked separately.</div>
                    </div>
                    <div class="text-muted mt-2 mt-md-0">
                        Showing <?php echo number_format($currency_start_row); ?> to <?php echo number_format($currency_end_row); ?> of <?php echo number_format($currency_total_rows); ?> record<?php echo $currency_total_rows === 1 ? '' : 's'; ?>
                    </div>
                </div>

                <div class="dataTables_wrapper dt-bootstrap4 no-footer" <?php if (empty($paged_currencies)) { echo 'style="overflow-x:auto;"'; } ?>>
                    <table class="table table-bordered table-head-custom table-checkable dataTable no-footer dtr-inline">
                        <thead>
                            <tr>
                                <th style="text-align:center; width:70px;">No.</th>
                                <th style="text-align:center; width:120px;">Code</th>
                                <th style="text-align:center;">Currency</th>
                                <th style="text-align:center; width:120px;">Symbol</th>
                                <th style="text-align:center; width:210px;">Latest MYR Rate</th>
                                <th style="text-align:center; width:150px;">Buffer</th>
                                <th style="text-align:center; width:160px;">Bank Charges</th>
                                <th style="text-align:center; width:160px;">Updated By</th>
                                <th class="action" style="text-align:center; min-width:380px;">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($paged_currencies)) { ?>
                                <tr>
                                    <td colspan="9" style="text-align:center; padding-top:16px; padding-bottom:16px;">Currency Records Not Found</td>
                                </tr>
                            <?php } else { ?>
                                <?php $count = $currency_start_row; ?>
                                <?php foreach ($paged_currencies as $currency) { ?>
                                    <?php
                                    $currency_id = (int) $currency['id'];
                                    $latest_rate = isset($latest_rates_by_currency[$currency_id]) ? $latest_rates_by_currency[$currency_id] : null;
                                    $history_rows = isset($rate_histories_by_currency[$currency_id]) ? $rate_histories_by_currency[$currency_id] : array();
                                    ?>
                                    <tr>
                                        <td style="text-align:center; padding-top:18px; padding-bottom:18px;"><?php echo $count; ?></td>
                                        <td style="text-align:center;">
                                            <span class="label label-lg label-light-primary label-inline currency-code-mark"><?php echo html_escape($currency['code']); ?></span>
                                        </td>
                                        <td>
                                            <div class="font-weight-bold text-dark"><?php echo html_escape($currency['name']); ?></div>
                                        </td>
                                        <td style="text-align:center;"><?php echo $currency['symbol'] !== null && $currency['symbol'] !== '' ? html_escape($currency['symbol']) : '<span class="text-muted">-</span>'; ?></td>
                                        <td style="text-align:center;">
                                            <?php if ($latest_rate) { ?>
                                                <?php $latest_buffer = (float) (isset($latest_rate['buffer']) ? $latest_rate['buffer'] : 0); ?>
                                                <div class="currency-rate-card mx-auto">
                                                    <div class="font-weight-bold text-dark"><?php echo number_format((float) $latest_rate['rate'], 6, '.', ','); ?> MYR</div>
                                                    <?php if ($latest_buffer > 0) { ?>
                                                        <div class="text-primary font-size-sm">eff. <?php echo number_format((float) $latest_rate['rate'] + $latest_buffer, 6, '.', ','); ?> MYR</div>
                                                    <?php } ?>
                                                    <div class="text-muted font-size-sm"><?php echo html_escape(costing_currency_display_date($latest_rate['valid_from'])); ?></div>
                                                </div>
                                            <?php } else { ?>
                                                <span class="label label-lg label-light-warning label-inline">No Rate</span>
                                            <?php } ?>
                                        </td>
                                        <td style="text-align:center;">
                                            <?php if ($latest_rate) { ?>
                                                <span class="font-weight-bold text-dark"><?php echo number_format((float) (isset($latest_rate['buffer']) ? $latest_rate['buffer'] : 0), 6, '.', ','); ?></span>
                                            <?php } else { ?>
                                                <span class="text-muted">-</span>
                                            <?php } ?>
                                        </td>
                                        <td style="text-align:center;">
                                            <?php if ($latest_rate) { ?>
                                                <span class="font-weight-bold text-dark">MYR <?php echo number_format((float) $latest_rate['bank_charges_myr'], 2); ?></span>
                                            <?php } else { ?>
                                                <span class="text-muted">-</span>
                                            <?php } ?>
                                        </td>
                                        <td style="text-align:center;">
                                            <?php if ($latest_rate && !empty($latest_rate['updated_by_name'])) { ?>
                                                <span class="font-weight-bold text-dark"><?php echo html_escape($latest_rate['updated_by_name']); ?></span>
                                            <?php } else { ?>
                                                <span class="text-muted">-</span>
                                            <?php } ?>
                                        </td>
                                        <td>
                                            <div class="currency-action-grid">
                                                <button
                                                    type="button"
                                                    class="btn btn-sm btn-light-primary font-weight-bold edit-currency"
                                                    data-currency="<?php echo html_escape(json_encode($currency)); ?>"
                                                >Edit</button>
                                                <form method="post" action="<?php echo base_url('Costing/Delete_Currency'); ?>" class="delete-currency-form">
                                                    <input type="hidden" name="active_tab" value="currencies">
                                                    <input type="hidden" name="currency_id" value="<?php echo $currency_id; ?>">
                                                    <button type="button" class="btn btn-sm btn-light-danger font-weight-bold delete-currency-button" data-currency-code="<?php echo html_escape($currency['code']); ?>">Delete</button>
                                                </form>
                                                <button
                                                    type="button"
                                                    class="btn btn-sm btn-light-success font-weight-bold update-rate"
                                                    data-currency-id="<?php echo $currency_id; ?>"
                                                    data-currency-code="<?php echo html_escape($currency['code']); ?>"
                                                    data-rate="<?php echo $latest_rate ? html_escape((string) $latest_rate['rate']) : ''; ?>"
                                                    data-buffer="<?php echo $latest_rate ? html_escape((string) (isset($latest_rate['buffer']) ? $latest_rate['buffer'] : 0)) : '0'; ?>"
                                                    data-bank-charges-myr="<?php echo $latest_rate ? html_escape((string) $latest_rate['bank_charges_myr']) : '0'; ?>"
                                                >Rate</button>
                                                <button
                                                    type="button"
                                                    class="btn btn-sm btn-light-info font-weight-bold view-rate-history"
                                                    data-currency-id="<?php echo $currency_id; ?>"
                                                    data-currency-code="<?php echo html_escape($currency['code']); ?>"
                                                >History</button>
                                            </div>

                                        </td>
                                    </tr>
                                    <?php $count++; ?>
                                <?php } ?>
                            <?php } ?>
                        </tbody>
                    </table>
                </div>

                <?php if ($currency_total_pages > 1) { ?>
                    <?php
                    $page_window_start = max(1, $currency_current_page - 2);
                    $page_window_end = min($currency_total_pages, $currency_current_page + 2);
                    if ($page_window_end - $page_window_start < 4) {
                        $page_window_start = max(1, $page_window_end - 4);
                        $page_window_end = min($currency_total_pages, $page_window_start + 4);
                    }
                    ?>
                    <div class="d-flex justify-content-between align-items-center flex-wrap mt-5">
                        <div class="text-muted mb-2">
                            Page <?php echo number_format($currency_current_page); ?> of <?php echo number_format($currency_total_pages); ?>
                        </div>
                        <div class="mb-2">
                            <a href="<?php echo html_escape(costing_currency_page_url(1, $currency_query_params)); ?>" class="btn btn-sm btn-light mr-2 <?php echo $currency_current_page <= 1 ? 'disabled' : ''; ?>">First</a>
                            <a href="<?php echo html_escape(costing_currency_page_url(max(1, $currency_current_page - 1), $currency_query_params)); ?>" class="btn btn-sm btn-light mr-2 <?php echo $currency_current_page <= 1 ? 'disabled' : ''; ?>">Previous</a>

                            <?php for ($page = $page_window_start; $page <= $page_window_end; $page++) { ?>
                                <a href="<?php echo html_escape(costing_currency_page_url($page, $currency_query_params)); ?>" class="btn btn-sm mr-2 <?php echo $page === $currency_current_page ? 'btn-primary' : 'btn-light'; ?>"><?php echo $page; ?></a>
                            <?php } ?>

                            <a href="<?php echo html_escape(costing_currency_page_url(min($currency_total_pages, $currency_current_page + 1), $currency_query_params)); ?>" class="btn btn-sm btn-light mr-2 <?php echo $currency_current_page >= $currency_total_pages ? 'disabled' : ''; ?>">Next</a>
                            <a href="<?php echo html_escape(costing_currency_page_url($currency_total_pages, $currency_query_params)); ?>" class="btn btn-sm btn-light <?php echo $currency_current_page >= $currency_total_pages ? 'disabled' : ''; ?>">Last</a>
                        </div>
                    </div>
                <?php } ?>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="currency_modal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" role="document">
        <div class="modal-content">
            <form method="post" action="<?php echo base_url('Costing/Save_Currency'); ?>" id="currency_form_modal">
                <input type="hidden" name="active_tab" value="currencies">
                <input type="hidden" name="currency_id" id="modal_currency_id" value="">
                <div class="modal-header">
                    <h5 class="modal-title" id="currency_modal_title">Add Currency</h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                        <i aria-hidden="true" class="ki ki-close"></i>
                    </button>
                </div>
                <div class="modal-body">
                    <div class="form-group">
                        <label>Code</label>
                        <input type="text" name="code" id="modal_currency_code" maxlength="3" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label>Name</label>
                        <input type="text" name="name" id="modal_currency_name" class="form-control" required>
                    </div>
                    <div class="form-group mb-0">
                        <label>Symbol</label>
                        <input type="text" name="symbol" id="modal_currency_symbol" class="form-control">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light-primary font-weight-bold" data-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary font-weight-bold">Save</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade" id="exchange_rate_modal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" role="document">
        <div class="modal-content">
            <form method="post" action="<?php echo base_url('Costing/Save_Exchange_Rate'); ?>" id="exchange_rate_form_modal">
                <input type="hidden" name="active_tab" value="rates">
                <input type="hidden" name="exchange_rate_id" id="modal_exchange_rate_id" value="">
                <input type="hidden" name="from_currency_id" id="modal_from_currency_id" value="">
                <input type="hidden" name="to_currency_id" id="modal_to_currency_id" value="<?php echo $myr_currency_id; ?>">
                <input type="hidden" name="unit_amount" id="modal_exchange_rate_unit_amount" value="1">
                <div class="modal-header">
                    <h5 class="modal-title" id="exchange_rate_modal_title">Update Currency Rate</h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                        <i aria-hidden="true" class="ki ki-close"></i>
                    </button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-light-info">
                        Save a new rate as <strong>1 foreign currency = X MYR</strong>. Previous rates stay in history.
                    </div>
                    <div class="form-group">
                        <label>Currency</label>
                        <input type="text" id="modal_rate_currency_label" class="form-control" readonly>
                    </div>
                    <div class="form-group">
                        <label>Rate</label>
                        <input type="number" step="0.00000001" min="0.00000001" name="converted_amount" id="modal_exchange_rate_converted_amount" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label>Buffer (MYR)</label>
                        <input type="number" step="0.00000001" min="0" name="buffer" id="modal_exchange_rate_buffer" class="form-control" value="0">
                        <span class="form-text text-muted">Added on top of the rate. Effective rate = rate + buffer.</span>
                    </div>
                    <div class="form-group">
                        <label>Bank Charges (MYR)</label>
                        <input type="number" step="0.01" min="0" name="bank_charges_myr" id="modal_exchange_rate_bank_charges_myr" class="form-control" value="0">
                    </div>
                    <div class="form-group">
                        <label>Valid From</label>
                        <input type="datetime-local" name="valid_from" id="modal_exchange_rate_valid_from" class="form-control">
                    </div>
                    <div class="alert alert-secondary mb-0" id="rate_preview_text">1 Currency = 0.000000 MYR</div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light-primary font-weight-bold" data-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary font-weight-bold">Save Rate</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade" id="rate_history_modal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="rate_history_modal_title">Rate History</h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <i aria-hidden="true" class="ki ki-close"></i>
                </button>
            </div>
            <div class="modal-body" id="rate_history_modal_body"></div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light-primary font-weight-bold" data-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<script>
    (function () {
        var rateHistoryData = <?php echo json_encode($rate_histories_by_currency, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
        var maxChartPoints = 30;
        var currencyModalTitle = document.getElementById('currency_modal_title');
        var modalCurrencyId = document.getElementById('modal_currency_id');
        var modalCurrencyCode = document.getElementById('modal_currency_code');
        var modalCurrencyName = document.getElementById('modal_currency_name');
        var modalCurrencySymbol = document.getElementById('modal_currency_symbol');

        var exchangeRateModalTitle = document.getElementById('exchange_rate_modal_title');
        var modalExchangeRateId = document.getElementById('modal_exchange_rate_id');
        var modalFromCurrencyId = document.getElementById('modal_from_currency_id');
        var modalExchangeRateConvertedAmount = document.getElementById('modal_exchange_rate_converted_amount');
        var modalExchangeRateUnitAmount = document.getElementById('modal_exchange_rate_unit_amount');
        var modalExchangeRateBuffer = document.getElementById('modal_exchange_rate_buffer');
        var modalExchangeRateBankChargesMyr = document.getElementById('modal_exchange_rate_bank_charges_myr');
        var modalExchangeRateValidFrom = document.getElementById('modal_exchange_rate_valid_from');
        var modalRateCurrencyLabel = document.getElementById('modal_rate_currency_label');
        var ratePreviewText = document.getElementById('rate_preview_text');

        function resetCurrencyModal() {
            currencyModalTitle.textContent = 'Add Currency';
            modalCurrencyId.value = '';
            modalCurrencyCode.value = '';
            modalCurrencyName.value = '';
            modalCurrencySymbol.value = '';
        }

        function formatAmount(value, decimals) {
            var parsed = parseFloat(value);
            if (isNaN(parsed)) {
                parsed = 0;
            }

            return parsed.toLocaleString(undefined, {
                minimumFractionDigits: decimals,
                maximumFractionDigits: decimals
            });
        }

        function updateRatePreview() {
            if (!ratePreviewText) {
                return;
            }

            var code = modalRateCurrencyLabel.value || 'Currency';
            var rate = parseFloat(modalExchangeRateConvertedAmount.value) || 0;
            var buffer = parseFloat(modalExchangeRateBuffer ? modalExchangeRateBuffer.value : 0) || 0;
            var preview = '1 ' + code + ' = ' + formatAmount(rate, 6);
            if (buffer > 0) {
                preview += ' + ' + formatAmount(buffer, 6) + ' buffer = ' + formatAmount(rate + buffer, 6);
            }
            preview += ' MYR + MYR ' + formatAmount(modalExchangeRateBankChargesMyr.value, 2) + ' bank charges';
            ratePreviewText.textContent = preview;
        }

        function confirmDeleteCurrency(button) {
            var form = button.closest('form');
            var currencyCode = button.getAttribute('data-currency-code') || 'this currency';
            if (!form) {
                return;
            }

            if (typeof Swal === 'undefined') {
                if (confirm('Delete ' + currencyCode + '?')) {
                    form.submit();
                }
                return;
            }

            var swalWithBootstrapButtons = Swal.mixin({
                customClass: {
                    confirmButton: 'btn btn-danger',
                    cancelButton: 'btn btn-light-primary'
                },
                buttonsStyling: false
            });

            swalWithBootstrapButtons.fire({
                title: 'Delete Currency: ' + currencyCode + '?',
                text: 'This currency will be removed if it is not used by costing records or exchange rates.',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonText: 'Yes, Delete',
                cancelButtonText: 'Cancel',
                reverseButtons: true
            }).then(function (result) {
                if (result.isConfirmed) {
                    form.submit();
                }
            });
        }

        function getCurrentDatetimeLocal() {
            var now = new Date();
            now.setMinutes(now.getMinutes() - now.getTimezoneOffset());
            return now.toISOString().slice(0, 16);
        }

        function escapeHtml(value) {
            return String(value === null || typeof value === 'undefined' ? '' : value)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');
        }

        function formatHistoryDate(value) {
            if (!value) {
                return 'No date';
            }

            var parsed = new Date(String(value).replace(' ', 'T'));
            if (isNaN(parsed.getTime())) {
                return value;
            }

            var months = ['JAN', 'FEB', 'MAR', 'APR', 'MAY', 'JUN', 'JUL', 'AUG', 'SEP', 'OCT', 'NOV', 'DEC'];
            var day = String(parsed.getDate()).padStart(2, '0');

            var hours = String(parsed.getHours()).padStart(2, '0');
            var minutes = String(parsed.getMinutes()).padStart(2, '0');

            return day + ' ' + months[parsed.getMonth()] + ' ' + parsed.getFullYear() + ' ' + hours + ':' + minutes;
        }

        function normalizeHistoryRows(rows) {
            return (rows || []).map(function (row) {
                return {
                    rate: parseFloat(row.rate),
                    buffer: parseFloat(row.buffer) || 0,
                    bank_charges_myr: parseFloat(row.bank_charges_myr),
                    updated_by_name: row.updated_by_name || '',
                    valid_from: row.valid_from || ''
                };
            }).filter(function (row) {
                return !isNaN(row.rate);
            });
        }

        function buildHistorySummary(rows) {
            var latest = rows[0] ? rows[0].rate : 0;
            var latestBankCharges = rows[0] ? rows[0].bank_charges_myr : 0;
            var latestUpdatedBy = rows[0] && rows[0].updated_by_name ? rows[0].updated_by_name : '-';
            var rates = rows.map(function (row) { return row.rate; });
            var highest = rates.length ? Math.max.apply(Math, rates) : 0;
            var lowest = rates.length ? Math.min.apply(Math, rates) : 0;

            return '<div class="rate-history-summary">' +
                '<div class="rate-history-summary-item"><div class="text-muted font-size-sm">Latest</div><div class="font-weight-bold text-dark">' + formatAmount(latest, 6) + '</div></div>' +
                '<div class="rate-history-summary-item"><div class="text-muted font-size-sm">Bank Charges</div><div class="font-weight-bold text-dark">MYR ' + formatAmount(latestBankCharges, 2) + '</div></div>' +
                '<div class="rate-history-summary-item"><div class="text-muted font-size-sm">Updated By</div><div class="font-weight-bold text-dark">' + escapeHtml(latestUpdatedBy) + '</div></div>' +
                '<div class="rate-history-summary-item"><div class="text-muted font-size-sm">Highest</div><div class="font-weight-bold text-dark">' + formatAmount(highest, 6) + '</div></div>' +
            '</div>';
        }

        function buildHistoryTable(rows, currencyCode) {
            var html = '<div class="table-responsive"><table class="table table-bordered table-head-custom mb-0">' +
                '<thead><tr><th style="text-align:center;">No.</th><th style="text-align:center;">Rate</th><th style="text-align:center;">Buffer</th><th style="text-align:center;">Bank Charges</th><th style="text-align:center;">Updated By</th><th style="text-align:center;">Valid From</th></tr></thead><tbody>';

            rows.forEach(function (row, index) {
                html += '<tr>' +
                    '<td style="text-align:center;">' + (index + 1) + '</td>' +
                    '<td style="text-align:center;">1 ' + escapeHtml(currencyCode) + ' = ' + formatAmount(row.rate, 6) + ' MYR</td>' +
                    '<td style="text-align:center;">' + formatAmount(row.buffer, 6) + ' MYR</td>' +
                    '<td style="text-align:center;">MYR ' + formatAmount(row.bank_charges_myr, 2) + '</td>' +
                    '<td style="text-align:center;">' + escapeHtml(row.updated_by_name || '-') + '</td>' +
                    '<td style="text-align:center;">' + escapeHtml(formatHistoryDate(row.valid_from)) + '</td>' +
                '</tr>';
            });

            return html + '</tbody></table></div>';
        }

        function drawRateHistoryChart(canvas, rows) {
            if (!canvas || !rows.length) {
                return;
            }

            var context = canvas.getContext('2d');
            var ratio = window.devicePixelRatio || 1;
            var width = canvas.clientWidth || 720;
            var height = canvas.clientHeight || 260;
            canvas.width = width * ratio;
            canvas.height = height * ratio;
            context.setTransform(ratio, 0, 0, ratio, 0, 0);
            context.clearRect(0, 0, width, height);

            var padding = { top: 24, right: 24, bottom: 44, left: 56 };
            var plotWidth = width - padding.left - padding.right;
            var plotHeight = height - padding.top - padding.bottom;
            var rates = rows.map(function (row) { return row.rate; });
            var minRate = Math.min.apply(Math, rates);
            var maxRate = Math.max.apply(Math, rates);
            var range = maxRate - minRate;

            if (range === 0) {
                range = Math.max(maxRate * 0.02, 0.000001);
                minRate = minRate - (range / 2);
                maxRate = maxRate + (range / 2);
            }

            function xFor(index) {
                if (rows.length === 1) {
                    return padding.left + plotWidth / 2;
                }

                return padding.left + (plotWidth * index / (rows.length - 1));
            }

            function yFor(rate) {
                return padding.top + ((maxRate - rate) / (maxRate - minRate)) * plotHeight;
            }

            context.strokeStyle = '#D7E2F2';
            context.lineWidth = 1;
            context.beginPath();
            for (var gridIndex = 0; gridIndex <= 4; gridIndex++) {
                var y = padding.top + (plotHeight * gridIndex / 4);
                context.moveTo(padding.left, y);
                context.lineTo(width - padding.right, y);
            }
            context.stroke();

            context.fillStyle = '#7E8299';
            context.font = '11px Poppins, Arial, sans-serif';
            context.textAlign = 'right';
            context.textBaseline = 'middle';
            for (var labelIndex = 0; labelIndex <= 4; labelIndex++) {
                var labelRate = maxRate - ((maxRate - minRate) * labelIndex / 4);
                context.fillText(formatAmount(labelRate, 4), padding.left - 8, padding.top + (plotHeight * labelIndex / 4));
            }

            context.strokeStyle = '#6082B6';
            context.lineWidth = 2;
            context.beginPath();
            if (rows.length === 1) {
                context.moveTo(padding.left, yFor(rows[0].rate));
                context.lineTo(width - padding.right, yFor(rows[0].rate));
            } else {
                rows.forEach(function (row, index) {
                    var x = xFor(index);
                    var y = yFor(row.rate);
                    if (index === 0) {
                        context.moveTo(x, y);
                    } else {
                        context.lineTo(x, y);
                    }
                });
            }
            context.stroke();

            context.fillStyle = '#3699FF';
            rows.forEach(function (row, index) {
                context.beginPath();
                context.arc(xFor(index), yFor(row.rate), rows.length === 1 ? 5 : 3.5, 0, Math.PI * 2);
                context.fill();
            });

            context.fillStyle = '#7E8299';
            context.textAlign = 'center';
            context.textBaseline = 'top';
            if (rows.length) {
                context.fillText(formatHistoryDate(rows[0].valid_from).split(',')[0], xFor(0), height - padding.bottom + 18);
                context.fillText(formatHistoryDate(rows[rows.length - 1].valid_from).split(',')[0], xFor(rows.length - 1), height - padding.bottom + 18);
            }
        }

        function showRateHistory(currencyCode, rows) {
            var normalizedRows = normalizeHistoryRows(rows);
            var latestRows = normalizedRows.slice(0, maxChartPoints);
            var chartRows = latestRows.slice().reverse();
            var body = document.getElementById('rate_history_modal_body');
            var cappedNotice = normalizedRows.length > maxChartPoints
                ? '<div class="alert alert-light-info">Showing latest ' + maxChartPoints + ' of ' + normalizedRows.length + ' rate records.</div>'
                : '';

            if (!normalizedRows.length) {
                body.innerHTML = '<div class="alert alert-light-warning mb-0">No MYR rate history found for ' + escapeHtml(currencyCode) + '.</div>';
                $('#rate_history_modal').modal('show');
                return;
            }

            body.innerHTML = buildHistorySummary(normalizedRows) +
                cappedNotice +
                '<div class="rate-history-chart-wrap"><canvas class="rate-history-chart" id="rate_history_chart"></canvas></div>' +
                buildHistoryTable(latestRows, currencyCode);

            $('#rate_history_modal').one('shown.bs.modal', function () {
                drawRateHistoryChart(document.getElementById('rate_history_chart'), chartRows);
            });
            $('#rate_history_modal').modal('show');
        }

        function resetExchangeRateModal() {
            exchangeRateModalTitle.textContent = 'Update Currency Rate';
            modalExchangeRateId.value = '';
            modalFromCurrencyId.value = '';
            modalExchangeRateUnitAmount.value = '1';
            modalExchangeRateConvertedAmount.value = '';
            if (modalExchangeRateBuffer) {
                modalExchangeRateBuffer.value = '0';
            }
            modalExchangeRateBankChargesMyr.value = '0';
            modalExchangeRateValidFrom.value = getCurrentDatetimeLocal();
            modalRateCurrencyLabel.value = '';
            updateRatePreview();
        }

        document.getElementById('add_currency_button').addEventListener('click', function () {
            resetCurrencyModal();
        });

        $(document).on('click', '.edit-currency', function (event) {
            event.preventDefault();

            try {
                var currency = JSON.parse(this.getAttribute('data-currency'));
                currencyModalTitle.textContent = 'Edit Currency';
                modalCurrencyId.value = currency.id || '';
                modalCurrencyCode.value = currency.code || '';
                modalCurrencyName.value = currency.name || '';
                modalCurrencySymbol.value = currency.symbol || '';
                $('#currency_modal').modal('show');
            } catch (error) {
                console.error('Unable to read currency action data.', error);
            }
        });

        $('#currency_modal').on('hidden.bs.modal', function () {
            resetCurrencyModal();
        });

        $(document).on('click', '.delete-currency-button', function (event) {
            event.preventDefault();
            confirmDeleteCurrency(this);
        });

        $(document).on('click', '.update-rate', function (event) {
            event.preventDefault();
            resetExchangeRateModal();

            modalFromCurrencyId.value = this.getAttribute('data-currency-id') || '';
            modalRateCurrencyLabel.value = this.getAttribute('data-currency-code') || '';
            modalExchangeRateConvertedAmount.value = this.getAttribute('data-rate') || '';
            if (modalExchangeRateBuffer) {
                modalExchangeRateBuffer.value = this.getAttribute('data-buffer') || '0';
            }
            modalExchangeRateBankChargesMyr.value = this.getAttribute('data-bank-charges-myr') || '0';
            updateRatePreview();
            $('#exchange_rate_modal').modal('show');
        });

        modalExchangeRateConvertedAmount.addEventListener('input', updateRatePreview);
        modalExchangeRateConvertedAmount.addEventListener('change', updateRatePreview);
        if (modalExchangeRateBuffer) {
            modalExchangeRateBuffer.addEventListener('input', updateRatePreview);
            modalExchangeRateBuffer.addEventListener('change', updateRatePreview);
        }
        modalExchangeRateBankChargesMyr.addEventListener('input', updateRatePreview);
        modalExchangeRateBankChargesMyr.addEventListener('change', updateRatePreview);

        $('#exchange_rate_modal').on('hidden.bs.modal', function () {
            resetExchangeRateModal();
        });

        $(document).on('click', '.view-rate-history', function (event) {
            event.preventDefault();

            var currencyId = this.getAttribute('data-currency-id');
            var currencyCode = this.getAttribute('data-currency-code') || 'Currency';

            document.getElementById('rate_history_modal_title').textContent = currencyCode + ' Rate History';
            showRateHistory(currencyCode, rateHistoryData[currencyId] || []);
        });

        updateRatePreview();
    })();
</script>
