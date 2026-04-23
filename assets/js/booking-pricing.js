/**
 * FluentBooking - Réservations de groupe
 *
 * Per-event features driven by `window.fbgrpConfig`:
 *   - priceEvents[]     : event IDs with "Prix par personne"
 *   - hideEmailEvents[] : event IDs with "Masquer l'email des invités"
 *
 * On pages where at least one matching event is rendered, we:
 *   1. Multiply the payment table amounts by the guest count (if price enabled).
 *   2. Pre-fill guest emails so the hidden field still carries a value (if hide enabled).
 *   3. Strip `required` from hidden email inputs so HTML5 validation never blocks submit.
 *   4. Keep names required on guest rows when price-per-person is on.
 */
(function () {
    'use strict';

    var cfg = window.fbgrpConfig || {};

    function toIntList(arr) {
        return (arr || []).map(function (v) { return parseInt(v, 10); })
                          .filter(function (v) { return !isNaN(v); });
    }

    var priceEvents = toIntList(cfg.priceEvents);
    var hideEvents  = toIntList(cfg.hideEmailEvents);

    if (!priceEvents.length && !hideEvents.length) return;

    // Collect event IDs rendered on the current page by scanning the globals
    // Fluent Booking emits per event (`fcal_public_vars_{calendar_id}_{event_id}`).
    function pageHasAny(enabledIds) {
        if (!enabledIds.length) return false;
        for (var key in window) {
            if (key.indexOf('fcal_public_vars_') !== 0) continue;
            var eid = parseInt(key.split('_').pop(), 10);
            if (enabledIds.indexOf(eid) !== -1) return true;
        }
        return false;
    }

    var active = { price: false, hide: false };
    function refreshActive() {
        active.price = pageHasAny(priceEvents);
        active.hide  = pageHasAny(hideEvents);
        return active.price || active.hide;
    }

    function parseAmount(text) {
        if (!text) return null;
        var digits = text.replace(/[^\d.,]/g, '');
        if (!digits) return null;
        if (digits.lastIndexOf(',') > digits.lastIndexOf('.')) {
            digits = digits.replace(/\./g, '').replace(',', '.');
        } else {
            digits = digits.replace(/,/g, '');
        }
        var n = parseFloat(digits);
        return isNaN(n) ? null : n;
    }

    function formatAmount(value, template) {
        if (!template) return value.toFixed(2);
        var str = value.toFixed(2);
        if (/\d,\d/.test(template)) str = str.replace('.', ',');
        return template.replace(/[\d]+[.,\d]*/, str);
    }

    function updatePriceTable(form) {
        var table = form.querySelector('.fcal_payment_items_table');
        if (!table) return;

        var guests = 1 + form.querySelectorAll('.fcal_multi_guest_input').length;
        var grandTotal = 0;
        var lineItemAmounts = [];

        table.querySelectorAll('tbody tr').forEach(function (row) {
            var cells = row.querySelectorAll('td');
            if (cells.length < 3) return;

            if (!row.dataset.unitPrice) {
                var qty = parseInt(cells[1].textContent, 10) || 1;
                var price = parseAmount(cells[2].textContent);
                if (price === null) return;
                row.dataset.unitPrice = price / qty;
                row.dataset.priceTpl = cells[2].textContent.trim();
            }

            var unit = parseFloat(row.dataset.unitPrice);
            if (isNaN(unit)) return;

            var line = unit * guests;
            grandTotal += line;
            cells[1].textContent = guests;
            cells[2].textContent = formatAmount(line, row.dataset.priceTpl);

            var priceSpan = cells[2].querySelector('.fcal_payment_amount');
            if (priceSpan) lineItemAmounts.push(priceSpan);
        });

        // Update every summary amount in the form (Subtotal, Total, …) except
        // the line-item prices we just wrote. Fluent Booking Pro reuses the
        // same `.fcal_payment_amount` class for items and totals, and the
        // "Total:" line can live outside the items table.
        form.querySelectorAll('.fcal_payment_amount').forEach(function (el) {
            if (lineItemAmounts.indexOf(el) !== -1) return;
            if (el.closest('.fcal_payment_items_table tbody tr td:nth-child(3)')) return;

            if (!el.dataset.totalTpl) {
                el.dataset.totalTpl = el.textContent.trim();
            }
            el.textContent = formatAmount(grandTotal, el.dataset.totalTpl);
        });
    }

    function prefillGuestEmails(form) {
        var mainEmail = form.querySelector('input[type="email"][placeholder]');
        if (!mainEmail || !mainEmail.value) return;

        var bookerEmail = mainEmail.value.trim();
        if (!bookerEmail) return;

        var at = bookerEmail.indexOf('@');
        if (at < 1) return;

        var local = bookerEmail.substring(0, at).split('+')[0];
        var domain = bookerEmail.substring(at + 1);

        form.querySelectorAll('.fcal_multi_guest_input').forEach(function (row, i) {
            var emailInput = row.querySelector('input[type="email"]');
            if (!emailInput || emailInput.value) return;

            emailInput.value = local + '+Invite' + (i + 1) + '@' + domain;
            emailInput.dispatchEvent(new Event('input', { bubbles: true }));
        });
    }

    function stripEmailRequired(form) {
        form.querySelectorAll('.fcal_multi_guest_input input[type="email"]').forEach(function (input) {
            if (input.hasAttribute('required')) input.removeAttribute('required');
        });
    }

    function enforceNameRequired(form) {
        form.querySelectorAll('.fcal_multi_guest_input input').forEach(function (input) {
            var type = (input.getAttribute('type') || 'text').toLowerCase();
            if (type === 'email') return;
            if (!input.hasAttribute('required')) input.setAttribute('required', '');
        });
    }

    function applyToForm(form) {
        if (active.price) updatePriceTable(form);
        if (active.hide) {
            prefillGuestEmails(form);
            stripEmailRequired(form);
        }
        if (active.price) enforceNameRequired(form);
    }

    function observe(form) {
        if (form.dataset.fbgrp) return;
        form.dataset.fbgrp = '1';

        var timer = null;
        new MutationObserver(function () {
            clearTimeout(timer);
            timer = setTimeout(function () { applyToForm(form); }, 80);
        }).observe(form, { childList: true, subtree: true });

        applyToForm(form);
    }

    function init() {
        if (!refreshActive()) return;

        document.querySelectorAll('.fcal_booking_form_wrap').forEach(observe);

        new MutationObserver(function () {
            if (!refreshActive()) return;
            document.querySelectorAll('.fcal_booking_form_wrap:not([data-fbgrp])').forEach(observe);
        }).observe(document.body, { childList: true, subtree: true });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
