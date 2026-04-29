<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config/config.php';

$db = getDatabaseConnection();
$response = ['success' => false, 'message' => ''];

try {
    $raw = file_get_contents('php://input');
    $body = $raw ? json_decode($raw, true) : [];

    // 🔍 DEBUG: salva sempre il body completo ricevuto
    file_put_contents(__DIR__.'/debug-save.log', "[IN] ".date('c')." ".print_r($body, true) . "\n", FILE_APPEND);

    $passageId = isset($body['passage_id']) ? (int)$body['passage_id'] : 0;
    $plateId   = isset($body['plate_id']) ? (int)$body['plate_id'] : 0; // può servire per targa

    if ($passageId <= 0 && $plateId <= 0) throw new Exception('passage_id o plate_id richiesto');

    // Estrazione e normalizzazione dei campi principali
    $Ppaid        = isset($body['Ppaid']) ? (int)$body['Ppaid'] : 0;
    $PpayC        = isset($body['PpayC']) ? (int)$body['PpayC'] : 0;
    $PpayE        = isset($body['PpayE']) ? (int)$body['PpayE'] : 0;
    $Pannullato   = isset($body['Pannullato']) ? (int)$body['Pannullato'] : 0;
    $Pannultxt    = isset($body['Pannultxt']) ? trim($body['Pannultxt']) : '';

    // PATCH: Salva SEMPRE Pticket_code col valore effettivo del ticket reale associato al passaggio!
    // 🔻 Vecchia versione che lasciava facilmente vuoto o null
    // $Pticket_code = isset($body['Pticket_code']) ? $body['Pticket_code'] : null;
    //
    // PATCH: migliorato — verifica se il campo manca o è vuoto; se sì cerca altri possibili campi dal body (ticket_code o ticket_code_printed)
    $Pticket_code = null;
    if (!empty($body['Pticket_code'])) {
        $Pticket_code = $body['Pticket_code'];
    } elseif (!empty($body['ticket_code'])) {
        $Pticket_code = $body['ticket_code'];
    } elseif (!empty($body['ticket_code_printed'])) {
        $Pticket_code = $body['ticket_code_printed'];
    }
    // NB: così se passa Pticket_code vuoto ma ticket_code c'è, usi ticket_code!

    // Gestione prezzo
    if (isset($body['invoice_price']) && $body['invoice_price'] !== '') {
        $invoice_price = floatval($body['invoice_price']);
    } elseif (isset($body['prezzo']) && $body['prezzo'] !== '') {
        $invoice_price = floatval($body['prezzo']);
    } else {
        $invoice_price = 0.0;
    }

    $fascia = isset($body['fascia']) ? $body['fascia'] : null;

    // ===== PATCH: normalizzazione datetime per evitare ' :00' =====
    // Nota: alcuni client inviano "YYYY-MM-DD HH:MM" o "YYYY-MM-DD HH:MM:SS", altri inviano vuoto.
    // Qui trasformiamo qualsiasi valore vuoto / non valido in NULL.
    $entry_datetime = isset($body['entry_datetime']) ? $body['entry_datetime'] : null;
    $exit_datetime  = isset($body['exit_datetime'])  ? $body['exit_datetime']  : null;

    $normalizeDatetime = function ($v) {
        if ($v === null) return null;
        if (!is_string($v)) return $v; // se arriva già null o altro, lascialo
        $v = trim($v);

        // casi vuoti / rotti tipici dal frontend
        if ($v === '' || $v === ':00' || $v === ' :00' || $v === ' :00:00') return null;

        // se contiene solo ora senza data, invalido
        if (preg_match('/^\d{1,2}:\d{2}(:\d{2})?$/', $v)) return null;

        // normalizza "YYYY-MM-DD HH:MM" -> "YYYY-MM-DD HH:MM:SS"
        if (preg_match('/^\d{4}-\d{2}-\d{2}\s+\d{2}:\d{2}$/', $v)) {
            $v .= ':00';
        }

        // validazione base
        if (!preg_match('/^\d{4}-\d{2}-\d{2}\s+\d{2}:\d{2}:\d{2}$/', $v)) {
            return null;
        }

        return $v;
    };

    $entry_datetime_norm = $normalizeDatetime($entry_datetime);
    $exit_datetime_norm  = $normalizeDatetime($exit_datetime);

    // info / note
    $info = isset($body['info']) ? $body['info'] : null;

    // PATCH: fallback se il client manda "notes" invece di "note"
    $note = null;
    if (array_key_exists('note', $body)) {
        $note = $body['note'];
    } elseif (array_key_exists('notes', $body)) {
        $note = $body['notes'];
    }
    // ===== FINE PATCH datetime =====

    // 🔍 DEBUG: mostra tutti i parametri preparati per la query SQL
    error_log("[DEBUG SAVE] passageId=$passageId | Ppaid=$Ppaid | PpayC=$PpayC | PpayE=$PpayE | Pannullato=$Pannullato | Pannultxt=$Pannultxt | invoice_price=$invoice_price | fascia=$fascia | Pticket_code=$Pticket_code | entry_dt={$entry_datetime_norm} | exit_dt={$exit_datetime_norm}");

    // PATCH: aggiorna TUTTI i campi con l'ordine giusto
    if ($passageId > 0) {
        $stmt = $db->prepare("
            INSERT INTO cassa (
                idpassages, Ppaid, PpayC, PpayE, Pannullato, Pannultxt, invoice_price, fascia, Pticket_code, updated_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE
                Ppaid=VALUES(Ppaid), PpayC=VALUES(PpayC), PpayE=VALUES(PpayE),
                Pannullato=VALUES(Pannullato), Pannultxt=VALUES(Pannultxt),
                invoice_price=VALUES(invoice_price), fascia=VALUES(fascia),
                Pticket_code=VALUES(Pticket_code), updated_at=NOW()
        ");
        // Debug: stampa l'array dei valori a video/log
        error_log("[DEBUG EXECUTE] ".json_encode([
            $passageId, $Ppaid, $PpayC, $PpayE, $Pannullato, $Pannultxt, $invoice_price, $fascia, $Pticket_code
        ]));
        $ok = $stmt->execute([
            $passageId, $Ppaid, $PpayC, $PpayE, $Pannullato, $Pannultxt, $invoice_price, $fascia, $Pticket_code
        ]);
        if (!$ok || $stmt->errorCode() !== '00000') {
            $errInfo = $stmt->errorInfo();
            throw new Exception('SQL error: '.$errInfo[2]);
        }
    }

    // PATCH: eventuale update su passages (opzionale, da customizzare)
    // ===== PATCH: usa i datetime normalizzati e non quelli raw =====
    $toUpdate = [];
    $queryParams = [];

    // entry_datetime / exit_datetime: usa i normalizzati
    if (array_key_exists('entry_datetime', $body)) {
        $toUpdate[] = "`entry_datetime`=?";
        $queryParams[] = $entry_datetime_norm; // può essere NULL
    }
    if (array_key_exists('exit_datetime', $body)) {
        $toUpdate[] = "`exit_datetime`=?";
        $queryParams[] = $exit_datetime_norm; // può essere NULL
    }

    // ticket_code: se arriva
    if (array_key_exists('ticket_code', $body)) {
        $toUpdate[] = "`ticket_code`=?";
        $queryParams[] = $body['ticket_code'];
    }

    // note/info: se arrivano
    if (array_key_exists('note', $body) || array_key_exists('notes', $body)) {
        $toUpdate[] = "`note`=?";
        $queryParams[] = $note;
    }
    if (array_key_exists('info', $body)) {
        $toUpdate[] = "`info`=?";
        $queryParams[] = $info;
    }

    if (!empty($toUpdate) && $passageId > 0) {
        $sql = "UPDATE passages SET " . implode(', ', $toUpdate) . " WHERE id=?";
        $queryParams[] = $passageId;
        $db->prepare($sql)->execute($queryParams);
    }
    // ===== FINE PATCH passages =====

    // Gestione targa: opzionale da personalizzare
    /*
    if ($plateId > 0) {
        // [PATCH TARGA QUI]
    }
    */

    $response['success'] = true;
    $response['message'] = '✅ Dati passaggio/targa salvati/aggiornati con successo';

} catch (Exception $e) {
    $response['success'] = false;
    $response['message'] = '❌ ' . $e->getMessage();
}

echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
?>