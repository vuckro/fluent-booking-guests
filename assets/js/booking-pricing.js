/**
 * WaasKit – FluentBooking Group Reservation Pricing
 * Frontend script: updates the reservation summary table in real time
 * as guest rows are added or removed.
 *
 * DOM targets (FluentBooking CSS classes):
 *   Form container : .fcal_booking_form_wrap
 *   Guest rows     : .fcal_multi_guest_input
 *   Payment table  : .fcal_payment_items_table
 *
 * @package WaasKit\FluentBookingGuests
 * @version 1.1.0
 */
(function () {
    'use strict';

    // -------------------------------------------------------------------------
    // Price helpers
    // -------------------------------------------------------------------------

    /**
     * Parse a localised price string into a plain float.
     * Supports "15,00 CHF", "CHF 15.00", "$15.00", "1.234,56", etc.
     * Returns null when no numeric value is found.
     *
     * @param  {string} text
     * @return {number|null}
     */
    function parseAmount(text) {
        if (!text) return null;
        var cleaned = text.replace(/[^\d.,]/g, '');
        if (!cleaned) return null;
        var lastComma = cleaned.lastIndexOf(',');
        var lastDot   = cleaned.lastIndexOf('.');
        if (lastComma > lastDot) {
            cleaned = cleaned.replace(/\./g, '').replace(',', '.');
        } else {
            cleaned = cleaned.replace(/,/g, '');
        }
        var value = parseFloat(cleaned);
        return isNaN(value) ? null : value;
    }

    /**
     * Re-format a float back to match a reference template string.
     * Example: formatAmount(30, "15,00 CHF") → "30,00 CHF"
     *
     * @param  {number} value
     * @param  {string} template  Original formatted string to mirror
     * @return {string}
     */
    function formatAmount(value, template) {
        if (!template) return value.toFixed(2);
        var useComma = /\d,\d/.test(template);
        var formatted = value.toFixed(2);
        if (useComma) {
            formatted = formatted.replace('.', ',');
        }
        return template.replace(/[\d]+[.,\d]*/, formatted);
    }

    // -------------------------------------------------------------------------
    // Guest-row helpers
    // -------------------------------------------------------------------------

    /**
     * Return the number of additional guest rows currently in the form.
     *
     * @param  {Element} formWrapper
     * @return {number}
     */
    function getGuestRowCount(formWrapper) {
        return formWrapper.querySelectorAll('.fcal_multi_guest_input').length;
    }

    /**
     * Return true if this table row is the "Total" summary row.
     * We detect it by checking whether the first <td> starts with "Total".
     *
     * @param  {Element} row
     * @return {boolean}
     */
    function isTotalRow(row) {
        var cells = row.querySelectorAll('td');
        if (!cells.length) return false;
        return cells[0].textContent.trim().toLowerCase().indexOf('total') === 0;
    }

    /**
     * Derive a unique placeholder e-mail address for a guest row so that
     * FluentBooking's backend validation (requires name + email) always passes.
     *
     * Strategy: reuse the main booker's e-mail with a "+guestN" alias.
     * Example: parent@example.com → parent+guest2@example.com
     * Fallback: guest-<timestamp>-N@placeholder.local
     *
     * @param  {Element} formWrapper
     * @param  {number}  index  1-based guest index
     * @return {string}
     */
    function buildPlaceholderEmail(formWrapper, index) {
        // The first visible e-mail input that is NOT inside a guest row = main booker.
        var mainInput = formWrapper.querySelector(
            'input[type="email"]:not(.fcal_multi_guest_input input)'
        );
        if (!mainInput) {
            // Fallback: first email input anywhere in the form that has a value.
            var allEmails = formWrapper.querySelectorAll('input[type="email"]');
            for (var i = 0; i < allEmails.length; i++) {
                if (allEmails[i].value.trim() && !allEmails[i].closest('.fcal_multi_guest_input')) {
                    mainInput = allEmails[i];
                    break;
                }
            }
        }

        var mainEmail = mainInput ? mainInput.value.trim() : '';
        if (mainEmail && mainEmail.indexOf('@') > 0) {
            var atPos     = mainEmail.indexOf('@');
            // Strip any existing +alias from the local part.
            var localPart = mainEmail.substring(0, atPos).split('+')[0];
            var domain    = mainEmail.substring(atPos + 1);
            return localPart + '+guest' + index + '@' + domain;
        }

        // No main email yet → use a safe placeholder.
        return 'guest-' + index + '-' + Date.now() + '@placeholder.local';
    }

    /**
     * For every guest row whose e-mail input is still empty, inject a
     * placeholder address so that FluentBooking's backend validation passes
     * and the spot/billing count stays consistent.
     *
     * The placeholder is set as the input's value and the Vue reactive model
     * is notified via native input/change events.
     *
     * @param  {Element} formWrapper
     */
    function autoFillMissingEmails(formWrapper) {
        var rows = formWrapper.querySelectorAll('.fcal_multi_guest_input');
        rows.forEach(function (row, idx) {
            var emailInput = row.querySelector('input[type="email"]');
            if (!emailInput || emailInput.value.trim()) return; // already filled

            var placeholder = buildPlaceholderEmail(formWrapper, idx + 1);
            emailInput.value = placeholder;

            // Notify Vue's reactive system so the value is picked up on submit.
            emailInput.dispatchEvent(new Event('input',  { bubbles: true }));
            emailInput.dispatchEvent(new Event('change', { bubbles: true }));
        });
    }

    // -------------------------------------------------------------------------
    // Table update
    // -------------------------------------------------------------------------

    /**
     * Recalculate and re-render the payment summary table based on the
     * current number of guests (1 main + additional rows).
     *
     * Algorithm:
     *   1. Classify each <tr> as either an item row or the total row.
     *   2. On the first call, snapshot the unit price from the item row.
     *   3. Update item rows: qty cell + price cell.
     *   4. Update the total row with the sum of all items.
     *
     * @param  {Element} formWrapper
     */
    function updatePaymentTable(formWrapper) {
        var table = formWrapper.querySelector('.fcal_payment_items_table');
        if (!table) return;

        var totalGuests = 1 + getGuestRowCount(formWrapper);
        var allRows     = table.querySelectorAll('tr');
        var totalPrice  = 0;

        // Pass 1 — update item rows (skip header rows with <th> and the total row).
        allRows.forEach(function (row) {
            var cells = row.querySelectorAll('td');
            if (cells.length < 3)   return; // <th> header or empty
            if (isTotalRow(row))    return; // "Total:" row — handled in pass 2

            var qtyCell   = cells[1];
            var priceCell = cells[2];

            // Snapshot unit price once (before we start modifying it).
            if (!row.dataset.wkFbUnitPrice) {
                var currentQty = parseInt(qtyCell.textContent.trim(), 10) || 1;
                var rawPrice   = parseAmount(priceCell.textContent.trim());
                if (rawPrice === null) return;
                row.dataset.wkFbUnitPrice  = rawPrice / currentQty;
                row.dataset.wkFbPriceTmpl  = priceCell.textContent.trim();
            }

            var unitPrice = parseFloat(row.dataset.wkFbUnitPrice);
            if (isNaN(unitPrice)) return;

            var lineTotal  = unitPrice * totalGuests;
            totalPrice    += lineTotal;

            qtyCell.textContent   = totalGuests;
            priceCell.textContent = formatAmount(lineTotal, row.dataset.wkFbPriceTmpl);
        });

        // Pass 2 — update the "Total:" row (any number of cells, just update last one).
        allRows.forEach(function (row) {
            if (!isTotalRow(row)) return;

            var cells    = row.querySelectorAll('td');
            var lastCell = cells[cells.length - 1];

            // Snapshot the original template text.
            if (!row.dataset.wkFbTotalTmpl) {
                row.dataset.wkFbTotalTmpl = lastCell.textContent.trim();
            }
            lastCell.textContent = formatAmount(totalPrice, row.dataset.wkFbTotalTmpl);
        });
    }

    // -------------------------------------------------------------------------
    // Initialisation
    // -------------------------------------------------------------------------

    /**
     * Attach a MutationObserver to one booking form wrapper.
     * Distinguishes between "a new guest row was added" (acts immediately +
     * auto-fills the email) and "other DOM changes" (debounced update).
     *
     * @param  {Element} formWrapper
     */
    function initForWrapper(formWrapper) {
        if (formWrapper.dataset.wkFbInit) return;
        formWrapper.dataset.wkFbInit = '1';

        var debounceTimer = null;

        var observer = new MutationObserver(function (mutations) {
            var newGuestRow = false;

            mutations.forEach(function (mutation) {
                mutation.addedNodes.forEach(function (node) {
                    if (node.nodeType !== 1) return;
                    if (
                        node.classList.contains('fcal_multi_guest_input') ||
                        node.querySelector('.fcal_multi_guest_input')
                    ) {
                        newGuestRow = true;
                    }
                });
            });

            if (newGuestRow) {
                // New row added — auto-fill e-mail and update table immediately.
                autoFillMissingEmails(formWrapper);
                updatePaymentTable(formWrapper);
            } else {
                // Any other DOM change (row deleted, value changed by Vue, etc.)
                clearTimeout(debounceTimer);
                debounceTimer = setTimeout(function () {
                    updatePaymentTable(formWrapper);
                }, 150);
            }
        });

        observer.observe(formWrapper, {
            childList:  true,
            subtree:    true,
            attributes: false,
            characterData: false
        });

        // Initial render (table may already be in the DOM).
        updatePaymentTable(formWrapper);
    }

    /**
     * Find all booking form wrappers and initialise each one.
     * A body-level observer catches wrappers injected asynchronously by the
     * FluentBooking Vue SPA after the initial page load.
     */
    function init() {
        document.querySelectorAll('.fcal_booking_form_wrap').forEach(initForWrapper);

        var bodyObserver = new MutationObserver(function () {
            document.querySelectorAll(
                '.fcal_booking_form_wrap:not([data-wk-fb-init])'
            ).forEach(initForWrapper);
        });

        bodyObserver.observe(document.body, { childList: true, subtree: true });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

})();
