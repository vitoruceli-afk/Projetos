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
        $setor = trim($_POST['setor'] ?? '');
        $intervalo = max(1, (int)($_POST['intervalo_sync_min'] ?? 5));
        $ativo = isset($_POST['ativo']) ? 1 : 0;

        if ($nome === '' || $host === '') {
            $formError = 'Nome e host (IP ou hostname) são obrigatórios.';
        } elseif ($porta < 1 || $porta > 65535) {
            $formError = 'Porta inválida.';
        } else {
            try {
                if ($action === 'add') {
                    $stmt = $db->prepare("INSERT INTO maquinas (nome, host, porta, usuario_responsavel, setor, intervalo_sync_min, ativo) VALUES (:n, :h, :p, :u, :s, :i, :a)");
                    $stmt->execute([':n' => $nome, ':h' => $host, ':p' => $porta, ':u' => $usuarioResp, ':s' => $setor, ':i' => $intervalo, ':a' => $ativo]);
                } else {
                    $id = (int)($_POST['id'] ?? 0);
                    $stmt = $db->prepare("UPDATE maquinas SET nome = :n, host = :h, porta = :p, usuario_responsavel = :u, setor = :s, intervalo_sync_min = :i, ativo = :a WHERE id = :id");
                    $stmt->execute([':n' => $nome, ':h' => $host, ':p' => $porta, ':u' => $usuarioResp, ':s' => $setor, ':i' => $intervalo, ':a' => $ativo, ':id' => $id]);
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
    } elseif ($action === 'desvincular_ou') {
        // Solta o vínculo com a OU (setor volta a ser texto livre, editável aqui) sem mexer no AD
        // nem na própria máquina — mantém o texto atual como ponto de partida.
        $id = (int)($_POST['id'] ?? 0);
        $db->prepare("UPDATE maquinas SET ou_id = NULL WHERE id = :id")->execute([':id' => $id]);
        header("Location: index.php?page=maquinas&edit=" . $id);
        exit;
    } elseif ($action === 'salvar_instalacao_config') {
        $adminUsuario = trim($_POST['admin_usuario'] ?? '');
        $adminSenha = $_POST['admin_senha'] ?? '';
        $msiPath = trim($_POST['msi_path'] ?? '');
        $timeout = max(30, (int)($_POST['timeout_segundos'] ?? INSTALL_TIMEOUT_PADRAO));
        $instalacaoConfig = getInstalacaoConfig($db);

        if ($msiPath === '') {
            $formError = 'Informe o caminho do arquivo MSI neste servidor.';
        } else {
            if ($adminSenha !== '') {
                $stmt = $db->prepare("UPDATE instalacao_remota_config SET admin_usuario = :u, admin_senha = :s, msi_path = :p, timeout_segundos = :t WHERE id = :id");
                $stmt->bindValue(':s', installEncrypt($adminSenha));
            } else {
                $stmt = $db->prepare("UPDATE instalacao_remota_config SET admin_usuario = :u, msi_path = :p, timeout_segundos = :t WHERE id = :id");
            }
            $stmt->bindValue(':u', $adminUsuario);
            $stmt->bindValue(':p', $msiPath);
            $stmt->bindValue(':t', $timeout, PDO::PARAM_INT);
            $stmt->bindValue(':id', $instalacaoConfig['id'], PDO::PARAM_INT);
            $stmt->execute();
            header("Location: index.php?page=maquinas");
            exit;
        }
    }
}

$instalacaoConfig = getInstalacaoConfig($db);

// setor_efetivo: nome ATUAL da OU vinculada (se houver), senão o texto livre gravado na máquina —
// é o valor que deve aparecer em qualquer lugar da tela, nunca m.setor puro.
$editing = null;
if (isset($_GET['edit'])) {
    $stmt = $db->prepare("SELECT m.*, ou.nome AS ou_nome, ou.ou_dn AS ou_dn,
                                  COALESCE(ou.nome, NULLIF(m.setor, '')) AS setor_efetivo
                           FROM maquinas m LEFT JOIN ad_ous ou ON ou.id = m.ou_id
                           WHERE m.id = :id");
    $stmt->bindValue(':id', (int)$_GET['edit'], PDO::PARAM_INT);
    $stmt->execute();
    $editing = $stmt->fetch();
}

$maquinas = $db->query("SELECT m.*, ou.nome AS ou_nome,
                                COALESCE(ou.nome, NULLIF(m.setor, '')) AS setor_efetivo
                         FROM maquinas m LEFT JOIN ad_ous ou ON ou.id = m.ou_id
                         ORDER BY m.nome ASC")->fetchAll();
$setoresExistentes = array_values(array_unique(array_filter(array_column($maquinas, 'setor_efetivo'))));
sort($setoresExistentes);

// Contagem de eventos por máquina, só para exibir na listagem (não bloqueia nada).
$totaisEventos = [];
foreach ($db->query("SELECT maquina_id, COUNT(*) AS n FROM eventos GROUP BY maquina_id") as $row) {
    $totaisEventos[$row['maquina_id']] = (int)$row['n'];
}

// Última tentativa de instalação remota de cada máquina (uma por maquina_id, a mais recente).
$ultimasInstalacoes = [];
foreach ($db->query("SELECT i1.* FROM instalacoes_remotas i1
                      LEFT JOIN instalacoes_remotas i2 ON i2.maquina_id = i1.maquina_id AND i2.id > i1.id
                      WHERE i2.id IS NULL") as $row) {
    $ultimasInstalacoes[$row['maquina_id']] = $row;
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

<div class="card mb-3">
    <div class="card-header">Instalação Remota do ActivityWatch (MSI)</div>
    <div class="card-body">
        <p class="text-muted small">
            Instala o pacote <code>ActivityWatch-Produtividade.msi</code> (ver <code>Instaladores\msi-build</code>) direto
            na máquina, via WMI — precisa de uma conta com direitos de administrador nela. A senha fica cifrada no banco.
        </p>
        <form method="POST" class="row g-2 align-items-end">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="salvar_instalacao_config">
            <div class="col-md-3">
                <label class="form-label">Usuário administrador</label>
                <input type="text" name="admin_usuario" class="form-control" placeholder="FAESA\svc_deploy" value="<?= htmlspecialchars($instalacaoConfig['admin_usuario']) ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label">Senha</label>
                <input type="password" name="admin_senha" class="form-control" placeholder="Deixe em branco para manter a atual">
            </div>
            <div class="col-md-4">
                <label class="form-label">Caminho do MSI neste servidor</label>
                <input type="text" name="msi_path" class="form-control" value="<?= htmlspecialchars($instalacaoConfig['msi_path']) ?>">
            </div>
            <div class="col-md-1">
                <label class="form-label">Timeout (s)</label>
                <input type="number" name="timeout_segundos" class="form-control" min="30" value="<?= (int)$instalacaoConfig['timeout_segundos'] ?>">
            </div>
            <div class="col-md-1">
                <button class="btn btn-outline-success w-100">Salvar</button>
            </div>
        </form>
    </div>
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
                        <label class="form-label">Setor</label>
                        <?php if ($editing && $editing['ou_id']): ?>
                            <input type="text" name="setor" class="form-control" value="<?= htmlspecialchars($editing['ou_nome']) ?>" readonly>
                            <div class="form-text">
                                Vinculado à OU <code><?= htmlspecialchars($editing['ou_dn']) ?></code> — acompanha o nome dela automaticamente.
                                Para digitar um setor livre aqui, <a href="#" onclick="if(confirm('Desvincular desta OU? O setor passa a ser um texto livre, editável nesta tela.')){document.getElementById('formDesvincularOu').submit();} return false;">desvincule</a> primeiro.
                            </div>
                        <?php else: ?>
                            <input type="text" name="setor" class="form-control" list="listaSetores" placeholder="Ex: Núcleo de Tecnologia da Informação" value="<?= htmlspecialchars($editing['setor'] ?? '') ?>">
                            <datalist id="listaSetores">
                                <?php foreach ($setoresExistentes as $s): ?><option value="<?= htmlspecialchars($s) ?>"><?php endforeach; ?>
                            </datalist>
                        <?php endif; ?>
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
                <?php if ($editing && $editing['ou_id']): ?>
                    <form method="POST" id="formDesvincularOu" class="d-none">
                        <?= csrfField() ?>
                        <input type="hidden" name="action" value="desvincular_ou">
                        <input type="hidden" name="id" value="<?= (int)$editing['id'] ?>">
                    </form>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="col-md-8">
        <div class="table-responsive">
        <table class="table table-bordered bg-white align-middle table-actions-sticky">
            <thead class="table-dark"><tr><th>Nome</th><th>Setor</th><th>Host</th><th>aw-server</th><th>Eventos</th><th>Última Sync</th><th>Status</th><th>Instalação MSI</th><th>Ações</th></tr></thead>
            <tbody>
                <?php foreach ($maquinas as $m): $inst = $ultimasInstalacoes[$m['id']] ?? null; ?>
                <tr>
                    <td>
                        <?= htmlspecialchars($m['nome']) ?>
                        <?php if (!$m['ativo']): ?><br><span class="badge bg-secondary">Inativa</span><?php endif; ?>
                        <?php if ($m['usuario_responsavel']): ?><div class="text-muted small"><?= htmlspecialchars($m['usuario_responsavel']) ?></div><?php endif; ?>
                    </td>
                    <td>
                        <?php if ($m['setor_efetivo']): ?>
                            <?= htmlspecialchars($m['setor_efetivo']) ?>
                            <?php if ($m['ou_id']): ?><i class="bi bi-link-45deg text-muted" title="Vinculado à OU — acompanha o nome dela"></i><?php endif; ?>
                        <?php else: ?><span class="text-muted">—</span><?php endif; ?>
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
                        <span class="badge-instalacao"
                              data-maquina-id="<?= (int)$m['id'] ?>"
                              data-instalacao-id="<?= $inst ? (int)$inst['id'] : '' ?>"
                              data-status="<?= $inst ? htmlspecialchars($inst['status']) : '' ?>">
                            <?php if (!$inst): ?><span class="text-muted">—</span>
                            <?php elseif ($inst['status'] === 'ok'): ?><span class="badge bg-success">OK</span>
                            <?php elseif ($inst['status'] === 'erro'): ?><span class="badge bg-danger" title="<?= htmlspecialchars($inst['mensagem']) ?>">Erro</span>
                            <?php else: ?><span class="badge bg-info">Em andamento…</span><?php endif; ?>
                        </span>
                        <?php if ($inst): ?>
                            <button type="button" class="btn btn-sm btn-outline-secondary btn-ver-log" data-instalacao-id="<?= (int)$inst['id'] ?>" title="Ver log"><i class="bi bi-file-text"></i></button>
                        <?php endif; ?>
                    </td>
                    <td class="text-nowrap">
                        <button type="button" class="btn btn-sm btn-outline-info btn-sync-now" data-id="<?= (int)$m['id'] ?>" title="Sincronizar agora"><i class="bi bi-arrow-repeat"></i></button>
                        <button type="button" class="btn btn-sm btn-outline-warning btn-install-now" data-id="<?= (int)$m['id'] ?>" title="Instalar ActivityWatch (MSI)"><i class="bi bi-cloud-download"></i></button>
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
                <?php if (empty($maquinas)): ?><tr><td colspan="9" class="text-center text-muted py-3">Nenhuma máquina cadastrada.</td></tr><?php endif; ?>
            </tbody>
        </table>
        </div>
    </div>
</div>

<div class="modal fade" id="modalLogInstalacao" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Log da Instalação</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
            </div>
            <div class="modal-body">
                <div id="logInstalacaoStatus" class="mb-2"></div>
                <pre id="logInstalacaoConteudo" class="small" style="white-space: pre-wrap; max-height: 60vh; overflow-y: auto;"></pre>
            </div>
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

function atualizarBadgeInstalacao(maquinaId, status, mensagem) {
    var badge = document.querySelector('.badge-instalacao[data-maquina-id="' + maquinaId + '"]');
    if (!badge) return;
    if (status === 'ok') {
        badge.innerHTML = '<span class="badge bg-success">OK</span>';
    } else if (status === 'erro') {
        badge.innerHTML = '<span class="badge bg-danger" title="' + (mensagem || '').replace(/"/g, '&quot;') + '">Erro</span>';
    } else {
        badge.innerHTML = '<span class="badge bg-info">Em andamento…</span>';
    }
}

function acompanharInstalacao(maquinaId, instalacaoId, btn) {
    var tentativas = 0;
    var intervalo = setInterval(function () {
        tentativas++;
        fetch('ajax_status_instalacao.php?id=' + instalacaoId)
            .then(r => r.json())
            .then(function (data) {
                if (!data.ok) { clearInterval(intervalo); return; }
                atualizarBadgeInstalacao(maquinaId, data.status, data.mensagem);
                if (data.status === 'ok' || data.status === 'erro') {
                    clearInterval(intervalo);
                    if (btn) { btn.disabled = false; btn.querySelector('i').className = 'bi bi-cloud-download'; }
                    window.location.reload();
                }
            })
            .catch(function () { clearInterval(intervalo); });
        // Desiste de acompanhar depois de ~10 min (o timeout do lado do servidor é 300s por
        // padrão) - a instalação continua rodando, só para de atualizar a tela sozinha.
        if (tentativas > 150) clearInterval(intervalo);
    }, 4000);
}

document.querySelectorAll('.btn-install-now').forEach(function (btn) {
    btn.addEventListener('click', function () {
        if (!confirm('Instalar o ActivityWatch nesta máquina agora, em modo silencioso?')) return;
        var maquinaId = btn.dataset.id;
        var icon = btn.querySelector('i');
        icon.className = 'bi bi-hourglass-split';
        btn.disabled = true;
        var fd = new FormData();
        fd.append('csrf_token', '<?= csrfToken() ?>');
        fd.append('id', maquinaId);
        fetch('ajax_instalar_maquina.php', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(function (data) {
                if (!data.ok) {
                    alert('Erro: ' + data.erro);
                    btn.disabled = false;
                    icon.className = 'bi bi-cloud-download';
                    return;
                }
                atualizarBadgeInstalacao(maquinaId, 'executando', '');
                acompanharInstalacao(maquinaId, data.instalacao_id, btn);
            })
            .catch(function () { alert('Falha ao iniciar a instalação (erro de rede).'); btn.disabled = false; icon.className = 'bi bi-cloud-download'; });
    });
});

document.querySelectorAll('.btn-ver-log').forEach(function (btn) {
    btn.addEventListener('click', function () {
        // Instanciado só aqui (não no carregamento do script) porque este bloco roda dentro de
        // main-content, antes da tag <script> do bootstrap.bundle.min.js no fim do layout.php -
        // "new bootstrap.Modal(...)" direto no topo do script lançava "bootstrap is not defined"
        // e abortava o resto do bloco, inclusive o addEventListener dos botões "Ver log" abaixo.
        var modalLog = bootstrap.Modal.getOrCreateInstance(document.getElementById('modalLogInstalacao'));
        document.getElementById('logInstalacaoStatus').textContent = 'Carregando...';
        document.getElementById('logInstalacaoConteudo').textContent = '';
        modalLog.show();
        fetch('ajax_status_instalacao.php?id=' + btn.dataset.instalacaoId)
            .then(r => r.json())
            .then(function (data) {
                if (!data.ok) { document.getElementById('logInstalacaoStatus').textContent = 'Não encontrado.'; return; }
                document.getElementById('logInstalacaoStatus').innerHTML =
                    '<strong>Status:</strong> ' + data.status + (data.mensagem ? ' — ' + data.mensagem : '');
                document.getElementById('logInstalacaoConteudo').textContent = data.log || '(sem log disponível)';
            })
            .catch(function () { document.getElementById('logInstalacaoStatus').textContent = 'Falha ao carregar (erro de rede).'; });
    });
});
</script>
