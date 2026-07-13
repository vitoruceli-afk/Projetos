#!/bin/sh
# ============================================================================
# Deploy: Conta Telefonia no cadastro de Cobrancas + rateio por Conta
# Gerado em 2026-07-09
#
# O QUE ESTE SCRIPT FAZ
#   1. Faz backup (mysqldump) da tabela telefonia_cobrancas.
#   2. Adiciona a coluna telefonia_cobrancas.conta_telefonia (idempotente,
#      nao falha se a coluna ja existir).
#   3. Sobrescreve os arquivos PHP alterados nesta sessao, fazendo backup
#      de cada um (sufixo .bak-<timestamp>) antes de gravar o novo conteudo.
#   4. Roda "php -l" em cada arquivo gravado, se o PHP CLI estiver disponivel.
#
# O QUE ESTE SCRIPT NAO FAZ
#   - Nao altera config/config.php (ex.: base_url). Isso e especifico de
#     cada ambiente; confira manualmente se necessario.
#   - Nao reinicia Apache/PHP-FPM (normalmente nao e necessario para
#     alteracoes em arquivos .php simples).
#
# USO
#   chmod +x deploy_conta_telefonia.sh
#   DEPLOY_DB_PASS='sua_senha_aqui' ./deploy_conta_telefonia.sh /caminho/para/rateio
#
# IMPORTANTE
#   - A senha do banco NAO fica gravada neste arquivo (de proposito, para nao
#     deixar credencial em texto plano em disco). Ela deve ser passada em
#     tempo de execucao pela variavel de ambiente DEPLOY_DB_PASS, conforme
#     o exemplo de uso acima. Prefira digitar o comando direto no shell
#     (sem salvar em historico/scripts) ou usar um cofre de segredos.
#   - Recomenda-se rodar em horario de baixo uso.
# ============================================================================

set -eu

DB_HOST="localhost"
DB_NAME="rateio"
DB_USER="root"

if [ -z "${DEPLOY_DB_PASS:-}" ]; then
    echo "Defina a variavel de ambiente DEPLOY_DB_PASS com a senha do MySQL antes de rodar." >&2
    echo "Exemplo: DEPLOY_DB_PASS='sua_senha' $0 /caminho/para/rateio" >&2
    exit 1
fi
DB_PASS="$DEPLOY_DB_PASS"

if [ "$#" -lt 1 ]; then
    echo "Uso: DEPLOY_DB_PASS='sua_senha' $0 /caminho/para/rateio" >&2
    exit 1
fi

APP_DIR="$1"

if [ ! -d "$APP_DIR" ]; then
    echo "Diretorio nao encontrado: $APP_DIR" >&2
    exit 1
fi

TS="$(date +%Y%m%d-%H%M%S)"

backup_file() {
    src="$1"
    if [ -f "$src" ]; then
        cp "$src" "${src}.bak-${TS}"
        echo "    backup: ${src}.bak-${TS}"
    fi
}

echo "==> 1/4 Backup da tabela telefonia_cobrancas (mysqldump)..."
DUMP_FILE="${APP_DIR}/telefonia_cobrancas.bak-${TS}.sql"
mysqldump -h "$DB_HOST" -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" telefonia_cobrancas > "$DUMP_FILE"
echo "    OK -> $DUMP_FILE"

echo "==> 2/4 Aplicando migracao de banco (ADD COLUMN conta_telefonia)..."
mysql -h "$DB_HOST" -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" <<'SQL'
SET @add := (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'telefonia_cobrancas'
               AND COLUMN_NAME = 'conta_telefonia');
SET @sql := IF(@add = 0,
    'ALTER TABLE telefonia_cobrancas ADD COLUMN conta_telefonia VARCHAR(80) DEFAULT ''''',
    'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SQL
echo "    OK."

echo "==> 3/4 Atualizando arquivos da aplicacao em: $APP_DIR"

# ---- src/Models/TelefoniaConta.php ----------------------------------------
FILE="$APP_DIR/src/Models/TelefoniaConta.php"
backup_file "$FILE"
cat > "$FILE" <<'PHP_EOF'
<?php

declare(strict_types=1);

namespace App\Models;

/*
|--------------------------------------------------------------------------
| TelefoniaConta  (Rateio Telefonia -> Contas)
|--------------------------------------------------------------------------
|
| Campos: nome do usuário, número de telefone, operadora,
| PEP (ref. tabela peps), Valor (consumo do mês) e Conta Telefonia.
|
*/
final class TelefoniaConta extends BaseModel
{
    protected static string $tabela = 'telefonia_contas';

    public static function listar(string $busca = ''): array
    {
        $sql = '
            SELECT
                c.id,
                c.nome_usuario,
                c.telefone,
                c.operadora,
                c.conta_telefonia,
                c.valor,
                c.pep_id,
                p.pep       AS pep,
                p.projeto   AS projeto
            FROM telefonia_contas c
            LEFT JOIN peps p ON p.id = c.pep_id
        ';

        $params = [];
        $termo  = trim($busca);

        if ($termo !== '') {
            $sql .= '
                WHERE c.nome_usuario LIKE ?
                   OR c.telefone LIKE ?
                   OR c.operadora LIKE ?
                   OR c.conta_telefonia LIKE ?
                   OR c.valor LIKE ?
                   OR p.pep LIKE ?
                   OR p.projeto LIKE ?
            ';
            $like   = '%' . $termo . '%';
            $params = array_fill(0, 7, $like);
        }

        $sql .= ' ORDER BY c.nome_usuario';

        $stmt = self::pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public static function criar(
        string $nome,
        string $telefone,
        string $operadora,
        int $pepId,
        float $valor,
        string $contaTelefonia
    ): int {
        $stmt = self::pdo()->prepare('
            INSERT INTO telefonia_contas
                (nome_usuario, telefone, operadora, pep_id, valor, conta_telefonia)
            VALUES (?, ?, ?, ?, ?, ?)
        ');
        $stmt->execute([$nome, $telefone, $operadora, $pepId, $valor, $contaTelefonia]);
        return (int) self::pdo()->lastInsertId();
    }

    public static function atualizar(
        int $id,
        string $nome,
        string $telefone,
        string $operadora,
        int $pepId,
        float $valor,
        string $contaTelefonia
    ): void {
        $stmt = self::pdo()->prepare('
            UPDATE telefonia_contas SET
                nome_usuario = ?, telefone = ?, operadora = ?,
                pep_id = ?, valor = ?, conta_telefonia = ?
            WHERE id = ?
        ');
        $stmt->execute([$nome, $telefone, $operadora, $pepId, $valor, $contaTelefonia, $id]);
    }

    /**
     * Atualiza somente o valor (consumo) de uma conta.
     */
    public static function atualizarValor(int $id, float $valor): void
    {
        $stmt = self::pdo()->prepare('UPDATE telefonia_contas SET valor = ? WHERE id = ?');
        $stmt->execute([$valor, $id]);
    }

    /**
     * Lista enxuta (id, telefone, nome) para a atualização em massa por CSV.
     */
    public static function paraAtualizacaoEmMassa(): array
    {
        return self::pdo()->query('
            SELECT id, telefone, nome_usuario FROM telefonia_contas ORDER BY nome_usuario
        ')->fetchAll();
    }

    /**
     * Valores distintos (não vazios) de "Conta Telefonia" já usados nas
     * contas, para alimentar o seletor no cadastro de cobranças.
     */
    public static function contasTelefoniaDistintas(): array
    {
        return self::pdo()->query("
            SELECT DISTINCT conta_telefonia
            FROM telefonia_contas
            WHERE conta_telefonia IS NOT NULL AND conta_telefonia <> ''
            ORDER BY conta_telefonia
        ")->fetchAll(\PDO::FETCH_COLUMN);
    }

    /**
     * Agregação por PEP usada no relatório financeiro Telefonia
     * (o "valor do usuário" é o valor lançado em cada conta).
     *
     * Quando $contaTelefonia é informada, restringe o rateio apenas aos
     * números de telefone que pertencem àquela Conta Telefonia.
     */
    public static function totaisPorPep(?string $contaTelefonia = null): array
    {
        $sql = '
            SELECT
                p.pep        AS pep,
                p.projeto    AS projeto,
                c.nome_usuario AS nome,
                c.telefone   AS email,
                c.conta_telefonia AS conta_telefonia,
                SUM(c.valor) AS valor_usuario
            FROM telefonia_contas c
            INNER JOIN peps p ON p.id = c.pep_id
        ';

        $params = [];
        if ($contaTelefonia !== null) {
            $sql .= ' WHERE c.conta_telefonia = ?';
            $params[] = $contaTelefonia;
        }

        $sql .= '
            GROUP BY c.id, p.pep, p.projeto, c.nome_usuario, c.telefone, c.conta_telefonia
            ORDER BY c.conta_telefonia, p.pep, c.nome_usuario
        ';

        $stmt = self::pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }
}
PHP_EOF
echo "    escrito: $FILE"

# ---- src/Models/TelefoniaCobranca.php -------------------------------------
FILE="$APP_DIR/src/Models/TelefoniaCobranca.php"
backup_file "$FILE"
cat > "$FILE" <<'PHP_EOF'
<?php

declare(strict_types=1);

namespace App\Models;

/*
|--------------------------------------------------------------------------
| TelefoniaCobranca  (Rateio Telefonia -> Cobranças)
|--------------------------------------------------------------------------
|
| Espelha o Rateio Microsoft: mês, ano e valor total.
|
*/
final class TelefoniaCobranca extends BaseModel
{
    protected static string $tabela = 'telefonia_cobrancas';
    protected static array $colunasBusca = ['mes', 'ano', 'valor_total', 'conta_telefonia'];

    public static function listar(string $busca = ''): array
    {
        [$where, $params] = self::clausulaBusca($busca, self::$colunasBusca);

        $sql = 'SELECT * FROM telefonia_cobrancas';
        if ($where !== '') {
            $sql .= ' WHERE ' . $where;
        }
        $sql .= ' ORDER BY ano DESC, mes DESC';

        $stmt = self::pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public static function criar(int $mes, int $ano, float $valor, string $contaTelefonia = ''): int
    {
        $stmt = self::pdo()->prepare('
            INSERT INTO telefonia_cobrancas (mes, ano, valor_total, conta_telefonia) VALUES (?, ?, ?, ?)
        ');
        $stmt->execute([$mes, $ano, $valor, $contaTelefonia]);
        return (int) self::pdo()->lastInsertId();
    }

    public static function atualizar(int $id, int $mes, int $ano, float $valor, string $contaTelefonia = ''): void
    {
        $stmt = self::pdo()->prepare('
            UPDATE telefonia_cobrancas SET mes = ?, ano = ?, valor_total = ?, conta_telefonia = ? WHERE id = ?
        ');
        $stmt->execute([$mes, $ano, $valor, $contaTelefonia, $id]);
    }

    public static function somaSelecionadas(array $ids): float
    {
        $ids = array_values(array_filter(array_map('intval', $ids)));
        if ($ids === []) {
            return 0.0;
        }
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $stmt = self::pdo()->prepare("SELECT SUM(valor_total) t FROM telefonia_cobrancas WHERE id IN ($ph)");
        $stmt->execute($ids);
        return (float) ($stmt->fetch()['t'] ?? 0);
    }
}
PHP_EOF
echo "    escrito: $FILE"

# ---- telefonia/cobrancas/form.php -----------------------------------------
FILE="$APP_DIR/telefonia/cobrancas/form.php"
backup_file "$FILE"
cat > "$FILE" <<'PHP_EOF'
<?php

declare(strict_types=1);

use App\Core\Auth;
use App\Core\Session;
use App\Models\TelefoniaCobranca;
use App\Models\TelefoniaConta;

require __DIR__ . '/../../includes/bootstrap.php';
Auth::exigirAdmin();

$id       = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$editando = $id > 0;
$registro = $editando ? TelefoniaCobranca::buscarPorId($id) : null;

if ($editando && $registro === null) {
    Session::flash('danger', 'Cobrança não encontrada.');
    header('Location: ' . url('telefonia/cobrancas/listar.php'));
    exit;
}

$contasTelefonia = TelefoniaConta::contasTelefoniaDistintas();
$erros = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $mes            = (int) ($_POST['mes'] ?? 0);
    $ano            = (int) ($_POST['ano'] ?? 0);
    $valor          = (float) str_replace(',', '.', $_POST['valor_total'] ?? '0');
    $contaTelefonia = trim($_POST['conta_telefonia'] ?? '');

    if ($mes < 1 || $mes > 12)   { $erros[] = 'Informe um mês válido (1 a 12).'; }
    if ($ano < 2000)             { $erros[] = 'Informe um ano válido.'; }
    if ($contaTelefonia === '') { $erros[] = 'Selecione a Conta Telefonia.'; }

    if ($erros === []) {
        if ($editando) {
            TelefoniaCobranca::atualizar($id, $mes, $ano, $valor, $contaTelefonia);
            Session::flash('success', 'Cobrança atualizada.');
        } else {
            TelefoniaCobranca::criar($mes, $ano, $valor, $contaTelefonia);
            Session::flash('success', 'Cobrança criada.');
        }
        header('Location: ' . url('telefonia/cobrancas/listar.php'));
        exit;
    }
}

$contexto     = 'telefonia';
$tituloPagina = $editando ? 'Editar Cobrança' : 'Nova Cobrança';
require __DIR__ . '/../../includes/header.php';
?>

<h2 class="mb-4"><?= e($tituloPagina) ?></h2>

<?php if ($erros !== []): ?>
    <div class="alert alert-danger">
        <?php foreach ($erros as $erro): ?><div><?= e($erro) ?></div><?php endforeach; ?>
    </div>
<?php endif; ?>

<div class="card shadow-sm">
    <div class="card-body">
        <form method="POST">
            <div class="mb-3">
                <label class="form-label">Mês</label>
                <input type="number" name="mes" min="1" max="12" class="form-control"
                       value="<?= e($_POST['mes'] ?? $registro['mes'] ?? '') ?>" required>
            </div>
            <div class="mb-3">
                <label class="form-label">Ano</label>
                <input type="number" name="ano" class="form-control"
                       value="<?= e($_POST['ano'] ?? $registro['ano'] ?? date('Y')) ?>" required>
            </div>
            <div class="mb-3">
                <label class="form-label">Valor Total (boleto)</label>
                <input type="number" step="0.01" name="valor_total" class="form-control"
                       value="<?= e($_POST['valor_total'] ?? $registro['valor_total'] ?? '') ?>" required>
            </div>
            <div class="mb-4">
                <label class="form-label">Conta Telefonia</label>
                <select name="conta_telefonia" class="form-select" required>
                    <option value="">-- Selecione --</option>
                    <?php
                    $contaTelefoniaVal = $_POST['conta_telefonia'] ?? $registro['conta_telefonia'] ?? '';
                    foreach ($contasTelefonia as $ct):
                    ?>
                        <option value="<?= e($ct) ?>" <?= $contaTelefoniaVal === $ct ? 'selected' : '' ?>>
                            <?= e($ct) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <?php if ($contasTelefonia === []): ?>
                    <div class="form-text text-warning">
                        Nenhuma Conta Telefonia cadastrada ainda em
                        <a href="<?= url('telefonia/contas/listar.php') ?>">Contas</a>.
                    </div>
                <?php endif; ?>
            </div>
            <button type="submit" class="btn btn-success">Salvar</button>
            <a href="<?= url('telefonia/cobrancas/listar.php') ?>" class="btn btn-secondary">Voltar</a>
        </form>
    </div>
</div>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
PHP_EOF
echo "    escrito: $FILE"

# ---- telefonia/cobrancas/listar.php ----------------------------------------
FILE="$APP_DIR/telefonia/cobrancas/listar.php"
backup_file "$FILE"
cat > "$FILE" <<'PHP_EOF'
<?php

declare(strict_types=1);

use App\Core\Auth;
use App\Models\TelefoniaCobranca;

$contexto     = 'telefonia';
$tituloPagina = 'Cobranças';

require __DIR__ . '/../../includes/header.php';

$busca     = trim($_GET['busca'] ?? '');
$cobrancas = TelefoniaCobranca::listar($busca);
$ehAdmin   = Auth::ehAdmin();

$meses = [1=>'Jan',2=>'Fev',3=>'Mar',4=>'Abr',5=>'Mai',6=>'Jun',
          7=>'Jul',8=>'Ago',9=>'Set',10=>'Out',11=>'Nov',12=>'Dez'];
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h2 class="mb-0">Cobranças</h2>
    <?php if ($ehAdmin): ?>
        <a href="<?= url('telefonia/cobrancas/form.php') ?>" class="btn btn-danger">
            <i class="bi bi-plus-lg"></i> Nova Cobrança
        </a>
    <?php endif; ?>
</div>

<form method="GET" class="filtro-bar d-flex flex-wrap gap-2 align-items-center mb-3">
    <input type="text" name="busca" class="form-control" placeholder="Buscar em todas as colunas..."
           value="<?= e($busca) ?>">
    <button class="btn btn-outline-secondary"><i class="bi bi-search"></i> Filtrar</button>
    <a href="<?= url('telefonia/cobrancas/listar.php') ?>" class="btn btn-outline-secondary">Limpar</a>
</form>

<form method="POST">
    <div class="d-flex flex-wrap gap-2 mb-2">
        <button type="submit" formaction="<?= url('telefonia/cobrancas/exportar.php') ?>" class="btn btn-success">
            <i class="bi bi-filetype-csv"></i> Exportar Selecionadas
        </button>
        <a href="<?= url('telefonia/cobrancas/exportar.php?todas=1&busca=' . urlencode($busca)) ?>"
           class="btn btn-outline-success">Exportar Todas</a>
        <?php if ($ehAdmin): ?>
            <button type="submit" formaction="<?= url('telefonia/cobrancas/excluir.php') ?>"
                    class="btn btn-danger ms-auto"
                    onclick="return confirm('Excluir as cobranças selecionadas?')">
                <i class="bi bi-trash"></i> Excluir Selecionadas
            </button>
        <?php endif; ?>
    </div>

    <div class="card shadow-sm">
        <div class="card-body table-responsive">
            <table class="table table-bordered table-hover align-middle">
                <thead class="table-dark">
                    <tr>
                        <th width="40"><input type="checkbox" id="selecionarTodos"></th>
                        <th>Mês</th>
                        <th>Ano</th>
                        <th width="160">Valor Total</th>
                        <th>Conta Telefonia</th>
                        <?php if ($ehAdmin): ?><th width="160">Ações</th><?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($cobrancas as $c): ?>
                    <tr>
                        <td><input type="checkbox" name="ids[]" value="<?= $c['id'] ?>" class="checkbox-item"></td>
                        <td><?= e($meses[(int) $c['mes']] ?? $c['mes']) ?> (<?= e($c['mes']) ?>)</td>
                        <td><?= e($c['ano']) ?></td>
                        <td><?= money($c['valor_total']) ?></td>
                        <td><?= e($c['conta_telefonia']) ?></td>
                        <?php if ($ehAdmin): ?>
                            <td>
                                <a href="<?= url('telefonia/cobrancas/form.php?id=' . $c['id']) ?>"
                                   class="btn btn-warning btn-sm">Editar</a>
                                <a href="<?= url('telefonia/cobrancas/excluir.php?id=' . $c['id']) ?>"
                                   class="btn btn-danger btn-sm"
                                   onclick="return confirm('Excluir esta cobrança?')">Excluir</a>
                            </td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
                <?php if ($cobrancas === []): ?>
                    <tr><td colspan="<?= $ehAdmin ? 6 : 5 ?>" class="text-center text-muted">
                        Nenhuma cobrança encontrada.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</form>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
PHP_EOF
echo "    escrito: $FILE"

# ---- telefonia/cobrancas/exportar.php --------------------------------------
FILE="$APP_DIR/telefonia/cobrancas/exportar.php"
backup_file "$FILE"
cat > "$FILE" <<'PHP_EOF'
<?php

declare(strict_types=1);

use App\Core\Auth;
use App\Core\Csv;
use App\Models\TelefoniaCobranca;

require __DIR__ . '/../../includes/bootstrap.php';
Auth::exigirLogin();

$ids   = array_map('intval', (array) ($_POST['ids'] ?? []));
$busca = trim($_GET['busca'] ?? $_POST['busca'] ?? '');

$cobrancas = TelefoniaCobranca::listar($busca);

if ($ids !== []) {
    $cobrancas = array_filter($cobrancas, static fn(array $c) => in_array((int) $c['id'], $ids, true));
}

$cabecalho = ['Mes', 'Ano', 'Valor Total', 'Conta Telefonia'];

$linhas = array_map(static fn(array $c) => [
    $c['mes'],
    $c['ano'],
    number_format((float) $c['valor_total'], 2, ',', '.'),
    $c['conta_telefonia'],
], $cobrancas);

Csv::download('cobrancas_telefonia.csv', $cabecalho, $linhas);
PHP_EOF
echo "    escrito: $FILE"

# ---- telefonia/relatorios/index.php ----------------------------------------
FILE="$APP_DIR/telefonia/relatorios/index.php"
backup_file "$FILE"
cat > "$FILE" <<'PHP_EOF'
<?php

declare(strict_types=1);

use App\Models\TelefoniaCobranca;

$contexto     = 'telefonia';
$tituloPagina = 'Relatórios';

require __DIR__ . '/../../includes/header.php';

$cobrancas = TelefoniaCobranca::listar();
?>

<h2 class="mb-4">Relatório Financeiro por PEP</h2>

<p class="text-muted">
    Selecione as cobranças para gerar o rateio proporcional por PEP,
    usando o valor lançado em cada conta. O resultado é armazenado em
    <strong>Rateios Gerados</strong> e pode ser exportado em CSV.
</p>

<?php if ($cobrancas === []): ?>
    <div class="alert alert-warning">
        Nenhuma cobrança cadastrada. Cadastre em
        <a href="<?= url('telefonia/cobrancas/listar.php') ?>">Cobranças</a>.
    </div>
<?php else: ?>
<form action="<?= url('telefonia/relatorios/gerar.php') ?>" method="POST">

    <div class="card shadow-sm mb-3">
        <div class="card-header">Identificação do rateio</div>
        <div class="card-body">
            <input type="text" name="descricao" class="form-control"
                   placeholder="Descrição (opcional)">
        </div>
    </div>

    <div class="card shadow-sm mb-3">
        <div class="card-header">Selecione as cobranças</div>
        <div class="card-body">
            <?php foreach ($cobrancas as $c): ?>
                <div class="form-check mb-2">
                    <input class="form-check-input" type="checkbox" name="cobrancas[]"
                           value="<?= $c['id'] ?>" id="cob<?= $c['id'] ?>">
                    <label class="form-check-label" for="cob<?= $c['id'] ?>">
                        <strong>Cobrança #<?= $c['id'] ?></strong>
                        &middot; <?= e($c['mes']) ?>/<?= e($c['ano']) ?>
                        &middot; <?= money($c['valor_total']) ?>
                        &middot; Conta Telefonia:
                        <span class="badge bg-secondary"><?= e($c['conta_telefonia'] ?: '(sem conta)') ?></span>
                    </label>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <button type="submit" class="btn btn-success">
        <i class="bi bi-calculator"></i> Gerar e Armazenar Rateio
    </button>
    <a href="<?= url('telefonia/rateios/listar.php') ?>" class="btn btn-outline-secondary">
        Ver Rateios Gerados
    </a>
</form>
<?php endif; ?>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
PHP_EOF
echo "    escrito: $FILE"

# ---- telefonia/relatorios/gerar.php ----------------------------------------
FILE="$APP_DIR/telefonia/relatorios/gerar.php"
backup_file "$FILE"
cat > "$FILE" <<'PHP_EOF'
<?php

declare(strict_types=1);

use App\Core\Auth;
use App\Core\Session;
use App\Core\Database;
use App\Core\RateioFinanceiro;
use App\Models\TelefoniaCobranca;
use App\Models\TelefoniaConta;
use App\Models\RateioHistorico;

require __DIR__ . '/../../includes/bootstrap.php';
Auth::exigirLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_POST['cobrancas'])) {
    Session::flash('danger', 'Selecione ao menos uma cobrança.');
    header('Location: ' . url('telefonia/relatorios/index.php'));
    exit;
}

$idsCobranca = array_map('intval', (array) $_POST['cobrancas']);
$descricao   = trim($_POST['descricao'] ?? '');

$pdo  = Database::pdo();
$ph   = implode(',', array_fill(0, count($idsCobranca), '?'));
$stmt = $pdo->prepare("SELECT * FROM telefonia_cobrancas WHERE id IN ($ph)");
$stmt->execute($idsCobranca);
$cobrancas = $stmt->fetchAll();

if ($cobrancas === []) {
    Session::flash('danger', 'Cobranças não encontradas.');
    header('Location: ' . url('telefonia/relatorios/index.php'));
    exit;
}

// Cada cobrança pertence a uma Conta Telefonia. Agrupa o valor do boleto por
// conta e rateia cada grupo somente entre os números que pertencem a ela.
$boletoPorConta = [];
foreach ($cobrancas as $c) {
    $conta = trim((string) $c['conta_telefonia']);
    $boletoPorConta[$conta] = ($boletoPorConta[$conta] ?? 0.0) + (float) $c['valor_total'];
}

$linhas        = [];
$valorBoleto   = 0.0;
$totalContas   = 0.0;
$diferenca     = 0.0;
$totalFinal    = 0.0;
$contasSemPep  = [];

foreach ($boletoPorConta as $conta => $valor) {
    // Chaves de array numéricas (ex.: "434545780") são convertidas para int
    // pelo PHP; forçamos de volta para string por causa do strict_types.
    $conta = (string) $conta;

    $contasDaConta = TelefoniaConta::totaisPorPep($conta === '' ? null : $conta);

    if ($contasDaConta === []) {
        $contasSemPep[] = $conta !== '' ? $conta : '(sem Conta Telefonia)';
        continue;
    }

    $resultadoConta = RateioFinanceiro::calcular($contasDaConta, $valor);

    $linhas      = array_merge($linhas, $resultadoConta['linhas']);
    $valorBoleto += $resultadoConta['valor_boleto'];
    $totalContas += $resultadoConta['total_contas'];
    $diferenca   += $resultadoConta['diferenca'];
    $totalFinal  += $resultadoConta['total_final'];
}

if ($linhas === []) {
    Session::flash('danger', 'Nenhum número de telefone encontrado para as Contas Telefonia das cobranças selecionadas.');
    header('Location: ' . url('telefonia/relatorios/index.php'));
    exit;
}

usort($cobrancas, static fn(array $a, array $b) => [(int) $b['ano'], (int) $b['mes']] <=> [(int) $a['ano'], (int) $a['mes']]);
$ref = $cobrancas[0];

if ($descricao === '') {
    $descricao = 'Rateio Telefonia ' . $ref['mes'] . '/' . $ref['ano'];
}

$id = RateioHistorico::registrar(
    'telefonia',
    (int) $ref['mes'],
    (int) $ref['ano'],
    $descricao,
    Auth::nome(),
    round($valorBoleto, 2),
    round($totalContas, 2),
    round($diferenca, 2),
    round($totalFinal, 2),
    $linhas
);

if ($contasSemPep !== []) {
    Session::flash('warning', 'Atenção: sem números cadastrados para a(s) Conta Telefonia: '
        . implode(', ', $contasSemPep) . '. O valor dessa(s) cobrança(s) não entrou no rateio.');
}

Session::flash('success', 'Rateio gerado e armazenado com sucesso.');
header('Location: ' . url('telefonia/rateios/ver.php?id=' . $id));
exit;
PHP_EOF
echo "    escrito: $FILE"

echo "==> 4/4 Verificando sintaxe PHP dos arquivos gravados..."
if command -v php >/dev/null 2>&1; then
    for f in \
        "$APP_DIR/src/Models/TelefoniaConta.php" \
        "$APP_DIR/src/Models/TelefoniaCobranca.php" \
        "$APP_DIR/telefonia/cobrancas/form.php" \
        "$APP_DIR/telefonia/cobrancas/listar.php" \
        "$APP_DIR/telefonia/cobrancas/exportar.php" \
        "$APP_DIR/telefonia/relatorios/index.php" \
        "$APP_DIR/telefonia/relatorios/gerar.php"
    do
        php -l "$f"
    done
else
    echo "    php CLI nao encontrado no PATH; pulei a verificacao de sintaxe."
fi

echo ""
echo "Concluido."
echo "  - Dump da tabela antes da migracao: $DUMP_FILE"
echo "  - Backups dos arquivos PHP: sufixo .bak-${TS} ao lado de cada arquivo"
echo ""
echo "Lembrete: confirme manualmente config/config.php (app.base_url) se este"
echo "ambiente de producao estiver em uma subpasta diferente da usada em dev."
