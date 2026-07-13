<?php

namespace Config;

class Database
{
    private static $instance = null;
    private $connection;
    private $host = 'localhost';
    private $db_name = 'manutencao_db';
    private $username = 'root';
    private $password = '';
    private $charset = 'utf8mb4';

    private function __construct()
    {
        try {
            $dsn = "mysql:host=" . $this->host . ";dbname=" . $this->db_name . ";charset=" . $this->charset;
            $this->connection = new \PDO(
                $dsn,
                $this->username,
                $this->password,
                array(
                    \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                    \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                    \PDO::ATTR_EMULATE_PREPARES => false
                )
            );
        } catch (\PDOException $e) {
            die("Erro de conexão com banco de dados: " . $e->getMessage());
        }
    }

    /**
     * Obter instância única do banco (Singleton)
     */
    public static function getInstance()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Obter conexão PDO
     */
    public function getConnection()
    {
        return $this->connection;
    }

    /**
     * Preparar query
     */
    public function query($sql)
    {
        return $this->connection->prepare($sql);
    }

    /**
     * Executar query sem retorno
     */
    public function execute($sql, $params = [])
    {
        try {
            $stmt = $this->connection->prepare($sql);
            return $stmt->execute($params);
        } catch (\PDOException $e) {
            throw new \Exception("Erro ao executar query: " . $e->getMessage());
        }
    }

    /**
     * Buscar um registro
     */
    public function fetch($sql, $params = [])
    {
        try {
            $stmt = $this->connection->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetch();
        } catch (\PDOException $e) {
            throw new \Exception("Erro ao buscar registro: " . $e->getMessage());
        }
    }

    /**
     * Buscar todos os registros
     */
    public function fetchAll($sql, $params = [])
    {
        try {
            $stmt = $this->connection->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll();
        } catch (\PDOException $e) {
            throw new \Exception("Erro ao buscar registros: " . $e->getMessage());
        }
    }

    /**
     * Inserir registro
     */
    public function insert($table, $data)
    {
        try {
            $columns = array_keys($data);
            $placeholders = array_fill(0, count($columns), '?');
            
            $sql = "INSERT INTO $table (" . implode(',', $columns) . ") 
                   VALUES (" . implode(',', $placeholders) . ")";
            
            $stmt = $this->connection->prepare($sql);
            $stmt->execute(array_values($data));
            
            return $this->connection->lastInsertId();
        } catch (\PDOException $e) {
            throw new \Exception("Erro ao inserir: " . $e->getMessage());
        }
    }

    /**
     * Atualizar registro
     */
    public function update($table, $data, $where, $whereParams = [])
    {
        try {
            $set = [];
            foreach ($data as $column => $value) {
                $set[] = "$column = ?";
            }
            
            $sql = "UPDATE $table SET " . implode(', ', $set) . " WHERE $where";
            
            $params = array_merge(array_values($data), $whereParams);
            $stmt = $this->connection->prepare($sql);
            return $stmt->execute($params);
        } catch (\PDOException $e) {
            throw new \Exception("Erro ao atualizar: " . $e->getMessage());
        }
    }

    /**
     * Deletar registro
     */
    public function delete($table, $where, $params = [])
    {
        try {
            $sql = "DELETE FROM $table WHERE $where";
            $stmt = $this->connection->prepare($sql);
            return $stmt->execute($params);
        } catch (\PDOException $e) {
            throw new \Exception("Erro ao deletar: " . $e->getMessage());
        }
    }

    /**
     * Obter último ID inserido
     */
    public function lastInsertId()
    {
        return $this->connection->lastInsertId();
    }

    /**
     * Contar registros
     */
    public function count($table, $where = '', $params = [])
    {
        try {
            $sql = "SELECT COUNT(*) as total FROM $table";
            if (!empty($where)) {
                $sql .= " WHERE $where";
            }
            
            $stmt = $this->connection->prepare($sql);
            $stmt->execute($params);
            $result = $stmt->fetch();
            return $result['total'] ?? 0;
        } catch (\PDOException $e) {
            throw new \Exception("Erro ao contar: " . $e->getMessage());
        }
    }

    /**
     * Buscar com paginação
     */
    public function fetchPaginated($sql, $page = 1, $limit = 15, $params = [])
    {
        try {
            $offset = ($page - 1) * $limit;
            
            $sqlLimited = $sql . " LIMIT ? OFFSET ?";
            $params[] = $limit;
            $params[] = $offset;
            
            return $this->fetchAll($sqlLimited, $params);
        } catch (\PDOException $e) {
            throw new \Exception("Erro ao buscar com paginação: " . $e->getMessage());
        }
    }

    /**
     * Iniciar transação
     */
    public function beginTransaction()
    {
        $this->connection->beginTransaction();
    }

    /**
     * Confirmar transação
     */
    public function commit()
    {
        $this->connection->commit();
    }

    /**
     * Reverter transação
     */
    public function rollback()
    {
        $this->connection->rollBack();
    }
}