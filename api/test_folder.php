<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../config/config.php';

$response = [];

// Mostra la cartella configurata
$response['ANPR_FOLDER'] = ANPR_FOLDER;
$response['folder_exists'] = is_dir(ANPR_FOLDER);

// Se esiste, mostra i file
if (is_dir(ANPR_FOLDER)) {
    $files = scandir(ANPR_FOLDER);
    $response['all_files'] = $files;
    
    // Conta per tipo
    $jpg_files = glob(ANPR_FOLDER . '*.jpg');
    $JPG_files = glob(ANPR_FOLDER . '*.JPG');
    $txt_files = glob(ANPR_FOLDER . '*.txt');
    
    $response['jpg_count'] = count($jpg_files);
    $response['JPG_count'] = count($JPG_files);
    $response['txt_count'] = count($txt_files);
    $response['total'] = count($jpg_files) + count($JPG_files) + count($txt_files);
    
    // Mostra i primi 3 file di ogni tipo
    $response['sample_jpg'] = array_slice($jpg_files, 0, 3);
    $response['sample_JPG'] = array_slice($JPG_files, 0, 3);
    $response['sample_txt'] = array_slice($txt_files, 0, 3);
}

echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
?>