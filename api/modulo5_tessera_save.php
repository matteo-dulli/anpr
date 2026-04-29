<?php
header('Content-Type: application/json; charset=utf-8');
date_default_timezone_set('Europe/Rome');
$response = ['success'=>false,'message'=>'','data'=>null];

try {
  $configPath = dirname(__DIR__) . '/config/config.php';
  if (!file_exists($configPath)) $configPath = __DIR__ . '/../config/config.php';
  if (!file_exists($configPath)) throw new Exception('config.php non trovato');
  require_once $configPath;

  $raw = file_get_contents('php://input');
  $b = $raw ? json_decode($raw, true) : null;
  if (!is_array($b)) throw new Exception('JSON non valido');

  $plate = trim((string)($b['plate_number'] ?? ''));
  if ($plate === '') throw new Exception('plate_number mancante');

  // ---- helper: stringa non vuota
  $nz = function($v) {
    $v = trim((string)($v ?? ''));
    return $v !== '';
  };

  // ---- campi "significativi" (se tutti vuoti => non creare)
  $hasData =
    // fascia / motivo / anagrafica
    $nz($b['fascias'] ?? '') ||
    $nz($b['motivo'] ?? '') ||
    $nz($b['nome'] ?? '') ||
    $nz($b['indirizzo'] ?? '') ||
    $nz($b['citta'] ?? '') ||
    $nz($b['cap'] ?? '') ||
    $nz($b['prov'] ?? '') ||
    $nz($b['stato'] ?? '') ||
    $nz($b['pi'] ?? '') ||
    $nz($b['cf'] ?? '') ||
    $nz($b['codun'] ?? '') ||
    $nz($b['info'] ?? '') ||
    // flag (se uno è 1 considero dato)
    ((int)($b['attivo'] ?? 0) === 1) ||
    ((int)($b['Apay'] ?? 0) === 1) ||
    ((int)($b['SpayC'] ?? 0) === 1) ||
    ((int)($b['SpayE'] ?? 0) === 1) ||
    ((int)($b['canc'] ?? 0) === 1);

  $db = getDatabaseConnection();

  // tessera attiva esistente?
  $stmt = $db->prepare("SELECT id FROM tesserapre WHERE plate_number=? AND canc=0 ORDER BY id DESC LIMIT 1");
  $stmt->execute([$plate]);
  $row = $stmt->fetch(PDO::FETCH_ASSOC);

  // ✅ se NON esiste tessera e NON ho dati, NON fare INSERT
  if (!$row && !$hasData) {
    $response['success'] = true;
    $response['message'] = 'ℹ️ Tessera a scalare: nessun dato da salvare (non creata)';
    $response['data'] = ['skipped' => true];
    echo json_encode($response, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE);
    exit;
  }

  // payload
  $payload = [
    'attivo' => (int)($b['attivo'] ?? 0),
    'Apay' => (int)($b['Apay'] ?? 0),
    'SpayC' => (int)($b['SpayC'] ?? 0),
    'SpayE' => (int)($b['SpayE'] ?? 0),
    'canc' => (int)($b['canc'] ?? 0),
    'motivo' => $b['motivo'] ?? null,
    'fascias' => $b['fascias'] ?? null,

    'nome' => $b['nome'] ?? null,
    'indirizzo' => $b['indirizzo'] ?? null,
    'citta' => $b['citta'] ?? null,
    'cap' => $b['cap'] ?? null,
    'prov' => $b['prov'] ?? null,
    'stato' => $b['stato'] ?? null,
    'pi' => $b['pi'] ?? null,
    'cf' => $b['cf'] ?? null,
    'codun' => $b['codun'] ?? null,
    'info' => $b['info'] ?? null,
  ];

  if (!$row) {
    // ✅ crea tessera solo perché abbiamo dati
    $ins = $db->prepare("
      INSERT INTO tesserapre
      (plate_number, attivo, res1, Apay, SpayC, SpayE, canc, motivo, fascias,
       nome, indirizzo, citta, cap, prov, stato, pi, cf, codun, info)
      VALUES
      (:plate_number, :attivo, 0.00, :Apay, :SpayC, :SpayE, :canc, :motivo, :fascias,
       :nome, :indirizzo, :citta, :cap, :prov, :stato, :pi, :cf, :codun, :info)
    ");
    $ins->execute(array_merge($payload, ['plate_number' => $plate]));
    $id = (int)$db->lastInsertId();

    // opzionale: card_number = id
    $db->prepare("UPDATE tesserapre SET card_number=? WHERE id=? LIMIT 1")->execute([$id, $id]);

    $response['success'] = true;
    $response['message'] = '✅ Tessera creata e salvata';
    $response['data'] = ['id'=>$id];
    echo json_encode($response, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE);
    exit;
  }

  $id = (int)$row['id'];

  $upd = $db->prepare("
    UPDATE tesserapre SET
      attivo=:attivo,
      Apay=:Apay, SpayC=:SpayC, SpayE=:SpayE,
      canc=:canc, motivo=:motivo,
      fascias=:fascias,
      nome=:nome, indirizzo=:indirizzo, citta=:citta, cap=:cap, prov=:prov, stato=:stato,
      pi=:pi, cf=:cf, codun=:codun, info=:info
    WHERE id=:id
    LIMIT 1
  ");
  $upd->execute(array_merge($payload, ['id'=>$id]));

  $response['success'] = true;
  $response['message'] = '✅ Tessera salvata';
  $response['data'] = ['id'=>$id];

} catch (Throwable $e) {
  $response['success'] = false;
  $response['message'] = '❌ ' . $e->getMessage();
}

echo json_encode($response, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE);
exit;