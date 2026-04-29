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
  if ($plate === '') throw new Exception('plate_number mancante');

  $db = getDatabaseConnection();

  // tessera attiva corrente
  $stmt = $db->prepare("SELECT * FROM tesserapre WHERE plate_number=? AND canc=0 ORDER BY id DESC LIMIT 1");
  $stmt->execute([$plate]);
  $old = $stmt->fetch(PDO::FETCH_ASSOC);
  if (!$old) throw new Exception('Nessuna tessera attiva da sostituire');

  $oldId = (int)$old['id'];
  $res1 = (float)($old['res1'] ?? 0);
  $nowSql = date('Y-m-d H:i:s');
  $nowHuman = date('d-m-Y H:i');

  $db->beginTransaction();

  // 1) annulla vecchia
  $db->prepare("
    UPDATE tesserapre
    SET canc=1, motivo='Nuova tessera', attivo=0
    WHERE id=?
    LIMIT 1
  ")->execute([$oldId]);

  // 2) crea nuova portando residuo + anagrafica
  $logtOld = (string)($old['logt'] ?? '');
  $logtOld .= "OldID={$oldId} annullata={$nowHuman}; ";

  $ins = $db->prepare("
    INSERT INTO tesserapre
    (plate_number, nome, indirizzo, citta, cap, prov, stato, pi, cf, codun, info,
     attivo, canc, motivo, fascias,
     res1, logt)
    VALUES
    (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?,
     1, 0, NULL, ?,
     ?, ?)
  ");

  $ins->execute([
    $plate,
    $old['nome'] ?? null,
    $old['indirizzo'] ?? null,
    $old['citta'] ?? null,
    $old['cap'] ?? null,
    $old['prov'] ?? null,
    $old['stato'] ?? null,
    $old['pi'] ?? null,
    $old['cf'] ?? null,
    $old['codun'] ?? null,
    $old['info'] ?? null,
    $old['fascias'] ?? null,
    $res1,
    $logtOld
  ]);

  $newId = (int)$db->lastInsertId();

  // opzionale: card_number = id
  $db->prepare("UPDATE tesserapre SET card_number=? WHERE id=? LIMIT 1")->execute([$newId, $newId]);

  $db->commit();

  $response['success'] = true;
  $response['message'] = '✅ Nuova tessera creata (la precedente è stata annullata)';
  $response['data'] = ['old_id'=>$oldId,'new_id'=>$newId,'res1'=>$res1,'created_at'=>$nowSql];

} catch (Throwable $e) {
  if (isset($db) && $db instanceof PDO && $db->inTransaction()) $db->rollBack();
  $response['success'] = false;
  $response['message'] = '❌ ' . $e->getMessage();
}
echo json_encode($response, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE);
exit;