<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Local DB sync logic for timesheets.
 *
 * Handles mirroring API times into local DB tables.
 */

if ( ! class_exists( 'WIW_Timesheet_Manager' ) ) {
    return;
}

/**
 * Syncs fetched timesheet data from the WIW API into local DB tables:
 * - wp_wiw_timesheets        (header: one per employee + week + location)
 * - wp_wiw_timesheet_entries (line items: one per WIW time record)
 *
 * This does NOT change any UI or workflow; it just mirrors data locally.
 *
 * @param array        $times       The raw times array (with calculated_duration, scheduled_duration, location fields).
 * @param array        $user_map    Map of user IDs to user objects.
 * @param DateTimeZone $wp_timezone WordPress timezone object.
 */

trait WIW_Timesheet_Sync_Trait {

    private function sync_timesheets_to_local_db( $times, $user_map, $wp_timezone ) {
        global $wpdb;

        if ( empty( $times ) ) {
            return;
        }

        $table_timesheets        = $wpdb->prefix . 'wiw_timesheets';
        $table_timesheet_entries = $wpdb->prefix . 'wiw_timesheet_entries';

        $grouped = [];

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

            $start_time_utc = $time_entry->start_time ?? '';
            if ( empty( $start_time_utc ) ) {
                continue;
            }

            $location_id   = (int) ( $time_entry->location_id ?? 0 );
            $location_name = (string) ( $time_entry->location_name ?? '' );

            try {
                $dt = new DateTime( $start_time_utc, new DateTimeZone( 'UTC' ) );
                $dt->setTimezone( $wp_timezone );

                $dayN = (int) $dt->format( 'N' );
                $days = ( $dayN <= 5 ) ? -( $dayN - 1 ) : ( 8 - $dayN );
                if ( $days !== 0 ) {
                    $dt->modify( "{$days} days" );
                }

                $week_start = $dt->format( 'Y-m-d' );
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

            $grouped[ $key ]['total_clocked_hours']
                += (float) ( $time_entry->calculated_duration ?? 0.0 );

            $grouped[ $key ]['total_scheduled_hours']
                += (float) ( $time_entry->scheduled_duration ?? 0.0 );

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

            $total_clocked_hours   = round( $bundle['total_clocked_hours'], 2 );
            $total_scheduled_hours = round( $bundle['total_scheduled_hours'], 2 );

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

                $entry_data = [
                    'timesheet_id'    => $header_id,
                    'wiw_time_id'     => $wiw_time_id,
                    'wiw_shift_id'    => (int) ( $time_entry->shift_id ?? 0 ),
                    'date'            => substr( $time_entry->start_time, 0, 10 ),
                    'location_id'     => (int) ( $time_entry->location_id ?? 0 ),
                    'location_name'   => (string) ( $time_entry->location_name ?? '' ),
                    'clock_in'        => $time_entry->start_time ?? null,
                    'clock_out'       => $time_entry->end_time ?? null,
                    'break_minutes'   => (int) ( $time_entry->break ?? 0 ),
                    'scheduled_hours' => round( (float) ( $time_entry->scheduled_duration ?? 0 ), 2 ),
                    'clocked_hours'   => round( (float) ( $time_entry->calculated_duration ?? 0 ), 2 ),
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
