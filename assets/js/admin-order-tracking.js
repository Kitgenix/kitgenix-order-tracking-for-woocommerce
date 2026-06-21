(function($){
    'use strict';

    var orderItemsLoaded = false;
    var orderItems = [];

    function escHtml(text) {
        return $('<div/>').text(text).html();
    }

    function applyStatusToOrderDropdown(status) {
        var $statusSelect = $('#order_status');

        if ($statusSelect.length) {
            if (status === 'partial') {
                $statusSelect.val('wc-partially-shipped').trigger('change');
            } else if (status === 'completed') {
                $statusSelect.val('wc-completed').trigger('change');
            }
        }
    }

    function resetModalFields($modal) {
        $modal.find('.kitgenix-order-tracking-for-woocommerce-field-number').val('');
        $modal.find('.kitgenix-order-tracking-for-woocommerce-field-carrier').val('');
        $modal.find('.kitgenix-order-tracking-for-woocommerce-field-date').val($modal.find('.kitgenix-order-tracking-for-woocommerce-field-date').attr('value'));
        $modal.find('input[name="kitgenix_order_tracking_for_woocommerce_status"][value="no_change"]').prop('checked', true);

        // Reset qty inputs to max.
        $modal.find('.kitgenix-order-tracking-for-woocommerce-item-row').each(function(){
            var $row = $(this);
            var max  = parseFloat($row.data('maxQty')) || 0;
            $row.find('.kitgenix-order-tracking-for-woocommerce-item-qty-input').val(max || 0);
        });
    }

    function renderItemsIntoModal() {
        var settings   = window.KitgenixOrderTracking || {};
        var i18n       = settings.i18n || {};
        var $modal     = $('#kitgenix-order-tracking-for-woocommerce-modal');
        var $container = $modal.find('.kitgenix-order-tracking-for-woocommerce-items');

        if (!$container.length) {
            return;
        }

        $container.empty();

        if (!orderItems.length) {
            return;
        }

        $container.append(
            $('<p/>', {
                'class': 'description',
                text: i18n.items_section_title || 'Select which items (and quantities) are included in this shipment.'
            })
        );

        orderItems.forEach(function(item){
            var row = $('<div/>', {
                'class': 'kitgenix-order-tracking-for-woocommerce-item-row',
                'data-item-id': item.item_id,
                'data-max-qty': item.qty
            });

            var media = $('<div/>', { 'class': 'kitgenix-order-tracking-for-woocommerce-item-row__media' });
            if (item.image_html) {
                media.html(item.image_html);
            }

            var info = $('<div/>', { 'class': 'kitgenix-order-tracking-for-woocommerce-item-row__info' });
            $('<div/>', {
                'class': 'kitgenix-order-tracking-for-woocommerce-item-row__title',
                text: item.name
            }).appendTo(info);
            if (item.sku) {
                $('<div/>', {
                    'class': 'kitgenix-order-tracking-for-woocommerce-item-row__meta',
                    text: 'SKU: ' + item.sku
                }).appendTo(info);
            }

            var qtyWrap = $('<div/>', { 'class': 'kitgenix-order-tracking-for-woocommerce-item-row__qty' });
            var input   = $('<input/>', {
                type: 'number',
                min: 0,
                'class': 'small-text kitgenix-order-tracking-for-woocommerce-item-qty-input',
                value: item.qty,
                max: item.qty
            });
            var maxLabel = $('<span/>', {
                'class': 'kitgenix-order-tracking-for-woocommerce-item-row__qty-max',
                text: 'of ' + item.qty
            });

            qtyWrap.append(input).append(maxLabel);

            row.append(media).append(info).append(qtyWrap);
            $container.append(row);
        });
    }

    function fetchOrderItemsIfNeeded(callback) {
        var settings = window.KitgenixOrderTracking || {};
        if (orderItemsLoaded || !settings.order_id) {
            if (typeof callback === 'function') {
                callback();
            }
            return;
        }

        $.ajax({
            url: settings.ajax_url,
            method: 'POST',
            dataType: 'json',
            data: {
                action: 'kitgenix_order_tracking_for_woocommerce_get_order_items',
                order_id: settings.order_id,
                nonce: settings.nonce
            }
        }).done(function(response){
            if (response && response.success && response.data && response.data.items) {
                orderItems = response.data.items;
            }
        }).always(function(){
            orderItemsLoaded = true;
            renderItemsIntoModal();
            if (typeof callback === 'function') {
                callback();
            }
        });
    }

    function openModal() {
        var $modal = $('#kitgenix-order-tracking-for-woocommerce-modal');
        if (!$modal.length) {
            return;
        }

        resetModalFields($modal);

        fetchOrderItemsIfNeeded(function(){
            renderItemsIntoModal();
            $modal.attr('aria-hidden', 'false');
            $modal.addClass('is-open');
        });
    }

    function closeModal() {
        var $modal = $('#kitgenix-order-tracking-for-woocommerce-modal');
        $modal.attr('aria-hidden', 'true');
        $modal.removeClass('is-open');
    }

    function collectShipmentItems($modal) {
        var items = [];
        $modal.find('.kitgenix-order-tracking-for-woocommerce-item-row').each(function(){
            var $row   = $(this);
            var itemId = parseInt($row.data('itemId'), 10) || 0;
            var max    = parseFloat($row.data('maxQty')) || 0;
            var qty    = parseFloat($row.find('.kitgenix-order-tracking-for-woocommerce-item-qty-input').val()) || 0;

            if (!itemId) {
                return;
            }

            if (qty < 0) {
                qty = 0;
            }
            if (max && qty > max) {
                qty = max;
            }

            if (qty > 0) {
                items.push({
                    item_id: itemId,
                    qty: qty
                });
            }
        });
        return items;
    }

    function addShipmentFromModal() {
        var $modal        = $('#kitgenix-order-tracking-for-woocommerce-modal');
        var number        = $.trim($modal.find('.kitgenix-order-tracking-for-woocommerce-field-number').val());
        var carrier       = $modal.find('.kitgenix-order-tracking-for-woocommerce-field-carrier').val();
        var carrierLabel  = $.trim($modal.find('.kitgenix-order-tracking-for-woocommerce-field-carrier option:selected').text());
        var date          = $.trim($modal.find('.kitgenix-order-tracking-for-woocommerce-field-date').val());
        var status        = $modal.find('input[name="kitgenix_order_tracking_for_woocommerce_status"]:checked').val() || 'no_change';

        var settings = window.KitgenixOrderTracking || {};
        var i18n     = settings.i18n || {};

        if (!number) {
            var msg = i18n.missing_tracking_number || 'Please enter a tracking number.';
            window.alert(msg);
            return;
        }

        var items = collectShipmentItems($modal);

        var $wrap = $('#kitgenix-order-tracking-for-woocommerce-wrap');
        var $list = $wrap.find('.kitgenix-shipments-list');
        if (!$list.length) {
            return;
        }

        $list.find('.kitgenix-no-shipments').remove();

        var index = $list.find('.kitgenix-shipment-row').length;

        var labelCarrier  = i18n.carrier_label   || 'Carrier:';
        var labelTracking = i18n.tracking_label  || 'Tracking number:';
        var labelDate     = i18n.date_shipped_label || 'Date shipped:';
        var labelUnknown  = i18n.unknown_carrier || 'Unknown';
        var labelRemove   = i18n.remove || 'Remove shipment';
        var labelItems    = i18n.items_label || 'Items in this shipment:';

        var html  = '';
        html += '<div class="kitgenix-shipment-row">';
        html += '<input type="hidden" name="kitgenix_shipments[' + index + '][id]" value="" />';
        html += '<input type="hidden" name="kitgenix_shipments[' + index + '][carrier]" value="' + escHtml(carrier) + '" />';
        html += '<input type="hidden" name="kitgenix_shipments[' + index + '][tracking_number]" value="' + escHtml(number) + '" />';
        html += '<input type="hidden" name="kitgenix_shipments[' + index + '][date_shipped]" value="' + escHtml(date) + '" />';

        if (items.length) {
            items.forEach(function(item){
                html += '<input type="hidden" name="kitgenix_shipments[' + index + '][items][' + escHtml(String(item.item_id)) + '][qty]" value="' + escHtml(String(item.qty)) + '" />';
            });
        }

        html += '<p>';
        html += '<strong>' + escHtml(labelCarrier) + '</strong> ' + escHtml(carrierLabel || carrier || labelUnknown) + '<br>';
        html += '<strong>' + escHtml(labelTracking) + '</strong> ' + escHtml(number);
        if (date) {
            html += '<br><strong>' + escHtml(labelDate) + '</strong> ' + escHtml(date);
        }
        html += '</p>';

        if (items.length) {
            html += '<p class="kitgenix-shipment-items"><strong>' + escHtml(labelItems) + '</strong><br>';
            items.forEach(function(item, idx){
                html += escHtml('#' + item.item_id + ' × ' + item.qty);
                if (idx < items.length - 1) {
                    html += '<br>';
                }
            });
            html += '</p>';
        }

        html += '<p><button type="button" class="button-link-delete kitgenix-remove-shipment">';
        html += escHtml(labelRemove);
        html += '</button></p>';

        html += '<hr /></div>';

        $list.append(html);

        $wrap.find('input[name="kitgenix_order_status_action"]').remove();
        $('<input type="hidden" name="kitgenix_order_status_action">')
            .val(status)
            .appendTo($wrap);

        applyStatusToOrderDropdown(status);

        closeModal();
    }

    function maybeShowNoShipmentsPlaceholder() {
        var $list = $('#kitgenix-order-tracking-for-woocommerce-wrap .kitgenix-shipments-list');
        if (!$list.length) {
            return;
        }

        if (!$list.find('.kitgenix-shipment-row').length) {
            var settings = window.KitgenixOrderTracking || {};
            var i18n     = settings.i18n || {};
            var none     = i18n.no_shipments || 'No tracking information added yet.';

            if (!$list.find('.kitgenix-no-shipments').length) {
                $list.append('<p class="kitgenix-no-shipments">' + escHtml(none) + '</p>');
            }
        }
    }

    $(document).on('click', '.kitgenix-add-shipment-modal', function(e){
        e.preventDefault();
        openModal();
    });

    $(document).on('click', '#kitgenix-order-tracking-for-woocommerce-modal [data-kitgenix-modal-close]', function(e){
        e.preventDefault();
        closeModal();
    });

    $(document).on('click', '#kitgenix-order-tracking-for-woocommerce-modal [data-kitgenix-modal-confirm]', function(e){
        e.preventDefault();
        addShipmentFromModal();
    });

    $(document).on('click', '.kitgenix-remove-shipment', function(e){
        e.preventDefault();
        $(this).closest('.kitgenix-shipment-row').remove();
        maybeShowNoShipmentsPlaceholder();
    });

    $(document).on('click', '.kitgenix-save-shipments', function(e){
        e.preventDefault();
        var $btn = $('#publish, #save_post, .save_order');
        if ($btn.length) {
            $btn.trigger('click');
        }
    });

    $(document).on('keydown', function(e){
        var $modal = $('#kitgenix-order-tracking-for-woocommerce-modal');
        if (e.key === 'Escape' && $modal.length && $modal.hasClass('is-open')) {
            closeModal();
        }
    });

})(jQuery);
