<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class QWJA_Application_Handler {

    const DUPLICATE_WINDOW_HOURS = 24;

    public function __construct() {
        add_action( 'wp_ajax_qwja_submit_application',        array( $this, 'handle_submission' ) );
        add_action( 'wp_ajax_nopriv_qwja_submit_application', array( $this, 'handle_submission' ) );
    }

    public function handle_submission() {
        // Convert any PHP fatal/exception thrown deep in the submit pipeline into a
        // graceful JSON error so the form doesn't see a raw HTTP 500 (which surfaces
        // as a useless "Network error" message in the browser).
        try {
            $this->do_handle_submission();
        } catch ( \Throwable $e ) {
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( 'Qadwilliam Jobs & Apply submission error: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine() );
            }
            if ( ! headers_sent() ) {
                status_header( 200 );
            }
            wp_send_json_error( array(
                'message' => 'We could not process your application due to a server error. Please try again, and if the problem persists contact the site administrator.',
            ) );
        }
    }

    private function do_handle_submission() {
        if ( ! isset( $_POST['qwja_nonce'] )
            || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['qwja_nonce'] ) ), 'qwja_apply_nonce' ) ) {
            wp_send_json_error( array( 'message' => 'Security check failed. Please refresh and try again.' ) );
        }

        // Trim-aware required field validation (whitespace-only names previously slipped through).
        $required = array( 'qwja_full_name', 'qwja_email', 'qwja_job_id' );
        foreach ( $required as $field ) {
            if ( ! isset( $_POST[ $field ] ) || trim( (string) wp_unslash( $_POST[ $field ] ) ) === '' ) {
                wp_send_json_error( array( 'message' => 'Please fill in all required fields.' ) );
            }
        }

        $job_id = absint( $_POST['qwja_job_id'] );
        $job    = get_post( $job_id );
        if ( ! $job || $job->post_type !== 'qwja_job' || $job->post_status !== 'publish' ) {
            wp_send_json_error( array( 'message' => 'Invalid job listing.' ) );
        }

        if ( QWJA_Deadline::is_expired( $job_id ) ) {
            wp_send_json_error( array( 'message' => 'The application deadline for this position has passed.' ) );
        }

        $email = sanitize_email( $_POST['qwja_email'] );
        if ( ! is_email( $email ) ) {
            wp_send_json_error( array( 'message' => 'Please enter a valid email address.' ) );
        }

        // Portfolio: required-flag check + URL validation regardless of required state.
        $require_portfolio = get_post_meta( $job_id, '_qwja_require_portfolio', true );
        $portfolio_raw     = isset( $_POST['qwja_portfolio_url'] ) ? trim( (string) wp_unslash( $_POST['qwja_portfolio_url'] ) ) : '';

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
        $configured_questions = get_post_meta( $job_id, '_qwja_screening_questions', true );
        if ( ! is_array( $configured_questions ) ) $configured_questions = array();
        $submitted_answers = isset( $_POST['qwja_screening'] ) && is_array( $_POST['qwja_screening'] )
            ? wp_unslash( $_POST['qwja_screening'] )
            : array();

        foreach ( $configured_questions as $question ) {
            if ( $question === '' ) continue;
            if ( ! isset( $submitted_answers[ $question ] ) || trim( (string) $submitted_answers[ $question ] ) === '' ) {
                wp_send_json_error( array( 'message' => 'Please answer all screening questions.' ) );
            }
        }

        // CV is mandatory: validate presence and upload error before reading file metadata.
        if ( empty( $_FILES['qwja_cv'] ) || ! isset( $_FILES['qwja_cv']['name'] ) || $_FILES['qwja_cv']['name'] === '' ) {
            wp_send_json_error( array( 'message' => 'A CV / resume is required.' ) );
        }

        $upload_error = isset( $_FILES['qwja_cv']['error'] ) ? (int) $_FILES['qwja_cv']['error'] : UPLOAD_ERR_NO_FILE;
        if ( $upload_error !== UPLOAD_ERR_OK ) {
            wp_send_json_error( array( 'message' => $this->upload_error_message( $upload_error ) ) );
        }

        if ( ! isset( $_FILES['qwja_cv']['size'] ) || (int) $_FILES['qwja_cv']['size'] === 0 ) {
            wp_send_json_error( array( 'message' => 'The uploaded CV appears to be empty.' ) );
        }

        // Rate-limit: block re-applying to the same job from the same email within the window.
        global $wpdb;
        $apps_table = $wpdb->prefix . 'qwja_applications';
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
        $cv_file = $this->handle_cv_upload( $_FILES['qwja_cv'] );
        if ( is_wp_error( $cv_file ) ) {
            wp_send_json_error( array( 'message' => $cv_file->get_error_message() ) );
        }

        // Save application to database
        $inserted = $wpdb->insert( $apps_table, array(
            'job_id'       => $job_id,
            'full_name'    => sanitize_text_field( wp_unslash( $_POST['qwja_full_name'] ) ),
            'email'        => $email,
            'phone'        => sanitize_text_field( wp_unslash( $_POST['qwja_phone'] ?? '' ) ),
            'cover_letter' => sanitize_textarea_field( wp_unslash( $_POST['qwja_cover_letter'] ?? '' ) ),
            'portfolio_url'=> $portfolio_url,
            'cv_file'      => $cv_file,
            'status'       => 'pending',
        ), array( '%d','%s','%s','%s','%s','%s','%s','%s' ) );

        if ( ! $inserted ) {
            // Clean up the orphaned CV file we just wrote.
            $this->cleanup_cv_file( $cv_file );
            $message = 'Could not save your application. Please try again.';
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG && ! empty( $wpdb->last_error ) ) {
                $message .= ' (' . $wpdb->last_error . ')';
            }
            wp_send_json_error( array( 'message' => $message ) );
        }

        $application_id = $wpdb->insert_id;

        // Save screening answers (only those that match configured questions).
        if ( ! empty( $configured_questions ) && ! empty( $submitted_answers ) ) {
            $answers_table = $wpdb->prefix . 'qwja_screening_answers';
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
        try {
            $notifications = new QWJA_Email_Notifications();
            $notifications->notify_admin( $application_id, $job );
            $notifications->notify_applicant( $application_id, $job );
        } catch ( \Throwable $e ) {
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( 'Qadwilliam Jobs & Apply email notification error: ' . $e->getMessage() );
            }
        }

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
        $allowed_mimes = array(
            'pdf'  => 'application/pdf',
            'doc'  => 'application/msword',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        );
        $allowed_ext = array( 'pdf', 'doc', 'docx' );

        $checked = wp_check_filetype_and_ext( $file['tmp_name'], $file['name'], $allowed_mimes );
        $ext     = isset( $checked['ext'] ) ? strtolower( $checked['ext'] ) : '';

        if ( ! $ext || ! in_array( $ext, $allowed_ext, true ) ) {
            return new WP_Error( 'invalid_file', 'Only PDF, DOC, and DOCX files are allowed.' );
        }

        if ( ! empty( $checked['type'] ) && ! in_array( $checked['type'], $allowed_mimes, true ) ) {
            return new WP_Error( 'invalid_file', 'Invalid file type detected.' );
        }

        if ( $file['size'] > 5 * 1024 * 1024 ) {
            return new WP_Error( 'file_too_large', 'CV file must be under 5MB.' );
        }

        if ( ! is_dir( qwja_upload_dir() ) ) {
            qwja_create_upload_dir();
        }

        if ( ! is_dir( qwja_upload_dir() ) || ! wp_is_writable( qwja_upload_dir() ) ) {
            return new WP_Error( 'upload_failed', 'Upload destination is not writable. Please contact the site administrator.' );
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';

        add_filter( 'upload_dir', array( $this, 'filter_cv_upload_dir' ) );

        $uploaded = wp_handle_upload(
            $file,
            array(
                'test_form' => false,
                'mimes'     => $allowed_mimes,
                'unique_filename_callback' => array( $this, 'unique_cv_filename' ),
            )
        );

        remove_filter( 'upload_dir', array( $this, 'filter_cv_upload_dir' ) );

        if ( isset( $uploaded['error'] ) ) {
            return new WP_Error( 'upload_failed', $uploaded['error'] );
        }

        if ( empty( $uploaded['file'] ) ) {
            return new WP_Error( 'upload_failed', 'Could not save your CV. Please try again.' );
        }

        return basename( $uploaded['file'] );
    }

    /**
     * Redirect wp_handle_upload() to the plugin's protected CV directory.
     *
     * @param array $dirs Upload directory paths.
     * @return array
     */
    public function filter_cv_upload_dir( $dirs ) {
        // Derive from the incoming $dirs (the unfiltered upload base) so we never
        // re-enter wp_upload_dir() / the upload_dir filter from inside the filter
        // itself — that recursion exhausted PHP memory and surfaced as HTTP 500.
        $subdir = 'qadwilliam-jobs-apply';

        if ( ! empty( $dirs['basedir'] ) ) {
            $base_path = trailingslashit( $dirs['basedir'] ) . $subdir;
        } else {
            $base_path = trailingslashit( WP_CONTENT_DIR ) . 'uploads/' . $subdir;
        }

        if ( ! empty( $dirs['baseurl'] ) ) {
            $base_url = trailingslashit( $dirs['baseurl'] ) . $subdir;
        } else {
            $base_url = trailingslashit( content_url( 'uploads' ) ) . $subdir;
        }

        $dirs['path']    = $base_path;
        $dirs['url']     = $base_url;
        $dirs['subdir']  = '';
        $dirs['basedir'] = $base_path;
        $dirs['baseurl'] = $base_url;
        return $dirs;
    }

    /**
     * @param string $dir  Upload directory path.
     * @param string $name Original filename.
     * @param string $ext  File extension.
     * @return string
     */
    public function unique_cv_filename( $dir, $name, $ext ) {
        unset( $dir, $name );
        return sanitize_file_name( time() . '_' . wp_generate_password( 8, false ) . $ext );
    }

    private function cleanup_cv_file( $filename ) {
        if ( ! $filename ) {
            return;
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';

        $path = qwja_upload_dir() . basename( $filename );
        if ( file_exists( $path ) ) {
            wp_delete_file( $path );
        }
    }
}
