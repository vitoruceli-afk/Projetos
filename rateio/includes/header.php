<?php

declare(strict_types=1);

use App\Core\Auth;
use App\Core\Session;
use App\Core\Config;

/*
|--------------------------------------------------------------------------
| HEADER / LAYOUT
|--------------------------------------------------------------------------
|
| Variáveis que a página pode definir antes de incluir este header:
|   $tituloPagina  -> título da aba
|   $contexto      -> 'inicial' | 'microsoft' | 'telefonia'
|
*/

require_once __DIR__ . '/bootstrap.php';

Auth::exigirLogin();

$contexto = $contexto ?? 'inicial';
Session::set('contexto', $contexto);

$tituloPagina = $tituloPagina ?? Config::get('app.nome');
$ehAdmin      = Auth::ehAdmin();

// Rótulo / cor do contexto atual
$contextoInfo = match ($contexto) {
    'microsoft' => ['rotulo' => 'Rateio Microsoft', 'classe' => 'bg-primary'],
    'telefonia'      => ['rotulo' => 'Rateio Telefonia',      'classe' => 'bg-danger'],
    default     => ['rotulo' => 'Área Inicial',     'classe' => 'bg-dark'],
};

$flashes = Session::pegarFlash();
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($tituloPagina) ?> - <?= e(Config::get('app.nome')) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="<?= url('assets/css/app.css') ?>" rel="stylesheet">
</head>
<body class="bg-light">

<!-- BARRA DE CONTEXTO -->
<div class="context-bar <?= $contextoInfo['classe'] ?> text-white">
    <div class="container-fluid d-flex justify-content-between align-items-center py-1 px-3">
        <span class="small">
            <i class="bi bi-geo-alt-fill"></i>
            Você está em: <strong><?= e($contextoInfo['rotulo']) ?></strong>
        </span>
        <span class="small">
            <i class="bi bi-person-circle"></i>
            <?= e(Auth::nome()) ?>
            <span class="badge bg-light text-dark ms-1"><?= e(ucfirst(Auth::perfil())) ?></span>
        </span>
    </div>
</div>

<!-- NAVBAR PRINCIPAL -->
<nav class="navbar navbar-expand-lg navbar-dark bg-dark">
    <div class="container-fluid">

        <a class="navbar-brand" href="<?= url('index.php') ?>">
            <i class="bi bi-pie-chart-fill"></i>
            <?= e(Config::get('app.nome')) ?>
        </a>

        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#menu">
            <span class="navbar-toggler-icon"></span>
        </button>

        <div class="collapse navbar-collapse" id="menu">
            <ul class="navbar-nav me-auto">

                <?php if ($contexto === 'inicial'): ?>

                    <?php if ($ehAdmin): ?>
                        <li class="nav-item">
                            <a class="nav-link" href="<?= url('usuarios/listar.php') ?>">
                                <i class="bi bi-people"></i> Usuários
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="<?= url('contatos/listar.php') ?>">
                                <i class="bi bi-person-lines-fill"></i> Contatos
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="<?= url('smtp/index.php') ?>">
                                <i class="bi bi-envelope-gear"></i> SMTP
                            </a>
                        </li>
                    <?php endif; ?>

                    <li class="nav-item">
                        <a class="nav-link" href="<?= url('peps/listar.php') ?>">
                            <i class="bi bi-diagram-3"></i> PEPs / Projetos
                        </a>
                    </li>

                <?php elseif ($contexto === 'microsoft'): ?>

                    <li class="nav-item">
                        <a class="nav-link" href="<?= url('microsoft/contas/listar.php') ?>">Contas</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="<?= url('microsoft/licencas/listar.php') ?>">Licenças</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="<?= url('microsoft/cobrancas/listar.php') ?>">Cobranças</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="<?= url('microsoft/rateios/listar.php') ?>">Rateios Gerados</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="<?= url('microsoft/relatorios/index.php') ?>">Relatórios</a>
                    </li>

                <?php elseif ($contexto === 'telefonia'): ?>

                    <li class="nav-item">
                        <a class="nav-link" href="<?= url('telefonia/contas/listar.php') ?>">Contas</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="<?= url('telefonia/cobrancas/listar.php') ?>">Cobranças</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="<?= url('telefonia/rateios/listar.php') ?>">Rateios Gerados</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="<?= url('telefonia/relatorios/index.php') ?>">Relatórios</a>
                    </li>

                <?php endif; ?>

            </ul>

            <ul class="navbar-nav ms-auto">

                <!-- ALTERNADOR DE CONTEXTO -->
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle" href="#" role="button"
                       data-bs-toggle="dropdown">
                        <i class="bi bi-arrow-left-right"></i> Alternar Rateio
                    </a>
                    <ul class="dropdown-menu dropdown-menu-end">
                        <li>
                            <a class="dropdown-item" href="<?= url('index.php') ?>">
                                <i class="bi bi-house"></i> Área Inicial
                            </a>
                        </li>
                        <li><hr class="dropdown-divider"></li>
                        <li>
                            <a class="dropdown-item" href="<?= url('microsoft/contas/listar.php') ?>">
                                <i class="bi bi-microsoft"></i> Rateio Microsoft
                            </a>
                        </li>
                        <li>
                            <a class="dropdown-item" href="<?= url('telefonia/contas/listar.php') ?>">
                                <i class="bi bi-telephone"></i> Rateio Telefonia
                            </a>
                        </li>
                    </ul>
                </li>

                <li class="nav-item">
                    <a class="nav-link text-danger" href="<?= url('logout.php') ?>">
                        <i class="bi bi-box-arrow-right"></i> Sair
                    </a>
                </li>
            </ul>
        </div>
    </div>
</nav>

<div class="container my-4">

    <?php foreach ($flashes as $msg): ?>
        <div class="alert alert-<?= e($msg['tipo']) ?> alert-dismissible fade show">
            <?= $msg['texto'] ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endforeach; ?>
