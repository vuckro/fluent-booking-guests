<?php
/**
 * Admin settings page: per-event toggles for the three group-reservation
 * features.
 *
 * Registered under the FluentBooking menu.
 */

defined( 'ABSPATH' ) || exit;

add_action( 'admin_menu', 'fbgrp_register_settings_page', 20 );

function fbgrp_register_settings_page() {
    if ( ! class_exists( '\\FluentBooking\\App\\Models\\CalendarSlot' ) ) {
        return;
    }

    add_submenu_page(
        'fluent-booking',
        __( 'Réservations de groupe', 'fbgrp' ),
        __( 'Réservations de groupe', 'fbgrp' ),
        'manage_options',
        'fbgrp-settings',
        'fbgrp_render_settings_page'
    );
}

add_action( 'admin_post_fbgrp_save_settings', 'fbgrp_handle_save_settings' );

function fbgrp_handle_save_settings() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( esc_html__( 'Accès refusé.', 'fbgrp' ) );
    }

    check_admin_referer( 'fbgrp_save_settings', 'fbgrp_nonce' );

    if ( ! class_exists( '\\FluentBooking\\App\\Models\\CalendarSlot' ) ) {
        wp_safe_redirect( add_query_arg( [ 'page' => 'fbgrp-settings', 'error' => '1' ], admin_url( 'admin.php' ) ) );
        exit;
    }

    $submitted_events = isset( $_POST['events'] ) && is_array( $_POST['events'] ) ? wp_unslash( $_POST['events'] ) : []; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash
    $touched_ids      = isset( $_POST['touched'] ) && is_array( $_POST['touched'] ) ? array_map( 'intval', wp_unslash( $_POST['touched'] ) ) : [];

    foreach ( $touched_ids as $event_id ) {
        $event = \FluentBooking\App\Models\CalendarSlot::find( $event_id );
        if ( ! $event ) {
            continue;
        }

        $flags = $submitted_events[ $event_id ] ?? [];

        $event->settings = [
            'fbgrp_one_per_spot'     => ! empty( $flags['spot_per_guest'] ),
            'fbgrp_price_per_guest'  => ! empty( $flags['price_per_person'] ),
            'fbgrp_hide_guest_email' => ! empty( $flags['hide_guest_email'] ),
        ];
        $event->save();
    }

    wp_safe_redirect( add_query_arg( [ 'page' => 'fbgrp-settings', 'saved' => '1' ], admin_url( 'admin.php' ) ) );
    exit;
}

function fbgrp_render_settings_styles() {
    ?>
    <style>
        .fbgrp-wrap { max-width: 1200px; }
        .fbgrp-hero {
            background: #fff;
            color: #1d2327;
            border: 1px solid #dcdcde;
            border-radius: 10px;
            padding: 22px 24px;
            margin: 16px 0 24px;
        }
        .fbgrp-hero h1 {
            color: #1d2327;
            margin: 0 0 4px;
            font-size: 20px;
            font-weight: 600;
            padding: 0;
            line-height: 1.3;
        }
        .fbgrp-hero p {
            margin: 0;
            color: #50575e;
            font-size: 13px;
            max-width: 720px;
            line-height: 1.5;
        }
        .fbgrp-features {
            display: grid; grid-template-columns: repeat(3, 1fr); gap: 16px; margin-bottom: 28px;
        }
        .fbgrp-feature {
            background: #fff; border: 1px solid #dcdcde; border-radius: 10px; padding: 18px 20px;
            display: flex; gap: 14px; align-items: flex-start;
        }
        .fbgrp-feature .fbgrp-icon {
            flex: 0 0 40px; height: 40px; border-radius: 8px;
            display: flex; align-items: center; justify-content: center;
            font-size: 22px; color: #fff;
        }
        .fbgrp-feature.fbgrp-feature-seat  .fbgrp-icon { background: #2563eb; }
        .fbgrp-feature.fbgrp-feature-price .fbgrp-icon { background: #7c3aed; }
        .fbgrp-feature.fbgrp-feature-email .fbgrp-icon { background: #0a7c5a; }
        .fbgrp-feature h3 { margin: 0 0 4px; font-size: 14px; font-weight: 600; }
        .fbgrp-feature p  { margin: 0; color: #50575e; font-size: 13px; line-height: 1.5; }

        .fbgrp-calendar-card {
            background: #fff; border: 1px solid #dcdcde; border-radius: 10px;
            margin-bottom: 20px; overflow: hidden;
        }
        .fbgrp-calendar-head {
            display: flex; align-items: center; gap: 12px;
            padding: 14px 20px; background: #f6f7f7; border-bottom: 1px solid #dcdcde;
        }
        .fbgrp-calendar-head .dashicons { color: #2271b1; font-size: 20px; width: 20px; height: 20px; }
        .fbgrp-calendar-head h2 { margin: 0; font-size: 15px; font-weight: 600; }
        .fbgrp-calendar-head .fbgrp-id-chip {
            font-size: 11px; color: #50575e; background: #fff;
            border: 1px solid #dcdcde; padding: 2px 8px; border-radius: 20px;
            font-weight: 500;
        }

        .fbgrp-event-row {
            display: grid;
            grid-template-columns: 1fr 200px 200px 220px;
            align-items: center; gap: 16px;
            padding: 16px 20px; border-bottom: 1px solid #f0f0f1;
        }
        .fbgrp-event-row:last-child { border-bottom: 0; }
        .fbgrp-event-row:hover { background: #fafafa; }

        .fbgrp-event-meta .fbgrp-event-title {
            font-weight: 600; font-size: 14px; color: #1d2327; text-decoration: none;
        }
        .fbgrp-event-meta .fbgrp-event-title:hover { color: #2271b1; }
        .fbgrp-event-meta .fbgrp-event-sub {
            display: flex; flex-wrap: wrap; gap: 6px; margin-top: 6px; align-items: center;
        }
        .fbgrp-chip {
            font-size: 11px; color: #50575e; background: #f0f0f1;
            padding: 2px 8px; border-radius: 20px; font-weight: 500;
        }
        .fbgrp-chip.fbgrp-chip-warn { background: #fcf0e3; color: #8a4b00; }

        /* Toggle switch */
        .fbgrp-toggle { position: relative; display: inline-block; vertical-align: middle; }
        .fbgrp-toggle input { opacity: 0; width: 0; height: 0; position: absolute; }
        .fbgrp-toggle .fbgrp-slider {
            position: relative; display: inline-block; width: 44px; height: 24px;
            background: #c7c7cc; border-radius: 24px; transition: background .18s;
            vertical-align: middle;
        }
        .fbgrp-toggle .fbgrp-slider::before {
            content: ""; position: absolute; left: 3px; top: 3px;
            width: 18px; height: 18px; background: #fff; border-radius: 50%;
            box-shadow: 0 1px 3px rgba(0,0,0,.2); transition: transform .18s;
        }
        .fbgrp-toggle input:checked + .fbgrp-slider { background: #2563eb; }
        .fbgrp-toggle input:checked + .fbgrp-slider::before { transform: translateX(20px); }
        .fbgrp-toggle input:focus-visible + .fbgrp-slider { outline: 2px solid #2271b1; outline-offset: 2px; }
        .fbgrp-toggle-label {
            display: flex; align-items: center; gap: 10px; cursor: pointer;
            font-size: 13px; color: #50575e;
        }
        .fbgrp-toggle-label .fbgrp-toggle-text { flex: 1; line-height: 1.3; }
        .fbgrp-toggle input:checked ~ .fbgrp-toggle-text { color: #1d2327; font-weight: 600; }

        .fbgrp-actions { margin-top: 24px; display: flex; gap: 10px; align-items: center; }
        .fbgrp-actions .button-primary { padding: 8px 22px; height: auto; font-size: 14px; }

        @media (max-width: 1040px) {
            .fbgrp-features { grid-template-columns: 1fr; }
            .fbgrp-event-row { grid-template-columns: 1fr; gap: 10px; }
        }
    </style>
    <?php
}

function fbgrp_render_settings_page() {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }

    fbgrp_render_settings_styles();

    echo '<div class="wrap fbgrp-wrap">';

    if ( ! class_exists( '\\FluentBooking\\App\\Models\\Calendar' ) || ! class_exists( '\\FluentBooking\\App\\Models\\CalendarSlot' ) ) {
        echo '<h1>' . esc_html__( 'Réservations de groupe', 'fbgrp' ) . '</h1>';
        echo '<div class="notice notice-error"><p>' . esc_html__( 'Fluent Booking est requis.', 'fbgrp' ) . '</p></div></div>';
        return;
    }

    // Hero
    echo '<div class="fbgrp-hero">';
    echo '<h1>' . esc_html__( 'Réservations de groupe', 'fbgrp' ) . '</h1>';
    echo '<p>' . esc_html__( 'Réglages par événement pour les réservations de groupe. Tout est désactivé par défaut.', 'fbgrp' ) . '</p>';
    echo '</div>';

    // Notice
    if ( ! empty( $_GET['saved'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Réglages enregistrés.', 'fbgrp' ) . '</p></div>';
    }

    // Feature explainers
    $features = [
        [
            'class' => 'fbgrp-feature-seat',
            'icon'  => 'dashicons-groups',
            'title' => __( 'Une place par invité', 'fbgrp' ),
            'desc'  => __( 'Chaque invité ajouté crée sa propre réservation et décompte une place du stock disponible.', 'fbgrp' ),
        ],
        [
            'class' => 'fbgrp-feature-price',
            'icon'  => 'dashicons-money-alt',
            'title' => __( 'Prix par personne', 'fbgrp' ),
            'desc'  => __( 'Le total affiché sur le formulaire est recalculé : prix unitaire × nombre d\'invités.', 'fbgrp' ),
        ],
        [
            'class' => 'fbgrp-feature-email',
            'icon'  => 'dashicons-hidden',
            'title' => __( 'Masquer l\'email des invités', 'fbgrp' ),
            'desc'  => __( 'Cache le champ email des invités ; une adresse est générée automatiquement côté serveur pour ne rien perdre.', 'fbgrp' ),
        ],
    ];

    echo '<div class="fbgrp-features">';
    foreach ( $features as $f ) {
        echo '<div class="fbgrp-feature ' . esc_attr( $f['class'] ) . '">';
        echo '<div class="fbgrp-icon"><span class="dashicons ' . esc_attr( $f['icon'] ) . '"></span></div>';
        echo '<div><h3>' . esc_html( $f['title'] ) . '</h3>';
        echo '<p>' . esc_html( $f['desc'] ) . '</p></div>';
        echo '</div>';
    }
    echo '</div>';

    // Form + calendars
    echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
    echo '<input type="hidden" name="action" value="fbgrp_save_settings">';
    wp_nonce_field( 'fbgrp_save_settings', 'fbgrp_nonce' );

    $calendars = \FluentBooking\App\Models\Calendar::query()->orderBy( 'id', 'asc' )->get();
    $has_any   = false;

    $toggles = [
        'spot_per_guest'   => __( 'Une place par invité', 'fbgrp' ),
        'price_per_person' => __( 'Prix par personne', 'fbgrp' ),
        'hide_guest_email' => __( 'Masquer l\'email', 'fbgrp' ),
    ];

    foreach ( $calendars as $calendar ) {
        $events = \FluentBooking\App\Models\CalendarSlot::where( 'calendar_id', $calendar->id )
            ->orderBy( 'id', 'asc' )
            ->get();

        if ( $events->isEmpty() ) {
            continue;
        }

        $has_any = true;

        $calendar_id    = (int) $calendar->id;
        $calendar_title = $calendar->title ? $calendar->title : sprintf( __( 'Calendrier #%d', 'fbgrp' ), $calendar_id );

        echo '<div class="fbgrp-calendar-card">';
        echo '<div class="fbgrp-calendar-head">';
        echo '<span class="dashicons dashicons-calendar-alt"></span>';
        echo '<h2>' . esc_html( $calendar_title ) . '</h2>';
        echo '<span class="fbgrp-id-chip">ID ' . $calendar_id . '</span>';
        echo '</div>';

        foreach ( $events as $event ) {
            $s      = fbgrp_slot_settings_array( $event );
            $values = [
                'spot_per_guest'   => ! empty( $s['fbgrp_one_per_spot'] ),
                'price_per_person' => ! empty( $s['fbgrp_price_per_guest'] ),
                'hide_guest_email' => ! empty( $s['fbgrp_hide_guest_email'] ),
            ];

            $event_id = (int) $event->id;

            $event_admin_url = admin_url(
                sprintf(
                    'admin.php?page=fluent-booking#/calendars/%d/slot-settings/%d/event-details',
                    $calendar_id,
                    $event_id
                )
            );

            $title = $event->title ?: sprintf( __( 'Événement #%d', 'fbgrp' ), $event_id );

            echo '<div class="fbgrp-event-row">';

            echo '<div class="fbgrp-event-meta">';
            echo '<a class="fbgrp-event-title" href="' . esc_url( $event_admin_url ) . '">' . esc_html( $title ) . '</a>';
            echo '<div class="fbgrp-event-sub">';
            echo '<span class="fbgrp-chip">' . esc_html( sprintf( __( 'Calendrier #%d', 'fbgrp' ), $calendar_id ) ) . '</span>';
            echo '<span class="fbgrp-chip">' . esc_html( sprintf( __( 'Événement #%d', 'fbgrp' ), $event_id ) ) . '</span>';
            if ( $event->status && $event->status !== 'active' ) {
                echo '<span class="fbgrp-chip fbgrp-chip-warn">' . esc_html( $event->status ) . '</span>';
            }
            echo '</div>';
            echo '</div>';

            foreach ( $toggles as $key => $label ) {
                $checked = $values[ $key ];
                echo '<label class="fbgrp-toggle-label">';
                echo '<span class="fbgrp-toggle">';
                echo '<input type="checkbox" name="events[' . $event_id . '][' . esc_attr( $key ) . ']" value="1" ' . checked( $checked, true, false ) . '>';
                echo '<span class="fbgrp-slider"></span>';
                echo '</span>';
                echo '<span class="fbgrp-toggle-text">' . esc_html( $label ) . '</span>';
                echo '</label>';
            }

            echo '<input type="hidden" name="touched[]" value="' . $event_id . '">';
            echo '</div>';
        }

        echo '</div>'; // .fbgrp-calendar-card
    }

    if ( ! $has_any ) {
        echo '<div class="notice notice-warning"><p>' . esc_html__( 'Aucun calendrier ni événement trouvé.', 'fbgrp' ) . '</p></div>';
    } else {
        echo '<div class="fbgrp-actions">';
        submit_button( __( 'Enregistrer les réglages', 'fbgrp' ), 'primary', 'submit', false );
        echo '</div>';
    }

    echo '</form>';
    echo '</div>';
}
