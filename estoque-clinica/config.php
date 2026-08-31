<?php
// Buffer de saída para toda a requisição — evita "headers already sent" quando um header()
// de redirecionamento é chamado depois de algum HTML já ter sido ecoado (ver mesma decisão
// documentada em ../mikrotik/config.php).
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

// Configurações do Banco de Dados Local (MySQL/MariaDB)
define('DB_HOST', '127.0.0.1');
define('DB_NAME', 'estoque_clinica');
define('DB_USER', 'root');
define('DB_PASS', '');

// Janelas de alerta de vencimento usadas no Dashboard e nos Relatórios.
define('VENCIMENTO_ALERTA_DIAS', 30);
define('VENCIMENTO_URGENTE_DIAS', 7);

define('UPLOAD_DIR_PACIENTES', __DIR__ . '/uploads/pacientes');
define('UPLOAD_URL_PACIENTES', 'uploads/pacientes');

// Incrementar sempre que uma migração (CREATE TABLE/ALTER TABLE) for adicionada em getDB() — é o
// que faz o bloco de migração rodar de novo (uma única vez) na próxima requisição após o deploy.
define('SCHEMA_VERSION', 1);

function getDB() {
    // Conexão + schema são cacheados numa estática por requisição (mesmo motivo documentado
    // em ../mikrotik/config.php: evita repetir os CREATE TABLE IF NOT EXISTS em toda chamada).
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

        // As migrações abaixo (CREATE TABLE/ALTER TABLE, ~30 idas ao banco) são idempotentes mas
        // caras para rodar em toda requisição — só precisam rodar de novo quando SCHEMA_VERSION
        // muda. schema_migrations guarda a última versão já aplicada nesta base.
        $db->exec("CREATE TABLE IF NOT EXISTS schema_migrations (versao INT NOT NULL)");
        $versaoAplicada = (int)($db->query("SELECT versao FROM schema_migrations LIMIT 1")->fetchColumn() ?: 0);

        if ($versaoAplicada < SCHEMA_VERSION) {

        $db->exec("CREATE TABLE IF NOT EXISTS local_users (
            id INT AUTO_INCREMENT PRIMARY KEY,
            username VARCHAR(100) NOT NULL UNIQUE,
            password_hash VARCHAR(255) NOT NULL,
            full_name VARCHAR(255) DEFAULT '',
            email VARCHAR(150) DEFAULT '',
            notificar_email TINYINT(1) NOT NULL DEFAULT 0,
            enabled TINYINT(1) NOT NULL DEFAULT 1,
            role VARCHAR(20) NOT NULL DEFAULT 'usuario',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )");

        // Migração leve: instalações que já tinham local_users sem email/notificar_email.
        $temEmailUsuario = (bool)$db->query("SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'local_users' AND COLUMN_NAME = 'email'")->fetchColumn();
        if (!$temEmailUsuario) {
            $db->exec("ALTER TABLE local_users
                ADD COLUMN email VARCHAR(150) DEFAULT '' AFTER full_name,
                ADD COLUMN notificar_email TINYINT(1) NOT NULL DEFAULT 0 AFTER email");
        }

        $db->exec("CREATE TABLE IF NOT EXISTS laboratorios (
            id INT AUTO_INCREMENT PRIMARY KEY,
            nome VARCHAR(200) NOT NULL,
            cnpj VARCHAR(20) DEFAULT '',
            contato VARCHAR(150) DEFAULT '',
            telefone VARCHAR(30) DEFAULT '',
            email VARCHAR(150) DEFAULT '',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_laboratorios_nome (nome)
        )");

        // A tabela insumos foi originalmente o catálogo ligado a laboratorio_id/codigo_barras (tela
        // removida quando Entrada/Saída passou a usar a base da ANVISA/CMED). Como nunca chegou a
        // existir cadastro real nela, a migração troca o schema direto em vez de conviver com as
        // colunas antigas — a tela Insumos agora é um catálogo simples de itens de uso/consumo da
        // clínica (luvas, seringas, material de limpeza etc.), sem relação com medicamentos.
        $temColunaLaboratorioId = (bool)$db->query("SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'insumos' AND COLUMN_NAME = 'laboratorio_id'")->fetchColumn();
        if ($temColunaLaboratorioId) {
            $estaVazia = (int)$db->query("SELECT COUNT(*) FROM insumos")->fetchColumn() === 0;
            if ($estaVazia) {
                $db->exec("DROP TABLE insumos");
            }
        }

        $db->exec("CREATE TABLE IF NOT EXISTS insumos (
            id INT AUTO_INCREMENT PRIMARY KEY,
            nome_comercial VARCHAR(200) NOT NULL,
            descricao TEXT,
            marca VARCHAR(150) DEFAULT '',
            categoria VARCHAR(100) DEFAULT '',
            codigo_barras VARCHAR(64) DEFAULT '',
            quantidade INT NOT NULL DEFAULT 0,
            estoque_minimo INT NOT NULL DEFAULT 0,
            unidade_medida VARCHAR(30) NOT NULL DEFAULT 'unidade',
            lote VARCHAR(80) DEFAULT '',
            validade DATE NULL,
            valor_unitario DECIMAL(10,2) NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            atualizado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_insumos_nome (nome_comercial),
            INDEX idx_insumos_categoria (categoria),
            INDEX idx_insumos_validade (validade),
            INDEX idx_insumos_codigo_barras (codigo_barras)
        )");

        // Migração leve: instalações que já tinham a tabela insumos no schema novo (sem EAN) ganham
        // a coluna agora.
        $temCodigoBarrasInsumo = (bool)$db->query("SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'insumos' AND COLUMN_NAME = 'codigo_barras'")->fetchColumn();
        if (!$temCodigoBarrasInsumo) {
            $db->exec("ALTER TABLE insumos ADD COLUMN codigo_barras VARCHAR(64) DEFAULT '' AFTER categoria, ADD INDEX idx_insumos_codigo_barras (codigo_barras)");
        }

        // Migração leve: valor unitário (custo de aquisição) informado na Entrada, pra Saída poder
        // trazer o valor automaticamente e montar o resumo financeiro.
        $temValorInsumo = (bool)$db->query("SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'insumos' AND COLUMN_NAME = 'valor_unitario'")->fetchColumn();
        if (!$temValorInsumo) {
            $db->exec("ALTER TABLE insumos ADD COLUMN valor_unitario DECIMAL(10,2) NOT NULL DEFAULT 0 AFTER validade");
        }

        // Migração histórica de uma versão bem antiga, onde insumo_id referenciava o catálogo de
        // insumos que existia ANTES da base da ANVISA/CMED (schema totalmente diferente do atual:
        // sem medicamento_id). Checa as duas colunas para não confundir com o schema atual — que
        // também tem insumo_id (agora com outro significado: Entrada/Saída movimenta insumos além
        // de medicamentos) — e só dropa se realmente for aquele schema antigo e a tabela seguir vazia.
        foreach (['movimentacoes', 'insumo_lotes'] as $tabelaAntiga) {
            $temColunaInsumoId = (bool)$db->query("SELECT COUNT(*) FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{$tabelaAntiga}' AND COLUMN_NAME = 'insumo_id'")->fetchColumn();
            $temColunaMedicamentoId = (bool)$db->query("SELECT COUNT(*) FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{$tabelaAntiga}' AND COLUMN_NAME = 'medicamento_id'")->fetchColumn();
            if ($temColunaInsumoId && !$temColunaMedicamentoId) {
                $estaVazia = (int)$db->query("SELECT COUNT(*) FROM `{$tabelaAntiga}`")->fetchColumn() === 0;
                if ($estaVazia) {
                    $db->exec("DROP TABLE `{$tabelaAntiga}`");
                }
            }
        }

        // Um medicamento pode ter vários lotes em estoque ao mesmo tempo, cada um com seu próprio
        // vencimento — é o que permite calcular "a vencer em 30/7 dias" e "vencido" por lote,
        // e dar saída seguindo o vencimento mais próximo primeiro (FIFO por validade).
        $db->exec("CREATE TABLE IF NOT EXISTS insumo_lotes (
            id INT AUTO_INCREMENT PRIMARY KEY,
            medicamento_id INT NOT NULL,
            lote VARCHAR(80) NOT NULL,
            validade DATE NOT NULL,
            quantidade INT NOT NULL DEFAULT 0,
            valor_unitario DECIMAL(10,2) NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_medicamento_lote (medicamento_id, lote),
            INDEX idx_lotes_validade (validade),
            INDEX idx_lotes_medicamento (medicamento_id)
        )");

        // Migração leve: valor unitário (custo de aquisição) do lote, informado na Entrada.
        $temValorLote = (bool)$db->query("SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'insumo_lotes' AND COLUMN_NAME = 'valor_unitario'")->fetchColumn();
        if (!$temValorLote) {
            $db->exec("ALTER TABLE insumo_lotes ADD COLUMN valor_unitario DECIMAL(10,2) NOT NULL DEFAULT 0 AFTER quantidade");
        }

        // Cabeçalho de uma confirmação em lote (tela Entrada: vários itens conferidos juntos e
        // gravados de uma vez em "Confirmar Entrada"). Cada linha de movimentacoes gerada por essa
        // confirmação aponta pra cá via confirmacao_id, permitindo que o relatório mostre a ação
        // como um todo (data/hora/usuário/quantidade de itens) em vez de item a item.
        $db->exec("CREATE TABLE IF NOT EXISTS movimentacao_confirmacoes (
            id INT AUTO_INCREMENT PRIMARY KEY,
            tipo VARCHAR(10) NOT NULL DEFAULT 'entrada',
            usuario VARCHAR(100) DEFAULT '',
            paciente_id INT NULL,
            total_itens INT NOT NULL DEFAULT 0,
            total_quantidade INT NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_confirmacoes_paciente (paciente_id)
        )");

        // Migração leve: instalações que já tinham movimentacao_confirmacoes sem paciente_id
        // (recurso da tela Saída: selecionar a quem os itens retirados se destinam).
        $temPacienteIdConfirmacao = (bool)$db->query("SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'movimentacao_confirmacoes' AND COLUMN_NAME = 'paciente_id'")->fetchColumn();
        if (!$temPacienteIdConfirmacao) {
            $db->exec("ALTER TABLE movimentacao_confirmacoes ADD COLUMN paciente_id INT NULL AFTER usuario, ADD INDEX idx_confirmacoes_paciente (paciente_id)");
        }

        // Histórico de entradas/saídas — cada linha é um movimento de um medicamento (com lote
        // específico via lote_id) OU de um insumo (sem lote — o insumo tem uma única quantidade),
        // nunca os dois ao mesmo tempo: exatamente uma das colunas medicamento_id/insumo_id é
        // preenchida por linha.
        $db->exec("CREATE TABLE IF NOT EXISTS movimentacoes (
            id INT AUTO_INCREMENT PRIMARY KEY,
            medicamento_id INT NULL,
            insumo_id INT NULL,
            lote_id INT NULL,
            confirmacao_id INT NULL,
            tipo VARCHAR(10) NOT NULL,
            quantidade INT NOT NULL,
            valor_unitario DECIMAL(10,2) NOT NULL DEFAULT 0,
            usuario VARCHAR(100) DEFAULT '',
            observacao VARCHAR(255) DEFAULT '',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_mov_medicamento (medicamento_id),
            INDEX idx_mov_insumo (insumo_id),
            INDEX idx_mov_created (created_at),
            INDEX idx_mov_tipo (tipo),
            INDEX idx_mov_confirmacao (confirmacao_id)
        )");

        // Migração leve: valor unitário congelado no momento do movimento (o que foi pago na
        // entrada, ou o valor do lote/insumo no momento da saída) — histórico não muda mesmo que o
        // valor cadastrado no lote/insumo seja atualizado depois por uma entrada mais recente.
        $temValorMov = (bool)$db->query("SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'movimentacoes' AND COLUMN_NAME = 'valor_unitario'")->fetchColumn();
        if (!$temValorMov) {
            $db->exec("ALTER TABLE movimentacoes ADD COLUMN valor_unitario DECIMAL(10,2) NOT NULL DEFAULT 0 AFTER quantidade");
        }

        // Migração leve: se movimentacoes já existia (de uma versão anterior a este recurso) sem
        // a coluna confirmacao_id, adiciona agora. Entradas antigas ficam com confirmacao_id NULL
        // e o relatório as agrupa de forma aproximada (mesmo usuário + mesmo minuto).
        $temConfirmacaoId = (bool)$db->query("SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'movimentacoes' AND COLUMN_NAME = 'confirmacao_id'")->fetchColumn();
        if (!$temConfirmacaoId) {
            $db->exec("ALTER TABLE movimentacoes ADD COLUMN confirmacao_id INT NULL AFTER lote_id, ADD INDEX idx_mov_confirmacao (confirmacao_id)");
        }

        // Migração leve: Entrada/Saída passou a também movimentar insumos (não só medicamentos).
        // medicamento_id precisa virar NULLable e ganhar a coluna irmã insumo_id.
        $temInsumoIdMov = (bool)$db->query("SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'movimentacoes' AND COLUMN_NAME = 'insumo_id'")->fetchColumn();
        if (!$temInsumoIdMov) {
            $db->exec("ALTER TABLE movimentacoes
                MODIFY COLUMN medicamento_id INT NULL,
                ADD COLUMN insumo_id INT NULL AFTER medicamento_id,
                ADD INDEX idx_mov_insumo (insumo_id)");
        }

        // Log de auditoria: uma linha por ação relevante executada na aplicação (login/logout,
        // CRUD de usuários, importação de medicamentos, entrada/saída de estoque...), exibida na
        // tela Log (admin). categoria agrupa a origem da ação; detalhes guarda um resumo em texto
        // livre (não estruturado, já que cada categoria tem informações bem diferentes entre si).
        $db->exec("CREATE TABLE IF NOT EXISTS logs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            categoria VARCHAR(50) NOT NULL,
            acao VARCHAR(255) NOT NULL,
            usuario VARCHAR(100) DEFAULT '',
            detalhes TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_logs_categoria (categoria),
            INDEX idx_logs_usuario (usuario),
            INDEX idx_logs_created (created_at)
        )");

        // Cadastro de pacientes da clínica.
        $db->exec("CREATE TABLE IF NOT EXISTS pacientes (
            id INT AUTO_INCREMENT PRIMARY KEY,
            nome_completo VARCHAR(200) NOT NULL,
            data_nascimento DATE NOT NULL,
            foto VARCHAR(255) DEFAULT '',
            sexo VARCHAR(20) NOT NULL DEFAULT 'prefiro_nao_informar',
            cpf VARCHAR(14) NOT NULL,
            nome_mae VARCHAR(200) NOT NULL,
            telefone_celular VARCHAR(20) DEFAULT '',
            email VARCHAR(150) DEFAULT '',
            cep VARCHAR(10) DEFAULT '',
            logradouro VARCHAR(200) DEFAULT '',
            numero VARCHAR(20) DEFAULT '',
            complemento VARCHAR(100) DEFAULT '',
            bairro VARCHAR(100) DEFAULT '',
            cidade VARCHAR(100) DEFAULT '',
            uf VARCHAR(2) DEFAULT '',
            tipo_atendimento VARCHAR(20) NOT NULL DEFAULT 'particular',
            convenio_nome VARCHAR(150) DEFAULT '',
            convenio_carteirinha VARCHAR(80) DEFAULT '',
            emergencia_nome VARCHAR(200) DEFAULT '',
            emergencia_telefone VARCHAR(20) DEFAULT '',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            atualizado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_pacientes_cpf (cpf),
            INDEX idx_pacientes_nome (nome_completo)
        )");

        // Configuração do servidor SMTP usado para as notificações diárias por e-mail (vencimento
        // e estoque mínimo). Linha única (id sempre 1) — mais simples que uma tabela chave/valor
        // pra um conjunto de campos fixo e pequeno como esse.
        $db->exec("CREATE TABLE IF NOT EXISTS notificacao_config (
            id INT PRIMARY KEY DEFAULT 1,
            ativo TINYINT(1) NOT NULL DEFAULT 0,
            modo VARCHAR(10) NOT NULL DEFAULT 'legacy',
            horario_envio TIME NOT NULL DEFAULT '07:00:00',
            remetente_nome VARCHAR(150) DEFAULT 'Estoque Clínica',
            remetente_email VARCHAR(150) DEFAULT '',
            smtp_host VARCHAR(150) DEFAULT '',
            smtp_porta INT NOT NULL DEFAULT 587,
            smtp_seguranca VARCHAR(10) NOT NULL DEFAULT 'tls',
            smtp_usuario VARCHAR(150) DEFAULT '',
            smtp_senha VARCHAR(255) DEFAULT '',
            oauth_provedor VARCHAR(20) DEFAULT 'google',
            oauth_client_id VARCHAR(255) DEFAULT '',
            oauth_client_secret VARCHAR(255) DEFAULT '',
            oauth_refresh_token TEXT,
            oauth_tenant VARCHAR(150) DEFAULT '',
            atualizado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        )");

        // Histórico de tentativas de envio da notificação diária — evita mandar duas vezes no
        // mesmo dia e dá pra tela de Notificações mostrar quando/se o último envio funcionou.
        $db->exec("CREATE TABLE IF NOT EXISTS notificacoes_envios (
            id INT AUTO_INCREMENT PRIMARY KEY,
            data_referencia DATE NOT NULL,
            sucesso TINYINT(1) NOT NULL DEFAULT 0,
            destinatarios INT NOT NULL DEFAULT 0,
            mensagem TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_envios_data (data_referencia)
        )");

        // Base de referência da ANVISA/CMED (lista de preços de medicamentos), importada via CSV
        // pelo administrador. codigo_ggrem é a chave de negócio do CMED (uma linha por apresentação),
        // usada para decidir insert x update a cada reimportação. dados_completos guarda o registro
        // inteiro (todas as colunas do CSV, inclusive as faixas de preço por regime tributário) em
        // JSON, para não perder informação por causa de colunas que este app não modela individualmente.
        $db->exec("CREATE TABLE IF NOT EXISTS medicamentos_anvisa (
            id INT AUTO_INCREMENT PRIMARY KEY,
            codigo_ggrem VARCHAR(30) NOT NULL,
            substancia VARCHAR(255) DEFAULT '',
            cnpj VARCHAR(30) DEFAULT '',
            laboratorio VARCHAR(255) DEFAULT '',
            registro VARCHAR(30) DEFAULT '',
            ean_1 VARCHAR(20) DEFAULT '',
            ean_2 VARCHAR(20) DEFAULT '',
            ean_3 VARCHAR(20) DEFAULT '',
            produto VARCHAR(255) DEFAULT '',
            apresentacao VARCHAR(255) DEFAULT '',
            classe_terapeutica VARCHAR(255) DEFAULT '',
            tipo_produto VARCHAR(150) DEFAULT '',
            regime_preco VARCHAR(100) DEFAULT '',
            tarja VARCHAR(50) DEFAULT '',
            restricao_hospitalar VARCHAR(20) DEFAULT '',
            comercializacao_2025 VARCHAR(20) DEFAULT '',
            estoque_minimo INT NULL,
            dados_completos LONGTEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            atualizado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_medicamentos_ggrem (codigo_ggrem),
            INDEX idx_medicamentos_substancia (substancia),
            INDEX idx_medicamentos_produto (produto),
            INDEX idx_medicamentos_laboratorio (laboratorio),
            INDEX idx_medicamentos_registro (registro),
            INDEX idx_medicamentos_ean1 (ean_1),
            INDEX idx_medicamentos_ean2 (ean_2),
            INDEX idx_medicamentos_ean3 (ean_3),
            INDEX idx_medicamentos_estoque_minimo (estoque_minimo)
        )");

        // Migração leve: instalações que já tinham a tabela sem esses índices. ean_2/ean_3 são
        // consultados a cada leitura de código de barras em Entrada/Saída (findMedicamentoByBarcode)
        // — sem índice, cada leitura varria as ~25 mil linhas do catálogo ANVISA/CMED importado.
        // estoque_minimo é filtrado no Dashboard, no Relatório de Estoque Mínimo e na notificação
        // diária; como só uma fração dos medicamentos tem mínimo configurado, o índice é bem seletivo.
        foreach (['idx_medicamentos_ean2' => 'ean_2', 'idx_medicamentos_ean3' => 'ean_3', 'idx_medicamentos_estoque_minimo' => 'estoque_minimo'] as $nomeIndice => $coluna) {
            $temIndice = (bool)$db->query("SELECT COUNT(*) FROM information_schema.STATISTICS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'medicamentos_anvisa' AND INDEX_NAME = '{$nomeIndice}'")->fetchColumn();
            if (!$temIndice) {
                $db->exec("ALTER TABLE medicamentos_anvisa ADD INDEX {$nomeIndice} ({$coluna})");
            }
        }

        // Migração leve: instalações que já tinham medicamentos_anvisa sem estoque_minimo ganham
        // a coluna agora. Fica de fora do mapeamento de colunas do CSV da ANVISA (ver
        // MEDICAMENTOS_ANVISA_COLUNAS/importarMedicamentosCsv) de propósito — é um dado que o
        // próprio app gerencia (informado na tela de Entrada), não algo que vem do arquivo
        // importado, então reimportar a base não pode sobrescrevê-lo.
        $temEstoqueMinimoMedicamento = (bool)$db->query("SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'medicamentos_anvisa' AND COLUMN_NAME = 'estoque_minimo'")->fetchColumn();
        if (!$temEstoqueMinimoMedicamento) {
            $db->exec("ALTER TABLE medicamentos_anvisa ADD COLUMN estoque_minimo INT NULL AFTER comercializacao_2025");
        }

        // Histórico de importações da base da ANVISA/CMED, exibido na tela Medicamentos.
        $db->exec("CREATE TABLE IF NOT EXISTS medicamentos_anvisa_imports (
            id INT AUTO_INCREMENT PRIMARY KEY,
            arquivo VARCHAR(255) DEFAULT '',
            linha_cabecalho INT NOT NULL DEFAULT 1,
            total_linhas INT NOT NULL DEFAULT 0,
            inseridos INT NOT NULL DEFAULT 0,
            atualizados INT NOT NULL DEFAULT 0,
            ignorados INT NOT NULL DEFAULT 0,
            usuario VARCHAR(100) DEFAULT '',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )");

        if ($versaoAplicada === 0) {
            $db->exec("INSERT INTO schema_migrations (versao) VALUES (" . SCHEMA_VERSION . ")");
        } else {
            $db->exec("UPDATE schema_migrations SET versao = " . SCHEMA_VERSION);
        }
        } // fim do if ($versaoAplicada < SCHEMA_VERSION)

        // Primeiro acesso: sem nenhum usuário cadastrado ainda, semeia um Administrador padrão
        // para não deixar a aplicação sem porta de entrada. Fica FORA do bloco de migração (é
        // barato — 1 SELECT — e precisa continuar valendo mesmo que o schema já esteja atualizado).
        $count = (int)$db->query("SELECT COUNT(*) FROM local_users")->fetchColumn();
        if ($count === 0) {
            $stmt = $db->prepare("INSERT INTO local_users (username, password_hash, full_name, enabled, role) VALUES ('admin', :hash, 'Administrador', 1, 'admin')");
            $stmt->bindValue(':hash', password_hash('admin123', PASSWORD_DEFAULT));
            $stmt->execute();
        }

        $cached = $db;
        return $cached;
    } catch (PDOException $e) {
        die("Erro de conexão com o MySQL: " . $e->getMessage());
    }
}

// ---- Autenticação ----

// checkAuth() (enabled) e currentUserRole() (role) precisavam do mesmo registro de local_users —
// cada uma rodava sua própria SELECT a cada requisição. Uma função só, cacheada por requisição,
// atende as duas com uma única ida ao banco.
function dadosUsuarioAtual() {
    $username = $_SESSION['user_logged_in'] ?? null;
    if (!$username) return null;
    static $cache = null; // ['username' => ..., 'enabled' => 0|1|null, 'role' => ...]
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
        registrarLog('Autenticação', 'Sessão expirada por inatividade', '', $_SESSION['user_logged_in']);
        session_unset();
        session_destroy();
        header("Location: login.php?timeout=1");
        exit;
    }

    // Conta pode ter sido desabilitada (ou excluída) por um administrador depois do login — encerra
    // a sessão imediatamente em vez de deixar a próxima requisição autenticada passar mesmo assim.
    $dados = dadosUsuarioAtual();
    if ($dados === null || $dados['enabled'] === null || $dados['enabled'] === 0) {
        registrarLog('Autenticação', 'Sessão encerrada (conta desabilitada)', '', $_SESSION['user_logged_in']);
        session_unset();
        session_destroy();
        header("Location: login.php");
        exit;
    }

    $_SESSION['last_activity'] = time();
}

// Consulta o perfil sempre no banco (não na sessão) para que uma mudança de perfil feita por um
// administrador tenha efeito imediato, mesmo que o usuário afetado já esteja com sessão aberta.
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

// ---- Log de auditoria ----

const LOG_CATEGORIAS = ['Autenticação', 'Usuários', 'Medicamentos', 'Movimentação', 'Insumos', 'Pacientes', 'Notificações'];

// Registra uma linha no log de auditoria. $usuario pode ser informado explicitamente para casos
// em que a ação altera a própria sessão (ex.: logout, timeout) — nesses casos o valor precisa ser
// capturado antes da sessão ser destruída; nos demais, usa o usuário logado automaticamente.
function registrarLog(string $categoria, string $acao, string $detalhes = '', ?string $usuario = null) {
    $db = getDB();
    $usuario = $usuario ?? ($_SESSION['user_logged_in'] ?? 'sistema');
    $stmt = $db->prepare("INSERT INTO logs (categoria, acao, usuario, detalhes) VALUES (:c, :a, :u, :d)");
    $stmt->execute([':c' => $categoria, ':a' => $acao, ':u' => $usuario, ':d' => $detalhes]);
}

// ---- Regras de estoque / vencimento ----

// Soma de todos os lotes com saldo do medicamento.
function medicamentoEstoqueTotal(PDO $db, $medicamentoId) {
    $stmt = $db->prepare("SELECT COALESCE(SUM(quantidade), 0) FROM insumo_lotes WHERE medicamento_id = :id AND quantidade > 0");
    $stmt->bindValue(':id', $medicamentoId, PDO::PARAM_INT);
    $stmt->execute();
    return (int)$stmt->fetchColumn();
}

// Todos os lotes com saldo do medicamento, do vencimento mais próximo para o mais distante —
// usada para o operador escolher de qual lote específico dar saída (o mesmo medicamento pode
// ter vários lotes com validades diferentes em estoque ao mesmo tempo).
function medicamentoLotesComSaldo(PDO $db, $medicamentoId) {
    $stmt = $db->prepare("SELECT * FROM insumo_lotes WHERE medicamento_id = :id AND quantidade > 0 ORDER BY validade ASC");
    $stmt->bindValue(':id', $medicamentoId, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll();
}

// Medicamentos cujo estoque atual (soma de todos os lotes com saldo) já está no mínimo
// cadastrado ou abaixo dele. Só considera quem TEM um mínimo definido — estoque_minimo NULL
// significa "nunca configurado", não "mínimo zero", então fica de fora da comparação.
function medicamentosAbaixoDoMinimo(PDO $db) {
    $sql = "SELECT ma.id, ma.produto, ma.laboratorio, ma.estoque_minimo,
            COALESCE(SUM(il.quantidade), 0) AS estoque_atual
        FROM medicamentos_anvisa ma
        LEFT JOIN insumo_lotes il ON il.medicamento_id = ma.id AND il.quantidade > 0
        WHERE ma.estoque_minimo IS NOT NULL
        GROUP BY ma.id, ma.produto, ma.laboratorio, ma.estoque_minimo
        HAVING estoque_atual <= ma.estoque_minimo
        ORDER BY ma.produto ASC";
    return $db->query($sql)->fetchAll();
}

// Insumos cuja quantidade atual já está no estoque mínimo cadastrado ou abaixo dele.
function insumosAbaixoDoMinimo(PDO $db) {
    $sql = "SELECT id, nome_comercial, marca, categoria, quantidade, estoque_minimo, unidade_medida
        FROM insumos
        WHERE quantidade <= estoque_minimo
        ORDER BY nome_comercial ASC";
    return $db->query($sql)->fetchAll();
}

// 'vencido' | 'urgente' (<= 7 dias) | 'alerta' (<= 30 dias) | 'ok' | null (sem lote com saldo)
function statusVencimento($validade) {
    if (!$validade) return null;
    $hoje = new DateTime('today');
    $venc = new DateTime($validade);
    $dias = (int)$hoje->diff($venc)->format('%r%a');
    if ($dias < 0) return 'vencido';
    if ($dias <= VENCIMENTO_URGENTE_DIAS) return 'urgente';
    if ($dias <= VENCIMENTO_ALERTA_DIAS) return 'alerta';
    return 'ok';
}

function statusVencimentoLabel($status) {
    return match ($status) {
        'vencido' => 'Vencido',
        'urgente' => 'Vence em até 7 dias',
        'alerta' => 'Vence em até 30 dias',
        'ok' => 'Dentro da validade',
        default => 'Sem estoque',
    };
}

function statusVencimentoBadgeClass($status) {
    return match ($status) {
        'vencido' => 'bg-danger',
        'urgente' => 'bg-warning text-dark',
        'alerta' => 'bg-info text-dark',
        'ok' => 'bg-success',
        default => 'bg-secondary',
    };
}

// Busca por qualquer um dos códigos de barras (EAN 1/2/3) cadastrados na base da ANVISA/CMED,
// ou pelo próprio Código GGREM (para permitir digitar/colar esse código diretamente).
function findMedicamentoByBarcode(PDO $db, $codigo) {
    $stmt = $db->prepare("SELECT * FROM medicamentos_anvisa
        WHERE ean_1 = :c OR ean_2 = :c OR ean_3 = :c OR codigo_ggrem = :c LIMIT 1");
    $stmt->bindValue(':c', $codigo);
    $stmt->execute();
    return $stmt->fetch() ?: null;
}

// Busca pelo EAN cadastrado na tela Insumos.
function findInsumoByBarcode(PDO $db, $codigo) {
    $stmt = $db->prepare("SELECT * FROM insumos WHERE codigo_barras = :c AND codigo_barras <> '' LIMIT 1");
    $stmt->bindValue(':c', $codigo);
    $stmt->execute();
    return $stmt->fetch() ?: null;
}

// Busca usada por Entrada/Saída: procura o código tanto na base de medicamentos (ANVISA/CMED)
// quanto no catálogo de Insumos, e devolve o resultado marcado com o tipo encontrado. Medicamento
// tem prioridade em caso de colisão de código (cenário raro, já que são catálogos independentes).
function buscarItemMovimentacao(PDO $db, $codigo) {
    $medicamento = findMedicamentoByBarcode($db, $codigo);
    if ($medicamento) {
        return ['tipo' => 'medicamento', 'dados' => $medicamento];
    }
    $insumo = findInsumoByBarcode($db, $codigo);
    if ($insumo) {
        return ['tipo' => 'insumo', 'dados' => $insumo];
    }
    return null;
}

// Expressão SQL que identifica a qual "lote de confirmação" (clique em Confirmar Entrada) uma
// linha de movimentacoes pertence: usa confirmacao_id quando existe (toda entrada feita após
// este recurso) e cai para um agrupamento aproximado por usuário+minuto em entradas antigas que
// não tinham esse vínculo — assim o relatório consegue agrupar tanto as novas quanto as antigas.
function movimentacaoGrupoChaveSql($aliasMov = 'mv') {
    return "COALESCE({$aliasMov}.confirmacao_id, CONCAT('legacy_', {$aliasMov}.usuario, '_', DATE_FORMAT({$aliasMov}.created_at, '%Y%m%d%H%i')))";
}

// ---- Base de Medicamentos (ANVISA/CMED) ----

// Cabeçalhos do CSV da ANVISA/CMED que mapeamos para colunas próprias (para busca/listagem).
// Todas as demais colunas do arquivo (inclusive as dezenas de faixas de preço PF/PMVG) são
// preservadas em conjunto no campo dados_completos, sem perda de informação.
const MEDICAMENTOS_ANVISA_COLUNAS = [
    'SUBSTÂNCIA' => 'substancia',
    'CNPJ' => 'cnpj',
    'LABORATÓRIO' => 'laboratorio',
    'CÓDIGO GGREM' => 'codigo_ggrem',
    'REGISTRO' => 'registro',
    'EAN 1' => 'ean_1',
    'EAN 2' => 'ean_2',
    'EAN 3' => 'ean_3',
    'PRODUTO' => 'produto',
    'APRESENTAÇÃO' => 'apresentacao',
    'CLASSE TERAPÊUTICA' => 'classe_terapeutica',
    'TIPO DE PRODUTO (STATUS DO PRODUTO)' => 'tipo_produto',
    'REGIME DE PREÇO' => 'regime_preco',
    'TARJA' => 'tarja',
    'RESTRIÇÃO HOSPITALAR' => 'restricao_hospitalar',
    'COMERCIALIZAÇÃO 2025' => 'comercializacao_2025',
];

// Reduz o nome de uma coluna ao seu "esqueleto" ASCII: maiúsculas, sem acentos nem caracteres
// de controle/corrompidos, espaços colapsados. Usada para casar o cabeçalho do CSV com
// MEDICAMENTOS_ANVISA_COLUNAS mesmo quando o arquivo chega com a acentuação corrompida (comum
// nos exports da ANVISA/CMED) — funciona porque bytes de acentuação/corrupção nunca caem na
// faixa ASCII imprimível, então descartá-los sempre resulta no mesmo "esqueleto", não importa
// qual tenha sido a codificação original.
function normalizarNomeColunaAnvisa($s) {
    $s = preg_replace('/[^\x20-\x7E]+/', '', (string)$s) ?? '';
    $s = preg_replace('/\s+/', ' ', $s) ?? '';
    return strtoupper(trim($s));
}

// Versão de MEDICAMENTOS_ANVISA_COLUNAS indexada pelo esqueleto normalizado (calculada uma vez,
// a partir da constante acima, para não duplicar/errar a lista à mão).
function medicamentosAnvisaColunasNormalizadas() {
    static $mapa = null;
    if ($mapa === null) {
        $mapa = [];
        foreach (MEDICAMENTOS_ANVISA_COLUNAS as $original => $campo) {
            $mapa[normalizarNomeColunaAnvisa($original)] = $campo;
        }
    }
    return $mapa;
}

// Lê o CSV a partir da linha de cabeçalho informada pelo administrador, faz upsert por
// CÓDIGO GGREM (chave de negócio do CMED) e registra o resultado em medicamentos_anvisa_imports.
// Lança RuntimeException com mensagem amigável em caso de arquivo inválido.
function importarMedicamentosCsv(PDO $db, string $caminhoArquivo, int $linhaCabecalho, string $nomeArquivo, string $usuario) {
    set_time_limit(0);
    ini_set('memory_limit', '512M');

    $handle = fopen($caminhoArquivo, 'r');
    if (!$handle) {
        throw new RuntimeException('Não foi possível abrir o arquivo enviado.');
    }

    $delimiter = ';';
    // Corrige tanto arquivos em ISO-8859-1 puro (bytes inválidos como UTF-8) quanto o problema
    // recorrente nos exports públicos da ANVISA/CMED: texto UTF-8 correto que foi decodificado
    // como Latin-1 e regravado como UTF-8, produzindo "SUBSTÃNCIA" em vez de "SUBSTÂNCIA" (esse
    // texto já É um UTF-8 tecnicamente válido, só que com o conteúdo errado, por isso o simples
    // teste de mb_check_encoding não pega esse caso). Reinterpretar os bytes como Latin-1 desfaz
    // a dupla codificação; se o resultado não virar UTF-8 válido, o texto original é mantido.
    $toUtf8 = function ($v) {
        $v = (string)$v;
        if (!mb_check_encoding($v, 'UTF-8')) {
            return mb_convert_encoding($v, 'UTF-8', 'ISO-8859-1');
        }
        $reparado = @mb_convert_encoding($v, 'ISO-8859-1', 'UTF-8');
        if ($reparado !== false && $reparado !== '' && $reparado !== $v && mb_check_encoding($reparado, 'UTF-8')) {
            return $reparado;
        }
        return $v;
    };

    $linhaAtual = 0;
    $header = null;
    while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {
        $linhaAtual++;
        if ($linhaAtual === $linhaCabecalho) {
            $header = array_map(function ($h) use ($toUtf8) {
                return trim($toUtf8($h), " \t\n\r\0\x0B\xEF\xBB\xBF");
            }, $row);
            break;
        }
    }
    if ($header === null) {
        fclose($handle);
        throw new RuntimeException("O arquivo tem menos de {$linhaCabecalho} linha(s); a linha de cabeçalho informada não foi encontrada.");
    }

    // Os exports públicos da ANVISA/CMED costumam vir com a acentuação corrompida de formas
    // imprevisíveis (ISO-8859-1 puro, UTF-8 duplamente codificado, bytes de controle perdidos
    // no caminho até chegar aqui...). Em vez de tentar adivinhar/reverter a codificação exata,
    // casa os nomes das colunas comparando só o "esqueleto" ASCII (letras/números/espaços/
    // pontuação comuns), descartando qualquer acento ou caractere corrompido — assim o
    // casamento funciona independentemente de qual seja a codificação real do arquivo.
    $colunasNormalizadas = medicamentosAnvisaColunasNormalizadas();
    $colIndex = []; // campo canônico => índice da coluna no CSV
    foreach ($header as $idx => $h) {
        $norm = normalizarNomeColunaAnvisa($h);
        if (isset($colunasNormalizadas[$norm])) {
            $colIndex[$colunasNormalizadas[$norm]] = $idx;
        }
    }
    if (!isset($colIndex['codigo_ggrem'])) {
        fclose($handle);
        throw new RuntimeException('A coluna "CÓDIGO GGREM" não foi encontrada na linha de cabeçalho informada. Confira o número da linha e tente novamente.');
    }

    $valor = function (array $row, string $campo) use ($colIndex) {
        $idx = $colIndex[$campo] ?? null;
        return ($idx !== null && isset($row[$idx])) ? trim($row[$idx]) : '';
    };

    $upsert = $db->prepare("INSERT INTO medicamentos_anvisa
        (codigo_ggrem, substancia, cnpj, laboratorio, registro, ean_1, ean_2, ean_3, produto, apresentacao,
         classe_terapeutica, tipo_produto, regime_preco, tarja, restricao_hospitalar, comercializacao_2025, dados_completos)
        VALUES (:ggrem, :subst, :cnpj, :lab, :reg, :ean1, :ean2, :ean3, :prod, :apres, :classe, :tipo, :regime, :tarja, :restr, :comerc, :dados)
        ON DUPLICATE KEY UPDATE
            substancia = VALUES(substancia), cnpj = VALUES(cnpj), laboratorio = VALUES(laboratorio),
            registro = VALUES(registro), ean_1 = VALUES(ean_1), ean_2 = VALUES(ean_2), ean_3 = VALUES(ean_3),
            produto = VALUES(produto), apresentacao = VALUES(apresentacao), classe_terapeutica = VALUES(classe_terapeutica),
            tipo_produto = VALUES(tipo_produto), regime_preco = VALUES(regime_preco), tarja = VALUES(tarja),
            restricao_hospitalar = VALUES(restricao_hospitalar), comercializacao_2025 = VALUES(comercializacao_2025),
            dados_completos = VALUES(dados_completos)");

    $totalLinhas = 0;
    $processadas = 0;
    $ignoradas = 0;
    $antesTotal = (int)$db->query("SELECT COUNT(*) FROM medicamentos_anvisa")->fetchColumn();

    $db->beginTransaction();
    while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {
        if (count($row) === 1 && trim((string)$row[0]) === '') {
            continue; // linha em branco, não conta como registro
        }
        $totalLinhas++;
        $row = array_map($toUtf8, $row);

        $ggrem = $valor($row, 'codigo_ggrem');
        if ($ggrem === '') {
            $ignoradas++;
            continue;
        }

        $dadosCompletos = [];
        foreach ($header as $idx => $label) {
            if ($label === '') continue;
            $dadosCompletos[$label] = $row[$idx] ?? '';
        }

        $upsert->execute([
            ':ggrem' => $ggrem,
            ':subst' => $valor($row, 'substancia'),
            ':cnpj' => $valor($row, 'cnpj'),
            ':lab' => $valor($row, 'laboratorio'),
            ':reg' => $valor($row, 'registro'),
            ':ean1' => $valor($row, 'ean_1'),
            ':ean2' => $valor($row, 'ean_2'),
            ':ean3' => $valor($row, 'ean_3'),
            ':prod' => $valor($row, 'produto'),
            ':apres' => $valor($row, 'apresentacao'),
            ':classe' => $valor($row, 'classe_terapeutica'),
            ':tipo' => $valor($row, 'tipo_produto'),
            ':regime' => $valor($row, 'regime_preco'),
            ':tarja' => $valor($row, 'tarja'),
            ':restr' => $valor($row, 'restricao_hospitalar'),
            ':comerc' => $valor($row, 'comercializacao_2025'),
            ':dados' => json_encode($dadosCompletos, JSON_UNESCAPED_UNICODE),
        ]);
        $processadas++;

        // Confirma em lotes para não segurar uma transação gigante em arquivos com dezenas de milhares de linhas.
        if ($processadas % 500 === 0) {
            $db->commit();
            $db->beginTransaction();
        }
    }
    $db->commit();
    fclose($handle);

    $depoisTotal = (int)$db->query("SELECT COUNT(*) FROM medicamentos_anvisa")->fetchColumn();
    $inseridos = max(0, $depoisTotal - $antesTotal);
    $atualizados = max(0, $processadas - $inseridos);

    $log = $db->prepare("INSERT INTO medicamentos_anvisa_imports
        (arquivo, linha_cabecalho, total_linhas, inseridos, atualizados, ignorados, usuario)
        VALUES (:a, :l, :t, :i, :u, :ig, :us)");
    $log->execute([
        ':a' => $nomeArquivo, ':l' => $linhaCabecalho, ':t' => $totalLinhas,
        ':i' => $inseridos, ':u' => $atualizados, ':ig' => $ignoradas, ':us' => $usuario,
    ]);

    return [
        'total' => $totalLinhas,
        'inseridos' => $inseridos,
        'atualizados' => $atualizados,
        'ignorados' => $ignoradas,
    ];
}

// ---- Pacientes ----

// Valida um CPF pelo algoritmo oficial de dígitos verificadores (não apenas o formato). Aceita
// tanto "000.000.000-00" quanto só os 11 dígitos.
function validarCPF($cpf) {
    $cpf = preg_replace('/\D/', '', (string)$cpf);
    if (strlen($cpf) !== 11 || preg_match('/^(\d)\1{10}$/', $cpf)) {
        return false; // tamanho errado ou os 11 dígitos iguais (000.000.000-00 etc., sempre inválido)
    }
    for ($t = 9; $t <= 10; $t++) {
        $soma = 0;
        for ($i = 0; $i < $t; $i++) {
            $soma += (int)$cpf[$i] * (($t + 1) - $i);
        }
        $digito = ((10 * $soma) % 11) % 10;
        if ((int)$cpf[$t] !== $digito) {
            return false;
        }
    }
    return true;
}

function formatarCPF($cpf) {
    $cpf = preg_replace('/\D/', '', (string)$cpf);
    if (strlen($cpf) !== 11) return $cpf;
    return substr($cpf, 0, 3) . '.' . substr($cpf, 3, 3) . '.' . substr($cpf, 6, 3) . '-' . substr($cpf, 9, 2);
}

// Salva a foto do paciente em uploads/pacientes com um nome único; devolve o nome do arquivo
// salvo (ou '' se nenhum arquivo válido foi enviado). Só aceita JPG/JPEG/PNG, conforme pedido.
function salvarFotoPaciente(array $file) {
    if (!isset($file['error']) || $file['error'] === UPLOAD_ERR_NO_FILE) {
        return '';
    }
    if ($file['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Falha ao enviar a foto (código ' . $file['error'] . ').');
    }

    $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png'];
    $mime = mime_content_type($file['tmp_name']);
    if (!isset($allowed[$mime])) {
        throw new RuntimeException('Formato de imagem não suportado. Use JPG, JPEG ou PNG.');
    }
    if ($file['size'] > 5 * 1024 * 1024) {
        throw new RuntimeException('A foto deve ter no máximo 5MB.');
    }

    if (!is_dir(UPLOAD_DIR_PACIENTES)) {
        mkdir(UPLOAD_DIR_PACIENTES, 0755, true);
    }

    $filename = bin2hex(random_bytes(16)) . '.' . $allowed[$mime];
    if (!move_uploaded_file($file['tmp_name'], UPLOAD_DIR_PACIENTES . '/' . $filename)) {
        throw new RuntimeException('Não foi possível salvar a foto enviada.');
    }
    return $filename;
}

function pacienteFotoUrl($foto) {
    if (empty($foto)) return null;
    return UPLOAD_URL_PACIENTES . '/' . rawurlencode($foto);
}

// ---- Notificações por e-mail (vencimento + estoque mínimo) ----

// Linha única de configuração (id=1), criada com valores padrão se ainda não existir.
function notificacaoConfig(PDO $db) {
    $cfg = $db->query("SELECT * FROM notificacao_config WHERE id = 1")->fetch();
    if (!$cfg) {
        $db->exec("INSERT INTO notificacao_config (id) VALUES (1)");
        $cfg = $db->query("SELECT * FROM notificacao_config WHERE id = 1")->fetch();
    }
    return $cfg;
}

// Troca o refresh_token por um access_token novo junto ao provedor (Google ou Microsoft). É uma
// chamada HTTP simples (POST com client_id/secret/refresh_token) — não precisa de nenhuma
// biblioteca de OAuth, só cURL, que já vem com o PHP.
function obterAccessTokenOAuth(array $cfg) {
    $endpoints = [
        'google' => 'https://oauth2.googleapis.com/token',
        'microsoft' => 'https://login.microsoftonline.com/' . ($cfg['oauth_tenant'] ?: 'common') . '/oauth2/v2.0/token',
    ];
    $url = $endpoints[$cfg['oauth_provedor']] ?? null;
    if (!$url) {
        return ['token' => null, 'erro' => 'Provedor OAuth desconhecido.'];
    }

    $params = [
        'client_id' => $cfg['oauth_client_id'],
        'client_secret' => $cfg['oauth_client_secret'],
        'refresh_token' => $cfg['oauth_refresh_token'],
        'grant_type' => 'refresh_token',
    ];
    if ($cfg['oauth_provedor'] === 'microsoft') {
        $params['scope'] = 'https://outlook.office365.com/.default offline_access';
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($params),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $resposta = curl_exec($ch);
    $erroCurl = curl_error($ch);
    curl_close($ch);

    if ($resposta === false) {
        return ['token' => null, 'erro' => "Falha na conexão com o provedor OAuth: {$erroCurl}"];
    }
    $json = json_decode($resposta, true);
    if (!isset($json['access_token'])) {
        $msg = $json['error_description'] ?? $json['error'] ?? $resposta;
        return ['token' => null, 'erro' => "Provedor OAuth recusou a renovação do token: {$msg}"];
    }
    return ['token' => $json['access_token'], 'erro' => null];
}

// Cliente SMTP mínimo via socket puro (sem biblioteca externa): EHLO, STARTTLS opcional, AUTH
// LOGIN (modo legacy) ou AUTH XOAUTH2 (modo oauth), MAIL FROM/RCPT TO/DATA. Servidor de teste
// (botão "Enviar teste" na tela Notificações) e o envio diário de verdade usam a mesma função.
function enviarEmailSmtp(array $cfg, array $destinatarios, string $assunto, string $corpoHtml) {
    if (empty($destinatarios)) {
        return ['sucesso' => false, 'mensagem' => 'Nenhum destinatário informado.'];
    }
    if ($cfg['smtp_host'] === '' || $cfg['remetente_email'] === '') {
        return ['sucesso' => false, 'mensagem' => 'Configure o servidor SMTP e o e-mail do remetente antes de enviar.'];
    }

    $host = $cfg['smtp_host'];
    $porta = (int)$cfg['smtp_porta'];
    $seguranca = $cfg['smtp_seguranca']; // 'tls' | 'ssl' | 'none'
    $timeout = 15;

    $enderecoConexao = ($seguranca === 'ssl' ? 'ssl://' : '') . $host;
    $fp = @fsockopen($enderecoConexao, $porta, $errno, $errstr, $timeout);
    if (!$fp) {
        return ['sucesso' => false, 'mensagem' => "Não foi possível conectar em {$host}:{$porta} — {$errstr}"];
    }
    stream_set_timeout($fp, $timeout);

    $ler = function () use ($fp) {
        $resp = '';
        while (($linha = fgets($fp, 515)) !== false) {
            $resp .= $linha;
            if (isset($linha[3]) && $linha[3] === ' ') break;
        }
        return $resp;
    };
    $enviar = function ($cmd) use ($fp) { fwrite($fp, $cmd . "\r\n"); };
    $codigo = function ($resp) { return (int)substr(trim($resp), 0, 3); };

    $resp = $ler();
    if ($codigo($resp) !== 220) { fclose($fp); return ['sucesso' => false, 'mensagem' => "Servidor recusou a conexão: {$resp}"]; }

    $heloNome = $_SERVER['SERVER_NAME'] ?? 'localhost';
    $enviar("EHLO {$heloNome}");
    $resp = $ler();
    if ($codigo($resp) !== 250) { fclose($fp); return ['sucesso' => false, 'mensagem' => "EHLO falhou: {$resp}"]; }

    if ($seguranca === 'tls') {
        $enviar("STARTTLS");
        $resp = $ler();
        if ($codigo($resp) !== 220) { fclose($fp); return ['sucesso' => false, 'mensagem' => "STARTTLS falhou: {$resp}"]; }
        if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
            fclose($fp);
            return ['sucesso' => false, 'mensagem' => 'Falha ao negociar TLS com o servidor.'];
        }
        $enviar("EHLO {$heloNome}");
        $resp = $ler();
    }

    if ($cfg['modo'] === 'oauth') {
        $tokenResp = obterAccessTokenOAuth($cfg);
        if (!$tokenResp['token']) {
            fclose($fp);
            return ['sucesso' => false, 'mensagem' => $tokenResp['erro']];
        }
        $authStr = base64_encode("user=" . $cfg['remetente_email'] . "\x01auth=Bearer " . $tokenResp['token'] . "\x01\x01");
        $enviar("AUTH XOAUTH2 {$authStr}");
        $resp = $ler();
        if ($codigo($resp) !== 235) { fclose($fp); return ['sucesso' => false, 'mensagem' => "Autenticação OAuth recusada: {$resp}"]; }
    } else {
        $enviar("AUTH LOGIN");
        $resp = $ler();
        if ($codigo($resp) !== 334) { fclose($fp); return ['sucesso' => false, 'mensagem' => "AUTH LOGIN não suportado: {$resp}"]; }
        $enviar(base64_encode($cfg['smtp_usuario']));
        $resp = $ler();
        $enviar(base64_encode($cfg['smtp_senha']));
        $resp = $ler();
        if ($codigo($resp) !== 235) { fclose($fp); return ['sucesso' => false, 'mensagem' => "Usuário ou senha recusados pelo servidor: {$resp}"]; }
    }

    $enviar("MAIL FROM:<{$cfg['remetente_email']}>");
    $resp = $ler();
    if ($codigo($resp) !== 250) { fclose($fp); return ['sucesso' => false, 'mensagem' => "MAIL FROM recusado: {$resp}"]; }

    foreach ($destinatarios as $dest) {
        $enviar("RCPT TO:<{$dest}>");
        $resp = $ler();
        if ($codigo($resp) >= 500) { fclose($fp); return ['sucesso' => false, 'mensagem' => "Destinatário recusado ({$dest}): {$resp}"]; }
    }

    $enviar("DATA");
    $resp = $ler();
    if ($codigo($resp) !== 354) { fclose($fp); return ['sucesso' => false, 'mensagem' => "DATA recusado: {$resp}"]; }

    $dataAtual = date('r');
    $cabecalhos = "From: {$cfg['remetente_nome']} <{$cfg['remetente_email']}>\r\n"
        . "To: " . implode(', ', $destinatarios) . "\r\n"
        . "Subject: =?UTF-8?B?" . base64_encode($assunto) . "?=\r\n"
        . "Date: {$dataAtual}\r\n"
        . "MIME-Version: 1.0\r\n"
        . "Content-Type: text/html; charset=UTF-8\r\n"
        . "Content-Transfer-Encoding: 8bit\r\n";

    // Dot-stuffing: uma linha começando com "." sozinha encerraria a mensagem prematuramente.
    $corpoEscapado = preg_replace('/^\./m', '..', $corpoHtml);
    $enviar($cabecalhos . "\r\n" . $corpoEscapado . "\r\n.");
    $resp = $ler();
    if ($codigo($resp) !== 250) { fclose($fp); return ['sucesso' => false, 'mensagem' => "Servidor recusou a mensagem: {$resp}"]; }

    $enviar("QUIT");
    fclose($fp);
    return ['sucesso' => true, 'mensagem' => 'E-mail enviado com sucesso.'];
}

// Monta o e-mail diário: vencendo em até 7 dias, vencendo em até 30 dias (excluindo os já
// listados em 7) e itens no estoque mínimo (medicamentos + insumos). Retorna null se não há
// nada a notificar hoje, pra não mandar e-mail vazio todo dia sem necessidade.
function montarConteudoNotificacaoDiaria(PDO $db) {
    $hoje = date('Y-m-d');
    $em7 = date('Y-m-d', strtotime('+' . VENCIMENTO_URGENTE_DIAS . ' days'));
    $em30 = date('Y-m-d', strtotime('+' . VENCIMENTO_ALERTA_DIAS . ' days'));

    $stmt = $db->prepare("SELECT md.produto AS nome, l.lote, l.validade, l.quantidade
        FROM insumo_lotes l JOIN medicamentos_anvisa md ON md.id = l.medicamento_id
        WHERE l.quantidade > 0 AND l.validade >= :hoje AND l.validade <= :em30
        ORDER BY l.validade ASC");
    $stmt->execute([':hoje' => $hoje, ':em30' => $em30]);
    $vencendo30 = $stmt->fetchAll();
    $vencendo7 = array_values(array_filter($vencendo30, function ($l) use ($em7) { return $l['validade'] <= $em7; }));
    $vencendo30 = array_values(array_filter($vencendo30, function ($l) use ($em7) { return $l['validade'] > $em7; }));

    $abaixoMinimo = [];
    foreach (medicamentosAbaixoDoMinimo($db) as $m) {
        $abaixoMinimo[] = ['tipo' => 'Medicamento', 'nome' => $m['produto'], 'atual' => (int)$m['estoque_atual'], 'minimo' => (int)$m['estoque_minimo']];
    }
    foreach (insumosAbaixoDoMinimo($db) as $i) {
        $abaixoMinimo[] = ['tipo' => 'Insumo', 'nome' => $i['nome_comercial'], 'atual' => (int)$i['quantidade'], 'minimo' => (int)$i['estoque_minimo']];
    }

    if (!$vencendo7 && !$vencendo30 && !$abaixoMinimo) {
        return null;
    }

    $linkApp = (!empty($_SERVER['HTTP_HOST']) ? ((!empty($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST']) : '') . '/estoque-clinica/index.php';

    $secao = function ($titulo, $corBadge, $linhas, $montarLinha) {
        if (!$linhas) return '';
        $corpo = '<h3 style="margin:24px 0 8px;font-size:15px;">' . htmlspecialchars($titulo) . ' <span style="background:' . $corBadge . ';color:#fff;border-radius:10px;padding:2px 8px;font-size:12px;">' . count($linhas) . '</span></h3>';
        $corpo .= '<table style="width:100%;border-collapse:collapse;font-size:13px;">' . implode('', array_map($montarLinha, $linhas)) . '</table>';
        return $corpo;
    };

    $html = '<div style="font-family:Segoe UI,Arial,sans-serif;color:#1a2233;max-width:640px;">';
    $html .= '<h2 style="margin:0 0 4px;">Estoque Clínica — Resumo diário</h2>';
    $html .= '<p style="color:#5c6b80;font-size:13px;margin:0 0 16px;">' . date('d/m/Y') . '</p>';

    $html .= $secao('Vencendo em até 7 dias', '#dc2626', $vencendo7, function ($l) {
        return '<tr style="border-bottom:1px solid #eee;"><td style="padding:6px 4px;">' . htmlspecialchars($l['nome']) . '</td>'
            . '<td style="padding:6px 4px;">Lote ' . htmlspecialchars($l['lote']) . '</td>'
            . '<td style="padding:6px 4px;">' . date('d/m/Y', strtotime($l['validade'])) . '</td>'
            . '<td style="padding:6px 4px;text-align:right;">' . (int)$l['quantidade'] . ' un.</td></tr>';
    });
    $html .= $secao('Vencendo em até 30 dias', '#f97803', $vencendo30, function ($l) {
        return '<tr style="border-bottom:1px solid #eee;"><td style="padding:6px 4px;">' . htmlspecialchars($l['nome']) . '</td>'
            . '<td style="padding:6px 4px;">Lote ' . htmlspecialchars($l['lote']) . '</td>'
            . '<td style="padding:6px 4px;">' . date('d/m/Y', strtotime($l['validade'])) . '</td>'
            . '<td style="padding:6px 4px;text-align:right;">' . (int)$l['quantidade'] . ' un.</td></tr>';
    });
    $html .= $secao('Estoque mínimo atingido', '#b8790a', $abaixoMinimo, function ($it) {
        return '<tr style="border-bottom:1px solid #eee;"><td style="padding:6px 4px;">' . htmlspecialchars($it['tipo']) . '</td>'
            . '<td style="padding:6px 4px;">' . htmlspecialchars($it['nome']) . '</td>'
            . '<td style="padding:6px 4px;text-align:right;">' . $it['atual'] . '</td>'
            . '<td style="padding:6px 4px;text-align:right;">mín. ' . $it['minimo'] . '</td></tr>';
    });

    $html .= '<p style="margin-top:24px;"><a href="' . htmlspecialchars($linkApp) . '" style="color:#178ec8;">Abrir o Estoque Clínica</a></p>';
    $html .= '</div>';

    $totalItens = count($vencendo7) + count($vencendo30) + count($abaixoMinimo);
    $assunto = "Estoque Clínica: {$totalItens} alerta(s) hoje";

    return ['assunto' => $assunto, 'corpo' => $html];
}

// Monta e manda o e-mail de verdade e grava o resultado em notificacoes_envios (um upsert do dia).
// Usada tanto pelo gatilho automático quanto pelo botão "Reenviar agora" da tela de Notificações.
function executarNotificacaoDiaria(PDO $db, array $cfg) {
    $hoje = date('Y-m-d');
    $destinatarios = $db->query("SELECT email FROM local_users WHERE notificar_email = 1 AND enabled = 1 AND email <> ''")->fetchAll(PDO::FETCH_COLUMN);
    $conteudo = $destinatarios ? montarConteudoNotificacaoDiaria($db) : null;

    if (!$destinatarios) {
        $resultado = ['sucesso' => true, 'mensagem' => 'Nenhum usuário está marcado para receber notificações por e-mail.'];
    } elseif (!$conteudo) {
        $resultado = ['sucesso' => true, 'mensagem' => 'Nada a notificar hoje (sem vencimentos próximos nem estoque mínimo atingido).'];
    } else {
        $resultado = enviarEmailSmtp($cfg, $destinatarios, $conteudo['assunto'], $conteudo['corpo']);
    }

    $db->prepare("INSERT INTO notificacoes_envios (data_referencia, sucesso, destinatarios, mensagem) VALUES (:d, :s, :dc, :m)
        ON DUPLICATE KEY UPDATE sucesso = VALUES(sucesso), destinatarios = VALUES(destinatarios), mensagem = VALUES(mensagem), created_at = NOW()")
       ->execute([
           ':d' => $hoje,
           ':s' => $resultado['sucesso'] ? 1 : 0,
           ':dc' => count($destinatarios),
           ':m' => $resultado['mensagem'],
       ]);

    registrarLog('Notificações', $resultado['sucesso'] ? 'Notificação diária enviada' : 'Falha ao enviar notificação diária',
        $resultado['mensagem'] . ' (' . count($destinatarios) . ' destinatário(s))', 'sistema');

    return $resultado;
}

// Chamada em toda requisição autenticada (ver index.php): se a notificação diária está ativa,
// ainda não foi enviada com sucesso hoje e já passou do horário configurado, envia agora — sem
// depender de nenhum agendador do sistema operacional. O primeiro SELECT é sempre leve; só monta
// e-mail e conecta no SMTP quando realmente está na hora.
function verificarEnviarNotificacaoDiaria(PDO $db) {
    $cfg = $db->query("SELECT * FROM notificacao_config WHERE id = 1")->fetch();
    if (!$cfg || !$cfg['ativo']) return;

    $hoje = date('Y-m-d');
    if (date('H:i:s') < $cfg['horario_envio']) return;

    $stmt = $db->prepare("SELECT * FROM notificacoes_envios WHERE data_referencia = :d");
    $stmt->execute([':d' => $hoje]);
    $envio = $stmt->fetch();

    if ($envio) {
        if ($envio['sucesso']) return; // já enviado com sucesso hoje
        // Já falhou antes hoje: só tenta de novo depois de um tempo, pra uma falha de SMTP (ex.:
        // configuração errada) não virar uma tentativa de conexão em todo carregamento de página.
        if (strtotime($envio['created_at']) > strtotime('-30 minutes')) return;
    }

    // "Reserva"/atualiza a tentativa do dia antes de fazer qualquer trabalho pesado (montar e-mail,
    // conectar no SMTP), pra duas requisições simultâneas não tentarem mandar ao mesmo tempo.
    try {
        if ($envio) {
            $upd = $db->prepare("UPDATE notificacoes_envios SET mensagem = 'Em andamento...', created_at = NOW() WHERE data_referencia = :d AND created_at = :antigo");
            $upd->execute([':d' => $hoje, ':antigo' => $envio['created_at']]);
            if ($upd->rowCount() === 0) return; // outra requisição já pegou essa nova tentativa
        } else {
            $db->prepare("INSERT INTO notificacoes_envios (data_referencia, sucesso, destinatarios, mensagem) VALUES (:d, 0, 0, 'Em andamento...')")
               ->execute([':d' => $hoje]);
        }
    } catch (PDOException $e) {
        return; // outra requisição já reservou o dia entre o SELECT e o INSERT
    }

    executarNotificacaoDiaria($db, $cfg);
}
