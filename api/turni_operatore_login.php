<?php
header('Content-Type: application/json; charset=utf-8');
date_default_timezone_set('Europe/Rome');
require_once __DIR__ . '/../config/config.php';

$db = getDatabaseConnection();
$response = ['success' => false, 'message' => '', 'data' => null];

try {
    $body = json_decode(file_get_contents('php://input'), true) ?? [];
    $operatore_cod = isset($body['operatore_cod']) ? trim($body['operatore_cod']) : '';

    if (!$operatore_cod) {
        throw new Exception('operatore_cod obbligatorio');
    }

    // ✅ NUOVO: verifica operatore da costanti.txt (non da DB)
    $costanti_file = __DIR__ . '/../costanti.txt';
    if (!file_exists($costanti_file)) {
        throw new Exception('File costanti.txt non trovato');
    }

    $operatore_trovato = false;
    $operatore_nome = '';
    $in_operatori_section = false;
    $lines = file($costanti_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

    foreach ($lines as $line) {
        $line = trim($line);
        
        // Inizio sezione operatori
        if (strpos($line, '# OPERATORI') === 0) {
            $in_operatori_section = true;
            continue;
        }
        
        // Fine sezione
        if ($in_operatori_section && strpos($line, '#') === 0 && strpos($line, '# OPERATORI') !== 0) {
            break;
        }

        // Leggi operatori
        if ($in_operatori_section && strpos($line, '=') !== false && strpos($line, '#') !== 0) {
            $parts = explode('=', $line, 2);
            if (count($parts) === 2) {
                $cod = trim($parts[0]);
                $nome = trim($parts[1]);
                
                if ($cod === $operatore_cod) {
                    $operatore_trovato = true;
                    $operatore_nome = $nome;
                    break;
                }
            }
        }
    }

    if (!$operatore_trovato) {
        throw new Exception("Operatore $operatore_cod non trovato in costanti.txt");
    }

    $now = date('Y-m-d H:i:s');

    // ✅ CORRETTO: crea automaticamente il record se non esiste
    // Primo: verifica se esiste
    $chk = $db->prepare("SELECT id FROM operatori_turni WHERE operatore_cod = ? LIMIT 1");
    $chk->execute([$operatore_cod]);
    $existing = $chk->fetch();

    if (!$existing) {
        // ✅ CORRETTO: INSERT senza colonna 'nome' (non esiste nella tabella)
        $ins = $db->prepare("
            INSERT INTO operatori_turni (operatore_cod, stato, inizio_turno, login_time, created_at, updated_at)
            VALUES (?, 'online', ?, ?, NOW(), NOW())
        ");
        $ins->execute([$operatore_cod, $now, $now]);
    } else {
        // Aggiorna record esistente
        $upd = $db->prepare("
            UPDATE operatori_turni
            SET stato = 'online', inizio_turno = ?, login_time = ?, updated_at = NOW()
            WHERE operatore_cod = ?
        ");
        $upd->execute([$now, $now, $operatore_cod]);
    }

    // Log azione
    if (function_exists('logEvent')) {
        logEvent('turni', "LOGIN: Operatore $operatore_cod ($operatore_nome) accesso turno");
    }

    $response['success'] = true;
    $response['message'] = "✅ Turno aperto per $operatore_nome";
    $response['data'] = [
        'operatore_cod' => $operatore_cod,
        'operatore_nome' => $operatore_nome,
        'stato' => 'online'
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
