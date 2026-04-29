<?php
header('Content-Type: application/json; charset=utf-8');
date_default_timezone_set('Europe/Rome');
require_once __DIR__ . '/../config/config.php';

$response = ['success' => false, 'message' => '', 'data' => null];

try {
    $path = __DIR__ . '/../costanti.txt'; // <-- se il path è diverso dimmelo
    if (!file_exists($path)) {
        throw new Exception("costanti.txt non trovato: $path");
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

    $maxDays = 1;

    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) continue;

        // Supporto:
        // - "LIMITE_GIORNI_PRESENZA = 6"
        // - "Giorni 6 = 6"
        if (preg_match('/^LIMITE_GIORNI_PRESENZA\s*=\s*(\d+)/i', $line, $m)) {
            $maxDays = max($maxDays, (int)$m[1]);
        } elseif (preg_match('/^Giorni\s+(\d+)\s*=\s*(\d+)/i', $line, $m)) {
            $maxDays = max($maxDays, (int)$m[2], (int)$m[1]);
        } elseif (preg_match('/^Giorno\s+(\d+)\s*=\s*(\d+)/i', $line, $m)) {
            $maxDays = max($maxDays, (int)$m[2], (int)$m[1]);
        }
    }

    // Costruisci opzioni standard (label generate automaticamente)
    $options = [];
    for ($d = 1; $d <= $maxDays; $d++) {
        if ($d === 1) $label = 'Oggi';
        elseif ($d === 2) $label = 'Oggi + Ieri';
        elseif ($d === 3) $label = 'Oggi + Ieri + Altroieri';
        else $label = "Ultimi {$d} giorni";
        $options[] = ['value' => $d, 'label' => $label];
    }

    $response['success'] = true;
    $response['data'] = [
        'max_days' => $maxDays,
        'options' => $options
    ];
} catch (Throwable $e) {
    http_response_code(500);
    $response['success'] = false;
    $response['message'] = '❌ ' . $e->getMessage();
}

echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);