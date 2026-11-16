<?php declare(strict_types=1);

namespace AutoDoc\Tests\TestProject\Entities;

/**
 * View type (HTML or PDF)
 */
enum DocumentViewType: string
{
    case Html = 'html';
    case Pdf = 'pdf';
}
