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
            
            // Optional: Temporarily disable the "Approve Period" button 
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
            
            // Restore Edit button and hide Save/Cancel
            $row.find('.wiw-edit-action').show();
            $row.find('.wiw-save-action, .wiw-cancel-action').hide();
            
            // Re-enable the "Approve Period" button
            $('.wiw-approve-period-ui').prop('disabled', false);
        });

        // --- 3. Save Action (AJAX to Update Hours) ---
        $('#wiw-timesheets-table').on('click', '.wiw-save-action', function(e) {
            e.preventDefault();
            
            var $button = $(this);
            var $row = $button.closest('.wiw-daily-record');
            var timeId = $row.data('time-id');
            // Assuming the nonce is stored on the pay period button
            var nonce = $row.closest('table').find('.wiw-approve-period-ui').data('nonce'); 
            
            var $startTimeInput = $row.find('.wiw-start-time');
            var $endTimeInput = $row.find('.wiw-end-time');
            
            var newTimeOnlyStart = $startTimeInput.val(); 
            var newTimeOnlyEnd = $endTimeInput.val();     
            
            var originalFullDateStart = $startTimeInput.data('full-datetime');
            var originalFullDateEnd = $endTimeInput.data('full-datetime');
            
            // Basic validation: ensure new times are in HH:MM format
            var timeRegex = /^\d{1,2}:\d{2}$/;
            if (!newTimeOnlyStart.match(timeRegex) || (originalFullDateEnd && !newTimeOnlyEnd.match(timeRegex))) {
                 alert('Please enter valid clock in and clock out times in HH:MM format (e.g., 10:30).');
                 return;
            }

            // Disable buttons and show loading state
            $button.text('Saving...').prop('disabled', true);
            $row.find('.wiw-cancel-action').prop('disabled', true);

            var data = {
                'action': 'wiw_edit_timesheet_hours', // PHP handler function name
                'security': nonce,
                'time_id': timeId,
                'start_time_new': newTimeOnlyStart,
                'end_time_new': newTimeOnlyEnd,
                'start_datetime_full': originalFullDateStart,
                'end_datetime_full': originalFullDateEnd
            };

            $.post(ajaxurl, data, function(response) {
                if (response.success) {
                    alert('Success! ' + response.data.message);
                    // Reload the page to fetch and display the new calculated hours/status/times
                    location.reload();
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


        // --- 4. Approve Period Action (AJAX to Approve Multiple IDs) ---
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
                'action': 'wiw_approve_timesheet_period', // PHP handler function name
                'security': nonce,
                'time_ids': periodIds // Comma-separated list of IDs
            };

            $.post(ajaxurl, data, function(response) {
                if (response.success) {
                    alert('Success! ' + response.data.message);
                    
                    // Find and update the status of all affected rows (client-side update)
                    var approvedIds = periodIds.split(',');
                    approvedIds.forEach(function(id) {
                        var $row = $('#wiw-record-' + id.trim());
                        $row.find('.wiw-status-text').text('Approved');
                        // Remove the ability to edit an approved timesheet
                        $row.find('.wiw-edit-action').hide();
                    });
                    
                    // Disable the period button since its job is done
                    $button.prop('disabled', true).text('Approved').removeClass('button-primary').addClass('button-secondary');
                    $button.attr('title', 'All timesheets in this period are now approved.');

                } else {
                    alert('Approval Failed: ' + response.data.message);
                    $button.prop('disabled', false).text('Approve Period').attr('title', originalTitle); // Restore button
                }
            }).fail(function(jqXHR, textStatus, errorThrown) {
                alert('AJAX Request Failed: ' + errorThrown);
                $button.prop('disabled', false).text('Approve Period').attr('title', originalTitle); // Restore button
            });
        });
        
        // --- 5. Raw Data Toggle ---
        $('.action-toggle-raw').on('click', function(e) {
            e.preventDefault();
            var targetId = $(this).data('target');
            $('#' + targetId).toggle();
        });

    });
</script>
