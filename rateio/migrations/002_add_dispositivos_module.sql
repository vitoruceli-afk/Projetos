-- =====================================================================
--  CONTROLE DE DISPOSITIVOS
--  Tabelas para gerenciar dispositivos (manual + GLPI)
-- =====================================================================

-- Tipos de dispositivos suportados
CREATE TABLE IF NOT EXISTS tipos_dispositivos (
    id      INT AUTO_INCREMENT PRIMARY KEY,
    nome    VARCHAR(100) NOT NULL UNIQUE,
    icone   VARCHAR(50)  DEFAULT '',
    descricao VARCHAR(255) DEFAULT ''
) ENGINE=InnoDB;

-- Dados iniciais de tipos
INSERT INTO tipos_dispositivos (nome, icone, descricao) VALUES
    ('Computador', 'bi-laptop', 'Desktop, notebook ou estação de trabalho'),
    ('Monitor', 'bi-square', 'Monitor de vídeo'),
    ('Celular/Smartphone', 'bi-phone', 'Dispositivo móvel'),
    ('Tablet', 'bi-tablet-landscape', 'Tablet ou iPad'),
    ('Impressora', 'bi-printer', 'Impressora ou multifuncional'),
    ('Servidor', 'bi-tower', 'Servidor de dados'),
    ('Roteador/Switch', 'bi-router', 'Equipamento de rede'),
    ('Webcam', 'bi-camera', 'Câmera ou webcam'),
    ('Fone/Headset', 'bi-headphones', 'Fone de ouvido ou headset'),
    ('Outros', 'bi-question-circle', 'Outros dispositivos')
ON DUPLICATE KEY UPDATE nome = nome;

-- Configuração de integração com GLPI
CREATE TABLE IF NOT EXISTS config_glpi (
    id            TINYINT PRIMARY KEY DEFAULT 1,
    habilitado    TINYINT(1) NOT NULL DEFAULT 0,
    url_base      VARCHAR(500) DEFAULT '',
    app_token     VARCHAR(255) DEFAULT '',
    user_token    VARCHAR(255) DEFAULT '',
    ultima_sincro DATETIME DEFAULT NULL,
    status_conexao VARCHAR(50) DEFAULT 'desconectado'
) ENGINE=InnoDB;

-- Dispositivos (manual + importados do GLPI)
CREATE TABLE IF NOT EXISTS dispositivos (
    id                INT AUTO_INCREMENT PRIMARY KEY,
    tipo_id           INT NOT NULL,
    pep_id            INT,
    nome              VARCHAR(150) NOT NULL,
    descricao         TEXT DEFAULT '',
    numero_serie      VARCHAR(100) DEFAULT '',
    modelo            VARCHAR(150) DEFAULT '',
    fabricante        VARCHAR(150) DEFAULT '',
    data_aquisicao    DATE DEFAULT NULL,
    status            ENUM('ativo','inativo','descartado','manutenção') DEFAULT 'ativo',
    localizacao       VARCHAR(200) DEFAULT '',
    responsavel       VARCHAR(150) DEFAULT '',
    valor_aquisicao   DECIMAL(10,2) DEFAULT 0,
    observacoes       TEXT DEFAULT '',
    
    -- Rastreamento de origem
    origem            ENUM('manual','glpi') DEFAULT 'manual',
    id_glpi           INT DEFAULT NULL,
    dados_glpi        JSON DEFAULT NULL,
    
    -- Timestamps
    criado_em         TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    atualizado_em     TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    sincronizado_em   DATETIME DEFAULT NULL,
    
    FOREIGN KEY (tipo_id) REFERENCES tipos_dispositivos(id),
    FOREIGN KEY (pep_id) REFERENCES peps(id) ON DELETE SET NULL,
    UNIQUE KEY (id_glpi, origem)
) ENGINE=InnoDB;

-- Log de sincronizações com GLPI
CREATE TABLE IF NOT EXISTS sincronizacoes_glpi (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    tipo_sincro  ENUM('importacao','atualizacao') NOT NULL,
    data_inicio  DATETIME DEFAULT CURRENT_TIMESTAMP,
    data_fim     DATETIME DEFAULT NULL,
    status       ENUM('pendente','executando','sucesso','erro') DEFAULT 'pendente',
    total_items  INT DEFAULT 0,
    items_sucesso INT DEFAULT 0,
    items_erro   INT DEFAULT 0,
    mensagem     TEXT DEFAULT '',
    usuario      VARCHAR(150) DEFAULT '',
    detalhes_json JSON DEFAULT NULL
) ENGINE=InnoDB;

-- Índices para performance
CREATE INDEX idx_dispositivos_tipo ON dispositivos(tipo_id);
CREATE INDEX idx_dispositivos_pep ON dispositivos(pep_id);
CREATE INDEX idx_dispositivos_origem ON dispositivos(origem);
CREATE INDEX idx_dispositivos_status ON dispositivos(status);
CREATE INDEX idx_dispositivos_id_glpi ON dispositivos(id_glpi);
