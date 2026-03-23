<?php
/**
 * Plugin Name:  FluentBooking - Group Reservation Pricing
 * Plugin URI:   https://github.com/vuckro/fluent-booking-guests
 * Description:  Multiplies the payment total by the number of guests and deducts one spot per guest.
 * Version:      3.0.0
 * Author:       WaasKit
 * Author URI:   https://waaskit.com
 * Requires PHP: 7.4
 * Requires at least: 6.0
 */

defined( 'ABSPATH' ) || exit;

define( 'FBGRP_VERSION', '3.0.0' );
define( 'FBGRP_URL', plugin_dir_url( __FILE__ ) );

// ---------------------------------------------------------------------------
// Frontend: dynamic pricing JS (skip native FluentBooking landing pages)
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
// Backend: fill missing guest emails so FluentBooking creates one booking
// (and deducts one spot) per guest.
//
// FluentBooking filters guests: array_filter($guests, fn($g) => $g['name'] && $g['email'])
// A guest with a name but no email is silently dropped → no booking, no spot deducted.
//
// We hook at priority 1 on the AJAX action (FluentBooking runs at priority 10)
// and patch $_REQUEST['guests'] so every named guest gets a generated email.
// ---------------------------------------------------------------------------

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
