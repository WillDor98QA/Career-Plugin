<?php
/**
 * Plugin Name: Career Portal
 * Plugin URI:  https://yoursite.com
 * Description: A fully custom careers page with job listings, applications, CV uploads, screening questions, portfolio links, email notifications, and an admin dashboard.
 * Version:     1.0.0
 * Author:      Your Name
 * Text Domain: career-portal
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'CP_VERSION',     '1.0.0' );
define( 'CP_PLUGIN_FILE', __FILE__ );
define( 'CP_PLUGIN_DIR',  plugin_dir_path( __FILE__ ) );
define( 'CP_PLUGIN_URL',  plugin_dir_url( __FILE__ ) );
define( 'CP_UPLOAD_DIR',  WP_CONTENT_DIR . '/career-portal-uploads/' );
define( 'CP_UPLOAD_URL',  WP_CONTENT_URL . '/career-portal-uploads/' );

require_once CP_PLUGIN_DIR . 'includes/class-database.php';
require_once CP_PLUGIN_DIR . 'includes/class-post-types.php';
require_once CP_PLUGIN_DIR . 'includes/class-application-handler.php';
require_once CP_PLUGIN_DIR . 'includes/class-email-notifications.php';
require_once CP_PLUGIN_DIR . 'includes/class-shortcodes.php';
require_once CP_PLUGIN_DIR . 'includes/class-nojs-handler.php';
require_once CP_PLUGIN_DIR . 'admin/class-admin.php';

function cp_init() {
    load_plugin_textdomain( 'career-portal', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );

    new CP_Post_Types();
    new CP_Shortcodes();
    new CP_Application_Handler();
    new CP_NoJS_Handler();
    if ( is_admin() ) {
        new CP_Admin();
    }
}
add_action( 'plugins_loaded', 'cp_init' );

// Create upload directory and protection files
function cp_create_upload_dir() {
    if ( ! file_exists( CP_UPLOAD_DIR ) ) {
        wp_mkdir_p( CP_UPLOAD_DIR );
    }

    // Apache-level protection
    $htaccess = CP_UPLOAD_DIR . '.htaccess';
    if ( ! file_exists( $htaccess ) ) {
        file_put_contents( $htaccess, "Options -Indexes\ndeny from all\n" );
    }

    // Prevent directory listing on servers that ignore .htaccess (e.g. nginx).
    // For nginx, also add to your server config:
    //   location /wp-content/career-portal-uploads/ { deny all; }
    $index = CP_UPLOAD_DIR . 'index.php';
    if ( ! file_exists( $index ) ) {
        file_put_contents( $index, "<?php\n// Silence is golden.\n" );
    }
}

// Single activation entry point: install schema, create upload dir, register CPT, flush rewrites
function cp_activate() {
    CP_Database::install();
    cp_create_upload_dir();

    // Register CPT so rewrite rules exist before flushing.
    CP_Post_Types::register_once();
    flush_rewrite_rules();
}
register_activation_hook( __FILE__, 'cp_activate' );

function cp_deactivate() {
    flush_rewrite_rules();
}
register_deactivation_hook( __FILE__, 'cp_deactivate' );
