<?php
/**
 * Admin management dashboard, moderation screen, and quota meter.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Ewithme_Admin_Settings {

    /**
     * Initializes admin menus and handlers.
     */
    public function init() {
        add_action('admin_menu', array($this, 'register_admin_menus'));
        add_action('admin_init', array($this, 'handle_admin_actions'));
        add_action('admin_notices', array($this, 'render_admin_notices'));
    }

    /**
     * Registers top-level and submenu items.
     */
    public function register_admin_menus() {
        add_menu_page(
            __('Ewithme Trust Reviews', 'ewithme-trust-verified-reviews'),
            __('Trust Reviews', 'ewithme-trust-verified-reviews'),
            'manage_options',
            'ewithme-trust-verified-reviews',
            array($this, 'render_reviews_page'),
            'dashicons-shield-alt',
            56
        );

        add_submenu_page(
            'ewithme-trust-verified-reviews',
            __('All Reviews', 'ewithme-trust-verified-reviews'),
            __('All Reviews', 'ewithme-trust-verified-reviews'),
            'manage_options',
            'ewithme-trust-verified-reviews',
            array($this, 'render_reviews_page')
        );

        add_submenu_page(
            'ewithme-trust-verified-reviews',
            __('Settings', 'ewithme-trust-verified-reviews'),
            __('Settings', 'ewithme-trust-verified-reviews'),
            'manage_options',
            'ewithme-settings',
            array($this, 'render_settings_page')
        );
    }

    /**
     * Handles review status updates (hide/delete), explicit opt-in provisioning, and settings saving.
     */
    public function handle_admin_actions() {
        if (!current_user_can('manage_options')) return;

        // 0. Handle Explicit Administrator Opt-In Domain Provisioning (Guidelines 7 & 9)
        if (isset($_POST['ewithme_optin_provision']) && check_admin_referer('ewithme_optin_provision_action')) {
            if (empty($_POST['ewithme_external_service_consent'])) {
                set_transient('ewithme_domain_verify_error', __('Please check the consent box to authorize connecting to the eWith.me external verification service.', 'ewithme-trust-verified-reviews'), 60);
                wp_safe_redirect(admin_url('admin.php?page=ewithme-settings&domain_error=1'));
                exit;
            }

            $include_email = !empty($_POST['ewithme_share_admin_email']);
            $res = Ewithme_Reviews_Activator::provision_domain_with_consent($include_email);
            if (!empty($res['success'])) {
                wp_safe_redirect(admin_url('admin.php?page=ewithme-settings&provisioned=1'));
            } else {
                set_transient('ewithme_domain_verify_error', $res['message'], 60);
                wp_safe_redirect(admin_url('admin.php?page=ewithme-settings&domain_error=1'));
            }
            exit;
        }

        // Handle Review Actions (Strictly POST with nonce check)
        $action = isset($_POST['ewithme_review_action']) ? sanitize_text_field(wp_unslash($_POST['ewithme_review_action'])) : '';
        $review_id = isset($_POST['review_id']) ? intval($_POST['review_id']) : 0;

        if (!empty($action) && $review_id > 0 && check_admin_referer('ewithme_review_action')) {
            global $wpdb;
            $table = $wpdb->prefix . 'ew_reviews';

            if ($action === 'approve') {
                // Local mode approval for reviews when external verification service is not connected
                $wpdb->update($table, array(
                    'status'      => 'verified',
                    'verified_at' => current_time('mysql', 1)
                ), array('id' => $review_id));
            } elseif ($action === 'hide') {
                // CRIT-02: Reversible local storefront hiding. Does NOT erase certificate from ledger.
                $wpdb->update($table, array('is_hidden' => 1), array('id' => $review_id));
            } elseif ($action === 'show') {
                // CRIT-02: Restore local storefront visibility.
                $wpdb->update($table, array('is_hidden' => 0), array('id' => $review_id));
            } elseif ($action === 'erase') {
                // Permanent FTC/GDPR ledger moderation/erasure
                $allowed_reasons = array('profanity_or_abuse', 'suspected_fake', 'unrelated_to_product', 'legal_or_privacy_request', 'author_requested');
                $raw_reason = isset($_POST['moderation_reason']) ? sanitize_text_field(wp_unslash($_POST['moderation_reason'])) : 'profanity_or_abuse';
                $reason = in_array($raw_reason, $allowed_reasons, true) ? $raw_reason : 'profanity_or_abuse';

                // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Table identifier is prepared via %i.
                $rev = $wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE id = %d', $table, $review_id));
                if ($rev && !empty($rev->certificate_id) && Ewithme_Api_Client::is_connected()) {
                    Ewithme_Api_Client::request_author_erasure('', $rev->certificate_id, $reason);
                }

                $wpdb->update($table, array(
                    'status'       => 'erased',
                    'is_hidden'    => 1,
                    'content'      => '',
                    'title'        => '',
                    'author_email' => ''
                ), array('id' => $review_id));
            } elseif ($action === 'post_reply') {
                $raw_reply = isset($_POST['reply_content']) ? wp_strip_all_tags(wp_unslash($_POST['reply_content'])) : '';
                $trimmed_reply = mb_substr(trim($raw_reply), 0, 1000);
                $wpdb->update($table, array(
                    'reply_content' => $trimmed_reply,
                    'replied_at'    => current_time('mysql', 1),
                    'replied_by'    => get_current_user_id()
                ), array('id' => $review_id));
            } elseif ($action === 'delete_reply') {
                $wpdb->update($table, array(
                    'reply_content' => null,
                    'replied_at'    => null,
                    'replied_by'    => null
                ), array('id' => $review_id));
            } elseif ($action === 'delete') {
                $wpdb->delete($table, array('id' => $review_id));
            }

            wp_safe_redirect(admin_url('admin.php?page=ewithme-trust-verified-reviews&updated=1'));
            exit;
        }

        // Handle Bulk Review Actions from WP_List_Table
        $bulk_action = isset($_POST['action']) ? sanitize_text_field(wp_unslash($_POST['action'])) : '';
        if (empty($bulk_action) && isset($_POST['action2'])) {
            $bulk_action = sanitize_text_field(wp_unslash($_POST['action2']));
        }
        if (!empty($bulk_action) && !empty($_POST['review_ids']) && is_array($_POST['review_ids']) && check_admin_referer('bulk-reviews')) {
            $ids = array_map('intval', $_POST['review_ids']);
            if (!empty($ids)) {
                global $wpdb;
                $table = $wpdb->prefix . 'ew_reviews';
                $placeholders = implode(', ', array_fill(0, count($ids), '%d'));
                $query_params = array_merge(array($table), $ids);
                if ($bulk_action === 'bulk_hide') {
                    // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Table identifier is prepared with %i and $placeholders contains prepared %d placeholders only.
                    $wpdb->query($wpdb->prepare("UPDATE %i SET is_hidden = 1 WHERE id IN ($placeholders)", $query_params));
                    wp_safe_redirect(admin_url('admin.php?page=ewithme-trust-verified-reviews&bulk_updated=' . count($ids)));
                    exit;
                } elseif ($bulk_action === 'bulk_show') {
                    // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Table identifier is prepared with %i and $placeholders contains prepared %d placeholders only.
                    $wpdb->query($wpdb->prepare("UPDATE %i SET is_hidden = 0 WHERE id IN ($placeholders)", $query_params));
                    wp_safe_redirect(admin_url('admin.php?page=ewithme-trust-verified-reviews&bulk_updated=' . count($ids)));
                    exit;
                }
            }
        }

        // Handle Domain Control Verification Trigger (WS5)
        if (isset($_POST['ewithme_verify_domain']) && check_admin_referer('ewithme_verify_domain_action')) {
            if (!Ewithme_Api_Client::is_connected()) {
                set_transient('ewithme_domain_verify_error', __('Please opt in to the external service and configure an API key first.', 'ewithme-trust-verified-reviews'), 60);
                wp_safe_redirect(admin_url('admin.php?page=ewithme-settings&domain_error=1'));
                exit;
            }
            $res = Ewithme_Api_Client::verify_domain_proof('auto');
            if (!empty($res['success'])) {
                wp_safe_redirect(admin_url('admin.php?page=ewithme-settings&domain_verified=1'));
            } else {
                $msg = $res['message'] ?? __('Verification failed.', 'ewithme-trust-verified-reviews');
                set_transient('ewithme_domain_verify_error', $msg, 60);
                wp_safe_redirect(admin_url('admin.php?page=ewithme-settings&domain_error=1'));
            }
            exit;
        }

        // Handle Ledger Synchronization Trigger
        if (isset($_POST['ewithme_sync_ledger']) && check_admin_referer('ewithme_sync_ledger_action')) {
            if (!Ewithme_Api_Client::is_connected()) {
                wp_safe_redirect(admin_url('admin.php?page=ewithme-trust-verified-reviews'));
                exit;
            }
            $res = Ewithme_Api_Client::sync_ledger_feed();
            $reconciled = intval($res['reconciled'] ?? 0);
            wp_safe_redirect(admin_url('admin.php?page=ewithme-trust-verified-reviews&synced=' . $reconciled));
            exit;
        }

        // Handle Settings Save
        if (isset($_POST['ewithme_save_settings']) && check_admin_referer('ewithme_save_settings_action')) {
            $config = get_option('ewithme_trust_config', array());
            $config['api_key']                    = sanitize_text_field(wp_unslash($_POST['api_key'] ?? ''));
            $config['external_service_optin']     = !empty($_POST['external_service_optin']) ? 1 : 0;
            $config['auto_inject_woo']            = !empty($_POST['auto_inject_woo']) ? 1 : 0;
            $config['notarize_woo_comments']      = !empty($_POST['notarize_woo_comments']) ? 1 : 0;
            $config['collection_source']          = sanitize_text_field(wp_unslash($_POST['collection_source'] ?? 'all_visitors'));
            $config['allow_incentivized_reviews'] = !empty($_POST['allow_incentivized_reviews']) ? 1 : 0;
            $config['show_disclosure_in_widget']  = !empty($_POST['show_disclosure_in_widget']) ? 1 : 0;
            $config['notify_admin_on_review']     = !empty($_POST['notify_admin_on_review']) ? 1 : 0;
            $config['admin_notification_email']   = sanitize_email(wp_unslash($_POST['admin_notification_email'] ?? ''));
            $config['enable_review_invitations']  = !empty($_POST['enable_review_invitations']) ? 1 : 0;
            $config['invitation_delay_days']      = isset($_POST['invitation_delay_days']) ? max(0, intval($_POST['invitation_delay_days'])) : 7;
            $config['star_color']                 = sanitize_hex_color(wp_unslash($_POST['star_color'] ?? '#f59e0b')) ?: '#f59e0b';
            $config['btn_color']                  = sanitize_hex_color(wp_unslash($_POST['btn_color'] ?? '#2563eb')) ?: '#2563eb';
            $config['api_url']                    = esc_url_raw(wp_unslash($_POST['api_url'] ?? EWITHME_DEFAULT_API_URL));
            $config['delete_data_on_uninstall']   = !empty($_POST['delete_data_on_uninstall']) ? 1 : 0;

            update_option('ewithme_trust_config', $config);
            delete_transient('ewithme_quota_cache'); // TRAP-H08: Invalidate cached quota on settings update
            wp_safe_redirect(admin_url('admin.php?page=ewithme-settings&saved=1'));
            exit;
        }
    }

    /**
     * Renders the moderation list page with paginated, searchable WP_List_Table.
     */
    public function render_reviews_page() {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- Safe read-only status query parameters and list table rendering.
        require_once EWITHME_REVIEWS_PLUGIN_DIR . 'includes/class-reviews-list-table.php';
        $list_table = new Ewithme_Reviews_List_Table();
        $list_table->prepare_items();

        ?>
        <div class="wrap">
            <h1 style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;">
                <span style="display:flex;align-items:center;gap:10px;">
                    <span class="dashicons dashicons-shield-alt" style="font-size:32px;width:32px;height:32px;color:#2563eb;"></span>
                    <?php esc_html_e('Customer Reviews & Verification Status', 'ewithme-trust-verified-reviews'); ?>
                </span>
                <?php if (Ewithme_Api_Client::is_connected()) : ?>
                    <form method="post" action="<?php echo esc_url(admin_url('admin.php?page=ewithme-trust-verified-reviews')); ?>" style="margin:0;">
                        <?php wp_nonce_field('ewithme_sync_ledger_action'); ?>
                        <button type="submit" name="ewithme_sync_ledger" value="1" class="button button-secondary" style="display:inline-flex;align-items:center;gap:6px;">
                            🔄 <?php esc_html_e('Sync with eWith.me Ledger', 'ewithme-trust-verified-reviews'); ?>
                        </button>
                    </form>
                <?php endif; ?>
            </h1>

            <?php if (!empty($_GET['updated'])) : ?>
                <div class="notice notice-success is-dismissible" style="margin:16px 0;">
                    <p><?php esc_html_e('Review status updated successfully.', 'ewithme-trust-verified-reviews'); ?></p>
                </div>
            <?php endif; ?>

            <?php if (isset($_GET['bulk_updated'])) : ?>
                <div class="notice notice-success is-dismissible" style="margin:16px 0;">
                    <p><?php
                        /* translators: %d: number of reviews updated */
                        printf(esc_html__('Bulk action applied to %d reviews successfully.', 'ewithme-trust-verified-reviews'), intval($_GET['bulk_updated']));
                    ?></p>
                </div>
            <?php endif; ?>

            <?php if (isset($_GET['synced'])) : ?>
                <div class="notice notice-info is-dismissible" style="margin:16px 0;">
                    <p><?php
                        /* translators: %d: number of reconciled review records */
                        printf(esc_html__('Ledger synchronization complete. Reconciled %d review records.', 'ewithme-trust-verified-reviews'), intval($_GET['synced']));
                    ?></p>
                </div>
            <?php endif; ?>

            <div class="notice notice-info" style="margin:16px 0;">
                <p>
                    ⚖️ <strong><?php esc_html_e('Consumer Transparency & Anti-Suppression Policy:', 'ewithme-trust-verified-reviews'); ?></strong>
                    <?php esc_html_e('Hiding reviews locally without an FTC-compliant reason is tracked on your independent eWith.me audit badge as "Unexplained review suppression". To legitimately remove abusive, fake, or legally restricted reviews from the cryptographic ledger, use "Moderate" below with an official reason.', 'ewithme-trust-verified-reviews'); ?>
                </p>
            </div>

            <form method="get" action="<?php echo esc_url(admin_url('admin.php')); ?>">
                <input type="hidden" name="page" value="ewithme-trust-verified-reviews" />
                <?php
                $list_table->search_box(__('Search Reviews', 'ewithme-trust-verified-reviews'), 'review_search');
                $list_table->display();
                ?>
            </form>
        </div>
        <?php
        // phpcs:enable WordPress.Security.NonceVerification.Recommended
    }

    /**
     * Renders settings, explicit opt-in connection card, API keys, and protocol status card.
     */
    public function render_settings_page() {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- Safe read-only status query parameters for admin page rendering.
        $config = get_option('ewithme_trust_config', array());
        $domain = $config['domain'] ?? wp_parse_url(get_site_url(), PHP_URL_HOST);
        $profile_url = $config['profile_url'] ?? 'https://ewith.me/trust';
        $is_connected = Ewithme_Api_Client::is_connected();

        // LOW-01: Live quota meter resolution with 1-hour transient caching (only if connected)
        $quota = false;
        if ($is_connected) {
            $quota = get_transient('ewithme_quota_cache');
            if ($quota === false) {
                $quota = Ewithme_Api_Client::check_quota();
                if ($quota) {
                    set_transient('ewithme_quota_cache', $quota, HOUR_IN_SECONDS);
                }
            }
        }

        $plan_name = !empty($quota['plan']) ? ucfirst($quota['plan']) : (!empty($config['plan']) ? ucfirst($config['plan']) : 'Free');
        $budget_used = isset($quota['budgetUsed']) ? intval($quota['budgetUsed']) : intval($config['budget_used'] ?? 0);
        $monthly_budget = isset($quota['monthlyBudget']) ? intval($quota['monthlyBudget']) : intval($config['monthly_budget'] ?? 5);
        $reset_date = !empty($quota['quotaResetDate']) ? date_i18n(get_option('date_format'), strtotime($quota['quotaResetDate'])) : __('Next monthly cycle', 'ewithme-trust-verified-reviews');
        $remaining = max(0, $monthly_budget - $budget_used);

        ?>
        <div class="wrap" style="max-width:850px;">
            <h1><?php esc_html_e('Ewithme Trust Settings & Protocol Status', 'ewithme-trust-verified-reviews'); ?></h1>

            <?php if (!empty($_GET['saved'])) : ?>
                <div class="notice notice-success is-dismissible" style="margin:16px 0;">
                    <p><?php esc_html_e('Settings saved successfully.', 'ewithme-trust-verified-reviews'); ?></p>
                </div>
            <?php endif; ?>

            <?php if (!empty($_GET['provisioned'])) : ?>
                <div class="notice notice-success is-dismissible" style="margin:16px 0;">
                    <p><?php esc_html_e('Connected to eWith.me Trust Service and provisioned API credentials successfully! Next step: click "Verify Domain Control Now" below.', 'ewithme-trust-verified-reviews'); ?></p>
                </div>
            <?php endif; ?>

            <?php if (!empty($_GET['domain_verified'])) : ?>
                <div class="notice notice-success is-dismissible" style="margin:16px 0;">
                    <p><?php esc_html_e('Domain control verified successfully! Your store can now register verified reviews without rate restrictions.', 'ewithme-trust-verified-reviews'); ?></p>
                </div>
            <?php elseif (!empty($_GET['domain_error'])) : ?>
                <div class="notice notice-error is-dismissible" style="margin:16px 0;">
                    <p><?php echo esc_html(get_transient('ewithme_domain_verify_error') ?: __('Verification or connection failed. Please check your settings.', 'ewithme-trust-verified-reviews')); ?></p>
                </div>
            <?php endif; ?>

            <?php if (!$is_connected) : ?>
                <!-- Explicit Opt-In External Service Connection Card (WordPress.org Guidelines 7 & 9 Compliance) -->
                <div style="background:#fffbeb;border:2px solid #f59e0b;border-radius:12px;padding:24px;margin:20px 0;box-shadow:0 1px 3px rgba(0,0,0,0.05);">
                    <h2 style="margin:0 0 8px;font-size:18px;color:#92400e;display:flex;align-items:center;gap:8px;">
                        🔌 <?php esc_html_e('Step 1: Connect to eWith.me Trust Verification Service (Opt-In Required)', 'ewithme-trust-verified-reviews'); ?>
                    </h2>
                    <p style="font-size:13.5px;color:#78350f;margin:0 0 14px;line-height:1.5;">
                        <?php esc_html_e('To protect your privacy and comply with WordPress.org guidelines, this plugin never contacts external servers automatically upon activation. To issue detached Ed25519 cryptographic signatures and send 1-click customer email confirmation links via eWith.me, please review the data disclosure below and opt in.', 'ewithme-trust-verified-reviews'); ?>
                    </p>
                    <form method="post" action="<?php echo esc_url(admin_url('admin.php?page=ewithme-settings')); ?>" style="background:#ffffff;border:1px solid #fde68a;border-radius:8px;padding:16px;">
                        <?php wp_nonce_field('ewithme_optin_provision_action'); ?>
                        <p style="margin:0 0 10px;">
                            <label style="display:flex;align-items:flex-start;gap:8px;font-size:13px;color:#1f2937;cursor:pointer;">
                                <input type="checkbox" name="ewithme_external_service_consent" value="1" required style="margin-top:3px;" />
                                <span>
                                    <?php
                                    printf(
                                        /* translators: 1: domain name, 2: Terms of Service URL, 3: Privacy Policy URL */
                                        esc_html__('I consent to connecting this site (%1$s) to the external eWith.me Trust API to provision an API key, verify customer reviews, and register signed commitments on the public audit ledger in accordance with the ', 'ewithme-trust-verified-reviews'),
                                        esc_html($domain)
                                    );
                                    ?>
                                    <a href="https://ewith.me/terms" target="_blank" rel="noopener noreferrer"><?php esc_html_e('Terms of Service', 'ewithme-trust-verified-reviews'); ?></a>
                                    <?php esc_html_e('and', 'ewithme-trust-verified-reviews'); ?>
                                    <a href="https://ewith.me/privacy" target="_blank" rel="noopener noreferrer"><?php esc_html_e('Privacy Policy', 'ewithme-trust-verified-reviews'); ?></a>.
                                </span>
                            </label>
                        </p>
                        <p style="margin:0 0 14px;">
                            <label style="display:flex;align-items:center;gap:8px;font-size:12.5px;color:#4b5563;cursor:pointer;">
                                <input type="checkbox" name="ewithme_share_admin_email" value="1" />
                                <span>
                                    <?php
                                    printf(
                                        /* translators: %s: admin email */
                                        esc_html__('Optional: Include administrator email (%s) as the domain contact address for security & key recovery notices.', 'ewithme-trust-verified-reviews'),
                                        esc_html(get_option('admin_email'))
                                    );
                                    ?>
                                </span>
                            </label>
                        </p>
                        <button type="submit" name="ewithme_optin_provision" value="1" class="button button-primary">
                            🔑 <?php esc_html_e('Opt-In & Provision Free API Key', 'ewithme-trust-verified-reviews'); ?>
                        </button>
                    </form>
                </div>
            <?php endif; ?>

            <!-- 5-Minute Merchant Quickstart Guide & Checklist -->
            <div style="background:#f8fafc;border:1px solid #cbd5e1;border-radius:12px;padding:22px 24px;margin:20px 0;">
                <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;margin-bottom:14px;border-bottom:1px solid #e2e8f0;padding-bottom:12px;">
                    <div>
                        <span style="font-size:11.5px;font-weight:800;text-transform:uppercase;color:#2563eb;letter-spacing:0.8px;">
                            🚀 <?php esc_html_e('Quickstart Guide', 'ewithme-trust-verified-reviews'); ?>
                        </span>
                        <h2 style="margin:4px 0 0;font-size:18px;color:#0f172a;font-weight:700;">
                            <?php esc_html_e('How to Get Active in 5 Minutes (Merchant Checklist)', 'ewithme-trust-verified-reviews'); ?>
                        </h2>
                    </div>
                    <span style="font-size:12px;background:#e0e7ff;color:#3730a3;padding:4px 10px;border-radius:9999px;font-weight:600;">
                        ⏱️ <?php esc_html_e('Setup Time: ~3-5 mins', 'ewithme-trust-verified-reviews'); ?>
                    </span>
                </div>

                <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(230px, 1fr));gap:14px;margin-bottom:18px;">
                    <!-- Step 1 -->
                    <div style="background:#ffffff;border:1px solid #e2e8f0;border-radius:8px;padding:14px;">
                        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px;">
                            <strong style="color:#0f172a;font-size:13px;">1. <?php esc_html_e('Connect & Verify Domain', 'ewithme-trust-verified-reviews'); ?></strong>
                            <?php if (!empty($config['domain_verified'])) : ?>
                                <span style="color:#16a34a;font-size:11px;font-weight:700;">✔ <?php esc_html_e('Verified', 'ewithme-trust-verified-reviews'); ?></span>
                            <?php else : ?>
                                <span style="color:#d97706;font-size:11px;font-weight:700;">⏳ <?php esc_html_e('Pending', 'ewithme-trust-verified-reviews'); ?></span>
                            <?php endif; ?>
                        </div>
                        <p style="font-size:12px;color:#64748b;margin:0 0 8px;line-height:1.4;">
                            <?php esc_html_e('Opt in to provision your API key, then click "Verify Domain Control Now" below.', 'ewithme-trust-verified-reviews'); ?>
                        </p>
                    </div>

                    <!-- Step 2 -->
                    <div style="background:#ffffff;border:1px solid #e2e8f0;border-radius:8px;padding:14px;">
                        <strong style="color:#0f172a;font-size:13px;display:block;margin-bottom:6px;">2. <?php esc_html_e('Branding & Placement', 'ewithme-trust-verified-reviews'); ?></strong>
                        <p style="font-size:12px;color:#64748b;margin:0 0 8px;line-height:1.4;">
                            <?php esc_html_e('WooCommerce auto-replaces reviews tab. Pick your star and button colors in settings below.', 'ewithme-trust-verified-reviews'); ?>
                        </p>
                    </div>

                    <!-- Step 3 -->
                    <div style="background:#ffffff;border:1px solid #e2e8f0;border-radius:8px;padding:14px;">
                        <strong style="color:#0f172a;font-size:13px;display:block;margin-bottom:6px;">3. <?php esc_html_e('Import Past Reviews', 'ewithme-trust-verified-reviews'); ?></strong>
                        <p style="font-size:12px;color:#64748b;margin:0 0 8px;line-height:1.4;">
                            <?php esc_html_e('Migrate from Judge.me, Loox, or CusRev CSV with honest "Store-Imported" badges.', 'ewithme-trust-verified-reviews'); ?>
                        </p>
                        <a href="<?php echo esc_url(admin_url('admin.php?page=ewithme-reviews-import')); ?>" class="button button-small" style="font-size:11px;">
                            📥 <?php esc_html_e('Go to CSV Importer', 'ewithme-trust-verified-reviews'); ?>
                        </a>
                    </div>

                    <!-- Step 4 -->
                    <div style="background:#ffffff;border:1px solid #e2e8f0;border-radius:8px;padding:14px;">
                        <strong style="color:#0f172a;font-size:13px;display:block;margin-bottom:6px;">4. <?php esc_html_e('Autopilot Email Requests', 'ewithme-trust-verified-reviews'); ?></strong>
                        <p style="font-size:12px;color:#64748b;margin:0 0 8px;line-height:1.4;">
                            <?php esc_html_e('Enable "Automated Review Invitations" below. Sends post-purchase request emails on autopilot.', 'ewithme-trust-verified-reviews'); ?>
                        </p>
                    </div>
                </div>

                <!-- Shortcodes Reference Box -->
                <details style="background:#ffffff;border:1px solid #e2e8f0;border-radius:8px;padding:10px 14px;font-size:12.5px;">
                    <summary style="cursor:pointer;font-weight:600;color:#1e293b;">
                        📋 <?php esc_html_e('Available Shortcodes Cheat Sheet (Click to Expand)', 'ewithme-trust-verified-reviews'); ?>
                    </summary>
                    <div style="margin-top:10px;display:flex;flex-direction:column;gap:8px;">
                        <div>
                            <code>[ewithme_reviews]</code> — <span style="color:#475569;"><?php esc_html_e('Main product review scorecard, verified card feed, write review modal, and author self-service erasure modal.', 'ewithme-trust-verified-reviews'); ?></span>
                        </div>
                        <div>
                            <code>[ewithme_reviews scope="all"]</code> — <span style="color:#475569;"><?php esc_html_e('Store-wide testimonials wall for dedicated pages (e.g. /reviews). Isolates single-product schema.', 'ewithme-trust-verified-reviews'); ?></span>
                        </div>
                        <div>
                            <code>[ewithme_badge]</code> — <span style="color:#475569;"><?php esc_html_e('Compact star rating pill linking to domain public audit report (ideal for headers/footers).', 'ewithme-trust-verified-reviews'); ?></span>
                        </div>
                        <div>
                            <code>[ewithme_disclosure]</code> — <span style="color:#475569;"><?php esc_html_e('EU Omnibus & FTC compliant consumer review transparency policy statement.', 'ewithme-trust-verified-reviews'); ?></span>
                        </div>
                    </div>
                </details>
            </div>

            <!-- Domain Verification Card (WS5 Proof of Control) -->
            <?php
            $is_domain_verified = !empty($config['domain_verified']);
            $challenge_token = $config['challenge_token'] ?? '';
            ?>
            <div style="background:#ffffff;border:1px solid #e5e7eb;border-radius:12px;padding:24px;margin:20px 0;box-shadow:0 1px 3px rgba(0,0,0,0.05);">
                <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;">
                    <div>
                        <span style="font-size:12px;font-weight:700;text-transform:uppercase;color:<?php echo $is_domain_verified ? '#059669' : '#d97706'; ?>;letter-spacing:0.5px;">
                            <?php esc_html_e('Domain Ownership Verification (WS5)', 'ewithme-trust-verified-reviews'); ?>
                        </span>
                        <h3 style="margin:4px 0 0;font-size:18px;color:#111827;">
                            <?php echo esc_html($domain); ?> — <?php echo $is_domain_verified ? '<span style="color:#059669;">✔ ' . esc_html__('Verified', 'ewithme-trust-verified-reviews') . '</span>' : '<span style="color:#d97706;">⏳ ' . esc_html__('Pending DNS/HTTP Verification', 'ewithme-trust-verified-reviews') . '</span>'; ?>
                        </h3>
                    </div>
                    <div>
                        <form method="post" action="<?php echo esc_url(admin_url('admin.php?page=ewithme-settings')); ?>" style="display:inline-block;margin:0;">
                            <?php wp_nonce_field('ewithme_verify_domain_action'); ?>
                            <button type="submit" name="ewithme_verify_domain" value="1" class="button button-primary" <?php disabled(!$is_connected); ?>>
                                🛡️ <?php esc_html_e('Verify Domain Control Now', 'ewithme-trust-verified-reviews'); ?>
                            </button>
                        </form>
                    </div>
                </div>

                <div style="margin-top:14px;background:#f9fafb;border:1px solid #e5e7eb;border-radius:8px;padding:14px;font-size:13px;line-height:1.6;color:#374151;">
                    <p style="margin:0 0 6px;">
                        <strong><?php esc_html_e('Method 1: DNS TXT Record (Recommended)', 'ewithme-trust-verified-reviews'); ?></strong><br>
                        <?php esc_html_e('Add a TXT record to your domain DNS management zone:', 'ewithme-trust-verified-reviews'); ?>
                    </p>
                    <div style="font-family:monospace;font-size:12px;background:#ffffff;padding:8px 12px;border:1px solid #e5e7eb;border-radius:6px;margin-bottom:10px;">
                        <div><span style="color:#6b7280;"><?php esc_html_e('Host/Name:', 'ewithme-trust-verified-reviews'); ?></span> <code>_ewithme-challenge</code></div>
                        <div style="margin-top:4px;"><span style="color:#6b7280;"><?php esc_html_e('TXT Value:', 'ewithme-trust-verified-reviews'); ?></span> <code><?php echo esc_html($challenge_token ?: __('Generated when you opt in to provision API key above', 'ewithme-trust-verified-reviews')); ?></code></div>
                    </div>

                    <p style="margin:0 0 4px;">
                        <strong><?php esc_html_e('Method 2: Automatic HTTP Origin Challenge', 'ewithme-trust-verified-reviews'); ?></strong><br>
                        <?php esc_html_e('eWith.me checks your site REST API origin endpoint automatically:', 'ewithme-trust-verified-reviews'); ?>
                        <code><?php echo esc_html(get_rest_url(null, 'ewithme/v1/verify-origin')); ?></code>
                    </p>
                </div>
            </div>

            <!-- Protocol Status Card -->
            <div style="background:#ffffff;border:1px solid #e5e7eb;border-radius:12px;padding:24px;margin:20px 0;box-shadow:0 1px 3px rgba(0,0,0,0.05);">
                <div style="display:flex;justify-content:space-between;align-items:center;">
                    <div>
                        <span style="font-size:12px;font-weight:700;text-transform:uppercase;color:#059669;letter-spacing:0.5px;"><?php esc_html_e('Protocol Status', 'ewithme-trust-verified-reviews'); ?></span>
                        <h2 style="margin:4px 0 0;font-size:22px;color:#111827;"><?php
                            if ($is_connected) {
                                /* translators: %s: protocol tier/plan name */
                                printf(esc_html__('%s Protocol Tier — Connected', 'ewithme-trust-verified-reviews'), esc_html($plan_name));
                            } else {
                                esc_html_e('Local Mode (Not Connected to External Service)', 'ewithme-trust-verified-reviews');
                            }
                        ?></h2>
                    </div>
                    <div>
                        <span style="display:inline-block;padding:6px 14px;background:#ecfdf5;color:#047857;border-radius:9999px;font-weight:600;font-size:13px;border:1px solid #a7f3d0;">
                            ✔ <?php
                                /* translators: 1: used quota count, 2: total monthly budget quota */
                                printf(esc_html__('%1$d / %2$d Monthly Quota Used', 'ewithme-trust-verified-reviews'), intval($budget_used), intval($monthly_budget));
                            ?>
                        </span>
                    </div>
                </div>

                <div style="margin:16px 0 10px;font-size:14px;color:#4b5563;line-height:1.5;">
                    <p style="margin:0 0 8px;">
                        <?php
                            /* translators: 1: number of remaining verifications, 2: reset date */
                            printf(esc_html__('All customer reviews submitted through your site are stored in your local WordPress database. When connected, email confirmations and cryptographic integrity attestations are provided by eWith.me. You have %1$d verifications remaining this billing cycle (resets %2$s).', 'ewithme-trust-verified-reviews'), intval($remaining), esc_html($reset_date));
                        ?>
                    </p>
                    <p style="margin:0;font-size:13px;color:#6b7280;">
                        <?php esc_html_e('Core review registration and tamper-evident schema snippets are provided free of charge.', 'ewithme-trust-verified-reviews'); ?>
                        <a href="https://ewith.me/terms" target="_blank" rel="noopener noreferrer"><?php esc_html_e('Terms of Service', 'ewithme-trust-verified-reviews'); ?></a> ·
                        <a href="https://ewith.me/privacy" target="_blank" rel="noopener noreferrer"><?php esc_html_e('Privacy Policy', 'ewithme-trust-verified-reviews'); ?></a>
                    </p>
                </div>

                <div style="font-size:13px;color:#6b7280;display:flex;justify-content:space-between;align-items:center;margin-top:16px;border-top:1px solid #f3f4f6;padding-top:12px;">
                    <span><?php esc_html_e('Connected Domain:', 'ewithme-trust-verified-reviews'); ?> <strong><?php echo esc_html($domain); ?></strong></span>
                    <div>
                        <a href="<?php echo esc_url('https://ewith.me/trust/audit/' . rawurlencode($domain)); ?>" target="_blank" class="button button-secondary" style="font-size:12px;margin-right:6px;">
                            ⚖️ <?php esc_html_e('View Audit Summary', 'ewithme-trust-verified-reviews'); ?>
                        </a>
                        <a href="<?php echo esc_url('https://ewith.me/trust/audit/' . rawurlencode($domain) . '#export'); ?>" target="_blank" class="button button-secondary" style="font-size:12px;margin-right:6px;">
                            📥 <?php esc_html_e('Verifiable Ledger Archive', 'ewithme-trust-verified-reviews'); ?>
                        </a>
                        <a href="<?php echo esc_url($profile_url); ?>" target="_blank" class="button button-secondary" style="font-size:12px;">
                            🔍 <?php esc_html_e('View Ledger Status', 'ewithme-trust-verified-reviews'); ?>
                        </a>
                    </div>
                </div>
            </div>

            <!-- Settings Form -->
            <form method="post" action="">
                <?php wp_nonce_field('ewithme_save_settings_action'); ?>
                <table class="form-table">
                    <tr>
                        <th scope="row"><?php esc_html_e('External Service Opt-In', 'ewithme-trust-verified-reviews'); ?></th>
                        <td>
                            <label>
                                <input name="external_service_optin" type="checkbox" value="1" <?php checked(!empty($config['external_service_optin'])); ?> />
                                <?php esc_html_e('Opt-In: Allow this site to connect to the external eWith.me Trust Verification API', 'ewithme-trust-verified-reviews'); ?>
                            </label>
                            <p class="description">
                                <?php esc_html_e('Turned OFF by default. When unchecked, the plugin runs in 100% offline Local Mode and never transmits data to eWith.me.', 'ewithme-trust-verified-reviews'); ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="api_key"><?php esc_html_e('API Key', 'ewithme-trust-verified-reviews'); ?></label></th>
                        <td>
                            <input name="api_key" type="password" id="api_key" value="<?php echo esc_attr($config['api_key'] ?? ''); ?>" class="regular-text" />
                            <p class="description"><?php esc_html_e('Provisioned when you click "Opt-In & Provision Free API Key" above, or enter an existing key from ewith.me.', 'ewithme-trust-verified-reviews'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="target_schema"><?php esc_html_e('Structured Data Output', 'ewithme-trust-verified-reviews'); ?></label></th>
                        <td>
                            <p style="margin:0 0 8px;font-weight:600;color:#111827;">Product Schema (Strict Search Central Compliance)</p>
                            <p class="description" style="color:#b45309;background:#fffbeb;border:1px solid #fef3c7;padding:10px 12px;border-radius:6px;font-size:12.5px;line-height:1.5;max-width:600px;">
                                ⚠️ <strong>Google Search Central Compliance:</strong> Google restricts star ratings in search results to specific schemas (such as <code>Product</code>). eWith.me will only emit review schema on single product pages to protect your site against Google rich-result penalties. Schema is strictly suppressed on home, category, and archive pages.<br>
                                <a href="https://developers.google.com/search/docs/appearance/structured-data/review-snippet" target="_blank" rel="noopener noreferrer" style="font-weight:600;color:#b45309;text-decoration:underline;">Read Google&#39;s Review Snippet guidelines &rarr;</a>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('WooCommerce Integration', 'ewithme-trust-verified-reviews'); ?></th>
                        <td>
                            <label style="display:block;margin-bottom:8px;">
                                <input name="auto_inject_woo" type="checkbox" value="1" <?php checked(!empty($config['auto_inject_woo'])); ?> />
                                <?php esc_html_e('Automatically display ratings under product title & reviews tab', 'ewithme-trust-verified-reviews'); ?>
                            </label>
                            <label style="display:block;">
                                <input name="notarize_woo_comments" type="checkbox" value="1" <?php checked(!empty($config['notarize_woo_comments'])); ?> />
                                <?php esc_html_e('Opt-In: Automatically forward native WooCommerce product review comments to eWith.me for cryptographic verification', 'ewithme-trust-verified-reviews'); ?>
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Review Collection Source', 'ewithme-trust-verified-reviews'); ?></th>
                        <td>
                            <select name="collection_source" id="collection_source" style="max-width:400px;width:100%;">
                                <option value="all_visitors" <?php selected(($config['collection_source'] ?? 'all_visitors'), 'all_visitors'); ?>>
                                    <?php esc_html_e('Open: Visitors who confirm email + Verified purchasers', 'ewithme-trust-verified-reviews'); ?>
                                </option>
                                <option value="verified_purchasers_only" <?php selected(($config['collection_source'] ?? ''), 'verified_purchasers_only'); ?>>
                                    <?php esc_html_e('Strict: Verified purchasers only (WooCommerce orders)', 'ewithme-trust-verified-reviews'); ?>
                                </option>
                            </select>
                            <p class="description">
                                <?php esc_html_e('Reviews are labelled with their honest tier: "Purchase-Verified" (linked to completed order) or "Email-Confirmed" (proved email control).', 'ewithme-trust-verified-reviews'); ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Incentivized Reviews', 'ewithme-trust-verified-reviews'); ?></th>
                        <td>
                            <label>
                                <input name="allow_incentivized_reviews" type="checkbox" value="1" <?php checked(!isset($config['allow_incentivized_reviews']) || !empty($config['allow_incentivized_reviews'])); ?> />
                                <?php esc_html_e('Allow reviewers to declare incentivized reviews (discounts, coupons, gifts)', 'ewithme-trust-verified-reviews'); ?>
                            </label>
                            <p class="description">
                                <?php esc_html_e('Incentivized reviews are visibly badged and automatically excluded from Google Product review markup in accordance with FTC guidelines.', 'ewithme-trust-verified-reviews'); ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Consumer Disclosure Statement', 'ewithme-trust-verified-reviews'); ?></th>
                        <td>
                            <label>
                                <input name="show_disclosure_in_widget" type="checkbox" value="1" <?php checked(!isset($config['show_disclosure_in_widget']) || !empty($config['show_disclosure_in_widget'])); ?> />
                                <?php esc_html_e('Show collapsible "How reviews are collected and verified" in widget', 'ewithme-trust-verified-reviews'); ?>
                            </label>
                            <div style="margin-top:10px;background:#f9fafb;border:1px solid #e5e7eb;border-radius:8px;padding:12px 14px;max-width:600px;">
                                <strong style="display:block;margin-bottom:6px;font-size:13px;color:#111827;">
                                    ⚖️ <?php esc_html_e('Current Auto-Generated Statement (EU Omnibus / FTC Alignment):', 'ewithme-trust-verified-reviews'); ?>
                                </strong>
                                <div style="font-size:12.5px;color:#4b5563;line-height:1.5;">
                                    <?php echo wp_kses_post(Ewithme_Frontend_Widget::get_review_disclosure_statement()); ?>
                                </div>
                            </div>
                            <p class="description" style="margin-top:8px;">
                                <?php esc_html_e('You can also display this statement anywhere on your store using the shortcode:', 'ewithme-trust-verified-reviews'); ?> <code>[ewithme_disclosure]</code>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Admin Email Alerts', 'ewithme-trust-verified-reviews'); ?></th>
                        <td>
                            <label>
                                <input name="notify_admin_on_review" type="checkbox" value="1" <?php checked(!isset($config['notify_admin_on_review']) || !empty($config['notify_admin_on_review'])); ?> />
                                <?php esc_html_e('Send email notification when a new customer review is verified and published', 'ewithme-trust-verified-reviews'); ?>
                            </label>
                            <div style="margin-top:8px;">
                                <label for="admin_notification_email" style="font-size:12px;color:#4b5563;display:block;margin-bottom:3px;"><?php esc_html_e('Notification Recipient Email (leave blank for site admin):', 'ewithme-trust-verified-reviews'); ?></label>
                                <input name="admin_notification_email" type="email" id="admin_notification_email" value="<?php echo esc_attr($config['admin_notification_email'] ?? ''); ?>" placeholder="<?php echo esc_attr(get_option('admin_email')); ?>" class="regular-text" />
                            </div>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Automated Review Invitations', 'ewithme-trust-verified-reviews'); ?></th>
                        <td>
                            <label>
                                <input name="enable_review_invitations" type="checkbox" value="1" <?php checked(!empty($config['enable_review_invitations'])); ?> />
                                <?php esc_html_e('Automatically email customers post-purchase to collect verified reviews (WooCommerce)', 'ewithme-trust-verified-reviews'); ?>
                            </label>
                            <div style="margin-top:8px;">
                                <label for="invitation_delay_days" style="font-size:12px;color:#4b5563;display:block;margin-bottom:3px;"><?php esc_html_e('Invitation Delay (days after order completed):', 'ewithme-trust-verified-reviews'); ?></label>
                                <input name="invitation_delay_days" type="number" id="invitation_delay_days" value="<?php echo esc_attr($config['invitation_delay_days'] ?? 7); ?>" min="0" max="60" style="width:80px;" />
                                <span class="description" style="margin-left:6px;"><?php esc_html_e('Days to wait so customer has received and experienced the item.', 'ewithme-trust-verified-reviews'); ?></span>
                            </div>
                            <p class="description" style="margin-top:6px;">
                                <?php esc_html_e('Invitations are scheduled asynchronously via Action Scheduler or WP-Cron and automatically checked for order cancellation/refund prior to dispatch.', 'ewithme-trust-verified-reviews'); ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Widget Styling', 'ewithme-trust-verified-reviews'); ?></th>
                        <td>
                            <div style="display:flex;align-items:center;gap:20px;flex-wrap:wrap;">
                                <div>
                                    <label for="star_color" style="font-size:12px;color:#4b5563;display:block;margin-bottom:4px;"><?php esc_html_e('Star Rating Color:', 'ewithme-trust-verified-reviews'); ?></label>
                                    <input name="star_color" type="color" id="star_color" value="<?php echo esc_attr($config['star_color'] ?? '#f59e0b'); ?>" style="height:34px;width:60px;padding:2px;cursor:pointer;" />
                                </div>
                                <div>
                                    <label for="btn_color" style="font-size:12px;color:#4b5563;display:block;margin-bottom:4px;"><?php esc_html_e('Primary Button Color:', 'ewithme-trust-verified-reviews'); ?></label>
                                    <input name="btn_color" type="color" id="btn_color" value="<?php echo esc_attr($config['btn_color'] ?? '#2563eb'); ?>" style="height:34px;width:60px;padding:2px;cursor:pointer;" />
                                </div>
                            </div>
                            <p class="description" style="margin-top:6px;"><?php esc_html_e('Customize widget accent colors to match your store theme branding.', 'ewithme-trust-verified-reviews'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="api_url"><?php esc_html_e('API URL', 'ewithme-trust-verified-reviews'); ?></label></th>
                        <td>
                            <input name="api_url" type="url" id="api_url" value="<?php echo esc_attr($config['api_url'] ?? EWITHME_DEFAULT_API_URL); ?>" class="regular-text" />
                            <p class="description"><?php esc_html_e('Central ewith.me authority endpoint.', 'ewithme-trust-verified-reviews'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Uninstall Cleanup', 'ewithme-trust-verified-reviews'); ?></th>
                        <td>
                            <label>
                                <input name="delete_data_on_uninstall" type="checkbox" value="1" <?php checked(!empty($config['delete_data_on_uninstall'])); ?> />
                                <?php esc_html_e('Delete local review table (wp_ew_reviews) and plugin settings when plugin is uninstalled', 'ewithme-trust-verified-reviews'); ?>
                            </label>
                        </td>
                    </tr>
                </table>

                <p class="submit">
                    <input type="submit" name="ewithme_save_settings" id="submit" class="button button-primary" value="<?php esc_attr_e('Save Changes', 'ewithme-trust-verified-reviews'); ?>" />
                </p>
            </form>
        </div>
        <?php
        // phpcs:enable WordPress.Security.NonceVerification.Recommended
    }

    /**
     * Renders admin notices for pending opt-in setup.
     */
    public function render_admin_notices() {
        if (!current_user_can('manage_options')) return;

        if (get_transient('ewithme_optin_setup_notice') || get_transient('ewithme_provision_notice')) {
            if (!Ewithme_Api_Client::is_connected()) {
                ?>
                <div class="notice notice-info is-dismissible">
                    <p>
                        <strong><?php esc_html_e('eWith.me Trust & Verified Reviews:', 'ewithme-trust-verified-reviews'); ?></strong>
                        <?php esc_html_e('Local database tables are ready! To enable cryptographic review verification and email confirmation links, please visit Settings to opt in and provision your free API key.', 'ewithme-trust-verified-reviews'); ?>
                        <a href="<?php echo esc_url(admin_url('admin.php?page=ewithme-settings')); ?>" class="button button-small" style="margin-left:8px;"><?php esc_html_e('Connect in Settings', 'ewithme-trust-verified-reviews'); ?></a>
                    </p>
                </div>
                <?php
            } else {
                delete_transient('ewithme_optin_setup_notice');
                delete_transient('ewithme_provision_notice');
            }
        }
    }
}
