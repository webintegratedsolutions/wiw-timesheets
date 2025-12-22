<?php
// Template: Timesheets admin page (extracted from admin_timesheets_page()).
?>

<div class="wrap">
            <h1>🗓️ When I Work Timesheet Dashboard</h1>

            <?php
            $timesheets_data = $this->fetch_timesheets_data();

            if ( is_wp_error( $timesheets_data ) ) {
                $error_message = $timesheets_data->get_error_message();
                ?>
                <div class="notice notice-error">
                    <p><strong>❌ Timesheet Fetch Error:</strong> <?php echo esc_html($error_message); ?></p>
                    <?php if ($timesheets_data->get_error_code() === 'wiw_token_missing') : ?>
                        <p>Please go to the <a href="<?php echo esc_url(admin_url('admin.php?page=wiw-timesheets-settings')); ?>">Settings Page</a> to log in and save your session token.</p>
                    <?php endif; ?>
                </div>
                <?php
            } else {
                $times          = isset($timesheets_data->times) ? $timesheets_data->times : array();
                $included_users = isset($timesheets_data->users) ? $timesheets_data->users : array();
                $included_shifts= isset($timesheets_data->shifts) ? $timesheets_data->shifts : array();
                $included_sites = isset($timesheets_data->sites) ? $timesheets_data->sites : array();

                $user_map  = array_column($included_users, null, 'id');
                $shift_map = array_column($included_shifts, null, 'id');
                $site_map  = array_column($included_sites, null, 'id');
                $site_map[0] = (object) array('name' => 'No Assigned Location');

                $wp_timezone_string = get_option('timezone_string');
                if (empty($wp_timezone_string)) { $wp_timezone_string = 'UTC'; }
                $wp_timezone = new DateTimeZone($wp_timezone_string);
                $time_format = get_option('time_format') ?: 'g:i A';

                if ( method_exists( $this, 'calculate_shift_duration_in_hours' ) ) {
                    foreach ( $times as &$time_entry ) {
                        $clocked_duration = $this->calculate_timesheet_duration_in_hours( $time_entry );
                        $time_entry->calculated_duration = $clocked_duration;

                        $shift_id            = $time_entry->shift_id ?? null;
                        $scheduled_shift_obj = $shift_map[ $shift_id ] ?? null;

                        if ( $scheduled_shift_obj ) {
                            $time_entry->scheduled_duration = $this->calculate_shift_duration_in_hours( $scheduled_shift_obj );

                            $dt_sched_start = new DateTime( $scheduled_shift_obj->start_time, new DateTimeZone( 'UTC' ) );
                            $dt_sched_end   = new DateTime( $scheduled_shift_obj->end_time,   new DateTimeZone( 'UTC' ) );
                            $dt_sched_start->setTimezone( $wp_timezone );
                            $dt_sched_end->setTimezone( $wp_timezone );

                            $time_entry->scheduled_shift_display =
                                $dt_sched_start->format( $time_format ) . ' - ' . $dt_sched_end->format( $time_format );

                            $site_lookup_id = $scheduled_shift_obj->site_id ?? 0;
                            $site_obj       = $site_map[ $site_lookup_id ] ?? null;

                            $time_entry->location_id   = $site_lookup_id;
                            $time_entry->location_name = ( $site_obj && isset( $site_obj->name ) )
                                ? esc_html( $site_obj->name )
                                : 'No Assigned Location';

                        } else {
                            $time_entry->scheduled_duration      = 0.0;
                            $time_entry->scheduled_shift_display = 'N/A';
                            $time_entry->location_id             = 0;
                            $time_entry->location_name           = 'N/A';
                        }
                    }
                    unset( $time_entry );
                }

                $this->sync_timesheets_to_local_db( $times, $user_map, $wp_timezone, $shift_map );

                $times = $this->sort_timesheet_data( $times, $user_map );
                $grouped_timesheets = $this->group_timesheet_by_pay_period( $times, $user_map );

                $timesheet_nonce = wp_create_nonce('wiw_timesheet_nonce');
                ?>
                <div class="notice notice-success"><p>✅ Timesheet data fetched successfully!</p></div>

                <h2>Latest Timesheets (Grouped by Employee and Pay Period)</h2>

                <?php if (empty($grouped_timesheets)) : ?>
                    <p>No timesheet records found within the filtered period.</p>
                <?php else : ?>

                <table class="wp-list-table widefat fixed striped" id="wiw-timesheets-table">
                    <thead>
                        <tr>
                            <th width="5%">Record ID</th>
                            <th width="8%">Date</th>
                            <th width="10%">Employee Name</th>
                            <th width="10%">Location</th>
                            <th width="10%">Scheduled Shift</th>
                            <th width="6%">Hrs Scheduled</th>
                            <th width="8%">Clock In</th>
                            <th width="8%">Clock Out</th>
                            <th width="7%">Breaks (Min -)</th>
                            <th width="6%">Hrs Clocked</th>
                            <th width="7%">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $employee_data = $grouped_timesheets;
                        $global_row_index = 0;
                        include WIW_PLUGIN_PATH . 'admin/timesheet-loop.php';
                        ?>
                    </tbody>
                </table>
                <?php endif; ?>

                <?php include WIW_PLUGIN_PATH . 'admin/timesheet-dashboard-script.php'; ?>

                <hr/>
                <details style="border: 1px solid #ccc; background: #fff; padding: 10px; margin-top: 20px;">
                    <summary style="cursor: pointer; font-weight: bold; padding: 5px; background: #e0e0e0; margin: -10px;">
                        💡 Click to Expand: Data Reference Legend (Condensed)
                    </summary>
                    <div style="padding-top: 15px;">
                        <table class="form-table" style="margin-top: 0;">
                            <tbody>
                                <tr><th scope="row">Record ID</th><td>Unique identifier for the timesheet entry (used for API actions).</td></tr>
                                <tr><th scope="row">Date</th><td>Clock In Date (local timezone). Highlighted red if Clock Out occurs on a different day (overnight shift).</td></tr>
                                <tr><th scope="row">Employee Name</th><td>Name retrieved from users data.</td></tr>
                                <tr><th scope="row">Location</th><td>Assigned Location retrieved from the corresponding shift record.</td></tr>
                                <tr><th scope="row">Scheduled Shift</th><td>Scheduled Start - End Time (local timezone). Shows N/A if no shift is linked.</td></tr>
                                <tr><th scope="row">Hrs Scheduled</th><td>Total Scheduled Hours. Pay period of totals aggregate this value.</td></tr>
                                <tr><th scope="row">Clock In / Out</th><td>Actual Clock In/Out Time (local timezone). Clock Out shows Active (N/A) if the shift is open.</td></tr>
                                <tr><th scope="row">Breaks (Min -)</th><td>Time deducted for breaks, derived from the break_hours raw data converted to minutes.</td></tr>
                                <tr><th scope="row">Hrs Clocked</th><td>Total Clocked Hours. Pay period of totals aggregate this value.</td></tr>
                                <tr><th scope="row">Status</th><td>Approval status: Pending or Approved.</td></tr>
                                <tr><th scope="row">Actions</th><td>Interactive options (Data and Edit/Approve).</td></tr>
                            </tbody>
                        </table>
                    </div>
                </details>
                <?php
            }
            ?>
        </div>