<script type="text/javascript">
    jQuery(document).ready(function($) {
        
        // --- 1. Edit Hours Action ---
        $('#wiw-timesheets-table').on('click', '.wiw-edit-action', function(e) {
            e.preventDefault();
            var $row = $(this).closest('.wiw-daily-record');
            $row.find('.wiw-display-time').hide();
            $row.find('.wiw-edit-input').show();
            $(this).hide();
            $row.find('.wiw-save-action, .wiw-cancel-action').show();
        });

        // --- 2. Cancel Action ---
        $('#wiw-timesheets-table').on('click', '.wiw-cancel-action', function(e) {
            e.preventDefault();
            var $row = $(this).closest('.wiw-daily-record');
            $row.find('.wiw-display-time').show();
            $row.find('.wiw-edit-input').hide();
            $row.find('.wiw-edit-action').show();
            $row.find('.wiw-save-action, .wiw-cancel-action').hide();
        });

        // --- 3. Save Action (AJAX) ---
        $('#wiw-timesheets-table').on('click', '.wiw-save-action', function(e) {
            e.preventDefault();
            var $button = $(this);
            var $row = $button.closest('.wiw-daily-record');
            var data = {
                'action': 'wiw_edit_timesheet_hours', 
                'security': $row.closest('table').find('.wiw-approve-period-ui:first').data('nonce'),
                'time_id': $row.data('time-id'),
                'start_time_new': $row.find('.wiw-start-time').val(),
                'end_time_new': $row.find('.wiw-end-time').val(),
                'break_minutes_new': $row.find('.wiw-break-minutes').val(),
                'start_datetime_full': $row.find('.wiw-start-time').data('full-datetime'),
                'end_datetime_full': $row.find('.wiw-end-time').data('full-datetime')
            };

            $button.text('Saving...').prop('disabled', true);
            $.post(ajaxurl, data, function(response) {
                if (response.success) { location.reload(); } 
                else { alert('Error: ' + response.data.message); $button.text('Save').prop('disabled', false); }
            });
        });

        // --- 4. Single Shift Approve ---
        $('#wiw-timesheets-table').on('click', '.wiw-approve-shift-ui', function(e) {
            var $button = $(this);
            $button.prop('disabled', true).text('Working...');
            $.post(ajaxurl, {
                'action': 'wiw_approve_single_timesheet',
                'security': $button.data('nonce'),
                'time_id': $button.data('time-id')
            }, function(response) {
                if (response.success) {
                    $button.closest('.wiw-daily-record').find('.wiw-status-text').text('Approved');
                    $button.hide();
                } else { alert('Failed: ' + response.data.message); $button.prop('disabled', false).text('Approve Shift'); }
            });
        });

        // --- 5. Raw Data Toggle ---
        $('.action-toggle-raw').on('click', function(e) {
            e.preventDefault();
            $('#' + $(this).data('target')).toggle();
        });
    });
</script>
