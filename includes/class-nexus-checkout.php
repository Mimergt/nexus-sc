<?php
/**
 * Nexus_Checkout
 *
 * Captures the `?account_id=` query parameter from the URL (set by the GHL subscription
 * page button link) and persists it in the WooCommerce session so it survives the checkout
 * flow and can be associated with the subscription once it is created.
 *
 * @package NexusSubscriptionController
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Nexus_Checkout {

    /** WC session key used to carry the pending GHL location ID through checkout. */
    const SESSION_KEY = 'nexus_pending_location_id';

    public static function init() {
        // Capture ?account_id from every page load and store in WC session.
        add_action( 'wp_loaded', array( __CLASS__, 'capture_account_id' ), 5 );

        // When WC redirects after "add to cart", re-append the param so it
        // appears on the checkout page too (helps with some themes).
        add_filter( 'woocommerce_add_to_cart_redirect', array( __CLASS__, 'preserve_account_id_in_redirect' ) );
    }

    /**
     * Read ?account_id from the current request and store in WC session.
     *
     * Validation: GHL locationIds are alphanumeric strings, typically 20 chars.
     * We allow 5-60 chars to give headroom without being dangerously permissive.
     */
    public static function capture_account_id() {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if ( empty( $_GET['account_id'] ) ) {
            return;
        }

        if ( ! function_exists( 'WC' ) || ! WC()->session ) {
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $raw = sanitize_text_field( wp_unslash( $_GET['account_id'] ) );

        // Strict allowlist: alphanumeric + hyphen/underscore, 5-60 chars.
        if ( ! preg_match( '/^[a-zA-Z0-9_\-]{5,60}$/', $raw ) ) {
            return;
        }

        WC()->session->set( self::SESSION_KEY, $raw );
    }

    /**
     * After an "add to cart" redirect, re-attach the account_id to the redirect URL
     * so that it is captured again on the resulting page load.
     *
     * @param  string $url Redirect URL produced by WooCommerce.
     * @return string
     */
    public static function preserve_account_id_in_redirect( $url ) {
        if ( ! function_exists( 'WC' ) || ! WC()->session ) {
            return $url;
        }

        $account_id = WC()->session->get( self::SESSION_KEY );
        if ( $account_id ) {
            $url = add_query_arg( 'account_id', rawurlencode( $account_id ), $url );
        }

        return $url;
    }

    /**
     * Return the location ID stored in the WC session, or empty string if none.
     *
     * @return string
     */
    public static function get_pending_location_id() {
        if ( ! function_exists( 'WC' ) || ! WC()->session ) {
            return '';
        }

        return (string) WC()->session->get( self::SESSION_KEY, '' );
    }

    /**
     * Clear the pending location ID from the session (called after it is saved to the subscription).
     */
    public static function clear_pending_location_id() {
        if ( function_exists( 'WC' ) && WC()->session ) {
            WC()->session->__unset( self::SESSION_KEY );
        }
    }
}
