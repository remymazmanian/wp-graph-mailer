<?php
/**
 * Mail cases, run inside WordPress with `wp eval-file`. The test double in
 * mu-plugins/graph-mock.php answers for Microsoft and records each request,
 * so these assert exactly what Graph Mailer sends.
 *
 * Copyright (C) 2026 Remy Mazmanian
 * GPL-2.0-or-later. See LICENSE.
 */

if ( ! defined( 'WP_CLI' ) ) {
	exit; // WP-CLI only.
}

$GLOBALS['gm_failed'] = 0;
$GLOBALS['gm_passed'] = 0;

function gm_check( $label, $ok, $detail = '' ) {
	if ( $ok ) {
		++$GLOBALS['gm_passed'];
		echo "PASS  $label\n";
	} else {
		++$GLOBALS['gm_failed'];
		echo "FAIL  $label" . ( '' !== $detail ? "\n      $detail" : '' ) . "\n";
	}
}

function gm_section( $title ) {
	echo "\n== $title\n";
}

/** Clears recorded requests, mode, log and caches between cases. */
function gm_reset( $mode = array() ) {
	update_option( 'gm_test_requests', array(), false );
	update_option( 'gm_test_mode', $mode, false );
	update_option( 'gm_test_default_mailer', 0, false );
	delete_option( 'graph_mailer_log' );
	delete_transient( 'graph_mailer_token' );
	delete_transient( 'graph_mailer_token_failed' );
	wp_cache_flush();
}

function gm_configure( $overrides = array() ) {
	update_option(
		'graph_mailer_settings',
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

function gm_requests( $host = null ) {
	wp_cache_delete( 'gm_test_requests', 'options' );
	$all = (array) get_option( 'gm_test_requests', array() );
	return array_values( null === $host ? $all : array_filter( $all, static function ( $r ) use ( $host ) { return $r['host'] === $host; } ) );
}

function gm_sends() {
	return gm_requests( 'graph.microsoft.com' );
}

function gm_last_message() {
	$sends = gm_sends();
	return $sends ? ( end( $sends )['body']['message'] ?? array() ) : array();
}

function gm_addresses( $list ) {
	return array_map( static function ( $r ) { return $r['emailAddress']['address']; }, (array) $list );
}

function gm_default_mailer_calls() {
	wp_cache_delete( 'gm_test_default_mailer', 'options' );
	return (int) get_option( 'gm_test_default_mailer', 0 );
}

function gm_log() {
	wp_cache_delete( 'graph_mailer_log', 'options' );
	return (array) get_option( 'graph_mailer_log', array() );
}

/* ------------------------------------------------------------------ */
gm_section( '1. Unconfigured' );

delete_option( 'graph_mailer_settings' );
gm_reset();
wp_mail( 'a@example.com', 'Hello', 'Body' );
gm_check( 'no request goes to Microsoft', ! gm_requests() );
gm_check( 'the default mailer handles the message', 1 === gm_default_mailer_calls() );

/* ------------------------------------------------------------------ */
gm_section( '2. Plain send and token caching' );

gm_configure();
gm_reset();
$ok  = wp_mail( 'a@example.com', 'Hello', 'Plain body' );
$msg = gm_last_message();
gm_check( 'wp_mail returns true', true === $ok );
gm_check( 'one token request, then one sendMail', 1 === count( gm_requests( 'login.microsoftonline.com' ) ) && 1 === count( gm_sends() ) );
gm_check( 'sendMail goes to the sender mailbox', '/v1.0/users/noreply%40example.com/sendMail' === ( gm_sends()[0]['path'] ?? '' ), gm_sends()[0]['path'] ?? 'none' );
gm_check( 'the token is sent as a Bearer header', 0 === strpos( (string) ( gm_sends()[0]['auth'] ?? '' ), 'Bearer test-token-' ) );
gm_check( 'plain text body', 'Text' === ( $msg['body']['contentType'] ?? '' ) && 'Plain body' === ( $msg['body']['content'] ?? '' ) );
gm_check( 'the default mailer is not used', 0 === gm_default_mailer_calls() );

wp_mail( 'b@example.com', 'Again', 'Body' );
gm_check( 'a second send reuses the cached token', 1 === count( gm_requests( 'login.microsoftonline.com' ) ) && 2 === count( gm_sends() ) );

gm_configure( array( 'client_secret' => 'rotated-secret' ) );
wp_mail( 'c@example.com', 'After rotation', 'Body' );
gm_check( 'changed credentials get a new token instead of the cached one', 2 === count( gm_requests( 'login.microsoftonline.com' ) ) );
gm_configure();

/* ------------------------------------------------------------------ */
gm_section( '3. HTML' );

gm_reset();
$html_filter = static function () { return 'text/html'; };
add_filter( 'wp_mail_content_type', $html_filter );
wp_mail( 'a@example.com', 'HTML by filter', '<p>Hi</p>' );
remove_filter( 'wp_mail_content_type', $html_filter );
gm_check( 'HTML set with the wp_mail_content_type filter is sent as HTML', 'HTML' === ( gm_last_message()['body']['contentType'] ?? '' ) );

wp_mail( 'a@example.com', 'HTML by header', '<p>Hi</p>', array( 'Content-Type: text/html; charset=UTF-8' ) );
gm_check( 'HTML set with a Content-Type header is sent as HTML', 'HTML' === ( gm_last_message()['body']['contentType'] ?? '' ) );

/* ------------------------------------------------------------------ */
gm_section( '4. Recipients' );

gm_reset();
wp_mail(
	'"Doe, Jane" <jane@example.com>, bob@example.com',
	'Recipients',
	'Body',
	array( 'Cc: carol@example.com, dave@example.com', 'Bcc: erin@example.com', 'Reply-To: Support Team <support@example.com>' )
);
$msg = gm_last_message();
gm_check( 'a quoted name with a comma stays one recipient', array( 'jane@example.com', 'bob@example.com' ) === gm_addresses( $msg['toRecipients'] ?? array() ), wp_json_encode( $msg['toRecipients'] ?? null ) );
gm_check( 'display names are kept', 'Doe, Jane' === ( $msg['toRecipients'][0]['emailAddress']['name'] ?? '' ) );
gm_check( 'every address in one Cc header is kept', array( 'carol@example.com', 'dave@example.com' ) === gm_addresses( $msg['ccRecipients'] ?? array() ), wp_json_encode( $msg['ccRecipients'] ?? null ) );
gm_check( 'Bcc is kept', array( 'erin@example.com' ) === gm_addresses( $msg['bccRecipients'] ?? array() ) );
gm_check( 'Reply-To keeps its address and name', 'support@example.com' === ( $msg['replyTo'][0]['emailAddress']['address'] ?? '' ) && 'Support Team' === ( $msg['replyTo'][0]['emailAddress']['name'] ?? '' ) );

wp_mail( array( 'x@example.com', 'X@example.com', 'y@example.com' ), 'Duplicates', 'Body' );
gm_check( 'duplicate addresses are sent once', array( 'x@example.com', 'y@example.com' ) === gm_addresses( gm_last_message()['toRecipients'] ?? array() ) );

gm_reset();
wp_mail( 'not-an-address', 'Nobody', 'Body' );
gm_check( 'no sendMail when there is no valid recipient', ! gm_sends() );
gm_check( 'the log names the problem and the fallback', false !== strpos( (string) ( gm_log()[0]['error'] ?? '' ), 'No valid recipient' ) && false !== strpos( (string) ( gm_log()[0]['error'] ?? '' ), 'default mailer' ), wp_json_encode( gm_log()[0] ?? null ) );

/* ------------------------------------------------------------------ */
gm_section( '5. Attachments' );

gm_reset();
$file = wp_tempnam( 'gm-attachment' );
file_put_contents( $file, 'attachment body' );
wp_mail( 'a@example.com', 'Files', 'Body', array(), array( 'invoice-1042.txt' => $file, $file ) );
$att = gm_last_message()['attachments'] ?? array();
gm_check( 'an attachment keyed by name uses that name', 'invoice-1042.txt' === ( $att[0]['name'] ?? '' ), wp_json_encode( $att ) );
gm_check( 'an attachment without a key uses the file name', wp_basename( $file ) === ( $att[1]['name'] ?? '' ) );
gm_check( 'contents arrive intact', 'attachment body' === base64_decode( (string) ( $att[0]['contentBytes'] ?? '' ) ) );
unlink( $file );

/* ------------------------------------------------------------------ */
gm_section( '6. Hooks' );

gm_reset();
$succeeded = 0;
$counter   = static function () use ( &$succeeded ) { ++$succeeded; };
add_action( 'wp_mail_succeeded', $counter );
wp_mail( 'a@example.com', 'Hook', 'Body' );
remove_action( 'wp_mail_succeeded', $counter );
gm_check( 'wp_mail_succeeded fires after a Graph send', 1 === $succeeded );

/* ------------------------------------------------------------------ */
gm_section( '7. Failures' );

gm_configure( array( 'fallback' => 0 ) );
gm_reset( array( 'send' => 'fail' ) );
$failed = 0;
$fail_counter = static function () use ( &$failed ) { ++$failed; };
add_action( 'wp_mail_failed', $fail_counter );
$ok = wp_mail( 'a@example.com', 'Will fail', 'Body' );
remove_action( 'wp_mail_failed', $fail_counter );
gm_check( 'with fallback off, a Graph error makes wp_mail return false', false === $ok );
gm_check( 'with fallback off, wp_mail_failed fires', 1 === $failed );
gm_check( 'with fallback off, the default mailer is not used', 0 === gm_default_mailer_calls() );
gm_check( 'the log has the Graph error', false !== strpos( (string) ( gm_log()[0]['error'] ?? '' ), 'Access is denied' ) );

gm_configure( array( 'fallback' => 1 ) );
gm_reset( array( 'send' => 'fail' ) );
wp_mail( 'a@example.com', 'Will fall back', 'Body' );
gm_check( 'with fallback on, the default mailer takes the message', 1 === gm_default_mailer_calls() );
gm_check( 'the log says it was handed to the default mailer', false !== strpos( (string) ( gm_log()[0]['error'] ?? '' ), 'Handed to the default mailer' ) );

gm_reset( array( 'token' => 'refuse' ) );
wp_mail( 'a@example.com', 'Token refused', 'Body' );
wp_mail( 'b@example.com', 'Token refused again', 'Body' );
gm_check( 'a refused token is not requested again for every email', 1 === count( gm_requests( 'login.microsoftonline.com' ) ), count( gm_requests( 'login.microsoftonline.com' ) ) . ' token requests' );
gm_check( 'the refusal reason reaches the log', false !== strpos( (string) ( gm_log()[0]['error'] ?? '' ), 'Invalid client secret' ) );
gm_configure( array( 'client_secret' => 'fixed-secret' ) );
update_option( 'gm_test_mode', array(), false );
wp_mail( 'c@example.com', 'After fixing the secret', 'Body' );
gm_check( 'new credentials try again straight away', 2 === count( gm_requests( 'login.microsoftonline.com' ) ) && 1 === count( gm_sends() ) );

/* ------------------------------------------------------------------ */
gm_configure();
gm_reset();
echo "\n{$GLOBALS['gm_passed']} passed, {$GLOBALS['gm_failed']} failed\n";
exit( $GLOBALS['gm_failed'] ? 1 : 0 );
