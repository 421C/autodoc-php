<?php declare(strict_types=1);

namespace AutoDoc\Analyzer\Flow;

use AutoDoc\DataTypes\Type;

/**
 * @phpstan-type OriginalBindingEvent array{
 *     event: ScopeEvent,
 *     visibility: ScopeEventVisibility,
 * }
 */
final readonly class CallerParameterBindingAnalysis
{
    private ?ScopeEvent $parameterBinding;

    public function __construct(
        private ScopeEventLog $eventLog,
        private string $parameterName,
        private int $parameterStartFilePos,
    ) {
        $this->parameterBinding = $this->findParameterBinding();
    }

    public function getOriginalBindingType(): ?Type
    {
        return $this->parameterBinding?->changes['type'] ?? null;
    }

    /**
     * @return list<OriginalBindingEvent>
     */
    public function getEventsAffectingOriginalBindingAtExit(int $readFilePos, BranchPath $readBranchPath): array
    {
        if ($this->parameterBinding === null) {
            return [];
        }

        $eventsAffectingOriginalBinding = [];
        $candidateEvents = $this->eventLog->getEventsInEvaluationOrder(
            varName: $this->parameterName,
            readFilePos: $readFilePos,
            readBranchPath: $readBranchPath,
        );

        foreach ($candidateEvents as $event) {
            if ($event->type === ScopeEventType::Assign
                || $event->isTypeAnnotation
                || $event->endFilePos <= $this->parameterBinding->endFilePos
            ) {
                continue;
            }

            $eventVisibility = $this->eventLog->getEventVisibility($event, $readBranchPath);
            $reassignmentState = $this->getReassignmentStateBefore($event);
            $visibilityOnOriginalBinding = $reassignmentState->restrictEventVisibility(
                eventVisibility: $eventVisibility,
            );

            if ($visibilityOnOriginalBinding === ScopeEventVisibility::Hidden) {
                continue;
            }

            $eventsAffectingOriginalBinding[] = [
                'event' => $event,
                'visibility' => $visibilityOnOriginalBinding,
            ];
        }

        return $eventsAffectingOriginalBinding;
    }

    private function findParameterBinding(): ?ScopeEvent
    {
        $parameterBinding = null;

        foreach ($this->eventLog->getAllEvents() as $event) {
            if ($event->varName !== $this->parameterName
                || $event->type !== ScopeEventType::Assign
                || $event->isTypeAnnotation
                || $event->startFilePos > $this->parameterStartFilePos
                || ! isset($event->changes['type'])
            ) {
                continue;
            }

            if ($parameterBinding === null || $event->startFilePos > $parameterBinding->startFilePos) {
                $parameterBinding = $event;
            }
        }

        return $parameterBinding;
    }

    private function getReassignmentStateBefore(ScopeEvent $targetEvent): CallerParameterReassignmentState
    {
        if ($this->parameterBinding === null) {
            return CallerParameterReassignmentState::DefinitelyReassigned;
        }

        $reassignmentState = CallerParameterReassignmentState::NotReassigned;
        $assignments = $this->getRuntimeAssignmentsBefore($targetEvent);

        foreach ($assignments as $assignment) {
            $reassignmentState = $reassignmentState->afterAssignment(
                assignmentVisibility: $this->getAssignmentReachabilityAt($assignment, $targetEvent),
            );
        }

        if ($reassignmentState === CallerParameterReassignmentState::PossiblyReassigned
            && $this->allReachingPathsReassignParameter($assignments, $targetEvent)
        ) {
            return CallerParameterReassignmentState::DefinitelyReassigned;
        }

        return $reassignmentState;
    }

    /**
     * @return ScopeEvent[]
     */
    private function getRuntimeAssignmentsBefore(ScopeEvent $targetEvent): array
    {
        if ($this->parameterBinding === null) {
            return [];
        }

        $assignments = array_filter(
            array: $this->eventLog->getAllEvents(),
            callback: fn (ScopeEvent $event): bool => $event !== $this->parameterBinding
                && $event->varName === $this->parameterName
                && $event->type === ScopeEventType::Assign
                && ! $event->isTypeAnnotation
                && $event->endFilePos > $this->parameterBinding->endFilePos
                && $event->endFilePos < $targetEvent->endFilePos,
        );

        return array_values($assignments);
    }

    /**
     * @param ScopeEvent[] $assignments
     */
    private function allReachingPathsReassignParameter(array $assignments, ScopeEvent $targetEvent): bool
    {
        /** @var array<int, array<int, true>> $assignedBranches */
        $assignedBranches = [];

        foreach ($assignments as $assignment) {
            $assignmentSegments = $assignment->branchPath->segments;
            $targetSegments = $targetEvent->branchPath->segments;
            $sharedDepth = $this->getSharedPathDepth($assignment->branchPath, $targetEvent->branchPath);
            $assignmentSegment = $assignmentSegments[$sharedDepth] ?? null;
            $targetSegment = $targetSegments[$sharedDepth] ?? null;

            if ($assignmentSegment === null
                || count($assignmentSegments) !== $sharedDepth + 1
                || ($targetSegment !== null
                    && $assignmentSegment['conditionId'] === $targetSegment['conditionId'])
            ) {
                continue;
            }

            $assignedBranches[$assignmentSegment['conditionId']][$assignmentSegment['branchIndex']] = true;
        }

        foreach ($assignedBranches as $conditionId => $branchAssignments) {
            $condition = $this->eventLog->getConditionById($conditionId);

            if ($condition === null || ! $condition->isExhaustive()) {
                continue;
            }

            $allPathsReassignParameter = true;

            for ($branchIndex = 0; $branchIndex < $condition->getBranchCount(); $branchIndex++) {
                if (! $condition->branchHasBreakout($branchIndex)
                    && ! isset($branchAssignments[$branchIndex])
                ) {
                    $allPathsReassignParameter = false;
                    break;
                }
            }

            if ($allPathsReassignParameter) {
                return true;
            }
        }

        return false;
    }

    private function getAssignmentReachabilityAt(
        ScopeEvent $assignment,
        ScopeEvent $targetEvent,
    ): ScopeEventVisibility {
        $assignmentPath = $assignment->branchPath;
        $targetPath = $targetEvent->branchPath;

        if ($assignmentPath->depth() === 0 || $assignmentPath->isVisibleFrom($targetPath)) {
            return ScopeEventVisibility::Certain;
        }

        $assignmentSegments = $assignmentPath->segments;
        $targetSegments = $targetPath->segments;
        $sharedDepth = $this->getSharedPathDepth($assignmentPath, $targetPath);

        $assignmentSegment = $assignmentSegments[$sharedDepth] ?? null;
        $targetSegment = $targetSegments[$sharedDepth] ?? null;

        if ($assignmentSegment === null) {
            return ScopeEventVisibility::Certain;
        }

        if ($targetSegment !== null
            && $assignmentSegment['conditionId'] === $targetSegment['conditionId']
        ) {
            return ScopeEventVisibility::Hidden;
        }

        $condition = $this->eventLog->getConditionById($assignmentSegment['conditionId']);

        if ($condition?->branchHasBreakout($assignmentSegment['branchIndex'])) {
            return ScopeEventVisibility::Hidden;
        }

        return ScopeEventVisibility::Uncertain;
    }

    private function getSharedPathDepth(BranchPath $left, BranchPath $right): int
    {
        $sharedDepth = 0;
        $maxSharedDepth = min($left->depth(), $right->depth());

        while ($sharedDepth < $maxSharedDepth
            && $left->segments[$sharedDepth] === $right->segments[$sharedDepth]
        ) {
            $sharedDepth++;
        }

        return $sharedDepth;
    }
}
