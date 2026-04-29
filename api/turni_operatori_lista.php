<?php
/**
 * turni_operatori_lista.php
 * Ritorna la lista degli operatori da costanti.txt + tabella operatori_turni
 * Response: { success, data: [ { cod, nome, stato } ] }
 */
header('Content-Type: application/json; charset=utf-8');
date_default_timezone_set('Europe/Rome');
require_once __DIR__ . '/../config/config.php';

$response = ['success' => false, 'message' => '', 'data' => []];

try {
    // --- Leggi costanti.txt per ottenere cod → nome ---
    $operatori = [];
    if (file_exists(COSTANTI_FILE)) {
        $lines = file(COSTANTI_FILE, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $inSezioneOperatori = false;

        foreach ($lines as $line) {
            $line = trim($line);
            // Ignora commenti
            if (strpos($line, '#') === 0) {
                // Controlla se siamo in sezione OPERATORI
                if (stripos($line, 'OPERATORI') !== false) {
                    $inSezioneOperatori = true;
                }
                continue;
            }
            if (!$inSezioneOperatori) continue;

            // Riga tipo: 01 = Gianni
            if (strpos($line, '=') !== false) {
                [$key, $val] = explode('=', $line, 2);
                $key = trim($key);
                $val = trim($val);
                // Codice operatore: numerico o alfanumerico fino a 10 char
                if (preg_match('/^\d{1,10}$/', $key) || preg_match('/^[A-Za-z0-9_]{1,10}$/', $key)) {
                    $operatori[$key] = $val;
                }
            }
        }
    }

    // --- Se non c'è nulla in costanti.txt, leggi dalla tabella DB ---
    if (empty($operatori)) {
        $db   = getDatabaseConnection();
        $stmt = $db->query("SELECT operatore_cod, nome FROM operatori_turni ORDER BY operatore_cod");
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $cod  = $row['operatore_cod'];
            $nome = isset($row['nome']) ? $row['nome'] : $cod;
            $operatori[$cod] = $nome;
        }
    }

    // --- Leggi stati correnti dal DB ---
    $stati = [];
    try {
        $db   = getDatabaseConnection();
        $stmt = $db->query("SELECT operatore_cod, stato FROM operatori_turni");
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $stati[$row['operatore_cod']] = $row['stato'];
        }
    } catch (Exception $e) {
        // DB potrebbe non avere la tabella ancora, ignora
    }

    // --- Componi risposta ---
    $data = [];
    foreach ($operatori as $cod => $nome) {
        $data[] = [
            'cod'   => $cod,
            'nome'  => $nome,
            'stato' => isset($stati[$cod]) ? $stati[$cod] : 'offline'
        ];
    }

    $response['success'] = true;
    $response['data']    = $data;

} catch (Exception $e) {
    $response['message'] = '❌ ' . $e->getMessage();
}

echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
?>
