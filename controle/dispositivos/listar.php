<?php

declare(strict_types=1);

use App\Core\Auth;
use App\Models\Dispositivo;
use App\Models\TipoDispositivo;
use App\Models\Pep;

$contexto     = 'inicial';
$tituloPagina = 'Dispositivos';

require __DIR__ . '/../includes/header.php';

$busca   = trim($_GET['busca'] ?? '');
$tipo_id = isset($_GET['tipo_id']) ? (int) $_GET['tipo_id'] : null;
$pep_id  = isset($_GET['pep_id']) ? (int) $_GET['pep_id'] : null;
$status  = $_GET['status'] ?? null;
$origem  = $_GET['origem'] ?? null;

$dispositivos = Dispositivo::listar($busca, $tipo_id, $pep_id, $status, $origem);
$tipos = TipoDispositivo::listar();
$peps = Pep::listar();
$ehAdmin = Auth::ehAdmin();
$stats = Dispositivo::estatisticas();

// Helper para formatar moeda
$money = static fn(float $v) => $v > 0 ? 'R$ ' . number_format($v, 2, ',', '.') : '—';

// Helper para badge de status
$badgeStatus = static fn(string $s) => match($s) {
    'ativo' => '<span class="badge bg-success">Ativo</span>',
    'inativo' => '<span class="badge bg-warning">Inativo</span>',
    'descartado' => '<span class="badge bg-danger">Descartado</span>',
    'manutenção' => '<span class="badge bg-info">Manutenção</span>',
    default => '<span class="badge bg-secondary">Desconhecido</span>'
};
?>

<div class="page-head">
    <div>
        <h1 class="page-title">Controle de Dispositivos</h1>
        <p class="page-sub">Gerencie computadores, celulares, tablets, impressoras e outros dispositivos</p>
    </div>
    <?php if ($ehAdmin): ?>
        <div>
            <a href="<?= url('dispositivos/importar.php') ?>" class="btn btn-outline-success">
                <i class="bi bi-filetype-csv"></i> Importar CSV
            </a>
            <a href="<?= url('dispositivos/sincronizar_glpi.php') ?>" class="btn btn-outline-info">
                <i class="bi bi-arrow-repeat"></i> Sincronizar GLPI
            </a>
            <a href="<?= url('dispositivos/form.php') ?>" class="btn btn-primary">
                <i class="bi bi-plus-lg"></i> Novo Dispositivo
            </a>
        </div>
    <?php endif; ?>
</div>

<!-- ESTATÍSTICAS -->
<div class="stat-strip">
    <a href="?status=" class="stat-tile">
        <div>
            <div class="stat-label">Total</div>
            <div class="stat-value"><?= $stats['total'] ?></div>
        </div>
        <div class="stat-icon blue"><i class="bi bi-hdd"></i></div>
    </a>
    <a href="?status=ativo" class="stat-tile">
        <div>
            <div class="stat-label">Ativos</div>
            <div class="stat-value online-c"><?= $stats['ativos'] ?></div>
        </div>
        <div class="stat-icon green"><i class="bi bi-check-circle"></i></div>
    </a>
    <a href="?status=inativo" class="stat-tile">
        <div>
            <div class="stat-label">Inativos</div>
            <div class="stat-value warning-c"><?= $stats['inativos'] ?></div>
        </div>
        <div class="stat-icon orange"><i class="bi bi-pause-circle"></i></div>
    </a>
    <a href="?status=descartado" class="stat-tile">
        <div>
            <div class="stat-label">Descartados</div>
            <div class="stat-value critical-c"><?= $stats['descartados'] ?></div>
        </div>
        <div class="stat-icon red"><i class="bi bi-trash"></i></div>
    </a>
    <a href="?origem=glpi" class="stat-tile">
        <div>
            <div class="stat-label">GLPI</div>
            <div class="stat-value"><?= $stats['importados_glpi'] ?></div>
        </div>
        <div class="stat-icon blue"><i class="bi bi-cloud-download"></i></div>
    </a>
</div>

<!-- FILTROS -->
<div class="entity-list-toolbar">
    <form method="GET" class="d-flex flex-wrap gap-2 align-items-center" style="flex: 1;">
        <input type="text" name="busca" class="form-control" placeholder="Buscar por nome, série, IMEI, modelo..."
               value="<?= e($busca) ?>" style="max-width: 300px;">

        <select name="tipo_id" class="form-control" style="max-width: 200px;">
            <option value="">Todos os tipos</option>
            <?php foreach ($tipos as $t): ?>
                <option value="<?= $t['id'] ?>" <?= $tipo_id === $t['id'] ? 'selected' : '' ?>>
                    <?= e($t['nome']) ?>
                </option>
            <?php endforeach; ?>
        </select>

        <select name="status" class="form-control" style="max-width: 150px;">
            <option value="">Todos os status</option>
            <option value="ativo" <?= $status === 'ativo' ? 'selected' : '' ?>>Ativo</option>
            <option value="inativo" <?= $status === 'inativo' ? 'selected' : '' ?>>Inativo</option>
            <option value="descartado" <?= $status === 'descartado' ? 'selected' : '' ?>>Descartado</option>
            <option value="manutenção" <?= $status === 'manutenção' ? 'selected' : '' ?>>Manutenção</option>
        </select>

        <button type="submit" class="btn btn-outline-secondary"><i class="bi bi-search"></i> Filtrar</button>
        <a href="<?= url('dispositivos/listar.php') ?>" class="btn btn-outline-secondary">Limpar</a>
    </form>
</div>

<?php if ($ehAdmin): ?>
<form method="POST" id="formAcoesDispositivos">
<div class="entity-list-toolbar d-flex flex-wrap gap-2 align-items-center mb-3">
    <select name="pep_id" class="form-control" style="max-width: 260px;">
        <option value="">Remover vínculo com PEP</option>
        <?php foreach ($peps as $p): ?>
            <option value="<?= $p['id'] ?>">[<?= e($p['pep']) ?>] <?= e($p['projeto']) ?></option>
        <?php endforeach; ?>
    </select>
    <button type="submit" formaction="<?= url('dispositivos/vincular_pep.php') ?>"
            class="btn btn-outline-primary" id="btnVincularPep" disabled>
        <i class="bi bi-diagram-3"></i> Vincular ao PEP
    </button>
    <button type="submit" formaction="<?= url('dispositivos/excluir.php') ?>"
            class="btn btn-outline-danger" id="btnExcluirSelecionados" disabled
            onclick="return confirm('Excluir os dispositivos selecionados? Esta ação não pode ser desfeita.')">
        <i class="bi bi-trash"></i> Excluir Selecionados
    </button>
</div>
<?php endif; ?>

<!-- TABELA -->
<div class="card shadow-sm">
    <div class="card-body table-responsive">
        <table class="table table-hover align-middle">
            <thead class="table-dark">
                <tr>
                    <?php if ($ehAdmin): ?><th width="40"><input type="checkbox" id="selecionarTodos"></th><?php endif; ?>
                    <th>Dispositivo</th>
                    <th>Tipo</th>
                    <th>PEP</th>
                    <th>Status</th>
                    <th>Origem</th>
                    <th width="100">Detalhes</th>
                    <?php if ($ehAdmin): ?><th width="100">Ações</th><?php endif; ?>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($dispositivos as $d): ?>
                <tr>
                    <?php if ($ehAdmin): ?>
                        <td><input type="checkbox" name="ids[]" value="<?= $d['id'] ?>" class="checkbox-item dispositivo-checkbox"></td>
                    <?php endif; ?>
                    <td>
                        <div style="display: flex; align-items: center; gap: 8px;">
                            <i class="bi <?= $d['icone'] ?>" style="font-size: 1.2rem;"></i>
                            <div>
                                <strong><?= e($d['nome']) ?></strong>
                                <?php if ($d['descricao']): ?>
                                    <br><small class="text-muted"><?= e(substr($d['descricao'], 0, 60)) ?></small>
                                <?php endif; ?>
                            </div>
                        </div>
                    </td>
                    <td><span class="badge bg-secondary"><?= e($d['tipo_nome']) ?></span></td>
                    <td>
                        <?php if ($d['pep_id']): ?>
                            <small>
                                <strong><?= e($d['pep_codigo']) ?></strong><br>
                                <span class="text-muted"><?= e($d['pep_projeto']) ?></span>
                            </small>
                        <?php else: ?>
                            <span class="text-muted">—</span>
                        <?php endif; ?>
                    </td>
                    <td><?= $badgeStatus($d['status']) ?></td>
                    <td>
                        <?php if ($d['origem'] === 'glpi'): ?>
                            <span class="badge bg-info"><i class="bi bi-cloud"></i> GLPI</span>
                        <?php elseif ($d['origem'] === 'csv'): ?>
                            <span class="badge bg-primary"><i class="bi bi-filetype-csv"></i> CSV</span>
                        <?php else: ?>
                            <span class="badge bg-secondary"><i class="bi bi-keyboard"></i> Manual</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-toggle="modal"
                                data-bs-target="#modalDetalhesDispositivo"
                                data-nome="<?= e($d['nome']) ?>"
                                data-responsavel="<?= e($d['responsavel'] ?: '—') ?>"
                                data-processador="<?= e($d['processador'] ?: '—') ?>"
                                data-memoria="<?= e($d['memoria'] ?: '—') ?>"
                                data-armazenamento="<?= e($d['armazenamento'] ?: '—') ?>"
                                data-so="<?= e($d['sistema_operacional'] ?: '—') ?>"
                                data-modelo="<?= e($d['modelo'] ?: '—') ?>"
                                data-fabricante="<?= e($d['fabricante'] ?: '—') ?>"
                                data-numero-serie="<?= e($d['numero_serie'] ?: '—') ?>"
                                data-imei="<?= e($d['imei'] ?: '—') ?>"
                                data-localizacao="<?= e($d['localizacao'] ?: '—') ?>"
                                data-area="<?= e($d['area'] ?: '—') ?>"
                                data-segunda-tela="<?= e($d['segunda_tela'] ?: '—') ?>"
                                data-valor="<?= e($money((float)($d['valor_aquisicao'] ?? 0))) ?>"
                                data-descricao="<?= e($d['descricao'] ?: '—') ?>"
                                data-observacoes="<?= e($d['observacoes'] ?: '—') ?>">
                            <i class="bi bi-eye"></i> Ver
                        </button>
                    </td>
                    <?php if ($ehAdmin): ?>
                        <td>
                            <a href="<?= url('dispositivos/form.php?id=' . $d['id']) ?>"
                               class="btn btn-warning btn-sm" title="Editar">
                               <i class="bi bi-pencil"></i>
                            </a>
                            <a href="<?= url('dispositivos/excluir.php?id=' . $d['id']) ?>"
                               class="btn btn-danger btn-sm"
                               onclick="return confirm('Excluir este dispositivo?')" title="Excluir">
                               <i class="bi bi-trash"></i>
                            </a>
                        </td>
                    <?php endif; ?>
                </tr>
            <?php endforeach; ?>
            <?php if ($dispositivos === []): ?>
                <tr><td colspan="<?= $ehAdmin ? 8 : 6 ?>" class="text-center text-muted py-4">
                    <i class="bi bi-inbox" style="font-size: 2rem; opacity: 0.5;"></i><br>
                    Nenhum dispositivo encontrado.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- MODAL DE DETALHES -->
<div class="modal fade" id="modalDetalhesDispositivo" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="mdNome">Detalhes do dispositivo</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <dl class="row mb-0">
                    <dt class="col-sm-5">Usuário</dt><dd class="col-sm-7" id="mdResponsavel"></dd>
                    <dt class="col-sm-5">Processador</dt><dd class="col-sm-7" id="mdProcessador"></dd>
                    <dt class="col-sm-5">Memória</dt><dd class="col-sm-7" id="mdMemoria"></dd>
                    <dt class="col-sm-5">Armazenamento</dt><dd class="col-sm-7" id="mdArmazenamento"></dd>
                    <dt class="col-sm-5">Sistema Operacional</dt><dd class="col-sm-7" id="mdSo"></dd>
                    <dt class="col-sm-5">Modelo</dt><dd class="col-sm-7" id="mdModelo"></dd>
                    <dt class="col-sm-5">Fabricante</dt><dd class="col-sm-7" id="mdFabricante"></dd>
                    <dt class="col-sm-5">Número de Série</dt><dd class="col-sm-7" id="mdNumeroSerie"></dd>
                    <dt class="col-sm-5">IMEI</dt><dd class="col-sm-7" id="mdImei"></dd>
                    <dt class="col-sm-5">Localização/Projeto</dt><dd class="col-sm-7" id="mdLocalizacao"></dd>
                    <dt class="col-sm-5">Área</dt><dd class="col-sm-7" id="mdArea"></dd>
                    <dt class="col-sm-5">2ª Tela</dt><dd class="col-sm-7" id="mdSegundaTela"></dd>
                    <dt class="col-sm-5">Valor de Aquisição</dt><dd class="col-sm-7" id="mdValor"></dd>
                    <dt class="col-sm-5">Descrição</dt><dd class="col-sm-7" id="mdDescricao"></dd>
                    <dt class="col-sm-5">Observações</dt><dd class="col-sm-7" id="mdObservacoes"></dd>
                </dl>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Fechar</button>
            </div>
        </div>
    </div>
</div>

<script>
(function () {
    const modal = document.getElementById('modalDetalhesDispositivo');
    const campos = {
        nome: 'mdNome', responsavel: 'mdResponsavel', processador: 'mdProcessador',
        memoria: 'mdMemoria', armazenamento: 'mdArmazenamento', so: 'mdSo',
        modelo: 'mdModelo', fabricante: 'mdFabricante', numeroSerie: 'mdNumeroSerie',
        imei: 'mdImei', localizacao: 'mdLocalizacao', area: 'mdArea',
        segundaTela: 'mdSegundaTela', valor: 'mdValor', descricao: 'mdDescricao',
        observacoes: 'mdObservacoes',
    };
    modal.addEventListener('show.bs.modal', function (event) {
        const btn = event.relatedTarget;
        for (const [chave, idElemento] of Object.entries(campos)) {
            document.getElementById(idElemento).textContent = btn.dataset[chave] || '—';
        }
    });
})();
</script>

<?php if ($ehAdmin): ?>
</form>

<script>
(function () {
    const checkboxes = document.querySelectorAll('.dispositivo-checkbox');
    const btnVincular = document.getElementById('btnVincularPep');
    const btnExcluir = document.getElementById('btnExcluirSelecionados');

    function atualizarBotoes() {
        const algumSelecionado = Array.from(checkboxes).some(cb => cb.checked);
        btnVincular.disabled = !algumSelecionado;
        btnExcluir.disabled = !algumSelecionado;
    }

    checkboxes.forEach(cb => cb.addEventListener('change', atualizarBotoes));
    const selecionarTodos = document.getElementById('selecionarTodos');
    if (selecionarTodos) {
        selecionarTodos.addEventListener('change', atualizarBotoes);
    }
})();
</script>
<?php endif; ?>

<?php require __DIR__ . '/../includes/footer.php'; ?>
