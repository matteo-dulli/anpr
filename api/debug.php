<?php
// api/debug.php - Debug completo del sistema
header('Content-Type: application/json');
require_once __DIR__ . '/../config/config.php';

$debug = [
    'timestamp' => date('Y-m-d H:i:s'),
    'system' => [],
    'folders' => [],
    'samples' => []
];

// ===== VERIFICA CARTELLE =====
$debug['folders']['monitored'] = [
    'path' => MONITORED_FOLDER,
    'exists' => is_dir(MONITORED_FOLDER),
    'readable' => is_readable(MONITORED_FOLDER),
    'writable' => is_writable(MONITORED_FOLDER),
    'free_space' => @disk_free_space(MONITORED_FOLDER) ? 
        format_bytes(@disk_free_space(MONITORED_FOLDER)) : 'N/A'
];

$debug['folders']['images'] = [
    'path' => IMAGES_COPY_FOLDER,
    'exists' => is_dir(IMAGES_COPY_FOLDER),
    'writable' => is_writable(IMAGES_COPY_FOLDER),
    'file_count' => is_dir(IMAGES_COPY_FOLDER) ? 
        count(glob(IMAGES_COPY_FOLDER . '/*.jpg')) : 0
];

$debug['folders']['logs'] = [
    'path' => LOGS_FOLDER,
    'exists' => is_dir(LOGS_FOLDER),
    'writable' => is_writable(LOGS_FOLDER)
];

// ===== VERIFICA PHP =====
$debug['system']['php'] = [
    'version' => phpversion(),
    'memory_limit' => ini_get('memory_limit'),
    'max_execution_time' => ini_get('max_execution_time'),
    'extensions' => [
        'pdo' => extension_loaded('pdo') ? '✅' : '❌',
        'pdo_mysql' => extension_loaded('pdo_mysql') ? '✅' : '❌',
        'json' => extension_loaded('json') ? '✅' : '❌'
    ]
];

// ===== VERIFICA DATABASE =====
try {
    require_once __DIR__ . '/../config/database.php';
    $db = Database::getInstance()->getConnection();
    
    $debug['system']['database'] = [
        'connected' => true,
        'host' => DB_HOST,
        'database' => DB_NAME,
        'plates' => $db->query("SELECT COUNT(*) as c FROM plates")->fetch()['c'],
        'tickets' => $db->query("SELECT COUNT(*) as c FROM tickets")->fetch()['c'],
        'last_scan' => $db->query(
            "SELECT files_found, new_plates, scan_time FROM scan_logs ORDER BY scan_time DESC LIMIT 1"
        )->fetch()
    ];
} catch (Exception $e) {
    $debug['system']['database'] = [
        'connected' => false,
        'error' => $e->getMessage()
    ];
}

// ===== VERIFICA PATTERN REGEX =====
$testFiles = [
    '23-03-2026-07-25-HB643VE-ANPR.jpg' => true,
    '26-03-2026-08-20-HR565VE-ANPR.jpg' => true,
    '01-01-2026-00-00-ABC1234-ANPR.jpg' => true,
    'invalid_file.jpg' => false,
    '23-03-2026-07-25-HB643VE-ANPR.png' => false,
];

$debug['system']['regex'] = [];
$pattern = '/^(\d{2})-(\d{2})-(\d{4})-(\d{2})-(\d{2})-([A-Z0-9]+)-ANPR\.jpg$/i';

foreach ($testFiles as $file => $shouldMatch) {
    $matches = [];
    $isMatch = preg_match($pattern, $file, $matches);
    
    $debug['system']['regex'][] = [
        'file' => $file,
        'expected' => $shouldMatch,
        'actual' => $isMatch === 1,
        'correct' => ($isMatch === 1) === $shouldMatch ? '✅' : '❌',
        'plate' => $isMatch === 1 ? $matches[6] : null,
        'date' => $isMatch === 1 ? $matches[3] . '-' . $matches[2] . '-' . $matches[1] : null
    ];
}

// ===== CAMPIONI CARTELLE =====
if (is_dir(MONITORED_FOLDER)) {
    $topFolders = @scandir(MONITORED_FOLDER);
    if ($topFolders) {
        $topFolders = array_diff($topFolders, ['.', '..', '.DS_Store', 'Thumbs.db']);
        
        foreach (array_slice($topFolders, 0, 5) as $folder) {
            $folderPath = MONITORED_FOLDER . DIRECTORY_SEPARATOR . $folder;
            
            if (!is_dir($folderPath)) continue;
            
            // Valida formato data
            $isDateFormat = preg_match('/^\d{4}-\d{2}-\d{2}$/', $folder);
            $isValidDate = $isDateFormat && isValidDate($folder);
            
            $debug['samples'][] = [
                'folder' => $folder,
                'is_directory' => true,
                'is_date_format' => $isDateFormat === 1,
                'is_valid_date' => $isValidDate,
                'status' => $isValidDate ? '✅ SCANSIONATA' : '⏭️ IGNORATA'
            ];
        }
    }
}

echo json_encode($debug, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

function format_bytes($bytes, $precision = 2) {
    $units = ['B', 'KB', 'MB', 'GB'];
    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);
    $bytes /= (1 << (10 * $pow));
    return round($bytes, $precision) . ' ' . $units[$pow];
}

function isValidDate($date) {
    $d = DateTime::createFromFormat('Y-m-d', $date);
    return $d && $d->format('Y-m-d') === $date;
}

?>