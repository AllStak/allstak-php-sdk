<?php

declare(strict_types=1);

namespace AllStak\Tests\Unit;

use AllStak\Buffer\RingBuffer;
use AllStak\SdkLogger;
use PHPUnit\Framework\TestCase;

final class RingBufferTest extends TestCase
{
    private function makeBuffer(int $capacity = 5): RingBuffer
    {
        return new RingBuffer($capacity, 'test', new SdkLogger(false));
    }

    public function testPushAndDrain(): void
    {
        $buf = $this->makeBuffer(10);
        $buf->push(['a' => 1]);
        $buf->push(['a' => 2]);
        $buf->push(['a' => 3]);

        $this->assertSame(3, $buf->count());
        $items = $buf->drain();
        $this->assertCount(3, $items);
        $this->assertSame(0, $buf->count());
    }

    public function testOverflowDropsOldest(): void
    {
        $buf = $this->makeBuffer(3);
        $buf->push(['v' => 1]);
        $buf->push(['v' => 2]);
        $buf->push(['v' => 3]);
        $buf->push(['v' => 4]); // should drop v=1

        $items = $buf->drain();
        $this->assertCount(3, $items);
        $this->assertSame(2, $items[0]['v']);
        $this->assertSame(3, $items[1]['v']);
        $this->assertSame(4, $items[2]['v']);
    }

    public function testDrainBatch(): void
    {
        $buf = $this->makeBuffer(10);
        for ($i = 1; $i <= 7; $i++) {
            $buf->push(['v' => $i]);
        }

        $batch = $buf->drainBatch(3);
        $this->assertCount(3, $batch);
        $this->assertSame(1, $batch[0]['v']);
        $this->assertSame(4, $buf->count());
    }

    public function testFlushThreshold(): void
    {
        $buf = $this->makeBuffer(10);
        for ($i = 0; $i < 7; $i++) {
            $buf->push(['v' => $i]);
        }
        $this->assertFalse($buf->isAtFlushThreshold()); // 7 < 8 (80%)

        $buf->push(['v' => 7]);
        $this->assertTrue($buf->isAtFlushThreshold()); // 8 >= 8 (80%)
    }

    public function testIsEmpty(): void
    {
        $buf = $this->makeBuffer();
        $this->assertTrue($buf->isEmpty());
        $buf->push(['x' => 1]);
        $this->assertFalse($buf->isEmpty());
        $buf->drain();
        $this->assertTrue($buf->isEmpty());
    }
}
