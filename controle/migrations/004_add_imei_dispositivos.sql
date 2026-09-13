-- =====================================================================
--  IMEI PARA DISPOSITIVOS MÓVEIS
--  Campo opcional (celulares/smartphones e tablets); NULL para os
--  demais tipos de dispositivo. UNIQUE permite múltiplos NULL mas
--  impede cadastrar o mesmo IMEI duas vezes.
-- =====================================================================

ALTER TABLE dispositivos
    ADD COLUMN imei VARCHAR(20) DEFAULT NULL AFTER numero_serie,
    ADD UNIQUE KEY idx_dispositivos_imei (imei);
