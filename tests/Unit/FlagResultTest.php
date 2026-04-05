<?php

declare(strict_types=1);

namespace AllStak\Tests\Unit;

use AllStak\Models\FlagResult;
use PHPUnit\Framework\TestCase;

final class FlagResultTest extends TestCase
{
    public function testBoolValue(): void
    {
        $flag = new FlagResult(key: 'test', enabled: true, value: 'true');
        $this->assertTrue($flag->boolValue());

        $flag2 = new FlagResult(key: 'test', enabled: true, value: 'false');
        $this->assertFalse($flag2->boolValue());

        $flag3 = new FlagResult(key: 'test', enabled: false, value: 'true');
        $this->assertFalse($flag3->boolValue()); // disabled means false
    }

    public function testIntValue(): void
    {
        $flag = new FlagResult(key: 'limit', enabled: true, value: '42');
        $this->assertSame(42, $flag->intValue());
    }

    public function testFloatValue(): void
    {
        $flag = new FlagResult(key: 'rate', enabled: true, value: '0.75');
        $this->assertSame(0.75, $flag->floatValue());
    }
}
