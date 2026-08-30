(function($){
    'use strict';

    var orderItems = [];
    var editingId   = null;
    var editingIndex = null;

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
        var settings = window.KitgenixOrderTracking || {};
        var i18n     = settings.i18n || {};

        editingId    = null;
        editingIndex = null;

        $modal.find('.kitgenix-modal-title').text(i18n.add_title || 'Add tracking');
        $modal.find('[data-kitgenix-modal-confirm]').text(i18n.confirm || 'Confirm');

        $modal.find('.kitgenix-order-tracking-for-woocommerce-field-editing-id').val('');
        $modal.find('.kitgenix-order-tracking-for-woocommerce-field-number').val('');
        $modal.find('.kitgenix-order-tracking-for-woocommerce-field-carrier').val('');
        $modal.find('.kitgenix-order-tracking-for-woocommerce-field-date').val($modal.find('.kitgenix-order-tracking-for-woocommerce-field-date').attr('value'));
        $modal.find('.kitgenix-order-tracking-for-woocommerce-field-delivery-estimate').val('');
        $modal.find('.kitgenix-order-tracking-for-woocommerce-field-shipment-status').val('shipped');
        $modal.find('.kitgenix-order-tracking-for-woocommerce-field-note').val('');
        $modal.find('input[name="kitgenix_order_tracking_for_woocommerce_status"][value="no_change"]').prop('checked', true);
    }

    function fillModalForEdit($modal, data) {
        var settings = window.KitgenixOrderTracking || {};
        var i18n     = settings.i18n || {};

        editingId    = data.id || null;
        editingIndex = data.index;

        $modal.find('.kitgenix-modal-title').text(i18n.edit_title || 'Edit tracking');
        $modal.find('[data-kitgenix-modal-confirm]').text(i18n.save || 'Save changes');

        $modal.find('.kitgenix-order-tracking-for-woocommerce-field-editing-id').val(data.id || '');
        $modal.find('.kitgenix-order-tracking-for-woocommerce-field-number').val(data.trackingNumber || '');
        $modal.find('.kitgenix-order-tracking-for-woocommerce-field-carrier').val(data.carrier || '');
        $modal.find('.kitgenix-order-tracking-for-woocommerce-field-date').val(data.dateShipped || '');
        $modal.find('.kitgenix-order-tracking-for-woocommerce-field-delivery-estimate').val(data.deliveryEstimate || '');
        $modal.find('.kitgenix-order-tracking-for-woocommerce-field-shipment-status').val(data.status || 'shipped');
        $modal.find('.kitgenix-order-tracking-for-woocommerce-field-note').val(data.note || '');
    }

    function renderItemsIntoModal(preselectedItems) {
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

        var preselected = {};
        (preselectedItems || []).forEach(function(item){
            preselected[item.item_id] = item.qty;
        });

        $container.append(
            $('<p/>', {
                'class': 'description',
                text: i18n.items_section_title || 'Select which items (and quantities) are included in this shipment.'
            })
        );

        orderItems.forEach(function(item){
            // When editing, the item's own previously-allocated quantity is not
            // counted against the "remaining" total returned by the server, so
            // add it back on here to get the true selectable max for this row.
            var ownQty = preselected.hasOwnProperty(item.item_id) ? preselected[item.item_id] : 0;
            var max    = item.qty + ownQty;
            var value  = preselected.hasOwnProperty(item.item_id) ? preselected[item.item_id] : item.qty;

            var row = $('<div/>', {
                'class': 'kitgenix-order-tracking-for-woocommerce-item-row',
                'data-item-id': item.item_id,
                'data-max-qty': max
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
                value: value,
                max: max
            });
            var maxLabel = $('<span/>', {
                'class': 'kitgenix-order-tracking-for-woocommerce-item-row__qty-max',
                text: 'of ' + max
            });

            qtyWrap.append(input).append(maxLabel);

            row.append(media).append(info).append(qtyWrap);
            $container.append(row);
        });
    }

    function fetchOrderItems(shipmentId, callback) {
        var settings = window.KitgenixOrderTracking || {};
        if (!settings.order_id) {
            orderItems = [];
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
                shipment_id: shipmentId || '',
                nonce: settings.nonce
            }
        }).done(function(response){
            orderItems = (response && response.success && response.data && response.data.items) ? response.data.items : [];
        }).always(function(){
            if (typeof callback === 'function') {
                callback();
            }
        });
    }

    function showItemsLoading($modal) {
        var settings    = window.KitgenixOrderTracking || {};
        var i18n        = settings.i18n || {};
        var $container  = $modal.find('.kitgenix-order-tracking-for-woocommerce-items');
        var $confirmBtn = $modal.find('[data-kitgenix-modal-confirm]');

        if ($container.length) {
            $container.attr('data-loaded', '0').html(
                '<p class="description kitgenix-order-tracking-for-woocommerce-items-loading">' +
                escHtml(i18n.loading_items || 'Loading order items…') +
                '</p>'
            );
        }

        // Item rows aren't in the DOM yet, so block Confirm until fetchOrderItems()
        // finishes – otherwise a fast click here would save the shipment with no
        // item quantities attached even though the order does have items.
        $confirmBtn.prop('disabled', true);
    }

    function openModal(editData) {
        var $modal = $('#kitgenix-order-tracking-for-woocommerce-modal');
        if (!$modal.length) {
            return;
        }

        resetModalFields($modal);

        var preselectedItems = [];
        if (editData) {
            fillModalForEdit($modal, editData);
            preselectedItems = editData.items || [];
        }

        // Open the modal right away with a loading placeholder instead of
        // waiting on the order-items AJAX call first – previously the button
        // click did nothing until that request finished, which reads as
        // "the button doesn't work" on a slow connection.
        showItemsLoading($modal);
        if (window.KitgenixAdminUI && typeof window.KitgenixAdminUI.openModal === 'function') {
            window.KitgenixAdminUI.openModal('#kitgenix-order-tracking-for-woocommerce-modal');
        } else {
            $modal.removeAttr('hidden').attr('aria-hidden', 'false');
        }

        fetchOrderItems(editData ? editData.id : '', function(){
            renderItemsIntoModal(preselectedItems);
            $modal.find('[data-kitgenix-modal-confirm]').prop('disabled', false);
        });
    }

    function closeModal() {
        var $modal = $('#kitgenix-order-tracking-for-woocommerce-modal');
        if (!$modal.length) {
            return;
        }
        if (window.KitgenixAdminUI && typeof window.KitgenixAdminUI.closeModal === 'function') {
            window.KitgenixAdminUI.closeModal('#kitgenix-order-tracking-for-woocommerce-modal');
        } else {
            $modal.attr('hidden', 'hidden').attr('aria-hidden', 'true');
        }
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

    function buildRowHtml(index, fields, items) {
        var settings = window.KitgenixOrderTracking || {};
        var i18n     = settings.i18n || {};
        var statuses = settings.statuses || {};

        var labelCarrier    = i18n.carrier_label            || 'Carrier:';
        var labelTracking   = i18n.tracking_label           || 'Tracking number:';
        var labelDate       = i18n.date_shipped_label       || 'Date shipped:';
        var labelEstimate   = i18n.delivery_estimate_label  || 'Estimated delivery:';
        var labelStatus     = i18n.status_label             || 'Status:';
        var labelNote       = i18n.note_label               || 'Internal note:';
        var labelUnknown    = i18n.unknown_carrier          || 'Unknown';
        var labelRemove     = i18n.remove                   || 'Remove shipment';
        var labelEdit       = i18n.edit_shipment            || 'Edit shipment';
        var labelItems      = i18n.items_label              || 'Items in this shipment:';

        var statusLabel = statuses[fields.status] || fields.status;
        var itemsJson    = JSON.stringify(items);

        var html = '';
        html += '<div class="kitgenix-shipment-row" data-index="' + index + '">';
        html += '<input type="hidden" name="kitgenix_shipments[' + index + '][id]" value="' + escHtml(fields.id || '') + '" />';
        html += '<input type="hidden" name="kitgenix_shipments[' + index + '][carrier]" value="' + escHtml(fields.carrier) + '" />';
        html += '<input type="hidden" name="kitgenix_shipments[' + index + '][tracking_number]" value="' + escHtml(fields.trackingNumber) + '" />';
        html += '<input type="hidden" name="kitgenix_shipments[' + index + '][date_shipped]" value="' + escHtml(fields.dateShipped) + '" />';
        html += '<input type="hidden" name="kitgenix_shipments[' + index + '][delivery_estimate]" value="' + escHtml(fields.deliveryEstimate) + '" />';
        html += '<input type="hidden" name="kitgenix_shipments[' + index + '][status]" value="' + escHtml(fields.status) + '" />';
        html += '<input type="hidden" name="kitgenix_shipments[' + index + '][note]" value="' + escHtml(fields.note) + '" />';

        items.forEach(function(item){
            html += '<input type="hidden" name="kitgenix_shipments[' + index + '][items][' + escHtml(String(item.item_id)) + '][qty]" value="' + escHtml(String(item.qty)) + '" />';
        });

        html += '<p>';
        html += '<strong>' + escHtml(labelCarrier) + '</strong> ' + escHtml(fields.carrierLabel || fields.carrier || labelUnknown) + '<br>';
        html += '<strong>' + escHtml(labelTracking) + '</strong> ' + escHtml(fields.trackingNumber) + '<br>';
        html += '<strong>' + escHtml(labelStatus) + '</strong> ' + escHtml(statusLabel);
        if (fields.dateShipped) {
            html += '<br><strong>' + escHtml(labelDate) + '</strong> ' + escHtml(fields.dateShipped);
        }
        if (fields.deliveryEstimate) {
            html += '<br><strong>' + escHtml(labelEstimate) + '</strong> ' + escHtml(fields.deliveryEstimate);
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

        if (fields.note) {
            html += '<p class="kitgenix-shipment-note"><strong>' + escHtml(labelNote) + '</strong><br>' + escHtml(fields.note) + '</p>';
        }

        html += '<p>';
        html += '<button type="button" class="button-link kitgenix-edit-shipment"';
        html += ' data-id="' + escHtml(fields.id || '') + '"';
        html += ' data-carrier="' + escHtml(fields.carrier) + '"';
        html += ' data-tracking-number="' + escHtml(fields.trackingNumber) + '"';
        html += ' data-date-shipped="' + escHtml(fields.dateShipped) + '"';
        html += ' data-delivery-estimate="' + escHtml(fields.deliveryEstimate) + '"';
        html += ' data-status="' + escHtml(fields.status) + '"';
        html += ' data-note="' + escHtml(fields.note) + '"';
        html += ' data-items="' + escHtml(itemsJson) + '">';
        html += escHtml(labelEdit);
        html += '</button>';
        html += '&nbsp;<button type="button" class="button-link-delete kitgenix-remove-shipment">';
        html += escHtml(labelRemove);
        html += '</button></p>';

        html += '<hr /></div>';

        return html;
    }

    function addOrUpdateShipmentFromModal() {
        var $modal        = $('#kitgenix-order-tracking-for-woocommerce-modal');
        var number        = $.trim($modal.find('.kitgenix-order-tracking-for-woocommerce-field-number').val());
        var carrier       = $modal.find('.kitgenix-order-tracking-for-woocommerce-field-carrier').val();
        var carrierLabel  = $.trim($modal.find('.kitgenix-order-tracking-for-woocommerce-field-carrier option:selected').text());
        var date          = $.trim($modal.find('.kitgenix-order-tracking-for-woocommerce-field-date').val());
        var estimate      = $.trim($modal.find('.kitgenix-order-tracking-for-woocommerce-field-delivery-estimate').val());
        var shipmentStatus = $modal.find('.kitgenix-order-tracking-for-woocommerce-field-shipment-status').val() || 'shipped';
        var note          = $modal.find('.kitgenix-order-tracking-for-woocommerce-field-note').val() || '';
        var orderStatus   = $modal.find('input[name="kitgenix_order_tracking_for_woocommerce_status"]:checked').val() || 'no_change';

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

        var fields = {
            id: editingId || '',
            carrier: carrier,
            carrierLabel: carrierLabel,
            trackingNumber: number,
            dateShipped: date,
            deliveryEstimate: estimate,
            status: shipmentStatus,
            note: note
        };

        if (editingIndex !== null) {
            var $existingRow = $list.find('.kitgenix-shipment-row[data-index="' + editingIndex + '"]');
            if ($existingRow.length) {
                $existingRow.replaceWith(buildRowHtml(editingIndex, fields, items));
            }
        } else {
            var index = $list.find('.kitgenix-shipment-row').length;
            $list.append(buildRowHtml(index, fields, items));
        }

        $wrap.find('input[name="kitgenix_order_status_action"]').remove();
        $('<input type="hidden" name="kitgenix_order_status_action">')
            .val(orderStatus)
            .appendTo($wrap);

        applyStatusToOrderDropdown(orderStatus);

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

    $(document).on('click', '.kitgenix-edit-shipment', function(e){
        e.preventDefault();
        var $btn  = $(this);
        var $row  = $btn.closest('.kitgenix-shipment-row');
        var items = [];
        try {
            items = JSON.parse($btn.data('items') || '[]');
        } catch (err) {
            items = [];
        }

        openModal({
            index: $row.data('index'),
            id: $btn.data('id'),
            carrier: $btn.data('carrier'),
            trackingNumber: $btn.data('trackingNumber'),
            dateShipped: $btn.data('dateShipped'),
            deliveryEstimate: $btn.data('deliveryEstimate'),
            status: $btn.data('status'),
            note: $btn.data('note'),
            items: items
        });
    });

    $(document).on('click', '#kitgenix-order-tracking-for-woocommerce-modal [data-kitgenix-modal-close]', function(e){
        e.preventDefault();
        closeModal();
    });

    $(document).on('click', '#kitgenix-order-tracking-for-woocommerce-modal [data-kitgenix-modal-confirm]', function(e){
        e.preventDefault();
        addOrUpdateShipmentFromModal();
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

    // Escape-to-close is handled globally by kitgenix-admin-components.js
    // once the modal is opened via window.KitgenixAdminUI.openModal().

})(jQuery);
