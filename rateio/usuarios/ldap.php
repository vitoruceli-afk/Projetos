<?php

declare(strict_types=1);

use App\Core\Auth;
use App\Core\Session;
use App\Models\ConfigLdap;

require __DIR__ . '/../includes/bootstrap.php';
Auth::exigirAdmin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    ConfigLdap::salvar([
        'habilitado'    => isset($_POST['habilitado']),
        'host'          => trim($_POST['host'] ?? ''),
        'porta'         => (int) ($_POST['porta'] ?? 389),
        'base_dn'       => trim($_POST['base_dn'] ?? ''),
        'dominio'       => trim($_POST['dominio'] ?? ''),
        'grupo_admin'   => trim($_POST['grupo_admin'] ?? ''),
        'grupo_usuario' => trim($_POST['grupo_usuario'] ?? ''),
        'filtro_login'  => trim($_POST['filtro_login'] ?? 'sAMAccountName'),
    ]);
    Session::flash('success', 'Configuração de LDAP salva.');
    header('Location: ' . url('usuarios/ldap.php'));
    exit;
}

$cfg = ConfigLdap::efetiva();

$contexto     = 'inicial';
$tituloPagina = 'Integração LDAP';
require __DIR__ . '/../includes/header.php';
?>

<h2 class="mb-4">Integração LDAP / Active Directory</h2>

<?php if (!function_exists('ldap_connect')): ?>
    <div class="alert alert-warning">
        <i class="bi bi-exclamation-triangle"></i>
        A extensão <code>php-ldap</code> não está habilitada neste servidor.
        A configuração pode ser salva, mas o login LDAP só funcionará após
        habilitar a extensão.
    </div>
<?php endif; ?>

<div class="card shadow-sm">
    <div class="card-body">
        <form method="POST">

            <div class="form-check form-switch mb-4">
                <input class="form-check-input" type="checkbox" name="habilitado"
                       id="habilitado" <?= !empty($cfg['habilitado']) ? 'checked' : '' ?>>
                <label class="form-check-label" for="habilitado">
                    Habilitar autenticação via LDAP
                </label>
            </div>

            <div class="row">
                <div class="col-md-8 mb-3">
                    <label class="form-label">Servidor (host)</label>
                    <input type="text" name="host" class="form-control"
                           value="<?= e($cfg['host']) ?>"
                           placeholder="ldap://servidor ou ldaps://servidor">
                </div>
                <div class="col-md-4 mb-3">
                    <label class="form-label">Porta</label>
                    <input type="number" name="porta" class="form-control"
                           value="<?= e($cfg['porta']) ?>" placeholder="389 ou 636">
                </div>
            </div>

            <div class="mb-3">
                <label class="form-label">Base DN</label>
                <input type="text" name="base_dn" class="form-control"
                       value="<?= e($cfg['base_dn']) ?>"
                       placeholder="DC=empresa,DC=local">
            </div>

            <div class="mb-3">
                <label class="form-label">Domínio (sufixo UPN)</label>
                <input type="text" name="dominio" class="form-control"
                       value="<?= e($cfg['dominio']) ?>"
                       placeholder="empresa.local">
            </div>

            <hr>
            <p class="text-muted">
                Grupos de segurança usados como <strong>filtro</strong> de acesso:
            </p>

            <div class="mb-3">
                <label class="form-label">Grupo de Administradores</label>
                <input type="text" name="grupo_admin" class="form-control"
                       value="<?= e($cfg['grupo_admin']) ?>"
                       placeholder="CN=Rateio-Admins,OU=Grupos,DC=empresa,DC=local">
                <div class="form-text">Membros recebem o perfil <strong>admin</strong>.</div>
            </div>

            <div class="mb-3">
                <label class="form-label">Grupo de Usuários</label>
                <input type="text" name="grupo_usuario" class="form-control"
                       value="<?= e($cfg['grupo_usuario']) ?>"
                       placeholder="CN=Rateio-Usuarios,OU=Grupos,DC=empresa,DC=local">
                <div class="form-text">Membros recebem o perfil <strong>usuário</strong> (somente leitura).</div>
            </div>

            <div class="mb-4">
                <label class="form-label">Atributo de login</label>
                <input type="text" name="filtro_login" class="form-control"
                       value="<?= e($cfg['filtro_login']) ?>"
                       placeholder="sAMAccountName">
            </div>

            <button type="submit" class="btn btn-success">Salvar</button>
            <a href="<?= url('usuarios/listar.php') ?>" class="btn btn-secondary">Voltar</a>
        </form>
    </div>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
