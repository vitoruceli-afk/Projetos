<?php
// ---------------------------------------------------------------------------------------------
// Motor de etiquetas parametrizável.
//
// Tudo que define uma etiqueta (dimensões, margens, grade da folha, elementos e posições) vive no
// banco, em milímetros — nada de tamanho fixo em código. Adicionar um formato novo (42x18mm, por
// exemplo) é cadastro pela tela, não alteração de código.
//
// A impressão é feita pelo próprio navegador com CSS em mm + @page: funciona igual em laser, jato
// de tinta e térmica (Zebra, Elgin, Argox, TSC, Godex...) desde que a impressora tenha driver no
// sistema operacional, sem depender de API proprietária nem de linguagem específica de impressora.
// O DPI cadastrado não entra na renderização (o driver resolve mm -> dots); ele é usado para
// avaliar se a barra fica fina demais para o cabeçote conseguir imprimir de forma legível.
// ---------------------------------------------------------------------------------------------

const ETIQUETA_TIPOS = [
    'rolo' => 'Rolo (térmica)',
    'folha' => 'Folha (A4 / carta)',
];

const ETIQUETA_IMPRESSAO_TIPOS = [
    'termica_direta' => 'Térmica direta',
    'transferencia_termica' => 'Transferência térmica',
    'laser' => 'Laser',
    'jato_tinta' => 'Jato de tinta',
];

const ETIQUETA_ORIENTACOES = ['retrato' => 'Retrato', 'paisagem' => 'Paisagem'];
const ETIQUETA_ROTACOES = [0 => '0°', 90 => '90°', 180 => '180°', 270 => '270°'];
const ETIQUETA_ALINHAMENTOS = ['left' => 'Esquerda', 'center' => 'Centro', 'right' => 'Direita'];

const ETIQUETA_ELEMENTO_TIPOS = [
    'campo' => 'Campo do item',
    'texto' => 'Texto fixo',
    'codigo_barras' => 'Código de barras',
    'qrcode' => 'QR Code',
    'linha' => 'Linha',
    'retangulo' => 'Retângulo',
];

// Campos do cadastro que podem ser impressos. A chave é o que fica gravado no elemento; o valor
// é o rótulo mostrado na tela de configuração.
const ETIQUETA_CAMPOS = [
    'produto' => 'Nome do medicamento/insumo',
    'apresentacao' => 'Apresentação / categoria',
    'substancia' => 'Princípio ativo (substância)',
    'origem' => 'Laboratório / marca',
    'codigo_interno' => 'Código interno (alfanumérico)',
    'tipo_unidade' => 'Tipo da unidade (Ampola, Frasco...)',
    'lote' => 'Lote',
    'validade' => 'Validade (MM/AAAA)',
    'entrada' => 'Código da entrada (ENT-...)',
    'fornecedor' => 'Fornecedor',
    'data_entrada' => 'Data da entrada',
    'unidade_medida' => 'Unidade de medida',
    'status' => 'Status da unidade',
    'data_impressao' => 'Data da impressão',
    'hora_impressao' => 'Hora da impressão',
];

// Piso de legibilidade da barra fina para leitores comuns, independentemente da impressora.
const ETIQUETA_MODULO_MINIMO_ABSOLUTO_MM = 0.19;

// Largura mínima da barra fina para o DPI da impressora: o cabeçote precisa de pelo menos 2 pontos
// por barra, senão ela sai borrada/irregular e o leitor erra. Em 203 DPI um ponto tem 0,125 mm, em
// 300 DPI 0,085 mm e em 600 DPI 0,042 mm — por isso a mesma etiqueta pode ser legível numa
// impressora e não em outra. É esta conta que traduz mm para pontos do cabeçote.
function etiquetaModuloMinimoMm(int $dpi): float {
    $doisPontos = $dpi > 0 ? (2 * 25.4 / $dpi) : ETIQUETA_MODULO_MINIMO_ABSOLUTO_MM;
    return round(max($doisPontos, ETIQUETA_MODULO_MINIMO_ABSOLUTO_MM), 3);
}

// Quantos milímetros de largura o código precisa para ficar legível naquele DPI — usado tanto na
// validação quanto na mensagem que orienta o usuário.
function etiquetaLarguraMinimaCodigo(string $exemplo, int $dpi): float {
    return round(code128TotalModulos($exemplo) * etiquetaModuloMinimoMm($dpi), 1);
}

function etiquetaTipoLabel($tipo) { return ETIQUETA_TIPOS[$tipo] ?? $tipo; }
function etiquetaImpressaoLabel($tipo) { return ETIQUETA_IMPRESSAO_TIPOS[$tipo] ?? $tipo; }

// ---- Consulta das unidades a imprimir -------------------------------------------------------

// Mesma base de unidadeSelectSql(), acrescida do que só a etiqueta precisa (princípio ativo,
// fornecedor e data da entrada) — evita duplicar dado nas tabelas de unidade.
function etiquetaUnidadesSelectSql(): string {
    return "SELECT u.*,
            COALESCE(md.produto, ins.nome_comercial) AS produto,
            COALESCE(md.laboratorio, ins.marca) AS origem,
            COALESCE(md.apresentacao, ins.categoria) AS apresentacao,
            md.substancia,
            ins.unidade_medida,
            l.lote, l.validade,
            mc.created_at AS entrada_data,
            f.razao_social AS fornecedor
        FROM unidades_estoque u
        LEFT JOIN insumo_lotes l ON l.id = u.lote_id
        LEFT JOIN medicamentos_anvisa md ON md.id = u.medicamento_id
        LEFT JOIN insumos ins ON ins.id = u.insumo_id
        LEFT JOIN movimentacao_confirmacoes mc ON mc.id = u.confirmacao_id
        LEFT JOIN fornecedores f ON f.id = mc.fornecedor_id";
}

// Traduz a linha da unidade no conjunto de valores que os elementos podem imprimir.
function etiquetaDadosUnidade(array $u): array {
    return [
        'produto' => (string)($u['produto'] ?? ''),
        'apresentacao' => (string)($u['apresentacao'] ?? ''),
        'substancia' => (string)($u['substancia'] ?? ''),
        'origem' => (string)($u['origem'] ?? ''),
        'codigo_interno' => (string)($u['codigo_interno'] ?? ''),
        'tipo_unidade' => UNIDADE_PREFIXOS[$u['prefixo'] ?? ''] ?? (string)($u['prefixo'] ?? ''),
        'lote' => (string)($u['lote'] ?? ''),
        'validade' => (string)(formatarValidade($u['validade'] ?? null) ?? ''),
        'entrada' => !empty($u['confirmacao_id']) ? codigoReferenciaEntrada((int)$u['confirmacao_id']) : '',
        'fornecedor' => (string)($u['fornecedor'] ?? ''),
        'data_entrada' => !empty($u['entrada_data']) ? date('d/m/Y', strtotime($u['entrada_data'])) : '',
        'unidade_medida' => (string)($u['unidade_medida'] ?? ''),
        'status' => unidadeStatusLabel($u['status'] ?? ''),
        'data_impressao' => date('d/m/Y'),
        'hora_impressao' => date('H:i'),
    ];
}

// Dados fictícios da etiqueta de teste (calibração): mesma estrutura, valores de exemplo.
function etiquetaDadosExemplo(): array {
    return [
        'produto' => 'DIPIRONA SÓDICA 500 MG/ML',
        'apresentacao' => 'SOL INJ CT 100 AMP VD AMB X 2 ML',
        'substancia' => 'DIPIRONA SÓDICA',
        'origem' => 'LABORATÓRIO EXEMPLO',
        'codigo_interno' => 'AMP0000000001',
        'tipo_unidade' => 'Ampola',
        'lote' => 'ABC12345',
        'validade' => '10/2027',
        'entrada' => 'ENT-000012345',
        'fornecedor' => 'DISTRIBUIDORA EXEMPLO LTDA',
        'data_entrada' => date('d/m/Y'),
        'unidade_medida' => 'unidade',
        'status' => 'Disponível',
        'data_impressao' => date('d/m/Y'),
        'hora_impressao' => date('H:i'),
    ];
}

// ---- Modelos ---------------------------------------------------------------------------------

function etiquetaModelo(PDO $db, int $modeloId) {
    $stmt = $db->prepare("SELECT * FROM etiqueta_modelos WHERE id = :id");
    $stmt->execute([':id' => $modeloId]);
    return $stmt->fetch() ?: null;
}

function etiquetaModelosAtivos(PDO $db): array {
    return $db->query("SELECT * FROM etiqueta_modelos WHERE ativo = 1 ORDER BY tipo ASC, largura_mm ASC, altura_mm ASC")->fetchAll();
}

function etiquetaElementos(PDO $db, int $modeloId): array {
    $stmt = $db->prepare("SELECT * FROM etiqueta_elementos WHERE modelo_id = :id AND ativo = 1 ORDER BY ordem ASC, id ASC");
    $stmt->execute([':id' => $modeloId]);
    return $stmt->fetchAll();
}

// Modelo que a tela de impressão usa quando o usuário não escolheu nenhum: o padrão dele, senão o
// marcado como padrão do sistema, senão o primeiro ativo.
function etiquetaModeloPadrao(PDO $db, ?string $usuario = null) {
    if ($usuario) {
        $stmt = $db->prepare("SELECT m.* FROM local_users u JOIN etiqueta_modelos m ON m.id = u.etiqueta_modelo_id
            WHERE u.username = :u AND m.ativo = 1");
        $stmt->execute([':u' => $usuario]);
        $modelo = $stmt->fetch();
        if ($modelo) return $modelo;
    }
    $modelo = $db->query("SELECT * FROM etiqueta_modelos WHERE ativo = 1 AND padrao = 1 LIMIT 1")->fetch();
    return $modelo ?: ($db->query("SELECT * FROM etiqueta_modelos WHERE ativo = 1 ORDER BY id ASC LIMIT 1")->fetch() ?: null);
}

// ---- Validações ------------------------------------------------------------------------------

// Erros que impedem salvar o modelo (dimensão inválida, etiqueta maior que a folha, grade que não
// cabe). Mensagem sempre dizendo o número que estourou, pra o usuário saber o que corrigir.
function etiquetaValidarModelo(array $m): array {
    $erros = [];
    $largura = (float)$m['largura_mm'];
    $altura = (float)$m['altura_mm'];

    if ($largura <= 0 || $altura <= 0) {
        $erros[] = 'Largura e altura da etiqueta devem ser maiores que zero.';
    }
    foreach (['margem_superior_mm', 'margem_inferior_mm', 'margem_esquerda_mm', 'margem_direita_mm',
              'espacamento_horizontal_mm', 'espacamento_vertical_mm', 'gap_mm'] as $campo) {
        if ((float)($m[$campo] ?? 0) < 0) {
            $erros[] = 'Margens, espaçamentos e gap não podem ser negativos.';
            break;
        }
    }

    if (($m['tipo'] ?? '') === 'folha') {
        $lf = (float)$m['largura_folha_mm'];
        $af = (float)$m['altura_folha_mm'];
        $colunas = max(1, (int)$m['colunas']);
        $linhas = max(1, (int)$m['linhas']);

        if ($lf <= 0 || $af <= 0) {
            $erros[] = 'Informe largura e altura da folha.';
        } else {
            if ($largura > $lf || $altura > $af) {
                $erros[] = 'A etiqueta (' . $largura . '×' . $altura . ' mm) é maior que a folha (' . $lf . '×' . $af . ' mm).';
            }
            $usadoH = (float)$m['margem_esquerda_mm'] + (float)$m['margem_direita_mm']
                    + ($colunas * $largura) + (($colunas - 1) * (float)$m['espacamento_horizontal_mm']);
            if ($usadoH > $lf + 0.01) {
                $erros[] = $colunas . ' coluna(s) ocupam ' . round($usadoH, 1) . ' mm e não cabem na largura da folha (' . $lf . ' mm).';
            }
            $usadoV = (float)$m['margem_superior_mm'] + (float)$m['margem_inferior_mm']
                    + ($linhas * $altura) + (($linhas - 1) * (float)$m['espacamento_vertical_mm']);
            if ($usadoV > $af + 0.01) {
                $erros[] = $linhas . ' linha(s) ocupam ' . round($usadoV, 1) . ' mm e não cabem na altura da folha (' . $af . ' mm).';
            }
        }
    }

    if ((int)($m['dpi'] ?? 0) <= 0) {
        $erros[] = 'Informe o DPI da impressora (203, 300 ou 600, por exemplo).';
    }
    return $erros;
}

// Problemas de conteúdo: elemento fora da etiqueta, código de barras/QR estourando a área ou barra
// fina demais pro DPI. Não corta nada em silêncio — devolve os avisos pra tela mostrar.
function etiquetaValidarElementos(array $modelo, array $elementos): array {
    $avisos = [];
    $largura = (float)$modelo['largura_mm'];
    $altura = (float)$modelo['altura_mm'];

    foreach ($elementos as $el) {
        $nome = etiquetaElementoDescricao($el);
        $x = (float)$el['x_mm'];
        $y = (float)$el['y_mm'];
        $w = (float)$el['largura_mm'];
        $h = (float)$el['altura_mm'];

        if ($x < 0 || $y < 0) {
            $avisos[] = "{$nome}: posição negativa (fora da etiqueta).";
        }
        if ($x + $w > $largura + 0.01) {
            $avisos[] = "{$nome}: ultrapassa a largura da etiqueta em " . round(($x + $w) - $largura, 1) . ' mm.';
        }
        if ($y + $h > $altura + 0.01) {
            $avisos[] = "{$nome}: ultrapassa a altura da etiqueta em " . round(($y + $h) - $altura, 1) . ' mm.';
        }

        if ($el['tipo'] === 'codigo_barras') {
            // Largura da barra fina = largura disponível / total de módulos do Code 128, comparada
            // com o mínimo que aquele DPI consegue imprimir. Avisa em vez de imprimir um código que
            // o leitor vai recusar — e diz o que fazer pra resolver.
            $dpi = (int)($modelo['dpi'] ?? 203);
            $modulos = code128TotalModulos('AMP0000000001');
            $moduloMm = $modulos > 0 ? $w / $modulos : 0;
            $minimo = etiquetaModuloMinimoMm($dpi);

            if ($moduloMm < $minimo) {
                $larguraNecessaria = etiquetaLarguraMinimaCodigo('AMP0000000001', $dpi);
                $avisos[] = "{$nome}: com " . round($w, 1) . ' mm a barra fina fica em ' . round($moduloMm, 3)
                    . ' mm, abaixo do mínimo de ' . $minimo . " mm para {$dpi} DPI. Um código de 13 caracteres precisa de "
                    . $larguraNecessaria . ' mm nesse DPI — aumente a largura do elemento, use uma etiqueta maior, '
                    . 'imprima numa impressora de DPI maior ou troque o código de barras por um QR Code.';
            }
            if ($h < 8) {
                $avisos[] = "{$nome}: altura de " . round($h, 1) . ' mm é baixa para leitura confiável (recomendado 8 mm ou mais).';
            }
        }
    }
    return $avisos;
}

function etiquetaElementoDescricao(array $el): string {
    if ($el['tipo'] === 'campo') {
        return 'Campo "' . (ETIQUETA_CAMPOS[$el['campo']] ?? $el['campo']) . '"';
    }
    if ($el['tipo'] === 'texto') {
        return 'Texto "' . mb_substr((string)$el['texto_fixo'], 0, 20) . '"';
    }
    return ETIQUETA_ELEMENTO_TIPOS[$el['tipo']] ?? $el['tipo'];
}

// ---- Renderização ----------------------------------------------------------------------------

// Total de módulos do Code 128 (incluindo as zonas de silêncio) — usado pra calcular a largura da
// barra fina e avisar quando ficar abaixo do legível.
function code128TotalModulos(string $texto): int {
    $padroes = code128Padroes();
    $valores = code128Valores($texto);
    $soma = $valores[0];
    for ($i = 1; $i < count($valores); $i++) { $soma += $valores[$i] * $i; }
    $valores[] = $soma % 103;
    $valores[] = 106;

    $total = 20; // 10 módulos de zona de silêncio de cada lado
    foreach ($valores as $v) {
        foreach (str_split($padroes[$v]) as $largura) { $total += (int)$largura; }
    }
    return $total;
}

// Code 128 que preenche exatamente a área do elemento: o viewBox mantém a proporção entre as
// barras (todas escalam junto, que é como o padrão permite), e a zona de silêncio vai embutida.
function etiquetaCodigoBarrasSvg(string $texto, float $larguraMm, float $alturaMm): string {
    $svg = code128Svg($texto, 1, 100);
    return preg_replace(
        '/^<svg([^>]*)width="[^"]*" height="[^"]*"/',
        '<svg$1width="' . $larguraMm . 'mm" height="' . $alturaMm . 'mm" preserveAspectRatio="none"',
        $svg
    );
}

// Um elemento posicionado em mm dentro da etiqueta. Texto que não couber é cortado com reticências
// (o aviso de "não cabe" é dado antes, na validação — aqui é só o retrato fiel do que sai).
function etiquetaRenderizarElemento(array $el, array $dados): string {
    $estilo = 'position:absolute;'
        . 'left:' . (float)$el['x_mm'] . 'mm;'
        . 'top:' . (float)$el['y_mm'] . 'mm;'
        . 'width:' . (float)$el['largura_mm'] . 'mm;'
        . 'height:' . (float)$el['altura_mm'] . 'mm;';

    if ((int)$el['rotacao'] !== 0) {
        $estilo .= 'transform:rotate(' . (int)$el['rotacao'] . 'deg);transform-origin:left top;';
    }

    switch ($el['tipo']) {
        case 'linha':
            return '<div style="' . $estilo . 'background:#000;"></div>';

        case 'retangulo':
            return '<div style="' . $estilo . 'border:0.3mm solid #000;"></div>';

        case 'codigo_barras':
            $valor = $dados[$el['campo'] ?: 'codigo_interno'] ?? '';
            if ($valor === '') return '';
            $svg = etiquetaCodigoBarrasSvg($valor, (float)$el['largura_mm'], (float)$el['altura_mm']);
            $texto = (int)$el['mostrar_valor'] === 1
                ? '<div style="text-align:center;font-family:monospace;font-weight:bold;font-size:'
                  . (float)$el['tamanho_fonte'] . 'pt;line-height:1;">' . htmlspecialchars($valor) . '</div>'
                : '';
            return '<div style="' . $estilo . 'display:flex;flex-direction:column;justify-content:center;">'
                . $svg . $texto . '</div>';

        case 'qrcode':
            // Desenhado no navegador (biblioteca carregada na tela de impressão): o conteúdo vai
            // no data-valor e o script troca pelo QR antes de imprimir.
            $valor = $dados[$el['campo'] ?: 'codigo_interno'] ?? '';
            return '<div class="etq-qrcode" data-valor="' . htmlspecialchars($valor) . '" style="' . $estilo . '"></div>';

        case 'texto':
        case 'campo':
        default:
            $valor = $el['tipo'] === 'texto'
                ? (string)$el['texto_fixo']
                : (string)($dados[$el['campo']] ?? '');
            if (trim($valor) === '') return '';
            $estilo .= 'font-family:' . ($el['fonte'] ?: 'Arial') . ',Helvetica,sans-serif;'
                . 'font-size:' . (float)$el['tamanho_fonte'] . 'pt;'
                . 'line-height:1.1;'
                . 'text-align:' . ($el['alinhamento'] ?: 'left') . ';'
                . 'overflow:hidden;'
                . ((int)$el['negrito'] === 1 ? 'font-weight:bold;' : '');
            return '<div style="' . $estilo . '">' . nl2br(htmlspecialchars($valor)) . '</div>';
    }
}

// A etiqueta inteira: caixa do tamanho exato do modelo, com os elementos posicionados dentro.
// O ajuste de calibração desloca todo o conteúdo, sem mexer no tamanho da etiqueta.
function etiquetaRenderizar(array $modelo, array $elementos, array $dados, bool $mostrarContorno = false): string {
    $ajusteX = (float)($modelo['ajuste_x_mm'] ?? 0);
    $ajusteY = (float)($modelo['ajuste_y_mm'] ?? 0);
    $escala = (float)($modelo['escala'] ?? 1) ?: 1;

    $html = '<div class="etq-label" style="width:' . (float)$modelo['largura_mm'] . 'mm;height:' . (float)$modelo['altura_mm'] . 'mm;'
        . ($mostrarContorno ? 'outline:0.2mm dashed #b9c3d3;' : '') . '">'
        . '<div class="etq-conteudo" style="position:absolute;inset:0;'
        . 'transform:translate(' . $ajusteX . 'mm,' . $ajusteY . 'mm) scale(' . $escala . ');transform-origin:left top;">';

    foreach ($elementos as $el) {
        $html .= etiquetaRenderizarElemento($el, $dados);
    }
    return $html . '</div></div>';
}

// Posição de cada etiqueta na folha, seguindo margens, grade e espaçamentos do modelo.
function etiquetaPosicaoNaFolha(array $modelo, int $indice): array {
    $colunas = max(1, (int)$modelo['colunas']);
    $coluna = $indice % $colunas;
    $linha = intdiv($indice, $colunas);

    return [
        'left' => (float)$modelo['margem_esquerda_mm'] + $coluna * ((float)$modelo['largura_mm'] + (float)$modelo['espacamento_horizontal_mm']),
        'top' => (float)$modelo['margem_superior_mm'] + $linha * ((float)$modelo['altura_mm'] + (float)$modelo['espacamento_vertical_mm']),
    ];
}

function etiquetasPorFolha(array $modelo): int {
    return ($modelo['tipo'] ?? '') === 'folha'
        ? max(1, (int)$modelo['linhas']) * max(1, (int)$modelo['colunas'])
        : 1;
}

// Regra @page do modelo: em folha, o tamanho do papel; em rolo, cada etiqueta é uma página do
// tamanho exato dela (mais o gap na altura, que é o avanço entre etiquetas).
function etiquetaRegraPagina(array $modelo): string {
    if (($modelo['tipo'] ?? '') === 'folha') {
        $tamanho = (float)$modelo['largura_folha_mm'] . 'mm ' . (float)$modelo['altura_folha_mm'] . 'mm';
    } else {
        $tamanho = (float)$modelo['largura_mm'] . 'mm ' . ((float)$modelo['altura_mm'] + (float)$modelo['gap_mm']) . 'mm';
    }
    $orientacao = ($modelo['orientacao'] ?? 'retrato') === 'paisagem' ? ' landscape' : '';
    return '@page { size: ' . $tamanho . $orientacao . '; margin: 0; }';
}

// ---- Catálogo inicial ------------------------------------------------------------------------

// Formatos de rolo mais usados (mm) e folhas A4 com as grades mais comuns. São só o ponto de
// partida: tudo editável na tela, e formatos novos entram por cadastro.
function etiquetaCatalogoInicial(): array {
    $rolos = [
        ['Ampola / frasco pequeno 30x20', 30, 20], ['Ampola 40x20', 40, 20],
        ['Medicamento 50x25', 50, 25], ['Medicamento / insumo 50x30', 50, 30],
        ['Medicamento 60x40', 60, 40], ['Produto 70x30', 70, 30],
        ['Estoque 80x30', 80, 30], ['Caixa 80x50', 80, 50],
        ['Caixa 100x50', 100, 50], ['Volume 100x100', 100, 100],
        ['Expedição 100x150', 100, 150],
    ];

    // A4 = 210x297mm. Cada grade define colunas/linhas e margens; a etiqueta é o que sobra.
    $a4 = [
        ['A4 - 80 etiquetas (35,6x16,9)', 4, 20, 35.6, 16.9, 8.0, 13.0, 2.5, 0],
        ['A4 - 65 etiquetas (38,1x21,2)', 5, 13, 38.1, 21.2, 5.0, 11.0, 2.5, 0],
        ['A4 - 30 etiquetas (64,6x33,8)', 3, 10, 64.6, 33.8, 6.0, 8.0, 3.0, 0],
        ['A4 - 20 etiquetas (105x29,7)', 2, 10, 105.0, 29.7, 0, 0, 0, 0],
        ['A4 - 14 etiquetas (105x42,4)', 2, 7, 105.0, 42.4, 0.5, 0, 0, 0],
        ['A4 - 10 etiquetas (105x59,4)', 2, 5, 105.0, 59.4, 0, 0, 0, 0],
        ['A4 - 9 etiquetas (70x99)', 3, 3, 70.0, 99.0, 0, 0, 0, 0],
        ['A4 - 6 etiquetas (105x99)', 2, 3, 105.0, 99.0, 0, 0, 0, 0],
        ['A4 - 4 etiquetas (105x148,5)', 2, 2, 105.0, 148.5, 0, 0, 0, 0],
        ['A4 - 2 etiquetas (210x148,5)', 1, 2, 210.0, 148.5, 0, 0, 0, 0],
        ['A4 - 1 etiqueta (210x297)', 1, 1, 210.0, 297.0, 0, 0, 0, 0],
    ];

    $modelos = [];
    foreach ($rolos as [$nome, $w, $h]) {
        // Etiqueta pequena de ampola só comporta um código de 13 caracteres legível a 300 DPI —
        // a 203 DPI a barra fina sairia abaixo do mínimo (a validação avisa se alguém mudar).
        $dpiSugerido = $w < 45 ? 300 : 203;
        $modelos[] = [
            'nome' => $nome, 'fabricante' => 'Genérico', 'codigo_modelo' => 'ROLO-' . $w . 'X' . $h,
            'tipo' => 'rolo', 'largura_mm' => $w, 'altura_mm' => $h, 'dpi' => $dpiSugerido,
            'largura_folha_mm' => 0, 'altura_folha_mm' => 0, 'linhas' => 1, 'colunas' => 1,
            'margem_superior_mm' => 0, 'margem_inferior_mm' => 0, 'margem_esquerda_mm' => 0, 'margem_direita_mm' => 0,
            'espacamento_horizontal_mm' => 0, 'espacamento_vertical_mm' => 0, 'gap_mm' => 2,
            'impressao_tipo' => 'termica_direta',
        ];
    }
    foreach ($a4 as [$nome, $colunas, $linhas, $w, $h, $margemTopo, $margemEsq, $espH, $espV]) {
        $modelos[] = [
            'nome' => $nome, 'fabricante' => 'Genérico', 'codigo_modelo' => 'A4-' . ($colunas * $linhas),
            'tipo' => 'folha', 'largura_mm' => $w, 'altura_mm' => $h,
            'largura_folha_mm' => 210, 'altura_folha_mm' => 297, 'linhas' => $linhas, 'colunas' => $colunas,
            'margem_superior_mm' => $margemTopo, 'margem_inferior_mm' => $margemTopo,
            'margem_esquerda_mm' => $margemEsq, 'margem_direita_mm' => $margemEsq,
            'espacamento_horizontal_mm' => $espH, 'espacamento_vertical_mm' => $espV,
            'gap_mm' => 0, 'dpi' => 600, 'impressao_tipo' => 'laser',
        ];
    }
    return $modelos;
}

// Layout padrão de um modelo novo, calculado em cima do tamanho da etiqueta: nome, apresentação,
// lote/validade, código de barras e o código legível. Serve de ponto de partida — o usuário
// reposiciona tudo depois. Em etiqueta muito pequena, os campos que não caberiam saem de fora em
// vez de espremer a fonte a ponto de não dar pra ler.
function etiquetaElementosPadrao(float $largura, float $altura): array {
    $margem = min(1.5, $largura * 0.04);
    $util = $largura - (2 * $margem);
    $fonteBase = max(5, min(9, $altura * 0.22));

    // A barra precisa de espaço fixo e é reservada primeiro — o texto usa o que sobra. Abaixo de
    // 8 mm de altura a leitura fica instável, então esse é o piso; em etiqueta baixa isso significa
    // menos linhas de texto, e não uma barra curta demais para o leitor.
    $alturaCodigo = max(3, $fonteBase * 0.42);
    $alturaBarras = max(8, min(18, $altura * 0.42));
    $topoBarras = $altura - $margem - $alturaCodigo - $alturaBarras;

    $elementos = [];
    $y = $margem;
    $ordem = 1;

    $linha = function (string $campo, float $tamanho, bool $negrito) use (&$elementos, &$y, &$ordem, $margem, $util, $topoBarras) {
        $altura = $tamanho * 0.38;
        if ($y + $altura > $topoBarras) { return; } // não cabe: melhor omitir do que ilegível
        $elementos[] = [
            'tipo' => 'campo', 'campo' => $campo, 'texto_fixo' => '',
            'x_mm' => $margem, 'y_mm' => round($y, 2), 'largura_mm' => round($util, 2), 'altura_mm' => round($altura, 2),
            'fonte' => 'Arial', 'tamanho_fonte' => round($tamanho, 1), 'negrito' => $negrito ? 1 : 0,
            'alinhamento' => 'left', 'rotacao' => 0, 'mostrar_valor' => 0, 'ordem' => $ordem++,
        ];
        $y += $altura + 0.4;
    };

    $linha('produto', $fonteBase, true);
    $linha('apresentacao', max(4.5, $fonteBase - 1.5), false);
    $linha('lote', max(4.5, $fonteBase - 1), false);
    $linha('validade', max(4.5, $fonteBase - 1), false);

    // Em etiqueta estreita a barra usa a largura toda: a zona de silêncio já está embutida nos
    // módulos do código, e cada décimo de milímetro a mais aqui é o que mantém a barra legível.
    $margemBarras = $largura < 45 ? 0 : $margem;
    $elementos[] = [
        'tipo' => 'codigo_barras', 'campo' => 'codigo_interno', 'texto_fixo' => '',
        'x_mm' => $margemBarras, 'y_mm' => round(max($margem, $topoBarras), 2),
        'largura_mm' => round($largura - (2 * $margemBarras), 2), 'altura_mm' => round($alturaBarras, 2),
        'fonte' => 'Arial', 'tamanho_fonte' => round(max(5, $fonteBase - 1), 1), 'negrito' => 0,
        'alinhamento' => 'center', 'rotacao' => 0, 'mostrar_valor' => 1, 'ordem' => $ordem++,
    ];
    return $elementos;
}

// Grava o modelo e os elementos padrão numa transação (usado pelo catálogo inicial e pelo
// assistente de criação).
function etiquetaCriarModelo(PDO $db, array $dados, ?array $elementos = null): int {
    $campos = ['nome', 'fabricante', 'codigo_modelo', 'descricao', 'tipo', 'material', 'largura_mm', 'altura_mm',
        'largura_folha_mm', 'altura_folha_mm', 'linhas', 'colunas', 'margem_superior_mm', 'margem_inferior_mm',
        'margem_esquerda_mm', 'margem_direita_mm', 'espacamento_horizontal_mm', 'espacamento_vertical_mm',
        'gap_mm', 'dpi', 'orientacao', 'rotacao', 'impressao_tipo', 'ajuste_x_mm', 'ajuste_y_mm', 'escala', 'ativo', 'padrao'];

    $valores = [];
    foreach ($campos as $campo) {
        $valores[':' . $campo] = $dados[$campo] ?? etiquetaValorPadraoCampo($campo);
    }

    $sql = "INSERT INTO etiqueta_modelos (" . implode(', ', $campos) . ")
        VALUES (" . implode(', ', array_map(fn($c) => ':' . $c, $campos)) . ")";
    $db->prepare($sql)->execute($valores);
    $modeloId = (int)$db->lastInsertId();

    $elementos = $elementos ?? etiquetaElementosPadrao((float)$dados['largura_mm'], (float)$dados['altura_mm']);
    etiquetaSalvarElementos($db, $modeloId, $elementos);
    return $modeloId;
}

function etiquetaValorPadraoCampo(string $campo) {
    return match ($campo) {
        'nome', 'fabricante', 'codigo_modelo', 'descricao', 'material' => '',
        'tipo' => 'rolo',
        'orientacao' => 'retrato',
        'impressao_tipo' => 'termica_direta',
        'linhas', 'colunas', 'ativo' => 1,
        'escala' => 1,
        'dpi' => 203,
        default => 0,
    };
}

// Substitui os elementos do modelo (a tela envia a lista inteira a cada salvamento).
function etiquetaSalvarElementos(PDO $db, int $modeloId, array $elementos): void {
    $db->prepare("DELETE FROM etiqueta_elementos WHERE modelo_id = :m")->execute([':m' => $modeloId]);

    $stmt = $db->prepare("INSERT INTO etiqueta_elementos
        (modelo_id, tipo, campo, texto_fixo, x_mm, y_mm, largura_mm, altura_mm, fonte, tamanho_fonte,
         negrito, alinhamento, rotacao, mostrar_valor, ordem, ativo)
        VALUES (:m, :tipo, :campo, :texto, :x, :y, :w, :h, :fonte, :tam, :neg, :alin, :rot, :mv, :ordem, 1)");

    foreach (array_values($elementos) as $i => $el) {
        $stmt->execute([
            ':m' => $modeloId,
            ':tipo' => array_key_exists($el['tipo'] ?? '', ETIQUETA_ELEMENTO_TIPOS) ? $el['tipo'] : 'campo',
            ':campo' => $el['campo'] ?? '',
            ':texto' => $el['texto_fixo'] ?? '',
            ':x' => (float)($el['x_mm'] ?? 0), ':y' => (float)($el['y_mm'] ?? 0),
            ':w' => (float)($el['largura_mm'] ?? 0), ':h' => (float)($el['altura_mm'] ?? 0),
            ':fonte' => $el['fonte'] ?? 'Arial', ':tam' => (float)($el['tamanho_fonte'] ?? 6),
            ':neg' => !empty($el['negrito']) ? 1 : 0,
            ':alin' => array_key_exists($el['alinhamento'] ?? '', ETIQUETA_ALINHAMENTOS) ? $el['alinhamento'] : 'left',
            ':rot' => (int)($el['rotacao'] ?? 0),
            ':mv' => !empty($el['mostrar_valor']) ? 1 : 0,
            ':ordem' => (int)($el['ordem'] ?? ($i + 1)),
        ]);
    }
}
