<?php
require_once 'config.php';
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_logged_in'])) {
    http_response_code(401);
    echo json_encode(['sucesso' => false, 'error' => 'Não autenticado.']);
    exit;
}

csrfVerify();

if (!isset($_FILES['xml']) || $_FILES['xml']['error'] === UPLOAD_ERR_NO_FILE) {
    echo json_encode(['sucesso' => false, 'error' => 'Selecione o arquivo XML da nota fiscal.']);
    exit;
}
if ($_FILES['xml']['error'] !== UPLOAD_ERR_OK) {
    $errosUpload = [
        UPLOAD_ERR_INI_SIZE => 'O arquivo excede o tamanho máximo permitido pelo servidor.',
        UPLOAD_ERR_FORM_SIZE => 'O arquivo excede o tamanho máximo permitido pelo formulário.',
        UPLOAD_ERR_PARTIAL => 'O upload foi interrompido no meio da transferência.',
    ];
    echo json_encode(['sucesso' => false, 'error' => $errosUpload[$_FILES['xml']['error']] ?? ('Falha ao enviar o arquivo (código ' . $_FILES['xml']['error'] . ').')]);
    exit;
}
if ($_FILES['xml']['size'] > 8 * 1024 * 1024) {
    echo json_encode(['sucesso' => false, 'error' => 'Arquivo muito grande (máximo 8 MB).']);
    exit;
}

$conteudo = file_get_contents($_FILES['xml']['tmp_name']);
if ($conteudo === false || trim($conteudo) === '') {
    echo json_encode(['sucesso' => false, 'error' => 'Não foi possível ler o arquivo enviado.']);
    exit;
}

try {
    $nfe = parseNfeXml($conteudo);
} catch (RuntimeException $e) {
    echo json_encode(['sucesso' => false, 'error' => $e->getMessage()]);
    exit;
}

$db = getDB();

$itens = [];
foreach ($nfe['itens'] as $idx => $item) {
    $achado = null;
    if ($item['codigo_barras'] !== '') {
        $achado = buscarItemMovimentacao($db, $item['codigo_barras']);
    }
    if (!$achado && $item['codigo_barras_trib'] !== '' && $item['codigo_barras_trib'] !== $item['codigo_barras']) {
        $achado = buscarItemMovimentacao($db, $item['codigo_barras_trib']);
    }

    $base = [
        'linha' => $idx + 1,
        'codigo_barras' => $item['codigo_barras'] !== '' ? $item['codigo_barras'] : $item['codigo_barras_trib'],
        'nome_nfe' => $item['nome_nfe'],
        'unidade_nfe' => $item['unidade_nfe'],
        'valor_unitario' => $item['valor_unitario'],
        'encontrado' => $achado !== null,
        'tipo' => $achado['tipo'] ?? null,
        'item_id' => $achado ? (int)$achado['dados']['id'] : null,
        'produto' => $achado
            ? ($achado['tipo'] === 'medicamento' ? $achado['dados']['produto'] : $achado['dados']['nome_comercial'])
            : $item['nome_nfe'],
        'laboratorio' => $achado ? ($achado['tipo'] === 'medicamento' ? $achado['dados']['laboratorio'] : $achado['dados']['marca']) : '',
        'apresentacao' => $achado ? ($achado['tipo'] === 'medicamento' ? $achado['dados']['apresentacao'] : $achado['dados']['categoria']) : '',
    ];

    // Uma linha da NFe pode ter vários lotes (grupo <rastro> repetido) — cada lote vira uma linha
    // própria no resumo, já que nosso estoque é sempre por lote. Sem rastro, uma única linha com
    // lote/validade em branco (o operador completa na conferência).
    if ($item['lotes']) {
        foreach ($item['lotes'] as $lote) {
            // A nota traz o dia exato de vencimento (dVal), mas os campos de validade do sistema
            // só trabalham com mês/ano — trunca pra "AAAA-MM", o mesmo formato que o input
            // type="month" da tela de conferência espera.
            $itens[] = array_merge($base, [
                'quantidade' => $lote['quantidade'],
                'lote' => $lote['lote'],
                'validade' => $lote['validade'] ? substr($lote['validade'], 0, 7) : null,
                'validade_br' => formatarValidade($lote['validade']),
            ]);
        }
    } else {
        $itens[] = array_merge($base, [
            'quantidade' => $item['quantidade'],
            'lote' => '',
            'validade' => null,
            'validade_br' => null,
        ]);
    }
}

// Vincula (ou cadastra na hora, se o CNPJ do emitente ainda não existir) o fornecedor desta nota —
// é o "caso o fornecedor que está na nota não exista, realize o cadastro" pedido para a Entrada.
$fornecedor = buscarOuCriarFornecedor($db, [
    'razao_social' => $nfe['emitente'],
    'nome_fantasia' => $nfe['emitente_fantasia'],
    'cnpj' => $nfe['emitente_cnpj'],
    'endereco' => $nfe['emitente_endereco'],
    'bairro' => $nfe['emitente_bairro'],
    'cep' => $nfe['emitente_cep'],
    'municipio' => $nfe['emitente_municipio'],
    'uf' => $nfe['emitente_uf'],
    'pais' => $nfe['emitente_pais'],
    'telefone' => $nfe['emitente_telefone'],
    'inscricao_estadual' => $nfe['emitente_ie'],
]);

registrarLog('Movimentação', 'Nota fiscal importada (XML)', "número: {$nfe['numero']}, emitente: {$nfe['emitente']}, chave: {$nfe['chave']}, " . count($itens) . ' item(ns) lido(s)');

echo json_encode([
    'sucesso' => true,
    'nfe' => [
        'chave' => $nfe['chave'],
        'numero' => $nfe['numero'],
        'emitente' => $nfe['emitente'],
        'emitente_cnpj' => $nfe['emitente_cnpj'],
    ],
    'fornecedor' => $fornecedor ? [
        'id' => (int)$fornecedor['id'],
        'razao_social' => $fornecedor['razao_social'],
        'nome_fantasia' => $fornecedor['nome_fantasia'],
        'cnpj' => formatarCNPJ($fornecedor['cnpj']),
    ] : null,
    'itens' => $itens,
]);

// Extrai chave de acesso, emitente e itens (com lotes, se houver) de um XML de NFe — aceita tanto
// o arquivo completo (nfeProc, com protocolo de autorização) quanto só o <NFe> assinado. Lança
// RuntimeException com mensagem amigável se o arquivo não for uma NFe reconhecível.
function parseNfeXml(string $conteudo): array {
    // O XML de NFe usa o namespace padrão xmlns="http://www.portalfiscal.inf.br/nfe" em toda tag,
    // o que obrigaria toda consulta a declarar esse namespace. Removê-lo do texto (só o valor do
    // atributo raiz, não o conteúdo dos elementos) simplifica a leitura com SimpleXML sem mudar
    // nenhum dado da nota.
    $semNamespace = preg_replace('/xmlns="[^"]*"/', '', $conteudo, 1);

    libxml_use_internal_errors(true);
    // LIBXML_NONET: nunca busca recursos externos (DTD/entidades) pela rede. Sem LIBXML_NOENT de
    // propósito — habilitar substituição de entidades é o vetor clássico de XXE.
    $xml = simplexml_load_string((string)$semNamespace, 'SimpleXMLElement', LIBXML_NONET);
    libxml_clear_errors();

    if ($xml === false) {
        throw new RuntimeException('O arquivo enviado não é um XML válido.');
    }

    $infNFe = $xml->NFe->infNFe ?? $xml->infNFe ?? null;
    if (!$infNFe || !isset($infNFe->det)) {
        throw new RuntimeException('O arquivo não parece ser o XML de uma Nota Fiscal Eletrônica (NFe) — verifique se é o arquivo correto.');
    }

    $chave = '';
    if (isset($xml->protNFe->infProt->chNFe)) {
        $chave = (string)$xml->protNFe->infProt->chNFe;
    } elseif (isset($infNFe['Id'])) {
        $chave = preg_replace('/\D/', '', (string)$infNFe['Id']);
    }

    $numero = (string)($infNFe->ide->nNF ?? '');
    $emit = $infNFe->emit;
    $emitente = (string)($emit->xNome ?? '');
    $emitenteFantasia = (string)($emit->xFant ?? '');
    $emitenteCnpj = (string)($emit->CNPJ ?? '');
    $emitenteIe = (string)($emit->IE ?? '');
    $ender = $emit->enderEmit ?? null;
    $emitenteEndereco = '';
    $emitenteBairro = '';
    $emitenteCep = '';
    $emitenteMunicipio = '';
    $emitenteUf = '';
    $emitentePais = '';
    $emitenteTelefone = '';
    if ($ender) {
        $emitenteEndereco = trim(trim((string)($ender->xLgr ?? '')) . ', ' . trim((string)($ender->nro ?? '')), ', ');
        if (trim((string)($ender->xCpl ?? '')) !== '') {
            $emitenteEndereco .= ' - ' . trim((string)$ender->xCpl);
        }
        $emitenteBairro = trim((string)($ender->xBairro ?? ''));
        $emitenteCep = preg_replace('/\D/', '', (string)($ender->CEP ?? ''));
        if (strlen($emitenteCep) === 8) {
            $emitenteCep = substr($emitenteCep, 0, 5) . '-' . substr($emitenteCep, 5);
        }
        $emitenteMunicipio = trim((string)($ender->xMun ?? ''));
        $emitenteUf = trim((string)($ender->UF ?? ''));
        $emitentePais = trim((string)($ender->xPais ?? ''));
        $emitenteTelefone = trim((string)($ender->fone ?? ''));
    }

    $itens = [];
    foreach ($infNFe->det as $det) {
        $prod = $det->prod;
        if (!$prod) continue;

        $ean = strtoupper(trim((string)($prod->cEAN ?? '')));
        $eanTrib = strtoupper(trim((string)($prod->cEANTrib ?? '')));
        // "SEM GTIN" é o valor padrão da NFe quando o produto não tem código de barras.
        if ($ean === 'SEM GTIN') $ean = '';
        if ($eanTrib === 'SEM GTIN') $eanTrib = '';

        $qCom = (float)($prod->qCom ?? 0);
        $vUnCom = (float)($prod->vUnCom ?? 0);

        $lotes = [];
        if (isset($prod->rastro)) {
            foreach ($prod->rastro as $rastro) {
                $lotes[] = [
                    'lote' => trim((string)($rastro->nLote ?? '')),
                    'validade' => trim((string)($rastro->dVal ?? '')) ?: null,
                    'quantidade' => (int)round((float)($rastro->qLote ?? 0)),
                ];
            }
        }

        $itens[] = [
            'codigo_barras' => $ean,
            'codigo_barras_trib' => $eanTrib,
            'nome_nfe' => trim((string)($prod->xProd ?? '')),
            'unidade_nfe' => trim((string)($prod->uCom ?? '')),
            'quantidade' => (int)round($qCom),
            'valor_unitario' => round($vUnCom, 2),
            'lotes' => $lotes,
        ];
    }

    if (!$itens) {
        throw new RuntimeException('Nenhum item foi encontrado nesta nota fiscal.');
    }

    return [
        'chave' => $chave !== '' ? $chave : null,
        'numero' => $numero,
        'emitente' => $emitente,
        'emitente_fantasia' => $emitenteFantasia,
        'emitente_cnpj' => $emitenteCnpj,
        'emitente_ie' => $emitenteIe,
        'emitente_endereco' => $emitenteEndereco,
        'emitente_bairro' => $emitenteBairro,
        'emitente_cep' => $emitenteCep,
        'emitente_municipio' => $emitenteMunicipio,
        'emitente_uf' => $emitenteUf,
        'emitente_pais' => $emitentePais,
        'emitente_telefone' => $emitenteTelefone,
        'itens' => $itens,
    ];
}
