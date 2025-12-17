<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Helper methods for timesheet calculations and grouping.
 *
 * Pure logic only — no hooks, no output, no side effects.
 */

if ( ! class_exists( 'WIW_Timesheet_Manager' ) ) {
    return;
}

trait WIW_Timesheet_Helpers_Trait {

    private function calculate_shift_duration_in_hours( $shift_entry ) {
        $start_time_utc = $shift_entry->start_time ?? '';
        $end_time_utc   = $shift_entry->end_time ?? '';
        $break_minutes = $shift_entry->break ?? 0;

        $duration = 0.0;

        try {
            if ( $start_time_utc && $end_time_utc ) {
                $dt_start = new DateTime( $start_time_utc, new DateTimeZone( 'UTC' ) );
                $dt_end   = new DateTime( $end_time_utc,   new DateTimeZone( 'UTC' ) );

                $interval = $dt_start->diff( $dt_end );
                $seconds  =
                    ( $interval->days * 86400 ) +
                    ( $interval->h * 3600 ) +
                    ( $interval->i * 60 ) +
                    $interval->s;

                $seconds -= ( (int) $break_minutes * 60 );

                $duration = round( max( 0, $seconds ) / 3600, 2 );
            }
        } catch ( Exception $e ) {
            // Leave duration at 0.0
        }

        return $duration;
    }

    private function calculate_timesheet_duration_in_hours( $time_entry ) {
        $duration = round( ( $time_entry->length ?? 0 ), 2 );

        if ( $duration == 0 && isset( $time_entry->duration ) ) {
            $duration = round( ( $time_entry->duration / 3600 ), 2 );
        }

        return max( 0.0, $duration );
    }

    private function sort_timesheet_data( $times, $user_map ) {
        usort( $times, function ( $a, $b ) use ( $user_map ) {
            $id_a = $a->user_id ?? 0;
            $id_b = $b->user_id ?? 0;

            $name_a = trim(
                ( $user_map[ $id_a ]->first_name ?? '' ) . ' ' .
                ( $user_map[ $id_a ]->last_name ?? 'Unknown' )
            );

            $name_b = trim(
                ( $user_map[ $id_b ]->first_name ?? '' ) . ' ' .
                ( $user_map[ $id_b ]->last_name ?? 'Unknown' )
            );

            $cmp = strcasecmp( $name_a, $name_b );
            if ( $cmp !== 0 ) {
                return $cmp;
            }

            return strtotime( $a->start_time ?? '' ) - strtotime( $b->start_time ?? '' );
        } );

        return $times;
    }

    private function group_timesheet_by_pay_period( $times, $user_map ) {
        $grouped = [];

        $tz_string = get_option( 'timezone_string' ) ?: 'UTC';
        $wp_tz     = new DateTimeZone( $tz_string );

        foreach ( $times as $time_entry ) {
            $user_id = $time_entry->user_id ?? 0;
            $user    = $user_map[ $user_id ] ?? null;

            if ( ! $user ) {
                continue;
            }

            $employee_name = trim(
                ( $user->first_name ?? '' ) . ' ' .
                ( $user->last_name ?? 'Unknown' )
            );

            $start_utc = $time_entry->start_time ?? '';
            if ( ! $start_utc ) {
                continue;
            }

            try {
                $dt = new DateTime( $start_utc, new DateTimeZone( 'UTC' ) );
                $dt->setTimezone( $wp_tz );

                $dayN = (int) $dt->format( 'N' );
                $days = ( $dayN <= 5 ) ? -( $dayN - 1 ) : ( 8 - $dayN );

                if ( $days !== 0 ) {
                    $dt->modify( "{$days} days" );
                }

                $week_start = $dt->format( 'Y-m-d' );
            } catch ( Exception $e ) {
                continue;
            }

            if ( ! isset( $grouped[ $employee_name ] ) ) {
                $grouped[ $employee_name ] = [];
            }

            if ( ! isset( $grouped[ $employee_name ][ $week_start ] ) ) {
                $grouped[ $employee_name ][ $week_start ] = [
                    'total_clocked_hours'   => 0.0,
                    'total_scheduled_hours' => 0.0,
                    'records'               => [],
                ];
            }

            $grouped[ $employee_name ][ $week_start ]['total_clocked_hours']
                += (float) ( $time_entry->calculated_duration ?? 0.0 );

            $grouped[ $employee_name ][ $week_start ]['total_scheduled_hours']
                += (float) ( $time_entry->scheduled_duration ?? 0.0 );

            $grouped[ $employee_name ][ $week_start ]['records'][] = $time_entry;
        }

        return $grouped;
    }
}
