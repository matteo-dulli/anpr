<?php
/**
 * turni_list.php
 * Ritorna la lista dei turni degli ultimi 30 giorni
 * Response: { success, data: [ { id, numero_turno, operatore_cod, stato, data_inizio, file_name } ] }
 */
header('Content-Type: application/json; charset=utf-8');
date_default_timezone_set('Europe/Rome');
require_once __DIR__ . '/../config/config.php';

$response = ['success' => false, 'message' => '', 'data' => []];

try {
    $db = getDatabaseConnection();

    // Crea tabella se non esiste
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

    $limite = date('Y-m-d H:i:s', strtotime('-30 days'));

    $stmt = $db->prepare("
        SELECT
            id,
            numero_turno,
            operatore_cod,
            stato,
            inizio  AS data_inizio,
            fine    AS data_fine,
            file_path
        FROM turni_sessioni
        WHERE inizio >= ?
        ORDER BY inizio DESC
        LIMIT 100
    ");
    $stmt->execute([$limite]);

    $data = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $data[] = [
            'id'            => (int)$row['id'],
            'numero_turno'  => (int)$row['numero_turno'],
            'operatore_cod' => $row['operatore_cod'],
            'stato'         => $row['stato'],
            'data_inizio'   => $row['data_inizio'],
            'data_fine'     => $row['data_fine'],
            'file_name'     => $row['file_path'] ? $row['file_path'] : null
        ];
    }

    $response['success'] = true;
    $response['data']    = $data;

} catch (Exception $e) {
    $response['message'] = '❌ ' . $e->getMessage();
}

echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
?>
