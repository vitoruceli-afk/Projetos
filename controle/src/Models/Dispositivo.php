<?php

declare(strict_types=1);

namespace App\Models;

/*
|--------------------------------------------------------------------------
| Dispositivo
|--------------------------------------------------------------------------
|
| Dispositivos cadastrados manualmente ou importados via GLPI
|
*/
final class Dispositivo extends BaseModel
{
    protected static string $tabela = 'dispositivos';
    // Prefixadas com "d." porque listar() faz JOIN com tipos_dispositivos,
    // que também tem uma coluna "nome" — sem o prefixo, o WHERE fica ambíguo.
    protected static array $colunasBusca = [
        'd.nome', 'd.descricao', 'd.numero_serie', 'd.imei', 'd.modelo', 'd.fabricante', 'd.responsavel',
        'd.processador', 'd.memoria', 'd.armazenamento', 'd.sistema_operacional', 'd.localizacao', 'd.area',
    ];

    public static function listar(string $busca = '', ?int $tipo_id = null, ?int $pep_id = null, ?string $status = null, ?string $origem = null): array
    {
        [$where, $params] = self::clausulaBusca($busca, self::$colunasBusca);

        $sql = 'SELECT d.*, t.nome as tipo_nome, t.icone, p.pep as pep_codigo, p.projeto as pep_projeto '
             . 'FROM dispositivos d '
             . 'LEFT JOIN tipos_dispositivos t ON t.id = d.tipo_id '
             . 'LEFT JOIN peps p ON p.id = d.pep_id';

        $conditions = [];
        if ($where !== '') {
            $conditions[] = "($where)";
        }
        if ($tipo_id !== null) {
            $conditions[] = 'd.tipo_id = ?';
            $params[] = $tipo_id;
        }
        if ($pep_id !== null) {
            $conditions[] = 'd.pep_id = ?';
            $params[] = $pep_id;
        }
        if ($status !== null) {
            $conditions[] = 'd.status = ?';
            $params[] = $status;
        }
        if ($origem !== null) {
            $conditions[] = 'd.origem = ?';
            $params[] = $origem;
        }

        if (!empty($conditions)) {
            $sql .= ' WHERE ' . implode(' AND ', $conditions);
        }

        $sql .= ' ORDER BY d.nome';

        $stmt = self::pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public static function criar(
        int $tipo_id,
        string $nome,
        ?int $pep_id = null,
        string $numero_serie = '',
        string $modelo = '',
        string $fabricante = '',
        ?string $data_aquisicao = null,
        string $status = 'ativo',
        string $localizacao = '',
        string $responsavel = '',
        float $valor_aquisicao = 0,
        string $descricao = '',
        string $observacoes = '',
        string $origem = 'manual',
        ?int $id_glpi = null,
        ?array $dados_glpi = null,
        ?string $imei = null,
        string $processador = '',
        string $memoria = '',
        string $armazenamento = '',
        string $sistema_operacional = '',
        string $area = '',
        string $segunda_tela = ''
    ): int {
        $stmt = self::pdo()->prepare(
            'INSERT INTO dispositivos '
            . '(tipo_id, nome, pep_id, numero_serie, imei, modelo, fabricante, processador, memoria, '
            . 'armazenamento, sistema_operacional, data_aquisicao, status, localizacao, area, segunda_tela, '
            . 'responsavel, valor_aquisicao, descricao, observacoes, origem, id_glpi, dados_glpi) '
            . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $tipo_id,
            $nome,
            $pep_id,
            $numero_serie,
            $imei !== null && $imei !== '' ? $imei : null,
            $modelo,
            $fabricante,
            $processador,
            $memoria,
            $armazenamento,
            $sistema_operacional,
            $data_aquisicao,
            $status,
            $localizacao,
            $area,
            $segunda_tela,
            $responsavel,
            $valor_aquisicao,
            $descricao,
            $observacoes,
            $origem,
            $id_glpi,
            $dados_glpi ? json_encode($dados_glpi) : null
        ]);
        return (int) self::pdo()->lastInsertId();
    }

    public static function atualizar(
        int $id,
        int $tipo_id,
        string $nome,
        ?int $pep_id = null,
        string $numero_serie = '',
        string $modelo = '',
        string $fabricante = '',
        ?string $data_aquisicao = null,
        string $status = 'ativo',
        string $localizacao = '',
        string $responsavel = '',
        float $valor_aquisicao = 0,
        string $descricao = '',
        string $observacoes = '',
        ?string $imei = null,
        string $processador = '',
        string $memoria = '',
        string $armazenamento = '',
        string $sistema_operacional = '',
        string $area = '',
        string $segunda_tela = ''
    ): void {
        $stmt = self::pdo()->prepare(
            'UPDATE dispositivos SET '
            . 'tipo_id = ?, nome = ?, pep_id = ?, numero_serie = ?, imei = ?, modelo = ?, fabricante = ?, '
            . 'processador = ?, memoria = ?, armazenamento = ?, sistema_operacional = ?, '
            . 'data_aquisicao = ?, status = ?, localizacao = ?, area = ?, segunda_tela = ?, responsavel = ?, '
            . 'valor_aquisicao = ?, descricao = ?, observacoes = ? WHERE id = ?'
        );
        $stmt->execute([
            $tipo_id,
            $nome,
            $pep_id,
            $numero_serie,
            $imei !== null && $imei !== '' ? $imei : null,
            $modelo,
            $fabricante,
            $processador,
            $memoria,
            $armazenamento,
            $sistema_operacional,
            $data_aquisicao,
            $status,
            $localizacao,
            $area,
            $segunda_tela,
            $responsavel,
            $valor_aquisicao,
            $descricao,
            $observacoes,
            $id
        ]);
    }

    public static function atualizarSincronizacao(int $id, ?array $dados_glpi = null): void
    {
        $stmt = self::pdo()->prepare(
            'UPDATE dispositivos SET dados_glpi = ?, sincronizado_em = NOW() WHERE id = ?'
        );
        $stmt->execute([
            $dados_glpi ? json_encode($dados_glpi) : null,
            $id
        ]);
    }

    public static function vincularPep(int $id, ?int $pep_id): void
    {
        $stmt = self::pdo()->prepare('UPDATE dispositivos SET pep_id = ? WHERE id = ?');
        $stmt->execute([$pep_id, $id]);
    }

    public static function alterarStatus(int $id, string $status): void
    {
        if (!in_array($status, ['ativo', 'inativo', 'descartado', 'manutenção'])) {
            throw new \InvalidArgumentException('Status inválido: ' . $status);
        }
        $stmt = self::pdo()->prepare('UPDATE dispositivos SET status = ? WHERE id = ?');
        $stmt->execute([$status, $id]);
    }

    public static function excluir(int $id): void
    {
        $stmt = self::pdo()->prepare('DELETE FROM dispositivos WHERE id = ?');
        $stmt->execute([$id]);
    }

    public static function excluirVarios(array $ids): void
    {
        if (empty($ids)) return;
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = self::pdo()->prepare("DELETE FROM dispositivos WHERE id IN ($placeholders)");
        $stmt->execute($ids);
    }

    public static function contarPorStatus(string $status): int
    {
        $stmt = self::pdo()->prepare('SELECT COUNT(*) as total FROM dispositivos WHERE status = ?');
        $stmt->execute([$status]);
        return (int) $stmt->fetch()['total'];
    }

    public static function contarPorTipo(int $tipo_id): int
    {
        $stmt = self::pdo()->prepare('SELECT COUNT(*) as total FROM dispositivos WHERE tipo_id = ?');
        $stmt->execute([$tipo_id]);
        return (int) $stmt->fetch()['total'];
    }

    public static function contarPorPep(int $pep_id): int
    {
        $stmt = self::pdo()->prepare('SELECT COUNT(*) as total FROM dispositivos WHERE pep_id = ?');
        $stmt->execute([$pep_id]);
        return (int) $stmt->fetch()['total'];
    }

    public static function dispositivosPorPep(int $pep_id): array
    {
        $sql = 'SELECT d.*, t.nome as tipo_nome, t.icone '
             . 'FROM dispositivos d '
             . 'LEFT JOIN tipos_dispositivos t ON t.id = d.tipo_id '
             . 'WHERE d.pep_id = ? '
             . 'ORDER BY d.nome';
        $stmt = self::pdo()->prepare($sql);
        $stmt->execute([$pep_id]);
        return $stmt->fetchAll();
    }

    public static function porNomeCI(string $nome): ?array
    {
        $stmt = self::pdo()->prepare('SELECT * FROM dispositivos WHERE LOWER(nome) = LOWER(?) LIMIT 1');
        $stmt->execute([trim($nome)]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /**
     * Cria ou atualiza (por nome, sem diferenciar maiúsc./minúsc.) um dispositivo
     * a partir de uma linha importada via CSV. Retorna o id e se foi criado agora.
     *
     * @return array{id:int, criado:bool}
     */
    public static function importarLinha(array $dados): array
    {
        $existente = self::porNomeCI($dados['nome']);

        $campos = [
            'tipo_id'             => $dados['tipo_id'],
            'nome'                => $dados['nome'],
            'pep_id'              => $dados['pep_id'],
            'responsavel'         => $dados['responsavel'] ?? '',
            'processador'         => $dados['processador'] ?? '',
            'memoria'             => $dados['memoria'] ?? '',
            'armazenamento'       => $dados['armazenamento'] ?? '',
            'sistema_operacional' => $dados['sistema_operacional'] ?? '',
            'localizacao'         => $dados['localizacao'] ?? '',
            'area'                => $dados['area'] ?? '',
            'segunda_tela'        => $dados['segunda_tela'] ?? '',
        ];

        if ($existente !== null) {
            $sets = [];
            $params = [];
            foreach ($campos as $coluna => $valor) {
                $sets[] = "$coluna = ?";
                $params[] = $valor;
            }
            $params[] = $existente['id'];
            $stmt = self::pdo()->prepare(
                'UPDATE dispositivos SET ' . implode(', ', $sets) . ' WHERE id = ?'
            );
            $stmt->execute($params);
            return ['id' => (int) $existente['id'], 'criado' => false];
        }

        $campos['status'] = 'ativo';
        $campos['origem'] = 'csv';
        $colunas = array_keys($campos);
        $placeholders = implode(',', array_fill(0, count($colunas), '?'));
        $stmt = self::pdo()->prepare(
            'INSERT INTO dispositivos (' . implode(',', $colunas) . ') VALUES (' . $placeholders . ')'
        );
        $stmt->execute(array_values($campos));
        return ['id' => (int) self::pdo()->lastInsertId(), 'criado' => true];
    }

    public static function porIdGlpi(int $id_glpi): ?array
    {
        $stmt = self::pdo()->prepare('SELECT * FROM dispositivos WHERE id_glpi = ? AND origem = "glpi" LIMIT 1');
        $stmt->execute([$id_glpi]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public static function estatisticas(): array
    {
        $sql = 'SELECT '
             . '(SELECT COUNT(*) FROM dispositivos) as total, '
             . '(SELECT COUNT(*) FROM dispositivos WHERE status = "ativo") as ativos, '
             . '(SELECT COUNT(*) FROM dispositivos WHERE status = "inativo") as inativos, '
             . '(SELECT COUNT(*) FROM dispositivos WHERE status = "descartado") as descartados, '
             . '(SELECT COUNT(*) FROM dispositivos WHERE status = "manutenção") as manutencao, '
             . '(SELECT COUNT(*) FROM dispositivos WHERE origem = "glpi") as importados_glpi, '
             . '(SELECT COUNT(*) FROM dispositivos WHERE origem = "manual") as cadastrados_manual';
        $stmt = self::pdo()->prepare($sql);
        $stmt->execute();
        return $stmt->fetch() ?: [];
    }
}
