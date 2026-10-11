=== Graph Mailer ===
Contributors: remymazmanian
Tags: email, smtp, microsoft 365, graph, outlook
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.3.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Send WordPress email through Microsoft 365 with Microsoft Graph. No SMTP password on your server, a safe fallback, and a send log.

== Description ==

If your domain's mail lives in Microsoft 365, your WordPress server doesn't need SMTP credentials, an SMTP relay or PHPMailer configuration. Graph Mailer sends everything that goes through `wp_mail()`, such as password resets, form notifications and order emails, through the Microsoft Graph `sendMail` endpoint with an app-only OAuth token. Messages go out from your real tenant and appear in the sender mailbox's Sent Items.

= How it behaves =

* **Nothing changes until it's configured.** While any of the four credentials is missing, WordPress mail works exactly as before.
* **Fails safely.** With fallback on (the default), a Graph error hands the message back to WordPress's own mailer instead of dropping it. With fallback off, the send fails and `wp_mail_failed` fires.
* **Behaves like WordPress's own mailer.** HTML switched on with the `wp_mail_content_type` filter (how WooCommerce and most form plugins do it) or a `Content-Type` header is sent as HTML, and `wp_mail_succeeded` fires after each successful send, so mail logging plugins keep working.
* **HTML, Cc, Bcc, Reply-To and attachments** up to the 3 MB `sendMail` request limit. Every address in a header such as `Cc: a@example.com, b@example.com` is delivered, display names are kept, and an attachment keyed by name (WordPress 6.2 and newer) is sent under that name.
* **Mail goes out as the sender mailbox.** Microsoft Graph sends as the configured mailbox, so a `From` header set by another plugin is ignored. Set `Reply-To` to send replies elsewhere, such as to a contact form visitor.
* **Tokens are cached** and refreshed two minutes before they expire. A cached token is only used with the credentials it was issued for, and saving the settings clears it. If Microsoft refuses a token, the plugin waits a minute before asking again rather than holding up every email.
* **A send log** on the settings screen keeps the last 20 messages, successes and failures alike, with Microsoft's error message for any that failed.
* **A test message** button, a setup checklist that ticks itself off, and a step-by-step Microsoft Entra guide you can show or hide.
* **Secrets can stay out of the database.** The client secret field is write-only, and all four settings can be defined as constants in `wp-config.php` instead.

== Installation ==

1. Install and activate Graph Mailer from Plugins > Add New, or upload the `graph-mailer` folder to `/wp-content/plugins/`.
2. In the Microsoft Entra admin center, register an application (single tenant, no redirect URI) and copy its **Directory (tenant) ID** and **Application (client) ID**.
3. Under **Certificates & secrets**, create a client secret and copy its value. It's shown only once.
4. Under **API permissions**, add the Microsoft Graph **application** permission `Mail.Send`, then click **Grant admin consent**.
5. In WordPress, go to Settings > Graph Mailer, enter the tenant ID, client ID, client secret and the sender mailbox, and save.
6. Send a test message from the same screen and check the send log.

The settings screen has the same walkthrough built in, with links to each Microsoft page.

To keep the credentials out of the database, define them in `wp-config.php` instead. The settings screen labels each value that comes from a constant.

`define( 'GRAPH_MAILER_TENANT_ID', '...' );`
`define( 'GRAPH_MAILER_CLIENT_ID', '...' );`
`define( 'GRAPH_MAILER_CLIENT_SECRET', '...' );`
`define( 'GRAPH_MAILER_SENDER', 'noreply@example.com' );`

== Frequently Asked Questions ==

= Do I need an SMTP server or an SMTP password? =

No. Graph Mailer talks to Microsoft Graph over HTTPS with an app-only token. There is no SMTP connection and no mailbox password.

= Which Microsoft 365 permission does it need? =

The Microsoft Graph application permission `Mail.Send`, with admin consent. Without consent, Microsoft issues a token but refuses every send, which is the most common setup problem.

`Mail.Send` as an application permission can send as any mailbox in your tenant. To limit the app to the one mailbox you use, add an Exchange application access policy. Microsoft documents this under "Limiting application permissions to specific Exchange Online mailboxes".

= What happens if Microsoft Graph is unavailable? =

With fallback on (the default), the message is handed back to WordPress's own mailer and the send log notes that it was. With fallback off, the send fails and WordPress fires `wp_mail_failed`.

= Why is my From address ignored? =

Microsoft Graph sends as the configured sender mailbox. A `From` header added by another plugin can't change that. Use `Reply-To` to route replies somewhere else.

= Does it work with WooCommerce and contact form plugins? =

Yes. Anything that sends with `wp_mail()` goes through Graph Mailer, and HTML emails stay HTML.

= Is there a size limit? =

Attachments can total up to 3 MB, the limit of Microsoft Graph's `sendMail` request. Larger messages fail with a clear error (and fall back to the default mailer if fallback is on).

= What does the plugin store? =

Its settings (including the client secret, unless you use the `wp-config.php` constants), a cached access token, and a log of the last 20 messages with each recipient, subject and result. Uninstalling the plugin deletes all of it, on every site of a multisite network.

= Does it track me or contact anything other than Microsoft? =

No. It makes no requests of its own except to Microsoft's sign-in and Graph services, and only once it's configured. See "External services" below.

== External services ==

Graph Mailer connects to two Microsoft services. It makes no requests until all four settings (tenant ID, client ID, client secret and sender mailbox) are filled in.

**Microsoft identity platform** (`login.microsoftonline.com`) issues the access token Graph Mailer uses to send mail.

* When: before the first email is sent, then whenever the cached token is about to expire (tokens last about an hour) or the settings change.
* What is sent: your tenant ID, client ID and client secret, and the requested scope (`https://graph.microsoft.com/.default`).

**Microsoft Graph** (`graph.microsoft.com`) delivers the email.

* When: every time WordPress sends an email while Graph Mailer is configured, including test messages from the settings screen.
* What is sent: the access token, the sender mailbox, and the message itself: its recipients (To, Cc, Bcc and Reply-To, with display names), subject, body and any attachments.

Both services are provided by Microsoft. Use of them is covered by the [Microsoft APIs Terms of Use](https://learn.microsoft.com/en-us/legal/microsoft-apis/terms-of-use), the [Microsoft Services Agreement](https://www.microsoft.com/servicesagreement) and your organization's Microsoft 365 agreement, and Microsoft's handling of the data is described in the [Microsoft Privacy Statement](https://privacy.microsoft.com/privacystatement).

The settings screen also links to pages in the Microsoft Entra admin center and Microsoft's documentation. Those are ordinary links that open only when you click them.

== Screenshots ==

1. The settings screen: Microsoft Entra credentials, a setup checklist that ticks itself off, and status.
2. The send log keeps the last 20 messages, with the recipient, subject and result of each.
3. A test message sent from the settings screen, with the result shown at the top.
4. The step-by-step Microsoft Entra guide, which you can show or hide.

== Changelog ==

= 0.3.0 =
* Prepared for the WordPress.org plugin directory: added this readme, made every string translatable, and moved the settings screen's styles and script into enqueued files.
* Added a Settings link on the Plugins screen and suggested privacy policy text under Settings > Privacy.
* The test message result is passed back with a nonce instead of a plain URL parameter.
* The client secret is sanitized like the other settings when it's saved.

= 0.2.0 =
* Fixed HTML mail from plugins that use the `wp_mail_content_type` filter, which arrived as raw markup.
* Fixed Cc, Bcc and Reply-To headers with several addresses, which lost every address in them.
* Fixed recipients whose quoted display name contains a comma.
* Cached tokens are tied to the credentials they were issued for.
* Added `wp_mail_succeeded` after a successful send, attachment names from array keys, and multisite-aware uninstall.
* After a token refusal, the plugin waits a minute before asking again.

= 0.1.0 =
* First release.

== Upgrade Notice ==

= 0.3.0 =
Prepares Graph Mailer for the WordPress.org directory: translatable strings, enqueued admin assets and a nonce-checked test result. No settings change.
