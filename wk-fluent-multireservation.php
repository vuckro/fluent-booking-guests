<?php
/**
 * Plugin Name:  FluentBooking - Group Reservation
 * Plugin URI:   https://github.com/vuckro/fluent-booking-guests
 * Description:  Per-event toggles for FluentBooking group events: spot-per-guest, per-person pricing, hide guest emails.
 * Version:      3.3.0
 * Requires PHP: 7.4
 * Requires at least: 6.0
 * License:      GPL-2.0-or-later
 */

defined( 'ABSPATH' ) || exit;

define( 'FBGRP_VERSION', '3.3.0' );
define( 'FBGRP_URL', plugin_dir_url( __FILE__ ) );
define( 'FBGRP_PATH', plugin_dir_path( __FILE__ ) );

// ---------------------------------------------------------------------------
// Per-event settings helpers
// ---------------------------------------------------------------------------

/**
 * Normalise the `settings` JSON of a CalendarSlot into a plain array.
 */
function fbgrp_slot_settings_array( $slot ) {
    if ( ! $slot ) {
        return [];
    }
    $s = $slot->settings;
    if ( is_string( $s ) ) {
        $s = maybe_unserialize( $s );
    }
    return is_array( $s ) ? $s : [];
}

/**
 * Read the per-event flags for the given event id. Returns null if the
 * event doesn't exist or FluentBooking isn't loaded.
 */
function fbgrp_get_event_flags( $event_id ) {
    $event_id = (int) $event_id;
    if ( ! $event_id || ! class_exists( '\\FluentBooking\\App\\Models\\CalendarSlot' ) ) {
        return null;
    }
    $event = \FluentBooking\App\Models\CalendarSlot::find( $event_id );
    if ( ! $event ) {
        return null;
    }
    $s = fbgrp_slot_settings_array( $event );
    return [
        'spot_per_guest'   => ! empty( $s['fbgrp_one_per_spot'] ),
        'price_per_person' => ! empty( $s['fbgrp_price_per_guest'] ),
        'hide_guest_email' => ! empty( $s['fbgrp_hide_guest_email'] ),
    ];
}

/**
 * Return the list of event IDs that have a given flag key enabled.
 *
 * @param string $flag One of the values returned by fbgrp_flag_keys().
 * @return int[]
 */
function fbgrp_get_events_with_flag( $flag ) {
    if ( ! class_exists( '\\FluentBooking\\App\\Models\\CalendarSlot' ) ) {
        return [];
    }
    $events = \FluentBooking\App\Models\CalendarSlot::query()->get();
    $ids    = [];
    foreach ( $events as $event ) {
        $s = fbgrp_slot_settings_array( $event );
        if ( ! empty( $s[ $flag ] ) ) {
            $ids[] = (int) $event->id;
        }
    }
    return $ids;
}

// ---------------------------------------------------------------------------
// Frontend: dynamic pricing JS + hide-email CSS — only when at least one
// event opts in.
// ---------------------------------------------------------------------------

add_action( 'wp_enqueue_scripts', function () {
    if ( isset( $_GET['fluent-booking'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        return;
    }

    $price_ids = fbgrp_get_events_with_flag( 'fbgrp_price_per_guest' );
    $hide_ids  = fbgrp_get_events_with_flag( 'fbgrp_hide_guest_email' );

    $needs_js = ! empty( $price_ids ) || ! empty( $hide_ids );
    if ( ! $needs_js ) {
        return;
    }

    wp_enqueue_script(
        'fbgrp-booking',
        FBGRP_URL . 'assets/js/booking-pricing.js',
        [],
        FBGRP_VERSION,
        true
    );

    wp_localize_script(
        'fbgrp-booking',
        'fbgrpConfig',
        [
            'priceEvents'     => array_values( array_map( 'intval', $price_ids ) ),
            'hideEmailEvents' => array_values( array_map( 'intval', $hide_ids ) ),
        ]
    );

    if ( ! empty( $hide_ids ) ) {
        wp_enqueue_style(
            'fbgrp-hide-email',
            FBGRP_URL . 'assets/css/hide-guest-email.css',
            [],
            FBGRP_VERSION
        );
    }
} );

// ---------------------------------------------------------------------------
// Backend: fill missing guest emails.
//
// Needed by BOTH "spot_per_guest" (forces one booking per guest) and
// "hide_guest_email" (UI field hidden → user can't type an email). If either
// flag is on for the target event, we patch $_REQUEST['guests'] so every
// named guest gets a generated address.
// ---------------------------------------------------------------------------

function fbgrp_fill_guest_emails() {
    $event_id = (int) ( $_REQUEST['event_id'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

    $flags = fbgrp_get_event_flags( $event_id );
    if ( ! $flags ) {
        return;
    }

    if ( ! $flags['spot_per_guest'] && ! $flags['hide_guest_email'] ) {
        return;
    }

    $guests = $_REQUEST['guests'] ?? null; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
    $email  = $_REQUEST['email']  ?? '';   // phpcs:ignore WordPress.Security.NonceVerification.Recommended

    if ( ! is_array( $guests ) || ! $email ) {
        return;
    }

    $email = sanitize_email( $email );
    if ( ! $email ) {
        return;
    }

    $changed = false;

    foreach ( $guests as $i => &$guest ) {
        if ( ! is_array( $guest ) ) {
            continue;
        }

        if ( ! trim( $guest['name'] ?? '' ) ) {
            continue;
        }

        if ( ! trim( $guest['email'] ?? '' ) ) {
            $at     = strpos( $email, '@' );
            $local  = explode( '+', substr( $email, 0, $at ) )[0];
            $domain = substr( $email, $at + 1 );
            $guest['email'] = $local . '+Invite' . ( $i + 1 ) . '@' . $domain;
            $changed = true;
        }
    }
    unset( $guest );

    if ( $changed ) {
        $_REQUEST['guests'] = $guests;
        $_POST['guests']    = $guests;
    }
}

add_action( 'wp_ajax_fluent_cal_schedule_meeting',        'fbgrp_fill_guest_emails', 1 );
add_action( 'wp_ajax_nopriv_fluent_cal_schedule_meeting', 'fbgrp_fill_guest_emails', 1 );

// ---------------------------------------------------------------------------
// Backend: tag guest bookings — gated on `spot_per_guest` only, since this
// tag only makes sense when each guest produces its own booking record.
// ---------------------------------------------------------------------------

add_action( 'fluent_booking/after_booking_scheduled', function ( $booking, $calendarSlot ) {
    if ( ! $calendarSlot->isMultiGuestEvent() ) {
        return;
    }

    $s = fbgrp_slot_settings_array( $calendarSlot );
    if ( empty( $s['fbgrp_one_per_spot'] ) ) {
        return;
    }

    $bookerEmail = sanitize_email( $_REQUEST['email'] ?? '' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
    $bookerName  = sanitize_text_field( $_REQUEST['name'] ?? '' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

    if ( ! $bookerEmail || ! $bookerName ) {
        return;
    }

    if ( $booking->email !== $bookerEmail ) {
        $booking->internal_note = sprintf( 'Invité par %s (%s)', $bookerName, $bookerEmail );
        $booking->save();
    }
}, 20, 2 );

// ---------------------------------------------------------------------------
// Admin settings page
// ---------------------------------------------------------------------------

require_once FBGRP_PATH . 'includes/admin-page.php';
