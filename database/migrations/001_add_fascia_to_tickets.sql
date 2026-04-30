-- Migration: Aggiunge la colonna `fascia` alla tabella `tickets`
-- Permette di tracciare la fascia oraria direttamente sul biglietto/targa,
-- allineandola ai passaggi (passages) che già la registrano in cassa.

ALTER TABLE `tickets`
    ADD COLUMN `fascia` VARCHAR(10) NULL
    COMMENT 'Fascia oraria selezionata al momento del salvataggio (F1, F2, etc.)'
    AFTER `paid`;
