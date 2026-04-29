<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
ini_set('display_errors', '0');
error_reporting(E_ALL);

set_error_handler(function($errno, $errstr, $errfile, $errline) {
    $logFile = __DIR__ . '/../logs/php_errors.log';
    @error_log("[$errno] $errstr in $errfile:$errline\n", 3, $logFile);
});

register_shutdown_function(function () {
    $e = error_get_last();
    if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode([
            'success' => false,
            'data' => ['fatal' => $e],
            'message' => 'Fatal error in scan_folder.php'
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }
});

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/thumbs.php';

function anpr_load_index(string $indexFile): array {
    $map = [];
    if (!is_file($indexFile)) return $map;
    $fh = @fopen($indexFile, 'rb');
    if (!$fh) return $map;
    while (!feof($fh)) {
        $line = trim((string)fgets($fh));
        if ($line === '') continue;
        $row = json_decode($line, true);
        if (is_array($row) && isset($row['path'])) $map[(string)$row['path']] = $row;
    }
    fclose($fh);
    return $map;
}

function anpr_save_index_atomic(string $indexFile, array $map): bool {
    $dir = dirname($indexFile);
    if (!is_dir($dir)) @mkdir($dir, 0777, true);
    $tmp = $indexFile . '.tmp.' . getmypid() . '.' . bin2hex(random_bytes(4));
    $fh = @fopen($tmp, 'wb');
    if (!$fh) return false;
    foreach ($map as $row) @fwrite($fh, json_encode($row, JSON_UNESCAPED_SLASHES) . "\n");
    @fclose($fh);
    if (!@rename($tmp, $indexFile)) {
        @unlink($tmp);
        return false;
    }
    return true;
}

$bootstrapThumbs = isset($_GET['bootstrap_thumbs']) && (string)$_GET['bootstrap_thumbs'] === '1';
$syncThumbs = isset($_GET['sync_thumbs']) && (string)$_GET['sync_thumbs'] === '1';
$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 200;
$limit = max(1, min(10000, $limit));

$resetCursor = isset($_GET['reset_cursor']) && (string)$_GET['reset_cursor'] === '1';
$cursorFile = defined('ANPR_THUMBS_BOOTSTRAP_CURSOR_FILE') ? (string)ANPR_THUMBS_BOOTSTRAP_CURSOR_FILE : (__DIR__ . '/../cache/thumbs_bootstrap.cursor');

$lockFp = null;
$baseLockFile = defined('ANPR_SCAN_LOCK_FILE') ? (string)ANPR_SCAN_LOCK_FILE : '';
$lockFile = $baseLockFile;

if (($bootstrapThumbs || $syncThumbs) && $baseLockFile !== '') {
    $lockFile = preg_match('/\.lock$/i', $baseLockFile) ? preg_replace('/\.lock$/i', '.bootstrap.lock', $baseLockFile) : ($baseLockFile . '.bootstrap');
}

if ($lockFile !== '') {
    $lockDir = dirname($lockFile);
    if (!is_dir($lockDir)) @mkdir($lockDir, 0777, true);
    $lockFp = @fopen($lockFile, 'c+');
    if (!$lockFp) {
        echo json_encode(['success' => false,'data' => ['new_plates' => 0],'message' => "Impossibile aprire lock file: $lockFile"], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        exit;
    }
    if (!@flock($lockFp, LOCK_EX | LOCK_NB)) {
        echo json_encode(['success' => true,'data' => ['new_plates' => 0,'skipped' => true],'message' => 'Scan già in esecuzione, skip'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        exit;
    }
    register_shutdown_function(function() use (&$lockFp) {
        if ($lockFp) {
            @flock($lockFp, LOCK_UN);
            @fclose($lockFp);
        }
    });
}

@set_time_limit(($bootstrapThumbs || $syncThumbs) ? 300 : 60);

$db = getDatabaseConnection();

$response = [
    'success' => false,
    'data' => [
        'mode' => $syncThumbs ? 'sync_thumbs' : ($bootstrapThumbs ? 'bootstrap_thumbs' : 'incremental'),
        'new_plates' => 0,
        'thumbs_generated' => 0,
        'thumbs_skipped' => 0,
        'thumbs_failed' => 0,
        'limit' => $limit,
        'debug' => [],
    ],
    'message' => ''
];

try {
    $imagesFolder = MONITORED_FOLDER;
    if (!is_dir($imagesFolder)) throw new Exception("Cartella scanner non trovata: $imagesFolder");
    $imagesFolderReal = realpath($imagesFolder);
    if ($imagesFolderReal === false) throw new Exception("Impossibile risolvere realpath di MONITORED_FOLDER: $imagesFolder");
    $imagesFolderNorm = rtrim(str_replace('\\', '/', $imagesFolderReal), '/');

    $thumbW = defined('ANPR_THUMB_W') ? (int)ANPR_THUMB_W : 240;
    $thumbQ = defined('ANPR_THUMB_Q') ? (int)ANPR_THUMB_Q : 60;

    $cursor = '';
    if ($bootstrapThumbs) {
        if ($resetCursor && is_file($cursorFile)) @unlink($cursorFile);
        if (is_file($cursorFile)) $cursor = trim((string)@file_get_contents($cursorFile));
    }

    $lastPath = '';
    if (!$bootstrapThumbs && !$syncThumbs) {
        $stmtLast = $db->query("SELECT MAX(image_path) AS last_path FROM images");
        $rowLast = $stmtLast ? $stmtLast->fetch(PDO::FETCH_ASSOC) : null;
        $lastPath = ($rowLast && !empty($rowLast['last_path'])) ? (string)$rowLast['last_path'] : '';
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($imagesFolderNorm, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );

    if ($syncThumbs) {
        if (!defined('ANPR_THUMBS_INDEX_FILE')) throw new Exception('ANPR_THUMBS_INDEX_FILE non definito');
        $indexFile = (string)ANPR_THUMBS_INDEX_FILE;
        $index = anpr_load_index($indexFile);
        $now = time();
        $seen = [];
        $missing = [];

        foreach ($iterator as $file) {
            if (!$file->isFile()) continue;
            $filename = $file->getFilename();
            $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
            if ($ext !== 'jpg' || strpos($filename, '.plate.') !== false) continue;

            $filePath = $file->getRealPath();
            if (!$filePath) continue;

            $filePathNorm = str_replace('\\', '/', $filePath);
            $relativePath = ltrim(str_replace($imagesFolderNorm, '', $filePathNorm), '/');
            if ($relativePath === '' || strpos($relativePath, '..') !== false) continue;

            $absPath = $imagesFolderNorm . '/' . $relativePath;
            $mtime = @filemtime($absPath) ?: 0;
            $size = @filesize($absPath) ?: 0;
            $cacheKey = $relativePath . '|' . $mtime . '|' . $size;
            $thumbFile = thumbPathFromCacheKey($cacheKey, $thumbW, $thumbQ);

            $row = $index[$relativePath] ?? ['path' => $relativePath];
            $row['mtime'] = $mtime;
            $row['size'] = $size;
            $row['thumb'] = $thumbFile;
            $row['last_seen'] = $now;
            $index[$relativePath] = $row;
            $seen[$relativePath] = true;

            if (!thumbIsValid($thumbFile)) {
                $missing[] = ['relative_path' => $relativePath,'abs_path' => $absPath,'cacheKey' => $cacheKey];
            }
        }

        $batch = array_slice($missing, 0, $limit);

        foreach ($batch as $item) {
            try {
                $thumb = ensureThumbForImage($item['abs_path'], $item['cacheKey'], $thumbW, $thumbQ);
                if (thumbIsValid($thumb)) $response['data']['thumbs_generated']++;
                else $response['data']['thumbs_skipped']++;
            } catch (Throwable $e) {
                $response['data']['thumbs_failed']++;
            }
        }

        $deleted = 0;
        foreach ($index as $path => $row) {
            if (!isset($seen[$path])) {
                $thumb = $row['thumb'] ?? '';
                if (is_string($thumb) && $thumb !== '' && is_file($thumb)) @unlink($thumb);
                if (is_string($thumb) && $thumb !== '' && is_file($thumb . '.lock')) @unlink($thumb . '.lock');
                unset($index[$path]);
                $deleted++;
            }
        }

        anpr_save_index_atomic($indexFile, $index);

        $response['success'] = true;
        $response['data']['index_file'] = $indexFile;
        $response['data']['index_entries'] = count($index);
        $response['data']['missing_total'] = count($missing);
        $response['data']['deleted'] = $deleted;
        $response['data']['done'] = (count($batch) < $limit);
        $response['message'] = "Sync thumbs OK (generated={$response['data']['thumbs_generated']}, failed={$response['data']['thumbs_failed']}, deleted=$deleted)";
        echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        exit;
    }

    $candidates = [];
    foreach ($iterator as $file) {
        if (!$file->isFile()) continue;
        $filename = $file->getFilename();
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        if ($ext !== 'jpg' || strpos($filename, '.plate.') !== false) continue;

        $filePath = $file->getRealPath();
        if (!$filePath) continue;

        $filePathNorm = str_replace('\\', '/', $filePath);
        $relativePath = ltrim(str_replace($imagesFolderNorm, '', $filePathNorm), '/');
        if ($relativePath === '' || strpos($relativePath, '..') !== false) continue;

        if ($bootstrapThumbs) {
            if ($cursor !== '' && strcmp($relativePath, $cursor) <= 0) continue;
        } else {
            if ($lastPath !== '' && strcmp($relativePath, $lastPath) <= 0) continue;
        }

        $candidates[] = ['filename' => $filename,'relative_path' => $relativePath];
    }

    if (count($candidates) === 0) {
        $response['success'] = true;
        $response['data']['done'] = true;
        $response['message'] = $bootstrapThumbs ? "Bootstrap completato: nessun altro file" : "Nessun nuovo file";
        echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        exit;
    }

    usort($candidates, fn($a, $b) => strcmp($a['relative_path'], $b['relative_path']));
    $candidates = array_slice($candidates, 0, $limit);

    if ($bootstrapThumbs) {
        $processed = 0;
        $lastProcessedPath = '';

        foreach ($candidates as $item) {
            $relativePath = $item['relative_path'];
            $absPath = $imagesFolderNorm . '/' . $relativePath;

            $mtime = @filemtime($absPath) ?: 0;
            $size = @filesize($absPath) ?: 0;
            $cacheKey = $relativePath . '|' . $mtime . '|' . $size;

            try {
                $thumbFile = ensureThumbForImage($absPath, $cacheKey, $thumbW, $thumbQ);
                if ($thumbFile && is_file($thumbFile) && @filesize($thumbFile) > 0) $response['data']['thumbs_generated']++;
                else $response['data']['thumbs_skipped']++;
            } catch (Throwable $e) {
                $response['data']['thumbs_failed']++;
            }

            $processed++;
            $lastProcessedPath = $relativePath;
        }

        if ($lastProcessedPath !== '') {
            $dir = dirname($cursorFile);
            if (!is_dir($dir)) @mkdir($dir, 0777, true);
            @file_put_contents($cursorFile, $lastProcessedPath);
        }

        $response['success'] = true;
        $response['data']['processed'] = $processed;
        $response['data']['cursor'] = $lastProcessedPath;
        $response['data']['done'] = (count($candidates) < $limit);
        $response['message'] = "Bootstrap thumbs OK (processed=$processed)";
        echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        exit;
    }

    $stmtSelectPlate = $db->prepare("SELECT id FROM plates WHERE plate_number = ? AND date_detected = ? LIMIT 1");
    $stmtInsertPlate = $db->prepare("INSERT INTO plates (plate_number, plate_corrected, date_detected, is_manual, created_at, updated_at) VALUES (?, ?, ?, 0, NOW(), NOW())");
    $stmtUpsertImage = $db->prepare("INSERT INTO images (plate_id, image_path, file_name, created_at) VALUES (?, ?, ?, NOW()) ON DUPLICATE KEY UPDATE plate_id = VALUES(plate_id), file_name = VALUES(file_name)");

    $newCount = 0;
    $duplicatePlates = 0;
    $invalidFiles = 0;

    foreach ($candidates as $item) {
        $filename = $item['filename'];
        $relativePath = $item['relative_path'];

        $matches = [];
        if (!preg_match('/(\d{2})-(\d{2})-(\d{4})-(\d{2})-(\d{2})-([A-Z]{2}\d{3}[A-Z]{2})-ANPR\.jpg/i', $filename, $matches)) {
            $invalidFiles++;
            continue;
        }

        $day = $matches[1];
        $month = $matches[2];
        $year = $matches[3];
        $hour = $matches[4];
        $minute = $matches[5];
        $plateNumber = strtoupper($matches[6]);
        $dateTime = "$year-$month-$day $hour:$minute:00";

        try {
            $stmtSelectPlate->execute([$plateNumber, $dateTime]);
            $plateRow = $stmtSelectPlate->fetch(PDO::FETCH_ASSOC);

            if (!$plateRow) {
                $stmtInsertPlate->execute([$plateNumber, $plateNumber, $dateTime]);
                $plateId = (int)$db->lastInsertId();
                $newCount++;
            } else {
                $plateId = (int)$plateRow['id'];
                $duplicatePlates++;
            }

            $stmtUpsertImage->execute([$plateId, $relativePath, $filename]);

            $absPath = $imagesFolderNorm . '/' . $relativePath;
            $mtime = @filemtime($absPath) ?: 0;
            $size = @filesize($absPath) ?: 0;
            $cacheKey = $relativePath . '|' . $mtime . '|' . $size;

            $thumbFile = ensureThumbForImage($absPath, $cacheKey, $thumbW, $thumbQ);
            if ($thumbFile && is_file($thumbFile) && @filesize($thumbFile) > 0) $response['data']['thumbs_generated']++;
            else $response['data']['thumbs_skipped']++;

        } catch (Throwable $e) {
            logEvent('error', "Errore processing: " . $e->getMessage(), ['file' => $filename,'image_path' => $relativePath]);
        }
    }

    $response['success'] = true;
    $response['data']['new_plates'] = $newCount;
    $response['data']['duplicate_plates'] = $duplicatePlates;
    $response['data']['invalid_files'] = $invalidFiles;
    $response['message'] = "Scansione completata: $newCount nuove targhe";

} catch (Throwable $e) {
    $response['success'] = false;
    $response['message'] = '❌ ' . $e->getMessage();
}

echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);