<?php

function escpos_get_printer_config(): array {
  $c = $GLOBALS['COSTANTI'] ?? [];
  $printerName  = trim((string)($c['PRINTER_NAME'] ?? ''));
  $printerShare = trim((string)($c['PRINTER_SHARE'] ?? ($c['USB_SHARE'] ?? '')));
  $barcode      = strtoupper(trim((string)($c['BARCODE'] ?? 'CODE128')));
  $barcodeFont  = trim((string)($c['BARCODE_FONT'] ?? 'Libre Barcode 128')); // legacy

  // Se qualcuno mette IP:PORT in PRINTER_SHARE, non è una share Windows
  if (preg_match('/^\d{1,3}(\.\d{1,3}){3}:\d+$/', $printerShare)) {
    $printerShare = '';
  }

  return [
    'printer_name'  => $printerName,
    'printer_share' => $printerShare,
    'barcode'       => $barcode,
    'barcode_font'  => $barcodeFont
  ];
}

function escpos_lock() {
  $lockFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ticket_print.lock';
  $fp = @fopen($lockFile, 'c');
  if (!$fp) return [null, 'Impossibile creare lock stampa'];
  if (!@flock($fp, LOCK_EX)) { @fclose($fp); return [null, 'Impossibile acquisire lock stampa']; }
  return [$fp, ''];
}
function escpos_unlock($lockFp): void {
  if ($lockFp) { @flock($lockFp, LOCK_UN); @fclose($lockFp); }
}

function escpos_debug_log(string $msg): void {
  $logDir = defined('LOGS_DIR') ? LOGS_DIR : __DIR__ . '/../logs';
  @file_put_contents($logDir . '/escpos_debug.log', '[' . date('Y-m-d H:i:s') . '] ' . $msg . "\n", FILE_APPEND);
}

/**
 * Estrae "BARCODE: xxx" o "BARCODE_SILENT: xxx" dal testo ticket/ricevuta.
 * Ritorna '' se non presente.
 */
function escpos_extract_barcode_from_txt(string $txt): string {
  if (preg_match('/^\s*BARCODE(?:_SILENT)?\s*:\s*(.+?)\s*$/mi', $txt, $m)) {
    return trim((string)$m[1]);
  }
  return '';
}

/**
 * Genera barcode Code128 vero come PNG usando la libreria Picqer.
 * IMPORTANT: usa l'autoloader del progetto in anpr/composer/autoload.php
 */
function win_generate_barcode_png_code128(string $barcodeValue, string $outPng): array {
  $barcodeValue = trim($barcodeValue);
  if ($barcodeValue === '') return ['success' => false, 'message' => 'Barcode vuoto'];

  $src = __DIR__ . '/../composer/php-barcode-generator-main/src/';
  escpos_debug_log('ENTER win_generate_barcode_png_code128 (ordered include version)');
  escpos_debug_log('barcode src=' . $src);

  try {
    $must = [
      // core
      $src . 'BarcodeBar.php',
      $src . 'Barcode.php',

      // base generator + png generator
      $src . 'BarcodeGenerator.php',
      $src . 'BarcodeGeneratorPNG.php',

      // exceptions (PngRenderer lancia BarcodeException)
      $src . 'Exceptions/BarcodeException.php',
      $src . 'Exceptions/UnknownTypeException.php',

      // types
      $src . 'Types/TypeInterface.php',
      $src . 'Types/TypeCode128.php',
      $src . 'Types/TypeCode128A.php',
      $src . 'Types/TypeCode128B.php',
      $src . 'Types/TypeCode128C.php',

      // renderers: PRIMA interface, POI implementazioni
      $src . 'Renderers/RendererInterface.php',
      $src . 'Renderers/PngRenderer.php',
    ];

    foreach ($must as $f) {
      if (!file_exists($f)) {
        return ['success' => false, 'message' => 'File libreria mancante: ' . $f];
      }
      require_once $f;
    }

    // ora deve esistere
    if (!class_exists('\Picqer\Barcode\BarcodeGeneratorPNG')) {
      return ['success' => false, 'message' => 'Classe Picqer\\Barcode\\BarcodeGeneratorPNG non trovata dopo include ordinato'];
    }

    $gen = new \Picqer\Barcode\BarcodeGeneratorPNG();

    // Nota: richiede GD o Imagick (tu hai GD perché PngRenderer lo usa se imagecreate esiste)
    $pngData = $gen->getBarcode($barcodeValue, $gen::TYPE_CODE_128, 4, 90);

    if (!is_string($pngData) || $pngData === '') {
      return ['success' => false, 'message' => 'Generazione barcode fallita (png vuoto)'];
    }

    if (@file_put_contents($outPng, $pngData) === false) {
      return ['success' => false, 'message' => 'Impossibile scrivere barcode png: ' . $outPng];
    }

    return ['success' => true, 'message' => 'Barcode PNG creato'];
  } catch (Throwable $e) {
    escpos_debug_log('barcode EXCEPTION: ' . $e->getMessage());
    return ['success' => false, 'message' => 'Errore generazione barcode: ' . $e->getMessage()];
  }
}

function win_print_png_to_printer(string $printer, string $pngPath): array {
  if ($printer === '') return ['success' => false, 'message' => 'Stampante non configurata (PRINTER_NAME/PRINTER_SHARE vuoti)'];
  if (!file_exists($pngPath)) return ['success' => false, 'message' => 'PNG non trovato: ' . $pngPath];

  $ps = <<<'PS'
param([string]$Printer,[string]$Png)
Add-Type -AssemblyName System.Drawing
$img=[System.Drawing.Image]::FromFile($Png)

$doc=New-Object System.Drawing.Printing.PrintDocument
$doc.PrinterSettings.PrinterName=$Printer
if(-not $doc.PrinterSettings.IsValid){ throw "Stampante non valida: $Printer" }

$doc.DefaultPageSettings.Margins = New-Object System.Drawing.Printing.Margins(0,0,0,0)

$doc.add_PrintPage({
  param($sender,$e)

  # IMPORTANT: no smoothing/anti-alias per barcode
  $e.Graphics.SmoothingMode=[System.Drawing.Drawing2D.SmoothingMode]::None
  $e.Graphics.InterpolationMode=[System.Drawing.Drawing2D.InterpolationMode]::NearestNeighbor
  $e.Graphics.PixelOffsetMode=[System.Drawing.Drawing2D.PixelOffsetMode]::None

  $e.Graphics.DrawImage($img,0,0,$img.Width,$img.Height)
  $e.HasMorePages=$false
})

$doc.Print()
$img.Dispose()
PS;

  $tmpPs = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'print_img_' . uniqid('', true) . '.ps1';
  file_put_contents($tmpPs, $ps);

  $cmd = 'powershell -NoProfile -ExecutionPolicy Bypass -File ' . escapeshellarg($tmpPs) .
         ' -Printer ' . escapeshellarg($printer) .
         ' -Png ' . escapeshellarg($pngPath);

  exec($cmd . ' 2>&1', $out, $code);
  @unlink($tmpPs);

  if ($code !== 0) return ['success' => false, 'message' => "Stampa PNG fallita ($code): " . implode("\n", $out)];
  return ['success' => true, 'message' => "Stampato su $printer"];
}

/**
 * Renderizza ticket PNG e inserisce il barcode come immagine, se disponibile.
 */
function win_render_ticket_png(string $txt, string $fallbackBarcodeValue, string $outPng, string $barcodeFont, string $barcodePngPath): array {
  $txt = str_replace("\r\n", "\n", $txt);
  $txt = str_replace("\r", "\n", $txt);

  $ps = <<<'PS'
param([string]$TxtPath,[string]$Barcode,[string]$OutPng,[string]$BarcodeFont,[string]$BarcodePng)
Add-Type -AssemblyName System.Drawing

$txt = Get-Content -Raw -Encoding UTF8 $TxtPath
$txt = $txt -replace "`r`n","`n"
$txt = $txt -replace "`r","`n"
$lines = $txt -split "`n"

$mono = New-Object System.Drawing.Font("Consolas", 10, [System.Drawing.FontStyle]::Regular)
$monoBold = New-Object System.Drawing.Font("Consolas", 12, [System.Drawing.FontStyle]::Bold)

# barcode image
$bcImg = $null
if (Test-Path $BarcodePng) {
  try { $bcImg = [System.Drawing.Image]::FromFile($BarcodePng) } catch { $bcImg = $null }
}

$w = 550
$lineH = 18
$topPad = 10
$y = $topPad
$h = $topPad + ($lines.Count * $lineH) + 260

$bmp = New-Object System.Drawing.Bitmap($w, $h)
$g = [System.Drawing.Graphics]::FromImage($bmp)
$g.Clear([System.Drawing.Color]::White)

$g.SmoothingMode = [System.Drawing.Drawing2D.SmoothingMode]::None
$g.TextRenderingHint = [System.Drawing.Text.TextRenderingHint]::SingleBitPerPixelGridFit
$brush = [System.Drawing.Brushes]::Black
$printedHeader = $false

foreach($line in $lines){
  $t = $line.Trim()

  if($t -like "BARCODE:*" -or $t -like "BARCODE_SILENT:*"){
    $y += 8
    $x = 20   # quiet zone
    $isSilent = $t -like "BARCODE_SILENT:*"

    # valore: preferisci quello presente nel TXT ("BARCODE: xxx" o "BARCODE_SILENT: xxx")
    $bcValue = ""
    try {
      if($isSilent){ $bcValue = $t.Substring(14).Trim() }
      else { $bcValue = $t.Substring(8).Trim() }
    } catch { $bcValue = "" }
    if($bcValue -eq "") { $bcValue = $Barcode }

    if($bcImg -ne $null){
      $g.InterpolationMode=[System.Drawing.Drawing2D.InterpolationMode]::NearestNeighbor
      $g.PixelOffsetMode=[System.Drawing.Drawing2D.PixelOffsetMode]::None
      $g.DrawImage($bcImg, $x, $y, $bcImg.Width, $bcImg.Height) | Out-Null
      $y += ($bcImg.Height + 6)

      # BARCODE_SILENT: non stampare il testo sotto il barcode
      if(-not $isSilent){
        $g.DrawString($bcValue, $mono, $brush, $x, $y) | Out-Null
        $y += 18
      }
    } else {
      # fallback testo: solo se NON silent
      if(-not $isSilent){
        $g.DrawString($bcValue, $monoBold, $brush, $x, $y) | Out-Null
        $y += 22
      }
    }

    $y += 10
    continue
  }

  if((-not $printedHeader) -and $t -ne "" -and ($t -notmatch "^(=+|-+)$")){
    $printedHeader = $true
    $g.DrawString($t, $monoBold, $brush, 0, $y) | Out-Null
    $y += 24
    continue
  }

  if($t -match "^=+$"){ $g.DrawString(("="*42), $mono, $brush, 0, $y) | Out-Null; $y += $lineH; continue }
  if($t -match "^-+$"){ $g.DrawString(("-"*42), $mono, $brush, 0, $y) | Out-Null; $y += $lineH; continue }

  $g.DrawString($line, $mono, $brush, 0, $y) | Out-Null
  $y += $lineH
}

$y += 40

$finalH = [Math]::Max($y, 200)
$final = New-Object System.Drawing.Bitmap($w, $finalH)
$g2 = [System.Drawing.Graphics]::FromImage($final)
$g2.Clear([System.Drawing.Color]::White)
$g2.DrawImage($bmp,0,0) | Out-Null

$final.Save($OutPng, [System.Drawing.Imaging.ImageFormat]::Png)

if($bcImg -ne $null){ $bcImg.Dispose() }
$g2.Dispose(); $final.Dispose(); $g.Dispose(); $bmp.Dispose()
PS;

  $tmpTxt = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ticket_' . uniqid('', true) . '.txt';
  $tmpPs  = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'render_ticket_' . uniqid('', true) . '.ps1';
  file_put_contents($tmpTxt, $txt);
  file_put_contents($tmpPs, $ps);

  $cmd = 'powershell -NoProfile -ExecutionPolicy Bypass -File ' . escapeshellarg($tmpPs) .
         ' -TxtPath ' . escapeshellarg($tmpTxt) .
         ' -Barcode ' . escapeshellarg($fallbackBarcodeValue) .
         ' -OutPng ' . escapeshellarg($outPng) .
         ' -BarcodeFont ' . escapeshellarg($barcodeFont) .
         ' -BarcodePng ' . escapeshellarg($barcodePngPath);

  exec($cmd . ' 2>&1', $out, $code);

  @unlink($tmpTxt);
  @unlink($tmpPs);

  if ($code !== 0) return ['success' => false, 'message' => "Render PNG fallito ($code): " . implode("\n", $out)];
  return ['success' => true, 'message' => 'PNG creato'];
}

function escpos_print_txt_with_barcode(string $txt, string $barcodeValue): array {
  $cfg = escpos_get_printer_config();
  $printer = $cfg['printer_name'] !== '' ? $cfg['printer_name'] : $cfg['printer_share'];
  if ($printer === '') return ['success' => false, 'message' => 'Config mancante: PRINTER_NAME o PRINTER_SHARE'];

  [$lockFp, $lockErr] = escpos_lock();
  if (!$lockFp) return ['success' => false, 'message' => $lockErr];

  $png = '';
  $barcodePng = '';

  try {
    // Se nel TXT c'è "BARCODE: xxx", usa quello (più affidabile)
    $txtBarcode = escpos_extract_barcode_from_txt($txt);
    $finalBarcodeValue = $txtBarcode !== '' ? $txtBarcode : trim((string)$barcodeValue);

    $png = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ticket_' . uniqid('', true) . '.png';
    $barcodePng = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'barcode_' . uniqid('', true) . '.png';

    escpos_debug_log('printer=' . $printer);
    escpos_debug_log('finalBarcodeValue=' . var_export($finalBarcodeValue, true));

    if ($finalBarcodeValue !== '') {
      $b = win_generate_barcode_png_code128($finalBarcodeValue, $barcodePng);
      escpos_debug_log('barcode gen: ' . json_encode($b, JSON_UNESCAPED_UNICODE));
      escpos_debug_log('barcode file exists=' . (file_exists($barcodePng) ? '1' : '0') . ' size=' . (file_exists($barcodePng) ? filesize($barcodePng) : 0));
      if (!$b['success']) {
        $barcodePng = ''; // fallback solo testo
      }
    } else {
      $barcodePng = '';
      escpos_debug_log('barcode skipped (empty)');
    }

    $r = win_render_ticket_png($txt, $finalBarcodeValue, $png, $cfg['barcode_font'], $barcodePng);
    escpos_debug_log('render: ' . json_encode($r, JSON_UNESCAPED_UNICODE));
    if (!$r['success']) { escpos_unlock($lockFp); return $r; }

    $p = win_print_png_to_printer($printer, $png);
    escpos_debug_log('print: ' . json_encode($p, JSON_UNESCAPED_UNICODE));

    if ($png !== '' && file_exists($png)) @unlink($png);
    if ($barcodePng !== '' && file_exists($barcodePng)) @unlink($barcodePng);

    escpos_unlock($lockFp);
    return $p;
  } catch (Throwable $e) {
    escpos_debug_log('EXCEPTION: ' . $e->getMessage());

    if ($png !== '' && file_exists($png)) @unlink($png);
    if ($barcodePng !== '' && file_exists($barcodePng)) @unlink($barcodePng);

    escpos_unlock($lockFp);
    return ['success' => false, 'message' => 'Errore stampa: ' . $e->getMessage()];
  }
}

function escpos_print_txt_with_barcode_from_file(string $filePath, string $barcodeValue): array {
  if (!file_exists($filePath)) return ['success' => false, 'message' => 'File non trovato: ' . $filePath];
  $txt = @file_get_contents($filePath);
  if ($txt === false) return ['success' => false, 'message' => 'Impossibile leggere file: ' . $filePath];
  return escpos_print_txt_with_barcode($txt, $barcodeValue);
}