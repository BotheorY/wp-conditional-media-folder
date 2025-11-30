<?php
/**
 * Fired when the plugin is uninstalled.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Delete options
delete_option( 'wcmf_custom_path' );
delete_option( 'wcmf_custom_url' );
delete_option( 'wcmf_rules' );

// Note: We do NOT delete the metadata from posts (_wcmf_is_custom).
// Reason: If the user reinstalls the plugin, they will likely want
// the existing custom files to still be recognized as custom.
// Cleaning up post meta for potentially thousands of images is also resource intensive.