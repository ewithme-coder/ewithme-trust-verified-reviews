<?php
/**
 * Fired during plugin activation.
 * Creates local database tables cleanly without contacting external servers (Guidelines 7 & 9).
 * External domain provisioning is performed only upon explicit administrator opt-in in Settings.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Ewithme_Reviews_Activator {

    /**
     * Executes local database table setup upon plugin activation.
     * Does NOT transmit any data to external servers during activation.
     */
    public static function activate() {
        self::create_database_table();
        set_transient('ewithme_optin_setup_notice', 1, DAY_IN_SECONDS * 7);
    }

    /**
     * Checks if database schema requires automatic migration on updates.
     */
    public static function maybe_upgrade_database() {
        if (get_option('ewithme_reviews_db_version') !== '1.5.0') {
            self::create_database_table();
        }
    }

    /**
     * Creates or updates the local MySQL reviews table using dbDelta.
     */
    public static function create_database_table() {
        global $wpdb;

        $table_name = $wpdb->prefix . 'ew_reviews';
        $charset_collate = $wpdb->get_charset_collate();

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');

        $sql = "CREATE TABLE $table_name (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            post_id bigint(20) unsigned NOT NULL DEFAULT 0,
            author_name varchar(100) NOT NULL DEFAULT '',
            author_email varchar(150) NOT NULL DEFAULT '',
            rating tinyint(3) unsigned NOT NULL DEFAULT 5,
            title varchar(255) DEFAULT '',
            content text NOT NULL,
            reply_content varchar(1500) DEFAULT NULL,
            replied_at datetime DEFAULT NULL,
            replied_by bigint(20) unsigned DEFAULT NULL,
            status varchar(20) NOT NULL DEFAULT 'pending',
            verification_tier varchar(32) NOT NULL DEFAULT 'email_confirmed',
            is_incentivized tinyint(1) NOT NULL DEFAULT 0,
            is_hidden tinyint(1) NOT NULL DEFAULT 0,
            order_id bigint(20) unsigned DEFAULT NULL,
            certificate_id varchar(64) DEFAULT NULL,
            certificate_url varchar(255) DEFAULT '',
            review_hash char(64) DEFAULT '',
            erasure_token_hash char(64) DEFAULT NULL,
            external_review_id varchar(100) DEFAULT NULL,
            verified_at datetime DEFAULT NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY post_status (post_id, status),
            UNIQUE KEY unique_cert_id (certificate_id),
            KEY erasure_tok (erasure_token_hash),
            KEY is_hidden (is_hidden),
            KEY ext_rev_id (external_review_id),
            KEY status_hidden_date (status, is_hidden, verified_at, created_at)
        ) $charset_collate;";

        dbDelta($sql);

        // TRAP-H01 Post-cleanse: Convert any legacy empty certificate_id strings to NULL safely after table exists
        // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Table identifier is prepared via %i.
        $wpdb->query($wpdb->prepare("UPDATE %i SET certificate_id = NULL WHERE certificate_id = '' OR certificate_id = '0'", $table_name));

        // Record database version
        update_option('ewithme_reviews_db_version', '1.5.0');

        // Initialize persistent salt for deterministic privacy-preserving email hashing and capability tokens
        if (!get_option('ewithme_trust_salt')) {
            update_option('ewithme_trust_salt', wp_generate_password(64, true, true));
        }
    }

    /**
     * Generates a cryptographically random capability token and its SHA-256 storage hash
     * for author self-service review erasure (GDPR / WS7).
     *
     * @return array { token: string, hash: string }
     */
    public static function generate_erasure_capability_token() {
        $raw_token = 'ew_erase_' . strtolower(wp_generate_password(32, false, false));
        $token_hash = hash('sha256', $raw_token);
        return array(
            'token' => $raw_token,
            'hash'  => $token_hash
        );
    }

    /**
     * Daily WP-Cron handler: Purges unconfirmed pending reviews older than 7 days (HIGH-02 & TRAP-H05).
     */
    public static function purge_expired_pending_reviews() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'ew_reviews';
        // Batched deletion to prevent table locks (TRAP-H05)
        // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Table identifier is prepared via %i.
        $wpdb->query($wpdb->prepare("DELETE FROM %i WHERE status = 'pending' AND created_at < (NOW() - INTERVAL 7 DAY) LIMIT 100", $table_name));
    }

    /**
     * Provisions the site domain with ewith.me central authority ONLY when explicitly triggered
     * by the store administrator after opt-in consent in WP Admin Settings (Guidelines 7 & 9).
     *
     * @param bool $include_admin_email Whether the admin opted in to include the site admin email.
     * @return array { success: bool, message: string }
     */
    public static function provision_domain_with_consent($include_admin_email = true) {
        $config = get_option('ewithme_trust_config', array());

        $site_url = get_site_url();
        $domain = wp_parse_url($site_url, PHP_URL_HOST);
        $admin_email = $include_admin_email ? get_option('admin_email') : '';
        $site_title = get_bloginfo('name');
        $api_base = !empty($config['api_url']) ? untrailingslashit($config['api_url']) : EWITHME_DEFAULT_API_URL;

        $provision_url = $api_base . '/api/v1/trust/provision';

        $response = wp_remote_post($provision_url, array(
            'timeout'     => 10,
            'redirection' => 5,
            'httpversion' => '1.1',
            'blocking'    => true,
            'headers'     => array(
                'Content-Type' => 'application/json; charset=utf-8',
                'User-Agent'   => 'WordPress/' . get_bloginfo('version') . '; EwithmeReviews/' . EWITHME_REVIEWS_VERSION
            ),
            'body'        => wp_json_encode(array(
                'domain'      => $domain,
                'admin_email' => $admin_email,
                'site_title'  => $site_title
            ))
        ));

        if (is_wp_error($response)) {
            return array(
                'success' => false,
                'message' => $response->get_error_message()
            );
        }

        $code = wp_remote_retrieve_response_code($response);
        $data = json_decode(wp_remote_retrieve_body($response), true);

        if ($code === 200 && !empty($data['success']) && !empty($data['api_key'])) {
            $updated_config = array_merge($config, array(
                'domain'                => $data['domain'] ?? $domain,
                'api_key'               => $data['api_key'],
                'webhook_secret'        => $data['webhook_secret'] ?? '',
                'plan'                  => $data['plan'] ?? 'free',
                'monthly_budget'        => $data['monthly_budget'] ?? 5,
                'budget_used'           => $data['budget_used'] ?? 0,
                'quota_reset_date'      => $data['quota_reset_date'] ?? '',
                'challenge_token'       => $data['challenge_token'] ?? '',
                'profile_url'           => $data['profile_url'] ?? 'https://ewith.me/trust',
                'api_url'               => $api_base,
                'external_service_optin'=> 1,
                'auto_inject_woo'       => $config['auto_inject_woo'] ?? 1,
                'target_schema'         => 'Product'
            ));
            update_option('ewithme_trust_config', $updated_config);
            delete_transient('ewithme_optin_setup_notice');
            delete_transient('ewithme_provision_notice');

            return array(
                'success' => true,
                'message' => __('Connected to eWith.me Trust Service and provisioned API key.', 'ewithme-trust-verified-reviews')
            );
        }

        return array(
            'success' => false,
            'message' => $data['message'] ?? __('Could not provision API credentials. Please try again or enter an existing API key manually.', 'ewithme-trust-verified-reviews')
        );
    }
}
