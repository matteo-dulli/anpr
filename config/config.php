<?php
// ===== CONFIGURAZIONE DATABASE =====
define('DB_HOST', 'localhost:3307');
define('DB_USER', 'root');
define('DB_PASSWORD', 'Marcopolo3@$1');
define('DB_NAME', 'anpr_db');
define('DB_PORT', 3307);

// ===== BASE URL/PATH APP (importante per URL corretti) =====
if (!defined('BASE_PATH')) define('BASE_PATH', '/anpr');         // path web dell'app
if (!defined('BASE_URL'))  define('BASE_URL', 'http://localhost'); // opzionale

// ===== CONFIGURAZIONE PERCORSI (filesystem) =====
define('PROJECT_ROOT', dirname(__FILE__, 2));
define('API_DIR', dirname(__FILE__));

// ===== CONFIGURAZIONE COSTANTI APPLICAZIONE =====
define('COSTANTI_FILE', PROJECT_ROOT . '/costanti.txt');

// ===== PERCORSI: differenzia Windows vs Linux/NAS =====
$isWindows = (PHP_OS_FAMILY === 'Windows');

if ($isWindows) {
    // Windows (XAMPP/Apache in C:\htdocs\anpr)
    define('MONITORED_FOLDER', PROJECT_ROOT . '/anpr_images');
    define('PRINT_DIR',        PROJECT_ROOT . '/print');
    define('INVOICE_DIR',      PROJECT_ROOT . '/invoice');
} else {
    // NAS/Linux
    define('MONITORED_FOLDER', '/share/CE_CACHEDEV1_DATA/Web/anpr/anpr_images');
    define('PRINT_DIR',        '/share/CE_CACHEDEV1_DATA/Web/anpr/print');
    define('INVOICE_DIR',      '/share/CE_CACHEDEV1_DATA/Web/anpr/invoice');
}
// Cache/app working dir (deve essere scrivibile)
if (!defined('ANPR_WORK_DIR')) {
  define('ANPR_WORK_DIR', __DIR__ . '/../cache');
}

// Thumbs
if (!defined('ANPR_THUMBS_DIR')) {
  define('ANPR_THUMBS_DIR', ANPR_WORK_DIR . '/thumbs');
}

if (!defined('ANPR_THUMB_W')) define('ANPR_THUMB_W', 240);
if (!defined('ANPR_THUMB_Q')) define('ANPR_THUMB_Q', 60);

if (!defined('ANPR_SCAN_LOCK_FILE')) {
  define('ANPR_SCAN_LOCK_FILE', ANPR_WORK_DIR . '/scan_folder.lock');
}
if (!defined('ANPR_THUMBS_BOOTSTRAP_CURSOR_FILE')) {
  define('ANPR_THUMBS_BOOTSTRAP_CURSOR_FILE', ANPR_WORK_DIR . '/thumbs_bootstrap.cursor');
}
if (!defined('ANPR_THUMBS_INDEX_FILE')) {
  define('ANPR_THUMBS_INDEX_FILE', ANPR_WORK_DIR . '/thumbs_index.jsonl');
}
// ===============================
// THUMBNAILS / CACHE
// ===============================

// URL base (se vuoi anche esporre la cache via web in futuro, non obbligatorio)
if (!defined('ANPR_CACHE_URL')) {
    define('ANPR_CACHE_URL', '/anpr/cache');
}

// Directory fisica cache (SCRIVIBILE)
if (!defined('ANPR_CACHE_DIR')) {
    define('ANPR_CACHE_DIR', rtrim((string)($_SERVER['DOCUMENT_ROOT'] ?? ''), "/\\") . ANPR_CACHE_URL);
}

if (!defined('ANPR_THUMBS_DIR')) {
    define('ANPR_THUMBS_DIR', rtrim(ANPR_CACHE_DIR, "/\\") . DIRECTORY_SEPARATOR . 'thumbs');
}

// Default thumb params (così non li spargi nel JS)
if (!defined('ANPR_THUMB_DEFAULT_W')) {
    define('ANPR_THUMB_DEFAULT_W', 320);
}
if (!defined('ANPR_THUMB_DEFAULT_Q')) {
    define('ANPR_THUMB_DEFAULT_Q', 70);
}
// Percorsi per immagini ANPR locali
define('ANPR_IMAGES_DIR', PROJECT_ROOT . '/anpr_images');
define('ANPR_IMAGES_URL', BASE_PATH . '/anpr_images');

define('API_BASE', BASE_PATH . '/api');

// Percorsi per upload pubblico
define('PUBLIC_DIR', PROJECT_ROOT . '/public');
define('PUBLIC_URL', BASE_PATH . '/public');

// Percorsi per download
define('DOWNLOAD_DIR', PROJECT_ROOT . '/download');
define('DOWNLOAD_URL', BASE_PATH . '/download');

// URL ticket/ricevute
define('PRINT_URL',   BASE_PATH . '/print');
define('INVOICE_URL', BASE_PATH . '/invoice');

// Percorsi per log
define('LOGS_DIR', PROJECT_ROOT . '/logs');
define('LOGS_ERRORS', LOGS_DIR . '/errors.log');
define('LOGS_PLATES', LOGS_DIR . '/plates.log');
define('LOGS_DELETIONS', LOGS_DIR . '/deletions.log');

// Redirige error_log() di PHP verso anpr/logs invece del log di sistema
ini_set('error_log', LOGS_DIR . '/php_errors.log');

// Percorsi database
define('DATABASE_DIR', PROJECT_ROOT . '/database');

// Percorsi per file temporanei
define('TEMP_DIR', PROJECT_ROOT . '/temp');
define('TEMP_UPLOADS', TEMP_DIR . '/uploads');

// Percorsi CSS e JS
define('CSS_DIR', PROJECT_ROOT . '/css');
define('CSS_URL', BASE_PATH . '/css');
define('JS_DIR', PROJECT_ROOT . '/js');
define('JS_URL', BASE_PATH . '/js');

// ===== CONFIGURAZIONE IMMAGINI =====
define('ALLOWED_IMAGE_TYPES', ['jpg', 'jpeg', 'png', 'gif', 'webp']);
define('MAX_IMAGE_SIZE', 10 * 1024 * 1024);
define('IMAGE_QUALITY', 85);
define('THUMB_WIDTH', 200);
define('THUMB_HEIGHT', 150);

// ===== CONFIGURAZIONE SCANSIONE =====
define('SCAN_INTERVAL', 750);
define('SCAN_BATCH_SIZE', 50);

// ===== CONFIGURAZIONE TIMEZONE =====
date_default_timezone_set('Europe/Rome');

// (resto del file invariato: DatabasePool, logEvent, ensureDirectoriesExist, ecc.)

// ===== POOL CONNESSIONI DB (SINGLETON) =====
class DatabasePool {
    private static $instance = null;
    private $pdo = null;
    private $lastConnectTime = 0;
    private $connectTimeout = 5;
    
    private function __construct() {}
    
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    public function getConnection() {
        try {
            if ($this->pdo !== null) {
                try {
                    $this->pdo->query("SELECT 1");
                    return $this->pdo;
                } catch (Exception $e) {
                    $this->pdo = null;
                }
            }
            
            $dsn = 'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=utf8mb4';
            
            $this->pdo = new PDO(
                $dsn,
                DB_USER,
                DB_PASSWORD,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                    PDO::ATTR_TIMEOUT => $this->connectTimeout,
                    PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci",
                ]
            );
            
            $this->lastConnectTime = time();
            return $this->pdo;
            
        } catch (PDOException $e) {
            logEvent('error', 'Connessione DB fallita: ' . $e->getMessage());
            throw new Exception('Errore DB: ' . $e->getMessage());
        }
    }
    
    public function closeConnection() {
        $this->pdo = null;
    }
}

// ===== FUNZIONE DI CONNESSIONE DB (WRAPPER) =====
function getDatabaseConnection() {
    try {
        return DatabasePool::getInstance()->getConnection();
    } catch (Exception $e) {
       /// error_log('Errore connessione DB: ' . $e->getMessage(), 3, LOGS_ERRORS);
        throw $e;
    }
}

// ===== FUNZIONE DI LOGGING =====
function logEvent($type, $message, $data = []) {
    $timestamp = date('Y-m-d H:i:s');
    $logFile = '';
    
    switch($type) {
        case 'plate':
            $logFile = LOGS_PLATES;
            break;
        case 'deletion':
            $logFile = LOGS_DELETIONS;
            break;
        case 'error':
            $logFile = LOGS_ERRORS;
            break;
        default:
            $logFile = LOGS_DIR . '/' . $type . '.log';
    }
    
    $logMessage = "[$timestamp] $message";
    if (!empty($data)) {
        $logMessage .= ' | ' . json_encode($data);
    }
    $logMessage .= "\n";
    
    @error_log($logMessage, 3, $logFile);
}

// ===== FUNZIONE DI CREAZIONE CARTELLE =====
function ensureDirectoriesExist() {
    $directories = [
        ANPR_IMAGES_DIR,
        PUBLIC_DIR,
        DOWNLOAD_DIR,
        PRINT_DIR,
        INVOICE_DIR,
        LOGS_DIR,
        DATABASE_DIR,
        TEMP_DIR,
        TEMP_UPLOADS,
        CSS_DIR,
        JS_DIR
    ];
    
    foreach ($directories as $dir) {
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
    }
}

// ===== FUNZIONE DI CONTROLLO PERMESSI =====
function checkDirectoryWritable($dir) {
    if (!is_dir($dir)) {
        return false;
    }
    return is_writable($dir);
}

// ===== FUNZIONE PER CERCARE IMMAGINE PER TARGA =====
function findImageByPlateNumber($plateNumber, $baseDir = null) {
    if (!$baseDir) {
        $baseDir = MONITORED_FOLDER;
    }
    
    if (!is_dir($baseDir)) {
        error_log("Cartella non trovata: $baseDir");
        return null;
    }
    
    try {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($baseDir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        
        $foundFiles = [];
        
        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $filename = $file->getFilename();
                $filePath = $file->getRealPath();
                
                if (stripos($filename, $plateNumber) !== false && 
                    stripos($filename, 'ANPR.jpg') !== false &&
                    stripos($filename, '.plate.') === false) {
                    
                    if (@getimagesize($filePath)) {
                        $foundFiles[] = [
                            'path' => $filePath,
                            'mtime' => filemtime($filePath),
                            'filename' => $filename
                        ];
                    }
                }
            }
        }
        
        usort($foundFiles, function($a, $b) {
            return $b['mtime'] - $a['mtime'];
        });
        
        if (!empty($foundFiles)) {
            return $foundFiles[0]['path'];
        }
        
        return null;
        
    } catch (Exception $e) {
        error_log('Errore ricerca immagine: ' . $e->getMessage());
        return null;
    }
}

// ===== FUNZIONE PER CARICARE COSTANTI =====
function loadCostanti() {
    $costanti = [
        'TestoF1' => 'Auto piccola',
        'TestoF2' => 'Auto media',
        'TestoF3' => 'Auto grande',
        'TestoF4' => 'Auto Lusso',
        'TestoF5' => 'Furgone',
        'PrezzoF1' => 3.00,
        'PrezzoF2' => 4.00,
        'PrezzoF3' => 5.00,
        'PrezzoF4' => 6.00,
        'PrezzoF5' => 7.00,
        'TolleranzaF1' => 5,
        'TolleranzaF2' => 5,
        'TolleranzaF3' => 5,
        'TolleranzaF4' => 5,
        'TolleranzaF5' => 5,
        'PrezzoDayF1' => 19.00,
        'PrezzoDayF2' => 20.00,
        'PrezzoDayF3' => 25.00,
        'PrezzoDayF4' => 35.00,
        'PrezzoDayF5' => 50.00,
        'Nore' => 5
    ];

    if (file_exists(COSTANTI_FILE)) {
        $lines = file(COSTANTI_FILE, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        
        foreach ($lines as $line) {
            if (strpos(trim($line), '#') === 0) {
                continue;
            }
            
            if (strpos($line, '=') !== false) {
                list($key, $value) = explode('=', $line, 2);
                $key = trim($key);
                $value = trim($value);
                
                if (is_numeric($value)) {
                    $value = strpos($value, '.') !== false ? (float)$value : (int)$value;
                }
                
                $costanti[$key] = $value;
            }
        }
        
        error_log('✅ Costanti caricate da: ' . COSTANTI_FILE);
    } else {
        error_log('⚠️ File costanti non trovato: ' . COSTANTI_FILE . ' - Uso defaults');
    }

    return $costanti;
}

// ===== CARICA COSTANTI GLOBALI =====
$GLOBALS['COSTANTI'] = loadCostanti();

// ===== ESECUZIONE INIZIALE =====
ensureDirectoriesExist();

$criticalDirs = [LOGS_DIR, TEMP_DIR, PRINT_DIR];
foreach ($criticalDirs as $dir) {
    if (!checkDirectoryWritable($dir)) {
        logEvent('error', "Cartella non scrivibile: $dir");
    }
}

if (!is_dir(MONITORED_FOLDER)) {
    logEvent('error', "MONITORED_FOLDER non trovata: " . MONITORED_FOLDER);
}
?>