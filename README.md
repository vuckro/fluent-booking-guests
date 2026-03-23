# FluentBooking - Group Reservation Pricing

A lightweight WordPress plugin that makes **FluentBooking group events** bill and deduct spots correctly when additional guests are added to a booking.

## The problem

FluentBooking's booking form has two issues with group events:

1. **Price display**: The payment table shows a static price that never updates as guests are added.
2. **Spot deduction**: If a guest row has a name but no email, FluentBooking's backend silently discards that guest. Only one booking is created instead of one per guest, so only one spot is deducted regardless of how many guests were added.

## What this plugin does

| Layer | Behaviour |
|---|---|
| **Frontend JS** | Watches the form for guest row additions/removals and updates *Quantity*, *Price* and *Total* in real time. |
| **Frontend JS** | Pre-fills each additional guest's email from the main booker's email. |
| **Frontend JS** | Marks name and email as `required` on every additional guest row. |
| **Backend PHP** | Fills missing guest emails server-side before FluentBooking processes the booking, ensuring every guest with a name gets a valid email (`booker+guest1@domain.com`). |
| **Backend PHP** | Ensures `quantity` equals the total guest count for payment. |

### Spot deduction

FluentBooking creates one booking record per guest (sharing a `group_id`), so each guest deducts one spot. The backend email fill ensures no guest is silently dropped, so **1 main + 2 guests = 3 bookings = 3 spots deducted**.

### Example

| Guests | Quantity | Price | Spots deducted |
|---|---|---|---|
| Main booker only | 1 | 15,00 CHF | 1 |
| + 1 guest | 2 | 30,00 CHF | 2 |
| + 2 guests | 3 | 45,00 CHF | 3 |

## Important: native landing pages

This plugin is **not designed for FluentBooking's native landing pages** (`?fluent-booking=calendar`). The frontend script is automatically disabled on those pages. It only works when the booking widget is embedded via shortcode or block on a regular WordPress page. The backend email fill still applies on all pages to prevent data loss.

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

A `MutationObserver` watches every `.fcal_booking_form_wrap` element. On any DOM change (guest added/removed), it:

1. Counts guest rows (`.fcal_multi_guest_input`) and computes `totalGuests = 1 + rows`.
2. Updates each `<tbody>` item row: quantity cell and `unitPrice x totalGuests`.
3. Updates the `<tfoot>` total via `.fcal_payment_amount`.
4. Pre-fills empty guest email fields with the main booker's email.
5. Sets `required` on all inputs inside guest rows.

### Backend (`cooms-fluent-multireservation.php`)

1. **Email fill** (`wp_ajax` priority 1): Before FluentBooking reads the POST data, patches `$_REQUEST['guests']` so every guest with a name gets an email derived from the main booker's address (`local+guestN@domain`). This prevents FluentBooking's `array_filter` from silently dropping guests.

2. **Quantity safety** (`fluent_booking/booking_data` priority 20): Sets `quantity = count(emails)` for multi-guest events when not already set by the core.

## Changelog

### 2.2.0
- Fixed: spot deduction — guests with missing emails are no longer silently dropped by the backend. The plugin now fills missing guest emails server-side before FluentBooking processes the booking.
- Documented native landing page limitation.

### 2.1.0
- Added: pre-fill guest email from the main booker's email field (frontend).
- Added: script disabled on FluentBooking native landing pages.

### 2.0.0
- Rewritten: simplified codebase.
- Fixed: total row not updating.
- Added: name and email fields marked `required` on additional guest rows.

### 1.1.0
- Added: automatic placeholder email injection on new guest rows.

### 1.0.0
- Initial release.

## License

GPL-2.0-or-later
