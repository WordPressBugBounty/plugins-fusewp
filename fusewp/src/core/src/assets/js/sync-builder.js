(function ($) {

    let SyncBuilder = function () {

        let _this = this, destination_item_tmpl, source_information_tmpl;

        this.init = function () {
            $(document).on('click', '.fusewp-add-action', this.addDestination);
            $(document).on('click', '.fusewp-delete-action', this.deleteDestination);
            $(document).on('click', '.fusewp-edit-action, .fusewp-action__header', this.toggleDestinationItem);

            $(document).on('change', '.fusewp-source-select', this.onSourceChange);
            $(document).on('change', '.fusewp-source-item-select', this.onSourceChange);

            $(document).on('change', '.fusewp-action-select', this.onDestinationChange);
            $(document).on('change', '.fusewp-integration-select', this.onIntegrationChange);
            $(document).on('change', '.fusewp-sync-list-select', this.onIntegrationListChange);
            $(document).on('click', '.fusewp_map_field_btn', this.mapAnotherFieldOnClick);
            $(document).on('click', '.fusewp-map-field-table-delete-icon', this.deleteFieldMapRowOnClick);

            $(document).on('click', '.fusewp-switch.fusewp-toggle-sync-status', this.syncStatusSwitch);

            $(this.onReady); // trigger on ready event
        };

        this.initScript = function () {
            $('.fusewp-field-select2').select2();
        };

        this.onReady = function () {
            destination_item_tmpl = wp.template('fusewp-destination-item');
            source_information_tmpl = wp.template('fusewp-source-information');
            window.fusewp_destination_items = fusewp_obj.fusewp_destination_items;
            window.fusewp_destination_item_label = fusewp_obj.fusewp_destination_item_label;
        };

        this.add_spinner = function () {
            _this.remove_spinner();
            $('#poststuff').prepend('<div class="fusewp_preloader"><span class="spinner is-active"></span></div>')
        };

        this.remove_spinner = function () {
            $('.fusewp_preloader').remove()
        };

        this.show_source_empty_state = function () {

            $('#fusewp-sync-destination-content .fusewp-no-actions-message p').html(fusewp_obj.sync_page_no_source_message);
            $('#fusewp-sync-destination-content .fusewp-no-actions-message').show();
        };

        this.onSourceChange = function (e) {

            let source = $('.fusewp-source-select').val(),
                source_with_item_id = $('.fusewp-source-item-select').val();

            $('.fusewp-sync-source-info').remove();

            $('.fusewp-actions-container .fusewp-action').remove();

            if (!source_with_item_id) source_with_item_id = '';

            if (source !== "") {

                _this.add_spinner();

                let data = {
                    'action': 'fusewp_sync_get_source_data',
                    'source': source,
                    'source_with_item_id': source_with_item_id,
                    'csrf': fusewp_obj.nonce
                };

                $.post(ajaxurl, data, function (response) {
                    _this.remove_spinner();
                    if ('success' in response && response.success === true) {
                        window.fusewp_destination_items = response.data.destination_items;
                        window.fusewp_destination_item_label = response.data.destination_item_label;

                        const source_table = $('.fusewp-source-table tbody');

                        const items_wrapper = source_table.find('.fusewp-source-item-select-wrapper');
                        if (items_wrapper.length) {
                            items_wrapper.remove();
                        }

                        source_table.append(response.data.source_items_field);

                        $('#fusewp-sync-destination-content .fusewp-actions-container').prepend(source_information_tmpl({
                            'message': response.data.rule_information
                        }));

                        $('#fusewp-sync-destination-content .fusewp-no-actions-message p').html(fusewp_obj.sync_page_no_destination_message);
                        $('.fusewp-metabox-footer').addClass('fusewp-show');
                    } else {
                        alert(response.data);
                    }
                });
            } else {
                _this.show_source_empty_state();
                $('.fusewp-metabox-footer').removeClass('fusewp-show');
            }
        };

        this.onDestinationChange = function () {
            $(this).parents('.fusewp-action').find('.action-title').text(
                $('option:selected', $(this)).text()
            )
        };

        this.onIntegrationChange = function (e) {

            let _this_ = $(this), integration = _this_.val(), $parent = _this_.parents('.fusewp-action'),
                sourceData = _this.getSourceData();

            _this_.closest('.fusewp-action__fields').find('.fusewp-sub-fields').remove();

            if (integration !== "") {

                _this.add_spinner();

                let data = {
                    'action': 'fusewp_sync_get_integration_fields',
                    'integration': integration,
                    'source': sourceData[0],
                    'source_item': sourceData[1],
                    'index': $parent.data('index'),
                    'csrf': fusewp_obj.nonce
                };

                $.post(ajaxurl, data, function (response) {
                    _this.remove_spinner();
                    if ('success' in response && response.success === true) {
                        _this_.parents('.fusewp-table tbody').append(response.data.integration_fields);
                        _this.initScript();
                    } else {
                        alert(response.data);
                    }
                });
            }
        };

        this.getSourceData = function () {
            let source = $('.fusewp-source-item-select').val(),
                source_item = '';

            if (source && source.indexOf('|') !== -1) {
                let result = source.split('|');
                source = result[0];
                source_item = result[1];
            }

            return [source, source_item];
        };

        this.onIntegrationListChange = function (e) {

            let _this_ = $(this), list_id = _this_.val(), $parent = _this_.parents('.fusewp-action'),
                integration = $('.fusewp-integration-select', $parent).val(),
                sourceData = _this.getSourceData();

            _this_.closest('.fusewp-action__fields').find('.fusewp-list-sub-fields').remove();

            if (list_id !== "") {

                _this.add_spinner();

                let data = {
                    'action': 'fusewp_sync_get_list_fields',
                    'source': sourceData[0],
                    'source_item': sourceData[1],
                    'integration': integration,
                    'list_id': list_id,
                    'index': $parent.data('index'),
                    'csrf': fusewp_obj.nonce
                };

                $.post(ajaxurl, data, function (response) {
                    _this.remove_spinner();
                    if ('success' in response && response.success === true) {
                        _this_.parents('.fusewp-table tbody').append(response.data.integration_list_fields);
                        _this.initScript();
                    } else {
                        alert(response.data);
                    }
                });
            }
        };

        this.addDestination = function (e) {

            e.preventDefault();

            let destination,
                index = 1,
                $actions = $('#fusewp-sync-destination-content .fusewp-actions-container .fusewp-action');

            if ($actions.length > 0) {
                index = $actions.eq(-1).data('index') + 1;
            }

            $('#fusewp-sync-destination-content .fusewp-no-actions-message').hide();

            destination = destination_item_tmpl({
                'index': index,
                'source_item_name': window.fusewp_destination_item_label,
                'destination_items': window.fusewp_destination_items
            });

            $('#fusewp-sync-destination-content .fusewp-actions-container').append(destination);
        };

        this.deleteDestination = function (e) {
            e.preventDefault();
            if (confirm(fusewp_obj.confirm_delete)) {
                $(this).closest('.fusewp-action').remove();

                if ($('.fusewp-actions-container .fusewp-action').length === 0) {
                    $('#fusewp-sync-destination-content .fusewp-no-actions-message').show();
                }
            }
        };

        this.toggleDestinationItem = function (e) {
            e.preventDefault();
            e.stopImmediatePropagation();

            $(this).parents('.fusewp-action').toggleClass('fusewp-open');
        };

        this.mapAnotherFieldOnClick = function (e) {
            e.preventDefault();
            let _this_ = $(this), $parent = _this_.closest('table'), $cloned = $('tbody tr', $parent).eq(0).clone();

            $cloned.find('.fusewp-map-field-table-data select').val('');
            $cloned.find('.fusewp-map-field-table-field-value select').val('');
            $('tbody', $parent).append($cloned);
        };

        this.deleteFieldMapRowOnClick = function (e) {
            e.preventDefault();
            $(this).closest('tr').remove()
        };

        this.syncStatusSwitch = function () {

            const $switch = $(this);

            if ($switch.is('.fusewp-loading')) return;

            const state = $switch.attr('data-fusewp-switch');
            const newState = state === 'active' ? 'disabled' : 'active';

            $switch.addClass('fusewp-loading');
            $switch.attr('data-fusewp-switch', newState);

            $.post(
                ajaxurl,
                {
                    action: 'fusewp_toggle_sync_status',
                    sync_rule_id: $switch.attr('data-sync-id'),
                    new_state: newState,
                },
                function () {
                    $switch.removeClass('fusewp-loading');
                }
            );
        };
    };

    (new SyncBuilder()).init();

})(jQuery);