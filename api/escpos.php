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
 * Estrae "BARCODE: xxx" dal testo. Ritorna '' se non presente.
 */
function escpos_extract_barcode_from_txt(string $txt): string {
  if (preg_match('/^\s*BARCODE\s*:\s*(.+?)\s*$/mi', $txt, $m)) {
    return trim((string)$m[1]);
  }
  return '';
}

/**
 * Costruisce il buffer ESC/POS raw (testo + barcode nativo CODE128).
 *
 * Layout:
 * - Prima riga non-separatore → header centrato, grassetto, doppia altezza
 * - Righe "===..." / "---..." → espanse a 42 caratteri (carta 80 mm)
 * - Riga "BARCODE:" → barcode CODE128 nativo tramite comando GS k 73 {B <data>
 */
function escpos_build_raw(string $txt, string $barcodeValue): string {
  $txt = str_replace("\r\n", "\n", $txt);
  $txt = str_replace("\r", "\n", $txt);

  // Determina il valore del barcode: preferisce quello nel testo
  $txtBarcode   = escpos_extract_barcode_from_txt($txt);
  $finalBarcode = $txtBarcode !== '' ? $txtBarcode : trim((string)$barcodeValue);

  $out = '';

  // ESC @ — inizializza stampante
  $out .= "\x1B\x40";
  // Code page PC850 (Latin-1 / Multilingual) — per caratteri accentati italiani
  $out .= "\x1B\x74\x02";

  $lines         = preg_split("/\n/", $txt);
  $printedHeader = false;

  foreach ($lines as $line) {
    $trim = trim($line);

    // ── Riga BARCODE ──────────────────────────────────────────────
    if (stripos($trim, 'BARCODE:') === 0) {
      if ($finalBarcode !== '') {
        // Feed prima del barcode (quiet zone verticale)
        $out .= "\x0A";
        // Allineamento centrato
        $out .= "\x1B\x61\x01";
        // HRI sotto il barcode (GS H 2)
        $out .= "\x1D\x48\x02";
        // Font HRI: A (GS f 0)
        $out .= "\x1D\x66\x00";
        // Altezza barcode: 100 punti (GS h 100) — leggibile da scanner
        $out .= "\x1D\x68" . chr(100);
        // Larghezza modulo: 3 punti (GS w 3)
        $out .= "\x1D\x77" . chr(3);
        // CODE128 — GS k 73 (0x49), lunghezza, prefisso "{B" + dati
        // "{B" seleziona il Subset B: caratteri ASCII 32-127 (lettere, cifre, punteggiatura)
        $payload = '{B' . $finalBarcode;
        $out .= "\x1D\x6B\x49" . chr(strlen($payload)) . $payload;
        // Feed dopo il barcode
        $out .= "\x0A\x0A";
        // Torna all'allineamento sinistro
        $out .= "\x1B\x61\x00";
      }
      continue;
    }

    // ── Header (prima riga non-separatore) ────────────────────────
    if (!$printedHeader && $trim !== '' && !preg_match('/^[=\-]+$/', $trim)) {
      $printedHeader = true;
      $out .= "\x1B\x61\x01"; // centrato
      $out .= "\x1B\x45\x01"; // grassetto on
      $out .= "\x1D\x21\x11"; // doppia larghezza + altezza
      $out .= $trim . "\x0A";
      $out .= "\x1D\x21\x00"; // dimensione normale
      $out .= "\x1B\x45\x00"; // grassetto off
      $out .= "\x1B\x61\x00"; // allineamento sinistro
      continue;
    }

    // ── Separatori ────────────────────────────────────────────────
    if (preg_match('/^=+$/', $trim)) {
      $out .= str_repeat('=', 42) . "\x0A";
      continue;
    }
    if (preg_match('/^-+$/', $trim)) {
      $out .= str_repeat('-', 42) . "\x0A";
      continue;
    }

    $out .= $line . "\x0A";
  }

  // Feed finale + taglio parziale
  $out .= "\x0A\x0A";
  $out .= "\x1D\x56\x01";

  return $out;
}

/**
 * Stampa via socket TCP diretto (ESC/POS raw, porta 9100 tipica).
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
 * Invia i byte ESC/POS direttamente, bypassando il renderer GDI.
 * Questo è l'unico modo affidabile per spedire comandi nativi CODE128
 * a una stampante termica collegata via USB su Windows.
 */
function escpos_print_windows(string $printerName, string $rawBytes): array {
  $tmpData = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'escpos_' . uniqid('', true) . '.bin';
  $tmpPs   = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'escpos_ps_' . uniqid('', true) . '.ps1';

  if (@file_put_contents($tmpData, $rawBytes) === false) {
    return ['success' => false, 'message' => 'Impossibile scrivere dati ESC/POS su file temporaneo'];
  }

  // PowerShell: legge i byte dal file e li manda alla stampante via WritePrinter ("RAW")
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
 * Funzione principale: sceglie TCP (se IP configurato) o Windows RAW.
 */
function escpos_print_txt_with_barcode(string $txt, string $barcodeValue): array {
  $cfg = escpos_get_printer_config();
  escpos_debug_log('method=' . $cfg['method'] . ' barcode=' . var_export($barcodeValue, true));

  if ($cfg['method'] === 'none') {
    return ['success' => false, 'message' => 'Stampante non configurata (impostare IP oppure PRINTER_NAME/PRINTER_SHARE in costanti.txt)'];
  }

  [$lockFp, $lockErr] = escpos_lock();
  if (!$lockFp) return ['success' => false, 'message' => $lockErr];

  try {
    $rawBytes = escpos_build_raw($txt, $barcodeValue);

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

function escpos_print_txt_with_barcode_from_file(string $filePath, string $barcodeValue): array {
  if (!file_exists($filePath)) return ['success' => false, 'message' => 'File non trovato: ' . $filePath];
  $txt = @file_get_contents($filePath);
  if ($txt === false) return ['success' => false, 'message' => 'Impossibile leggere file: ' . $filePath];
  return escpos_print_txt_with_barcode($txt, $barcodeValue);
}