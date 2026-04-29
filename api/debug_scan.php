<?php
// api/debug_scan.php - Debug scansione con output dettagliato
header('Content-Type: application/json');
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

$debug = [
    'timestamp' => date('Y-m-d H:i:s'),
    'config' => [
        'monitored_folder' => MONITORED_FOLDER,
        'exists' => is_dir(MONITORED_FOLDER),
        'readable' => is_readable(MONITORED_FOLDER)
    ],
    'scan_log' => [],
    'statistics' => [
        'date_folders' => 0,
        'hour_folders' => 0,
        'minute_folders' => 0,
        'jpg_files' => 0,
        'valid_files' => 0,
        'duplicates' => 0,
        'inserted' => 0
    ]
];

if (!is_dir(MONITORED_FOLDER)) {
    echo json_encode([
        'error' => 'Cartella non trovata: ' . MONITORED_FOLDER,
        'config' => $debug['config']
    ]);
    exit;
}

try {
    $db = Database::getInstance()->getConnection();
    
    $debug['scan_log'][] = "🔍 INIZIO SCANSIONE";
    
    // ===== LIVELLO 1: CARTELLE DATA =====
    $topFolders = @scandir(MONITORED_FOLDER);
    $topFolders = array_diff($topFolders, ['.', '..', '.DS_Store', 'Thumbs.db']);
    
    $debug['scan_log'][] = "📂 Trovate " . count($topFolders) . " cartelle principali";
    
    foreach ($topFolders as $dateFolder) {
        $datePath = MONITORED_FOLDER . DIRECTORY_SEPARATOR . $dateFolder;
        
        if (!is_dir($datePath)) {
            $debug['scan_log'][] = "  ⏭️  $dateFolder (non è directory)";
            continue;
        }
        
        $isDateFormat = preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFolder);
        
        if (!$isDateFormat) {
            $debug['scan_log'][] = "  ⏭️  $dateFolder (formato non YYYY-MM-DD)";
            continue;
        }
        
        $debug['scan_log'][] = "  📅 $dateFolder (scansionata)";
        $debug['statistics']['date_folders']++;
        
        // ===== LIVELLO 2: CARTELLE ORA =====
        $hourFolders = @scandir($datePath);
        $hourFolders = array_diff($hourFolders, ['.', '..', '.DS_Store', 'Thumbs.db']);
        
        foreach ($hourFolders as $hourFolder) {
            $hourPath = $datePath . DIRECTORY_SEPARATOR . $hourFolder;
            
            if (!is_dir($hourPath)) continue;
            if (!preg_match('/^\d{2}$/', $hourFolder)) continue;
            
            $hour = (int)$hourFolder;
            if ($hour < 0 || $hour > 23) continue;
            
            $debug['statistics']['hour_folders']++;
            
            // ===== LIVELLO 3: CARTELLE MINUTI =====
            $minuteFolders = @scandir($hourPath);
            $minuteFolders = array_diff($minuteFolders, ['.', '..', '.DS_Store', 'Thumbs.db']);
            
            foreach ($minuteFolders as $minuteFolder) {
                $minutePath = $hourPath . DIRECTORY_SEPARATOR . $minuteFolder;
                
                if (!is_dir($minutePath)) continue;
                if (!preg_match('/^\d{2}$/', $minuteFolder)) continue;
                
                $minute = (int)$minuteFolder;
                if ($minute < 0 || $minute > 59) continue;
                
                $debug['statistics']['minute_folders']++;
                
                // ===== LIVELLO 4: FILE JPG =====
                $files = @scandir($minutePath);
                $files = array_diff($files, ['.', '..', '.DS_Store', 'Thumbs.db']);
                
                foreach ($files as $file) {
                    if (!preg_match('/\.jpg$/i', $file)) continue;
                    
                    $debug['statistics']['jpg_files']++;
                    $filePath = $minutePath . DIRECTORY_SEPARATOR . $file;
                    
                    // Estrai informazioni
                    $pattern = '/^(\d{2})-(\d{2})-(\d{4})-(\d{2})-(\d{2})-([A-Z0-9]+)-ANPR\.jpg$/i';
                    
                    if (!preg_match($pattern, $file, $matches)) {
                        $debug['scan_log'][] = "    ❌ File pattern non valido: $file";
                        continue;
                    }
                    
                    $debug['statistics']['valid_files']++;
                    
                    $day = $matches[1];
                    $month = $matches[2];
                    $year = $matches[3];
                    $fileHour = $matches[4];
                    $fileMinute = $matches[5];
                    $plate = strtoupper($matches[6]);
                    
                    $fileDate = "$year-$month-$day";
                    $fileTime = "$fileHour:$fileMinute:00";
                    $datetime = "$fileDate $fileTime";
                    
                    // Verifica se esiste
                    $stmt = $db->prepare(
                        "SELECT id FROM plates WHERE plate_number = ? AND DATE(date_detected) = ? AND TIME_FORMAT(date_detected, '%H:%i') = ?"
                    );
                    $stmt->execute([$plate, $fileDate, "$fileHour:$fileMinute"]);
                    
                    if ($stmt->fetch()) {
                        $debug['statistics']['duplicates']++;
                        continue;
                    }
                    
                    // Inserisci
                    try {
                        $relativeImagePath = str_replace(MONITORED_FOLDER . DIRECTORY_SEPARATOR, '', $filePath);
                        
                        $stmt = $db->prepare(
                            "INSERT INTO plates (plate_number, date_detected, image_path, folder_path) VALUES (?, ?, ?, ?)"
                        );
                        $stmt->execute([$plate, $datetime, $filePath, $relativeImagePath]);
                        
                        $debug['statistics']['inserted']++;
                        $debug['scan_log'][] = "    ✅ $plate - $datetime - $file";
                        
                    } catch (Exception $e) {
                        $debug['scan_log'][] = "    ❌ DB Error: " . $e->getMessage();
                    }
                }
            }
        }
    }
    
    $debug['scan_log'][] = "✅ SCANSIONE COMPLETATA";
    
} catch (Exception $e) {
    $debug['error'] = $e->getMessage();
}

echo json_encode($debug, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
?>