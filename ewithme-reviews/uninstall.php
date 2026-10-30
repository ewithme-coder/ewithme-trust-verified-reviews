<?php
/**
 * Fired when the plugin is uninstalled via WordPress admin.
 * Preserves customer reviews and trust ledger commitments by default unless explicit full-cleanup is opted in (TRAP-09).
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// Always clean up temporary plugin transients
delete_transient('ewithme_optin_setup_notice');
delete_transient('ewithme_provision_notice');
delete_transient('ewithme_quota_cache');
delete_transient('ewithme_domain_verify_error');

$ewithme_config = get_option('ewithme_trust_config', array());

// Only purge customer review records and database tables if merchant explicitly configured data removal
if (!empty($ewithme_config['delete_data_on_uninstall'])) {
    global $wpdb;
    $ewithme_table_name = $wpdb->prefix . 'ew_reviews';
    // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Table identifier is prepared via %i on uninstall.
    $wpdb->query($wpdb->prepare('DROP TABLE IF EXISTS %i', $ewithme_table_name));

    delete_option('ewithme_trust_config');
    delete_option('ewithme_reviews_db_version');
    delete_option('ewithme_trust_salt');
}
