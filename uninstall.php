<?php
/**
 * Removes Sendelva's settings, send log and cached tokens, on every site of a
 * multisite network. Settings left by Graph Mailer, this plugin's old name,
 * are Graph Mailer's to remove when it is deleted.
 *
 * Copyright (C) 2026 Remy Mazmanian
 * GPL-2.0-or-later — see LICENSE.
 */
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

function sendelva_uninstall_site() {
	delete_option( 'sendelva_settings' );
	delete_option( 'sendelva_log' );
	delete_transient( 'sendelva_token' );
	delete_transient( 'sendelva_token_failed' );
}

if ( is_multisite() ) {
	foreach ( get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) as $sendelva_site ) {
		switch_to_blog( $sendelva_site );
		sendelva_uninstall_site();
		restore_current_blog();
	}
} else {
	sendelva_uninstall_site();
}
