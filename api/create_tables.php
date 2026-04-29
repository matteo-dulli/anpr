<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

$response = ['success' => false, 'tables_created' => []];

try {
    $db = Database::getInstance()->getConnection();
    
    // ===== CREA TABELLA PLATES =====
    try {
        $db->exec("
            CREATE TABLE IF NOT EXISTS plates (
                id INT PRIMARY KEY AUTO_INCREMENT,
                plate_number VARCHAR(20) NOT NULL,
                plate_corrected VARCHAR(20),
                date_detected DATETIME,
                image_path VARCHAR(500),
                folder_path VARCHAR(500),
                is_manual BOOLEAN DEFAULT FALSE,
                is_modified BOOLEAN DEFAULT FALSE,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY unique_plate_time (plate_number, date_detected),
                INDEX idx_date (date_detected),
                INDEX idx_plate (plate_number)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        $response['tables_created'][] = '✅ plates';
    } catch (Exception $e) {
        $response['tables_created'][] = '❌ plates: ' . $e->getMessage();
    }
    
    // ===== CREA TABELLA TICKETS =====
    try {
        $db->exec("
            CREATE TABLE IF NOT EXISTS tickets (
                id INT PRIMARY KEY AUTO_INCREMENT,
                plate_id INT NOT NULL,
                ticket_info VARCHAR(50),
                vehicle_type VARCHAR(50),
                vehicle_brand VARCHAR(100),
                vehicle_color VARCHAR(50),
                vehicle_position VARCHAR(10),
                entry_date DATE,
                entry_time TIME,
                exit_date DATE,
                exit_time TIME,
                notes LONGTEXT,
                paid BOOLEAN DEFAULT FALSE,
                subscription VARCHAR(50),
                subscription_from DATE,
                subscription_to DATE,
                ticket_prepaid BOOLEAN DEFAULT FALSE,
                ticket_from DATE,
                ticket_to DATE,
                ticket_balance DECIMAL(10, 2) DEFAULT 0.00,
                authorized_vehicle VARCHAR(50),
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                FOREIGN KEY (plate_id) REFERENCES plates(id) ON DELETE CASCADE,
                INDEX idx_plate_id (plate_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        $response['tables_created'][] = '✅ tickets';
    } catch (Exception $e) {
        $response['tables_created'][] = '❌ tickets: ' . $e->getMessage();
    }
    
    // ===== CREA TABELLA MANUAL_TICKETS =====
    try {
        $db->exec("
            CREATE TABLE IF NOT EXISTS manual_tickets (
                id INT PRIMARY KEY AUTO_INCREMENT,
                plate_number VARCHAR(20) NOT NULL,
                ticket_info VARCHAR(50),
                vehicle_type VARCHAR(50),
                vehicle_brand VARCHAR(100),
                vehicle_color VARCHAR(50),
                vehicle_position VARCHAR(10),
                entry_date DATE,
                entry_time TIME,
                exit_date DATE,
                exit_time TIME,
                notes LONGTEXT,
                paid BOOLEAN DEFAULT FALSE,
                subscription VARCHAR(50),
                subscription_from DATE,
                subscription_to DATE,
                ticket_prepaid BOOLEAN DEFAULT FALSE,
                ticket_from DATE,
                ticket_to DATE,
                ticket_balance DECIMAL(10, 2) DEFAULT 0.00,
                authorized_vehicle VARCHAR(50),
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_plate (plate_number)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        $response['tables_created'][] = '✅ manual_tickets';
    } catch (Exception $e) {
        $response['tables_created'][] = '❌ manual_tickets: ' . $e->getMessage();
    }
    
    // ===== CREA TABELLA SCAN_LOGS =====
    try {
        $db->exec("
            CREATE TABLE IF NOT EXISTS scan_logs (
                id INT PRIMARY KEY AUTO_INCREMENT,
                scan_time TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                files_found INT DEFAULT 0,
                new_plates INT DEFAULT 0,
                duplicates INT DEFAULT 0,
                errors INT DEFAULT 0,
                INDEX idx_scan_time (scan_time)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        $response['tables_created'][] = '✅ scan_logs';
    } catch (Exception $e) {
        $response['tables_created'][] = '❌ scan_logs: ' . $e->getMessage();
    }
    
    $response['success'] = true;
    $response['message'] = 'Creazione tabelle completata!';
    
} catch (Exception $e) {
    $response['message'] = '❌ Errore generale: ' . $e->getMessage();
}

echo json_encode($response, JSON_PRETTY_PRINT);
?>