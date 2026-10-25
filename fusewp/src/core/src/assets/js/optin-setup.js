/**
 * Opt-in Checkbox settings: the "Where the Checkbox Shows" rows follow the
 * registration plugins picked in "Require Opt-in Consent For", and each row's
 * Setup button opens its popup. The popups' inputs belong to the settings form,
 * so "Save Changes" saves them; this file only shows, hides and summarises.
 */
(function ($) {
    'use strict';

    $(function () {
        var $wrap = $('.fusewp-optin-setup');

        if ( ! $wrap.length) return;

        var i18n    = $wrap.data('i18n') || {};
        var $select = $('#optin_integrations');

        var popup = function (id) {
            return $wrap.find('.fusewp-optin-setup__popup').filter(function () {
                return $(this).attr('data-checkpoint') === id;
            });
        };

        var summarise = function ($row) {
            var $p     = popup($row.attr('data-checkpoint'));
            var $forms = $p.find('select[name$="[forms][]"]');
            var text;

            if ( ! $forms.length) {
                text = i18n.one_form;
            } else if ('selected' !== $p.find('input[name$="[mode]"]:checked').val()) {
                text = i18n.all;
            } else {
                var picked = ($forms.val() || []).length;
                text = picked ? String(i18n.some).replace('%1$s', picked).replace('%2$s', $forms.find('option').length) : i18n.none;
            }

            text += ' · ' + ($.trim($p.find('input[name$="[label]"]').val()) ? i18n.label : i18n['default']);

            $row.find('.js-summary').text(text);
        };

        // rows for the registration plugins currently selected.
        var sync = function () {
            var selected = $select.val() || [];
            var shown    = 0;

            $wrap.find('.fusewp-optin-setup__row').each(function () {
                var on = -1 !== $.inArray($(this).attr('data-checkpoint'), selected);
                $(this).toggle(on);
                if (on) shown++;
                summarise($(this));
            });

            $wrap.find('table').toggle(shown > 0);
            $wrap.find('.fusewp-optin-setup__empty').toggle(0 === shown);
        };

        // the form list only matters for "Only these forms".
        var modeChanged = function ($p) {
            var selected = 'selected' === $p.find('input[name$="[mode]"]:checked').val();
            $p.find('.fusewp-optin-setup__forms').toggle(selected);
        };

        // select2 (loaded on FuseWP's pages), drawn in the popup so its list opens above it.
        var enhance = function ($p) {
            var $s = $p.find('.fusewp-optin-setup__select');
            if ( ! $.fn.select2 || ! $s.length || $s.hasClass('select2-hidden-accessible')) return;
            $s.select2({
                width        : '100%',
                placeholder  : $s.attr('data-placeholder') || '',
                closeOnSelect: false,
                dropdownParent: $p.find('.fusewp-optin-setup__dialog')
            });
        };

        var close = function ($p) {
            $p.prop('hidden', true);
            $('body').removeClass('fusewp-optin-setup-open');
            sync();
            $wrap.find('.js-optin-setup[data-checkpoint="' + $p.attr('data-checkpoint') + '"]').trigger('focus');
        };

        $wrap.on('click', '.js-optin-setup', function () {
            var $p = popup($(this).attr('data-checkpoint'));
            modeChanged($p);
            $p.prop('hidden', false);
            enhance($p);
            $('body').addClass('fusewp-optin-setup-open');
            $p.find('input:visible').first().trigger('focus');
        });

        $wrap.on('change', 'input[name$="[mode]"]', function () {
            modeChanged($(this).closest('.fusewp-optin-setup__popup'));
        });

        $wrap.on('click', '.js-optin-close', function () {
            close($(this).closest('.fusewp-optin-setup__popup'));
        });

        // a click on the dimmed backdrop, or Escape, closes it too.
        $wrap.on('mousedown', '.fusewp-optin-setup__popup', function (e) {
            if (e.target === this) close($(this));
        });

        $(document).on('keydown', function (e) {
            if ('Escape' !== e.key || $('.select2-container--open').length) return;
            var $open = $wrap.find('.fusewp-optin-setup__popup:not([hidden])');
            if ($open.length) close($open);
        });

        // Enter in the label field would submit the whole settings form.
        $wrap.on('keydown', '.fusewp-optin-setup__popup input[type="text"]', function (e) {
            if ('Enter' === e.key) {
                e.preventDefault();
                close($(this).closest('.fusewp-optin-setup__popup'));
            }
        });

        $select.on('change', sync);

        sync();
    });
})(jQuery);
