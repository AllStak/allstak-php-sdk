<?php

declare(strict_types=1);

namespace AllStak\Integrations;

class InstrumentedStatement
{
    private \PDOStatement $stmt;
    private string $sql;
    private InstrumentedPdo $pdo;

    public function __construct(\PDOStatement $stmt, string $sql, InstrumentedPdo $pdo)
    {
        $this->stmt = $stmt;
        $this->sql = $sql;
        $this->pdo = $pdo;
    }

    public function execute(?array $params = null): bool
    {
        $start = microtime(true);
        try {
            $result = $this->stmt->execute($params);
            $duration = (microtime(true) - $start) * 1000;
            $this->pdo->capture($this->sql, $duration, 'success', null, $this->stmt->rowCount());
            return $result;
        } catch (\Throwable $e) {
            $duration = (microtime(true) - $start) * 1000;
            $this->pdo->capture($this->sql, $duration, 'error', $e->getMessage());
            throw $e;
        }
    }

    public function __call(string $name, array $arguments): mixed
    {
        return $this->stmt->$name(...$arguments);
    }

    public function fetch(int $mode = \PDO::FETCH_DEFAULT, ...$args): mixed
    {
        return $this->stmt->fetch($mode, ...$args);
    }

    public function fetchAll(int $mode = \PDO::FETCH_DEFAULT, ...$args): array
    {
        return $this->stmt->fetchAll($mode, ...$args);
    }

    public function rowCount(): int
    {
        return $this->stmt->rowCount();
    }
}
