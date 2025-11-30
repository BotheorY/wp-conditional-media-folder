<?php
/**
 * Plugin Name: WP Conditional Media Folder
 * Plugin URI:  https://github.com/BotheorY/wp-conditional-media-folder
 * Description: Automatically saves uploaded media files to custom server directories based on configurable filename or MIME type rules while ensuring full integration and accessibility within the WordPress Media Library.
 * Version:     1.0.1
 * Author:      Andrea Barbagallo (BotheorY)
 * Author URI:  https://www.andreabarbagallo.com/
 * License:     MIT
 * License URI: https://opensource.org/licenses/MIT
 * Text Domain: wp-conditional-media-folder
 * Domain Path: /languages
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Define Constants
define( 'WCMF_VERSION', '1.0.1' );
define( 'WCMF_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'WCMF_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

// Autoloader for classes
spl_autoload_register( function ( $class ) {
	$prefix = 'WCMF\\';
	$base_dir = WCMF_PLUGIN_DIR . 'includes/';

	$len = strlen( $prefix );
	if ( strncmp( $prefix, $class, $len ) !== 0 ) {
		return;
	}

	$relative_class = substr( $class, $len );
	$file = $base_dir . 'class-' . str_replace( '_', '-', strtolower( $relative_class ) ) . '.php';

	if ( file_exists( $file ) ) {
		require $file;
	}
} );

/**
 * Initialize the plugin.
 */
function wcmf_load_textdomain() {
    load_plugin_textdomain( 'wp-conditional-media-folder', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
}
add_action( 'plugins_loaded', 'wcmf_load_textdomain' );

function wcmf_init() {
    $settings = new \WCMF\WCMF_Settings();
    $core     = new \WCMF\WCMF_Core();

    $settings->init();
    $core->init();
}
add_action( 'init', 'wcmf_init' );

/**
 * Activation Hook: Setup default options to avoid empty array issues.
 */
register_activation_hook( __FILE__, function() {
	if ( false === get_option( 'wcmf_rules' ) ) {
		update_option( 'wcmf_rules', [] );
	}
});
