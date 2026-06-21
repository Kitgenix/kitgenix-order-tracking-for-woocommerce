(function () {
    'use strict';

    function initRanklistBars() {
        var bars = document.querySelectorAll('.kitgenix-analytics-ranklist__bar span[data-kitgenix-order-tracking-for-woocommercewidth]');
        if (!bars.length) {
            return;
        }

        for (var i = 0; i < bars.length; i++) {
            var el = bars[i];
            var raw = el.getAttribute('data-kitgenix-order-tracking-for-woocommercewidth');
            if (raw === null) {
                continue;
            }

            var pct = parseFloat(String(raw));
            if (isNaN(pct)) {
                pct = 0;
            }

            pct = Math.max(0, Math.min(100, pct));
            el.style.width = pct + '%';
        }
    }

    function initCustomCarriersUi() {
        var labels = (window.kitgenixOrderTrackingAnalytics && window.kitgenixOrderTrackingAnalytics.labels)
            ? window.kitgenixOrderTrackingAnalytics.labels
            : {
                upload: 'Upload',
                upload_title: 'Select icon',
                upload_use: 'Use this icon',
                confirm_delete_custom_carrier: 'Delete this custom shipper?'
            };

        var modal = document.getElementById('kitgenix-order-tracking-custom-carrier-modal');
        var editForm = document.getElementById('kitgenix-order-tracking-custom-carrier-edit-form');
        var deleteForm = document.getElementById('kitgenix-order-tracking-custom-carrier-delete-form');
        var modalDeleteBtn = document.getElementById('kitgenix-order-tracking-custom-carrier-modal-delete');

        var mediaFrame = null;
        var currentIconInput = null;

        function openMediaPickerForInput(inputEl) {
            if (!window.wp || !wp.media) {
                return;
            }

            currentIconInput = inputEl;

            if (!mediaFrame) {
                mediaFrame = wp.media({
                    title: labels.upload_title,
                    button: { text: labels.upload_use },
                    multiple: false,
                    library: { type: 'image' }
                });

                mediaFrame.on('select', function () {
                    if (!currentIconInput) {
                        return;
                    }
                    var selection = mediaFrame.state().get('selection');
                    if (!selection) {
                        return;
                    }
                    var first = selection.first();
                    if (!first) {
                        return;
                    }
                    var attachment = first.toJSON();
                    if (attachment && attachment.url) {
                        currentIconInput.value = attachment.url;
                    }
                });
            }

            mediaFrame.open();
        }

        function openModalFromLink(link) {
            if (!modal || !editForm || !link) {
                return;
            }

            editForm.querySelector('input[name="kitgenix_order_tracking_for_woocommerce_custom_action"]').value = 'edit';
            editForm.querySelector('input[name="kitgenix_order_tracking_for_woocommerce_custom_carrier[key]"]').value = link.getAttribute('data-key') || '';
            editForm.querySelector('input[name="kitgenix_order_tracking_for_woocommerce_custom_carrier[label]"]').value = link.getAttribute('data-label') || '';
            editForm.querySelector('input[name="kitgenix_order_tracking_for_woocommerce_custom_carrier[url_pattern]"]').value = link.getAttribute('data-url-pattern') || '';
            editForm.querySelector('input[name="kitgenix_order_tracking_for_woocommerce_custom_carrier[icon]"]').value = link.getAttribute('data-icon') || '';
            editForm.querySelector('input[name="kitgenix_order_tracking_for_woocommerce_custom_carrier[priority]"]').value = link.getAttribute('data-priority') || '0';

            var enabledVal = link.getAttribute('data-enabled') || '0';
            var enabledEl = editForm.querySelector('input[name="kitgenix_order_tracking_for_woocommerce_custom_carrier[enabled]"]');
            if (enabledEl) {
                enabledEl.checked = (enabledVal === '1');
            }

            modal.setAttribute('aria-hidden', 'false');
            modal.classList.add('is-open');

            var first = document.getElementById('kitgenix_order_tracking_modal_label');
            if (first) {
                first.focus();
            }
        }

        function closeModal() {
            if (!modal) {
                return;
            }
            modal.setAttribute('aria-hidden', 'true');
            modal.classList.remove('is-open');
        }

        function submitDeleteForKey(key) {
            if (!deleteForm) {
                return;
            }
            var keyEl = deleteForm.querySelector('input[name="kitgenix_order_tracking_for_woocommerce_custom_carrier[key]"]');
            if (!keyEl) {
                return;
            }
            keyEl.value = key || '';
            deleteForm.submit();
        }

        document.addEventListener('click', function (e) {
            var t = e.target;
            if (t && t.nodeType === 3) {
                t = t.parentElement;
            }
            if (!t) {
                return;
            }

            var close = t.closest('[data-kitgenix-modal-close]');
            if (close && modal && modal.classList.contains('is-open')) {
                e.preventDefault();
                closeModal();
                return;
            }

            var uploadBtn = t.closest('.kitgenix-order-tracking-for-woocommerce-icon-upload');
            if (uploadBtn) {
                e.preventDefault();
                var wrap = uploadBtn.closest('.kitgenix-field') || uploadBtn.parentElement;
                if (!wrap) {
                    return;
                }
                var inputEl = wrap.querySelector('input[type="url"]');
                if (!inputEl) {
                    return;
                }
                openMediaPickerForInput(inputEl);
                return;
            }

            var editLink = t.closest('[data-kitgenix-custom-carrier-edit]');
            if (editLink) {
                e.preventDefault();
                openModalFromLink(editLink);
                return;
            }

            var deleteLink = t.closest('[data-kitgenix-custom-carrier-delete]');
            if (deleteLink) {
                e.preventDefault();
                var key = deleteLink.getAttribute('data-key') || '';
                if (!key) {
                    return;
                }
                if (!window.confirm(labels.confirm_delete_custom_carrier)) {
                    return;
                }
                submitDeleteForKey(key);
                return;
            }
        });

        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && modal && modal.classList.contains('is-open')) {
                closeModal();
            }
        });

        if (modalDeleteBtn) {
            modalDeleteBtn.addEventListener('click', function (e) {
                e.preventDefault();
                if (!editForm) {
                    return;
                }
                var keyEl = editForm.querySelector('input[name="kitgenix_order_tracking_for_woocommerce_custom_carrier[key]"]');
                var key = keyEl ? (keyEl.value || '') : '';
                if (!key) {
                    return;
                }
                if (!window.confirm(labels.confirm_delete_custom_carrier)) {
                    return;
                }
                submitDeleteForKey(key);
            });
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () {
            initRanklistBars();
            initCustomCarriersUi();
        });
    } else {
        initRanklistBars();
        initCustomCarriersUi();
    }
})();
