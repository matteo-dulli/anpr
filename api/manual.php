<?php
// test_scan_manual.php - Scansione manuale
header('Content-Type: application/json');
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

$result = [
    'timestamp' => date('Y-m-d H:i:s'),
    'config' => [
        'monitored_folder' => MONITORED_FOLDER,
        'exists' => is_dir(MONITORED_FOLDER),
        'readable' => is_readable(MONITORED_FOLDER)
    ],
    'scan' => [
        'date_folders' => 0,
        'files_found' => 0,
        'valid_patterns' => 0,
        'duplicates' => 0,
        'inserted' => 0,
        'errors' => []
    ]
];

if (!is_dir(MONITORED_FOLDER)) {
    $result['error'] = 'Cartella non trovata';
    echo json_encode($result);
    exit;
}

try {
    $db = Database::getInstance()->getConnection();
    
    $topFolders = @scandir(MONITORED_FOLDER);
    $topFolders = array_diff($topFolders, ['.', '..', '.DS_Store', 'Thumbs.db']);
    
    // Prendi solo prime 2 cartelle data per test veloce
    $topFolders = array_slice(array_values($topFolders), 0, 2);
    
    foreach ($topFolders as $dateFolder) {
        $datePath = MONITORED_FOLDER . DIRECTORY_SEPARATOR . $dateFolder;
        
        if (!is_dir($datePath)) continue;
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFolder)) continue;
        
        $result['scan']['date_folders']++;
        
        $hourFolders = @scandir($datePath);
        $hourFolders = array_diff($hourFolders, ['.', '..']);
        
        foreach ($hourFolders as $hourFolder) {
            $hourPath = $datePath . DIRECTORY_SEPARATOR . $hourFolder;
            
            if (!is_dir($hourPath)) continue;
            if (!preg_match('/^\d{2}$/', $hourFolder)) continue;
            
            $hour = (int)$hourFolder;
            if ($hour < 0 || $hour > 23) continue;
            
            $minuteFolders = @scandir($hourPath);
            $minuteFolders = array_diff($minuteFolders, ['.', '..']);
            
            foreach ($minuteFolders as $minuteFolder) {
                $minutePath = $hourPath . DIRECTORY_SEPARATOR . $minuteFolder;
                
                if (!is_dir($minutePath)) continue;
                if (!preg_match('/^\d{2}$/', $minuteFolder)) continue;
                
                $minute = (int)$minuteFolder;
                if ($minute < 0 || $minute > 59) continue;
                
                $files = @scandir($minutePath);
                $files = array_diff($files, ['.', '..']);
                
                foreach ($files as $file) {
                    if (!preg_match('/\.jpg$/i', $file)) continue;
                    
                    $result['scan']['files_found']++;
                    $filePath = $minutePath . DIRECTORY_SEPARATOR . $file;
                    
                    $pattern = '/^(\d{2})-(\d{2})-(\d{4})-(\d{2})-(\d{2})-([A-Z0-9]+)-ANPR\.jpg$/i';
                    
                    if (!preg_match($pattern, $file, $matches)) {
                        $result['scan']['errors'][] = "Pattern not match: $file";
                        continue;
                    }
                    
                    $result['scan']['valid_patterns']++;
                    
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
                        $result['scan']['duplicates']++;
                        continue;
                    }
                    
                    // Inserisci
                    try {
                        $relativeImagePath = str_replace(MONITORED_FOLDER . DIRECTORY_SEPARATOR, '', $filePath);
                        
                        $stmt = $db->prepare(
                            "INSERT INTO plates (plate_number, date_detected, image_path, folder_path) 
                             VALUES (?, ?, ?, ?)"
                        );
                        $stmt->execute([$plate, $datetime, $filePath, $relativeImagePath]);
                        
                        $result['scan']['inserted']++;
                        
                    } catch (Exception $e) {
                        $result['scan']['errors'][] = "Insert error: " . $e->getMessage();
                    }
                }
            }
        }
    }
    
} catch (Exception $e) {
    $result['error'] = $e->getMessage();
}

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
?>