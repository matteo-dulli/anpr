<?php
// list_contents.php - Lista il contenuto reale
header('Content-Type: application/json');
require_once __DIR__ . '/../config/config.php';

$result = [
    'timestamp' => date('Y-m-d H:i:s'),
    'monitored_folder' => MONITORED_FOLDER,
    'exists' => is_dir(MONITORED_FOLDER),
    'readable' => is_readable(MONITORED_FOLDER),
    'first_level_contents' => [],
    'analysis' => []
];

if (!is_dir(MONITORED_FOLDER)) {
    echo json_encode($result);
    exit;
}

// Leggi il contenuto
$contents = @scandir(MONITORED_FOLDER);
$contents = array_diff($contents, ['.', '..', '.DS_Store', 'Thumbs.db']);

$result['total_items'] = count($contents);

// Analizza ogni item
foreach ($contents as $item) {
    $itemPath = MONITORED_FOLDER . DIRECTORY_SEPARATOR . $item;
    $isDir = is_dir($itemPath);
    
    // Verifica formato data
    $isDateFormat = preg_match('/^\d{4}-\d{2}-\d{2}$/', $item);
    $isValidDate = false;
    
    if ($isDateFormat) {
        $d = DateTime::createFromFormat('Y-m-d', $item);
        $isValidDate = $d && $d->format('Y-m-d') === $item;
    }
    
    $analysis = [
        'name' => $item,
        'path' => $itemPath,
        'is_directory' => $isDir,
        'is_readable' => is_readable($itemPath),
        'matches_date_format' => $isDateFormat === 1,
        'is_valid_date' => $isValidDate,
        'type' => 'DIRECTORY' // Modifica se file
    ];
    
    if ($isDir) {
        // Se è directory, elenca il contenuto
        $subItems = @scandir($itemPath);
        $analysis['subdirectories'] = count(array_diff($subItems, ['.', '..']));
        $analysis['first_3_subdirs'] = array_slice(array_diff($subItems, ['.', '..']), 0, 3);
    } else {
        $analysis['size'] = filesize($itemPath);
        $analysis['type'] = 'FILE';
    }
    
    $result['first_level_contents'][] = $analysis;
}

// Analisi
$dateFolders = array_filter($contents, function($item) {
    return is_dir(MONITORED_FOLDER . DIRECTORY_SEPARATOR . $item) && 
           preg_match('/^\d{4}-\d{2}-\d{2}$/', $item);
});

$result['analysis']['total_items'] = count($contents);
$result['analysis']['directories'] = count(array_filter($contents, function($item) {
    return is_dir(MONITORED_FOLDER . DIRECTORY_SEPARATOR . $item);
}));
$result['analysis']['files'] = count(array_filter($contents, function($item) {
    return !is_dir(MONITORED_FOLDER . DIRECTORY_SEPARATOR . $item);
}));
$result['analysis']['date_format_directories'] = count($dateFolders);
$result['analysis']['date_format_directories_list'] = array_values($dateFolders);

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
?>