<?php declare(strict_types=1);

namespace AutoDoc\Analyzer;

use AutoDoc\DataTypes\UnknownType;
use AutoDoc\OpenApi\MediaType;
use AutoDoc\OpenApi\Operation;
use AutoDoc\OpenApi\Response;
use Closure;
use ReflectionFunction;

class PhpClosure
{
    public function __construct(
        public Closure $closure,
        public Scope $scope,
    ) {}


    private ?PhpFunction $phpFunction = null;


    public function getPhpFunction(): ?PhpFunction
    {
        if (isset($this->phpFunction)) {
            return $this->phpFunction;
        }

        $this->phpFunction = new PhpFunction(
            new ReflectionFunction($this->closure),
            $this->scope,
        );

        return $this->phpFunction;
    }


    public function toOperation(): Operation
    {
        $operation = new Operation;

        $phpFunction = $this->getPhpFunction();
        $phpDoc = $phpFunction?->getPhpDoc();

        if ($phpDoc) {
            [$operation->summary, $operation->description] = $phpDoc->getSummaryAndDescription();
        }

        $responseBodyType = $phpFunction?->getReturnType()?->unwrapType();

        if ($responseBodyType && !($responseBodyType instanceof UnknownType)) {
            $operation->responses = [
                '200' => new Response(
                    content: [
                        'application/json' => new MediaType($responseBodyType->toSchema($this->scope->config)),
                    ],
                ),
            ];
        }

        return $operation;
    }
}
