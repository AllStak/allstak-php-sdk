<?php

declare(strict_types=1);

namespace AllStak\Integrations;

use AllStak\AllStak;

class DatabaseMonitor
{
    private AllStak $sdk;
    private array $buffer = [];
    private int $batchSize;
    private string $service;
    private string $environment;

    public function __construct(AllStak $sdk, string $service = '', string $environment = '')
    {
        $this->sdk = $sdk;
        $this->batchSize = 20;
        $this->service = $service;
        $this->environment = $environment;
    }

    /**
     * Record a database query.
     */
    public function recordQuery(
        string $query,
        float $durationMs,
        string $status = 'success',
        ?string $errorMessage = null,
        string $databaseName = '',
        string $databaseType = '',
        int $rowsAffected = -1,
        string $traceId = '',
        string $spanId = ''
    ): void {
        $normalized = self::normalizeQuery($query);

        $this->buffer[] = [
            'normalizedQuery' => $normalized,
            'queryHash' => self::hashQuery($normalized),
            'queryType' => self::detectQueryType($query),
            'durationMs' => (int) $durationMs,
            'timestampMillis' => (int) (microtime(true) * 1000),
            'status' => $status,
            'errorMessage' => $errorMessage ? substr($errorMessage, 0, 500) : '',
            'databaseName' => $databaseName,
            'databaseType' => $databaseType,
            'service' => $this->service,
            'environment' => $this->environment,
            'release' => $this->sdk->getOptions()->release,
            'traceId' => $traceId,
            'spanId' => $spanId,
            'rowsAffected' => $rowsAffected,
        ];

        if (count($this->buffer) >= $this->batchSize) {
            $this->flush();
        }
    }

    /**
     * Wrap a PDO instance for automatic query capture.
     */
    public static function wrapPdo(\PDO $pdo, AllStak $sdk, string $dbName = '', string $dbType = ''): InstrumentedPdo
    {
        return new InstrumentedPdo($pdo, $sdk, $dbName, $dbType);
    }

    /**
     * Flush buffered queries to the backend.
     */
    public function flush(): void
    {
        if (empty($this->buffer)) {
            return;
        }

        $items = $this->buffer;
        $this->buffer = [];

        foreach (array_chunk($items, 100) as $batch) {
            try {
                $this->sdk->sendRaw('/ingest/v1/db', ['queries' => $batch]);
            } catch (\Throwable $e) {
                // Don't crash the app
            }
        }
    }

    /**
     * Normalize SQL by replacing literals with placeholders.
     */
    public static function normalizeQuery(string $sql): string
    {
        // Replace string literals
        $sql = preg_replace("/'[^']*'/", '?', $sql);
        // Replace numeric literals
        $sql = preg_replace('/\b\d+(\.\d+)?\b/', '?', $sql);
        // Collapse whitespace
        $sql = preg_replace('/\s+/', ' ', trim($sql));
        return $sql;
    }

    public static function hashQuery(string $normalized): string
    {
        return substr(md5($normalized), 0, 16);
    }

    public static function detectQueryType(string $sql): string
    {
        $first = strtoupper(strtok(trim($sql), " \t\n\r"));
        return in_array($first, ['SELECT', 'INSERT', 'UPDATE', 'DELETE']) ? $first : 'OTHER';
    }
}
