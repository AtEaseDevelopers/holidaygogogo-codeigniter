/* Preview matching approved facts without saving or making an AI request. */
$(document).on('click', '.faq-knowledge-preview', function () {
    var button = $(this), form = button.closest('form'), id = String(button.data('suggestion-id'));
    var target = $(button.data('target')), data = {
        workspace_token: form.find('[name="workspace_token"]').val(), suggestion_id: id
    };
    if (button.data('mode') === 'update') {
        data.title = form.find('[name="Title"]').val();
        data.questions = form.find('[name="sub_questions[]"]').map(function () { return $(this).val(); }).get();
    } else {
        data.additional = form.find('[name="details[' + id + '][additional]"]').val();
        data.more_messages = form.find('[name="details[' + id + '][more_messages]"]').is(':checked') ? '1' : '0';
    }
    button.prop('disabled', true);
    $.ajax({url: button.data('url'), method: 'POST', data: data, dataType: 'json'})
        .done(function (reply) {
            target.empty();
            if (!reply.ok) { $('<p class="small text-danger mb-0">').text(reply.error).appendTo(target); return; }
            var sources = reply.selection.sources;
            $('<p class="small text-muted mb-2">').text('Matching approved Knowledge Sources: ' + sources.length).appendTo(target);
            if (!sources.length) {
                $('<p class="small text-muted mb-0">').text('No approved Knowledge Sources matched. Add the missing scope or information, or verify and approve an applicable source.').appendTo(target);
                return;
            }
            var table = $('<table class="table table-sm table-bordered mb-0" style="font-size:12px;">');
            table.append('<thead><tr><th>Source</th><th>Why selected</th></tr></thead>');
            var body = $('<tbody>').appendTo(table);
            sources.forEach(function (source) {
                var row = $('<tr>').appendTo(body), cell = $('<td>').appendTo(row);
                var label = source.reference + ' · ' + source.title;
                if (reply.can_manage_sources) {
                    $('<a target="_blank" rel="noopener">').attr('href', button.data('source-url') + source.source_id).text(label).appendTo(cell);
                } else { $('<span>').text(label).appendTo(cell); }
                var details = $('<details class="mt-1">').appendTo(cell);
                $('<summary class="text-muted">').text('Source facts').appendTo(details);
                $('<div class="mt-1" style="white-space:pre-wrap;">').text(source.excerpt).appendTo(details);
                $('<td>').text(source.selection_reason).appendTo(row);
            });
            $('<div class="table-responsive">').append(table).appendTo(target);
        })
        .fail(function () { target.empty().append($('<p class="small text-danger mb-0">').text('Could not refresh matching sources. Please try again.')); })
        .always(function () { button.prop('disabled', false); });
});
