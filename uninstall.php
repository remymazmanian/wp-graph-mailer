<?php
/**
 * Copyright (C) 2026 Remy Mazmanian
 * GPL-2.0-or-later — see LICENSE.
 */
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;
delete_option( 'graph_mailer_settings' );
delete_option( 'graph_mailer_log' );
delete_transient( 'graph_mailer_token' );
