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
  $b = $raw ? json_decode($raw, true) : [];

  $plate = trim((string)($b['plate_number'] ?? ''));
  if ($plate === '') throw new Exception('plate_number mancante');

  $db = getDatabaseConnection();

  $stmt = $db->prepare("SELECT id FROM abbonamenti WHERE plate_number=? ORDER BY id DESC LIMIT 1");
  $stmt->execute([$plate]);
  $row = $stmt->fetch(PDO::FETCH_ASSOC);

  $data = [
    'plate_number' => $plate,
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
    'tipo' => $b['tipo'] ?? null,
    'inabb' => $b['inabb'] ?? null,
    'finabb' => $b['finabb'] ?? null,
    'attivo' => (int)($b['attivo'] ?? 0),
    'prezzo' => $b['prezzo'] ?? null,
    'Apay' => (int)($b['Apay'] ?? 0),
    'SpayE' => (int)($b['SpayE'] ?? 0),
    'SpayC' => (int)($b['SpayC'] ?? 0),
    'Dpay' => $b['Dpay'] ?? null
  ];

  if ($row && !empty($row['id'])) {
    $id = (int)$row['id'];

    $upd = $db->prepare("
      UPDATE abbonamenti SET
        nome=:nome, indirizzo=:indirizzo, citta=:citta, cap=:cap, prov=:prov, stato=:stato,
        pi=:pi, cf=:cf, codun=:codun, info=:info,
        tipo=:tipo, inabb=:inabb, finabb=:finabb, attivo=:attivo,
        prezzo=:prezzo, Apay=:Apay, SpayE=:SpayE, SpayC=:SpayC, Dpay=:Dpay
      WHERE id=:id
      LIMIT 1
    ");

    // ✅ IMPORTANT: niente parametri extra (es. plate_number) nell'UPDATE
    $params = $data;
    unset($params['plate_number']);
    $params['id'] = $id;

    $upd->execute($params);

    $response['success'] = true;
    $response['message'] = '✅ Abbonamento aggiornato';
    $response['data'] = ['id'=>$id];
  } else {
    $ins = $db->prepare("
      INSERT INTO abbonamenti
      (plate_number,nome,indirizzo,citta,cap,prov,stato,pi,cf,codun,info,tipo,inabb,finabb,attivo,prezzo,Apay,SpayE,SpayC,Dpay)
      VALUES
      (:plate_number,:nome,:indirizzo,:citta,:cap,:prov,:stato,:pi,:cf,:codun,:info,:tipo,:inabb,:finabb,:attivo,:prezzo,:Apay,:SpayE,:SpayC,:Dpay)
    ");
    $ins->execute($data);

    $response['success'] = true;
    $response['message'] = '✅ Abbonamento creato';
    $response['data'] = ['id'=>(int)$db->lastInsertId()];
  }

} catch (Throwable $e) {
  $response['success'] = false;
  $response['message'] = '❌ ' . $e->getMessage();
}

echo json_encode($response, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE);
exit;