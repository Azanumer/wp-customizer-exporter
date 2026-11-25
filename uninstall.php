<?php
/**
 * Uninstall WP Customizer Exporter.
 *
 * Deletes the staged-import transient. Theme mods are site data and are
 * intentionally left untouched.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

delete_transient( 'wce_import_' . get_current_user_id() );
