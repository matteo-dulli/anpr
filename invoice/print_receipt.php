<?php
$code = isset($_GET['code']) ? trim((string)$_GET['code']) : '';
if ($code === '') {
  http_response_code(400);
  header('Content-Type: text/plain; charset=utf-8');
  echo 'Missing receipt code';
  exit;
}
?>
<!doctype html>
<html lang="it">
<head>
  <meta charset="utf-8" />
  <title>Print Receipt</title>
  <style>
    @page { margin: 4mm; }
    body { margin: 0; font-family: Arial, sans-serif; font-size: 12px; }
    .wrap { width: 72mm; padding: 2mm; }
    .barcode { text-align: center; margin-top: 6mm; }
    .barcode img { width: 68mm; height: auto; image-rendering: crisp-edges; }
  </style>
</head>
<body>
  <div class="wrap">
    <!-- QUI puoi replicare il corpo ricevuta come già lo generi -->
    <div class="barcode">
      <img src="/anpr/api/barcode.php?text=<?= urlencode($code) ?>&h=80&scale=2" alt="barcode" />
    </div>
  </div>
</body>
</html>