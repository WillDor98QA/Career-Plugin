/* Qadwilliam Jobs & Apply — screening-questions UI on the job edit screen. */
(function () {
    'use strict';

    function checkDuplicates() {
        var inputs = document.querySelectorAll('.cp-screening-q');
        var seen = {};
        var hasDup = false;
        inputs.forEach(function (el) {
            var v = (el.value || '').trim().toLowerCase();
            el.style.borderColor = '';
            if (!v) {
                return;
            }
            if (seen[v]) {
                hasDup = true;
                el.style.borderColor = '#b32d2e';
                seen[v].style.borderColor = '#b32d2e';
            } else {
                seen[v] = el;
            }
        });
        var warn = document.querySelector('.cp-dup-warning');
        if (warn) {
            warn.style.display = hasDup ? 'block' : 'none';
        }
    }

    function init() {
        var addBtn = document.getElementById('cp-add-question');
        if (!addBtn) {
            return;
        }

        addBtn.addEventListener('click', function () {
            var wrap = document.getElementById('cp-questions-wrap');
            if (!wrap) {
                return;
            }
            var row = document.createElement('div');
            row.className = 'cp-question-row';
            row.style.cssText = 'display:flex;gap:8px;margin-bottom:8px;';
            row.innerHTML =
                '<input type="text" name="qwja_screening_questions[]" ' +
                'class="regular-text cp-screening-q" ' +
                'placeholder="e.g. Why do you want this role?">' +
                '<button type="button" class="button cp-remove-question">Remove</button>';
            wrap.appendChild(row);
        });

        document.addEventListener('click', function (e) {
            if (e.target.classList.contains('cp-remove-question')) {
                var row = e.target.closest('.cp-question-row');
                if (row) {
                    row.remove();
                }
                checkDuplicates();
            }
        });

        document.addEventListener('input', function (e) {
            if (e.target.classList.contains('cp-screening-q')) {
                checkDuplicates();
            }
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
