<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Config;

/*
|--------------------------------------------------------------------------
| ConfigLdap
|--------------------------------------------------------------------------
|
| Persiste (linha única) a configuração de LDAP definida pelo admin.
| Se não houver linha no banco, usa os valores de config/config.php.
|
*/
final class ConfigLdap extends BaseModel
{
    protected static string $tabela = 'config_ldap';

    /**
     * Configuração efetiva: banco (se existir) com fallback no config.php.
     */
    public static function efetiva(): array
    {
        $padrao = Config::get('ldap', []);

        $row = self::pdo()->query('SELECT * FROM config_ldap WHERE id = 1 LIMIT 1')->fetch();

        if (!$row) {
            return $padrao;
        }

        return [
            'habilitado'    => (bool) $row['habilitado'],
            'host'          => $row['host'] ?: $padrao['host'],
            'porta'         => (int) ($row['porta'] ?: $padrao['porta']),
            'base_dn'       => $row['base_dn'] ?: $padrao['base_dn'],
            'dominio'       => $row['dominio'] ?: $padrao['dominio'],
            'grupo_admin'   => $row['grupo_admin'] ?: $padrao['grupo_admin'],
            'grupo_usuario' => $row['grupo_usuario'] ?: $padrao['grupo_usuario'],
            'filtro_login'  => $row['filtro_login'] ?: $padrao['filtro_login'],
        ];
    }

    public static function salvar(array $dados): void
    {
        $stmt = self::pdo()->prepare('
            INSERT INTO config_ldap
                (id, habilitado, host, porta, base_dn, dominio,
                 grupo_admin, grupo_usuario, filtro_login)
            VALUES
                (1, ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                habilitado    = VALUES(habilitado),
                host          = VALUES(host),
                porta         = VALUES(porta),
                base_dn       = VALUES(base_dn),
                dominio       = VALUES(dominio),
                grupo_admin   = VALUES(grupo_admin),
                grupo_usuario = VALUES(grupo_usuario),
                filtro_login  = VALUES(filtro_login)
        ');

        $stmt->execute([
            !empty($dados['habilitado']) ? 1 : 0,
            $dados['host'] ?? '',
            (int) ($dados['porta'] ?? 389),
            $dados['base_dn'] ?? '',
            $dados['dominio'] ?? '',
            $dados['grupo_admin'] ?? '',
            $dados['grupo_usuario'] ?? '',
            $dados['filtro_login'] ?? 'sAMAccountName',
        ]);
    }
}
