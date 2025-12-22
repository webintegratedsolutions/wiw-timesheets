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
$table_daily_records = $wpdb->prefix . 'wiw_daily_records'; // Compatibility alias/view for client UI.
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

/**
 * Compatibility: create a VIEW for "{$wpdb->prefix}wiw_daily_records" backed by
 * "{$wpdb->prefix}wiw_timesheet_entries" so front-end/client UI can query a stable name.
 *
 * This keeps backend behavior unchanged while allowing client UI to use "wiw_daily_records".
 */
$daily_exists = $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $table_daily_records ) );

if ( $daily_exists !== $table_daily_records ) {
	// Attempt to create a VIEW. If permissions prevent it, we fall back to creating a real table.
	$create_view_sql = "
		CREATE VIEW {$table_daily_records} AS
		SELECT
			id,
			timesheet_id,
			wiw_time_id,
			wiw_shift_id,
			date,
			location_id,
			location_name,
			clock_in,
			clock_out,
			break_minutes,
			scheduled_hours,
			clocked_hours,
			payable_hours,
			scheduled_start,
			scheduled_end,
			status,
			created_at,
			updated_at
		FROM {$table_entries}
	";
	$wpdb->query( $create_view_sql );

	// If the VIEW failed (common on restricted shared hosting), create a real table + copy data once.
	$daily_exists_after = $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $table_daily_records ) );
	if ( $daily_exists_after !== $table_daily_records ) {
		$sql_daily_records = "CREATE TABLE {$table_daily_records} (
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

		dbDelta( $sql_daily_records );

		// One-time copy so client UI can immediately show what backend already has.
		$wpdb->query( "INSERT IGNORE INTO {$table_daily_records} SELECT * FROM {$table_entries}" );
	}
}

}
