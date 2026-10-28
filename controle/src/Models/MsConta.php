<?php

declare(strict_types=1);

namespace App\Models;

/*
|--------------------------------------------------------------------------
| MsConta  (Rateio Microsoft -> Contas)
|--------------------------------------------------------------------------
|
| Antiga tela "Rateios", renomeada para "Contas".
| As colunas PEP e Projeto vêm do cadastro central de PEP (tabela peps).
| Cada conta pode ter várias licenças (ms_contas_licencas).
|
*/
final class MsConta extends BaseModel
{
    protected static string $tabela = 'ms_contas';

    /**
     * Lista as contas já com PEP/Projeto e licenças agregadas.
     * O filtro de busca cobre todas as colunas exibidas.
     */
    public static function listar(string $busca = ''): array
    {
        $base = '
            SELECT
                c.id,
                c.nome,
                c.email,
                p.pep        AS pep,
                p.projeto    AS projeto,
                c.pep_id,
                GROUP_CONCAT(l.descricao ORDER BY l.descricao SEPARATOR ", ") AS licencas,
                COALESCE(SUM(l.valor), 0) AS valor_total
            FROM ms_contas c
            LEFT JOIN peps p              ON p.id = c.pep_id
            LEFT JOIN ms_contas_licencas cl ON cl.conta_id = c.id
            LEFT JOIN ms_licencas l       ON l.id = cl.licenca_id
        ';

        $params = [];
        $termo  = trim($busca);

        if ($termo !== '') {
            // Busca em todas as colunas relevantes (inclusive licenças agregadas via HAVING)
            $base .= '
                WHERE c.nome LIKE ?
                   OR c.email LIKE ?
                   OR p.pep LIKE ?
                   OR p.projeto LIKE ?
                   OR l.descricao LIKE ?
            ';
            $like   = '%' . $termo . '%';
            $params = [$like, $like, $like, $like, $like];
        }

        $base .= '
            GROUP BY c.id, c.nome, c.email, p.pep, p.projeto, c.pep_id
            ORDER BY c.nome
        ';

        $stmt = self::pdo()->prepare($base);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public static function criar(string $nome, string $email, int $pepId, array $licencas): int
    {
        $pdo = self::pdo();
        $pdo->beginTransaction();

        try {
            $stmt = $pdo->prepare('
                INSERT INTO ms_contas (nome, email, pep_id)
                VALUES (?, ?, ?)
            ');
            $stmt->execute([$nome, $email, $pepId]);
            $id = (int) $pdo->lastInsertId();

            self::vincularLicencas($id, $licencas);

            $pdo->commit();
            return $id;
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    public static function atualizar(int $id, string $nome, string $email, int $pepId, array $licencas): void
    {
        $pdo = self::pdo();
        $pdo->beginTransaction();

        try {
            $stmt = $pdo->prepare('
                UPDATE ms_contas SET nome = ?, email = ?, pep_id = ? WHERE id = ?
            ');
            $stmt->execute([$nome, $email, $pepId, $id]);

            $pdo->prepare('DELETE FROM ms_contas_licencas WHERE conta_id = ?')->execute([$id]);
            self::vincularLicencas($id, $licencas);

            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    private static function vincularLicencas(int $contaId, array $licencas): void
    {
        if ($licencas === []) {
            return;
        }
        $stmt = self::pdo()->prepare('
            INSERT INTO ms_contas_licencas (conta_id, licenca_id) VALUES (?, ?)
        ');
        foreach ($licencas as $licencaId) {
            $stmt->execute([$contaId, (int) $licencaId]);
        }
    }

    public static function excluir(int $id): void
    {
        self::pdo()->prepare('DELETE FROM ms_contas_licencas WHERE conta_id = ?')->execute([$id]);
        parent::excluir($id);
    }

    public static function excluirVarios(array $ids): void
    {
        $ids = array_values(array_filter(array_map('intval', $ids)));
        if ($ids === []) {
            return;
        }
        $ph = implode(',', array_fill(0, count($ids), '?'));
        self::pdo()->prepare("DELETE FROM ms_contas_licencas WHERE conta_id IN ($ph)")->execute($ids);
        self::pdo()->prepare("DELETE FROM ms_contas WHERE id IN ($ph)")->execute($ids);
    }

    public static function licencasDaConta(int $id): array
    {
        $stmt = self::pdo()->prepare('SELECT licenca_id FROM ms_contas_licencas WHERE conta_id = ?');
        $stmt->execute([$id]);
        return array_map('intval', array_column($stmt->fetchAll(), 'licenca_id'));
    }

    /**
     * Agregação por PEP (valor de licenças somado por conta) usada no relatório.
     */
    public static function totaisPorPep(): array
    {
        return self::pdo()->query('
            SELECT
                p.pep      AS pep,
                p.projeto  AS projeto,
                c.nome     AS nome,
                c.email    AS email,
                SUM(l.valor) AS valor_usuario
            FROM ms_contas c
            INNER JOIN peps p              ON p.id = c.pep_id
            INNER JOIN ms_contas_licencas cl ON cl.conta_id = c.id
            INNER JOIN ms_licencas l       ON l.id = cl.licenca_id
            GROUP BY c.id, p.pep, p.projeto, c.nome, c.email
            ORDER BY p.pep, c.nome
        ')->fetchAll();
    }
}
