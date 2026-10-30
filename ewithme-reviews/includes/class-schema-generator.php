<?php
/**
 * Server-Side Rendered (SSR) JSON-LD Schema Generator.
 * Strictly adheres to Google Search Central Structured Data guidelines for review snippets:
 * - Emits Product schema strictly on single product pages.
 * - Suppresses schema on non-product pages, archives, and homepages to prevent manual action penalties.
 * - Suppresses AggregateRating when approved review count is 0.
 * - Excludes incentivized reviews from rich snippet markup by default.
 * - Integrates cleanly with SEO plugins (Yoast, Rank Math, WooCommerce) via @id graph referencing or filters.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Ewithme_Schema_Generator {

    /**
     * Initializes schema action hooks.
     */
    public function init() {
        add_action('wp_head', array($this, 'inject_schema_json_ld'), 99);
    }

    /**
     * Determines if the current request is a single product page eligible for review markup.
     * Restricts strictly to single product pages (WooCommerce is_product() or singular 'product' post type).
     */
    public function is_eligible_product_page() {
        // Never emit on front page, blog homepage, search, 404, or archives
        if (is_front_page() || is_home() || is_archive() || is_search() || is_404()) {
            return false;
        }

        // Must be singular product page
        if (function_exists('is_product') && is_product()) {
            return true;
        }

        if (is_singular('product')) {
            return true;
        }

        // Allow merchants to whitelist custom post types via filter if they sell items as products
        return apply_filters('ewithme_schema_is_product_page', false);
    }

    /**
     * Injects structured data JSON-LD into head for singular products.
     */
    public function inject_schema_json_ld() {
        if (!$this->is_eligible_product_page()) {
            return;
        }

        global $wpdb, $post;
        if (!$post || empty($post->ID)) {
            return;
        }

        $table = $wpdb->prefix . 'ew_reviews';

        // Memoize is_incentivized column presence check to eliminate redundant database DESCRIBE queries (PERF-LOW-01)
        static $has_incentivized_col = null;
        if ($has_incentivized_col === null) {
            $db_ver = get_option('ewithme_reviews_db_version', '');
            if (!empty($db_ver) && version_compare($db_ver, '1.1.0', '>=')) {
                $has_incentivized_col = true;
            } else {
                // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table identifier is prepared via %i.
                $cols = $wpdb->get_col($wpdb->prepare('DESCRIBE %i', $table), 0);
                $has_incentivized_col = ($cols && in_array('is_incentivized', $cols, true));
            }
        }

        // Exclude incentivized reviews from AggregateRating & Review items by default
        $incentivized_clause = $has_incentivized_col ? " AND (is_incentivized = 0 OR is_incentivized IS NULL)" : "";

        // Calculate aggregate ratings for verified, non-incentivized, visible reviews on this product
        // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table identifier is prepared via %i and incentivized_clause is hardcoded boolean logic.
        $summary = $wpdb->get_row($wpdb->prepare("
            SELECT COUNT(*) as review_count, AVG(rating) as avg_rating
            FROM %i
            WHERE post_id = %d AND status = 'verified' AND is_hidden = 0$incentivized_clause
        ", $table, $post->ID));

        if (!$summary || intval($summary->review_count) === 0) {
            return; // Never emit AggregateRating if 0 approved visible reviews (TRAP-H07)
        }

        $review_count = intval($summary->review_count);
        $avg_rating   = round(floatval($summary->avg_rating), 1);

        // Fetch up to 10 latest verified, non-incentivized, visible reviews
        // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table identifier is prepared via %i and incentivized_clause is hardcoded boolean logic.
        $reviews = $wpdb->get_results($wpdb->prepare("
            SELECT author_name, rating, title, content, verified_at, certificate_url
            FROM %i
            WHERE post_id = %d AND status = 'verified' AND is_hidden = 0$incentivized_clause
            ORDER BY verified_at DESC
            LIMIT 10
        ", $table, $post->ID));

        // LOW-03: Decode HTML entities in product titles for clean schema
        $item_name = wp_strip_all_tags(html_entity_decode(get_the_title($post->ID), ENT_QUOTES, 'UTF-8'));
        $item_url  = get_permalink($post->ID);
        $item_id   = $item_url . '#product';

        $reviews_array = array();
        if (!empty($reviews)) {
            foreach ($reviews as $rev) {
                // MED-04 & TRAP-H09: Multi-byte safe author initials
                $formatted_name = sanitize_text_field($rev->author_name);
                $parts = explode(' ', $formatted_name);
                $last = end($parts);
                $initial = function_exists('mb_substr') ? mb_substr($last, 0, 1, 'UTF-8') : substr($last, 0, 1);
                $upper_initial = function_exists('mb_strtoupper') ? mb_strtoupper($initial, 'UTF-8') : strtoupper($initial);
                $display_author = (count($parts) > 1) ? $parts[0] . ' ' . $upper_initial . '.' : $formatted_name;

                $reviews_array[] = array(
                    '@type'         => 'Review',
                    'author'        => array(
                        '@type' => 'Person',
                        'name'  => $display_author
                    ),
                    'datePublished' => !empty($rev->verified_at) ? gmdate('Y-m-d', strtotime($rev->verified_at)) : gmdate('Y-m-d'),
                    'reviewRating'  => array(
                        '@type'       => 'Rating',
                        'ratingValue' => strval($rev->rating),
                        'bestRating'  => '5',
                        'worstRating' => '1'
                    ),
                    'reviewBody'    => wp_strip_all_tags($rev->content),
                    'publisher'     => array(
                        '@type' => 'Organization',
                        'name'  => !empty($rev->certificate_url) ? 'eWith.me Verified Reviews' : get_bloginfo('name'),
                        'url'   => !empty($rev->certificate_url) ? $rev->certificate_url : home_url()
                    )
                );
            }
        }

        // Schema graph assembly with @id anchor to allow merging with Yoast/Rank Math/WooCommerce Product graph
        $schema_data = array(
            '@context'        => 'https://schema.org',
            '@type'           => 'Product',
            '@id'             => $item_id,
            'name'            => $item_name,
            'url'             => $item_url,
            'aggregateRating' => array(
                '@type'       => 'AggregateRating',
                'ratingValue' => strval($avg_rating),
                'reviewCount' => strval($review_count),
                'bestRating'  => '5',
                'worstRating' => '1'
            ),
            'review'          => $reviews_array
        );

        // Filter for merchants or SEO integration plugins who want to stitch into their own schema graph
        $filtered_schema = apply_filters('ewithme_schema_graph', $schema_data, $post->ID);

        if (!empty($filtered_schema)) {
            echo "\n<!-- eWith.me Trust SSR Compliant Product Schema (Google Search Central Compliant) -->\n";
            // HIGH-01: Use JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT to prevent </script> tag breakouts
            echo '<script type="application/ld+json">' . wp_json_encode($filtered_schema, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "</script>\n";
        }
    }
}
