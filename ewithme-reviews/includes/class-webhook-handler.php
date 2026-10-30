<?php
/**
 * Webhook, origin verification, and self-service author erasure REST endpoint handler.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Ewithme_Webhook_Handler {

    /**
     * Initializes REST routes.
     */
    public function init() {
        add_action('rest_api_init', array($this, 'register_routes'));
    }

    /**
     * Registers REST API endpoints.
     */
    public function register_routes() {
        // 1. Inbound Webhook: POST /wp-json/ewithme/v1/webhook
        register_rest_route('ewithme/v1', '/webhook', array(
            'methods'             => 'POST',
            'callback'            => array($this, 'handle_incoming_webhook'),
            'permission_callback' => '__return_true'
        ));

        // 2. Domain Ownership Challenge: GET /wp-json/ewithme/v1/verify-origin
        register_rest_route('ewithme/v1', '/verify-origin', array(
            'methods'             => 'GET',
            'callback'            => array($this, 'handle_origin_challenge'),
            'permission_callback' => '__return_true'
        ));

        // 3. Author Self-Service Cryptographic Erasure via Capability Token (WS7 / GDPR): POST /wp-json/ewithme/v1/erase-review
        register_rest_route('ewithme/v1', '/erase-review', array(
            'methods'             => 'POST',
            'callback'            => array($this, 'handle_self_service_erasure'),
            'permission_callback' => '__return_true'
        ));
    }

    /**
     * Handles author self-service cryptographic erasure via capability token (WS7 / GDPR).
     * Allows a review author holding a valid capability token (`erasure_token`) to wipe their
     * review plaintext and PII while preserving unbroken hash chain continuity.
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public function handle_self_service_erasure($request) {
        $params = $request->get_json_params();
        if (empty($params) || !is_array($params)) {
            $params = $request->get_body_params();
        }

        $erasure_token = isset($params['erasure_token']) ? sanitize_text_field(trim($params['erasure_token'])) : '';
        $cert_id       = isset($params['certificate_id']) ? sanitize_text_field(trim($params['certificate_id'])) : '';

        if (empty($erasure_token)) {
            return new WP_REST_Response(array(
                'success' => false,
                'error'   => 'missing_token',
                'message' => __('A valid author erasure capability token is required.', 'ewithme-trust-verified-reviews')
            ), 400);
        }

        $result = self::execute_capability_token_erasure($erasure_token, $cert_id);
        $status_code = !empty($result['success']) ? 200 : 400;
        return new WP_REST_Response($result, $status_code);
    }

    /**
     * Executes capability-token-authenticated review erasure locally, and notifies the remote ledger
     * ONLY when the administrator has explicitly opted in and configured an API key (Guidelines 7 & 9).
     *
     * @param string $erasure_token Raw capability token provided by the review author
     * @param string $cert_id Optional certificate ID
     * @return array { success: bool, message: string, erased_id?: int, certificate_id?: string }
     */
    public static function execute_capability_token_erasure($erasure_token, $cert_id = '') {
        global $wpdb;
        $table = $wpdb->prefix . 'ew_reviews';
        $token_hash = hash('sha256', trim($erasure_token));

        // 1. Check local database for matching capability token hash
        // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Table identifier is prepared via %i.
        $local_review = $wpdb->get_row($wpdb->prepare(
            'SELECT id, certificate_id, status FROM %i WHERE erasure_token_hash = %s LIMIT 1',
            $table,
            $token_hash
        ));

        // Reject immediately if capability token does not match any local review (never phone home on unmatched tokens)
        if (!$local_review) {
            return array(
                'success' => false,
                'error'   => 'invalid_capability_token',
                'message' => __('Invalid or expired erasure capability token. No matching review could be authenticated.', 'ewithme-trust-verified-reviews')
            );
        }

        $effective_cert_id = !empty($local_review->certificate_id) ? $local_review->certificate_id : $cert_id;

        // 2. Wipe plaintext review content, title, and email locally while preserving review_hash and certificate_id continuity
        $wpdb->update(
            $table,
            array(
                'status'             => 'erased',
                'is_hidden'          => 1,
                'content'            => '',
                'title'              => '',
                'author_name'        => 'Redacted Author',
                'author_email'       => '',
                'erasure_token_hash' => null
            ),
            array('id' => $local_review->id),
            array('%s', '%d', '%s', '%s', '%s', '%s', '%s'),
            array('%d')
        );

        // 3. Only notify external ledger if the administrator explicitly opted in AND has a valid API key AND the review has a remote certificate
        if (Ewithme_Api_Client::is_connected() && !empty($effective_cert_id)) {
            $remote_res = Ewithme_Api_Client::request_author_erasure($erasure_token, $effective_cert_id, 'author_requested');
            if (empty($effective_cert_id) && !empty($remote_res['certificate_id'])) {
                $effective_cert_id = sanitize_text_field($remote_res['certificate_id']);
            }
        }

        return array(
            'success'        => true,
            'erased_id'      => intval($local_review->id),
            'certificate_id' => $effective_cert_id,
            'message'        => __('Your review plaintext and personal data have been cryptographically erased while preserving audit chain continuity.', 'ewithme-trust-verified-reviews')
        );
    }

    /**
     * Handles inbound webhooks from ewith.me central server.
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public function handle_incoming_webhook($request) {
        if (!Ewithme_Api_Client::is_connected()) {
            return new WP_REST_Response(array(
                'error'   => 'not_connected',
                'message' => 'External service connection is not enabled on this site.'
            ), 403);
        }

        $config = get_option('ewithme_trust_config', array());
        $webhook_secret = $config['webhook_secret'] ?? '';
        $api_key = $config['api_key'] ?? '';
        $raw_body = $request->get_body();
        $signature_header = $request->get_header('x-ewithme-signature');
        $auth_header = $request->get_header('authorization');

        // 1. Strict Authentication & Transition Reconciliation (SEC-CRIT-01, TRAP-01 & TRAP-H03)
        $is_authenticated = false;

        if (!empty($webhook_secret) && !empty($signature_header)) {
            $expected_sig = 'sha256=' . hash_hmac('sha256', $raw_body, $webhook_secret);
            if (hash_equals($expected_sig, $signature_header)) {
                $is_authenticated = true;
            }
        }

        // If signature did not match or secret is not yet set, evaluate Bearer auth fallback (TRAP-H03)
        if (!$is_authenticated && !empty($api_key) && !empty($auth_header)) {
            if (hash_equals('Bearer ' . $api_key, $auth_header)) {
                $is_authenticated = true;
                // Opportunistically save incoming secret (or rotated secret) transmitted securely over authenticated channel
                $incoming_secret = $request->get_header('x-ewithme-secret');
                if (!empty($incoming_secret)) {
                    $config['webhook_secret'] = sanitize_text_field($incoming_secret);
                    update_option('ewithme_trust_config', $config);
                }
            }
        }

        if (!$is_authenticated) {
            return new WP_REST_Response(array(
                'error'   => 'unauthorized',
                'message' => 'Valid x-ewithme-signature HMAC header or Bearer API key required.'
            ), 401);
        }

        $payload = json_decode($raw_body, true);
        if (empty($payload) || empty($payload['event'])) {
            return new WP_REST_Response(array('error' => 'invalid_payload', 'message' => 'Malformed JSON or missing event field.'), 400);
        }

        // 2. Mandatory Replay Attack Prevention & Timestamp Skew Check (MED-01, TRAP-08 & TRAP-H11)
        if (empty($payload['event_id']) || empty($payload['timestamp'])) {
            return new WP_REST_Response(array(
                'error'   => 'missing_security_fields',
                'message' => 'Webhook payload must include both event_id and timestamp.'
            ), 400);
        }

        $event_id = sanitize_text_field(trim($payload['event_id']));
        if (get_transient('ew_wh_evt_' . $event_id)) {
            return new WP_REST_Response(array('success' => true, 'already_processed' => true, 'event_id' => $event_id), 200);
        }
        set_transient('ew_wh_evt_' . $event_id, 1, DAY_IN_SECONDS);

        $sent_time = strtotime($payload['timestamp']);
        if (!$sent_time || abs(time() - $sent_time) > 900) {
            return new WP_REST_Response(array('error' => 'timestamp_skew', 'message' => 'Webhook timestamp exceeds 900s skew limit.'), 400);
        }

        global $wpdb;
        $table = $wpdb->prefix . 'ew_reviews';
        $domain_salt = get_option('ewithme_trust_salt', '');

        if ($payload['event'] === 'review.verified') {
            $external_id        = intval($payload['external_id'] ?? 0);
            $cert_id            = sanitize_text_field($payload['certificate_id'] ?? '');
            $cert_url           = esc_url_raw($payload['certificate_url'] ?? '');
            $review_hash        = sanitize_text_field($payload['review_hash'] ?? '');
            $erasure_token_hash = sanitize_text_field($payload['erasure_token_hash'] ?? '');
            $verified_at        = !empty($payload['verified_at']) ? gmdate('Y-m-d H:i:s', strtotime($payload['verified_at'])) : current_time('mysql', 1);
            $tier               = sanitize_text_field($payload['tier'] ?? 'email_confirmed');
            $incentivized       = !empty($payload['incentivized']) ? 1 : 0;

            // Check if certificate_id already recorded to prevent duplicate inserts
            if (!empty($cert_id)) {
                // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Table identifier is prepared via %i.
                $existing_cert = $wpdb->get_row($wpdb->prepare('SELECT id FROM %i WHERE certificate_id = %s', $table, $cert_id));
                if ($existing_cert) {
                    return new WP_REST_Response(array('success' => true, 'already_exists' => true, 'id' => $existing_cert->id), 200);
                }
            }

            // 3. Safe Identity Mapping (FUNC-CRIT-02 & PRIV-MED-03 & TRAP-02)
            // Only update local record if external_id matches an actual pending review with matching/empty certificate
            if ($external_id > 0) {
                // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Table identifier is prepared via %i.
                $existing = $wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE id = %d', $table, $external_id));
                if ($existing && (empty($existing->certificate_id) || $existing->certificate_id === $cert_id)) {
                    // Hash author_email with domain salt for privacy; never retain plaintext email
                    // MED-02: If already a 64-character SHA-256 hex string, preserve it without double-hashing
                    $hashed_email = '';
                    if (!empty($existing->author_email)) {
                        if (preg_match('/^[a-f0-9]{64}$/i', $existing->author_email)) {
                            $hashed_email = $existing->author_email;
                        } elseif (!empty($domain_salt)) {
                            $hashed_email = hash_hmac('sha256', strtolower(trim($existing->author_email)), $domain_salt);
                        }
                    }

                    $update_fields = array(
                        'status'            => 'verified',
                        'author_email'      => $hashed_email,
                        'certificate_id'    => $cert_id,
                        'certificate_url'   => $cert_url,
                        'review_hash'       => $review_hash,
                        'verification_tier' => $tier,
                        'is_incentivized'   => $incentivized,
                        'verified_at'       => $verified_at
                    );
                    $update_formats = array('%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s');

                    if (!empty($erasure_token_hash)) {
                        $update_fields['erasure_token_hash'] = $erasure_token_hash;
                        $update_formats[] = '%s';
                    }

                    $wpdb->update(
                        $table,
                        $update_fields,
                        array('id' => $external_id),
                        $update_formats,
                        array('%d')
                    );

                    self::send_admin_review_notification(
                        $existing->author_name ?? 'Customer',
                        intval($existing->rating ?? 5),
                        $existing->title ?? '',
                        $existing->content ?? '',
                        intval($existing->post_id ?? 0),
                        $cert_url
                    );

                    return new WP_REST_Response(array('success' => true, 'updated_id' => $external_id), 200);
                }
            }

            // 4. Direct Oracle Submission (WS3) or fresh record insert
            $post_id      = intval($payload['post_id'] ?? 0);
            $author_name  = sanitize_text_field($payload['display_name'] ?? 'Anonymous Reviewer');
            $rating       = intval($payload['rating'] ?? 5);
            $title        = sanitize_text_field($payload['review_title'] ?? '');
            $content      = sanitize_textarea_field($payload['review_text'] ?? '');

            // Store salted email hash if email provided in payload, else empty string
            // MED-02: Preserve existing hash if already 64-character SHA-256
            $raw_email = sanitize_email($payload['author_email'] ?? '');
            $hashed_email = '';
            if (!empty($raw_email)) {
                if (preg_match('/^[a-f0-9]{64}$/i', $raw_email)) {
                    $hashed_email = $raw_email;
                } elseif (!empty($domain_salt)) {
                    $hashed_email = hash_hmac('sha256', strtolower(trim($raw_email)), $domain_salt);
                }
            }

            if (empty($erasure_token_hash)) {
                $generated_cap = Ewithme_Reviews_Activator::generate_erasure_capability_token();
                $erasure_token_hash = $generated_cap['hash'];
            }

            $wpdb->insert(
                $table,
                array(
                    'post_id'            => $post_id,
                    'author_name'        => $author_name,
                    'author_email'       => $hashed_email,
                    'rating'             => $rating,
                    'title'              => $title,
                    'content'            => $content,
                    'status'             => 'verified',
                    'verification_tier'  => $tier,
                    'is_incentivized'    => $incentivized,
                    'certificate_id'     => $cert_id,
                    'certificate_url'    => $cert_url,
                    'review_hash'        => $review_hash,
                    'erasure_token_hash' => $erasure_token_hash,
                    'verified_at'        => $verified_at,
                    'created_at'         => $verified_at
                ),
                array('%d', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s')
            );

            self::send_admin_review_notification($author_name, $rating, $title, $content, $post_id, $cert_url);

            return new WP_REST_Response(array('success' => true, 'inserted_id' => $wpdb->insert_id), 200);
        }

        // 5. WS7: Moderation / Author Erasure event
        if ($payload['event'] === 'review.moderated') {
            $cert_id = sanitize_text_field($payload['certificate_id'] ?? '');

            if (!empty($cert_id)) {
                $wpdb->update(
                    $table,
                    array(
                        'status'             => 'erased',
                        'is_hidden'          => 1,
                        'content'            => '',
                        'title'              => '',
                        'author_name'        => 'Redacted Author',
                        'author_email'       => '',
                        'erasure_token_hash' => null
                    ),
                    array('certificate_id' => $cert_id),
                    array('%s', '%d', '%s', '%s', '%s', '%s', '%s'),
                    array('%s')
                );
                return new WP_REST_Response(array('success' => true, 'erased_certificate' => $cert_id), 200);
            }
        }

        return new WP_REST_Response(array('success' => true, 'ignored' => true), 200);
    }

    /**
     * Responds to domain origin ownership challenge from ewith.me.
     *
     * @return WP_REST_Response
     */
    public function handle_origin_challenge() {
        if (!Ewithme_Api_Client::is_connected()) {
            return new WP_REST_Response(array(
                'verified' => false,
                'message'  => 'External service connection is not enabled on this site.'
            ), 403);
        }

        $config = get_option('ewithme_trust_config', array());

        return new WP_REST_Response(array(
            'domain'          => $config['domain'] ?? '',
            'challenge_token' => $config['challenge_token'] ?? '',
            'verified'        => true
        ), 200);
    }

    /**
     * Sends an email notification to store administrator when a verified review is received.
     */
    public static function send_admin_review_notification($author_name, $rating, $title, $content, $post_id, $cert_url) {
        $config = get_option('ewithme_trust_config', array());
        if (isset($config['notify_admin_on_review']) && empty($config['notify_admin_on_review'])) {
            return;
        }

        $to = !empty($config['admin_notification_email']) ? $config['admin_notification_email'] : get_option('admin_email');
        if (empty($to)) return;

        $stars = str_repeat('★', intval($rating)) . str_repeat('☆', 5 - intval($rating));
        $item_title = $post_id > 0 ? get_the_title($post_id) : __('Store Experience', 'ewithme-trust-verified-reviews');
        $site_name = get_bloginfo('name');

        $subject = sprintf(
            /* translators: 1: site name, 2: stars */
            __('[%1$s] New Verified Customer Review (%2$s)', 'ewithme-trust-verified-reviews'),
            $site_name,
            $stars
        );

        $admin_url = admin_url('admin.php?page=ewithme-trust-verified-reviews');

        $message = sprintf(
            /* translators: 1: author name, 2: stars, 3: numeric rating, 4: product title, 5: review headline, 6: review content, 7: certificate URL, 8: admin URL */
            __(
                'Hello,

A new verified review has been published on your store:

Reviewer: %1$s
Rating: %2$s (%3$d/5)
Product: %4$s
Headline: %5$s

Review Comments:
"%6$s"

Certificate: %7$s
Manage Reviews in WP Admin: %8$s

-- 
eWith.me Trust Protocol',
                'ewithme-trust-verified-reviews'
            ),
            $author_name,
            $stars,
            intval($rating),
            $item_title,
            $title ?: __('(No title)', 'ewithme-trust-verified-reviews'),
            $content,
            $cert_url ?: __('(Pending certificate URL)', 'ewithme-trust-verified-reviews'),
            $admin_url
        );

        wp_mail($to, $subject, $message, array('Content-Type: text/plain; charset=UTF-8'));
    }
}
