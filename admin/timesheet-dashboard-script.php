<script type="text/javascript">
jQuery(document).ready(function($) {

    // ✅ One stable nonce for all timesheet dashboard AJAX calls
    var wiwTimesheetNonce = '<?php echo esc_js( $timesheet_nonce ); ?>';

    // --- 1. Edit Hours Action (Toggles Inputs) ---
    $('#wiw-timesheets-table').on('click', '.wiw-edit-action', function(e) {
        e.preventDefault();

        var $button = $(this);
        var $row = $button.closest('.wiw-daily-record');

        $row.find('.wiw-display-time').hide();
        $row.find('.wiw-edit-input').show();
        $row.find('.wiw-start-time').focus();

        $button.hide();
        $row.find('.wiw-save-action, .wiw-cancel-action').show();

        $row.find('.wiw-approve-shift-ui, .action-toggle-raw').prop('disabled', true);
        $('.wiw-approve-period-ui').prop('disabled', true);
    });

    // --- 2. Cancel Action (Reverts View) ---
    $('#wiw-timesheets-table').on('click', '.wiw-cancel-action', function(e) {
        e.preventDefault();

        var $row = $(this).closest('.wiw-daily-record');

        $row.find('.wiw-display-time').show();
        $row.find('.wiw-edit-input').hide();

        $row.find('.wiw-edit-action').show();
        $row.find('.wiw-save-action, .wiw-cancel-action').hide();

        $row.find('.wiw-approve-shift-ui, .action-toggle-raw').prop('disabled', false);
        $('.wiw-approve-period-ui').prop('disabled', false);
    });

    // --- 3. Save Action (AJAX to Update Hours) ---
    $('#wiw-timesheets-table').on('click', '.wiw-save-action', function(e) {
        e.preventDefault();

        var $button = $(this);
        var $row = $button.closest('.wiw-daily-record');
        var timeId = $row.data('time-id');

        var $startTimeInput = $row.find('.wiw-start-time');
        var $endTimeInput = $row.find('.wiw-end-time');
        var $breakMinutesInput = $row.find('.wiw-break-minutes');

        var newTimeOnlyStart = $startTimeInput.val();
        var newTimeOnlyEnd = $endTimeInput.val();
        var newBreakMinutes = $breakMinutesInput.val();

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

        $button.text('Saving...').prop('disabled', true);
        $row.find('.wiw-cancel-action').prop('disabled', true);

        var data = {
            action: 'wiw_edit_timesheet_hours',
            security: wiwTimesheetNonce,
            time_id: timeId,
            start_time_new: newTimeOnlyStart,
            end_time_new: newTimeOnlyEnd,
            break_minutes_new: newBreakMinutes,
            start_datetime_full: originalFullDateStart,
            end_datetime_full: originalFullDateEnd
        };

        $.post(ajaxurl, data, function(response) {
            if (response.success) {
                alert('Success! ' + response.data.message);
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

    // --- 4. Approve Single Shift Action ---
    $('#wiw-timesheets-table').on('click', '.wiw-approve-shift-ui', function(e) {
        e.preventDefault();

        var $button = $(this);
        var timeId = $button.data('time-id');
        var $row = $button.closest('.wiw-daily-record');

        if ($button.is(':disabled') || !timeId) return;

        $button.prop('disabled', true).text('Working...');

        var data = {
            action: 'wiw_approve_single_timesheet',
            security: wiwTimesheetNonce,
            time_id: timeId
        };

        $.post(ajaxurl, data, function(response) {
            if (response.success) {
                alert('Success! Shift ' + timeId + ' approved.');

                $row.find('.wiw-status-text').text('Approved');
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

    // --- 5. Approve Period Action ---
    $('#wiw-timesheets-table').on('click', '.wiw-approve-period-ui', function(e) {
        e.preventDefault();

        var $button = $(this);
        var periodIds = $button.data('period-ids');
        var originalTitle = $button.attr('title');

        if ($button.is(':disabled') || !periodIds) {
            alert($button.attr('title') || 'No pending records to approve.');
            return;
        }

        $button.prop('disabled', true).text('Approving...');

        var data = {
            action: 'wiw_approve_timesheet_period',
            security: wiwTimesheetNonce,
            time_ids: periodIds
        };

        $.post(ajaxurl, data, function(response) {
            if (response.success) {
                alert('Success! ' + response.data.message);

                var approvedIds = periodIds.split(',');
                approvedIds.forEach(function(id) {
                    var $row = $('#wiw-record-' + id.trim());
                    $row.find('.wiw-status-text').text('Approved');
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
