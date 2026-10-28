<?php

declare(strict_types=1);

namespace App\Models;

/*
|--------------------------------------------------------------------------
| TipoDispositivo
|--------------------------------------------------------------------------
|
| Tipos de dispositivos suportados (computador, celular, tablet, etc)
|
*/
final class TipoDispositivo extends BaseModel
{
    protected static string $tabela = 'tipos_dispositivos';

    public static function listar(): array
    {
        $sql = 'SELECT * FROM tipos_dispositivos ORDER BY nome';
        $stmt = self::pdo()->prepare($sql);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public static function porId(int $id): ?array
    {
        $stmt = self::pdo()->prepare('SELECT * FROM tipos_dispositivos WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public static function porNome(string $nome): ?array
    {
        $stmt = self::pdo()->prepare('SELECT * FROM tipos_dispositivos WHERE nome = ? LIMIT 1');
        $stmt->execute([$nome]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public static function porNomeCI(string $nome): ?array
    {
        $stmt = self::pdo()->prepare('SELECT * FROM tipos_dispositivos WHERE LOWER(nome) = LOWER(?) LIMIT 1');
        $stmt->execute([trim($nome)]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public static function criar(string $nome, string $icone = 'bi-question-circle', string $descricao = ''): int
    {
        $stmt = self::pdo()->prepare(
            'INSERT INTO tipos_dispositivos (nome, icone, descricao) VALUES (?, ?, ?)'
        );
        $stmt->execute([$nome, $icone, $descricao]);
        return (int) self::pdo()->lastInsertId();
    }

    /**
     * Retorna o id do tipo com esse nome (sem diferenciar maiúsc./minúsc.),
     * criando-o caso ainda não exista.
     */
    public static function localizarOuCriar(string $nome): int
    {
        $existente = self::porNomeCI($nome);
        if ($existente !== null) {
            return (int) $existente['id'];
        }
        return self::criar($nome);
    }
}
