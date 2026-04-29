<?php
// api/get_plate_image.php
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

require_once __DIR__ . '/../config/config.php';

$plateId = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($plateId <= 0) {
    http_response_code(400);
    die('ID non valido');
}

try {
    $db = getDatabaseConnection();

    $stmt = $db->prepare('SELECT plate_number, plate_corrected FROM plates WHERE id = ?');
    $stmt->execute([$plateId]);
    $plate = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$plate) {
        http_response_code(404);
        die('Targa non trovata');
    }
} catch (Exception $e) {
    http_response_code(500);
    die('Errore DB');
}

$plateNumber = $plate['plate_corrected'] ?: $plate['plate_number'];

// Usa la stessa funzione di ricerca, ma filtrando sul suffisso .plate.jpg
$baseDir = MONITORED_FOLDER;
if (!is_dir($baseDir)) {
    http_response_code(500);
    die('Cartella immagini non trovata');
}

$foundPath = null;

try {
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($baseDir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );

    foreach ($iterator as $file) {
        if (!$file->isFile()) continue;

        $filename = $file->getFilename();
        $filePath = $file->getRealPath();

        // vogliamo SOLO i file ANPR.plate.jpg della targa
        if (stripos($filename, $plateNumber) !== false &&
            stripos($filename, 'ANPR.plate.jpg') !== false) {

            if (@getimagesize($filePath)) {
                $foundPath = $filePath;
                break;
            }
        }
    }
} catch (Exception $e) {
    $foundPath = null;
}

if ($foundPath && is_file($foundPath)) {
    $info = @getimagesize($foundPath);
    if ($info !== false) {
        header('Content-Type: ' . $info['mime']);
        header('Content-Length: ' . filesize($foundPath));
        readfile($foundPath);
        exit;
    }
}

// Se arriviamo qui, non esiste una .plate.jpg valida: restituisci un rettangolo nero  (400x120)
$width = 400;
$height = 120;

$image = imagecreatetruecolor($width, $height);
$black = imagecolorallocate($image, 0, 0, 0);
imagefilledrectangle($image, 0, 0, $width, $height, $black);

header('Content-Type: image/png');
imagepng($image);
imagedestroy($image);
exit;