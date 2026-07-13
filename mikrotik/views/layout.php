<?php
$db = getDB();
$routers = $db->query("SELECT id, name, location FROM routers");
if (isset($_GET['select_router'])) {
    $_SESSION['active_router'] = (int)$_GET['select_router'];
    header("Location: index.php");
    exit;
}
$active_router_id = $_SESSION['active_router'] ?? null;
$is_admin = isAdmin();
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <title>Mikrotik Manager</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
</head>
<body>
<nav class="navbar navbar-expand-lg navbar-dark bg-dark">
  <div class="container-fluid">
    <a class="navbar-brand" href="index.php">Mikrotik Manager</a>
    <div class="collapse navbar-collapse">
      <ul class="navbar-nav me-auto">
        <li class="nav-item"><a class="nav-link" href="index.php?page=dashboard">Dashboard</a></li>
        <li class="nav-item"><a class="nav-link" href="index.php?page=hotspot">Hotspots</a></li>
        <li class="nav-item"><a class="nav-link" href="index.php?page=firewall">Firewall</a></li>
        <li class="nav-item"><a class="nav-link" href="index.php?page=diagnostics">Diagnóstico</a></li>
        <?php if ($is_admin): ?>
        <li class="nav-item"><a class="nav-link" href="index.php?page=routers">Gerenciar Roteadores</a></li>
        <li class="nav-item"><a class="nav-link" href="index.php?page=users">Usuários</a></li>
        <li class="nav-item"><a class="nav-link" href="index.php?page=logs">Logs</a></li>
        <?php endif; ?>
      </ul>
      <form class="d-flex me-3" method="GET" action="index.php">
        <select name="select_router" class="form-select form-select-sm" onchange="this.form.submit()">
            <option value="">-- Selecione o Mikrotik --</option>
            <?php while($row = $routers->fetch(PDO::FETCH_ASSOC)): ?>
                <option value="<?= $row['id'] ?>" <?= $active_router_id == $row['id'] ? 'selected' : '' ?>>
                    <?= htmlspecialchars($row['name'] . ' (' . $row['location'] . ')') ?>
                </option>
            <?php endwhile; ?>
        </select>
      </form>
      <span class="navbar-text me-3 text-white">
        <i class="bi bi-person-circle"></i> <?= htmlspecialchars($_SESSION['user_logged_in']) ?>
        <span class="badge <?= $is_admin ? 'bg-warning text-dark' : 'bg-secondary' ?> ms-1"><?= $is_admin ? 'Administrador' : 'Usuário Padrão' ?></span>
      </span>
      <a href="logout.php" class="btn btn-outline-light btn-sm">Sair</a>
    </div>
  </div>
</nav>
<div class="container mt-4">
    <?php
    // Permite acessar Dashboard e Gerenciar Roteadores sem selecionar um MikroTik
    $pages_without_router = ['dashboard', 'routers', 'users', 'logs'];

    if (!$active_router_id && !in_array($page, $pages_without_router)) {
        echo '<div class="alert alert-warning">Por favor, selecione um Mikrotik no menu superior para gerenciar.</div>';
    } else {
        include "views/{$page}.php";
    }
    ?>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>