-- =====================================================================
-- MIGRAZIONE v4: aggiunta secondary_barcode a lavaggi e ricariche
-- Eseguire una volta sola sul DB di produzione
-- =====================================================================

USE anpr_db;

-- =====================================================================
-- 1. AGGIUNTA COLONNA secondary_barcode A lavaggi (se non esiste)
-- =====================================================================
ALTER TABLE lavaggi
    ADD COLUMN IF NOT EXISTS secondary_barcode VARCHAR(10) NULL AFTER primary_barcode
        COMMENT 'Codice secondario del ticket abbinato al lavaggio (fino a 10 caratteri)';

-- =====================================================================
-- 2. AGGIUNTA COLONNA secondary_barcode A ricariche (se non esiste)
-- =====================================================================
ALTER TABLE ricariche
    ADD COLUMN IF NOT EXISTS secondary_barcode VARCHAR(10) NULL AFTER primary_barcode
        COMMENT 'Codice secondario del ticket abbinato alla ricarica (fino a 10 caratteri)';
