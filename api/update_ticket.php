<?php
header('Content-Type: application/json');
date_default_timezone_set('Europe/Rome');
require_once __DIR__ . '/../config/config.php';

$db = getDatabaseConnection();
$response = ['success' => false, 'message' => ''];

try {
    $data = json_decode(file_get_contents('php://input'), true);

    if (!isset($data['plate_id'])) {
        throw new Exception('ID targa mancante');
    }

    $plateId = (int)$data['plate_id'];

    // ===== FUNZIONE PER CONVERTIRE STRINGHE VUOTE A NULL =====
    $getDateValue = function($value) {
        return (!empty($value) && $value !== '') ? $value : NULL;
    };

    // Normalizza plate_number
    $plateNumber = strtoupper(trim($data['plate_number'] ?? ''));

    // VEICOLO (da propagare, tranne posizione)
    $vehicleType        = $data['vehicle_type'] ?? '';
    $vehicleBrand       = $data['vehicle_brand'] ?? '';
    $vehicleColor       = $data['vehicle_color'] ?? '';
    $vehiclePosition    = $data['vehicle_position'] ?? '';
    $notes              = $data['notes'] ?? '';
    $authorizedVehicle  = $data['authorized_vehicle'] ?? '';

    // ABBONAMENTO (si propaga solo se data_detected è nel range)
    $subscription       = $data['subscription'] ?? '';
    $subscriptionFrom   = $getDateValue($data['subscription_from'] ?? '');
    $subscriptionTo     = $getDateValue($data['subscription_to'] ?? '');

    // CAMPI TICKET SOLO PER QUESTA TARGA (NON si propagano)
    $ticketInfo         = $data['ticket_info'] ?? '';
    $ticketCode         = $data['ticket_code'] ?? '';
    $entryDate          = $getDateValue($data['entry_date'] ?? '');
    $entryTime          = $getDateValue($data['entry_time'] ?? '');
    $exitDate           = $getDateValue($data['exit_date'] ?? '');
    $exitTime           = $getDateValue($data['exit_time'] ?? '');
    $paid               = (int)($data['paid'] ?? 0);
    $ticketPrepaid      = (int)($data['ticket_prepaid'] ?? 0);
    $ticketFrom         = $getDateValue($data['ticket_from'] ?? '');
    $ticketTo           = $getDateValue($data['ticket_to'] ?? '');
    $ticketBalance      = (float)($data['ticket_balance'] ?? 0.00);

    // ✅ fascia: se non vuota, verrà scritta su tickets.fascia
    $fascia             = isset($data['fascia']) ? trim((string)$data['fascia']) : '';
    if (!in_array($fascia, ['F1', 'F2', 'F3', 'F4', 'F5'])) {
        $fascia = '';
    }

    // ✅ CAMPI PER TABELLA CASSA (ingresso/uscita)
    $Tentry_date        = $getDateValue($data['Tentry_date'] ?? '');
    $Tentry_time        = $getDateValue($data['Tentry_time'] ?? '');
    $Texit_date         = $getDateValue($data['Texit_date'] ?? '');
    $Texit_time         = $getDateValue($data['Texit_time'] ?? '');

    // ✅ CAMPI PAGAMENTO PER TABELLA CASSA
    $annullato          = (int)($data['annullato'] ?? 0);
    $motivo             = $data['motivo'] ?? '';
    $pay_cash           = (int)($data['pay_cash'] ?? 0);
    $pay_electronic     = (int)($data['pay_electronic'] ?? 0);

    // ===== INIZIA TRANSAZIONE =====
    $db->beginTransaction();

    // ===== 1. AGGIORNA TARGA CORRENTE IN plates =====
    $stmt = $db->prepare("
        UPDATE plates
        SET plate_number = ?, plate_corrected = ?
        WHERE id = ?
    ");
    $stmt->execute([
        $plateNumber,
        $plateNumber,
        $plateId
    ]);

    // ===== 2. OTTIENI INFO TARGA CORRENTE (plate_corrected, date_detected) =====
    $stmt = $db->prepare("SELECT plate_number, plate_corrected, date_detected FROM plates WHERE id = ?");
    $stmt->execute([$plateId]);
    $plateRow = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$plateRow) {
        throw new Exception('Targa non trovata dopo aggiornamento');
    }

    $canonicalPlate    = $plateRow['plate_corrected'] ?: $plateRow['plate_number'];
    $thisDateDetected  = $plateRow['date_detected'];

    // ===== 3. AGGIORNA O CREA TICKET SOLO PER plate_id CORRENTE =====
    $stmt = $db->prepare("
        INSERT INTO tickets (
            plate_id,
            ticket_info,
            vehicle_type,
            vehicle_brand,
            vehicle_color,
            vehicle_position,
            entry_date,
            entry_time,
            exit_date,
            exit_time,
            notes,
            paid,
            subscription,
            subscription_from,
            subscription_to,
            ticket_prepaid,
            ticket_from,
            ticket_to,
            ticket_balance,
            authorized_vehicle,
            fascia
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            ticket_info        = VALUES(ticket_info),
            vehicle_type       = VALUES(vehicle_type),
            vehicle_brand      = VALUES(vehicle_brand),
            vehicle_color      = VALUES(vehicle_color),
            vehicle_position   = VALUES(vehicle_position),
            entry_date         = VALUES(entry_date),
            entry_time         = VALUES(entry_time),
            exit_date          = VALUES(exit_date),
            exit_time          = VALUES(exit_time),
            notes              = VALUES(notes),
            paid               = VALUES(paid),
            subscription       = VALUES(subscription),
            subscription_from  = VALUES(subscription_from),
            subscription_to    = VALUES(subscription_to),
            ticket_prepaid     = VALUES(ticket_prepaid),
            ticket_from        = VALUES(ticket_from),
            ticket_to          = VALUES(ticket_to),
            ticket_balance     = VALUES(ticket_balance),
            authorized_vehicle = VALUES(authorized_vehicle),
            fascia             = CASE WHEN VALUES(fascia) != '' THEN VALUES(fascia) ELSE fascia END
    ");

    $stmt->execute([
        $plateId,
        $ticketInfo,
        $vehicleType,
        $vehicleBrand,
        $vehicleColor,
        $vehiclePosition,
        $entryDate,
        $entryTime,
        $exitDate,
        $exitTime,
        $notes,
        $paid,
        $subscription,
        $subscriptionFrom,
        $subscriptionTo,
        $ticketPrepaid,
        $ticketFrom,
        $ticketTo,
        $ticketBalance,
        $authorizedVehicle,
        $fascia ?: null
    ]);

    // ===== 3b. SALVA/AGGIORNA ticket_code IN tickets_printed =====
    // FIX:
    // - MAI aggiornare tutte le righe per plate_id
    // - Aggiorna SOLO la riga più recente della targa (ORDER BY id DESC LIMIT 1)
    // - NON fare INSERT qui (tickets_printed viene creato da emit_ticket.php)
    if (!empty($ticketCode)) {

        //// ===== VECCHIO CODICE (NON USARE) =====
        //// $checkStmt = $db->prepare("SELECT id FROM tickets_printed WHERE plate_id = ? LIMIT 1");
        //// $checkStmt->execute([$plateId]);
        //// $existingTicket = $checkStmt->fetch(PDO::FETCH_ASSOC);
        ////
        //// if ($existingTicket) {
        ////     $updateStmt = $db->prepare("
        ////         UPDATE tickets_printed
        ////         SET ticket_code = ?, plate_number = ?
        ////         WHERE plate_id = ?
        ////     ");
        ////     $updateStmt->execute([
        ////         $ticketCode,
        ////         $canonicalPlate,
        ////         $plateId
        ////     ]);
        //// } else {
        ////     $insertStmt = $db->prepare("
        ////         INSERT INTO tickets_printed (ticket_code, plate_id, plate_number, entry_datetime)
        ////         VALUES (?, ?, ?, NOW())
        ////     ");
        ////     $insertStmt->execute([
        ////         $ticketCode,
        ////         $plateId,
        ////         $canonicalPlate
        ////     ]);
        //// }
        //// ===== FINE VECCHIO CODICE =====

        // ✅ NUOVO CODICE: aggiorna SOLO l'ultima riga per quella targa
        $checkStmt = $db->prepare("
            SELECT id, ticket_code
            FROM tickets_printed
            WHERE plate_id = ?
            ORDER BY id DESC
            LIMIT 1
        ");
        $checkStmt->execute([$plateId]);
        $lastPrinted = $checkStmt->fetch(PDO::FETCH_ASSOC);

        if ($lastPrinted) {
            // Se ticket_code è lo stesso, aggiorna solo plate_number
            if ($lastPrinted['ticket_code'] === $ticketCode) {
                $updateStmt = $db->prepare("
                    UPDATE tickets_printed
                    SET plate_number = ?
                    WHERE id = ?
                ");
                $updateStmt->execute([
                    $canonicalPlate,
                    (int)$lastPrinted['id']
                ]);
            } else {
                // Se ticket_code è diverso, aggiorna solo la riga più recente (non tutte per plate_id)
                $updateStmt = $db->prepare("
                    UPDATE tickets_printed
                    SET ticket_code = ?, plate_number = ?
                    WHERE id = ?
                ");
                $updateStmt->execute([
                    $ticketCode,
                    $canonicalPlate,
                    (int)$lastPrinted['id']
                ]);
            }
        } else {
            // ✅ Conservativo: NON inserire tickets_printed qui.
            // Se vuoi gestire casi edge (mai dovrebbe succedere), puoi scommentare l'insert.
            //// $insertStmt = $db->prepare("
            ////     INSERT INTO tickets_printed (ticket_code, plate_id, plate_number, entry_datetime)
            ////     VALUES (?, ?, ?, NOW())
            //// ");
            //// $insertStmt->execute([
            ////     $ticketCode,
            ////     $plateId,
            ////     $canonicalPlate
            //// ]);
        }
    }

    // ===== 3c. ✅ SALVA SU TABELLA CASSA (Ingresso/Uscita/Pagamento) =====
    if (!empty($Tentry_date) || !empty($Tentry_time) || !empty($Texit_date) || !empty($Texit_time)) {
        // Verifica se esiste già un record in cassa per questa targa
        $checkCassaStmt = $db->prepare("SELECT idcassa FROM cassa WHERE Tplate_id = ? LIMIT 1");
        $checkCassaStmt->execute([$plateId]);
        $existingCassa = $checkCassaStmt->fetch(PDO::FETCH_ASSOC);

        if ($existingCassa) {
            // Aggiorna il record esistente
            $updateCassaStmt = $db->prepare("
                UPDATE cassa
                SET
                    Tplate_id = ?,
                    Tticket_code = ?,
                    Tentry_date = ?,
                    Tentry_time = ?,
                    Texit_date = ?,
                    Texit_time = ?,
                    Tannullato = ?,
                    Tannultxt = ?,
                    TpayC = ?,
                    TpayE = ?
                WHERE Tplate_id = ?
            ");
            $updateCassaStmt->execute([
                $plateId,
                $ticketCode,
                $Tentry_date,
                $Tentry_time,
                $Texit_date,
                $Texit_time,
                $annullato,
                $motivo,
                $pay_cash,
                $pay_electronic,
                $plateId
            ]);
        } else {
            // Crea un nuovo record
            $insertCassaStmt = $db->prepare("
                INSERT INTO cassa (
                    Tplate_id,
                    Tticket_code,
                    Tentry_date,
                    Tentry_time,
                    Texit_date,
                    Texit_time,
                    Tannullato,
                    Tannultxt,
                    TpayC,
                    TpayE
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $insertCassaStmt->execute([
                $plateId,
                $ticketCode,
                $Tentry_date,
                $Tentry_time,
                $Texit_date,
                $Texit_time,
                $annullato,
                $motivo,
                $pay_cash,
                $pay_electronic
            ]);
        }
    }

    // ===== 4. PROPAGAZIONE SU ALTRE TARGHE CON STESSA TARGA =====
    // NOTE: Questa parte non è direttamente responsabile del duplicato su uq_ticket_code.
    // La lasciamo invariata.

    // 4.1 Trova tutti gli id di plates con stessa targa (plate_corrected o plate_number), escluso quello corrente
    $stmt = $db->prepare("
        SELECT id, date_detected
        FROM plates
        WHERE id <> ?
          AND (plate_corrected = ? OR plate_number = ?)
    ");
    $stmt->execute([$plateId, $canonicalPlate, $canonicalPlate]);
    $otherPlates = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if ($otherPlates) {
        // 4.2 Campi veicolo da propagare (tranne posizione)
        $baseTicketFields = [
            'vehicle_type'       => $vehicleType,
            'vehicle_brand'      => $vehicleBrand,
            'vehicle_color'      => $vehicleColor,
            'authorized_vehicle' => $authorizedVehicle,
            'notes'              => $notes,
        ];

        // 4.3 Campi abbonamento da propagare solo se abbiamo un intervallo valido
        $hasSubscription = !empty($subscription) && !empty($subscriptionFrom) && !empty($subscriptionTo);

        foreach ($otherPlates as $op) {
            $otherId           = (int)$op['id'];
            $otherDateDetected = $op['date_detected'];

            // Verifica se va propagato anche l'abbonamento per questa targa
            $applySubscription = false;
            if ($hasSubscription && !empty($otherDateDetected)) {
                $od = substr($otherDateDetected, 0, 10);
                if ($od >= $subscriptionFrom && $od <= $subscriptionTo) {
                    $applySubscription = true;
                }
            }

            $subFields = [];
            if ($applySubscription) {
                $subFields = [
                    'subscription'      => $subscription,
                    'subscription_from' => $subscriptionFrom,
                    'subscription_to'   => $subscriptionTo,
                ];
            }

            $updateFields = array_merge($baseTicketFields, $subFields);

            // INSERT ... ON DUPLICATE per questo plate_id
            $stmt = $db->prepare("
                INSERT INTO tickets (
                    plate_id,
                    vehicle_type,
                    vehicle_brand,
                    vehicle_color,
                    notes,
                    authorized_vehicle,
                    subscription,
                    subscription_from,
                    subscription_to
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    vehicle_type       = VALUES(vehicle_type),
                    vehicle_brand      = VALUES(vehicle_brand),
                    vehicle_color      = VALUES(vehicle_color),
                    notes              = VALUES(notes),
                    authorized_vehicle = VALUES(authorized_vehicle),
                    subscription       = VALUES(subscription),
                    subscription_from  = VALUES(subscription_from),
                    subscription_to    = VALUES(subscription_to)
            ");

            $stmt->execute([
                $otherId,
                $updateFields['vehicle_type']       ?? null,
                $updateFields['vehicle_brand']      ?? null,
                $updateFields['vehicle_color']      ?? null,
                $updateFields['notes']              ?? null,
                $updateFields['authorized_vehicle'] ?? null,
                $updateFields['subscription']       ?? null,
                $updateFields['subscription_from']  ?? null,
                $updateFields['subscription_to']    ?? null,
            ]);
        }
    }

    // ===== COMMIT =====
    $db->commit();

    $response['success'] = true;
    $response['message'] = '✅ Ticket aggiornato correttamente su tickets e cassa';

} catch (Exception $e) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    $response['message'] = '❌ ' . $e->getMessage();
    logEvent('error', 'UPDATE_TICKET_ERROR: ' . $e->getMessage());
}

echo json_encode($response, JSON_PRETTY_PRINT);
?>