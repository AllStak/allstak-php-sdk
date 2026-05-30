<?php

declare(strict_types=1);

namespace AllStak;

final class Diagnostics implements \JsonSerializable
{
    public function __construct(
        public readonly int $eventsCaptured = 0,
        public readonly int $eventsSent = 0,
        public readonly int $eventsFailed = 0,
        public readonly int $eventsDropped = 0,
        public readonly int $eventsPersisted = 0,
        public readonly int $eventsReplayed = 0,
        public readonly int $queueSize = 0,
        public readonly int $retryAttempts = 0,
        public readonly int $rateLimitedCount = 0,
        public readonly int $compressedPayloads = 0,
        public readonly int $uncompressedPayloads = 0,
        public readonly int $compressionBytesSaved = 0,
        public readonly int $sanitizerRedactionCount = 0,
        public readonly int $activeTraceCount = 0,
        public readonly int $activeSpanCount = 0,
        public readonly int $breadcrumbCount = 0,
        public readonly int $sessionRecoveryCount = 0,
        public readonly bool $disabled = false,
    ) {}

    /** @return array<string,int|bool> */
    public function toArray(): array
    {
        return [
            'eventsCaptured' => $this->eventsCaptured,
            'eventsSent' => $this->eventsSent,
            'eventsFailed' => $this->eventsFailed,
            'eventsDropped' => $this->eventsDropped,
            'eventsPersisted' => $this->eventsPersisted,
            'eventsReplayed' => $this->eventsReplayed,
            'queueSize' => $this->queueSize,
            'retryAttempts' => $this->retryAttempts,
            'rateLimitedCount' => $this->rateLimitedCount,
            'compressedPayloads' => $this->compressedPayloads,
            'uncompressedPayloads' => $this->uncompressedPayloads,
            'compressionBytesSaved' => $this->compressionBytesSaved,
            'sanitizerRedactionCount' => $this->sanitizerRedactionCount,
            'activeTraceCount' => $this->activeTraceCount,
            'activeSpanCount' => $this->activeSpanCount,
            'breadcrumbCount' => $this->breadcrumbCount,
            'sessionRecoveryCount' => $this->sessionRecoveryCount,
            'disabled' => $this->disabled,
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
