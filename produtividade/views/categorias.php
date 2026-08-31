<?php
requireAdmin();
$db = getDB();
$formError = '';
$reprocessarMsg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    csrfVerify();
    $action = $_POST['action'];

    if ($action === 'add_categoria' || $action === 'update_categoria') {
        $nome = trim($_POST['nome'] ?? '');
        $cor = trim($_POST['cor'] ?? '#94a2b8');
        $pontuacao = (int)($_POST['pontuacao'] ?? 0);
        $ordem = (int)($_POST['ordem'] ?? 50);

        if ($nome === '') {
            $formError = 'Nome da categoria é obrigatório.';
        } elseif (!preg_match('/^#[0-9a-fA-F]{6}$/', $cor)) {
            $formError = 'Cor inválida (use o seletor de cor).';
        } else {
            if ($action === 'add_categoria') {
                $db->prepare("INSERT INTO categorias (nome, cor, pontuacao, ordem) VALUES (:n, :c, :p, :o)")
                   ->execute([':n' => $nome, ':c' => $cor, ':p' => $pontuacao, ':o' => $ordem]);
            } else {
                $id = (int)($_POST['id'] ?? 0);
                $db->prepare("UPDATE categorias SET nome = :n, cor = :c, pontuacao = :p, ordem = :o WHERE id = :id")
                   ->execute([':n' => $nome, ':c' => $cor, ':p' => $pontuacao, ':o' => $ordem, ':id' => $id]);
            }
            header("Location: index.php?page=categorias");
            exit;
        }
    } elseif ($action === 'delete_categoria') {
        $id = (int)($_POST['id'] ?? 0);
        $db->prepare("DELETE FROM categorias WHERE id = :id")->execute([':id' => $id]);
        header("Location: index.php?page=categorias");
        exit;
    } elseif ($action === 'add_regra') {
        $categoriaId = (int)($_POST['categoria_id'] ?? 0);
        $campo = in_array($_POST['campo'] ?? '', ['app', 'titulo', 'url'], true) ? $_POST['campo'] : 'app';
        $tipo = in_array($_POST['tipo'] ?? '', ['contem', 'regex'], true) ? $_POST['tipo'] : 'contem';
        $padrao = trim($_POST['padrao'] ?? '');
        $prioridade = (int)($_POST['prioridade'] ?? 50);

        if ($padrao === '' || $categoriaId <= 0) {
            $formError = 'Selecione a categoria e informe o padrão da regra.';
        } else {
            $db->prepare("INSERT INTO categoria_regras (categoria_id, campo, tipo, padrao, prioridade) VALUES (:cat, :campo, :tipo, :padrao, :prio)")
               ->execute([':cat' => $categoriaId, ':campo' => $campo, ':tipo' => $tipo, ':padrao' => $padrao, ':prio' => $prioridade]);
            header("Location: index.php?page=categorias");
            exit;
        }
    } elseif ($action === 'delete_regra') {
        $id = (int)($_POST['id'] ?? 0);
        $db->prepare("DELETE FROM categoria_regras WHERE id = :id")->execute([':id' => $id]);
        header("Location: index.php?page=categorias");
        exit;
    } elseif ($action === 'toggle_regra') {
        $id = (int)($_POST['id'] ?? 0);
        $db->prepare("UPDATE categoria_regras SET ativo = 1 - ativo WHERE id = :id")->execute([':id' => $id]);
        header("Location: index.php?page=categorias");
        exit;
    } elseif ($action === 'reprocessar') {
        // Reaplica as regras atuais sobre os eventos já coletados (útil depois de criar/editar
        // regras). Lê em lotes com LIMIT/OFFSET (em vez de trazer a tabela inteira pra memória ou
        // usar modo unbuffered — que impede rodar o UPDATE de cada linha na mesma conexão enquanto
        // o cursor de leitura ainda está aberto).
        set_time_limit(180);
        $leituraStmt = $db->prepare("SELECT id, app, titulo, url FROM eventos WHERE tipo IN ('window','web') ORDER BY id LIMIT :lim OFFSET :off");
        $update = $db->prepare("UPDATE eventos SET categoria_id = :cat WHERE id = :id");
        $loteTamanho = 2000;
        $total = 0;
        do {
            $leituraStmt->bindValue(':lim', $loteTamanho, PDO::PARAM_INT);
            $leituraStmt->bindValue(':off', $total, PDO::PARAM_INT);
            $leituraStmt->execute();
            $lote = $leituraStmt->fetchAll();
            foreach ($lote as $row) {
                $catId = classificarEvento($db, $row['app'], $row['titulo'], $row['url']);
                $update->execute([':cat' => $catId, ':id' => $row['id']]);
                $total++;
            }
        } while (count($lote) === $loteTamanho);
        $reprocessarMsg = "Reprocessamento concluído: {$total} evento(s) reclassificado(s).";
    }
}

$editing = null;
if (isset($_GET['edit'])) {
    $stmt = $db->prepare("SELECT * FROM categorias WHERE id = :id");
    $stmt->bindValue(':id', (int)$_GET['edit'], PDO::PARAM_INT);
    $stmt->execute();
    $editing = $stmt->fetch();
}

$categorias = $db->query("SELECT * FROM categorias ORDER BY ordem ASC, nome ASC")->fetchAll();
$regrasPorCategoria = [];
foreach ($db->query("SELECT * FROM categoria_regras ORDER BY prioridade ASC, id ASC") as $r) {
    $regrasPorCategoria[$r['categoria_id']][] = $r;
}

function labelPontuacao($p) {
    if ($p == 1) return '<span class="badge bg-success">Produtivo</span>';
    if ($p == -1) return '<span class="badge bg-danger">Improdutivo</span>';
    return '<span class="badge bg-secondary">Neutro</span>';
}
?>
<div class="page-head">
    <div>
        <h1 class="page-title">Categorias e Regras</h1>
        <div class="page-sub">Regras avaliadas por prioridade (menor primeiro) — a primeira que casar com app/título/URL decide a categoria do evento</div>
    </div>
    <form method="POST" onsubmit="return confirm('Reaplicar as regras atuais sobre todo o histórico de eventos? Isso pode levar alguns minutos em bases grandes.');">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="reprocessar">
        <button class="btn btn-outline-primary btn-sm"><i class="bi bi-arrow-clockwise"></i> Reprocessar Eventos Existentes</button>
    </form>
</div>

<?php if ($reprocessarMsg): ?><div class="alert alert-success"><?= htmlspecialchars($reprocessarMsg) ?></div><?php endif; ?>

<div class="row g-3">
    <div class="col-md-4">
        <div class="card mb-3">
            <div class="card-header"><?= $editing ? 'Editar Categoria' : 'Nova Categoria' ?></div>
            <div class="card-body">
                <?php if ($formError): ?><div class="alert alert-danger py-2"><?= htmlspecialchars($formError) ?></div><?php endif; ?>
                <form method="POST">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="<?= $editing ? 'update_categoria' : 'add_categoria' ?>">
                    <?php if ($editing): ?><input type="hidden" name="id" value="<?= (int)$editing['id'] ?>"><?php endif; ?>
                    <div class="mb-2">
                        <label class="form-label">Nome</label>
                        <input type="text" name="nome" class="form-control" value="<?= htmlspecialchars($editing['nome'] ?? '') ?>" required>
                    </div>
                    <div class="row g-2 mb-2">
                        <div class="col-4">
                            <label class="form-label">Cor</label>
                            <input type="color" name="cor" class="form-control form-control-color w-100" value="<?= htmlspecialchars($editing['cor'] ?? '#94a2b8') ?>">
                        </div>
                        <div class="col-8">
                            <label class="form-label">Pontuação</label>
                            <select name="pontuacao" class="form-select">
                                <option value="1" <?= (($editing['pontuacao'] ?? 0) == 1) ? 'selected' : '' ?>>Produtivo</option>
                                <option value="0" <?= (($editing['pontuacao'] ?? 0) == 0) ? 'selected' : '' ?>>Neutro</option>
                                <option value="-1" <?= (($editing['pontuacao'] ?? 0) == -1) ? 'selected' : '' ?>>Improdutivo</option>
                            </select>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Ordem de exibição</label>
                        <input type="number" name="ordem" class="form-control" value="<?= htmlspecialchars($editing['ordem'] ?? 50) ?>">
                    </div>
                    <button class="btn btn-outline-success w-100"><?= $editing ? 'Salvar' : 'Criar Categoria' ?></button>
                    <?php if ($editing): ?><a href="index.php?page=categorias" class="btn btn-outline-secondary w-100 mt-2">Cancelar</a><?php endif; ?>
                </form>
            </div>
        </div>

        <div class="card">
            <div class="card-header">Nova Regra</div>
            <div class="card-body">
                <form method="POST">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="add_regra">
                    <div class="mb-2">
                        <label class="form-label">Categoria</label>
                        <select name="categoria_id" class="form-select" required>
                            <?php foreach ($categorias as $c): ?>
                                <option value="<?= (int)$c['id'] ?>"><?= htmlspecialchars($c['nome']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="row g-2 mb-2">
                        <div class="col-6">
                            <label class="form-label">Campo</label>
                            <select name="campo" class="form-select">
                                <option value="app">Aplicativo</option>
                                <option value="titulo">Título da janela</option>
                                <option value="url">URL</option>
                            </select>
                        </div>
                        <div class="col-6">
                            <label class="form-label">Tipo</label>
                            <select name="tipo" class="form-select">
                                <option value="contem">Contém</option>
                                <option value="regex">Regex</option>
                            </select>
                        </div>
                    </div>
                    <div class="mb-2">
                        <label class="form-label">Padrão</label>
                        <input type="text" name="padrao" class="form-control" placeholder="Ex: chrome, youtube.com, ^Word" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Prioridade (menor = avaliada primeiro)</label>
                        <input type="number" name="prioridade" class="form-control" value="50">
                    </div>
                    <button class="btn btn-outline-success w-100">Adicionar Regra</button>
                </form>
            </div>
        </div>
    </div>

    <div class="col-md-8">
        <div class="entity-list">
        <?php foreach ($categorias as $cat): ?>
            <div class="entity-card">
                <div class="entity-card-head">
                    <div class="entity-title-wrap">
                        <span class="cat-dot mt-1" style="background: <?= htmlspecialchars($cat['cor']) ?>"></span>
                        <div>
                            <div class="entity-title"><?= htmlspecialchars($cat['nome']) ?></div>
                            <div class="entity-sub">prioridade base <?= (int)$cat['ordem'] ?></div>
                        </div>
                    </div>
                    <div class="entity-badges">
                        <?= labelPontuacao($cat['pontuacao']) ?>
                        <a href="index.php?page=categorias&edit=<?= (int)$cat['id'] ?>" class="btn btn-sm btn-outline-primary"><i class="bi bi-pencil"></i></a>
                        <form method="POST" class="d-inline" onsubmit="return confirm('Excluir esta categoria e suas regras? Eventos já classificados nela ficam sem categoria.');">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="delete_categoria">
                            <input type="hidden" name="id" value="<?= (int)$cat['id'] ?>">
                            <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                        </form>
                    </div>
                </div>
                <table class="table table-sm mb-0 mt-2">
                    <thead><tr><th>Prior.</th><th>Campo</th><th>Tipo</th><th>Padrão</th><th></th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ($regrasPorCategoria[$cat['id']] ?? [] as $r): ?>
                        <tr class="<?= $r['ativo'] ? '' : 'text-muted' ?>">
                            <td><?= (int)$r['prioridade'] ?></td>
                            <td><?= htmlspecialchars($r['campo']) ?></td>
                            <td><?= htmlspecialchars($r['tipo']) ?></td>
                            <td><code><?= htmlspecialchars($r['padrao']) ?></code></td>
                            <td class="text-nowrap">
                                <form method="POST" class="d-inline">
                                    <?= csrfField() ?>
                                    <input type="hidden" name="action" value="toggle_regra">
                                    <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                                    <button class="btn btn-sm btn-outline-secondary"><?= $r['ativo'] ? 'Desativar' : 'Ativar' ?></button>
                                </form>
                            </td>
                            <td>
                                <form method="POST" class="d-inline" onsubmit="return confirm('Excluir esta regra?');">
                                    <?= csrfField() ?>
                                    <input type="hidden" name="action" value="delete_regra">
                                    <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                                    <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($regrasPorCategoria[$cat['id']])): ?>
                        <tr><td colspan="6" class="text-center text-muted py-2">Nenhuma regra nesta categoria.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        <?php endforeach; ?>
        <?php if (empty($categorias)): ?><div class="text-muted text-center py-3">Nenhuma categoria cadastrada.</div><?php endif; ?>
        </div>
    </div>
</div>
