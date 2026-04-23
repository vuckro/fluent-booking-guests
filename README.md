# FluentBooking – Réservations de groupe

A lightweight WordPress plugin that adds **per-event toggles** to FluentBooking group events, so each calendar event can enable guest-friendly behaviour independently:

- Count each guest as its own booking / spot
- Multiply the displayed total by the number of guests
- Hide the email field on guest rows

Everything is **OFF by default**. You opt in from a dedicated admin page.

## The problems it solves

FluentBooking's group booking form has a few rough edges:

1. **Price display is static** — adding a guest doesn't update the total shown on the form.
2. **Spot deduction drops silent guests** — if a guest row has a name but no email, FluentBooking discards that guest, so only one booking is created and only one spot is deducted.
3. **Collecting guest emails is noisy** — you often don't need each guest's email, just the booker's.

## What you get

Three independent toggles, configured per calendar event:

| Toggle | Effect | Layer |
|---|---|---|
| **Une place par invité** | Each named guest gets an auto-generated email, creates its own booking record, and deducts one spot. Each guest booking is tagged with *Invité par {name} ({email})* in the internal note. | Backend PHP |
| **Prix par personne** | The payment summary on the form is recalculated live: `unit × (1 + guests)`. All amount lines (Acompte, Subtotal, Total…) stay in sync. | Frontend JS (display only) |
| **Masquer l'email des invités** | Hides the guest email input via CSS. A generated address (`booker+InviteN@domain.com`) is filled in automatically server-side so no data is lost. | Frontend CSS + Backend PHP |

Settings live inside each event's `settings` JSON column (`wp_fcal_calendar_events.settings`), under the keys `fbgrp_one_per_spot`, `fbgrp_price_per_guest`, `fbgrp_hide_guest_email`.

### How spots are deducted

FluentBooking creates one booking record per guest (sharing a `group_id`). Each booking deducts one spot. The backend email-fill ensures no guest is silently dropped:

**1 main booker + 3 guests = 4 bookings = 4 spots deducted**

### Example (Prix par personne)

| Guests on form | Quantity shown | Price shown |
|---|---|---|
| Main booker only | 1 | $15.00 |
| + 1 guest | 2 | $30.00 |
| + 3 guests | 4 | $60.00 |

## Known limitation — *Prix par personne* is display-only

The **Prix par personne** toggle only multiplies the numbers rendered on the booking form. It does **not** modify the amount actually charged by Stripe or stored on the order: FluentBooking's native payment flow still uses the event's configured price as the charge amount.

This is fine for events that use on-site payment or a fixed deposit ("Acompte") unrelated to guest count. Events that rely on Stripe for the full multiplied amount should use FluentBooking's native per-duration payment settings, not this toggle.

## Native landing pages

The frontend script is automatically skipped on FluentBooking's native landing page (`?fluent-booking=calendar`). The plugin is designed for booking widgets embedded via shortcode or block on a regular WordPress page. Backend email-fill still runs everywhere to prevent data loss.

## Requirements

- WordPress 6.0+
- PHP 7.4+
- [FluentBooking](https://fluentbooking.com/) + FluentBooking Pro
- A **group** / **group_event** calendar slot

## Installation

1. Clone or upload the plugin folder to `/wp-content/plugins/fluent-booking-guests/`.
2. Activate via **Plugins → Installed Plugins**.
3. Open **FluentBooking → Réservations de groupe** in the WP admin and flip the toggles for the events you need.

## Architecture

### Frontend (`assets/js/booking-pricing.js`)

Loaded only when at least one event opts in to *Prix par personne* or *Masquer l'email des invités* (controlled by `window.fbgrpConfig` — two arrays `priceEvents` and `hideEmailEvents`, populated via `wp_localize_script`).

A `MutationObserver` on each `.fcal_booking_form_wrap` triggers a debounced update that, depending on active flags:

- Rewrites every `.fcal_payment_amount` in the payment summary (line items, Subtotal, Total) using `unit × (1 + guest_count)`. The update walks *all* amount spans in the form (not only `<tfoot>`) so the final *Total:* line stays in sync even when it lives outside the items table.
- Pre-fills empty guest email fields with `booker+InviteN@domain.com`.
- Strips `required` from hidden email inputs so HTML5 validation never blocks submission.
- Enforces `required` on guest name inputs (never on email).

### Frontend (`assets/css/hide-guest-email.css`)

A minimal stylesheet loaded only when *Masquer l'email des invités* is active on at least one event. Uses the `:has()` selector to hide both the email input and its wrapping field row, with a `[type="email"]` fallback for older browsers.

### Backend (`wk-fluent-multireservation.php`)

- `fbgrp_fill_guest_emails()` on `wp_ajax_fluent_cal_schedule_meeting` priority 1 — fills missing guest emails server-side **if** the target event has either *Une place par invité* **or** *Masquer l'email des invités* enabled. This prevents FluentBooking's `array_filter` from silently dropping guests, and guarantees that hiding the email field doesn't cause data loss.
- `fluent_booking/after_booking_scheduled` priority 20 — writes *Invité par {name} ({email})* into `internal_note` for guest bookings, gated on *Une place par invité*.

### Admin (`includes/admin-page.php`)

A standalone WordPress admin page under the FluentBooking menu. Lists every calendar with its events, with a row of toggle switches per event. Saves via `admin-post.php` + nonce, writing straight into the event's `settings` JSON via FluentBooking's native model mutator (which merges keys instead of overwriting).

No integration into FluentBooking's compiled Vue admin — the bundle is not plugin-extensible without patching, so a native WP admin page was chosen for maintainability.

## File layout

```
fluent-booking-guests/
├── wk-fluent-multireservation.php   # main plugin file, hooks, helpers
├── includes/
│   └── admin-page.php               # settings UI
├── assets/
│   ├── js/booking-pricing.js        # guest-aware form behaviour
│   └── css/hide-guest-email.css     # optional email-hiding stylesheet
└── README.md
```

## Changelog

### 3.3.0
- Added **Masquer l'email des invités** toggle — hides the email field on guest rows and generates the address server-side so no data is lost.
- Renamed the admin menu to **Réservations de groupe**; feature labels are now *Une place par invité* and *Prix par personne*.
- Fixed the *Total:* line (rendered outside the items table) staying stale when guests are added — all `.fcal_payment_amount` spans in the form are now kept in sync.
- Refactored the frontend JS around a single `window.fbgrpConfig` object carrying per-feature event ID lists.
- Admin page redesigned: neutral card layout, per-event toggle switches, clickable event names linking to the FluentBooking event editor.

### 3.2.0
- Added per-event toggles via the admin page (previously global behaviour).
- Both features now **OFF by default**; must be enabled per event.
- Frontend pricing JS is only enqueued when at least one event opts in.
- The AJAX email-fill and the guest-tagging hook check the target event's flag before acting.

### 3.1.0
- Added *Invité par {name} ({email})* internal note on guest bookings.
- Added frontend email pre-fill (`booker+InviteN@domain.com`) so the email field can be hidden via CSS.

### 3.0.0
- Removed unnecessary `fluent_booking/booking_data` filter; confirmed 1 main + N guests = N+1 bookings.

### 2.2.0
- Fixed spot deduction by filling missing guest emails server-side.

### 2.0.0
- Rewritten: simplified codebase, fixed total row, added required fields.

### 1.0.0
- Initial release.

## License

GPL-2.0-or-later
