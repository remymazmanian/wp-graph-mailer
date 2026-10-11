# Changelog

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project uses [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.2.0] - 2026-10-11

Fixes HTML mail, Cc and Bcc lists, and recipients whose names contain commas. Nothing needs reconfiguring after the update.

### Added

- `wp_mail_succeeded` fires after a successful Graph send, as it does after WordPress's own mailer, so mail logging plugins see these messages.
- Attachments keyed by name (WordPress 6.2 and newer) are sent under that name.
- Display names on recipients and `Reply-To`, such as `Support Team <support@example.com>`.
- Uninstalling on a multisite network removes the settings, log and cached tokens from every site.
- `SECURITY.md` explaining how to report a vulnerability privately.
- End-to-end tests that run WordPress in Docker against a stand-in for Microsoft's endpoints, and CI that checks PHP syntax on 7.4 to 8.5 and runs them.

### Changed

- After Microsoft refuses a token, the plugin waits a minute before asking again, instead of making every email wait up to 15 seconds for the same refusal. Saving the settings or changing the credentials retries straight away.
- A failed send that was handed to the default mailer says so in the send log.

### Fixed

- HTML mail from plugins that switch to HTML with the `wp_mail_content_type` filter, including WooCommerce and most form plugins, arrived as raw markup. It's now sent as HTML.
- A header holding several addresses, such as `Cc: a@example.com, b@example.com`, lost every address in it. All of them are delivered now.
- A recipient whose quoted name contains a comma, such as `"Doe, Jane" <jane@example.com>`, was split into broken addresses.
- A cached access token could be used after the credentials in `wp-config.php` changed. Tokens are now tied to the credentials they were issued for.
- A message with no valid recipient was sent to Graph anyway and failed there. It now fails straight away with a clear error.
- The same address listed twice is sent once.

## [0.1.0] - 2026-08-17

First release of Graph Mailer, a WordPress plugin that sends your site's email through Microsoft Graph and your Microsoft 365 tenant, with no SMTP credentials on the server. It needs WordPress 6.0 or newer, PHP 7.4 or newer and a Microsoft 365 tenant.

### Added

- Delivery of everything sent through `wp_mail()`, such as password resets and form notifications, through the Microsoft Graph `sendMail` endpoint with an app-only OAuth token. Sent messages appear in the sender mailbox's Sent Items.
- No change to your mail until the plugin is configured: while any of the four credentials is missing, WordPress mail works exactly as before.
- Fallback to WordPress's default mailer when Graph returns an error, on by default. With fallback off, the send fails and `wp_mail_failed` fires.
- Support for HTML mail, cc, bcc, reply-to and attachments up to the 3 MB `sendMail` request limit.
- Access token caching, with the token refreshed two minutes before it expires and cleared whenever settings change.
- Send log of the last 20 messages on the settings screen, including the Graph error for any failed send.
- Test message form on the settings screen.
- Write-only client secret field, plus `wp-config.php` constants (`GRAPH_MAILER_TENANT_ID`, `GRAPH_MAILER_CLIENT_ID`, `GRAPH_MAILER_CLIENT_SECRET`, `GRAPH_MAILER_SENDER`) that take priority over the stored settings.
- Setup checklist on the settings screen that ticks itself off, and a step-by-step Microsoft Entra guide you can show or hide.

[Unreleased]: https://github.com/remymazmanian/wp-graph-mailer/compare/v0.2.0...HEAD
[0.2.0]: https://github.com/remymazmanian/wp-graph-mailer/releases/tag/v0.2.0
[0.1.0]: https://github.com/remymazmanian/wp-graph-mailer/releases/tag/v0.1.0
