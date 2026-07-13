-- =====================================================================
--  Sistema de Rateio (Microsoft + Telefonia)
--  Esquema do banco de dados — MySQL / MariaDB
-- =====================================================================

CREATE DATABASE IF NOT EXISTS rateio
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE rateio;

-- ---------------------------------------------------------------------
--  USUÁRIOS  (locais e provisionados via LDAP)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS usuarios (
    id      INT AUTO_INCREMENT PRIMARY KEY,
    nome    VARCHAR(150) NOT NULL,
    usuario VARCHAR(100) NOT NULL UNIQUE,
    email   VARCHAR(150) DEFAULT '',
    senha   VARCHAR(255) DEFAULT '',
    perfil  ENUM('admin','usuario') NOT NULL DEFAULT 'usuario',
    origem  ENUM('local','ldap')    NOT NULL DEFAULT 'local',
    criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- Usuário administrador padrão  (login: admin / senha: admin123)
-- A senha abaixo está em MD5 (compatível com o login). Ao primeiro acesso,
-- recomenda-se trocar a senha — o sistema regrava em bcrypt automaticamente
-- quando o usuário é editado pela tela de Usuários.
INSERT INTO usuarios (nome, usuario, email, senha, perfil, origem)
VALUES (
    'Administrador',
    'admin',
    'admin@local',
    '0192023a7bbd73250516f069df18b500', -- md5('admin123')
    'admin',
    'local'
)
ON DUPLICATE KEY UPDATE usuario = usuario;

-- ---------------------------------------------------------------------
--  CONFIGURAÇÃO LDAP  (linha única, id = 1)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS config_ldap (
    id            TINYINT PRIMARY KEY DEFAULT 1,
    habilitado    TINYINT(1) NOT NULL DEFAULT 0,
    host          VARCHAR(255) DEFAULT '',
    porta         INT DEFAULT 389,
    base_dn       VARCHAR(255) DEFAULT '',
    dominio       VARCHAR(150) DEFAULT '',
    grupo_admin   VARCHAR(255) DEFAULT '',
    grupo_usuario VARCHAR(255) DEFAULT '',
    filtro_login  VARCHAR(100) DEFAULT 'sAMAccountName'
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
--  CONFIGURAÇÃO SMTP  (linha única, id = 1)
--  metodo: 'smtp' (servidor SMTP com usuário/senha) ou 'oauth' (XOAUTH2)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS config_smtp (
    id                  TINYINT PRIMARY KEY DEFAULT 1,
    metodo              ENUM('smtp','oauth') NOT NULL DEFAULT 'smtp',
    host                VARCHAR(255) DEFAULT '',
    porta               INT DEFAULT 587,
    seguranca           ENUM('none','tls','ssl') NOT NULL DEFAULT 'tls',
    usuario             VARCHAR(255) DEFAULT '',
    senha               VARCHAR(255) DEFAULT '',
    remetente_nome      VARCHAR(150) DEFAULT '',
    remetente_email     VARCHAR(255) DEFAULT '',
    oauth_provedor      ENUM('microsoft','google','custom') NOT NULL DEFAULT 'microsoft',
    oauth_tenant        VARCHAR(255) DEFAULT '',
    oauth_client_id     VARCHAR(255) DEFAULT '',
    oauth_client_secret VARCHAR(255) DEFAULT '',
    oauth_refresh_token TEXT,
    oauth_token_url     VARCHAR(500) DEFAULT '',
    oauth_scope         VARCHAR(500) DEFAULT ''
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
--  CONTATOS  (listas de e-mail por gerência)
--  Um contato pode pertencer à lista Microsoft, Telefonia ou ambas.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS contatos (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    nome            VARCHAR(150) NOT NULL,
    email           VARCHAR(255) NOT NULL,
    lista_microsoft TINYINT(1) NOT NULL DEFAULT 0,
    lista_telefonia TINYINT(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
--  PEPs / PROJETOS  (compartilhado entre Microsoft e Telefonia)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS peps (
    id      INT AUTO_INCREMENT PRIMARY KEY,
    pep     VARCHAR(50)  NOT NULL UNIQUE,
    projeto VARCHAR(200) NOT NULL
) ENGINE=InnoDB;

-- =====================================================================
--  RATEIO MICROSOFT
-- =====================================================================

CREATE TABLE IF NOT EXISTS ms_licencas (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    codigo_licenca VARCHAR(50)  NOT NULL,
    descricao      VARCHAR(255) NOT NULL,
    valor          DECIMAL(10,2) NOT NULL DEFAULT 0,
    modo_cobranca  VARCHAR(50)  DEFAULT ''
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS ms_contas (
    id     INT AUTO_INCREMENT PRIMARY KEY,
    nome   VARCHAR(150) NOT NULL,
    email  VARCHAR(150) NOT NULL,
    pep_id INT NOT NULL,
    FOREIGN KEY (pep_id) REFERENCES peps(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS ms_contas_licencas (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    conta_id   INT NOT NULL,
    licenca_id INT NOT NULL,
    FOREIGN KEY (conta_id)   REFERENCES ms_contas(id)   ON DELETE CASCADE,
    FOREIGN KEY (licenca_id) REFERENCES ms_licencas(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS ms_cobrancas (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    mes         INT NOT NULL,
    ano         INT NOT NULL,
    valor_total DECIMAL(10,2) NOT NULL DEFAULT 0
) ENGINE=InnoDB;

-- =====================================================================
--  RATEIO TELEFONIA
-- =====================================================================

CREATE TABLE IF NOT EXISTS telefonia_contas (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    nome_usuario VARCHAR(150) NOT NULL,
    telefone     VARCHAR(30)  NOT NULL,
    operadora    VARCHAR(50)  DEFAULT '',
    pep_id       INT NOT NULL,
    valor        DECIMAL(10,2) NOT NULL DEFAULT 0,
    conta_telefonia   VARCHAR(80)  DEFAULT '',
    FOREIGN KEY (pep_id) REFERENCES peps(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS telefonia_cobrancas (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    mes         INT NOT NULL,
    ano         INT NOT NULL,
    valor_total DECIMAL(10,2) NOT NULL DEFAULT 0,
    conta_telefonia VARCHAR(80) DEFAULT ''
) ENGINE=InnoDB;

-- =====================================================================
--  HISTÓRICO DE RATEIOS GERADOS  (Microsoft e Telefonia)
-- =====================================================================
CREATE TABLE IF NOT EXISTS rateios_historico (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    tipo         ENUM('microsoft','telefonia') NOT NULL,
    mes          INT NOT NULL,
    ano          INT NOT NULL,
    descricao    VARCHAR(255) DEFAULT '',
    gerado_por   VARCHAR(150) DEFAULT '',
    gerado_em    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    valor_boleto DECIMAL(12,2) NOT NULL DEFAULT 0,
    total_contas DECIMAL(12,2) NOT NULL DEFAULT 0,
    diferenca    DECIMAL(12,2) NOT NULL DEFAULT 0,
    total_final  DECIMAL(12,2) NOT NULL DEFAULT 0,
    dados_json   LONGTEXT
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
--  MIGRAÇÃO (apenas para bancos JÁ existentes)
-- ---------------------------------------------------------------------
--  Estes comandos NÃO são necessários em uma instalação nova (o CREATE
--  TABLE acima já cria o formato atual). Rode apenas os itens aplicáveis
--  ao seu banco. Antes, confira a estrutura:
--      DESCRIBE telefonia_contas;
--      SHOW TABLES LIKE 'telefonia_planos';
--
--  A) Renomeação de "Vivo" para "Telefonia" (tabelas, coluna e enum):
--
-- RENAME TABLE vivo_planos    TO telefonia_planos;
-- RENAME TABLE vivo_contas    TO telefonia_contas;
-- RENAME TABLE vivo_cobrancas TO telefonia_cobrancas;
-- ALTER TABLE telefonia_contas CHANGE conta_vivo conta_telefonia VARCHAR(80) DEFAULT '';
-- ALTER TABLE rateios_historico
--     MODIFY tipo ENUM('microsoft','vivo','telefonia') NOT NULL;
-- UPDATE rateios_historico SET tipo = 'telefonia' WHERE tipo = 'vivo';
-- ALTER TABLE rateios_historico
--     MODIFY tipo ENUM('microsoft','telefonia') NOT NULL;
--
--  B) Caso a tabela de cobranças ainda esteja no formato antigo
--     (nota fiscal), alinhe-a a mês/ano/valor total:
--
-- ALTER TABLE telefonia_cobrancas
--     CHANGE mes_vencimento mes INT NOT NULL,
--     CHANGE ano_vencimento ano INT NOT NULL,
--     CHANGE valor_nota valor_total DECIMAL(10,2) NOT NULL DEFAULT 0,
--     DROP COLUMN numero_nota;
--
--  C) Remoção de Planos da Telefonia (o valor passou a ficar na própria conta).
--
--     IMPORTANTE: se a coluna "valor" JÁ existir em telefonia_contas, NÃO
--     rode o "ADD COLUMN valor" (erro #1060 - coluna duplicada). O trecho
--     abaixo adiciona a coluna apenas se ela ainda não existir, funcionando
--     tanto no MySQL 8 quanto no MariaDB:
--
-- SET @add := (SELECT COUNT(*) FROM information_schema.COLUMNS
--              WHERE TABLE_SCHEMA = DATABASE()
--                AND TABLE_NAME = 'telefonia_contas'
--                AND COLUMN_NAME = 'valor');
-- SET @sql := IF(@add = 0,
--     'ALTER TABLE telefonia_contas ADD COLUMN valor DECIMAL(10,2) NOT NULL DEFAULT 0',
--     'DO 0');
-- PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
--
--     Em seguida, SOMENTE se a tabela telefonia_planos e a coluna plano_id
--     ainda existirem (formato antigo), traga o valor do plano para a conta
--     e descarte o que não é mais usado. A FK de plano_id é descoberta
--     automaticamente (o nome é gerado pelo banco):
--
-- UPDATE telefonia_contas c
--     JOIN telefonia_planos pl ON pl.id = c.plano_id
--     SET c.valor = pl.valor
--     WHERE c.valor = 0;
-- SET @fk := NULL;
-- SELECT CONSTRAINT_NAME INTO @fk
--     FROM information_schema.KEY_COLUMN_USAGE
--     WHERE TABLE_SCHEMA = DATABASE()
--       AND TABLE_NAME = 'telefonia_contas'
--       AND COLUMN_NAME = 'plano_id'
--       AND REFERENCED_TABLE_NAME IS NOT NULL
--     LIMIT 1;
-- SET @sql := IF(@fk IS NOT NULL,
--     CONCAT('ALTER TABLE telefonia_contas DROP FOREIGN KEY ', @fk), 'DO 0');
-- PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
-- ALTER TABLE telefonia_contas DROP COLUMN plano_id;
-- DROP TABLE telefonia_planos;
--
--  D) Envio de rateios por e-mail (novas tabelas). Em bancos já existentes,
--     basta executar os CREATE TABLE de "config_smtp" e "contatos" acima
--     (ambos usam IF NOT EXISTS, então é seguro reexecutar este arquivo).
--
--  E) Cobrança da Telefonia vinculada à Conta Telefonia (seletor no cadastro
--     de cobranças, com as opções vindas dos valores já usados em
--     telefonia_contas.conta_telefonia). Adiciona a coluna somente se ainda
--     não existir:
--
-- SET @add := (SELECT COUNT(*) FROM information_schema.COLUMNS
--              WHERE TABLE_SCHEMA = DATABASE()
--                AND TABLE_NAME = 'telefonia_cobrancas'
--                AND COLUMN_NAME = 'conta_telefonia');
-- SET @sql := IF(@add = 0,
--     'ALTER TABLE telefonia_cobrancas ADD COLUMN conta_telefonia VARCHAR(80) DEFAULT ''''',
--     'DO 0');
-- PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
