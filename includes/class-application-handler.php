<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class CP_Application_Handler {

    const DUPLICATE_WINDOW_HOURS = 24;

    public function __construct() {
        add_action( 'wp_ajax_cp_submit_application',        array( $this, 'handle_submission' ) );
        add_action( 'wp_ajax_nopriv_cp_submit_application', array( $this, 'handle_submission' ) );
    }

    public function handle_submission() {
        // Verify nonce
        if ( ! isset( $_POST['cp_nonce'] ) || ! wp_verify_nonce( $_POST['cp_nonce'], 'cp_apply_nonce' ) ) {
            wp_send_json_error( array( 'message' => 'Security check failed. Please refresh and try again.' ) );
        }

        // Trim-aware required field validation (whitespace-only names previously slipped through).
        $required = array( 'cp_full_name', 'cp_email', 'cp_job_id' );
        foreach ( $required as $field ) {
            if ( ! isset( $_POST[ $field ] ) || trim( (string) wp_unslash( $_POST[ $field ] ) ) === '' ) {
                wp_send_json_error( array( 'message' => 'Please fill in all required fields.' ) );
            }
        }

        $job_id = absint( $_POST['cp_job_id'] );
        $job    = get_post( $job_id );
        if ( ! $job || $job->post_type !== 'cp_job' || $job->post_status !== 'publish' ) {
            wp_send_json_error( array( 'message' => 'Invalid job listing.' ) );
        }

        // Enforce application deadline (end of day, site timezone).
        $deadline = get_post_meta( $job_id, '_cp_deadline', true );
        if ( $deadline ) {
            $deadline_ts = strtotime( $deadline . ' 23:59:59' );
            if ( $deadline_ts && $deadline_ts < current_time( 'timestamp' ) ) {
                wp_send_json_error( array( 'message' => 'The application deadline for this position has passed.' ) );
            }
        }

        $email = sanitize_email( $_POST['cp_email'] );
        if ( ! is_email( $email ) ) {
            wp_send_json_error( array( 'message' => 'Please enter a valid email address.' ) );
        }

        // Portfolio: required-flag check + URL validation regardless of required state.
        $require_portfolio = get_post_meta( $job_id, '_cp_require_portfolio', true );
        $portfolio_raw     = isset( $_POST['cp_portfolio_url'] ) ? trim( (string) wp_unslash( $_POST['cp_portfolio_url'] ) ) : '';

        if ( $require_portfolio === '1' && $portfolio_raw === '' ) {
            wp_send_json_error( array( 'message' => 'A portfolio link is required for this position.' ) );
        }

        $portfolio_url = '';
        if ( $portfolio_raw !== '' ) {
            // Block obviously dangerous schemes outright.
            if ( preg_match( '#^\s*(javascript|data|vbscript):#i', $portfolio_raw ) ) {
                wp_send_json_error( array( 'message' => 'Portfolio URL is not a valid web address.' ) );
            }
            $sanitized = esc_url_raw( $portfolio_raw );
            if ( ! $sanitized || ! filter_var( $sanitized, FILTER_VALIDATE_URL ) ) {
                wp_send_json_error( array( 'message' => 'Portfolio URL is not a valid web address.' ) );
            }
            $portfolio_url = $sanitized;
        }

        // Server-side screening question validation: every configured question must have a non-empty answer.
        $configured_questions = get_post_meta( $job_id, '_cp_screening_questions', true );
        if ( ! is_array( $configured_questions ) ) $configured_questions = array();
        $submitted_answers = isset( $_POST['cp_screening'] ) && is_array( $_POST['cp_screening'] )
            ? wp_unslash( $_POST['cp_screening'] )
            : array();

        foreach ( $configured_questions as $question ) {
            if ( $question === '' ) continue;
            if ( ! isset( $submitted_answers[ $question ] ) || trim( (string) $submitted_answers[ $question ] ) === '' ) {
                wp_send_json_error( array( 'message' => 'Please answer all screening questions.' ) );
            }
        }

        // CV is mandatory: validate presence and upload error before reading file metadata.
        if ( empty( $_FILES['cp_cv'] ) || ! isset( $_FILES['cp_cv']['name'] ) || $_FILES['cp_cv']['name'] === '' ) {
            wp_send_json_error( array( 'message' => 'A CV / resume is required.' ) );
        }

        $upload_error = isset( $_FILES['cp_cv']['error'] ) ? (int) $_FILES['cp_cv']['error'] : UPLOAD_ERR_NO_FILE;
        if ( $upload_error !== UPLOAD_ERR_OK ) {
            wp_send_json_error( array( 'message' => $this->upload_error_message( $upload_error ) ) );
        }

        if ( ! isset( $_FILES['cp_cv']['size'] ) || (int) $_FILES['cp_cv']['size'] === 0 ) {
            wp_send_json_error( array( 'message' => 'The uploaded CV appears to be empty.' ) );
        }

        // Rate-limit: block re-applying to the same job from the same email within the window.
        global $wpdb;
        $apps_table = $wpdb->prefix . 'cp_applications';
        $existing   = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$apps_table}
             WHERE email = %s AND job_id = %d
               AND submitted_at >= DATE_SUB( UTC_TIMESTAMP(), INTERVAL %d HOUR )",
            $email, $job_id, self::DUPLICATE_WINDOW_HOURS
        ) );
        if ( $existing > 0 ) {
            wp_send_json_error( array( 'message' => 'You have already applied for this position.' ) );
        }

        // Handle CV upload (after all other validation so we don't write files for bad submissions).
        $cv_file = $this->handle_cv_upload( $_FILES['cp_cv'] );
        if ( is_wp_error( $cv_file ) ) {
            wp_send_json_error( array( 'message' => $cv_file->get_error_message() ) );
        }

        // Save application to database
        $inserted = $wpdb->insert( $apps_table, array(
            'job_id'       => $job_id,
            'full_name'    => sanitize_text_field( wp_unslash( $_POST['cp_full_name'] ) ),
            'email'        => $email,
            'phone'        => sanitize_text_field( wp_unslash( $_POST['cp_phone'] ?? '' ) ),
            'cover_letter' => sanitize_textarea_field( wp_unslash( $_POST['cp_cover_letter'] ?? '' ) ),
            'portfolio_url'=> $portfolio_url,
            'cv_file'      => $cv_file,
            'status'       => 'pending',
        ), array( '%d','%s','%s','%s','%s','%s','%s','%s' ) );

        if ( ! $inserted ) {
            // Clean up the orphaned CV file we just wrote.
            $this->cleanup_cv_file( $cv_file );
            wp_send_json_error( array( 'message' => 'Could not save your application. Please try again.' ) );
        }

        $application_id = $wpdb->insert_id;

        // Save screening answers (only those that match configured questions).
        if ( ! empty( $configured_questions ) && ! empty( $submitted_answers ) ) {
            $answers_table = $wpdb->prefix . 'cp_screening_answers';
            foreach ( $configured_questions as $question ) {
                if ( $question === '' || ! isset( $submitted_answers[ $question ] ) ) continue;
                $wpdb->insert( $answers_table, array(
                    'application_id' => $application_id,
                    'question'       => sanitize_text_field( $question ),
                    'answer'         => sanitize_textarea_field( $submitted_answers[ $question ] ),
                ), array( '%d', '%s', '%s' ) );
            }
        }

        // Send notifications (failures here should not break the user's submission).
        $notifications = new CP_Email_Notifications();
        $notifications->notify_admin( $application_id, $job );
        $notifications->notify_applicant( $application_id, $job );

        wp_send_json_success( array(
            'message' => 'Your application has been submitted successfully! We will be in touch soon.'
        ) );
    }

    private function upload_error_message( $code ) {
        switch ( $code ) {
            case UPLOAD_ERR_INI_SIZE:
            case UPLOAD_ERR_FORM_SIZE:
                return 'Your CV is too large. Please upload a file under 5MB.';
            case UPLOAD_ERR_PARTIAL:
                return 'The CV upload was interrupted. Please try again.';
            case UPLOAD_ERR_NO_FILE:
                return 'A CV / resume is required.';
            case UPLOAD_ERR_NO_TMP_DIR:
                return 'Server is missing a temporary upload folder. Please contact the site administrator.';
            case UPLOAD_ERR_CANT_WRITE:
                return 'Server could not save the uploaded CV. Please contact the site administrator.';
            case UPLOAD_ERR_EXTENSION:
                return 'A server extension blocked the upload. Please contact the site administrator.';
            default:
                return 'Could not upload your CV. Please try again.';
        }
    }

    private function handle_cv_upload( $file ) {
        $allowed_types = array(
            'application/pdf',
            'application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            // Some hosts/clients send .docx as one of these — tolerate them when extension also matches.
            'application/zip',
            'application/octet-stream',
        );
        $allowed_ext = array( 'pdf', 'doc', 'docx' );

        $ext = strtolower( pathinfo( $file['name'], PATHINFO_EXTENSION ) );
        if ( ! in_array( $ext, $allowed_ext, true ) ) {
            return new WP_Error( 'invalid_file', 'Only PDF, DOC, and DOCX files are allowed.' );
        }

        // MIME validation — gracefully degrade if the fileinfo extension isn't installed.
        if ( extension_loaded( 'fileinfo' ) && class_exists( 'finfo' ) ) {
            $finfo = new finfo( FILEINFO_MIME_TYPE );
            $mime  = $finfo->file( $file['tmp_name'] );
            if ( $mime && ! in_array( $mime, $allowed_types, true ) ) {
                return new WP_Error( 'invalid_file', 'Invalid file type detected.' );
            }
        } else {
            _doing_it_wrong(
                __METHOD__,
                'PHP fileinfo extension is missing; falling back to extension-only validation for CV uploads.',
                '1.0.0'
            );
        }

        if ( $file['size'] > 5 * 1024 * 1024 ) {
            return new WP_Error( 'file_too_large', 'CV file must be under 5MB.' );
        }

        if ( ! is_dir( CP_UPLOAD_DIR ) || ! wp_is_writable( CP_UPLOAD_DIR ) ) {
            return new WP_Error( 'upload_failed', 'Upload destination is not writable. Please contact the site administrator.' );
        }

        $filename  = sanitize_file_name( time() . '_' . wp_generate_password( 8, false ) . '.' . $ext );
        $dest_path = CP_UPLOAD_DIR . $filename;

        if ( ! move_uploaded_file( $file['tmp_name'], $dest_path ) ) {
            return new WP_Error( 'upload_failed', 'Could not save your CV. Please try again.' );
        }

        return $filename;
    }

    private function cleanup_cv_file( $filename ) {
        if ( ! $filename ) return;
        $path = CP_UPLOAD_DIR . basename( $filename );
        if ( file_exists( $path ) ) {
            @unlink( $path );
        }
    }
}
