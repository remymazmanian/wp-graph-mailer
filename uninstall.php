<?php
/**
 * Removes Graph Mailer's settings, send log and cached tokens, on every site
 * of a multisite network.
 *
 * Copyright (C) 2026 Remy Mazmanian
 * GPL-2.0-or-later — see LICENSE.
 */
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

function graph_mailer_uninstall_site() {
	delete_option( 'graph_mailer_settings' );
	delete_option( 'graph_mailer_log' );
	delete_transient( 'graph_mailer_token' );
	delete_transient( 'graph_mailer_token_failed' );
}

if ( is_multisite() ) {
	foreach ( get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) as $graph_mailer_site ) {
		switch_to_blog( $graph_mailer_site );
		graph_mailer_uninstall_site();
		restore_current_blog();
	}
} else {
	graph_mailer_uninstall_site();
}
