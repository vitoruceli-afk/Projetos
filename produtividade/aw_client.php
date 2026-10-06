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

    if (!empty($avisos)) {
        $msg = implode('; ', $avisos);
        $marcarMaquina('parcial', $msg);
        $finalizar('parcial', $msg, $totalNovos);
        return ['ok' => true, 'aviso' => $msg, 'eventos_novos' => $totalNovos];
    }

    $marcarMaquina('ok', null);
    $finalizar('ok', '', $totalNovos);
    return ['ok' => true, 'erro' => '', 'eventos_novos' => $totalNovos];
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
