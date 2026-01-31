<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! current_user_can( 'manage_options' ) ) {
	wp_die( esc_html__( 'You do not have permission to access this page.', 'wiw-timesheets' ) );
}

global $wpdb;

$table_name = $wpdb->prefix . 'wiw_timesheet_sync_logs';
$per_page   = 20;
$paged      = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
$offset     = ( $paged - 1 ) * $per_page;

$total_logs = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table_name}" );
$logs       = $wpdb->get_results(
	$wpdb->prepare(
		"SELECT * FROM {$table_name} ORDER BY created_at DESC LIMIT %d OFFSET %d",
		$per_page,
		$offset
	)
);
?>

<div class="wrap">
	<h1>WIW Timesheets Sync Logs</h1>

	<?php if ( empty( $logs ) ) : ?>
		<p>No sync logs have been recorded yet.</p>
	<?php else : ?>
		<table class="widefat striped">
			<thead>
				<tr>
					<th>Date</th>
					<th>Client</th>
					<th>Records</th>
					<th>Synced By</th>
					<th>Synced Data</th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $logs as $log ) : ?>
					<?php
					$payload_display = $log->payload;
					if ( ! empty( $log->payload ) ) {
						$decoded = json_decode( $log->payload, true );
						if ( json_last_error() === JSON_ERROR_NONE ) {
							$payload_display = wp_json_encode( $decoded, JSON_PRETTY_PRINT );
						}
					}
					?>
					<tr>
						<td><?php echo esc_html( $log->created_at ); ?></td>
						<td>
							<?php echo esc_html( $log->client_name ?: (string) $log->client_id ); ?>
							<?php if ( empty( $log->client_name ) && ! empty( $log->client_id ) ) : ?>
								<br><small>ID: <?php echo esc_html( $log->client_id ); ?></small>
							<?php endif; ?>
						</td>
						<td><?php echo esc_html( $log->record_count ); ?></td>
						<td>
							<?php echo esc_html( $log->synced_by_display_name ?: $log->synced_by_user_login ); ?>
							<?php if ( ! empty( $log->synced_by_user_login ) ) : ?>
								<br><small><?php echo esc_html( $log->synced_by_user_login ); ?></small>
							<?php endif; ?>
						</td>
						<td>
							<details>
								<summary>View data</summary>
								<pre style="max-height: 300px; overflow: auto; font-size: 11px;"><?php echo esc_html( $payload_display ); ?></pre>
							</details>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>

		<?php
		$total_pages = max( 1, (int) ceil( $total_logs / $per_page ) );
		if ( $total_pages > 1 ) :
			?>
			<div class="tablenav">
				<div class="tablenav-pages">
					<?php
					echo wp_kses_post(
						paginate_links(
							array(
								'base'      => add_query_arg( 'paged', '%#%' ),
								'format'    => '',
								'prev_text' => '&laquo;',
								'next_text' => '&raquo;',
								'total'     => $total_pages,
								'current'   => $paged,
							)
						)
					);
					?>
				</div>
			</div>
		<?php endif; ?>
	<?php endif; ?>
</div>
