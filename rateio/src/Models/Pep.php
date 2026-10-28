<?php

declare(strict_types=1);

namespace App\Models;

/*
|--------------------------------------------------------------------------
| Pep
|--------------------------------------------------------------------------
|
| Cadastro central de PEP + Projeto, compartilhado entre os rateios
| Microsoft e Telefonia.
|
*/
final class Pep extends BaseModel
{
    protected static string $tabela = 'peps';
    protected static array $colunasBusca = ['pep', 'projeto', 'centro_custo', 'responsavel_nome', 'responsavel_cpf'];

    public static function listar(string $busca = ''): array
    {
        [$where, $params] = self::clausulaBusca($busca, self::$colunasBusca);

        $sql = 'SELECT * FROM peps';
        if ($where !== '') {
            $sql .= ' WHERE ' . $where;
        }
        $sql .= ' ORDER BY pep';

        $stmt = self::pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public static function criar(
        string $pep,
        string $projeto,
        string $centro_custo = '',
        int $periodo_meses = 12,
        ?string $data_inicio = null,
        string $responsavel_nome = '',
        string $responsavel_cpf = ''
    ): int {
        $data_termino = self::calcularDataTermino($data_inicio, $periodo_meses);

        $stmt = self::pdo()->prepare(
            'INSERT INTO peps (pep, projeto, centro_custo, periodo_meses, data_inicio, data_termino, responsavel_nome, responsavel_cpf) '
            . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $pep,
            $projeto,
            $centro_custo,
            $periodo_meses,
            $data_inicio,
            $data_termino,
            $responsavel_nome,
            $responsavel_cpf
        ]);
        return (int) self::pdo()->lastInsertId();
    }

    public static function atualizar(
        int $id,
        string $pep,
        string $projeto,
        string $centro_custo = '',
        int $periodo_meses = 12,
        ?string $data_inicio = null,
        string $responsavel_nome = '',
        string $responsavel_cpf = ''
    ): void {
        $data_termino = self::calcularDataTermino($data_inicio, $periodo_meses);

        $stmt = self::pdo()->prepare(
            'UPDATE peps SET pep = ?, projeto = ?, centro_custo = ?, periodo_meses = ?, '
            . 'data_inicio = ?, data_termino = ?, responsavel_nome = ?, responsavel_cpf = ? WHERE id = ?'
        );
        $stmt->execute([
            $pep,
            $projeto,
            $centro_custo,
            $periodo_meses,
            $data_inicio,
            $data_termino,
            $responsavel_nome,
            $responsavel_cpf,
            $id
        ]);
    }

    /**
     * Calcula a data de término baseado na data de início e período em meses
     */
    public static function calcularDataTermino(?string $data_inicio, int $periodo_meses): ?string
    {
        if ($data_inicio === null || $data_inicio === '') {
            return null;
        }

        try {
            $date = \DateTime::createFromFormat('Y-m-d', $data_inicio);
            if ($date === false) {
                return null;
            }
            $date->add(new \DateInterval('P' . $periodo_meses . 'M'));
            return $date->format('Y-m-d');
        } catch (\Exception $e) {
            return null;
        }
    }

    public static function porCodigo(string $pep): ?array
    {
        $stmt = self::pdo()->prepare('SELECT * FROM peps WHERE pep = ? LIMIT 1');
        $stmt->execute([trim($pep)]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public static function pepEmUso(string $pep, ?int $ignorarId = null): bool
    {
        $sql = 'SELECT id FROM peps WHERE pep = ?';
        $params = [$pep];
        if ($ignorarId !== null) {
            $sql .= ' AND id <> ?';
            $params[] = $ignorarId;
        }
        $stmt = self::pdo()->prepare($sql);
        $stmt->execute($params);
        return (bool) $stmt->fetch();
    }
}
