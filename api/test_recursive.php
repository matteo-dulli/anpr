<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../config/config.php';

$response = [];
$response['ANPR_FOLDER'] = ANPR_FOLDER;
$response['exists'] = is_dir(ANPR_FOLDER);

if (is_dir(ANPR_FOLDER)) {
    $files = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(ANPR_FOLDER, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    
    $jpg_count = 0;
    $txt_count = 0;
    $sample_files = [];
    
    foreach ($iterator as $fileinfo) {
        if ($fileinfo->isFile()) {
            $fileName = $fileinfo->getFilename();
            $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
            
            if (in_array($ext, ['jpg', 'jpeg', 'txt'])) {
                if ($ext === 'jpg' || $ext === 'jpeg') {
                    $jpg_count++;
                } else {
                    $txt_count++;
                }
                
                if (count($sample_files) < 5) {
                    $sample_files[] = [
                        'name' => $fileName,
                        'path' => $fileinfo->getRealPath()
                    ];
                }
            }
        }
    }
    
    $response['jpg_found'] = $jpg_count;
    $response['txt_found'] = $txt_count;
    $response['total'] = $jpg_count + $txt_count;
    $response['sample_files'] = $sample_files;
}

echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
?>