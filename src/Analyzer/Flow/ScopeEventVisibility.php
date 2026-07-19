<?php declare(strict_types=1);

namespace AutoDoc\Analyzer\Flow;

enum ScopeEventVisibility
{
    case Certain;
    case Uncertain;
    case Hidden;
}
