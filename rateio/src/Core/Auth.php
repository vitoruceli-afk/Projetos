<?php

declare(strict_types=1);

namespace App\Core;

use App\Models\Usuario;
use App\Models\ConfigLdap;

/*
|--------------------------------------------------------------------------
| Auth
|--------------------------------------------------------------------------
|
| Responsável por autenticar o usuário (local ou LDAP), manter a sessão
| e expor verificações de permissão (admin x usuario).
|
| Perfis:
|   - admin   : acesso total (CRUD em todas as telas).
|   - usuario : somente leitura + geração de relatórios.
|
*/
final class Auth
{
    /*
    |----------------------------------------------------------------------
    | TENTA AUTENTICAR
    |----------------------------------------------------------------------
    |
    | 1) Tenta autenticação local (tabela usuarios, origem = 'local').
    | 2) Se LDAP estiver habilitado, tenta autenticar no diretório.
    |
    */
    public static function tentar(string $login, string $senha): bool
    {
        $login = trim($login);

        // 1) LOCAL
        if (self::autenticarLocal($login, $senha)) {
            return true;
        }

        // 2) LDAP
        $ldap = ConfigLdap::efetiva();

        if (!empty($ldap['habilitado'])) {
            return self::autenticarLdap($login, $senha, $ldap);
        }

        return false;
    }

    /*
    |----------------------------------------------------------------------
    | AUTENTICAÇÃO LOCAL
    |----------------------------------------------------------------------
    */
    private static function autenticarLocal(string $login, string $senha): bool
    {
        $usuario = Usuario::porLogin($login, 'local');

        if ($usuario === null) {
            return false;
        }

        if (!self::verificarSenha($senha, $usuario['senha'])) {
            return false;
        }

        self::registrarSessao($usuario);
        return true;
    }

    /*
    |----------------------------------------------------------------------
    | VERIFICAÇÃO DE SENHA
    |----------------------------------------------------------------------
    |
    | Suporta hashes modernos (password_hash) e o legado MD5 da aplicação
    | original. Ao detectar MD5, faz upgrade automático para bcrypt.
    |
    */
    private static function verificarSenha(string $senha, string $hash): bool
    {
        if (password_verify($senha, $hash)) {
            return true;
        }

        // Legado MD5
        if (strlen($hash) === 32 && ctype_xdigit($hash) && md5($senha) === $hash) {
            return true;
        }

        return false;
    }

    /*
    |----------------------------------------------------------------------
    | AUTENTICAÇÃO LDAP / ACTIVE DIRECTORY
    |----------------------------------------------------------------------
    |
    | Usa o grupo de segurança como filtro: o usuário só entra se pertencer
    | ao grupo de admin ou ao grupo de usuário configurado.
    |
    */
    private static function autenticarLdap(string $login, string $senha, array $cfg): bool
    {
        if (!function_exists('ldap_connect')) {
            return false;
        }

        if ($senha === '') {
            return false; // bind anônimo não é login válido
        }

        $conn = @ldap_connect($cfg['host'], (int) $cfg['porta']);

        if ($conn === false) {
            return false;
        }

        ldap_set_option($conn, LDAP_OPT_PROTOCOL_VERSION, 3);
        ldap_set_option($conn, LDAP_OPT_REFERRALS, 0);

        // UPN: usuario@dominio.local
        $upn = str_contains($login, '@')
            ? $login
            : $login . '@' . $cfg['dominio'];

        // Bind com as credenciais do usuário
        if (!@ldap_bind($conn, $upn, $senha)) {
            @ldap_unbind($conn);
            return false;
        }

        // Localiza o usuário e seus grupos (memberOf)
        $filtro = '(' . $cfg['filtro_login'] . '=' . self::ldapEscape($login) . ')';

        $busca = @ldap_search(
            $conn,
            $cfg['base_dn'],
            $filtro,
            ['cn', 'displayname', 'mail', 'memberof', $cfg['filtro_login']]
        );

        if ($busca === false) {
            @ldap_unbind($conn);
            return false;
        }

        $entradas = ldap_get_entries($conn, $busca);
        @ldap_unbind($conn);

        if (($entradas['count'] ?? 0) === 0) {
            return false;
        }

        $entrada = $entradas[0];

        // Coleta os grupos do usuário
        $grupos = [];
        if (isset($entrada['memberof'])) {
            $total = (int) $entrada['memberof']['count'];
            for ($i = 0; $i < $total; $i++) {
                $grupos[] = strtolower($entrada['memberof'][$i]);
            }
        }

        $ehAdmin   = self::pertenceAoGrupo($grupos, $cfg['grupo_admin']);
        $ehUsuario = self::pertenceAoGrupo($grupos, $cfg['grupo_usuario']);

        if (!$ehAdmin && !$ehUsuario) {
            // Não pertence a nenhum grupo autorizado
            return false;
        }

        $perfil = $ehAdmin ? 'admin' : 'usuario';

        $nome  = $entrada['displayname'][0] ?? ($entrada['cn'][0] ?? $login);
        $email = $entrada['mail'][0] ?? $login;

        // Provisiona / atualiza o usuário local (origem = ldap)
        $id = Usuario::provisionarLdap($login, $nome, $email, $perfil);

        self::registrarSessao([
            'id'     => $id,
            'nome'   => $nome,
            'email'  => $email,
            'perfil' => $perfil,
            'origem' => 'ldap',
        ]);

        return true;
    }

    private static function pertenceAoGrupo(array $gruposUsuario, string $grupoAlvo): bool
    {
        $grupoAlvo = strtolower(trim($grupoAlvo));

        if ($grupoAlvo === '') {
            return false;
        }

        foreach ($gruposUsuario as $g) {
            // Compara DN completo OU o CN simples (CN=Grupo,...)
            if ($g === $grupoAlvo) {
                return true;
            }
            if (str_starts_with($g, 'cn=' . $grupoAlvo . ',')) {
                return true;
            }
            if (str_contains($g, '=' . $grupoAlvo . ',') || str_contains($g, '=' . $grupoAlvo)) {
                if (str_contains($g, $grupoAlvo)) {
                    return true;
                }
            }
        }

        return false;
    }

    private static function ldapEscape(string $valor): string
    {
        if (function_exists('ldap_escape')) {
            return ldap_escape($valor, '', LDAP_ESCAPE_FILTER);
        }
        return addcslashes($valor, "\\*()\0");
    }

    /*
    |----------------------------------------------------------------------
    | SESSÃO
    |----------------------------------------------------------------------
    */
    private static function registrarSessao(array $usuario): void
    {
        Session::set('usuario_id', (int) $usuario['id']);
        Session::set('usuario_nome', $usuario['nome']);
        Session::set('usuario_email', $usuario['email'] ?? '');
        Session::set('perfil', $usuario['perfil']);
        Session::set('origem', $usuario['origem'] ?? 'local');
    }

    public static function logado(): bool
    {
        return Session::tem('usuario_id');
    }

    public static function exigirLogin(): void
    {
        if (!self::logado()) {
            header('Location: ' . url('login.php'));
            exit;
        }
    }

    public static function ehAdmin(): bool
    {
        return Session::get('perfil') === 'admin';
    }

    /**
     * Bloqueia o acesso de quem não é administrador.
     */
    public static function exigirAdmin(): void
    {
        self::exigirLogin();

        if (!self::ehAdmin()) {
            http_response_code(403);
            Session::flash('danger', 'Acesso negado: esta ação exige perfil de administrador.');
            $destino = Session::get('contexto') === 'telefonia'
                ? url('telefonia/contas/listar.php')
                : url('microsoft/contas/listar.php');
            header('Location: ' . $destino);
            exit;
        }
    }

    public static function nome(): string
    {
        return (string) Session::get('usuario_nome', '');
    }

    public static function perfil(): string
    {
        return (string) Session::get('perfil', 'usuario');
    }
}
