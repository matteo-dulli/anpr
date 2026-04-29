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
  if (!is_array($b)) throw new Exception('JSON non valido');

  $plate = trim((string)($b['plate_number'] ?? ''));
  $importo = (float)($b['importo'] ?? 0);

  if ($plate === '') throw new Exception('plate_number mancante');
  if ($importo <= 0) throw new Exception('importo non valido');

  $SpayC = (int)($b['SpayC'] ?? 0);
  $SpayE = (int)($b['SpayE'] ?? 0);

  // ✅ mutua esclusione (se entrambi 1, preferisco contante)
  if ($SpayC === 1 && $SpayE === 1) $SpayE = 0;

  $dpaySql = date('Y-m-d H:i:s');   // per DB
  $dpayHuman = date('d-m-Y H:i');   // per logpay

  $db = getDatabaseConnection();

  // prendo tessera attiva o la creo
  $stmt = $db->prepare("SELECT * FROM tesserapre WHERE plate_number=? AND canc=0 ORDER BY id DESC LIMIT 1");
  $stmt->execute([$plate]);
  $t = $stmt->fetch(PDO::FETCH_ASSOC);

  if (!$t) {
    $db->prepare("INSERT INTO tesserapre (plate_number, attivo, res1, logpay) VALUES (?, 1, 0.00, '')")
       ->execute([$plate]);
    $id = (int)$db->lastInsertId();

    // opzionale: card_number = id
    $db->prepare("UPDATE tesserapre SET card_number=? WHERE id=? LIMIT 1")->execute([$id, $id]);

    $t = ['id'=>$id, 'res1'=>0.00, 'logpay'=>''];
  }

  $id = (int)$t['id'];
  $res1 = (float)($t['res1'] ?? 0);
  $newRes = $res1 + $importo;

  $logpay = (string)($t['logpay'] ?? '');
  $logpay .= $importo . ' - ' . $dpayHuman . ' - ';

  $upd = $db->prepare("
    UPDATE tesserapre
    SET
      res1=?,
      -- ✅ prezzo è SOLO input: dopo conferma lo azzeriamo
      prezzo=NULL,
      Apay=1,
      Dpay=?,
      SpayC=?,
      SpayE=?,
      logpay=?
    WHERE id=?
    LIMIT 1
  ");
  $upd->execute([$newRes, $dpaySql, $SpayC, $SpayE, $logpay, $id]);

  $response['success'] = true;
  $response['message'] = "✅ Ricarica effettuata: €{$importo} - {$dpayHuman}";
  $response['data'] = ['id'=>$id,'res1'=>$newRes,'Dpay'=>$dpaySql];

} catch (Throwable $e) {
  $response['success'] = false;
  $response['message'] = '❌ ' . $e->getMessage();
}
echo json_encode($response, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE);
exit;