<?php

declare(strict_types=1);

namespace App\Models;

/*
|--------------------------------------------------------------------------
| MsCobranca  (Rateio Microsoft -> Cobranças)
|--------------------------------------------------------------------------
*/
final class MsCobranca extends BaseModel
{
    protected static string $tabela = 'ms_cobrancas';
    protected static array $colunasBusca = ['mes', 'ano', 'valor_total'];

    public static function listar(string $busca = ''): array
    {
        [$where, $params] = self::clausulaBusca($busca, self::$colunasBusca);

        $sql = 'SELECT * FROM ms_cobrancas';
        if ($where !== '') {
            $sql .= ' WHERE ' . $where;
        }
        $sql .= ' ORDER BY ano DESC, mes DESC';

        $stmt = self::pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public static function criar(int $mes, int $ano, float $valor): int
    {
        $stmt = self::pdo()->prepare('
            INSERT INTO ms_cobrancas (mes, ano, valor_total) VALUES (?, ?, ?)
        ');
        $stmt->execute([$mes, $ano, $valor]);
        return (int) self::pdo()->lastInsertId();
    }

    public static function atualizar(int $id, int $mes, int $ano, float $valor): void
    {
        $stmt = self::pdo()->prepare('
            UPDATE ms_cobrancas SET mes = ?, ano = ?, valor_total = ? WHERE id = ?
        ');
        $stmt->execute([$mes, $ano, $valor, $id]);
    }

    public static function somaSelecionadas(array $ids): float
    {
        $ids = array_values(array_filter(array_map('intval', $ids)));
        if ($ids === []) {
            return 0.0;
        }
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $stmt = self::pdo()->prepare("SELECT SUM(valor_total) t FROM ms_cobrancas WHERE id IN ($ph)");
        $stmt->execute($ids);
        return (float) ($stmt->fetch()['t'] ?? 0);
    }
}
