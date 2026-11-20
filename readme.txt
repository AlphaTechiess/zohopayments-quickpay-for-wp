=== Zoho Payments Quick Pay ===
Contributors: zohopayments
Tags: zoho, zoho payments, payment gateway, gutenberg, checkout, quick pay
Requires at least: 5.8
Tested up to: 7.1.5
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Accept payments seamlessly on WordPress using Zoho Payments. Extends the native WordPress Button block with Quick Pay checkout, zero cron bloat, and cryptographic webhook verification.

== Description ==

**Zoho Payments Quick Pay** enables businesses and website owners to easily accept online payments using Zoho Payments without writing a single line of code.

### Key Highlights:

* **Native WordPress Button Integration**: Seamlessly extends the native WordPress Button (`core/button`) block under **Advanced Settings**. Also available directly in the Gutenberg block inserter as **Zoho Payments Quick Pay**.
* **100% Theme Style Inheritance**: Inherits all global colors, gradients, borders, radiuses, typography, outline/fill styles, and alignments directly from your WordPress theme (`theme.json`).
* **Zero Cron Bloat & On-Demand OAuth**: Generates access tokens strictly on-demand when a customer initiates checkout. Caches tokens safely with an expiration buffer. Zero background hourly cron jobs, keeping your server fast and resource-efficient.
* **Official Zoho Payments SDK**: Integrates the official Zoho client-side checkout widget (`zpayments.js`) for a seamless customer payment experience.
* **Dual-Engine Reliability**: Connects via high-performance REST API with automatic AJAX fallback to ensure checkout works flawlessly across all hosting environments and permalink configurations.
* **Cryptographic Webhook Verification**: Automatically verifies incoming Zoho webhook events using HMAC-SHA256 (`X-Zoho-Webhook-Signature` with timestamp `t` and signature `v`) to protect your store against spoofing.
* **Sandbox & Live Modes**: Test transactions safely in Sandbox mode before switching to production.
* **Conditional Loading**: Frontend scripts and stylesheets are only enqueued on pages where a Zoho Payments button is active, guaranteeing zero bloat on non-payment pages.

== Installation ==

1. Upload the `zoho-payments` directory to the `/wp-content/plugins/` directory, or install the plugin directly through the WordPress plugins screen.
2. Activate the plugin through the 'Plugins' menu in WordPress.
3. Navigate to **Zoho Payments** in your WordPress admin menu.
4. Select your Edition, and enter your Zoho Payments Account ID, Client ID, Client Secret, API Key, and Webhook Signing Key.
5. Copy the displayed **Authorized Redirect URI** into your Zoho API Console, and add the **Webhook Notification URL** with the `payment.succeeded` event under Zoho Payments Developer Space > Webhooks.
6. Click **Connect with Zoho Payments** to complete OAuth authorization.
7. Add a **Button** to any page, open **Advanced Settings** in the block sidebar, and toggle **Enable Zoho Payments Checkout** (or select **Zoho Payments Quick Pay** directly from the block inserter).

== Frequently Asked Questions ==

= Where do I get my Zoho Client ID and Client Secret? =
Log in to the [Zoho API Console](https://api-console.zoho.com/add?client_type=ORG) and create an **Org Client**. Enter your client details, and paste the fixed **Authorized Redirect URI** provided in this plugin's settings page.

= Where do I find my Zoho Payments Account ID? =
You can find your Zoho Payments Account ID in your Zoho Payments dashboard under **Settings > Organization Profile**.

= Where do I get the API Key? =
In your Zoho Payments dashboard, navigate to **Settings > Developer Space > API Keys** to copy your developer API key.

= Where do I find the Webhook Signing Key? =
Go to **Settings > Developer Space > Webhooks** in your Zoho Payments dashboard. Click the options menu next to your webhook endpoint and select **View Signing Key**.

= What happens if I leave the Redirect URL empty? =
If you leave the Redirect URL blank in the block settings, the customer will simply remain on the current page after payment is completed. No redirection or page reload occurs.

== Changelog ==

= 1.0.0 =
* Initial production release.
* Seamless native WordPress Button (`core/button`) block extension with 100% theme style inheritance.
* Multi-edition support for India and United States Zoho accounts.
* Zero-cron on-demand OAuth 2.0 token generation with intelligent caching.
* Official Zoho Payments checkout widget integration with dual REST and AJAX fallback.
* Cryptographic HMAC-SHA256 webhook signature verification and automated order logging.
* Interactive copy tools with checkmark feedback.
