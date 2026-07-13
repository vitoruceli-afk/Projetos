<?php

declare(strict_types=1);

namespace App\Models;

/*
|--------------------------------------------------------------------------
| Usuario
|--------------------------------------------------------------------------
|
| Usuários locais e usuários provisionados via LDAP.
| Perfis: 'admin' (total) e 'usuario' (somente leitura + relatórios).
|
*/
final class Usuario extends BaseModel
{
    protected static string $tabela = 'usuarios';

    /**
     * Busca usuário pelo login (campo "usuario") ou e-mail.
     */
    public static function porLogin(string $login, ?string $origem = null): ?array
    {
        $sql = 'SELECT * FROM usuarios WHERE (usuario = ? OR email = ?)';
        $params = [$login, $login];

        if ($origem !== null) {
            $sql .= ' AND origem = ?';
            $params[] = $origem;
        }

        $sql .= ' LIMIT 1';

        $stmt = self::pdo()->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public static function criar(
        string $nome,
        string $usuario,
        string $email,
        string $senha,
        string $perfil
    ): int {
        $stmt = self::pdo()->prepare('
            INSERT INTO usuarios (nome, usuario, email, senha, perfil, origem)
            VALUES (?, ?, ?, ?, ?, "local")
        ');
        $stmt->execute([
            $nome,
            $usuario,
            $email,
            password_hash($senha, PASSWORD_DEFAULT),
            $perfil,
        ]);

        return (int) self::pdo()->lastInsertId();
    }

    public static function atualizar(
        int $id,
        string $nome,
        string $usuario,
        string $email,
        string $perfil,
        ?string $senha = null
    ): void {
        if ($senha !== null && $senha !== '') {
            $stmt = self::pdo()->prepare('
                UPDATE usuarios
                SET nome = ?, usuario = ?, email = ?, perfil = ?, senha = ?
                WHERE id = ?
            ');
            $stmt->execute([
                $nome,
                $usuario,
                $email,
                $perfil,
                password_hash($senha, PASSWORD_DEFAULT),
                $id,
            ]);
        } else {
            $stmt = self::pdo()->prepare('
                UPDATE usuarios
                SET nome = ?, usuario = ?, email = ?, perfil = ?
                WHERE id = ?
            ');
            $stmt->execute([$nome, $usuario, $email, $perfil, $id]);
        }
    }

    /**
     * Cria ou atualiza um usuário vindo do LDAP. Retorna o id.
     */
    public static function provisionarLdap(
        string $login,
        string $nome,
        string $email,
        string $perfil
    ): int {
        $existente = self::porLogin($login, 'ldap');

        if ($existente !== null) {
            $stmt = self::pdo()->prepare('
                UPDATE usuarios
                SET nome = ?, email = ?, perfil = ?
                WHERE id = ?
            ');
            $stmt->execute([$nome, $email, $perfil, $existente['id']]);
            return (int) $existente['id'];
        }

        $stmt = self::pdo()->prepare('
            INSERT INTO usuarios (nome, usuario, email, senha, perfil, origem)
            VALUES (?, ?, ?, "", ?, "ldap")
        ');
        $stmt->execute([$nome, $login, $email, $perfil]);

        return (int) self::pdo()->lastInsertId();
    }

    public static function loginEmUso(string $usuario, ?int $ignorarId = null): bool
    {
        $sql = 'SELECT id FROM usuarios WHERE usuario = ?';
        $params = [$usuario];

        if ($ignorarId !== null) {
            $sql .= ' AND id <> ?';
            $params[] = $ignorarId;
        }

        $stmt = self::pdo()->prepare($sql);
        $stmt->execute($params);
        return (bool) $stmt->fetch();
    }
}
