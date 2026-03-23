=== Nexus Subscription Controller ===
Contributors: mimergt
Tags: WooCommerce, subscriptions, GoHighLevel, GHL, EpicPay, bridge
Requires at least: 6.0
Tested up to: 6.8.1
Stable tag: 1.0.0
Requires PHP: 7.4
License: MIT

Controls access to the GHL/EpicPay integration based on WooCommerce Subscriptions state.

== Description ==

Nexus Subscription Controller vincula cada suscripción de WooCommerce Subscriptions con el
ID de sub-cuenta de GoHighLevel (location_id) del cliente que la compró.

Funcionalidades principales:

* Captura el parámetro `?account_id=` en la URL de compra y lo guarda en la sesión de WooCommerce.
* Al crear la suscripción, asocia el location_id como meta privado (`_nexus_ghl_location_id`) tanto en la suscripción como en la orden padre.
* Bloquea compras duplicadas: si ya existe una suscripción activa para el mismo location_id, el checkout muestra un error.
* Sincroniza el estado (activo / inactivo) con el bridge de Cloudflare via REST: al activar, cancelar, suspender o expirar la suscripción, envía un POST al bridge.
* Expone el endpoint REST `GET /wp-json/nexus-sc/v1/check-subscription?location_id=XXX` para que el bridge verifique el estado en tiempo real, autenticado por una API key secreta compartida.
* Añade columna "GHL Account" en la lista de suscripciones y órdenes de WooCommerce admin.
* Añade metabox / panel lateral con el location_id en la pantalla de detalle de la suscripción.

Requiere WooCommerce Subscriptions (plugin oficial WooThemes) activo.

== Configuración ==

Añadir en `wp-config.php`:

  define( 'NSC_BRIDGE_URL',     'https://tu-worker.workers.dev' );
  define( 'NSC_BRIDGE_API_KEY', 'clave-secreta-aleatoria' );

La misma clave debe configurarse en el bridge de Cloudflare como variable de entorno
`WP_NEXUS_API_KEY`.

== Flujo de compra ==

1. El cliente llega al checkout desde la página de la app en GHL. La URL incluye:
   `?account_id={locationId}` (generado dinámicamente por el bridge).
2. Nexus captura el account_id y lo guarda en la sesión WC.
3. Al completar la compra, el location_id queda vinculado a la suscripción.
4. El bridge, al cargar `/app` para ese location_id, consulta este plugin y decide
   si mostrar la pantalla de onboarding o la pantalla con las llaves de integración.

== Instalación ==

1. Sube la carpeta `nexus-sc` a `wp-content/plugins/`.
2. Activa el plugin desde WP Admin > Plugins.
3. Asegúrate de que WooCommerce Subscriptions esté activo.
4. Configura las constantes en `wp-config.php` (ver Configuración).

== Changelog ==

= 1.0.0 =
* Lanzamiento inicial.
* Captura de ?account_id en checkout.
* Asociación de location_id a suscripción.
* Validación de duplicados.
* Sincronización de estado al bridge.
* Endpoint REST autenticado.
* Columnas admin en suscripciones y órdenes.
* Metabox en detalle de suscripción.
