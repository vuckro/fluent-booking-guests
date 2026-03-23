# WaasKit – FluentBooking Group Reservation Pricing

A WordPress plugin that makes **FluentBooking group events** bill and deduct spots correctly when additional guests are added to a booking.

---

## The problem

FluentBooking's booking form renders a static price table (e.g. *Quantity: 1 / 15,00 CHF*) that never updates as guests are added via the **"Add another"** section. Worse, if a guest row is filled with only a name (no e-mail), the backend silently discards that guest, so the displayed quantity and the actual billed quantity can diverge.

---

## What this plugin does

| Layer | Behaviour |
|---|---|
| **Frontend JS** | Watches the form for guest row additions/removals. Updates the *Quantity* and *Price* cells — and the *Total* row — in real time. |
| **E-mail auto-fill** | When a new guest row is added and its e-mail field is empty, the script injects a `+alias` placeholder derived from the main booker's address (e.g. `parent+guest2@example.com`). This ensures FluentBooking's backend always counts the row. |
| **Backend PHP filter** | Hooks into `fluent_booking/booking_data` as a safety net to guarantee `quantity` is set to the correct guest count before the payment order is created. |

### Visual example

| Guests | Quantity | Price |
|---|---|---|
| Main booker only | 1 | 15,00 CHF |
| + 1 additional child | 2 | 30,00 CHF |
| + 2 additional children | 3 | 45,00 CHF |

---

## Requirements

- WordPress 6.0+
- PHP 7.4+
- [FluentBooking](https://fluentbooking.com/) (free) + FluentBooking Pro
- A **group** or **group_event** type calendar slot with per-person pricing enabled

---

## Installation

1. Upload the `cooms-fluent-multireservation` folder to `/wp-content/plugins/`.
2. Activate the plugin via **Plugins → Installed Plugins**.
3. No configuration needed — the plugin works automatically on all pages that contain a FluentBooking booking widget.

---

## How it works

### Frontend (`assets/js/booking-pricing.js`)

- A **`MutationObserver`** watches every `.fcal_booking_form_wrap` element.
- When a new `.fcal_multi_guest_input` row appears (user clicks *"Add another"*):
  1. `autoFillMissingEmails()` injects a `+alias` placeholder into any empty e-mail input and fires `input`/`change` events so Vue's reactive model picks up the value.
  2. `updatePaymentTable()` immediately recalculates quantity × unit price for each item row and rewrites the *Total* row.
- When a row is deleted or any other DOM change occurs, the table is updated after a 150 ms debounce.
- `isTotalRow()` identifies the *"Total:"* row by its first cell text, preventing it from being misread as a regular item row (which would corrupt the grand total).

### Backend (`cooms-fluent-multireservation.php`)

The `fluent_booking/booking_data` filter (priority 20) checks whether the event is a multi-guest type. If `$bookingData['email']` is already an array (FluentBooking converts additional guests into individual e-mail entries in `prepareBookingData()`), the filter sets `$bookingData['quantity']` to `count($emails)` when the core hasn't set it yet. FluentBooking Pro's `createMultiGuestBooking()` will override this value for the parent booking anyway — this filter only guards against edge cases in custom payment flows.

---

## Spot deduction

No extra code is needed. FluentBooking already creates one **booking record per guest** for `group` / `group_event` events (each with the same `group_id`), so each guest deducts exactly one spot from the available capacity. The *"X spots remaining"* counter on the calendar reflects this automatically.

---

## Customisation

| Need | Where to change |
|---|---|
| Change placeholder e-mail format | `buildPlaceholderEmail()` in `booking-pricing.js` |
| Disable e-mail auto-fill | Remove the `autoFillMissingEmails()` call in `initForWrapper()` |
| Target a different form class | Update the selectors at the top of `booking-pricing.js` |

---

## Changelog

### 1.1.0
- Fixed: *Total* row not updating when the guest row has only a name (no e-mail).
- Added: automatic placeholder e-mail injection on new guest rows.
- Added: immediate table update on guest row addition (no debounce delay).
- Renamed: white-labelled as **WaasKit – FluentBooking Group Reservation Pricing**.

### 1.0.0
- Initial release.

---

## License

GPL-2.0-or-later
