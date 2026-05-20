(function($) {
    'use strict';

    var STATUS_LABELS = {
        pending:   'Pending',
        reviewing: 'Reviewing',
        interview: 'Interview',
        hired:     'Hired',
        rejected:  'Rejected'
    };

    var STATUS_COLORS = {
        pending:   '#f0ad4e',
        reviewing: '#5bc0de',
        interview: '#9b59b6',
        hired:     '#5cb85c',
        rejected:  '#d9534f'
    };

    // Remember the prior value on each select so we can (a) detect no-op changes
    // silently and (b) revert if the user cancels the confirm dialog.
    $(document).on('focus', '.cp-status-select', function() {
        $(this).data('prev', $(this).val());
    });
    // Capture initial values on page load too.
    $(function() {
        $('.cp-status-select').each(function() {
            $(this).data('prev', $(this).val());
        });
    });

    $(document).on('change', '.cp-status-select', function() {
        var $sel    = $(this);
        var id      = $sel.data('id');
        var status  = $sel.val();
        var $row    = $sel.closest('tr');
        var prev    = $sel.data('prev');

        // No-op: silently do nothing.
        if (prev === status) return;

        var label = STATUS_LABELS[status] || status;
        var confirmed = window.confirm(
            'Change status to "' + label + '"? This will email the applicant.'
        );

        if (!confirmed) {
            $sel.val(prev);
            return;
        }

        $.post(qwjaAdmin.ajaxUrl, {
            action: 'qwja_update_status',
            id:     id,
            status: status,
            nonce:  qwjaAdmin.nonce
        }, function(res) {
            if ( res.success ) {
                $sel.data('prev', status);

                if ($row.length) {
                    $row.css('background', '#d4edda');
                    setTimeout(function() { $row.css('background', ''); }, 1000);
                }

                var color = STATUS_COLORS[status] || '#888';

                $row.find('.cp-status-badge')
                    .text(label)
                    .css({
                        'background': color + '20',
                        'color':      color,
                        'border':     '1px solid ' + color + '40'
                    });

                var $current = $('#cp-status-select').siblings('.cp-status-current');
                if ($current.length) {
                    $current.text(label).css('color', color);
                }
            } else {
                $sel.val(prev);
                window.alert('Could not update status: ' + (res.data || 'Unknown error'));
            }
        }).fail(function() {
            $sel.val(prev);
            window.alert('Could not reach the server. Please try again.');
        });
    });

    // SMTP test email (Settings page)
    $(document).on('click', '#cp-send-test-email', function() {
        var $btn    = $(this);
        var $result = $('#cp-test-email-result');
        var email   = $('#qwja_test_email').val();

        if (!email) {
            $result.css('color', '#b32d2e').text('Enter an email address.');
            return;
        }

        $btn.prop('disabled', true);
        $result.css('color', '#666').text('Sending…');

        $.post(qwjaAdmin.ajaxUrl, {
            action: 'qwja_send_test_email',
            email:  email,
            nonce:  qwjaAdmin.nonce
        }, function(res) {
            if (res.success) {
                $result.css('color', '#227a22').text(res.data.message || 'Sent!');
            } else {
                $result.css('color', '#b32d2e').text((res.data && res.data.message) || 'Failed to send.');
            }
        }).fail(function() {
            $result.css('color', '#b32d2e').text('Could not reach the server.');
        }).always(function() {
            $btn.prop('disabled', false);
        });
    });

})(jQuery);
