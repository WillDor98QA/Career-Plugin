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

global $wpdb;

// 1. Drop custom tables.
$tables = array(
    $wpdb->prefix . 'qwja_applications',
    $wpdb->prefix . 'qwja_screening_answers',
);
foreach ( $tables as $table ) {
    // Table names are built from $wpdb->prefix and hardcoded suffixes; safe to interpolate.
    $wpdb->query( "DROP TABLE IF EXISTS {$table}" );
}

// 2. Delete all qwja_job posts and their meta.
$job_ids = $wpdb->get_col( $wpdb->prepare(
    "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s",
    'qwja_job'
) );

if ( ! empty( $job_ids ) ) {
    foreach ( $job_ids as $job_id ) {
        wp_delete_post( (int) $job_id, true );
    }
}

// 3. Delete department taxonomy terms (orphaned now that jobs are gone).
$term_ids = $wpdb->get_col( $wpdb->prepare(
    "SELECT t.term_id
       FROM {$wpdb->terms} t
       JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = t.term_id
      WHERE tt.taxonomy = %s",
    'qwja_department'
) );
foreach ( $term_ids as $term_id ) {
    wp_delete_term( (int) $term_id, 'qwja_department' );
}

// 4. Delete plugin options.
$options = array( 'qwja_admin_email', 'qwja_db_version', 'qwja_careers_page_id', 'qwja_setup_dismissed', 'qwja_mail_settings' );
foreach ( $options as $option ) {
    delete_option( $option );
}

// 5. Clean up scheduled hooks (if any are ever added in the future).
// (No-op today, kept as a placeholder so future contributors remember.)
