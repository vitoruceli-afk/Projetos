<?php

declare(strict_types=1);

namespace App\Models;

/*
|--------------------------------------------------------------------------
| MsLicenca  (Rateio Microsoft -> Licenças)
|--------------------------------------------------------------------------
*/
final class MsLicenca extends BaseModel
{
    protected static string $tabela = 'ms_licencas';
    protected static array $colunasBusca = ['codigo_licenca', 'descricao', 'valor', 'modo_cobranca'];

    public static function listar(string $busca = ''): array
    {
        [$where, $params] = self::clausulaBusca($busca, self::$colunasBusca);

        $sql = 'SELECT * FROM ms_licencas';
        if ($where !== '') {
            $sql .= ' WHERE ' . $where;
        }
        $sql .= ' ORDER BY descricao';

        $stmt = self::pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public static function criar(string $codigo, string $descricao, float $valor, string $modo): int
    {
        $stmt = self::pdo()->prepare('
            INSERT INTO ms_licencas (codigo_licenca, descricao, valor, modo_cobranca)
            VALUES (?, ?, ?, ?)
        ');
        $stmt->execute([$codigo, $descricao, $valor, $modo]);
        return (int) self::pdo()->lastInsertId();
    }

    public static function atualizar(int $id, string $codigo, string $descricao, float $valor, string $modo): void
    {
        $stmt = self::pdo()->prepare('
            UPDATE ms_licencas
            SET codigo_licenca = ?, descricao = ?, valor = ?, modo_cobranca = ?
            WHERE id = ?
        ');
        $stmt->execute([$codigo, $descricao, $valor, $modo, $id]);
    }

    /**
     * Resolve uma licença por código OU descrição (usado na importação).
     */
    public static function porCodigoOuDescricao(string $valor): ?array
    {
        $valor = trim($valor);
        $stmt = self::pdo()->prepare('
            SELECT * FROM ms_licencas
            WHERE codigo_licenca = ? OR descricao = ?
            LIMIT 1
        ');
        $stmt->execute([$valor, $valor]);
        $row = $stmt->fetch();
        return $row ?: null;
    }
}
