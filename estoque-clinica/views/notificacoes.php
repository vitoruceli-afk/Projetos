<?php
requireAdmin();
$db = getDB();
$formError = '';
$formSuccess = '';
$testeResultado = null;

$cfg = notificacaoConfig($db);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    csrfVerify();

    if ($_POST['action'] === 'salvar') {
        $ativo = isset($_POST['ativo']) ? 1 : 0;
        $modo = ($_POST['modo'] ?? '') === 'oauth' ? 'oauth' : 'legacy';
        $horario = trim($_POST['horario_envio'] ?? '07:00');
        $remetenteNome = trim($_POST['remetente_nome'] ?? '');
        $remetenteEmail = trim($_POST['remetente_email'] ?? '');
        $smtpHost = trim($_POST['smtp_host'] ?? '');
        $smtpPorta = (int)($_POST['smtp_porta'] ?? 587);
        $smtpSeguranca = in_array($_POST['smtp_seguranca'] ?? '', ['tls', 'ssl', 'none'], true) ? $_POST['smtp_seguranca'] : 'tls';
        $smtpUsuario = trim($_POST['smtp_usuario'] ?? '');
        $smtpSenha = $_POST['smtp_senha'] ?? '';
        $oauthProvedor = in_array($_POST['oauth_provedor'] ?? '', ['google', 'microsoft'], true) ? $_POST['oauth_provedor'] : 'google';
        $oauthClientId = trim($_POST['oauth_client_id'] ?? '');
        $oauthClientSecret = $_POST['oauth_client_secret'] ?? '';
        $oauthRefreshToken = trim($_POST['oauth_refresh_token'] ?? '');
        $oauthTenant = trim($_POST['oauth_tenant'] ?? '');

        if ($remetenteEmail !== '' && !filter_var($remetenteEmail, FILTER_VALIDATE_EMAIL)) {
            $formError = 'Informe um e-mail de remetente válido.';
        } elseif (!preg_match('/^\d{1,2}:\d{2}$/', $horario)) {
            $formError = 'Informe um horário válido (HH:MM).';
        } else {
            $params = [
                ':ativo' => $ativo, ':modo' => $modo, ':horario' => $horario . ':00',
                ':rn' => $remetenteNome, ':re' => $remetenteEmail,
                ':sh' => $smtpHost, ':sp' => $smtpPorta, ':ss' => $smtpSeguranca, ':su' => $smtpUsuario,
                ':op' => $oauthProvedor, ':oci' => $oauthClientId, ':ot' => $oauthTenant,
            ];
            $sql = "UPDATE notificacao_config SET ativo=:ativo, modo=:modo, horario_envio=:horario,
                remetente_nome=:rn, remetente_email=:re, smtp_host=:sh, smtp_porta=:sp, smtp_seguranca=:ss, smtp_usuario=:su,
                oauth_provedor=:op, oauth_client_id=:oci, oauth_tenant=:ot";
            // Senha/segredos só são atualizados se o admin digitou algo novo — em branco mantém o
            // valor já salvo (mesmo padrão da troca de senha em Usuários), pra não precisar
            // redigitar segredo a cada alteração de configuração e não expor o valor salvo na tela.
            if ($smtpSenha !== '') { $sql .= ", smtp_senha=:ssenha"; $params[':ssenha'] = $smtpSenha; }
            if ($oauthClientSecret !== '') { $sql .= ", oauth_client_secret=:ocs"; $params[':ocs'] = $oauthClientSecret; }
            if ($oauthRefreshToken !== '') { $sql .= ", oauth_refresh_token=:ort"; $params[':ort'] = $oauthRefreshToken; }
            $sql .= " WHERE id = 1";
            $db->prepare($sql)->execute($params);
            registrarLog('Notificações', 'Configuração de notificações atualizada', "modo: {$modo}, ativo: " . ($ativo ? 'sim' : 'não') . ", horário: {$horario}");
            $formSuccess = 'Configurações salvas com sucesso.';
            $cfg = notificacaoConfig($db);
        }
    } elseif ($_POST['action'] === 'teste') {
        $destinoTeste = trim($_POST['destino_teste'] ?? '');
        if (!filter_var($destinoTeste, FILTER_VALIDATE_EMAIL)) {
            $formError = 'Informe um e-mail válido para o teste.';
        } else {
            $corpoTeste = '<div style="font-family:Segoe UI,Arial,sans-serif;">'
                . '<h2>Estoque Clínica</h2>'
                . '<p>Este é um e-mail de teste da tela de Notificações.</p>'
                . '<p>Se você recebeu esta mensagem, a configuração de SMTP está funcionando corretamente.</p>'
                . '</div>';
            $testeResultado = enviarEmailSmtp($cfg, [$destinoTeste], 'Estoque Clínica — E-mail de teste', $corpoTeste);
            registrarLog('Notificações', $testeResultado['sucesso'] ? 'Teste de e-mail enviado' : 'Falha no teste de e-mail',
                "destino: {$destinoTeste} — {$testeResultado['mensagem']}");
        }
    } elseif ($_POST['action'] === 'reenviar') {
        $testeResultado = executarNotificacaoDiaria($db, $cfg);
    }
}

$ultimoEnvio = $db->query("SELECT * FROM notificacoes_envios ORDER BY data_referencia DESC LIMIT 1")->fetch();
$horarioAtual = substr($cfg['horario_envio'], 0, 5);
?>
<div class="page-head">
    <div>
        <h1 class="page-title">Notificações</h1>
        <div class="page-sub">Configuração do servidor de e-mail (SMTP) para os alertas diários de vencimento e estoque mínimo</div>
    </div>
</div>

<?php if ($formError): ?><div class="alert alert-danger"><?= htmlspecialchars($formError) ?></div><?php endif; ?>
<?php if ($formSuccess): ?><div class="alert alert-success"><?= htmlspecialchars($formSuccess) ?></div><?php endif; ?>
<?php if ($testeResultado): ?>
    <div class="alert <?= $testeResultado['sucesso'] ? 'alert-success' : 'alert-danger' ?>"><?= htmlspecialchars($testeResultado['mensagem']) ?></div>
<?php endif; ?>

<div class="row g-3">
    <div class="col-lg-8">
        <div class="card">
            <div class="card-header">Configuração</div>
            <div class="card-body">
                <form method="POST" id="notifForm">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="salvar">

                    <div class="form-check form-switch mb-3">
                        <input class="form-check-input" type="checkbox" role="switch" name="ativo" id="ativoCheck" <?= $cfg['ativo'] ? 'checked' : '' ?>>
                        <label class="form-check-label fw-bold" for="ativoCheck">Notificações diárias ativas</label>
                    </div>

                    <div class="row g-2 mb-3">
                        <div class="col-md-4">
                            <label class="form-label">Horário de envio</label>
                            <input type="time" name="horario_envio" class="form-control" value="<?= htmlspecialchars($horarioAtual) ?>" required>
                            <div class="form-text">O envio acontece na primeira ação de um administrador ou usuário após esse horário — não depende de um agendador do sistema operacional.</div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Nome do remetente</label>
                            <input type="text" name="remetente_nome" class="form-control" value="<?= htmlspecialchars($cfg['remetente_nome']) ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">E-mail do remetente</label>
                            <input type="email" name="remetente_email" class="form-control" value="<?= htmlspecialchars($cfg['remetente_email']) ?>">
                        </div>
                    </div>

                    <hr>
                    <label class="form-label">Modo de autenticação</label>
                    <div class="btn-group mb-3 d-flex" role="group">
                        <input type="radio" class="btn-check" name="modo" id="modoLegacy" value="legacy" autocomplete="off" <?= $cfg['modo'] !== 'oauth' ? 'checked' : '' ?>>
                        <label class="btn btn-outline-primary" for="modoLegacy">Legacy (usuário e senha)</label>
                        <input type="radio" class="btn-check" name="modo" id="modoOauth" value="oauth" autocomplete="off" <?= $cfg['modo'] === 'oauth' ? 'checked' : '' ?>>
                        <label class="btn btn-outline-primary" for="modoOauth">OAuth 2.0</label>
                    </div>

                    <div id="blocoLegacy">
                        <div class="row g-2 mb-2">
                            <div class="col-md-6">
                                <label class="form-label">Servidor SMTP</label>
                                <input type="text" name="smtp_host" class="form-control" placeholder="smtp.gmail.com" value="<?= htmlspecialchars($cfg['smtp_host']) ?>">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Porta</label>
                                <input type="number" name="smtp_porta" class="form-control" value="<?= (int)$cfg['smtp_porta'] ?>">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Segurança</label>
                                <select name="smtp_seguranca" class="form-select">
                                    <option value="tls" <?= $cfg['smtp_seguranca'] === 'tls' ? 'selected' : '' ?>>STARTTLS</option>
                                    <option value="ssl" <?= $cfg['smtp_seguranca'] === 'ssl' ? 'selected' : '' ?>>SSL</option>
                                    <option value="none" <?= $cfg['smtp_seguranca'] === 'none' ? 'selected' : '' ?>>Nenhuma</option>
                                </select>
                            </div>
                        </div>
                        <div class="row g-2 mb-2">
                            <div class="col-md-6">
                                <label class="form-label">Usuário SMTP</label>
                                <input type="text" name="smtp_usuario" class="form-control" value="<?= htmlspecialchars($cfg['smtp_usuario']) ?>">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Senha</label>
                                <input type="password" name="smtp_senha" class="form-control" placeholder="<?= $cfg['smtp_senha'] !== '' ? 'Deixe em branco para manter' : '' ?>">
                            </div>
                        </div>
                    </div>

                    <div id="blocoOauth">
                        <div class="row g-2 mb-2">
                            <div class="col-md-4">
                                <label class="form-label">Provedor</label>
                                <select name="oauth_provedor" id="oauthProvedorSelect" class="form-select">
                                    <option value="google" <?= $cfg['oauth_provedor'] === 'google' ? 'selected' : '' ?>>Google (Gmail)</option>
                                    <option value="microsoft" <?= $cfg['oauth_provedor'] === 'microsoft' ? 'selected' : '' ?>>Microsoft (Outlook/Office 365)</option>
                                </select>
                            </div>
                            <div class="col-md-4" id="oauthTenantWrap">
                                <label class="form-label">Tenant (Microsoft)</label>
                                <input type="text" name="oauth_tenant" class="form-control" placeholder="common" value="<?= htmlspecialchars($cfg['oauth_tenant']) ?>">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">E-mail da conta autorizada</label>
                                <input type="text" class="form-control" value="usa o e-mail do remetente acima" disabled>
                            </div>
                        </div>
                        <div class="row g-2 mb-2">
                            <div class="col-md-6">
                                <label class="form-label">Client ID</label>
                                <input type="text" name="oauth_client_id" class="form-control" value="<?= htmlspecialchars($cfg['oauth_client_id']) ?>">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Client Secret</label>
                                <input type="password" name="oauth_client_secret" class="form-control" placeholder="<?= $cfg['oauth_client_secret'] !== '' ? 'Deixe em branco para manter' : '' ?>">
                            </div>
                        </div>
                        <div class="mb-2">
                            <label class="form-label">Refresh Token</label>
                            <input type="password" name="oauth_refresh_token" class="form-control" placeholder="<?= $cfg['oauth_refresh_token'] !== '' ? 'Deixe em branco para manter' : '' ?>">
                            <div class="form-text">Obtido no consentimento OAuth do provedor (fora desta tela) — precisa do escopo de envio de e-mail (ex.: <code>https://mail.google.com/</code> no Google).</div>
                        </div>
                    </div>

                    <button class="btn btn-outline-success w-100 mt-2">Salvar Configurações</button>
                </form>
            </div>
        </div>
    </div>

    <div class="col-lg-4">
        <div class="card mb-3">
            <div class="card-header">Enviar E-mail de Teste</div>
            <div class="card-body">
                <form method="POST">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="teste">
                    <div class="mb-2">
                        <label class="form-label">Destino</label>
                        <input type="email" name="destino_teste" class="form-control" value="<?= htmlspecialchars($_SESSION['user_logged_in'] ?? '') ?>" placeholder="seu@email.com" required>
                    </div>
                    <button class="btn btn-outline-primary w-100">Enviar Teste</button>
                </form>
            </div>
        </div>

        <div class="card">
            <div class="card-header">Último Envio Diário</div>
            <div class="card-body">
                <?php if (!$ultimoEnvio): ?>
                    <p class="text-muted small mb-0">Nenhum envio realizado ainda.</p>
                <?php else: ?>
                    <div class="entity-field-label">Data de referência</div>
                    <div class="entity-field-value mb-2"><?= date('d/m/Y', strtotime($ultimoEnvio['data_referencia'])) ?></div>
                    <div class="entity-field-label">Status</div>
                    <div class="mb-2">
                        <span class="badge <?= $ultimoEnvio['sucesso'] ? 'bg-success' : 'bg-danger' ?>"><?= $ultimoEnvio['sucesso'] ? 'Sucesso' : 'Falha' ?></span>
                    </div>
                    <div class="entity-field-label">Destinatários</div>
                    <div class="entity-field-value mb-2"><?= (int)$ultimoEnvio['destinatarios'] ?></div>
                    <div class="entity-field-label">Detalhes</div>
                    <div class="entity-sub"><?= htmlspecialchars($ultimoEnvio['mensagem']) ?></div>
                <?php endif; ?>
                <form method="POST" class="mt-3" onsubmit="return confirm('Isso monta e envia agora o e-mail de notificação de hoje (vencimentos e estoque mínimo) para todos os usuários marcados. Continuar?');">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="reenviar">
                    <button class="btn btn-outline-warning w-100">Reenviar Notificação de Hoje</button>
                </form>
                <div class="form-text mt-1">Use se o envio automático falhou (ex.: erro de SMTP corrigido depois do horário) — ele não espera o próximo dia.</div>
            </div>
        </div>
    </div>
</div>

<script>
(function () {
    var modoLegacy = document.getElementById('modoLegacy');
    var modoOauth = document.getElementById('modoOauth');
    var blocoLegacy = document.getElementById('blocoLegacy');
    var blocoOauth = document.getElementById('blocoOauth');
    var oauthProvedorSelect = document.getElementById('oauthProvedorSelect');
    var oauthTenantWrap = document.getElementById('oauthTenantWrap');

    function ajustarModo() {
        var ehOauth = modoOauth.checked;
        blocoLegacy.style.display = ehOauth ? 'none' : '';
        blocoOauth.style.display = ehOauth ? '' : 'none';
    }
    function ajustarTenant() {
        oauthTenantWrap.style.display = oauthProvedorSelect.value === 'microsoft' ? '' : 'none';
    }

    modoLegacy.addEventListener('change', ajustarModo);
    modoOauth.addEventListener('change', ajustarModo);
    oauthProvedorSelect.addEventListener('change', ajustarTenant);
    ajustarModo();
    ajustarTenant();
})();
</script>
