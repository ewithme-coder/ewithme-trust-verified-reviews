<?php
/**
 * Reviews List Table: Paginated, searchable, and filterable admin management table.
 * Extends WP_List_Table for standard WordPress admin experience.
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('WP_List_Table')) {
    require_once(ABSPATH . 'wp-admin/includes/class-wp-list-table.php');
}

class Ewithme_Reviews_List_Table extends WP_List_Table {

    public function __construct() {
        parent::__construct(array(
            'singular' => 'review',
            'plural'   => 'reviews',
            'ajax'     => false
        ));
    }

    /**
     * Defines table columns.
     */
    public function get_columns() {
        return array(
            'cb'       => '<input type="checkbox" />',
            'reviewer' => __('Reviewer', 'ewithme-trust-verified-reviews'),
            'product'  => __('Product', 'ewithme-trust-verified-reviews'),
            'rating'   => __('Rating', 'ewithme-trust-verified-reviews'),
            'review'   => __('Review Content', 'ewithme-trust-verified-reviews'),
            'status'   => __('Verification Status', 'ewithme-trust-verified-reviews'),
            'order'    => __('Order #', 'ewithme-trust-verified-reviews'),
            'date'     => __('Date', 'ewithme-trust-verified-reviews'),
            'actions'  => __('Actions', 'ewithme-trust-verified-reviews')
        );
    }

    /**
     * Defines sortable columns.
     */
    public function get_sortable_columns() {
        return array(
            'rating' => array('rating', false),
            'date'   => array('created_at', true)
        );
    }

    /**
     * Defines bulk actions.
     */
    public function get_bulk_actions() {
        return array(
            'bulk_hide' => __('Hide Locally', 'ewithme-trust-verified-reviews'),
            'bulk_show' => __('Show Locally', 'ewithme-trust-verified-reviews')
        );
    }

    /**
     * Render checkbox column.
     */
    protected function column_cb($item) {
        return sprintf('<input type="checkbox" name="review_ids[]" value="%d" />', intval($item->id));
    }

    /**
     * Render reviewer column.
     */
    protected function column_reviewer($item) {
        $name = esc_html($item->author_name);
        $email = !empty($item->author_email) ? '<br><span style="font-size:11px;color:#6b7280;">' . esc_html($item->author_email) . '</span>' : '';
        return '<strong>' . $name . '</strong>' . $email;
    }

    /**
     * Render product attribution column.
     */
    protected function column_product($item) {
        $post_id = intval($item->post_id);
        if ($post_id <= 0) {
            return '<span class="badge" style="background:#f3f4f6;padding:2px 6px;border-radius:4px;font-size:11px;color:#4b5563;">' . esc_html__('Store-wide', 'ewithme-trust-verified-reviews') . '</span>';
        }

        $title = get_the_title($post_id);
        if (empty($title)) {
            /* translators: %d: post ID of deleted product */
            return '<span style="color:#9ca3af;">' . sprintf(esc_html__('ID #%d (Deleted)', 'ewithme-trust-verified-reviews'), $post_id) . '</span>';
        }

        $edit_url = get_edit_post_link($post_id);
        $view_url = get_permalink($post_id);

        $html = '<strong><a href="' . esc_url($edit_url) . '" title="' . esc_attr__('Edit product', 'ewithme-trust-verified-reviews') . '">' . esc_html(wp_trim_words($title, 5)) . '</a></strong>';
        if ($view_url) {
            $html .= '<br><a href="' . esc_url($view_url) . '" target="_blank" style="font-size:11px;color:#2563eb;">' . esc_html__('View page ↗', 'ewithme-trust-verified-reviews') . '</a>';
        }

        return $html;
    }

    /**
     * Render rating column.
     */
    protected function column_rating($item) {
        $r = intval($item->rating);
        return '<span style="color:#f59e0b;font-size:15px;white-space:nowrap;">' . esc_html(str_repeat('★', $r)) . '</span> (' . $r . ')';
    }

    /**
     * Render review content column.
     */
    protected function column_review($item) {
        $html = '';
        if (!empty($item->title)) {
            $html .= '<strong>' . esc_html($item->title) . '</strong><br>';
        }
        $html .= esc_html(wp_trim_words($item->content, 25));

        // If there is an existing merchant reply, render it indented
        if (!empty($item->reply_content)) {
            $html .= '<div style="margin-top:6px;padding:6px 10px;background:#f9fafb;border-left:3px solid #2563eb;border-radius:4px;font-size:12px;">';
            $html .= '<strong style="color:#1e40af;">' . esc_html__('Store Response:', 'ewithme-trust-verified-reviews') . '</strong> ';
            $html .= esc_html(wp_trim_words($item->reply_content, 20));
            $html .= '</div>';
        }

        return $html;
    }

    /**
     * Render status column.
     */
    protected function column_status($item) {
        $html = '';
        if ($item->status === 'verified') {
            $html .= '<span style="display:inline-block;padding:2px 8px;background:#ecfdf5;color:#047857;border-radius:12px;font-size:11px;font-weight:600;">✔ ' . esc_html__('Verified', 'ewithme-trust-verified-reviews') . '</span>';
            if (!empty($item->certificate_url)) {
                $html .= '<br><a href="' . esc_url($item->certificate_url) . '" target="_blank" style="font-size:11px;color:#2563eb;">' . esc_html__('Certificate ↗', 'ewithme-trust-verified-reviews') . '</a>';
            }
        } elseif ($item->status === 'pending') {
            $html .= '<span style="display:inline-block;padding:2px 8px;background:#fef3c7;color:#b45309;border-radius:12px;font-size:11px;font-weight:600;">⏳ ' . esc_html__('Awaiting Email', 'ewithme-trust-verified-reviews') . '</span>';
        } elseif ($item->status === 'erased') {
            $html .= '<span style="display:inline-block;padding:2px 8px;background:#fef2f2;color:#991b1b;border-radius:12px;font-size:11px;font-weight:600;">🗑️ ' . esc_html__('Erased', 'ewithme-trust-verified-reviews') . '</span>';
        } else {
            $html .= '<span style="display:inline-block;padding:2px 8px;background:#f3f4f6;color:#6b7280;border-radius:12px;font-size:11px;">' . esc_html(ucfirst($item->status)) . '</span>';
        }

        if (!empty($item->is_hidden)) {
            $html .= '<br><span style="display:inline-block;padding:1px 6px;background:#fef2f2;color:#991b1b;border-radius:4px;font-size:10px;font-weight:600;margin-top:4px;">🚫 ' . esc_html__('Hidden Locally', 'ewithme-trust-verified-reviews') . '</span>';
        }

        return $html;
    }

    /**
     * Render WooCommerce order attribution.
     */
    protected function column_order($item) {
        $order_id = intval($item->order_id);
        if ($order_id > 0) {
            $order_edit_url = admin_url('post.php?post=' . $order_id . '&action=edit');
            return '<a href="' . esc_url($order_edit_url) . '" style="font-size:12px;font-weight:600;">#' . $order_id . '</a>';
        }
        return '<span style="color:#9ca3af;font-size:11px;">' . esc_html__('None', 'ewithme-trust-verified-reviews') . '</span>';
    }

    /**
     * Render date column.
     */
    protected function column_date($item) {
        return esc_html(date_i18n(get_option('date_format'), strtotime($item->created_at)));
    }

    /**
     * Render actions column.
     */
    protected function column_actions($item) {
        ob_start();
        ?>
        <form method="post" action="<?php echo esc_url(admin_url('admin.php?page=ewithme-trust-verified-reviews')); ?>" style="display:inline-flex;align-items:center;gap:4px;margin:0;">
            <?php wp_nonce_field('ewithme_review_action'); ?>
            <input type="hidden" name="review_id" value="<?php echo esc_attr($item->id); ?>">
            
            <?php if (in_array($item->status, array('pending', 'pending_verification'), true) && !Ewithme_Api_Client::is_connected()) : ?>
                <button type="submit" name="ewithme_review_action" value="approve" class="button-link" style="color:#059669;cursor:pointer;font-size:12px;font-weight:600;" title="<?php esc_attr_e('Approve review locally', 'ewithme-trust-verified-reviews'); ?>"><?php esc_html_e('Approve', 'ewithme-trust-verified-reviews'); ?></button>
                <span style="color:#d1d5db;">|</span>
            <?php elseif ($item->status === 'verified' && empty($item->is_hidden)) : ?>
                <button type="submit" name="ewithme_review_action" value="hide" class="button-link" style="color:#d97706;cursor:pointer;font-size:12px;" title="<?php esc_attr_e('Hide locally from storefront', 'ewithme-trust-verified-reviews'); ?>"><?php esc_html_e('Hide', 'ewithme-trust-verified-reviews'); ?></button>
                <span style="color:#d1d5db;">|</span>
            <?php elseif ($item->status === 'verified' && !empty($item->is_hidden)) : ?>
                <button type="submit" name="ewithme_review_action" value="show" class="button-link" style="color:#059669;cursor:pointer;font-size:12px;" title="<?php esc_attr_e('Restore visibility on storefront', 'ewithme-trust-verified-reviews'); ?>"><?php esc_html_e('Show', 'ewithme-trust-verified-reviews'); ?></button>
                <span style="color:#d1d5db;">|</span>
            <?php endif; ?>

            <?php if (!empty($item->certificate_id) && $item->status !== 'erased') : ?>
                <details style="display:inline-block;position:relative;">
                    <summary class="button-link" style="color:#dc2626;cursor:pointer;font-size:12px;"><?php esc_html_e('Moderate', 'ewithme-trust-verified-reviews'); ?></summary>
                    <div style="position:absolute;right:0;top:100%;background:#ffffff;border:1px solid #e5e7eb;box-shadow:0 10px 15px -3px rgba(0,0,0,0.1);padding:10px;border-radius:6px;z-index:99;width:220px;text-align:left;">
                        <label style="font-size:11px;font-weight:600;display:block;margin-bottom:4px;"><?php esc_html_e('FTC / Legal Reason:', 'ewithme-trust-verified-reviews'); ?></label>
                        <select name="moderation_reason" style="width:100%;font-size:12px;margin-bottom:8px;">
                            <option value="profanity_or_abuse"><?php esc_html_e('Abuse / Profanity', 'ewithme-trust-verified-reviews'); ?></option>
                            <option value="suspected_fake"><?php esc_html_e('Suspected Fake', 'ewithme-trust-verified-reviews'); ?></option>
                            <option value="unrelated_to_product"><?php esc_html_e('Unrelated Content', 'ewithme-trust-verified-reviews'); ?></option>
                            <option value="legal_or_privacy_request"><?php esc_html_e('Legal / Privacy', 'ewithme-trust-verified-reviews'); ?></option>
                            <option value="author_requested"><?php esc_html_e('Author Requested', 'ewithme-trust-verified-reviews'); ?></option>
                        </select>
                        <button type="submit" name="ewithme_review_action" value="erase" class="button button-small button-primary" style="background:#dc2626;border-color:#b91c1c;width:100%;" onclick="return confirm('<?php esc_attr_e('Permanently erase review from public trust ledger? This cannot be undone.', 'ewithme-trust-verified-reviews'); ?>');"><?php esc_html_e('Confirm Erasure', 'ewithme-trust-verified-reviews'); ?></button>
                    </div>
                </details>
                <span style="color:#d1d5db;">|</span>
            <?php endif; ?>

            <!-- Merchant Reply Action -->
            <details style="display:inline-block;position:relative;">
                <summary class="button-link" style="color:#2563eb;cursor:pointer;font-size:12px;" title="<?php esc_attr_e('Public Store Response', 'ewithme-trust-verified-reviews'); ?>">
                    <?php echo !empty($item->reply_content) ? esc_html__('Edit Reply', 'ewithme-trust-verified-reviews') : esc_html__('Reply', 'ewithme-trust-verified-reviews'); ?>
                </summary>
                <div style="position:absolute;right:0;top:100%;background:#ffffff;border:1px solid #e5e7eb;box-shadow:0 10px 15px -3px rgba(0,0,0,0.1);padding:12px;border-radius:6px;z-index:99;width:280px;text-align:left;">
                    <label style="font-size:11px;font-weight:600;display:block;margin-bottom:4px;"><?php esc_html_e('Public Store Owner Response:', 'ewithme-trust-verified-reviews'); ?></label>
                    <textarea name="reply_content" rows="3" maxlength="1000" style="width:100%;font-size:12px;margin-bottom:8px;" placeholder="<?php esc_attr_e('Write a professional response to this customer...', 'ewithme-trust-verified-reviews'); ?>"><?php echo esc_textarea($item->reply_content ?? ''); ?></textarea>
                    <div style="display:flex;justify-content:space-between;gap:6px;">
                        <button type="submit" name="ewithme_review_action" value="post_reply" class="button button-small button-primary" style="flex:1;">
                            <?php esc_html_e('Save Reply', 'ewithme-trust-verified-reviews'); ?>
                        </button>
                        <?php if (!empty($item->reply_content)) : ?>
                            <button type="submit" name="ewithme_review_action" value="delete_reply" class="button button-small" style="color:#dc2626;" onclick="return confirm('<?php esc_attr_e('Remove this store reply?', 'ewithme-trust-verified-reviews'); ?>');">
                                <?php esc_html_e('Delete', 'ewithme-trust-verified-reviews'); ?>
                            </button>
                        <?php endif; ?>
                    </div>
                </div>
            </details>
            <span style="color:#d1d5db;">|</span>

            <button type="submit" name="ewithme_review_action" value="delete" class="button-link" style="color:#9ca3af;cursor:pointer;font-size:12px;" onclick="return confirm('<?php esc_attr_e('Delete local review record?', 'ewithme-trust-verified-reviews'); ?>');"><?php esc_html_e('Del', 'ewithme-trust-verified-reviews'); ?></button>
        </form>
        <?php
        return ob_get_clean();
    }

    /**
     * Multidimensional Filter bar above table (Rating, Status, Product).
     */
    public function extra_tablenav($which) {
        if ($which !== 'top') return;

        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- Tablenav GET filter inputs are safe read-only query parameters.
        global $wpdb;
        $table = $wpdb->prefix . 'ew_reviews';

        $selected_rating = isset($_GET['filter_rating']) ? intval($_GET['filter_rating']) : 0;
        $selected_status = isset($_GET['filter_status']) ? sanitize_text_field(wp_unslash($_GET['filter_status'])) : '';
        $selected_post_id = isset($_GET['filter_post_id']) ? intval($_GET['filter_post_id']) : 0;

        // Distinct products with reviews
        // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table identifier is prepared via %i.
        $products = $wpdb->get_results($wpdb->prepare('SELECT DISTINCT post_id FROM %i WHERE post_id > 0 LIMIT 100', $table));

        ?>
        <div class="alignleft actions">
            <select name="filter_rating">
                <option value=""><?php esc_html_e('All Ratings', 'ewithme-trust-verified-reviews'); ?></option>
                <option value="5" <?php selected($selected_rating, 5); ?>>★★★★★ (5)</option>
                <option value="4" <?php selected($selected_rating, 4); ?>>★★★★☆ (4)</option>
                <option value="3" <?php selected($selected_rating, 3); ?>>★★★☆☆ (3)</option>
                <option value="2" <?php selected($selected_rating, 2); ?>>★★☆☆☆ (2)</option>
                <option value="1" <?php selected($selected_rating, 1); ?>>★☆☆☆☆ (1)</option>
            </select>

            <select name="filter_status">
                <option value=""><?php esc_html_e('All Statuses', 'ewithme-trust-verified-reviews'); ?></option>
                <option value="verified" <?php selected($selected_status, 'verified'); ?>><?php esc_html_e('Verified', 'ewithme-trust-verified-reviews'); ?></option>
                <option value="pending" <?php selected($selected_status, 'pending'); ?>><?php esc_html_e('Awaiting Email', 'ewithme-trust-verified-reviews'); ?></option>
                <option value="hidden" <?php selected($selected_status, 'hidden'); ?>><?php esc_html_e('Hidden Locally', 'ewithme-trust-verified-reviews'); ?></option>
                <option value="erased" <?php selected($selected_status, 'erased'); ?>><?php esc_html_e('Erased', 'ewithme-trust-verified-reviews'); ?></option>
            </select>

            <?php if (!empty($products)) : ?>
                <select name="filter_post_id" style="max-width:200px;">
                    <option value=""><?php esc_html_e('All Products', 'ewithme-trust-verified-reviews'); ?></option>
                    <?php foreach ($products as $p) : ?>
                        <?php $p_title = get_the_title($p->post_id); ?>
                        <option value="<?php echo esc_attr($p->post_id); ?>" <?php selected($selected_post_id, $p->post_id); ?>>
                            <?php echo esc_html(wp_trim_words($p_title ?: ('Product #' . $p->post_id), 4)); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            <?php endif; ?>

            <?php submit_button(__('Filter', 'ewithme-trust-verified-reviews'), 'button', 'filter_action', false); ?>
        </div>
        <?php
        // phpcs:enable WordPress.Security.NonceVerification.Recommended
    }

    /**
     * Prepares data, pagination, and sorting for display.
     */
    public function prepare_items() {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- Search and pagination GET parameters are safe read-only query arguments.
        global $wpdb;
        $table = $wpdb->prefix . 'ew_reviews';

        $per_page = 20;
        $current_page = $this->get_pagenum();

        $where = array('1=1');
        $params = array();

        // 1. Keyword Search
        if (!empty($_GET['s'])) {
            $search = '%' . $wpdb->esc_like(sanitize_text_field(wp_unslash($_GET['s']))) . '%';
            $where[] = '(author_name LIKE %s OR author_email LIKE %s OR title LIKE %s OR content LIKE %s)';
            $params[] = $search;
            $params[] = $search;
            $params[] = $search;
            $params[] = $search;
        }

        // 2. Rating filter
        if (!empty($_GET['filter_rating'])) {
            $where[] = 'rating = %d';
            $params[] = intval($_GET['filter_rating']);
        }

        // 3. Status filter
        if (!empty($_GET['filter_status'])) {
            $status = sanitize_text_field(wp_unslash($_GET['filter_status']));
            if ($status === 'hidden') {
                $where[] = 'is_hidden = 1';
            } else {
                $where[] = 'status = %s';
                $params[] = $status;
            }
        }

        // 4. Product filter
        if (!empty($_GET['filter_post_id'])) {
            $where[] = 'post_id = %d';
            $params[] = intval($_GET['filter_post_id']);
        }

        $where_sql = implode(' AND ', $where);

        // Sorting (strict allowlist validation)
        $allowed_orderby = array('id', 'rating', 'author_name', 'created_at', 'status', 'verification_tier');
        $raw_orderby = isset($_GET['orderby']) ? sanitize_key(wp_unslash($_GET['orderby'])) : 'created_at';
        $orderby = in_array($raw_orderby, $allowed_orderby, true) ? $raw_orderby : 'created_at';
        $order = (isset($_GET['order']) && strtoupper(sanitize_key(wp_unslash($_GET['order']))) === 'ASC') ? 'ASC' : 'DESC';

        // Count query
        $count_params = array_merge(array($table), $params);
        // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $table is prepared with %i, and $count_params passed as array.
        $total_items = intval($wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM %i WHERE $where_sql", $count_params)));

        // Data query
        $offset = ($current_page - 1) * $per_page;
        $data_params = array_merge(array($table), $params, array($per_page, $offset));

        // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $table is prepared with %i, $orderby and $order are strictly whitelisted, and $data_params passed as array.
        $this->items = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM %i WHERE $where_sql ORDER BY $orderby $order LIMIT %d OFFSET %d",
                $data_params
            )
        );

        $this->set_pagination_args(array(
            'total_items' => $total_items,
            'per_page'    => $per_page,
            'total_pages' => ceil($total_items / $per_page)
        ));
        // phpcs:enable WordPress.Security.NonceVerification.Recommended
    }
}
