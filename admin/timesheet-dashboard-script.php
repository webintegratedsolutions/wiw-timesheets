<script type="text/javascript">
    jQuery(document).ready(function($) {
        
        // --- 1. Edit Hours Action (Toggles Inputs) ---
        $('#wiw-timesheets-table').on('click', '.wiw-edit-action', function(e) {
            e.preventDefault();
            
            var $button = $(this);
            var $row = $button.closest('.wiw-daily-record');
            
            // Hide display spans
            $row.find('.wiw-display-time').hide();
            
            // Show input fields and set focus to the start time
            $row.find('.wiw-edit-input').show();
            $row.find('.wiw-start-time').focus();
            
            // Hide Edit button and show Save/Cancel
            $button.hide();
            $row.find('.wiw-save-action, .wiw-cancel-action').show();
            
            // Temporarily disable other action buttons
            $row.find('.wiw-approve-shift-ui, .action-toggle-raw').prop('disabled', true);
            $('.wiw-approve-period-ui').prop('disabled', true);
        });

        // --- 2. Cancel Action (Reverts View) ---
        $('#wiw-timesheets-table').on('click', '.wiw-cancel-action', function(e) {
            e.preventDefault();
            
            var $row = $(this).closest('.wiw-daily-record');
            
            // Show display spans
            $row.find('.wiw-display-time').show();
            
            // Hide input fields
            $row.find('.wiw-edit-input').hide();
            
            // Restore Edit button, hide Save/Cancel
            $row.find('.wiw-edit-action').show();
            $row.find('.wiw-save-action, .wiw-cancel-action').hide();

            // Re-enable other action buttons
            $row.find('.wiw-approve-shift-ui, .action-toggle-raw').prop('disabled', false);
            $('.wiw-approve-period-ui').prop('disabled', false);
        });

        // --- 3. Save Action (AJAX to Update Hours) ---
        $('#wiw-timesheets-table').on('click', '.wiw-save-action', function(e) {
            e.preventDefault();
            
            var $button = $(this);
            var $row = $button.closest('.wiw-daily-record');
            var timeId = $row.data('time-id');
            // Get nonce from a stable element, like the period button
            var nonce = $row.closest('table').find('.wiw-approve-period-ui:first').data('nonce'); 
            
            var $startTimeInput = $row.find('.wiw-start-time');
            var $endTimeInput = $row.find('.wiw-end-time');
            var $breakMinutesInput = $row.find('.wiw-break-minutes'); 
            
            var newTimeOnlyStart = $startTimeInput.val(); 
            var newTimeOnlyEnd = $endTimeInput.val();     
            var newBreakMinutes = $breakMinutesInput.val(); 

            // Basic validation
            var timeRegex = /^\d{1,2}:\d{2}$/;
            var originalFullDateEnd = $endTimeInput.data('full-datetime');
            
            if (!newTimeOnlyStart.match(timeRegex) || (originalFullDateEnd && !newTimeOnlyEnd.match(timeRegex))) {
                 alert('Please enter valid clock in and clock out times in HH:MM format (e.g., 10:30).');
                 return;
            }
            
            if (isNaN(newBreakMinutes) || newBreakMinutes < 0) {
                 alert('Please enter a valid non-negative number for Breaks in Minutes.');
                 return;
            }

            var originalFullDateStart = $startTimeInput.data('full-datetime');

            // Disable buttons and show loading state
            $button.text('Saving...').prop('disabled', true);
            $row.find('.wiw-cancel-action').prop('disabled', true);

            var data = {
                'action': 'wiw_edit_timesheet_hours', 
                'security': nonce,
                'time_id': timeId,
                'start_time_new': newTimeOnlyStart,
                'end_time_new': newTimeOnlyEnd,
                'break_minutes_new': newBreakMinutes,
                'start_datetime_full': originalFullDateStart,
                'end_datetime_full': originalFullDateEnd
            };

            $.post(ajaxurl, data, function(response) {
                if (response.success) {
                    alert('Success! ' + response.data.message);
                    location.reload(); // Reload to reflect recalculated hours/status
                } else {
                    alert('Save Failed: ' + response.data.message);
                    $button.text('Save').prop('disabled', false);
                    $row.find('.wiw-cancel-action').prop('disabled', false);
                }
            }).fail(function(jqXHR, textStatus, errorThrown) {
                alert('AJAX Request Failed: ' + errorThrown);
                $button.text('Save').prop('disabled', false);
                $row.find('.wiw-cancel-action').prop('disabled', false);
            });
        });

        // --- 4. Approve Single Shift Action (AJAX to Approve One ID) ---
        $('#wiw-timesheets-table').on('click', '.wiw-approve-shift-ui', function(e) {
            e.preventDefault();
            
            var $button = $(this);
            var timeId = $button.data('time-id');
            var nonce = $button.data('nonce');
            var $row = $button.closest('.wiw-daily-record');

            if ($button.is(':disabled') || !timeId) {
                return;
            }
            
            // Disable button and show loading state
            $button.prop('disabled', true).text('Working...');

            var data = {
                'action': 'wiw_approve_single_timesheet', // NEW AJAX ACTION
                'security': nonce,
                'time_id': timeId 
            };

            $.post(ajaxurl, data, function(response) {
                if (response.success) {
                    alert('Success! Shift ' + timeId + ' approved.');
                    
                    // Update Status text
                    $row.find('.wiw-status-text').text('Approved');
                    
                    // Hide the Approve button and Edit button
                    $button.text('Approved').removeClass('button-primary').addClass('button-secondary').prop('disabled', true).hide();
                    $row.find('.wiw-edit-action').hide();


                } else {
                    alert('Approval Failed: ' + response.data.message);
                    $button.prop('disabled', false).text('Approve Shift Hours'); 
                }
            }).fail(function(jqXHR, textStatus, errorThrown) {
                alert('AJAX Request Failed: ' + errorThrown);
                $button.prop('disabled', false).text('Approve Shift Hours');
            });
        });


        // --- 5. Approve Period Action (AJAX to Approve Multiple IDs) ---
        $('#wiw-timesheets-table').on('click', '.wiw-approve-period-ui', function(e) {
            e.preventDefault();
            
            var $button = $(this);
            var periodIds = $button.data('period-ids');
            var nonce = $button.data('nonce');
            var originalTitle = $button.attr('title');

            if ($button.is(':disabled') || !periodIds) {
                alert($button.attr('title') || 'No pending records to approve.');
                return;
            }
            
            // Disable button and show loading state
            $button.prop('disabled', true).text('Approving...');

            var data = {
                'action': 'wiw_approve_timesheet_period', 
                'security': nonce,
                'time_ids': periodIds 
            };

            $.post(ajaxurl, data, function(response) {
                if (response.success) {
                    alert('Success! ' + response.data.message);
                    
                    var approvedIds = periodIds.split(',');
                    approvedIds.forEach(function(id) {
                        var $row = $('#wiw-record-' + id.trim());
                        $row.find('.wiw-status-text').text('Approved');
                        // Hide all action buttons for the newly approved shift
                        $row.find('.wiw-approve-shift-ui, .wiw-edit-action').hide();
                    });
                    
                    $button.prop('disabled', true).text('Approved').removeClass('button-primary').addClass('button-secondary');
                    $button.attr('title', 'All timesheets in this period are now approved.');

                } else {
                    alert('Approval Failed: ' + response.data.message);
                    $button.prop('disabled', false).text('Approve Period').attr('title', originalTitle); 
                }
            }).fail(function(jqXHR, textStatus, errorThrown) {
                alert('AJAX Request Failed: ' + errorThrown);
                $button.prop('disabled', false).text('Approve Period').attr('title', originalTitle);
            });
        });
        
        // --- 6. Raw Data Toggle ---
        $('.action-toggle-raw').on('click', function(e) {
            e.preventDefault();
            var targetId = $(this).data('target');
            $('#' + targetId).toggle();
        });

    });
</script>
