-- Crea Database
CREATE DATABASE IF NOT EXISTS anpr_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE anpr_db;

-- =====================================================================
-- Tabella Targhe Rilevate
-- =====================================================================
CREATE TABLE IF NOT EXISTS plates (
    id INT PRIMARY KEY AUTO_INCREMENT,
    plate_number    VARCHAR(20)  NOT NULL,
    plate_corrected VARCHAR(20)  NULL,
    date_detected   DATETIME     DEFAULT CURRENT_TIMESTAMP,
    image_path      VARCHAR(500) NULL,
    folder_path     VARCHAR(500) NULL,
    is_manual       BOOLEAN      DEFAULT FALSE,
    is_modified     BOOLEAN      DEFAULT FALSE,
    modified_plate  VARCHAR(20)  NULL,
    created_at      TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_plate_time (plate_number, date_detected),
    INDEX idx_date  (date_detected),
    INDEX idx_plate (plate_number)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
-- Tabella Ticket (dati veicolo per targa)
-- =====================================================================
CREATE TABLE IF NOT EXISTS tickets (
    id                 INT PRIMARY KEY AUTO_INCREMENT,
    plate_id           INT          NOT NULL,
    ticket_info        VARCHAR(50)  NULL,
    vehicle_type       VARCHAR(50)  NULL,
    vehicle_brand      VARCHAR(100) NULL,
    vehicle_color      VARCHAR(50)  NULL,
    vehicle_position   VARCHAR(10)  NULL,
    entry_date         DATE         NULL,
    entry_time         TIME         NULL,
    exit_date          DATE         NULL,
    exit_time          TIME         NULL,
    notes              LONGTEXT     NULL,
    paid               BOOLEAN      DEFAULT FALSE,
    subscription       VARCHAR(50)  NULL,
    subscription_from  DATE         NULL,
    subscription_to    DATE         NULL,
    ticket_prepaid     BOOLEAN      DEFAULT FALSE,
    ticket_from        DATE         NULL,
    ticket_to          DATE         NULL,
    ticket_balance     DECIMAL(10,2) DEFAULT 0.00,
    authorized_vehicle VARCHAR(50)  NULL,
    created_at         TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    updated_at         TIMESTAMP    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (plate_id) REFERENCES plates(id) ON DELETE CASCADE,
    INDEX idx_plate_id (plate_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
-- Tabella Manual Tickets
-- =====================================================================
CREATE TABLE IF NOT EXISTS manual_tickets (
    id                 INT PRIMARY KEY AUTO_INCREMENT,
    plate_number       VARCHAR(20)  NOT NULL,
    ticket_info        VARCHAR(50)  NULL,
    vehicle_type       VARCHAR(50)  NULL,
    vehicle_brand      VARCHAR(100) NULL,
    vehicle_color      VARCHAR(50)  NULL,
    vehicle_position   VARCHAR(10)  NULL,
    entry_date         DATE         NULL,
    entry_time         TIME         NULL,
    exit_date          DATE         NULL,
    exit_time          TIME         NULL,
    notes              LONGTEXT     NULL,
    paid               BOOLEAN      DEFAULT FALSE,
    subscription       VARCHAR(50)  NULL,
    subscription_from  DATE         NULL,
    subscription_to    DATE         NULL,
    ticket_prepaid     BOOLEAN      DEFAULT FALSE,
    ticket_from        DATE         NULL,
    ticket_to          DATE         NULL,
    ticket_balance     DECIMAL(10,2) DEFAULT 0.00,
    authorized_vehicle VARCHAR(50)  NULL,
    updated_at         TIMESTAMP    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_plate (plate_number)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
-- Tabella Log Scansioni
-- =====================================================================
CREATE TABLE IF NOT EXISTS scan_logs (
    id          INT PRIMARY KEY AUTO_INCREMENT,
    scan_time   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    files_found INT DEFAULT 0,
    new_plates  INT DEFAULT 0,
    duplicates  INT DEFAULT 0,
    errors      INT DEFAULT 0,
    INDEX idx_scan_time (scan_time)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
-- Tabella Ticket Stampati (emessi dal sistema)
-- =====================================================================
CREATE TABLE IF NOT EXISTS tickets_printed (
    id             INT PRIMARY KEY AUTO_INCREMENT,
    ticket_code    VARCHAR(50)    NULL,
    plate_id       INT            NULL,
    plate_number   VARCHAR(20)    NULL,
    entry_datetime DATETIME       NULL,
    exit_datetime  DATETIME       NULL,
    passage_id     INT            NULL,
    scal           INT            NULL COMMENT 'ID tessera scalare',
    scal_amount    DECIMAL(10,2)  NULL COMMENT 'Importo scalato dalla tessera',
    fascia         VARCHAR(10)    NULL COMMENT 'Fascia oraria scelta all''emissione del ticket',
    created_at     TIMESTAMP      DEFAULT CURRENT_TIMESTAMP,
    updated_at     TIMESTAMP      DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_plate_id    (plate_id),
    INDEX idx_ticket_code (ticket_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
-- Tabella Passaggi Anonimi (senza targa)
-- =====================================================================
CREATE TABLE IF NOT EXISTS passages (
    id                INT PRIMARY KEY AUTO_INCREMENT,
    ticket_printed_id INT          NULL,
    ticket_code       VARCHAR(50)  NULL,
    entry_datetime    DATETIME     NULL,
    exit_datetime     DATETIME     NULL,
    note              TEXT         NULL,
    info              VARCHAR(100) NULL,
    paid              TINYINT      DEFAULT 0,
    created_at        TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    updated_at        TIMESTAMP    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_ticket_printed_id (ticket_printed_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
-- Tabella Cassa (registrazioni pagamento targhe e passaggi)
-- =====================================================================
CREATE TABLE IF NOT EXISTS cassa (
    idcassa       INT PRIMARY KEY AUTO_INCREMENT,

    -- campi targa
    Tplate_id     INT            NULL,
    Tticket_code  VARCHAR(50)    NULL,
    invoice_code  VARCHAR(50)    NULL,
    datacassa     DATE           NULL,
    oraincasso    TIME           NULL,
    Tentry_date   DATE           NULL,
    Tentry_time   TIME           NULL,
    Texit_date    DATE           NULL,
    Texit_time    TIME           NULL,
    Tpaid         TINYINT        DEFAULT 0,
    TpayC         TINYINT        DEFAULT 0,
    TpayE         TINYINT        DEFAULT 0,
    Tannullato    TINYINT        DEFAULT 0,
    Tannultxt     VARCHAR(100)   NULL,
    giorni        INT            NULL,
    ore           INT            NULL,
    minuti        INT            NULL,
    invoice_entry_datetime DATETIME NULL,
    invoice_exit_datetime  DATETIME NULL,
    prezzo        DECIMAL(10,2)  DEFAULT 0,
    invoice_price DECIMAL(10,2)  DEFAULT 0,
    fascia        VARCHAR(10)    NULL COMMENT 'Fascia oraria applicata',

    -- campi passaggio
    idpassages    INT            NULL,
    Ppaid         TINYINT        DEFAULT 0,
    PpayC         TINYINT        DEFAULT 0,
    PpayE         TINYINT        DEFAULT 0,
    Pannullato    TINYINT        DEFAULT 0,
    Pannultxt     VARCHAR(100)   NULL,
    Pticket_code  VARCHAR(50)    NULL,
    Pexit_datetime DATETIME      NULL,

    -- campi comuni
    plate_number  VARCHAR(20)    NULL,
    created_at    TIMESTAMP      DEFAULT CURRENT_TIMESTAMP,
    updated_at    TIMESTAMP      DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    INDEX idx_tplate_id    (Tplate_id),
    INDEX idx_tticket_code (Tticket_code),
    INDEX idx_idpassages   (idpassages),
    INDEX idx_invoice_code (invoice_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
-- Tabella Ricevute (codici ricevuta)
-- =====================================================================
CREATE TABLE IF NOT EXISTS invoices (
    id           INT PRIMARY KEY AUTO_INCREMENT,
    receipt_code VARCHAR(50)    NOT NULL,
    price        DECIMAL(10,2)  DEFAULT 0,
    created_at   DATETIME       NULL,
    updated_at   DATETIME       NULL,
    UNIQUE KEY uq_receipt_code (receipt_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
-- Tabella Ricevute Stampate
-- =====================================================================
CREATE TABLE IF NOT EXISTS invoices_printed (
    id                INT PRIMARY KEY AUTO_INCREMENT,
    receipt_code      VARCHAR(50)    NULL,
    passage_id        INT            NULL,
    entry_datetime    DATETIME       NULL,
    exit_datetime     DATETIME       NULL,
    price             DECIMAL(10,2)  DEFAULT 0,
    created_at        DATETIME       NULL,
    Tannullato        TINYINT        DEFAULT 0,
    Tannultxt         VARCHAR(100)   NULL,
    tessera_id        INT            NULL,
    tessera_scaled    DECIMAL(10,2)  NULL,
    tessera_res_after DECIMAL(10,2)  NULL,
    INDEX idx_receipt_code (receipt_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
-- Tabella Info Garage (dati per stampa ticket/ricevute)
-- =====================================================================
CREATE TABLE IF NOT EXISTS garage_info (
    id              INT PRIMARY KEY AUTO_INCREMENT,
    ragione_sociale VARCHAR(100)  NOT NULL DEFAULT '',
    nome_cognome    VARCHAR(100)  NULL,
    indirizzo       VARCHAR(200)  NULL,
    cap             VARCHAR(10)   NULL,
    citta           VARCHAR(100)  NULL,
    provincia       VARCHAR(5)    NULL,
    piva            VARCHAR(20)   NULL,
    cf              VARCHAR(20)   NULL,
    tel             VARCHAR(20)   NULL,
    cell            VARCHAR(20)   NULL,
    email           VARCHAR(100)  NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
-- Tabella Tessere a Scalare (modulo5)
-- =====================================================================
CREATE TABLE IF NOT EXISTS tesserapre (
    id           INT PRIMARY KEY AUTO_INCREMENT,
    plate_number VARCHAR(20)    NOT NULL,
    attivo       TINYINT        DEFAULT 0,
    res1         DECIMAL(10,2)  DEFAULT 0.00 COMMENT 'Credito residuo',
    Apay         TINYINT        DEFAULT 0,
    SpayC        TINYINT        DEFAULT 0,
    SpayE        TINYINT        DEFAULT 0,
    canc         TINYINT        DEFAULT 0,
    motivo       VARCHAR(100)   NULL,
    fascias      VARCHAR(10)    NULL COMMENT 'Fascia oraria della tessera prepagata',
    card_number  INT            NULL,
    nome         VARCHAR(100)   NULL,
    indirizzo    VARCHAR(200)   NULL,
    citta        VARCHAR(100)   NULL,
    cap          VARCHAR(10)    NULL,
    prov         VARCHAR(5)     NULL,
    stato        VARCHAR(50)    NULL,
    pi           VARCHAR(20)    NULL,
    cf           VARCHAR(20)    NULL,
    codun        VARCHAR(20)    NULL,
    info         TEXT           NULL,
    created_at   TIMESTAMP      DEFAULT CURRENT_TIMESTAMP,
    updated_at   TIMESTAMP      DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_plate_number (plate_number)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
-- Tabella Immagini ANPR
-- =====================================================================
CREATE TABLE IF NOT EXISTS images (
    id         INT PRIMARY KEY AUTO_INCREMENT,
    plate_id   INT            NOT NULL,
    image_path VARCHAR(500)   NULL,
    file_name  VARCHAR(200)   NULL,
    created_at TIMESTAMP      DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_plate_id (plate_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
-- Tabella Log Ingressi Manuali (pre-ticket)
-- =====================================================================
CREATE TABLE IF NOT EXISTS manual_plates_log (
    id         INT PRIMARY KEY AUTO_INCREMENT,
    plate_id   INT     NOT NULL,
    entry_date DATE    NULL,
    entry_time TIME    NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_plate_id (plate_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
