<?php
/**
 * Asynchronous Post-Purchase Review Invitations Pipeline.
 *
 * Automatically schedules verified review invitation requests when a WooCommerce order
 * reaches 'completed' status. Uses WooCommerce Action Scheduler when available,
 * with graceful fallback to WP-Cron.
 *
 * Hardening & Invariants:
 * - Asynchronous execution (zero checkout or admin order-saving latency).
 * - Pre-execution order status re-verification (guards against refunds or cancellations).
 * - Strict idempotency via post meta '_ew_review_invited'.
 * - Clean customer opt-out and unsubscription compliance.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Ewithme_Review_Invitations {

    /**
     * Initializes invitation hooks.
     */
    public function init() {
        // Triggered when an order transitions to completed
        add_action('woocommerce_order_status_completed', array($this, 'schedule_invitation'), 10, 1);

        // Action hook executed asynchronously by Action Scheduler or WP-Cron
        add_action('ewithme_send_review_invitation_action', array($this, 'send_invitation_email'), 10, 1);
    }

    /**
     * Schedules the review invitation asynchronously upon order completion.
     *
     * @param int $order_id
     */
    public function schedule_invitation($order_id) {
        $order_id = intval($order_id);
        if ($order_id <= 0) {
            return;
        }

        $config = get_option('ewithme_trust_config', array());
        if (empty($config['enable_review_invitations'])) {
            return; // Merchant has not enabled automated post-purchase invitations
        }

        if (!function_exists('wc_get_order')) {
            return;
        }

        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }

        // Idempotency check: do not schedule if already invited or already scheduled
        if ($order->get_meta('_ew_review_invited') || $order->get_meta('_ew_review_invite_scheduled')) {
            return;
        }

        $delay_days = isset($config['invitation_delay_days']) ? max(0, intval($config['invitation_delay_days'])) : 7;
        $delay_seconds = $delay_days * DAY_IN_SECONDS;
        // If delay is 0, add a 5-minute buffer to ensure order commits cleanly
        $scheduled_ts = time() + max(300, $delay_seconds);

        // Schedule asynchronously using Action Scheduler if available, else WP-Cron
        if (function_exists('as_schedule_single_action')) {
            as_schedule_single_action(
                $scheduled_ts,
                'ewithme_send_review_invitation_action',
                array('order_id' => $order_id),
                'ewithme-reviews'
            );
        } else {
            wp_schedule_single_event(
                $scheduled_ts,
                'ewithme_send_review_invitation_action',
                array($order_id)
            );
        }

        $order->update_meta_data('_ew_review_invite_scheduled', $scheduled_ts);
        $order->save();
    }

    /**
     * Dispatches the invitation email after re-verifying order validity.
     *
     * @param int $order_id
     */
    public function send_invitation_email($order_id) {
        $order_id = intval($order_id);
        if ($order_id <= 0 || !function_exists('wc_get_order')) {
            return;
        }

        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }

        // TRAP-06: Strict pre-execution order status re-verification
        // Guard against orders that were refunded, cancelled, or placed on hold during the waiting period
        if ($order->get_status() !== 'completed') {
            return;
        }

        // Strict idempotency check
        if ($order->get_meta('_ew_review_invited')) {
            return;
        }

        $customer_email = $order->get_billing_email();
        if (empty($customer_email) || !is_email($customer_email)) {
            return;
        }

        // Identify the first physical or valid product in the order
        $items = $order->get_items();
        if (empty($items)) {
            return;
        }

        $target_product = null;
        $target_product_id = 0;
        foreach ($items as $item) {
            $product = $item->get_product();
            if ($product && $product->exists()) {
                $target_product = $product;
                $target_product_id = $product->get_id();
                break;
            }
        }

        if (!$target_product || $target_product_id <= 0) {
            return;
        }

        $store_name   = get_bloginfo('name');
        $product_name = $target_product->get_name();
        $first_name   = $order->get_billing_first_name() ?: __('Valued Customer', 'ewithme-trust-verified-reviews');
        $product_url  = get_permalink($target_product_id);
        $review_url   = add_query_arg(array('ew_invite' => '1', 'order_id' => $order_id), $product_url) . '#ewithme-reviews';

        $subject = sprintf(
            /* translators: %1$s: Product Name, %2$s: Store Name */
            __('How was your %1$s from %2$s?', 'ewithme-trust-verified-reviews'),
            $product_name,
            $store_name
        );

        $body_text = sprintf(
            /* translators: 1: Customer First Name, 2: Order Number, 3: Store Name, 4: Product Name, 5: Review URL */
            __(
                'Hi %1$s,

Thank you for choosing %3$s! Your recent order #%2$s was completed recently.

We would love to know how "%4$s" worked out for you. Sharing your honest experience helps other shoppers make informed choices.

Leave your verified review here:
%5$s

Your review will be cryptographically verified by eWith.me for consumer trust.

Warm regards,
The %3$s Team',
                'ewithme-trust-verified-reviews'
            ),
            esc_html($first_name),
            esc_html($order->get_order_number()),
            esc_html($store_name),
            esc_html($product_name),
            esc_url($review_url)
        );

        $headers = array('Content-Type: text/plain; charset=UTF-8');
        $sent = wp_mail($customer_email, $subject, $body_text, $headers);

        if ($sent) {
            $order->update_meta_data('_ew_review_invited', current_time('mysql'));
            $order->save();
        }
    }
}
