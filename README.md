=== Ewithme Trust & Verified Reviews ===
Contributors: ewithme
Donate link: https://ewith.me/trust
Tags: reviews, ratings, woocommerce, customer reviews, schema
Requires at least: 6.2
Tested up to: 7.1
Stable tag: 2.1.2
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Self-hosted customer reviews with detached cryptographic signatures, Search Central-compliant single-product schema, and an open audit layer.

== Description ==

eWith.me Trust is an open verification protocol and customer review plugin for WordPress and WooCommerce.

Unlike hosted review platforms where feedback is locked in a third-party silo, eWith.me stores all review data in your own WordPress database (`wp_ew_reviews`). When you opt in to connect the eWith.me verification service, each verified review receives a detached Ed25519 cryptographic attestation and text commitment registered on an append-only ledger, allowing anyone to verify review authenticity offline using the bundled verifier (`Ewithme_Trust_Verifier`) without contacting eWith.me servers.

= Why eWith.me Trust? =
* **Full Data Ownership**: Store reviews in your local WordPress database. No vendor lock-in and full local functionality.
* **Explicit Opt-In Privacy**: Never phones home on activation. External API connection is 100% opt-in from your Settings screen.
* **Cryptographic Attestations (Protocol v2)**: Reviews are canonicalized with RFC 8785 (JCS) and signed using detached Ed25519 signatures. Anyone can verify proofs offline using the bundled `Ewithme_Trust_Verifier` class.
* **Search Central Compliance**: Automatically generates valid JSON-LD `Product` and `AggregateRating` schema exclusively on single product pages. Suppressed on home, about, category, and archive pages to protect against Google rich snippet manual actions.
* **Transparent Verification Tiers**: Distinguishes between `purchase_verified` (automatically hooked to WooCommerce completed orders), `email_confirmed`, and `native_imported` reviews.
* **FTC & EU Omnibus Disclosure**: Includes the `[ewithme_disclosure]` shortcode explaining how reviews are collected and verified, and automatically excludes incentivized reviews from structured data.
* **Cryptographic Erasure (GDPR)**: Supports both administrator moderation and author self-service erasure via capability tokens (`ew_erase_...` issued upon review submission). Reviewers can erase their review plaintext and personal data directly via the storefront "Self-Service Erasure (GDPR)" modal or the `/wp-json/ewithme/v1/erase-review` REST endpoint while maintaining hash chain continuity.
* **Anti-Suppression Audit Layer**: Detects hidden or deleted reviews by comparing registered reviews against displayed storefront reviews.
* **Domain Proof of Control**: Gated by DNS TXT record or HTTP challenge endpoint (`/wp-json/ewithme/v1/verify-origin`) to prevent third-party domain spoofing.

== External services ==

This plugin can optionally connect to the **eWith.me Trust Verification API** (`https://ewith.me`) to send single-use email confirmation links to reviewers, issue detached Ed25519 cryptographic signatures over review commitments, and synchronize your public anti-suppression audit ledger.

**Important Privacy Guarantee:** This plugin does **not** transmit any data during plugin activation. Connecting to eWith.me requires explicit administrator opt-in under **Trust Reviews > Settings**.

When connected, data is transmitted to `https://ewith.me` under the following specific conditions:

1. **API Key Provisioning (`POST https://ewith.me/api/v1/trust/provision`)**:
   * **When**: Only when the site administrator checks the consent box and clicks **"Opt-In & Provision Free API Key"** in **Trust Reviews > Settings**.
   * **Data sent**: Store domain name, site title, and (only if optionally checked by the administrator) the administrator email address.
2. **Domain Ownership Verification (`POST https://ewith.me/api/v1/trust/verify-domain`)**:
   * **When**: Only when the site administrator clicks **"Verify Domain Control Now"** in **Trust Reviews > Settings**.
   * **Data sent**: Store domain name and selected verification method (`auto`, `dns`, or `http`).
3. **Customer Review Verification Request (`POST https://ewith.me/api/v1/trust/request-verification`)**:
   * **When**: When a customer submits the review form on the frontend (or when a native WooCommerce review is submitted if the administrator opted in to native comment notarization).
   * **Data sent**: Reviewer display name, reviewer email address (used strictly to dispatch the 1-click verification link and deleted after confirmation), star rating, review title, review text, product ID/title, verification tier, incentivized status, and the author's erasure capability token.
4. **Cryptographic Review Erasure (`POST https://ewith.me/api/v1/trust/erase-review`)**:
   * **When**: Only when the administrator has explicitly opted in (`external_service_optin`) and configured an API key, AND a verified local review with a remote certificate ID is erased by its author via their Erasure Capability Token or moderated by an administrator in **Trust Reviews > All Reviews**. When operating in Local Mode (without opt-in), erasure is performed strictly within the local WordPress database with zero external network requests.
   * **Data sent**: Review Certificate ID (`cert_id`), Erasure Capability Token (`erasure_token`), and moderation/erasure reason (`reason`).
5. **Quota Status & Ledger Synchronization (`GET https://ewith.me/api/v1/trust/quota-status` and `GET https://ewith.me/api/v1/trust/feed/{domain}`)**:
   * **When**: Only when the administrator has explicitly opted in and configured an API key, and views the Settings screen (cached for 1 hour) or clicks **"Sync with eWith.me Ledger"**.
   * **Data sent**: Store domain name and Bearer API key.

This service is provided by **eWith.me**:
* [eWith.me Trust Service Overview](https://ewith.me/trust)
* [eWith.me Terms of Service](https://ewith.me/terms)
* [eWith.me Privacy Policy](https://ewith.me/privacy)

== Installation ==

Setting up eWith.me Trust on your store takes about 3 to 5 minutes:

1. **Install & Activate**:
   In WordPress Admin, go to **Plugins > Add New > Upload Plugin**, choose the plugin zip file, and click **Activate**. Activation creates the local `wp_ew_reviews` database table cleanly with zero external network calls.

2. **Opt-In & Connect API Key**:
   Navigate to **Trust Reviews > Settings**. Review the external service disclosure, check the opt-in consent box, and click **"Opt-In & Provision Free API Key"**.

3. **Verify Domain Control**:
   Under "Domain Ownership Verification", click **"Verify Domain Control Now"**.
   * *Option A (Default)*: 1-Click Instant HTTP Origin Verification (automatic, no DNS edits needed).
   * *Option B (Advanced / Cloudflare)*: Add a DNS TXT record (`_ewithme-challenge` -> your token) and click verify.

4. **Style & Placement**:
   * *WooCommerce*: The plugin automatically replaces the standard product reviews tab by default.
   * *Branding*: Under **Trust Reviews > Settings**, adjust the **Star Rating Color** and **Primary Button Color** to match your store theme.
   * *Custom Builders*: Embed `[ewithme_reviews]` anywhere on single product templates.

5. **Import Existing Reviews (Optional)**:
   If migrating from Judge.me, Loox, CusRev, or CSV, go to **Trust Reviews > Import**, drop your export file, and let the auto-matcher import your reviews with truthful "Store-Imported" badges.

6. **Enable Post-Purchase Autopilot (Optional)**:
   In **Trust Reviews > Settings**, check **"Automatically email customers post-purchase to collect verified reviews"** and set your delivery delay (e.g. 7 days).

== Available Shortcodes ==

* `[ewithme_reviews]`: Renders the single-product review grid, average score summary, cryptographic verification marks, interactive customer review submission modal, and self-service GDPR erasure modal.
* `[ewithme_reviews scope="all"]`: Displays a store-wide testimonials wall aggregating all verified store reviews on a dedicated page (e.g. `yourstore.com/reviews`). Automatically suppresses the submission modal to isolate single-product rich snippet schemas.
* `[ewithme_badge]`: Displays a compact verified rating pill linking to your domain's live public audit report (ideal for site headers, footers, or checkout trust badges).
* `[ewithme_audit_badge]`: Displays the live count of displayed verified reviews and links directly to your domain's public audit ledger.
* `[ewithme_disclosure]`: Generates a dynamic consumer review transparency policy statement explaining collection and verification criteria (satisfies FTC and EU Omnibus Directive requirements).

== Frequently Asked Questions ==

= Does this plugin phone home or send data when activated? =
No. Activating the plugin only creates the local `wp_ew_reviews` table in your WordPress database. No external connections are made unless and until an administrator explicitly opts in on the **Trust Reviews > Settings** page.

= How does author self-service cryptographic erasure work? =
When a customer submits a review, the plugin generates a unique capability token (`ew_erase_...`), stores its SHA-256 hash (`erasure_token_hash`) in `wp_ew_reviews`, and displays the token to the author. At any time, the author can click **"Self-Service Erasure (GDPR)"** on the review widget (or call `POST /wp-json/ewithme/v1/erase-review`) and submit their capability token to immediately wipe their review plaintext, headline, and personal data locally and on the ledger.

= Do I need to edit DNS records or contact my web host? =
In most cases, no. The plugin supports an automatic HTTP origin challenge (`/wp-json/ewithme/v1/verify-origin`) that completes verification in 2 seconds without touching DNS. A DNS TXT record option (`_ewithme-challenge`) is provided as a fallback for stores behind strict firewall caching.

= Can I import our existing reviews from Judge.me, Loox, or CusRev? =
Yes. Use **Trust Reviews > Import** to upload your CSV export. Our auto-detection engine maps reviewer names, ratings, text, dates, and product SKUs/IDs. Imported reviews display with an honest "Store-Imported" badge.

= Where are customer reviews stored? =
All reviews and ratings are stored in your own WordPress MySQL database in the `wp_ew_reviews` table. You retain complete custody of your content.

= How does independent offline verification work? =
Each registered review receives an Ed25519 signature over its RFC 8785 canonical hash and text commitment (`SHA-256(salt || text)`). Any auditor or shopper can verify this proof offline using the bundled `Ewithme_Trust_Verifier` class and our published public key.

= Why does structured data only appear on single product pages? =
Google's Search Central structured data policies treat review stars on homepages, category pages, or organization entities as self-serving. To protect your site against manual penalties, schema is strictly limited to single product pages where reviews are visibly rendered.

== Screenshots ==

1. Customer review widget showcasing rating scorecard, verified tier pills, and self-service erasure trigger.
2. Compact trust badge with live audit link.
3. Administrative settings panel with explicit opt-in provisioning, domain challenge verification, and schema controls.

== Changelog ==

= 2.1.2 =
* Compliance & Privacy Fix: Enforced centralized `Ewithme_Api_Client::is_connected()` opt-in and API key verification across all outbound API methods (`request_author_erasure`, `verify_domain_proof`, `sync_ledger_feed`, `request_verification`, and `check_quota`) to guarantee zero remote calls when the administrator has not opted in (Guidelines 7 & 9).
* Security & Privacy Fix: Updated `execute_capability_token_erasure()` to authenticate the capability token hash against the local database first and only notify the remote ledger if a matching local review has a certificate ID and the administrator has opted in.
* Admin Settings Improvement: Added an explicit "External Service Opt-In" toggle in Settings so administrators can enable or revoke external API connectivity at any time, and added local review approval support when operating in 100% offline Local Mode.
* Frontend Hygiene: Removed unused external URL parameters from `wp_localize_script` and gated external verification badge links on active opt-in status.

= 2.1.1 =
* Compliance & Privacy Fix: Removed automatic domain provisioning during plugin activation (`register_activation_hook`). External service provisioning now requires explicit administrator opt-in in Settings (Guidelines 7 & 9).
* Documentation Fix: Added complete `== External services ==` section in `readme.txt` detailing all API endpoints, transmitted data, Terms of Service (`https://ewith.me/terms`), and Privacy Policy (`https://ewith.me/privacy`).
* Feature Complete: Added local capability token generation (`erasure_token_hash` column in `wp_ew_reviews`), frontend "Self-Service Erasure (GDPR)" modal, AJAX handler (`ewithme_author_erase_review`), and REST endpoint (`POST /wp-json/ewithme/v1/erase-review`) for author self-service cryptographic erasure.
* Performance & Scoping Fix: Frontend CSS and JS assets are now registered and enqueued strictly on pages where shortcodes or WooCommerce product tabs are rendered, and CSS custom properties are scoped to plugin containers rather than `:root`.
* Bug Fix: Fixed undefined `$rating` variable warning in `handle_native_review_comment()` under PHP 8.x with `WP_DEBUG` enabled, and made native WooCommerce comment forwarding explicitly opt-in.

= 2.1.0 =
* Added Store Owner Responses: public merchant replies rendered directly in customer review cards.
* Added Full WP_List_Table: paginated review management (20/page) with rating/status filters, text search, order attribution links, and bulk hide/show actions.
* Added Global Shortcode `[ewithme_reviews scope="all"]`: dedicated store-wide testimonials wall with single-product schema isolation.
* Added Multi-Platform CSV Review Importer: 1-click migration for Judge.me, Loox, CusRev, and generic CSVs with honest "Store-Imported" badges.
* Added Automated Post-Purchase Invitations: asynchronous review collection pipeline via WooCommerce Action Scheduler with status re-verification.
* Added 5-Minute In-Dashboard Merchant Quickstart Guide and Shortcode Cheat Sheet in settings.
* Added Real-time Admin Email Alerts on new verified customer reviews via `wp_mail()`.
* Added Store Theme Branding: custom star rating and primary button accent color pickers.

= 2.0.0 =
* Upgraded to Protocol v2: RFC 8785 JSON canonicalization, detached Ed25519 signatures, and HKDF per-domain blind indexing.
* Added WS5 domain proof of control: verification via DNS TXT record or HTTP challenge file.
* Added WS6 verification tiers (`purchase_verified` and `email_confirmed`) and `[ewithme_disclosure]` shortcode.
* Added WS7 cryptographic erasure supporting reviewer privacy requests.

= 1.1.0 =
* Search Central compliance updates: restricted structured data to single product pages.
* Added exclusion of incentivized reviews from rich snippet markup.

= 1.0.0 =
* Initial public release.
