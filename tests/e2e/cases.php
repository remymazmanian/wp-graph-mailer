<?php
/**
 * Mail cases, run inside WordPress with `wp eval-file`. The test double in
 * mu-plugins/graph-mock.php answers for Microsoft and records each request,
 * so these assert exactly what Sendelva sends.
 *
 * Copyright (C) 2026 Remy Mazmanian
 * GPL-2.0-or-later. See LICENSE.
 */

if ( ! defined( 'WP_CLI' ) ) {
	exit; // WP-CLI only.
}

$GLOBALS['e2e_failed'] = 0;
$GLOBALS['e2e_passed'] = 0;

function e2e_check( $label, $ok, $detail = '' ) {
	if ( $ok ) {
		++$GLOBALS['e2e_passed'];
		echo "PASS  $label\n";
	} else {
		++$GLOBALS['e2e_failed'];
		echo "FAIL  $label" . ( '' !== $detail ? "\n      $detail" : '' ) . "\n";
	}
}

function e2e_section( $title ) {
	echo "\n== $title\n";
}

/** Clears recorded requests, mode, log and caches between cases. */
function e2e_reset( $mode = array() ) {
	update_option( 'e2e_requests', array(), false );
	update_option( 'e2e_mode', $mode, false );
	update_option( 'e2e_default_mailer', 0, false );
	delete_option( 'sendelva_log' );
	delete_transient( 'sendelva_token' );
	delete_transient( 'sendelva_token_failed' );
	wp_cache_flush();
}

function e2e_configure( $overrides = array() ) {
	update_option(
		'sendelva_settings',
		array_merge(
			array(
				'tenant_id'     => '00000000-1111-2222-3333-444444444444',
				'client_id'     => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee',
				'client_secret' => 'test-secret',
				'sender'        => 'noreply@example.com',
				'fallback'      => 1,
			),
			$overrides
		)
	);
}

function e2e_requests( $host = null ) {
	wp_cache_delete( 'e2e_requests', 'options' );
	$all = (array) get_option( 'e2e_requests', array() );
	return array_values( null === $host ? $all : array_filter( $all, static function ( $r ) use ( $host ) { return $r['host'] === $host; } ) );
}

function e2e_sends() {
	return e2e_requests( 'graph.microsoft.com' );
}

function e2e_last_message() {
	$sends = e2e_sends();
	return $sends ? ( end( $sends )['body']['message'] ?? array() ) : array();
}

function e2e_addresses( $list ) {
	return array_map( static function ( $r ) { return $r['emailAddress']['address']; }, (array) $list );
}

function e2e_default_mailer_calls() {
	wp_cache_delete( 'e2e_default_mailer', 'options' );
	return (int) get_option( 'e2e_default_mailer', 0 );
}

function e2e_log() {
	wp_cache_delete( 'sendelva_log', 'options' );
	return (array) get_option( 'sendelva_log', array() );
}

/* ------------------------------------------------------------------ */
e2e_section( '1. Unconfigured' );

delete_option( 'sendelva_settings' );
e2e_reset();
wp_mail( 'a@example.com', 'Hello', 'Body' );
e2e_check( 'no request goes to Microsoft', ! e2e_requests() );
e2e_check( 'the default mailer handles the message', 1 === e2e_default_mailer_calls() );

/* ------------------------------------------------------------------ */
e2e_section( '2. Plain send and token caching' );

e2e_configure();
e2e_reset();
$ok  = wp_mail( 'a@example.com', 'Hello', 'Plain body' );
$msg = e2e_last_message();
e2e_check( 'wp_mail returns true', true === $ok );
e2e_check( 'one token request, then one sendMail', 1 === count( e2e_requests( 'login.microsoftonline.com' ) ) && 1 === count( e2e_sends() ) );
e2e_check( 'sendMail goes to the sender mailbox', '/v1.0/users/noreply%40example.com/sendMail' === ( e2e_sends()[0]['path'] ?? '' ), e2e_sends()[0]['path'] ?? 'none' );
e2e_check( 'the token is sent as a Bearer header', 0 === strpos( (string) ( e2e_sends()[0]['auth'] ?? '' ), 'Bearer test-token-' ) );
e2e_check( 'plain text body', 'Text' === ( $msg['body']['contentType'] ?? '' ) && 'Plain body' === ( $msg['body']['content'] ?? '' ) );
e2e_check( 'the default mailer is not used', 0 === e2e_default_mailer_calls() );

wp_mail( 'b@example.com', 'Again', 'Body' );
e2e_check( 'a second send reuses the cached token', 1 === count( e2e_requests( 'login.microsoftonline.com' ) ) && 2 === count( e2e_sends() ) );

e2e_configure( array( 'client_secret' => 'rotated-secret' ) );
wp_mail( 'c@example.com', 'After rotation', 'Body' );
e2e_check( 'changed credentials get a new token instead of the cached one', 2 === count( e2e_requests( 'login.microsoftonline.com' ) ) );
e2e_configure();

/* ------------------------------------------------------------------ */
e2e_section( '3. HTML' );

e2e_reset();
$html_filter = static function () { return 'text/html'; };
add_filter( 'wp_mail_content_type', $html_filter );
wp_mail( 'a@example.com', 'HTML by filter', '<p>Hi</p>' );
remove_filter( 'wp_mail_content_type', $html_filter );
e2e_check( 'HTML set with the wp_mail_content_type filter is sent as HTML', 'HTML' === ( e2e_last_message()['body']['contentType'] ?? '' ) );

wp_mail( 'a@example.com', 'HTML by header', '<p>Hi</p>', array( 'Content-Type: text/html; charset=UTF-8' ) );
e2e_check( 'HTML set with a Content-Type header is sent as HTML', 'HTML' === ( e2e_last_message()['body']['contentType'] ?? '' ) );

/* ------------------------------------------------------------------ */
e2e_section( '4. Recipients' );

e2e_reset();
wp_mail(
	'"Doe, Jane" <jane@example.com>, bob@example.com',
	'Recipients',
	'Body',
	array( 'Cc: carol@example.com, dave@example.com', 'Bcc: erin@example.com', 'Reply-To: Support Team <support@example.com>' )
);
$msg = e2e_last_message();
e2e_check( 'a quoted name with a comma stays one recipient', array( 'jane@example.com', 'bob@example.com' ) === e2e_addresses( $msg['toRecipients'] ?? array() ), wp_json_encode( $msg['toRecipients'] ?? null ) );
e2e_check( 'display names are kept', 'Doe, Jane' === ( $msg['toRecipients'][0]['emailAddress']['name'] ?? '' ) );
e2e_check( 'every address in one Cc header is kept', array( 'carol@example.com', 'dave@example.com' ) === e2e_addresses( $msg['ccRecipients'] ?? array() ), wp_json_encode( $msg['ccRecipients'] ?? null ) );
e2e_check( 'Bcc is kept', array( 'erin@example.com' ) === e2e_addresses( $msg['bccRecipients'] ?? array() ) );
e2e_check( 'Reply-To keeps its address and name', 'support@example.com' === ( $msg['replyTo'][0]['emailAddress']['address'] ?? '' ) && 'Support Team' === ( $msg['replyTo'][0]['emailAddress']['name'] ?? '' ) );

wp_mail( array( 'x@example.com', 'X@example.com', 'y@example.com' ), 'Duplicates', 'Body' );
e2e_check( 'duplicate addresses are sent once', array( 'x@example.com', 'y@example.com' ) === e2e_addresses( e2e_last_message()['toRecipients'] ?? array() ) );

e2e_reset();
wp_mail( 'not-an-address', 'Nobody', 'Body' );
e2e_check( 'no sendMail when there is no valid recipient', ! e2e_sends() );
e2e_check( 'the log names the problem and the fallback', false !== strpos( (string) ( e2e_log()[0]['error'] ?? '' ), 'No valid recipient' ) && false !== strpos( (string) ( e2e_log()[0]['error'] ?? '' ), 'default mailer' ), wp_json_encode( e2e_log()[0] ?? null ) );

/* ------------------------------------------------------------------ */
e2e_section( '5. Attachments' );

e2e_reset();
$file = wp_tempnam( 'e2e-attachment' );
file_put_contents( $file, 'attachment body' );
wp_mail( 'a@example.com', 'Files', 'Body', array(), array( 'invoice-1042.txt' => $file, $file ) );
$att = e2e_last_message()['attachments'] ?? array();
e2e_check( 'an attachment keyed by name uses that name', 'invoice-1042.txt' === ( $att[0]['name'] ?? '' ), wp_json_encode( $att ) );
e2e_check( 'an attachment without a key uses the file name', wp_basename( $file ) === ( $att[1]['name'] ?? '' ) );
e2e_check( 'contents arrive intact', 'attachment body' === base64_decode( (string) ( $att[0]['contentBytes'] ?? '' ) ) );
unlink( $file );

/* ------------------------------------------------------------------ */
e2e_section( '6. Hooks' );

e2e_reset();
$succeeded = 0;
$counter   = static function () use ( &$succeeded ) { ++$succeeded; };
add_action( 'wp_mail_succeeded', $counter );
wp_mail( 'a@example.com', 'Hook', 'Body' );
remove_action( 'wp_mail_succeeded', $counter );
e2e_check( 'wp_mail_succeeded fires after a Graph send', 1 === $succeeded );

/* ------------------------------------------------------------------ */
e2e_section( '7. Failures' );

e2e_configure( array( 'fallback' => 0 ) );
e2e_reset( array( 'send' => 'fail' ) );
$failed = 0;
$fail_counter = static function () use ( &$failed ) { ++$failed; };
add_action( 'wp_mail_failed', $fail_counter );
$ok = wp_mail( 'a@example.com', 'Will fail', 'Body' );
remove_action( 'wp_mail_failed', $fail_counter );
e2e_check( 'with fallback off, a Graph error makes wp_mail return false', false === $ok );
e2e_check( 'with fallback off, wp_mail_failed fires', 1 === $failed );
e2e_check( 'with fallback off, the default mailer is not used', 0 === e2e_default_mailer_calls() );
e2e_check( 'the log has the Graph error', false !== strpos( (string) ( e2e_log()[0]['error'] ?? '' ), 'Access is denied' ) );

e2e_configure( array( 'fallback' => 1 ) );
e2e_reset( array( 'send' => 'fail' ) );
wp_mail( 'a@example.com', 'Will fall back', 'Body' );
e2e_check( 'with fallback on, the default mailer takes the message', 1 === e2e_default_mailer_calls() );
e2e_check( 'the log says it was handed to the default mailer', false !== strpos( (string) ( e2e_log()[0]['error'] ?? '' ), 'Handed to the default mailer' ) );

e2e_reset( array( 'token' => 'refuse' ) );
wp_mail( 'a@example.com', 'Token refused', 'Body' );
wp_mail( 'b@example.com', 'Token refused again', 'Body' );
e2e_check( 'a refused token is not requested again for every email', 1 === count( e2e_requests( 'login.microsoftonline.com' ) ), count( e2e_requests( 'login.microsoftonline.com' ) ) . ' token requests' );
e2e_check( 'the refusal reason reaches the log', false !== strpos( (string) ( e2e_log()[0]['error'] ?? '' ), 'Invalid client secret' ) );
e2e_configure( array( 'client_secret' => 'fixed-secret' ) );
update_option( 'e2e_mode', array(), false );
wp_mail( 'c@example.com', 'After fixing the secret', 'Body' );
e2e_check( 'new credentials try again straight away', 2 === count( e2e_requests( 'login.microsoftonline.com' ) ) && 1 === count( e2e_sends() ) );

/* ------------------------------------------------------------------ */
e2e_section( '8. Moving over from Graph Mailer' );

$legacy_settings = array(
	'tenant_id'     => 'legacy-tenant',
	'client_id'     => 'legacy-client',
	'client_secret' => 'legacy-secret',
	'sender'        => 'legacy@example.com',
	'fallback'      => 0,
);
$legacy_log = array( array( 'time' => 1700000000, 'to' => 'old@example.com', 'subject' => 'Sent by Graph Mailer', 'ok' => true, 'error' => '' ) );
delete_option( 'sendelva_settings' );
e2e_reset();
update_option( 'graph_mailer_settings', $legacy_settings );
update_option( 'graph_mailer_log', $legacy_log, false );
Sendelva::migrate_legacy_settings();
wp_cache_delete( 'sendelva_settings', 'options' );
e2e_check( 'Graph Mailer\'s settings are copied', $legacy_settings === get_option( 'sendelva_settings' ), wp_json_encode( get_option( 'sendelva_settings' ) ) );
e2e_check( 'its send log is copied', $legacy_log === e2e_log() );
e2e_check( 'Graph Mailer\'s own options are left in place', $legacy_settings === get_option( 'graph_mailer_settings' ) && $legacy_log === get_option( 'graph_mailer_log' ) );

e2e_configure();
Sendelva::migrate_legacy_settings();
e2e_check( 'settings already saved in Sendelva are never overwritten', 'test-secret' === ( get_option( 'sendelva_settings' )['client_secret'] ?? '' ) );
delete_option( 'graph_mailer_settings' );
delete_option( 'graph_mailer_log' );

/* ------------------------------------------------------------------ */
// Constants can't be undefined, so this section runs last.
e2e_section( '9. wp-config.php constants' );

delete_option( 'sendelva_settings' );
e2e_reset();
define( 'GRAPH_MAILER_TENANT_ID', 'legacy-tenant' );
define( 'GRAPH_MAILER_CLIENT_ID', 'legacy-client' );
define( 'GRAPH_MAILER_CLIENT_SECRET', 'legacy-secret' );
define( 'GRAPH_MAILER_SENDER', 'legacy@example.com' );
wp_mail( 'a@example.com', 'Old constants', 'Body' );
e2e_check( 'the old GRAPH_MAILER_* constants still configure the plugin', '/v1.0/users/legacy%40example.com/sendMail' === ( e2e_sends()[0]['path'] ?? '' ), wp_json_encode( e2e_requests() ) );
e2e_check( 'and are the credentials sent to Microsoft', '/legacy-tenant/oauth2/v2.0/token' === ( e2e_requests( 'login.microsoftonline.com' )[0]['path'] ?? '' ) && 'legacy-secret' === ( e2e_requests( 'login.microsoftonline.com' )[0]['body']['client_secret'] ?? '' ) );

define( 'SENDELVA_TENANT_ID', 'new-tenant' );
define( 'SENDELVA_SENDER', 'new@example.com' );
e2e_reset();
wp_mail( 'a@example.com', 'New constants', 'Body' );
e2e_check( 'a SENDELVA_* constant wins over the old name', '/v1.0/users/new%40example.com/sendMail' === ( e2e_sends()[0]['path'] ?? '' ) && '/new-tenant/oauth2/v2.0/token' === ( e2e_requests( 'login.microsoftonline.com' )[0]['path'] ?? '' ) );
e2e_check( 'each value is marked as coming from wp-config.php', ! empty( Sendelva::settings()['tenant_id_const'] ) && ! empty( Sendelva::settings()['client_secret_const'] ) );

/* ------------------------------------------------------------------ */
e2e_configure();
e2e_reset();
echo "\n{$GLOBALS['e2e_passed']} passed, {$GLOBALS['e2e_failed']} failed\n";
exit( $GLOBALS['e2e_failed'] ? 1 : 0 );
