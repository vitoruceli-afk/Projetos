<?php
// Cliente LDAP para integração com o Active Directory: busca membros de um grupo (para importar
// como usuários deste sistema) e computadores de uma OU (para importar como máquinas monitoradas).
// Mesma abordagem de conexão validada na integração LDAP do Mikrotik Manager desta instituição
// (protocolo v3, referrals desligados, timeout curto para não travar a tela se o DC cair).

function ldapConnect($server) {
    $ldap = @ldap_connect($server);
    if (!$ldap) return false;
    @ldap_set_option($ldap, LDAP_OPT_PROTOCOL_VERSION, 3);
    @ldap_set_option($ldap, LDAP_OPT_REFERRALS, 0);
    @ldap_set_option($ldap, LDAP_OPT_NETWORK_TIMEOUT, LDAP_CONN_TIMEOUT);
    @ldap_set_option($ldap, LDAP_OPT_TIMEOUT, LDAP_CONN_TIMEOUT);
    return $ldap;
}

// Configuração de conexão com o AD (linha única, id=1). Sempre existe — semeada em getDB().
function getAdConfig(PDO $db) {
    return $db->query("SELECT * FROM ad_config ORDER BY id ASC LIMIT 1")->fetch();
}

// Abre e autentica uma conexão LDAP com a conta de serviço configurada. Devolve
// [linkLdap|false, mensagemDeErro].
function adConectarServico(array $config) {
    if (empty($config['ldap_server'])) {
        return [false, 'Servidor LDAP não configurado.'];
    }
    if (empty($config['bind_username'])) {
        return [false, 'Nenhuma conta de serviço configurada.'];
    }
    $ldap = ldapConnect($config['ldap_server']);
    if (!$ldap) {
        return [false, 'Não foi possível conectar ao servidor LDAP.'];
    }
    $senha = adDecrypt($config['bind_password']);
    if (!@ldap_bind($ldap, $config['bind_username'], $senha)) {
        return [false, 'Falha ao autenticar com a conta de serviço configurada.'];
    }
    return [$ldap, ''];
}

// Testa a conexão + autenticação da conta de serviço (botão "Testar Conexão" da tela de
// Integração > Active Directory). Não faz nenhuma busca, só valida o bind.
function adTestarConexao(array $config) {
    [$ldap, $erro] = adConectarServico($config);
    if (!$ldap) {
        return ['ok' => false, 'erro' => $erro];
    }
    return ['ok' => true, 'erro' => ''];
}

// userAccountControl é uma máscara de bits; 0x2 (ACCOUNTCONTROL_DISABLE) indica conta desabilitada
// no AD. Ausência do atributo (nem sempre é retornado) é tratada como "ativo" por padrão.
function adContaAtiva($entry) {
    if (!isset($entry['useraccountcontrol'][0])) return true;
    return (((int)$entry['useraccountcontrol'][0]) & 2) === 0;
}

// Busca os usuários (objectClass=user) que são membro do grupo informado (DN completo). Usado
// tanto para listar na tela quanto, com os mesmos parâmetros, para revalidar a seleção no momento
// de importar (evita confiar em nome/e-mail que o navegador devolveria no POST).
function adBuscarMembrosGrupo(array $config, $groupDn, &$erro = null) {
    [$ldap, $erroConexao] = adConectarServico($config);
    if (!$ldap) {
        $erro = $erroConexao;
        return [];
    }

    $filtro = "(&(objectClass=user)(memberOf=" . $groupDn . "))";
    $busca = @ldap_search($ldap, $config['ldap_basedn'], $filtro, ['sAMAccountName', 'displayName', 'mail', 'userAccountControl', 'distinguishedName']);
    if (!$busca) {
        $erro = 'Falha ao buscar usuários no grupo informado (DN inválido ou fora da base configurada?).';
        return [];
    }
    $entries = @ldap_get_entries($ldap, $busca);

    $usuarios = [];
    for ($i = 0; $i < ($entries['count'] ?? 0); $i++) {
        $entry = $entries[$i];
        $username = $entry['samaccountname'][0] ?? '';
        if ($username === '') continue;
        $usuarios[] = [
            'username' => $username,
            'nome' => $entry['displayname'][0] ?? $username,
            'email' => $entry['mail'][0] ?? '',
            'ativo' => adContaAtiva($entry),
            'dn' => $entry['dn'] ?? '',
        ];
    }
    usort($usuarios, fn($a, $b) => strcasecmp($a['nome'], $b['nome']));
    return $usuarios;
}

// Busca os computadores (objectClass=computer) dentro da OU informada (DN completo), em toda a
// subárvore (inclui sub-OUs). Devolve o hostname de rede (dNSHostName) já pronto para virar o
// campo "host" de uma máquina monitorada.
function adBuscarComputadoresOU(array $config, $ouDn, &$erro = null) {
    [$ldap, $erroConexao] = adConectarServico($config);
    if (!$ldap) {
        $erro = $erroConexao;
        return [];
    }

    $filtro = "(objectClass=computer)";
    $busca = @ldap_search($ldap, $ouDn, $filtro, ['cn', 'dNSHostName', 'description', 'operatingSystem', 'userAccountControl', 'distinguishedName']);
    if (!$busca) {
        $erro = 'Falha ao buscar computadores na OU informada (DN inválido ou fora da base configurada?).';
        return [];
    }
    $entries = @ldap_get_entries($ldap, $busca);

    $maquinas = [];
    for ($i = 0; $i < ($entries['count'] ?? 0); $i++) {
        $entry = $entries[$i];
        $cn = $entry['cn'][0] ?? '';
        if ($cn === '') continue;
        $maquinas[] = [
            'cn' => $cn,
            'host' => $entry['dnshostname'][0] ?? $cn,
            'descricao' => $entry['description'][0] ?? '',
            'sistema' => $entry['operatingsystem'][0] ?? '',
            'ativo' => adContaAtiva($entry),
            'dn' => $entry['dn'] ?? '',
        ];
    }
    usort($maquinas, fn($a, $b) => strcasecmp($a['cn'], $b['cn']));
    return $maquinas;
}
