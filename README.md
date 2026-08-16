# Graph Mailer

WordPress mail through Microsoft Graph. No SMTP.

If your domain's mail lives in Microsoft 365, your WordPress server does not need SMTP credentials, an SMTP relay, or PHPMailer configuration. Graph Mailer short-circuits `wp_mail()` and delivers through the Microsoft Graph `sendMail` endpoint with an app-only OAuth token. Password resets, form notifications, and everything else that uses `wp_mail()` goes out through your real tenant, lands with proper SPF/DKIM alignment, and appears in the sender's Sent Items.

## How it behaves

- **Inert until configured.** With any credential missing, the plugin returns control to WordPress and mail flows exactly as before. Activating it changes nothing by itself.
- **Fails safely.** With fallback on (the default), a Graph error hands the message back to the default mailer rather than dropping it. With fallback off, failures are loud: the send fails and `wp_mail_failed` fires.
- **Tokens are cached** in a transient and refreshed two minutes before expiry. Changing settings clears the cache.
- **Everything is logged.** The last 20 sends — successes and failures, with the Graph error message — are on the settings screen.
- **HTML, cc/bcc, reply-to, and attachments** (to the 3 MB `sendMail` request limit) are supported.

## Setup

The settings screen carries a live checklist that ticks itself off, and this is the long form:

1. **Register an application** — Microsoft Entra admin center → App registrations → New registration. Single tenant, no redirect URI. Copy the **Directory (tenant) ID** and **Application (client) ID**.
2. **Create a client secret** — Certificates & secrets → New client secret. Copy the value immediately; it is shown once.
3. **Grant Mail.Send** — API permissions → Add a permission → Microsoft Graph → **Application permissions** → `Mail.Send`, then **Grant admin consent**. This is the step everyone forgets; without consent the token is issued but every send is refused.
4. **Enter the four values** under Settings → Graph Mailer: tenant ID, client ID, secret, and the sender mailbox. The sender must be a real (or shared) mailbox in the tenant.
5. **Send a test message** from the same screen and check the send log.

### Keeping the secret out of the database

The secret field is write-only — it is never redisplayed. Better still, define the credentials in `wp-config.php` and the database copies are ignored:

```php
define( 'GRAPH_MAILER_TENANT_ID', '…' );
define( 'GRAPH_MAILER_CLIENT_ID', '…' );
define( 'GRAPH_MAILER_CLIENT_SECRET', '…' );
define( 'GRAPH_MAILER_SENDER', 'noreply@example.com' );
```

The settings screen labels each value that comes from a constant.

### Scoping the permission

`Mail.Send` as an application permission allows sending as **any** mailbox in the tenant. Constrain it with an [application access policy](https://learn.microsoft.com/en-us/graph/auth-limit-mailbox-access) so the app can only send as the mailbox you intend:

```powershell
New-ApplicationAccessPolicy -AppId <client-id> -PolicyScopeGroupId <mail-enabled-group> -AccessRight RestrictAccess
```

Not required to function — required to be honest about blast radius if the secret leaks.

## Requirements

| | |
|---|---|
| WordPress | 6.0+ (uses the `pre_wp_mail` filter) |
| PHP | 7.4+ |
| Microsoft | A Microsoft 365 tenant, and admin rights in it once, for consent |

## Licence

Copyright (C) 2026 Remy Mazmanian.

GPL-2.0-or-later. Full text in [`LICENSE`](LICENSE); copyright notice in [`NOTICE`](NOTICE).
