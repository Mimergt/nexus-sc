<?php
/**
 * Nexus_API
 *
 * Registers a lightweight REST endpoint that the Cloudflare bridge (or any trusted
 * internal system) uses to check whether a given GHL location_id has an active
 * WooCommerce Subscription.
 *
 * Endpoint:   GET /wp-json/nexus-sc/v1/check-subscription
 * Parameters:
 *   location_id  (required) – GHL location / sub-account ID to look up.
 *   key          (optional) – API key (alternative to Authorization header).
 * Headers:
 *   Authorization: Bearer <key>  – preferred authentication method.
 *
 * Authentication uses a constant-time comparison against NSC_BRIDGE_API_KEY
 * (defined in wp-config.php) to prevent timing-oracle attacks.
 *
 * @package NexusSubscriptionController
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Nexus_API {

    const REST_NAMESPACE = 'nexus-sc/v1';

    public static function init() {
        add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
    }

    public static function register_routes() {
        register_rest_route(
            self::REST_NAMESPACE,
            '/check-subscription',
            array(
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => array( __CLASS__, 'check_subscription' ),
                'permission_callback' => array( __CLASS__, 'verify_api_key' ),
                'args'                => array(
                    'location_id' => array(
                        'required'          => true,
                        'type'              => 'string',
                        'sanitize_callback' => 'sanitize_text_field',
                        'validate_callback' => function ( $value ) {
                            return (bool) preg_match( '/^[a-zA-Z0-9_\-]{5,60}$/', $value );
                        },
                        'description'       => 'GHL sub-account location ID.',
                    ),
                    // 'key' param is read manually in permission_callback below.
                ),
            )
        );
    }

    /**
     * Verify the shared API key sent either as ?key= or as Authorization: Bearer <key>.
     *
     * @param  WP_REST_Request $request
     * @return true|WP_Error
     */
    public static function verify_api_key( WP_REST_Request $request ) {
        $expected = defined( 'NSC_BRIDGE_API_KEY' ) ? NSC_BRIDGE_API_KEY : '';

        if ( empty( $expected ) ) {
            return new WP_Error(
                'nsc_no_api_key',
                esc_html__( 'NSC_BRIDGE_API_KEY no está configurada en el servidor. Defínela en wp-config.php.', 'nexus-sc' ),
                array( 'status' => 500 )
            );
        }

        // Try query-param first, then Authorization header.
        $provided = (string) $request->get_param( 'key' );

        if ( '' === $provided ) {
            $auth_header = (string) $request->get_header( 'authorization' );
            if ( str_starts_with( $auth_header, 'Bearer ' ) ) {
                $provided = substr( $auth_header, 7 );
            }
        }

        if ( '' === $provided ) {
            return new WP_Error(
                'nsc_missing_key',
                esc_html__( 'API key requerida (parámetro ?key= o cabecera Authorization: Bearer).', 'nexus-sc' ),
                array( 'status' => 401 )
            );
        }

        // Constant-time comparison to prevent timing-oracle enumeration.
        if ( ! hash_equals( $expected, $provided ) ) {
            return new WP_Error(
                'nsc_invalid_key',
                esc_html__( 'API key inválida.', 'nexus-sc' ),
                array( 'status' => 403 )
            );
        }

        return true;
    }

    /**
     * Return the subscription status for a given GHL location_id.
     *
     * Response shape:
     * {
     *   "active":          bool,
     *   "location_id":     string,
     *   "status":          string,   // WC subscription status or "not_found"
     *   "subscription_id": int|null
     * }
     *
     * @param  WP_REST_Request $request
     * @return WP_REST_Response
     */
    public static function check_subscription( WP_REST_Request $request ) {
        $location_id = $request->get_param( 'location_id' );

        $subscription = Nexus_Subscription::get_subscription_for_location( $location_id );

        if ( null === $subscription ) {
            return rest_ensure_response( array(
                'active'          => false,
                'location_id'     => $location_id,
                'status'          => 'not_found',
                'subscription_id' => null,
            ) );
        }

        $status = $subscription->get_status();
        $active = in_array( $status, array( 'active', 'pending-cancel' ), true );

        return rest_ensure_response( array(
            'active'          => $active,
            'location_id'     => $location_id,
            'status'          => $status,
            'subscription_id' => $subscription->get_id(),
        ) );
    }
}
