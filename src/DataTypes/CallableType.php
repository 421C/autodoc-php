<?php declare(strict_types=1);

namespace AutoDoc\DataTypes;

use AutoDoc\Analyzer\PhpAnonymousFunction;
use AutoDoc\Analyzer\PhpFunctionArgument;
use AutoDoc\Config;
use PhpParser\Node;

class CallableType extends Type
{
    public function __construct(
        public ?string $description = null,
        private ?PhpAnonymousFunction $anonymousFunction = null,
    ) {}

    /**
     * @param PhpFunctionArgument[] $args
     */
    public function getReturnType(array $args = [], ?Node $callerNode = null): Type
    {
        return $this->anonymousFunction?->resolveReturnType($args, $callerNode) ?? new UnknownType;
    }

    public function toSchema(?Config $config = null): array
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
