<?php
// ✅ API per leggere operatori da costanti.txt
header('Content-Type: application/json; charset=utf-8');
date_default_timezone_set('Europe/Rome');

$response = ['success' => false, 'message' => '', 'data' => []];

try {
    $costanti_file = __DIR__ . '/../costanti.txt';
    
    if (!file_exists($costanti_file)) {
        throw new Exception('File costanti.txt non trovato');
    }

    $operatori = [];
    $in_operatori_section = false;
    $lines = file($costanti_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

    foreach ($lines as $line) {
        $line = trim($line);
        
        // Inizio sezione operatori
        if (strpos($line, '# OPERATORI') === 0) {
            $in_operatori_section = true;
            continue;
        }
        
        // Fine sezione (nuova sezione con #)
        if ($in_operatori_section && strpos($line, '#') === 0 && strpos($line, '# OPERATORI') !== 0) {
            break;
        }

        // Leggi operatori: "01 = Gianni", "02 = Rossi", ecc
        if ($in_operatori_section && strpos($line, '=') !== false && strpos($line, '#') !== 0) {
            $parts = explode('=', $line, 2);
            if (count($parts) === 2) {
                $cod = trim($parts[0]);
                $nome = trim($parts[1]);
                $operatori[] = [
                    'cod' => $cod,
                    'nome' => $nome,
                    'label' => "$cod - $nome"
                ];
            }
        }
    }

    if (empty($operatori)) {
        throw new Exception('Nessun operatore trovato in costanti.txt');
    }

    $response['success'] = true;
    $response['data'] = $operatori;

} catch (Exception $e) {
    $response['message'] = '❌ ' . $e->getMessage();
}

http_response_code($response['success'] ? 200 : 400);
echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
?>
