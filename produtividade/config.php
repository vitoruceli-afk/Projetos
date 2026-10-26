<?php
// Buffer de saída para toda a requisição — evita "headers already sent" quando um header()
// de redirecionamento é chamado depois de algum HTML já ter sido ecoado (mesma decisão
// documentada em ../mikrotik/config.php e ../estoque-clinica/config.php).
ob_start();

date_default_timezone_set('America/Sao_Paulo');

define('SESSION_IDLE_TIMEOUT', 1800); // 30 minutos

ini_set('session.gc_maxlifetime', SESSION_IDLE_TIMEOUT + 300);
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();

// Configurações do Banco de Dados Local (MySQL/MariaDB do XAMPP) — ambiente local usa seu próprio
// banco, separado do servidor de homologação (10.1.100.123); todo teste/alteração feito aqui fica
// isolado, sem afetar homologação. Credenciais do servidor de homologação ficam só no config.php
// de lá (/var/www/produtividade.faesa.br/config.php), nunca neste arquivo versionado.
define('DB_HOST', '127.0.0.1');
define('DB_NAME', 'produtividade_aw');
define('DB_USER', 'root');
define('DB_PASS', '');

// Porta padrão do aw-server (ActivityWatch) quando a máquina não especificar outra.
define('AW_PORTA_PADRAO', 5600);

// Timeouts (segundos) das chamadas HTTP feitas às máquinas da rede. Curtos de propósito: uma
// máquina desligada/offline não pode travar a sincronização das demais por dezenas de segundos
// (timeout de TCP do SO). Ver aw_client.php.
define('AW_TIMEOUT_CONECTAR', 3);
define('AW_TIMEOUT_TOTAL', 20);

// Quantos dias de histórico buscar na primeira sincronização de uma máquina (buckets sem cursor
// salvo ainda). Sincronizações seguintes partem sempre do último evento já salvo.
define('AW_BACKFILL_INICIAL_DIAS', 7);

// Timeout (segundos) para conectar/consultar o Active Directory (Integração > Active Directory).
define('LDAP_CONN_TIMEOUT', 3);

// Chave usada para criptografar a senha da conta de serviço do AD salva no banco.
// TROQUE este valor em produção e mantenha-o fora do controle de versão (ex: variável de ambiente).
define('AD_ENC_KEY', 'produtividade-aw-ad-troque-esta-chave');

// Chave usada para criptografar a senha da conta admin usada na instalação remota do MSI
// (Máquinas > Instalação Remota). TROQUE este valor em produção.
define('INSTALL_ENC_KEY', 'produtividade-aw-install-troque-esta-chave');

// Caminho padrão do MSI gerado (ver Instaladores\msi-build) e tempo máximo (segundos) que a
// instalação remota espera o msiexec terminar na máquina de destino antes de desistir. Caminho
// muda por SO porque o servidor pode rodar tanto no Windows (ambiente local) quanto no Linux
// (homologação/produção) — ver install_client.php, que escolhe remote_install.ps1 ou .py conforme
// PHP_OS_FAMILY. Isso é só o valor usado ao SEMEAR a configuração na primeira instalação; depois
// disso o caminho fica editável em Máquinas > Instalação Remota, por ambiente.
define('INSTALL_MSI_PATH_PADRAO', PHP_OS_FAMILY === 'Windows'
    ? 'C:\\xampp\\htdocs\\produtividade\\Instaladores\\ActivityWatch-Produtividade.msi'
    : '/var/www/produtividade.faesa.br/Instaladores/ActivityWatch-Produtividade.msi');
define('INSTALL_TIMEOUT_PADRAO', 300);

// Python do venv dedicado (impacket) usado pelo executor Linux da instalação remota — ver
// remote_install.py/remote_uninstall.py e o branch em install_client.php. Sem efeito no Windows.
define('INSTALL_PYTHON_BIN', '/opt/produtividade-remoteinstall-venv/bin/python3');

// IP desta máquina (onde a aplicação roda hoje) — passado como propriedade SERVERIP do MSI na
// instalação remota, para a regra de firewall da máquina de destino liberar o host certo. Mesmo
// valor usado como padrão dentro do próprio pacote MSI (Instaladores\msi-build\Product.wxs);
// atualize os dois se a aplicação for movida para outro servidor.
define('AW_SERVIDOR_IP', '10.10.140.17');

// Incrementar sempre que uma migração (CREATE TABLE/ALTER TABLE) for adicionada em getDB() — é o
// que faz o bloco de migração rodar de novo (uma única vez) na próxima requisição após o deploy.
define('SCHEMA_VERSION', 27);

function getDB() {
    // Conexão + schema cacheados numa estática por requisição (mesmo motivo documentado em
    // ../estoque-clinica/config.php: evita repetir as migrações em toda chamada).
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }

    try {
        $dsn = "mysql:host=" . DB_HOST . ";charset=utf8mb4";
        $db = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $db->exec("CREATE DATABASE IF NOT EXISTS `" . DB_NAME . "` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $db->exec("USE `" . DB_NAME . "`");

        $db->exec("CREATE TABLE IF NOT EXISTS schema_migrations (versao INT NOT NULL)");
        $versaoAplicada = (int)($db->query("SELECT versao FROM schema_migrations LIMIT 1")->fetchColumn() ?: 0);

        if ($versaoAplicada < SCHEMA_VERSION) {

        $db->exec("CREATE TABLE IF NOT EXISTS local_users (
            id INT AUTO_INCREMENT PRIMARY KEY,
            username VARCHAR(100) NOT NULL UNIQUE,
            password_hash VARCHAR(255) NOT NULL,
            full_name VARCHAR(255) DEFAULT '',
            enabled TINYINT(1) NOT NULL DEFAULT 1,
            role VARCHAR(20) NOT NULL DEFAULT 'usuario',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )");

        // Máquinas da rede com o aw-server (ActivityWatch) exposto. host aceita IP ou hostname.
        $db->exec("CREATE TABLE IF NOT EXISTS maquinas (
            id INT AUTO_INCREMENT PRIMARY KEY,
            nome VARCHAR(150) NOT NULL,
            host VARCHAR(255) NOT NULL,
            porta INT NOT NULL DEFAULT 5600,
            usuario_responsavel VARCHAR(150) DEFAULT '',
            ip_local VARCHAR(45) DEFAULT NULL,
            ativo TINYINT(1) NOT NULL DEFAULT 1,
            intervalo_sync_min INT NOT NULL DEFAULT 5,
            aw_hostname VARCHAR(150) DEFAULT NULL,
            aw_versao VARCHAR(50) DEFAULT NULL,
            ultimo_sync_at DATETIME NULL,
            ultimo_sync_status VARCHAR(20) DEFAULT NULL,
            ultimo_erro VARCHAR(500) DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_maquina_host_porta (host, porta)
        )");

        // Um bucket = um watcher específico em uma máquina (janela ativa, afk, navegador...).
        // ultimo_evento_ts é o cursor de sincronização incremental daquele bucket.
        $db->exec("CREATE TABLE IF NOT EXISTS buckets (
            id INT AUTO_INCREMENT PRIMARY KEY,
            maquina_id INT NOT NULL,
            bucket_id VARCHAR(190) NOT NULL,
            tipo VARCHAR(20) NOT NULL DEFAULT 'other',
            cliente VARCHAR(100) DEFAULT '',
            aw_hostname VARCHAR(150) DEFAULT '',
            criado_em_aw DATETIME NULL,
            ultimo_evento_ts DATETIME(3) NULL,
            ultimo_sync_at DATETIME NULL,
            UNIQUE KEY uq_bucket (maquina_id, bucket_id),
            CONSTRAINT fk_buckets_maquina FOREIGN KEY (maquina_id) REFERENCES maquinas(id) ON DELETE CASCADE
        )");

        $db->exec("CREATE TABLE IF NOT EXISTS categorias (
            id INT AUTO_INCREMENT PRIMARY KEY,
            nome VARCHAR(100) NOT NULL,
            cor VARCHAR(7) NOT NULL DEFAULT '#94a2b8',
            pontuacao TINYINT NOT NULL DEFAULT 0,
            ordem INT NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )");

        // Regras que classificam um evento em uma categoria. Avaliadas em ordem de prioridade
        // (menor primeiro); a primeira que casar decide a categoria do evento — mesmo princípio
        // usado pela categorização nativa do ActivityWatch.
        $db->exec("CREATE TABLE IF NOT EXISTS categoria_regras (
            id INT AUTO_INCREMENT PRIMARY KEY,
            categoria_id INT NOT NULL,
            campo VARCHAR(10) NOT NULL DEFAULT 'app',
            tipo VARCHAR(10) NOT NULL DEFAULT 'contem',
            padrao VARCHAR(300) NOT NULL,
            prioridade INT NOT NULL DEFAULT 50,
            ativo TINYINT(1) NOT NULL DEFAULT 1,
            CONSTRAINT fk_regras_categoria FOREIGN KEY (categoria_id) REFERENCES categorias(id) ON DELETE CASCADE
        )");

        // Eventos brutos coletados via REST API do aw-server. tipo é copiado do bucket (window
        // /afk/web/other) para evitar JOIN em toda consulta de relatório/dashboard. A chave única
        // (bucket_id, ts) permite reprocessar uma janela de tempo sem duplicar (INSERT ... ON
        // DUPLICATE KEY UPDATE), o que também resolve o evento "em andamento" do ActivityWatch,
        // cuja duração cresce a cada sincronização até o usuário trocar de janela/aba.
        $db->exec("CREATE TABLE IF NOT EXISTS eventos (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            bucket_id INT NOT NULL,
            maquina_id INT NOT NULL,
            tipo VARCHAR(20) NOT NULL,
            ts DATETIME(3) NOT NULL,
            duracao DECIMAL(14,3) NOT NULL DEFAULT 0,
            app VARCHAR(255) DEFAULT NULL,
            titulo VARCHAR(500) DEFAULT NULL,
            url VARCHAR(1000) DEFAULT NULL,
            status VARCHAR(20) DEFAULT NULL,
            dados JSON DEFAULT NULL,
            categoria_id INT DEFAULT NULL,
            UNIQUE KEY uq_evento (bucket_id, ts),
            INDEX idx_maquina_tipo_ts (maquina_id, tipo, ts),
            INDEX idx_app (app),
            INDEX idx_categoria (categoria_id),
            CONSTRAINT fk_eventos_bucket FOREIGN KEY (bucket_id) REFERENCES buckets(id) ON DELETE CASCADE,
            CONSTRAINT fk_eventos_maquina FOREIGN KEY (maquina_id) REFERENCES maquinas(id) ON DELETE CASCADE,
            CONSTRAINT fk_eventos_categoria FOREIGN KEY (categoria_id) REFERENCES categorias(id) ON DELETE SET NULL
        )");

        // Histórico de execuções da sincronização (manual ou via sync_runner.php agendado) — usado
        // na tela de Logs para diagnosticar máquina offline, aw-server fechado, firewall etc.
        $db->exec("CREATE TABLE IF NOT EXISTS sincronizacoes (
            id INT AUTO_INCREMENT PRIMARY KEY,
            maquina_id INT NOT NULL,
            iniciado_em DATETIME NOT NULL,
            finalizado_em DATETIME NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'executando',
            eventos_novos INT NOT NULL DEFAULT 0,
            mensagem VARCHAR(500) DEFAULT '',
            origem VARCHAR(20) NOT NULL DEFAULT 'manual',
            INDEX idx_sync_maquina (maquina_id, iniciado_em)
        )");

        // Migração leve: instalações que já tinham local_users/maquinas antes da integração com AD.
        // origem/ad_dn marcam o que veio de uma importação do Active Directory (Integração > AD),
        // para diferenciar de cadastros manuais e evitar reimportar o mesmo objeto duas vezes.
        $temOrigemUsuario = (bool)$db->query("SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'local_users' AND COLUMN_NAME = 'origem'")->fetchColumn();
        if (!$temOrigemUsuario) {
            $db->exec("ALTER TABLE local_users
                ADD COLUMN origem VARCHAR(10) NOT NULL DEFAULT 'local' AFTER role,
                ADD COLUMN ad_dn VARCHAR(400) DEFAULT NULL AFTER origem");
        }
        $temAdDnMaquina = (bool)$db->query("SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'maquinas' AND COLUMN_NAME = 'ad_dn'")->fetchColumn();
        if (!$temAdDnMaquina) {
            $db->exec("ALTER TABLE maquinas ADD COLUMN ad_dn VARCHAR(400) DEFAULT NULL AFTER usuario_responsavel");
        }

        // Setor/departamento da máquina — usado nas visões "Por Setor" e "Máquinas do Setor" do
        // Dashboard. Texto livre (não uma tabela à parte) para não exigir uma tela de cadastro só
        // pra isso; a importação por OU (Integração > Active Directory) já preenche automaticamente
        // com o nome da OU, então o texto tende a ficar consistente sem precisar de CRUD dedicado.
        $temSetorMaquina = (bool)$db->query("SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'maquinas' AND COLUMN_NAME = 'setor'")->fetchColumn();
        if (!$temSetorMaquina) {
            $db->exec("ALTER TABLE maquinas ADD COLUMN setor VARCHAR(150) NOT NULL DEFAULT '' AFTER usuario_responsavel, ADD INDEX idx_maquinas_setor (setor)");
        }

        // Conexão com o Active Directory — linha única (id=1), editável em Integração > Active
        // Directory. bind_password fica cifrada com adEncrypt()/adDecrypt() (ver config.php).
        $db->exec("CREATE TABLE IF NOT EXISTS ad_config (
            id INT AUTO_INCREMENT PRIMARY KEY,
            ldap_server VARCHAR(255) NOT NULL DEFAULT '',
            ldap_basedn VARCHAR(255) NOT NULL DEFAULT '',
            bind_username VARCHAR(255) NOT NULL DEFAULT '',
            bind_password VARCHAR(255) NOT NULL DEFAULT ''
        )");
        $temAdConfig = (int)$db->query("SELECT COUNT(*) FROM ad_config")->fetchColumn();
        if ($temAdConfig === 0) {
            // Servidor/base DN padrão já validados na integração LDAP do Mikrotik Manager desta
            // mesma instituição — o admin só precisa informar a conta de serviço e testar.
            $db->exec("INSERT INTO ad_config (ldap_server, ldap_basedn, bind_username, bind_password)
                VALUES ('ldap://faesa.br', 'DC=faesa,DC=br', '', '')");
        }

        // Grupos do AD cujos membros podem ser importados como usuários deste sistema (aba
        // Usuários > Active Directory). role_padrao é sugerida no momento da importação.
        $db->exec("CREATE TABLE IF NOT EXISTS ad_grupos (
            id INT AUTO_INCREMENT PRIMARY KEY,
            nome VARCHAR(150) NOT NULL,
            group_dn VARCHAR(400) NOT NULL,
            role_padrao VARCHAR(20) NOT NULL DEFAULT 'usuario',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )");

        // Unidades Organizacionais do AD cujos computadores podem ser importados como máquinas
        // monitoradas (aba Máquinas > Active Directory).
        $db->exec("CREATE TABLE IF NOT EXISTS ad_ous (
            id INT AUTO_INCREMENT PRIMARY KEY,
            nome VARCHAR(150) NOT NULL,
            ou_dn VARCHAR(400) NOT NULL,
            porta_padrao INT NOT NULL DEFAULT 5600,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )");

        // Vínculo (não só cópia de texto) entre a máquina e a OU do AD usada para importá-la — o
        // setor exibido em Máquinas/Dashboard passa a acompanhar o nome ATUAL da OU (Integração >
        // Active Directory), então renomear a OU ali atualiza o setor de todas as máquinas ligadas
        // a ela, sem precisar reimportar nada. ON DELETE SET NULL: remover o cadastro da OU não
        // apaga máquinas, só desfaz o vínculo (o texto congelado em maquinas.setor vira o fallback).
        $temOuIdMaquina = (bool)$db->query("SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'maquinas' AND COLUMN_NAME = 'ou_id'")->fetchColumn();
        if (!$temOuIdMaquina) {
            $db->exec("ALTER TABLE maquinas
                ADD COLUMN ou_id INT DEFAULT NULL AFTER setor,
                ADD INDEX idx_maquinas_ou (ou_id),
                ADD CONSTRAINT fk_maquinas_ou FOREIGN KEY (ou_id) REFERENCES ad_ous(id) ON DELETE SET NULL");
        }

        // Configuração da instalação remota do MSI (linha única, id=1) — Máquinas > Instalação
        // Remota. admin_senha fica cifrada com installEncrypt()/installDecrypt().
        $db->exec("CREATE TABLE IF NOT EXISTS instalacao_remota_config (
            id INT AUTO_INCREMENT PRIMARY KEY,
            admin_usuario VARCHAR(255) NOT NULL DEFAULT '',
            admin_senha VARCHAR(255) NOT NULL DEFAULT '',
            msi_path VARCHAR(500) NOT NULL DEFAULT '',
            timeout_segundos INT NOT NULL DEFAULT 300
        )");
        $temInstalacaoConfig = (int)$db->query("SELECT COUNT(*) FROM instalacao_remota_config")->fetchColumn();
        if ($temInstalacaoConfig === 0) {
            $db->exec("INSERT INTO instalacao_remota_config (admin_usuario, admin_senha, msi_path, timeout_segundos)
                VALUES ('', '', " . $db->quote(INSTALL_MSI_PATH_PADRAO) . ", " . INSTALL_TIMEOUT_PADRAO . ")");
        }

        // Histórico/estado de cada tentativa de instalação remota do MSI numa máquina — usado para
        // o admin acompanhar o progresso (fila/executando/ok/erro) e ver o log do msiexec depois.
        $db->exec("CREATE TABLE IF NOT EXISTS instalacoes_remotas (
            id INT AUTO_INCREMENT PRIMARY KEY,
            maquina_id INT NOT NULL,
            acao VARCHAR(20) NOT NULL DEFAULT 'instalar',
            iniciado_em DATETIME NOT NULL,
            finalizado_em DATETIME NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'fila',
            mensagem VARCHAR(500) DEFAULT '',
            log MEDIUMTEXT,
            INDEX idx_instalacao_maquina (maquina_id, iniciado_em),
            CONSTRAINT fk_instalacoes_maquina FOREIGN KEY (maquina_id) REFERENCES maquinas(id) ON DELETE CASCADE
        )");
        // acao diferencia instalar/atualizar (mesmo fluxo: rodar o MSI atual de novo — o
        // MajorUpgrade do proprio pacote troca a versao instalada) de desinstalar (remove de
        // verdade via msiexec /x). Migração leve para quem já tinha a tabela sem essa coluna.
        $temAcaoInstalacao = (bool)$db->query("SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'instalacoes_remotas' AND COLUMN_NAME = 'acao'")->fetchColumn();
        if (!$temAcaoInstalacao) {
            $db->exec("ALTER TABLE instalacoes_remotas ADD COLUMN acao VARCHAR(20) NOT NULL DEFAULT 'instalar' AFTER maquina_id");
        }

        // IP reportado pelo próprio agente (aw-watcher-currentuser), não pelo DNS do "host"
        // cadastrado — ver atualizarUsuarioResponsavelDetectado() em aw_client.php, que preenche
        // esta coluna junto com usuario_responsavel a partir do mesmo evento tipo 'usuario'.
        $temIpLocal = (bool)$db->query("SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'maquinas' AND COLUMN_NAME = 'ip_local'")->fetchColumn();
        if (!$temIpLocal) {
            $db->exec("ALTER TABLE maquinas ADD COLUMN ip_local VARCHAR(45) DEFAULT NULL AFTER usuario_responsavel");
        }

        // Usuário do Windows detectado (aw-watcher-currentuser) responsável por CADA evento de
        // window/web — pré-calculado no momento da sincronização (ver atualizarUsuarioDetectadoEventos()
        // em aw_client.php), não em tempo de consulta: cruzar os eventos com as sessões de usuário
        // (heartbeats tipo 'usuario') via JOIN de intervalo a cada carregamento do Dashboard levou a
        // consulta a 7-10s numa base com só ~50 mil eventos (o otimizador do MariaDB 10.4 não usa o
        // índice pra esse tipo de junção por faixa correlacionada). Gravar o resultado direto na
        // linha, como já é feito com categoria_id, deixa a leitura da aba Usuários tão rápida quanto
        // as demais abas do Dashboard.
        $temUsuarioDetectado = (bool)$db->query("SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'eventos' AND COLUMN_NAME = 'usuario_detectado'")->fetchColumn();
        if (!$temUsuarioDetectado) {
            $db->exec("ALTER TABLE eventos
                ADD COLUMN usuario_detectado VARCHAR(150) DEFAULT NULL AFTER categoria_id,
                ADD INDEX idx_usuario_detectado (usuario_detectado)");
        }

        // Categorias e regras padrão — só na primeira instalação (tabela vazia). O admin edita
        // livremente depois; isso é só um ponto de partida razoável.
        $temCategorias = (int)$db->query("SELECT COUNT(*) FROM categorias")->fetchColumn();
        if ($temCategorias === 0) {
            $catStmt = $db->prepare("INSERT INTO categorias (nome, cor, pontuacao, ordem) VALUES (:n, :c, :p, :o)");
            $regraStmt = $db->prepare("INSERT INTO categoria_regras (categoria_id, campo, tipo, padrao, prioridade) VALUES (:cat, :campo, :tipo, :padrao, :prio)");

            $seed = [
                ['Produtivo · Desenvolvimento', '#16a34a', 1, 10, [
                    ['app', 'contem', 'Code'], ['app', 'contem', 'PhpStorm'], ['app', 'contem', 'IntelliJ'],
                    ['app', 'contem', 'Visual Studio'], ['app', 'contem', 'Sublime'], ['app', 'contem', 'Notepad++'],
                    ['app', 'contem', 'DataGrip'], ['app', 'contem', 'Terminal'], ['app', 'contem', 'powershell'],
                    ['app', 'contem', 'cmd.exe'], ['app', 'contem', 'MySQL Workbench'], ['url', 'contem', 'github.com'],
                    ['url', 'contem', 'stackoverflow.com'], ['url', 'contem', 'localhost'],
                ]],
                // Ferramentas de TI/suporte (RDP, PuTTY, GLPI, VMware, Mikrotik etc.) — separada de
                // Escritório (Word/Excel) e Desenvolvimento (código) pra manter a distribuição por
                // categoria fiel ao trabalho real de quem faz suporte/infraestrutura.
                ['Produtivo · TI/Suporte', '#7c3aed', 1, 15, [
                    ['app', 'contem', 'mstsc'], ['app', 'contem', 'webphone'], ['app', 'contem', 'Notepad.exe'],
                    ['app', 'contem', 'putty'], ['app', 'contem', 'Taskmgr'],
                    ['titulo', 'contem', 'Calculadora'], ['titulo', 'contem', 'Configurações'],
                    ['titulo', 'contem', 'GLPI'], ['titulo', 'contem', 'Chamado'],
                    ['titulo', 'contem', 'idpaesxi'], ['titulo', 'contem', 'idpavcsa'], ['titulo', 'contem', 'VMware ESXi'],
                    ['titulo', 'contem', 'MonitCall'], ['titulo', 'contem', 'Intranet Faesa'], ['titulo', 'contem', 'Ramais'],
                    ['titulo', 'contem', 'Mikrotik'], ['titulo', 'contem', 'ActivityWatch'],
                    ['titulo', 'contem', 'Publico – Explorador de Arquivos'], ['titulo', 'contem', 'NTI – Explorador de Arquivos'],
                ]],
                ['Produtivo · Escritório', '#178ec8', 1, 20, [
                    ['app', 'contem', 'WINWORD'], ['app', 'contem', 'EXCEL'], ['app', 'contem', 'POWERPNT'],
                    ['app', 'contem', 'Outlook'], ['app', 'contem', 'Teams'], ['app', 'contem', 'OneNote'],
                    ['app', 'contem', 'Acrobat'], ['app', 'contem', 'AcroRd32'],
                    ['titulo', 'contem', 'Adobe Acrobat'], ['titulo', 'contem', 'Acrobat Reader'], ['titulo', 'contem', '.pdf'], ['titulo', 'contem', 'Acrobat'],
                    ['titulo', 'regex', '\\.(docx?|xlsx?|xlsm|xlsb|pptx?|pps[xm]?|pdf)(\\s|$)'],
                    ['url', 'contem', 'microsoft365.com'], ['url', 'contem', 'office365.com'], ['url', 'contem', 'officeapps.live.com'],
                    ['url', 'contem', 'onedrive.live.com'], ['url', 'contem', '1drv.ms'], ['url', 'contem', 'outlook.office'],
                    ['url', 'contem', 'acrobat.adobe.com'], ['url', 'contem', 'documentcloud.adobe.com'], ['url', 'contem', 'adobe.com/acrobat'],
                    ['url', 'regex', '^(blob:)?[a-z]+://([^/?#]*\\.)?faesa\\.br([:/?#]|$)'],
                    ['url', 'regex', '^(blob:)?[a-z]+://([^/?#]*\\.)?microsoftonline\\.com([:/?#]|$)'],
                    ['url', 'regex', '^(blob:)?[a-z]+://([^/?#]*\\.)?worka\\.love([:/?#]|$)'],
                    ['url', 'regex', '^(blob:)?[a-z]+://([^/?#]*\\.)?lyceum\\.com\\.br([:/?#]|$)'],
                    ['url', 'regex', '^(blob:)?[a-z]+://([^/?#]*\\.)?powerapps\\.com([:/?#]|$)'],
                    ['url', 'regex', '^(blob:)?[a-z]+://([^/?#]*\\.)?clicksign\\.com([:/?#]|$)'],
                    ['url', 'regex', '^(blob:)?[a-z]+://([^/?#]*\\.)?ilovepdf\\.com([:/?#]|$)'],
                    ['url', 'contem', 'office.com'], ['url', 'contem', 'sharepoint.com'],
                    // Regras por título: sem a extensão de navegador do ActivityWatch instalada, o
                    // campo url nunca vem preenchido — abas de Office/Google Workspace só dão pra
                    // identificar pelo título da janela do navegador (ex.: "Relatório - Word").
                    // \b...\b evita falso positivo tipo "password" casando com "word".
                    ['titulo', 'regex', '\\bWord\\b'], ['titulo', 'regex', '\\bExcel\\b'], ['titulo', 'regex', '\\bPowerPoint\\b'],
                    ['titulo', 'contem', 'OneDrive'], ['titulo', 'contem', 'SharePoint'],
                    ['titulo', 'contem', 'Google Docs'], ['titulo', 'contem', 'Google Sheets'], ['titulo', 'contem', 'Google Slides'],
                    ['titulo', 'contem', 'Planilhas Google'], ['titulo', 'contem', 'Documentos Google'], ['titulo', 'contem', 'Apresentações Google'],
                    ['titulo', 'contem', 'Microsoft Teams'], ['titulo', 'contem', 'Outlook'],
                ]],
                ['Improdutivo · Entretenimento', '#dc2626', -1, 30, [
                    ['url', 'contem', 'youtube.com'], ['url', 'contem', 'netflix.com'], ['url', 'contem', 'twitch.tv'],
                    ['url', 'contem', 'facebook.com'], ['url', 'contem', 'instagram.com'], ['url', 'contem', 'tiktok.com'],
                    ['url', 'contem', 'twitter.com'], ['url', 'contem', 'x.com'], ['app', 'contem', 'Steam'],
                    ['app', 'contem', 'Spotify'], ['app', 'contem', 'WhatsApp'],
                    // Mesmo motivo acima: mesmas redes sociais/streaming, mas casando pelo título da
                    // aba do navegador em vez da URL (que não existe sem o watcher de navegador).
                    ['titulo', 'contem', 'YouTube'], ['titulo', 'contem', 'Instagram'], ['titulo', 'contem', 'Netflix'],
                    ['titulo', 'contem', 'Twitch'], ['titulo', 'contem', 'Facebook'], ['titulo', 'contem', 'TikTok'],
                    ['titulo', 'contem', 'Twitter'], ['titulo', 'contem', 'Spotify'], ['titulo', 'contem', 'WhatsApp'],
                    ['titulo', 'contem', 'Steam'],
                ]],
                ['Neutro · Navegação', '#94a2b8', 0, 80, [
                    ['app', 'contem', 'Chrome'], ['app', 'contem', 'Firefox'], ['app', 'contem', 'Edge'], ['app', 'contem', 'Msedge'],
                ]],
                ['Neutro · Sistema', '#5c6b80', 0, 90, [
                    ['app', 'contem', 'explorer'], ['app', 'contem', 'Finder'], ['app', 'contem', 'LockApp'],
                ]],
            ];

            foreach ($seed as [$nome, $cor, $pontuacao, $ordem, $regras]) {
                $catStmt->execute([':n' => $nome, ':c' => $cor, ':p' => $pontuacao, ':o' => $ordem]);
                $catId = (int)$db->lastInsertId();
                $prio = $ordem;
                foreach ($regras as [$campo, $tipo, $padrao]) {
                    $regraStmt->execute([':cat' => $catId, ':campo' => $campo, ':tipo' => $tipo, ':padrao' => $padrao, ':prio' => $prio]);
                }
            }
        }

        // Regras de domínio por URL passam a aceitar o prefixo "blob:" (URLs como
        // blob:https://workability.worka.love/<uuid> não casavam com o padrão antigo). Converte as
        // regras já gravadas — só as que ainda começam com o padrão antigo.
        $db->exec("UPDATE categoria_regras SET padrao = CONCAT('^(blob:)?', SUBSTRING(padrao, 2))
                    WHERE campo = 'url' AND tipo = 'regex' AND padrao LIKE '^[a-z]+://%'");
        // Acrobat/PDF na categoria "Produtivo · Escritório" (junto de Word/Excel/PowerPoint) — vale
        // pra instalações já existentes, onde a semeadura acima não roda mais. Idempotente: só insere
        // a regra se ainda não existir nessa categoria.
        $catEscritorio = $db->query("SELECT id FROM categorias WHERE nome = 'Produtivo · Escritório' LIMIT 1")->fetchColumn();
        if ($catEscritorio) {
            $existeRegra = $db->prepare("SELECT 1 FROM categoria_regras WHERE categoria_id = :cat AND campo = :campo AND tipo = :tipo AND padrao = :padrao LIMIT 1");
            $novaRegra = $db->prepare("INSERT INTO categoria_regras (categoria_id, campo, tipo, padrao, prioridade) VALUES (:cat, :campo, :tipo, :padrao, 20)");
            foreach ([['app', 'contem', 'Acrobat'], ['app', 'contem', 'AcroRd32'],
                      ['titulo', 'contem', 'Adobe Acrobat'], ['titulo', 'contem', 'Acrobat Reader'], ['titulo', 'contem', '.pdf'],
                      ['titulo', 'contem', 'Acrobat'],
                      ['titulo', 'regex', '\\.(docx?|xlsx?|xlsm|xlsb|pptx?|pps[xm]?|pdf)(\\s|$)'],
                      ['url', 'contem', 'microsoft365.com'], ['url', 'contem', 'office365.com'], ['url', 'contem', 'officeapps.live.com'],
                      ['url', 'contem', 'onedrive.live.com'], ['url', 'contem', '1drv.ms'], ['url', 'contem', 'outlook.office'],
                      ['url', 'contem', 'acrobat.adobe.com'], ['url', 'contem', 'documentcloud.adobe.com'], ['url', 'contem', 'adobe.com/acrobat'],
                    ['url', 'regex', '^(blob:)?[a-z]+://([^/?#]*\\.)?faesa\\.br([:/?#]|$)'],
                    ['url', 'regex', '^(blob:)?[a-z]+://([^/?#]*\\.)?microsoftonline\\.com([:/?#]|$)'],
                    ['url', 'regex', '^(blob:)?[a-z]+://([^/?#]*\\.)?worka\\.love([:/?#]|$)'],
                    ['url', 'regex', '^(blob:)?[a-z]+://([^/?#]*\\.)?lyceum\\.com\\.br([:/?#]|$)'],
                    ['url', 'regex', '^(blob:)?[a-z]+://([^/?#]*\\.)?powerapps\\.com([:/?#]|$)'],
                    ['url', 'regex', '^(blob:)?[a-z]+://([^/?#]*\\.)?clicksign\\.com([:/?#]|$)'],
                    ['url', 'regex', '^(blob:)?[a-z]+://([^/?#]*\\.)?ilovepdf\\.com([:/?#]|$)']] as [$campo, $tipo, $padrao]) {
                $p = [':cat' => $catEscritorio, ':campo' => $campo, ':tipo' => $tipo, ':padrao' => $padrao];
                $existeRegra->execute($p);
                if (!$existeRegra->fetchColumn()) $novaRegra->execute($p);
            }
        }

        // Redes sociais e streaming (por URL) na categoria "Improdutivo · Entretenimento". Cada regra
        // casa quando o HOST da URL é o domínio ou um subdomínio dele (não casa o texto no meio de um
        // caminho/consulta). Idempotente: só insere a regra se ainda não existir nessa categoria.
        $catEntret = $db->query("SELECT id FROM categorias WHERE nome = 'Improdutivo · Entretenimento' LIMIT 1")->fetchColumn();
        if ($catEntret) {
            $dominiosEntret = [
                // redes sociais e mensageiros de uso pessoal
                'facebook.com', 'instagram.com', 'tiktok.com', 'twitter.com', 'x.com', 'threads.net', 'pinterest.com',
                'reddit.com', 'snapchat.com', 'tumblr.com', 'kwai.com', 'discord.com', 'web.whatsapp.com', 'web.telegram.org',
                // streaming de vídeo, música e transmissões ao vivo
                'youtube.com', 'youtu.be', 'netflix.com', 'primevideo.com', 'disneyplus.com', 'hbomax.com', 'max.com',
                'globoplay.globo.com', 'paramountplus.com', 'crunchyroll.com', 'tv.apple.com', 'pluto.tv',
                'twitch.tv', 'kick.com', 'spotify.com', 'deezer.com', 'soundcloud.com',
                // portais e sites de notícias (nacionais, internacionais e do Espírito Santo)
                'g1.globo.com', 'ge.globo.com', 'oglobo.globo.com', 'extra.globo.com', 'uol.com.br', 'terra.com.br',
                'r7.com', 'cnnbrasil.com.br', 'estadao.com.br', 'metropoles.com', 'ig.com.br', 'msn.com',
                'correiobraziliense.com.br', 'gazetadopovo.com.br', 'oantagonista.com.br', 'brasil247.com', 'poder360.com.br',
                'veja.abril.com.br', 'istoe.com.br', 'cartacapital.com.br', 'lance.com.br',
                'agazeta.com.br', 'folhavitoria.com.br', 'tribunaonline.com.br', 'eshoje.com.br', 'seculodiario.com.br',
                'news.google.com', 'bbc.com', 'cnn.com', 'nytimes.com', 'theguardian.com', 'reuters.com', 'elpais.com',
            ];
            $existeRegraEnt = $db->prepare("SELECT 1 FROM categoria_regras WHERE categoria_id = :cat AND campo = 'url' AND tipo = 'regex' AND padrao = :padrao LIMIT 1");
            $novaRegraEnt = $db->prepare("INSERT INTO categoria_regras (categoria_id, campo, tipo, padrao, prioridade) VALUES (:cat, 'url', 'regex', :padrao, 30)");
            foreach ($dominiosEntret as $dom) {
                $p = [':cat' => $catEntret, ':padrao' => '^(blob:)?[a-z]+://([^/?#]*\\.)?' . str_replace('.', '\\.', $dom) . '([:/?#]|$)'];
                $existeRegraEnt->execute($p);
                if (!$existeRegraEnt->fetchColumn()) $novaRegraEnt->execute($p);
            }
        }

        // Atualização agendada do agente ("atualizar na próxima vez que a máquina estiver comunicável"):
        // data/hora em que alguém agendou; NULL = sem agendamento. Ver dispararAtualizacaoAgendada()
        // em aw_client.php e ajax_agendar_atualizacao.php.
        $temAtualizarPendente = (bool)$db->query("SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'maquinas' AND COLUMN_NAME = 'atualizar_pendente_em'")->fetchColumn();
        if (!$temAtualizarPendente) {
            $db->exec("ALTER TABLE maquinas ADD COLUMN atualizar_pendente_em DATETIME DEFAULT NULL");
        }
        // Limpeza de regras (auditoria de categorias). Idempotente — cada passo só age se ainda houver o que corrigir.
        $catEntretL = $db->query("SELECT id FROM categorias WHERE nome = 'Improdutivo · Entretenimento' LIMIT 1")->fetchColumn();
        $catEscritorioL = $db->query("SELECT id FROM categorias WHERE nome = 'Produtivo · Escritório' LIMIT 1")->fetchColumn();
        $catTiL = $db->query("SELECT id FROM categorias WHERE nome = 'Produtivo · TI/Suporte' LIMIT 1")->fetchColumn();
        $catSistemaL = $db->query("SELECT id FROM categorias WHERE nome = 'Neutro · Sistema' LIMIT 1")->fetchColumn();
        // 1) Regras "contém" de URL redundantes: cada uma já tem a regra de domínio (por host) correspondente na
        //    mesma categoria, e "contém" casa o texto em qualquer lugar da URL (ex.: "x.com" pegava dropbox.com).
        if ($catEntretL) {
            $db->prepare("DELETE FROM categoria_regras WHERE categoria_id = :c AND campo = 'url' AND tipo = 'contem'
                            AND padrao IN ('youtube.com', 'netflix.com', 'twitch.tv', 'facebook.com', 'instagram.com', 'tiktok.com', 'twitter.com', 'x.com')")
               ->execute([':c' => $catEntretL]);
        }
        // 2) "Acrobat" (aplicativo) em Neutro · Sistema nunca vencia: a mesma regra em Escritório vem antes.
        if ($catSistemaL) {
            $db->prepare("DELETE FROM categoria_regras WHERE categoria_id = :c AND campo = 'app' AND tipo = 'contem' AND padrao = 'Acrobat'")
               ->execute([':c' => $catSistemaL]);
        }
        // 3) Outlook novo (olk.exe) estava em TI/Suporte e vencia a regra de título "Outlook" de Escritório;
        //    passa para Escritório e deixa de ser um trecho curto ("olk") que casava qualquer app com essas letras.
        if ($catTiL && $catEscritorioL) {
            $jaExiste = $db->prepare("SELECT COUNT(*) FROM categoria_regras WHERE categoria_id = :c AND campo = 'app' AND tipo = 'contem' AND padrao = 'olk.exe'");
            $jaExiste->execute([':c' => $catEscritorioL]);
            if ((int)$jaExiste->fetchColumn() > 0) {
                $db->prepare("DELETE FROM categoria_regras WHERE categoria_id = :t AND campo = 'app' AND tipo = 'contem' AND padrao = 'olk'")->execute([':t' => $catTiL]);
            } else {
                $db->prepare("UPDATE categoria_regras SET categoria_id = :e, padrao = 'olk.exe', prioridade = 20
                                WHERE categoria_id = :t AND campo = 'app' AND tipo = 'contem' AND padrao = 'olk'")
                   ->execute([':e' => $catEscritorioL, ':t' => $catTiL]);
            }
        }

        // Título "iLovePDF" em Produtivo · Escritório: rede de segurança pra janelas de navegador sem aba
        // correspondente (máquina sem a extensão, ou janela sem evento de aba) — o título da janela carrega o
        // nome do site ("Baixar o arquivo | iLovePDF - Google Chrome").
        if ($catEscritorioL) {
            $jaTemTitulo = $db->prepare("SELECT COUNT(*) FROM categoria_regras WHERE categoria_id = :c AND campo = 'titulo' AND tipo = 'contem' AND padrao = 'iLovePDF'");
            $jaTemTitulo->execute([':c' => $catEscritorioL]);
            if ((int)$jaTemTitulo->fetchColumn() === 0) {
                $db->prepare("INSERT INTO categoria_regras (categoria_id, campo, tipo, padrao, prioridade) VALUES (:c, 'titulo', 'contem', 'iLovePDF', 20)")
                   ->execute([':c' => $catEscritorioL]);
            }
        }

        // Consolidação em 3 categorias: Produtivo (prioridade 10), Improdutivo (50) e Neutro (90). Une as
        // categorias por tipo — Escritório + Desenvolvimento + TI/Suporte -> Produtivo; Entretenimento ->
        // Improdutivo; Navegação + Sistema -> Neutro — movendo regras (todas passam a ter a prioridade do
        // grupo) e eventos para a categoria de destino e apagando as antigas. Idempotente: depois de rodar,
        // as categorias de origem não existem mais e nada é refeito. As migrações acima que procuram as
        // categorias antigas pelo nome simplesmente não as encontram mais e não fazem nada.
        $gruposConsolidar = [
            ['Produtivo',   '#16a34a',  1, 10, 10, ['Produtivo · Escritório', 'Produtivo · Desenvolvimento', 'Produtivo · TI/Suporte']],
            ['Improdutivo', '#dc2626', -1, 50, 50, ['Improdutivo · Entretenimento']],
            ['Neutro',      '#94a2b8',  0, 90, 90, ['Neutro · Navegação', 'Neutro · Sistema']],
        ];
        $buscaCat = $db->prepare("SELECT id FROM categorias WHERE nome = :n LIMIT 1");
        $moveEventos = $db->prepare("UPDATE eventos SET categoria_id = :alvo WHERE categoria_id = :orig");
        $moveRegras = $db->prepare("UPDATE categoria_regras SET categoria_id = :alvo WHERE categoria_id = :orig");
        $apagaCat = $db->prepare("DELETE FROM categorias WHERE id = :id");
        foreach ($gruposConsolidar as [$alvoNome, $alvoCor, $alvoPont, $alvoOrdem, $alvoPrio, $origensNomes]) {
            $buscaCat->execute([':n' => $alvoNome]);
            $alvoId = $buscaCat->fetchColumn();
            $origensIds = [];
            foreach ($origensNomes as $nomeOrigem) {
                $buscaCat->execute([':n' => $nomeOrigem]);
                $idOrigem = $buscaCat->fetchColumn();
                if ($idOrigem) $origensIds[] = (int)$idOrigem;
            }
            if (!$alvoId && !$origensIds) continue; // nada a consolidar neste grupo
            if (!$alvoId) $alvoId = array_shift($origensIds); // reaproveita a primeira origem como destino (mantém o id dos eventos dela)
            $alvoId = (int)$alvoId;
            $db->prepare("UPDATE categorias SET nome = :n, cor = :c, pontuacao = :p, ordem = :o WHERE id = :id")
               ->execute([':n' => $alvoNome, ':c' => $alvoCor, ':p' => $alvoPont, ':o' => $alvoOrdem, ':id' => $alvoId]);
            foreach ($origensIds as $idOrigem) {
                if ($idOrigem === $alvoId) continue;
                $moveEventos->execute([':alvo' => $alvoId, ':orig' => $idOrigem]);
                $moveRegras->execute([':alvo' => $alvoId, ':orig' => $idOrigem]);
                $apagaCat->execute([':id' => $idOrigem]);
            }
            $db->prepare("UPDATE categoria_regras SET prioridade = :p WHERE categoria_id = :c")->execute([':p' => $alvoPrio, ':c' => $alvoId]);
        }
        // Regras repetidas que a união pode ter criado (mesmo campo, tipo e padrão na mesma categoria).
        $db->exec("DELETE r1 FROM categoria_regras r1
                    JOIN categoria_regras r2 ON r2.categoria_id = r1.categoria_id AND r2.campo = r1.campo AND r2.tipo = r1.tipo
                                            AND r2.padrao = r1.padrao AND r2.id < r1.id");

        // Domínios de trabalho na categoria "Produtivo" (por URL). Cada regra casa quando o HOST da URL é o
        // domínio ou um subdomínio dele (aceita o prefixo "blob:"). Idempotente: só insere o que ainda não existe.
        $catProdutivoD = $db->query("SELECT id FROM categorias WHERE nome = 'Produtivo' LIMIT 1")->fetchColumn();
        if ($catProdutivoD) {
            $existeRegraProd = $db->prepare("SELECT 1 FROM categoria_regras WHERE categoria_id = :cat AND campo = 'url' AND tipo = 'regex' AND padrao = :padrao LIMIT 1");
            $novaRegraProd = $db->prepare("INSERT INTO categoria_regras (categoria_id, campo, tipo, padrao, prioridade) VALUES (:cat, 'url', 'regex', :padrao, 10)");
            foreach (['crmeducacional.com', 'techcert.com.br', 'cloud.microsoft', 'static.microsoft'] as $dominioProd) {
                $pProd = [':cat' => $catProdutivoD, ':padrao' => '^(blob:)?[a-z]+://([^/?#]*\\.)?' . str_replace('.', '\\.', $dominioProd) . '([:/?#]|$)'];
                $existeRegraProd->execute($pProd);
                if (!$existeRegraProd->fetchColumn()) $novaRegraProd->execute($pProd);
            }
        }

        // Aplicativos de design na categoria "Produtivo" (por aplicativo da janela). Idempotente.
        $catProdutivoA = $db->query("SELECT id FROM categorias WHERE nome = 'Produtivo' LIMIT 1")->fetchColumn();
        if ($catProdutivoA) {
            $existeAppProd = $db->prepare("SELECT 1 FROM categoria_regras WHERE categoria_id = :cat AND campo = 'app' AND tipo = 'contem' AND padrao = :padrao LIMIT 1");
            $novaAppProd = $db->prepare("INSERT INTO categoria_regras (categoria_id, campo, tipo, padrao, prioridade) VALUES (:cat, 'app', 'contem', :padrao, 10)");
            foreach (['Photoshop.exe', 'Illustrator.exe'] as $appProd) {
                $existeAppProd->execute([':cat' => $catProdutivoA, ':padrao' => $appProd]);
                if (!$existeAppProd->fetchColumn()) $novaAppProd->execute([':cat' => $catProdutivoA, ':padrao' => $appProd]);
            }
        }

        // Aplicativos web do Google Workspace e da Microsoft 365/Azure na categoria "Produtivo" (por URL). São
        // hosts ESPECÍFICOS de cada aplicativo (docs.google.com, teams.microsoft.com, ...) e não os domínios
        // inteiros — google.com e microsoft.com também servem busca, publicidade e notícias. Cada regra casa o
        // host exato ou um subdomínio dele (aceita "blob:"). Idempotente: só insere o que ainda não existe.
        $catProdutivoG = $db->query("SELECT id FROM categorias WHERE nome = 'Produtivo' LIMIT 1")->fetchColumn();
        if ($catProdutivoG) {
            $dominiosGoogleMs = [
                // Google Workspace
                'docs.google.com', 'drive.google.com', 'sheets.google.com', 'slides.google.com', 'mail.google.com',
                'calendar.google.com', 'meet.google.com', 'chat.google.com', 'forms.google.com', 'sites.google.com',
                'keep.google.com', 'classroom.google.com', 'contacts.google.com', 'groups.google.com', 'script.google.com',
                'admin.google.com', 'lookerstudio.google.com', 'datastudio.google.com', 'analytics.google.com',
                'tagmanager.google.com', 'colab.research.google.com', 'console.cloud.google.com', 'workspace.google.com',
                // Microsoft 365, Power Platform e Azure
                'outlook.office.com', 'outlook.office365.com', 'outlook.live.com', 'teams.microsoft.com', 'teams.live.com',
                'onedrive.live.com', '1drv.ms', 'office.live.com', 'powerbi.com', 'make.powerautomate.com', 'flow.microsoft.com',
                'forms.microsoft.com', 'to-do.microsoft.com', 'onenote.com', 'admin.microsoft.com', 'intune.microsoft.com',
                'entra.microsoft.com', 'security.microsoft.com', 'compliance.microsoft.com', 'learn.microsoft.com',
                'support.microsoft.com', 'azure.com', 'dev.azure.com', 'visualstudio.com',
            ];
            $existeRegraGms = $db->prepare("SELECT 1 FROM categoria_regras WHERE categoria_id = :cat AND campo = 'url' AND tipo = 'regex' AND padrao = :padrao LIMIT 1");
            $novaRegraGms = $db->prepare("INSERT INTO categoria_regras (categoria_id, campo, tipo, padrao, prioridade) VALUES (:cat, 'url', 'regex', :padrao, 10)");
            foreach ($dominiosGoogleMs as $dominioGms) {
                $pGms = [':cat' => $catProdutivoG, ':padrao' => '^(blob:)?[a-z]+://([^/?#]*\\.)?' . str_replace('.', '\\.', $dominioGms) . '([:/?#]|$)'];
                $existeRegraGms->execute($pGms);
                if (!$existeRegraGms->fetchColumn()) $novaRegraGms->execute($pGms);
            }
        }

        // Assistentes de IA (ChatGPT, Claude e Copilot) na categoria "Produtivo": os sites (regras de domínio por
        // host, aceitam "blob:") e os aplicativos de desktop (regras por aplicativo da janela). Idempotente.
        $catProdutivoIA = $db->query("SELECT id FROM categorias WHERE nome = 'Produtivo' LIMIT 1")->fetchColumn();
        if ($catProdutivoIA) {
            $existeRegraIA = $db->prepare("SELECT 1 FROM categoria_regras WHERE categoria_id = :cat AND campo = :campo AND tipo = :tipo AND padrao = :padrao LIMIT 1");
            $novaRegraIA = $db->prepare("INSERT INTO categoria_regras (categoria_id, campo, tipo, padrao, prioridade) VALUES (:cat, :campo, :tipo, :padrao, 10)");
            $regrasIA = [];
            foreach (['chatgpt.com', 'openai.com', 'claude.ai', 'anthropic.com', 'copilot.microsoft.com', 'contraktor.com.br', 'gov.br'] as $dominioIA) {
                $regrasIA[] = ['url', 'regex', '^(blob:)?[a-z]+://([^/?#]*\\.)?' . str_replace('.', '\\.', $dominioIA) . '([:/?#]|$)'];
            }
            foreach (['ChatGPT.exe', 'Claude.exe', 'Copilot'] as $appIA) $regrasIA[] = ['app', 'contem', $appIA];
            foreach ($regrasIA as [$campoIA, $tipoIA, $padraoIA]) {
                $pIA = [':cat' => $catProdutivoIA, ':campo' => $campoIA, ':tipo' => $tipoIA, ':padrao' => $padraoIA];
                $existeRegraIA->execute($pIA);
                if (!$existeRegraIA->fetchColumn()) $novaRegraIA->execute($pIA);
            }
        }

        // WhatsApp passa de Improdutivo para Neutro: move para "Neutro" (prioridade 90) as regras de WhatsApp que
        // estavam em "Improdutivo" (aplicativo, título e o domínio web.whatsapp.com) e remove repetições que a
        // mudança possa criar. Idempotente: depois de rodar, não resta regra de WhatsApp em Improdutivo.
        $catImprodutivoW = $db->query("SELECT id FROM categorias WHERE nome = 'Improdutivo' LIMIT 1")->fetchColumn();
        $catNeutroW = $db->query("SELECT id FROM categorias WHERE nome = 'Neutro' LIMIT 1")->fetchColumn();
        if ($catImprodutivoW && $catNeutroW) {
            $db->prepare("UPDATE categoria_regras SET categoria_id = :neutro, prioridade = 90
                            WHERE categoria_id = :improdutivo AND LOWER(padrao) LIKE '%whatsapp%'")
               ->execute([':neutro' => $catNeutroW, ':improdutivo' => $catImprodutivoW]);
            $db->exec("DELETE r1 FROM categoria_regras r1
                        JOIN categoria_regras r2 ON r2.categoria_id = r1.categoria_id AND r2.campo = r1.campo AND r2.tipo = r1.tipo
                                                AND r2.padrao = r1.padrao AND r2.id < r1.id");
        }

        // rdstation.com.br (RD Station: app.rdstation.com.br, etc.) na categoria "Produtivo" — regra de domínio por host
        // (aceita "blob:"). Idempotente.
        $catProdutivoRD = $db->query("SELECT id FROM categorias WHERE nome = 'Produtivo' LIMIT 1")->fetchColumn();
        if ($catProdutivoRD) {
            $padraoRD = '^(blob:)?[a-z]+://([^/?#]*\\.)?rdstation\\.com\\.br([:/?#]|$)';
            $existeRD = $db->prepare("SELECT 1 FROM categoria_regras WHERE categoria_id = :cat AND campo = 'url' AND tipo = 'regex' AND padrao = :padrao LIMIT 1");
            $existeRD->execute([':cat' => $catProdutivoRD, ':padrao' => $padraoRD]);
            if (!$existeRD->fetchColumn()) {
                $db->prepare("INSERT INTO categoria_regras (categoria_id, campo, tipo, padrao, prioridade) VALUES (:cat, 'url', 'regex', :padrao, 10)")
                   ->execute([':cat' => $catProdutivoRD, ':padrao' => $padraoRD]);
            }
        }

        $db->exec("DELETE FROM schema_migrations");
        $db->exec("INSERT INTO schema_migrations (versao) VALUES (" . SCHEMA_VERSION . ")");
        }

        $cached = $db;
        return $db;
    } catch (PDOException $e) {
        die('Erro ao conectar/preparar o banco de dados: ' . $e->getMessage());
    }
}

// ---- Autenticação ----

function dadosUsuarioAtual() {
    $username = $_SESSION['user_logged_in'] ?? null;
    if (!$username) return null;
    static $cache = null;
    if ($cache !== null && $cache['username'] === $username) {
        return $cache;
    }
    $db = getDB();
    $stmt = $db->prepare("SELECT enabled, role FROM local_users WHERE username = :u");
    $stmt->bindValue(':u', $username);
    $stmt->execute();
    $row = $stmt->fetch();
    $cache = [
        'username' => $username,
        'enabled' => $row ? (int)$row['enabled'] : null,
        'role' => $row ? $row['role'] : 'usuario',
    ];
    return $cache;
}

function checkAuth() {
    if (!isset($_SESSION['user_logged_in'])) {
        header("Location: login.php");
        exit;
    }

    if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity']) > SESSION_IDLE_TIMEOUT) {
        session_unset();
        session_destroy();
        header("Location: login.php?timeout=1");
        exit;
    }

    $dados = dadosUsuarioAtual();
    if ($dados === null || $dados['enabled'] === null || $dados['enabled'] === 0) {
        session_unset();
        session_destroy();
        header("Location: login.php");
        exit;
    }

    $_SESSION['last_activity'] = time();
}

function currentUserRole() {
    $dados = dadosUsuarioAtual();
    return $dados ? $dados['role'] : 'usuario';
}

function isAdmin() {
    return currentUserRole() === 'admin';
}

function requireAdmin() {
    if (!isAdmin()) {
        http_response_code(403);
        die('Acesso restrito ao perfil Administrador.');
    }
}

function userInitials($username) {
    $parts = array_values(array_filter(preg_split('/[.\s_-]+/', trim((string)$username))));
    if (count($parts) >= 2) {
        return strtoupper(mb_substr($parts[0], 0, 1) . mb_substr($parts[1], 0, 1));
    }
    return strtoupper(mb_substr((string)$username, 0, 2));
}

// ---- CSRF ----

function csrfToken() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrfField() {
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(csrfToken()) . '">';
}

function csrfVerify() {
    $sent = $_POST['csrf_token'] ?? '';
    if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $sent)) {
        http_response_code(403);
        die('Falha na validação de segurança (CSRF). Atualize a página e tente novamente.');
    }
}

// ---- Formatação ----

// Converte segundos em "Xh Ym" (ou "Ym" se menos de 1h, ou "Xs" se menos de 1 min) para exibição.
function formatarDuracao($segundos) {
    $segundos = (float)$segundos;
    if ($segundos < 60) {
        return round($segundos) . 's';
    }
    $totalMin = (int)round($segundos / 60);
    $h = intdiv($totalMin, 60);
    $m = $totalMin % 60;
    if ($h > 0) {
        return $m > 0 ? "{$h}h {$m}m" : "{$h}h";
    }
    return "{$m}m";
}

// ---- Criptografia (senha da conta de serviço do AD) ----

function adEncrypt($plaintext) {
    $key = hash('sha256', AD_ENC_KEY, true);
    $iv = random_bytes(openssl_cipher_iv_length('aes-256-cbc'));
    $cipher = openssl_encrypt($plaintext, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
    return base64_encode($iv . $cipher);
}

function adDecrypt($stored) {
    $ivLen = openssl_cipher_iv_length('aes-256-cbc');
    $raw = base64_decode((string)$stored, true);
    if ($raw === false || strlen($raw) <= $ivLen) {
        return (string)$stored;
    }
    $key = hash('sha256', AD_ENC_KEY, true);
    $iv = substr($raw, 0, $ivLen);
    $cipher = substr($raw, $ivLen);
    $plain = openssl_decrypt($cipher, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
    return $plain !== false ? $plain : (string)$stored;
}

function installEncrypt($plaintext) {
    $key = hash('sha256', INSTALL_ENC_KEY, true);
    $iv = random_bytes(openssl_cipher_iv_length('aes-256-cbc'));
    $cipher = openssl_encrypt($plaintext, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
    return base64_encode($iv . $cipher);
}

function installDecrypt($stored) {
    $ivLen = openssl_cipher_iv_length('aes-256-cbc');
    $raw = base64_decode((string)$stored, true);
    if ($raw === false || strlen($raw) <= $ivLen) {
        return (string)$stored;
    }
    $key = hash('sha256', INSTALL_ENC_KEY, true);
    $iv = substr($raw, 0, $ivLen);
    $cipher = substr($raw, $ivLen);
    $plain = openssl_decrypt($cipher, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
    return $plain !== false ? $plain : (string)$stored;
}

require_once __DIR__ . '/aw_client.php';
require_once __DIR__ . '/ad_client.php';
require_once __DIR__ . '/install_client.php';
