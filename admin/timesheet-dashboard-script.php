<script type="text/javascript">
jQuery(document).ready(function($) {

    // Read-only dashboard: only raw data toggle remains.
    $('#wiw-timesheets-table').on('click', '.action-toggle-raw', function(e) {
        e.preventDefault();
        var targetId = $(this).data('target');
        $('#' + targetId).toggle();
    });

});
</script>
