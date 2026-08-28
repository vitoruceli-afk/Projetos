<?php
requireAdmin();
$db = getDB();
$formError = '';
$formSuccess = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'importar') {
    csrfVerify();
    $linhaCabecalho = max(1, (int)($_POST['linha_cabecalho'] ?? 1));

    if (!isset($_FILES['csv']) || $_FILES['csv']['error'] === UPLOAD_ERR_NO_FILE) {
        $formError = 'Selecione o arquivo CSV da ANVISA/CMED para importar.';
    } elseif ($_FILES['csv']['error'] !== UPLOAD_ERR_OK) {
        $errosUpload = [
            UPLOAD_ERR_INI_SIZE => 'O arquivo excede o tamanho máximo permitido pelo servidor (upload_max_filesize/post_max_size no php.ini).',
            UPLOAD_ERR_FORM_SIZE => 'O arquivo excede o tamanho máximo permitido pelo formulário.',
            UPLOAD_ERR_PARTIAL => 'O upload foi interrompido no meio da transferência.',
        ];
        $formError = $errosUpload[$_FILES['csv']['error']] ?? ('Falha ao enviar o arquivo (código ' . $_FILES['csv']['error'] . ').');
    } else {
        try {
            $resultado = importarMedicamentosCsv($db, $_FILES['csv']['tmp_name'], $linhaCabecalho, $_FILES['csv']['name'], $_SESSION['user_logged_in']);
            $formSuccess = "Importação concluída: {$resultado['total']} linha(s) lida(s), {$resultado['inseridos']} inserida(s), {$resultado['atualizados']} atualizada(s)"
                . ($resultado['ignorados'] > 0 ? ", {$resultado['ignorados']} ignorada(s) por não ter CÓDIGO GGREM" : '') . '.';
        } catch (RuntimeException $e) {
            $formError = $e->getMessage();
        }
    }
}

$totalMedicamentos = (int)$db->query("SELECT COUNT(*) FROM medicamentos_anvisa")->fetchColumn();
$ultimaImportacao = $db->query("SELECT * FROM medicamentos_anvisa_imports ORDER BY id DESC LIMIT 1")->fetch();

$busca = trim($_GET['busca'] ?? '');
$porPagina = 25;
$paginaAtual = max(1, (int)($_GET['pagina'] ?? 1));

$where = '';
$params = [];
if ($busca !== '') {
    $where = " WHERE substancia LIKE :b OR produto LIKE :b OR laboratorio LIKE :b OR registro LIKE :b
        OR ean_1 LIKE :b OR ean_2 LIKE :b OR ean_3 LIKE :b OR codigo_ggrem LIKE :b";
    $params[':b'] = "%{$busca}%";
}

$totalResultados = (int)(function () use ($db, $where, $params) {
    $stmt = $db->prepare("SELECT COUNT(*) FROM medicamentos_anvisa{$where}");
    $stmt->execute($params);
    return $stmt->fetchColumn();
})();
$totalPaginas = max(1, (int)ceil($totalResultados / $porPagina));
$paginaAtual = min($paginaAtual, $totalPaginas);
$offset = ($paginaAtual - 1) * $porPagina;

$stmt = $db->prepare("SELECT id, codigo_ggrem, substancia, produto, apresentacao, laboratorio, registro, ean_1, tarja, classe_terapeutica
    FROM medicamentos_anvisa{$where} ORDER BY substancia ASC, produto ASC LIMIT {$porPagina} OFFSET {$offset}");
$stmt->execute($params);
$medicamentos = $stmt->fetchAll();

function medQueryString(array $overrides = []) {
    $qs = array_merge(['page' => 'medicamentos'], $_GET, $overrides);
    return htmlspecialchars(http_build_query($qs));
}
?>
<div class="page-head">
    <div>
        <h1 class="page-title">Medicamentos (Base ANVISA/CMED)</h1>
        <div class="page-sub">Consulta de referência de medicamentos registrados na ANVISA, atualizada via importação de CSV</div>
    </div>
</div>

<?php if ($formError): ?><div class="alert alert-danger"><?= htmlspecialchars($formError) ?></div><?php endif; ?>
<?php if ($formSuccess): ?><div class="alert alert-success"><?= htmlspecialchars($formSuccess) ?></div><?php endif; ?>

<div class="row g-3 mb-1">
    <div class="col-lg-4">
        <div class="card h-100">
            <div class="card-header">Atualizar Base via CSV</div>
            <div class="card-body">
                <div class="form-text mb-3">
                    Envie o arquivo de preços/registros da ANVISA (CMED), separado por ponto e vírgula.
                    Informe em qual linha do arquivo está o cabeçalho das colunas — o sistema lê a partir dela
                    e ignora tudo o que vem antes (cabeçalhos institucionais, notas etc.).
                </div>
                <form method="POST" enctype="multipart/form-data">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="importar">
                    <div class="mb-2">
                        <label class="form-label">Arquivo CSV</label>
                        <input type="file" name="csv" class="form-control" accept=".csv,text/csv" required>
                    </div>
                    <div class="mb-2">
                        <label class="form-label">Linha do cabeçalho</label>
                        <input type="number" name="linha_cabecalho" class="form-control" min="1" value="1" required>
                        <div class="form-text">Número da linha (contando a partir de 1) onde estão os nomes das colunas, ex.: SUBSTÂNCIA, PRODUTO, CÓDIGO GGREM...</div>
                    </div>
                    <button class="btn btn-outline-success w-100 mt-2"><i class="bi bi-upload"></i> Importar / Atualizar Base</button>
                </form>

                <hr>
                <div class="entity-field-label">Total na base</div>
                <div class="entity-field-value mb-2"><?= number_format($totalMedicamentos, 0, ',', '.') ?> medicamento(s)</div>

                <?php if ($ultimaImportacao): ?>
                    <div class="entity-field-label">Última importação</div>
                    <div class="entity-field-value">
                        <?= date('d/m/Y H:i', strtotime($ultimaImportacao['created_at'])) ?> · <?= htmlspecialchars($ultimaImportacao['usuario']) ?>
                    </div>
                    <div class="entity-sub mt-1">
                        <?= htmlspecialchars($ultimaImportacao['arquivo']) ?><br>
                        <?= (int)$ultimaImportacao['total_linhas'] ?> linha(s) lida(s) ·
                        <?= (int)$ultimaImportacao['inseridos'] ?> inserida(s) ·
                        <?= (int)$ultimaImportacao['atualizados'] ?> atualizada(s)
                        <?php if ($ultimaImportacao['ignorados'] > 0): ?> · <?= (int)$ultimaImportacao['ignorados'] ?> ignorada(s)<?php endif; ?>
                    </div>
                <?php else: ?>
                    <div class="text-muted small">Nenhuma importação realizada ainda.</div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="col-lg-8">
        <div class="entity-list-toolbar">
            <form method="GET" class="d-flex gap-2 flex-grow-1">
                <input type="hidden" name="page" value="medicamentos">
                <input type="text" name="busca" class="form-control" placeholder="Buscar por substância, produto, laboratório, registro, EAN ou código GGREM..." value="<?= htmlspecialchars($busca) ?>">
                <button class="btn btn-outline-secondary"><i class="bi bi-search"></i></button>
            </form>
        </div>

        <div class="table-responsive">
            <table class="table table-striped table-hover bg-white align-middle table-actions-sticky">
                <thead class="table-dark">
                    <tr><th>Substância</th><th>Produto</th><th>Laboratório</th><th>Registro</th><th>EAN</th><th>Tarja</th><th></th></tr>
                </thead>
                <tbody>
                    <?php if (empty($medicamentos)): ?>
                        <tr><td colspan="7" class="text-center text-muted py-4">
                            <?= $totalMedicamentos === 0 ? 'Nenhum medicamento importado ainda. Utilize o formulário ao lado.' : 'Nenhum resultado para esta busca.' ?>
                        </td></tr>
                    <?php else: ?>
                        <?php foreach ($medicamentos as $m): ?>
                            <tr>
                                <td><?= htmlspecialchars($m['substancia']) ?></td>
                                <td>
                                    <?= htmlspecialchars($m['produto']) ?>
                                    <?php if ($m['apresentacao']): ?><div class="entity-sub"><?= htmlspecialchars($m['apresentacao']) ?></div><?php endif; ?>
                                </td>
                                <td><?= htmlspecialchars($m['laboratorio']) ?></td>
                                <td class="mono"><?= htmlspecialchars($m['registro']) ?></td>
                                <td class="mono"><?= htmlspecialchars($m['ean_1']) ?></td>
                                <td><?= $m['tarja'] ? htmlspecialchars($m['tarja']) : '<span class="text-muted">—</span>' ?></td>
                                <td class="text-nowrap">
                                    <button type="button" class="btn btn-sm btn-outline-primary btn-ver-medicamento" data-id="<?= (int)$m['id'] ?>">
                                        <i class="bi bi-eye"></i> Detalhes
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <?php if ($totalPaginas > 1): ?>
            <nav>
                <ul class="pagination">
                    <li class="page-item <?= $paginaAtual <= 1 ? 'disabled' : '' ?>">
                        <a class="page-link" href="?<?= medQueryString(['pagina' => $paginaAtual - 1]) ?>">Anterior</a>
                    </li>
                    <li class="page-item disabled"><span class="page-link"><?= $paginaAtual ?> / <?= $totalPaginas ?></span></li>
                    <li class="page-item <?= $paginaAtual >= $totalPaginas ? 'disabled' : '' ?>">
                        <a class="page-link" href="?<?= medQueryString(['pagina' => $paginaAtual + 1]) ?>">Próxima</a>
                    </li>
                </ul>
            </nav>
        <?php endif; ?>
    </div>
</div>

<div class="modal fade" id="medicamentoModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Detalhes do Medicamento</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
            </div>
            <div class="modal-body" id="medicamentoModalBody">
                <div class="text-muted small">Carregando...</div>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    var modalEl = document.getElementById('medicamentoModal');
    var modalBody = document.getElementById('medicamentoModalBody');
    var modal = new bootstrap.Modal(modalEl);

    function esc(s) {
        var d = document.createElement('div');
        d.textContent = (s === null || s === undefined) ? '' : String(s);
        return d.innerHTML;
    }

    document.querySelectorAll('.btn-ver-medicamento').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var id = btn.getAttribute('data-id');
            modalBody.innerHTML = '<div class="text-muted small">Carregando...</div>';
            modal.show();

            fetch('ajax_medicamento_detalhe.php?id=' + encodeURIComponent(id))
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (!data.found) {
                        modalBody.innerHTML = '<div class="alert alert-danger mb-0">' + esc(data.error) + '</div>';
                        return;
                    }
                    var rows = '';
                    data.campos.forEach(function (c) {
                        rows += '<tr><td class="entity-field-label" style="white-space:nowrap;">' + esc(c[0]) + '</td><td>' + (esc(c[1]) || '<span class="text-muted">—</span>') + '</td></tr>';
                    });
                    modalBody.innerHTML = '<div class="table-responsive"><table class="table table-sm table-striped mb-0">' +
                        '<tbody>' + rows + '</tbody></table></div>';
                })
                .catch(function () {
                    modalBody.innerHTML = '<div class="alert alert-danger mb-0">Erro ao carregar os detalhes.</div>';
                });
        });
    });
});
</script>
