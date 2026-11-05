<?php
// Testes do botão "Detalhes" da revisão (quem acessou a chave: máquina, IP, departamento e usuário).

function sugestaoPara($tipo, $valor) {
    db()->prepare("INSERT INTO ia_sugestoes (chave_hash, tipo, valor, estado, categoria_sugerida) VALUES (:h, :t, :v, 'sugerida', 'neutro')")->execute([':h' => iaHash($tipo, $valor), ':t' => $tipo, ':v' => $valor]);
    return (int)db()->lastInsertId();
}
function eventoDe($maquina, $usuario, $tipo, $app, $titulo, $url, $diasAtras = 1, $seg = 600) {
    static $n = 0; $n++;
    db()->prepare("INSERT INTO eventos (bucket_id, maquina_id, tipo, ts, duracao, app, titulo, url, usuario_detectado) VALUES (:b, :m, :t, :ts, :d, :a, :ti, :u, :us)")
        ->execute([':b' => $tipo === 'web' ? 2 : 1, ':m' => $maquina, ':t' => $tipo, ':ts' => gmdate('Y-m-d H:i:s', time() - $diasAtras * 86400 - $n * 7) . '.000', ':d' => $seg, ':a' => $app, ':ti' => $titulo, ':u' => $url, ':us' => $usuario]);
}

teste('19. detalhes: máquina, IP, departamento e usuário de quem acessou o domínio (e só dele)', function () {
    resetar();
    db()->exec("UPDATE maquinas SET nome = 'BIB-01', ip_local = '10.10.1.5', setor = 'Biblioteca', usuario_responsavel = 'FAESA\\\\resp.bib' WHERE id = 1");
    db()->exec("INSERT INTO maquinas (id, nome, host, porta, ativo, ip_local, setor) VALUES (2, 'RH-07', 'rh-07.local', 5600, 1, '10.10.2.9', 'Recursos Humanos')");
    eventoDe(1, 'FAESA\\ana', 'web', null, 'Página inicial', 'https://www.exemplo.com/a');
    eventoDe(1, 'FAESA\\ana', 'web', null, 'Outra página', 'https://exemplo.com/b?x=1', 2);
    eventoDe(2, 'FAESA\\bruno', 'web', null, 'Vídeo', 'https://sub.exemplo.com/v', 1, 1800);
    eventoDe(2, null, 'web', null, 'Sem usuário', 'https://exemplo.com/c', 1, 60);          // máquina sem usuário detectado -> responsável (RH-07 não tem)
    eventoDe(1, 'FAESA\\ana', 'web', null, 'NÃO conta', 'https://xexemplo.com/z');            // outro domínio que só CONTÉM o texto
    eventoDe(1, 'FAESA\\ana', 'web', null, 'NÃO conta', 'https://exemplo.com.outro.org/z');   // idem
    eventoDe(1, 'FAESA\\ana', 'web', null, 'NÃO conta (velho)', 'https://exemplo.com/velho', 45);   // fora da janela de 30 dias
    $id = sugestaoPara('dominio', 'exemplo.com');
    $d = iaDetalhesChave(db(), $id);
    igual('exemplo.com', $d['chave'], 'chave'); igual(30, $d['dias'], 'janela de 30 dias');
    igual(3, count($d['linhas']), '3 combinações máquina×usuário (ana@BIB, bruno@RH, sem usuário@RH)');
    igual(4, $d['resumo']['eventos'], '4 eventos contados (os 3 de fora não entram)');
    igual(2, $d['resumo']['maquinas'], '2 máquinas'); igual(2, $d['resumo']['departamentos'], '2 departamentos');
    $por = []; foreach ($d['linhas'] as $l) $por[$l['maquina'] . '|' . ($l['usuario'] ?? '')] = $l;
    $ana = $por['BIB-01|FAESA\\ana'];
    igual('10.10.1.5', $ana['ip'], 'IP da máquina'); igual('Biblioteca', $ana['departamento'], 'departamento'); igual(2, $ana['eventos'], 'eventos da ana'); igual('detectado', $ana['usuario_origem'], 'origem do usuário');
    afirmar(count($ana['titulos']) >= 1 && strpos(implode('|', $ana['titulos']), 'NÃO conta') === false, 'títulos de exemplo só do que conta');
    igual('Recursos Humanos', $por['RH-07|FAESA\\bruno']['departamento'], 'departamento da outra máquina'); igual(0.5, $por['RH-07|FAESA\\bruno']['horas'], 'horas do bruno (1800 s)');
    afirmar(isset($por['RH-07|']) && $por['RH-07|']['usuario'] === null, 'sem usuário e sem responsável: vazio');
    // ordenado pelo maior tempo de uso
    igual('RH-07', $d['linhas'][0]['maquina'], 'ordena por tempo (bruno 0,5 h primeiro)');
    // máquina sem usuário detectado usa o responsável cadastrado
    eventoDe(1, null, 'web', null, 'x', 'https://exemplo.com/d', 1, 5000);
    $d2 = iaDetalhesChave(db(), $id);
    $resp = array_values(array_filter($d2['linhas'], fn($l) => $l['usuario_origem'] === 'responsavel'));
    igual(1, count($resp), 'usuário vem do responsável quando não há detecção'); igual('FAESA\\resp.bib', $resp[0]['usuario'], 'nome do responsável');
});

teste('20. detalhes de aplicativo e chave inexistente', function () {
    resetar();
    db()->exec("UPDATE maquinas SET nome = 'LAB-03', ip_local = '10.10.9.3', setor = 'Laboratório' WHERE id = 1");
    eventoDe(1, 'FAESA\\carla', 'window', 'zeta.exe', 'Planilha de notas', null);
    eventoDe(1, 'FAESA\\carla', 'window', 'outro.exe', 'não conta', null);
    $d = iaDetalhesChave(db(), sugestaoPara('app', 'zeta.exe'));
    igual(1, count($d['linhas']), 'só o aplicativo da chave'); igual('Laboratório', $d['linhas'][0]['departamento'], 'departamento'); igual('Planilha de notas', $d['linhas'][0]['titulos'][0], 'título de exemplo');
    lanca(fn() => iaDetalhesChave(db(), 999999), 'não encontrada', 'sugestão inexistente');
    $vazio = iaDetalhesChave(db(), sugestaoPara('dominio', 'ninguem-acessou.com.br'));
    igual(0, count($vazio['linhas']), 'sem acessos: lista vazia'); igual(0, $vazio['resumo']['eventos'], 'resumo zerado');
});

teste('21. endpoint de detalhes: só administrador; devolve JSON; id inválido é recusado', function () {
    resetar();
    db()->exec("DELETE FROM local_users WHERE username IN ('t_adm', 't_user')");
    db()->exec("INSERT INTO local_users (username, password_hash, enabled, role) VALUES ('t_adm', 'x', 1, 'admin'), ('t_user', 'x', 1, 'usuario')");
    eventoDe(1, 'FAESA\\ana', 'web', null, 't', 'https://exemplo.com/a');
    $id = sugestaoPara('dominio', 'exemplo.com');
    $raiz = addslashes(dirname(__DIR__));
    $chamar = fn($usuario, $get) => executarPhp("define('DB_NAME', '" . DB_NAME . "'); chdir('{$raiz}'); require 'config.php'; @session_start(); \$_SESSION['user_logged_in'] = '{$usuario}'; \$_SESSION['last_activity'] = time();
        \$_SERVER['REQUEST_METHOD'] = 'GET'; \$_GET = " . var_export($get, true) . "; include 'ajax_ia_detalhes.php';");
    afirmar(stripos($chamar('t_user', ['id' => $id]), 'Acesso restrito') !== false, 'usuário comum é barrado');
    $json = json_decode($chamar('t_adm', ['id' => $id]), true);
    afirmar(is_array($json) && $json['ok'] === true && $json['chave'] === 'exemplo.com' && $json['resumo']['eventos'] === 1, 'administrador recebe o JSON');
    $inv = json_decode($chamar('t_adm', ['id' => 0]), true);
    afirmar(is_array($inv) && $inv['ok'] === false, 'id inválido recusado');
    $nf = json_decode($chamar('t_adm', ['id' => 987654]), true);
    afirmar(is_array($nf) && $nf['ok'] === false && stripos($nf['erro'], 'não encontrada') !== false, 'sugestão inexistente -> erro claro');
    // a tela da revisão traz o botão e o modal
    $tela = executarPhp("define('DB_NAME', '" . DB_NAME . "'); chdir('{$raiz}'); require 'config.php'; @session_start(); \$_SESSION['user_logged_in'] = 't_adm'; \$_SESSION['last_activity'] = time();
        \$_SERVER['REQUEST_METHOD'] = 'GET'; \$_GET = ['page' => 'ia', 'aba' => 'revisao']; ob_start(); include 'views/ia.php'; echo ob_get_clean();");
    afirmar(strpos($tela, 'btn-detalhes-ia') !== false && strpos($tela, 'modalDetalhesIa') !== false && strpos($tela, 'ajax_ia_detalhes.php') !== false, 'a tela de revisão tem o botão Detalhes e o modal');
    foreach (['Fatal error', 'Warning:', 'Notice:', 'Deprecated:'] as $erro) afirmar(strpos($tela, $erro) === false, "tela sem '{$erro}'");
});
