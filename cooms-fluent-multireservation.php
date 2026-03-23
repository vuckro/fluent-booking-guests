<?php
/**
 * Plugin Name:  FluentBooking - Group Reservation Pricing
 * Plugin URI:   https://github.com/vuckro/fluent-booking-guests
 * Description:  Multiplies the payment total by the number of guests, pre-fills guest emails, enforces required fields, and ensures the correct quantity reaches the payment order.
 * Version:      2.1.0
 * Author:       WaasKit
 * Author URI:   https://waaskit.com
 * Requires PHP: 7.4
 * Requires at least: 6.0
 */

defined( 'ABSPATH' ) || exit;

define( 'FBGRP_VERSION', '2.1.0' );
define( 'FBGRP_URL', plugin_dir_url( __FILE__ ) );

add_action( 'wp_enqueue_scripts', function () {
    // Do not load on FluentBooking native landing pages (?fluent-booking=calendar).
    if ( isset( $_GET['fluent-booking'] ) ) {
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

/**
 * Ensure booking quantity equals total guests for multi-guest events.
 *
 * After prepareBookingData(), the `email` field becomes an array with one
 * entry per guest. We use its count to set the quantity when the core
 * hasn't set it yet (edge case in custom payment flows).
 */
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
