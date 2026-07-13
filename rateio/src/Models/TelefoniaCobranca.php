<?php

declare(strict_types=1);

namespace App\Models;

/*
|--------------------------------------------------------------------------
| TelefoniaCobranca  (Rateio Telefonia -> Cobranças)
|--------------------------------------------------------------------------
|
| Espelha o Rateio Microsoft: mês, ano e valor total.
|
*/
final class TelefoniaCobranca extends BaseModel
{
    protected static string $tabela = 'telefonia_cobrancas';
    protected static array $colunasBusca = ['mes', 'ano', 'valor_total', 'conta_telefonia'];

    public static function listar(string $busca = ''): array
    {
        [$where, $params] = self::clausulaBusca($busca, self::$colunasBusca);

        $sql = 'SELECT * FROM telefonia_cobrancas';
        if ($where !== '') {
            $sql .= ' WHERE ' . $where;
        }
        $sql .= ' ORDER BY ano DESC, mes DESC';

        $stmt = self::pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public static function criar(int $mes, int $ano, float $valor, string $contaTelefonia = ''): int
    {
        $stmt = self::pdo()->prepare('
            INSERT INTO telefonia_cobrancas (mes, ano, valor_total, conta_telefonia) VALUES (?, ?, ?, ?)
        ');
        $stmt->execute([$mes, $ano, $valor, $contaTelefonia]);
        return (int) self::pdo()->lastInsertId();
    }

    public static function atualizar(int $id, int $mes, int $ano, float $valor, string $contaTelefonia = ''): void
    {
        $stmt = self::pdo()->prepare('
            UPDATE telefonia_cobrancas SET mes = ?, ano = ?, valor_total = ?, conta_telefonia = ? WHERE id = ?
        ');
        $stmt->execute([$mes, $ano, $valor, $contaTelefonia, $id]);
    }

    public static function somaSelecionadas(array $ids): float
    {
        $ids = array_values(array_filter(array_map('intval', $ids)));
        if ($ids === []) {
            return 0.0;
        }
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $stmt = self::pdo()->prepare("SELECT SUM(valor_total) t FROM telefonia_cobrancas WHERE id IN ($ph)");
        $stmt->execute($ids);
        return (float) ($stmt->fetch()['t'] ?? 0);
    }
}
