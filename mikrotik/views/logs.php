<?php
requireAdmin();
$db = getDB();

$categoryLabels = [
    'auth' => 'Login/Logoff',
    'hotspot' => 'Hotspot',
    'firewall' => 'Firewall',
    'bypass' => 'Bypass',
    'routers' => 'Gerenciar Roteadores',
    'users' => 'Usuários',
];

$category = $_GET['category'] ?? '';
if (!array_key_exists($category, $categoryLabels)) $category = '';
$username = trim($_GET['username'] ?? '');
$dateFrom = trim($_GET['date_from'] ?? '');
$dateTo = trim($_GET['date_to'] ?? '');

$where = [];
$params = [];

if ($category !== '') {
    $where[] = 'category = :category';
    $params[':category'] = $category;
}
if ($username !== '') {
    $where[] = 'username LIKE :username';
    $params[':username'] = '%' . $username . '%';
}
if ($dateFrom !== '') {
    $where[] = 'created_at >= :date_from';
    $params[':date_from'] = $dateFrom . ' 00:00:00';
}
if ($dateTo !== '') {
    $where[] = 'created_at <= :date_to';
    $params[':date_to'] = $dateTo . ' 23:59:59';
}

$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$countStmt = $db->prepare("SELECT COUNT(*) FROM activity_log {$whereSql}");
$countStmt->execute($params);
$total = (int)$countStmt->fetchColumn();

$perPage = 50;
$p = max(1, (int)($_GET['p'] ?? 1));
$totalPages = max(1, (int)ceil($total / $perPage));
if ($p > $totalPages) $p = $totalPages;
$offset = ($p - 1) * $perPage;

$stmt = $db->prepare("SELECT * FROM activity_log {$whereSql} ORDER BY created_at DESC, id DESC LIMIT {$perPage} OFFSET {$offset}");
$stmt->execute($params);
$logs = $stmt->fetchAll();

// Mantém os filtros atuais ao trocar de página (link de paginação reescreve a query string inteira,
// por isso 'page' precisa ser preservado explicitamente para continuar apontando para esta view).
function logsQueryString(array $overrides = []) {
    $params = array_merge([
        'category' => $_GET['category'] ?? '',
        'username' => $_GET['username'] ?? '',
        'date_from' => $_GET['date_from'] ?? '',
        'date_to' => $_GET['date_to'] ?? '',
        'p' => $_GET['p'] ?? '',
    ], $overrides);
    $params = array_filter($params, fn($v) => $v !== '');
    $params['page'] = 'logs';
    return http_build_query($params);
}
?>
<h1 class="page-title mb-3">Log de Atividades</h1>

<div class="card mb-4">
    <div class="card-body">
        <form method="GET" class="row gx-3 gy-2 align-items-end">
            <input type="hidden" name="page" value="logs">
            <div class="col-12 col-sm-6 col-lg-3">
                <label class="form-label small text-muted mb-0">Categoria</label>
                <select name="category" class="form-select">
                    <option value="">Todas</option>
                    <?php foreach ($categoryLabels as $key => $label): ?>
                        <option value="<?= $key ?>" <?= $category === $key ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-12 col-sm-6 col-lg-3">
                <label class="form-label small text-muted mb-0">Usuário</label>
                <input type="text" name="username" class="form-control" placeholder="Filtrar por usuário" value="<?= htmlspecialchars($username) ?>">
            </div>
            <div class="col-6 col-lg-2">
                <label class="form-label small text-muted mb-0">De</label>
                <input type="date" name="date_from" class="form-control" value="<?= htmlspecialchars($dateFrom) ?>">
            </div>
            <div class="col-6 col-lg-2">
                <label class="form-label small text-muted mb-0">Até</label>
                <input type="date" name="date_to" class="form-control" value="<?= htmlspecialchars($dateTo) ?>">
            </div>
            <div class="col-12 col-lg-2 d-flex gap-2">
                <button type="submit" class="btn btn-outline-primary flex-fill">Filtrar</button>
                <a href="index.php?page=logs" class="btn btn-outline-secondary">Limpar</a>
            </div>
        </form>
    </div>
</div>

<div class="d-flex justify-content-between align-items-center mb-2">
    <span class="badge bg-secondary">Total: <?= $total ?> registro<?= $total === 1 ? '' : 's' ?></span>
    <?php if ($total > 0): ?>
        <span class="text-muted small">Página <?= $p ?> de <?= $totalPages ?></span>
    <?php endif; ?>
</div>

<div class="table-responsive">
    <table class="table table-striped table-hover bg-white shadow-sm table-sm align-middle" style="font-size: 0.88rem;">
        <thead class="table-dark">
            <tr>
                <th>Data/Hora</th>
                <th>Usuário</th>
                <th>Perfil</th>
                <th>Categoria</th>
                <th>Ação</th>
                <th>Detalhes</th>
                <th>IP</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($logs)): ?>
                <tr><td colspan="7" class="text-center text-muted py-4">Nenhum registro encontrado para os filtros selecionados.</td></tr>
            <?php else: ?>
                <?php foreach ($logs as $log): ?>
                    <tr>
                        <td class="mono text-nowrap"><?= htmlspecialchars($log['created_at']) ?></td>
                        <td><?= htmlspecialchars($log['username']) ?></td>
                        <td>
                            <?php if ($log['role'] === 'admin'): ?>
                                <span class="badge bg-warning text-dark">Administrador</span>
                            <?php elseif ($log['role'] === 'standard'): ?>
                                <span class="badge bg-secondary">Usuário Padrão</span>
                            <?php else: ?>
                                <span class="text-muted">-</span>
                            <?php endif; ?>
                        </td>
                        <td><span class="badge bg-info text-dark"><?= htmlspecialchars($categoryLabels[$log['category']] ?? $log['category']) ?></span></td>
                        <td><?= htmlspecialchars($log['action']) ?></td>
                        <td class="text-muted"><?= htmlspecialchars($log['details']) ?></td>
                        <td><small class="mono text-muted"><?= htmlspecialchars($log['ip_address']) ?></small></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<?php if ($totalPages > 1): ?>
<nav>
    <ul class="pagination justify-content-center">
        <li class="page-item <?= $p <= 1 ? 'disabled' : '' ?>">
            <a class="page-link" href="?<?= logsQueryString(['p' => $p - 1]) ?>">Anterior</a>
        </li>
        <li class="page-item disabled"><span class="page-link">Página <?= $p ?> de <?= $totalPages ?></span></li>
        <li class="page-item <?= $p >= $totalPages ? 'disabled' : '' ?>">
            <a class="page-link" href="?<?= logsQueryString(['p' => $p + 1]) ?>">Próxima</a>
        </li>
    </ul>
</nav>
<?php endif; ?>
