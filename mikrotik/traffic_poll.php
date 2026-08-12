<?php
// Endpoint JSON consultado via polling (fetch) pela tela de Diagnóstico para mostrar o
// tráfego em tempo real das interfaces ativas do roteador selecionado. Não é uma "view" incluída
// pelo layout.php — é chamado diretamente pelo navegador a cada poucos segundos.
require_once 'config.php';
require_once 'RouterosAPI.php';
checkAuth();

header('Content-Type: application/json; charset=utf-8');

// CPU/memória e tráfego das interfaces são informação de infraestrutura do equipamento em si
// (não de um hotspot/regra específico), então ficam restritos ao mesmo escopo de Administrador
// usado no resto da área de infraestrutura (Roteadores, Logs).
if (!isAdmin()) {
    http_response_code(403);
    echo json_encode(['error' => 'Acesso restrito ao perfil Administrador.']);
    exit;
}

if (empty($_SESSION['active_router']) || !hasRouterAccess($_SESSION['active_router'])) {
    http_response_code(403);
    echo json_encode(['error' => 'Nenhum roteador ativo ou sem acesso a ele.']);
    exit;
}

$db = getDB();
$stmt = $db->prepare("SELECT * FROM routers WHERE id = :id");
$stmt->bindValue(':id', $_SESSION['active_router'], PDO::PARAM_INT);
$stmt->execute();
$router = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$router) {
    http_response_code(404);
    echo json_encode(['error' => 'Roteador não encontrado.']);
    exit;
}

$api = new RouterosAPI();
$api->port = (int)($router['port'] ?? 8728);
$api->timeout = 3;

if (!$api->connect($router['ip'], $router['username'], routerDecrypt($router['password']))) {
    http_response_code(502);
    echo json_encode(['error' => 'Falha ao conectar ao MikroTik.']);
    exit;
}

// CPU e memória atuais do roteador (mesma conexão, evita abrir uma segunda a cada poll).
$resourceRaw = $api->comm('/system/resource/print');
$resource = is_array($resourceRaw) && isset($resourceRaw[0]) ? $resourceRaw[0] : [];
$totalMemory = (float)($resource['total-memory'] ?? 0);
$freeMemory = (float)($resource['free-memory'] ?? 0);
$system = [
    'cpu_load' => (float)($resource['cpu-load'] ?? 0),
    'total_memory' => $totalMemory,
    'free_memory' => $freeMemory,
    'used_memory' => max(0, $totalMemory - $freeMemory),
];

// "Ativa" = habilitada e efetivamente up (running), não só cadastrada.
$api->write('/interface/print');
$ifaces = $api->read(true, 5);

$activeNames = [];
$ifaceType = [];
if (is_array($ifaces)) {
    foreach ($ifaces as $row) {
        if (!is_array($row) || empty($row['name'])) continue;
        $disabled = isset($row['disabled']) && in_array(strtolower((string)$row['disabled']), ['true', 'yes']);
        $running = isset($row['running']) && in_array(strtolower((string)$row['running']), ['true', 'yes']);
        if (!$disabled && $running) {
            $activeNames[] = $row['name'];
            $ifaceType[$row['name']] = $row['type'] ?? '-';
        }
    }
}

$traffic = [];
if (!empty($activeNames)) {
    // /interface/monitor-traffic com "once" devolve uma leitura instantânea (bits/s já calculados
    // pelo próprio RouterOS) para todas as interfaces pedidas de uma vez, sem precisar tirar duas
    // amostras manualmente e calcular o delta aqui.
    $raw = $api->comm('/interface/monitor-traffic', ['interface' => implode(',', $activeNames), 'once' => ''], 5);
    if (is_array($raw)) {
        foreach ($raw as $row) {
            if (!is_array($row) || empty($row['name'])) continue;
            $traffic[] = [
                'name' => $row['name'],
                'type' => $ifaceType[$row['name']] ?? '-',
                'rx_bps' => (float)($row['rx-bits-per-second'] ?? 0),
                'tx_bps' => (float)($row['tx-bits-per-second'] ?? 0),
            ];
        }
    }
}
$api->disconnect();

// Mais movimentadas primeiro, pra quem tem muitas interfaces ver o que importa sem precisar rolar.
usort($traffic, fn($a, $b) => ($b['rx_bps'] + $b['tx_bps']) <=> ($a['rx_bps'] + $a['tx_bps']));

echo json_encode([
    'ok' => true,
    'router' => $router['name'],
    'system' => $system,
    'interfaces' => $traffic,
]);
