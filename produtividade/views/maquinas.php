<?php
requireAdmin();
$db = getDB();
$formError = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    csrfVerify();
    $action = $_POST['action'];

    if ($action === 'add' || $action === 'update') {
        $nome = trim($_POST['nome'] ?? '');
        $host = trim($_POST['host'] ?? '');
        $porta = (int)($_POST['porta'] ?? AW_PORTA_PADRAO);
        $usuarioResp = trim($_POST['usuario_responsavel'] ?? '');
        $intervalo = max(1, (int)($_POST['intervalo_sync_min'] ?? 5));
        $ativo = isset($_POST['ativo']) ? 1 : 0;

        if ($nome === '' || $host === '') {
            $formError = 'Nome e host (IP ou hostname) são obrigatórios.';
        } elseif ($porta < 1 || $porta > 65535) {
            $formError = 'Porta inválida.';
        } else {
            try {
                if ($action === 'add') {
                    $stmt = $db->prepare("INSERT INTO maquinas (nome, host, porta, usuario_responsavel, intervalo_sync_min, ativo) VALUES (:n, :h, :p, :u, :i, :a)");
                    $stmt->execute([':n' => $nome, ':h' => $host, ':p' => $porta, ':u' => $usuarioResp, ':i' => $intervalo, ':a' => $ativo]);
                } else {
                    $id = (int)($_POST['id'] ?? 0);
                    $stmt = $db->prepare("UPDATE maquinas SET nome = :n, host = :h, porta = :p, usuario_responsavel = :u, intervalo_sync_min = :i, ativo = :a WHERE id = :id");
                    $stmt->execute([':n' => $nome, ':h' => $host, ':p' => $porta, ':u' => $usuarioResp, ':i' => $intervalo, ':a' => $ativo, ':id' => $id]);
                }
                header("Location: index.php?page=maquinas");
                exit;
            } catch (PDOException $e) {
                $formError = (strpos($e->getMessage(), 'Duplicate') !== false)
                    ? 'Já existe uma máquina cadastrada com esse host e porta.'
                    : 'Erro ao salvar: ' . $e->getMessage();
            }
        }
    } elseif ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $db->prepare("DELETE FROM maquinas WHERE id = :id")->execute([':id' => $id]);
        header("Location: index.php?page=maquinas");
        exit;
    }
}

$editing = null;
if (isset($_GET['edit'])) {
    $stmt = $db->prepare("SELECT * FROM maquinas WHERE id = :id");
    $stmt->bindValue(':id', (int)$_GET['edit'], PDO::PARAM_INT);
    $stmt->execute();
    $editing = $stmt->fetch();
}

$maquinas = $db->query("SELECT * FROM maquinas ORDER BY nome ASC")->fetchAll();

// Contagem de eventos por máquina, só para exibir na listagem (não bloqueia nada).
$totaisEventos = [];
foreach ($db->query("SELECT maquina_id, COUNT(*) AS n FROM eventos GROUP BY maquina_id") as $row) {
    $totaisEventos[$row['maquina_id']] = (int)$row['n'];
}
?>
<div class="page-head">
    <div>
        <h1 class="page-title">Máquinas</h1>
        <div class="page-sub">Cadastre cada máquina com o aw-server (ActivityWatch) exposto na rede — por IP ou hostname</div>
    </div>
</div>

<div class="alert alert-warning">
    <strong>Segurança:</strong> a API do ActivityWatch não tem autenticação — qualquer host que alcançar a porta do aw-server
    consegue ler/gravar/apagar todo o histórico daquela máquina. Configure o firewall de cada máquina monitorada para liberar
    a porta (padrão 5600) <strong>apenas</strong> para o IP deste servidor, nunca para a rede inteira.
</div>

<div class="row g-3">
    <div class="col-md-4">
        <div class="card">
            <div class="card-header"><?= $editing ? 'Editar Máquina' : 'Nova Máquina' ?></div>
            <div class="card-body">
                <?php if ($formError): ?><div class="alert alert-danger py-2"><?= htmlspecialchars($formError) ?></div><?php endif; ?>
                <form method="POST" id="formMaquina">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="<?= $editing ? 'update' : 'add' ?>">
                    <?php if ($editing): ?><input type="hidden" name="id" value="<?= (int)$editing['id'] ?>"><?php endif; ?>
                    <div class="mb-2">
                        <label class="form-label">Nome</label>
                        <input type="text" name="nome" class="form-control" placeholder="Ex: Recepção - PC01" value="<?= htmlspecialchars($editing['nome'] ?? '') ?>" required>
                    </div>
                    <div class="mb-2">
                        <label class="form-label">Host (IP ou hostname)</label>
                        <input type="text" name="host" id="campoHost" class="form-control" placeholder="192.168.1.50 ou pc-recepcao" value="<?= htmlspecialchars($editing['host'] ?? '') ?>" required>
                    </div>
                    <div class="mb-2">
                        <label class="form-label">Porta</label>
                        <input type="number" name="porta" id="campoPorta" class="form-control" value="<?= htmlspecialchars($editing['porta'] ?? AW_PORTA_PADRAO) ?>" required>
                    </div>
                    <div class="mb-2">
                        <label class="form-label">Usuário responsável (opcional)</label>
                        <input type="text" name="usuario_responsavel" class="form-control" value="<?= htmlspecialchars($editing['usuario_responsavel'] ?? '') ?>">
                    </div>
                    <div class="mb-2">
                        <label class="form-label">Sincronizar a cada (minutos)</label>
                        <input type="number" name="intervalo_sync_min" class="form-control" min="1" value="<?= htmlspecialchars($editing['intervalo_sync_min'] ?? 5) ?>">
                    </div>
                    <div class="form-check mb-3">
                        <input class="form-check-input" type="checkbox" name="ativo" id="ativoCheck" <?= ($editing === null || !empty($editing['ativo'])) ? 'checked' : '' ?>>
                        <label class="form-check-label" for="ativoCheck">Ativa (incluída na sincronização automática)</label>
                    </div>
                    <button type="button" id="btnTestar" class="btn btn-outline-info w-100 mb-2"><i class="bi bi-plug"></i> Testar Conexão</button>
                    <div id="resultadoTeste" class="small mb-2"></div>
                    <button class="btn btn-outline-success w-100"><?= $editing ? 'Salvar Alterações' : 'Criar Máquina' ?></button>
                    <?php if ($editing): ?><a href="index.php?page=maquinas" class="btn btn-outline-secondary w-100 mt-2">Cancelar Edição</a><?php endif; ?>
                </form>
            </div>
        </div>
    </div>
    <div class="col-md-8">
        <div class="table-responsive">
        <table class="table table-bordered bg-white align-middle table-actions-sticky">
            <thead class="table-dark"><tr><th>Nome</th><th>Host</th><th>aw-server</th><th>Eventos</th><th>Última Sync</th><th>Status</th><th>Ações</th></tr></thead>
            <tbody>
                <?php foreach ($maquinas as $m): ?>
                <tr>
                    <td>
                        <?= htmlspecialchars($m['nome']) ?>
                        <?php if (!$m['ativo']): ?><br><span class="badge bg-secondary">Inativa</span><?php endif; ?>
                        <?php if ($m['usuario_responsavel']): ?><div class="text-muted small"><?= htmlspecialchars($m['usuario_responsavel']) ?></div><?php endif; ?>
                    </td>
                    <td><code><?= htmlspecialchars($m['host']) ?>:<?= (int)$m['porta'] ?></code><div class="text-muted small">a cada <?= (int)$m['intervalo_sync_min'] ?> min</div></td>
                    <td><?= $m['aw_hostname'] ? htmlspecialchars($m['aw_hostname']) . '<br><span class="text-muted small">v' . htmlspecialchars($m['aw_versao']) . '</span>' : '<span class="text-muted">—</span>' ?></td>
                    <td><?= number_format($totaisEventos[$m['id']] ?? 0, 0, ',', '.') ?></td>
                    <td><small class="mono text-muted"><?= $m['ultimo_sync_at'] ? htmlspecialchars($m['ultimo_sync_at']) : 'nunca' ?></small></td>
                    <td>
                        <?php if ($m['ultimo_sync_status'] === 'ok'): ?><span class="badge bg-success">OK</span>
                        <?php elseif ($m['ultimo_sync_status'] === 'parcial'): ?><span class="badge bg-warning" title="<?= htmlspecialchars($m['ultimo_erro'] ?? '') ?>">Parcial</span>
                        <?php elseif ($m['ultimo_sync_status'] === 'erro'): ?><span class="badge bg-danger" title="<?= htmlspecialchars($m['ultimo_erro'] ?? '') ?>">Erro</span>
                        <?php else: ?><span class="badge bg-secondary">—</span><?php endif; ?>
                    </td>
                    <td class="text-nowrap">
                        <button type="button" class="btn btn-sm btn-outline-info btn-sync-now" data-id="<?= (int)$m['id'] ?>" title="Sincronizar agora"><i class="bi bi-arrow-repeat"></i></button>
                        <a href="index.php?page=maquinas&edit=<?= (int)$m['id'] ?>" class="btn btn-sm btn-outline-primary"><i class="bi bi-pencil"></i></a>
                        <form method="POST" class="d-inline" onsubmit="return confirm('Excluir esta máquina e todos os eventos coletados dela?');">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?= (int)$m['id'] ?>">
                            <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($maquinas)): ?><tr><td colspan="7" class="text-center text-muted py-3">Nenhuma máquina cadastrada.</td></tr><?php endif; ?>
            </tbody>
        </table>
        </div>
    </div>
</div>

<script>
document.getElementById('btnTestar').addEventListener('click', function () {
    var resultado = document.getElementById('resultadoTeste');
    var host = document.getElementById('campoHost').value.trim();
    var porta = document.getElementById('campoPorta').value.trim();
    if (!host) { resultado.innerHTML = '<span class="text-danger">Informe o host primeiro.</span>'; return; }
    resultado.innerHTML = '<span class="text-muted">Testando...</span>';
    var fd = new FormData();
    fd.append('csrf_token', '<?= csrfToken() ?>');
    fd.append('host', host);
    fd.append('porta', porta);
    fetch('ajax_testar_maquina.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(function (data) {
            if (data.ok) {
                var nBuckets = Object.keys(data.buckets || {}).length;
                resultado.innerHTML = '<span class="text-success"><i class="bi bi-check-circle"></i> Conectado: ' + (data.hostname || host) + ' (aw v' + data.versao + ') — ' + nBuckets + ' bucket(s) encontrado(s).</span>';
            } else {
                resultado.innerHTML = '<span class="text-danger"><i class="bi bi-x-circle"></i> ' + data.erro + '</span>';
            }
        })
        .catch(function () { resultado.innerHTML = '<span class="text-danger">Falha ao testar (erro de rede).</span>'; });
});

document.querySelectorAll('.btn-sync-now').forEach(function (btn) {
    btn.addEventListener('click', function () {
        var icon = btn.querySelector('i');
        icon.className = 'bi bi-hourglass-split';
        btn.disabled = true;
        var fd = new FormData();
        fd.append('csrf_token', '<?= csrfToken() ?>');
        fd.append('id', btn.dataset.id);
        fetch('ajax_sincronizar_maquina.php', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(function (data) {
                alert(data.ok ? ('Sincronização concluída: ' + data.eventos_novos + ' evento(s) novo(s)/atualizado(s).' + (data.aviso ? '\nAviso: ' + data.aviso : '')) : ('Erro: ' + data.erro));
                window.location.reload();
            })
            .catch(function () { alert('Falha ao sincronizar (erro de rede).'); btn.disabled = false; icon.className = 'bi bi-arrow-repeat'; });
    });
});
</script>
