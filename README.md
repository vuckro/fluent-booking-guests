# FluentBooking - Group Reservation Pricing

A lightweight WordPress plugin that makes **FluentBooking group events** bill and deduct spots correctly when additional guests are added to a booking.

## The problem

FluentBooking's booking form has two issues with group events:

1. **Price display**: The payment table shows a static price that never updates as guests are added.
2. **Spot deduction**: If a guest row has a name but no email, FluentBooking's backend silently discards that guest. Only one booking is created instead of one per guest, so only one spot is deducted.

## What this plugin does

| Layer | Behaviour |
|---|---|
| **Frontend JS** | Watches the form for guest additions/removals and updates *Quantity*, *Price* and *Total* in real time. |
| **Frontend JS** | Pre-fills each guest's email from the main booker's email (`booker+Invite1@domain.com`). |
| **Frontend JS** | Marks name and email as `required` on every additional guest row. |
| **Backend PHP** | Fills missing guest emails server-side as a safety net. |
| **Backend PHP** | Tags each guest booking with "Invité par {name} ({email})" in the internal note for easy back-office identification. |

### How spots are deducted

FluentBooking creates one booking record per guest (sharing a `group_id`). Each booking deducts one spot. The backend email fill ensures no guest is silently dropped:

**1 main + 3 guests = 4 bookings = 4 spots deducted**

### Example

| Guests | Quantity | Price | Spots deducted |
|---|---|---|---|
| Main booker only | 1 | 15,00 CHF | 1 |
| + 1 guest | 2 | 30,00 CHF | 2 |
| + 3 guests | 4 | 60,00 CHF | 4 |

## Important: native landing pages

This plugin is **not designed for FluentBooking's native landing pages** (`?fluent-booking=calendar`). The frontend script is automatically disabled on those pages. It only works when the booking widget is embedded via shortcode or block on a regular WordPress page.

The backend email fill still runs on all pages to prevent data loss.

## Requirements

- WordPress 6.0+
- PHP 7.4+
- [FluentBooking](https://fluentbooking.com/) + FluentBooking Pro
- A **group** or **group_event** calendar slot with per-person pricing

## Installation

1. Upload the `cooms-fluent-multireservation` folder to `/wp-content/plugins/`.
2. Activate via **Plugins > Installed Plugins**.
3. No configuration needed — works automatically.

## Architecture

### Frontend (`assets/js/booking-pricing.js`)

A `MutationObserver` watches every `.fcal_booking_form_wrap` element. On any DOM change it:

1. Counts guest rows: `totalGuests = 1 + .fcal_multi_guest_input count`.
2. Updates each payment table row: `quantity = totalGuests`, `price = unitPrice × totalGuests`.
3. Updates the total in `<tfoot> .fcal_payment_amount`.
4. Pre-fills empty guest email fields with `booker+InviteN@domain.com`.
5. Sets `required` on all guest row inputs.

### Backend (`cooms-fluent-multireservation.php`)

1. **Email fill** (`wp_ajax` priority 1): Patches `$_REQUEST['guests']` so every guest with a name gets a generated email. Prevents FluentBooking's `array_filter` from dropping guests.
2. **Guest tagging** (`fluent_booking/after_booking_scheduled`): For group bookings, compares each booking's email against the original booker. If different, writes "Invité par {name} ({email})" into the `internal_note` field for easy back-office identification.

## Changelog

### 3.1.0
- Added: "Invité par {name} ({email})" internal note on guest bookings for back-office clarity.
- Added: frontend email pre-fill (`booker+InviteN@domain.com`) so the email field can be hidden via CSS.

### 3.0.0
- Simplified: removed unnecessary `fluent_booking/booking_data` filter.
- Confirmed: 1 main + N guests = N+1 bookings = N+1 spots deducted.

### 2.2.0
- Fixed: spot deduction — fill missing guest emails server-side.

### 2.0.0
- Rewritten: simplified codebase, fixed total row, added required fields.

### 1.0.0
- Initial release.

## License

GPL-2.0-or-later
