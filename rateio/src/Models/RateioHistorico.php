<?php

declare(strict_types=1);

namespace App\Models;

/*
|--------------------------------------------------------------------------
| RateioHistorico
|--------------------------------------------------------------------------
|
| Armazena os rateios gerados a cada mês para consulta posterior.
| O campo "tipo" diferencia Microsoft de Telefonia. O detalhamento das linhas
| do relatório é gravado em "dados_json".
|
*/
final class RateioHistorico extends BaseModel
{
    protected static string $tabela = 'rateios_historico';

    public static function listar(string $tipo, string $busca = ''): array
    {
        $sql    = 'SELECT * FROM rateios_historico WHERE tipo = ?';
        $params = [$tipo];

        $termo = trim($busca);
        if ($termo !== '') {
            $sql .= ' AND (descricao LIKE ? OR mes LIKE ? OR ano LIKE ? OR gerado_por LIKE ?)';
            $like = '%' . $termo . '%';
            array_push($params, $like, $like, $like, $like);
        }

        $sql .= ' ORDER BY ano DESC, mes DESC, id DESC';

        $stmt = self::pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public static function registrar(
        string $tipo,
        int $mes,
        int $ano,
        string $descricao,
        string $geradoPor,
        float $valorBoleto,
        float $totalContas,
        float $diferenca,
        float $totalFinal,
        array $linhas
    ): int {
        $stmt = self::pdo()->prepare('
            INSERT INTO rateios_historico
                (tipo, mes, ano, descricao, gerado_por, gerado_em,
                 valor_boleto, total_contas, diferenca, total_final, dados_json)
            VALUES
                (?, ?, ?, ?, ?, NOW(), ?, ?, ?, ?, ?)
        ');
        $stmt->execute([
            $tipo,
            $mes,
            $ano,
            $descricao,
            $geradoPor,
            $valorBoleto,
            $totalContas,
            $diferenca,
            $totalFinal,
            json_encode($linhas, JSON_UNESCAPED_UNICODE),
        ]);

        return (int) self::pdo()->lastInsertId();
    }

    public static function linhas(int $id): array
    {
        $reg = self::buscarPorId($id);
        if ($reg === null) {
            return [];
        }
        return json_decode($reg['dados_json'] ?? '[]', true) ?: [];
    }
}
