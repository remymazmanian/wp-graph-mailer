<?php
/**
 * Test double for Microsoft's token endpoint and Graph sendMail. Records every
 * request in the gm_test_requests option and answers according to
 * gm_test_mode: token=ok|refuse, send=ok|fail. Mounted by the e2e suite only.
 */

add_filter(
	'pre_http_request',
	static function ( $pre, $args, $url ) {
		$host = (string) wp_parse_url( $url, PHP_URL_HOST );
		if ( ! in_array( $host, array( 'login.microsoftonline.com', 'graph.microsoft.com' ), true ) ) {
			return $pre;
		}

		$mode = wp_parse_args( (array) get_option( 'gm_test_mode', array() ), array( 'token' => 'ok', 'send' => 'ok' ) );
		$log  = (array) get_option( 'gm_test_requests', array() );
		$body = $args['body'] ?? '';

		$log[] = array(
			'host' => $host,
			'path' => (string) wp_parse_url( $url, PHP_URL_PATH ),
			'auth' => isset( $args['headers']['Authorization'] ) ? $args['headers']['Authorization'] : '',
			'body' => is_string( $body ) ? json_decode( $body, true ) : $body,
		);
		update_option( 'gm_test_requests', $log, false );

		if ( 'login.microsoftonline.com' === $host ) {
			if ( 'refuse' === $mode['token'] ) {
				return array( 'headers' => array(), 'body' => wp_json_encode( array( 'error' => 'invalid_client', 'error_description' => 'AADSTS7000215: Invalid client secret provided.' ) ), 'response' => array( 'code' => 401, 'message' => 'Unauthorized' ), 'cookies' => array(), 'filename' => null );
			}
			return array( 'headers' => array(), 'body' => wp_json_encode( array( 'token_type' => 'Bearer', 'expires_in' => 3599, 'access_token' => 'test-token-' . count( $log ) ) ), 'response' => array( 'code' => 200, 'message' => 'OK' ), 'cookies' => array(), 'filename' => null );
		}

		if ( 'fail' === $mode['send'] ) {
			return array( 'headers' => array(), 'body' => wp_json_encode( array( 'error' => array( 'code' => 'ErrorAccessDenied', 'message' => 'Access is denied. Check credentials and try again.' ) ) ), 'response' => array( 'code' => 403, 'message' => 'Forbidden' ), 'cookies' => array(), 'filename' => null );
		}
		return array( 'headers' => array(), 'body' => '', 'response' => array( 'code' => 202, 'message' => 'Accepted' ), 'cookies' => array(), 'filename' => null );
	},
	10,
	3
);

// Record when a message is left for WordPress's own mailer, and stop it there
// so nothing is really sent. Runs after Graph Mailer's own pre_wp_mail filter.
add_filter(
	'pre_wp_mail',
	static function ( $short_circuit ) {
		if ( null !== $short_circuit ) {
			return $short_circuit;
		}
		update_option( 'gm_test_default_mailer', (int) get_option( 'gm_test_default_mailer', 0 ) + 1, false );
		return false;
	},
	99
);
