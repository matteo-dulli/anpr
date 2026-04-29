-- ============================================================
-- 🔐 SCHEMA TURNI OPERATORI - Sistema gestione turni ANPR
-- ============================================================

-- ✅ TABELLA PRINCIPALE: TURNI OPERATORI
CREATE TABLE IF NOT EXISTS `operatori_turni` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `operatore_cod` VARCHAR(10) UNIQUE NOT NULL COMMENT '01, 02, 03... (numerico da costanti.txt)',
  `stato` ENUM('online', 'offline') DEFAULT 'offline' COMMENT 'Turno aperto/chiuso',
  `inizio_turno` DATETIME NULL COMMENT 'Quando ha aperto il turno',
  `fine_turno` DATETIME NULL COMMENT 'Quando ha chiuso il turno',
  `ore_totali` DECIMAL(10, 2) DEFAULT 0 COMMENT 'Ore totali lavorate',
  `login_time` DATETIME NULL COMMENT 'Timestamp ultimo login',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_operatore_cod (operatore_cod),
  INDEX idx_stato (stato),
  INDEX idx_inizio_turno (inizio_turno)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Registrazione turni operatori';

-- ✅ TABELLA LOG AZIONI: traccia tutte le operazioni per operatore
CREATE TABLE IF NOT EXISTS `operatori_log_azioni` (
  `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
  `id_turno` INT COMMENT 'FK: operatori_turni.id',
  `operatore_cod` VARCHAR(10) NOT NULL COMMENT '01, 02, 03...',
  `azione` VARCHAR(50) NOT NULL COMMENT 'emetti_ticket, ristampa_ticket, ricevuta, etc.',
  `plate_id` INT COMMENT 'FK: plates.id (se disponibile)',
  `passage_id` INT COMMENT 'FK: passages.id (se disponibile)',
  `ticket_code` VARCHAR(50) COMMENT 'Numero ticket',
  `invoice_id` INT COMMENT 'FK: invoices.id (se ricevuta)',
  `invoice_printed_id` INT COMMENT 'FK: invoices_printed.id (se ristampa ricevuta)',
  `data_ora` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_id_turno (id_turno),
  INDEX idx_operatore_cod (operatore_cod),
  INDEX idx_azione (azione),
  INDEX idx_data_ora (data_ora),
  FOREIGN KEY (id_turno) REFERENCES operatori_turni(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Log azioni per turno';

-- ============================================================
-- AGGIUNTE COLONNE id_turno A TABELLE ESISTENTI
-- ============================================================

-- ✅ MANUAL_PLATES (targhe manuali)
ALTER TABLE `manual_plates` ADD COLUMN `id_turno` INT COMMENT 'FK: operatori_turni.id' AFTER `id`;
ALTER TABLE `manual_plates` ADD KEY `idx_id_turno` (`id_turno`);
ALTER TABLE `manual_plates` ADD CONSTRAINT `fk_manual_plates_turno` 
  FOREIGN KEY (`id_turno`) REFERENCES `operatori_turni`(`id`) ON DELETE SET NULL;

-- ✅ TICKETS (biglietti)
ALTER TABLE `tickets` ADD COLUMN `id_turno` INT COMMENT 'FK: operatori_turni.id' AFTER `id`;
ALTER TABLE `tickets` ADD KEY `idx_id_turno` (`id_turno`);
ALTER TABLE `tickets` ADD CONSTRAINT `fk_tickets_turno` 
  FOREIGN KEY (`id_turno`) REFERENCES `operatori_turni`(`id`) ON DELETE SET NULL;

-- ✅ TICKETS_PRINTED (ristampe biglietti)
ALTER TABLE `tickets_printed` ADD COLUMN `id_turno` INT COMMENT 'FK: operatori_turni.id' AFTER `id`;
ALTER TABLE `tickets_printed` ADD KEY `idx_id_turno` (`id_turno`);
ALTER TABLE `tickets_printed` ADD CONSTRAINT `fk_tickets_printed_turno` 
  FOREIGN KEY (`id_turno`) REFERENCES `operatori_turni`(`id`) ON DELETE SET NULL;

-- ✅ PASSAGES (passaggi)
ALTER TABLE `passages` ADD COLUMN `id_turno` INT COMMENT 'FK: operatori_turni.id' AFTER `id`;
ALTER TABLE `passages` ADD KEY `idx_id_turno` (`id_turno`);
ALTER TABLE `passages` ADD CONSTRAINT `fk_passages_turno` 
  FOREIGN KEY (`id_turno`) REFERENCES `operatori_turni`(`id`) ON DELETE SET NULL;

-- ✅ INVOICES (ricevute)
ALTER TABLE `invoices` ADD COLUMN `id_turno` INT COMMENT 'FK: operatori_turni.id' AFTER `id`;
ALTER TABLE `invoices` ADD KEY `idx_id_turno` (`id_turno`);
ALTER TABLE `invoices` ADD CONSTRAINT `fk_invoices_turno` 
  FOREIGN KEY (`id_turno`) REFERENCES `operatori_turni`(`id`) ON DELETE SET NULL;

-- ✅ INVOICES_PRINTED (ristampe ricevute)
ALTER TABLE `invoices_printed` ADD COLUMN `id_turno` INT COMMENT 'FK: operatori_turni.id' AFTER `id`;
ALTER TABLE `invoices_printed` ADD KEY `idx_id_turno` (`id_turno`);
ALTER TABLE `invoices_printed` ADD CONSTRAINT `fk_invoices_printed_turno` 
  FOREIGN KEY (`id_turno`) REFERENCES `operatori_turni`(`id`) ON DELETE SET NULL;

-- ✅ TESSERAPRE (abbonamenti) - CORRETTO: tesserapre (non tesserepre)
ALTER TABLE `tesserapre` ADD COLUMN `id_turno` INT COMMENT 'FK: operatori_turni.id' AFTER `id`;
ALTER TABLE `tesserapre` ADD KEY `idx_id_turno` (`id_turno`);
ALTER TABLE `tesserapre` ADD CONSTRAINT `fk_tesserapre_turno` 
  FOREIGN KEY (`id_turno`) REFERENCES `operatori_turni`(`id`) ON DELETE SET NULL;

-- ✅ ABBONAMENTI
ALTER TABLE `abbonamenti` ADD COLUMN `id_turno` INT COMMENT 'FK: operatori_turni.id' AFTER `id`;
ALTER TABLE `abbonamenti` ADD KEY `idx_id_turno` (`id_turno`);
ALTER TABLE `abbonamenti` ADD CONSTRAINT `fk_abbonamenti_turno` 
  FOREIGN KEY (`id_turno`) REFERENCES `operatori_turni`(`id`) ON DELETE SET NULL;

-- ============================================================
-- INIT OPERATORI (solo codici numerici)
-- ============================================================
-- Nomi vengono da costanti.txt, qui salviamo solo i codici
INSERT INTO `operatori_turni` (`operatore_cod`, `stato`) VALUES
('01', 'offline'),
('02', 'offline'),
('03', 'offline'),
('04', 'offline'),
('05', 'offline')
ON DUPLICATE KEY UPDATE `stato`=VALUES(`stato`);

-- ============================================================
-- VISTE UTILI PER REPORT
-- ============================================================

-- Vista: Tutte le azioni per turno
CREATE OR REPLACE VIEW `v_turni_azioni` AS
SELECT 
  ot.id as id_turno,
  ot.operatore_cod,
  ot.stato,
  ot.inizio_turno,
  ot.fine_turno,
  ot.ore_totali,
  ola.azione,
  COUNT(*) as conteggio,
  MIN(ola.data_ora) as prima_azione,
  MAX(ola.data_ora) as ultima_azione
FROM operatori_turni ot
LEFT JOIN operatori_log_azioni ola ON ot.id = ola.id_turno
GROUP BY ot.id, ot.operatore_cod, ola.azione
ORDER BY ot.inizio_turno DESC;

-- Vista: Riepilogo turni per operatore
CREATE OR REPLACE VIEW `v_turni_riepilogo` AS
SELECT 
  ot.id as id_turno,
  ot.operatore_cod,
  ot.inizio_turno,
  ot.fine_turno,
  ot.ore_totali,
  ot.stato,
  (SELECT COUNT(*) FROM operatori_log_azioni ola WHERE ola.id_turno = ot.id) as totale_azioni,
  (SELECT COUNT(*) FROM operatori_log_azioni ola WHERE ola.id_turno = ot.id AND ola.azione = 'emetti_ticket') as ticket_emessi,
  (SELECT COUNT(*) FROM operatori_log_azioni ola WHERE ola.id_turno = ot.id AND ola.azione = 'ricevuta') as ricevute,
  (SELECT COUNT(*) FROM operatori_log_azioni ola WHERE ola.id_turno = ot.id AND ola.azione = 'ristampa_ticket') as ticket_ristampati,
  (SELECT COUNT(*) FROM operatori_log_azioni ola WHERE ola.id_turno = ot.id AND ola.azione = 'ristampa_ricevuta') as ricevute_ristampate
FROM operatori_turni ot
ORDER BY ot.inizio_turno DESC;
