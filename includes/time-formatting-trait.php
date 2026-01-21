<?php
/**
 * Time/date formatting helpers for WIW Timesheets.
 *
 * This file is intentionally small to support incremental refactors.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

trait WIWTS_Time_Formatting_Trait {

    private function wiw_format_time_local( $datetime_str ) {
        $datetime_str = is_scalar( $datetime_str ) ? trim( (string) $datetime_str ) : '';
        if ( $datetime_str === '' ) {
            return '';
        }

        try {
            $wp_timezone_string = get_option( 'timezone_string' );
            if ( empty( $wp_timezone_string ) ) {
                $wp_timezone_string = 'UTC';
            }
            $wp_tz = new DateTimeZone( $wp_timezone_string );

            // Stored values in your local tables are already local DATETIME (no TZ info).
            $dt = new DateTime( $datetime_str, $wp_tz );
            $dt->setTimezone( $wp_tz );

            $time_format = get_option( 'time_format' );
            if ( empty( $time_format ) ) {
                $time_format = 'g:i A';
            }

            return $dt->format( $time_format );
        } catch ( Exception $e ) {
            return '';
        }
    }

    private function wiw_format_time_range_local( $start_datetime, $end_datetime ) {
        $start = $this->wiw_format_time_local( $start_datetime );
        $end   = $this->wiw_format_time_local( $end_datetime );

        if ( $start !== '' && $end !== '' ) {
            return $start . ' - ' . $end;
        }

        if ( $start !== '' ) {
            return $start;
        }

        return '';
    }

    private function wiw_format_datetime_local_pretty( $datetime_str ) {
        $datetime_str = is_scalar( $datetime_str ) ? trim( (string) $datetime_str ) : '';
        if ( $datetime_str === '' ) {
            return 'N/A';
        }

        try {
            $wp_timezone_string = get_option( 'timezone_string' );
            if ( empty( $wp_timezone_string ) ) {
                $wp_timezone_string = 'UTC';
            }
            $wp_tz = new DateTimeZone( $wp_timezone_string );

            // Stored values are local DATETIME in DB (no TZ info), treat as WP local.
            $dt = new DateTime( $datetime_str, $wp_tz );
            $dt->setTimezone( $wp_tz );

            $date_format = get_option( 'date_format' );
            if ( empty( $date_format ) ) {
                $date_format = 'M j, Y';
            }

            $time_format = get_option( 'time_format' );
            if ( empty( $time_format ) ) {
                $time_format = 'g:i A';
            }

            return $dt->format( $date_format . ' ' . $time_format );
        } catch ( Exception $e ) {
            return 'N/A';
        }
    }

    private function normalize_datetime_to_minute( $datetime ) {
        $datetime = is_string( $datetime ) ? trim( $datetime ) : '';
        if ( $datetime === '' ) {
            return '';
        }

        try {
            $wp_timezone_string = get_option( 'timezone_string' );
            if ( empty( $wp_timezone_string ) ) {
                $wp_timezone_string = 'UTC';
            }
            $wp_tz = new DateTimeZone( $wp_timezone_string );

            $dt = new DateTime( $datetime, $wp_tz );
            $dt->setTimezone( $wp_tz );

            return $dt->format( 'Y-m-d H:i:00' );
        } catch ( Exception $e ) {
            return '';
        }
    }
}
