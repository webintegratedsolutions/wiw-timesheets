<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Creates / updates plugin DB tables.
 *
 * Uses dbDelta so we can safely add columns over time.
 */
function wiw_timesheet_manager_install() {
	global $wpdb;

	// Required for dbDelta().
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';

	$charset_collate = $wpdb->get_charset_collate();

$table_timesheets    = $wpdb->prefix . 'wiw_timesheets';
$table_entries       = $wpdb->prefix . 'wiw_timesheet_entries';
$table_edit_logs     = $wpdb->prefix . 'wiw_timesheet_edit_logs';
$table_flags         = $wpdb->prefix . 'wiw_timesheet_flags';

	// Timesheets (header)
	$sql_timesheets = "CREATE TABLE {$table_timesheets} (
		id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
		employee_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
		employee_name VARCHAR(255) NOT NULL DEFAULT '',
		location_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
		location_name VARCHAR(255) NOT NULL DEFAULT '',
		week_start_date DATE NOT NULL,
		week_end_date DATE NULL,
		total_scheduled_hours DECIMAL(10,2) NOT NULL DEFAULT 0.00,
		total_clocked_hours DECIMAL(10,2) NOT NULL DEFAULT 0.00,
		status VARCHAR(50) NOT NULL DEFAULT 'pending',
		created_at DATETIME NOT NULL,
		updated_at DATETIME NOT NULL,
		PRIMARY KEY  (id),
		KEY employee_week_location (employee_id, week_start_date, location_id),
		KEY week_start_date (week_start_date)
	) {$charset_collate};";

	// Timesheet entries (line items)
	$sql_entries = "CREATE TABLE {$table_entries} (
		id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
		timesheet_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
		wiw_time_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
		wiw_shift_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
		date DATE NULL,
		location_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
		location_name VARCHAR(255) NOT NULL DEFAULT '',
		clock_in DATETIME NULL,
		clock_out DATETIME NULL,
		break_minutes INT(11) NOT NULL DEFAULT 0,
		scheduled_hours DECIMAL(10,2) NOT NULL DEFAULT 0.00,
		clocked_hours DECIMAL(10,2) NOT NULL DEFAULT 0.00,
		payable_hours DECIMAL(10,2) NOT NULL DEFAULT 0.00,
		scheduled_start DATETIME NULL,
		scheduled_end DATETIME NULL,
		status VARCHAR(50) NOT NULL DEFAULT 'pending',
		created_at DATETIME NOT NULL,
		updated_at DATETIME NOT NULL,
		PRIMARY KEY  (id),
		UNIQUE KEY wiw_time_id (wiw_time_id),
		KEY timesheet_id (timesheet_id),
		KEY date (date)
	) {$charset_collate};";

	// Edit logs
	$sql_logs = "CREATE TABLE {$table_edit_logs} (
		id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
		timesheet_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
		entry_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
		wiw_time_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
		edit_type VARCHAR(100) NOT NULL DEFAULT '',
		old_value TEXT NULL,
		new_value TEXT NULL,
		edited_by_user_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
		edited_by_user_login VARCHAR(60) NOT NULL DEFAULT '',
		edited_by_display_name VARCHAR(255) NOT NULL DEFAULT '',
		employee_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
		employee_name VARCHAR(255) NOT NULL DEFAULT '',
		location_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
		location_name VARCHAR(255) NOT NULL DEFAULT '',
		week_start_date DATE NULL,
		created_at DATETIME NOT NULL,
		PRIMARY KEY  (id),
		KEY timesheet_id (timesheet_id),
		KEY wiw_time_id (wiw_time_id),
		KEY created_at (created_at)
	) {$charset_collate};";

	/**
	 * Flags table
	 *
	 * Improvements:
	 * - Adds created_at / updated_at so we can track resolution workflow later
	 * - Adds UNIQUE(wiw_time_id, flag_type) to prevent duplicate flags per time record
	 */
	$sql_flags = "CREATE TABLE {$table_flags} (
		id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
		wiw_time_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
		flag_type CHAR(3) NOT NULL DEFAULT '',
		description TEXT NOT NULL,
		flag_status VARCHAR(20) NOT NULL DEFAULT 'active',
		created_at DATETIME NOT NULL,
		updated_at DATETIME NOT NULL,
		PRIMARY KEY  (id),
		UNIQUE KEY uniq_time_flag (wiw_time_id, flag_type),
		KEY idx_wiw_time_id (wiw_time_id),
		KEY idx_flag_status (flag_status),
		KEY idx_flag_type (flag_type)
	) {$charset_collate};";

// Run dbDelta for all tables.
dbDelta( $sql_timesheets );
dbDelta( $sql_entries );
dbDelta( $sql_logs );
dbDelta( $sql_flags );

}
