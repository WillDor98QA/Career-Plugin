<?php
/**
 * Qadwilliam Jobs & Apply — uninstall cleanup.
 *
 * Runs only when the user deletes the plugin from the WordPress admin (NOT on
 * simple deactivate). Removes the plugin's custom tables, posts, taxonomy
 * terms, and options.
 *
 * NOTE: The uploaded CV files under wp-content/uploads/qadwilliam-jobs-apply/
 * are intentionally NOT deleted. Those are user-submitted documents that the
 * site owner may still need for legal/HR records, and silently destroying them
 * during an uninstall would be a data-safety footgun. Site owners can remove
 * that directory manually if they want a full purge.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) exit;

/**
 * Remove the plugin's data. Wrapped in a function so its locals don't leak into
 * the global scope (uninstall.php runs at the top level).
 */
function qwja_run_uninstall() {
    global $wpdb;

    // 1. Drop custom tables. Names are built from $wpdb->prefix + fixed suffixes
    //    (never user input); DROP TABLE cannot be parameterized, so interpolation is required.
    $qwja_tables = array(
        $wpdb->prefix . 'qwja_applications',
        $wpdb->prefix . 'qwja_screening_answers',
    );
    foreach ( $qwja_tables as $qwja_table ) {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- One-time uninstall cleanup; table name is plugin-controlled.
        $wpdb->query( "DROP TABLE IF EXISTS {$qwja_table}" );
    }

    // 2. Delete all qwja_job posts and their meta.
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-time uninstall cleanup of plugin posts.
    $qwja_job_ids = $wpdb->get_col( $wpdb->prepare(
        "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s",
        'qwja_job'
    ) );

    if ( ! empty( $qwja_job_ids ) ) {
        foreach ( $qwja_job_ids as $qwja_job_id ) {
            wp_delete_post( (int) $qwja_job_id, true );
        }
    }

    // 3. Delete department taxonomy terms (orphaned now that jobs are gone).
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-time uninstall cleanup of plugin taxonomy terms.
    $qwja_term_ids = $wpdb->get_col( $wpdb->prepare(
        "SELECT t.term_id
           FROM {$wpdb->terms} t
           JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = t.term_id
          WHERE tt.taxonomy = %s",
        'qwja_department'
    ) );
    foreach ( $qwja_term_ids as $qwja_term_id ) {
        wp_delete_term( (int) $qwja_term_id, 'qwja_department' );
    }

    // 4. Delete plugin options.
    $qwja_options = array( 'qwja_admin_email', 'qwja_db_version', 'qwja_careers_page_id', 'qwja_setup_dismissed', 'qwja_mail_settings' );
    foreach ( $qwja_options as $qwja_option ) {
        delete_option( $qwja_option );
    }

    // 5. Clean up scheduled hooks (if any are ever added in the future).
    // (No-op today, kept as a placeholder so future contributors remember.)
}

qwja_run_uninstall();
