<?php
/**
 * Plugin Name: Nexus Subscription Controller
 * Plugin URI:  https://github.com/Mimergt/nexus-sc
 * Description: Controla el acceso a la integración GHL/EpicPay (bridge Cloudflare) según el estado de la suscripción en WooCommerce Subscriptions. Vincula el GHL location_id a la suscripción, valida duplicados, sincroniza estado con el bridge y expone un endpoint REST seguro para que el bridge verifique licencias.
 * Version:     1.0.0
 * Requires PHP: 7.4
 * Author:      Mimer
 * Author URI:  https://epic.gt
 * License:     MIT
 * WC requires at least: 7.4.0
 * WC tested up to:     9.3.0
 *
 * @package NexusSubscriptionController
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Prevent direct access.
}

define( 'NSC_VERSION', '1.0.0' );
define( 'NSC_PLUGIN_FILE', __FILE__ );
define( 'NSC_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );

/**
 * URL base del bridge de Cloudflare.
 * Definir en wp-config.php:
 *   define( 'NSC_BRIDGE_URL', 'https://recurrente-bridge.epicgt.workers.dev' );
 */
if ( ! defined( 'NSC_BRIDGE_URL' ) ) {
    define( 'NSC_BRIDGE_URL', '' );
}

/**
 * Clave secreta compartida con el bridge para autenticar el endpoint REST.
 * Definir en wp-config.php:
 *   define( 'NSC_BRIDGE_API_KEY', 'tu-clave-secreta-aleatoria' );
 */
if ( ! defined( 'NSC_BRIDGE_API_KEY' ) ) {
    define( 'NSC_BRIDGE_API_KEY', '' );
}

/**
 * Inicializa el plugin una vez que WooCommerce esté cargado.
 */
function nexus_sc_init() {
    // Require WooCommerce Subscriptions.
    if ( ! class_exists( 'WC_Subscriptions' ) ) {
        add_action( 'admin_notices', 'nexus_sc_missing_wcs_notice' );
        return;
    }

    require_once NSC_PLUGIN_DIR . 'includes/class-nexus-checkout.php';
    require_once NSC_PLUGIN_DIR . 'includes/class-nexus-subscription.php';
    require_once NSC_PLUGIN_DIR . 'includes/class-nexus-api.php';
    require_once NSC_PLUGIN_DIR . 'includes/class-nexus-admin.php';

    Nexus_Checkout::init();
    Nexus_Subscription::init();
    Nexus_API::init();
    Nexus_Admin::init();
}
add_action( 'plugins_loaded', 'nexus_sc_init', 20 );

/**
 * Admin notice when WooCommerce Subscriptions is not active.
 */
function nexus_sc_missing_wcs_notice() {
    echo '<div class="notice notice-error"><p>'
        . sprintf(
            /* translators: %s = plugin name link */
            esc_html__( '%s requiere el plugin WooCommerce Subscriptions para funcionar. Por favor instálalo y actívalo.', 'nexus-sc' ),
            '<strong>Nexus Subscription Controller</strong>'
        )
        . '</p></div>';
}

/**
 * Settings link in the plugins list.
 */
function nexus_sc_action_links( $links ) {
    $url = admin_url( 'admin.php?page=wc-settings&tab=subscriptions' );
    array_unshift( $links, '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Configuración', 'nexus-sc' ) . '</a>' );
    return $links;
}
add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), 'nexus_sc_action_links' );

/**
 * HPOS + Blocks compatibility declarations.
 */
add_action( 'before_woocommerce_init', function () {
    if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', __FILE__, true );
    }
} );
