<?php

declare(strict_types=1);

use App\Core\Auth;
use App\Core\Session;
use App\Core\Mailer;
use App\Models\ConfigSmtp;

require __DIR__ . '/../includes/bootstrap.php';
Auth::exigirAdmin();

$msgTeste = null;
$tipoTeste = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $acao = $_POST['acao'] ?? 'salvar';

    ConfigSmtp::salvar($_POST);

    if ($acao === 'teste') {
        $emailTeste = trim($_POST['email_teste'] ?? '');
        try {
            if (!filter_var($emailTeste, FILTER_VALIDATE_EMAIL)) {
                throw new \RuntimeException('Informe um e-mail de teste válido.');
            }
            Mailer::enviar(
                [['nome' => 'Teste', 'email' => $emailTeste]],
                'Teste de configuração SMTP - Sistema de Rateio',
                '<p>Este é um e-mail de teste do Sistema de Rateio. '
                . 'Se você recebeu esta mensagem, o envio está funcionando.</p>'
            );
            $msgTeste  = 'E-mail de teste enviado para ' . e($emailTeste) . '.';
            $tipoTeste = 'success';
        } catch (\Throwable $ex) {
            $msgTeste  = 'Falha no envio de teste: ' . e($ex->getMessage());
            $tipoTeste = 'danger';
        }
    } else {
        Session::flash('success', 'Configuração SMTP salva.');
        header('Location: ' . url('smtp/index.php'));
        exit;
    }
}

$c = ConfigSmtp::obter();

$contexto     = 'inicial';
$tituloPagina = 'Configuração SMTP';
require __DIR__ . '/../includes/header.php';

$metodo = $c['metodo'] ?? 'smtp';
?>

<h2 class="mb-4">Configuração de E-mail (SMTP)</h2>

<?php if ($msgTeste !== null): ?>
    <div class="alert alert-<?= $tipoTeste ?>"><?= $msgTeste ?></div>
<?php endif; ?>

<form method="POST">
    <div class="card shadow-sm mb-3">
        <div class="card-header">Método de autenticação</div>
        <div class="card-body">
            <div class="mb-3">
                <select name="metodo" id="metodo" class="form-select" onchange="alternarMetodo()">
                    <option value="smtp"  <?= $metodo === 'smtp'  ? 'selected' : '' ?>>Servidor SMTP (usuário e senha)</option>
                    <option value="oauth" <?= $metodo === 'oauth' ? 'selected' : '' ?>>OAuth (autenticação moderna / XOAUTH2)</option>
                </select>
            </div>

            <div class="row">
                <div class="col-md-6 mb-3">
                    <label class="form-label">Nome do remetente</label>
                    <input type="text" name="remetente_nome" class="form-control" value="<?= e($c['remetente_nome']) ?>">
                </div>
                <div class="col-md-6 mb-3">
                    <label class="form-label">E-mail do remetente</label>
                    <input type="email" name="remetente_email" class="form-control" value="<?= e($c['remetente_email']) ?>" required>
                </div>
            </div>
        </div>
    </div>

    <!-- SERVIDOR SMTP -->
    <div class="card shadow-sm mb-3 bloco-smtp">
        <div class="card-header">Servidor SMTP</div>
        <div class="card-body">
            <div class="row">
                <div class="col-md-6 mb-3">
                    <label class="form-label">Host</label>
                    <input type="text" name="host" class="form-control" value="<?= e($c['host']) ?>"
                           placeholder="smtp.empresa.com">
                </div>
                <div class="col-md-3 mb-3">
                    <label class="form-label">Porta</label>
                    <input type="number" name="porta" class="form-control" value="<?= e($c['porta']) ?>">
                </div>
                <div class="col-md-3 mb-3">
                    <label class="form-label">Segurança</label>
                    <select name="seguranca" class="form-select">
                        <?php foreach (['tls' => 'STARTTLS', 'ssl' => 'SSL/TLS', 'none' => 'Nenhuma'] as $k => $lbl): ?>
                            <option value="<?= $k ?>" <?= ($c['seguranca'] ?? 'tls') === $k ? 'selected' : '' ?>><?= $lbl ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="row">
                <div class="col-md-6 mb-3">
                    <label class="form-label">Usuário</label>
                    <input type="text" name="usuario" class="form-control" value="<?= e($c['usuario']) ?>"
                           autocomplete="off">
                </div>
                <div class="col-md-6 mb-3 bloco-smtp-senha">
                    <label class="form-label">Senha</label>
                    <input type="password" name="senha" class="form-control" value="<?= e($c['senha']) ?>"
                           autocomplete="new-password">
                </div>
            </div>
            <p class="text-muted small mb-0">No método OAuth, o usuário é a caixa de e-mail; a senha é ignorada.</p>
        </div>
    </div>

    <!-- OAUTH -->
    <div class="card shadow-sm mb-3 bloco-oauth">
        <div class="card-header">OAuth / Autenticação Moderna</div>
        <div class="card-body">
            <div class="row">
                <div class="col-md-4 mb-3">
                    <label class="form-label">Provedor</label>
                    <select name="oauth_provedor" class="form-select">
                        <?php foreach (['microsoft' => 'Microsoft 365', 'google' => 'Google', 'custom' => 'Personalizado'] as $k => $lbl): ?>
                            <option value="<?= $k ?>" <?= ($c['oauth_provedor'] ?? 'microsoft') === $k ? 'selected' : '' ?>><?= $lbl ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-8 mb-3">
                    <label class="form-label">Tenant (Microsoft) <small class="text-muted">— opcional</small></label>
                    <input type="text" name="oauth_tenant" class="form-control" value="<?= e($c['oauth_tenant']) ?>"
                           placeholder="ID do diretório ou domínio (common, se vazio)">
                </div>
            </div>
            <div class="row">
                <div class="col-md-6 mb-3">
                    <label class="form-label">Client ID</label>
                    <input type="text" name="oauth_client_id" class="form-control" value="<?= e($c['oauth_client_id']) ?>" autocomplete="off">
                </div>
                <div class="col-md-6 mb-3">
                    <label class="form-label">Client Secret</label>
                    <input type="password" name="oauth_client_secret" class="form-control" value="<?= e($c['oauth_client_secret']) ?>" autocomplete="new-password">
                </div>
            </div>
            <div class="mb-3">
                <label class="form-label">Refresh Token</label>
                <textarea name="oauth_refresh_token" class="form-control" rows="2" autocomplete="off"><?= e($c['oauth_refresh_token']) ?></textarea>
            </div>
            <div class="row">
                <div class="col-md-6 mb-3">
                    <label class="form-label">Token URL <small class="text-muted">— só para "Personalizado"</small></label>
                    <input type="text" name="oauth_token_url" class="form-control" value="<?= e($c['oauth_token_url']) ?>">
                </div>
                <div class="col-md-6 mb-3">
                    <label class="form-label">Scope <small class="text-muted">— opcional</small></label>
                    <input type="text" name="oauth_scope" class="form-control" value="<?= e($c['oauth_scope']) ?>">
                </div>
            </div>
            <p class="text-muted small mb-0">
                Requer a extensão cURL no servidor e acesso de saída ao provedor para obter o token.
            </p>
        </div>
    </div>

    <button type="submit" name="acao" value="salvar" class="btn btn-success">Salvar configuração</button>
    <a href="<?= url('index.php') ?>" class="btn btn-secondary">Voltar</a>

    <hr class="my-4">

    <div class="card border-info">
        <div class="card-header bg-info text-white">Enviar e-mail de teste</div>
        <div class="card-body">
            <p class="text-muted">Salva a configuração atual e envia um e-mail de teste.</p>
            <div class="input-group" style="max-width:520px">
                <input type="email" name="email_teste" class="form-control" placeholder="seu-email@empresa.com"
                       value="<?= e($_POST['email_teste'] ?? '') ?>">
                <button type="submit" name="acao" value="teste" class="btn btn-outline-info">Enviar teste</button>
            </div>
        </div>
    </div>
</form>

<script>
function alternarMetodo() {
    var m = document.getElementById('metodo').value;
    document.querySelectorAll('.bloco-oauth').forEach(function (el) {
        el.style.display = (m === 'oauth') ? '' : 'none';
    });
    document.querySelectorAll('.bloco-smtp-senha').forEach(function (el) {
        el.style.display = (m === 'oauth') ? 'none' : '';
    });
}
document.addEventListener('DOMContentLoaded', alternarMetodo);
</script>

<?php require __DIR__ . '/../includes/footer.php'; ?>
