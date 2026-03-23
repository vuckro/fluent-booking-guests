<?php
/**
 * Plugin Name:  WaasKit – FluentBooking Group Reservation Pricing
 * Plugin URI:   https://github.com/vuckro/fluent-booking-guests
 * Description:  Dynamically updates the reservation summary table as guests are added, auto-fills placeholder e-mails so every guest row is counted by the backend, and ensures the correct quantity reaches the payment order.
 * Version:      1.1.0
 * Author:       WaasKit
 * Author URI:   https://waaskit.com
 * Text Domain:  waaskit-fb-guests
 * Requires PHP: 7.4
 * Requires at least: 6.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'WAASKIT_FB_GUESTS_VERSION', '1.1.0' );
define( 'WAASKIT_FB_GUESTS_FILE',    __FILE__ );
define( 'WAASKIT_FB_GUESTS_DIR',     plugin_dir_path( __FILE__ ) );
define( 'WAASKIT_FB_GUESTS_URL',     plugin_dir_url( __FILE__ ) );

// ---------------------------------------------------------------------------
// Frontend asset
// ---------------------------------------------------------------------------

/**
 * Enqueue the pricing updater script on every public page.
 *
 * FluentBooking embeds the calendar/booking widget dynamically via a
 * shortcode or block, so we register the script site-wide rather than
 * attempting unreliable page-level detection.
 */
add_action( 'wp_enqueue_scripts', 'waaskit_fb_guests_enqueue_scripts' );

function waaskit_fb_guests_enqueue_scripts() {
    wp_enqueue_script(
        'waaskit-fb-guests',
        WAASKIT_FB_GUESTS_URL . 'assets/js/booking-pricing.js',
        [],                          // no dependencies
        WAASKIT_FB_GUESTS_VERSION,
        true                         // load in footer
    );
}

// ---------------------------------------------------------------------------
// Backend safety filter
// ---------------------------------------------------------------------------

/**
 * Ensure the booking order quantity always equals the total number of guests
 * (main booker + additional guests) for multi-guest FluentBooking events.
 *
 * FluentBooking Pro already sets `quantity = totalGuests` inside
 * `BookingService::createMultiGuestBooking()` for the parent booking when a
 * `payment_method` is present. This filter acts as a safety net for edge
 * cases (e.g. custom payment flows that fire `fluent_booking/booking_data`
 * before the quantity is set by the core).
 *
 * @param  array       $bookingData      Fillable booking data array.
 * @param  object      $calendarSlot     CalendarSlot model instance.
 * @param  array       $customFieldsData Custom form field values.
 * @param  array       $originalData     Data after prepareBookingData(); for
 *                                       multi-guest events `email` is an array.
 * @return array
 */
add_filter( 'fluent_booking/booking_data', 'waaskit_fb_guests_set_quantity', 20, 4 );

function waaskit_fb_guests_set_quantity( $bookingData, $calendarSlot, $customFieldsData, $originalData ) {
    // Guard: only apply to multi-guest event types (group / group_event).
    if (
        ! is_object( $calendarSlot )
        || ! method_exists( $calendarSlot, 'isMultiGuestEvent' )
        || ! $calendarSlot->isMultiGuestEvent()
    ) {
        return $bookingData;
    }

    // After `prepareBookingData()`, additional guests are merged into the
    // `email` field as an array (one entry per guest including the main booker).
    $emails = $bookingData['email'] ?? null;

    if ( ! is_array( $emails ) || count( $emails ) <= 1 ) {
        return $bookingData;
    }

    // Set quantity only when not already present; the core will override it
    // for the last (parent) booking inside createMultiGuestBooking().
    if ( empty( $bookingData['quantity'] ) ) {
        $bookingData['quantity'] = count( $emails );
    }

    return $bookingData;
}
