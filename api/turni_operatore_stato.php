<?php
/**
 * turni_operatore_stato.php
 * Controlla se c'è un turno attivo in questo momento (stato = 'online')
 * Usato dal JS al caricamento pagina per ripristinare lo stato
 * Response: { success, data: { operatore_cod, stato, inizio_turno, id_sessione, numero_turno } }
 */
header('Content-Type: application/json; charset=utf-8');
date_default_timezone_set('Europe/Rome');
require_once __DIR__ . '/../config/config.php';

$response = ['success' => false, 'message' => '', 'data' => null];

try {
    $db = getDatabaseConnection();

    // Prima cerca in turni_sessioni (tabella sessioni singole)
    $sessione = null;
    try {
        $tableCheck = $db->query("SHOW TABLES LIKE 'turni_sessioni'");
        if ($tableCheck->rowCount() > 0) {
            $stmt = $db->query("
                SELECT id, operatore_cod, stato, inizio, numero_turno
                FROM turni_sessioni
                WHERE stato = 'online'
                ORDER BY inizio DESC
                LIMIT 1
            ");
            $sessione = $stmt->fetch(PDO::FETCH_ASSOC);
        }
    } catch (Exception $e) {
        // Tabella non disponibile, ignora
    }

    if ($sessione) {
        $response['success'] = true;
        $response['data']    = [
            'operatore_cod' => $sessione['operatore_cod'],
            'stato'         => 'online',
            'inizio_turno'  => $sessione['inizio'],
            'id_sessione'   => (int)$sessione['id'],
            'numero_turno'  => (int)$sessione['numero_turno']
        ];
    } else {
        // Fallback: controlla operatori_turni (tabella legacy)
        try {
            $stmt2 = $db->query("
                SELECT operatore_cod, stato, inizio_turno
                FROM operatori_turni
                WHERE stato = 'online'
                LIMIT 1
            ");
            $turno = $stmt2->fetch(PDO::FETCH_ASSOC);

            if ($turno) {
                $response['success'] = true;
                $response['data']    = [
                    'operatore_cod' => $turno['operatore_cod'],
                    'stato'         => 'online',
                    'inizio_turno'  => $turno['inizio_turno'],
                    'id_sessione'   => null,
                    'numero_turno'  => null
                ];
            }
            // else: nessun turno aperto → success=false, data=null
        } catch (Exception $e) {
            // Tabella non disponibile
        }
    }

} catch (Exception $e) {
    $response['message'] = '❌ ' . $e->getMessage();
}

echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
?>
