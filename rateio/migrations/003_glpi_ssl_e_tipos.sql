-- =====================================================================
--  AJUSTES NA INTEGRAÇÃO GLPI
--  - Opção de verificar certificado SSL (configurável por instalação)
--  - Tipo "Periféricos" (usado pelo mapeamento automático do GLPI,
--    faltava no catálogo inicial de tipos_dispositivos)
-- =====================================================================

ALTER TABLE config_glpi
    ADD COLUMN verificar_ssl TINYINT(1) NOT NULL DEFAULT 1 AFTER user_token;

INSERT IGNORE INTO tipos_dispositivos (nome, icone, descricao) VALUES
    ('Periféricos', 'bi-usb-symbol', 'Teclado, mouse, headset, webcam ou outro periférico');
