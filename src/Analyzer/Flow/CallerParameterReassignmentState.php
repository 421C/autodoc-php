<?php declare(strict_types=1);

namespace AutoDoc\Analyzer\Flow;

/**
 * Whether reassignment occurred on none, some, or all paths reaching an event.
 * This determines whether the event can still affect the original binding.
 */
enum CallerParameterReassignmentState
{
    case NotReassigned;
    case PossiblyReassigned;
    case DefinitelyReassigned;

    public function afterAssignment(ScopeEventVisibility $assignmentVisibility): self
    {
        return match ($assignmentVisibility) {
            ScopeEventVisibility::Certain => self::DefinitelyReassigned,
            ScopeEventVisibility::Uncertain => $this === self::NotReassigned
                ? self::PossiblyReassigned
                : $this,
            ScopeEventVisibility::Hidden => $this,
        };
    }

    public function restrictEventVisibility(
        ScopeEventVisibility $eventVisibility,
    ): ScopeEventVisibility {
        return match ($this) {
            self::NotReassigned => $eventVisibility,
            self::PossiblyReassigned => $eventVisibility === ScopeEventVisibility::Hidden
                ? ScopeEventVisibility::Hidden
                : ScopeEventVisibility::Uncertain,
            self::DefinitelyReassigned => ScopeEventVisibility::Hidden,
        };
    }
}
