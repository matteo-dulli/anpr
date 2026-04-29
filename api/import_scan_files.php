<?php
// api/import_scan_files.php - Importa targhe dai file nella cartella
header('Content-Type: application/json');
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

$db = Database::getInstance()->getConnection();
$response = ['success' => false, 'data' => ['imported' => 0, 'duplicates' => 0, 'errors' => 0], 'message' => ''];

try {
    $folder = ANPR_FOLDER; // Dalla config
    
    if (!is_dir($folder)) {
        throw new Exception("Cartella non trovata: $folder");
    }
    
    // Leggi tutti i file .txt
    $files = glob($folder . '*.jpg');
    
    if (empty($files)) {
        $response['success'] = true;
        $response['message'] = '⚠️ Nessun file trovato nella cartella';
        echo json_encode($response);
        exit;
    }
    
    $imported = 0;
    $duplicates = 0;
    $errors = 0;
    
    foreach ($files as $file) {
        try {
            $content = file_get_contents($file);
            
            // Estrai targa dal contenuto del file
            // Esempio: "Targa: FG553SA" oppure "PLATE: FG553SA"
            $plateMatch = [];
            if (preg_match('/(?:Targa|PLATE|Numero)[\s:]*([A-Z0-9]{6,10})/i', $content, $plateMatch)) {
                $plateNumber = strtoupper(trim($plateMatch[1]));
            } else {
                $errors++;
                continue;
            }
            
            // Estrai data dal filename o dal contenuto
            $dateMatch = [];
            if (preg_match('/(\d{4})[-_](\d{2})[-_](\d{2})/', basename($file), $dateMatch)) {
                $sqlDate = $dateMatch[1] . '-' . $dateMatch[2] . '-' . $dateMatch[3];
            } else {
                $sqlDate = date('Y-m-d');
            }
            
            // Estrai ora dal contenuto
            $timeMatch = [];
            if (preg_match('/(?:Ora|TIME)[\s:]*(\d{2}):(\d{2}):?(\d{2})?/i', $content, $timeMatch)) {
                $hour = str_pad($timeMatch[1], 2, '0', STR_PAD_LEFT);
                $minute = str_pad($timeMatch[2], 2, '0', STR_PAD_LEFT);
                $second = str_pad($timeMatch[3] ?? '00', 2, '0', STR_PAD_LEFT);
                $sqlTime = "$hour:$minute:$second";
                $sqlDateTime = "$sqlDate $sqlTime";
            } else {
                $sqlDateTime = "$sqlDate " . date('H:i:s');
            }
            
            // Controlla se esiste già
            $checkStmt = $db->prepare("
                SELECT id FROM plates 
                WHERE plate_number = ? 
                AND DATE(date_detected) = DATE(?)
                LIMIT 1
            ");
            $checkStmt->execute([$plateNumber, $sqlDateTime]);
            
            if ($checkStmt->fetch()) {
                $duplicates++;
                continue;
            }
            
            // Inserisci nuova targa
            $insertStmt = $db->prepare("
                INSERT INTO plates (plate_number, plate_corrected, date_detected, is_manual)
                VALUES (?, ?, ?, FALSE)
            ");
            $insertStmt->execute([$plateNumber, $plateNumber, $sqlDateTime]);
            
            $imported++;
            
        } catch (Exception $e) {
            error_log("Errore import file $file: " . $e->getMessage());
            $errors++;
            continue;
        }
    }
    
    $response['success'] = true;
    $response['data']['imported'] = $imported;
    $response['data']['duplicates'] = $duplicates;
    $response['data']['errors'] = $errors;
    $response['message'] = "✅ Importate $imported targhe ($duplicates duplicate, $errors errori)";
    
} catch (Exception $e) {
    error_log("Errore import_scan_files.php: " . $e->getMessage());
    $response['message'] = '❌ ' . $e->getMessage();
}

echo json_encode($response);
?>