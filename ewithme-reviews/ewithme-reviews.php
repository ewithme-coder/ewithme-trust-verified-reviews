<?php
/**
 * Plugin Name: Ewithme Trust & Verified Reviews
 * Plugin URI: https://ewith.me/trust
 * Description: Self-hosted customer reviews with independent cryptographic verification and Search Central-compliant Product schema.
 * Version: 2.1.2
 * Requires at least: 6.2
 * Requires PHP: 7.4
 * Author: eWith.me
 * Author URI: https://ewith.me
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: ewithme-trust-verified-reviews
 * Domain Path: /languages
 */

// If this file is called directly, abort.
if (!defined('ABSPATH')) {
    exit;
}

define('EWITHME_REVIEWS_VERSION', '2.1.2');
define('EWITHME_REVIEWS_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('EWITHME_REVIEWS_PLUGIN_URL', plugin_dir_url(__FILE__));
define('EWITHME_DEFAULT_API_URL', 'https://ewith.me');

// Include required classes
require_once EWITHME_REVIEWS_PLUGIN_DIR . 'includes/class-activator.php';
require_once EWITHME_REVIEWS_PLUGIN_DIR . 'includes/class-api-client.php';
require_once EWITHME_REVIEWS_PLUGIN_DIR . 'includes/class-webhook-handler.php';
require_once EWITHME_REVIEWS_PLUGIN_DIR . 'includes/class-schema-generator.php';
require_once EWITHME_REVIEWS_PLUGIN_DIR . 'includes/class-trust-verifier.php';
require_once EWITHME_REVIEWS_PLUGIN_DIR . 'includes/class-admin-settings.php';
require_once EWITHME_REVIEWS_PLUGIN_DIR . 'includes/class-review-invitations.php';
require_once EWITHME_REVIEWS_PLUGIN_DIR . 'includes/class-review-importer.php';
require_once EWITHME_REVIEWS_PLUGIN_DIR . 'public/class-frontend-widget.php';

/**
 * The code that runs during plugin activation.
 */
function ewithme_reviews_activate() {
    Ewithme_Reviews_Activator::activate();
    if (!wp_next_scheduled('ewithme_purge_expired_pending_reviews')) {
        wp_schedule_event(time(), 'daily', 'ewithme_purge_expired_pending_reviews');
    }
}
register_activation_hook(__FILE__, 'ewithme_reviews_activate');

/**
 * The code that runs during plugin deactivation.
 */
function ewithme_reviews_deactivate() {
    wp_clear_scheduled_hook('ewithme_purge_expired_pending_reviews');
    // Flush rewrite rules if needed
    flush_rewrite_rules();
}
register_deactivation_hook(__FILE__, 'ewithme_reviews_deactivate');

// Register daily cleanup cron action (HIGH-02 & TRAP-H05)
add_action('ewithme_purge_expired_pending_reviews', array('Ewithme_Reviews_Activator', 'purge_expired_pending_reviews'));

/**
 * Initialize all components of the plugin.
 */
function ewithme_reviews_run() {
    // 1. Webhook and REST API Handler
    $webhook_handler = new Ewithme_Webhook_Handler();
    $webhook_handler->init();

    // 2. Schema Generator (SSR JSON-LD on wp_head)
    $schema_generator = new Ewithme_Schema_Generator();
    $schema_generator->init();

    // 3. Admin Settings, Moderation & CSV Importer
    if (is_admin()) {
        Ewithme_Reviews_Activator::maybe_upgrade_database();
        $admin = new Ewithme_Admin_Settings();
        $admin->init();
        $importer = new Ewithme_Review_Importer();
        $importer->init();
    }

    // 4. Frontend Widget & Shortcodes
    $frontend = new Ewithme_Frontend_Widget();
    $frontend->init();

    // 5. Automated Post-Purchase Review Invitations
    $invitations = new Ewithme_Review_Invitations();
    $invitations->init();
}
add_action('plugins_loaded', 'ewithme_reviews_run');
