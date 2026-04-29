<?php
header('Content-Type: application/json');
date_default_timezone_set('Europe/Rome');

$now = new DateTime('now', new DateTimeZone('Europe/Rome'));

echo json_encode([
    'success' => true,
    'date' => $now->format('Y-m-d'),
    'time' => $now->format('H:i'),
    'datetime' => $now->format('Y-m-d H:i:s'),
    'timestamp' => $now->getTimestamp()
], JSON_PRETTY_PRINT);
?>