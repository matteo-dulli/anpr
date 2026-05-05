<?php
header('Content-Type: application/json; charset=utf-8');
date_default_timezone_set('Europe/Rome');
require_once __DIR__ . '/../config/config.php';

$db = getDatabaseConnection();
$response = ['success' => false, 'message' => '', 'data' => null];

try {
    $body = json_decode(file_get_contents('php://input'), true) ?? [];
    $operatore_cod = isset($body['operatore_cod']) ? trim($body['operatore_cod']) : '';

    if ($operatore_cod === '') {
        throw new Exception('operatore_cod obbligatorio');
    }

    // Legge operatore da COSTANTI_FILE (# OPERATORI)
    if (!file_exists(COSTANTI_FILE)) {
        throw new Exception('File costanti.txt non trovato');
    }

    $operatore_trovato = false;
    $operatore_nome = '';
    $in_operatori_section = false;
    $lines = file(COSTANTI_FILE, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

    foreach ($lines as $line) {
        $line = trim($line);

        if (strpos($line, '# OPERATORI') === 0) {
            $in_operatori_section = true;
            continue;
        }
        if ($in_operatori_section && strpos($line, '#') === 0 && strpos($line, '# OPERATORI') !== 0) {
            break;
        }

        if ($in_operatori_section && strpos($line, '=') !== false && strpos($line, '#') !== 0) {
            [$cod, $nome] = array_map('trim', explode('=', $line, 2));
            if ($cod === $operatore_cod) {
                $operatore_trovato = true;
                $operatore_nome = $nome;
                break;
            }
        }
    }

    if (!$operatore_trovato) {
        throw new Exception("Operatore $operatore_cod non trovato in costanti.txt");
    }

    $now = date('Y-m-d H:i:s');

    $db->beginTransaction();

    // (opzionale) chiude eventuali turni_sessioni online rimasti aperti
    $db->exec("UPDATE turni_sessioni SET stato='offline', fine=IFNULL(fine, NOW()) WHERE stato='online'");

    // Aggiorna/crea record operatori_turni (legacy)
    $chk = $db->prepare("SELECT id FROM operatori_turni WHERE operatore_cod = ? LIMIT 1");
    $chk->execute([$operatore_cod]);
    $existing = $chk->fetch(PDO::FETCH_ASSOC);

    if (!$existing) {
        $ins = $db->prepare("
            INSERT INTO operatori_turni (operatore_cod, stato, inizio_turno, login_time)
            VALUES (?, 'online', ?, ?)
        ");
        $ins->execute([$operatore_cod, $now, $now]);
    } else {
        $upd = $db->prepare("
            UPDATE operatori_turni
            SET stato='online', inizio_turno=?, login_time=?, updated_at=NOW()
            WHERE operatore_cod=?
        ");
        $upd->execute([$now, $now, $operatore_cod]);
    }

    // Crea turno sessione
    $stmtNum = $db->query("SELECT COALESCE(MAX(numero_turno), 0) + 1 AS next_num FROM turni_sessioni");
    $nextNum = (int)($stmtNum->fetch(PDO::FETCH_ASSOC)['next_num'] ?? 1);
    if ($nextNum <= 0) $nextNum = 1;

    $insSess = $db->prepare("
        INSERT INTO turni_sessioni (numero_turno, operatore_cod, stato, inizio)
        VALUES (?, ?, 'online', ?)
    ");
    $insSess->execute([$nextNum, $operatore_cod, $now]);

    $id_sessione = (int)$db->lastInsertId();

    $db->commit();

    $response['success'] = true;
    $response['message'] = "✅ Turno aperto per $operatore_nome";
    $response['data'] = [
        'operatore_cod'  => $operatore_cod,
        'operatore_nome' => $operatore_nome,
        'stato'          => 'online',
        'id_sessione'    => $id_sessione,
        'numero_turno'   => $nextNum,
        'inizio'         => $now
    ];

} catch (Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    $response['message'] = '❌ ' . $e->getMessage();
}

http_response_code($response['success'] ? 200 : 400);
echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
?>