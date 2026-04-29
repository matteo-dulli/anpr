<?php
header('Content-Type: application/json; charset=utf-8');
date_default_timezone_set('Europe/Rome');

require_once __DIR__ . '/../config/config.php';

$db = getDatabaseConnection();
$response = ['success' => false, 'data' => [], 'count' => 0, 'message' => ''];

try {
    $query = isset($_GET['q']) ? trim($_GET['q']) : '';
    
    if (strlen($query) < 2) {
        throw new Exception('Query troppo corta');
    }

    $items = [];

    // ===== 1. CERCA IL TICKET IN tickets_printed =====
    $sqlTickets = "
        SELECT id, ticket_code, plate_id, passage_id, entry_datetime
        FROM tickets_printed
        WHERE ticket_code = ?
        LIMIT 1
    ";
    
    $stmtTickets = $db->prepare($sqlTickets);
    $stmtTickets->execute([$query]);
    $ticket = $stmtTickets->fetch(PDO::FETCH_ASSOC);

    if ($ticket) {
        // ===== SE ABBINATO A UNA TARGA =====
        if (!empty($ticket['plate_id']) && $ticket['plate_id'] > 0) {
            $sqlPlate = "
                SELECT id, plate_number, plate_corrected, date_detected, is_manual, image_path 
                FROM plates 
                WHERE id = ?
            ";
            $stmtPlate = $db->prepare($sqlPlate);
            $stmtPlate->execute([$ticket['plate_id']]);
            $plate = $stmtPlate->fetch(PDO::FETCH_ASSOC);
            
            if ($plate) {
                $items[] = [
                    'id' => (int)$plate['id'],
                    'plate_number' => $plate['plate_number'],
                    'plate_corrected' => $plate['plate_corrected'],
                    'date_detected' => $plate['date_detected'],
                    'is_manual' => (int)$plate['is_manual'],
                    'image_path' => $plate['image_path'],
                    'is_passage' => 0,
                    'passage_id' => null,
                    'ticket_code' => $ticket['ticket_code']
                ];
            }
        }
        // ===== SE ABBINATO A UN PASSAGGIO =====
        elseif (!empty($ticket['passage_id']) && $ticket['passage_id'] > 0) {
            $sqlPassage = "
                SELECT id, entry_datetime, ticket_code, note
                FROM passages 
                WHERE id = ?
            ";
            $stmtPassage = $db->prepare($sqlPassage);
            $stmtPassage->execute([$ticket['passage_id']]);
            $passage = $stmtPassage->fetch(PDO::FETCH_ASSOC);
            
            if ($passage) {
                $items[] = [
                    'id' => -(int)$passage['id'],
                    'plate_number' => "🚶 PASSAGGIO " . $passage['id'],
                    'plate_corrected' => null,
                    'date_detected' => $passage['entry_datetime'],
                    'is_manual' => 1,
                    'image_path' => null,
                    'is_passage' => 1,
                    'passage_id' => (int)$passage['id'],
                    'ticket_code' => $ticket['ticket_code']
                ];
            }
        }
        // ✅ NUOVO: SE TICKET EMESSO STANDALONE (NESSUN ABBINAMENTO) =====
        else {
            $items[] = [
                'id' => -(int)$ticket['id'],
                'plate_number' => "🎫 TICKET #" . $ticket['id'],
                'plate_corrected' => null,
                'date_detected' => $ticket['entry_datetime'],
                'is_manual' => 1,
                'image_path' => null,
                'is_passage' => 1,
                'passage_id' => null,
                'ticket_code' => $ticket['ticket_code']
            ];
        }
    }

    // ===== 2. SE NON TROVATO IN tickets_printed, CERCA DIRETTAMENTE IN passages =====
    if (empty($items)) {
        $sqlPassagesDirect = "
            SELECT id, entry_datetime, ticket_code, note
            FROM passages
            WHERE ticket_code = ?
            LIMIT 1
        ";
        $stmtPassagesDirect = $db->prepare($sqlPassagesDirect);
        $stmtPassagesDirect->execute([$query]);
        $passageDirect = $stmtPassagesDirect->fetch(PDO::FETCH_ASSOC);

        if ($passageDirect) {
            $items[] = [
                'id' => -(int)$passageDirect['id'],
                'plate_number' => "🚶 PASSAGGIO " . $passageDirect['id'],
                'plate_corrected' => null,
                'date_detected' => $passageDirect['entry_datetime'],
                'is_manual' => 1,
                'image_path' => null,
                'is_passage' => 1,
                'passage_id' => (int)$passageDirect['id'],
                'ticket_code' => $passageDirect['ticket_code']
            ];
        }
    }

    // ===== 3. SE NON TROVATO COME BARCODE ESATTO, RICERCA TARGHE (SENZA LIMITE DI TEMPO) =====
    if (empty($items)) {
        $searchTerm = '%' . $query . '%';
        $sqlPlates = "
            SELECT id, plate_number, plate_corrected, date_detected, is_manual, image_path
            FROM plates
            WHERE 
                UPPER(plate_number) LIKE UPPER(?)
                OR UPPER(plate_corrected) LIKE UPPER(?)
            ORDER BY date_detected DESC
            LIMIT 100
        ";
        
        $stmtPlates = $db->prepare($sqlPlates);
        $stmtPlates->execute([$searchTerm, $searchTerm]);
        $plates = $stmtPlates->fetchAll(PDO::FETCH_ASSOC);

        foreach ($plates as $plate) {
            $items[] = [
                'id' => (int)$plate['id'],
                'plate_number' => $plate['plate_number'],
                'plate_corrected' => $plate['plate_corrected'],
                'date_detected' => $plate['date_detected'],
                'is_manual' => (int)$plate['is_manual'],
                'image_path' => $plate['image_path'],
                'is_passage' => 0,
                'passage_id' => null,
                'ticket_code' => null
            ];
        }
    }

    // ===== 4. SE ANCORA NULLA, RICERCA PASSAGGI PER PATTERN (SENZA LIMITE DI TEMPO) =====
    if (empty($items)) {
        $searchTerm = '%' . $query . '%';
        $sqlPassages = "
            SELECT id, entry_datetime, ticket_code, note
            FROM passages
            WHERE UPPER(ticket_code) LIKE UPPER(?)
            ORDER BY entry_datetime DESC
            LIMIT 100
        ";
        
        $stmtPassages = $db->prepare($sqlPassages);
        $stmtPassages->execute([$searchTerm]);
        $passages = $stmtPassages->fetchAll(PDO::FETCH_ASSOC);

        foreach ($passages as $pg) {
            $items[] = [
                'id' => -(int)$pg['id'],
                'plate_number' => "🚶 PASSAGGIO " . $pg['id'],
                'plate_corrected' => null,
                'date_detected' => $pg['entry_datetime'],
                'is_manual' => 1,
                'image_path' => null,
                'is_passage' => 1,
                'passage_id' => (int)$pg['id'],
                'ticket_code' => $pg['ticket_code']
            ];
        }
    }

    // =====================================================================
    // ===== 5. NUOVO: CERCA TICKET PER PATTERN in tickets_printed (LIKE) =====
    // =====================================================================
    if (empty($items)) {
        $searchTerm = '%' . $query . '%';

        $sqlTicketsLike = "
            SELECT id, ticket_code, plate_id, passage_id, entry_datetime
            FROM tickets_printed
            WHERE UPPER(ticket_code) LIKE UPPER(?)
            ORDER BY entry_datetime DESC
            LIMIT 100
        ";

        $stmtTicketsLike = $db->prepare($sqlTicketsLike);
        $stmtTicketsLike->execute([$searchTerm]);
        $ticketsLike = $stmtTicketsLike->fetchAll(PDO::FETCH_ASSOC);

        foreach ($ticketsLike as $tk) {
            // ticket -> targa
            if (!empty($tk['plate_id']) && (int)$tk['plate_id'] > 0) {
                $stmtPlate = $db->prepare("
                    SELECT id, plate_number, plate_corrected, date_detected, is_manual, image_path
                    FROM plates
                    WHERE id = ?
                    LIMIT 1
                ");
                $stmtPlate->execute([(int)$tk['plate_id']]);
                $plate = $stmtPlate->fetch(PDO::FETCH_ASSOC);

                if ($plate) {
                    $items[] = [
                        'id' => (int)$plate['id'],
                        'plate_number' => $plate['plate_number'],
                        'plate_corrected' => $plate['plate_corrected'],
                        'date_detected' => $plate['date_detected'],
                        'is_manual' => (int)$plate['is_manual'],
                        'image_path' => $plate['image_path'],
                        'is_passage' => 0,
                        'passage_id' => null,
                        'ticket_code' => $tk['ticket_code']
                    ];
                }
            }
            // ticket -> passaggio
            elseif (!empty($tk['passage_id']) && (int)$tk['passage_id'] > 0) {
                $items[] = [
                    'id' => -(int)$tk['passage_id'],
                    'plate_number' => "🚶 PASSAGGIO " . (int)$tk['passage_id'],
                    'plate_corrected' => null,
                    'date_detected' => $tk['entry_datetime'],
                    'is_manual' => 1,
                    'image_path' => null,
                    'is_passage' => 1,
                    'passage_id' => (int)$tk['passage_id'],
                    'ticket_code' => $tk['ticket_code']
                ];
            }
            // ticket standalone
            else {
                $items[] = [
                    'id' => -(int)$tk['id'],
                    'plate_number' => "🎫 TICKET #" . (int)$tk['id'],
                    'plate_corrected' => null,
                    'date_detected' => $tk['entry_datetime'],
                    'is_manual' => 1,
                    'image_path' => null,
                    'is_passage' => 1,
                    'passage_id' => null,
                    'ticket_code' => $tk['ticket_code']
                ];
            }
        }
    }

    // =====================================================================
// ===== 6. NUOVO: CERCA RICEVUTE PER PATTERN in invoices_printed (LIKE) ==
// =====================================================================
if (empty($items)) {
    $searchTerm = '%' . $query . '%';

    // OLD (ERRATO: nella tabella non esiste plate_id)
    // $sqlReceiptsLike = "
    //     SELECT id, receipt_code, plate_id, passage_id, created_at
    //     FROM invoices_printed
    //     WHERE UPPER(receipt_code) LIKE UPPER(?)
    //     ORDER BY created_at DESC
    //     LIMIT 100
    // ";

    // NEW (CORRETTO: campi reali)
    $sqlReceiptsLike = "
        SELECT id, receipt_code, passage_id, entry_datetime, exit_datetime, price, created_at
        FROM invoices_printed
        WHERE UPPER(receipt_code) LIKE UPPER(?)
        ORDER BY created_at DESC
        LIMIT 100
    ";

    $stmtReceiptsLike = $db->prepare($sqlReceiptsLike);
    $stmtReceiptsLike->execute([$searchTerm]);
    $receiptsLike = $stmtReceiptsLike->fetchAll(PDO::FETCH_ASSOC);

    foreach ($receiptsLike as $rc) {
        // ricevuta -> passaggio (apribile)
        if (!empty($rc['passage_id']) && (int)$rc['passage_id'] > 0) {
            $items[] = [
                // mantengo id negativo come nel tuo stile (non è fondamentale)
                'id' => -(int)$rc['passage_id'],
                'plate_number' => "🧾 RICEVUTA " . $rc['receipt_code'] . " · 🚶 PASSAGGIO " . (int)$rc['passage_id'],
                'plate_corrected' => null,
                // per uniformità col dropdown uso created_at se c’è, altrimenti entry_datetime
                'date_detected' => $rc['created_at'] ?: $rc['entry_datetime'],
                'is_manual' => 1,
                'image_path' => null,
                'is_passage' => 1,
                'passage_id' => (int)$rc['passage_id'],
                // per far vedere il codice nel dropdown (JS già mostra ticket_code)
                'ticket_code' => $rc['receipt_code']
            ];
        }
        // ricevuta standalone (non collegata a passaggio)
        else {
            $items[] = [
                'id' => -(int)$rc['id'],
                'plate_number' => "🧾 RICEVUTA " . $rc['receipt_code'],
                'plate_corrected' => null,
                'date_detected' => $rc['created_at'] ?: $rc['entry_datetime'],
                'is_manual' => 1,
                'image_path' => null,
                // la segno come non-passaggio: non è apribile con selectPassage
                'is_passage' => 0,
                'passage_id' => null,
                'ticket_code' => $rc['receipt_code']
            ];
        }
    }
}

    $response['success'] = true;
    $response['data'] = $items;
    $response['count'] = count($items);

} catch (Exception $e) {
    $response['success'] = false;
    $response['message'] = '❌ ' . $e->getMessage();
}

echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
?>