<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'WIW_Timesheet_Manager' ) ) {
    return;
}

trait WIW_Timesheet_Sync_Trait {

    // Store time flags for a given time entry.
private function wiwts_sync_store_time_flags( $wiw_time_id, $clock_in_local, $clock_out_local, $scheduled_start_local, $scheduled_end_local, $tz, $scheduled_hours_local = null, $payable_hours_local = null ) {
    global $wpdb;

    $wiw_time_id = (int) $wiw_time_id;
    if ( ! $wiw_time_id ) {
        return;
    }

    $table_flags = $wpdb->prefix . 'wiw_timesheet_flags';

    // Normalize inputs
    $clock_in_local        = is_string( $clock_in_local ) ? trim( $clock_in_local ) : '';
    $clock_out_local       = is_string( $clock_out_local ) ? trim( $clock_out_local ) : '';
    $scheduled_start_local = is_string( $scheduled_start_local ) ? trim( $scheduled_start_local ) : '';
    $scheduled_end_local   = is_string( $scheduled_end_local ) ? trim( $scheduled_end_local ) : '';

    // Optional: compare scheduled vs payable hours (used by flag 109)
    $scheduled_hours_local = ( $scheduled_hours_local !== null ) ? (float) $scheduled_hours_local : null;
    $payable_hours_local   = ( $payable_hours_local !== null ) ? (float) $payable_hours_local : null;

    // Compute which flags SHOULD be active right now (by type).
    $active_flags = array(); // ['101' => 'desc', ...]

    // Missing checks first
    if ( $clock_in_local === '' ) {
        $active_flags['105'] = 'Missing clock-in time';
    }
    if ( $clock_out_local === '' ) {
        $active_flags['106'] = 'Missing clock-out time';
    }

    // Scheduled vs Payable mismatch (rounded to 2 decimals)
    if ( $scheduled_hours_local !== null && $payable_hours_local !== null ) {
        $s = round( (float) $scheduled_hours_local, 2 );
        $p = round( (float) $payable_hours_local, 2 );
        if ( $s !== $p ) {
            $active_flags['109'] = 'Scheduled Hours do not match Payable Hours';
        }
    }

    // Only run early/late logic when scheduled bounds exist AND relevant clock value exists.
    try {
        $dt_sched_start = ( $scheduled_start_local !== '' ) ? new DateTime( $scheduled_start_local, $tz ) : null;
        $dt_sched_end   = ( $scheduled_end_local !== '' )   ? new DateTime( $scheduled_end_local,   $tz ) : null;

        $dt_clock_in  = ( $clock_in_local !== '' )  ? new DateTime( $clock_in_local,  $tz ) : null;
        $dt_clock_out = ( $clock_out_local !== '' ) ? new DateTime( $clock_out_local, $tz ) : null;

        // 101 / 103 (clock-in relative to scheduled start)
        if ( $dt_sched_start && $dt_clock_in ) {
            $sched_start_ts = $dt_sched_start->getTimestamp();
            $clock_in_ts    = $dt_clock_in->getTimestamp();

            // 101: more than 15 minutes early
            if ( $clock_in_ts < ( $sched_start_ts - ( 15 * 60 ) ) ) {
                $active_flags['101'] = 'Clocked in more than 15 minutes before scheduled start';
            }

            // 107 / 103: clock-in after scheduled start (minute precision)
            // Normalize both to minute precision so exact matches don't false-flag due to seconds.
            $sched_start_ts = $sched_start_ts - ( $sched_start_ts % 60 );
            $clock_in_ts    = $clock_in_ts - ( $clock_in_ts % 60 );

            // 107: late, but 15 minutes or less
            if ( $clock_in_ts > $sched_start_ts && $clock_in_ts <= ( $sched_start_ts + ( 15 * 60 ) ) ) {
                $active_flags['107'] = 'Clocked in less than 15 minutes after scheduled start';
            }

            // 103: more than 15 minutes late
            if ( $clock_in_ts > ( $sched_start_ts + ( 15 * 60 ) ) ) {
                $active_flags['103'] = 'Clocked in more than 15 minutes after scheduled start';
            }

        }

        // 102 / 104 (clock-out relative to scheduled end)
        if ( $dt_sched_end && $dt_clock_out ) {
            $sched_end_ts = $dt_sched_end->getTimestamp();
            $clock_out_ts = $dt_clock_out->getTimestamp();

            // 102: clocked out before scheduled end
            if ( $clock_out_ts < $sched_end_ts ) {
                $active_flags['102'] = 'Clocked out before scheduled end';
            }

            // 104: more than 15 minutes late
            if ( $clock_out_ts > ( $sched_end_ts + ( 15 * 60 ) ) ) {
                $active_flags['104'] = 'Clocked out more than 15 minutes after scheduled end';
            }
        }
    } catch ( Exception $e ) {
        // If parsing fails, do not add early/late flags.
    }

    $now = current_time( 'mysql' );

    // Load existing flags for this time id.
    $existing = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT id, flag_type, flag_status
             FROM {$table_flags}
             WHERE wiw_time_id = %d",
            $wiw_time_id
        )
    );

    $existing_by_type = array(); // ['101' => row]
    foreach ( (array) $existing as $row ) {
        $t = isset( $row->flag_type ) ? (string) $row->flag_type : '';
        if ( $t !== '' ) {
            $existing_by_type[ $t ] = $row;
        }
    }

    // 1) Update existing flags: set active/resolved as needed (created_at unchanged).
    foreach ( $existing_by_type as $type => $row ) {
        $should_be_active = array_key_exists( (string) $type, $active_flags );

        $new_status = $should_be_active ? 'active' : 'resolved';
        $new_desc   = $should_be_active ? (string) $active_flags[ (string) $type ] : null;

        $data = array(
            'flag_status' => $new_status,
            'updated_at'  => $now,
        );
        $formats = array( '%s', '%s' );

        // If active, update description too (keeps wording aligned to current rules).
        if ( $new_desc !== null ) {
            $data['description'] = $new_desc;
            $formats[] = '%s';
            // Put description first for nicer SQL order (optional)
            $data = array(
                'description' => $new_desc,
                'flag_status' => $new_status,
                'updated_at'  => $now,
            );
            $formats = array( '%s', '%s', '%s' );
        }

        $wpdb->update(
            $table_flags,
            $data,
            array( 'id' => (int) $row->id ),
            $formats,
            array( '%d' )
        );
    }

    // 2) Insert any new flags that should be active but don't exist yet.
    foreach ( $active_flags as $type => $desc ) {
        if ( isset( $existing_by_type[ (string) $type ] ) ) {
            continue;
        }

        $wpdb->insert(
            $table_flags,
            array(
                'wiw_time_id' => $wiw_time_id,
                'flag_type'   => (string) $type,
                'description' => (string) $desc,
                'flag_status' => 'active',
                'created_at'  => $now,
                'updated_at'  => $now,
            ),
            array( '%d', '%s', '%s', '%s', '%s', '%s' )
        );
    }
}

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
            if ( property_exists( $time_entry, 'break' ) && $time_entry->break !== null && $time_entry->break !== '' ) {
                $break_minutes = (int) $time_entry->break;
                if ( $break_minutes > 0 ) {
                    return $break_minutes;
                }
                return null;
            }

            if ( property_exists( $time_entry, 'break_hours' ) && is_numeric( $time_entry->break_hours ) ) {
                $break_hours = (float) $time_entry->break_hours;
                if ( $break_hours > 0 ) {
                    return (int) round( $break_hours * 60 );
                }
                return null;
            }

            return null;
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

        /**
         * LOCAL helper: compute payable hours (clamped to scheduled_start/end when present).
         * If scheduled bounds are missing, falls back to clocked.
         */
        $compute_local_payable_hours = function(
            $clock_in_local,
            $clock_out_local,
            $scheduled_start_local,
            $scheduled_end_local,
            $break_minutes,
            $fallback_hours
        ) use ( $wp_timezone ) {
            $fallback_hours = (float) $fallback_hours;

            // If no actual clock range, fallback.
            if ( empty( $clock_in_local ) || empty( $clock_out_local ) ) {
                return round( max( 0.0, $fallback_hours ), 2 );
            }

            try {
                $dt_in  = new DateTime( (string) $clock_in_local );
                $dt_out = new DateTime( (string) $clock_out_local );

                $dt_in->setTimezone( $wp_timezone );
                $dt_out->setTimezone( $wp_timezone );

                if ( $dt_out <= $dt_in ) {
                    return round( max( 0.0, $fallback_hours ), 2 );
                }

                // Clamp payable start to scheduled_start if clock-in is earlier.
                if ( ! empty( $scheduled_start_local ) ) {
                    try {
                        $dt_sched_start = new DateTime( (string) $scheduled_start_local );
                        $dt_sched_start->setTimezone( $wp_timezone );
                        if ( $dt_in < $dt_sched_start ) {
                            $dt_in = $dt_sched_start;
                        }
                    } catch ( Exception $e ) {
                        // ignore clamp if schedule parse fails
                    }
                }

                // Clamp payable end to scheduled_end if clock-out is later.
                if ( ! empty( $scheduled_end_local ) ) {
                    try {
                        $dt_sched_end = new DateTime( (string) $scheduled_end_local );
                        $dt_sched_end->setTimezone( $wp_timezone );
                        if ( $dt_out > $dt_sched_end ) {
                            $dt_out = $dt_sched_end;
                        }
                    } catch ( Exception $e ) {
                        // ignore clamp if schedule parse fails
                    }
                }

                if ( $dt_out <= $dt_in ) {
                    return 0.0;
                }

                $interval = $dt_in->diff( $dt_out );
                $seconds  = ( $interval->days * 86400 ) + ( $interval->h * 3600 ) + ( $interval->i * 60 ) + $interval->s;

                $seconds -= ( (int) $break_minutes * 60 );
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

// Parse start time and compute BIWEEKLY pay period start (Sunday) anchored to 2025-12-07.
try {
    $dt_local = new DateTime( $start_time_raw );
    $dt_local->setTimezone( $wp_timezone );

    // Anchor: 2025-12-07 is a known pay period start (Sunday).
    $anchor = new DateTime( '2025-12-07 00:00:00', $wp_timezone );

    // 1) Move dt_local back to the Sunday of its week.
    $dayN = (int) $dt_local->format( 'N' ); // 1=Mon..7=Sun
    $days_back_to_sunday = ( $dayN % 7 );   // Sun(7)->0, Mon(1)->1, ...
    if ( $days_back_to_sunday !== 0 ) {
        $dt_local->modify( "-{$days_back_to_sunday} days" );
    }

    // 2) Snap that Sunday to the correct biweekly boundary relative to the anchor.
    $diff_days = (int) floor( ( $dt_local->getTimestamp() - $anchor->getTimestamp() ) / DAY_IN_SECONDS );
    $mod = $diff_days % 14;
    if ( $mod < 0 ) { $mod += 14; }
    if ( $mod !== 0 ) {
        $dt_local->modify( '-' . $mod . ' days' );
    }

    $week_start = $dt_local->format( 'Y-m-d' );
} catch ( Exception $e ) {
    continue;
}

            $key = "{$user_id}|{$week_start}";

            if ( ! isset( $grouped[ $key ] ) ) {
$grouped[ $key ] = [
    'employee_id'           => $user_id,
    'employee_name'         => $employee_name,

    // Timesheet headers are no longer grouped by location.
    'location_id'           => 0,
    'location_name'         => 'All Locations',

    'week_start_date'       => $week_start,
    'records'               => [],
    'total_clocked_hours'   => 0.0,
    'total_scheduled_hours' => 0.0,
];

            }

            // ---------------- LOCAL-ONLY BREAK RULE ----------------
            $shift_id = (int) ( $time_entry->shift_id ?? 0 );
            $shift    = ( $shift_id && isset( $shift_map[ $shift_id ] ) ) ? $shift_map[ $shift_id ] : null;

            $scheduled_hours = (float) ( $time_entry->scheduled_duration ?? 0.0 );
            if ( $scheduled_hours <= 0 && $shift ) {
                $shift_start_raw = (string) ( $shift->start_time ?? '' );
                $shift_end_raw   = (string) ( $shift->end_time ?? '' );

                if ( $shift_start_raw !== '' && $shift_end_raw !== '' ) {
                    try {
                        $dt_shift_start = new DateTime( $shift_start_raw );
                        $dt_shift_end   = new DateTime( $shift_end_raw );

                        $dt_shift_start->setTimezone( $wp_timezone );
                        $dt_shift_end->setTimezone( $wp_timezone );

                        if ( $dt_shift_end > $dt_shift_start ) {
                            $interval = $dt_shift_start->diff( $dt_shift_end );
                            $seconds  = ( $interval->days * 86400 ) + ( $interval->h * 3600 ) + ( $interval->i * 60 ) + $interval->s;
                            $scheduled_hours = (float) ( $seconds / 3600 );
                        }
                    } catch ( Exception $e ) {
                        $scheduled_hours = 0.0;
                    }
                }
            }

            if ( $scheduled_hours <= 0 ) {
                $scheduled_hours = (float) ( $time_entry->calculated_duration ?? 0.0 );
            }

            $break_api_minutes = $get_break_minutes_from_api( $time_entry );
            $api_break_provided = $break_api_minutes !== null;

            // Only enforce a default break when the API provides no break data.
            $break_enforced = $api_break_provided
                ? (int) $break_api_minutes
                : ( ( $scheduled_hours > 5.0 ) ? 60 : 0 );

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
            $time_entry->_wiw_api_break_provided  = $api_break_provided;
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
            $week_end_date = date( 'Y-m-d', strtotime( $week_start_date . ' +13 days' ) );

            $total_clocked_hours   = round( (float) $bundle['total_clocked_hours'], 2 );
            $total_scheduled_hours = round( (float) $bundle['total_scheduled_hours'], 2 );

            $header_id = $wpdb->get_var(
                $wpdb->prepare(
"SELECT id FROM {$table_timesheets}
 WHERE employee_id = %d AND week_start_date = %s",
$employee_id,
$week_start_date
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

            $has_local_edits = false;
            $api_time_ids    = [];

            foreach ( $bundle['records'] as $time_entry ) {
                $wiw_time_id = (int) ( $time_entry->id ?? 0 );
                if ( ! $wiw_time_id ) {
                    continue;
                }
                $api_time_ids[] = $wiw_time_id;

                $shift_id = (int) ( $time_entry->shift_id ?? 0 );
                $shift    = ( $shift_id && isset( $shift_map[ $shift_id ] ) ) ? $shift_map[ $shift_id ] : null;

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

                // ✅ Scheduled start/end from SHIFT
                $scheduled_start_local = null;
                $scheduled_end_local   = null;

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

                $api_break_provided = ! empty( $time_entry->_wiw_api_break_provided );
                if ( ! empty( $scheduled_start_local ) && ! empty( $scheduled_end_local ) ) {
                    try {
                        $dt_sched_start = new DateTimeImmutable( (string) $scheduled_start_local, $wp_timezone );
                        $dt_sched_end   = new DateTimeImmutable( (string) $scheduled_end_local, $wp_timezone );

                        if ( $dt_sched_end > $dt_sched_start ) {
                            $diff = $dt_sched_start->diff( $dt_sched_end );
                            $span_seconds =
                                ( (int) $diff->days * 86400 )
                                + ( (int) $diff->h * 3600 )
                                + ( (int) $diff->i * 60 )
                                + ( (int) $diff->s );
                            $scheduled_span_hours = (float) ( $span_seconds / 3600 );
                            if ( $scheduled_span_hours > 5.0 ) {
                                $break_minutes_local = max( 60, $break_minutes_local );
                            }
                        }
                    } catch ( Exception $e ) {
                    }
                }

                $clocked_hours_local = isset( $time_entry->_wiw_local_clocked_hours )
                    ? (float) $time_entry->_wiw_local_clocked_hours
                    : round( (float) ( $time_entry->calculated_duration ?? 0.0 ), 2 );

                $payable_hours_local = $compute_local_payable_hours(
                    $clock_in_local,
                    $clock_out_local,
                    $scheduled_start_local,
                    $scheduled_end_local,
                    $break_minutes_local,
                    $clocked_hours_local // fallback is the already-computed clocked hours
                );

                // Additional hours = Clock Out minus Scheduled End (hours), if Clock Out is later.
                $additional_hours_local = 0.00;
                if ( ! empty( $scheduled_end_local ) && ! empty( $clock_out_local ) ) {
                    try {
                        $dt_sched_end = new DateTime( (string) $scheduled_end_local );
                        $dt_clock_out = new DateTime( (string) $clock_out_local );

                        $dt_sched_end->setTimezone( $wp_timezone );
                        $dt_clock_out->setTimezone( $wp_timezone );

                        if ( $dt_clock_out > $dt_sched_end ) {
                            $additional_hours_local = round(
                                ( $dt_clock_out->getTimestamp() - $dt_sched_end->getTimestamp() ) / 3600,
                                2
                            );
                        }
                    } catch ( Exception $e ) {
                        $additional_hours_local = 0.00;
                    }
                }

// Compute scheduled hours (same logic as Sched. Hrs column) for flag comparisons.
                // Compute scheduled hours for this entry (used for DB + flag 109).
                // Rule: Scheduled Hrs = (Scheduled End - Scheduled Start) span in hours,
                // and if span exceeds 5.0 hours, automatically deduct 60 minutes (1.0 hour).
                $scheduled_hours_local_for_entry = null;

                // Prefer scheduled span derived from scheduled_start/scheduled_end timestamps.
                if ( ! empty( $scheduled_start_local ) && ! empty( $scheduled_end_local ) ) {
                    try {
                        $tz_sched = $wp_timezone;

                        $dt_sched_start = new DateTimeImmutable( (string) $scheduled_start_local, $tz_sched );
                        $dt_sched_end   = new DateTimeImmutable( (string) $scheduled_end_local, $tz_sched );

                        if ( $dt_sched_end > $dt_sched_start ) {
                            $diff = $dt_sched_start->diff( $dt_sched_end );
                            $span_seconds =
                                ( (int) $diff->days * 86400 )
                                + ( (int) $diff->h * 3600 )
                                + ( (int) $diff->i * 60 )
                                + ( (int) $diff->s );

                            $scheduled_hours_local_for_entry = (float) ( $span_seconds / 3600 );
                        } else {
                            $scheduled_hours_local_for_entry = 0.0;
                        }
                    } catch ( Exception $e ) {
                        $scheduled_hours_local_for_entry = null;
                    }
                }

                // Fallback to API durations if scheduled span is unavailable.
                if ( $scheduled_hours_local_for_entry === null ) {
                    $scheduled_hours_local_for_entry = (float) ( $time_entry->scheduled_duration ?? 0.0 );
                    if ( $scheduled_hours_local_for_entry <= 0 ) {
                        $scheduled_hours_local_for_entry = (float) ( $time_entry->calculated_duration ?? 0.0 );
                    }
                }

                // Apply auto-break deduction rule to Scheduled Hrs only (independent of break_minutes_local).
                if ( $scheduled_hours_local_for_entry > 5.0 ) {
                    $scheduled_hours_local_for_entry = max( 0.0, $scheduled_hours_local_for_entry - 1.0 );
                }

                if ( $scheduled_hours_local_for_entry > 5.0 ) {
                    $break_minutes_local = max( 60, $break_minutes_local );
                }

                $entry_data = [
                    'timesheet_id'    => $header_id,
                    'wiw_time_id'     => $wiw_time_id,
                    'wiw_shift_id'    => (int) ( $time_entry->shift_id ?? 0 ),
                    'date'            => $entry_date,
                    'location_id'     => (int) ( $time_entry->location_id ?? 0 ),
                    'location_name'   => (string) ( $time_entry->location_name ?? '' ),
                    'clock_in'        => $clock_in_local,
                    'clock_out'       => $clock_out_local,

                    'scheduled_start' => $scheduled_start_local,
                    'scheduled_end'   => $scheduled_end_local,

                    'break_minutes'   => (int) $break_minutes_local,
                     'scheduled_hours' => round( $scheduled_hours_local_for_entry, 2 ),

                    'clocked_hours'   => round( $clocked_hours_local, 2 ),
                    'payable_hours'   => round( $payable_hours_local, 2 ),

                    'additional_hours'  => round( (float) $additional_hours_local, 2 ),
                    'extra_time_status' => 'unset',

                    'status'          => 'pending',
                    'updated_at'      => $now,

                ];

                $clock_in_for_flags  = $clock_in_local;
                $clock_out_for_flags = $clock_out_local;

                if ( $entry_id ) {
                    $existing_entry = $wpdb->get_row(
                        $wpdb->prepare(
                            "SELECT clock_in, clock_out, break_minutes, clocked_hours, payable_hours, additional_hours, status
                             FROM {$table_timesheet_entries}
                             WHERE id = %d",
                            $entry_id
                        )
                    );

                    $has_local_edit = (bool) $wpdb->get_var(
                        $wpdb->prepare(
                            "SELECT 1
                             FROM {$wpdb->prefix}wiw_timesheet_edit_logs
                             WHERE entry_id = %d OR wiw_time_id = %d
                             LIMIT 1",
                            $entry_id,
                            $wiw_time_id
                        )
                    );

                    $is_approved = ( $existing_entry && isset( $existing_entry->status ) )
                        ? ( strtolower( (string) $existing_entry->status ) === 'approved' )
                        : false;

                    if ( ( $has_local_edit || $is_approved ) && $existing_entry ) {
                        $has_local_edits = true;

                        $api_break_provided = ! empty( $time_entry->_wiw_api_break_provided );

                        $entry_data['clock_in']         = $existing_entry->clock_in;
                        $entry_data['clock_out']        = $existing_entry->clock_out;
                        $break_minutes_override = $api_break_provided
                            ? (int) $break_minutes_local
                            : max( (int) $break_minutes_local, (int) $existing_entry->break_minutes );

                        $entry_data['break_minutes'] = $break_minutes_override;

                        $clocked_hours_local = $compute_local_clocked_hours(
                            (string) ( $existing_entry->clock_in ?? '' ),
                            (string) ( $existing_entry->clock_out ?? '' ),
                            (int) $entry_data['break_minutes'],
                            (float) $existing_entry->clocked_hours
                        );

                        $payable_hours_local = $compute_local_payable_hours(
                            (string) ( $existing_entry->clock_in ?? '' ),
                            (string) ( $existing_entry->clock_out ?? '' ),
                            (string) ( $scheduled_start_local ?? '' ),
                            (string) ( $scheduled_end_local ?? '' ),
                            (int) $entry_data['break_minutes'],
                            $clocked_hours_local
                        );

                        $entry_data['clocked_hours'] = $clocked_hours_local;
                        $entry_data['payable_hours'] = $payable_hours_local;
                        $entry_data['break_minutes']    = $api_break_provided
                            ? (int) $break_minutes_local
                            : (int) $existing_entry->break_minutes;

                        $clocked_hours_local = $api_break_provided
                            ? $compute_local_clocked_hours(
                                (string) ( $existing_entry->clock_in ?? '' ),
                                (string) ( $existing_entry->clock_out ?? '' ),
                                (int) $entry_data['break_minutes'],
                                (float) $existing_entry->clocked_hours
                            )
                            : (float) $existing_entry->clocked_hours;

                        $payable_hours_local = $api_break_provided
                            ? $compute_local_payable_hours(
                                (string) ( $existing_entry->clock_in ?? '' ),
                                (string) ( $existing_entry->clock_out ?? '' ),
                                (string) ( $scheduled_start_local ?? '' ),
                                (string) ( $scheduled_end_local ?? '' ),
                                (int) $entry_data['break_minutes'],
                                $clocked_hours_local
                            )
                            : (float) $existing_entry->payable_hours;

                        $entry_data['clocked_hours']    = $clocked_hours_local;
                        $entry_data['payable_hours']    = $payable_hours_local;
                        $entry_data['additional_hours'] = (float) $existing_entry->additional_hours;
                        $entry_data['status']           = $existing_entry->status;

                        $clock_in_for_flags  = $existing_entry->clock_in;
                        $clock_out_for_flags = $existing_entry->clock_out;
                    }


                    // Preserve existing confirmed/denied status on resync.
                    if ( isset( $entry_data['extra_time_status'] ) ) {
                        unset( $entry_data['extra_time_status'] );
                    }

                    $wpdb->update(
                        $table_timesheet_entries,
                        $entry_data,
                        [ 'id' => $entry_id ]
                    );
                } else {
                    $entry_data['created_at'] = $now;
                    $wpdb->insert( $table_timesheet_entries, $entry_data );
                }

                // ✅ Store flags for this time record during sync (deletes old, inserts current)
                $this->wiwts_sync_store_time_flags(
                    $wiw_time_id,
                    (string) ( $clock_in_for_flags ?? '' ),
                    (string) ( $clock_out_for_flags ?? '' ),
                    (string) ( $scheduled_start_local ?? '' ),
                    (string) ( $scheduled_end_local ?? '' ),
                    $wp_timezone,
                    $scheduled_hours_local_for_entry,
                    $payable_hours_local
                );

            }

            if ( ! empty( $api_time_ids ) ) {
                $placeholders = implode( ',', array_fill( 0, count( $api_time_ids ), '%d' ) );
                $wpdb->query(
                    $wpdb->prepare(
                        "DELETE FROM {$table_timesheet_entries}
                         WHERE timesheet_id = %d
                         AND wiw_time_id NOT IN ({$placeholders})",
                        array_merge( [ $header_id ], $api_time_ids )
                    )
                );
            }

            $remaining_entries = (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$table_timesheet_entries} WHERE timesheet_id = %d",
                    $header_id
                )
            );

            if ( $remaining_entries === 0 ) {
                $wpdb->delete( $table_timesheets, [ 'id' => $header_id ], [ '%d' ] );
                continue;

            $remaining_entries = (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$table_timesheet_entries} WHERE timesheet_id = %d",
                    $header_id
                )
            );

            if ( $remaining_entries === 0 ) {
                $wpdb->delete( $table_timesheets, [ 'id' => $header_id ], [ '%d' ] );
                continue;
            }

            $totals = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT
                        COALESCE(SUM(clocked_hours), 0) AS total_clocked,
                        COALESCE(SUM(scheduled_hours), 0) AS total_scheduled
                     FROM {$table_timesheet_entries}
                     WHERE timesheet_id = %d",
                    $header_id
                )
            );

            if ( $totals ) {
                $wpdb->update(
                    $table_timesheets,
                    array(
                        'total_clocked_hours'   => (float) round( (float) $totals->total_clocked, 2 ),
                        'total_scheduled_hours' => (float) round( (float) $totals->total_scheduled, 2 ),
                        'updated_at'            => $now,
                    ),
                    array( 'id' => $header_id ),
                    array( '%f', '%f', '%s' ),
                    array( '%d' )
                );
            }

            }

            $totals = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT
                        COALESCE(SUM(clocked_hours), 0) AS total_clocked,
                        COALESCE(SUM(scheduled_hours), 0) AS total_scheduled
                     FROM {$table_timesheet_entries}
                     WHERE timesheet_id = %d",
                    $header_id
                )
            );

            if ( $totals ) {
                $wpdb->update(
                    $table_timesheets,
                    array(
                        'total_clocked_hours'   => (float) round( (float) $totals->total_clocked, 2 ),
                        'total_scheduled_hours' => (float) round( (float) $totals->total_scheduled, 2 ),
                        'updated_at'            => $now,
                    ),
                    array( 'id' => $header_id ),
                    array( '%f', '%f', '%s' ),
                    array( '%d' )
                );
            }

            unset( $has_local_edits );
        }
    }
}
