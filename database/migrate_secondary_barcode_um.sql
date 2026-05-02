-- Migration: aggiunge secondary_barcode e um alla tabella tickets_printed
-- Eseguire una sola volta sul database di produzione.

ALTER TABLE tickets_printed
    ADD COLUMN secondary_barcode VARCHAR(32) NULL AFTER fascia,
    ADD COLUMN um TINYINT(1) NOT NULL DEFAULT 0 AFTER secondary_barcode;

CREATE UNIQUE INDEX idx_tp_secondary_barcode ON tickets_printed(secondary_barcode);
