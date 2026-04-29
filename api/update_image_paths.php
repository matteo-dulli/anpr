<?php
// api/update_image_paths.php - Aggiorna image_path per tutte le targhe
header('Content-Type: application/json');
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

$db = Database::getInstance()->getConnection();
$response = ['success' => false, 'updated' => 0, 'debug' => []];

try {
    $folder = ANPR_FOLDER;
    
    if (!is_dir($folder)) {
        throw new Exception("Cartella non trovata: $folder");
    }
    
    // Leggi RICORSIVAMENTE tutti i file JPG
    $files = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($folder, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    
    foreach ($iterator as $fileinfo) {
        if ($fileinfo->isFile()) {
            $fileName = $fileinfo->getFilename();
            $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
            
            // Solo i file -ANPR.jpg (non .plate.jpg)
            if ($ext === 'jpg' && strpos($fileName, '-ANPR.jpg') !== false && strpos($fileName, '.plate.') === false) {
                $files[] = $fileinfo->getRealPath();
            }
        }
    }
    
    $response['debug'][] = "📁 Trovati " . count($files) . " file JPG";
    
    $updatedCount = 0;
    $errorCount = 0;
    $debugCount = 0;
    
    foreach ($files as $file) {
        try {
            $fileName = basename($file);
            
            // ===== ESTRAI TARGA =====
            if (!preg_match('/-([A-Z0-9]{6,10})-ANPR/i', $fileName, $match)) {
                $errorCount++;
                continue;
            }
            
            $plateNumber = strtoupper($match[1]);
            
            // ===== ESTRAI DATA/ORA DAL NOME FILE =====
            // Formato: 20-03-2026-20-53-GZ194YB-ANPR.jpg
            if (!preg_match('/^(\d{2})-(\d{2})-(\d{4})-(\d{2})-(\d{2})-/', $fileName, $match)) {
                $errorCount++;
                if ($debugCount < 5) {
                    $response['debug'][] = "❌ File: $fileName → Data non estratta";
                    $debugCount++;
                }
                continue;
            }
            
            $day = $match[1];
            $month = $match[2];
            $year = $match[3];
            $hour = $match[4];
            $minute = $match[5];
            
            $sqlDate = "$year-$month-$day";
            $sqlTime = "$hour:$minute:00";
            
            // ===== AGGIORNA RECORD =====
            $updateStmt = $db->prepare("
                UPDATE plates 
                SET image_path = ? 
                WHERE plate_number = ? 
                AND DATE(date_detected) = ? 
                AND TIME(date_detected) = ?
                LIMIT 1
            ");
            
            if ($updateStmt->execute([$file, $plateNumber, $sqlDate, $sqlTime])) {
                if ($updateStmt->rowCount() > 0) {
                    $updatedCount++;
                    if ($debugCount < 5) {
                        $response['debug'][] = "✅ Aggiornata: $plateNumber ($sqlDate $sqlTime)";
                        $debugCount++;
                    }
                } else {
                    $errorCount++;
                }
            } else {
                $errorCount++;
            }
            
        } catch (Exception $e) {
            $errorCount++;
            continue;
        }
    }
    
    $response['success'] = true;
    $response['updated'] = $updatedCount;
    $response['errors'] = $errorCount;
    $response['debug'][] = "✅ Aggiornate: $updatedCount targhe";
    $response['debug'][] = "❌ Errori: $errorCount";
    $response['message'] = "✅ Aggiornati $updatedCount record con image_path";
    
} catch (Exception $e) {
    $response['message'] = '❌ ' . $e->getMessage();
    $response['debug'][] = $e->getMessage();
}

echo json_encode($response, JSON_PRETTY_PRINT);
?>