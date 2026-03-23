/**
 * FluentBooking - Group Reservation Pricing
 *
 * Watches for guest rows (.fcal_multi_guest_input) being added or removed,
 * updates the payment summary table (unit price × total guests),
 * and enforces required name + email on each additional guest.
 */
(function () {
    'use strict';

    // -- Price helpers --------------------------------------------------------

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

    // -- Payment table update -------------------------------------------------

    function updateTable(form) {
        var table = form.querySelector('.fcal_payment_items_table');
        if (!table) return;

        var guests = 1 + form.querySelectorAll('.fcal_multi_guest_input').length;
        var grandTotal = 0;

        // Update item rows in <tbody> (cells are <td>).
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
        });

        // Update total row in <tfoot>. The amount lives inside
        // <th><span class="fcal_payment_amount">...</span></th>.
        var amountEl = table.querySelector('tfoot .fcal_payment_amount');
        if (amountEl) {
            if (!amountEl.dataset.totalTpl) {
                amountEl.dataset.totalTpl = amountEl.textContent.trim();
            }
            amountEl.textContent = formatAmount(grandTotal, amountEl.dataset.totalTpl);
        }
    }

    // -- Guest field validation -----------------------------------------------

    function enforceRequired(form) {
        form.querySelectorAll('.fcal_multi_guest_input').forEach(function (row) {
            row.querySelectorAll('input[type="text"], input[type="email"]').forEach(function (input) {
                if (!input.hasAttribute('required')) {
                    input.setAttribute('required', '');
                }
            });
        });
    }

    // -- Observer -------------------------------------------------------------

    function observe(form) {
        if (form.dataset.fbgrp) return;
        form.dataset.fbgrp = '1';

        var timer = null;

        new MutationObserver(function () {
            clearTimeout(timer);
            timer = setTimeout(function () {
                updateTable(form);
                enforceRequired(form);
            }, 80);
        }).observe(form, { childList: true, subtree: true });

        updateTable(form);
    }

    function init() {
        document.querySelectorAll('.fcal_booking_form_wrap').forEach(observe);

        new MutationObserver(function () {
            document.querySelectorAll('.fcal_booking_form_wrap:not([data-fbgrp])').forEach(observe);
        }).observe(document.body, { childList: true, subtree: true });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
