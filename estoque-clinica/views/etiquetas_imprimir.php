<?php
$db = getDB();
$usuario = $_SESSION['user_logged_in'] ?? null;

// Salvar o modelo escolhido como padrão do usuário (item "definir como meu padrão").
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'meu_padrao') {
    csrfVerify();
    $db->prepare("UPDATE local_users SET etiqueta_modelo_id = :m WHERE username = :u")
       ->execute([':m' => (int)$_POST['modelo'] ?: null, ':u' => $usuario]);
    registrarLog('Etiquetas', 'Modelo padrão do usuário definido', 'modelo id: ' . (int)$_POST['modelo']);
    header('Location: ' . ($_POST['voltar'] ?? 'index.php?page=etiquetas'));
    exit;
}

// O que imprimir: a entrada inteira, um lote, unidades selecionadas — ou a etiqueta de teste.
$entradaId = (int)($_GET['entrada'] ?? 0);
$loteId = (int)($_GET['lote'] ?? 0);
$idsParam = trim($_GET['ids'] ?? '');
$ehTeste = !empty($_GET['teste']);
$copias = max(1, min(500, (int)($_GET['copias'] ?? 1)));

$modeloId = (int)($_GET['modelo'] ?? 0);
$modelo = $modeloId > 0 ? etiquetaModelo($db, $modeloId) : etiquetaModeloPadrao($db, $usuario);
$modelos = etiquetaModelosAtivos($db);
$elementos = $modelo ? etiquetaElementos($db, (int)$modelo['id']) : [];

$unidades = [];
if (!$ehTeste && $modelo) {
    $where = '';
    $params = [];
    if ($idsParam !== '') {
        $ids = array_values(array_filter(array_map('intval', explode(',', $idsParam))));
        if ($ids) {
            $where = 'u.id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')';
            $params = $ids;
        }
    } elseif ($entradaId > 0) {
        $where = 'u.confirmacao_id = ?';
        $params = [$entradaId];
    } elseif ($loteId > 0) {
        $where = 'u.lote_id = ?';
        $params = [$loteId];
    }
    if ($where !== '') {
        $stmt = $db->prepare(etiquetaUnidadesSelectSql() . " WHERE {$where} ORDER BY u.codigo_interno ASC");
        $stmt->execute($params);
        $unidades = $stmt->fetchAll();
    }
}

// Cada etiqueta a imprimir vira um conjunto de dados. Na impressão em lote de um mesmo item, o
// mesmo dado é repetido; com várias unidades, cada etiqueta sai com o seu próprio conteúdo.
$etiquetas = [];
if ($ehTeste) {
    $etiquetas[] = etiquetaDadosExemplo();
} else {
    foreach ($unidades as $u) {
        for ($c = 0; $c < $copias; $c++) {
            $etiquetas[] = etiquetaDadosUnidade($u);
        }
    }
}

if ($unidades && $modelo) {
    registrarLog('Unidades', 'Etiquetas impressas', count($etiquetas) . ' etiqueta(s) no modelo "' . $modelo['nome'] . '"'
        . ($entradaId ? ', entrada ' . codigoReferenciaEntrada($entradaId) : ''));
}

$avisos = ($modelo && $elementos) ? etiquetaValidarElementos($modelo, $elementos) : [];
if ($modelo && !$elementos) {
    $avisos[] = 'Este modelo não tem nenhum elemento configurado — a etiqueta sairia em branco.';
}

$escopo = $ehTeste ? 'Etiqueta de teste (calibração)' : 'Nenhuma seleção';
if (!$ehTeste) {
    if ($idsParam !== '') { $escopo = count($unidades) === 1 ? 'Etiqueta avulsa' : count($unidades) . ' unidades selecionadas'; }
    elseif ($entradaId > 0) { $escopo = 'Entrada ' . codigoReferenciaEntrada($entradaId); }
    elseif ($loteId > 0) { $escopo = 'Lote ' . ($unidades ? $unidades[0]['lote'] : $loteId); }
}

$parametrosSelecao = array_filter([
    'entrada' => $entradaId ?: null, 'lote' => $loteId ?: null,
    'ids' => $idsParam !== '' ? $idsParam : null, 'teste' => $ehTeste ? 1 : null,
]);
$urlAtual = 'index.php?page=etiquetas_imprimir&' . http_build_query($parametrosSelecao + ['modelo' => $modelo['id'] ?? 0, 'copias' => $copias]);
$porFolha = $modelo ? etiquetasPorFolha($modelo) : 1;
$folhas = $porFolha > 0 ? (int)ceil(count($etiquetas) / $porFolha) : 0;
?>
<style>
<?= $modelo ? etiquetaRegraPagina($modelo) : '' ?>

.etq-label { position: relative; background: #fff; color: #000; overflow: hidden; }
.etq-folha {
    position: relative;
    background: #fff;
    width: <?= $modelo && $modelo['tipo'] === 'folha' ? (float)$modelo['largura_folha_mm'] : ((float)($modelo['largura_mm'] ?? 50)) ?>mm;
    height: <?= $modelo && $modelo['tipo'] === 'folha' ? (float)$modelo['altura_folha_mm'] : ((float)($modelo['altura_mm'] ?? 25) + (float)($modelo['gap_mm'] ?? 0)) ?>mm;
    margin: 0 auto 6mm;
    box-shadow: 0 0 0 1px #c7d0dd;
    page-break-after: always;
    overflow: hidden;
}
.etq-folha:last-child { page-break-after: auto; }
.etq-posicao { position: absolute; }

@media print {
    .rail, .rail-backdrop, .topbar, .page-head, .nao-imprimir { display: none !important; }
    .app-shell { display: block !important; max-width: none !important; }
    .main-col { flex: none !important; }
    .main-content { padding: 0 !important; margin: 0 !important; max-width: none !important; }
    body { background: #fff !important; }
    .etq-folha { box-shadow: none; margin: 0; }
}
</style>

<div class="page-head">
    <div>
        <h1 class="page-title">Impressão de Etiquetas</h1>
        <div class="page-sub">
            <?= htmlspecialchars($escopo) ?> · <?= count($etiquetas) ?> etiqueta(s)
            <?php if ($modelo): ?>
                · modelo <?= htmlspecialchars($modelo['nome']) ?> (<?= (float)$modelo['largura_mm'] ?>×<?= (float)$modelo['altura_mm'] ?> mm)
                <?php if ($modelo['tipo'] === 'folha'): ?> · <?= $folhas ?> folha(s)<?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php if (!$modelo): ?>
    <div class="alert alert-warning">Nenhum modelo de etiqueta ativo. Cadastre um em <a href="index.php?page=etiqueta_modelos">Modelos de Etiqueta</a>.</div>
<?php else: ?>

<div class="card mb-3 nao-imprimir">
    <div class="card-body">
        <form method="GET" class="row g-2 align-items-end">
            <input type="hidden" name="page" value="etiquetas_imprimir">
            <?php foreach ($parametrosSelecao as $campo => $valor): ?>
                <input type="hidden" name="<?= $campo ?>" value="<?= htmlspecialchars((string)$valor) ?>">
            <?php endforeach; ?>

            <div class="col-12 col-md-5">
                <label class="form-label">Modelo de etiqueta</label>
                <select name="modelo" class="form-select" onchange="this.form.submit()">
                    <?php foreach ($modelos as $m): ?>
                        <option value="<?= (int)$m['id'] ?>" <?= (int)$m['id'] === (int)$modelo['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($m['nome']) ?> — <?= (float)$m['largura_mm'] ?>×<?= (float)$m['altura_mm'] ?> mm (<?= etiquetaTipoLabel($m['tipo']) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label">Cópias por unidade</label>
                <input type="number" name="copias" class="form-control" min="1" max="500" value="<?= $copias ?>">
            </div>
            <div class="col-6 col-md-2">
                <button class="btn btn-outline-secondary w-100">Aplicar</button>
            </div>
            <div class="col-12 col-md-3 text-md-end">
                <a href="index.php?page=etiqueta_modelos&edit=<?= (int)$modelo['id'] ?>" class="btn btn-outline-secondary btn-sm">
                    <i class="bi bi-sliders"></i> Configurar modelo
                </a>
            </div>
        </form>

        <form method="POST" class="mt-2">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="meu_padrao">
            <input type="hidden" name="modelo" value="<?= (int)$modelo['id'] ?>">
            <input type="hidden" name="voltar" value="<?= htmlspecialchars($urlAtual) ?>">
            <button class="btn btn-link btn-sm p-0"><i class="bi bi-star"></i> Usar este modelo como meu padrão</button>
        </form>
    </div>
</div>

<?php if ($avisos): ?>
    <div class="alert alert-warning nao-imprimir">
        <strong>Confira antes de imprimir:</strong>
        <ul class="mb-0 ps-3">
            <?php foreach ($avisos as $aviso): ?><li><?= htmlspecialchars($aviso) ?></li><?php endforeach; ?>
        </ul>
        <div class="mt-2 small">Nada é cortado em silêncio — ajuste o modelo ou escolha uma etiqueta maior.</div>
    </div>
<?php endif; ?>

<div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3 nao-imprimir">
    <a href="index.php?page=etiquetas" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left"></i> Voltar para Etiquetas</a>
    <div class="d-flex gap-2">
        <a href="index.php?page=etiquetas_imprimir&modelo=<?= (int)$modelo['id'] ?>&teste=1" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-rulers"></i> Etiqueta de teste
        </a>
        <button type="button" class="btn btn-primary btn-sm" onclick="window.print()"><i class="bi bi-printer"></i> Imprimir</button>
    </div>
</div>

<?php if (!$etiquetas): ?>
    <div class="card"><div class="card-body text-center text-muted py-4">
        Nenhuma unidade selecionada. Escolha as etiquetas na tela <a href="index.php?page=etiquetas">Etiquetas</a>.
    </div></div>
<?php else: ?>
    <?php
    // Em folha, as etiquetas são posicionadas na grade do modelo (com margens e espaçamentos); em
    // rolo, cada etiqueta é uma "folha" do tamanho dela mesma.
    $paginas = array_chunk($etiquetas, $porFolha);
    foreach ($paginas as $pagina): ?>
        <div class="etq-folha">
            <?php foreach ($pagina as $indice => $dados):
                $pos = $modelo['tipo'] === 'folha' ? etiquetaPosicaoNaFolha($modelo, $indice) : ['left' => 0, 'top' => 0]; ?>
                <div class="etq-posicao" style="left:<?= $pos['left'] ?>mm;top:<?= $pos['top'] ?>mm;">
                    <?= etiquetaRenderizar($modelo, $elementos, $dados, $ehTeste) ?>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endforeach; ?>
<?php endif; ?>

<?php
// QR Code é desenhado no navegador: a biblioteca é minúscula e evita depender de extensão do PHP
// (este servidor não tem GD/Imagick). Só carrega quando o modelo realmente usa QR.
$temQr = false;
foreach ($elementos as $el) { if ($el['tipo'] === 'qrcode') { $temQr = true; break; } }
?>
<?php if ($temQr): ?>
<script src="https://cdn.jsdelivr.net/npm/qrcode-generator@1.4.4/qrcode.js"></script>
<script>
document.querySelectorAll('.etq-qrcode').forEach(function (el) {
    var valor = el.getAttribute('data-valor') || '';
    if (!valor) return;
    var qr = qrcode(0, 'M'); // versão automática, correção de erro média
    qr.addData(valor);
    qr.make();
    el.innerHTML = qr.createSvgTag({ cellSize: 2, margin: 2, scalable: true });
    var svg = el.querySelector('svg');
    if (svg) { svg.setAttribute('width', '100%'); svg.setAttribute('height', '100%'); }
});
</script>
<?php endif; ?>

<?php endif; ?>
