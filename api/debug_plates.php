<?php
// api/debug_plates.php
header('Content-Type: application/json');
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

$db = Database::getInstance()->getConnection();

try {
    // Prendi i primi 5 record dalla tabella
    $stmt = $db->prepare("
        SELECT id, plate_number, date_detected 
        FROM plates 
        ORDER BY id ASC
        LIMIT 5
    ");
    $stmt->execute();
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Prendi i file dalla cartella
    $folder = ANPR_FOLDER;
    $files = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($folder, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    
    foreach ($iterator as $fileinfo) {
        if ($fileinfo->isFile()) {
            $fileName = $fileinfo->getFilename();
            if (strpos($fileName, '-ANPR.jpg') !== false && strpos($fileName, '.plate.') === false) {
                $files[] = $fileName;
                if (count($files) >= 5) break;
            }
        }
    }
    
    echo json_encode([
        'success' => true,
        'db_records' => $results,
        'file_samples' => $files
    ], JSON_PRETTY_PRINT);
    
} catch (Exception $e) {
    echo json_encode(['error' => $e->getMessage()]);
}
?>