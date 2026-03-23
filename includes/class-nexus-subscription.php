<?php
/**
 * Nexus_Subscription
 *
 * Hooks into WooCommerce Subscriptions to:
 *  1. Associate the GHL location_id (from the WC session) with the subscription at creation time.
 *  2. Validate that the same location_id does not already have an active subscription (prevents duplicates).
 *  3. Sync the subscription's active/inactive state to the Cloudflare bridge via a REST call.
 *
 * @package NexusSubscriptionController
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Nexus_Subscription {

    /**
     * Post-meta / order-meta key that stores the GHL location (sub-account) ID.
     */
    const META_KEY = '_nexus_ghl_location_id';

    public static function init() {
        // ── Association ──────────────────────────────────────────────────────
        // Called by WC Subscriptions right after the subscription post is created.
        add_action( 'woocommerce_checkout_subscription_created', array( __CLASS__, 'save_location_id' ), 10, 3 );

        // ── Duplicate guard ─────────────────────────────────────────────────
        // Runs during checkout validation, before the order/subscription is created.
        add_action( 'woocommerce_checkout_process', array( __CLASS__, 'validate_no_duplicate' ) );

        // ── Bridge sync – activation ─────────────────────────────────────────
        add_action( 'woocommerce_subscription_status_active', array( __CLASS__, 'on_activated' ), 10, 1 );

        // ── Bridge sync – deactivation ───────────────────────────────────────
        add_action( 'woocommerce_subscription_status_cancelled', array( __CLASS__, 'on_deactivated' ), 10, 1 );
        add_action( 'woocommerce_subscription_status_expired',   array( __CLASS__, 'on_deactivated' ), 10, 1 );
        add_action( 'woocommerce_subscription_status_on-hold',   array( __CLASS__, 'on_deactivated' ), 10, 1 );
        add_action( 'woocommerce_subscription_status_pending',   array( __CLASS__, 'on_deactivated' ), 10, 1 );
    }

    // ────────────────────────────────────────────────────────────────────────
    // 1. Association
    // ────────────────────────────────────────────────────────────────────────

    /**
     * Save the GHL location ID from the WC session into the newly-created subscription (and its parent order).
     *
     * @param WC_Subscription $subscription  The freshly created subscription object.
     * @param WC_Order        $order         The parent checkout order.
     * @param array           $recurring_cart (unused)
     */
    public static function save_location_id( $subscription, $order, $recurring_cart ) {
        $location_id = Nexus_Checkout::get_pending_location_id();

        if ( empty( $location_id ) ) {
            return;
        }

        $clean = sanitize_text_field( $location_id );

        // Store on the subscription.
        $subscription->update_meta_data( self::META_KEY, $clean );
        $subscription->save();

        // Mirror on the parent order for reference in order listings.
        $order->update_meta_data( self::META_KEY, $clean );
        $order->save();

        // Clear from session – the value has been persisted.
        Nexus_Checkout::clear_pending_location_id();
    }

    // ────────────────────────────────────────────────────────────────────────
    // 2. Duplicate guard
    // ────────────────────────────────────────────────────────────────────────

    /**
     * Block checkout if the same GHL location_id already has an active or pending-cancel subscription.
     * Only fires when there IS a location_id in the session (regular purchases without one are not affected).
     */
    public static function validate_no_duplicate() {
        $location_id = Nexus_Checkout::get_pending_location_id();

        if ( empty( $location_id ) ) {
            return;
        }

        if ( self::location_has_active_subscription( $location_id ) ) {
            wc_add_notice(
                esc_html__( 'Esta cuenta de GoHighLevel ya tiene una suscripción activa. Si crees que esto es un error, por favor contáctanos.', 'nexus-sc' ),
                'error'
            );
        }
    }

    // ────────────────────────────────────────────────────────────────────────
    // 3. Bridge sync
    // ────────────────────────────────────────────────────────────────────────

    /**
     * Called when a subscription transitions to "active".
     *
     * @param WC_Subscription $subscription
     */
    public static function on_activated( $subscription ) {
        $location_id = $subscription->get_meta( self::META_KEY );
        if ( ! empty( $location_id ) ) {
            self::sync_to_bridge( $location_id, true );
        }
    }

    /**
     * Called when a subscription transitions to a non-active state.
     *
     * @param WC_Subscription $subscription
     */
    public static function on_deactivated( $subscription ) {
        $location_id = $subscription->get_meta( self::META_KEY );
        if ( ! empty( $location_id ) ) {
            self::sync_to_bridge( $location_id, false );
        }
    }

    /**
     * Send active/inactive status to the Cloudflare bridge.
     * Uses NSC_BRIDGE_URL and NSC_BRIDGE_API_KEY constants (set in wp-config.php).
     * Fires-and-forgets (non-blocking for the checkout flow; errors are silently logged).
     *
     * @param string $location_id GHL location (sub-account) ID.
     * @param bool   $active      Whether the subscription is now active.
     */
    public static function sync_to_bridge( $location_id, $active ) {
        $bridge_url = defined( 'NSC_BRIDGE_URL' ) ? rtrim( NSC_BRIDGE_URL, '/' ) : '';
        $api_key    = defined( 'NSC_BRIDGE_API_KEY' ) ? NSC_BRIDGE_API_KEY : '';

        if ( empty( $bridge_url ) || empty( $api_key ) ) {
            return; // Bridge not configured – skip silently.
        }

        $response = wp_remote_post(
            $bridge_url . '/api/tenant-status',
            array(
                'headers' => array(
                    'Content-Type'  => 'application/json',
                    'X-NSC-API-Key' => $api_key,
                ),
                'body'    => wp_json_encode( array(
                    'locationId' => $location_id,
                    'active'     => (bool) $active,
                ) ),
                'timeout'   => 10,
                'blocking'  => false, // fire-and-forget
            )
        );

        if ( is_wp_error( $response ) ) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
            error_log( '[nexus-sc] sync_to_bridge error: ' . $response->get_error_message() );
        }
    }

    // ────────────────────────────────────────────────────────────────────────
    // Helpers (used by REST API and Admin)
    // ────────────────────────────────────────────────────────────────────────

    /**
     * Check whether a given location_id already has a subscription in an active or pending-cancel state.
     *
     * @param  string $location_id
     * @return bool
     */
    public static function location_has_active_subscription( $location_id ) {
        $results = wcs_get_subscriptions( array(
            'subscription_status'    => array( 'active', 'pending-cancel' ),
            'subscriptions_per_page' => 1,
            'meta_query'             => array(
                array(
                    'key'   => self::META_KEY,
                    'value' => sanitize_text_field( $location_id ),
                ),
            ),
        ) );

        return ! empty( $results );
    }

    /**
     * Return the most recent subscription (any status) for the given location_id, or null.
     *
     * @param  string $location_id
     * @return WC_Subscription|null
     */
    public static function get_subscription_for_location( $location_id ) {
        $results = wcs_get_subscriptions( array(
            'subscription_status'    => 'any',
            'subscriptions_per_page' => 1,
            'meta_query'             => array(
                array(
                    'key'   => self::META_KEY,
                    'value' => sanitize_text_field( $location_id ),
                ),
            ),
        ) );

        return empty( $results ) ? null : reset( $results );
    }
}
