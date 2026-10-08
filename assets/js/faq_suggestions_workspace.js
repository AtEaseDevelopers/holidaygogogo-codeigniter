(function () {
    'use strict';
    var workspace = $('#faq-workspace');
    if (!workspace.length) { return; }

    function confirmApproval(form, title) {
        if (form.dataset.approvePending === '1' || form.dataset.approveSubmitting === '1') { return false; }
        form.dataset.approvePending = '1';
        Swal.fire({
            width: 550, background: 'url(' + workspace.data('confirm-background') + ')', icon: 'question',
            titleText: 'Approve ' + title + '?', text: 'Approved answers will be added to the FAQ Library.',
            showCancelButton: true, confirmButtonText: 'Approve', cancelButtonText: 'Cancel',
            customClass: {confirmButton: 'btn btn-light-success m-2', cancelButton: 'btn btn-danger m-2'}, buttonsStyling: true
        }).then(function (result) {
            delete form.dataset.approvePending;
            if (result.isConfirmed) {
                form.dataset.approveSubmitting = '1';
                Array.from(form.elements).forEach(function (field) { if (field.type === 'submit') { field.disabled = true; } });
                HTMLFormElement.prototype.submit.call(form);
            }
        });
        return false;
    }

    $('.faq-approve-form').on('submit', function () { return confirmApproval(this, this.dataset.approveTitle); });
    function updateSelection() {
        var total = $('.faq-select').length, selected = $('.faq-select:checked').length;
        $('#faq-selected-count').text(selected + ' selected');
        $('#faq-bulk-submit').prop('disabled', selected === 0);
        $('#faq-select-all').prop('checked', total > 0 && selected === total).prop('indeterminate', selected > 0 && selected < total);
    }
    $('#faq-select-all').on('change', function () { $('.faq-select').prop('checked', this.checked); updateSelection(); });
    $('.faq-select').on('change', updateSelection);
    $('#faq-bulk-form').on('submit', function () {
        var action = $('#faq-bulk-action').val(), count = $('.faq-select:checked').length;
        if (!count) { return false; }
        if (action === 'reevaluate' && count > 10) { alert('Select up to 10 suggestions for re-evaluation.'); return false; }
        if (action === 'approve') { return confirmApproval(this, 'the ' + count + ' selected suggestion' + (count === 1 ? '' : 's')); }
        if (action === 'delete') { return Confirm_Delete_Form(this, workspace.data('confirm-background'), 'the ' + count + ' selected suggestion' + (count === 1 ? '' : 's')); }
        $('#faq-bulk-submit').prop('disabled', true);
    });
    $('#faq-suggestions-table [data-toggle="tooltip"]').tooltip();
    updateSelection();
    if (['queued', 'running'].indexOf(workspace.data('run-state')) !== -1) {
        setTimeout(function () { location.reload(); }, 4000);
    }
})();
