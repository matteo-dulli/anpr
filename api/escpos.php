<?php

/**
 * ESC/POS printing module — supports TCP and Windows RAW.
 *
 * TCP path   : set IP (and optionally PORT) in costanti.txt
 * Windows path: set PRINTER_NAME or PRINTER_SHARE in costanti.txt
 *
 * Barcode: native ESC/POS CODE128 (GS k 73) rendered by printer hardware.
 */

function escpos_get_printer_config(): array {
  $c = $GLOBALS['COSTANTI'] ?? [];

  $ip      = trim((string)($c['IP'] ?? ''));
  $portRaw = $c['PORT'] ?? $c['Port'] ?? 9100;
  $port    = is_numeric($portRaw) ? (int)$portRaw : 9100;

  $printerName  = trim((string)($c['PRINTER_NAME'] ?? ''));
  $printerShare = trim((string)($c['PRINTER_SHARE'] ?? ($c['USB_SHARE'] ?? '')));

  // TCP when IP is a valid IP address
  if ($ip !== '' && filter_var($ip, FILTER_VALIDATE_IP)) {
    return ['method' => 'tcp', 'ip' => $ip, 'port' => $port];
  }

  // Windows RAW when a printer name/share is configured
  $printer = $printerName !== '' ? $printerName : $printerShare;
  if ($printer !== '') {
    return ['method' => 'windows', 'printer' => $printer];
  }

  return ['method' => 'none'];
}

function escpos_lock(): array {
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
  @file_put_contents(__DIR__ . '/escpos_debug.log', '[' . date('Y-m-d H:i:s') . '] ' . $msg . "\n", FILE_APPEND);
}

/**
 * ✅ Helper: rimuove righe decorative (====/----/____ e unicode ─/═) prima della stampa.
 */
function escpos_strip_decorative_lines(string $txt): string {
  $txt = str_replace("\r\n", "\n", $txt);
  $txt = str_replace("\r", "\n", $txt);

  // =====  -----  _____
  $txt = preg_replace('/^[=\-_]+$/m', '', $txt);

  // unicode line drawing: ─ ━ ═ (e mix simili)
  $txt = preg_replace('/^[─━═\-_=]+$/mu', '', $txt);

  // compatta righe vuote multiple
  $txt = preg_replace("/\n{3,}/", "\n\n", $txt);

  return $txt;
}

/**
 * Costruisce il buffer ESC/POS raw (testo + barcode nativo CODE128).
 */
function escpos_build_raw(string $txt, string $barcodeValue, bool $showHri = false): string {
  $txt = str_replace("\r\n", "\n", $txt);
  $txt = str_replace("\r", "\n", $txt);

  // ✅ NEW: rimuovi righe decorative prima di tutto
  $txt = escpos_strip_decorative_lines($txt);

  // ✅ barcode ONLY from input/DB (never from TXT)
  $finalBarcode = trim((string)$barcodeValue);

  $out = '';
  $out .= "\x1B\x40";                 // ESC @ init
  $out .= "\x1B\x74" . chr(16);       // ✅ WPC1252 (N=16 sulla tua stampante, € OK)

  // ✅ Converti UTF-8 -> Windows-1252 (così € diventa 0x80)
  // NOTA: //IGNORE è più stabile di //TRANSLIT per l'€
  $txt1252 = @iconv('UTF-8', 'Windows-1252//IGNORE', $txt);
  if ($txt1252 !== false && $txt1252 !== null && $txt1252 !== '') {
    $txt = $txt1252;
  } else {
    // fallback: se iconv fallisce, evita euro in UTF-8 (3 byte)
    $txt = str_replace('€', 'EUR', $txt);
  }

  $lines         = preg_split("/\n/", $txt);
  $printedHeader = false;

  // ✅ se troviamo BARCODE: nel template, stampiamo lì
  $barcodePrinted = false;

  // helper: stampa barcode con le impostazioni richieste
  $emitBarcode = function () use (&$out, $finalBarcode, $showHri) {
    if ($finalBarcode === '') return;

    $out .= "\x0A";
    $out .= "\x1B\x61\x01"; // center

    // HRI on/off (ticket=false => NON stampa testo umano)
    $out .= $showHri ? "\x1D\x48\x02" : "\x1D\x48\x00";
    $out .= "\x1D\x66\x00"; // HRI font A

    // dimensione barcode
    $out .= "\x1D\x77" . chr(2);    // module width 2
    $out .= "\x1D\x68" . chr(120);  // height 120

    // CODE128: payload diretto + comando CON LUNGHEZZA
    $payload = $finalBarcode;
    if (strlen($payload) > 255) $payload = substr($payload, 0, 255);

    $out .= "\x1D\x6B\x49" . chr(strlen($payload)) . $payload;

    $out .= "\x0A\x0A";
    $out .= "\x1B\x61\x00"; // left
  };

  foreach ($lines as $line) {
    $trim = trim($line);

    // ✅ NEW: skip righe decorative (doppia sicurezza)
    if ($trim !== '' && preg_match('/^[=\-_]+$/', $trim)) continue;
    if ($trim !== '' && preg_match('/^[─━═\-_=]+$/u', $trim)) continue;

    // placeholder BARCODE:
    if (stripos($trim, 'BARCODE:') === 0) {
      $barcodePrinted = true;
      $emitBarcode();
      continue;
    }

    // header (non trattare separatori come header)
    if (!$printedHeader && $trim !== '' && !preg_match('/^[=\-]+$/', $trim)) {
      $printedHeader = true;
      $out .= "\x1B\x61\x01";
      $out .= "\x1B\x45\x01";
      $out .= "\x1D\x21\x11";
      $out .= $trim . "\x0A";
      $out .= "\x1D\x21\x00";
      $out .= "\x1B\x45\x00";
      $out .= "\x1B\x61\x00";
      continue;
    }

    // ------------------------------------------------------------
    // OLD: ristampava separatori a lunghezza fissa
    // Richiesta: TOGLI TUTTE LE RIGHE --------/______/=====
    // ------------------------------------------------------------
    /*
    if (preg_match('/^=+$/', $trim)) { $out .= str_repeat('=', 42) . "\x0A"; continue; }
    if (preg_match('/^-+$/', $trim)) { $out .= str_repeat('-', 42) . "\x0A"; continue; }
    */

    $out .= $line . "\x0A";
  }

  // ✅ FALLBACK: se manca BARCODE: nel template, stampalo in fondo
  if (!$barcodePrinted) {
    $emitBarcode();
  }

  // ✅ FIX TAGLIO: feed più lungo prima del cut, altrimenti “taglia troppo presto”
  $out .= "\x0A\x0A\x0A\x0A\x0A";     // 5 righe
  $out .= "\x1B\x64" . chr(5);        // ESC d n => feed 5 righe (molto compatibile)

  // ✅ Cut (full cut) più compatibile di GS V 1 su alcune stampanti
  $out .= "\x1D\x56\x00";

  return $out;
}

/**
 * Stampa via socket TCP diretto (porta 9100 tipica).
 */
function escpos_print_tcp(string $ip, int $port, string $rawBytes): array {
  $fp = @fsockopen($ip, $port, $errno, $errstr, 3.0);
  if (!$fp) {
    return ['success' => false, 'message' => "Connessione TCP fallita {$ip}:{$port} ({$errno}) {$errstr}"];
  }
  stream_set_timeout($fp, 3);

  $total   = strlen($rawBytes);
  $written = 0;
  while ($written < $total) {
    $w = @fwrite($fp, substr($rawBytes, $written));
    if ($w === false || $w === 0) break;
    $written += $w;
  }
  @fflush($fp);
  @fclose($fp);

  if ($written < $total) {
    return ['success' => false, 'message' => "TCP: trasmissione incompleta ({$written}/{$total} byte)"];
  }
  return ['success' => true, 'message' => "Stampato su {$ip}:{$port}"];
}

/**
 * Stampa via Windows RAW printer (PowerShell + WritePrinter API).
 */
function escpos_print_windows(string $printerName, string $rawBytes): array {
  $tmpData = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'escpos_' . uniqid('', true) . '.bin';
  $tmpPs   = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'escpos_ps_' . uniqid('', true) . '.ps1';

  if (@file_put_contents($tmpData, $rawBytes) === false) {
    return ['success' => false, 'message' => 'Impossibile scrivere dati ESC/POS su file temporaneo'];
  }

  $ps = <<<'PS'
param([string]$Printer, [string]$DataFile)

$bytes = [System.IO.File]::ReadAllBytes($DataFile)

$src = @"
using System;
using System.Runtime.InteropServices;

public class EscposRawPrint {
    [StructLayout(LayoutKind.Sequential, CharSet = CharSet.Ansi)]
    public class DOCINFOA {
        [MarshalAs(UnmanagedType.LPStr)] public string pDocName;
        [MarshalAs(UnmanagedType.LPStr)] public string pOutputFile;
        [MarshalAs(UnmanagedType.LPStr)] public string pDatatype;
    }

    [DllImport("winspool.drv", CharSet = CharSet.Ansi, SetLastError = true)]
    public static extern bool OpenPrinter(string pPrinterName, out IntPtr phPrinter, IntPtr pDefault);

    [DllImport("winspool.drv", SetLastError = true)]
    public static extern bool ClosePrinter(IntPtr hPrinter);

    [DllImport("winspool.drv", CharSet = CharSet.Ansi, SetLastError = true)]
    public static extern int StartDocPrinter(IntPtr hPrinter, int Level, DOCINFOA pDocInfo);

    [DllImport("winspool.drv", SetLastError = true)]
    public static extern bool StartPagePrinter(IntPtr hPrinter);

    [DllImport("winspool.drv", SetLastError = true)]
    public static extern bool WritePrinter(IntPtr hPrinter, IntPtr pBuf, int cbBuf, out int pcWritten);

    [DllImport("winspool.drv", SetLastError = true)]
    public static extern bool EndPagePrinter(IntPtr hPrinter);

    [DllImport("winspool.drv", SetLastError = true)]
    public static extern bool EndDocPrinter(IntPtr hPrinter);

    public static bool Send(string printer, byte[] data) {
        IntPtr hPrinter;
        if (!OpenPrinter(printer, out hPrinter, IntPtr.Zero)) return false;
        try {
            var di = new DOCINFOA { pDocName = "ESC/POS", pOutputFile = null, pDatatype = "RAW" };
            if (StartDocPrinter(hPrinter, 1, di) == 0) return false;
            try {
                StartPagePrinter(hPrinter);
                IntPtr ptr = Marshal.AllocHGlobal(data.Length);
                try {
                    Marshal.Copy(data, 0, ptr, data.Length);
                    int written;
                    return WritePrinter(hPrinter, ptr, data.Length, out written);
                } finally {
                    Marshal.FreeHGlobal(ptr);
                }
            } finally {
                EndPagePrinter(hPrinter);
                EndDocPrinter(hPrinter);
            }
        } finally {
            ClosePrinter(hPrinter);
        }
    }
}
"@

if (-not ([System.Management.Automation.PSTypeName]'EscposRawPrint').Type) {
    Add-Type -TypeDefinition $src -Language CSharp
}

$ok = [EscposRawPrint]::Send($Printer, $bytes)
if (-not $ok) {
    $err = [System.Runtime.InteropServices.Marshal]::GetLastWin32Error()
    throw "WritePrinter fallito (err=$err) su stampante: $Printer"
}
PS;

  if (@file_put_contents($tmpPs, $ps) === false) {
    @unlink($tmpData);
    return ['success' => false, 'message' => 'Impossibile scrivere script PowerShell temporaneo'];
  }

  $cmd = 'powershell -NoProfile -ExecutionPolicy Bypass -File ' . escapeshellarg($tmpPs) .
         ' -Printer '  . escapeshellarg($printerName) .
         ' -DataFile ' . escapeshellarg($tmpData);

  exec($cmd . ' 2>&1', $out, $code);

  @unlink($tmpData);
  @unlink($tmpPs);

  if ($code !== 0) {
    return ['success' => false, 'message' => "Stampa Windows fallita (exit={$code}): " . implode('; ', $out)];
  }
  return ['success' => true, 'message' => "Stampato su {$printerName}"];
}

/**
 * Funzione principale: sceglie TCP o Windows RAW.
 */
function escpos_print_txt_with_barcode(string $txt, string $barcodeValue, bool $showHri = false): array {
  $cfg = escpos_get_printer_config();
  escpos_debug_log('method=' . ($cfg['method'] ?? 'none') . ' barcode=' . var_export($barcodeValue, true) . ' hri=' . ($showHri ? '1' : '0'));

  if (($cfg['method'] ?? 'none') === 'none') {
    return ['success' => false, 'message' => 'Stampante non configurata (impostare IP oppure PRINTER_NAME/PRINTER_SHARE in costanti.txt)'];
  }

  [$lockFp, $lockErr] = escpos_lock();
  if (!$lockFp) return ['success' => false, 'message' => $lockErr];

  try {
    $rawBytes = escpos_build_raw($txt, $barcodeValue, $showHri);

    // debug: salva ultimo raw
    @file_put_contents(__DIR__ . '/_last_escpos.bin', $rawBytes);

    if ($cfg['method'] === 'tcp') {
      $result = escpos_print_tcp($cfg['ip'], $cfg['port'], $rawBytes);
    } else {
      $result = escpos_print_windows($cfg['printer'], $rawBytes);
    }

    escpos_debug_log('result=' . json_encode($result));
    escpos_unlock($lockFp);
    return $result;
  } catch (Throwable $e) {
    escpos_debug_log('EXCEPTION: ' . $e->getMessage());
    escpos_unlock($lockFp);
    return ['success' => false, 'message' => 'Errore stampa: ' . $e->getMessage()];
  }
}

function escpos_print_txt_with_barcode_from_file(string $filePath, string $barcodeValue, bool $showHri = false): array {
  if (!file_exists($filePath)) return ['success' => false, 'message' => 'File non trovato: ' . $filePath];
  $txt = @file_get_contents($filePath);
  if ($txt === false) return ['success' => false, 'message' => 'Impossibile leggere file: ' . $filePath];
  return escpos_print_txt_with_barcode($txt, $barcodeValue, $showHri);
}