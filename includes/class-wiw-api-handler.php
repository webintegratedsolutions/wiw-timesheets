<?php
if (!defined('ABSPATH')) exit;

class WIW_API_Handler {
    
    /**
     * Fetches and maps users into an ID-keyed array
     */
    public function get_employee_user_map() {
        $response = WIW_API_Client::request('users');
        $users = $response->users ?? [];
        return array_column($users, null, 'id');
    }

    /**
     * Fetches timesheet data and groups it for the dashboard
     */
    public function get_timesheet_data() {
        $timesheets = WIW_API_Client::request('times', ['include' => 'users,shifts,sites']);
        // ... include your logic here for calculating durations and grouping by pay period ...
        return $timesheets;
    }
}
