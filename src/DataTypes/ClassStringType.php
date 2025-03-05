<?php declare(strict_types=1);

namespace AutoDoc\DataTypes;

/**
 * @template TClass
 *
 * @property ?class-string<TClass> $className
 */
class ClassStringType extends StringType
{
    public function __construct(
        public ?UnresolvedPhpDocType $classTemplateType = null,
        public ?string $className = null,
    ) {
        parent::__construct($className);
    }
}
