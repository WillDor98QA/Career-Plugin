<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class CP_Email_Notifications {

    public function notify_admin( $application_id, $job ) {
        $app      = CP_Database::get_application( $application_id );
        $answers  = CP_Database::get_screening_answers( $application_id );
        $admin_email = get_option( 'cp_admin_email', get_option('admin_email') );
        $subject  = '[New Application] ' . $job->post_title . ' — ' . $app->full_name;

        $cv_link = '';
        if ( $app->cv_file ) {
            $cv_link = '<p><strong>CV:</strong> <a href="' . esc_url( admin_url( 'admin-post.php?action=cp_download_cv&id=' . $application_id . '&_wpnonce=' . wp_create_nonce('cp_download_cv') ) ) . '">Download CV</a></p>';
        }

        $screening_html = '';
        if ( $answers ) {
            $screening_html = '<h3 style="color:#333;border-bottom:1px solid #eee;padding-bottom:8px;">Screening Answers</h3>';
            foreach ( $answers as $a ) {
                $screening_html .= '<p><strong>' . esc_html($a->question) . '</strong><br>' . nl2br(esc_html($a->answer)) . '</p>';
            }
        }

        $body = $this->wrap_email( '
            <h2 style="color:#1a1a2e;">New Job Application Received</h2>
            <table style="width:100%;border-collapse:collapse;margin-bottom:20px;">
                <tr><td style="padding:8px;border-bottom:1px solid #f0f0f0;color:#666;width:140px;">Position</td><td style="padding:8px;border-bottom:1px solid #f0f0f0;"><strong>' . esc_html($job->post_title) . '</strong></td></tr>
                <tr><td style="padding:8px;border-bottom:1px solid #f0f0f0;color:#666;">Applicant</td><td style="padding:8px;border-bottom:1px solid #f0f0f0;">' . esc_html($app->full_name) . '</td></tr>
                <tr><td style="padding:8px;border-bottom:1px solid #f0f0f0;color:#666;">Email</td><td style="padding:8px;border-bottom:1px solid #f0f0f0;"><a href="mailto:' . esc_attr($app->email) . '">' . esc_html($app->email) . '</a></td></tr>
                <tr><td style="padding:8px;border-bottom:1px solid #f0f0f0;color:#666;">Phone</td><td style="padding:8px;border-bottom:1px solid #f0f0f0;">' . esc_html($app->phone ?: '—') . '</td></tr>
                <tr><td style="padding:8px;border-bottom:1px solid #f0f0f0;color:#666;">Portfolio</td><td style="padding:8px;border-bottom:1px solid #f0f0f0;">' . ( $app->portfolio_url ? '<a href="' . esc_url($app->portfolio_url) . '">' . esc_html($app->portfolio_url) . '</a>' : '—' ) . '</td></tr>
                <tr><td style="padding:8px;color:#666;">Submitted</td><td style="padding:8px;">' . esc_html($app->submitted_at) . '</td></tr>
            </table>
            ' . ( $app->cover_letter ? '<h3 style="color:#333;border-bottom:1px solid #eee;padding-bottom:8px;">Cover Letter</h3><p>' . nl2br(esc_html($app->cover_letter)) . '</p>' : '' ) . '
            ' . $screening_html . '
            ' . $cv_link . '
            <p style="margin-top:24px;"><a href="' . esc_url( admin_url('admin.php?page=career-portal&action=view&id=' . $application_id) ) . '" style="background:#0073aa;color:#fff;padding:10px 20px;text-decoration:none;border-radius:4px;">View in Dashboard</a></p>
        ' );

        $this->send( $admin_email, $subject, $body );
    }

    public function notify_applicant( $application_id, $job ) {
        $app     = CP_Database::get_application( $application_id );
        $subject = 'We received your application — ' . $job->post_title;
        $company = get_bloginfo('name');

        $body = $this->wrap_email( '
            <h2 style="color:#1a1a2e;">Thank you for applying, ' . esc_html( $this->first_name( $app->full_name ) ) . '!</h2>
            <p>We have received your application for the <strong>' . esc_html($job->post_title) . '</strong> position at <strong>' . esc_html($company) . '</strong>.</p>
            <p>Our team will review your application and get back to you as soon as possible. In the meantime, feel free to reach out if you have any questions.</p>
            <div style="background:#f8f9fa;border-left:4px solid #0073aa;padding:16px;margin:24px 0;border-radius:0 4px 4px 0;">
                <p style="margin:0;color:#555;"><strong>Position:</strong> ' . esc_html($job->post_title) . '<br>
                <strong>Application ID:</strong> #' . (int) $application_id . '<br>
                <strong>Submitted:</strong> ' . esc_html($app->submitted_at) . '</p>
            </div>
            <p>We appreciate your interest and will be in touch soon.</p>
            <p>Best regards,<br><strong>' . esc_html($company) . ' Hiring Team</strong></p>
        ' );

        $this->send( $app->email, $subject, $body );
    }

    public function notify_status_change( $application_id, $new_status ) {
        $app  = CP_Database::get_application( $application_id );
        if ( ! $app ) return;
        $job  = get_post( $app->job_id );

        $status_messages = array(
            'pending'   => 'Your application has been reopened and is pending review.',
            'reviewing' => 'Your application is now under review.',
            'interview' => 'Congratulations! We would like to invite you for an interview. Our team will be in touch shortly with more details.',
            'hired'     => 'We are delighted to offer you the position! Our team will contact you shortly with next steps.',
            'rejected'  => 'After careful consideration, we regret to inform you that we will not be moving forward with your application at this time. We appreciate your interest and encourage you to apply for future openings.',
        );

        if ( ! isset( $status_messages[$new_status] ) ) return;

        $labels = array(
            'pending'   => 'Application Reopened',
            'reviewing' => 'Application Under Review',
            'interview' => 'Interview Invitation',
            'hired'     => 'Offer Extended',
            'rejected'  => 'Application Update',
        );

        $job_title = $job ? $job->post_title : '';
        $subject   = $labels[$new_status] . ( $job_title ? ' — ' . $job_title : '' );

        $body = $this->wrap_email( '
            <h2 style="color:#1a1a2e;">Application Update</h2>
            <p>Hi ' . esc_html( $this->first_name( $app->full_name ) ) . ',</p>
            <p>We have an update regarding your application' . ( $job_title ? ' for <strong>' . esc_html( $job_title ) . '</strong>' : '' ) . '.</p>
            <p>' . esc_html($status_messages[$new_status]) . '</p>
            <p>Best regards,<br><strong>' . esc_html(get_bloginfo('name')) . ' Hiring Team</strong></p>
        ' );

        $this->send( $app->email, $subject, $body );
    }

    private function first_name( $full_name ) {
        $trimmed = trim( (string) $full_name );
        if ( $trimmed === '' ) return '';
        $parts = preg_split( '/\s+/', $trimmed );
        return $parts ? $parts[0] : $trimmed;
    }

    private function wrap_email( $content ) {
        $company = get_bloginfo('name');
        $url     = get_bloginfo('url');
        return '<!DOCTYPE html><html><body style="margin:0;padding:0;background:#f4f4f4;font-family:Arial,sans-serif;">
            <table width="100%" cellpadding="0" cellspacing="0" style="background:#f4f4f4;padding:30px 0;">
                <tr><td align="center">
                    <table width="600" cellpadding="0" cellspacing="0" style="background:#ffffff;border-radius:8px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,0.08);">
                        <tr><td style="background:#0073aa;padding:20px 30px;">
                            <h1 style="color:#fff;margin:0;font-size:20px;">' . esc_html($company) . '</h1>
                        </td></tr>
                        <tr><td style="padding:30px;color:#333;font-size:15px;line-height:1.6;">' . $content . '</td></tr>
                        <tr><td style="background:#f8f9fa;padding:16px 30px;text-align:center;font-size:12px;color:#999;">
                            &copy; ' . date('Y') . ' <a href="' . esc_url($url) . '" style="color:#0073aa;">' . esc_html($company) . '</a>
                        </td></tr>
                    </table>
                </td></tr>
            </table>
        </body></html>';
    }

    /**
     * Backup for plugins that read wp_mail_content_type instead of the header.
     */
    public function set_html_content_type() {
        return 'text/html; charset=UTF-8';
    }

    private function send( $to, $subject, $body ) {
        if ( ! is_email( $to ) ) return false;

        $from_email = $this->resolve_from_email();
        $from_name  = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );

        // Set Content-Type on the headers array directly — this is the most reliable
        // way to get HTML rendering across core wp_mail, PHPMailer, and SMTP plugins.
        $headers = array(
            'Content-Type: text/html; charset=UTF-8',
            'From: ' . $from_name . ' <' . $from_email . '>',
        );

        add_filter( 'wp_mail_content_type', array( $this, 'set_html_content_type' ) );
        $sent = wp_mail( $to, $subject, $body, $headers );
        remove_filter( 'wp_mail_content_type', array( $this, 'set_html_content_type' ) );

        return $sent;
    }

    /**
     * Pick a From address that won't fail SPF/DMARC: prefer the configured
     * notification address only if it shares the site domain, otherwise fall
     * back to the WordPress admin email.
     */
    private function resolve_from_email() {
        $candidate = get_option( 'cp_admin_email', get_option( 'admin_email' ) );
        $site_host = wp_parse_url( home_url(), PHP_URL_HOST );

        if ( $candidate && $site_host ) {
            $candidate_host = substr( strrchr( $candidate, '@' ), 1 );
            if ( $candidate_host && stripos( $site_host, $candidate_host ) !== false ) {
                return $candidate;
            }
        }
        return get_option( 'admin_email' );
    }
}
