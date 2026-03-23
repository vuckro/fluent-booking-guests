<?php
/**
 * Plugin Name:  FluentBooking - Group Reservation Pricing
 * Plugin URI:   https://github.com/vuckro/fluent-booking-guests
 * Description:  Multiplies the payment total by the number of guests, ensures every additional guest has a valid email so the backend creates one booking (and deducts one spot) per guest.
 * Version:      2.2.0
 * Author:       WaasKit
 * Author URI:   https://waaskit.com
 * Requires PHP: 7.4
 * Requires at least: 6.0
 */

defined( 'ABSPATH' ) || exit;

define( 'FBGRP_VERSION', '2.2.0' );
define( 'FBGRP_URL', plugin_dir_url( __FILE__ ) );

// ---------------------------------------------------------------------------
// Frontend script (disabled on native FluentBooking landing pages)
// ---------------------------------------------------------------------------

add_action( 'wp_enqueue_scripts', function () {
    if ( isset( $_GET['fluent-booking'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        return;
    }

    wp_enqueue_script(
        'fbgrp-pricing',
        FBGRP_URL . 'assets/js/booking-pricing.js',
        [],
        FBGRP_VERSION,
        true
    );
} );

// ---------------------------------------------------------------------------
// Backend: fill missing guest emails BEFORE FluentBooking filters them out
// ---------------------------------------------------------------------------

/**
 * FluentBooking's BookingController filters additional guests with:
 *   array_filter($guests, fn($g) => $g['name'] && $g['email'])
 *
 * If a guest row has a name but no email, the backend silently drops it,
 * resulting in fewer bookings created and fewer spots deducted.
 *
 * We hook into the same AJAX action at priority 1 (FluentBooking uses 10)
 * and patch $_REQUEST['guests'] so every guest with a name gets an email
 * derived from the main booker's address.
 */
function fbgrp_fill_guest_emails() {
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
        $name  = trim( $guest['name']  ?? '' );
        $gMail = trim( $guest['email'] ?? '' );

        // Skip guests with no name (they'll be filtered out by FluentBooking anyway).
        if ( ! $name ) {
            continue;
        }

        // Fill email if missing: booker+guest1@domain.com
        if ( ! $gMail ) {
            $at    = strpos( $email, '@' );
            $local = explode( '+', substr( $email, 0, $at ) )[0];
            $domain = substr( $email, $at + 1 );
            $guest['email'] = $local . '+guest' . ( $i + 1 ) . '@' . $domain;
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
// Backend safety: ensure quantity equals guest count
// ---------------------------------------------------------------------------

add_filter( 'fluent_booking/booking_data', function ( $data, $slot ) {
    if ( ! is_object( $slot ) || ! method_exists( $slot, 'isMultiGuestEvent' ) || ! $slot->isMultiGuestEvent() ) {
        return $data;
    }

    $emails = $data['email'] ?? null;

    if ( is_array( $emails ) && count( $emails ) > 1 && empty( $data['quantity'] ) ) {
        $data['quantity'] = count( $emails );
    }

    return $data;
}, 20, 2 );
