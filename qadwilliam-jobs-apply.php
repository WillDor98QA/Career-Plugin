<?php
/**
 * Plugin Name: Qadwilliam Jobs & Apply
 * Description: The complete hiring platform for WordPress — job listings, applications, CV uploads, screening questions, and built-in email.
 * Version:     1.0.1
 * Author:      William Dor
 * Author URI:  https://william-six-zeta.vercel.app/
 * License:     GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: qadwilliam-jobs-apply
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// Prevent fatals if two copies of this plugin are active (e.g. old Jobbly folder + new folder).
if ( defined( 'QWJA_PLUGIN_LOADED' ) ) {
    return;
}
define( 'QWJA_PLUGIN_LOADED', true );

define( 'QWJA_VERSION',     '1.0.1' );
define( 'QWJA_PLUGIN_FILE', __FILE__ );
define( 'QWJA_PLUGIN_DIR',  plugin_dir_path( __FILE__ ) );
define( 'QWJA_PLUGIN_URL',  plugin_dir_url( __FILE__ ) );

/**
 * Absolute filesystem path to the plugin's CV upload directory.
 * Lives under the WordPress uploads basedir so it respects custom uploads paths and multisite.
 * Returns '' if wp_upload_dir() reports an error; callers must treat that as "uploads unavailable".
 */
if ( ! function_exists( 'qwja_upload_dir' ) ) {
    /**
     * @return string Trailing-slashed path, or '' if the uploads dir is unavailable.
     */
    function qwja_upload_dir() {
        $base = wp_upload_dir( null, false );
        if ( ! empty( $base['error'] ) || empty( $base['basedir'] ) ) {
            return '';
        }
        return trailingslashit( $base['basedir'] ) . 'qadwilliam-jobs-apply/';
    }
}

if ( ! function_exists( 'qwja_upload_url' ) ) {
    /**
     * @return string Trailing-slashed URL, or '' if the uploads URL is unavailable.
     */
    function qwja_upload_url() {
        $base = wp_upload_dir( null, false );
        if ( ! empty( $base['error'] ) || empty( $base['baseurl'] ) ) {
            return '';
        }
        return trailingslashit( $base['baseurl'] ) . 'qadwilliam-jobs-apply/';
    }
}

if ( ! function_exists( 'qwja_is_submit_application_ajax' ) ) {
    /**
     * Whether the current request is our public application AJAX action.
     */
    function qwja_is_submit_application_ajax() {
        if ( ! wp_doing_ajax() ) {
            return false;
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- action routing only.
        $action = isset( $_REQUEST['action'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['action'] ) ) : '';
        return $action === 'qwja_submit_application';
    }
}

if ( ! function_exists( 'qwja_ajax_submit_fatal_shutdown' ) ) {
    /**
     * Turn PHP fatals on the apply AJAX action into JSON (HTTP 200) when possible.
     */
    function qwja_ajax_submit_fatal_shutdown() {
        if ( ! qwja_is_submit_application_ajax() ) {
            return;
        }

        $err = error_get_last();
        if ( ! $err ) {
            return;
        }

        $fatal_types = array(
            E_ERROR,
            E_PARSE,
            E_CORE_ERROR,
            E_COMPILE_ERROR,
            E_USER_ERROR,
            E_RECOVERABLE_ERROR,
        );
        if ( ! in_array( $err['type'], $fatal_types, true ) ) {
            return;
        }

        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Debug-only diagnostic, gated behind WP_DEBUG.
            error_log(
                'Qadwilliam Jobs & Apply fatal on submit AJAX: '
                . $err['message'] . ' @ ' . $err['file'] . ':' . $err['line']
            );
        }

        if ( headers_sent() ) {
            return;
        }

        while ( ob_get_level() > 0 ) {
            ob_end_clean();
        }

        status_header( 200 );
        if ( function_exists( 'nocache_headers' ) ) {
            nocache_headers();
        }
        header( 'Content-Type: application/json; charset=' . get_option( 'blog_charset' ) );

        $detail = defined( 'WP_DEBUG' ) && WP_DEBUG;
        $msg    = $detail
            ? $err['message'] . ' (' . basename( $err['file'] ) . ':' . $err['line'] . ')'
            : 'We could not process your application due to a server error. Please try again.';

        echo wp_json_encode(
            array(
                'success' => false,
                'data'    => array(
                    'message' => $msg,
                ),
            )
        );
        exit;
    }
}

// Register as early as possible on apply AJAX so fatals during require/include still return JSON.
if ( function_exists( 'qwja_is_submit_application_ajax' ) && qwja_is_submit_application_ajax() ) {
    register_shutdown_function( 'qwja_ajax_submit_fatal_shutdown' );
}

require_once QWJA_PLUGIN_DIR . 'includes/class-deadline.php';
require_once QWJA_PLUGIN_DIR . 'includes/class-database.php';
require_once QWJA_PLUGIN_DIR . 'includes/class-post-types.php';
require_once QWJA_PLUGIN_DIR . 'includes/class-application-handler.php';
require_once QWJA_PLUGIN_DIR . 'includes/class-mailer.php';
require_once QWJA_PLUGIN_DIR . 'includes/class-email-notifications.php';
require_once QWJA_PLUGIN_DIR . 'includes/class-shortcodes.php';
require_once QWJA_PLUGIN_DIR . 'includes/class-nojs-handler.php';
require_once QWJA_PLUGIN_DIR . 'includes/class-template-loader.php';
require_once QWJA_PLUGIN_DIR . 'includes/class-setup.php';
require_once QWJA_PLUGIN_DIR . 'admin/class-admin.php';

function qwja_init() {
    if ( class_exists( 'QWJA_Database', false ) ) {
        QWJA_Database::ensure_installed();
    }

    new QWJA_Post_Types();
    new QWJA_Shortcodes();
    new QWJA_Application_Handler();
    new QWJA_NoJS_Handler();
    new QWJA_Template_Loader();
    new QWJA_Setup();
    if ( is_admin() ) {
        new QWJA_Admin();
    }
}
add_action( 'plugins_loaded', 'qwja_init' );

/**
 * Register fatal-to-JSON handler before the AJAX callback runs.
 */
function qwja_register_submit_ajax_fatal_handler() {
    if ( qwja_is_submit_application_ajax() ) {
        register_shutdown_function( 'qwja_ajax_submit_fatal_shutdown' );
    }
}
add_action( 'plugins_loaded', 'qwja_register_submit_ajax_fatal_handler', 1 );

/**
 * Create the protected CV upload directory under wp-content/uploads/.
 * Idempotent; safe to call from the activation hook and lazily from upload handling.
 */
if ( ! function_exists( 'qwja_create_upload_dir' ) ) {
function qwja_create_upload_dir() {
    $dir = qwja_upload_dir();

    if ( '' === $dir ) {
        return;
    }

    if ( ! file_exists( $dir ) ) {
        wp_mkdir_p( $dir );
    }

    // Apache: deny direct access to uploaded CV files.
    $htaccess = $dir . '.htaccess';
    if ( ! file_exists( $htaccess ) ) {
        @file_put_contents( $htaccess, "Options -Indexes\ndeny from all\n" );
    }

    // Prevent directory listing on servers that ignore .htaccess (e.g. nginx).
    // For nginx, also add to your server config:
    //   location ~ ^/wp-content/uploads/qadwilliam-jobs-apply/ { deny all; }
    $index = $dir . 'index.html';
    if ( ! file_exists( $index ) ) {
        @file_put_contents( $index, '' );
    }
}
}

// Single activation entry point: install schema, create upload dir, register CPT, flush rewrites
function qwja_activate() {
    QWJA_Database::ensure_installed();
    qwja_create_upload_dir();

    // Register CPT so rewrite rules exist before flushing.
    QWJA_Post_Types::register_once();
    QWJA_Setup::create_careers_page();
    flush_rewrite_rules();
}
register_activation_hook( __FILE__, 'qwja_activate' );

function qwja_deactivate() {
    flush_rewrite_rules();
}
register_deactivation_hook( __FILE__, 'qwja_deactivate' );
