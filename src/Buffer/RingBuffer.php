<?php

declare(strict_types=1);

namespace AllStak\Buffer;

use AllStak\SdkLogger;

final class RingBuffer
{
    private array $items = [];
    private int $capacity;
    private SdkLogger $logger;
    private string $name;
    private bool $overflowWarned = false;
    private int $droppedCount = 0;

    public function __construct(int $capacity, string $name, SdkLogger $logger)
    {
        $this->capacity = $capacity;
        $this->name = $name;
        $this->logger = $logger;
    }

    public function push(array $item): void
    {
        if (count($this->items) >= $this->capacity) {
            array_shift($this->items); // drop oldest (tail-drop)
            $this->droppedCount++;
            if (!$this->overflowWarned) {
                $this->logger->warning("AllStak SDK: {$this->name} buffer full ({$this->capacity}), dropping oldest items");
                $this->overflowWarned = true;
            }
        }
        $this->items[] = $item;
    }

    /**
     * Drain all items from the buffer and return them.
     *
     * @return array[]
     */
    public function drain(): array
    {
        $drained = $this->items;
        $this->items = [];
        $this->overflowWarned = false;
        return $drained;
    }

    /**
     * Drain up to $max items.
     *
     * @return array[]
     */
    public function drainBatch(int $max): array
    {
        $batch = array_splice($this->items, 0, $max);
        if (empty($this->items)) {
            $this->overflowWarned = false;
        }
        return $batch;
    }

    public function count(): int
    {
        return count($this->items);
    }

    public function capacity(): int
    {
        return $this->capacity;
    }

    public function droppedCount(): int
    {
        return $this->droppedCount;
    }

    public function isAtFlushThreshold(): bool
    {
        return $this->count() >= (int) ($this->capacity * 0.8);
    }

    public function isEmpty(): bool
    {
        return empty($this->items);
    }
}
