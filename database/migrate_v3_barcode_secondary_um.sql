-- =====================================================================
-- MIGRAZIONE v3: aggiunta barcode_secondary e um a tickets_printed
-- Eseguire una volta sola sul DB di produzione
-- =====================================================================

USE anpr_db;

-- =====================================================================
-- 1. AGGIUNTA COLONNA barcode_secondary A tickets_printed (se non esiste)
-- =====================================================================
ALTER TABLE tickets_printed
    ADD COLUMN IF NOT EXISTS barcode_secondary VARCHAR(20) NULL
        COMMENT 'Parte finale del barcode primario (dopo ultimo -)';

-- =====================================================================
-- 2. AGGIUNTA COLONNA um A tickets_printed (se non esiste)
-- =====================================================================
ALTER TABLE tickets_printed
    ADD COLUMN IF NOT EXISTS um TINYINT DEFAULT 0
        COMMENT 'Flag UM: 1 se accesso tramite barcode secondario, targa, selezione manuale o finale primario';

-- =====================================================================
-- 3. BACKFILL barcode_secondary per record esistenti (calcola dal ticket_code)
-- =====================================================================
UPDATE tickets_printed
SET barcode_secondary = SUBSTRING_INDEX(ticket_code, '-', -1)
WHERE barcode_secondary IS NULL
  AND ticket_code IS NOT NULL
  AND ticket_code <> ''
  AND LOCATE('-', ticket_code) > 0;
