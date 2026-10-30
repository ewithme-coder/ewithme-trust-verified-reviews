<?php
/**
 * API client to communicate with central ewith.me Trust service.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Ewithme_Api_Client {

    /**
     * Retrieves the stored configuration array.
     */
    public static function get_config() {
        return get_option('ewithme_trust_config', array(
            'api_url'                => EWITHME_DEFAULT_API_URL,
            'api_key'                => '',
            'external_service_optin' => 0,
            'webhook_secret'         => '',
            'plan'                   => 'free',
            'monthly_budget'         => 5,
            'budget_used'            => 0
        ));
    }

    /**
     * Checks whether the site administrator has explicitly opted in to the external service
     * and configured a valid API key (WordPress.org Guidelines 7 & 9).
     *
     * @return bool
     */
    public static function is_connected() {
        $config = self::get_config();
        return !empty($config['external_service_optin']) && !empty($config['api_key']);
    }

    /**
     * Sends a review verification request to central ewith.me server.
     *
     * @param array $review_data Review attributes
     * @return array Response payload
     */
    public static function request_verification($review_data) {
        if (!self::is_connected()) {
            return array(
                'success' => false,
                'error'   => 'not_configured',
                'message' => __('Plugin is not connected to Ewithme Trust API.', 'ewithme-trust-verified-reviews')
            );
        }

        $config = self::get_config();
        $api_key = $config['api_key'] ?? '';
        $api_base = !empty($config['api_url']) ? untrailingslashit($config['api_url']) : EWITHME_DEFAULT_API_URL;

        $endpoint = $api_base . '/api/v1/trust/request-verification';

        $response = wp_remote_post($endpoint, array(
            'timeout' => 15,
            'headers' => array(
                'Content-Type'  => 'application/json; charset=utf-8',
                'Authorization' => 'Bearer ' . $api_key,
                'User-Agent'    => 'WordPress/' . get_bloginfo('version') . '; EwithmeReviews/' . EWITHME_REVIEWS_VERSION
            ),
            'body'    => wp_json_encode(array(
                'external_id'   => strval($review_data['id'] ?? ''),
                'author_name'   => $review_data['author_name'] ?? '',
                'author_email'  => $review_data['author_email'] ?? '',
                'rating'        => intval($review_data['rating'] ?? 5),
                'review_title'  => $review_data['title'] ?? '',
                'review_text'   => $review_data['content'] ?? '',
                'item_name'     => $review_data['item_name'] ?? '',
                'product_id'    => strval($review_data['product_id'] ?? ''),
                'tier'          => $review_data['tier'] ?? 'email_confirmed',
                'incentivized'  => !empty($review_data['incentivized']),
                'erasure_token' => $review_data['erasure_token'] ?? ''
            ))
        ));

        if (is_wp_error($response)) {
            return array(
                'success' => false,
                'error'   => 'network_error',
                'message' => $response->get_error_message()
            );
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true) ?: array();

        if ($code === 202 || $code === 200) {
            return array(
                'success'       => true,
                'status'        => 'dispatched',
                'message'       => $body['message'] ?? __('Verification email sent.', 'ewithme-trust-verified-reviews'),
                'erasure_token' => $body['erasure_token'] ?? ($review_data['erasure_token'] ?? '')
            );
        }

        return array(
            'success'     => false,
            'status_code' => $code,
            'error'       => $body['error'] ?? 'api_error',
            'message'     => $body['message'] ?? __('Failed to request verification.', 'ewithme-trust-verified-reviews'),
            'upgrade_url' => $body['upgrade_url'] ?? ''
        );
    }

    /**
     * Requests cryptographic erasure of a review on the ewith.me ledger using an author capability token
     * or administrator moderation reason (WS7). Strictly requires explicit administrator opt-in and API key.
     *
     * @param string $erasure_token Capability token issued to the review author
     * @param string $cert_id Optional certificate ID
     * @param string $reason Erasure or moderation reason
     * @return array Result payload
     */
    public static function request_author_erasure($erasure_token, $cert_id = '', $reason = 'author_requested') {
        if (!self::is_connected()) {
            return array(
                'success' => false,
                'error'   => 'not_configured',
                'message' => __('External service is not opted in or configured.', 'ewithme-trust-verified-reviews')
            );
        }

        $config = self::get_config();
        $api_key = $config['api_key'] ?? '';
        $api_base = !empty($config['api_url']) ? untrailingslashit($config['api_url']) : EWITHME_DEFAULT_API_URL;
        $endpoint = $api_base . '/api/v1/trust/erase-review';

        $response = wp_remote_post($endpoint, array(
            'timeout' => 12,
            'headers' => array(
                'Content-Type'  => 'application/json; charset=utf-8',
                'Authorization' => 'Bearer ' . $api_key,
                'User-Agent'    => 'WordPress/' . get_bloginfo('version') . '; EwithmeReviews/' . EWITHME_REVIEWS_VERSION
            ),
            'body'    => wp_json_encode(array(
                'erasure_token' => sanitize_text_field($erasure_token),
                'cert_id'       => sanitize_text_field($cert_id),
                'reason'        => sanitize_text_field($reason ?: 'author_requested')
            ))
        ));

        if (is_wp_error($response)) {
            return array(
                'success' => false,
                'error'   => 'network_error',
                'message' => $response->get_error_message()
            );
        }

        $code = wp_remote_retrieve_response_code($response);
        $data = json_decode(wp_remote_retrieve_body($response), true) ?: array();

        if ($code === 200 && !empty($data['success'])) {
            return array(
                'success'        => true,
                'certificate_id' => $data['certificate_id'] ?? $cert_id,
                'message'        => $data['message'] ?? __('Review erased from cryptographic ledger.', 'ewithme-trust-verified-reviews')
            );
        }

        return array(
            'success' => false,
            'message' => $data['message'] ?? __('Remote ledger erasure could not be verified.', 'ewithme-trust-verified-reviews')
        );
    }

    /**
     * Polls the remaining verification budget and account tier.
     *
     * @return array|false Quota data
     */
    public static function check_quota() {
        if (!self::is_connected()) {
            return false;
        }

        $config = self::get_config();
        $api_key = $config['api_key'] ?? '';
        $api_base = !empty($config['api_url']) ? untrailingslashit($config['api_url']) : EWITHME_DEFAULT_API_URL;

        $endpoint = $api_base . '/api/v1/trust/quota-status';

        $response = wp_remote_get($endpoint, array(
            'timeout' => 10,
            'headers' => array(
                'Authorization' => 'Bearer ' . $api_key,
                'User-Agent'    => 'WordPress/' . get_bloginfo('version') . '; EwithmeReviews/' . EWITHME_REVIEWS_VERSION
            )
        ));

        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            return false;
        }

        $data = json_decode(wp_remote_retrieve_body($response), true);
        if (!empty($data['success'])) {
            $config['plan']             = $data['plan'] ?? 'free';
            $config['monthly_budget']   = $data['monthlyBudget'] ?? 5;
            $config['budget_used']      = $data['budgetUsed'] ?? 0;
            $config['quota_reset_date'] = $data['quotaResetDate'] ?? '';
            update_option('ewithme_trust_config', $config);
            return $data;
        }

        return false;
    }

    /**
     * Checks/verifies domain control proof with ewith.me central authority (WS5).
     * Strictly requires explicit administrator opt-in and API key.
     *
     * @param string $method 'auto', 'dns', or 'http'
     * @return array Result payload
     */
    public static function verify_domain_proof($method = 'auto') {
        if (!self::is_connected()) {
            return array(
                'success' => false,
                'error'   => 'not_configured',
                'message' => __('Please opt in to the external service and configure an API key before verifying domain control.', 'ewithme-trust-verified-reviews')
            );
        }

        $config = self::get_config();
        $api_key = $config['api_key'] ?? '';
        $domain = $config['domain'] ?? wp_parse_url(get_site_url(), PHP_URL_HOST);
        $api_base = !empty($config['api_url']) ? untrailingslashit($config['api_url']) : EWITHME_DEFAULT_API_URL;

        $endpoint = $api_base . '/api/v1/trust/verify-domain';

        $response = wp_remote_post($endpoint, array(
            'timeout' => 12,
            'headers' => array(
                'Content-Type'  => 'application/json; charset=utf-8',
                'Authorization' => 'Bearer ' . $api_key,
                'User-Agent'    => 'WordPress/' . get_bloginfo('version') . '; EwithmeReviews/' . EWITHME_REVIEWS_VERSION
            ),
            'body'    => wp_json_encode(array(
                'domain' => $domain,
                'method' => $method
            ))
        ));

        if (is_wp_error($response)) {
            return array(
                'success' => false,
                'error'   => 'network_error',
                'message' => $response->get_error_message()
            );
        }

        $code = wp_remote_retrieve_response_code($response);
        $data = json_decode(wp_remote_retrieve_body($response), true) ?: array();

        if ($code === 200 && !empty($data['success'])) {
            $config['domain_verified'] = 1;
            update_option('ewithme_trust_config', $config);
            return $data;
        }

        return array(
            'success' => false,
            'message' => $data['message'] ?? __('Domain proof verification failed. Please check your DNS TXT record.', 'ewithme-trust-verified-reviews')
        );
    }

    /**
     * Synchronizes local database with public eWith.me append-only ledger feed.
     * Acts as an operational self-healing fallback when incoming webhooks were blocked by firewalls.
     * Strictly requires explicit administrator opt-in and API key.
     *
     * @return array Sync summary { reconciled: int, total: int }
     */
    public static function sync_ledger_feed() {
        if (!self::is_connected()) {
            return array(
                'success'    => false,
                'reconciled' => 0,
                'total'      => 0,
                'message'    => __('External service is not opted in or configured.', 'ewithme-trust-verified-reviews')
            );
        }

        $config = self::get_config();
        $api_key = $config['api_key'] ?? '';
        $domain = $config['domain'] ?? wp_parse_url(get_site_url(), PHP_URL_HOST);
        $api_base = !empty($config['api_url']) ? untrailingslashit($config['api_url']) : EWITHME_DEFAULT_API_URL;

        $endpoint = $api_base . '/api/v1/trust/feed/' . rawurlencode($domain);

        $response = wp_remote_get($endpoint, array(
            'timeout' => 15,
            'headers' => array(
                'Authorization' => 'Bearer ' . $api_key,
                'User-Agent'    => 'WordPress/' . get_bloginfo('version') . '; EwithmeReviews/' . EWITHME_REVIEWS_VERSION
            )
        ));

        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            return array('success' => false, 'message' => __('Could not retrieve ledger feed.', 'ewithme-trust-verified-reviews'));
        }

        $feed = json_decode(wp_remote_retrieve_body($response), true);
        if (empty($feed) || !isset($feed['entries']) || !is_array($feed['entries'])) {
            return array('success' => true, 'reconciled' => 0, 'total' => 0);
        }

        global $wpdb;
        $table = $wpdb->prefix . 'ew_reviews';
        $reconciled = 0;

        foreach ($feed['entries'] as $entry_wrapper) {
            $entry = $entry_wrapper['entry'] ?? null;
            if (!$entry || empty($entry['type'])) continue;

            $cert_id = $entry['review_id'] ?? '';
            if (empty($cert_id)) continue;

            if ($entry['type'] === 'review.registered') {
                // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Table identifier is prepared via %i.
                $existing = $wpdb->get_row($wpdb->prepare('SELECT id, status FROM %i WHERE certificate_id = %s', $table, $cert_id));
                if ($existing) {
                    if ($existing->status !== 'verified') {
                        $wpdb->update($table, array('status' => 'verified'), array('id' => $existing->id));
                        $reconciled++;
                    }
                } else {
                    $ext_id = intval($entry['external_id'] ?? 0);
                    if ($ext_id > 0) {
                        // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Table identifier is prepared via %i.
                        $matched = $wpdb->get_row($wpdb->prepare('SELECT id, status FROM %i WHERE id = %d', $table, $ext_id));
                        if ($matched) {
                            $cert_url = $api_base . '/trust/cert/' . $cert_id;
                            $wpdb->update($table, array(
                                'certificate_id'  => $cert_id,
                                'certificate_url' => $cert_url,
                                'status'          => 'verified',
                                'verified_at'     => !empty($entry['ts']) ? gmdate('Y-m-d H:i:s', strtotime($entry['ts'])) : current_time('mysql', 1)
                            ), array('id' => $matched->id));
                            $reconciled++;
                        }
                    }
                }
            } elseif ($entry['type'] === 'review.moderated') {
                $wpdb->update($table, array(
                    'status'    => 'erased',
                    'is_hidden' => 1,
                    'content'   => '',
                    'title'     => ''
                ), array('certificate_id' => $cert_id));
                $reconciled++;
            }
        }

        return array('success' => true, 'reconciled' => $reconciled, 'total' => count($feed['entries']));
    }
}
