<?php
requireAdmin();
$db = getDB();
$formError = '';
$testResult = null;
$importResultUsuarios = null; // ['criados' => [['username','senha']], 'existentes' => [...]]
$importResultMaquinas = null; // ['criados' => [...], 'existentes' => [...]]

$config = getAdConfig($db);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    csrfVerify();
    $action = $_POST['action'];

    if ($action === 'save_config') {
        $server = trim($_POST['ldap_server'] ?? '');
        $basedn = trim($_POST['ldap_basedn'] ?? '');
        $bindUsername = trim($_POST['bind_username'] ?? '');
        $bindPassword = $_POST['bind_password'] ?? '';

        if ($server === '' || $basedn === '') {
            $formError = 'Servidor e base DN são obrigatórios.';
        } else {
            if ($bindPassword !== '') {
                $stmt = $db->prepare("UPDATE ad_config SET ldap_server = :s, ldap_basedn = :b, bind_username = :bu, bind_password = :bp WHERE id = :id");
                $stmt->bindValue(':bp', adEncrypt($bindPassword));
            } else {
                $stmt = $db->prepare("UPDATE ad_config SET ldap_server = :s, ldap_basedn = :b, bind_username = :bu WHERE id = :id");
            }
            $stmt->bindValue(':s', $server);
            $stmt->bindValue(':b', $basedn);
            $stmt->bindValue(':bu', $bindUsername);
            $stmt->bindValue(':id', $config['id'], PDO::PARAM_INT);
            $stmt->execute();
            header("Location: index.php?page=ad");
            exit;
        }
    } elseif ($action === 'test_config') {
        // Testa com os valores do formulário (mesmo ainda não salvos), igual ao fluxo de
        // "Testar Conexão" de Máquinas — evita salvar uma configuração errada só para testá-la.
        $testConfig = [
            'ldap_server' => trim($_POST['ldap_server'] ?? '') ?: $config['ldap_server'],
            'ldap_basedn' => trim($_POST['ldap_basedn'] ?? '') ?: $config['ldap_basedn'],
            'bind_username' => trim($_POST['bind_username'] ?? ''),
            'bind_password' => ($_POST['bind_password'] ?? '') !== '' ? adEncrypt($_POST['bind_password']) : $config['bind_password'],
        ];
        $testResult = adTestarConexao($testConfig);
    } elseif ($action === 'add_grupo') {
        $nome = trim($_POST['nome'] ?? '');
        $groupDn = trim($_POST['group_dn'] ?? '');
        $rolePadrao = ($_POST['role_padrao'] ?? '') === 'admin' ? 'admin' : 'usuario';
        if ($nome === '' || $groupDn === '') {
            $formError = 'Nome e DN do grupo são obrigatórios.';
        } else {
            $db->prepare("INSERT INTO ad_grupos (nome, group_dn, role_padrao) VALUES (:n, :dn, :r)")
               ->execute([':n' => $nome, ':dn' => $groupDn, ':r' => $rolePadrao]);
            header("Location: index.php?page=ad");
            exit;
        }
    } elseif ($action === 'delete_grupo') {
        $db->prepare("DELETE FROM ad_grupos WHERE id = :id")->execute([':id' => (int)($_POST['id'] ?? 0)]);
        header("Location: index.php?page=ad");
        exit;
    } elseif ($action === 'add_ou') {
        $nome = trim($_POST['nome'] ?? '');
        $ouDn = trim($_POST['ou_dn'] ?? '');
        $portaPadrao = (int)($_POST['porta_padrao'] ?? AW_PORTA_PADRAO) ?: AW_PORTA_PADRAO;
        if ($nome === '' || $ouDn === '') {
            $formError = 'Nome e DN da OU são obrigatórios.';
        } else {
            $db->prepare("INSERT INTO ad_ous (nome, ou_dn, porta_padrao) VALUES (:n, :dn, :p)")
               ->execute([':n' => $nome, ':dn' => $ouDn, ':p' => $portaPadrao]);
            header("Location: index.php?page=ad");
            exit;
        }
    } elseif ($action === 'update_ou') {
        $ouId = (int)($_POST['id'] ?? 0);
        $nome = trim($_POST['nome'] ?? '');
        $ouDn = trim($_POST['ou_dn'] ?? '');
        $portaPadrao = (int)($_POST['porta_padrao'] ?? AW_PORTA_PADRAO) ?: AW_PORTA_PADRAO;
        if ($nome === '' || $ouDn === '') {
            $formError = 'Nome e DN da OU são obrigatórios.';
        } else {
            // Renomear aqui é o que faz o setor de toda máquina vinculada a esta OU (maquinas.ou_id)
            // acompanhar o novo nome automaticamente — não precisa reimportar nada.
            $db->prepare("UPDATE ad_ous SET nome = :n, ou_dn = :dn, porta_padrao = :p WHERE id = :id")
               ->execute([':n' => $nome, ':dn' => $ouDn, ':p' => $portaPadrao, ':id' => $ouId]);
            header("Location: index.php?page=ad");
            exit;
        }
    } elseif ($action === 'delete_ou') {
        $db->prepare("DELETE FROM ad_ous WHERE id = :id")->execute([':id' => (int)($_POST['id'] ?? 0)]);
        header("Location: index.php?page=ad");
        exit;
    } elseif ($action === 'importar_usuarios') {
        $grupoId = (int)($_POST['grupo_id'] ?? 0);
        $selecionados = array_map('strval', $_POST['usuarios'] ?? []);
        $stmt = $db->prepare("SELECT * FROM ad_grupos WHERE id = :id");
        $stmt->execute([':id' => $grupoId]);
        $grupo = $stmt->fetch();

        if (!$grupo || empty($selecionados)) {
            $formError = 'Selecione ao menos um usuário do grupo para importar.';
        } else {
            // Revalida contra o AD na hora de importar em vez de confiar no que veio do POST —
            // nome/e-mail exibidos na tela não são fonte de verdade para o que é gravado.
            $membros = adBuscarMembrosGrupo($config, $grupo['group_dn'], $erroBusca);
            $porUsername = [];
            foreach ($membros as $m) { $porUsername[$m['username']] = $m; }

            $criados = [];
            $existentes = [];
            $checkStmt = $db->prepare("SELECT id FROM local_users WHERE username = :u");
            $insertStmt = $db->prepare("INSERT INTO local_users (username, password_hash, full_name, enabled, role, origem, ad_dn)
                VALUES (:u, :p, :n, 1, :r, 'ad', :dn)");

            foreach ($selecionados as $username) {
                if (!isset($porUsername[$username])) continue;
                $membro = $porUsername[$username];
                $checkStmt->execute([':u' => $username]);
                if ($checkStmt->fetch()) {
                    $existentes[] = $username;
                    continue;
                }
                $senhaGerada = bin2hex(random_bytes(6));
                $insertStmt->execute([
                    ':u' => $username,
                    ':p' => password_hash($senhaGerada, PASSWORD_DEFAULT),
                    ':n' => $membro['nome'],
                    ':r' => $grupo['role_padrao'],
                    ':dn' => $membro['dn'],
                ]);
                $criados[] = ['username' => $username, 'nome' => $membro['nome'], 'senha' => $senhaGerada];
            }
            $importResultUsuarios = ['criados' => $criados, 'existentes' => $existentes, 'grupo_id' => $grupoId];
        }
    } elseif ($action === 'importar_maquinas') {
        $ouId = (int)($_POST['ou_id'] ?? 0);
        $selecionados = array_map('strval', $_POST['maquinas'] ?? []);
        $stmt = $db->prepare("SELECT * FROM ad_ous WHERE id = :id");
        $stmt->execute([':id' => $ouId]);
        $ou = $stmt->fetch();

        if (!$ou || empty($selecionados)) {
            $formError = 'Selecione ao menos um computador da OU para importar.';
        } else {
            $computadores = adBuscarComputadoresOU($config, $ou['ou_dn'], $erroBusca);
            $porCn = [];
            foreach ($computadores as $c) { $porCn[$c['cn']] = $c; }

            $criados = [];
            $existentes = [];
            $checkStmt = $db->prepare("SELECT id FROM maquinas WHERE host = :h AND porta = :p");
            $insertStmt = $db->prepare("INSERT INTO maquinas (nome, host, porta, ativo, ad_dn, setor, ou_id) VALUES (:n, :h, :p, 0, :dn, :setor, :ouid)");

            foreach ($selecionados as $cn) {
                if (!isset($porCn[$cn])) continue;
                $maquina = $porCn[$cn];
                $checkStmt->execute([':h' => $maquina['host'], ':p' => $ou['porta_padrao']]);
                if ($checkStmt->fetch()) {
                    $existentes[] = $maquina['host'];
                    continue;
                }
                // ou_id vincula a máquina à OU — o setor exibido passa a acompanhar o nome ATUAL
                // dela (Máquinas/Dashboard fazem JOIN); a coluna setor aqui é só um retrato inicial,
                // usado como texto de fallback se o cadastro da OU for excluído depois.
                $insertStmt->execute([':n' => $cn, ':h' => $maquina['host'], ':p' => $ou['porta_padrao'], ':dn' => $maquina['dn'], ':setor' => $ou['nome'], ':ouid' => $ou['id']]);
                $criados[] = ['cn' => $cn, 'host' => $maquina['host']];
            }
            $importResultMaquinas = ['criados' => $criados, 'existentes' => $existentes, 'ou_id' => $ouId];
        }
    }
}

$grupos = $db->query("SELECT * FROM ad_grupos ORDER BY nome ASC")->fetchAll();
$ous = $db->query("SELECT * FROM ad_ous ORDER BY nome ASC")->fetchAll();

$editandoOu = null;
if (isset($_GET['editar_ou'])) {
    $stmt = $db->prepare("SELECT * FROM ad_ous WHERE id = :id");
    $stmt->execute([':id' => (int)$_GET['editar_ou']]);
    $editandoOu = $stmt->fetch();
}
$maquinasVinculadasPorOu = [];
foreach ($db->query("SELECT ou_id, COUNT(*) AS n FROM maquinas WHERE ou_id IS NOT NULL GROUP BY ou_id") as $row) {
    $maquinasVinculadasPorOu[$row['ou_id']] = (int)$row['n'];
}

$membrosVisualizados = null;
$erroMembros = null;
$verGrupoId = (int)($_GET['ver_grupo'] ?? 0);
if ($verGrupoId > 0) {
    $stmt = $db->prepare("SELECT * FROM ad_grupos WHERE id = :id");
    $stmt->execute([':id' => $verGrupoId]);
    $grupoAtivo = $stmt->fetch();
    if ($grupoAtivo) {
        $membrosVisualizados = adBuscarMembrosGrupo($config, $grupoAtivo['group_dn'], $erroMembros);
    }
}

$computadoresVisualizados = null;
$erroComputadores = null;
$verOuId = (int)($_GET['ver_ou'] ?? 0);
if ($verOuId > 0) {
    $stmt = $db->prepare("SELECT * FROM ad_ous WHERE id = :id");
    $stmt->execute([':id' => $verOuId]);
    $ouAtiva = $stmt->fetch();
    if ($ouAtiva) {
        $computadoresVisualizados = adBuscarComputadoresOU($config, $ouAtiva['ou_dn'], $erroComputadores);
    }
}

$usernamesExistentes = array_column($db->query("SELECT username FROM local_users")->fetchAll(), 'username');
$usernamesExistentes = array_flip($usernamesExistentes);
$hostsExistentes = array_column($db->query("SELECT host FROM maquinas")->fetchAll(), 'host');
$hostsExistentes = array_flip($hostsExistentes);
?>
<div class="page-head">
    <div>
        <h1 class="page-title">Active Directory</h1>
        <div class="page-sub">Conecte ao AD para importar usuários por grupo e máquinas por Unidade Organizacional (OU)</div>
    </div>
</div>

<?php if ($formError): ?><div class="alert alert-danger"><?= htmlspecialchars($formError) ?></div><?php endif; ?>

<div class="card mb-3">
    <div class="card-header">Conexão</div>
    <div class="card-body">
        <?php if ($testResult): ?>
            <div class="alert <?= $testResult['ok'] ? 'alert-success' : 'alert-danger' ?> py-2">
                <?= $testResult['ok'] ? '<i class="bi bi-check-circle"></i> Conexão e autenticação com a conta de serviço realizadas com sucesso.' : '<i class="bi bi-x-circle"></i> ' . htmlspecialchars($testResult['erro']) ?>
            </div>
        <?php endif; ?>
        <form method="POST" class="row g-2">
            <?= csrfField() ?>
            <div class="col-md-6">
                <label class="form-label">Servidor LDAP</label>
                <input type="text" name="ldap_server" class="form-control" placeholder="ldap://faesa.br" value="<?= htmlspecialchars($config['ldap_server']) ?>" required>
            </div>
            <div class="col-md-6">
                <label class="form-label">Base DN</label>
                <input type="text" name="ldap_basedn" class="form-control" placeholder="DC=faesa,DC=br" value="<?= htmlspecialchars($config['ldap_basedn']) ?>" required>
            </div>
            <div class="col-md-6">
                <label class="form-label">Conta de serviço (ex: svc_produtividade@faesa.br ou DN completo)</label>
                <input type="text" name="bind_username" class="form-control" value="<?= htmlspecialchars($config['bind_username']) ?>">
            </div>
            <div class="col-md-6">
                <label class="form-label">Senha da conta de serviço</label>
                <input type="password" name="bind_password" class="form-control" placeholder="Deixe em branco para manter a senha atual">
            </div>
            <div class="col-12 mt-2">
                <button type="submit" name="action" value="save_config" class="btn btn-outline-success">Salvar Configurações</button>
                <button type="submit" name="action" value="test_config" class="btn btn-outline-secondary">Testar Conexão</button>
            </div>
        </form>
        <div class="alert alert-warning mt-3 mb-0 py-2 small">
            A conta de serviço só precisa de permissão de <strong>leitura</strong> no AD (bind + busca). Não use uma conta de administrador do domínio para isso.
        </div>
    </div>
</div>

<div class="card mb-3">
            <div class="card-header">Grupos → Usuários</div>
            <div class="card-body">
                <form method="POST" class="row g-2 mb-3">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="add_grupo">
                    <div class="col-md-4"><label class="form-label">Nome</label><input type="text" name="nome" class="form-control" placeholder="Ex: TI" required></div>
                    <div class="col-md-5"><label class="form-label">DN do grupo</label><input type="text" name="group_dn" class="form-control" placeholder="CN=TI,OU=Grupos,DC=faesa,DC=br" required></div>
                    <div class="col-md-2">
                        <label class="form-label">Perfil ao importar</label>
                        <select name="role_padrao" class="form-select">
                            <option value="usuario">Padrão</option>
                            <option value="admin">Admin</option>
                        </select>
                    </div>
                    <div class="col-md-1 d-flex align-items-end"><button class="btn btn-outline-success w-100"><i class="bi bi-plus-lg"></i></button></div>
                </form>

                <table class="table table-sm mb-0">
                    <thead><tr><th>Nome</th><th>DN</th><th>Perfil</th><th></th></tr></thead>
                    <tbody>
                        <?php foreach ($grupos as $g): ?>
                        <tr>
                            <td><?= htmlspecialchars($g['nome']) ?></td>
                            <td class="text-truncate" style="max-width: 220px"><code title="<?= htmlspecialchars($g['group_dn']) ?>"><?= htmlspecialchars($g['group_dn']) ?></code></td>
                            <td><span class="badge bg-secondary"><?= $g['role_padrao'] === 'admin' ? 'Admin' : 'Padrão' ?></span></td>
                            <td class="text-nowrap">
                                <a href="index.php?page=ad&ver_grupo=<?= (int)$g['id'] ?>#grupo-<?= (int)$g['id'] ?>" class="btn btn-sm btn-outline-info"><i class="bi bi-people"></i> Ver membros</a>
                                <form method="POST" class="d-inline" onsubmit="return confirm('Remover este grupo da lista? (não afeta o AD nem os usuários já importados)');">
                                    <?= csrfField() ?>
                                    <input type="hidden" name="action" value="delete_grupo">
                                    <input type="hidden" name="id" value="<?= (int)$g['id'] ?>">
                                    <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($grupos)): ?><tr><td colspan="4" class="text-center text-muted py-2">Nenhum grupo cadastrado.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="card mb-3" id="topo-ou">
            <div class="card-header">OUs → Máquinas</div>
            <div class="card-body">
                <form method="POST" class="row g-2 mb-3">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="<?= $editandoOu ? 'update_ou' : 'add_ou' ?>">
                    <?php if ($editandoOu): ?><input type="hidden" name="id" value="<?= (int)$editandoOu['id'] ?>"><?php endif; ?>
                    <div class="col-md-4"><label class="form-label">Nome</label><input type="text" name="nome" class="form-control" placeholder="Ex: Laboratórios" value="<?= htmlspecialchars($editandoOu['nome'] ?? '') ?>" required></div>
                    <div class="col-md-5"><label class="form-label">DN da OU</label><input type="text" name="ou_dn" class="form-control" placeholder="OU=Laboratorios,DC=faesa,DC=br" value="<?= htmlspecialchars($editandoOu['ou_dn'] ?? '') ?>" required></div>
                    <div class="col-md-2"><label class="form-label">Porta AW</label><input type="number" name="porta_padrao" class="form-control" value="<?= htmlspecialchars($editandoOu['porta_padrao'] ?? AW_PORTA_PADRAO) ?>"></div>
                    <div class="col-md-1 d-flex align-items-end"><button class="btn btn-outline-success w-100"><i class="bi <?= $editandoOu ? 'bi-check-lg' : 'bi-plus-lg' ?>"></i></button></div>
                    <?php if ($editandoOu): ?>
                        <div class="col-12">
                            <div class="alert alert-info py-2 mb-0 small">
                                Renomear aqui atualiza o setor de <strong><?= $maquinasVinculadasPorOu[$editandoOu['id']] ?? 0 ?> máquina(s)</strong> já vinculada(s) a esta OU, automaticamente.
                            </div>
                        </div>
                        <div class="col-12"><a href="index.php?page=ad" class="btn btn-outline-secondary btn-sm">Cancelar edição</a></div>
                    <?php endif; ?>
                </form>

                <table class="table table-sm mb-0">
                    <thead><tr><th>Nome</th><th>DN</th><th>Porta</th><th>Máquinas</th><th></th></tr></thead>
                    <tbody>
                        <?php foreach ($ous as $o): ?>
                        <tr>
                            <td><?= htmlspecialchars($o['nome']) ?></td>
                            <td class="text-truncate" style="max-width: 220px"><code title="<?= htmlspecialchars($o['ou_dn']) ?>"><?= htmlspecialchars($o['ou_dn']) ?></code></td>
                            <td><?= (int)$o['porta_padrao'] ?></td>
                            <td><?= (int)($maquinasVinculadasPorOu[$o['id']] ?? 0) ?></td>
                            <td class="text-nowrap">
                                <a href="index.php?page=ad&ver_ou=<?= (int)$o['id'] ?>#ou-<?= (int)$o['id'] ?>" class="btn btn-sm btn-outline-info"><i class="bi bi-pc-display"></i></a>
                                <a href="index.php?page=ad&editar_ou=<?= (int)$o['id'] ?>#topo-ou" class="btn btn-sm btn-outline-primary"><i class="bi bi-pencil"></i></a>
                                <form method="POST" class="d-inline" onsubmit="return confirm('Remover esta OU da lista? As máquinas já vinculadas a ela ficam sem setor vinculado (o texto atual é mantido). Não afeta o AD nem as máquinas em si.');">
                                    <?= csrfField() ?>
                                    <input type="hidden" name="action" value="delete_ou">
                                    <input type="hidden" name="id" value="<?= (int)$o['id'] ?>">
                                    <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($ous)): ?><tr><td colspan="5" class="text-center text-muted py-2">Nenhuma OU cadastrada.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

<?php if ($importResultUsuarios): ?>
<div class="card mb-3" id="grupo-<?= (int)$importResultUsuarios['grupo_id'] ?>">
    <div class="card-header">Resultado da Importação de Usuários</div>
    <div class="card-body">
        <?php if (!empty($importResultUsuarios['criados'])): ?>
            <div class="alert alert-success">
                <strong><?= count($importResultUsuarios['criados']) ?> usuário(s) criado(s).</strong>
                As senhas abaixo só aparecem <strong>uma vez</strong> — anote-as ou peça para o usuário trocar no primeiro acesso (Usuários &gt; editar).
            </div>
            <table class="table table-sm">
                <thead><tr><th>Usuário</th><th>Nome</th><th>Senha gerada</th></tr></thead>
                <tbody>
                <?php foreach ($importResultUsuarios['criados'] as $c): ?>
                    <tr><td><?= htmlspecialchars($c['username']) ?></td><td><?= htmlspecialchars($c['nome']) ?></td><td><code><?= htmlspecialchars($c['senha']) ?></code></td></tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
        <?php if (!empty($importResultUsuarios['existentes'])): ?>
            <div class="alert alert-info py-2 mb-0">Já existiam e não foram alterados: <?= htmlspecialchars(implode(', ', $importResultUsuarios['existentes'])) ?></div>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<?php if ($importResultMaquinas): ?>
<div class="card mb-3" id="ou-<?= (int)$importResultMaquinas['ou_id'] ?>">
    <div class="card-header">Resultado da Importação de Máquinas</div>
    <div class="card-body">
        <?php if (!empty($importResultMaquinas['criados'])): ?>
            <div class="alert alert-success">
                <strong><?= count($importResultMaquinas['criados']) ?> máquina(s) criada(s)</strong> — importadas <strong>inativas</strong> por padrão.
                Confirme que o aw-server está exposto na rede em cada uma e ative-as em <a href="index.php?page=maquinas">Máquinas</a>.
            </div>
            <table class="table table-sm">
                <thead><tr><th>Nome (CN)</th><th>Host</th></tr></thead>
                <tbody>
                <?php foreach ($importResultMaquinas['criados'] as $c): ?>
                    <tr><td><?= htmlspecialchars($c['cn']) ?></td><td><code><?= htmlspecialchars($c['host']) ?></code></td></tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
        <?php if (!empty($importResultMaquinas['existentes'])): ?>
            <div class="alert alert-info py-2 mb-0">Já existiam e não foram alteradas: <?= htmlspecialchars(implode(', ', $importResultMaquinas['existentes'])) ?></div>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<?php if ($verGrupoId > 0): ?>
<div class="card mb-3" id="grupo-<?= $verGrupoId ?>">
    <div class="card-header">Membros do Grupo <?= isset($grupoAtivo) ? '"' . htmlspecialchars($grupoAtivo['nome']) . '"' : '' ?></div>
    <div class="card-body">
        <?php if (!isset($grupoAtivo) || !$grupoAtivo): ?>
            <div class="alert alert-danger mb-0">Grupo não encontrado.</div>
        <?php elseif ($erroMembros): ?>
            <div class="alert alert-danger mb-0"><?= htmlspecialchars($erroMembros) ?></div>
        <?php elseif (empty($membrosVisualizados)): ?>
            <div class="text-muted">Nenhum membro encontrado nesse grupo (ou o grupo está vazio).</div>
        <?php else: ?>
            <form method="POST">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="importar_usuarios">
                <input type="hidden" name="grupo_id" value="<?= $verGrupoId ?>">
                <div class="table-responsive">
                <table class="table table-sm align-middle">
                    <thead><tr><th style="width:32px"><input type="checkbox" onclick="this.closest('table').querySelectorAll('.chk-usuario').forEach(c => c.checked = this.checked)"></th><th>Usuário</th><th>Nome</th><th>E-mail</th><th>Status AD</th><th>Já existe aqui</th></tr></thead>
                    <tbody>
                    <?php foreach ($membrosVisualizados as $m): $jaExiste = isset($usernamesExistentes[$m['username']]); ?>
                        <tr>
                            <td><input type="checkbox" class="chk-usuario" name="usuarios[]" value="<?= htmlspecialchars($m['username']) ?>" <?= $jaExiste ? 'disabled' : '' ?>></td>
                            <td><?= htmlspecialchars($m['username']) ?></td>
                            <td><?= htmlspecialchars($m['nome']) ?></td>
                            <td><?= htmlspecialchars($m['email'] ?: '—') ?></td>
                            <td><?= $m['ativo'] ? '<span class="badge bg-success">Ativo</span>' : '<span class="badge bg-secondary">Desabilitado</span>' ?></td>
                            <td><?= $jaExiste ? '<span class="badge bg-info">Sim</span>' : '' ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
                <button class="btn btn-outline-success"><i class="bi bi-download"></i> Importar Selecionados</button>
                <span class="text-muted small ms-2">Gera uma senha aleatória para cada conta nova (mostrada uma única vez).</span>
            </form>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<?php if ($verOuId > 0): ?>
<div class="card mb-3" id="ou-<?= $verOuId ?>">
    <div class="card-header">Computadores da OU <?= isset($ouAtiva) ? '"' . htmlspecialchars($ouAtiva['nome']) . '"' : '' ?></div>
    <div class="card-body">
        <?php if (!isset($ouAtiva) || !$ouAtiva): ?>
            <div class="alert alert-danger mb-0">OU não encontrada.</div>
        <?php elseif ($erroComputadores): ?>
            <div class="alert alert-danger mb-0"><?= htmlspecialchars($erroComputadores) ?></div>
        <?php elseif (empty($computadoresVisualizados)): ?>
            <div class="text-muted">Nenhum computador encontrado nessa OU.</div>
        <?php else: ?>
            <form method="POST">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="importar_maquinas">
                <input type="hidden" name="ou_id" value="<?= $verOuId ?>">
                <div class="table-responsive">
                <table class="table table-sm align-middle">
                    <thead><tr><th style="width:32px"><input type="checkbox" onclick="this.closest('table').querySelectorAll('.chk-maquina').forEach(c => c.checked = this.checked)"></th><th>Nome (CN)</th><th>Host (dNSHostName)</th><th>Sistema</th><th>Status AD</th><th>Já existe aqui</th></tr></thead>
                    <tbody>
                    <?php foreach ($computadoresVisualizados as $c): $jaExiste = isset($hostsExistentes[$c['host']]); ?>
                        <tr>
                            <td><input type="checkbox" class="chk-maquina" name="maquinas[]" value="<?= htmlspecialchars($c['cn']) ?>" <?= $jaExiste ? 'disabled' : '' ?>></td>
                            <td><?= htmlspecialchars($c['cn']) ?></td>
                            <td><code><?= htmlspecialchars($c['host']) ?></code></td>
                            <td><?= htmlspecialchars($c['sistema'] ?: '—') ?></td>
                            <td><?= $c['ativo'] ? '<span class="badge bg-success">Ativo</span>' : '<span class="badge bg-secondary">Desabilitado</span>' ?></td>
                            <td><?= $jaExiste ? '<span class="badge bg-info">Sim</span>' : '' ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
                <button class="btn btn-outline-success"><i class="bi bi-download"></i> Importar Selecionados</button>
                <span class="text-muted small ms-2">Máquinas são importadas <strong>inativas</strong> — ative depois de confirmar o aw-server exposto.</span>
            </form>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>
