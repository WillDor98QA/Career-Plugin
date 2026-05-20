/* Qadwilliam Jobs & Apply — dismiss the first-run setup notice via AJAX. */
(function () {
    'use strict';

    function init() {
        var notice = document.querySelector('.cp-setup-notice');
        if (!notice || typeof qwjaSetupNotice === 'undefined') {
            return;
        }
        notice.addEventListener('click', function (e) {
            if (!e.target.classList.contains('notice-dismiss')) {
                return;
            }
            var fd = new FormData();
            fd.append('action', 'qwja_dismiss_setup_notice');
            fd.append('nonce', qwjaSetupNotice.nonce);
            fetch(qwjaSetupNotice.ajaxUrl, {
                method: 'POST',
                body: fd,
                credentials: 'same-origin'
            });
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
