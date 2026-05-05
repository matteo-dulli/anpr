<?php
/**
 * modulo1_receipt_format.php
 * Helper per generare il testo ricevuta per lavaggi e ricariche
 */

require_once __DIR__ . '/../config/config.php';

/**
 * Genera testo ricevuta LAVAGGIO
 */
function generate_receipt_wash(
    string $receiptCode,
    string $plateNumber,
    float $totalPrice,
    string $washType,
    array $accessories = [],
    array $products = []
): string {
    $db = getDatabaseConnection();
    
    // Recupera dati garage
    $garage = [];
    try {
        $stmt = $db->query("SELECT * FROM garage_info LIMIT 1");
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $garage = $row ?: [];
    } catch (Throwable $e) {
        error_log('[generate_receipt_wash] Errore recupero garage: ' . $e->getMessage());
    }
    
    // Intestazione garage
    $ragioneSociale = trim((string)($garage['ragione_sociale'] ?? 'GARAGE XYZ'));
    $indirizzo = trim((string)($garage['indirizzo'] ?? 'Via Esempio 1'));
    $capCitta = trim((string)($garage['cap'] ?? '00100')) . ' ' . trim((string)($garage['citta'] ?? 'Roma'));
    $provincia = trim((string)($garage['provincia'] ?? 'RM'));
    $piva = trim((string)($garage['partita_iva'] ?? 'IT12345678901'));
    $cf = trim((string)($garage['codice_fiscale'] ?? 'RSSMRA...'));
    $tel = trim((string)($garage['telefono'] ?? '061234567'));
    $cell = trim((string)($garage['cellulare'] ?? '3331234567'));
    $email = trim((string)($garage['email'] ?? 'info@garage.xyz'));
    
    // Costruisci ricevuta
    $lines = [];
    $lines[] = $ragioneSociale;
    $lines[] = $indirizzo . ' ' . $capCitta . ' (' . $provincia . ')';
    $lines[] = 'P.IVA: ' . $piva . ' CF: ' . $cf;
    $lines[] = 'Tel: ' . $tel . '   Cell: ' . $cell;
    $lines[] = 'Email: ' . $email;
    $lines[] = '';
    $lines[] = 'RICEVUTA: ' . $receiptCode;
    $lines[] = 'TARGA: ' . $plateNumber;
    $lines[] = '';
    $lines[] = 'LAVAGGIO E ACCESSORI: € ' . number_format($totalPrice, 2, ',', '');
    
    // Dettagli lavaggio
    if (!empty($washType)) {
        $lines[] = 'Tipo: ' . $washType;
    }
    
    // Accessori
    if (!empty($accessories) && is_array($accessories)) {
        $lines[] = '';
        $lines[] = 'Accessori:';
        foreach ($accessories as $acc) {
            if (!empty($acc['desc'])) {
                $qty = (int)($acc['qty'] ?? 1);
                $price = (float)($acc['price'] ?? 0);
                $subtotal = $qty * $price;
                $lines[] = '  - ' . $acc['desc'] . ' x' . $qty . ' € ' . number_format($subtotal, 2, ',', '');
            }
        }
    }
    
    // Prodotti
    if (!empty($products) && is_array($products)) {
        $lines[] = '';
        $lines[] = 'Prodotti:';
        foreach ($products as $prod) {
            if (!empty($prod['desc'])) {
                $qty = (int)($prod['qty'] ?? 1);
                $price = (float)($prod['price'] ?? 0);
                $subtotal = $qty * $price;
                $lines[] = '  - ' . $prod['desc'] . ' x' . $qty . ' € ' . number_format($subtotal, 2, ',', '');
            }
        }
    }
    
    $lines[] = '';
    $lines[] = 'Grazie e Arrivederci - ';
    $lines[] = 'thank you and goodbye';
    $lines[] = '';
    $lines[] = 'BARCODE: ' . $receiptCode;
    
    return implode(PHP_EOL, $lines);
}

/**
 * Genera testo ricevuta RICARICA
 */
function generate_receipt_recharge(
    string $receiptCode,
    string $plateNumber,
    float $totalPrice,
    string $rechargeType,
    float $quantityHours = 0
): string {
    $db = getDatabaseConnection();
    
    // Recupera dati garage
    $garage = [];
    try {
        $stmt = $db->query("SELECT * FROM garage_info LIMIT 1");
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $garage = $row ?: [];
    } catch (Throwable $e) {
        error_log('[generate_receipt_recharge] Errore recupero garage: ' . $e->getMessage());
    }
    
    // Intestazione garage
    $ragioneSociale = trim((string)($garage['ragione_sociale'] ?? 'GARAGE XYZ'));
    $indirizzo = trim((string)($garage['indirizzo'] ?? 'Via Esempio 1'));
    $capCitta = trim((string)($garage['cap'] ?? '00100')) . ' ' . trim((string)($garage['citta'] ?? 'Roma'));
    $provincia = trim((string)($garage['provincia'] ?? 'RM'));
    $piva = trim((string)($garage['partita_iva'] ?? 'IT12345678901'));
    $cf = trim((string)($garage['codice_fiscale'] ?? 'RSSMRA...'));
    $tel = trim((string)($garage['telefono'] ?? '061234567'));
    $cell = trim((string)($garage['cellulare'] ?? '3331234567'));
    $email = trim((string)($garage['email'] ?? 'info@garage.xyz'));
    
    // Costruisci ricevuta
    $lines = [];
    $lines[] = $ragioneSociale;
    $lines[] = $indirizzo . ' ' . $capCitta . ' (' . $provincia . ')';
    $lines[] = 'P.IVA: ' . $piva . ' CF: ' . $cf;
    $lines[] = 'Tel: ' . $tel . '   Cell: ' . $cell;
    $lines[] = 'Email: ' . $email;
    $lines[] = '';
    $lines[] = 'RICEVUTA: ' . $receiptCode;
    $lines[] = 'TARGA: ' . $plateNumber;
    $lines[] = '';
    $lines[] = 'RICARICA ELETTRICA: € ' . number_format($totalPrice, 2, ',', '');
    
    // Tipo ricarica e quantità ore
    if (!empty($rechargeType)) {
        $qtyText = $quantityHours > 0 ? '   Qta/H.: ' . number_format($quantityHours, 1, ',', '') : '';
        $lines[] = 'Tipo: ' . $rechargeType . $qtyText;
    }
    
    $lines[] = '';
    $lines[] = 'Arrivederci e grazie';
    $lines[] = 'Thank you and goodbye';
    $lines[] = '';
    $lines[] = 'BARCODE: ' . $receiptCode;
    
    return implode(PHP_EOL, $lines);
}

?>
