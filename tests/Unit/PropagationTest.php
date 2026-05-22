<?php

declare(strict_types=1);

namespace AllStak\Tests\Unit;

use AllStak\Propagation;
use PHPUnit\Framework\TestCase;

final class PropagationTest extends TestCase
{
    public function testMergeBaggagePreservesVendorMembersAndReplacesSdkMembers(): void
    {
        $this->assertSame(
            'vendor=value,allstak-trace_id=tt,allstak-request_id=rr,allstak-span_id=ss',
            Propagation::mergeBaggage('vendor=value, allstak-trace_id=old, allstak-span_id=old', 'tt', 'rr', 'ss')
        );
    }

    public function testBaggageBuildsCanonicalMembers(): void
    {
        $this->assertSame(
            'allstak-trace_id=tt,allstak-request_id=rr,allstak-span_id=ss',
            Propagation::baggage('tt', 'rr', 'ss')
        );
    }
}
