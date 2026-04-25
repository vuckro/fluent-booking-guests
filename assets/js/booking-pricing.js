/**
 * FluentBooking — Réservations de groupe
 *
 * Per-event features driven by `window.fbgrpConfig`:
 *   - priceEvents[]     : event IDs with "Prix par personne"
 *   - hideEmailEvents[] : event IDs with "Masquer l'email des invités"
 *
 * On a form whose event opts in:
 *   - "Prix par personne" multiplies every payment-summary amount by the
 *     guest count, then recomputes any applied coupon against that scaled
 *     subtotal so Subtotal − Discount = Total stays consistent.
 *   - "Masquer l'email des invités" pre-fills the booker's email pattern
 *     into hidden guest email inputs and strips their `required` so HTML5
 *     validation never blocks submission.
 */
(function () {
    'use strict';

    var cfg         = window.fbgrpConfig || {};
    var priceEvents = toIntList(cfg.priceEvents);
    var hideEvents  = toIntList(cfg.hideEmailEvents);

    if (!priceEvents.length && !hideEvents.length) return;

    var active       = { price: false, hide: false };
    var couponCache  = {};

    interceptCouponAjax();

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

    // -----------------------------------------------------------------------
    // Bootstrap
    // -----------------------------------------------------------------------

    function init() {
        if (!refreshActive()) return;

        document.querySelectorAll('.fcal_booking_form_wrap').forEach(observe);

        new MutationObserver(function () {
            if (!refreshActive()) return;
            document.querySelectorAll('.fcal_booking_form_wrap:not([data-fbgrp])').forEach(observe);
        }).observe(document.body, { childList: true, subtree: true });
    }

    function observe(form) {
        if (form.dataset.fbgrp) return;
        form.dataset.fbgrp = '1';

        var timer;
        new MutationObserver(function () {
            clearTimeout(timer);
            timer = setTimeout(function () { applyToForm(form); }, 80);
        }).observe(form, { childList: true, subtree: true });

        applyToForm(form);
    }

    function applyToForm(form) {
        if (active.price) {
            updatePriceTable(form);
            enforceNameRequired(form);
        }
        if (active.hide) {
            prefillGuestEmails(form);
            stripEmailRequired(form);
        }
    }

    // Match each enabled event id against the `fcal_public_vars_*` globals
    // FluentBooking emits for every rendered event on the page.
    function refreshActive() {
        active.price = pageHasAny(priceEvents);
        active.hide  = pageHasAny(hideEvents);
        return active.price || active.hide;
    }

    function pageHasAny(enabledIds) {
        if (!enabledIds.length) return false;
        for (var key in window) {
            if (key.indexOf('fcal_public_vars_') !== 0) continue;
            var eid = parseInt(key.split('_').pop(), 10);
            if (enabledIds.indexOf(eid) !== -1) return true;
        }
        return false;
    }

    // -----------------------------------------------------------------------
    // Coupon AJAX interception
    //
    // FluentBooking computes the displayed discount against the unscaled unit
    // subtotal (because it knows nothing about our per-guest multiplication),
    // so the rendered amount is often capped. We grab the original coupon
    // configuration straight from the apply-coupon AJAX response and recompute
    // the discount ourselves against the multiplied subtotal.
    // -----------------------------------------------------------------------

    function interceptCouponAjax() {
        var origSend = XMLHttpRequest.prototype.send;

        XMLHttpRequest.prototype.send = function (body) {
            if (isCouponRequest(body)) {
                var xhr = this;
                xhr.addEventListener('load', function () { cacheCouponsFromResponse(xhr); });
            }
            return origSend.apply(this, arguments);
        };
    }

    function isCouponRequest(body) {
        var actions = ['fluent_booking_apply_coupon', 'fluent_booking_apply_bulk_coupons'];
        if (typeof body === 'string') {
            for (var i = 0; i < actions.length; i++) {
                if (body.indexOf(actions[i]) !== -1) return true;
            }
            return false;
        }
        if (body instanceof FormData) {
            return actions.indexOf(body.get('action')) !== -1;
        }
        return false;
    }

    function cacheCouponsFromResponse(xhr) {
        // FluentBooking sets responseType="json", so xhr.response is the
        // parsed object directly (and xhr.responseText is empty in that mode).
        var data = xhr.response;
        if (!data && xhr.responseText) {
            try { data = JSON.parse(xhr.responseText); } catch (e) { return; }
        }
        if (!data) return;

        if (data.coupon) storeCoupon(data.coupon);
        if (Array.isArray(data.coupons)) data.coupons.forEach(storeCoupon);
    }

    function storeCoupon(c) {
        if (!c || !c.coupon_code) return;
        couponCache[c.coupon_code] = {
            type:        c.discount_type,
            value:       parseFloat(c.discount) || 0,
            maxDiscount: parseFloat(c.max_discount_amount) || 0
        };
    }

    function computeDiscount(cfg, subtotal) {
        var amt = cfg.type === 'percentage'
            ? subtotal * cfg.value / 100
            : cfg.value;
        if (cfg.maxDiscount > 0 && cfg.maxDiscount < amt) amt = cfg.maxDiscount;
        return Math.min(amt, subtotal);
    }

    // -----------------------------------------------------------------------
    // Pricing display
    // -----------------------------------------------------------------------

    function updatePriceTable(form) {
        var table = form.querySelector('.fcal_payment_items_table');
        if (!table) return;

        var guests         = 1 + form.querySelectorAll('.fcal_multi_guest_input').length;
        var lineItemSpans  = [];
        var grandTotal     = 0;

        table.querySelectorAll('tbody tr').forEach(function (row) {
            var line = scaleLineItem(row, guests);
            if (line === null) return;
            grandTotal += line.amount;
            if (line.span) lineItemSpans.push(line.span);
        });

        var discount   = applyCoupons(form, grandTotal);
        var finalTotal = Math.max(0, grandTotal - discount);

        // Fluent Booking reuses `.fcal_payment_amount` for line items, the
        // subtotal, and the total — and the "Total:" line can live outside the
        // items table. Rewrite every span that's not a line item: amounts
        // inside `.fcal_payment_total` get the discounted total, everything
        // else (Subtotal, etc.) gets the gross subtotal.
        form.querySelectorAll('.fcal_payment_amount').forEach(function (el) {
            if (lineItemSpans.indexOf(el) !== -1) return;
            if (el.closest('.fcal_payment_items_table tbody td')) return;

            if (!el.dataset.fbgrpTpl) el.dataset.fbgrpTpl = el.textContent.trim();
            var value = el.closest('.fcal_payment_total') ? finalTotal : grandTotal;
            el.textContent = formatAmount(value, el.dataset.fbgrpTpl);
        });
    }

    function scaleLineItem(row, guests) {
        var cells = row.querySelectorAll('td');
        if (cells.length < 3) return null;

        if (!row.dataset.fbgrpUnit) {
            var qty   = parseInt(cells[1].textContent, 10) || 1;
            var price = parseAmount(cells[2].textContent);
            if (price === null) return null;
            row.dataset.fbgrpUnit = price / qty;
            row.dataset.fbgrpTpl  = cells[2].textContent.trim();
        }

        var unit = parseFloat(row.dataset.fbgrpUnit);
        if (isNaN(unit)) return null;

        var amount = unit * guests;
        cells[1].textContent = guests;
        cells[2].textContent = formatAmount(amount, row.dataset.fbgrpTpl);

        return { amount: amount, span: cells[2].querySelector('.fcal_payment_amount') };
    }

    function applyCoupons(form, subtotal) {
        var total = 0;
        form.querySelectorAll('.fcal_applied_coupon').forEach(function (row) {
            total += applyCouponRow(row, subtotal);
        });
        return total;
    }

    function applyCouponRow(row, subtotal) {
        var cell = findDiscountCell(row);
        if (!cell) return 0;

        // Cache the original "- {amount}" string on first sight so subsequent
        // passes keep the formatting (currency symbol, separators, sign) and
        // can still recover the original value when no AJAX config is cached.
        if (!row.dataset.fbgrpTpl) {
            var orig = parseAmount(cell.textContent);
            if (orig === null) return 0;
            row.dataset.fbgrpTpl  = cell.textContent.trim();
            row.dataset.fbgrpOrig = orig;
        }

        var cfg = couponCache[getCouponCode(row)];
        var amt = cfg
            ? computeDiscount(cfg, subtotal)
            : Math.min(parseFloat(row.dataset.fbgrpOrig), subtotal);

        cell.textContent = formatAmount(amt, row.dataset.fbgrpTpl);
        return amt;
    }

    function findDiscountCell(row) {
        var cells = row.querySelectorAll('th, td');
        for (var i = cells.length - 1; i >= 0; i--) {
            var txt = (cells[i].textContent || '').trim();
            if (txt.charAt(0) !== '-' && txt.charAt(0) !== '−') continue;
            if (parseAmount(txt) > 0) return cells[i];
        }
        return null;
    }

    function getCouponCode(row) {
        var badge = row.querySelector('.fcal_coupon_badge');
        if (!badge) return '';
        // Badge text is "{code}" or "{code} (rate%)" plus an inline-remove
        // glyph. Strip the remove button first, then trim a trailing "(…%)".
        var clone = badge.cloneNode(true);
        var rm    = clone.querySelector('.fcal_inline_remove');
        if (rm) rm.remove();
        return clone.textContent.replace(/\([^)]*\)/, '').trim();
    }

    // -----------------------------------------------------------------------
    // Guest email handling
    // -----------------------------------------------------------------------

    function prefillGuestEmails(form) {
        var mainEmail = form.querySelector('input[type="email"][placeholder]');
        if (!mainEmail || !mainEmail.value) return;

        var booker = mainEmail.value.trim();
        var at     = booker.indexOf('@');
        if (at < 1) return;

        var local  = booker.substring(0, at).split('+')[0];
        var domain = booker.substring(at + 1);

        form.querySelectorAll('.fcal_multi_guest_input').forEach(function (row, i) {
            var input = row.querySelector('input[type="email"]');
            if (!input || input.value) return;

            input.value = local + '+Invite' + (i + 1) + '@' + domain;
            input.dispatchEvent(new Event('input', { bubbles: true }));
        });
    }

    function stripEmailRequired(form) {
        form.querySelectorAll('.fcal_multi_guest_input input[type="email"][required]').forEach(function (input) {
            input.removeAttribute('required');
        });
    }

    function enforceNameRequired(form) {
        form.querySelectorAll('.fcal_multi_guest_input input').forEach(function (input) {
            var type = (input.getAttribute('type') || 'text').toLowerCase();
            if (type === 'email') return;
            if (!input.hasAttribute('required')) input.setAttribute('required', '');
        });
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    function toIntList(arr) {
        return (arr || []).map(function (v) { return parseInt(v, 10); })
                          .filter(function (v) { return !isNaN(v); });
    }

    function parseAmount(text) {
        if (!text) return null;
        var digits = text.replace(/[^\d.,]/g, '');
        if (!digits) return null;
        // European notation ("1.234,56") vs US ("1,234.56"): the *last*
        // separator in the string is the decimal one.
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
})();
