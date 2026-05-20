(function($) {
    'use strict';

    $(document).ready(function() {

        // Multiple [qwja_apply] shortcodes may render on the same page, so iterate
        // and scope every jQuery lookup to its own form.
        $('.cp-application-form').each(function() {
            initForm($(this));
        });

        function initForm($form) {
            if (!$form.length) return;

            var $wrap    = $form.closest('.cp-apply-form-wrap');
            var $submit  = $form.find('.cp-submit-btn');
            var $success = $wrap.find('.cp-alert-success');
            var $error   = $wrap.find('.cp-alert-error');
            var isSubmitting = false;

            $form.on('submit', function(e) {
                e.preventDefault();

                if (isSubmitting) {
                    return;
                }

                $success.hide();
                $error.hide();

                var valid = true;
                $form.find('[required]').each(function() {
                    var val = ($(this).val() || '').toString().trim();
                    if (!val) {
                        $(this).css('border-color', '#e74c3c');
                        valid = false;
                    } else {
                        $(this).css('border-color', '');
                    }
                });

                if (!valid) {
                    $error.text('Please fill in all required fields.').show();
                    $('html, body').animate({ scrollTop: $error.offset().top - 40 }, 300);
                    return;
                }

                isSubmitting = true;

                var formData = new FormData($form[0]);
                formData.set('qwja_nonce', qwjaAjax.nonce);

                $submit.prop('disabled', true);
                $submit.find('.cp-btn-text').hide();
                $submit.find('.cp-btn-loading').show();

                $.ajax({
                    url:         qwjaAjax.url,
                    type:        'POST',
                    data:        formData,
                    processData: false,
                    contentType: false,
                    success: function(res) {
                        if (res.success) {
                            // Keep isSubmitting=true so the (now hidden) form can't be re-fired.
                            $form.slideUp(300);
                            $success.text(res.data.message).show();
                            $('html, body').animate({ scrollTop: $success.offset().top - 60 }, 400);
                        } else {
                            isSubmitting = false;
                            $error.text((res.data && res.data.message) || 'Something went wrong. Please try again.').show();
                            $('html, body').animate({ scrollTop: $error.offset().top - 40 }, 300);
                            $submit.prop('disabled', false);
                            $submit.find('.cp-btn-text').show();
                            $submit.find('.cp-btn-loading').hide();
                        }
                    },
                    error: function(xhr) {
                        isSubmitting = false;

                        var message = 'Something went wrong. Please try again.';
                        var parsed  = null;
                        var raw     = xhr && xhr.responseText ? xhr.responseText.trim() : '';

                        if (raw) {
                            try {
                                parsed = JSON.parse(raw);
                            } catch (ignore) {
                                parsed = null;
                            }
                        }

                        if (parsed && parsed.data && parsed.data.message) {
                            message = parsed.data.message;
                        } else if (xhr && xhr.status === 0) {
                            message = 'Network error. Please check your connection and try again.';
                        } else if (xhr && xhr.status >= 500) {
                            message = 'Server error (' + xhr.status + '). Please try again, or contact the site administrator if this keeps happening.';
                            if (typeof qwjaAjax !== 'undefined' && qwjaAjax.debug) {
                                var fatal = raw.match(/Fatal error:[^\n<]{0,400}/i)
                                    || raw.match(/Uncaught [^\n<]{0,400}/i)
                                    || raw.match(/PHP (Parse|Fatal) error[^\n<]{0,400}/i);
                                if (fatal && fatal[0]) {
                                    message += ' ' + fatal[0].trim().slice(0, 280);
                                }
                            }
                        } else if (xhr && xhr.status === 413) {
                            message = 'Your CV is too large for the server to accept. Please upload a smaller file.';
                        }

                        if (window.console && console.error) {
                            console.error('Qadwilliam Jobs & Apply submission failed', {
                                status:       xhr && xhr.status,
                                statusText:   xhr && xhr.statusText,
                                responseText: raw ? raw.slice(0, 8000) : ''
                            });
                        }

                        $error.text(message).show();
                        if ($error.offset()) {
                            $('html, body').animate({ scrollTop: $error.offset().top - 40 }, 300);
                        }
                        $submit.prop('disabled', false);
                        $submit.find('.cp-btn-text').show();
                        $submit.find('.cp-btn-loading').hide();
                    }
                });
            });

            $form.find('input, textarea').on('focus', function() {
                $(this).css('border-color', '');
            });
        }
    });

})(jQuery);
