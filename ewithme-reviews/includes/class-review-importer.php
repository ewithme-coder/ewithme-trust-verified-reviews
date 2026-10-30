<?php
/**
 * Review Importer for Judge.me, Loox, CusRev, and Generic CSV formats.
 *
 * Imports existing reviews into wp_ew_reviews with strict honesty invariants:
 * - Assigned the truthful 'native_imported' verification tier.
 * - Never fabricates cryptographic ledger certificates or hashes.
 * - Excludes non-existent or duplicate reviews.
 * - Integrates with WooCommerce product catalog via ID, SKU, or Title matching.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Ewithme_Review_Importer {

    /**
     * Initializes admin menu and import actions.
     */
    public function init() {
        add_action('admin_menu', array($this, 'register_menu'), 20);
        add_action('admin_init', array($this, 'handle_csv_upload'));
    }

    /**
     * Adds the 'Import' submenu under Trust Reviews.
     */
    public function register_menu() {
        add_submenu_page(
            'ewithme-trust-verified-reviews',
            __('Import Reviews', 'ewithme-trust-verified-reviews'),
            __('Import', 'ewithme-trust-verified-reviews'),
            'manage_options',
            'ewithme-reviews-import',
            array($this, 'render_import_page')
        );
    }

    /**
     * Handles the CSV upload and insertion pipeline.
     */
    public function handle_csv_upload() {
        if (!current_user_can('manage_options')) {
            return;
        }

        if (!isset($_POST['ewithme_import_csv_submit']) || !check_admin_referer('ewithme_import_csv_action')) {
            return;
        }

        if (empty($_FILES['reviews_csv']) || !is_array($_FILES['reviews_csv']) || !isset($_FILES['reviews_csv']['error']) || $_FILES['reviews_csv']['error'] !== UPLOAD_ERR_OK || empty($_FILES['reviews_csv']['tmp_name'])) {
            wp_safe_redirect(admin_url('admin.php?page=ewithme-reviews-import&import_error=upload_failed'));
            exit;
        }

        $file_name = isset($_FILES['reviews_csv']['name']) ? sanitize_file_name(wp_unslash($_FILES['reviews_csv']['name'])) : '';
        $file_ext  = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
        if ($file_ext !== 'csv') {
            wp_safe_redirect(admin_url('admin.php?page=ewithme-reviews-import&import_error=invalid_filetype'));
            exit;
        }

        $tmp_file = isset($_FILES['reviews_csv']['tmp_name']) ? sanitize_text_field(wp_unslash($_FILES['reviews_csv']['tmp_name'])) : '';
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Required for stream parsing uploaded CSV via fgetcsv.
        $handle = fopen($tmp_file, 'r');
        if (!$handle) {
            wp_safe_redirect(admin_url('admin.php?page=ewithme-reviews-import&import_error=cannot_read'));
            exit;
        }

        // Read header row
        $header = fgetcsv($handle, 4096, ',');
        if (!$header || count($header) < 2) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closes CSV handle.
            fclose($handle);
            wp_safe_redirect(admin_url('admin.php?page=ewithme-reviews-import&import_error=empty_or_invalid_csv'));
            exit;
        }

        // Detect column indexes
        $col_map = $this->map_columns($header);
        if ($col_map['content'] === null || $col_map['rating'] === null) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closes CSV handle.
            fclose($handle);
            wp_safe_redirect(admin_url('admin.php?page=ewithme-reviews-import&import_error=missing_required_columns'));
            exit;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'ew_reviews';

        $imported = 0;
        $skipped  = 0;
        $unmatched = 0;
        $max_rows = 5000;
        $row_count = 0;

        while (($row = fgetcsv($handle, 8192, ',')) !== false) {
            $row_count++;
            if ($row_count > $max_rows) {
                break; // Safety limit
            }

            // Extract review fields
            $raw_content = isset($col_map['content']) && isset($row[$col_map['content']]) ? $row[$col_map['content']] : '';
            $content = sanitize_textarea_field(trim($raw_content));
            if (empty($content)) {
                $skipped++;
                continue;
            }

            $raw_rating = isset($col_map['rating']) && isset($row[$col_map['rating']]) ? $row[$col_map['rating']] : 5;
            $rating = intval(preg_replace('/[^0-9]/', '', $raw_rating));
            if ($rating < 1 || $rating > 5) {
                $rating = 5;
            }

            $raw_author = isset($col_map['author']) && isset($row[$col_map['author']]) ? $row[$col_map['author']] : 'Customer';
            $author_name = sanitize_text_field(trim($raw_author)) ?: 'Customer';

            $raw_email = isset($col_map['email']) && isset($row[$col_map['email']]) ? $row[$col_map['email']] : '';
            $author_email = sanitize_email(trim($raw_email));

            $raw_title = isset($col_map['title']) && isset($row[$col_map['title']]) ? $row[$col_map['title']] : '';
            $title = sanitize_text_field(trim($raw_title));

            $raw_date = isset($col_map['date']) && isset($row[$col_map['date']]) ? $row[$col_map['date']] : '';
            $timestamp = !empty($raw_date) ? strtotime($raw_date) : false;
            $review_date = $timestamp ? gmdate('Y-m-d H:i:s', $timestamp) : current_time('mysql', 1);

            // Match product
            $raw_product_id = isset($col_map['product']) && isset($row[$col_map['product']]) ? trim($row[$col_map['product']]) : '';
            $post_id = $this->resolve_product_id($raw_product_id);

            if ($post_id <= 0) {
                $unmatched++;
                continue; // Cannot attach to an unknown product
            }

            // Check duplicate
            // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table identifier is prepared via %i.
            $exists = $wpdb->get_var($wpdb->prepare(
                'SELECT id FROM %i WHERE post_id = %d AND author_name = %s AND content = %s LIMIT 1',
                $table,
                $post_id,
                $author_name,
                $content
            ));

            if ($exists) {
                $skipped++;
                continue;
            }

            // Insert with honest 'native_imported' tier
            $inserted = $wpdb->insert(
                $table,
                array(
                    'post_id'           => $post_id,
                    'author_name'       => $author_name,
                    'author_email'      => $author_email,
                    'rating'            => $rating,
                    'title'             => $title,
                    'content'           => $content,
                    'status'            => 'verified',
                    'verification_tier' => 'native_imported',
                    'is_incentivized'   => 0,
                    'is_hidden'         => 0,
                    'certificate_id'    => null,
                    'certificate_url'   => null,
                    'verified_at'       => $review_date,
                    'created_at'        => $review_date,
                ),
                array('%d', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s', '%s', '%s')
            );

            if ($inserted) {
                $imported++;
            } else {
                $skipped++;
            }
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closes CSV handle.
        fclose($handle);

        wp_safe_redirect(admin_url(sprintf(
            'admin.php?page=ewithme-reviews-import&imported=%d&skipped=%d&unmatched=%d',
            $imported,
            $skipped,
            $unmatched
        )));
        exit;
    }

    /**
     * Maps CSV headers to review schema fields.
     *
     * @param array $headers
     * @return array
     */
    private function map_columns($headers) {
        $map = array(
            'content' => null,
            'rating'  => null,
            'author'  => null,
            'email'   => null,
            'title'   => null,
            'date'    => null,
            'product' => null,
        );

        foreach ($headers as $idx => $raw_col) {
            $col = strtolower(trim(str_replace(array('-', '_', ' '), '', $raw_col)));

            // Content
            if ($map['content'] === null && in_array($col, array('body', 'reviewbody', 'content', 'reviewcontent', 'reviewtext', 'comment', 'description'), true)) {
                $map['content'] = $idx;
            }
            // Rating
            elseif ($map['rating'] === null && in_array($col, array('rating', 'score', 'stars', 'reviewrating', 'ratingvalue'), true)) {
                $map['rating'] = $idx;
            }
            // Author
            elseif ($map['author'] === null && in_array($col, array('reviewername', 'author', 'authorname', 'name', 'customername', 'reviewauthor'), true)) {
                $map['author'] = $idx;
            }
            // Email
            elseif ($map['email'] === null && in_array($col, array('revieweremail', 'email', 'authoremail', 'customeremail'), true)) {
                $map['email'] = $idx;
            }
            // Title
            elseif ($map['title'] === null && in_array($col, array('title', 'reviewtitle', 'headline', 'subject'), true)) {
                $map['title'] = $idx;
            }
            // Date
            elseif ($map['date'] === null && in_array($col, array('date', 'reviewdate', 'createdat', 'submissiondate', 'datecreated'), true)) {
                $map['date'] = $idx;
            }
            // Product identifier
            elseif ($map['product'] === null && in_array($col, array('productid', 'producthandle', 'productsku', 'sku', 'producttitle', 'itemid', 'handle', 'postid'), true)) {
                $map['product'] = $idx;
            }
        }

        return $map;
    }

    /**
     * Resolves a raw product identifier to a valid WordPress post/product ID.
     *
     * @param string $val
     * @return int
     */
    private function resolve_product_id($val) {
        if (empty($val)) {
            return 0;
        }

        // Direct numeric ID
        if (is_numeric($val)) {
            $id = intval($val);
            $post = get_post($id);
            if ($post && in_array($post->post_type, array('product', 'post', 'page'), true)) {
                return $id;
            }
        }

        // SKU matching (WooCommerce)
        if (function_exists('wc_get_product_id_by_sku')) {
            $sku_id = wc_get_product_id_by_sku($val);
            if ($sku_id > 0) {
                return $sku_id;
            }
        }

        // Title matching (get_posts replaces deprecated get_page_by_title)
        $matching_posts = get_posts(array(
            'title'                  => $val,
            'post_type'              => 'product',
            'post_status'            => 'publish',
            'posts_per_page'         => 1,
            'update_post_meta_cache' => false,
            'update_post_term_cache' => false,
            'no_found_rows'          => true,
        ));
        if (!empty($matching_posts)) {
            return $matching_posts[0]->ID;
        }

        return 0;
    }

    /**
     * Renders the CSV import interface.
     */
    public function render_import_page() {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- Safe read-only GET parameters for displaying admin import status messages.
        $imported  = isset($_GET['imported']) ? intval(wp_unslash($_GET['imported'])) : null;
        $skipped   = isset($_GET['skipped']) ? intval(wp_unslash($_GET['skipped'])) : 0;
        $unmatched = isset($_GET['unmatched']) ? intval(wp_unslash($_GET['unmatched'])) : 0;
        $error     = isset($_GET['import_error']) ? sanitize_text_field(wp_unslash($_GET['import_error'])) : null;

        ?>
        <div class="wrap">
            <h1>
                <span class="dashicons dashicons-upload" style="font-size:30px;width:30px;height:30px;color:#2563eb;margin-right:8px;"></span>
                <?php esc_html_e('Import Existing Reviews', 'ewithme-trust-verified-reviews'); ?>
            </h1>

            <?php if ($imported !== null) : ?>
                <div class="notice notice-success is-dismissible">
                    <p>
                        <strong><?php
                            echo esc_html(sprintf(
                                /* translators: %d: number of reviews successfully imported */
                                __('Import complete: %d reviews successfully imported.', 'ewithme-trust-verified-reviews'),
                                intval($imported)
                            ));
                        ?></strong>
                        <?php if ($skipped > 0 || $unmatched > 0) : ?>
                            <span>(<?php
                                echo esc_html(sprintf(
                                    /* translators: 1: number of skipped reviews, 2: number of unmatched products */
                                    __('%1$d skipped/duplicates, %2$d unmatched products', 'ewithme-trust-verified-reviews'),
                                    intval($skipped),
                                    intval($unmatched)
                                ));
                            ?>)</span>
                        <?php endif; ?>
                    </p>
                </div>
            <?php endif; ?>

            <?php if ($error) : ?>
                <div class="notice notice-error is-dismissible">
                    <p>
                        <strong><?php esc_html_e('Import Error:', 'ewithme-trust-verified-reviews'); ?></strong>
                        <?php
                        if ($error === 'invalid_filetype') {
                            esc_html_e('Please upload a valid CSV (.csv) file.', 'ewithme-trust-verified-reviews');
                        } elseif ($error === 'missing_required_columns') {
                            esc_html_e('The uploaded CSV is missing required review content or rating columns.', 'ewithme-trust-verified-reviews');
                        } else {
                            esc_html_e('An error occurred during file upload. Please try again.', 'ewithme-trust-verified-reviews');
                        }
                        ?>
                    </p>
                </div>
            <?php endif; ?>

            <div style="background:#fff;border:1px solid #ccd0d4;padding:24px;border-radius:8px;max-width:760px;margin-top:20px;">
                <h2 style="margin-top:0;"><?php esc_html_e('Upload Reviews CSV', 'ewithme-trust-verified-reviews'); ?></h2>
                <p style="color:#50575e;font-size:13.5px;line-height:1.5;">
                    <?php esc_html_e('Easily migrate your existing store reviews from Judge.me, Loox, CusRev, or generic CSV exports. Our auto-detection engine matches author names, ratings, text, dates, and products.', 'ewithme-trust-verified-reviews'); ?>
                </p>

                <div style="background:#f0fdf4;border-left:4px solid #16a34a;padding:12px 16px;margin:18px 0;font-size:13px;color:#166534;">
                    <strong>⚖️ <?php esc_html_e('Honest Verification Standard:', 'ewithme-trust-verified-reviews'); ?></strong><br>
                    <?php esc_html_e('Imported reviews are preserved and displayed with the "Store-Imported" badge. In strict compliance with consumer protection standards, eWith.me does not fabricate cryptographic ledger certificates for reviews collected prior to installation.', 'ewithme-trust-verified-reviews'); ?>
                </div>

                <form method="post" enctype="multipart/form-data" action="<?php echo esc_url(admin_url('admin.php?page=ewithme-reviews-import')); ?>">
                    <?php wp_nonce_field('ewithme_import_csv_action'); ?>

                    <table class="form-table" role="presentation">
                        <tr>
                            <th scope="row"><label for="reviews_csv"><?php esc_html_e('Select CSV File', 'ewithme-trust-verified-reviews'); ?></label></th>
                            <td>
                                <input type="file" name="reviews_csv" id="reviews_csv" accept=".csv" required style="font-size:14px;" />
                                <p class="description">
                                    <?php esc_html_e('Max file size: 10MB. Supports up to 5,000 reviews per batch.', 'ewithme-trust-verified-reviews'); ?>
                                </p>
                            </td>
                        </tr>
                    </table>

                    <p class="submit" style="margin-top:16px;">
                        <input type="submit" name="ewithme_import_csv_submit" class="button button-primary" value="<?php esc_attr_e('Import Reviews', 'ewithme-trust-verified-reviews'); ?>" />
                    </p>
                </form>
            </div>
        </div>
        <?php
        // phpcs:enable WordPress.Security.NonceVerification.Recommended
    }
}
