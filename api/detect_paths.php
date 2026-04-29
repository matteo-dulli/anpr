<?php
// ===== FILE PER RILEVARE I PERCORSI ASSOLUTI E AGGIORNARE config.php =====
// Funziona su Linux/Windows e anche se questo file non è nella root del progetto.
// Accedi a: http://<host>/anpr/detect_paths.php  (o dovunque lo metti)

header('Content-Type: text/html; charset=utf-8');

function normalizePath($path) {
    $path = str_replace('\\', '/', $path);
    return rtrim($path, '/');
}

function detectBasePath() {
    // Esempi:
    //  - /anpr/detect_paths.php => /anpr
    //  - /detect_paths.php      => ''
    $scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
    $dir = rtrim(str_replace('\\', '/', dirname($scriptName)), '/');
    if ($dir === '/') $dir = '';
    return $dir;
}

function detectBaseUrl() {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME'] ?? 'localhost');
    return $scheme . '://' . $host;
}

function renderDefine($name, $value) {
    $escaped = str_replace(["\\", "'"], ["\\\\", "\\'"], $value);
    return "define('{$name}', '{$escaped}');";
}

/**
 * Trova la root del progetto risalendo le cartelle finché trova config/config.php
 * (così non dipende dal fatto che detect_paths.php sia in /anpr o /anpr/api)
 */
function findProjectRoot($startDir, $maxUp = 8) {
    $dir = $startDir;
    for ($i = 0; $i <= $maxUp; $i++) {
        $candidate = $dir . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'config.php';
        if (is_file($candidate)) return $dir;

        $parent = dirname($dir);
        if ($parent === $dir) break;
        $dir = $parent;
    }
    return $startDir;
}
function findDirUnderProjectRoot($projectRoot, $relativeDir, $maxDepth = 4) {
    $projectRoot = normalizePath($projectRoot);
    $relativeDir = trim(str_replace('\\', '/', $relativeDir), '/');

    $targetName = basename($relativeDir); // es. "invoice"
    $wantedSuffix = '/' . $relativeDir;   // es. "/invoice"

    if (!is_dir($projectRoot)) return normalizePath($projectRoot . '/' . $relativeDir);

    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($projectRoot, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );

    foreach ($it as $fileInfo) {
        if (!$fileInfo->isDir()) continue;

        // limita profondità (evita scan infinito)
        if (method_exists($it, 'getDepth') && $it->getDepth() > $maxDepth) continue;

        $p = normalizePath($fileInfo->getPathname());

        // match esatto su suffisso: .../<relativeDir>
        if (str_ends_with($p, $wantedSuffix)) return $p;

        // fallback: match su nome directory (meno preciso)
        if (basename($p) === $targetName) return $p;
    }

    // fallback: percorso standard sotto root
    return normalizePath($projectRoot . '/' . $relativeDir);
}
/**
 * Euristica: prova a rilevare cartelle esterne (NAS) in base a dove sta il progetto.
 * - Se il project root è sotto /share/... allora usa /share/.../print|invoice|anpr_images
 * - Altrimenti usa sottocartelle locali del progetto.
 */
function guessExternalOrLocalDir($projectRoot, $folderName) {
    $projectRoot = normalizePath($projectRoot);

    // Caso QNAP/NAS tipico (come il tuo): /share/CE_CACHEDEV1_DATA/Web/anpr
    if (strpos($projectRoot, '/share/') === 0) {
        $base = dirname($projectRoot); // /share/.../Web
        $candidate = normalizePath($base . '/' . $folderName);

        if (is_dir($candidate)) return $candidate;
        // se non esiste, comunque lo proponiamo (è quello "standard" nel tuo layout)
        return $candidate;
    }

    // Fallback: cartella interna al progetto
    return normalizePath($projectRoot . '/' . $folderName);
}

// ---- RILEVAMENTO ROOT / URL ----
$projectRoot = normalizePath(findProjectRoot(__DIR__));
$basePath    = detectBasePath();   // es. /anpr
$baseUrl     = detectBaseUrl();    // es. http://192.168.1.253
$apiBase     = $basePath . '/api';

$apiDir      = normalizePath($projectRoot . '/api');

// ---- RILEVAMENTO CARTELLE "SPECIALI" (NAS o locali) ----
// Nota: queste sono euristiche. Se vuoi forzarle manualmente, puoi sempre cambiare poi in config.php.
$monitoredFolder = findDirUnderProjectRoot($projectRoot, 'anpr_images', 4);
$printDir        = findDirUnderProjectRoot($projectRoot, 'print', 4);
$invoiceDir      = findDirUnderProjectRoot($projectRoot, 'invoice', 4);

// URL pubblici per print/invoice: tipicamente sono esposti come /print e /invoice sotto la stessa base path
$printUrl   = $basePath . '/print';
$invoiceUrl = $basePath . '/invoice';

$auto = [
    // URL / base
    'BASE_PATH' => $basePath,
    'BASE_URL'  => $baseUrl,
    'API_BASE'  => $apiBase,

    // filesystem "core"
    'PROJECT_ROOT'    => $projectRoot,
    'API_DIR'         => $apiDir,
    'ANPR_IMAGES_DIR' => normalizePath($projectRoot . '/anpr_images'),
    'PUBLIC_DIR'      => normalizePath($projectRoot . '/public'),
    'DOWNLOAD_DIR'    => normalizePath($projectRoot . '/download'),
    'LOGS_DIR'        => normalizePath($projectRoot . '/logs'),
    'DATABASE_DIR'    => normalizePath($projectRoot . '/database'),
    'TEMP_DIR'        => normalizePath($projectRoot . '/temp'),
    'CSS_DIR'         => normalizePath($projectRoot . '/css'),
    'JS_DIR'          => normalizePath($projectRoot . '/js'),

    // filesystem "special"
    'MONITORED_FOLDER' => $monitoredFolder,
    'PRINT_DIR'        => $printDir,
    'INVOICE_DIR'      => $invoiceDir,

    // URL statiche (relative alla base path)
    'ANPR_IMAGES_URL' => $basePath . '/anpr_images',
    'PUBLIC_URL'      => $basePath . '/public',
    'DOWNLOAD_URL'    => $basePath . '/download',
    'CSS_URL'         => $basePath . '/css',
    'JS_URL'          => $basePath . '/js',

    // URL "special"
    'PRINT_URL'   => $printUrl,
    'INVOICE_URL' => $invoiceUrl,
];

$directoriesToCheck = [
    'PROJECT_ROOT','API_DIR','ANPR_IMAGES_DIR','PUBLIC_DIR','DOWNLOAD_DIR','LOGS_DIR','DATABASE_DIR','TEMP_DIR','CSS_DIR','JS_DIR',
    'MONITORED_FOLDER','PRINT_DIR','INVOICE_DIR'
];

$configPath = normalizePath($projectRoot . '/config/config.php');

// blocco autogenerato
$blockStart = "// >>> AUTOGENERATED PATHS (detect_paths.php) >>>";
$blockEnd   = "// <<< AUTOGENERATED PATHS (detect_paths.php) <<<";

// genera blocco con define "if (!defined())" per evitare warning di costanti già definite
function renderDefineIfNotDefined($name, $value) {
    $escaped = str_replace(["\\", "'"], ["\\\\", "\\'"], $value);
    return "if (!defined('{$name}')) define('{$name}', '{$escaped}');";
}

$generatedLines = [];
$generatedLines[] = "<?php";
$generatedLines[] = $blockStart;
$generatedLines[] = "// Aggiornato automaticamente il: " . date('Y-m-d H:i:s');
$generatedLines[] = "";

$generatedLines[] = renderDefineIfNotDefined('BASE_PATH', $auto['BASE_PATH']);
$generatedLines[] = renderDefineIfNotDefined('BASE_URL', $auto['BASE_URL']);
$generatedLines[] = renderDefineIfNotDefined('API_BASE', $auto['API_BASE']);
$generatedLines[] = "";

$generatedLines[] = renderDefineIfNotDefined('PROJECT_ROOT', $auto['PROJECT_ROOT']);
$generatedLines[] = renderDefineIfNotDefined('API_DIR', $auto['API_DIR']);

$generatedLines[] = renderDefineIfNotDefined('ANPR_IMAGES_DIR', $auto['ANPR_IMAGES_DIR']);
$generatedLines[] = renderDefineIfNotDefined('ANPR_IMAGES_URL', $auto['ANPR_IMAGES_URL']);

$generatedLines[] = renderDefineIfNotDefined('PUBLIC_DIR', $auto['PUBLIC_DIR']);
$generatedLines[] = renderDefineIfNotDefined('PUBLIC_URL', $auto['PUBLIC_URL']);

$generatedLines[] = renderDefineIfNotDefined('DOWNLOAD_DIR', $auto['DOWNLOAD_DIR']);
$generatedLines[] = renderDefineIfNotDefined('DOWNLOAD_URL', $auto['DOWNLOAD_URL']);

$generatedLines[] = renderDefineIfNotDefined('LOGS_DIR', $auto['LOGS_DIR']);
$generatedLines[] = renderDefineIfNotDefined('DATABASE_DIR', $auto['DATABASE_DIR']);
$generatedLines[] = renderDefineIfNotDefined('TEMP_DIR', $auto['TEMP_DIR']);

$generatedLines[] = renderDefineIfNotDefined('CSS_DIR', $auto['CSS_DIR']);
$generatedLines[] = renderDefineIfNotDefined('CSS_URL', $auto['CSS_URL']);

$generatedLines[] = renderDefineIfNotDefined('JS_DIR', $auto['JS_DIR']);
$generatedLines[] = renderDefineIfNotDefined('JS_URL', $auto['JS_URL']);

$generatedLines[] = "";
$generatedLines[] = "// Cartelle speciali";
$generatedLines[] = renderDefineIfNotDefined('MONITORED_FOLDER', $auto['MONITORED_FOLDER']);
$generatedLines[] = renderDefineIfNotDefined('PRINT_DIR', $auto['PRINT_DIR']);
$generatedLines[] = renderDefineIfNotDefined('PRINT_URL', $auto['PRINT_URL']);
$generatedLines[] = renderDefineIfNotDefined('INVOICE_DIR', $auto['INVOICE_DIR']);
$generatedLines[] = renderDefineIfNotDefined('INVOICE_URL', $auto['INVOICE_URL']);

$generatedLines[] = $blockEnd;
$generatedLines[] = "";
$generatedBlock = implode("\n", $generatedLines) . "\n";

$statusMsg = '';
$statusClass = '';

// Handle POST write
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'write_config') {
    if (!file_exists($configPath)) {
        $statusMsg = "❌ config.php non trovato in: {$configPath}";
        $statusClass = 'status-error';
    } else {
        $configText = file_get_contents($configPath);

        // backup
        $backupPath = $configPath . '.bak-' . date('Ymd-His');
        @copy($configPath, $backupPath);

        if (strpos($configText, $blockStart) !== false && strpos($configText, $blockEnd) !== false) {
            $pattern = '/' . preg_quote($blockStart, '/') . '.*?' . preg_quote($blockEnd, '/') . '\s*/s';
            $newText = preg_replace($pattern, $generatedBlock, $configText);
        } else {
            $newText = rtrim($configText) . "\n\n" . $generatedBlock;
        }

        $ok = file_put_contents($configPath, $newText);
        if ($ok === false) {
            $statusMsg = "❌ Impossibile scrivere config.php (permessi?). Percorso: {$configPath}";
            $statusClass = 'status-error';
        } else {
            $statusMsg = "✅ config.php aggiornato. Backup creato: " . basename($backupPath);
            $statusClass = 'status-ok';
        }
    }
}

$serverInfo = [
    'HTTP Host' => $_SERVER['HTTP_HOST'] ?? 'N/A',
    'Server Name' => $_SERVER['SERVER_NAME'] ?? 'N/A',
    'Server Addr' => $_SERVER['SERVER_ADDR'] ?? 'N/A',
    'Server Port' => $_SERVER['SERVER_PORT'] ?? 'N/A',
    'Document Root' => $_SERVER['DOCUMENT_ROOT'] ?? 'N/A',
    'Script Name' => $_SERVER['SCRIPT_NAME'] ?? 'N/A',
    'Script Filename' => $_SERVER['SCRIPT_FILENAME'] ?? 'N/A',
    'PHP Version' => phpversion(),
    'OS' => php_uname(),
];
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Rilevamento Percorsi ANPR</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Courier New', monospace; background: #1e1e1e; color: #d4d4d4; padding: 20px; }
        .container { max-width: 1200px; margin: 0 auto; }
        h1 { color: #4ec9b0; margin-bottom: 20px; }
        h2 { color: #569cd6; margin-top: 25px; margin-bottom: 12px; border-bottom: 2px solid #569cd6; padding-bottom: 10px; }
        .section { background: #252526; padding: 20px; border-radius: 5px; margin-bottom: 18px; border-left: 4px solid #007acc; }
        .path-item {
            background: #1e1e1e;
            padding: 12px;
            margin: 10px 0;
            border-radius: 4px;
            border-left: 3px solid #4ec9b0;
            font-size: 13px;
            word-break: break-all;
        }
        .path-item code {
            display: block;
            color: #ce9178;
            background: #2d2d30;
            padding: 8px;
            border-radius: 3px;
            margin-top: 5px;
            overflow-x: auto;
        }
        .label { color: #9cdcfe; font-weight: bold; }
        button {
            background: #007acc;
            color: white;
            border: none;
            padding: 10px 16px;
            border-radius: 4px;
            cursor: pointer;
            font-family: 'Courier New', monospace;
            margin-top: 10px;
        }
        button:hover { background: #005a9e; }
        .copy-btn { background: #4ec9b0; font-size: 12px; padding: 6px 12px; margin-left: 10px; }
        .copy-btn:hover { background: #3db899; }
        .config-code {
            background: #1e1e1e;
            border: 1px solid #4ec9b0;
            padding: 15px;
            border-radius: 4px;
            margin: 15px 0;
            overflow-x: auto;
        }
        .config-code code { color: #ce9178; font-size: 12px; line-height: 1.6; display: block; white-space: pre; }
        .status-ok { color: #4ec9b0; font-weight: bold; }
        .status-error { color: #f48771; font-weight: bold; }
        .warn { color: #fbbf24; font-weight: bold; }
        .small { font-size: 12px; color: #999; margin-top: 8px; }
    </style>
</head>
<body>
<div class="container">
    <h1>Rilevamento Percorsi ANPR</h1>

    <?php if ($statusMsg): ?>
        <div class="section">
            <div class="<?php echo htmlspecialchars($statusClass); ?>"><?php echo htmlspecialchars($statusMsg); ?></div>
        </div>
    <?php endif; ?>

    <div class="section">
        <h2>Info server</h2>
        <?php foreach ($serverInfo as $k => $v): ?>
            <div class="path-item">
                <span class="label"><?php echo htmlspecialchars($k); ?>:</span>
                <code><?php echo htmlspecialchars((string)$v); ?></code>
            </div>
        <?php endforeach; ?>
    </div>

    <div class="section">
        <h2>Base URL / Base Path (auto)</h2>
        <div class="path-item"><span class="label">BASE_URL:</span><code><?php echo htmlspecialchars($auto['BASE_URL']); ?></code></div>
        <div class="path-item"><span class="label">BASE_PATH:</span><code><?php echo htmlspecialchars($auto['BASE_PATH'] ?: '/'); ?></code></div>
        <div class="path-item"><span class="label">API_BASE:</span><code><?php echo htmlspecialchars($auto['API_BASE']); ?></code></div>
        <div class="small">PROJECT_ROOT rilevato cercando <code>config/config.php</code> risalendo le directory (funziona anche se detect_paths.php è in /api).</div>
    </div>

    <div class="section">
        <h2>Percorsi assoluti (filesystem)</h2>
        <?php foreach ($directoriesToCheck as $name): ?>
            <?php
                $path = $auto[$name];
                $exists = is_dir($path);
            ?>
            <div class="path-item">
                <span class="label"><?php echo htmlspecialchars($name); ?>:</span>
                <code><?php echo htmlspecialchars($path); ?></code>
                <span class="<?php echo $exists ? 'status-ok' : 'status-error'; ?>">
                    <?php echo $exists ? '✅ Esiste' : '❌ NON TROVATO'; ?>
                </span>
                <button class="copy-btn" onclick="copyToClipboard('<?php echo htmlspecialchars($path); ?>')">Copia</button>
            </div>
        <?php endforeach; ?>
        <div class="small">
            <span class="warn">Nota:</span> MONITORED_FOLDER / PRINT_DIR / INVOICE_DIR sono calcolate con euristiche.
            Se vuoi, dimmi le regole esatte e le rendiamo “perfette” per il tuo ambiente.
        </div>
    </div>

    <div class="section">
        <h2>Preview blocco da scrivere in config.php</h2>
        <div class="config-code"><code><?php echo htmlspecialchars($generatedBlock); ?></code></div>

        <form method="post" onsubmit="return confirm('Confermi aggiornamento di config.php? Verrà creato un backup.');">
            <input type="hidden" name="action" value="write_config">
            <button type="submit">Aggiorna config.php automaticamente</button>
        </form>

        <div class="small">File target: <code><?php echo htmlspecialchars($configPath); ?></code></div>
    </div>
</div>

<script>
function copyToClipboard(text) {
    navigator.clipboard.writeText(text).then(() => alert('Copiato: ' + text));
}
</script>
</body>
</html>