<?php
// Variables available: $employee_data, $user_map, $wp_timezone, $time_format, $global_row_index

if ( empty( $employee_data ) ) : ?>
    <tr>
        <td colspan="11">No timesheet records found within the filtered week.</td>
    </tr>
<?php else : ?>

    <?php foreach ( $employee_data as $employee_name => $periods ) : ?>

        <!-- EMPLOYEE HEADER ROW -->
        <tr class="wiw-employee-header">
            <td colspan="11" style="background-color: #e6e6fa; font-weight: bold; font-size: 1.1em;">
                👤 Employee: <?php echo esc_html( $employee_name ); ?>
            </td>
        </tr>

        <?php foreach ( $periods as $period_start_date => $period_data ) :
            $period_end_date  = date( 'Y-m-d', strtotime( $period_start_date . ' + 4 days' ) );
            $total_clocked    = number_format( $period_data['total_clocked_hours'] ?? 0.0, 2 );
            $total_scheduled  = number_format( $period_data['total_scheduled_hours'] ?? 0.0, 2 );
            ?>

            <!-- WEEK TOTAL ROW -->
            <tr class="wiw-period-total">
                <td colspan="5" style="background-color: #f0f0ff; font-weight: bold;">
                    📅 Week of: <?php echo esc_html( $period_start_date ); ?> to <?php echo esc_html( $period_end_date ); ?>
                </td>

                <td style="background-color: #f0f0ff; font-weight: bold;"><?php echo esc_html( $total_scheduled ); ?></td>

                <td colspan="3" style="background-color: #f0f0ff;"></td>

                <td style="background-color: #f0f0ff; font-weight: bold;"><?php echo esc_html( $total_clocked ); ?></td>

                <td style="background-color: #f0f0ff;"></td>
            </tr>

            <?php foreach ( $period_data['records'] as $time_entry ) :

                $time_id                 = $time_entry->id ?? 'N/A';
                $scheduled_shift_display = $time_entry->scheduled_shift_display ?? 'N/A';
                $location_name           = $time_entry->location_name ?? 'N/A';

                $start_time_utc = $time_entry->start_time ?? '';
                $end_time_utc   = $time_entry->end_time ?? '';

                // Date + time display
                $display_date       = 'N/A';
                $display_start_time = 'N/A';
                $display_end_time   = 'Active (N/A)';
                $date_match         = true;

                try {
                    if ( ! empty( $start_time_utc ) ) {
                        $dt_start_utc = new DateTime( $start_time_utc, new DateTimeZone( 'UTC' ) );
                        $dt_start_utc->setTimezone( $wp_timezone );

                        $display_date       = $dt_start_utc->format( 'Y-m-d' );
                        $display_start_time = $dt_start_utc->format( $time_format );
                    }

                    if ( ! empty( $end_time_utc ) ) {
                        $dt_end_utc = new DateTime( $end_time_utc, new DateTimeZone( 'UTC' ) );
                        $dt_end_utc->setTimezone( $wp_timezone );

                        $display_end_time = $dt_end_utc->format( $time_format );

                        if ( $display_date !== $dt_end_utc->format( 'Y-m-d' ) ) {
                            $date_match = false;
                        }
                    }
                } catch ( Exception $e ) {
                    $display_start_time = 'Error';
                    $display_end_time   = 'Error';
                    $display_date       = 'Error';
                }

                // Breaks
                $break_hours_raw = $time_entry->break_hours ?? 0;
                $break_minutes   = 0;

                if ( is_numeric( $break_hours_raw ) && $break_hours_raw > 0 ) {
                    $break_minutes = round( $break_hours_raw * 60 );
                }

                $clocked_duration    = number_format( $time_entry->calculated_duration ?? 0.0, 2 );
                $scheduled_duration  = number_format( $time_entry->scheduled_duration ?? 0.0, 2 );
                $status              = ( isset( $time_entry->approved ) && $time_entry->approved ) ? 'Approved' : 'Pending';

                $row_id      = 'wiw-raw-' . $global_row_index++;
                $row_data_id = 'wiw-record-' . $time_id;

                $date_cell_style = ( $date_match || $display_end_time === 'Active (N/A)' )
                    ? ''
                    : 'style="background-color: #ffe0e0;" title="Clock out date does not match clock in date."';
                ?>

                <!-- DAILY RECORD ROW -->
                <tr class="wiw-daily-record" id="<?php echo esc_attr( $row_data_id ); ?>" data-time-id="<?php echo esc_attr( $time_id ); ?>">
                    <td><?php echo esc_html( $time_id ); ?></td>
                    <td <?php echo $date_cell_style; ?>><?php echo esc_html( $display_date ); ?></td>
                    <td><?php echo esc_html( $employee_name ); ?></td>
                    <td><?php echo esc_html( $location_name ); ?></td>
                    <td><?php echo esc_html( $scheduled_shift_display ); ?></td>

                    <td><?php echo esc_html( $scheduled_duration ); ?></td>
                    <td><?php echo esc_html( $display_start_time ); ?></td>
                    <td><?php echo esc_html( $display_end_time ); ?></td>
                    <td style="text-align:center;"><?php echo ( $break_minutes > 0 ) ? '-' . esc_html( $break_minutes ) : '0'; ?></td>
                    <td><?php echo esc_html( $clocked_duration ); ?></td>

                    <td class="wiw-status-cell">
                        <span class="wiw-status-text"><?php echo esc_html( $status ); ?></span>
                    </td>
                </tr>

                <!-- RAW TOGGLE ROW -->
                <tr class="wiw-data-toggle-row" style="background-color: #f8f8ff;">
                    <td colspan="11" style="padding-top: 5px; padding-bottom: 5px; text-align: left;">
                        <button type="button" class="button action-toggle-raw" data-target="<?php echo esc_attr( $row_id ); ?>">
                            View Shift Record Data
                        </button>
                    </td>
                </tr>

                <!-- RAW DATA ROW -->
                <tr id="<?php echo esc_attr( $row_id ); ?>" style="display:none; background-color: #f9f9f9;">
                    <td colspan="11">
                        <div style="padding: 10px; border: 1px solid #ccc; max-height: 300px; overflow: auto;">
                            <strong>Raw API Data:</strong>
                            <pre style="font-size: 11px;"><?php print_r( $time_entry ); ?></pre>
                        </div>
                    </td>
                </tr>

            <?php endforeach; // records ?>
        <?php endforeach; // periods ?>
    <?php endforeach; // employees ?>

<?php endif; ?>
