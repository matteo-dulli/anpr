-- Crea Database
CREATE DATABASE IF NOT EXISTS anpr_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE anpr_db;

-- Tabella Targhe Rilevate
CREATE TABLE IF NOT EXISTS plates (
    id INT PRIMARY KEY AUTO_INCREMENT,
    plate_number VARCHAR(20) NOT NULL,
    date_detected DATETIME DEFAULT CURRENT_TIMESTAMP,
    image_path VARCHAR(500),
    folder_path VARCHAR(500),
    is_modified BOOLEAN DEFAULT FALSE,
    modified_plate VARCHAR(20),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_plate_time (plate_number, date_detected),
    INDEX idx_date (date_detected),
    INDEX idx_plate (plate_number)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabella Ticket
CREATE TABLE IF NOT EXISTS tickets (
    id INT PRIMARY KEY AUTO_INCREMENT,
    plate_id INT NOT NULL,
    barcode VARCHAR(100),
    ticket_number VARCHAR(50),
    ticket_data VARCHAR(500),
    ticket_time TIME,
    user_notes LONGTEXT,
    payment_status VARCHAR(10), -- 'Si', 'No'
    vehicle_type VARCHAR(50), -- Auto, Moto, Furgone, Camion
    vehicle_color VARCHAR(50),
    vehicle_model VARCHAR(100),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (plate_id) REFERENCES plates(id) ON DELETE CASCADE,
    INDEX idx_plate_id (plate_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabella Log Scansioni
CREATE TABLE IF NOT EXISTS scan_logs (
    id INT PRIMARY KEY AUTO_INCREMENT,
    scan_time TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    files_found INT DEFAULT 0,
    new_plates INT DEFAULT 0,
    errors LONGTEXT,
    INDEX idx_scan_time (scan_time)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;