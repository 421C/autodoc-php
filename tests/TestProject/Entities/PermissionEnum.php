<?php declare(strict_types=1);

namespace AutoDoc\Tests\TestProject\Entities;

enum PermissionEnum: string
{
    /**
     * @autodoc-ignore
     */
    case None = 'none';

    case Read = 'read';
    case Write = 'write';
}
