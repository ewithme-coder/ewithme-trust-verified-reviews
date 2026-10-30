<?php
/**
 * Frontend presentation: Shortcodes, AJAX review submission, self-service capability token erasure, and widget templates.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Ewithme_Frontend_Widget {

    /**
     * Tracks whether plugin assets have been enqueued on the current page.
     *
     * @var bool
     */
    private static $assets_enqueued = false;

    /**
     * Initializes shortcodes, scripts, and AJAX listeners.
     */
    public function init() {
        add_shortcode('ewithme_reviews', array($this, 'render_reviews_widget_shortcode'));
        add_shortcode('ewithme_badge', array($this, 'render_badge_shortcode'));
        add_shortcode('ewithme_disclosure', array($this, 'render_disclosure_shortcode'));
        add_shortcode('ewithme_audit_badge', array($this, 'render_audit_badge_shortcode'));

        add_action('wp_enqueue_scripts', array($this, 'register_and_maybe_enqueue_assets'));

        // AJAX handlers for customer review submissions
        add_action('wp_ajax_ewithme_submit_review', array($this, 'handle_review_submission'));
        add_action('wp_ajax_nopriv_ewithme_submit_review', array($this, 'handle_review_submission'));

        // AJAX handlers for author self-service cryptographic erasure via capability token (WS7 / GDPR)
        add_action('wp_ajax_ewithme_author_erase_review', array($this, 'handle_author_erasure_ajax'));
        add_action('wp_ajax_nopriv_ewithme_author_erase_review', array($this, 'handle_author_erasure_ajax'));

        // Optional WooCommerce integration
        add_action('init', array($this, 'setup_woocommerce_hooks'));
    }

    /**
     * Registers frontend styles and scripts, and enqueues them only on pages that need them.
     */
    public function register_and_maybe_enqueue_assets() {
        wp_register_style(
            'ewithme-widget-style',
            EWITHME_REVIEWS_PLUGIN_URL . 'public/css/ewithme-widget.css',
            array(),
            EWITHME_REVIEWS_VERSION
        );

        wp_register_script(
            'ewithme-widget-script',
            EWITHME_REVIEWS_PLUGIN_URL . 'public/js/ewithme-widget.js',
            array('jquery'),
            EWITHME_REVIEWS_VERSION,
            true
        );

        $config = get_option('ewithme_trust_config', array());

        // Scope brand accent colors strictly to plugin container selectors (never pollute global :root)
        $star_color = !empty($config['star_color']) ? sanitize_hex_color($config['star_color']) : '#f59e0b';
        $btn_color  = !empty($config['btn_color']) ? sanitize_hex_color($config['btn_color']) : '#2563eb';
        if ($star_color !== '#f59e0b' || $btn_color !== '#2563eb') {
            $custom_css = sprintf(
                '.ew-reviews-container, .ew-badge-pill, .ew-audit-badge-wrap, .ew-disclosure-box { --ew-gold: %s; --ew-primary: %s; }',
                esc_attr($star_color ?: '#f59e0b'),
                esc_attr($btn_color ?: '#2563eb')
            );
            wp_add_inline_style('ewithme-widget-style', $custom_css);
        }

        wp_localize_script('ewithme-widget-script', 'ewithmeReviewsData', array(
            'ajax_url'  => admin_url('admin-ajax.php'),
            'nonce'     => wp_create_nonce('ewithme_review_nonce'),
            'connected' => Ewithme_Api_Client::is_connected() ? 1 : 0
        ));

        // Automatically enqueue if on a WooCommerce single product page with auto-inject enabled, or if post content has our shortcodes
        global $post;
        $is_woo_product = (function_exists('is_product') && is_product()) || is_singular('product');
        if ($is_woo_product && !empty($config['auto_inject_woo'])) {
            self::enqueue_assets();
        } elseif ($post && is_a($post, 'WP_Post')) {
            if (
                has_shortcode($post->post_content, 'ewithme_reviews') ||
                has_shortcode($post->post_content, 'ewithme_badge') ||
                has_shortcode($post->post_content, 'ewithme_disclosure') ||
                has_shortcode($post->post_content, 'ewithme_audit_badge')
            ) {
                self::enqueue_assets();
            }
        }
    }

    /**
     * Enqueues the registered widget style and script on demand when a widget or shortcode renders.
     */
    public static function enqueue_assets() {
        if (self::$assets_enqueued) {
            return;
        }
        wp_enqueue_style('ewithme-widget-style');
        wp_enqueue_script('ewithme-widget-script');
        self::$assets_enqueued = true;
    }

    /**
     * Sets up WooCommerce automatic injection and order-completion hooks.
     */
    public function setup_woocommerce_hooks() {
        if (!class_exists('WooCommerce')) return;

        $config = get_option('ewithme_trust_config', array());
        if (!empty($config['auto_inject_woo'])) {
            add_action('woocommerce_single_product_summary', array($this, 'render_badge_action'), 15);
            add_filter('woocommerce_product_tabs', array($this, 'add_woocommerce_reviews_tab'));
        }

        // Hook WooCommerce order completion to record purchase-verified status locally
        add_action('woocommerce_order_status_completed', array($this, 'handle_woocommerce_order_completed'));

        // WS8: Notarize native WooCommerce / CusRev reviews via comment_post hook ONLY when merchant explicitly opted in
        if (!empty($config['notarize_woo_comments']) && Ewithme_Api_Client::is_connected()) {
            add_action('comment_post', array($this, 'handle_native_review_comment'), 20, 3);
        }
    }

    /**
     * Echos the compact star rating badge under the WooCommerce product title.
     */
    public function render_badge_action() {
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Output is safely constructed and escaped internally by shortcode renderer.
        echo $this->render_badge_shortcode(array());
    }

    /**
     * Adds custom verified reviews tab to WooCommerce product pages.
     */
    public function add_woocommerce_reviews_tab($tabs) {
        $tabs['ewithme_reviews'] = array(
            'title'    => __('Verified Reviews', 'ewithme-trust-verified-reviews'),
            'priority' => 30,
            'callback' => array($this, 'render_reviews_tab_content')
        );

        // Suppress redundant native WooCommerce review tab if auto-placement is enabled (TRAP-07)
        if (isset($tabs['reviews'])) {
            unset($tabs['reviews']);
        }

        return $tabs;
    }

    /**
     * Echos the full review widget inside the WooCommerce product tabs.
     * Prevents PHP 8.x TypeError when WooCommerce passes string key as first argument.
     *
     * @param string $key
     * @param array $tab
     */
    public function render_reviews_tab_content($key = '', $tab = array()) {
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Output is safely constructed and escaped internally by shortcode renderer.
        echo $this->render_reviews_widget_shortcode(array());
    }

    /**
     * Records purchase-verified status on completed WooCommerce orders.
     * Stores only an order commitment reference without customer PII or raw transaction contents.
     * Matches both plaintext email and domain-salted email hash (TRAP-02).
     *
     * @param int $order_id
     */
    public function handle_woocommerce_order_completed($order_id) {
        if (!function_exists('wc_get_order')) return;
        $order = wc_get_order($order_id);
        if (!$order) return;

        $email = strtolower(trim($order->get_billing_email()));
        if (empty($email)) return;

        $domain_salt = get_option('ewithme_trust_salt', '');
        $email_hash = !empty($domain_salt) ? hash_hmac('sha256', $email, $domain_salt) : '';

        $items = $order->get_items();
        foreach ($items as $item) {
            $product_id = $item->get_product_id();
            if ($product_id > 0) {
                global $wpdb;
                $table = $wpdb->prefix . 'ew_reviews';
                // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Table identifier is prepared via %i.
                $wpdb->query($wpdb->prepare("
                    UPDATE %i
                    SET verification_tier = 'purchase_verified',
                        order_id = %d
                    WHERE post_id = %d AND (author_email = %s OR author_email = %s)
                ", $table, $order_id, $product_id, $email, $email_hash));
            }
        }
    }

    /**
     * Notarizes native WooCommerce or CusRev reviews submitted via standard comment form (WS8).
     * Only runs if merchant explicitly enabled 'notarize_woo_comments' in settings.
     *
     * @param int $comment_id
     * @param int|string $comment_approved
     * @param array $commentdata
     */
    public function handle_native_review_comment($comment_id, $comment_approved, $commentdata) {
        // TRAP-H02: If comment is held for moderation or marked as spam, do not notarize
        if ($comment_approved !== 1 && $comment_approved !== '1') {
            return;
        }

        $comment_type = get_comment_type($comment_id);
        if ($comment_type !== 'review' && empty($commentdata['comment_type'])) {
            $post_id = intval($commentdata['comment_post_ID'] ?? 0);
            if (!$post_id || get_post_type($post_id) !== 'product') return;
        }

        $post_id = intval($commentdata['comment_post_ID'] ?? 0);
        if ($post_id <= 0) return;

        // Initialize $rating safely from comment meta before checking $_POST (fixes PHP 8.x undefined variable warning)
        $rating = intval(get_comment_meta($comment_id, 'rating', true));
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Native WooCommerce comment submission is authenticated by WordPress core comment_post pipeline.
        if ($rating <= 0 && isset($_POST['rating'])) {
            // phpcs:ignore WordPress.Security.NonceVerification.Missing
            $rating = intval(sanitize_text_field(wp_unslash($_POST['rating'])));
        }
        if ($rating <= 0) {
            // Abort: Do not convert unrated comments or product questions into 5-star reviews (TRAP-H02)
            return;
        }
        $rating = min(5, max(1, $rating));

        $author_email = sanitize_email($commentdata['comment_author_email'] ?? '');
        $purchase_info = self::verify_purchase_status($author_email, $post_id);
        $tier = (!empty($purchase_info['tier']) && $purchase_info['tier'] === 'purchase_verified') ? 'purchase_verified' : 'native_imported';

        global $wpdb;
        $table = $wpdb->prefix . 'ew_reviews';
        // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Table identifier is prepared via %i.
        $existing = $wpdb->get_var($wpdb->prepare('SELECT id FROM %i WHERE external_review_id = %s', $table, 'woo_' . $comment_id));
        if ($existing) return;

        $author_name = sanitize_text_field($commentdata['comment_author'] ?? 'Customer');
        $review_text = wp_strip_all_tags($commentdata['comment_content'] ?? '');
        $cap_token   = Ewithme_Reviews_Activator::generate_erasure_capability_token();

        $wpdb->insert(
            $table,
            array(
                'post_id'            => $post_id,
                'author_name'        => $author_name,
                'author_email'       => $author_email,
                'rating'             => $rating,
                'title'              => '',
                'content'            => $review_text,
                'status'             => 'pending_verification',
                'verification_tier'  => $tier,
                'is_incentivized'    => 0,
                'erasure_token_hash' => $cap_token['hash'],
                'external_review_id' => 'woo_' . $comment_id,
                'created_at'         => current_time('mysql')
            ),
            array('%d', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s')
        );

        $inserted_id = $wpdb->insert_id;

        // Dispatch verification request to ewith.me central authority only if connected
        if ($inserted_id && !empty($author_email) && Ewithme_Api_Client::is_connected()) {
            Ewithme_Api_Client::request_verification(array(
                'id'            => $inserted_id,
                'product_id'    => $post_id > 0 ? (string)$post_id : '',
                'author_name'   => $author_name,
                'author_email'  => $author_email,
                'rating'        => $rating,
                'title'         => '',
                'content'       => $review_text,
                'item_name'     => get_the_title($post_id),
                'tier'          => $tier,
                'incentivized'  => 0,
                'erasure_token' => $cap_token['token']
            ));
        }
    }

    /**
     * Checks if a reviewer email corresponds to an authenticated purchaser of a product.
     *
     * @param string $author_email
     * @param int $post_id
     * @return array { tier: string, order_commitment: string|null }
     */
    public static function verify_purchase_status($author_email, $post_id) {
        $email = strtolower(trim($author_email));
        if (empty($email) || empty($post_id)) {
            return array('tier' => 'email_confirmed', 'order_commitment' => null);
        }

        if (function_exists('wc_customer_bought_product')) {
            $user = get_user_by('email', $email);
            $user_id = $user ? $user->ID : 0;
            if (wc_customer_bought_product($email, $user_id, $post_id)) {
                $commitment = hash('sha256', 'order:' . $post_id . ':' . $email);
                return array('tier' => 'purchase_verified', 'order_commitment' => $commitment);
            }
        }

        return array('tier' => 'email_confirmed', 'order_commitment' => null);
    }

    /**
     * Generates the dynamic 'How reviews are collected and verified' statement
     * based on the merchant's configured settings (EU Omnibus Directive & FTC compliant).
     *
     * @return string
     */
    public static function get_review_disclosure_statement() {
        $config = get_option('ewithme_trust_config', array());
        $only_purchasers = !empty($config['collection_source']) && $config['collection_source'] === 'verified_purchasers_only';
        $allow_incentivized = !isset($config['allow_incentivized_reviews']) || !empty($config['allow_incentivized_reviews']);

        $source_text = $only_purchasers
            ? __('customers who have completed an authenticated order on this store', 'ewithme-trust-verified-reviews')
            : __('customers who completed an order, as well as visitors who confirmed their email address', 'ewithme-trust-verified-reviews');

        $disclosure = sprintf(
            /* translators: %s: review source disclosure description */
            __('Reviews on this site are collected from %s. Reviews labelled <strong>Purchase-Verified</strong> are linked to a completed order. Reviews labelled <strong>Email-Confirmed</strong> confirm the author verified their email address without proving a purchase.', 'ewithme-trust-verified-reviews'),
            $source_text
        );

        if ($allow_incentivized) {
            $disclosure .= ' ' . __('Reviews labelled <strong>Incentivized</strong> were submitted in exchange for a benefit (such as a discount or free item) and are explicitly excluded from search engine review rich snippets.', 'ewithme-trust-verified-reviews');
        } else {
            $disclosure .= ' ' . __('Incentivized reviews are not permitted on this site.', 'ewithme-trust-verified-reviews');
        }

        $disclosure .= ' ' . __('All verified reviews are timestamped and signed via the eWith.me Trust protocol to detect post-publication edits or silent review suppression. Authors receive a self-service capability token upon submission to cryptographically erase their review plaintext at any time.', 'ewithme-trust-verified-reviews');

        return apply_filters('ewithme_review_disclosure_statement', $disclosure);
    }

    /**
     * Renders the [ewithme_disclosure] shortcode.
     */
    public function render_disclosure_shortcode($atts = array()) {
        self::enqueue_assets();
        $statement = self::get_review_disclosure_statement();
        ob_start();
        ?>
        <div class="ew-disclosure-box">
            <div class="ew-disclosure-header">
                <span class="ew-disclosure-icon">⚖️</span>
                <strong><?php esc_html_e('How reviews are collected and verified', 'ewithme-trust-verified-reviews'); ?></strong>
            </div>
            <div class="ew-disclosure-text">
                <?php echo wp_kses_post($statement); ?>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Renders independent review audit badge: [ewithme_audit_badge] (WS4)
     * Displays factual audit count and links directly to the independent domain audit page.
     */
    public function render_audit_badge_shortcode($atts = array()) {
        self::enqueue_assets();
        $config = get_option('ewithme_trust_config', array());
        $site_url = get_site_url();
        $domain = !empty($config['domain']) ? $config['domain'] : wp_parse_url($site_url, PHP_URL_HOST);
        $api_base = !empty($config['api_url']) ? untrailingslashit($config['api_url']) : (defined('EWITHME_DEFAULT_API_URL') ? EWITHME_DEFAULT_API_URL : 'https://ewith.me');
        $audit_url = $api_base . '/trust/audit/' . rawurlencode($domain);

        global $wpdb;
        $table = $wpdb->prefix . 'ew_reviews';
        // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Table identifier is prepared via %i.
        $displayed_count = intval($wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM %i WHERE status = 'verified' AND is_hidden = 0", $table)));

        ob_start();
        ?>
        <div class="ew-audit-badge-wrap">
            <?php if (Ewithme_Api_Client::is_connected()) : ?>
                <a href="<?php echo esc_url($audit_url); ?>" target="_blank" rel="noopener" class="ew-audit-badge-link" title="<?php esc_attr_e('View Independent Review Audit', 'ewithme-trust-verified-reviews'); ?>">
                    <span class="ew-audit-badge-icon">🛡️</span>
                    <span class="ew-audit-badge-title"><?php esc_html_e('Review Audit', 'ewithme-trust-verified-reviews'); ?>:</span>
                    <span class="ew-audit-badge-state"><?php
                        /* translators: %d: number of verified reviews displayed */
                        printf(esc_html__('Displayed: %d · Audited by eWith.me', 'ewithme-trust-verified-reviews'), intval($displayed_count));
                    ?></span>
                    <span class="ew-audit-badge-arrow">↗</span>
                </a>
            <?php else : ?>
                <span class="ew-audit-badge-link">
                    <span class="ew-audit-badge-icon">🛡️</span>
                    <span class="ew-audit-badge-title"><?php esc_html_e('Verified Reviews', 'ewithme-trust-verified-reviews'); ?>:</span>
                    <span class="ew-audit-badge-state"><?php
                        /* translators: %d: number of verified reviews displayed */
                        printf(esc_html__('Displayed: %d', 'ewithme-trust-verified-reviews'), intval($displayed_count));
                    ?></span>
                </span>
            <?php endif; ?>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Renders the compact star badge: ★★★★★ 4.9 (42) [✔ Verified by ewith.me]
     */
    public function render_badge_shortcode($atts = array()) {
        self::enqueue_assets();
        global $wpdb, $post;
        $post_id = (is_array($atts) && !empty($atts['post_id'])) ? intval($atts['post_id']) : (isset($post->ID) ? $post->ID : 0);

        if (!$post_id) return '';

        $table = $wpdb->prefix . 'ew_reviews';
        // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Table identifier is prepared via %i.
        $summary = $wpdb->get_row($wpdb->prepare("
            SELECT COUNT(*) as cnt, AVG(rating) as avg_rating
            FROM %i
            WHERE post_id = %d AND status = 'verified' AND is_hidden = 0
        ", $table, $post_id));

        $count = $summary ? intval($summary->cnt) : 0;
        $rating = $summary && $count > 0 ? round(floatval($summary->avg_rating), 1) : 5.0;
        $stars_html = str_repeat('★', intval(round($rating))) . str_repeat('☆', 5 - intval(round($rating)));

        ob_start();
        ?>
        <div class="ew-badge-pill" data-post-id="<?php echo esc_attr($post_id); ?>">
            <span class="ew-badge-stars"><?php echo esc_html($stars_html); ?></span>
            <span class="ew-badge-score"><?php echo esc_html($rating); ?></span>
            <span class="ew-badge-count">(<?php echo esc_html($count); ?>)</span>
            <span class="ew-badge-stamp">
                <span class="ew-badge-check">✔</span>
                <?php if (Ewithme_Api_Client::is_connected()) : ?>
                    <?php esc_html_e('Verified by', 'ewithme-trust-verified-reviews'); ?> <a href="https://ewith.me/trust" target="_blank" rel="noopener">eWith.me</a>
                <?php else : ?>
                    <?php esc_html_e('Verified Reviews', 'ewithme-trust-verified-reviews'); ?>
                <?php endif; ?>
            </span>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Renders the full reviews section with rating overview, cards, write review modal, and author self-service erasure modal.
     */
    public function render_reviews_widget_shortcode($atts = array()) {
        self::enqueue_assets();
        global $wpdb, $post;
        $is_global = (is_array($atts) && !empty($atts['scope']) && $atts['scope'] === 'all');
        $post_id = (is_array($atts) && !empty($atts['post_id'])) ? intval($atts['post_id']) : (isset($post->ID) ? $post->ID : 0);

        if (!$is_global && !$post_id) return '';

        $table = $wpdb->prefix . 'ew_reviews';

        if ($is_global) {
            // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Table identifier is prepared via %i.
            $summary = $wpdb->get_row($wpdb->prepare("
                SELECT COUNT(*) as cnt, AVG(rating) as avg_rating
                FROM %i
                WHERE status = 'verified' AND is_hidden = 0
            ", $table));
            // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Table identifier is prepared via %i.
            $reviews = $wpdb->get_results($wpdb->prepare("
                SELECT * FROM %i
                WHERE status = 'verified' AND is_hidden = 0
                ORDER BY verified_at DESC, created_at DESC
                LIMIT 50
            ", $table));
        } else {
            // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Table identifier is prepared via %i.
            $summary = $wpdb->get_row($wpdb->prepare("
                SELECT COUNT(*) as cnt, AVG(rating) as avg_rating
                FROM %i
                WHERE post_id = %d AND status = 'verified' AND is_hidden = 0
            ", $table, $post_id));

            // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Table identifier is prepared via %i.
            $reviews = $wpdb->get_results($wpdb->prepare("
                SELECT * FROM %i
                WHERE post_id = %d AND status = 'verified' AND is_hidden = 0
                ORDER BY verified_at DESC, created_at DESC
                LIMIT 50
            ", $table, $post_id));
        }

        $count = $summary ? intval($summary->cnt) : 0;
        $avg_rating = $summary && $count > 0 ? round(floatval($summary->avg_rating), 1) : 5.0;

        ob_start();
        ?>
        <div class="ew-reviews-container" id="ewithme-reviews-<?php echo esc_attr($post_id ?: 'global'); ?>">
            <!-- Header Summary Box -->
            <div class="ew-summary-card">
                <div class="ew-score-column">
                    <div class="ew-big-score"><?php echo esc_html($avg_rating); ?></div>
                    <div class="ew-stars-gold"><?php echo esc_html(str_repeat('★', intval(round($avg_rating)))); ?></div>
                    <div class="ew-score-label"><?php
                        /* translators: %d: number of verified reviews */
                        printf(esc_html__('Based on %d verified reviews', 'ewithme-trust-verified-reviews'), intval($count));
                    ?></div>
                </div>
                <div class="ew-trust-stamp-column">
                    <?php if (Ewithme_Api_Client::is_connected()) : ?>
                        <div class="ew-trust-mark">
                            <span class="ew-check-icon">✔</span> <?php esc_html_e('Cryptographically Verified', 'ewithme-trust-verified-reviews'); ?>
                        </div>
                        <p class="ew-trust-desc"><?php esc_html_e('Reviews are authenticated through single-use confirmation links by eWith.me.', 'ewithme-trust-verified-reviews'); ?></p>
                    <?php else : ?>
                        <div class="ew-trust-mark">
                            <span class="ew-check-icon">✔</span> <?php esc_html_e('Verified Customer Reviews', 'ewithme-trust-verified-reviews'); ?>
                        </div>
                        <p class="ew-trust-desc"><?php esc_html_e('Reviews are stored and managed locally on this store.', 'ewithme-trust-verified-reviews'); ?></p>
                    <?php endif; ?>
                    <div style="display:flex;gap:8px;justify-content:flex-end;flex-wrap:wrap;">
                        <?php if (!$is_global && $post_id > 0) : ?>
                            <button type="button" class="ew-btn-primary ew-open-modal-btn" data-post-id="<?php echo esc_attr($post_id); ?>" data-item-name="<?php echo esc_attr(get_the_title($post_id)); ?>">
                                <?php esc_html_e('Write a Review', 'ewithme-trust-verified-reviews'); ?>
                            </button>
                        <?php endif; ?>
                        <button type="button" class="ew-btn-secondary ew-open-erase-modal-btn" style="background:#ffffff;border:1px solid #d1d5db;color:#4b5563;font-size:12px;padding:8px 12px;border-radius:8px;cursor:pointer;">
                            🗑️ <?php esc_html_e('Self-Service Erasure (GDPR)', 'ewithme-trust-verified-reviews'); ?>
                        </button>
                    </div>
                </div>
            </div>

            <?php
            $config = get_option('ewithme_trust_config', array());
            if (!isset($config['show_disclosure_in_widget']) || !empty($config['show_disclosure_in_widget'])) :
            ?>
                <div class="ew-widget-disclosure">
                    <details class="ew-disclosure-details">
                        <summary class="ew-disclosure-summary">
                            <span>⚖️ <?php esc_html_e('How reviews are collected and verified', 'ewithme-trust-verified-reviews'); ?></span>
                        </summary>
                        <div class="ew-disclosure-content">
                            <?php echo wp_kses_post(self::get_review_disclosure_statement()); ?>
                        </div>
                    </details>
                </div>
            <?php endif; ?>

            <!-- Review Cards List -->
            <div class="ew-review-cards-list">
                <?php if (empty($reviews)) : ?>
                    <div class="ew-no-reviews">
                        <p><?php esc_html_e('No verified reviews yet. Be the first to review!', 'ewithme-trust-verified-reviews'); ?></p>
                    </div>
                <?php else : ?>
                    <?php foreach ($reviews as $rev) : ?>
                        <?php
                        $name = sanitize_text_field($rev->author_name);
                        $parts = explode(' ', $name);
                        $last = end($parts);
                        $initial = function_exists('mb_substr') ? mb_substr($last, 0, 1, 'UTF-8') : substr($last, 0, 1);
                        $upper_initial = function_exists('mb_strtoupper') ? mb_strtoupper($initial, 'UTF-8') : strtoupper($initial);
                        $display_name = (count($parts) > 1) ? $parts[0] . ' ' . $upper_initial . '.' : $name;
                        $is_purchase = (!empty($rev->verification_tier) && $rev->verification_tier === 'purchase_verified');
                        $is_imported = (!empty($rev->verification_tier) && $rev->verification_tier === 'native_imported');
                        if ($is_purchase) {
                            $tier_label = __('Purchase-Verified', 'ewithme-trust-verified-reviews');
                            $tier_class = 'ew-tier-purchase';
                            $tier_icon  = '🛍️';
                        } elseif ($is_imported) {
                            $tier_label = __('Store-Imported', 'ewithme-trust-verified-reviews');
                            $tier_class = 'ew-tier-imported';
                            $tier_icon  = '📥';
                        } else {
                            $tier_label = __('Email-Confirmed', 'ewithme-trust-verified-reviews');
                            $tier_class = 'ew-tier-email';
                            $tier_icon  = '✔';
                        }
                        ?>
                        <div class="ew-card" data-certificate-id="<?php echo esc_attr($rev->certificate_id ?? ''); ?>">
                            <div class="ew-card-header">
                                <div>
                                    <span class="ew-card-stars"><?php echo esc_html(str_repeat('★', intval($rev->rating))); ?></span>
                                    <strong class="ew-card-author"><?php echo esc_html($display_name); ?></strong>
                                </div>
                                <div class="ew-card-badges">
                                    <div class="ew-card-verified-badge <?php echo esc_attr($tier_class); ?>">
                                        <span class="ew-badge-icon"><?php echo esc_html($tier_icon); ?></span> <?php echo esc_html($tier_label); ?>
                                    </div>
                                    <?php if (!empty($rev->is_incentivized)) : ?>
                                        <div class="ew-card-verified-badge ew-tier-incentivized">
                                            <span class="ew-badge-icon">🎁</span> <?php esc_html_e('Incentivized', 'ewithme-trust-verified-reviews'); ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <?php if (!empty($rev->title)) : ?>
                                <h4 class="ew-card-title"><?php echo esc_html($rev->title); ?></h4>
                            <?php endif; ?>
                            <p class="ew-card-text"><?php echo esc_html($rev->content); ?></p>
                            <?php if (!empty($rev->reply_content)) : ?>
                                <div class="ew-merchant-reply">
                                    <div class="ew-merchant-reply-header">
                                        <span class="ew-merchant-badge">🏪 <?php esc_html_e('Store Owner Response', 'ewithme-trust-verified-reviews'); ?></span>
                                        <?php if (!empty($rev->replied_at)) : ?>
                                            <span class="ew-merchant-date"><?php echo esc_html(date_i18n(get_option('date_format'), strtotime($rev->replied_at))); ?></span>
                                        <?php endif; ?>
                                    </div>
                                    <p class="ew-merchant-reply-text"><?php echo esc_html($rev->reply_content); ?></p>
                                </div>
                            <?php endif; ?>
                            <div class="ew-card-footer">
                                <span class="ew-card-date"><?php echo esc_html(date_i18n(get_option('date_format'), strtotime($rev->created_at))); ?></span>
                                <?php if (!empty($rev->certificate_url)) : ?>
                                    <a href="<?php echo esc_url($rev->certificate_url); ?>" target="_blank" rel="noopener" class="ew-cert-link">
                                        <?php esc_html_e('Independent Certificate ↗', 'ewithme-trust-verified-reviews'); ?>
                                    </a>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <?php if (!$is_global && $post_id > 0) : ?>
                <!-- Write Review Modal -->
                <div class="ew-modal-overlay ew-write-review-modal" style="display:none;">
                    <div class="ew-modal-content">
                        <div class="ew-modal-header">
                            <h3><?php esc_html_e('Write a Verified Review', 'ewithme-trust-verified-reviews'); ?></h3>
                            <button type="button" class="ew-modal-close">&times;</button>
                        </div>
                        <form class="ew-review-form">
                            <input type="hidden" name="post_id" value="<?php echo esc_attr($post_id); ?>">
                            <input type="hidden" name="item_name" value="<?php echo esc_attr(get_the_title($post_id)); ?>">

                            <!-- Anti-Spam Honeypot & Timestamp Guard (TRAP-H04 & HIGH-03) -->
                            <div style="position:absolute;left:-9999px;opacity:0;height:0;overflow:hidden;" aria-hidden="true">
                                <label for="ew_hp_check" class="screen-reader-text"><?php esc_html_e('Do not fill this field', 'ewithme-trust-verified-reviews'); ?></label>
                                <input type="text" id="ew_hp_check" name="ew_hp_check" value="" tabindex="-1" autocomplete="off">
                                <input type="hidden" name="ew_rendered_ts" value="<?php echo esc_attr(time()); ?>">
                            </div>

                            <div class="ew-form-group">
                                <label><?php esc_html_e('Your Rating *', 'ewithme-trust-verified-reviews'); ?></label>
                                <div class="ew-star-rating-select">
                                    <span class="ew-star-opt" data-val="5">★</span>
                                    <span class="ew-star-opt" data-val="4">★</span>
                                    <span class="ew-star-opt" data-val="3">★</span>
                                    <span class="ew-star-opt" data-val="2">★</span>
                                    <span class="ew-star-opt" data-val="1">★</span>
                                </div>
                                <input type="hidden" name="rating" value="5">
                            </div>

                            <div class="ew-form-row">
                                <div class="ew-form-group">
                                    <label><?php esc_html_e('Your Name *', 'ewithme-trust-verified-reviews'); ?></label>
                                    <input type="text" name="author_name" required placeholder="e.g. Marcus Johnson">
                                </div>
                                <div class="ew-form-group">
                                    <label><?php esc_html_e('Your Email *', 'ewithme-trust-verified-reviews'); ?></label>
                                    <input type="email" name="author_email" required placeholder="name@example.com">
                                    <small class="ew-help"><?php esc_html_e('Used once to send your verification link. Never published.', 'ewithme-trust-verified-reviews'); ?></small>
                                </div>
                            </div>

                            <div class="ew-form-group">
                                <label><?php esc_html_e('Review Headline', 'ewithme-trust-verified-reviews'); ?></label>
                                <input type="text" name="title" placeholder="Summary of your experience">
                            </div>

                            <div class="ew-form-group">
                                <label><?php esc_html_e('Review Comments *', 'ewithme-trust-verified-reviews'); ?></label>
                                <textarea name="content" rows="4" required placeholder="What did you like or dislike?"></textarea>
                            </div>

                            <div class="ew-form-group ew-incentive-group">
                                <label class="ew-checkbox-label">
                                    <input type="checkbox" name="is_incentivized" value="1">
                                    <span><?php esc_html_e('I received a benefit, discount, or free item for this review (Incentivized Review)', 'ewithme-trust-verified-reviews'); ?></span>
                                </label>
                            </div>

                            <div class="ew-form-actions">
                                <button type="submit" class="ew-btn-primary ew-submit-btn"><?php esc_html_e('Submit for Verification', 'ewithme-trust-verified-reviews'); ?></button>
                            </div>
                            <div class="ew-form-status" style="display:none;"></div>
                        </form>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Self-Service Cryptographic Review Erasure Modal (WS7 / GDPR Capability Token Flow) -->
            <div class="ew-modal-overlay ew-erase-review-modal" style="display:none;">
                <div class="ew-modal-content">
                    <div class="ew-modal-header">
                        <h3><?php esc_html_e('Author Self-Service Cryptographic Erasure', 'ewithme-trust-verified-reviews'); ?></h3>
                        <button type="button" class="ew-modal-close">&times;</button>
                    </div>
                    <form class="ew-erase-form">
                        <p style="font-size:13px;color:#4b5563;margin:0 0 14px;line-height:1.5;">
                            <?php esc_html_e('Enter the Erasure Capability Token issued to you when you submitted your review. Your review text, headline, and personal data will be permanently wiped from this storefront and the cryptographic ledger while maintaining hash chain continuity.', 'ewithme-trust-verified-reviews'); ?>
                        </p>
                        <div class="ew-form-group">
                            <label><?php esc_html_e('Erasure Capability Token *', 'ewithme-trust-verified-reviews'); ?></label>
                            <input type="text" name="erasure_token" required placeholder="ew_erase_..." autocomplete="off">
                        </div>
                        <div class="ew-form-group">
                            <label><?php esc_html_e('Certificate ID (Optional)', 'ewithme-trust-verified-reviews'); ?></label>
                            <input type="text" name="certificate_id" placeholder="e.g. cert_..." autocomplete="off">
                        </div>
                        <div class="ew-form-actions">
                            <button type="submit" class="ew-btn-primary ew-erase-submit-btn" style="background:#dc2626;">
                                <?php esc_html_e('Permanently Erase My Review', 'ewithme-trust-verified-reviews'); ?>
                            </button>
                        </div>
                        <div class="ew-erase-status ew-form-status" style="display:none;"></div>
                    </form>
                </div>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Handles review submission via AJAX from the modal form.
     * Generates and returns an author self-service capability token (`erasure_token`) for GDPR erasure.
     */
    public function handle_review_submission() {
        check_ajax_referer('ewithme_review_nonce', 'nonce');

        // TRAP-H04: Honeypot check: silently exit with success if filled by bot
        if (!empty($_POST['ew_hp_check'])) {
            wp_send_json_success(array(
                'message' => __('Thank you! A confirmation link has been sent to your email to verify your review.', 'ewithme-trust-verified-reviews')
            ));
        }

        // HIGH-03: Bot submission speed check (reject sub-second programmatic submissions)
        $rendered_ts = intval($_POST['ew_rendered_ts'] ?? 0);
        if ($rendered_ts > 0 && (time() - $rendered_ts) < 2) {
            wp_send_json_error(array(
                'message' => __('Submission failed. Please take your time to write a thoughtful review.', 'ewithme-trust-verified-reviews')
            ));
        }

        $post_id         = intval($_POST['post_id'] ?? 0);
        $author_name     = sanitize_text_field(wp_unslash($_POST['author_name'] ?? ''));
        $author_email    = sanitize_email(wp_unslash($_POST['author_email'] ?? ''));
        $rating          = intval($_POST['rating'] ?? 5);
        $title           = sanitize_text_field(wp_unslash($_POST['title'] ?? ''));
        $content         = sanitize_textarea_field(wp_unslash($_POST['content'] ?? ''));
        $item_name       = sanitize_text_field(wp_unslash($_POST['item_name'] ?? ''));
        $is_incentivized = !empty($_POST['is_incentivized']) ? 1 : 0;

        if ($post_id <= 0) {
            wp_send_json_error(array('message' => __('Reviews must be submitted for an eligible product or post.', 'ewithme-trust-verified-reviews')));
        }

        if (!$author_name || !$author_email || !$content) {
            wp_send_json_error(array('message' => __('Please complete all required fields.', 'ewithme-trust-verified-reviews')));
        }

        if ($rating < 1 || $rating > 5) {
            $rating = 5;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'ew_reviews';

        $purchase_info = self::verify_purchase_status($author_email, $post_id);
        $tier = $purchase_info['tier'];

        // Generate author self-service capability token for GDPR cryptographic erasure (WS7)
        $cap_token = Ewithme_Reviews_Activator::generate_erasure_capability_token();

        // 1. Insert review locally as 'pending' with capability token hash
        $wpdb->insert(
            $table,
            array(
                'post_id'            => $post_id,
                'author_name'        => $author_name,
                'author_email'       => $author_email,
                'rating'             => $rating,
                'title'              => $title,
                'content'            => $content,
                'status'             => 'pending',
                'verification_tier'  => $tier,
                'is_incentivized'    => $is_incentivized,
                'erasure_token_hash' => $cap_token['hash'],
                'created_at'         => current_time('mysql', 1)
            ),
            array('%d', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%d', '%s', '%s')
        );

        $inserted_id = $wpdb->insert_id;

        // 2. Dispatch verification request to ewith.me central authority ONLY if administrator opted in and connected API key
        $dispatch_res = array('success' => false);
        if (Ewithme_Api_Client::is_connected()) {
            $dispatch_res = Ewithme_Api_Client::request_verification(array(
                'id'            => $inserted_id,
                'product_id'    => $post_id > 0 ? (string)$post_id : '',
                'author_name'   => $author_name,
                'author_email'  => $author_email,
                'rating'        => $rating,
                'title'         => $title,
                'content'       => $content,
                'item_name'     => $item_name,
                'tier'          => $tier,
                'incentivized'  => $is_incentivized,
                'erasure_token' => $cap_token['token']
            ));
        }

        if (!empty($dispatch_res['success'])) {
            wp_send_json_success(array(
                'erasure_token' => $cap_token['token'],
                'message'       => sprintf(
                    /* translators: %s: author self-service erasure capability token */
                    __('Thank you! A confirmation link has been sent to your email. Save your self-service Erasure Capability Token for future privacy removal: %s', 'ewithme-trust-verified-reviews'),
                    $cap_token['token']
                )
            ));
        } else {
            // Even if external API is not yet connected by merchant, confirm local storage and provide erasure token
            wp_send_json_success(array(
                'erasure_token' => $cap_token['token'],
                'message'       => sprintf(
                    /* translators: %s: author self-service erasure capability token */
                    __('Your review has been saved locally pending verification. Save your self-service Erasure Capability Token for future privacy removal: %s', 'ewithme-trust-verified-reviews'),
                    $cap_token['token']
                )
            ));
        }
    }

    /**
     * Handles AJAX author self-service cryptographic erasure requests from the frontend modal.
     */
    public function handle_author_erasure_ajax() {
        check_ajax_referer('ewithme_review_nonce', 'nonce');

        $erasure_token = isset($_POST['erasure_token']) ? sanitize_text_field(wp_unslash($_POST['erasure_token'])) : '';
        $cert_id       = isset($_POST['certificate_id']) ? sanitize_text_field(wp_unslash($_POST['certificate_id'])) : '';

        if (empty($erasure_token)) {
            wp_send_json_error(array(
                'message' => __('Please enter your Erasure Capability Token.', 'ewithme-trust-verified-reviews')
            ));
        }

        $result = Ewithme_Webhook_Handler::execute_capability_token_erasure($erasure_token, $cert_id);
        if (!empty($result['success'])) {
            wp_send_json_success($result);
        } else {
            wp_send_json_error($result);
        }
    }
}
