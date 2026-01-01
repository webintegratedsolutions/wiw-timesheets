<?php
// Template: Local Timesheets admin page.
// Variables are prepared in admin_local_timesheets_page() before this file is included.
?>     
        
        <div class="wrap">
            <h1>📁 Local Timesheets (Database View)</h1>
<p>This page displays timesheets stored locally in WordPress, grouped by Employee and Pay Period.</p>
        <?php

        if ( $selected_id > 0 ) {
            $header = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT * FROM {$table_timesheets} WHERE id = %d",
                    $selected_id
                )
            );

            if ( $header ) {
                $list_url = remove_query_arg( 'timesheet_id' );
                ?>
                <p>
                    <a href="<?php echo esc_url( $list_url ); ?>" class="button">← Back to Local Timesheets List</a>
                </p>

                <?php
                if ( isset( $_GET['reset_success'] ) ) : ?>
                    <div class="notice notice-success is-dismissible"><p>✅ Local timesheet reset from When I Work successfully.</p></div>
                <?php endif;

                if ( isset( $_GET['reset_error'] ) && $_GET['reset_error'] !== '' ) : ?>
                    <div class="notice notice-error is-dismissible">
                        <p><strong>❌ Reset failed:</strong> <?php echo esc_html( sanitize_text_field( wp_unslash( $_GET['reset_error'] ) ) ); ?></p>
                    </div>
                <?php endif; ?>

<?php if ( isset( $_GET['finalize_success'] ) ) : ?>
    <div class="notice notice-success is-dismissible"><p>✅ Timesheet signed off and finalized.</p></div>
<?php endif; ?>

<?php if ( isset( $_GET['finalize_error'] ) && $_GET['finalize_error'] !== '' ) : ?>
    <div class="notice notice-error is-dismissible">
        <p><strong>❌ Sign off failed:</strong> <?php echo esc_html( sanitize_text_field( wp_unslash( $_GET['finalize_error'] ) ) ); ?></p>
    </div>
<?php endif; ?>

<?php $is_timesheet_approved = ( strtolower( (string) ( $header->status ?? '' ) ) === 'approved' ); ?>

<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin: 10px 0 20px;">
    <input type="hidden" name="action" value="wiw_reset_local_timesheet" />
    <input type="hidden" name="timesheet_id" value="<?php echo esc_attr( (int) $header->id ); ?>" />
    <?php wp_nonce_field( 'wiw_reset_local_timesheet', 'wiw_reset_nonce' ); ?>

    <?php if ( $is_timesheet_approved ) : ?>
        <button type="button" class="button button-secondary" disabled="disabled"
            style="opacity:0.6;cursor:not-allowed;"
            title="This timesheet has been finalized and can no longer be reset.">
            Timesheet Finalized
        </button>
    <?php else : ?>
        <button type="submit" class="button button-secondary"
            onclick="return confirm('Reset will discard ALL local edits for this timesheet and restore the original data from When I Work. Continue?');">
            Reset from API
        </button>
    <?php endif; ?>
</form>


                <h2>Timesheet #<?php echo esc_html( $header->id ); ?> Details</h2>
                <?php
// === WIWTS SIGN-OFF ELIGIBILITY CHECK START ===
$all_entries_approved = true;

// Initially assume sign-off is enabled
$signoff_enabled = ( $all_entries_approved && ! $is_timesheet_approved );

$entry_statuses = $wpdb->get_col(
    $wpdb->prepare(
        "SELECT status
         FROM {$table_timesheet_entries}
         WHERE timesheet_id = %d",
        (int) $header->id
    )
);

// If there are no entries, or any non-approved entry, disable sign off
if ( empty( $entry_statuses ) ) {
    $all_entries_approved = false;
} else {
    foreach ( $entry_statuses as $st ) {
        if ( strtolower( (string) $st ) !== 'approved' ) {
            $all_entries_approved = false;
            break;
        }
    }
}
// === WIWTS SIGN-OFF ELIGIBILITY CHECK END ===
?>

                <table class="widefat striped" style="max-width: 900px;">
                    <tbody>
                        <tr>
    <th scope="row" style="width: 200px;">Timesheet ID</th>
    <td>#<?php echo esc_html( $header->id ); ?></td>
</tr>
                        <tr>
                            <th scope="row" style="width: 200px;">Employee</th>
                            <td><?php echo esc_html( $header->employee_name ); ?> (ID: <?php echo esc_html( $header->employee_id ); ?>)</td>
                        </tr>
                        <tr>
                            <th scope="row">Location</th>
                            <td>
                                <?php echo esc_html( $header->location_name ); ?>
                                <?php if ( ! empty( $header->location_id ) ) : ?>
                                    (ID: <?php echo esc_html( $header->location_id ); ?>)
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">Pay Period</th>
                            <td>
                                <?php echo esc_html( $header->week_start_date ); ?>
                                <?php if ( ! empty( $header->week_end_date ) ) : ?>
                                    &nbsp;to&nbsp;<?php echo esc_html( $header->week_end_date ); ?>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php
// === WIWTS SHIFTS SUMMARY ROW START ===
$entries_count = (int) $wpdb->get_var(
    $wpdb->prepare(
        "SELECT COUNT(*) FROM {$table_timesheet_entries} WHERE timesheet_id = %d",
        (int) $header->id
    )
);

// Pull distinct shift IDs from local entries (if your entries table stores shift_id)
$shift_ids = $wpdb->get_col(
    $wpdb->prepare(
        "SELECT DISTINCT wiw_shift_id
         FROM {$table_timesheet_entries}
         WHERE timesheet_id = %d
           AND wiw_shift_id IS NOT NULL
           AND wiw_shift_id <> 0
         ORDER BY wiw_shift_id ASC",
        (int) $header->id
    )
);

$shift_ids = array_values( array_unique( array_filter( array_map( 'absint', (array) $shift_ids ) ) ) );

$shift_ids_display = 'N/A';
if ( ! empty( $shift_ids ) ) {
    $shift_ids_display = implode( ', ', $shift_ids );
}
// === WIWTS SHIFTS SUMMARY ROW END ===
?>

<tr>
    <th scope="row">Shifts</th>
    <td>
        <?php echo esc_html( $entries_count ); ?>
        <?php if ( ! empty( $shift_ids ) ) : ?>
            — (Shift Ids: <?php echo esc_html( $shift_ids_display ); ?>)
        <?php else : ?>
            — (Shift Ids: N/A)
        <?php endif; ?>
    </td>
</tr>

                        <tr>
                            <th scope="row">Totals</th>
<td>
    <?php
    // Calculate payable total directly from entries (authoritative)
    $payable_total = (float) $wpdb->get_var(
        $wpdb->prepare(
            "SELECT COALESCE(SUM(payable_hours), 0)
             FROM {$table_timesheet_entries}
             WHERE timesheet_id = %d",
            (int) $header->id
        )
    );
    ?>
    Scheduled: <?php echo esc_html( number_format( (float) $header->total_scheduled_hours, 2 ) ); ?> hrs,
    Clocked: <span id="wiw-local-header-total-clocked">
        <?php echo esc_html( number_format( (float) $header->total_clocked_hours, 2 ) ); ?>
    </span> hrs,
    Payable: <?php echo esc_html( number_format( $payable_total, 2 ) ); ?> hrs
</td>

                        </tr>
                        <tr>
                            <th scope="row">Status</th>
                            <td><?php echo esc_html( $header->status ); ?></td>
                        </tr>
<tr>
    <th scope="row">Created / Updated</th>
    <td>
        Created: <?php echo esc_html( $header->created_at ); ?><br/>
        Updated: <?php echo esc_html( $header->updated_at ); ?>
    </td>
</tr>

<?php
// === WIWTS SIGN OFF ENABLE CHECK (ROBUST) START ===
$table_entries = $wpdb->prefix . 'wiw_timesheet_entries';

$is_timesheet_approved = ( strtolower( (string) ( $header->status ?? '' ) ) === 'approved' );

// Count any records not approved (case-insensitive)
$unapproved_count = (int) $wpdb->get_var(
    $wpdb->prepare(
        "SELECT COUNT(*)
         FROM {$table_entries}
         WHERE timesheet_id = %d
           AND LOWER(COALESCE(status,'')) <> 'approved'",
        (int) $header->id
    )
);

// Count total records (so we don't allow sign off when there are zero rows)
$total_count = (int) $wpdb->get_var(
    $wpdb->prepare(
        "SELECT COUNT(*)
         FROM {$table_entries}
         WHERE timesheet_id = %d",
        (int) $header->id
    )
);

// Enabled only if ALL daily records approved, there is at least 1 record, and header not already approved
$signoff_enabled = ( ! $is_timesheet_approved && $total_count > 0 && $unapproved_count === 0 );
// === WIWTS SIGN OFF ENABLE CHECK (ROBUST) END ===
?>

<tr>
    <th scope="row">Sign Off</th>
    <td>
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin:0;">
            <input type="hidden" name="action" value="wiw_finalize_local_timesheet" />
            <input type="hidden" name="timesheet_id" value="<?php echo esc_attr( (int) $header->id ); ?>" />
            <?php wp_nonce_field( 'wiw_finalize_local_timesheet', 'wiw_finalize_nonce' ); ?>

            <?php if ( $signoff_enabled ) : ?>
                <button type="submit"
                        class="button button-primary"
                        onclick="return confirm('Sign off this timesheet?\n\nOnce signed off, changes can no longer be made to any time records for this pay period.');">
                    Sign Off
                </button>
            <?php else : ?>
                <button type="button"
                        class="button"
                        disabled="disabled"
                        style="background:#ccc;border-color:#ccc;color:#666;cursor:not-allowed;opacity:1;"
                        title="<?php
                            echo esc_attr(
                                $is_timesheet_approved
                                    ? 'Timesheet already finalized'
                                    : 'All daily records must be approved before sign off'
                            );
                        ?>">
                    Sign Off
                </button>
            <?php endif; ?>

            <?php if ( $is_timesheet_approved ) : ?>
                <p style="margin:6px 0 0;color:#666;font-size:12px;">This timesheet has been finalized.</p>
            <?php elseif ( ! $signoff_enabled ) : ?>
                <p style="margin:6px 0 0;color:#666;font-size:12px;">All daily records must be approved before sign off.</p>
            <?php endif; ?>
        </form>
    </td>
</tr>
                    </tbody>
                </table>

                <h3 style="margin-top: 30px;">Daily Entries</h3>
                <?php
                $entries = $wpdb->get_results(
                    $wpdb->prepare(
                        "SELECT * FROM {$table_timesheet_entries}
                         WHERE timesheet_id = %d
                         ORDER BY date ASC, clock_in ASC",
                        $header->id
                    )
                );

                // ✅ Prefetch flags for all entries in one query (avoid N+1 queries)
$table_flags = $wpdb->prefix . 'wiw_timesheet_flags';
$flags_map   = array(); // [wiw_time_id] => array of flag rows

$wiw_ids = array();
foreach ( (array) $entries as $e ) {
    if ( ! empty( $e->wiw_time_id ) ) {
        $wiw_ids[] = (int) $e->wiw_time_id;
    }
}
$wiw_ids = array_values( array_unique( array_filter( $wiw_ids ) ) );

if ( ! empty( $wiw_ids ) ) {
    $in = implode( ',', array_map( 'absint', $wiw_ids ) );

    $flag_rows = $wpdb->get_results(
        "SELECT id, wiw_time_id, flag_type, description, flag_status, created_at, updated_at
         FROM {$table_flags}
         WHERE wiw_time_id IN ({$in})
         ORDER BY wiw_time_id ASC, id ASC"
    );

    foreach ( (array) $flag_rows as $fr ) {
        $tid = (int) ( $fr->wiw_time_id ?? 0 );
        if ( ! $tid ) { continue; }
        if ( ! isset( $flags_map[ $tid ] ) ) {
            $flags_map[ $tid ] = array();
        }
        $flags_map[ $tid ][] = $fr;
    }
}

// ✅ Prefetch edit logs for all entries in one query (avoid N+1 queries)
$table_logs = $wpdb->prefix . 'wiw_timesheet_edit_logs';
$logs_map   = array(); // [wiw_time_id] => array of log rows

if ( ! empty( $wiw_ids ) ) {
    $in_logs = implode( ',', array_map( 'absint', $wiw_ids ) );

    $log_rows = $wpdb->get_results(
        "SELECT id, wiw_time_id, edit_type, old_value, new_value, edited_by_user_login, edited_by_display_name, created_at
         FROM {$table_logs}
         WHERE wiw_time_id IN ({$in_logs})
         ORDER BY wiw_time_id ASC, id DESC"
    );

    foreach ( (array) $log_rows as $lr ) {
        $tid = (int) ( $lr->wiw_time_id ?? 0 );
        if ( ! $tid ) { continue; }
        if ( ! isset( $logs_map[ $tid ] ) ) {
            $logs_map[ $tid ] = array();
        }
        $logs_map[ $tid ][] = $lr;
    }
}


                if ( empty( $entries ) ) : ?>
                    <p>No entries found for this timesheet.</p>
                <?php else : ?>
<table class="widefat fixed striped">
<thead>
    <tr>
        <th width="7%">Date</th>
        <th width="9%">Time ID</th>
        <th width="12%">Location</th>
        <th width="13%">Sched</th>
        <th width="8%">In</th>
        <th width="8%">Out</th>
        <th width="6%">Break</th>
        <th width="6%">Sched</th>
        <th width="6%">Clocked</th>
        <th width="6%">Payable</th>
        <th width="6%">Status</th>
        <th width="13%">Actions</th>
    </tr>
</thead>

                        <tbody>
                        <?php foreach ( $entries as $entry ) : ?>
                            <?php
                            $fmt = function( $dt_string ) {
                                if ( empty( $dt_string ) ) { return 'N/A'; }
                                $tz_string = get_option( 'timezone_string' ) ?: 'UTC';
                                $tz = new DateTimeZone( $tz_string );
                                try {
                                    $dt = new DateTime( (string) $dt_string, $tz );
                                    return $dt->format( 'g:ia' );
                                } catch ( Exception $e ) {
                                    return 'N/A';
                                }
                            };

                            $scheduled_range = 'N/A';
                            if ( ! empty( $entry->scheduled_start ) && ! empty( $entry->scheduled_end ) ) {
                                $scheduled_range = $fmt( $entry->scheduled_start ) . ' to ' . $fmt( $entry->scheduled_end );
                            }

                            $clock_in_display  = $fmt( $entry->clock_in );
                            $clock_out_display = $fmt( $entry->clock_out );

$payable_hours_val = isset( $entry->payable_hours ) ? (float) $entry->payable_hours : (float) $entry->clocked_hours;

// Location display (entry-level; timesheets now include multiple locations)
$entry_location_name = ( isset( $entry->location_name ) && (string) $entry->location_name !== '' ) ? (string) $entry->location_name : 'N/A';
$entry_location_id   = ( isset( $entry->location_id ) && (string) $entry->location_id !== '' ) ? (string) $entry->location_id : '';
?>
<tr>

    <td><?php echo esc_html( $entry->date ); ?></td>
    <td><?php echo esc_html( (int) $entry->wiw_time_id ); ?></td>

    <td>
        <?php echo esc_html( $entry_location_name ); ?>
        <?php if ( $entry_location_id !== '' ) : ?>
            <br/><small>(ID: <?php echo esc_html( $entry_location_id ); ?>)</small>
        <?php endif; ?>
    </td>

    <td><?php echo esc_html( $scheduled_range ); ?></td>

                                <td class="wiw-local-clock-in"
                                    data-time="<?php echo esc_attr( $entry->clock_in ? substr( (string) $entry->clock_in, 11, 5 ) : '' ); ?>">
                                    <?php echo esc_html( $clock_in_display ); ?>
                                </td>

                                <td class="wiw-local-clock-out"
                                    data-time="<?php echo esc_attr( $entry->clock_out ? substr( (string) $entry->clock_out, 11, 5 ) : '' ); ?>">
                                    <?php echo esc_html( $clock_out_display ); ?>
                                </td>

                                <td class="wiw-local-break-min" data-break="<?php echo esc_attr( (int) $entry->break_minutes ); ?>">
                                    <?php echo esc_html( (int) $entry->break_minutes ); ?>
                                </td>

                                <td><?php echo esc_html( number_format( (float) $entry->scheduled_hours, 2 ) ); ?></td>

                                <td class="wiw-local-clocked-hours">
                                    <?php echo esc_html( number_format( (float) $entry->clocked_hours, 2 ) ); ?>
                                </td>

                                <td class="wiw-local-payable-hours">
                                    <?php echo esc_html( number_format( (float) $payable_hours_val, 2 ) ); ?>
                                </td>

                                <td class="wiw-local-status" data-status="<?php echo esc_attr( (string) $entry->status ); ?>">
    <?php echo esc_html( (string) $entry->status ); ?>
</td>

<td>
<?php
$entry_status = (string) ( $entry->status ?? 'pending' );
$is_approved  = ( strtolower( $entry_status ) === 'approved' );

// Flags data already prepared above
$wiw_time_id_for_flags = (int) ( $entry->wiw_time_id ?? 0 );

// Safe access to flags for this entry
$row_flags            = isset( $flags_map[ $wiw_time_id_for_flags ] ) ? (array) $flags_map[ $wiw_time_id_for_flags ] : array();
$flags_count          = count( $row_flags );
$flags_row_id         = 'wiw-local-flags-' . (int) $entry->id;

// === WIWTS APPROVE COLOR BY FLAGS ICON LOGIC START ===
$has_active_flags = false;

if ( $flags_count ) {
    foreach ( $row_flags as $fr ) {
        $status_raw = isset( $fr->flag_status ) ? (string) $fr->flag_status : '';
        $status     = strtolower( trim( $status_raw ) );

        if ( $status !== 'resolved' ) {
            $has_active_flags = true;
            break;
        }
    }
}

$approve_bg   = $has_active_flags ? '#dba617' : '#46b450';
$approve_bord = $approve_bg;
// === WIWTS APPROVE COLOR BY FLAGS ICON LOGIC END ===

?>

<?php if ( ! $is_approved ) : ?>
    <div style="display:flex;flex-direction:column;align-items:flex-start;gap:6px;">

        <button type="button"
                class="button button-small wiw-local-edit-entry"
                data-entry-id="<?php echo esc_attr( $entry->id ); ?>">
            Edit
        </button>

        <button type="button"
                class="button button-small wiw-local-approve-entry"
                data-entry-id="<?php echo esc_attr( $entry->id ); ?>"
                style="background:<?php echo esc_attr( $approve_bg ); ?>;border-color:<?php echo esc_attr( $approve_bord ); ?>;color:#fff;">
            Approve Pay Period
        </button>

    </div>
<?php else : ?>
    <div style="display:flex;flex-direction:column;align-items:flex-start;gap:6px;">

        <button type="button"
                class="button button-small wiw-local-approve-entry wiw-local-approved"
                disabled="disabled"
                style="background:#2271b1;border-color:#2271b1;color:#fff;opacity:1;cursor:default;">
            Pay Period Approved
        </button>

    </div>
<?php endif; ?>

</td>
                               
</tr>

<!-- ✅ Flags button moved to its own row below, aligned left -->
<tr class="wiw-local-flags-actions-row">
    <td style="text-align:left;">
<?php
// === WIWTS FLAGS ICON (ROW-SAFE) START ===
$wiw_time_id_for_flags = (int) ( $entry->wiw_time_id ?? 0 );
$row_flags            = isset( $flags_map[ $wiw_time_id_for_flags ] ) ? (array) $flags_map[ $wiw_time_id_for_flags ] : array();
$flags_count          = count( $row_flags );

$has_active = false;
if ( $flags_count ) {
    foreach ( $row_flags as $fr ) {
        $status_raw = isset( $fr->flag_status ) ? (string) $fr->flag_status : '';
        $status     = strtolower( trim( $status_raw ) );
if ( $status !== 'resolved' ) {

            $has_active = true;
            break;
        }
    }
}

$flags_icon = '🚩';
if ( $flags_count && ! $has_active ) {
    $flags_icon = '✅';
}
// === WIWTS FLAGS ICON (ROW-SAFE) END ===
?>

<button type="button"
        class="button button-small wiw-local-toggle-flags"
        data-target="<?php echo esc_attr( $flags_row_id ); ?>"
        <?php echo $flags_count ? '' : 'disabled="disabled"'; ?>
        aria-label="<?php echo esc_attr( $flags_count ? 'View flags' : 'No flags' ); ?>">
    <?php if ( $flags_count ) : ?>
        <?php echo esc_html( $flags_icon ); ?>
        Flags (<?php echo (int) $flags_count; ?>)
    <?php else : ?>
        No Flags
    <?php endif; ?>
</button>

<?php
$wiw_time_id_for_logs = (int) ( $entry->wiw_time_id ?? 0 );
$row_logs            = isset( $logs_map[ $wiw_time_id_for_logs ] ) ? (array) $logs_map[ $wiw_time_id_for_logs ] : array();
$logs_count          = count( $row_logs );

$logs_row_id         = 'wiw-local-logs-' . (int) $entry->id;
?>

    </td>
   <td colspan="11"></td>
</tr>

<!-- ✅ Hidden flags details row toggled by the button above -->
<tr id="<?php echo esc_attr( $flags_row_id ); ?>" style="display:none; background-color:#f9f9f9;">
    <td colspan="12">
        <div style="padding:10px; border:1px solid #ddd;">
            <?php if ( empty( $row_flags ) ) : ?>
                <em>No flags for this record.</em>
            <?php else : ?>
                <ul style="margin:0; padding-left:18px;">
                    <?php foreach ( $row_flags as $fr ) : ?>
<li>
    <strong><?php echo esc_html( (string) ( $fr->flag_type ?? '' ) ); ?></strong>
    — <?php
$status_raw = ( isset( $fr->flag_status ) && $fr->flag_status === 'resolved' ) ? 'resolved' : 'active';

// Display label mapping
$status_label = ( $status_raw === 'resolved' ) ? 'resolved' : 'unresolved';
$color        = ( $status_raw === 'resolved' ) ? 'green' : 'orange';

echo esc_html( (string) $fr->description ) .
     ' <span style="font-weight:600;color:' . esc_attr( $color ) . ';">(' .
     esc_html( $status_label ) .
     ')</span>';

    ?>
</li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
    </td>
</tr>
                            </tr>

                        <?php endforeach; ?>

                        </tbody>
                    </table>

<?php
// ✅ Unified Edit Logs table (under Daily Entries)
$timesheet_logs = $wpdb->get_results(
    $wpdb->prepare(
        "SELECT id, wiw_time_id, edit_type, old_value, new_value, edited_by_user_login, edited_by_display_name, created_at
         FROM {$table_logs}
         WHERE timesheet_id = %d
         ORDER BY id DESC",
        (int) $header->id
    )
);
?>

<h3 style="margin-top: 30px;">📝 Edit Logs</h3>

<?php if ( empty( $timesheet_logs ) ) : ?>
    <p>No edit logs found for this timesheet.</p>
<?php else : ?>
    <table class="widefat fixed striped" style="margin-top: 10px;">
        <thead>
            <tr>
                <th width="12%">Time Record ID</th>
                <th width="16%">Edit Type</th>
                <th width="18%">Old Value</th>
                <th width="18%">New Value</th>
                <th width="18%">Edited By</th>
                <th width="18%">Date</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ( $timesheet_logs as $lr ) : ?>
                <?php $who = (string) ( $lr->edited_by_display_name ?: $lr->edited_by_user_login ); ?>
                <tr>
                    <td><?php echo esc_html( (int) ( $lr->wiw_time_id ?? 0 ) ); ?></td>
                    <td><?php echo esc_html( (string) ( $lr->edit_type ?? '' ) ); ?></td>
                    <td><code><?php echo esc_html( (string) ( $lr->old_value ?? '' ) ); ?></code></td>
                    <td><code><?php echo esc_html( (string) ( $lr->new_value ?? '' ) ); ?></code></td>
                    <td><?php echo esc_html( $who ); ?></td>
                    <td><?php echo esc_html( (string) ( $lr->created_at ?? '' ) ); ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>


                    <?php
                    $local_edit_nonce = wp_create_nonce( 'wiw_local_edit_entry' );
                    // === WIWTS APPROVE TIME RECORD NONCE ADD START ===
$local_approve_nonce = wp_create_nonce( 'wiw_local_approve_entry' );
// === WIWTS APPROVE TIME RECORD NONCE ADD END ===

                    ?>
                    <script type="text/javascript">
                    jQuery(function($) {

// Toggle flags row
$(document).on('click', '.wiw-local-toggle-flags', function(e) {
    e.preventDefault();
    var targetId = $(this).data('target');
    if (targetId) {
        $('#' + targetId).toggle();
    }
});

// Toggle logs row
$(document).on('click', '.wiw-local-toggle-logs', function(e) {
    e.preventDefault();
    var targetId = $(this).data('target');
    if (targetId) {
        $('#' + targetId).toggle();
    }
});


                        // Edit entry button handler
                        $('.wiw-local-edit-entry').on('click', function(e) {
                            e.preventDefault();

                            var $btn    = $(this);
                            var $row    = $btn.closest('tr');
                            var entryId = $btn.data('entry-id');

                            var $cellIn   = $row.find('.wiw-local-clock-in');
                            var $cellOut  = $row.find('.wiw-local-clock-out');
                            var $cellHrs  = $row.find('.wiw-local-clocked-hours');
                            var $cellPay  = $row.find('.wiw-local-payable-hours');

                            var $cellBreak = $row.find('.wiw-local-break-min');
                            var currentBreak = $cellBreak.data('break');
                            if (currentBreak === undefined || currentBreak === null) currentBreak = 0;
                            currentBreak = String(currentBreak);

                            var currentIn  = $cellIn.data('time')  || '';
                            var currentOut = $cellOut.data('time') || '';

                            var newIn = window.prompt('Enter new Clock In time (HH:MM, 24-hour)', currentIn);
                            if (!newIn) return;

                            var newOut = window.prompt('Enter new Clock Out time (HH:MM, 24-hour)', currentOut);
                            if (!newOut) return;

                            var newBreak = window.prompt('Enter Break minutes (0 or more)', currentBreak);
                            if (newBreak === null) return;

                            newBreak = String(newBreak).trim();
                            if (newBreak === '') newBreak = '0';

                            if (!/^\d+$/.test(newBreak)) {
                                alert('Break minutes must be a whole number (e.g., 0, 15, 30).');
                                return;
                            }

                            $.post(ajaxurl, {
                                action: 'wiw_local_update_entry',
                                security: '<?php echo esc_js( $local_edit_nonce ); ?>',
                                entry_id: entryId,
                                clock_in_time: newIn,
                                clock_out_time: newOut,
                                break_minutes: newBreak
                            }).done(function(resp) {
    if (!resp || !resp.success) {
        alert((resp && resp.data && resp.data.message) ? resp.data.message : 'Update failed.');
        return;
    }

    if (resp.data.clock_in_display) {
        $cellIn.text(resp.data.clock_in_display).data('time', newIn);
    }

    if (resp.data.clock_out_display) {
        $cellOut.text(resp.data.clock_out_display).data('time', newOut);
    }

    if (resp.data.break_minutes_display !== undefined) {
        $cellBreak
            .text(resp.data.break_minutes_display)
            .data('break', parseInt(resp.data.break_minutes_display, 10));
    }

    if (resp.data.clocked_hours_display) {
        $cellHrs.text(resp.data.clocked_hours_display);
    }

    if (resp.data.payable_hours_display) {
        $cellPay.text(resp.data.payable_hours_display);
    }

    if (resp.data.header_total_clocked_display) {
        $('#wiw-local-header-total-clocked').text(resp.data.header_total_clocked_display);
    }

    // ✅ Force refresh so flags + edit logs update
setTimeout(function () {
    window.location.reload();
}, 0);

}).fail(function() {
                                alert('AJAX error updating entry.');
                            });

                        });
                    });

// === WIWTS APPROVE TIME RECORD JS ADD START ===
jQuery(document).on('click', '.wiw-local-approve-entry', function(e) {
    e.preventDefault();

    var $btn = jQuery(this);
    if ($btn.is(':disabled') || $btn.hasClass('wiw-local-approved')) {
        return;
    }

    var entryId = $btn.data('entry-id');
    if (!entryId) return;

// Build confirmation message
var confirmMsg = 'Approve this time record?\n\nThis will finalize the record.';

// 🔎 Check for active flags in the flags details row
var flagsRowId = 'wiw-local-flags-' + entryId;
var $flagsRow  = jQuery('#' + flagsRowId);

if ($flagsRow.length) {
    var activeFlags = [];

    $flagsRow.find('li').each(function () {
        var text = jQuery(this).text();
        if (text.toLowerCase().includes('(active)')) {
            activeFlags.push('• ' + text.replace(/\s*\(active\)\s*/i, '').trim());
        }
    });

    if (activeFlags.length) {
        confirmMsg += '\n\n⚠️ ACTIVE FLAGS DETECTED:\n' + activeFlags.join('\n');
    }
}

if (!window.confirm(confirmMsg)) {
    return;
}

    jQuery.post(ajaxurl, {
        action: 'wiw_local_approve_entry',
        security: '<?php echo esc_js( $local_approve_nonce ); ?>',
        entry_id: entryId
    }).done(function(resp) {
        if (!resp || !resp.success) {
            alert((resp && resp.data && resp.data.message) ? resp.data.message : 'Approval failed.');
            return;
        }

        // Update UI immediately
        var $row = $btn.closest('tr');
        $row.find('.wiw-local-status').text('approved').attr('data-status', 'approved');

        // Remove Edit button if present
        $row.find('.wiw-local-edit-entry').remove();

        // Turn Approve into disabled "Approved" (blue)
        $btn
            .text('Approved')
            .addClass('wiw-local-approved')
            .prop('disabled', true)
            .css({
                background: '#2271b1',
                borderColor: '#2271b1',
                color: '#fff',
                opacity: 1,
                cursor: 'default',
                marginTop: 0
            });

        // Refresh so Edit Logs table reflects the new entry
        window.location.reload();
    }).fail(function() {
        alert('AJAX error approving entry.');
    });
});
// === WIWTS APPROVE TIME RECORD JS ADD END ===

                    </script>
                <?php endif; ?>

                <?php
            } else {
                ?>
                <div class="notice notice-error">
                    <p>Timesheet not found.</p>
                </div>
                <?php
            }

            echo '</div>'; // .wrap
            return;
        }

        // No specific ID selected: show list of headers (GROUPED like main dashboard)
        $headers = $wpdb->get_results(
            "SELECT * FROM {$table_timesheets}
             ORDER BY employee_name ASC, week_start_date DESC, location_name ASC
             LIMIT 500"
        );

        if ( empty( $headers ) ) : ?>
            <div class="notice notice-warning">
                <p>No local timesheets found. Visit the main WIW Timesheets Dashboard to fetch and sync data.</p>
            </div>
        <?php else :

            $ids = array_map( static function( $r ) { return (int) $r->id; }, $headers );
            $ids = array_filter( $ids );

            $totals_map = array();
            if ( ! empty( $ids ) ) {
                $in = implode( ',', array_map( 'absint', $ids ) );

$rows = $wpdb->get_results(
    "SELECT timesheet_id,
            COALESCE(SUM(break_minutes), 0) AS break_total,
            COALESCE(SUM(payable_hours), 0) AS payable_total,
            MIN(date) AS min_date,

            MIN(scheduled_start) AS min_scheduled_start,
            MAX(scheduled_end)   AS max_scheduled_end,

            MIN(clock_in)  AS min_clock_in,
            MAX(clock_out) AS max_clock_out

     FROM {$table_timesheet_entries}
     WHERE timesheet_id IN ({$in})
     GROUP BY timesheet_id"
);


                foreach ( (array) $rows as $t ) {
                    $tid = (int) ( $t->timesheet_id ?? 0 );
                    if ( ! $tid ) { continue; }
$totals_map[ $tid ] = array(
    'break_total'   => (int) ( $t->break_total ?? 0 ),
    'payable_total' => (float) ( $t->payable_total ?? 0 ),
    'min_date'      => (string) ( $t->min_date ?? '' ),

    'min_scheduled_start' => (string) ( $t->min_scheduled_start ?? '' ),
    'max_scheduled_end'   => (string) ( $t->max_scheduled_end ?? '' ),

    'min_clock_in'        => (string) ( $t->min_clock_in ?? '' ),
    'max_clock_out'       => (string) ( $t->max_clock_out ?? '' ),
);
                }
            }

            $grouped = array();
            foreach ( $headers as $row ) {
                $emp  = (string) ( $row->employee_name ?? 'Unknown' );
                $week = (string) ( $row->week_start_date ?? '' );
                if ( $week === '' ) { continue; }

                if ( ! isset( $grouped[ $emp ] ) ) {
                    $grouped[ $emp ] = array();
                }
                if ( ! isset( $grouped[ $emp ][ $week ] ) ) {
                    $grouped[ $emp ][ $week ] = array(
                        'rows' => array(),
                    );
                }
                $grouped[ $emp ][ $week ]['rows'][] = $row;
            }
            ?>

            <!-- ✅ FIXED: THEAD now has Location column so it matches row output -->
            <table class="wp-list-table widefat fixed striped">
                <thead>
<tr>
    <th width="2%">ID</th>
    <th width="6%">Date</th>

    <th width="11%">Sched. Start/End</th>
    <th width="11%">Clock In/Clock Out</th>

    <th width="12%">Employee</th>
    <th width="16%">Location</th>

    <th width="7%">Break (Min)</th>
    <th width="7%">Sched. Hrs</th>
    <th width="7%">Clocked Hrs</th>
    <th width="7%">Payable Hrs</th>

    <th width="7%">Status</th>
    <th width="8%">Actions</th>
</tr>

                </thead>

                <tbody>
                <?php foreach ( $grouped as $employee_name => $weeks ) : ?>
                    <tr class="wiw-employee-header">
                        <td colspan="12" style="background-color: #e6e6fa; font-weight: bold; font-size: 1.1em;">
                            👤 Employee: <?php echo esc_html( $employee_name ); ?>
                        </td>
                    </tr>

                    <?php foreach ( $weeks as $week_start => $bundle ) :

                        usort( $bundle['rows'], static function( $a, $b ) {
                            $la = (string) ( $a->location_name ?? '' );
                            $lb = (string) ( $b->location_name ?? '' );
                            return strcasecmp( $la, $lb );
                        } );

                        $week_end = '';
if ( ! empty( $bundle['rows'][0]->week_end_date ) ) {
    $week_end = (string) $bundle['rows'][0]->week_end_date;
} else {
    // Biweekly fallback: Sunday + 13 days (second Saturday)
    $week_end = date( 'Y-m-d', strtotime( $week_start . ' +13 days' ) );
}

                        $week_break   = 0;
                        $week_sched   = 0.0;
                        $week_clocked = 0.0;
                        $week_payable = 0.0;

                        foreach ( $bundle['rows'] as $r ) {
                            $tid = (int) ( $r->id ?? 0 );
                            $week_sched   += (float) ( $r->total_scheduled_hours ?? 0 );
                            $week_clocked += (float) ( $r->total_clocked_hours ?? 0 );
                            $week_break   += (int) ( $totals_map[ $tid ]['break_total'] ?? 0 );
                            $week_payable += (float) ( $totals_map[ $tid ]['payable_total'] ?? 0 );
                        }
                        ?>
                        <tr class="wiw-period-total">
                            <td colspan="6" style="background-color: #f0f0ff; font-weight: bold;">
                                📅 Pay Period: <?php echo esc_html( $week_start ); ?> to <?php echo esc_html( $week_end ); ?>
                            </td>
                            <td style="background-color: #f0f0ff; font-weight: bold;"><?php echo esc_html( (int) $week_break ); ?></td>
                            <td style="background-color: #f0f0ff; font-weight: bold;"><?php echo esc_html( number_format( (float) $week_sched, 2 ) ); ?></td>
                            <td style="background-color: #f0f0ff; font-weight: bold;"><?php echo esc_html( number_format( (float) $week_clocked, 2 ) ); ?></td>
                            <td style="background-color: #f0f0ff; font-weight: bold;"><?php echo esc_html( number_format( (float) $week_payable, 2 ) ); ?></td>
                            <td colspan="2" style="background-color: #f0f0ff;"></td>
                        </tr>

                        

                        <?php 
                        
                        $tz_string = get_option( 'timezone_string' ) ?: 'UTC';
$tz        = new DateTimeZone( $tz_string );

$fmt_time = function( $dt_string ) use ( $tz ) {
    if ( empty( $dt_string ) ) return 'N/A';
    try {
        $dt = new DateTime( (string) $dt_string, $tz );
        return $dt->format( 'g:ia' );
    } catch ( Exception $e ) {
        return 'N/A';
    }
};

$fmt_range = function( $start, $end, $separator ) use ( $fmt_time ) {
    if ( empty( $start ) || empty( $end ) ) return 'N/A';
    return $fmt_time( $start ) . $separator . $fmt_time( $end );
};

                        foreach ( $bundle['rows'] as $row ) :
                            $tid = (int) ( $row->id ?? 0 );

                            $break_total   = (int) ( $totals_map[ $tid ]['break_total'] ?? 0 );
                            $payable_total = (float) ( $totals_map[ $tid ]['payable_total'] ?? 0 );

                            $detail_url = add_query_arg(
                                array( 'timesheet_id' => $tid ),
                                menu_page_url( 'wiw-local-timesheets', false )
                            );

                            $min_date = (string) ( $totals_map[ $tid ]['min_date'] ?? '' );
                            if ( $min_date === '' ) { $min_date = '—'; }
                            ?>
                            <tr>
                                <td><?php echo esc_html( $tid ); ?></td>
<td><?php echo esc_html( $min_date ); ?></td>

<?php
$min_sched = (string) ( $totals_map[ $tid ]['min_scheduled_start'] ?? '' );
$max_sched = (string) ( $totals_map[ $tid ]['max_scheduled_end'] ?? '' );
$min_in    = (string) ( $totals_map[ $tid ]['min_clock_in'] ?? '' );
$max_out   = (string) ( $totals_map[ $tid ]['max_clock_out'] ?? '' );

$scheduled_range = $fmt_range( $min_sched, $max_sched, ' to ' );
$clock_range     = $fmt_range( $min_in,    $max_out,   ' / ' );
?>
<td><?php echo esc_html( $scheduled_range ); ?></td>
<td><?php echo esc_html( $clock_range ); ?></td>

<td><?php echo esc_html( $row->employee_name ); ?></td>
<td>
    <?php echo esc_html( $row->location_name ); ?>

                                    <?php if ( ! empty( $row->location_id ) ) : ?>
                                        <br/><small>(ID: <?php echo esc_html( $row->location_id ); ?>)</small>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo esc_html( $break_total ); ?></td>
                                <td><?php echo esc_html( number_format( (float) $row->total_scheduled_hours, 2 ) ); ?></td>
                                <td><?php echo esc_html( number_format( (float) $row->total_clocked_hours, 2 ) ); ?></td>
                                <td><?php echo esc_html( number_format( (float) $payable_total, 2 ) ); ?></td>
                                <td><?php echo esc_html( $row->status ); ?></td>
                                <td>
                                    <a href="<?php echo esc_url( $detail_url ); ?>" class="button button-small">Open Timesheet</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>

                    <?php endforeach; ?>
                <?php endforeach; ?>
                </tbody>
            </table>

        <?php endif; ?>
    </div><!-- .wrap -->