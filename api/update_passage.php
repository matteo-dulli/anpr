<?php
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

header('Content-Type: application/json; charset=utf-8');
date_default_timezone_set('Europe/Rome');

require_once __DIR__ . '/../config/config.php';

$db = getDatabaseConnection();
$response = ['success' => false, 'message' => ''];

try {
    $raw = file_get_contents('php://input');
    error_log('UPDATE_PASSAGE RAW: ' . substr($raw, 0, 200));
    
    $body = $raw ? json_decode($raw, true) : [];

    $passage_id = isset($body['passage_id']) ? (int)$body['passage_id'] : 0;
    $info = isset($body['info']) ? trim($body['info']) : '';
    $note = isset($body['note']) ? trim($body['note']) : '';
    $paid = isset($body['paid']) ? (int)$body['paid'] : 0;
    $annullato = isset($body['annullato']) ? (int)$body['annullato'] : 0;
    $motivo = isset($body['motivo_annullamento']) ? trim($body['motivo_annullamento']) : '';
    $fascia = isset($body['fascia']) ? trim($body['fascia']) : '';
    $prezzo = isset($body['prezzo']) ? (float)$body['prezzo'] : 0.00;
    $pag_cash = isset($body['pag_cash']) ? (int)$body['pag_cash'] : 0;
    $pag_electronic = isset($body['pag_electronic']) ? (int)$body['pag_electronic'] : 0;
    $receipt_emitted = isset($body['receipt_emitted']) ? (int)$body['receipt_emitted'] : 0;

    error_log('📋 UPDATE_PASSAGE PARAMS: ID=' . $passage_id . ' FASCIA=' . $fascia . ' PREZZO=' . $prezzo . ' RECEIPT_EMITTED=' . $receipt_emitted);

    if ($passage_id <= 0) {
        throw new Exception('ID passaggio non valido: ' . $passage_id);
    }

    // ===== AGGIORNA passages =====
    error_log('🔄 Step 1: Aggiornamento passages...');
    $stmt = $db->prepare("
        UPDATE passages
        SET 
            note = ?,
            info = ?,
            paid = ?,
            updated_at = NOW()
        WHERE id = ?
    ");
    
    if (!$stmt->execute([$note, $info, $paid, $passage_id])) {
        throw new Exception('Errore UPDATE passages: ' . implode(', ', $stmt->errorInfo()));
    }

    error_log('✅ Step 1: passages aggiornato');

    // ===== CONTROLLA SE ESISTE IN CASSA =====
    error_log('🔄 Step 2: Verifica record cassa...');
    $stmtCheck = $db->prepare("SELECT idcassa FROM cassa WHERE idpassages = ? LIMIT 1");
    $stmtCheck->execute([$passage_id]);
    $existingCassa = $stmtCheck->fetch(PDO::FETCH_ASSOC);

    if ($existingCassa) {
        // ===== UPDATE CASSA =====
        error_log('🔄 Step 3a: UPDATE cassa (idcassa=' . $existingCassa['idcassa'] . ')');
        
        // Se receipt_emitted=1, genera invoice_code se non esiste
        if ($receipt_emitted) {
            $stmtCassa = $db->prepare("
                UPDATE cassa
                SET 
                    Ppaid = ?,
                    PpayC = ?,
                    PpayE = ?,
                    invoice_price = ?,
                    Pannullato = ?,
                    Pannultxt = ?,
                    fascia = ?,
                    invoice_code = COALESCE(invoice_code, CONCAT('R_', DATE_FORMAT(NOW(), '%Y%m%d_%H%i%s'))),
                    updated_at = NOW()
                WHERE idpassages = ?
            ");
        } else {
            // UPDATE normale senza toccare invoice_code
            $stmtCassa = $db->prepare("
                UPDATE cassa
                SET 
                    Ppaid = ?,
                    PpayC = ?,
                    PpayE = ?,
                    invoice_price = ?,
                    Pannullato = ?,
                    Pannultxt = ?,
                    fascia = ?,
                    updated_at = NOW()
                WHERE idpassages = ?
            ");
        }
        
        if (!$stmtCassa->execute([$paid, $pag_cash, $pag_electronic, $prezzo, $annullato, $motivo, $fascia, $passage_id])) {
            throw new Exception('Errore UPDATE cassa: ' . implode(', ', $stmtCassa->errorInfo()));
        }
        
        error_log('✅ Step 3a: cassa aggiornato');
    } else {
        // ===== INSERT CASSA =====
        error_log('🔄 Step 3b: INSERT cassa (idpassages=' . $passage_id . ')');
        
        // Recupera ticket_code da passages
        $stmtGetTicket = $db->prepare("SELECT ticket_code, entry_datetime, exit_datetime FROM passages WHERE id = ?");
        $stmtGetTicket->execute([$passage_id]);
        $passageData = $stmtGetTicket->fetch(PDO::FETCH_ASSOC);
        
        if (!$passageData) {
            throw new Exception('Passaggio non trovato per ID: ' . $passage_id);
        }
        
        $ticket_code = $passageData['ticket_code'] ?: '';
        $entry_datetime = $passageData['entry_datetime'] ?: NULL;
        $exit_datetime = $passageData['exit_datetime'] ?: NULL;
        $invoice_code = $receipt_emitted ? 'R_' . date('Ymd_His') : NULL;
        
        $stmtCassa = $db->prepare("
            INSERT INTO cassa 
            (idpassages, Pticket_code, Pentry_datetime, Pexit_datetime, Ppaid, PpayC, PpayE, invoice_price, Pannullato, Pannultxt, fascia, invoice_code, datacassa, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, CURDATE(), NOW())
        ");
        
        if (!$stmtCassa->execute([$passage_id, $ticket_code, $entry_datetime, $exit_datetime, $paid, $pag_cash, $pag_electronic, $prezzo, $annullato, $motivo, $fascia, $invoice_code])) {
            throw new Exception('Errore INSERT cassa: ' . implode(', ', $stmtCassa->errorInfo()));
        }
        
        error_log('✅ Step 3b: cassa inserito');
    }

    $response['success'] = true;
    $response['message'] = '✅ Passaggio salvato correttamente';
    $response['data'] = [
        'passage_id' => $passage_id,
        'paid' => $paid,
        'PpayC' => $pag_cash,
        'PpayE' => $pag_electronic,
        'invoice_price' => $prezzo,
        'receipt_emitted' => $receipt_emitted
    ];

    error_log('✅ UPDATE_PASSAGE SUCCESS: ID=' . $passage_id);

} catch (Exception $e) {
    error_log('❌ UPDATE_PASSAGE ERROR: ' . $e->getMessage() . ' File: ' . $e->getFile() . ' Line: ' . $e->getLine());
    $response['success'] = false;
    $response['message'] = '❌ ' . $e->getMessage();
}

http_response_code($response['success'] ? 200 : 500);
echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
?>