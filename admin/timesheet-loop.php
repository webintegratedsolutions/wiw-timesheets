<?php 
if (empty($employee_data)): ?>
    <tr><td colspan="8">No records found.</td></tr>
<?php else: 
    foreach ($employee_data as $record): 
        $row_id = 'wiw-record-' . ($record->id ?? 0);
        ?>
        <tr id="<?php echo esc_attr($row_id); ?>" class="wiw-daily-record">
            <td><?php echo esc_html($record->id); ?></td>
            <td><?php echo esc_html($record->start_time); ?></td>
            <td></td>
            <td></td>
        </tr>
    <?php endforeach; 
endif; ?>
