<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Qadwilliam Jobs & Apply's own SMTP mailer — sends via PHPMailer directly so other
 * mail plugins (WP Mail SMTP, etc.) are not involved.
 */
class QWJA_Mailer {

    const OPTION_KEY = 'qwja_mail_settings';

    /**
     * Default settings structure.
     */
    public static function defaults() {
        return array(
            'enabled'     => '0',
            'host'        => '',
            'port'        => '587',
            'encryption'  => 'tls',
            'auth'        => '1',
            'username'    => '',
            'password'    => '',
            'from_email'  => '',
            'from_name'   => '',
        );
    }

    public static function get_settings() {
        $saved = get_option( self::OPTION_KEY, array() );
        if ( ! is_array( $saved ) ) {
            $saved = array();
        }
        return wp_parse_args( $saved, self::defaults() );
    }

    public static function is_configured() {
        $s = self::get_settings();
        return $s['enabled'] === '1'
            && $s['host'] !== ''
            && is_email( $s['from_email'] );
    }

    /**
     * Save mail settings from the settings form POST array.
     */
    public static function save_from_post( $post ) {
        $current = self::get_settings();

        $settings = array(
            'enabled'    => ! empty( $post['qwja_mail_enabled'] ) ? '1' : '0',
            'host'       => sanitize_text_field( $post['qwja_mail_host'] ?? '' ),
            'port'       => absint( $post['qwja_mail_port'] ?? 587 ) ?: 587,
            'encryption' => self::sanitize_encryption( $post['qwja_mail_encryption'] ?? 'tls' ),
            'auth'       => ! empty( $post['qwja_mail_auth'] ) ? '1' : '0',
            'username'   => sanitize_text_field( $post['qwja_mail_username'] ?? '' ),
            'from_email' => sanitize_email( $post['qwja_mail_from_email'] ?? '' ),
            'from_name'  => sanitize_text_field( $post['qwja_mail_from_name'] ?? '' ),
        );

        $new_password = isset( $post['qwja_mail_password'] ) ? (string) wp_unslash( $post['qwja_mail_password'] ) : '';
        if ( $new_password !== '' ) {
            $settings['password'] = self::encrypt_password( $new_password );
        } else {
            $settings['password'] = $current['password'];
        }

        update_option( self::OPTION_KEY, $settings );
        return $settings;
    }

    private static function sanitize_encryption( $value ) {
        $allowed = array( 'none', 'ssl', 'tls' );
        return in_array( $value, $allowed, true ) ? $value : 'tls';
    }

    /**
     * Send an HTML email using the plugin's SMTP configuration.
     *
     * @return true|WP_Error
     */
    public static function send( $to, $subject, $html_body ) {
        if ( ! is_email( $to ) ) {
            return new WP_Error( 'qwja_mail_invalid_to', 'Invalid recipient email address.' );
        }

        if ( ! self::is_configured() ) {
            return new WP_Error(
                'qwja_mail_not_configured',
                'Qadwilliam Jobs & Apply mail is not configured. Go to Qadwilliam Jobs & Apply → Settings and set up SMTP.'
            );
        }

        $settings = self::get_settings();
        $mail     = self::create_phpmailer();

        try {
            self::apply_smtp_config( $mail, $settings );

            $from_email = $settings['from_email'];
            $from_name  = $settings['from_name'] !== ''
                ? $settings['from_name']
                : wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );

            $mail->setFrom( $from_email, $from_name );
            $mail->addAddress( $to );
            $mail->Subject = $subject;
            $mail->isHTML( true );
            $mail->CharSet = 'UTF-8';
            $mail->Body    = $html_body;
            $mail->AltBody = wp_strip_all_tags( $html_body );

            $mail->send();
            return true;
        } catch ( Exception $e ) {
            return new WP_Error( 'qwja_mail_send_failed', $e->getMessage() );
        }
    }

    /**
     * Send a test message to verify SMTP settings.
     *
     * @return true|WP_Error
     */
    public static function send_test( $to ) {
        $site = get_bloginfo( 'name' );
        $body = '<!DOCTYPE html><html><body style="font-family:Arial,sans-serif;padding:24px;">
            <h2 style="color:#0073aa;">Qadwilliam Jobs & Apply — Test Email</h2>
            <p>If you are reading this, your Qadwilliam Jobs & Apply SMTP settings are working correctly.</p>
            <p style="color:#666;font-size:13px;">Sent from <strong>' . esc_html( $site ) . '</strong> at ' . esc_html( current_time( 'mysql' ) ) . '</p>
        </body></html>';

        return self::send(
            $to,
            '[' . $site . '] Qadwilliam Jobs & Apply SMTP test',
            $body
        );
    }

    public static function get_from_email() {
        $s = self::get_settings();
        if ( self::is_configured() && is_email( $s['from_email'] ) ) {
            return $s['from_email'];
        }
        return get_option( 'admin_email' );
    }

    public static function get_from_name() {
        $s = self::get_settings();
        if ( $s['from_name'] !== '' ) {
            return $s['from_name'];
        }
        return wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
    }

    private static function create_phpmailer() {
        if ( ! class_exists( 'PHPMailer\PHPMailer\PHPMailer', false ) ) {
            require_once ABSPATH . WPINC . '/PHPMailer/PHPMailer.php';
            require_once ABSPATH . WPINC . '/PHPMailer/SMTP.php';
            require_once ABSPATH . WPINC . '/PHPMailer/Exception.php';
        }

        return new PHPMailer\PHPMailer\PHPMailer( true );
    }

    private static function apply_smtp_config( PHPMailer\PHPMailer\PHPMailer $mail, array $settings ) {
        $mail->isSMTP();
        $mail->Host       = $settings['host'];
        $mail->Port       = (int) $settings['port'];
        $mail->SMTPAuth   = $settings['auth'] === '1';

        if ( $settings['encryption'] === 'ssl' ) {
            $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
        } elseif ( $settings['encryption'] === 'tls' ) {
            $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
        } else {
            $mail->SMTPSecure  = '';
            $mail->SMTPAutoTLS = false;
        }

        if ( $mail->SMTPAuth ) {
            $mail->Username = $settings['username'];
            $mail->Password = self::decrypt_password( $settings['password'] );
        }

        // Reasonable timeout for shared hosting.
        $mail->Timeout = 30;
    }

    private static function encrypt_password( $plain ) {
        if ( $plain === '' ) {
            return '';
        }
        if ( ! function_exists( 'openssl_encrypt' ) ) {
            return base64_encode( $plain );
        }
        $key   = hash( 'sha256', wp_salt( 'auth' ), true );
        $iv    = openssl_random_pseudo_bytes( 16 );
        $enc   = openssl_encrypt( $plain, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv );
        return base64_encode( $iv . $enc );
    }

    private static function decrypt_password( $stored ) {
        if ( $stored === '' ) {
            return '';
        }
        if ( ! function_exists( 'openssl_decrypt' ) ) {
            return base64_decode( $stored, true ) ?: '';
        }
        $raw = base64_decode( $stored, true );
        if ( $raw === false || strlen( $raw ) < 17 ) {
            return base64_decode( $stored, true ) ?: '';
        }
        $iv  = substr( $raw, 0, 16 );
        $enc = substr( $raw, 16 );
        $key = hash( 'sha256', wp_salt( 'auth' ), true );
        $dec = openssl_decrypt( $enc, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv );
        return $dec !== false ? $dec : '';
    }
}
