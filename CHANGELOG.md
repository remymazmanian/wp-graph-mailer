# Changelog

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project uses [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

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

[Unreleased]: https://github.com/remymazmanian/wp-graph-mailer/compare/v0.1.0...HEAD
[0.1.0]: https://github.com/remymazmanian/wp-graph-mailer/releases/tag/v0.1.0
