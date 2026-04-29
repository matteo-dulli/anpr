<?php
// api/import_plates.php - Importa tutte le targhe
header('Content-Type: application/json');
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

$db = Database::getInstance()->getConnection();
$response = ['success' => false, 'inserted' => 0, 'duplicates' => 0, 'errors' => 0, 'debug' => []];

try {
    $folder = ANPR_FOLDER;
    
    if (!is_dir($folder)) {
        throw new Exception("Cartella non trovata: $folder");
    }
    
    // Leggi RICORSIVAMENTE
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
            if ($ext === 'jpg' && preg_match('/-ANPR\.jpg$/', $fileName) && !preg_match('/\.plate\.jpg$/', $fileName)) {
                $files[] = $fileName;
            }
        }
    }
    
    $response['files_found'] = count($files);
    $response['debug'][] = "📁 Trovati " . count($files) . " file -ANPR.jpg";
    
    if (empty($files)) {
        $response['success'] = true;
        echo json_encode($response);
        exit;
    }
    
    // Processa ogni file
    foreach ($files as $fileName) {
        try {
            // Estrai targa
            if (!preg_match('/-([A-Z0-9]{6,10})-ANPR/i', $fileName, $match)) {
                $response['errors']++;
                continue;
            }
            
            $plateNumber = strtoupper($match[1]);
            
            // Estrai data/ora
            if (!preg_match('/(\d{2})-(\d{2})-(\d{4})-(\d{2})-(\d{2})/', $fileName, $match)) {
                $response['errors']++;
                continue;
            }
            
            $sqlDate = "$match[3]-$match[2]-$match[1]";
            $sqlTime = "$match[4]:$match[5]:00";
            $sqlDateTime = "$sqlDate $sqlTime";
            
            // Controlla se esiste
            $check = $db->prepare("SELECT id FROM plates WHERE plate_number = ? AND DATE(date_detected) = ? AND TIME(date_detected) = ? LIMIT 1");
            $check->execute([$plateNumber, $sqlDate, $sqlTime]);
            
            if ($check->fetch()) {
                $response['duplicates']++;
                continue;
            }
            
            // Inserisci
            $insert = $db->prepare("INSERT INTO plates (plate_number, plate_corrected, date_detected, is_manual) VALUES (?, ?, ?, FALSE)");
            
            if ($insert->execute([$plateNumber, $plateNumber, $sqlDateTime])) {
                $response['inserted']++;
            } else {
                $response['errors']++;
            }
            
        } catch (Exception $e) {
            $response['errors']++;
        }
    }
    
    $response['success'] = true;
    $response['debug'][] = "✅ Inserite: " . $response['inserted'];
    $response['debug'][] = "⚠️ Duplicati: " . $response['duplicates'];
    $response['debug'][] = "❌ Errori: " . $response['errors'];
    
} catch (Exception $e) {
    $response['error'] = $e->getMessage();
}

echo json_encode($response, JSON_PRETTY_PRINT);
?>