<?php
/**
 * turni_operatore_login.php
 * Apre il turno per un operatore:
 *  1. Aggiorna operatori_turni (stato = online)
 *  2. Crea una nuova riga in turni_sessioni (con numero_turno auto)
 *  3. Ritorna id_sessione e numero_turno per il JS
 */
header('Content-Type: application/json; charset=utf-8');
date_default_timezone_set('Europe/Rome');
require_once __DIR__ . '/../config/config.php';

$db = getDatabaseConnection();
$response = ['success' => false, 'message' => '', 'data' => null];

try {
    $body          = json_decode(file_get_contents('php://input'), true) ?? [];
    $operatore_cod = isset($body['operatore_cod']) ? trim($body['operatore_cod']) : '';

    if (!$operatore_cod) {
        throw new Exception('operatore_cod obbligatorio');
    }

    // Assicura che la tabella turni_sessioni esista
    $db->exec("
        CREATE TABLE IF NOT EXISTS turni_sessioni (
            id            INT AUTO_INCREMENT PRIMARY KEY,
            numero_turno  INT NOT NULL DEFAULT 0,
            operatore_cod VARCHAR(50) NOT NULL,
            stato         ENUM('online','offline') DEFAULT 'online',
            inizio        TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            fine          TIMESTAMP NULL,
            ticket_emessi          INT DEFAULT 0,
            ticket_pagati_contanti INT DEFAULT 0,
            ticket_pagati_online   INT DEFAULT 0,
            ticket_annullati       INT DEFAULT 0,
            ricevute               INT DEFAULT 0,
            importo_contante       DECIMAL(10,2) DEFAULT 0,
            importo_online         DECIMAL(10,2) DEFAULT 0,
            importo_totale         DECIMAL(10,2) DEFAULT 0,
            file_path     VARCHAR(255) NULL,
            created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_operatore (operatore_cod),
            INDEX idx_stato (stato),
            INDEX idx_inizio (inizio)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $now = date('Y-m-d H:i:s');

    // Aggiorna operatori_turni (tabella stato corrente)
    $chk = $db->prepare("SELECT id FROM operatori_turni WHERE operatore_cod = ? LIMIT 1");
    $chk->execute([$operatore_cod]);
    if ($chk->fetch()) {
        $stmt = $db->prepare("
            UPDATE operatori_turni
            SET stato = 'online', inizio_turno = ?, login_time = ?, updated_at = NOW()
            WHERE operatore_cod = ?
        ");
        $stmt->execute([$now, $now, $operatore_cod]);
    }
    // (Se non esiste la riga, non è bloccante — la tabella turni_sessioni è la vera fonte)

    // Calcola prossimo numero_turno
    $maxStmt = $db->query("SELECT COALESCE(MAX(numero_turno), 0) + 1 AS next_num FROM turni_sessioni");
    $maxRow  = $maxStmt->fetch(PDO::FETCH_ASSOC);
    $nextNum = (int)($maxRow['next_num'] ?? 1);

    // Inserisci nuova sessione
    $ins = $db->prepare("
        INSERT INTO turni_sessioni (numero_turno, operatore_cod, stato, inizio)
        VALUES (?, ?, 'online', ?)
    ");
    $ins->execute([$nextNum, $operatore_cod, $now]);
    $idSessione = (int)$db->lastInsertId();

    if (function_exists('logEvent')) {
        logEvent('turni', "LOGIN: Operatore $operatore_cod — Turno n. $nextNum (sessione $idSessione)");
    }

    $response['success'] = true;
    $response['message'] = "✅ Turno aperto per operatore $operatore_cod";
    $response['data']    = [
        'operatore_cod' => $operatore_cod,
        'stato'         => 'online',
        'id_sessione'   => $idSessione,
        'numero_turno'  => $nextNum,
        'inizio'        => $now
    ];

} catch (Exception $e) {
    $response['message'] = '❌ ' . $e->getMessage();
    if (function_exists('logEvent')) {
        logEvent('error', 'TURNI_LOGIN_ERROR: ' . $e->getMessage());
    }
}

http_response_code($response['success'] ? 200 : 400);
echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
?>
