<?php
function suffix(string $code): string {
  $code = trim($code);
  if ($code === '') return '';
  $pos = strrpos($code, '-');
  return ($pos !== false) ? substr($code, $pos + 1) : $code;
}

$code = isset($_GET['code']) ? trim((string)$_GET['code']) : '';
$mode = isset($_GET['mode']) ? trim((string)$_GET['mode']) : '';

if ($code === '') {
  http_response_code(400);
  header('Content-Type: text/plain; charset=utf-8');
  echo 'Missing code';
  exit;
}

// in ristampa: usa sempre il secondario (suffix)
$barcodeValue = ($mode === 'reprint') ? suffix($code) : $code;
?>
<!doctype html>
<html lang="it">
<head>
  <meta charset="utf-8" />
  <title>Print Ticket</title>
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
    <!-- QUI puoi replicare il corpo ticket come già lo generi (azienda, targa, ingresso, ecc.) -->
    <!-- NON stampiamo testo sotto al barcode -->
    <div class="barcode">
      <img src="/anpr/api/barcode.php?text=<?= urlencode($barcodeValue) ?>&h=80&scale=2" alt="barcode" />
    </div>
  </div>

  <script>
    // per iframe hidden-print: la print() verrà chiamata dal parent
  </script>
</body>
</html>