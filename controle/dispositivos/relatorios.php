<?php

declare(strict_types=1);

use App\Models\Dispositivo;
use App\Models\Pep;

$contexto     = 'inicial';
$tituloPagina = 'Relatórios de Dispositivos';

require __DIR__ . '/../includes/header.php';

$stats = Dispositivo::estatisticas();
$peps  = Pep::comContagemDispositivos();
$semPep = Dispositivo::contarSemPep();

$totalPeps    = count($peps);
$pepsAtivos   = 0;
$pepsAVencer  = 0;
$pepsVencidos = 0;
foreach ($peps as $p) {
    match ($p['status_pep']) {
        'ativo'    => $pepsAtivos++,
        'a_vencer' => $pepsAVencer++,
        'vencido'  => $pepsVencidos++,
        default    => null,
    };
}

// Só carrega a lista de dispositivos dos PEPs que realmente têm algum
// (evita consulta desnecessária para PEPs sem dispositivo vinculado).
$dispositivosPorPep = [];
foreach ($peps as $p) {
    if ($p['qtd_dispositivos'] > 0) {
        $dispositivosPorPep[$p['id']] = Dispositivo::dispositivosPorPep((int) $p['id']);
    }
}

$formatarData = static function (?string $data): string {
    if (!$data) {
        return '—';
    }
    $partes = explode('-', $data);
    return count($partes) === 3 ? "{$partes[2]}/{$partes[1]}/{$partes[0]}" : $data;
};

$badgePrazo = static fn(string $status) => match ($status) {
    'vencido'  => '<span class="badge bg-danger">Vencido</span>',
    'a_vencer' => '<span class="badge bg-warning">A vencer</span>',
    default    => '<span class="badge bg-success">Ativo</span>',
};

$badgeStatusDispositivo = static fn(string $s) => match ($s) {
    'ativo' => '<span class="badge bg-success">Ativo</span>',
    'inativo' => '<span class="badge bg-warning">Inativo</span>',
    'descartado' => '<span class="badge bg-danger">Descartado</span>',
    'manutenção' => '<span class="badge bg-info">Manutenção</span>',
    default => '<span class="badge bg-secondary">Desconhecido</span>'
};
?>

<div class="page-head">
    <div>
        <h1 class="page-title">Relatórios de Dispositivos</h1>
        <p class="page-sub">Visão geral de dispositivos e vínculo com PEPs</p>
    </div>
    <a href="<?= url('dispositivos/listar.php') ?>" class="btn btn-outline-secondary">
        <i class="bi bi-arrow-left"></i> Voltar
    </a>
</div>

<!-- ESTATÍSTICAS -->
<div class="stat-strip">
    <div class="stat-tile">
        <div>
            <div class="stat-label">Total de Dispositivos</div>
            <div class="stat-value"><?= $stats['total'] ?></div>
        </div>
        <div class="stat-icon blue"><i class="bi bi-hdd"></i></div>
    </div>
    <div class="stat-tile">
        <div>
            <div class="stat-label">Total de PEPs</div>
            <div class="stat-value"><?= $totalPeps ?></div>
        </div>
        <div class="stat-icon blue"><i class="bi bi-diagram-3"></i></div>
    </div>
    <div class="stat-tile">
        <div>
            <div class="stat-label">PEPs Ativos</div>
            <div class="stat-value online-c"><?= $pepsAtivos ?></div>
        </div>
        <div class="stat-icon green"><i class="bi bi-check-circle"></i></div>
    </div>
    <div class="stat-tile">
        <div>
            <div class="stat-label">PEPs a Vencer (30 dias)</div>
            <div class="stat-value warning-c"><?= $pepsAVencer ?></div>
        </div>
        <div class="stat-icon orange"><i class="bi bi-hourglass-split"></i></div>
    </div>
    <div class="stat-tile">
        <div>
            <div class="stat-label">PEPs Vencidos</div>
            <div class="stat-value critical-c"><?= $pepsVencidos ?></div>
        </div>
        <div class="stat-icon red"><i class="bi bi-exclamation-circle"></i></div>
    </div>
</div>

<!-- DISPOSITIVOS POR PEP -->
<div class="card shadow-sm mt-4">
    <div class="card-header">
        <strong><i class="bi bi-list-ul"></i> Dispositivos por PEP</strong>
    </div>
    <div class="card-body table-responsive">
        <table class="table table-hover align-middle">
            <thead class="table-dark">
                <tr>
                    <th>PEP</th>
                    <th>Projeto</th>
                    <th>Status do Prazo</th>
                    <th>Data Término</th>
                    <th>Qtd. Dispositivos</th>
                    <th width="140">Dispositivos</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($peps as $p): ?>
                <tr>
                    <td><strong><?= e($p['pep']) ?></strong></td>
                    <td><?= e($p['projeto']) ?></td>
                    <td><?= $badgePrazo($p['status_pep']) ?></td>
                    <td><?= $formatarData($p['data_termino']) ?></td>
                    <td><span class="badge bg-secondary"><?= $p['qtd_dispositivos'] ?></span></td>
                    <td>
                        <?php if ($p['qtd_dispositivos'] > 0): ?>
                            <button type="button" class="btn btn-outline-secondary btn-sm"
                                    data-bs-toggle="modal" data-bs-target="#modalDispositivosPep"
                                    data-pep="<?= (int) $p['id'] ?>" data-pep-nome="<?= e($p['pep']) ?>">
                                <i class="bi bi-eye"></i> Ver
                            </button>
                        <?php else: ?>
                            <span class="text-muted">—</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if ($semPep > 0): ?>
                <tr>
                    <td colspan="4"><span class="text-muted">Sem PEP associado</span></td>
                    <td><span class="badge bg-secondary"><?= $semPep ?></span></td>
                    <td>
                        <a href="<?= url('dispositivos/listar.php') ?>" class="btn btn-outline-secondary btn-sm">
                            <i class="bi bi-eye"></i> Ver
                        </a>
                    </td>
                </tr>
            <?php endif; ?>
            <?php if ($peps === [] && $semPep === 0): ?>
                <tr><td colspan="6" class="text-center text-muted py-4">Nenhum dispositivo cadastrado.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- MODAL DE DISPOSITIVOS DO PEP -->
<div class="modal fade" id="modalDispositivosPep" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-scrollable modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="mdpTitulo">Dispositivos do PEP</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div id="mdpConteudo"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Fechar</button>
            </div>
        </div>
    </div>
</div>

<script>
const dispositivosPorPep = <?= json_encode(array_map(
    static fn($lista) => array_map(
        static fn($d) => [
            'nome' => $d['nome'],
            'tipo' => $d['tipo_nome'],
            'status' => $d['status'],
        ],
        $lista
    ),
    $dispositivosPorPep
), JSON_UNESCAPED_UNICODE) ?>;

const badgesStatus = {
    ativo: '<span class="badge bg-success">Ativo</span>',
    inativo: '<span class="badge bg-warning">Inativo</span>',
    descartado: '<span class="badge bg-danger">Descartado</span>',
    'manutenção': '<span class="badge bg-info">Manutenção</span>',
};

document.getElementById('modalDispositivosPep').addEventListener('show.bs.modal', function (event) {
    const btn = event.relatedTarget;
    const pepId = btn.dataset.pep;
    const lista = dispositivosPorPep[pepId] || [];

    document.getElementById('mdpTitulo').textContent = 'Dispositivos do PEP ' + btn.dataset.pepNome;

    let html = '<table class="table table-sm table-hover"><thead><tr><th>Dispositivo</th><th>Tipo</th><th>Status</th></tr></thead><tbody>';
    lista.forEach(function (d) {
        const badge = badgesStatus[d.status] || '<span class="badge bg-secondary">' + d.status + '</span>';
        html += '<tr><td>' + escapeHtml(d.nome) + '</td><td>' + escapeHtml(d.tipo || '—') + '</td><td>' + badge + '</td></tr>';
    });
    html += '</tbody></table>';

    document.getElementById('mdpConteudo').innerHTML = html;
});

function escapeHtml(texto) {
    const div = document.createElement('div');
    div.textContent = texto == null ? '' : texto;
    return div.innerHTML;
}
</script>

<?php require __DIR__ . '/../includes/footer.php'; ?>
