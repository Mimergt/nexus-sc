<?php
/**
 * Nexus_Admin
 *
 * Enhances the WordPress / WooCommerce admin area with GHL account information:
 *  - Adds a "GHL Account" column to the WC Subscriptions list (classic & HPOS).
 *  - Adds a "GHL Account" column to the WC Orders list (classic & HPOS).
 *  - Adds a side metabox on the single subscription edit screen.
 *  - Renders a data panel on HPOS single subscription screens.
 *
 * @package NexusSubscriptionController
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Nexus_Admin {

    public static function init() {
        // ── Subscriptions list (classic CPT) ─────────────────────────────────
        add_filter( 'manage_edit-shop_subscription_columns',         array( __CLASS__, 'add_subscription_column' ) );
        add_action( 'manage_shop_subscription_posts_custom_column',  array( __CLASS__, 'render_subscription_column' ), 10, 2 );

        // ── Subscriptions list (HPOS) ─────────────────────────────────────────
        add_filter( 'woocommerce_subscription_list_table_columns',           array( __CLASS__, 'add_subscription_column' ) );
        add_filter( 'woocommerce_subscription_list_table_column_content',    array( __CLASS__, 'filter_hpos_subscription_column_content' ), 10, 3 );

        // ── Orders list (classic CPT) ─────────────────────────────────────────
        add_filter( 'manage_edit-shop_order_columns',       array( __CLASS__, 'add_order_column' ) );
        add_action( 'manage_shop_order_posts_custom_column', array( __CLASS__, 'render_order_column' ), 10, 2 );

        // ── Orders list (HPOS) ───────────────────────────────────────────────
        add_filter( 'woocommerce_shop_order_list_table_columns',          array( __CLASS__, 'add_order_column' ) );
        add_filter( 'woocommerce_shop_order_list_table_column_content',   array( __CLASS__, 'filter_hpos_order_column_content' ), 10, 3 );

        // ── Single subscription – metabox (classic CPT) ───────────────────────
        add_action( 'add_meta_boxes',                            array( __CLASS__, 'register_meta_box' ) );

        // ── Single subscription – data panel (HPOS) ──────────────────────────
        add_action( 'woocommerce_admin_subscription_data_panels', array( __CLASS__, 'render_subscription_panel' ) );
    }

    // ────────────────────────────────────────────────────────────────────────
    // Subscriptions list
    // ────────────────────────────────────────────────────────────────────────

    public static function add_subscription_column( $columns ) {
        $columns['nexus_ghl_location'] = esc_html__( 'GHL Account', 'nexus-sc' );
        return $columns;
    }

    /** Classic CPT column renderer. */
    public static function render_subscription_column( $column, $post_id ) {
        if ( 'nexus_ghl_location' !== $column ) {
            return;
        }
        $subscription = wcs_get_subscription( $post_id );
        if ( ! $subscription ) {
            return;
        }
        self::echo_location_badge( $subscription->get_meta( Nexus_Subscription::META_KEY ) );
    }

    /** HPOS column content filter. */
    public static function filter_hpos_subscription_column_content( $content, $column, $subscription ) {
        if ( 'nexus_ghl_location' !== $column ) {
            return $content;
        }
        return self::get_location_badge_html( $subscription->get_meta( Nexus_Subscription::META_KEY ) );
    }

    // ────────────────────────────────────────────────────────────────────────
    // Orders list
    // ────────────────────────────────────────────────────────────────────────

    public static function add_order_column( $columns ) {
        $columns['nexus_ghl_location'] = esc_html__( 'GHL Account', 'nexus-sc' );
        return $columns;
    }

    /** Classic CPT order column renderer. */
    public static function render_order_column( $column, $post_id ) {
        if ( 'nexus_ghl_location' !== $column ) {
            return;
        }
        $order = wc_get_order( $post_id );
        if ( ! $order ) {
            return;
        }
        self::echo_location_badge( $order->get_meta( Nexus_Subscription::META_KEY ) );
    }

    /** HPOS order column content filter. */
    public static function filter_hpos_order_column_content( $content, $column, $order ) {
        if ( 'nexus_ghl_location' !== $column ) {
            return $content;
        }
        return self::get_location_badge_html( $order->get_meta( Nexus_Subscription::META_KEY ) );
    }

    // ────────────────────────────────────────────────────────────────────────
    // Single subscription metabox (classic CPT)
    // ────────────────────────────────────────────────────────────────────────

    public static function register_meta_box() {
        add_meta_box(
            'nexus_sc_ghl_account',
            esc_html__( 'Nexus – GHL Account', 'nexus-sc' ),
            array( __CLASS__, 'render_meta_box' ),
            'shop_subscription',
            'side',
            'default'
        );
    }

    public static function render_meta_box( $post ) {
        $subscription = wcs_get_subscription( $post->ID );
        if ( ! $subscription ) {
            return;
        }
        self::render_location_details( $subscription );
    }

    // ────────────────────────────────────────────────────────────────────────
    // Single subscription panel (HPOS)
    // ────────────────────────────────────────────────────────────────────────

    public static function render_subscription_panel( $subscription ) {
        echo '<div class="panel woocommerce_options_panel" id="nexus-sc-panel"><h2>'
            . esc_html__( 'Nexus – GHL Account', 'nexus-sc' )
            . '</h2>';
        self::render_location_details( $subscription );
        echo '</div>';
    }

    // ────────────────────────────────────────────────────────────────────────
    // Shared rendering helpers
    // ────────────────────────────────────────────────────────────────────────

    /**
     * Render GHL location details for a subscription object.
     *
     * @param WC_Subscription $subscription
     */
    private static function render_location_details( $subscription ) {
        $location_id = $subscription->get_meta( Nexus_Subscription::META_KEY );
        $bridge_url  = ( defined( 'NSC_BRIDGE_URL' ) && NSC_BRIDGE_URL ) ? rtrim( NSC_BRIDGE_URL, '/' ) : '';
        ?>
        <div style="padding: 6px 0;">
            <strong><?php esc_html_e( 'GHL Location ID:', 'nexus-sc' ); ?></strong><br>
            <?php if ( $location_id ) : ?>
                <code style="word-break: break-all;"><?php echo esc_html( $location_id ); ?></code>
                <?php if ( $bridge_url ) : ?>
                    <br><small>
                        <a href="<?php echo esc_url( $bridge_url . '/admin/tenant?locationId=' . rawurlencode( $location_id ) ); ?>"
                           target="_blank" rel="noopener noreferrer">
                            <?php esc_html_e( 'Ver en Bridge →', 'nexus-sc' ); ?>
                        </a>
                    </small>
                <?php endif; ?>
            <?php else : ?>
                <em><?php esc_html_e( 'No hay cuenta GHL asociada', 'nexus-sc' ); ?></em>
                <br>
                <small style="color:#888;">
                    <?php esc_html_e( 'El cliente no incluyó ?account_id en la URL de compra.', 'nexus-sc' ); ?>
                </small>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Print a styled badge for the location_id or a dash if empty.
     *
     * @param string $location_id
     */
    private static function echo_location_badge( $location_id ) {
        echo self::get_location_badge_html( $location_id );
    }

    /**
     * Build badge HTML for list tables.
     *
     * @param string $location_id
     * @return string
     */
    private static function get_location_badge_html( $location_id ) {
        if ( $location_id ) {
            return '<code style="font-size:11px;">' . esc_html( $location_id ) . '</code>';
        }

        return '<span class="na">&ndash;</span>';
    }
}
