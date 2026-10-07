/* Preview approved facts automatically, without saving or making an AI request. */
(function ($) {
    function showError(target, message) {
        target.empty().append($('<p class="small text-danger mb-2">').text(message));
        $('<button type="button" class="btn btn-light-info btn-sm faq-knowledge-retry">')
            .text('Retry loading sources').appendTo(target);
    }

    function render(control, target, reply) {
        var sources = reply.selection.sources;
        var panel = $('<details class="border rounded p-3 mt-3 faq-knowledge-selection" open>').appendTo(target.empty());
        $('<summary class="font-weight-bold" style="font-size:13px;">')
            .text('Knowledge Sources for AI Evaluation · ' + sources.length + ' source(s)').appendTo(panel);
        $('<p class="small text-muted mt-2 mb-2">')
            .text('During re-evaluation, only valid approved sources for attached destinations are sent to AI. AI checks which facts apply. Save destination changes before re-evaluating.').appendTo(panel);
        if (!sources.length) {
            var hasDestinations = (reply.selection.destination_ids || []).length > 0;
            $('<p class="small text-muted mb-0 mt-2">').text(hasDestinations
                ? 'No valid approved Knowledge Sources are available for the attached destinations and travel dates.'
                : 'Attach and save a destination to include its Knowledge Sources during re-evaluation.').appendTo(panel);
            return;
        }
        var table = $('<table class="table table-sm table-bordered mb-0" style="font-size:12px;">');
        table.append('<thead><tr><th>Source</th><th>Why selected</th></tr></thead>');
        var body = $('<tbody>').appendTo(table);
        sources.forEach(function (source) {
            var row = $('<tr>').appendTo(body), cell = $('<td>').appendTo(row);
            var label = source.reference + ' · ' + source.title;
            if (reply.can_manage_sources) {
                $('<a target="_blank" rel="noopener">').attr('href', control.data('source-url') + source.source_id).text(label).appendTo(cell);
            } else { $('<span>').text(label).appendTo(cell); }
            var facts = $('<details class="mt-1">').appendTo(cell);
            $('<summary class="text-muted">').text('Source facts').appendTo(facts);
            $('<div class="mt-1" style="white-space:pre-wrap;">').text(source.excerpt).appendTo(facts);
            $('<td>').text(source.selection_reason).appendTo(row);
        });
        $('<div class="table-responsive mt-2">').append(table).appendTo(panel);
    }

    function refresh(control, delay) {
        var state = control.data('knowledge-preview-state');
        if (!state) {
            state = {version: 0, timer: null, request: null};
            control.data('knowledge-preview-state', state);
        }
        clearTimeout(state.timer);
        var version = ++state.version;
        if (state.request) { state.request.abort(); state.request = null; }
        var target = $(control.data('target'));
        target.attr('aria-busy', 'true').empty()
            .append($('<p class="small text-muted mt-3 mb-0" role="status">').text('Loading Knowledge Sources…'));
        state.timer = setTimeout(function () {
            state.timer = null;
            var form = control.closest('form'), id = String(control.data('suggestion-id'));
            var data = {workspace_token: form.find('[name="workspace_token"]').val(), suggestion_id: id};
            if (control.data('mode') === 'update') {
                data.title = form.find('[name="Title"]').val();
                data.questions = form.find('[name="sub_questions[]"]').map(function () { return $(this).val(); }).get();
                if (!data.questions.length) { data.questions = ['']; }
                data.destination_ids_present = '1';
                data.destination_ids = form.find('[name="Destinations[]"]').val() || [];
            } else {
                data.additional = form.find('[name="details[' + id + '][additional]"]').val();
                data.more_messages = form.find('[name="details[' + id + '][more_messages]"]').is(':checked') ? '1' : '0';
            }
            state.request = $.ajax({url: control.data('url'), method: 'POST', data: data, dataType: 'json'})
                .done(function (reply) {
                    if (state.version !== version) { return; }
                    if (!reply.ok) { showError(target, reply.error || 'Could not load matching sources.'); return; }
                    render(control, target, reply);
                })
                .fail(function (request, status) {
                    if (state.version !== version || status === 'abort') { return; }
                    showError(target, 'Could not load matching sources. Please try again.');
                })
                .always(function () {
                    if (state.version !== version) { return; }
                    state.request = null;
                    target.attr('aria-busy', 'false');
                });
        }, delay);
    }

    function refreshForm(field, delay) {
        $(field).closest('form').find('.faq-knowledge-preview[data-mode="update"]').each(function () {
            refresh($(this), delay);
        });
    }

    $(document).on('change', '#faq_form [name="Destinations[]"]', function () { refreshForm(this, 0); });
    $(document).on('input', '#faq_form [name="Title"], #faq_form [name="sub_questions[]"]', function () { refreshForm(this, 400); });
    $(document).on('click', '#faq_form .faq-item-remove, #faq_form #faq-add-item', function () { refreshForm(this, 400); });
    $(document).on('input', '#faq-reevaluation-form textarea[name$="[additional]"]', function () {
        $(this).closest('.card').find('.faq-knowledge-preview').each(function () { refresh($(this), 400); });
    });
    $(document).on('change', '#faq-reevaluation-form input[name$="[more_messages]"]', function () {
        $(this).closest('.card').find('.faq-knowledge-preview').each(function () { refresh($(this), 0); });
    });
    $(document).on('click', '.faq-knowledge-retry', function () { refresh($(this).closest('.faq-knowledge-preview'), 0); });
})(jQuery);
