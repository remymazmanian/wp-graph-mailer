<?php
/**
 * Plugin Name:       Graph Mailer
 * Plugin URI:        https://remymazmanian.com/graph-mailer/
 * Description:       Sends WordPress email through Microsoft Graph instead of SMTP. App-only OAuth against Microsoft Entra, no SMTP credentials on the server, and the default mailer as a fallback.
 * Version:           0.3.0
 * Requires at least: 6.0
 * Tested up to:      7.1
 * Requires PHP:      7.4
 * Author:            Remy Mazmanian
 * Author URI:        https://remymazmanian.com/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       graph-mailer
 */

/**
 * Copyright (C) 2026 Remy Mazmanian
 *
 * This program is free software; you can redistribute it and/or modify it
 * under the terms of the GNU General Public License as published by the
 * Free Software Foundation; either version 2 of the License, or (at your
 * option) any later version.
 *
 * This program is distributed in the hope that it will be useful, but
 * WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the GNU
 * General Public License for more details.
 */

defined( 'ABSPATH' ) || exit;

define( 'GRAPH_MAILER_VERSION', '0.3.0' );

final class Graph_Mailer {

	const OPTION    = 'graph_mailer_settings';
	const LOG       = 'graph_mailer_log';
	const TOKEN_KEY = 'graph_mailer_token';
	const LOG_MAX   = 20;

	/** Seconds to wait before asking Microsoft for a token again after a refusal. */
	const TOKEN_BACKOFF = 60;

	private static $instance;

	public static function instance() {
		return self::$instance ?: self::$instance = new self();
	}

	private function __construct() {
		add_filter( 'pre_wp_mail', array( $this, 'intercept' ), 10, 2 );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_menu', array( $this, 'add_settings_page' ) );
		add_action( 'admin_post_graph_mailer_test', array( $this, 'handle_test_send' ) );
		add_action( 'admin_init', array( $this, 'privacy_policy' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), array( $this, 'action_links' ) );
	}

	/* ---------------------------------------------------------- settings */

	public static function defaults() {
		return array(
			'tenant_id'     => '',
			'client_id'     => '',
			'client_secret' => '',
			'sender'        => '',
			'fallback'      => 1,
		);
	}

	/**
	 * Constants beat stored options, so secrets can live in wp-config.php:
	 * GRAPH_MAILER_TENANT_ID, GRAPH_MAILER_CLIENT_ID,
	 * GRAPH_MAILER_CLIENT_SECRET, GRAPH_MAILER_SENDER.
	 */
	public static function settings() {
		$s = wp_parse_args( (array) get_option( self::OPTION, array() ), self::defaults() );
		foreach ( array( 'tenant_id', 'client_id', 'client_secret', 'sender' ) as $key ) {
			$const = 'GRAPH_MAILER_' . strtoupper( $key );
			if ( defined( $const ) && constant( $const ) ) {
				$s[ $key ]              = constant( $const );
				$s[ $key . '_const' ] = true;
			}
		}
		return $s;
	}

	public static function configured() {
		$s = self::settings();
		return $s['tenant_id'] && $s['client_id'] && $s['client_secret'] && is_email( $s['sender'] );
	}

	public function register_settings() {
		register_setting( 'graph_mailer', self::OPTION, array(
			'type'              => 'array',
			'sanitize_callback' => function ( $in ) {
				$in  = is_array( $in ) ? $in : array();
				$old = (array) get_option( self::OPTION, array() );
				$out = array();
				$out['tenant_id'] = sanitize_text_field( $in['tenant_id'] ?? '' );
				$out['client_id'] = sanitize_text_field( $in['client_id'] ?? '' );
				// Write-only: an empty submit keeps the stored secret.
				$posted               = sanitize_text_field( (string) ( $in['client_secret'] ?? '' ) );
				$out['client_secret'] = '' !== $posted ? $posted : ( $old['client_secret'] ?? '' );
				$out['sender']   = sanitize_email( $in['sender'] ?? '' );
				$out['fallback'] = empty( $in['fallback'] ) ? 0 : 1;
				delete_transient( self::TOKEN_KEY );
				delete_transient( self::TOKEN_KEY . '_failed' );
				return $out;
			},
		) );
	}

	/* ---------------------------------------------------------- graph */

	/**
	 * Identifies the credentials a cached token belongs to, so a token for
	 * old credentials (say, after wp-config.php changes) is never reused.
	 */
	private static function credentials_hash() {
		$s = self::settings();
		return md5( $s['tenant_id'] . '|' . $s['client_id'] . '|' . $s['client_secret'] );
	}

	/**
	 * The cached token for the current credentials, or ''.
	 */
	public static function cached_token() {
		$cached = get_transient( self::TOKEN_KEY );
		if ( is_array( $cached ) && ! empty( $cached['token'] ) && hash_equals( (string) ( $cached['for'] ?? '' ), self::credentials_hash() ) ) {
			return (string) $cached['token'];
		}
		return '';
	}

	private function token() {
		$cached = self::cached_token();
		if ( '' !== $cached ) {
			return $cached;
		}

		// After a refusal, don't make every email wait on another token request
		// that will fail the same way. Saving the settings clears this.
		$recent = get_transient( self::TOKEN_KEY . '_failed' );
		if ( is_array( $recent ) && hash_equals( (string) ( $recent['for'] ?? '' ), self::credentials_hash() ) ) {
			return new WP_Error( 'graph_mailer_token', (string) $recent['error'] );
		}

		$s        = self::settings();
		$response = wp_remote_post(
			'https://login.microsoftonline.com/' . rawurlencode( $s['tenant_id'] ) . '/oauth2/v2.0/token',
			array(
				'timeout' => 15,
				'body'    => array(
					'grant_type'    => 'client_credentials',
					'client_id'     => $s['client_id'],
					'client_secret' => $s['client_secret'],
					'scope'         => 'https://graph.microsoft.com/.default',
				),
			)
		);
		if ( is_wp_error( $response ) ) {
			/* translators: %s: error from the HTTP request. */
			return $this->token_failed( sprintf( __( 'Token request failed: %s', 'graph-mailer' ), $response->get_error_message() ) );
		}
		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( empty( $body['access_token'] ) ) {
			$detail = $body['error_description'] ?? wp_remote_retrieve_response_message( $response );
			/* translators: %s: Microsoft's reason for refusing the token. */
			return $this->token_failed( sprintf( __( 'Token request refused: %s', 'graph-mailer' ), substr( (string) $detail, 0, 200 ) ) );
		}
		$ttl = max( 60, (int) ( $body['expires_in'] ?? 3600 ) - 120 );
		set_transient( self::TOKEN_KEY, array( 'token' => $body['access_token'], 'for' => self::credentials_hash() ), $ttl );
		delete_transient( self::TOKEN_KEY . '_failed' );
		return $body['access_token'];
	}

	private function token_failed( $message ) {
		set_transient( self::TOKEN_KEY . '_failed', array( 'error' => $message, 'for' => self::credentials_hash() ), self::TOKEN_BACKOFF );
		return new WP_Error( 'graph_mailer_token', $message );
	}

	/**
	 * Short-circuits wp_mail() when configured. Returning null lets
	 * WordPress's own mailer run instead.
	 */
	public function intercept( $short_circuit, $atts ) {
		if ( null !== $short_circuit || ! self::configured() ) {
			return $short_circuit;
		}

		$result = $this->send( $atts );

		if ( true === $result ) {
			return true;
		}

		$fallback = (bool) self::settings()['fallback'];
		$this->log( $atts, false, $result->get_error_message() . ( $fallback ? ' ' . __( 'Handed to the default mailer.', 'graph-mailer' ) : '' ) );
		if ( $fallback ) {
			return null; // hand the message to the default mailer
		}
		// Core's own hook, fired as WordPress's mailer would, so error loggers see Graph failures.
		do_action( 'wp_mail_failed', new WP_Error( 'wp_mail_failed', $result->get_error_message(), $atts ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
		return false;
	}

	private function send( $atts ) {
		$to          = $atts['to'] ?? array();
		$subject     = (string) ( $atts['subject'] ?? '' );
		$message     = (string) ( $atts['message'] ?? '' );
		$headers     = $atts['headers'] ?? array();
		$attachments = $atts['attachments'] ?? array();

		$parsed = $this->parse_headers( $headers );

		// Core applies this filter inside its own mailer, which a short-circuit
		// skips, so apply it here. WooCommerce and most form plugins switch to
		// HTML this way rather than with a header.
		$content_type = (string) apply_filters( 'wp_mail_content_type', $parsed['content_type'] ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core's filter, applied as wp_mail() would.
		$html         = 0 === stripos( trim( $content_type ), 'text/html' );

		$payload = array(
			'message' => array(
				'subject'      => $subject,
				'body'         => array(
					'contentType' => $html ? 'HTML' : 'Text',
					'content'     => $message,
				),
				'toRecipients' => $this->recipients( $to ),
			),
			'saveToSentItems' => true,
		);
		if ( $parsed['cc'] ) {
			$payload['message']['ccRecipients'] = $this->recipients( $parsed['cc'] );
		}
		if ( $parsed['bcc'] ) {
			$payload['message']['bccRecipients'] = $this->recipients( $parsed['bcc'] );
		}
		if ( $parsed['reply_to'] ) {
			$payload['message']['replyTo'] = $this->recipients( $parsed['reply_to'] );
		}

		if ( ! $payload['message']['toRecipients'] && empty( $payload['message']['ccRecipients'] ) && empty( $payload['message']['bccRecipients'] ) ) {
			return new WP_Error( 'graph_mailer_recipients', __( 'No valid recipient address.', 'graph-mailer' ) );
		}

		$total = 0;
		foreach ( (array) $attachments as $name => $path ) {
			// Like core's mailer, skip a file that can't be read.
			if ( ! is_string( $path ) || ! is_readable( $path ) ) {
				continue;
			}
			$size = filesize( $path );
			if ( $total + $size > 3 * MB_IN_BYTES ) {
				return new WP_Error( 'graph_mailer_attachments', __( 'Attachments exceed the 3 MB sendMail limit.', 'graph-mailer' ) );
			}
			$total += $size;
			$payload['message']['attachments'][] = array(
				'@odata.type'  => '#microsoft.graph.fileAttachment',
				// WordPress 6.2+ lets the array key name the file.
				'name'         => is_string( $name ) && '' !== $name ? $name : wp_basename( $path ),
				// Graph's fileAttachment carries the file as base64 (contentBytes); the file is a local path wp_mail() was given.
				'contentBytes' => base64_encode( file_get_contents( $path ) ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode, WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			);
		}

		$json = wp_json_encode( $payload );
		if ( false === $json ) {
			return new WP_Error( 'graph_mailer_encoding', __( 'The message could not be encoded as UTF-8 JSON.', 'graph-mailer' ) );
		}

		$token = $this->token();
		if ( is_wp_error( $token ) ) {
			return $token;
		}

		$s        = self::settings();
		$response = wp_remote_post(
			'https://graph.microsoft.com/v1.0/users/' . rawurlencode( $s['sender'] ) . '/sendMail',
			array(
				'timeout' => 20,
				'headers' => array(
					'Authorization' => 'Bearer ' . $token,
					'Content-Type'  => 'application/json',
				),
				'body'    => $json,
			)
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$code = wp_remote_retrieve_response_code( $response );
		if ( 202 !== $code ) {
			$body = json_decode( wp_remote_retrieve_body( $response ), true );
			/* translators: %d: HTTP status code. */
			$msg  = $body['error']['message'] ?? sprintf( __( 'Microsoft Graph returned HTTP %d.', 'graph-mailer' ), $code );
			return new WP_Error( 'graph_mailer_send', substr( (string) $msg, 0, 200 ) );
		}

		$this->log( $atts, true, '' );

		// Core fires this after its own mailer succeeds; logging plugins rely on it.
		do_action( 'wp_mail_succeeded', $atts ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
		return true;
	}

	/**
	 * Turns an address list into Graph recipients. Takes an array or a string,
	 * and any item may itself hold several comma-separated addresses, such as
	 * a single "Cc: a@example.com, b@example.com" header. Display names are
	 * kept, and a comma inside a quoted name doesn't split the address.
	 */
	private function recipients( $list ) {
		$out  = array();
		$seen = array();
		foreach ( (array) $list as $chunk ) {
			foreach ( self::split_addresses( (string) $chunk ) as $item ) {
				$name = '';
				$addr = $item;
				if ( preg_match( '/^(.*)<([^>]+)>$/', $item, $m ) ) {
					$name = trim( trim( $m[1] ), '"' );
					$addr = trim( $m[2] );
				}
				$key = strtolower( $addr );
				if ( ! is_email( $addr ) || isset( $seen[ $key ] ) ) {
					continue;
				}
				$seen[ $key ] = true;
				$email        = array( 'address' => $addr );
				if ( '' !== $name ) {
					$email['name'] = $name;
				}
				$out[] = array( 'emailAddress' => $email );
			}
		}
		return $out;
	}

	/**
	 * Splits on commas that aren't inside double quotes or angle brackets.
	 *
	 * @return string[]
	 */
	public static function split_addresses( $list ) {
		$parts  = array();
		$buf    = '';
		$quoted = false;
		$angle  = false;
		$len    = strlen( $list );
		for ( $i = 0; $i < $len; $i++ ) {
			$c = $list[ $i ];
			if ( '"' === $c && ! $angle ) {
				$quoted = ! $quoted;
			} elseif ( '<' === $c && ! $quoted ) {
				$angle = true;
			} elseif ( '>' === $c && ! $quoted ) {
				$angle = false;
			} elseif ( ',' === $c && ! $quoted && ! $angle ) {
				$parts[] = $buf;
				$buf     = '';
				continue;
			}
			$buf .= $c;
		}
		$parts[] = $buf;
		return array_values( array_filter( array_map( 'trim', $parts ), 'strlen' ) );
	}

	private function parse_headers( $headers ) {
		$out = array( 'content_type' => 'text/plain', 'cc' => array(), 'bcc' => array(), 'reply_to' => array() );
		$lines = is_array( $headers ) ? $headers : preg_split( '/\r?\n/', (string) $headers );
		foreach ( $lines as $line ) {
			if ( false === strpos( $line, ':' ) ) {
				continue;
			}
			list( $name, $value ) = array_map( 'trim', explode( ':', $line, 2 ) );
			switch ( strtolower( $name ) ) {
				case 'content-type':
					$out['content_type'] = $value;
					break;
				case 'cc':
					$out['cc'][] = $value;
					break;
				case 'bcc':
					$out['bcc'][] = $value;
					break;
				case 'reply-to':
					$out['reply_to'][] = $value;
					break;
			}
		}
		return $out;
	}

	private function log( $atts, $ok, $error ) {
		$log   = (array) get_option( self::LOG, array() );
		$to    = $atts['to'] ?? '';
		$to    = is_array( $to ) ? implode( ', ', $to ) : $to;
		array_unshift( $log, array(
			'time'    => time(),
			'to'      => substr( (string) $to, 0, 120 ),
			'subject' => substr( (string) ( $atts['subject'] ?? '' ), 0, 120 ),
			'ok'      => (bool) $ok,
			'error'   => substr( (string) $error, 0, 200 ),
	) );
		update_option( self::LOG, array_slice( $log, 0, self::LOG_MAX ), false );
	}

	/* ---------------------------------------------------------- admin */

	public function add_settings_page() {
		$hook = add_options_page(
			__( 'Graph Mailer', 'graph-mailer' ),
			__( 'Graph Mailer', 'graph-mailer' ),
			'manage_options',
			'graph-mailer',
			array( $this, 'render_settings_page' )
		);
		add_action( 'load-' . $hook, array( $this, 'help_tab' ) );
		add_action( 'admin_print_styles-' . $hook, array( $this, 'enqueue_assets' ) );
	}

	/** The settings screen's stylesheet and the guide's show/hide script. */
	public function enqueue_assets() {
		wp_enqueue_style( 'graph-mailer-admin', plugins_url( 'assets/admin.css', __FILE__ ), array(), GRAPH_MAILER_VERSION );
		wp_enqueue_script( 'graph-mailer-admin', plugins_url( 'assets/admin.js', __FILE__ ), array(), GRAPH_MAILER_VERSION, true );
	}

	/** A "Settings" link next to Deactivate on the Plugins screen. */
	public function action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'options-general.php?page=graph-mailer' ) ) . '">' . esc_html__( 'Settings', 'graph-mailer' ) . '</a>' );
		return $links;
	}

	/** Suggested text for the site's privacy policy (Settings > Privacy). */
	public function privacy_policy() {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}
		$content = '<p>' . esc_html__( 'This site sends its email, such as password resets and form notifications, through Microsoft Graph, part of Microsoft 365. Each message, including its recipients, subject, body and any attachments, is sent to Microsoft to be delivered from our mailbox.', 'graph-mailer' ) . '</p>'
			. '<p>' . esc_html__( 'A log of the last 20 messages, with each recipient address, subject and delivery result, is kept on this site so administrators can check that email is working.', 'graph-mailer' ) . '</p>'
			. '<p>' . sprintf(
				/* translators: %s: link to the Microsoft Privacy Statement. */
				esc_html__( 'Microsoft\'s handling of this data is described in the %s.', 'graph-mailer' ),
				'<a href="https://privacy.microsoft.com/privacystatement">' . esc_html__( 'Microsoft Privacy Statement', 'graph-mailer' ) . '</a>'
			) . '</p>';
		wp_add_privacy_policy_content( __( 'Graph Mailer', 'graph-mailer' ), wp_kses_post( $content ) );
	}

	public function help_tab() {
		get_current_screen()->add_help_tab(
			array(
				'id'      => 'gm-help',
				'title'   => __( 'How it works', 'graph-mailer' ),
				'content' =>
					'<p>' . esc_html__( 'Graph Mailer short-circuits wp_mail() and delivers through Microsoft Graph with an app-only token. No SMTP server, no PHPMailer configuration, and the client secret can live in wp-config.php as a constant instead of the database.', 'graph-mailer' ) . '</p>' .
					'<p>' . esc_html__( 'Until all four credentials are present the plugin is inert and WordPress mail flows exactly as before. With fallback enabled, a Graph failure hands the message back to the default mailer instead of dropping it.', 'graph-mailer' ) . '</p>',
			)
		);
	}

	public function handle_test_send() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to send a test message.', 'graph-mailer' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'graph_mailer_test' );

		$to = isset( $_POST['test_to'] ) ? sanitize_email( wp_unslash( $_POST['test_to'] ) ) : '';
		if ( ! $to ) {
			$to = get_option( 'admin_email' );
		}
		$ok = wp_mail(
			$to,
			/* translators: %s: site name. */
			sprintf( __( 'Graph Mailer test — %s', 'graph-mailer' ), get_bloginfo( 'name' ) ),
			/* translators: 1: site address, 2: time the message was sent. */
			sprintf( __( "This message was sent through Microsoft Graph by the Graph Mailer plugin.\n\nSite: %1\$s\nTime: %2\$s", 'graph-mailer' ), home_url( '/' ), gmdate( 'c' ) )
		);

		// The result travels back in the URL with its own nonce, so nothing is stored.
		wp_safe_redirect(
			add_query_arg(
				array(
					'gm_test'  => $ok ? 'ok' : 'fail',
					'gm_nonce' => wp_create_nonce( 'graph_mailer_test_result' ),
				),
				admin_url( 'options-general.php?page=graph-mailer' )
			)
		);
		exit;
	}

	/** The result of the last test send, read from the redirect: 'ok', 'fail' or ''. */
	private function test_result() {
		if ( ! isset( $_GET['gm_test'], $_GET['gm_nonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_GET['gm_nonce'] ) ), 'graph_mailer_test_result' ) ) {
			return '';
		}
		$result = sanitize_key( wp_unslash( $_GET['gm_test'] ) );
		return in_array( $result, array( 'ok', 'fail' ), true ) ? $result : '';
	}

	/** One credential field. Values from wp-config.php constants are never shown. */
	private function render_field( $s, $key, $label, $type, $desc ) {
		$is_const = ! empty( $s[ $key . '_const' ] );
		$name     = self::OPTION . '[' . $key . ']';
		?>
		<div class="gm-field">
			<strong>
				<?php echo esc_html( $label ); ?>
				<?php if ( $is_const ) : ?>
					<span class="gm-const"><?php esc_html_e( 'wp-config', 'graph-mailer' ); ?></span>
				<?php endif; ?>
			</strong>
			<?php if ( 'password' === $type ) : ?>
				<input type="password" autocomplete="new-password" name="<?php echo esc_attr( $name ); ?>" value="" placeholder="<?php echo esc_attr( $s[ $key ] ? __( 'saved — leave blank to keep', 'graph-mailer' ) : '' ); ?>" <?php disabled( $is_const ); ?>>
			<?php else : ?>
				<input type="<?php echo esc_attr( $type ); ?>" name="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( $is_const ? '' : $s[ $key ] ); ?>" placeholder="<?php echo esc_attr( $is_const ? __( 'defined in wp-config.php', 'graph-mailer' ) : '' ); ?>" <?php disabled( $is_const ); ?>>
			<?php endif; ?>
			<p class="description"><?php echo esc_html( $desc ); ?></p>
		</div>
		<?php
	}

	/** A status value: green for good, amber for not yet, red for failed. */
	private function badge( $state, $text ) {
		$class = array(
			'ok'   => 'gm-ok',
			'warn' => 'gm-warn',
			'bad'  => 'gm-bad',
		);
		return '<span class="' . esc_attr( $class[ $state ] ) . '">' . esc_html( $text ) . '</span>';
	}

	public function render_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$s          = self::settings();
		$configured = self::configured();
		$log        = (array) get_option( self::LOG, array() );
		$last_ok    = $log && ! empty( $log[0]['ok'] );
		$test       = $this->test_result();
		$allowed    = array(
			'strong' => array(),
			'span'   => array( 'class' => array() ),
		);
		?>
		<div class="wrap gm-wrap">
			<div class="gm-head">
				<h1><?php esc_html_e( 'Graph Mailer', 'graph-mailer' ); ?></h1>
				<span class="gm-ver"><?php echo esc_html( GRAPH_MAILER_VERSION ); ?></span>
			</div>
			<p class="gm-sub"><?php esc_html_e( 'WordPress mail through Microsoft Graph. App-only OAuth against Microsoft Entra — no SMTP server, no mail credentials in PHPMailer. Inert until configured; with fallback on, a Graph failure hands the message to the default mailer instead of dropping it.', 'graph-mailer' ); ?></p>

			<?php if ( 'ok' === $test ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Test message accepted. Check the inbox — and the send log below.', 'graph-mailer' ); ?></p></div>
			<?php elseif ( 'fail' === $test ) : ?>
				<div class="notice notice-error is-dismissible"><p><?php esc_html_e( 'Test send failed. The send log below has the error.', 'graph-mailer' ); ?></p></div>
			<?php endif; ?>

			<div class="gm-card gm-guide" id="gm-guide">
				<div class="gm-guide-head">
					<h2><?php esc_html_e( 'Step-by-step guide', 'graph-mailer' ); ?></h2>
					<label class="gm-switch" title="<?php esc_attr_e( 'Show the walkthrough', 'graph-mailer' ); ?>">
						<input type="checkbox" id="gm-guide-toggle">
						<span class="gm-slider"></span>
					</label>
				</div>
				<div class="gm-guide-body">
					<div class="gm-gstep">
						<span class="gm-gstep-n">1</span>
						<div>
							<h3><?php esc_html_e( 'Register an application', 'graph-mailer' ); ?></h3>
							<p><?php esc_html_e( 'Sign in to the Microsoft Entra admin center with an admin account, open App registrations, and click "New registration". Give it a name like "WordPress Mailer", leave "Single tenant" selected, leave the redirect URI empty, and click Register.', 'graph-mailer' ); ?></p>
						</div>
						<a class="button gm-gstep-link" target="_blank" rel="noopener" href="https://entra.microsoft.com/#view/Microsoft_AAD_RegisteredApps/ApplicationsListBlade"><?php esc_html_e( 'Open App registrations ↗', 'graph-mailer' ); ?></a>
					</div>
					<div class="gm-gstep">
						<span class="gm-gstep-n">2</span>
						<div>
							<h3><?php esc_html_e( 'Copy the two IDs', 'graph-mailer' ); ?></h3>
							<p><?php echo wp_kses( __( 'On the app\'s Overview page, copy <span class="gm-copy">Directory (tenant) ID</span> and <span class="gm-copy">Application (client) ID</span> into the fields below.', 'graph-mailer' ), $allowed ); ?></p>
						</div>
						<span></span>
					</div>
					<div class="gm-gstep">
						<span class="gm-gstep-n">3</span>
						<div>
							<h3><?php esc_html_e( 'Create a client secret', 'graph-mailer' ); ?></h3>
							<p><?php esc_html_e( 'In the app, open Certificates & secrets, click "New client secret", pick an expiry, and copy the Value column immediately — it is shown once. Put a reminder in your calendar for the expiry date; mail stops when it lapses.', 'graph-mailer' ); ?></p>
						</div>
						<a class="button gm-gstep-link" target="_blank" rel="noopener" href="https://learn.microsoft.com/en-us/entra/identity-platform/how-to-add-credentials"><?php esc_html_e( 'Microsoft docs ↗', 'graph-mailer' ); ?></a>
					</div>
					<div class="gm-gstep">
						<span class="gm-gstep-n">4</span>
						<div>
							<h3><?php esc_html_e( 'Grant Mail.Send and consent', 'graph-mailer' ); ?></h3>
							<p><?php echo wp_kses( __( 'In the app, open API permissions → "Add a permission" → Microsoft Graph → <strong>Application permissions</strong> (not Delegated) → tick <span class="gm-copy">Mail.Send</span> → "Add permissions". Then click <strong>"Grant admin consent"</strong> and confirm. Skipping consent is the most common failure: the token is issued but every send is refused.', 'graph-mailer' ), $allowed ); ?></p>
						</div>
						<a class="button gm-gstep-link" target="_blank" rel="noopener" href="https://learn.microsoft.com/en-us/graph/permissions-reference"><?php esc_html_e( 'Permission reference ↗', 'graph-mailer' ); ?></a>
					</div>
					<div class="gm-gstep">
						<span class="gm-gstep-n">5</span>
						<div>
							<h3><?php esc_html_e( 'Enter the values and test', 'graph-mailer' ); ?></h3>
							<p><?php esc_html_e( 'Fill the four fields below, save, then use the Test card to send yourself a message. The send log records the exact Graph error if anything is off.', 'graph-mailer' ); ?></p>
						</div>
						<span></span>
					</div>
					<div class="gm-gstep">
						<span class="gm-gstep-n">+</span>
						<div>
							<h3><?php esc_html_e( 'Optional: limit the blast radius', 'graph-mailer' ); ?></h3>
							<p><?php esc_html_e( 'Application Mail.Send can send as any mailbox in the tenant. An Exchange application access policy restricts the app to only the sender mailbox — recommended for production.', 'graph-mailer' ); ?></p>
						</div>
						<a class="button gm-gstep-link" target="_blank" rel="noopener" href="https://learn.microsoft.com/en-us/graph/auth-limit-mailbox-access"><?php esc_html_e( 'Access policies ↗', 'graph-mailer' ); ?></a>
					</div>
				</div>
			</div>

			<div class="gm-grid">
				<div>
					<form method="post" action="options.php" class="gm-card gm-form">
						<h2><?php esc_html_e( 'Microsoft Entra credentials', 'graph-mailer' ); ?></h2>
						<div class="inside">
							<?php
							settings_fields( 'graph_mailer' );
							$this->render_field( $s, 'tenant_id', __( 'Tenant ID', 'graph-mailer' ), 'text', __( 'Directory (tenant) ID from the app registration overview.', 'graph-mailer' ) );
							$this->render_field( $s, 'client_id', __( 'Client ID', 'graph-mailer' ), 'text', __( 'Application (client) ID from the same screen.', 'graph-mailer' ) );
							$this->render_field( $s, 'client_secret', __( 'Client secret', 'graph-mailer' ), 'password', __( 'Write-only: shown never, kept unless you type a new one. Prefer the GRAPH_MAILER_CLIENT_SECRET constant in wp-config.php.', 'graph-mailer' ) );
							$this->render_field( $s, 'sender', __( 'Sender mailbox', 'graph-mailer' ), 'email', __( 'The mailbox mail is sent as. Must be a real licensed mailbox (or shared mailbox) in the tenant.', 'graph-mailer' ) );
							?>
							<div class="gm-field">
								<label>
									<input type="checkbox" name="<?php echo esc_attr( self::OPTION ); ?>[fallback]" value="1" <?php checked( $s['fallback'] ); ?>>
									<strong class="gm-inline"><?php esc_html_e( 'Fall back to the default mailer on failure', 'graph-mailer' ); ?></strong>
								</label>
								<p class="description"><?php esc_html_e( 'Off means a Graph failure fails the send outright — visible, but mail stops. On means delivery degrades to PHP mail instead.', 'graph-mailer' ); ?></p>
							</div>
							<?php submit_button(); ?>
						</div>
					</form>

					<div class="gm-card">
						<h2><?php esc_html_e( 'Send log', 'graph-mailer' ); ?></h2>
						<div class="inside">
							<?php if ( $log ) : ?>
							<table class="gm-log">
								<?php foreach ( $log as $entry ) : ?>
								<tr>
									<td><?php echo esc_html( wp_date( 'M j H:i', (int) $entry['time'] ) ); ?></td>
									<td><?php echo esc_html( $entry['to'] ); ?><br><span class="gm-muted"><?php echo esc_html( $entry['subject'] ); ?></span></td>
									<td>
										<?php if ( ! empty( $entry['ok'] ) ) : ?>
											<span class="gm-ok"><?php esc_html_e( 'sent', 'graph-mailer' ); ?></span>
										<?php else : ?>
											<span class="gm-bad"><?php esc_html_e( 'failed', 'graph-mailer' ); ?></span><br><span class="gm-muted"><?php echo esc_html( $entry['error'] ); ?></span>
										<?php endif; ?>
									</td>
								</tr>
								<?php endforeach; ?>
							</table>
							<?php else : ?>
							<p class="description"><?php esc_html_e( 'No sends yet. The last 20 are kept here, successes and failures alike.', 'graph-mailer' ); ?></p>
							<?php endif; ?>
						</div>
					</div>
				</div>

				<div>
					<div class="gm-card gm-side">
						<h2><?php esc_html_e( 'Setup', 'graph-mailer' ); ?></h2>
						<div class="inside">
							<?php
							// Step 3 (consent) can't be read from here; a successful send proves it.
							$steps = array(
								array( (bool) ( $s['tenant_id'] && $s['client_id'] ), __( 'Register an app in Microsoft Entra and copy its IDs', 'graph-mailer' ) ),
								array( (bool) $s['client_secret'], __( 'Create a client secret', 'graph-mailer' ) ),
								array( $last_ok, __( 'Grant Mail.Send (application) and give admin consent', 'graph-mailer' ) ),
								array( (bool) is_email( $s['sender'] ), __( 'Set the sender mailbox', 'graph-mailer' ) ),
								array( $last_ok, __( 'Send a test message', 'graph-mailer' ) ),
							);
							?>
							<ol class="gm-steps">
								<?php foreach ( $steps as $step ) : ?>
									<li class="<?php echo esc_attr( $step[0] ? 'gm-step-done' : 'gm-step-open' ); ?>"><?php echo esc_html( $step[1] ); ?></li>
								<?php endforeach; ?>
							</ol>
							<p class="description"><?php esc_html_e( 'Entra admin center → App registrations → New registration. No redirect URI is needed for app-only mail.', 'graph-mailer' ); ?></p>
						</div>
					</div>

					<div class="gm-card gm-side">
						<h2><?php esc_html_e( 'Status', 'graph-mailer' ); ?></h2>
						<div class="inside">
							<ul class="gm-status">
								<li><span><?php esc_html_e( 'Configured', 'graph-mailer' ); ?></span>
									<?php echo $configured ? $this->badge( 'ok', __( 'yes', 'graph-mailer' ) ) : $this->badge( 'warn', __( 'no — mail unaffected', 'graph-mailer' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- badge() escapes. ?></li>
								<li><span><?php esc_html_e( 'Token cached', 'graph-mailer' ); ?></span>
									<?php echo '' !== self::cached_token() ? $this->badge( 'ok', __( 'yes', 'graph-mailer' ) ) : $this->badge( 'warn', __( 'no', 'graph-mailer' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- badge() escapes. ?></li>
								<li><span><?php esc_html_e( 'Last send', 'graph-mailer' ); ?></span>
									<?php
									if ( ! $log ) {
										echo $this->badge( 'warn', '—' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- badge() escapes.
									} else {
										echo $last_ok ? $this->badge( 'ok', __( 'sent', 'graph-mailer' ) ) : $this->badge( 'bad', __( 'failed', 'graph-mailer' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- badge() escapes.
									}
									?>
								</li>
							</ul>
						</div>
					</div>

					<div class="gm-card">
						<h2><?php esc_html_e( 'Test', 'graph-mailer' ); ?></h2>
						<div class="inside">
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
								<?php wp_nonce_field( 'graph_mailer_test' ); ?>
								<input type="hidden" name="action" value="graph_mailer_test">
								<p><input type="email" name="test_to" class="gm-full" placeholder="<?php echo esc_attr( get_option( 'admin_email' ) ); ?>"></p>
								<?php submit_button( __( 'Send test message', 'graph-mailer' ), 'secondary', 'submit', false, $configured ? array() : array( 'disabled' => 'disabled' ) ); ?>
								<?php if ( ! $configured ) : ?>
								<p class="description"><?php esc_html_e( 'Enter the credentials first.', 'graph-mailer' ); ?></p>
								<?php endif; ?>
							</form>
						</div>
					</div>
				</div>
			</div>
		</div>
		<?php
	}
}

Graph_Mailer::instance();
