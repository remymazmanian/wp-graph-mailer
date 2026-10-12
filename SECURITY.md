# Security policy

## Reporting a vulnerability

Please report security problems privately through GitHub: open the [Security tab](https://github.com/remymazmanian/wp-sendelva/security) and choose **Report a vulnerability**. Don't open a public issue for a vulnerability.

Include the plugin, WordPress and PHP versions, what you found and, if you can, steps that reproduce it. You'll get a reply within a few days, and fixes are released as soon as they're ready.

## Supported versions

Security fixes go into the latest release.

## Scope

Especially welcome:

- anything that exposes the client secret or the access token, on the settings screen, in the send log, in error messages or elsewhere
- ways for a user without `manage_options` to change the settings or trigger a test send
- ways to make the plugin send mail somewhere other than where `wp_mail()` was asked to send it

Problems in Microsoft Graph or Microsoft Entra themselves should go to Microsoft.
