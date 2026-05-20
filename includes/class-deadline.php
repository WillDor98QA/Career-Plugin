<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Application deadline parsing, display, and expiry checks (site timezone).
 */
class QWJA_Deadline {

    const META_KEY = '_qwja_deadline';

    /**
     * Whether the job's application window has closed.
     */
    public static function is_expired( $job_id ) {
        $deadline = get_post_meta( $job_id, self::META_KEY, true );
        if ( $deadline === '' || $deadline === null ) {
            return false;
        }

        $deadline_ts = self::to_timestamp( $deadline );
        if ( $deadline_ts === null ) {
            return false;
        }

        return $deadline_ts < current_time( 'timestamp' );
    }

    /**
     * Convert stored meta to Unix timestamp (WordPress site timezone).
     *
     * @return int|null Null if empty/invalid.
     */
    public static function to_timestamp( $stored ) {
        $stored = trim( (string) $stored );
        if ( $stored === '' ) {
            return null;
        }

        // Legacy date-only values: close at end of that calendar day.
        if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $stored ) ) {
            $stored .= ' 23:59:59';
        }

        // Normalise datetime-local "T" separator if present in DB.
        $stored = str_replace( 'T', ' ', $stored );

        // Add seconds when missing (Y-m-d H:i).
        if ( preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $stored ) ) {
            $stored .= ':00';
        }

        $tz = wp_timezone();
        $dt = date_create( $stored, $tz );
        if ( ! $dt ) {
            return null;
        }

        return $dt->getTimestamp();
    }

    /**
     * Human-readable deadline for the frontend.
     */
    public static function format_display( $stored ) {
        $ts = self::to_timestamp( $stored );
        if ( $ts === null ) {
            return '';
        }

        $stored = trim( (string) $stored );
        $has_time = (bool) preg_match( '/\d{2}:\d{2}/', str_replace( 'T', ' ', $stored ) )
            && ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $stored );

        if ( $has_time ) {
            return date_i18n( 'M j, Y g:i A', $ts );
        }

        return date_i18n( 'M j, Y', $ts );
    }

    /**
     * Value for HTML datetime-local input (Y-m-d\TH:i).
     */
    public static function value_for_input( $stored ) {
        $stored = trim( (string) $stored );
        if ( $stored === '' ) {
            return '';
        }

        $ts = self::to_timestamp( $stored );
        if ( $ts === null ) {
            return '';
        }

        $tz = wp_timezone();
        $dt = new DateTime( '@' . $ts );
        $dt->setTimezone( $tz );

        return $dt->format( 'Y-m-d\TH:i' );
    }

    /**
     * Sanitize admin input (datetime-local) for storage as Y-m-d H:i:s.
     */
    public static function sanitize_input( $raw ) {
        $raw = trim( sanitize_text_field( wp_unslash( $raw ) ) );
        if ( $raw === '' ) {
            return '';
        }

        $raw = str_replace( 'T', ' ', $raw );

        if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $raw ) ) {
            $raw .= ' 23:59:59';
        } elseif ( preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $raw ) ) {
            $raw .= ':00';
        }

        $tz = wp_timezone();
        $dt = date_create( $raw, $tz );
        if ( ! $dt ) {
            return '';
        }

        return $dt->format( 'Y-m-d H:i:s' );
    }
}
