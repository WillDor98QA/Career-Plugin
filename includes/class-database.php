<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class CP_Database {

    // Single source of truth for allowed application statuses.
    public static function allowed_statuses() {
        return array( 'pending', 'reviewing', 'interview', 'hired', 'rejected' );
    }

    public static function install() {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();

        $applications_table = $wpdb->prefix . 'cp_applications';
        $sql = "CREATE TABLE IF NOT EXISTS {$applications_table} (
            id            BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            job_id        BIGINT(20) UNSIGNED NOT NULL,
            full_name     VARCHAR(200) NOT NULL,
            email         VARCHAR(200) NOT NULL,
            phone         VARCHAR(50)  DEFAULT '',
            cover_letter  TEXT         DEFAULT '',
            portfolio_url VARCHAR(500) DEFAULT '',
            cv_file       VARCHAR(500) DEFAULT '',
            status        VARCHAR(50)  NOT NULL DEFAULT 'pending',
            submitted_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY job_id (job_id),
            KEY status (status),
            KEY email (email(100))
        ) {$charset};";

        $screening_table = $wpdb->prefix . 'cp_screening_answers';
        $sql2 = "CREATE TABLE IF NOT EXISTS {$screening_table} (
            id             BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            application_id BIGINT(20) UNSIGNED NOT NULL,
            question       TEXT NOT NULL,
            answer         TEXT NOT NULL,
            PRIMARY KEY (id),
            KEY application_id (application_id)
        ) {$charset};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );
        dbDelta( $sql2 );

        update_option( 'cp_db_version', CP_VERSION );
    }

    public static function get_applications( $args = array() ) {
        global $wpdb;
        $table = $wpdb->prefix . 'cp_applications';

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
            return $wpdb->get_results( $wpdb->prepare( $query, $values ) );
        }
        return $wpdb->get_results( $query );
    }

    public static function count_applications( $args = array() ) {
        global $wpdb;
        $table  = $wpdb->prefix . 'cp_applications';
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
            return (int) $wpdb->get_var( $wpdb->prepare( $query, $values ) );
        }
        return (int) $wpdb->get_var( $query );
    }

    public static function get_application( $id ) {
        global $wpdb;
        $table = $wpdb->prefix . 'cp_applications';
        return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ) );
    }

    public static function get_screening_answers( $application_id ) {
        global $wpdb;
        $table = $wpdb->prefix . 'cp_screening_answers';
        return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE application_id = %d", $application_id ) );
    }

    public static function update_status( $id, $status ) {
        global $wpdb;
        $table   = $wpdb->prefix . 'cp_applications';
        if ( ! in_array( $status, self::allowed_statuses(), true ) ) return false;
        return $wpdb->update( $table, array( 'status' => $status ), array( 'id' => $id ), array( '%s' ), array( '%d' ) );
    }
}
