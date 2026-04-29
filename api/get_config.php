<?php
// ===== FILE DI CONFIGURAZIONE DINAMICA =====
// Salva questo file in /api/get_config.php
// Questo estrae automaticamente i percorsi dal server

header('Content-Type: application/json');

// ===== RILEVA AUTOMATICAMENTE IL PERCORSO ASSOLUTO =====
$apiDir = dirname(__FILE__);
$projectRoot = dirname($apiDir);

// ===== ESTRAE I PERCORSI =====
$paths = [
    'PROJECT_ROOT' => $projectRoot,
    'API_DIR' => $apiDir,
    'ANPR_IMAGES_DIR' => $projectRoot . '/anpr_images',
    'PUBLIC_DIR' => $projectRoot . '/public',
    'DOWNLOAD_DIR' => $projectRoot . '/download',
    'LOGS_DIR' => $projectRoot . '/logs',
    'DATABASE_DIR' => $projectRoot . '/database',
    'TEMP_DIR' => $projectRoot . '/temp',
    'CSS_DIR' => $projectRoot . '/css',
    'JS_DIR' => $projectRoot . '/js',
    'DOCUMENT_ROOT' => $_SERVER['DOCUMENT_ROOT'],
    'SCRIPT_FILENAME' => $_SERVER['SCRIPT_FILENAME'],
];

// ===== INFO SERVER =====
$serverInfo = [
    'SERVER_NAME' => $_SERVER['SERVER_NAME'] ?? 'unknown',
    'HTTP_HOST' => $_SERVER['HTTP_HOST'] ?? 'unknown',
    'SERVER_ADDR' => $_SERVER['SERVER_ADDR'] ?? 'unknown',
    'SERVER_PORT' => $_SERVER['SERVER_PORT'] ?? 'unknown',
    'PHP_VERSION' => phpversion(),
    'OS' => php_uname(),
];

// ===== VERIFICA ESISTENZA CARTELLE =====
$pathsWithStatus = [];
foreach ($paths as $name => $path) {
    $pathsWithStatus[$name] = [
        'path' => $path,
        'exists' => is_dir($path) || is_file($path),
    ];
}

echo json_encode([
    'server_info' => $serverInfo,
    'paths' => $pathsWithStatus,
    'timestamp' => date('Y-m-d H:i:s'),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
?>