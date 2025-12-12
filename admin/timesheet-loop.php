<?php 
// Variables available: $employee_data, $user_map, $wp_timezone, $time_format, $timesheet_nonce, $global_row_index

if (empty($employee_data)): 
    ?>
    <tr>
        <td colspan="12">No timesheet records found within the filtered period.</td>
    </tr>
<?php else: ?>
    <?php foreach ($employee_data as $employee_name => $periods) : 
        
        // --- EMPLOYEE HEADER ROW ---
        ?>
        <tr class="wiw-employee-header">
            <td colspan="12" style="background-color: #e6e6fa; font-weight: bold; font-size: 1.1em;">
                👤 Employee: <?php echo esc_html($employee_name); ?>
            </td>
        </tr>
        <?php

        foreach ($periods as $period_start_date => $period_data) : 
            $period_end_date = date('Y-m-d', strtotime($period_start_date . ' + 4 days'));
            
            $total_clocked = number_format($period_data['total_clocked_hours'] ?? 0.0, 2);
            $total_scheduled = number_format($period_data['total_scheduled_hours'] ?? 0.0, 2);
            
            // Collect pending IDs
            $is_period_pending = false;
            $period_time_ids = [];
            foreach ($period_data['records'] as $time_entry) {
                $time_id = $time_entry->id ?? null;
                if ($time_id && (isset($time_entry->approved) && !$time_entry->approved)) {
                    $is_period_pending = true;
                    $period_time_ids[] = $time_id;
                }
            }
            $period_time_ids_str = implode(',', $period_time_ids);

            // --- PAY PERIOD TOTAL ROW ---
            ?>
            <tr class="wiw-period-total">
                <td colspan="5" style="background-color: #f0f0ff; font-weight: bold;">
                    📅 Pay Period: <?php echo esc_html($period_start_date); ?> to <?php echo esc_html($period_end_date); ?>
                </td>
                
                <td style="background-color: #f0f0ff; font-weight: bold;"><?php echo $total_scheduled; ?></td>
                
                <td colspan="3" style="background-color: #f0f0ff;"></td>
                
                <td style="background-color: #f0f0ff; font-weight: bold;"><?php echo $total_clocked; ?></td>
                
                <td colspan="2" style="background-color: #f0f0ff; text-align: right;">
                    <button type="button" 
                            class="button button-primary button-small wiw-approve-period-ui" 
                            data-period-ids="<?php echo esc_attr($period_time_ids_str); ?>"
                            data-nonce="<?php echo esc_attr($timesheet_nonce); ?>"
                            title="<?php echo $is_period_pending ? 'Approve all pending records in this pay period.' : 'All records in this period are already approved.'; ?>"
                            <?php echo $is_period_pending ? '' : 'disabled'; ?>
                    >
                        Approve Period
                    </button>
                </td>
            </tr>
            <?php

            // --- DAILY RECORD ROWS ---
            foreach ($period_data['records'] as $time_entry) : 
                
                $time_id = $time_entry->id ?? 'N/A';
                $scheduled_shift_display = $time_entry->scheduled_shift_display ?? 'N/A';
                $location_name = $time_entry->location_name ?? 'N/A'; 
                
                $start_time_utc = $time_entry->start_time ?? ''; 
                $end_time_utc = $time_entry->end_time ?? '';

                // --- Date and Time Processing ---
                $display_date = 'N/A';
                $display_start_time = 'N/A';
                $display_end_time = 'Active (N/A)';
                $raw_start_datetime = '';
                $raw_start_time_only = '';
                $raw_end_datetime = '';
                $raw_end_time_only = '';   
                $date_match = true;

                try {
                    if (!empty($start_time_utc)) {
                        $dt_start_utc = new DateTime($start_time_utc, new DateTimeZone('UTC'));
                        $dt_start_utc->setTimezone($wp_timezone);
                        
                        $display_date = $dt_start_utc->format('Y-m-d');
                        $display_start_time = $dt_start_utc->format($time_format);
                        $raw_start_datetime = $dt_start_utc->format('Y-m-d H:i:s'); 
                        $raw_start_time_only = $dt_start_utc->format('H:i'); 
                    }

                    if (!empty($end_time_utc)) {
                        $dt_end_utc = new DateTime($end_time_utc, new DateTimeZone('UTC'));
                        $dt_end_utc->setTimezone($wp_timezone);
                        
                        $display_end_time = $dt_end_utc->format($time_format);
                        $raw_end_datetime = $dt_end_utc->format('Y-m-d H:i:s');
                        $raw_end_time_only = $dt_end_utc->format('H:i');     
                        
                        if ($display_date !== $dt_end_utc->format('Y-m-d')) {
                            $date_match = false;
                        }
                    }
                } catch (Exception $e) {
                    $display_start_time = 'Error';
                    $display_end_time = 'Error';
                    $display_date = 'Error';
                }
                
                // --- Calculate Breaks ---
                $break_hours_raw = $time_entry->break_hours ?? 0;
                $break_minutes = 0;
                
                if (is_numeric($break_hours_raw) && $break_hours_raw > 0) {
                    $break_minutes = round($break_hours_raw * 60);
                }
                
                $clocked_duration = number_format($time_entry->calculated_duration ?? 0.0, 2);
                $scheduled_duration = number_format($time_entry->scheduled_duration ?? 0.0, 2);
                
                $status = (isset($time_entry->approved) && $time_entry->approved) ? 'Approved' : 'Pending';

                $row_id = 'wiw-raw-' . $global_row_index++;
                $row_data_id = 'wiw-record-' . $time_id;
                
                $date_cell_style = ($date_match || $display_end_time === 'Active (N/A)') ? '' : 'style="background-color: #ffe0e0;" title="Clock out date does not match clock in date."';
                $edit_button_style = ($status === 'Approved') ? 'style="display:none;"' : '';

                // --- Daily Record Row Display ---
                ?>
                <tr class="wiw-daily-record" id="<?php echo esc_attr($row_data_id); ?>" data-time-id="<?php echo esc_attr($time_id); ?>">
                    <td><?php echo esc_html($time_id); ?></td>
                    <td <?php echo $date_cell_style; ?>><?php echo esc_html($display_date); ?></td>
                    <td><?php echo esc_html($employee_name); ?></td>
                    <td><?php echo esc_html($location_name); ?></td> 
                    <td><?php echo esc_html($scheduled_shift_display); ?></td>
                    
                    <td><?php echo esc_html($scheduled_duration); ?></td>

                    <td class="wiw-clock-in-cell">
                        <span class="wiw-display-time"><?php echo esc_html($display_start_time); ?></span>
                        <input 
                            type="text" 
                            class="wiw-edit-input wiw-start-time" 
                            value="<?php echo esc_attr($raw_start_time_only); ?>" 
                            data-full-datetime="<?php echo esc_attr($raw_start_datetime); ?>" 
                            style="display:none; width: 80px; font-size: 11px;"
                        >
                    </td>
                    
                    <td class="wiw-clock-out-cell">
                        <span class="wiw-display-time"><?php echo esc_html($display_end_time); ?></span>
                        <input 
                            type="text" 
                            class="wiw-edit-input wiw-end-time" 
                            value="<?php echo esc_attr($raw_end_time_only); ?>" 
                            data-full-datetime="<?php echo esc_attr($raw_end_datetime); ?>" 
                            style="display:none; width: 80px; font-size: 11px;"
                        >
                    </td>
                    
                    <td style="text-align:center;">
                        <span class="wiw-display-time">
                            <?php echo ($break_minutes > 0) ? '-' . esc_html($break_minutes) : '0'; ?>
                        </span>
                        <input 
                            type="number" 
                            min="0"
                            class="wiw-edit-input wiw-break-minutes" 
                            value="<?php echo esc_attr($break_minutes); ?>" 
                            style="display:none; width: 60px; font-size: 11px; text-align: center;"
                        >
                    </td>
                    
                    <td><?php echo esc_html($clocked_duration); ?></td>
                    
                    <td class="wiw-status-cell">
                        <span class="wiw-status-text"><?php echo esc_html($status); ?></span>
                    </td>
                    <td class="wiw-actions-cell">
                        <button type="button" class="button action-toggle-raw" data-target="<?php echo esc_attr($row_id); ?>">
                            Data
                        </button>
                        <div class="wiw-action-group">
                            <button type="button" class="button button-primary button-small wiw-edit-action" <?php echo $edit_button_style; ?>>
                                Edit Hours
                            </button>
                            <button type="button" class="button button-primary button-small wiw-save-action" style="display:none;">
                                Save
                            </button>
                            <button type="button" class="button button-secondary button-small wiw-cancel-action" style="display:none;">
                                Cancel
                            </button>
                        </div>
                    </td>
                </tr>
                
                <tr id="<?php echo esc_attr($row_id); ?>" style="display:none; background-color: #f9f9f9;">
                    <td colspan="12">
                        <div style="padding: 10px; border: 1px solid #ccc; max-height: 300px; overflow: auto;">
                            <strong>Raw API Data:</strong>
                            <pre style="font-size: 11px;"><?php print_r($time_entry); ?></pre>
                        </div>
                    </td>
                </tr>
                <?php 
            endforeach; // End daily records loop
        endforeach; // End weekly periods loop
    endforeach; // End employee loop
endif; 
?>
