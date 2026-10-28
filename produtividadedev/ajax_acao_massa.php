<?php
// Ação em massa sobre máquinas (tela Máquinas): recebe os ids (CSV) escolhidos no cliente — as selecionadas ou
// todas as listadas — e aplica UMA ação a cada uma, devolvendo o resultado por máquina (ok / pulada / erro).
// Ações: sincronizar, atualizar, instalar, desinstalar, agendar (atualizar na próxima vez online),
// cancelar_agendamento, ativar, desativar.
require_once 'config.php';
checkAuth();
requireAdmin();
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'erro' => 'Método não permitido.']);
    exit;
}
csrfVerify();
set_time_limit(300);

// Instalar/atualizar/desinstalar disparam, cada uma, um processo em segundo plano que copia o MSI (~85 MB)
// e usa WMI na máquina de destino: dezenas ao mesmo tempo sobrecarregam o servidor e a rede. Acima deste
// limite a ação imediata é recusada — para lotes grandes, usa-se "Atualizar na próxima vez online", que
// escalona sozinho (cada máquina é atualizada quando sincroniza com sucesso).
const ACAO_MASSA_LIMITE_IMEDIATO = 10;
const ACAO_MASSA_LIMITE_TOTAL = 300;

$acoes = ['sincronizar', 'atualizar', 'instalar', 'desinstalar', 'agendar', 'cancelar_agendamento', 'ativar', 'desativar'];
$acao = $_POST['acao'] ?? '';
if (!in_array($acao, $acoes, true)) {
    echo json_encode(['ok' => false, 'erro' => 'Ação inválida.']);
    exit;
}
$ids = array_values(array_unique(array_filter(array_map('intval', explode(',', (string)($_POST['ids'] ?? ''))), fn($v) => $v > 0)));
if (!$ids) {
    echo json_encode(['ok' => false, 'erro' => 'Nenhuma máquina informada.']);
    exit;
}
if (count($ids) > ACAO_MASSA_LIMITE_TOTAL) {
    echo json_encode(['ok' => false, 'erro' => 'Máximo de ' . ACAO_MASSA_LIMITE_TOTAL . ' máquinas por ação.']);
    exit;
}

$db = getDB();
$stmt = $db->query("SELECT * FROM maquinas WHERE id IN (" . implode(',', $ids) . ") ORDER BY nome");
$maquinas = $stmt->fetchAll();

$ehInstalacao = in_array($acao, ['atualizar', 'instalar', 'desinstalar'], true);
if ($ehInstalacao && count($maquinas) > ACAO_MASSA_LIMITE_IMEDIATO) {
    echo json_encode(['ok' => false, 'erro' => 'Para ' . count($maquinas) . ' máquinas o limite de ação imediata é ' . ACAO_MASSA_LIMITE_IMEDIATO
        . ' por vez (cada uma copia o MSI e roda via WMI). Use "Atualizar na próxima vez online" para lotes maiores — ela escalona sozinha.']);
    exit;
}
if (($ehInstalacao || $acao === 'agendar') && empty(getInstalacaoConfig($db)['admin_usuario'])) {
    echo json_encode(['ok' => false, 'erro' => 'Configure a conta de administrador em "Instalação Remota" antes.']);
    exit;
}

// Agente instalado = a última instalação/atualização/desinstalação COM SUCESSO foi instalar ou atualizar (mesma regra da tela).
$instalada = function ($id) use ($db) {
    $s = $db->prepare("SELECT acao FROM instalacoes_remotas WHERE maquina_id = :id AND status = 'ok' ORDER BY id DESC LIMIT 1");
    $s->execute([':id' => $id]);
    return in_array($s->fetchColumn(), ['instalar', 'atualizar'], true);
};
$emAndamento = function ($id) use ($db) {
    $s = $db->prepare("SELECT COUNT(*) FROM instalacoes_remotas WHERE maquina_id = :id AND status IN ('fila', 'executando')");
    $s->execute([':id' => $id]);
    return (int)$s->fetchColumn() > 0;
};

$resultados = []; $resumo = ['ok' => 0, 'puladas' => 0, 'erros' => 0];
$registra = function ($m, $status, $msg) use (&$resultados, &$resumo) {
    $resultados[] = ['id' => (int)$m['id'], 'nome' => $m['nome'], 'status' => $status, 'mensagem' => $msg];
    $resumo[$status === 'ok' ? 'ok' : ($status === 'pulada' ? 'puladas' : 'erros')]++;
};

foreach ($maquinas as $m) {
    try {
        switch ($acao) {
            case 'sincronizar':
                $r = sincronizarMaquina($db, $m, 'manual');
                if (!empty($r['ok'])) $registra($m, 'ok', ($r['eventos_novos'] ?? 0) . ' evento(s) novo(s)' . (!empty($r['aviso']) ? ' — ' . $r['aviso'] : ''));
                else $registra($m, 'erro', $r['erro'] ?? 'falha na sincronização');
                break;
            case 'atualizar':
                if (!$instalada($m['id'])) { $registra($m, 'pulada', 'sem instalação registrada — use "Instalar"'); break; }
                if ($emAndamento($m['id'])) { $registra($m, 'pulada', 'já há uma instalação/atualização em andamento'); break; }
                dispararInstalacaoRemota($db, $m, 'atualizar');
                $registra($m, 'ok', 'atualização iniciada');
                break;
            case 'instalar':
                if ($instalada($m['id'])) { $registra($m, 'pulada', 'agente já instalado — use "Atualizar"'); break; }
                if ($emAndamento($m['id'])) { $registra($m, 'pulada', 'já há uma instalação/atualização em andamento'); break; }
                dispararInstalacaoRemota($db, $m, 'instalar');
                $registra($m, 'ok', 'instalação iniciada');
                break;
            case 'desinstalar':
                if (!$instalada($m['id'])) { $registra($m, 'pulada', 'agente não consta como instalado'); break; }
                if ($emAndamento($m['id'])) { $registra($m, 'pulada', 'já há uma instalação/atualização em andamento'); break; }
                dispararInstalacaoRemota($db, $m, 'desinstalar');
                $registra($m, 'ok', 'desinstalação iniciada');
                break;
            case 'agendar':
                if (!$instalada($m['id'])) { $registra($m, 'pulada', 'sem instalação registrada — o agendamento é só de atualização'); break; }
                $db->prepare("UPDATE maquinas SET atualizar_pendente_em = :t WHERE id = :id")->execute([':t' => date('Y-m-d H:i:s'), ':id' => $m['id']]);
                $registra($m, 'ok', 'atualização agendada para a próxima sincronização bem-sucedida');
                break;
            case 'cancelar_agendamento':
                if (empty($m['atualizar_pendente_em'])) { $registra($m, 'pulada', 'não havia atualização agendada'); break; }
                $db->prepare("UPDATE maquinas SET atualizar_pendente_em = NULL WHERE id = :id")->execute([':id' => $m['id']]);
                $registra($m, 'ok', 'agendamento cancelado');
                break;
            case 'ativar':
            case 'desativar':
                $novo = $acao === 'ativar' ? 1 : 0;
                if ((int)$m['ativo'] === $novo) { $registra($m, 'pulada', $novo ? 'já estava ativa' : 'já estava inativa'); break; }
                $db->prepare("UPDATE maquinas SET ativo = :a WHERE id = :id")->execute([':a' => $novo, ':id' => $m['id']]);
                $registra($m, 'ok', $novo ? 'ativada' : 'desativada');
                break;
        }
    } catch (Throwable $e) {
        $registra($m, 'erro', $e->getMessage());
    }
}

echo json_encode(['ok' => true, 'acao' => $acao, 'resumo' => $resumo, 'resultados' => $resultados], JSON_UNESCAPED_UNICODE);
