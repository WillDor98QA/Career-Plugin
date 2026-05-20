<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class QWJA_Database {

    // Single source of truth for allowed application statuses.
    public static function allowed_statuses() {
        return array( 'pending', 'reviewing', 'interview', 'hired', 'rejected' );
    }

    /**
     * Names of the plugin's custom tables (without the WP table prefix).
     *
     * @return string[]
     */
    public static function table_suffixes() {
        return array( 'qwja_applications', 'qwja_screening_answers' );
    }

    /**
     * True when every plugin table exists in the current database.
     *
     * Cached per-request so the lookup isn't repeated on every AJAX call.
     */
    public static function tables_exist() {
        static $exists = null;
        if ( $exists !== null ) {
            return $exists;
        }

        global $wpdb;
        foreach ( self::table_suffixes() as $suffix ) {
            $table = $wpdb->prefix . $suffix;
            // Underscores are LIKE wildcards in MySQL — escape them so we match the literal table name.
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- LIKE operand only; table name comes from prefix + fixed suffix.
            $found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
            if ( $found !== $table ) {
                $exists = false;
                return false;
            }
        }
        $exists = true;
        return true;
    }

    /**
     * Make sure the plugin tables exist; install on first use if they don't.
     *
     * Safety-net for installs where the activation hook didn't run (e.g. the
     * plugin file was renamed without a deactivate/reactivate cycle).
     */
    public static function ensure_installed() {
        self::maybe_migrate_legacy_content();

        if ( self::tables_exist() ) {
            return;
        }

        if ( self::migrate_legacy_tables() ) {
            return;
        }

        self::install();
    }

    /**
     * @param string $table Full table name (with prefix).
     */
    private static function table_exists_by_name( $table ) {
        global $wpdb;
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- LIKE operand only.
        $found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
        return $found === $table;
    }

    /**
     * Rename Jobbly / Career Portal tables (wp_*cp_*) to wp_*qwja_* when present.
     */
    private static function migrate_legacy_tables() {
        global $wpdb;

        $pairs = array(
            'cp_applications'       => 'qwja_applications',
            'cp_screening_answers'  => 'qwja_screening_answers',
        );

        $renamed = false;
        foreach ( $pairs as $old_suffix => $new_suffix ) {
            $old_table = $wpdb->prefix . $old_suffix;
            $new_table = $wpdb->prefix . $new_suffix;

            if ( self::table_exists_by_name( $new_table ) ) {
                continue;
            }
            if ( ! self::table_exists_by_name( $old_table ) ) {
                continue;
            }

            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- names built from prefix + fixed suffixes.
            $wpdb->query( "RENAME TABLE `{$old_table}` TO `{$new_table}`" );
            $renamed = true;
        }

        return $renamed && self::tables_exist();
    }

    /**
     * One-time migration from cp_job post type and _cp_* meta keys (Jobbly rename).
     */
    public static function maybe_migrate_legacy_content() {
        if ( get_option( 'qwja_migrated_from_cp' ) ) {
            return;
        }

        global $wpdb;

        $wpdb->update(
            $wpdb->posts,
            array( 'post_type' => 'qwja_job' ),
            array( 'post_type' => 'cp_job' )
        );

        $wpdb->update(
            $wpdb->term_taxonomy,
            array( 'taxonomy' => 'qwja_department' ),
            array( 'taxonomy' => 'cp_department' )
        );

        $meta_map = array(
            '_cp_location'              => '_qwja_location',
            '_cp_job_type'              => '_qwja_job_type',
            '_cp_salary'                => '_qwja_salary',
            '_cp_deadline'              => '_qwja_deadline',
            '_cp_require_portfolio'     => '_qwja_require_portfolio',
            '_cp_screening_questions'   => '_qwja_screening_questions',
        );

        foreach ( $meta_map as $old_key => $new_key ) {
            $wpdb->update(
                $wpdb->postmeta,
                array( 'meta_key' => $new_key ),
                array( 'meta_key' => $old_key )
            );
        }

        update_option( 'qwja_migrated_from_cp', '1' );
    }

    public static function install() {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();

        $applications_table = $wpdb->prefix . 'qwja_applications';
        // Avoid DEFAULT on TEXT/LONGTEXT — invalid on many MySQL/MariaDB versions and can break dbDelta.
        $sql = "CREATE TABLE {$applications_table} (
            id            BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            job_id        BIGINT(20) UNSIGNED NOT NULL,
            full_name     VARCHAR(200) NOT NULL,
            email         VARCHAR(200) NOT NULL,
            phone         VARCHAR(50)  NOT NULL DEFAULT '',
            cover_letter  LONGTEXT     NOT NULL,
            portfolio_url VARCHAR(500) NOT NULL DEFAULT '',
            cv_file       VARCHAR(500) NOT NULL DEFAULT '',
            status        VARCHAR(50)  NOT NULL DEFAULT 'pending',
            submitted_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY job_id (job_id),
            KEY status (status),
            KEY email (email(100))
        ) {$charset};";

        $screening_table = $wpdb->prefix . 'qwja_screening_answers';
        $sql2 = "CREATE TABLE {$screening_table} (
            id             BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            application_id BIGINT(20) UNSIGNED NOT NULL,
            question       LONGTEXT NOT NULL,
            answer         LONGTEXT NOT NULL,
            PRIMARY KEY (id),
            KEY application_id (application_id)
        ) {$charset};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );
        dbDelta( $sql2 );

        update_option( 'qwja_db_version', QWJA_VERSION );
    }

    public static function get_applications( $args = array() ) {
        global $wpdb;
        $table = $wpdb->prefix . 'qwja_applications';

        $defaults = array(
            'job_id'   => 0,
            'status'   => '',
            'per_page' => 20,
            'paged'    => 1,
            'orderby'  => 'submitted_at',
            'order'    => 'DESC',
        );
        $args   = wp_parse_args( $args, $defaults );
        $offset = ( $args['paged'] - 1 ) * $args['per_page'];

        // Validate status against allowed enum; ignore filter otherwise.
        if ( ! empty( $args['status'] ) && ! in_array( $args['status'], self::allowed_statuses(), true ) ) {
            $args['status'] = '';
        }

        $where  = array( '1=1' );
        $values = array();

        if ( ! empty( $args['job_id'] ) ) {
            $where[]  = 'job_id = %d';
            $values[] = $args['job_id'];
        }
        if ( ! empty( $args['status'] ) ) {
            $where[]  = 'status = %s';
            $values[] = $args['status'];
        }

        $where_sql = implode( ' AND ', $where );
        $order_sql = sanitize_sql_orderby( $args['orderby'] . ' ' . $args['order'] ) ?: 'submitted_at DESC';

        $query = "SELECT * FROM {$table} WHERE {$where_sql} ORDER BY {$order_sql} LIMIT %d OFFSET %d";
        $values[] = $args['per_page'];
        $values[] = $offset;

        if ( ! empty( $values ) ) {
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table from $wpdb->prefix; $where_sql built from %d/%s placeholders; $order_sql validated via sanitize_sql_orderby().
            return $wpdb->get_results( $wpdb->prepare( $query, $values ) );
        }
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table from $wpdb->prefix; $where_sql is the static '1=1'; $order_sql validated via sanitize_sql_orderby().
        return $wpdb->get_results( $query );
    }

    public static function count_applications( $args = array() ) {
        global $wpdb;
        $table  = $wpdb->prefix . 'qwja_applications';
        $where  = array( '1=1' );
        $values = array();

        // Validate status against allowed enum; ignore filter otherwise.
        if ( ! empty( $args['status'] ) && ! in_array( $args['status'], self::allowed_statuses(), true ) ) {
            $args['status'] = '';
        }

        if ( ! empty( $args['job_id'] ) ) {
            $where[]  = 'job_id = %d';
            $values[] = $args['job_id'];
        }
        if ( ! empty( $args['status'] ) ) {
            $where[]  = 'status = %s';
            $values[] = $args['status'];
        }

        $where_sql = implode( ' AND ', $where );
        $query = "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}";

        if ( ! empty( $values ) ) {
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table from $wpdb->prefix; $where_sql built from %d/%s placeholders.
            return (int) $wpdb->get_var( $wpdb->prepare( $query, $values ) );
        }
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table from $wpdb->prefix; $where_sql is the static '1=1'.
        return (int) $wpdb->get_var( $query );
    }

    public static function get_application( $id ) {
        global $wpdb;
        $table = $wpdb->prefix . 'qwja_applications';
        return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ) );
    }

    public static function get_screening_answers( $application_id ) {
        global $wpdb;
        $table = $wpdb->prefix . 'qwja_screening_answers';
        return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE application_id = %d", $application_id ) );
    }

    public static function update_status( $id, $status ) {
        global $wpdb;
        $table   = $wpdb->prefix . 'qwja_applications';
        if ( ! in_array( $status, self::allowed_statuses(), true ) ) return false;
        return $wpdb->update( $table, array( 'status' => $status ), array( 'id' => $id ), array( '%s' ), array( '%d' ) );
    }
}
