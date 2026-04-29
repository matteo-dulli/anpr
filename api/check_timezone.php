<?php
header('Content-Type: application/json');

$currentTimezone = date_default_timezone_get();
$serverTime = date('Y-m-d H:i:s');
$utcTime = gmdate('Y-m-d H:i:s');

echo json_encode([
    'current_timezone' => $currentTimezone,
    'server_time' => $serverTime,
    'utc_time' => $utcTime,
    'expected_timezone' => 'Europe/Rome'
], JSON_PRETTY_PRINT);
?>