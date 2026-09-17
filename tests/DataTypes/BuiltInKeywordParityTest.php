<?php declare(strict_types=1);

namespace AutoDoc\Tests\DataTypes;

use AutoDoc\Analyzer\DocBlock\PhpDoc;
use AutoDoc\Analyzer\Scope;
use AutoDoc\DataTypes\BoolType;
use AutoDoc\DataTypes\CallableType;
use AutoDoc\DataTypes\Type;
use AutoDoc\DataTypes\UnknownType;
use AutoDoc\Tests\TestProject\Entities\BuiltInKeywordHolder;
use AutoDoc\Tests\Traits\LoadsConfig;
use PhpParser\Node;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionNamedType;

final class BuiltInKeywordParityTest extends TestCase
{
    use LoadsConfig;

    #[Test]
    public function nativeHintsDocBlocksAndReflectionResolveBuiltInKeywordsAlike(): void
    {
        $scope = new Scope(self::loadConfig());
        $phpDoc = new PhpDoc('/** */', $scope);

        foreach (self::reflectedKeywordTypes() as $keyword => $reflectionType) {
            $nativeType = $scope->resolveType(new Node\Identifier($keyword));
            $docBlockType = $phpDoc->resolveTypeFromNode($phpDoc->createTypeNode($keyword));
            $reflectedType = Type::resolveFromReflection($reflectionType, $scope);

            $this->assertNotInstanceOf(UnknownType::class, $nativeType, $keyword);
            $this->assertNotNull($docBlockType, $keyword);
            $this->assertSame($nativeType::class, $docBlockType::class, $keyword);
            $this->assertSame($nativeType::class, $reflectedType::class, $keyword);
        }
    }


    #[Test]
    public function boolLiteralKeywordsKeepTheirValueThroughEveryPath(): void
    {
        $scope = new Scope(self::loadConfig());
        $phpDoc = new PhpDoc('/** */', $scope);
        $reflectionTypes = self::reflectedKeywordTypes();

        foreach ([true, false] as $value) {
            $keyword = $value ? 'true' : 'false';

            $nativeType = $scope->resolveType(new Node\Identifier($keyword));
            $docBlockType = $phpDoc->resolveTypeFromNode($phpDoc->createTypeNode($keyword));
            $reflectedType = Type::resolveFromReflection($reflectionTypes[$keyword], $scope);

            $this->assertInstanceOf(BoolType::class, $nativeType);
            $this->assertInstanceOf(BoolType::class, $docBlockType);
            $this->assertInstanceOf(BoolType::class, $reflectedType);

            $this->assertSame($value, $nativeType->value, $keyword);
            $this->assertSame($value, $docBlockType->value, $keyword);
            $this->assertSame($value, $reflectedType->value, $keyword);
        }
    }


    #[Test]
    public function callableResolvesToCallableTypeFromEveryPath(): void
    {
        $scope = new Scope(self::loadConfig());
        $phpDoc = new PhpDoc('/** */', $scope);

        $this->assertInstanceOf(CallableType::class, $scope->resolveType(new Node\Identifier('callable')));
        $this->assertInstanceOf(CallableType::class, $phpDoc->resolveTypeFromNode($phpDoc->createTypeNode('callable')));
        $this->assertInstanceOf(CallableType::class, Type::resolveFromReflection(self::reflectedKeywordTypes()['callable'], $scope));
    }


    /**
     * @return array<string, ReflectionNamedType>
     */
    private static function reflectedKeywordTypes(): array
    {
        $types = [];

        foreach (new ReflectionMethod(BuiltInKeywordHolder::class, 'parameters')->getParameters() as $parameter) {
            $type = $parameter->getType();

            if ($type instanceof ReflectionNamedType) {
                $types[$parameter->getName()] = $type;
            }
        }

        foreach (['void' => 'returnsVoid', 'never' => 'returnsNever'] as $keyword => $methodName) {
            $returnType = new ReflectionMethod(BuiltInKeywordHolder::class, $methodName)->getReturnType();

            if ($returnType instanceof ReflectionNamedType) {
                $types[$keyword] = $returnType;
            }
        }

        return $types;
    }
}
