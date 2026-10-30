# Ewithme Trust & Verified Reviews (WordPress & WooCommerce Plugin)

[![Latest Release](https://img.shields.io/badge/release-v2.1.2-blue)](https://github.com/ewithme-coder/ewithme-trust-verified-reviews/releases/latest)
[![WordPress](https://img.shields.io/badge/WordPress-6.2%2B%20%7C%20Tested%207.1-21759b)](https://ewith.me/trust)
[![PHP](https://img.shields.io/badge/PHP-7.4%2B-777bb4)](https://www.php.net/)
[![License](https://img.shields.io/badge/License-GPLv2%2B-green)](https://www.gnu.org/licenses/gpl-2.0.html)

Self-hosted customer reviews for WordPress and WooCommerce with detached Ed25519 cryptographic signatures (RFC 8785 JCS), Google Search Central-compliant single-product `Product` schema, and an open anti-suppression audit layer.

## 📥 1-Click Download & Install

👉 **[Download `ewithme-trust-verified-reviews-2.1.2.zip` (Latest Release)](https://github.com/ewithme-coder/ewithme-trust-verified-reviews/releases/latest/download/ewithme-trust-verified-reviews-2.1.2.zip)**

1. Download the `.zip` file from the link above (do not use `Code -> Download ZIP`, as WordPress requires the flat plugin archive from Releases).
2. In your WordPress Admin dashboard, go to **Plugins → Add New → Upload Plugin**.
3. Choose `ewithme-trust-verified-reviews-2.1.2.zip`, click **Install Now**, and click **Activate**.
4. Open **Trust Reviews → Settings** to customize widget colors, enable WooCommerce integration, or optionally opt in to the eWith.me Trust Verification API.

---

## ✨ Key Features

- **100% Local Data Ownership (`wp_ew_reviews`)**: All customer reviews and ratings are stored in your own WordPress MySQL database. Works out-of-the-box in 100% offline **Local Mode** with zero vendor lock-in.
- **Strict Opt-In Privacy (WordPress.org Guidelines 7 & 9 Compliant)**: Never phones home on activation or without explicit administrator consent. Connecting to the external eWith.me Trust Verification API is 100% optional and turned **OFF** by default.
- **Cryptographic Attestations (Protocol v2)**: When opted in, each verified review receives a detached Ed25519 signature over its RFC 8785 canonical JSON payload and salted text commitment (`SHA-256(salt || text)`). Any shopper or auditor can verify proofs offline using the bundled `Ewithme_Trust_Verifier` class (`libsodium`).
- **Google Search Central Compliant Schema**: Emits server-side rendered (SSR) `Product` and `AggregateRating` JSON-LD strictly on single product pages. Automatically suppresses schema on homepages, categories, and archives, and excludes incentivized reviews from rich snippet markup.
- **Transparent Verification Tiers**: Clearly distinguishes between `Purchase-Verified` (linked to completed WooCommerce orders), `Email-Confirmed`, and `Store-Imported` reviews.
- **Author Self-Service Cryptographic Erasure (GDPR / WS7)**: Generates a unique capability token (`ew_erase_...`) upon review submission so authors can erase their review plaintext and personal data at any time via the storefront modal or `POST /wp-json/ewithme/v1/erase-review`.
- **1-Click CSV Importer**: Migrate existing reviews from Judge.me, Loox, CusRev, or generic CSV exports with automatic SKU/ID/Title product matching and honest `Store-Imported` badges.
- **Automated Post-Purchase Review Invitations**: Asynchronously schedules post-purchase review request emails via WooCommerce Action Scheduler (or WP-Cron fallback) with pre-dispatch order status re-verification.

---

## 📋 Available Shortcodes

| Shortcode | Description |
| :--- | :--- |
| `[ewithme_reviews]` | Renders the single-product review scorecard, verified review feed, "Write a Review" modal, and "Self-Service Erasure (GDPR)" modal. |
| `[ewithme_reviews scope="all"]` | Displays a store-wide testimonials wall aggregating all verified store reviews while isolating single-product JSON-LD schema. |
| `[ewithme_badge]` | Renders a compact star rating badge (`★★★★★ 4.9 (42)`). |
| `[ewithme_audit_badge]` | Displays the live count of displayed verified reviews and (when connected) links to your public domain audit ledger. |
| `[ewithme_disclosure]` | Outputs a dynamic FTC & EU Omnibus Directive compliant consumer review collection and verification disclosure statement. |
