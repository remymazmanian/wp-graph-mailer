<?php
/**
 * Plugin Name: Graph Mailer
 * Plugin URI: https://remymazmanian.com/
 * Description: Sends WordPress email through Microsoft Graph instead of SMTP. App-only OAuth against Microsoft Entra, no PHPMailer configuration, no SMTP credentials on the server. Falls back to the default mailer when unconfigured.
 * Version: 0.1.0
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Author: Remy Mazmanian
 * Author URI: https://remymazmanian.com/
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: graph-mailer
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

final class Graph_Mailer {

	const OPTION    = 'graph_mailer_settings';
	const LOG       = 'graph_mailer_log';
	const TOKEN_KEY = 'graph_mailer_token';
	const LOG_MAX   = 20;

	private static $instance;

	public static function instance() {
		return self::$instance ?: self::$instance = new self();
	}

	private function __construct() {
		add_filter( 'pre_wp_mail', array( $this, 'intercept' ), 10, 2 );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_menu', array( $this, 'add_settings_page' ) );
		add_action( 'admin_post_graph_mailer_test', array( $this, 'handle_test_send' ) );
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
				$old = (array) get_option( self::OPTION, array() );
				$out = array();
				$out['tenant_id'] = sanitize_text_field( $in['tenant_id'] ?? '' );
				$out['client_id'] = sanitize_text_field( $in['client_id'] ?? '' );
				// Write-only: an empty submit keeps the stored secret.
				$posted               = trim( (string) ( $in['client_secret'] ?? '' ) );
				$out['client_secret'] = '' !== $posted ? $posted : ( $old['client_secret'] ?? '' );
				$out['sender']   = sanitize_email( $in['sender'] ?? '' );
				$out['fallback'] = empty( $in['fallback'] ) ? 0 : 1;
				delete_transient( self::TOKEN_KEY );
				return $out;
			},
		) );
	}

	/* ---------------------------------------------------------- graph */

	private function token() {
		$cached = get_transient( self::TOKEN_KEY );
		if ( $cached ) {
			return $cached;
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
			return $response;
		}
		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( empty( $body['access_token'] ) ) {
			$detail = $body['error_description'] ?? wp_remote_retrieve_response_message( $response );
			return new WP_Error( 'graph_mailer_token', 'Token request refused: ' . substr( (string) $detail, 0, 200 ) );
		}
		$ttl = max( 60, (int) ( $body['expires_in'] ?? 3600 ) - 120 );
		set_transient( self::TOKEN_KEY, $body['access_token'], $ttl );
		return $body['access_token'];
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

		$this->log( $atts, false, $result->get_error_message() );
		if ( self::settings()['fallback'] ) {
			return null; // hand the message to the default mailer
		}
		do_action( 'wp_mail_failed', new WP_Error( 'wp_mail_failed', $result->get_error_message(), $atts ) );
		return false;
	}

	private function send( $atts ) {
		$to          = $atts['to'] ?? array();
		$subject     = (string) ( $atts['subject'] ?? '' );
		$message     = (string) ( $atts['message'] ?? '' );
		$headers     = $atts['headers'] ?? array();
		$attachments = $atts['attachments'] ?? array();

		$parsed = $this->parse_headers( $headers );
		$html   = false !== stripos( $parsed['content_type'], 'text/html' );

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

		$total = 0;
		foreach ( (array) $attachments as $path ) {
			if ( ! is_readable( $path ) ) {
				continue;
			}
			$size = filesize( $path );
			if ( $total + $size > 3 * MB_IN_BYTES ) {
				return new WP_Error( 'graph_mailer_attachments', 'Attachments exceed the 3 MB sendMail limit.' );
			}
			$total += $size;
			$payload['message']['attachments'][] = array(
				'@odata.type'  => '#microsoft.graph.fileAttachment',
				'name'         => wp_basename( $path ),
				'contentBytes' => base64_encode( file_get_contents( $path ) ),
			);
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
				'body'    => wp_json_encode( $payload ),
			)
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$code = wp_remote_retrieve_response_code( $response );
		if ( 202 !== $code ) {
			$body = json_decode( wp_remote_retrieve_body( $response ), true );
			$msg  = $body['error']['message'] ?? ( 'HTTP ' . $code );
			return new WP_Error( 'graph_mailer_send', substr( (string) $msg, 0, 200 ) );
		}

		$this->log( $atts, true, '' );
		return true;
	}

	private function recipients( $list ) {
		$out = array();
		foreach ( is_array( $list ) ? $list : explode( ',', $list ) as $addr ) {
			$addr = trim( $addr );
			if ( preg_match( '/<([^>]+)>/', $addr, $m ) ) {
				$addr = $m[1];
			}
			if ( is_email( $addr ) ) {
				$out[] = array( 'emailAddress' => array( 'address' => $addr ) );
			}
		}
		return $out;
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
		$hook = add_options_page( 'Graph Mailer', 'Graph Mailer', 'manage_options', 'graph-mailer', array( $this, 'render_settings_page' ) );
		add_action( 'load-' . $hook, array( $this, 'help_tab' ) );
	}

	public function help_tab() {
		get_current_screen()->add_help_tab( array(
			'id'      => 'gm-help',
			'title'   => __( 'How it works', 'graph-mailer' ),
			'content' =>
				'<p>' . esc_html__( 'Graph Mailer short-circuits wp_mail() and delivers through Microsoft Graph with an app-only token. No SMTP server, no PHPMailer configuration, and the client secret can live in wp-config.php as a constant instead of the database.', 'graph-mailer' ) . '</p>' .
				'<p>' . esc_html__( 'Until all four credentials are present the plugin is inert and WordPress mail flows exactly as before. With fallback enabled, a Graph failure hands the message back to the default mailer instead of dropping it.', 'graph-mailer' ) . '</p>',
		) );
	}

	public function handle_test_send() {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'graph_mailer_test' ) ) {
			wp_die( 'Not allowed.' );
		}
		$to = sanitize_email( wp_unslash( $_POST['test_to'] ?? '' ) );
		if ( ! $to ) {
			$to = get_option( 'admin_email' );
		}
		$ok = wp_mail( $to, 'Graph Mailer test — ' . get_bloginfo( 'name' ), "This message was sent through Microsoft Graph by the Graph Mailer plugin.\n\nSite: " . home_url( '/' ) . "\nTime: " . gmdate( 'c' ) );
		wp_safe_redirect( add_query_arg( 'gm_test', $ok ? 'ok' : 'fail', admin_url( 'options-general.php?page=graph-mailer' ) ) );
		exit;
	}

	public function render_settings_page() {
		$s          = self::settings();
		$configured = self::configured();
		$log        = (array) get_option( self::LOG, array() );
		$last_ok    = $log && ! empty( $log[0]['ok'] );
		?>
		<style>
			.gm-wrap{max-width:960px;}
			.gm-head{display:flex;align-items:baseline;gap:.6rem;margin:0 0 4px;}
			.gm-head h1{padding:0;margin:0;}
			.gm-ver{font-family:Menlo,Consolas,monospace;font-size:11px;color:#646970;border:1px solid #c3c4c7;border-radius:3px;padding:1px 6px;background:#fff;}
			.gm-sub{color:#646970;margin:0 0 20px;max-width:70ch;}
			.gm-grid{display:grid;grid-template-columns:minmax(0,2fr) minmax(260px,1fr);gap:20px;align-items:start;}
			@media(max-width:960px){.gm-grid{grid-template-columns:1fr;}}
			.gm-card{background:#fff;border:1px solid #c3c4c7;border-radius:4px;box-shadow:0 1px 1px rgba(0,0,0,.04);}
			.gm-card h2{font-size:13px;text-transform:uppercase;letter-spacing:.04em;color:#1d2327;margin:0;padding:12px 16px;border-bottom:1px solid #f0f0f1;}
			.gm-card .inside{padding:16px;}
			.gm-field{padding:12px 0;border-bottom:1px solid #f0f0f1;}
			.gm-field:first-child{padding-top:2px;}
			.gm-field:last-child{border-bottom:0;padding-bottom:2px;}
			.gm-field>strong{display:block;margin-bottom:6px;}
			.gm-field input[type=text],.gm-field input[type=email],.gm-field input[type=password]{width:100%;max-width:420px;}
			.gm-field .description{margin:6px 0 0;}
			.gm-const{font-family:Menlo,Consolas,monospace;font-size:11px;color:#00701a;border:1px solid #b6e0be;background:#edfaef;border-radius:3px;padding:1px 6px;margin-left:6px;}
			.gm-status{list-style:none;margin:0;padding:0;}
			.gm-status li{display:flex;justify-content:space-between;gap:10px;padding:7px 0;border-bottom:1px solid #f0f0f1;font-size:13px;}
			.gm-status li:last-child{border-bottom:0;}
			.gm-ok{color:#00701a;font-weight:600;}
			.gm-warn{color:#996800;font-weight:600;}
			.gm-bad{color:#b32d2e;font-weight:600;}
			.gm-steps{margin:0;padding:0 0 0 2px;list-style:none;counter-reset:gm;}
			.gm-steps li{counter-increment:gm;padding:6px 0 6px 30px;position:relative;font-size:13px;border-bottom:1px solid #f0f0f1;}
			.gm-steps li:last-child{border-bottom:0;}
			.gm-steps li::before{content:counter(gm);position:absolute;left:0;top:6px;width:20px;height:20px;border-radius:50%;text-align:center;line-height:20px;font-size:11px;font-weight:600;background:#f0f0f1;color:#646970;}
			.gm-step-done{color:#646970;text-decoration:line-through;text-decoration-color:#c3c4c7;}
			.gm-step-done::before{content:"\2713" !important;background:#00701a !important;color:#fff !important;}
			.gm-log{width:100%;border-collapse:collapse;font-size:12px;}
			.gm-log td{padding:6px 8px 6px 0;border-bottom:1px solid #f0f0f1;vertical-align:top;}
			.gm-log tr:last-child td{border-bottom:0;}
		</style>
		<div class="wrap gm-wrap">
			<div class="gm-head">
				<h1><?php esc_html_e( 'Graph Mailer', 'graph-mailer' ); ?></h1>
				<span class="gm-ver">0.1.0</span>
			</div>
			<p class="gm-sub"><?php esc_html_e( 'WordPress mail through Microsoft Graph. App-only OAuth against Microsoft Entra — no SMTP server, no mail credentials in PHPMailer. Inert until configured; with fallback on, a Graph failure hands the message to the default mailer instead of dropping it.', 'graph-mailer' ); ?></p>

			<?php if ( isset( $_GET['gm_test'] ) ) : ?>
				<div class="notice notice-<?php echo 'ok' === $_GET['gm_test'] ? 'success' : 'error'; ?> is-dismissible"><p>
					<?php echo 'ok' === $_GET['gm_test'] ? esc_html__( 'Test message accepted. Check the inbox — and the send log below.', 'graph-mailer' ) : esc_html__( 'Test send failed. The send log below has the error.', 'graph-mailer' ); ?>
				</p></div>
			<?php endif; ?>

			<div class="gm-grid">
				<div>
					<form method="post" action="options.php" class="gm-card" style="margin-bottom:20px;">
						<h2><?php esc_html_e( 'Microsoft Entra credentials', 'graph-mailer' ); ?></h2>
						<div class="inside">
							<?php settings_fields( 'graph_mailer' ); ?>
							<?php
							$field = function ( $key, $label, $type, $desc ) use ( $s ) {
								$is_const = ! empty( $s[ $key . '_const' ] );
								echo '<div class="gm-field"><strong>' . esc_html( $label );
								if ( $is_const ) {
									echo '<span class="gm-const">wp-config</span>';
								}
								echo '</strong>';
								if ( 'password' === $type ) {
									$ph = $s[ $key ] ? __( 'saved — leave blank to keep', 'graph-mailer' ) : '';
									echo '<input type="password" autocomplete="new-password" name="' . esc_attr( self::OPTION ) . '[' . esc_attr( $key ) . ']" value="" placeholder="' . esc_attr( $ph ) . '"' . disabled( $is_const, true, false ) . '>';
								} else {
									echo '<input type="' . esc_attr( $type ) . '" name="' . esc_attr( self::OPTION ) . '[' . esc_attr( $key ) . ']" value="' . esc_attr( $is_const ? '' : $s[ $key ] ) . '"' . disabled( $is_const, true, false ) . ( $is_const ? ' placeholder="' . esc_attr__( 'defined in wp-config.php', 'graph-mailer' ) . '"' : '' ) . '>';
								}
								echo '<p class="description">' . esc_html( $desc ) . '</p></div>';
							};
							$field( 'tenant_id', __( 'Tenant ID', 'graph-mailer' ), 'text', __( 'Directory (tenant) ID from the app registration overview.', 'graph-mailer' ) );
							$field( 'client_id', __( 'Client ID', 'graph-mailer' ), 'text', __( 'Application (client) ID from the same screen.', 'graph-mailer' ) );
							$field( 'client_secret', __( 'Client secret', 'graph-mailer' ), 'password', __( 'Write-only: shown never, kept unless you type a new one. Prefer the GRAPH_MAILER_CLIENT_SECRET constant in wp-config.php.', 'graph-mailer' ) );
							$field( 'sender', __( 'Sender mailbox', 'graph-mailer' ), 'email', __( 'The mailbox mail is sent as. Must be a real licensed mailbox (or shared mailbox) in the tenant.', 'graph-mailer' ) );
							?>
							<div class="gm-field">
								<label>
									<input type="checkbox" name="<?php echo esc_attr( self::OPTION ); ?>[fallback]" value="1" <?php checked( $s['fallback'] ); ?>>
									<strong style="display:inline"><?php esc_html_e( 'Fall back to the default mailer on failure', 'graph-mailer' ); ?></strong>
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
									<td><?php echo esc_html( wp_date( 'M j H:i', $entry['time'] ) ); ?></td>
									<td><?php echo esc_html( $entry['to'] ); ?><br><span style="color:#646970"><?php echo esc_html( $entry['subject'] ); ?></span></td>
									<td><?php echo $entry['ok'] ? '<span class="gm-ok">sent</span>' : '<span class="gm-bad">failed</span><br><span style="color:#646970">' . esc_html( $entry['error'] ) . '</span>'; ?></td>
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
					<div class="gm-card" style="margin-bottom:20px;">
						<h2><?php esc_html_e( 'Setup', 'graph-mailer' ); ?></h2>
						<div class="inside">
							<?php
							$steps = array(
								array( (bool) ( $s['tenant_id'] && $s['client_id'] ), __( 'Register an app in Microsoft Entra and copy its IDs', 'graph-mailer' ) ),
								array( (bool) $s['client_secret'], __( 'Create a client secret', 'graph-mailer' ) ),
								array( false, __( 'Grant Mail.Send (application) and give admin consent', 'graph-mailer' ) ),
								array( (bool) is_email( $s['sender'] ), __( 'Set the sender mailbox', 'graph-mailer' ) ),
								array( $last_ok, __( 'Send a test message', 'graph-mailer' ) ),
							);
							// Step 3 cannot be read from here; infer it from a successful send.
							$steps[2][0] = $last_ok;
							echo '<ol class="gm-steps">';
							foreach ( $steps as $st ) {
								echo '<li class="' . ( $st[0] ? 'gm-step-done' : 'gm-step-open' ) . '">' . esc_html( $st[1] ) . '</li>';
							}
							echo '</ol>';
							?>
							<p class="description"><?php esc_html_e( 'Entra admin center → App registrations → New registration. No redirect URI is needed for app-only mail.', 'graph-mailer' ); ?></p>
						</div>
					</div>

					<div class="gm-card" style="margin-bottom:20px;">
						<h2><?php esc_html_e( 'Status', 'graph-mailer' ); ?></h2>
						<div class="inside">
							<ul class="gm-status">
								<li><span><?php esc_html_e( 'Configured', 'graph-mailer' ); ?></span>
									<?php echo $configured ? '<span class="gm-ok">' . esc_html__( 'yes', 'graph-mailer' ) . '</span>' : '<span class="gm-warn">' . esc_html__( 'no — mail unaffected', 'graph-mailer' ) . '</span>'; ?></li>
								<li><span><?php esc_html_e( 'Token cached', 'graph-mailer' ); ?></span>
									<?php echo get_transient( self::TOKEN_KEY ) ? '<span class="gm-ok">' . esc_html__( 'yes', 'graph-mailer' ) . '</span>' : '<span class="gm-warn">' . esc_html__( 'no', 'graph-mailer' ) . '</span>'; ?></li>
								<li><span><?php esc_html_e( 'Last send', 'graph-mailer' ); ?></span>
									<?php echo $log ? ( $log[0]['ok'] ? '<span class="gm-ok">' . esc_html__( 'sent', 'graph-mailer' ) . '</span>' : '<span class="gm-bad">' . esc_html__( 'failed', 'graph-mailer' ) . '</span>' ) : '<span class="gm-warn">—</span>'; ?></li>
							</ul>
						</div>
					</div>

					<div class="gm-card">
						<h2><?php esc_html_e( 'Test', 'graph-mailer' ); ?></h2>
						<div class="inside">
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
								<?php wp_nonce_field( 'graph_mailer_test' ); ?>
								<input type="hidden" name="action" value="graph_mailer_test">
								<p><input type="email" name="test_to" style="width:100%" placeholder="<?php echo esc_attr( get_option( 'admin_email' ) ); ?>"></p>
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
