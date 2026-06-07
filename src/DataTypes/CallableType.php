<?php declare(strict_types=1);

namespace AutoDoc\DataTypes;

use AutoDoc\Analyzer\ArgumentList;
use AutoDoc\Analyzer\PhpCallable;
use AutoDoc\Config;
use PhpParser\Node;

class CallableType extends Type
{
    public function __construct(
        public ?string $description = null,
        private readonly ?PhpCallable $phpCallable = null,
    ) {}

    public function getReturnType(ArgumentList $args, ?Node $callerNode = null): Type
    {
        return $this->phpCallable?->resolveReturnType($args, $callerNode) ?? new UnknownType;
    }

    public function narrowArgumentTypeFromTruthyReturn(int $argumentIndex, Type $argumentType, ?Node $callerNode = null): ?Type
    {
        return $this->phpCallable?->narrowArgumentTypeFromTruthyReturn($argumentIndex, $argumentType, $callerNode);
    }

    public function toSchema(Config $config): array
    {
        return array_filter([
            'type' => 'string',
            'description' => $this->description,
            'examples' => $this->examples ? array_values($this->examples) : null,
            'deprecated' => $this->deprecated,
            'x-deprecated-description' => $this->deprecatedDescription,
        ]);
    }
}
