-- =====================================================================
--  ESPECIFICAÇÕES TÉCNICAS + IMPORTAÇÃO CSV DE DISPOSITIVOS
--  Novas colunas usadas pela importação via CSV (e editáveis no
--  formulário manual). "origem" ganha o valor 'csv' para rastrear
--  dispositivos trazidos pela planilha.
-- =====================================================================

ALTER TABLE dispositivos
    ADD COLUMN processador         VARCHAR(150) DEFAULT '' AFTER fabricante,
    ADD COLUMN memoria             VARCHAR(100) DEFAULT '' AFTER processador,
    ADD COLUMN armazenamento       VARCHAR(100) DEFAULT '' AFTER memoria,
    ADD COLUMN sistema_operacional VARCHAR(100) DEFAULT '' AFTER armazenamento,
    ADD COLUMN area                VARCHAR(100) DEFAULT '' AFTER localizacao,
    ADD COLUMN segunda_tela        VARCHAR(100) DEFAULT '' AFTER area;

ALTER TABLE dispositivos
    MODIFY COLUMN origem ENUM('manual','glpi','csv') DEFAULT 'manual';
