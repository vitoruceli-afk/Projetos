<?php
// Cliente HTTP para a REST API do ActivityWatch (aw-server) exposta pelas máquinas da rede, e
// motor de sincronização/categorização. Referência: https://docs.activitywatch.net/en/latest/api/rest.html
//
// A API do ActivityWatch NÃO tem autenticação — a segurança dela é apenas "não aceitar conexões
// que não sejam localhost" por padrão. Para este sistema funcionar, cada máquina monitorada
// precisa ter o aw-server configurado para escutar na rede (aw-server.toml: address = "0.0.0.0"
// ou o IP da interface de rede) e o firewall do Windows liberando a porta (padrão 5600) *apenas*
// para o IP deste servidor — nunca aberta para a rede inteira, já que qualquer host que alcançar
// a porta consegue ler/gravar/apagar todo o histórico de atividade daquela máquina.

function awUrlBase($maquina) {
    $host = trim($maquina['host']);
    $porta = (int)($maquina['porta'] ?: AW_PORTA_PADRAO);
    return "http://{$host}:{$porta}/api/0";
}

// Faz uma requisição GET à API do aw-server de uma máquina. Retorna sempre um array
// ['ok' => bool, 'http_code' => int, 'json' => mixed|null, 'erro' => string].
function awRequest($maquina, $path, array $query = []) {
    $url = awUrlBase($maquina) . $path;
    if (!empty($query)) {
        $url .= '?' . http_build_query($query);
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => AW_TIMEOUT_CONECTAR,
        CURLOPT_TIMEOUT => AW_TIMEOUT_TOTAL,
        CURLOPT_HTTPHEADER => ['Accept: application/json'],
        CURLOPT_FOLLOWLOCATION => false,
    ]);
    $body = curl_exec($ch);
    $erroCurl = curl_error($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($body === false) {
        return ['ok' => false, 'http_code' => 0, 'json' => null, 'erro' => $erroCurl ?: 'Falha na conexão'];
    }
    if ($httpCode < 200 || $httpCode >= 300) {
        return ['ok' => false, 'http_code' => $httpCode, 'json' => null, 'erro' => "HTTP {$httpCode}"];
    }
    $json = json_decode($body, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        return ['ok' => false, 'http_code' => $httpCode, 'json' => null, 'erro' => 'Resposta inválida (não é JSON)'];
    }
    return ['ok' => true, 'http_code' => $httpCode, 'json' => $json, 'erro' => ''];
}

// Testa a conectividade com o aw-server de uma máquina (usado pelo botão "Testar conexão" e antes
// de cada sincronização). Não grava nada no banco.
function awTestarConexao($maquina) {
    $info = awRequest($maquina, '/info');
    if (!$info['ok']) {
        return ['ok' => false, 'erro' => 'Não foi possível conectar: ' . $info['erro']];
    }
    $buckets = awRequest($maquina, '/buckets/');
    if (!$buckets['ok']) {
        return ['ok' => false, 'erro' => 'Conectou, mas falhou ao listar buckets: ' . $buckets['erro']];
    }
    return [
        'ok' => true,
        'hostname' => $info['json']['hostname'] ?? null,
        'versao' => $info['json']['version'] ?? null,
        'buckets' => $buckets['json'] ?? [],
    ];
}

// Classifica o tipo interno (window/afk/web/usuario/sessao/other) a partir do "type" que o
// aw-server devolve para cada bucket (currentwindow, afkstatus, web.tab.current, ...). "currentuser"
// e "sessionevent" são buckets dos nossos próprios agentes (aw-watcher-currentuser e
// aw-watcher-sessionevents, ver Agentes/) — não são watchers oficiais do ActivityWatch, são "custom
// watchers" que falam com a mesma API pública, sem modificar nada da instalação existente.
function awTipoBucket($tipoAw) {
    $tipoAw = (string)$tipoAw;
    if ($tipoAw === 'currentwindow') return 'window';
    if ($tipoAw === 'afkstatus') return 'afk';
    if (strpos($tipoAw, 'web.tab') === 0) return 'web';
    if ($tipoAw === 'currentuser') return 'usuario';
    if ($tipoAw === 'sessionevent') return 'sessao';
    return 'other';
}

// Garante que os buckets reportados pela máquina existam na tabela local `buckets` e devolve
// [bucket_id_aw => linha local] para uso na sincronização.
function awSincronizarListaBuckets(PDO $db, $maquina, array $bucketsAw) {
    $existentesStmt = $db->prepare("SELECT * FROM buckets WHERE maquina_id = :m");
    $existentesStmt->execute([':m' => $maquina['id']]);
    $existentes = [];
    foreach ($existentesStmt->fetchAll() as $row) {
        $existentes[$row['bucket_id']] = $row;
    }

    $insert = $db->prepare("INSERT INTO buckets (maquina_id, bucket_id, tipo, cliente, aw_hostname, criado_em_aw)
                             VALUES (:m, :bid, :tipo, :cliente, :host, :criado)");

    foreach ($bucketsAw as $bucketIdAw => $meta) {
        if (isset($existentes[$bucketIdAw])) continue;
        $criado = null;
        if (!empty($meta['created'])) {
            $ts = strtotime($meta['created']);
            if ($ts !== false) $criado = date('Y-m-d H:i:s', $ts);
        }
        $insert->execute([
            ':m' => $maquina['id'],
            ':bid' => $bucketIdAw,
            ':tipo' => awTipoBucket($meta['type'] ?? ''),
            ':cliente' => $meta['client'] ?? '',
            ':host' => $meta['hostname'] ?? '',
            ':criado' => $criado,
        ]);
        $existentes[$bucketIdAw] = [
            'id' => (int)$db->lastInsertId(),
            'maquina_id' => $maquina['id'],
            'bucket_id' => $bucketIdAw,
            'tipo' => awTipoBucket($meta['type'] ?? ''),
            'ultimo_evento_ts' => null,
        ];
    }

    return $existentes;
}

// Carrega as regras de categorização ativas, já ordenadas por prioridade (menor primeiro = avaliada
// primeiro). Cacheado por requisição — usado em rajada durante uma sincronização.
function awRegrasCategorizacao(PDO $db) {
    static $cache = null;
    if ($cache !== null) return $cache;
    $stmt = $db->query("SELECT r.*, c.pontuacao FROM categoria_regras r
                         JOIN categorias c ON c.id = r.categoria_id
                         WHERE r.ativo = 1 ORDER BY r.prioridade ASC, r.id ASC");
    $cache = $stmt->fetchAll();
    return $cache;
}

// Aplica as regras de categorização a um evento (app/título/url) e devolve o id da categoria
// correspondente, ou null se nenhuma regra casar (evento fica "sem categoria").
function classificarEvento(PDO $db, $app, $titulo, $url) {
    $regras = awRegrasCategorizacao($db);
    $valores = ['app' => (string)$app, 'titulo' => (string)$titulo, 'url' => (string)$url];

    foreach ($regras as $regra) {
        $valor = $valores[$regra['campo']] ?? '';
        if ($valor === '') continue;
        $padrao = $regra['padrao'];
        $casou = false;
        if ($regra['tipo'] === 'regex') {
            $casou = @preg_match('/' . str_replace('/', '\/', $padrao) . '/i', $valor) === 1;
        } else {
            $casou = mb_stripos($valor, $padrao) !== false;
        }
        if ($casou) {
            return (int)$regra['categoria_id'];
        }
    }
    return null;
}

// Sincroniza uma única máquina: lista buckets, busca eventos novos de cada um (a partir do cursor
// salvo, ou dos últimos AW_BACKFILL_INICIAL_DIAS dias se for a primeira vez) e grava em `eventos`.
// Retorna um array com o resultado, e sempre registra uma linha em `sincronizacoes`.
function sincronizarMaquina(PDO $db, array $maquina, $origem = 'manual') {
    $logStmt = $db->prepare("INSERT INTO sincronizacoes (maquina_id, iniciado_em, status, origem) VALUES (:m, :i, 'executando', :o)");
    $logStmt->execute([':m' => $maquina['id'], ':i' => date('Y-m-d H:i:s'), ':o' => $origem]);
    $logId = (int)$db->lastInsertId();

    $finalizar = function ($status, $mensagem, $eventosNovos) use ($db, $logId) {
        $db->prepare("UPDATE sincronizacoes SET finalizado_em = :f, status = :s, mensagem = :msg, eventos_novos = :n WHERE id = :id")
           ->execute([':f' => date('Y-m-d H:i:s'), ':s' => $status, ':msg' => $mensagem, ':n' => $eventosNovos, ':id' => $logId]);
    };

    $marcarMaquina = function ($status, $erro = null) use ($db, $maquina) {
        $db->prepare("UPDATE maquinas SET ultimo_sync_at = :now, ultimo_sync_status = :s, ultimo_erro = :e WHERE id = :id")
           ->execute([':now' => date('Y-m-d H:i:s'), ':s' => $status, ':e' => $erro, ':id' => $maquina['id']]);
    };

    $bucketsResp = awRequest($maquina, '/buckets/');
    if (!$bucketsResp['ok']) {
        $erro = 'Falha ao conectar: ' . $bucketsResp['erro'];
        $marcarMaquina('erro', $erro);
        $finalizar('erro', $erro, 0);
        return ['ok' => false, 'erro' => $erro, 'eventos_novos' => 0];
    }

    $bucketsAw = $bucketsResp['json'] ?? [];
    $buckets = awSincronizarListaBuckets($db, $maquina, $bucketsAw);

    // Aproveita para atualizar hostname/versão do aw-server exibidos na tela de Máquinas.
    $infoResp = awRequest($maquina, '/info');
    if ($infoResp['ok']) {
        $db->prepare("UPDATE maquinas SET aw_hostname = :h, aw_versao = :v WHERE id = :id")
           ->execute([
               ':h' => $infoResp['json']['hostname'] ?? null,
               ':v' => $infoResp['json']['version'] ?? null,
               ':id' => $maquina['id'],
           ]);
    }

    // usuario_detectado entra aqui também (além da correlação por heartbeat feita depois, em
    // atualizarUsuarioDetectadoEventos()) porque eventos tipo 'sessao' (login/lock/unlock, ver
    // Agentes/aw-watcher-sessionevents) já trazem o usuário na PRÓPRIA linha (data.user) — não
    // precisam de correlação nenhuma com o bucket 'usuario'. Isso importa de verdade: correlação por
    // heartbeat falha se houver qualquer lacuna no bucket 'usuario' naquele intervalo (ex: máquina
    // ociosa a noite toda, sem heartbeat nenhum), deixando o evento de sessão pra sempre sem
    // usuario_detectado mesmo sabendo exatamente quem foi, só por não ter cruzado com outro bucket.
    // COALESCE(VALUES(...), usuario_detectado) no UPDATE preserva o valor já calculado por
    // correlação em eventos window/web/afk (onde :usuario_detectado vem NULL) ao resincronizar.
    $upsert = $db->prepare("INSERT INTO eventos (bucket_id, maquina_id, tipo, ts, duracao, app, titulo, url, status, dados, categoria_id, usuario_detectado)
                             VALUES (:bucket_id, :maquina_id, :tipo, :ts, :duracao, :app, :titulo, :url, :status, :dados, :categoria_id, :usuario_detectado)
                             ON DUPLICATE KEY UPDATE duracao = VALUES(duracao), dados = VALUES(dados), categoria_id = VALUES(categoria_id),
                                 usuario_detectado = COALESCE(VALUES(usuario_detectado), usuario_detectado)");

    $totalNovos = 0;
    $avisos = [];
    $agora = new DateTime('now', new DateTimeZone('UTC'));

    foreach ($buckets as $bucketIdAw => $bucket) {
        if ($bucket['tipo'] === 'other') {
            // Ainda registramos o bucket (para aparecer na tela de Máquinas), mas não puxamos
            // eventos de watchers não mapeados — evita guardar formatos de "data" desconhecidos.
            continue;
        }

        // Bucket que a aplicação conhece de antes mas que o aw-server da máquina NÃO lista mais (a extensão
        // do navegador cria "aw-watcher-web-chrome" sem hostname antes de descobrir o nome da máquina e
        // depois passa a usar "aw-watcher-web-chrome_<host>"; o do agente de sessão some se o aw-server é
        // recriado e só volta no próximo evento). Pedir os eventos dele dava HTTP 404 a cada sincronização e
        // deixava a máquina eternamente em "parcial", sem nada de errado de fato. Os eventos já gravados
        // continuam; o bucket só é puxado de novo se voltar a aparecer na lista.
        if (!isset($bucketsAw[$bucketIdAw])) {
            continue;
        }

        if (!empty($bucket['ultimo_evento_ts'])) {
            // Retrocede 5 min sobre o cursor: o último evento de uma sessão do ActivityWatch tem a
            // duração atualizada a cada heartbeat enquanto a mesma janela/aba continua ativa, então
            // é preciso reconsultá-lo (o upsert acima corrige a duração sem duplicar a linha).
            $start = (new DateTime($bucket['ultimo_evento_ts'], new DateTimeZone('UTC')))->modify('-5 minutes');
        } else {
            $start = (clone $agora)->modify('-' . AW_BACKFILL_INICIAL_DIAS . ' days');
        }

        $eventosResp = awRequest($maquina, "/buckets/{$bucketIdAw}/events", [
            'start' => $start->format('Y-m-d\TH:i:s.v\Z'),
            'end' => $agora->format('Y-m-d\TH:i:s.v\Z'),
            'limit' => 10000,
        ]);

        if (!$eventosResp['ok']) {
            $avisos[] = "bucket {$bucketIdAw}: " . $eventosResp['erro'];
            continue;
        }

        $lista = $eventosResp['json'] ?? [];
        if (count($lista) >= 10000) {
            $avisos[] = "bucket {$bucketIdAw}: atingiu o limite de 10000 eventos na janela — considere reduzir o intervalo de sincronização desta máquina.";
        }

        $maiorTs = null;
        foreach ($lista as $evt) {
            $tsObj = new DateTime($evt['timestamp'], new DateTimeZone('UTC'));
            $ts = $tsObj->format('Y-m-d H:i:s.v');
            if ($maiorTs === null || $ts > $maiorTs) $maiorTs = $ts;

            $data = $evt['data'] ?? [];
            $app = $data['app'] ?? null;
            $titulo = $data['title'] ?? null;
            $url = $data['url'] ?? null;
            // 'evento' é o campo que o aw-watcher-sessionevents usa (login/lock/unlock, ver
            // Agentes/aw-watcher-sessionevents) — cai na mesma coluna 'status' já usada pelo afk
            // ('not-afk'/'afk'), evita precisar de uma coluna nova só pra isso.
            $status = $data['status'] ?? $data['evento'] ?? null;

            $categoriaId = ($bucket['tipo'] === 'window' || $bucket['tipo'] === 'web')
                ? classificarEvento($db, $app, $titulo, $url)
                : null;

            // Só 'sessao' se autodeclara aqui — window/web/afk continuam dependendo inteiramente
            // da correlação por heartbeat em atualizarUsuarioDetectadoEventos() (ver comentário
            // acima, no prepare do $upsert).
            $usuarioAutodeclarado = null;
            if ($bucket['tipo'] === 'sessao' && !empty($data['user'])) {
                $usuarioAutodeclarado = mb_substr((string)$data['user'], 0, 150);
            }

            $upsert->execute([
                ':bucket_id' => $bucket['id'],
                ':maquina_id' => $maquina['id'],
                ':tipo' => $bucket['tipo'],
                ':ts' => $ts,
                ':duracao' => $evt['duration'] ?? 0,
                ':app' => $app !== null ? mb_substr($app, 0, 255) : null,
                ':titulo' => $titulo !== null ? mb_substr($titulo, 0, 500) : null,
                ':url' => $url !== null ? mb_substr($url, 0, 1000) : null,
                ':status' => $status,
                ':dados' => json_encode($data, JSON_UNESCAPED_UNICODE),
                ':categoria_id' => $categoriaId,
                ':usuario_detectado' => $usuarioAutodeclarado,
            ]);
            $totalNovos++;
        }

        if ($maiorTs !== null) {
            $db->prepare("UPDATE buckets SET ultimo_evento_ts = :ts, ultimo_sync_at = :now WHERE id = :id")
               ->execute([':ts' => $maiorTs, ':now' => date('Y-m-d H:i:s'), ':id' => $bucket['id']]);
        } else {
            $db->prepare("UPDATE buckets SET ultimo_sync_at = :now WHERE id = :id")
               ->execute([':now' => date('Y-m-d H:i:s'), ':id' => $bucket['id']]);
        }
    }

    atualizarUsuarioResponsavelDetectado($db, $maquina['id']);
    atualizarUsuarioDetectadoEventos($db, $maquina['id']);
    propagarUsuarioDetectado($db, $maquina['id']);

    // Janela do navegador herda a categoria da aba (URL) correspondente — ver herdarCategoriaDasAbas().
    // Só os últimos dias: eventos já reconciliados não mudam, e o upsert acima regrava a categoria de
    // janela dos ~5 min finais, que precisam ser reconciliados de novo.
    herdarCategoriaDasAbas($db, $maquina['id'], gmdate('Y-m-d H:i:s', time() - 3 * 86400));

    // A máquina respondeu (sincronização 'ok' ou 'parcial') — é a hora de disparar a atualização do
    // agente, se alguém a deixou agendada para "a próxima vez que estiver comunicável".
    $atualizacaoDisparada = dispararAtualizacaoAgendada($db, $maquina);

    if (!empty($avisos)) {
        $msg = implode('; ', $avisos);
        $marcarMaquina('parcial', $msg);
        $finalizar('parcial', $msg, $totalNovos);
        return ['ok' => true, 'aviso' => $msg, 'eventos_novos' => $totalNovos, 'atualizacao_disparada' => $atualizacaoDisparada];
    }

    $marcarMaquina('ok', null);
    $finalizar('ok', '', $totalNovos);
    return ['ok' => true, 'erro' => '', 'eventos_novos' => $totalNovos, 'atualizacao_disparada' => $atualizacaoDisparada];
}

// Uma mesma atividade no navegador gera DOIS eventos: o de JANELA (watcher de janela, ex.: chrome.exe +
// título "Baixar o arquivo | iLovePDF - Google Chrome", classificado por aplicativo/título) e o de ABA
// (extensão aw-watcher-web, com a URL, classificado por regras de domínio). Sem reconciliar, o mesmo
// acesso podia cair em categorias diferentes — a janela na categoria de reserva "Neutro · Navegação"
// (só casou o aplicativo) e a aba em "Produtivo · Escritório" (casou o domínio).
// A URL é a informação mais precisa: a janela de navegador passa a herdar a categoria da aba que está
// dentro dela, desde que essa categoria seja específica — não a de reserva nem nula. Vale também quando
// a janela casou uma categoria específica por título e a aba outra.
// A MELHOR aba é escolhida primeiro, qualquer que seja a categoria dela (inclusive neutra ou sem categoria); só
// depois se verifica se ela é específica. Filtrar por categoria antes de escolher fazia a janela herdar a categoria
// de OUTRA aba vizinha quando a aba certa era neutra (ex.: WhatsApp Web ao lado de uma aba de trabalho).
// Casamento janela↔aba: a aba começa até 2s antes ou (duração da janela + 3s) depois do início da janela
// — a extensão costuma reportar a aba ALGUNS décimos DEPOIS do watcher de janela, e janelas de duração 0
// (troca rápida de foco) precisam dessa folga — e, entre os candidatos, vale primeiro a aba cujo título
// está contido no título da janela ("Baixar o arquivo | iLovePDF" em "... - Google Chrome") e depois a de
// início mais próximo.
// $maquinaId e $desdeUtc limitam o trabalho (null = tudo). Devolve quantos eventos mudaram.
function herdarCategoriaDasAbas(PDO $db, $maquinaId = null, $desdeUtc = null) {
    // Categoria "de reserva" = a neutra (pontuação 0): é onde a janela do navegador cai quando só o aplicativo
    // casou. A categoria da aba só é herdada quando é específica (produtiva/improdutiva), nunca a neutra.
    $reserva = array_flip(array_map('intval', array_column($db->query("SELECT id FROM categorias WHERE pontuacao = 0")->fetchAll(), 'id')));
    $filtroW = ''; $filtroB = ''; $paramsW = []; $paramsB = [];
    if ($maquinaId !== null) { $filtroW .= ' AND w.maquina_id = :m'; $paramsW[':m'] = $maquinaId; $filtroB .= ' AND b.maquina_id = :m'; $paramsB[':m'] = $maquinaId; }
    if ($desdeUtc !== null) {
        $filtroW .= ' AND w.ts >= :d'; $paramsW[':d'] = $desdeUtc;
        // as abas começam até 2 s antes da janela — uma folga de 1 min na borda inicial cobre qualquer arredondamento
        $filtroB .= ' AND b.ts >= :d'; $paramsB[':d'] = gmdate('Y-m-d H:i:s', strtotime($desdeUtc . ' UTC') - 60);
    }

    // O casamento é feito em MEMÓRIA, por máquina, com busca binária pelo horário. A versão anterior usava uma
    // subconsulta correlacionada por janela: o MariaDB não aproveitava o intervalo de ts dela e relia TODAS as abas
    // da máquina para cada janela (~2,7 milhões de leituras por sincronização, 11 a 19 s por máquina).
    $stW = $db->prepare("SELECT w.id, w.maquina_id, w.ts, w.duracao, w.titulo, w.categoria_id FROM eventos w
        WHERE w.tipo = 'window' AND LOWER(w.app) IN ('chrome.exe', 'msedge.exe', 'firefox.exe', 'brave.exe', 'opera.exe') {$filtroW}");
    $stW->execute($paramsW);
    $janelasPorMaq = [];
    while ($r = $stW->fetch(PDO::FETCH_ASSOC)) $janelasPorMaq[$r['maquina_id']][] = $r;
    if (!$janelasPorMaq) return 0;

    $stB = $db->prepare("SELECT b.maquina_id, b.ts, b.titulo, b.categoria_id FROM eventos b WHERE b.tipo = 'web' {$filtroB}
        AND b.maquina_id IN (" . implode(',', array_map('intval', array_keys($janelasPorMaq))) . ") ORDER BY b.maquina_id, b.ts");
    $stB->execute($paramsB);
    $epoch = fn($ts) => strtotime(substr($ts, 0, 19) . ' UTC') + (float)('0' . substr($ts, 19));
    $abasPorMaq = [];
    while ($r = $stB->fetch(PDO::FETCH_ASSOC)) $abasPorMaq[$r['maquina_id']][] = [$epoch($r['ts']), (string)$r['titulo'], $r['categoria_id']];

    $update = $db->prepare("UPDATE eventos SET categoria_id = :c WHERE id = :id");
    $n = 0;
    foreach ($janelasPorMaq as $mid => $janelas) {
        $abas = $abasPorMaq[$mid] ?? [];
        $na = count($abas);
        if ($na === 0) continue;
        foreach ($janelas as $w) {
            $t = $epoch($w['ts']);
            $lo = $t - 2; $hi = $t + ceil((float)$w['duracao']) + 3;
            // primeira aba com horário >= lo (busca binária)
            $a = 0; $b = $na;
            while ($a < $b) { $meio = ($a + $b) >> 1; if ($abas[$meio][0] < $lo) $a = $meio + 1; else $b = $meio; }
            $melhor = null; $melhorTitulo = false; $melhorDif = INF;
            $tituloJanela = (string)$w['titulo'];
            for ($i = $a; $i < $na && $abas[$i][0] <= $hi; $i++) {
                $tituloOk = $abas[$i][1] !== '' && $tituloJanela !== '' && mb_stripos($tituloJanela, $abas[$i][1]) !== false;
                $dif = abs($abas[$i][0] - $t);
                // título contido na janela vale primeiro; depois, a de início mais próximo
                if ($melhor === null || ($tituloOk && !$melhorTitulo) || ($tituloOk === $melhorTitulo && $dif < $melhorDif)) {
                    $melhor = $i; $melhorTitulo = $tituloOk; $melhorDif = $dif;
                }
            }
            if ($melhor === null) continue;
            $nova = $abas[$melhor][2];
            if ($nova === null || isset($reserva[(int)$nova])) continue;   // aba sem categoria ou neutra: a janela fica como está
            if ($w['categoria_id'] === null || (int)$w['categoria_id'] !== (int)$nova) {
                $update->execute([':c' => $nova, ':id' => $w['id']]);
                $n++;
            }
        }
    }
    return $n;
}

// Atualização agendada ("atualizar na próxima vez que a máquina estiver comunicável"): chamada ao fim
// de uma sincronização bem-sucedida (a prova de que a máquina está de pé e alcançável). Dispara a
// atualização remota UMA vez e limpa o agendamento — se a atualização falhar, o resultado aparece no
// status da instalação, e quem quiser tentar de novo agenda outra vez (evita um laço de tentativas
// infinitas numa máquina que responde na porta 5600 mas recusa SMB/DCOM). Devolve true se disparou.
function dispararAtualizacaoAgendada(PDO $db, array $maquina) {
    $stmt = $db->prepare("SELECT atualizar_pendente_em FROM maquinas WHERE id = :id");
    $stmt->execute([':id' => $maquina['id']]);
    if (!$stmt->fetchColumn()) return false;

    // Já há uma instalação/atualização em andamento nesta máquina: mantém o agendamento para a
    // próxima sincronização, em vez de empilhar uma segunda execução em paralelo.
    $andamento = $db->prepare("SELECT COUNT(*) FROM instalacoes_remotas WHERE maquina_id = :id AND status IN ('fila', 'executando')");
    $andamento->execute([':id' => $maquina['id']]);
    if ((int)$andamento->fetchColumn() > 0) return false;

    $config = getInstalacaoConfig($db);
    if (empty($config['admin_usuario'])) return false;

    // "Reivindica" o agendamento de forma atômica: se duas sincronizações da mesma máquina
    // coincidirem, só uma delas consegue limpar o campo e disparar.
    $claim = $db->prepare("UPDATE maquinas SET atualizar_pendente_em = NULL WHERE id = :id AND atualizar_pendente_em IS NOT NULL");
    $claim->execute([':id' => $maquina['id']]);
    if ($claim->rowCount() < 1) return false;

    dispararInstalacaoRemota($db, $maquina, 'atualizar');
    return true;
}

// Preenche "Usuário responsável" e "IP local" sozinho a partir do bucket "aw-watcher-currentuser_<host>"
// (o agente próprio em Agentes/, não um watcher oficial do ActivityWatch) — sem exigir credencial
// nenhuma, já que o agente roda dentro da própria sessão do usuário e só reporta a si mesmo. O IP
// vem da PRÓPRIA MÁQUINA (rota padrão, ver aw-watcher-currentuser.ps1) — nunca do DNS do "host"
// cadastrado, que pode ter registro desatualizado. Silenciosamente não faz nada em máquinas onde
// esse agente ainda não está instalado (não existe evento tipo 'usuario' pra elas, a consulta
// simplesmente não acha nada).
function atualizarUsuarioResponsavelDetectado(PDO $db, $maquinaId) {
    $stmt = $db->prepare("SELECT
                               JSON_UNQUOTE(JSON_EXTRACT(dados, '$.user')) AS usuario,
                               JSON_UNQUOTE(JSON_EXTRACT(dados, '$.ip')) AS ip
                           FROM eventos
                           WHERE maquina_id = :m AND tipo = 'usuario' AND JSON_EXTRACT(dados, '$.user') IS NOT NULL
                           ORDER BY ts DESC LIMIT 1");
    $stmt->execute([':m' => $maquinaId]);
    $linha = $stmt->fetch();
    if ($linha && $linha['usuario']) {
        $db->prepare("UPDATE maquinas SET usuario_responsavel = :u, ip_local = :ip WHERE id = :id")
           ->execute([
               ':u' => mb_substr($linha['usuario'], 0, 150),
               ':ip' => $linha['ip'] !== null ? mb_substr($linha['ip'], 0, 45) : null,
               ':id' => $maquinaId,
           ]);
    }
}

// Propaga o último usuário conhecido para eventos que ficaram SEM usuário_detectado — por exemplo quando o
// agente aw-watcher-currentuser parou de enviar batimentos (depois de uma atualização do MSI com o usuário
// já logado, ou com o agente parado) mas a máquina continuou gerando eventos de janela, aba e AFK.
// Evidências de quem estava logado (em ordem de tempo): os batimentos do bucket 'usuario' e os eventos de
// 'sessao' (login/bloqueio/desbloqueio), que trazem o usuário na própria linha. Cada evidência vale a partir
// do seu início até a PRÓXIMA evidência (de qualquer usuário — um login de outra pessoa encerra a anterior) ou,
// no máximo, 24 h depois do fim dela. Só toca eventos ainda sem usuário: o que a correlação por batimento já
// preencheu não muda. $dias limita a janela reprocessada (padrão: as últimas 72 h; um backfill usa um valor maior).
function propagarUsuarioDetectado(PDO $db, $maquinaId, $dias = 3) {
    $desde = gmdate('Y-m-d H:i:s', time() - ($dias + 1) * 86400);
    $stmt = $db->prepare("SELECT ts, duracao, tipo, JSON_UNQUOTE(JSON_EXTRACT(dados, '$.user')) AS usuario
                           FROM eventos WHERE maquina_id = :m AND tipo IN ('usuario', 'sessao') AND ts >= :d ORDER BY ts ASC");
    $stmt->execute([':m' => $maquinaId, ':d' => $desde]);
    $pontos = [];
    foreach ($stmt->fetchAll() as $r) {
        if ($r['usuario'] === null || $r['usuario'] === '') continue;
        $ini = strtotime(substr($r['ts'], 0, 19) . ' UTC');
        $fimCobertura = $r['tipo'] === 'usuario' ? $ini + (int)ceil((float)$r['duracao']) : $ini;
        $pontos[] = ['ini' => $ini, 'fim' => $fimCobertura, 'u' => mb_substr($r['usuario'], 0, 150)];
    }
    $n = count($pontos);
    if ($n === 0) return 0;
    $limiteInferior = time() - $dias * 86400;
    $update = $db->prepare("UPDATE eventos SET usuario_detectado = :u
                             WHERE maquina_id = :m AND tipo IN ('window', 'web', 'afk', 'sessao')
                               AND (usuario_detectado IS NULL OR usuario_detectado = '')
                               AND ts >= :a AND ts < :b");
    $alterados = 0;
    for ($i = 0; $i < $n; $i++) {
        $a = $pontos[$i]['ini'];
        $b = $pontos[$i]['fim'] + 86400;
        if ($i + 1 < $n && $pontos[$i + 1]['ini'] < $b) $b = $pontos[$i + 1]['ini'];
        if ($b <= $limiteInferior || $b <= $a) continue;
        $update->execute([':u' => $pontos[$i]['u'], ':m' => $maquinaId, ':a' => gmdate('Y-m-d H:i:s', max($a, $limiteInferior)) . '.000', ':b' => gmdate('Y-m-d H:i:s', $b) . '.000']);
        $alterados += $update->rowCount();
    }
    return $alterados;
}

// Preenche eventos.usuario_detectado (window/web/afk/sessao) com o usuário do Windows que estava logado na
// máquina no momento de cada evento, a partir do mesmo bucket "aw-watcher-currentuser_<host>" usado
// por atualizarUsuarioResponsavelDetectado() acima — mas evento a evento, não só "o último visto",
// pra sustentar a aba Usuários e a quebra por usuário dentro da Visão Máquina do Dashboard. Cada
// linha do bucket 'usuario' já é um heartbeat fundido pelo aw-server (pulsetime=600s, ver
// aw-watcher-currentuser.ps1) num único evento contínuo enquanto o mesmo usuário fica logado; uma
// "sessão" aqui é o intervalo [ts, ts_fim) desse evento, onde ts_fim é 600s depois do fim reportado
// (cobre o atraso até o próximo heartbeat agendado) ou o início do heartbeat seguinte, o que vier
// primeiro.
//
// $limiteEventos limita quantos dos ÚLTIMOS eventos 'usuario' desta máquina são reprocessados: como
// o registro de uma sessão em andamento tem sua duração (e portanto seu ts_fim) crescendo a cada
// heartbeat SEM criar uma linha nova, o valor padrão (10) é suficiente pra manter a sessão atual e
// pegar eventuais trocas de usuário recentes a cada sincronização, sem reprocessar o histórico
// inteiro sempre - reprocessamento em massa (ex: após adicionar esta coluna) usa um limite bem
// maior, só uma vez.
function atualizarUsuarioDetectadoEventos(PDO $db, $maquinaId, $limiteEventos = 10) {
    $stmt = $db->prepare("SELECT ts, duracao, JSON_UNQUOTE(JSON_EXTRACT(dados, '$.user')) AS usuario
                           FROM (
                               SELECT ts, duracao, dados FROM eventos
                               WHERE maquina_id = :m AND tipo = 'usuario'
                               ORDER BY ts DESC LIMIT " . (int)$limiteEventos . "
                           ) ultimos
                           ORDER BY ts ASC");
    $stmt->execute([':m' => $maquinaId]);
    $linhas = $stmt->fetchAll();
    $n = count($linhas);
    if ($n === 0) return;

    $update = $db->prepare("UPDATE eventos SET usuario_detectado = :u
                             WHERE maquina_id = :m AND tipo IN ('window', 'web', 'afk', 'sessao')
                               AND ts >= :ini AND ts < :fim");
    for ($i = 0; $i < $n; $i++) {
        $usuario = $linhas[$i]['usuario'];
        if ($usuario === null || $usuario === '') continue;

        $tsInicio = new DateTime($linhas[$i]['ts'], new DateTimeZone('UTC'));
        $tsFim = (clone $tsInicio)->modify('+' . (ceil((float)$linhas[$i]['duracao']) + 600) . ' seconds');
        if ($i + 1 < $n) {
            $proximoTs = new DateTime($linhas[$i + 1]['ts'], new DateTimeZone('UTC'));
            if ($proximoTs < $tsFim) $tsFim = $proximoTs;
        }

        $update->execute([
            ':u' => mb_substr($usuario, 0, 150),
            ':m' => $maquinaId,
            ':ini' => $tsInicio->format('Y-m-d H:i:s.v'),
            ':fim' => $tsFim->format('Y-m-d H:i:s.v'),
        ]);
    }
}
