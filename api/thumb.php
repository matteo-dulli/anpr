<?php
// api/thumb.php?src=/anpr/uploads/plates/xxx.jpg&w=320&q=75
header('Content-Type: image/jpeg');

$src = $_GET['src'] ?? '';
$w   = isset($_GET['w']) ? (int)$_GET['w'] : 320;
$q   = isset($_GET['q']) ? (int)$_GET['q'] : 75;

$w = max(80, min(1200, $w));
$q = max(40, min(95, $q));

if ($src === '' || strpos($src, '..') !== false) {
    http_response_code(400);
    exit;
}

// Mappa URL -> path su disco (DA ADATTARE)
$docRoot = rtrim($_SERVER['DOCUMENT_ROOT'] ?? '', '/\\');
$srcPath = $docRoot . $src;

if (!is_file($srcPath)) {
    http_response_code(404);
    exit;
}

// Cache dir
$cacheDir = __DIR__ . '/../cache/thumbs';
if (!is_dir($cacheDir)) @mkdir($cacheDir, 0777, true);

$ext = strtolower(pathinfo($srcPath, PATHINFO_EXTENSION));
$cacheKey = md5($srcPath . '|' . filemtime($srcPath) . "|w={$w}|q={$q}");
$cacheFile = $cacheDir . '/' . $cacheKey . '.jpg';

if (is_file($cacheFile)) {
    readfile($cacheFile);
    exit;
}

// Carica immagine
switch ($ext) {
    case 'jpg':
    case 'jpeg':
        $im = @imagecreatefromjpeg($srcPath);
        break;
    case 'png':
        $im = @imagecreatefrompng($srcPath);
        break;
    case 'gif':
        $im = @imagecreatefromgif($srcPath);
        break;
    case 'webp':
        if (function_exists('imagecreatefromwebp')) {
            $im = @imagecreatefromwebp($srcPath);
        } else {
            $im = false;
        }
        break;
    default:
        $im = false;
}

if (!$im) {
    http_response_code(415);
    exit;
}

$srcW = imagesx($im);
$srcH = imagesy($im);

if ($srcW <= 0 || $srcH <= 0) {
    imagedestroy($im);
    http_response_code(500);
    exit;
}

$ratio = $srcH / $srcW;
$newW = $w;
$newH = (int)round($w * $ratio);

$thumb = imagecreatetruecolor($newW, $newH);
imagecopyresampled($thumb, $im, 0, 0, 0, 0, $newW, $newH, $srcW, $srcH);

// Salva cache + output
imagejpeg($thumb, $cacheFile, $q);
imagejpeg($thumb, null, $q);

imagedestroy($thumb);
imagedestroy($im);