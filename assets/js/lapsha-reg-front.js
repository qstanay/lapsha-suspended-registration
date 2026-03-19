/**
 * Lapsha Suspended Registration – frontend JS
 *
 * Handles: captcha loading, image-select clicks, form integration.
 */
(function ($) {
    'use strict';

    var ajaxUrl = (typeof ajax_reg_object !== 'undefined') ? ajax_reg_object.ajaxurl : '/wp-admin/admin-ajax.php';

    /* ─── Load captcha on page ready ─── */
    function loadCaptcha() {
        var $wrap = $('.lapsha-captcha-wrap');
        if (!$wrap.length) return;

        $.post(ajaxUrl, { action: 'lapsha_captcha' }, function (res) {
            if (res.success && res.data) {
                $('#lapsha-captcha-challenge').html(res.data.html);
                $('#lapsha_captcha_key').val(res.data.key);

                // If image_select: bind clicks
                if (res.data.type === 'image_select') {
                    bindImageSelect();
                }
            }
        });
    }

    /* ─── Refresh button ─── */
    $(document).on('click', '.lapsha-captcha-refresh', function (e) {
        e.preventDefault();
        $('#lapsha_captcha_answer').val('');
        loadCaptcha();
    });

    /* ─── Image select: user clicks an emoji ─── */
    function bindImageSelect() {
        $('.lapsha-captcha-option').off('click.lapsha').on('click.lapsha', function () {
            $('.lapsha-captcha-option').removeClass('selected');
            $(this).addClass('selected');
            $('#lapsha_captcha_answer').val($(this).data('value'));
        });
    }

    /* ─── Patch the existing AJAX registration call ───
     *
     * The theme's ajax-reg-script.js collects form data manually.
     * We need to make sure our custom fields (captcha key, captcha answer, honeypot)
     * are included in the AJAX POST. We intercept $.ajax for the ajaxreg action.
     */
    if ($.ajaxPrefilter) {
        $.ajaxPrefilter(function (options, originalOptions) {
            if (!options.data || typeof options.data !== 'string') return;
            if (options.data.indexOf('action=ajaxreg') === -1) return;

            // Append our fields
            var extras = '';

            var captchaKey = $('#lapsha_captcha_key').val();
            if (captchaKey) {
                extras += '&lapsha_captcha_key=' + encodeURIComponent(captchaKey);
            }

            var captchaAnswer = $('#lapsha_captcha_answer').val();
            if (captchaAnswer) {
                extras += '&lapsha_captcha_answer=' + encodeURIComponent(captchaAnswer);
            }

            var honeypot = $('#lapsha_website_url').val();
            extras += '&lapsha_website_url=' + encodeURIComponent(honeypot || '');

            options.data += extras;
        });
    }

    /* ─── Handle the "pending" response (email verification mode) ─── */
    if ($.ajaxPrefilter) {
        $(document).ajaxComplete(function (event, xhr, settings) {
            if (!settings.data || typeof settings.data !== 'string') return;
            if (settings.data.indexOf('action=ajaxreg') === -1) return;

            try {
                var response = JSON.parse(xhr.responseText);
                if (response.pending === true) {
                    // Redirect to the dedicated "check your email" page
                    window.location.href = (typeof lapshaReg !== 'undefined' && lapshaReg.pendingUrl)
                        ? lapshaReg.pendingUrl
                        : window.location.pathname + '?lapsha_pending=1';
                }
            } catch (e) {
                // not JSON, ignore
            }
        });
    }

    /* ─── Refresh captcha after failed attempt ─── */
    $(document).ajaxComplete(function (event, xhr, settings) {
        if (!settings.data || typeof settings.data !== 'string') return;
        if (settings.data.indexOf('action=ajaxreg') === -1) return;

        try {
            var response = JSON.parse(xhr.responseText);
            if (response.code && response.code !== 200) {
                loadCaptcha();
            }
        } catch (e) {}
    });

    /* ─── Init ─── */
    $(document).ready(function () {
        loadCaptcha();
    });

})(jQuery);
