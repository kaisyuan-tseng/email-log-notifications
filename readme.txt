=== Email Log & Notifications ===
Contributors: kaisyuan-tseng
Tags: email, log, notifications, smtp, inquiry
Requires at least: 6.0
Tested up to: 7.1.4
Requires PHP: 8.0
Stable tag: 2.0.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A companion to your SMTP plugin: sends inquiry notifications and localized auto-confirmations, and keeps a delivery log for every send.

== Description ==

Email Log & Notifications handles the content and outcome of transactional inquiry email. Your SMTP plugin handles delivery; this plugin decides what is sent, logs the result, and gives administrators a read-only record of every inquiry.

Features:

* Public REST endpoint for inquiry submission (`POST /wp-json/email-log-notifications/v1/inquiry`) with honeypot and rate limiting.
* Inquiry notification to the sales inbox, with optional attachments.
* Auto-confirmation to the visitor in the visitor's own language, with per-language editable templates.
* Delivery log: success/failure plus the raw SMTP error, visible on the inquiry detail screen.
* Read-only inquiry list, detail dialog, dashboard widget, and visual template editor.
* Configurable field labels, required fields, recipients, attachment limits, and dashboard limits through filters.

This plugin does not send unsolicited marketing email and does not phone home. It does not require a third-party account.

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/` or install it from the WordPress.org plugin directory.
2. Activate the plugin through the Plugins screen in WordPress.
3. Open `Inquiries` in the admin menu and then `Email Templates` to set the notification recipient, subject, and body.
4. Point your front-end form to the REST endpoint documented in the FAQ.

**Upgrading from 1.x:** version 2.0.0 renames the inquiry post type, meta keys, options, and attachment directory. Back up your database and uploads before upgrading.

== Frequently Asked Questions ==

= What data does the plugin collect? =

For each inquiry the plugin stores the name, email, message, any submitted form fields, browser language, timezone, source page, IP address, and any uploaded attachments. This data is stored as a private post type and in the uploads directory. It is displayed only to users with permission to view inquiries.

= Does the plugin send data to third parties? =

No. The plugin sends transactional email through `wp_mail()` using your site's configured mailer. Version 2.0.0 removed the previous dynamic lookup of a front-end field manifest from a visitor-supplied URL. Field labels now come from saved inquiry metadata, site configuration, or the `eln_field_manifest` filter.

= How can I change the recipient? =

Use the `eln_recipient` filter. The default is the site admin email.

= How can I add or change UI languages? =

All UI strings use WordPress gettext with the text domain `email-log-notifications`. Bundled `zh_CN` and `zh_TW` translations are included. Additional translations can be supplied through translate.wordpress.org or placed in `wp-content/languages/plugins/`.

= Are there developer hooks? =

Yes. Filters: `eln_recipient`, `eln_max_files`, `eln_required_fields`, `eln_length_limits`, `eln_dashboard_limit`, `eln_strings`, `eln_field_manifest`. Action: `eln_autoreply_sent`.

= How do I remove my data? =

Deleting an inquiry removes its attachments. The WordPress privacy tools can export and erase inquiry records by email address. Uninstalling the plugin removes its options, inquiry records, stored attachments, and custom capabilities.

== Screenshots ==

1. Inquiry list with delivery status.
2. Read-only inquiry detail dialog.
3. Email template editor with live preview.

== Changelog ==

= 2.0.0 =

* Breaking change: inquiry post type renamed to `eln_inquiry`, meta keys and options prefixed with `eln_`, and attachments moved to `uploads/eln-inquiries/`. A one-time migration runs in the admin. Back up before upgrading.
* Breaking change: legacy REST namespace `site-inquiries/v1` and old admin action names are deprecated and will be removed in 2.1.0.
* Security: removed a debug log that wrote template settings to a web-accessible file.
* Security: removed a server-side HTTP request driven by visitor-supplied URLs (SSRF).
* Security: inquiry detail and delete now use object-level capability checks.
* Security: hardened rate limiting, upload validation, inline script encoding, and preview sandboxing.
* Engineering: split the monolithic plugin into focused modules, externalized CSS/JS, and added uninstall and privacy support.
* I18n: replaced the built-in three-language array with WordPress gettext and bundled POT/PO/MO files.

== Upgrade Notice ==

= 2.0.0 =
Back up your database and uploads before upgrading. This release migrates post types, meta keys, options, and attachment paths.
