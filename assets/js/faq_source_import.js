(function ($) {
    'use strict';
    var panel = $('#faq-source-import');
    if (!panel.length || panel.attr('data-state') === 'error') { return; }
    function poll() {
        $.ajax({url: panel.attr('data-status-url'), dataType: 'json', cache: false, timeout: 15000})
            .done(function (result) {
                if (result.state === 'done') {
                    window.location.replace(panel.attr('data-complete-url'));
                } else if (result.state === 'error') {
                    $('#faq-source-import-progress').addClass('d-none');
                    $('#faq-source-import-error').text(result.error || 'Could not extract this source. Please upload again.').removeClass('d-none');
                    $('#faq-source-import-retry').removeClass('d-none');
                } else {
                    $('#faq-source-import-message').text(result.state === 'queued' ? 'Preparing your source…' : 'Extracting your source…');
                    window.setTimeout(poll, 2500);
                }
            }).fail(function (xhr) {
                if (xhr.status === 401 || xhr.status === 403 || (xhr.status === 200 && /^\s*</.test(xhr.responseText || ''))) {
                    $('#faq-source-import-message').text('Please refresh this page to sign in and check your upload.');
                    return;
                }
                if (xhr.status === 404) {
                    $('#faq-source-import-progress').addClass('d-none');
                    $('#faq-source-import-error').text('This upload could not be found. Please upload again.').removeClass('d-none');
                    $('#faq-source-import-retry').removeClass('d-none');
                    return;
                }
                $('#faq-source-import-message').text('Reconnecting to check your upload…');
                window.setTimeout(poll, 5000);
            });
    }
    window.setTimeout(poll, 1000);
})(jQuery);
