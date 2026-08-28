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

        $db->exec("CREATE TABLE IF NOT EXISTS local_users (
            id INT AUTO_INCREMENT PRIMARY KEY,
            username VARCHAR(100) NOT NULL UNIQUE,
            password_hash VARCHAR(255) NOT NULL,
            full_name VARCHAR(255) DEFAULT '',
            enabled TINYINT(1) NOT NULL DEFAULT 1,
            role VARCHAR(20) NOT NULL DEFAULT 'usuario',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )");

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

        $db->exec("CREATE TABLE IF NOT EXISTS insumos (
            id INT AUTO_INCREMENT PRIMARY KEY,
            nome VARCHAR(200) NOT NULL,
            laboratorio_id INT NOT NULL,
            composicao TEXT,
            codigo_barras VARCHAR(64) NOT NULL UNIQUE,
            unidade_medida VARCHAR(30) NOT NULL DEFAULT 'unidade',
            observacoes TEXT,
            foto VARCHAR(255) DEFAULT '',
            ativo TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_insumos_laboratorio (laboratorio_id),
            INDEX idx_insumos_nome (nome)
        )");

        // O catálogo próprio de insumos foi descontinuado (telas Insumos/Laboratórios removidas):
        // o estoque agora é rastreado direto em cima da base de medicamentos da ANVISA/CMED. As
        // tabelas abaixo guardavam lotes/movimentações por insumo_id; como nunca chegou a existir
        // estoque real registrado (tabelas vazias), a migração troca a coluna para medicamento_id
        // em vez de tentar remapear dados que não teriam correspondência em medicamentos_anvisa.
        foreach (['movimentacoes', 'insumo_lotes'] as $tabelaAntiga) {
            $temColunaInsumoId = (bool)$db->query("SELECT COUNT(*) FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{$tabelaAntiga}' AND COLUMN_NAME = 'insumo_id'")->fetchColumn();
            if ($temColunaInsumoId) {
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
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_medicamento_lote (medicamento_id, lote),
            INDEX idx_lotes_validade (validade),
            INDEX idx_lotes_medicamento (medicamento_id)
        )");

        // Cabeçalho de uma confirmação em lote (tela Entrada: vários itens conferidos juntos e
        // gravados de uma vez em "Confirmar Entrada"). Cada linha de movimentacoes gerada por essa
        // confirmação aponta pra cá via confirmacao_id, permitindo que o relatório mostre a ação
        // como um todo (data/hora/usuário/quantidade de itens) em vez de item a item.
        $db->exec("CREATE TABLE IF NOT EXISTS movimentacao_confirmacoes (
            id INT AUTO_INCREMENT PRIMARY KEY,
            tipo VARCHAR(10) NOT NULL DEFAULT 'entrada',
            usuario VARCHAR(100) DEFAULT '',
            total_itens INT NOT NULL DEFAULT 0,
            total_quantidade INT NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )");

        // Histórico de entradas/saídas — cada linha é um movimento aplicado a um lote específico.
        $db->exec("CREATE TABLE IF NOT EXISTS movimentacoes (
            id INT AUTO_INCREMENT PRIMARY KEY,
            medicamento_id INT NOT NULL,
            lote_id INT NULL,
            confirmacao_id INT NULL,
            tipo VARCHAR(10) NOT NULL,
            quantidade INT NOT NULL,
            usuario VARCHAR(100) DEFAULT '',
            observacao VARCHAR(255) DEFAULT '',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_mov_medicamento (medicamento_id),
            INDEX idx_mov_created (created_at),
            INDEX idx_mov_tipo (tipo),
            INDEX idx_mov_confirmacao (confirmacao_id)
        )");

        // Migração leve: se movimentacoes já existia (de uma versão anterior a este recurso) sem
        // a coluna confirmacao_id, adiciona agora. Entradas antigas ficam com confirmacao_id NULL
        // e o relatório as agrupa de forma aproximada (mesmo usuário + mesmo minuto).
        $temConfirmacaoId = (bool)$db->query("SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'movimentacoes' AND COLUMN_NAME = 'confirmacao_id'")->fetchColumn();
        if (!$temConfirmacaoId) {
            $db->exec("ALTER TABLE movimentacoes ADD COLUMN confirmacao_id INT NULL AFTER lote_id, ADD INDEX idx_mov_confirmacao (confirmacao_id)");
        }

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
            dados_completos LONGTEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            atualizado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_medicamentos_ggrem (codigo_ggrem),
            INDEX idx_medicamentos_substancia (substancia),
            INDEX idx_medicamentos_produto (produto),
            INDEX idx_medicamentos_laboratorio (laboratorio),
            INDEX idx_medicamentos_registro (registro),
            INDEX idx_medicamentos_ean1 (ean_1)
        )");

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

        // Primeiro acesso: sem nenhum usuário cadastrado ainda, semeia um Administrador padrão
        // para não deixar a aplicação sem porta de entrada.
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

    // Conta pode ter sido desabilitada por um administrador depois do login — encerra a sessão
    // imediatamente em vez de deixar a próxima requisição autenticada passar mesmo assim.
    $db = getDB();
    $stmt = $db->prepare("SELECT enabled FROM local_users WHERE username = :u");
    $stmt->bindValue(':u', $_SESSION['user_logged_in']);
    $stmt->execute();
    $enabled = $stmt->fetchColumn();
    if ($enabled === false || (int)$enabled === 0) {
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
    $username = $_SESSION['user_logged_in'] ?? null;
    if (!$username) return 'usuario';
    static $cachedRole = null;
    static $cachedUser = null;
    if ($cachedUser === $username && $cachedRole !== null) {
        return $cachedRole;
    }
    $db = getDB();
    $stmt = $db->prepare("SELECT role FROM local_users WHERE username = :u");
    $stmt->bindValue(':u', $username);
    $stmt->execute();
    $role = $stmt->fetchColumn();
    $cachedUser = $username;
    $cachedRole = $role !== false ? $role : 'usuario';
    return $cachedRole;
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
