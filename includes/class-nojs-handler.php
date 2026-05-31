<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Catches application form submissions that arrive as a regular POST (i.e. the
 * browser submitted the form without JavaScript). The AJAX flow is the only
 * supported path because file uploads and inline validation depend on it, so
 * instead of silently failing or saving an incomplete record we return a clear
 * page explaining what's required.
 */
class QWJA_NoJS_Handler {

    public function __construct() {
        add_action( 'init', array( $this, 'maybe_handle' ) );
    }

    public function maybe_handle() {
        if ( ! isset( $_SERVER['REQUEST_METHOD'] ) || $_SERVER['REQUEST_METHOD'] !== 'POST' ) return;
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Only checks for a marker field then calls wp_die(); no form data is processed or stored.
        if ( empty( $_POST['qwja_nojs_submit'] ) ) return;

        // Don't interfere with the AJAX endpoint — that request goes to admin-ajax.php,
        // which uses its own dispatcher and never hits the `init` action this way.
        if ( defined( 'DOING_AJAX' ) && DOING_AJAX ) return;

        wp_die(
            esc_html__( 'JavaScript is required to submit your application. Please enable JavaScript in your browser and try again.', 'qadwilliam-jobs-apply' ),
            esc_html__( 'JavaScript Required', 'qadwilliam-jobs-apply' ),
            array( 'response' => 400, 'back_link' => true )
        );
    }
}
