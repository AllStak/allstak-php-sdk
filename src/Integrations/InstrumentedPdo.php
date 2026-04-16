<?php

declare(strict_types=1);

namespace AllStak\Integrations;

use AllStak\AllStak;

/**
 * A PDO wrapper that automatically captures query telemetry.
 * Usage: $pdo = AllStak\Integrations\InstrumentedPdo::wrap($originalPdo);
 */
class InstrumentedPdo
{
    private \PDO $pdo;
    private AllStak $sdk;
    private string $dbName;
    private string $dbType;

    public function __construct(\PDO $pdo, AllStak $sdk, string $dbName = '', string $dbType = '')
    {
        $this->pdo = $pdo;
        $this->sdk = $sdk;
        $this->dbName = $dbName;
        $this->dbType = $dbType ?: $this->detectDriver($pdo);
    }

    /**
     * Wrap a PDO for automatic instrumentation.
     */
    public static function wrap(\PDO $pdo, string $dbName = '', string $dbType = ''): self
    {
        return new self($pdo, AllStak::getInstance(), $dbName, $dbType);
    }

    /**
     * Execute a query and capture telemetry.
     */
    public function query(string $sql, ...$args): \PDOStatement|false
    {
        $start = microtime(true);
        try {
            $result = $this->pdo->query($sql, ...$args);
            $duration = (microtime(true) - $start) * 1000;
            $this->capture($sql, $duration, 'success', null, $result ? $result->rowCount() : -1);
            return $result;
        } catch (\Throwable $e) {
            $duration = (microtime(true) - $start) * 1000;
            $this->capture($sql, $duration, 'error', $e->getMessage());
            throw $e;
        }
    }

    /**
     * Prepare a statement with instrumentation.
     */
    public function prepare(string $sql, array $options = []): InstrumentedStatement
    {
        $stmt = $this->pdo->prepare($sql, $options);
        return new InstrumentedStatement($stmt, $sql, $this);
    }

    /**
     * Execute a raw SQL statement and capture telemetry.
     */
    public function exec(string $sql): int|false
    {
        $start = microtime(true);
        try {
            $result = $this->pdo->exec($sql);
            $duration = (microtime(true) - $start) * 1000;
            $this->capture($sql, $duration, 'success', null, $result !== false ? $result : -1);
            return $result;
        } catch (\Throwable $e) {
            $duration = (microtime(true) - $start) * 1000;
            $this->capture($sql, $duration, 'error', $e->getMessage());
            throw $e;
        }
    }

    /**
     * Forward all other PDO method calls to the wrapped instance.
     */
    public function __call(string $name, array $arguments): mixed
    {
        return $this->pdo->$name(...$arguments);
    }

    public function getPdo(): \PDO
    {
        return $this->pdo;
    }

    public function capture(string $sql, float $durationMs, string $status, ?string $error = null, int $rows = -1): void
    {
        $dbMonitor = $this->sdk->getDatabaseMonitor();
        if ($dbMonitor) {
            $dbMonitor->recordQuery($sql, $durationMs, $status, $error, $this->dbName, $this->dbType, $rows);
        }
    }

    private function detectDriver(\PDO $pdo): string
    {
        try {
            return $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME) ?: '';
        } catch (\Throwable $e) {
            return '';
        }
    }
}
