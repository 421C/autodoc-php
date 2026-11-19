<?php declare(strict_types=1);

namespace AutoDoc\Tests\TestProject\Extensions;

use AutoDoc\Analyzer\PhpClass;
use AutoDoc\DataTypes\BoolType;
use AutoDoc\DataTypes\ObjectType;
use AutoDoc\DataTypes\Type;
use AutoDoc\Extensions\ClassExtension;
use AutoDoc\Tests\TestProject\Entities\ClassRecordedAsRequestBody;

class RequestBodyClassExtension extends ClassExtension
{
    public function getRequestType(PhpClass $phpClass): ?Type
    {
        if ($phpClass->className !== ClassRecordedAsRequestBody::class) {
            return null;
        }

        return new ObjectType(properties: [
            'urgent' => new BoolType,
        ]);
    }
}
