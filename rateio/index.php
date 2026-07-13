<?php

declare(strict_types=1);

use App\Core\Auth;
use App\Core\Database;

$contexto     = 'inicial';
$tituloPagina = 'Área Inicial';

require __DIR__ . '/includes/header.php';

$pdo = Database::pdo();

// Indicadores rápidos
$contaMs   = (int) $pdo->query('SELECT COUNT(*) c FROM ms_contas')->fetch()['c'];
$contaTelefonia = (int) $pdo->query('SELECT COUNT(*) c FROM telefonia_contas')->fetch()['c'];
$contaPep  = (int) $pdo->query('SELECT COUNT(*) c FROM peps')->fetch()['c'];
?>

<h1 class="mb-1">Bem-vindo, <?= e(Auth::nome()) ?></h1>
<p class="text-muted mb-4">
    Selecione o rateio que deseja gerenciar ou administre os cadastros compartilhados.
</p>

<!-- SELEÇÃO DE RATEIO -->
<div class="row g-4 mb-4">

    <div class="col-md-6">
        <a href="<?= url('microsoft/contas/listar.php') ?>"
           class="card area-card shadow-sm h-100 border-primary">
            <div class="card-body text-center py-5">
                <div class="icone text-primary mb-2"><i class="bi bi-microsoft"></i></div>
                <h3 class="card-title">Rateio Microsoft</h3>
                <p class="text-muted mb-2">
                    Contas, licenças, cobranças e relatórios financeiros por PEP.
                </p>
                <span class="badge bg-primary"><?= $contaMs ?> conta(s)</span>
            </div>
        </a>
    </div>

    <div class="col-md-6">
        <a href="<?= url('telefonia/contas/listar.php') ?>"
           class="card area-card shadow-sm h-100 border-danger">
            <div class="card-body text-center py-5">
                <div class="icone text-danger mb-2"><i class="bi bi-telephone-fill"></i></div>
                <h3 class="card-title">Rateio Telefonia</h3>
                <p class="text-muted mb-2">
                    Linhas, cobranças e relatórios financeiros por PEP.
                </p>
                <span class="badge bg-danger"><?= $contaTelefonia ?> conta(s)</span>
            </div>
        </a>
    </div>

</div>

<!-- CADASTROS COMPARTILHADOS -->
<h4 class="mb-3">Cadastros da Área Inicial</h4>

<div class="row g-4">

    <div class="col-md-6">
        <a href="<?= url('peps/listar.php') ?>" class="card area-card shadow-sm h-100">
            <div class="card-body d-flex align-items-center">
                <div class="icone text-secondary me-3"><i class="bi bi-diagram-3"></i></div>
                <div>
                    <h5 class="mb-1">PEPs / Projetos</h5>
                    <p class="text-muted mb-0">
                        Cadastro usado pelos rateios Microsoft e Telefonia.
                        <span class="badge bg-secondary"><?= $contaPep ?></span>
                    </p>
                </div>
            </div>
        </a>
    </div>

    <?php if (Auth::ehAdmin()): ?>
    <div class="col-md-6">
        <a href="<?= url('usuarios/listar.php') ?>" class="card area-card shadow-sm h-100">
            <div class="card-body d-flex align-items-center">
                <div class="icone text-secondary me-3"><i class="bi bi-people"></i></div>
                <div>
                    <h5 class="mb-1">Usuários</h5>
                    <p class="text-muted mb-0">
                        Usuários locais e integração LDAP / Active Directory.
                    </p>
                </div>
            </div>
        </a>
    </div>

    <div class="col-md-6">
        <a href="<?= url('contatos/listar.php') ?>" class="card area-card shadow-sm h-100">
            <div class="card-body d-flex align-items-center">
                <div class="icone text-secondary me-3"><i class="bi bi-person-lines-fill"></i></div>
                <div>
                    <h5 class="mb-1">Contatos / Listas de E-mail</h5>
                    <p class="text-muted mb-0">
                        Contatos das gerências Microsoft e Telefonia para envio dos rateios.
                    </p>
                </div>
            </div>
        </a>
    </div>

    <div class="col-md-6">
        <a href="<?= url('smtp/index.php') ?>" class="card area-card shadow-sm h-100">
            <div class="card-body d-flex align-items-center">
                <div class="icone text-secondary me-3"><i class="bi bi-envelope-gear"></i></div>
                <div>
                    <h5 class="mb-1">Configuração SMTP</h5>
                    <p class="text-muted mb-0">
                        Servidor SMTP ou OAuth para envio dos rateios por e-mail.
                    </p>
                </div>
            </div>
        </a>
    </div>
    <?php endif; ?>

</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
