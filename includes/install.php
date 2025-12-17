<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Create or update the WIW Timesheets database tables.
 *
 * - wp_wiw_timesheets        (header: one per employee + week + location)
 * - wp_wiw_timesheet_entries (line items: one per WIW time record)
 */
function wiw_timesheet_manager_install() {
    global $wpdb;

    $charset_collate = $wpdb->get_charset_collate();

    $table_timesheets        = $wpdb->prefix . 'wiw_timesheets';
    $table_timesheet_entries = $wpdb->prefix . 'wiw_timesheet_entries';
    $table_edit_logs         = $wpdb->prefix . 'wiw_timesheet_edit_logs';

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    // Header table: one row per Employee + Week + Location
    $sql_timesheets = "CREATE TABLE {$table_timesheets} (
        id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        employee_id BIGINT(20) UNSIGNED NOT NULL,
        employee_name VARCHAR(191) NOT NULL DEFAULT '',
        location_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
        location_name VARCHAR(191) NOT NULL DEFAULT '',
        week_start_date DATE NOT NULL,
        week_end_date DATE NOT NULL,
        total_scheduled_hours DECIMAL(6,2) NOT NULL DEFAULT 0.00,
        total_clocked_hours DECIMAL(6,2) NOT NULL DEFAULT 0.00,
        status VARCHAR(20) NOT NULL DEFAULT 'pending',
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        PRIMARY KEY  (id),
        UNIQUE KEY employee_week_location (employee_id, week_start_date, location_id),
        KEY week_start (week_start_date)
    ) {$charset_collate};";

    // Line items: one row per daily WIW time record
    $sql_timesheet_entries = "CREATE TABLE {$table_timesheet_entries} (
        id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        timesheet_id BIGINT(20) UNSIGNED NOT NULL,
        wiw_time_id BIGINT(20) UNSIGNED NOT NULL,
        wiw_shift_id BIGINT(20) UNSIGNED DEFAULT NULL,
        date DATE NOT NULL,
        location_id BIGINT(20) UNSIGNED DEFAULT 0,
        location_name VARCHAR(191) NOT NULL DEFAULT '',
        clock_in DATETIME DEFAULT NULL,
        clock_out DATETIME DEFAULT NULL,
        break_minutes INT(11) NOT NULL DEFAULT 0,
        scheduled_hours DECIMAL(6,2) NOT NULL DEFAULT 0.00,
        clocked_hours DECIMAL(6,2) NOT NULL DEFAULT 0.00,
        status VARCHAR(20) NOT NULL DEFAULT 'pending',
        notes TEXT NULL,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        PRIMARY KEY  (id),
        UNIQUE KEY wiw_time_id (wiw_time_id),
        KEY timesheet_id (timesheet_id),
        KEY entry_date (date)
    ) {$charset_collate};";

    // Edit logs: one row per field change
    $sql_edit_logs = "CREATE TABLE {$table_edit_logs} (
        id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,

        timesheet_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
        entry_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
        wiw_time_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,

        edit_type VARCHAR(50) NOT NULL DEFAULT '',

        old_value TEXT NULL,
        new_value TEXT NULL,

        edited_by_user_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
        edited_by_user_login VARCHAR(60) NOT NULL DEFAULT '',
        edited_by_display_name VARCHAR(191) NOT NULL DEFAULT '',

        employee_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
        employee_name VARCHAR(191) NOT NULL DEFAULT '',
        location_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
        location_name VARCHAR(191) NOT NULL DEFAULT '',
        week_start_date DATE NOT NULL,

        created_at DATETIME NOT NULL,

        PRIMARY KEY  (id),
        KEY timesheet_id (timesheet_id),
        KEY entry_id (entry_id),
        KEY created_at (created_at)
    ) {$charset_collate};";

    dbDelta( $sql_timesheets );
    dbDelta( $sql_timesheet_entries );
    dbDelta( $sql_edit_logs );
}
