(function ($) {

    var admin_sidebar_tab_settings = function () {

        var open_tab = function (tab_selector, control_view) {
            if ($(tab_selector).length === 0) return;

            $('.fwp-settings-wrap .nav-tab-wrapper a').removeClass('nav-tab-active');
            $(tab_selector).addClass('nav-tab-active').trigger('blur');
            var clicked_group = $(tab_selector).attr('href');
            if (typeof (localStorage) !== 'undefined') {
                localStorage.setItem(option_name + "_active-tab", $(tab_selector).attr('href'));
            }
            $('.fwp-group-wrapper').hide();
            $(clicked_group).fadeIn();

            if (typeof control_view !== 'undefined') {
                $('html, body').animate({
                    // we are removing 20 to accomodate admin bar which cut into view.
                    scrollTop: $("#" + control_view).offset().top - 20
                }, 2000);
            }

            // reset/remove hash from url
            window.location.hash = '';

            $.each(window.cmSettingsInstances, function (index, value) {
                value.refresh();
            });
        };

        var open_active_or_first_tab = function () {
            var active_tab = '';
            if (typeof (localStorage) !== 'undefined') {
                active_tab = localStorage.getItem(option_name + "_active-tab");
            }

            if (active_tab !== '' && $(active_tab).length) {
                active_tab += '-tab';
            } else {
                active_tab = $('.fwp-settings-wrap .nav-tab-wrapper a').first();
            }

            open_tab(active_tab);
        };

        $('.fwp-group-wrapper').hide();
        var option_name = $('div.fwp-settings-wrap').data('option-name');

        $('.fwp-settings-wrap .nav-tab-wrapper a').on('click', function (e) {
            e.preventDefault();
            open_tab(this);
        });

        var hash_event_triggered = false;

        $(window).on('hashchange', function () {
            if (hash_event_triggered === true) return;

            // in #registration_page?login_page, registration_page is the tab id and
            // login_page the control/settings tr id.
            var hash = this.location.hash, tab_id_len, tab_id, cache;
            if (hash.length === 0) open_active_or_first_tab();

            if ((tab_id_len = hash.indexOf('?')) !== -1) {
                tab_id = hash.slice(0, tab_id_len);
                control_tr_id = hash.slice(tab_id_len + 1);

                if ((cache = $('a' + tab_id + '-tab')).length !== 0) {
                    open_tab(cache, control_tr_id);
                }
            } else {
                open_tab(hash + '-tab')
            }

            hash_event_triggered = true;

        });

        $(window).trigger('hashchange');
    };

    $(function () {

        admin_sidebar_tab_settings();

        // confirm before deleting
        $('.fwp-confirm-delete, .fusewp-confirm-delete').on('click', function (e) {
            e.preventDefault();
            if (confirm(fusewp_obj.confirm_delete)) {
                window.location.href = $(this).attr('href');
            }
        });

        // confirm before bulk syncing
        $('.fusewp-confirm-bulk-sync').on('click', function (e) {
            e.preventDefault();
            if (confirm(fusewp_obj.bulk_sync_confirm_message)) {
                window.location.href = $(this).attr('href');
            }
        });

        $('.wp-csa-select2, .fusewp-field-select2').select2();

        if (typeof postboxes !== 'undefined' && /fusewp/.test(pagenow)) {
            postboxes.add_postbox_toggles(pagenow);
        }
    });
})(jQuery);