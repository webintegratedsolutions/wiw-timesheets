<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'WIW_Timesheet_Manager' ) ) {
    return;
}

trait WIW_Timesheet_Sync_Trait {

    /**
     * Sync API times into local DB.
     *
     * @param array $times
     * @param array $user_map
     * @param DateTimeZone $wp_timezone
     * @param array $shift_map Optional: map of shift_id => shift object (from API includes)
     */
    private function sync_timesheets_to_local_db( $times, $user_map, $wp_timezone, $shift_map = [] ) {
        global $wpdb;

        if ( empty( $times ) ) {
            return;
        }

        $table_timesheets        = $wpdb->prefix . 'wiw_timesheets';
        $table_timesheet_entries = $wpdb->prefix . 'wiw_timesheet_entries';

        $grouped = [];

        /**
         * Normalize API break into MINUTES.
         * - Prefer "break" if present (often minutes).
         * - Otherwise if "break_hours" exists (hours float), convert to minutes.
         */
        $get_break_minutes_from_api = function( $time_entry ) {
            if ( isset( $time_entry->break ) && $time_entry->break !== null && $time_entry->break !== '' ) {
                return (int) $time_entry->break;
            }

            if ( isset( $time_entry->break_hours ) && is_numeric( $time_entry->break_hours ) ) {
                return (int) round( (float) $time_entry->break_hours * 60 );
            }

            return 0;
        };

        /**
         * LOCAL helper: compute adjusted clocked hours for local storage.
         * Uses start/end timestamps and subtracts ENFORCED break minutes.
         * Falls back to API-calculated duration if end time is missing/unparseable.
         */
        $compute_local_clocked_hours = function( $start_raw, $end_raw, $enforced_break_minutes, $fallback_hours ) use ( $wp_timezone ) {
            $fallback_hours = (float) $fallback_hours;

            $start_raw = (string) $start_raw;
            $end_raw   = (string) $end_raw;

            if ( $start_raw === '' || $end_raw === '' ) {
                return round( max( 0.0, $fallback_hours ), 2 );
            }

            try {
                $dt_in  = new DateTime( $start_raw );
                $dt_out = new DateTime( $end_raw );

                $dt_in->setTimezone( $wp_timezone );
                $dt_out->setTimezone( $wp_timezone );

                if ( $dt_out <= $dt_in ) {
                    return round( max( 0.0, $fallback_hours ), 2 );
                }

                $interval = $dt_in->diff( $dt_out );
                $seconds  = ( $interval->days * 86400 ) + ( $interval->h * 3600 ) + ( $interval->i * 60 ) + $interval->s;

                $seconds -= ( (int) $enforced_break_minutes * 60 );
                if ( $seconds < 0 ) {
                    $seconds = 0;
                }

                return round( $seconds / 3600, 2 );
            } catch ( Exception $e ) {
                return round( max( 0.0, $fallback_hours ), 2 );
            }
        };

        foreach ( $times as $time_entry ) {
            $user_id = isset( $time_entry->user_id ) ? (int) $time_entry->user_id : 0;
            if ( ! $user_id || ! isset( $user_map[ $user_id ] ) ) {
                continue;
            }

            $user          = $user_map[ $user_id ];
            $employee_name = trim(
                ( $user->first_name ?? '' ) . ' ' .
                ( $user->last_name ?? 'Unknown' )
            );

            $start_time_raw = $time_entry->start_time ?? '';
            if ( empty( $start_time_raw ) ) {
                continue;
            }

            $location_id   = (int) ( $time_entry->location_id ?? 0 );
            $location_name = (string) ( $time_entry->location_name ?? '' );

            // Parse start time and use local date for grouping.
            try {
                $dt_local = new DateTime( $start_time_raw );
                $dt_local->setTimezone( $wp_timezone );

                $dayN = (int) $dt_local->format( 'N' ); // 1=Mon..7=Sun
                $days = ( $dayN <= 5 ) ? -( $dayN - 1 ) : ( 8 - $dayN );
                if ( $days !== 0 ) {
                    $dt_local->modify( "{$days} days" );
                }

                $week_start = $dt_local->format( 'Y-m-d' );
            } catch ( Exception $e ) {
                continue;
            }

            $key = "{$user_id}|{$week_start}|{$location_id}";

            if ( ! isset( $grouped[ $key ] ) ) {
                $grouped[ $key ] = [
                    'employee_id'           => $user_id,
                    'employee_name'         => $employee_name,
                    'location_id'           => $location_id,
                    'location_name'         => $location_name,
                    'week_start_date'       => $week_start,
                    'records'               => [],
                    'total_clocked_hours'   => 0.0,
                    'total_scheduled_hours' => 0.0,
                ];
            }

            // ---------------- LOCAL-ONLY BREAK RULE ----------------
            $scheduled_hours = (float) ( $time_entry->scheduled_duration ?? 0.0 );
            if ( $scheduled_hours <= 0 ) {
                $scheduled_hours = (float) ( $time_entry->calculated_duration ?? 0.0 );
            }

            $break_api_minutes = $get_break_minutes_from_api( $time_entry );

            // If Sched. Hrs exceed 5, break is EXACTLY 60.
            $break_enforced = ( $scheduled_hours > 5.0 ) ? 60 : (int) $break_api_minutes;

            $fallback_clocked = (float) ( $time_entry->calculated_duration ?? 0.0 );
            $adjusted_clocked = $compute_local_clocked_hours(
                (string) ( $time_entry->start_time ?? '' ),
                (string) ( $time_entry->end_time ?? '' ),
                $break_enforced,
                $fallback_clocked
            );

            $grouped[ $key ]['total_clocked_hours']   += $adjusted_clocked;
            $grouped[ $key ]['total_scheduled_hours'] += (float) ( $time_entry->scheduled_duration ?? 0.0 );

            $time_entry->_wiw_local_break_minutes = $break_enforced;
            $time_entry->_wiw_local_clocked_hours = $adjusted_clocked;
            // -------------------------------------------------------

            $grouped[ $key ]['records'][] = $time_entry;
        }

        if ( empty( $grouped ) ) {
            return;
        }

        $now = current_time( 'mysql' );

        foreach ( $grouped as $bundle ) {
            $employee_id     = $bundle['employee_id'];
            $employee_name   = $bundle['employee_name'];
            $location_id     = $bundle['location_id'];
            $location_name   = $bundle['location_name'];
            $week_start_date = $bundle['week_start_date'];
            $week_end_date   = date( 'Y-m-d', strtotime( $week_start_date . ' +4 days' ) );

            $total_clocked_hours   = round( (float) $bundle['total_clocked_hours'], 2 );
            $total_scheduled_hours = round( (float) $bundle['total_scheduled_hours'], 2 );

            $header_id = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT id FROM {$table_timesheets}
                     WHERE employee_id = %d AND week_start_date = %s AND location_id = %d",
                    $employee_id,
                    $week_start_date,
                    $location_id
                )
            );

            $header_data = [
                'employee_id'           => $employee_id,
                'employee_name'         => $employee_name,
                'location_id'           => $location_id,
                'location_name'         => $location_name,
                'week_start_date'       => $week_start_date,
                'week_end_date'         => $week_end_date,
                'total_scheduled_hours' => $total_scheduled_hours,
                'total_clocked_hours'   => $total_clocked_hours,
                'updated_at'            => $now,
            ];

            if ( $header_id ) {
                $wpdb->update(
                    $table_timesheets,
                    $header_data,
                    [ 'id' => $header_id ]
                );
            } else {
                $header_data['status']     = 'pending';
                $header_data['created_at'] = $now;

                $wpdb->insert( $table_timesheets, $header_data );
                $header_id = (int) $wpdb->insert_id;
            }

            if ( ! $header_id ) {
                continue;
            }

            foreach ( $bundle['records'] as $time_entry ) {
                $wiw_time_id = (int) ( $time_entry->id ?? 0 );
                if ( ! $wiw_time_id ) {
                    continue;
                }

                $entry_id = $wpdb->get_var(
                    $wpdb->prepare(
                        "SELECT id FROM {$table_timesheet_entries} WHERE wiw_time_id = %d",
                        $wiw_time_id
                    )
                );

                $clock_in_local  = null;
                $clock_out_local = null;
                $entry_date      = null;

                try {
                    $dt_in = new DateTime( (string) ( $time_entry->start_time ?? '' ) );
                    $dt_in->setTimezone( $wp_timezone );
                    $clock_in_local = $dt_in->format( 'Y-m-d H:i:s' );
                    $entry_date     = $dt_in->format( 'Y-m-d' );
                } catch ( Exception $e ) {
                    continue;
                }

                $end_raw = (string) ( $time_entry->end_time ?? '' );
                if ( $end_raw !== '' ) {
                    try {
                        $dt_out = new DateTime( $end_raw );
                        $dt_out->setTimezone( $wp_timezone );
                        $clock_out_local = $dt_out->format( 'Y-m-d H:i:s' );
                    } catch ( Exception $e ) {
                        $clock_out_local = null;
                    }
                }

                // ✅ Scheduled start/end from SHIFT (this is the real fix)
                $scheduled_start_local = null;
                $scheduled_end_local   = null;

                $shift_id = (int) ( $time_entry->shift_id ?? 0 );
                $shift    = ( $shift_id && isset( $shift_map[ $shift_id ] ) ) ? $shift_map[ $shift_id ] : null;

                if ( $shift ) {
                    $shift_start_raw = (string) ( $shift->start_time ?? '' );
                    $shift_end_raw   = (string) ( $shift->end_time ?? '' );

                    if ( $shift_start_raw !== '' ) {
                        try {
                            $dt_sched_start = new DateTime( $shift_start_raw );
                            $dt_sched_start->setTimezone( $wp_timezone );
                            $scheduled_start_local = $dt_sched_start->format( 'Y-m-d H:i:s' );
                        } catch ( Exception $e ) {
                            $scheduled_start_local = null;
                        }
                    }

                    if ( $shift_end_raw !== '' ) {
                        try {
                            $dt_sched_end = new DateTime( $shift_end_raw );
                            $dt_sched_end->setTimezone( $wp_timezone );
                            $scheduled_end_local = $dt_sched_end->format( 'Y-m-d H:i:s' );
                        } catch ( Exception $e ) {
                            $scheduled_end_local = null;
                        }
                    }
                }

                $break_minutes_local = isset( $time_entry->_wiw_local_break_minutes )
                    ? (int) $time_entry->_wiw_local_break_minutes
                    : 0;

                $clocked_hours_local = isset( $time_entry->_wiw_local_clocked_hours )
                    ? (float) $time_entry->_wiw_local_clocked_hours
                    : round( (float) ( $time_entry->calculated_duration ?? 0.0 ), 2 );

                $entry_data = [
                    'timesheet_id'    => $header_id,
                    'wiw_time_id'     => $wiw_time_id,
                    'wiw_shift_id'    => (int) ( $time_entry->shift_id ?? 0 ),
                    'date'            => $entry_date,
                    'location_id'     => (int) ( $time_entry->location_id ?? 0 ),
                    'location_name'   => (string) ( $time_entry->location_name ?? '' ),
                    'clock_in'        => $clock_in_local,
                    'clock_out'       => $clock_out_local,

                    // ✅ DB columns you already added
                    'scheduled_start' => $scheduled_start_local,
                    'scheduled_end'   => $scheduled_end_local,

                    'break_minutes'   => (int) $break_minutes_local,
                    'scheduled_hours' => round( (float) ( $time_entry->scheduled_duration ?? 0.0 ), 2 ),
                    'clocked_hours'   => round( $clocked_hours_local, 2 ),

                    'status'          => 'pending',
                    'updated_at'      => $now,
                ];

                if ( $entry_id ) {
                    $wpdb->update(
                        $table_timesheet_entries,
                        $entry_data,
                        [ 'id' => $entry_id ]
                    );
                } else {
                    $entry_data['created_at'] = $now;
                    $wpdb->insert( $table_timesheet_entries, $entry_data );
                }
            }
        }
    }
}
