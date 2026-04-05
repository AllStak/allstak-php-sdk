<?php

declare(strict_types=1);

namespace AllStak;

final class SdkLogger
{
    private bool $debug;

    public function __construct(bool $debug = false)
    {
        $this->debug = $debug;
    }

    public function debug(string $message, array $context = []): void
    {
        if ($this->debug) {
            $this->write('DEBUG', $message, $context);
        }
    }

    public function warning(string $message, array $context = []): void
    {
        $this->write('WARN', $message, $context);
    }

    public function error(string $message, array $context = []): void
    {
        $this->write('ERROR', $message, $context);
    }

    private function write(string $level, string $message, array $context): void
    {
        $timestamp = date('Y-m-d\TH:i:s.vP');
        $line = "[AllStak SDK {$level}] [{$timestamp}] {$message}";
        if (!empty($context)) {
            $line .= ' ' . json_encode($context, JSON_UNESCAPED_SLASHES);
        }
        $stream = defined('STDERR') ? STDERR : fopen('php://stderr', 'w');
        fwrite($stream, $line . PHP_EOL);
    }
}
