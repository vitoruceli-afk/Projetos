<?php

declare(strict_types=1);

namespace App\Models;

/*
|--------------------------------------------------------------------------
| TelefoniaConta  (Rateio Telefonia -> Contas)
|--------------------------------------------------------------------------
|
| Campos: nome do usuário, número de telefone, operadora,
| PEP (ref. tabela peps), Valor (consumo do mês) e Conta Telefonia.
|
*/
final class TelefoniaConta extends BaseModel
{
    protected static string $tabela = 'telefonia_contas';

    public static function listar(string $busca = ''): array
    {
        $sql = '
            SELECT
                c.id,
                c.nome_usuario,
                c.telefone,
                c.operadora,
                c.conta_telefonia,
                c.valor,
                c.pep_id,
                p.pep       AS pep,
                p.projeto   AS projeto
            FROM telefonia_contas c
            LEFT JOIN peps p ON p.id = c.pep_id
        ';

        $params = [];
        $termo  = trim($busca);

        if ($termo !== '') {
            $sql .= '
                WHERE c.nome_usuario LIKE ?
                   OR c.telefone LIKE ?
                   OR c.operadora LIKE ?
                   OR c.conta_telefonia LIKE ?
                   OR c.valor LIKE ?
                   OR p.pep LIKE ?
                   OR p.projeto LIKE ?
            ';
            $like   = '%' . $termo . '%';
            $params = array_fill(0, 7, $like);
        }

        $sql .= ' ORDER BY c.nome_usuario';

        $stmt = self::pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public static function criar(
        string $nome,
        string $telefone,
        string $operadora,
        int $pepId,
        float $valor,
        string $contaTelefonia
    ): int {
        $stmt = self::pdo()->prepare('
            INSERT INTO telefonia_contas
                (nome_usuario, telefone, operadora, pep_id, valor, conta_telefonia)
            VALUES (?, ?, ?, ?, ?, ?)
        ');
        $stmt->execute([$nome, $telefone, $operadora, $pepId, $valor, $contaTelefonia]);
        return (int) self::pdo()->lastInsertId();
    }

    public static function atualizar(
        int $id,
        string $nome,
        string $telefone,
        string $operadora,
        int $pepId,
        float $valor,
        string $contaTelefonia
    ): void {
        $stmt = self::pdo()->prepare('
            UPDATE telefonia_contas SET
                nome_usuario = ?, telefone = ?, operadora = ?,
                pep_id = ?, valor = ?, conta_telefonia = ?
            WHERE id = ?
        ');
        $stmt->execute([$nome, $telefone, $operadora, $pepId, $valor, $contaTelefonia, $id]);
    }

    /**
     * Atualiza somente o valor (consumo) de uma conta.
     */
    public static function atualizarValor(int $id, float $valor): void
    {
        $stmt = self::pdo()->prepare('UPDATE telefonia_contas SET valor = ? WHERE id = ?');
        $stmt->execute([$valor, $id]);
    }

    /**
     * Lista enxuta (id, telefone, nome) para a atualização em massa por CSV.
     */
    public static function paraAtualizacaoEmMassa(): array
    {
        return self::pdo()->query('
            SELECT id, telefone, nome_usuario FROM telefonia_contas ORDER BY nome_usuario
        ')->fetchAll();
    }

    /**
     * Valores distintos (não vazios) de "Conta Telefonia" já usados nas
     * contas, para alimentar o seletor no cadastro de cobranças.
     */
    public static function contasTelefoniaDistintas(): array
    {
        return self::pdo()->query("
            SELECT DISTINCT conta_telefonia
            FROM telefonia_contas
            WHERE conta_telefonia IS NOT NULL AND conta_telefonia <> ''
            ORDER BY conta_telefonia
        ")->fetchAll(\PDO::FETCH_COLUMN);
    }

    /**
     * Agregação por PEP usada no relatório financeiro Telefonia
     * (o "valor do usuário" é o valor lançado em cada conta).
     *
     * Quando $contaTelefonia é informada, restringe o rateio apenas aos
     * números de telefone que pertencem àquela Conta Telefonia.
     */
    public static function totaisPorPep(?string $contaTelefonia = null): array
    {
        $sql = '
            SELECT
                p.pep        AS pep,
                p.projeto    AS projeto,
                c.nome_usuario AS nome,
                c.telefone   AS email,
                c.conta_telefonia AS conta_telefonia,
                SUM(c.valor) AS valor_usuario
            FROM telefonia_contas c
            INNER JOIN peps p ON p.id = c.pep_id
        ';

        $params = [];
        if ($contaTelefonia !== null) {
            $sql .= ' WHERE c.conta_telefonia = ?';
            $params[] = $contaTelefonia;
        }

        $sql .= '
            GROUP BY c.id, p.pep, p.projeto, c.nome_usuario, c.telefone, c.conta_telefonia
            ORDER BY c.conta_telefonia, p.pep, c.nome_usuario
        ';

        $stmt = self::pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }
}
