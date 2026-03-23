# FluentBooking - Group Reservation Pricing

A lightweight WordPress plugin that makes **FluentBooking group events** bill and deduct spots correctly when additional guests are added to a booking.

## The problem

FluentBooking's booking form renders a static price table (e.g. *Quantity: 1 / 15,00 CHF*) that never updates as guests are added via the **"Add another"** button. A user could add guests without the price changing, effectively booking extra spots at the single-person rate.

## What this plugin does

| Layer | Behaviour |
|---|---|
| **Frontend JS** | Watches the form for guest row additions/removals and updates *Quantity*, *Price* and *Total* in real time. |
| **Email pre-fill** | Pre-fills each additional guest's email field with the main booker's email, so the price updates as soon as the guest name is entered. |
| **Validation** | Marks name and email fields as `required` on every additional guest row, preventing submission with incomplete guest data. |
| **Backend PHP** | Hooks into `fluent_booking/booking_data` to guarantee `quantity` equals the total guest count before payment. |

### Example

| Guests | Quantity | Price |
|---|---|---|
| Main booker only | 1 | 15,00 CHF |
| + 1 guest | 2 | 30,00 CHF |
| + 2 guests | 3 | 45,00 CHF |

## Important: native landing pages

This plugin is **not designed for FluentBooking's native landing pages** (`?fluent-booking=calendar`). The script is automatically disabled on those pages. It only works when the booking widget is embedded via shortcode or block on a regular WordPress page.

## Requirements

- WordPress 6.0+
- PHP 7.4+
- [FluentBooking](https://fluentbooking.com/) + FluentBooking Pro
- A **group** or **group_event** calendar slot with per-person pricing

## Installation

1. Upload the `cooms-fluent-multireservation` folder to `/wp-content/plugins/`.
2. Activate via **Plugins > Installed Plugins**.
3. No configuration needed — works automatically on all pages with an embedded FluentBooking widget.

## How it works

### Frontend (`assets/js/booking-pricing.js`)

A `MutationObserver` watches every `.fcal_booking_form_wrap` element. On any child-list change (guest added/removed, form re-rendered), it:

1. Counts guest rows (`.fcal_multi_guest_input`) and computes `totalGuests = 1 + rows`.
2. Updates each `<tbody>` item row: sets the quantity cell and recalculates `unitPrice x totalGuests`.
3. Updates the `<tfoot>` total via the `.fcal_payment_amount` span.
4. Pre-fills empty guest email fields with the main booker's email.
5. Sets `required` on all inputs inside guest rows.

Price parsing handles multiple locale formats (`15,00 CHF`, `$15.00`, `1.234,56`).

### Backend (`cooms-fluent-multireservation.php`)

- Skips loading the script on FluentBooking native landing pages (`?fluent-booking` query parameter).
- The `fluent_booking/booking_data` filter (priority 20) sets `$bookingData['quantity']` to `count($bookingData['email'])` for multi-guest events when the core hasn't set it yet.

### Spot deduction

No extra code needed. FluentBooking creates one booking record per guest for group events (sharing a `group_id`), so each guest deducts one spot automatically.

## Changelog

### 2.1.0
- Added: pre-fill guest email from the main booker's email field.
- Added: script disabled on FluentBooking native landing pages.
- Removed: "/ personne" label feature.
- Simplified: extracted `nativeSetter` once, inlined `refresh` callback.

### 2.0.0
- Rewritten: simplified codebase (~100 lines JS, ~50 lines PHP).
- Fixed: total row not updating (was looking for `<td>` instead of `<th>` / `.fcal_payment_amount`).
- Added: name and email fields marked `required` on additional guest rows.

### 1.1.0
- Added: automatic placeholder email injection on new guest rows.
- Added: immediate table update on guest row addition.

### 1.0.0
- Initial release.

## License

GPL-2.0-or-later
