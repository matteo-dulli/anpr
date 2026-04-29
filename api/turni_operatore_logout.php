<?php
/**
 * turni_operatore_logout.php
 * Chiude il turno:
 *  1. Calcola statistiche dal DB (operatori_log_azioni)
 *  2. Genera file .txt in /anpr/turni/
 *  3. Aggiorna turni_sessioni e operatori_turni (stato=offline)
 *  4. Ritorna { success, numero_turno, data_chiusura, file_path }
 */
header('Content-Type: application/json; charset=utf-8');
date_default_timezone_set('Europe/Rome');
require_once __DIR__ . '/../config/config.php';

$db = getDatabaseConnection();
$response = ['success' => false, 'message' => '', 'data' => null];

try {
    $body          = json_decode(file_get_contents('php://input'), true) ?? [];
    $operatore_cod = isset($body['operatore_cod']) ? trim($body['operatore_cod']) : '';
    $id_sessione   = isset($body['id_sessione'])   ? (int)$body['id_sessione']    : 0;

    if (!$operatore_cod) {
        throw new Exception('operatore_cod obbligatorio');
    }

    $now = date('Y-m-d H:i:s');

    // ----------------------------------------------------------------
    // 1. Leggi la sessione corrente
    // ----------------------------------------------------------------
    $sessione       = null;
    $numero_turno   = 0;
    $inizio_turno   = null;

    // Prima cerca in turni_sessioni
    $tableCheck = $db->query("SHOW TABLES LIKE 'turni_sessioni'");
    if ($tableCheck->rowCount() > 0) {
        if ($id_sessione > 0) {
            $s = $db->prepare("SELECT * FROM turni_sessioni WHERE id = ? AND operatore_cod = ? AND stato = 'online' LIMIT 1");
            $s->execute([$id_sessione, $operatore_cod]);
            $sessione = $s->fetch(PDO::FETCH_ASSOC);
        }
        if (!$sessione) {
            $s = $db->prepare("SELECT * FROM turni_sessioni WHERE operatore_cod = ? AND stato = 'online' ORDER BY inizio DESC LIMIT 1");
            $s->execute([$operatore_cod]);
            $sessione = $s->fetch(PDO::FETCH_ASSOC);
        }
    }

    if ($sessione) {
        $numero_turno = (int)$sessione['numero_turno'];
        $inizio_turno = $sessione['inizio'];
        $id_sessione  = (int)$sessione['id'];
    } else {
        // Fallback: operatori_turni
        $s = $db->prepare("SELECT inizio_turno FROM operatori_turni WHERE operatore_cod = ? LIMIT 1");
        $s->execute([$operatore_cod]);
        $row = $s->fetch(PDO::FETCH_ASSOC);
        $inizio_turno = $row ? $row['inizio_turno'] : $now;
        // Genera numero_turno progressivo
        try {
            $maxS       = $db->query("SELECT COALESCE(MAX(numero_turno), 0) + 1 AS n FROM turni_sessioni");
            $numero_turno = (int)$maxS->fetch(PDO::FETCH_ASSOC)['n'];
        } catch (Exception $e) {
            $numero_turno = 1;
        }
    }

    // ----------------------------------------------------------------
    // 2. Calcola statistiche dal log azioni (per il periodo del turno)
    // ----------------------------------------------------------------
    $ticket_emessi          = 0;
    $ticket_ristampati      = 0;
    $ticket_annullati       = 0;
    $ricevute               = 0;
    $ricevute_ristampate    = 0;

    try {
        $logTableCheck = $db->query("SHOW TABLES LIKE 'operatori_log_azioni'");
        if ($logTableCheck->rowCount() > 0) {
            if ($id_sessione > 0) {
                // Usa id_turno se disponibile
                $logStmt = $db->prepare("
                    SELECT azione, COUNT(*) as cnt
                    FROM operatori_log_azioni
                    WHERE operatore_cod = ?
                      AND data_ora >= ?
                    GROUP BY azione
                ");
                $logStmt->execute([$operatore_cod, $inizio_turno]);
            } else {
                $logStmt = $db->prepare("
                    SELECT azione, COUNT(*) as cnt
                    FROM operatori_log_azioni
                    WHERE operatore_cod = ?
                      AND data_ora >= ?
                    GROUP BY azione
                ");
                $logStmt->execute([$operatore_cod, $inizio_turno]);
            }

            while ($log = $logStmt->fetch(PDO::FETCH_ASSOC)) {
                switch ($log['azione']) {
                    case 'emetti_ticket':      $ticket_emessi       = (int)$log['cnt']; break;
                    case 'ristampa_ticket':    $ticket_ristampati   = (int)$log['cnt']; break;
                    case 'annulla_ticket':     $ticket_annullati    = (int)$log['cnt']; break;
                    case 'ricevuta':           $ricevute            = (int)$log['cnt']; break;
                    case 'ristampa_ricevuta':  $ricevute_ristampate = (int)$log['cnt']; break;
                }
            }
        }
    } catch (Exception $e) {
        // Log non disponibile, usa 0
    }

    // Pagamenti: prova da passages / invoices se disponibili
    $importo_contante = 0.00;
    $importo_online   = 0.00;

    try {
        $pCheck = $db->query("SHOW TABLES LIKE 'passages'");
        if ($pCheck->rowCount() > 0) {
            // Conta pagamenti dal momento di apertura turno
            $pStmt = $db->prepare("
                SELECT
                    SUM(CASE WHEN pay_cash = 1    THEN COALESCE(importo_pagato,0) ELSE 0 END) AS contante,
                    SUM(CASE WHEN pay_electronic = 1 THEN COALESCE(importo_pagato,0) ELSE 0 END) AS online
                FROM passages
                WHERE Ppaid = 1
                  AND created_at >= ?
            ");
            $pStmt->execute([$inizio_turno]);
            $pRow = $pStmt->fetch(PDO::FETCH_ASSOC);
            if ($pRow) {
                $importo_contante = (float)($pRow['contante'] ?? 0);
                $importo_online   = (float)($pRow['online']   ?? 0);
            }
        }
    } catch (Exception $e) {
        // Tabella non ha queste colonne, usa 0
    }

    $importo_totale = $importo_contante + $importo_online;

    // ----------------------------------------------------------------
    // 3. Genera file .txt
    // ----------------------------------------------------------------
    $turniDir = PROJECT_ROOT . '/turni';
    if (!is_dir($turniDir)) {
        @mkdir($turniDir, 0755, true);
    }

    $dataFormatoFile  = date('d-m-Y');
    $numFormatted     = str_pad($numero_turno, 3, '0', STR_PAD_LEFT);
    $fileName         = "{$numFormatted}_{$dataFormatoFile}.txt";
    $filePath         = $turniDir . '/' . $fileName;

    $oraInizio   = $inizio_turno ? date('H:i:s', strtotime($inizio_turno)) : '--:--:--';
    $oraChiusura = date('H:i:s');

    $content  = "═══════════════════════════════════════════\n";
    $content .= "RIEPILOGO TURNO N. " . str_pad($numero_turno, 3, '0', STR_PAD_LEFT) . "\n";
    $content .= "Data:       $dataFormatoFile\n";
    $content .= "Operatore:  $operatore_cod\n";
    $content .= "Ora inizio: $oraInizio\n";
    $content .= "Ora chiusura: $oraChiusura\n";
    $content .= "\n";
    $content .= "STATISTICHE EMISSIONI:\n";
    $content .= "─────────────────────\n";
    $content .= sprintf("Ticket emessi totale:      %5d\n", $ticket_emessi);
    $content .= sprintf("Ticket ristampati:         %5d\n", $ticket_ristampati);
    $content .= sprintf("Ticket annullati:          %5d\n", $ticket_annullati);
    $content .= sprintf("Ricevute emesse:           %5d\n", $ricevute);
    $content .= sprintf("Ricevute ristampate:       %5d\n", $ricevute_ristampate);
    $content .= "\n";
    $content .= "PAGAMENTI:\n";
    $content .= "──────────\n";
    $content .= sprintf("Contante:    € %10s\n", number_format($importo_contante, 2, ',', '.'));
    $content .= sprintf("Online:      € %10s\n", number_format($importo_online,   2, ',', '.'));
    $content .= sprintf("TOTALE:      € %10s\n", number_format($importo_totale,   2, ',', '.'));
    $content .= "\n";
    $content .= "═══════════════════════════════════════════\n";

    @file_put_contents($filePath, $content);

    // ----------------------------------------------------------------
    // 4. Aggiorna DB
    // ----------------------------------------------------------------
    // Aggiorna turni_sessioni
    if ($id_sessione > 0) {
        try {
            $upd = $db->prepare("
                UPDATE turni_sessioni
                SET stato          = 'offline',
                    fine           = ?,
                    ticket_emessi          = ?,
                    ticket_pagati_contanti = ?,
                    ticket_pagati_online   = ?,
                    ticket_annullati       = ?,
                    ricevute               = ?,
                    importo_contante       = ?,
                    importo_online         = ?,
                    importo_totale         = ?,
                    file_path              = ?
                WHERE id = ?
            ");
            $upd->execute([
                $now,
                $ticket_emessi,
                0, // pagati contanti (non tracciato a livello azione)
                0, // pagati online
                $ticket_annullati,
                $ricevute,
                $importo_contante,
                $importo_online,
                $importo_totale,
                $fileName,
                $id_sessione
            ]);
        } catch (Exception $e) {
            // Ignora se colonne non esistono ancora
        }
    }

    // Aggiorna operatori_turni
    try {
        $ore_totali = 0;
        if ($inizio_turno) {
            $diff = (new DateTime($now))->diff(new DateTime($inizio_turno));
            $ore_totali = $diff->h + ($diff->i / 60) + ($diff->s / 3600) + ($diff->days * 24);
        }

        $upd2 = $db->prepare("
            UPDATE operatori_turni
            SET stato = 'offline', fine_turno = ?, ore_totali = ore_totali + ?, updated_at = NOW()
            WHERE operatore_cod = ?
        ");
        $upd2->execute([$now, round($ore_totali, 2), $operatore_cod]);
    } catch (Exception $e) {
        // Ignora se tabella non ha queste colonne
    }

    if (function_exists('logEvent')) {
        logEvent('turni', "LOGOUT: Operatore $operatore_cod — Turno $numero_turno — File: $fileName");
    }

    $response['success'] = true;
    $response['message'] = "✅ Turno {$numFormatted} chiuso — Riepilogo salvato";
    $response['data']    = [
        'operatore_cod'  => $operatore_cod,
        'stato'          => 'offline',
        'numero_turno'   => $numero_turno,
        'data_chiusura'  => $now,
        'file_name'      => $fileName,
        'file_path'      => 'turni/' . $fileName,
        'statistiche'    => [
            'ticket_emessi'       => $ticket_emessi,
            'ticket_ristampati'   => $ticket_ristampati,
            'ticket_annullati'    => $ticket_annullati,
            'ricevute'            => $ricevute,
            'importo_contante'    => $importo_contante,
            'importo_online'      => $importo_online,
            'importo_totale'      => $importo_totale
        ]
    ];

} catch (Exception $e) {
    $response['message'] = '❌ ' . $e->getMessage();
    if (function_exists('logEvent')) {
        logEvent('error', 'TURNI_LOGOUT_ERROR: ' . $e->getMessage());
    }
}

http_response_code($response['success'] ? 200 : 400);
echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
?>
