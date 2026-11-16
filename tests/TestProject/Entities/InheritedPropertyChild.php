<?php declare(strict_types=1);

namespace AutoDoc\Tests\TestProject\Entities;

class InheritedPropertyChild extends InheritedPropertyMiddle
{
    /** Receipt is available */
    public bool $hasReceipt = false;
}
