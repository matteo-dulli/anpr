<?php
header('Content-Type: application/json; charset=utf-8');
date_default_timezone_set('Europe/Rome');

require_once __DIR__ . '/../config/config.php';

$cost = $GLOBALS['COSTANTI'] ?? [];
$sec = isset($cost['TEMPOCURSORE_SEC']) ? (int)$cost['TEMPOCURSORE_SEC'] : 30;
if ($sec <= 0) $sec = 30;

echo json_encode([
  'success' => true,
  'data' => [
    'TEMPOCURSORE_SEC' => $sec
  ]
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);