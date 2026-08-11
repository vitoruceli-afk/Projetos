<?php
// Estabelece a conexão com o banco de dados MySQL
$db = getDB();

// LÓGICA DE ALTERNÂNCIA: Intercepta o clique do botão "Gerenciar"
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'switch_router') {
    csrfVerify();
    $router_id = (int)$_POST['router_id'];
    
    // Sincronizado exatamente com a sessão utilizada no index.php
    $_SESSION['active_router'] = $router_id;
    
    // Mensagem de feedback rápido na tela
    echo "<div class='alert alert-success alert-dismissible fade show mb-3' role='alert'>
            <i class='bi bi-check-circle-fill'></i> Roteador alterado com sucesso! Redirecionando...
            <button type='button' class='btn-close' data-bs-dismiss='alert' aria-label='Close'></button>
          </div>";
          
    // Força o recarregamento da página para atualizar o menu superior e os cookies de sessão da API
    header("Refresh:1; url=index.php?page=dashboard");
}

try {
    // Busca todos os roteadores cadastrados na página "Gerenciar Roteadores"
    $stmt = $db->query("SELECT id, name, location, ip, username, password, port FROM routers ORDER BY name ASC");
    $routers_list = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    echo "<div class='alert alert-danger'>Erro ao buscar roteadores no banco de dados: " . $e->getMessage() . "</div>";
    return;
}

// Recupera o ID do roteador ativo atualmente usando a chave correta (pode ser NULL se nenhum estiver selecionado)
$active_id_now = $_SESSION['active_router'] ?? null;

$dashboard_data = [];
$total_online = 0;
$total_offline = 0;

// Varre a lista de roteadores vinda do MySQL
if (!empty($routers_list)) {
    // 1) Testa a alcançabilidade TCP de TODOS os roteadores em paralelo (não-bloqueante),
    //    em vez de tentar conectar um por um em sequência. Isso evita que N roteadores
    //    offline somem N segundos de espera (1s de timeout cada) no carregamento da página.
    $targets = [];
    foreach ($routers_list as $router) {
        if (!empty($router['ip'])) {
            $targets[$router['id']] = ['host' => $router['ip'], 'port' => (int)($router['port'] ?? 8728)];
        }
    }
    $alive_map = class_exists('RouterosAPI') ? RouterosAPI::checkHostsAlive($targets, 1.5) : [];

    foreach ($routers_list as $router) {
        $id = $router['id'];
        $host = $router['ip'];
        $user = $router['username'];
        $pass = routerDecrypt($router['password']);
        $port = $router['port'] ?? 8728;
        $name = $router['name'];
        $location = $router['location'];

        $is_online = false;
        $version = '-';
        $model = '-';
        $uptime = '-';

        // 2) Só tenta login completo (mais lento) nos roteadores que responderam ao teste TCP.
        if (!empty($host) && !empty($alive_map[$id])) {
            $routerAPI = new RouterosAPI();
            $routerAPI->port = (int)$port;
            $routerAPI->timeout = 1;

            if (@$routerAPI->connect($host, $user, $pass)) {
                $is_online = true;
                $total_online++;

                // Coleta dados de hardware e sistema se estiver Online
                $resource = $routerAPI->comm('/system/resource/print');
                if (!empty($resource[0])) {
                    $version = $resource[0]['version'] ?? 'Desconhecida';
                    $model = $resource[0]['board-name'] ?? 'MikroTik';
                    $uptime = $resource[0]['uptime'] ?? '00:00:00';
                }
                $routerAPI->disconnect();
            } else {
                $total_offline++;
            }
        } else {
            $total_offline++;
        }

        $dashboard_data[] = [
            'id' => $id,
            'name' => $name,
            'location' => $location,
            'host' => $host,
            'model' => $model,
            'version' => $version,
            'uptime' => $uptime,
            'online' => $is_online
        ];
    }
}

$total_routers = count($dashboard_data);
?>

<div class="page-head">
    <div>
        <h1 class="page-title">Dashboard de Monitoramento de Infraestrutura</h1>
        <div class="page-sub">Status em tempo real dos equipamentos cadastrados</div>
    </div>
</div>

<?php if (empty($active_id_now) && $total_routers > 0): ?>
    <div class="alert alert-warning mb-4" role="alert">
        <strong>Nenhum roteador selecionado para gerência.</strong>
        Escolha um dos MikroTiks operacionais na lista abaixo e clique em <strong>"Gerenciar"</strong> para liberar os menus de Firewall e Hotspot.
    </div>
<?php endif; ?>

<div class="stat-strip">
    <div class="stat-tile">
        <div class="stat-label">Cadastrados</div>
        <div class="stat-value"><?= $total_routers ?></div>
        <div class="stat-note">equipamentos monitorados</div>
    </div>
    <div class="stat-tile">
        <div class="stat-label">Online <span class="dot online"></span></div>
        <div class="stat-value online-c"><?= $total_online ?></div>
        <div class="stat-note">respondendo à API</div>
    </div>
    <div class="stat-tile">
        <div class="stat-label">Offline <span class="dot offline"></span></div>
        <div class="stat-value critical-c"><?= $total_offline ?></div>
        <div class="stat-note">inacessíveis agora</div>
    </div>
</div>

<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span>Equipamentos</span>
        <span class="badge bg-light"><?= $total_routers ?> roteador<?= $total_routers === 1 ? '' : 'es' ?></span>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-striped table-hover align-middle mb-0">
                <thead class="table-dark">
                    <tr>
                        <th>Nome do Equipamento</th>
                        <th>Localidade / Campus</th>
                        <th>Endereço IP</th>
                        <th>Modelo / Hardware</th>
                        <th>Versão RouterOS</th>
                        <th>Uptime (Tempo Ativo)</th>
                        <th class="text-center">Status</th>
                        <th class="text-center" style="width: 150px;">Ação</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($dashboard_data)): ?>
                        <tr>
                            <td colspan="8" class="text-center text-muted py-4">
                                Nenhum equipamento cadastrado. Vá até a página <a href="index.php?page=routers" class="fw-bold text-decoration-none">Gerenciar Roteadores</a> para começar.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($dashboard_data as $router): ?>
                            <?php 
                                // Validação segura do roteador ativo
                                $is_current_active = ($active_id_now !== null && $active_id_now == $router['id']); 
                            ?>
                            <tr class="<?= $is_current_active ? 'table-success' : '' ?>">
                                <td>
                                    <strong><?= htmlspecialchars($router['name']) ?></strong>
                                    <?php if ($is_current_active): ?>
                                        <span class="badge bg-success ms-1">Atual</span>
                                    <?php endif; ?>
                                </td>
                                <td><span class="badge bg-light"><i class="bi bi-geo-alt"></i> <?= htmlspecialchars($router['location']) ?></span></td>
                                <td class="mono"><?= htmlspecialchars($router['host']) ?></td>
                                <td><?= htmlspecialchars($router['model']) ?></td>
                                <td><span class="badge bg-secondary"><?= htmlspecialchars($router['version']) ?></span></td>
                                <td class="mono text-muted"><?= htmlspecialchars($router['uptime']) ?></td>
                                <td class="text-center">
                                    <?php if ($router['online']): ?>
                                        <span class="badge bg-success"><i class="bi bi-cloud-check"></i> ONLINE</span>
                                    <?php else: ?>
                                        <span class="badge bg-danger"><i class="bi bi-cloud-slash"></i> OFFLINE</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center">
                                    <?php if ($is_current_active): ?>
                                        <button class="btn btn-sm btn-success disabled w-100 text-nowrap" style="font-size: 0.8rem; padding: 4px 8px;">
                                            <i class="bi bi-eye-fill"></i> Ativo
                                        </button>
                                    <?php elseif (!$router['online']): ?>
                                        <button class="btn btn-sm btn-outline-secondary disabled w-100 text-nowrap" style="font-size: 0.8rem; padding: 4px 8px;" title="Não é possível gerenciar um equipamento offline">
                                            <i class="bi bi-slash-circle"></i> Inacessível
                                        </button>
                                    <?php else: ?>
                                        <form method="POST" action="" class="m-0">
                                            <?= csrfField() ?>
                                            <input type="hidden" name="action" value="switch_router">
                                            <input type="hidden" name="router_id" value="<?= $router['id'] ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-primary w-100 text-nowrap" style="font-size: 0.8rem; padding: 4px 8px;">
                                                <i class="bi bi-arrow-left-right"></i> Gerenciar
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>