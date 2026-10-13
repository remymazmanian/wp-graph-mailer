# Sendelva – Email Delivery for Microsoft 365

[![Plugin version](https://img.shields.io/wordpress/plugin/v/sendelva)](https://wordpress.org/plugins/sendelva/) [![Active installs](https://img.shields.io/wordpress/plugin/installs/sendelva)](https://wordpress.org/plugins/sendelva/) [![Tested up to](https://img.shields.io/wordpress/plugin/tested/sendelva)](https://wordpress.org/plugins/sendelva/)

WordPress mail through Microsoft Graph. No SMTP. Formerly Graph Mailer.

If your domain's mail lives in Microsoft 365, your WordPress server does not need SMTP credentials, an SMTP relay, or PHPMailer configuration. Sendelva short-circuits `wp_mail()` and delivers through the Microsoft Graph `sendMail` endpoint with an app-only OAuth token. Password resets, form notifications, and everything else that uses `wp_mail()` goes out through your real tenant, lands with proper SPF/DKIM alignment, and appears in the sender's Sent Items.

## How it behaves

- **Inert until configured.** With any credential missing, the plugin returns control to WordPress and mail flows exactly as before. Activating it changes nothing by itself.
- **Fails safely.** With fallback on (the default), a Graph error hands the message back to the default mailer rather than dropping it. With fallback off, failures are loud: the send fails and `wp_mail_failed` fires.
- **Behaves like WordPress's own mailer.** HTML switched on with the `wp_mail_content_type` filter (how WooCommerce and most form plugins do it) or a `Content-Type` header is sent as HTML. `wp_mail_succeeded` fires after a successful send, so mail logging plugins keep working.
- **Tokens are cached** in a transient and refreshed two minutes before expiry. A cached token is only used with the credentials it was issued for, and changing settings clears the cache. If Microsoft refuses a token, the plugin waits a minute before asking again rather than holding up every email.
- **Everything is logged.** The last 20 sends — successes and failures, with the Graph error message — are on the settings screen. A failure that was handed to the default mailer says so.
- **HTML, cc/bcc, reply-to, and attachments** (to the 3 MB `sendMail` request limit) are supported. Every address in a header such as `Cc: a@example.com, b@example.com` is delivered, display names are kept, and an attachment keyed by name (WordPress 6.2+) is sent under that name.
- **Mail goes out as the sender mailbox.** Graph sends as the configured mailbox, so a `From` header set by another plugin is ignored. To route replies elsewhere, such as to a contact form visitor, set `Reply-To`.

## Install

Sendelva is on the WordPress.org plugin directory: [wordpress.org/plugins/sendelva](https://wordpress.org/plugins/sendelva/)

- **From your dashboard:** Plugins > Add New > search "Sendelva" > Install Now > Activate.
- **With WP-CLI:** `wp plugin install sendelva --activate`

Then follow the setup below.

## Setup

Prefer it as a web page? The same walkthrough lives at [remymazmanian.com/wordpress-email-microsoft-365-guide](https://remymazmanian.com/wordpress-email-microsoft-365-guide/).

The settings screen carries a live checklist that ticks itself off, and this is the long form:

1. **Register an application** — Microsoft Entra admin center → App registrations → New registration. Single tenant, no redirect URI. Copy the **Directory (tenant) ID** and **Application (client) ID**.
2. **Create a client secret** — Certificates & secrets → New client secret. Copy the value immediately; it is shown once.
3. **Grant Mail.Send** — API permissions → Add a permission → Microsoft Graph → **Application permissions** → `Mail.Send`, then **Grant admin consent**. This is the step everyone forgets; without consent the token is issued but every send is refused.
4. **Enter the four values** under Settings → Sendelva: tenant ID, client ID, secret, and the sender mailbox. The sender must be a real (or shared) mailbox in the tenant.
5. **Send a test message** from the same screen and check the send log.

### Keeping the secret out of the database

The secret field is write-only — it is never redisplayed. Better still, define the credentials in `wp-config.php` and the database copies are ignored:

```php
define( 'SENDELVA_TENANT_ID', '…' );
define( 'SENDELVA_CLIENT_ID', '…' );
define( 'SENDELVA_CLIENT_SECRET', '…' );
define( 'SENDELVA_SENDER', 'noreply@example.com' );
```

The settings screen labels each value that comes from a constant. The `GRAPH_MAILER_*` names from before the rename still work.

### Coming from Graph Mailer

Sendelva is Graph Mailer under a new name, in its own `sendelva` folder. Install and activate it: the first time it runs, it copies Graph Mailer's settings and send log. Then deactivate and delete Graph Mailer; while it's still active, Sendelva shows a link to deactivate it. Each email is sent only once in the meantime.

### Scoping the permission

`Mail.Send` as an application permission allows sending as **any** mailbox in the tenant. Constrain it with an [application access policy](https://learn.microsoft.com/en-us/graph/auth-limit-mailbox-access) so the app can only send as the mailbox you intend:

```powershell
New-ApplicationAccessPolicy -AppId <client-id> -PolicyScopeGroupId <mail-enabled-group> -AccessRight RestrictAccess
```

Not required to function — required to be honest about blast radius if the secret leaks.

## Development

`readme.txt` is the WordPress.org readme, and `.wordpress-org/` holds the directory banners, icons and screenshots.

Build the plugin package exactly as a release does (the repository minus everything in `.distignore`, in a `sendelva/` folder):

```bash
bin/build-zip.sh
```

CI runs the official [Plugin Check](https://wordpress.org/plugins/plugin-check/) on that package for every push and pull request. Publishing a GitHub release attaches the zip to the release and, once the `SVN_USERNAME` and `SVN_PASSWORD` secrets are set, deploys the same files to WordPress.org.

The end-to-end tests start WordPress in Docker with a stand-in for Microsoft's token and Graph endpoints, then check what each `wp_mail()` call actually sends:

```bash
tests/e2e/run.sh
```

They need Docker. `KEEP=1 tests/e2e/run.sh` leaves the site running at http://localhost:8091.

## Requirements

| | |
|---|---|
| WordPress | 6.0+ (uses the `pre_wp_mail` filter) |
| PHP | 7.4+ |
| Microsoft | A Microsoft 365 tenant, and admin rights in it once, for consent |

## Licence

Copyright (C) 2026 Remy Mazmanian.

GPL-2.0-or-later. Full text in [`LICENSE`](LICENSE); copyright notice in [`NOTICE`](NOTICE).
