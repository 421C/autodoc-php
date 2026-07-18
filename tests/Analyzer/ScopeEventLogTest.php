<?php declare(strict_types=1);

namespace AutoDoc\Tests\Analyzer;

use AutoDoc\Analyzer\Flow\BranchPath;
use AutoDoc\Analyzer\Flow\ScopeEvent;
use AutoDoc\Analyzer\Flow\ScopeEventLog;
use AutoDoc\Analyzer\Flow\ScopeEventType;
use AutoDoc\Analyzer\Flow\ScopeEventVisibility;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ScopeEventLogTest extends TestCase
{
    #[Test]
    public function eventIsHiddenFromSiblingBranch(): void
    {
        $events = new ScopeEventLog;
        $event = new ScopeEvent(
            type: ScopeEventType::Mutate,
            varName: 'value',
            branchPath: new BranchPath([
                ['conditionId' => 1, 'branchIndex' => 0],
            ]),
        );
        $siblingBranch = new BranchPath([
            ['conditionId' => 1, 'branchIndex' => 1],
        ]);

        self::assertSame(
            ScopeEventVisibility::Hidden,
            $events->getEventVisibility($event, $siblingBranch),
        );
    }
}
