<?php declare(strict_types=1);

namespace AutoDoc\Tests\Analyzer;

use AutoDoc\Analyzer\PhpCallable;
use AutoDoc\Analyzer\Scope;
use AutoDoc\DataTypes\ObjectType;
use AutoDoc\Tests\TestProject\Entities\TypedStatusResult;
use AutoDoc\Tests\Traits\LoadsConfig;
use AutoDoc\TypeScript\TypeConverter;
use AutoDoc\TypeScript\TypeScriptRenderContext;
use Closure;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionFunction;
use stdClass;

final class TypedPropertyWriteTest extends TestCase
{
    use LoadsConfig;

    #[Test]
    public function writingAnUnknownValueKeepsTheDeclaredPropertyType(): void
    {
        $closures = [
            'unknown' => function () {
                $result = new TypedStatusResult;
                /** @phpstan-ignore assign.propertyType */
                $result->eligible = TypedPropertyWriteTest::untypedValue();

                return $result;
            },
            'unknownOrNull' => function () {
                $result = new TypedStatusResult;
                /** @phpstan-ignore assign.propertyType */
                $result->eligible = TypedPropertyWriteTest::untypedValue() ?? null;

                return $result;
            },
            'bothBranchesOfAnIfElse' => function (bool $flag) {
                $result = new TypedStatusResult;

                if ($flag) {
                    /** @phpstan-ignore assign.propertyType */
                    $result->eligible = TypedPropertyWriteTest::untypedValue();

                } else {
                    /** @phpstan-ignore assign.propertyType */
                    $result->eligible = TypedPropertyWriteTest::untypedValue();
                }

                return $result;
            },
            'propertyOfANestedObject' => function () {
                $result = new TypedStatusResult;
                $result->previous = new TypedStatusResult;
                /** @phpstan-ignore assign.propertyType */
                $result->previous->eligible = TypedPropertyWriteTest::untypedValue();

                return $result->previous;
            },
        ];

        $this->assertSame(
            array_fill_keys(array_keys($closures), 'boolean|null'),
            array_map(fn (Closure $closure) => $this->writtenPropertyTypeScript($closure, 'eligible'), $closures),
        );
    }


    #[Test]
    public function writingAnUnknownValueKeepsEachKindOfDeclaration(): void
    {
        $nativeType = function () {
            $result = new TypedStatusResult;
            /** @phpstan-ignore assign.propertyType */
            $result->checkedAt = TypedPropertyWriteTest::untypedValue();

            return $result;
        };

        $varTag = function () {
            $result = new TypedStatusResult;
            /** @phpstan-ignore assign.propertyType */
            $result->count = TypedPropertyWriteTest::untypedValue();

            return $result;
        };

        $this->assertSame(
            [
                'nativeType' => 'string|null',
                'varTag' => 'number|null',
            ],
            [
                'nativeType' => $this->writtenPropertyTypeScript($nativeType, 'checkedAt'),
                'varTag' => $this->writtenPropertyTypeScript($varTag, 'count'),
            ],
        );
    }


    #[Test]
    public function writingAKnownValueNarrowsTheDeclaredPropertyType(): void
    {
        $closure = function () {
            $result = new TypedStatusResult;
            $result->eligible = true;

            return $result;
        };

        $this->assertSame('true', $this->writtenPropertyTypeScript($closure, 'eligible'));
    }


    #[Test]
    public function writingAnUnknownValueWithoutADeclarationStaysUnknown(): void
    {
        $mixedProperty = function () {
            $result = new TypedStatusResult;
            $result->payload = TypedPropertyWriteTest::untypedValue();

            return $result;
        };

        $stdClass = function () {
            $result = new stdClass;
            $result->eligible = TypedPropertyWriteTest::untypedValue();

            return $result;
        };

        $this->assertSame(
            [
                'mixedProperty' => 'unknown',
                'stdClass' => 'unknown',
            ],
            [
                'mixedProperty' => $this->writtenPropertyTypeScript($mixedProperty, 'payload'),
                'stdClass' => $this->writtenPropertyTypeScript($stdClass, 'eligible'),
            ],
        );
    }


    private function writtenPropertyTypeScript(Closure $closure, string $propertyName): string
    {
        $config = self::loadConfig();
        $scope = new Scope($config);

        $type = new PhpCallable(
            scope: $scope,
            reflection: new ReflectionFunction($closure),
        )->getReturnType()->unwrapType($config);

        self::assertInstanceOf(ObjectType::class, $type);
        self::assertArrayHasKey($propertyName, $type->properties);

        return new TypeConverter()->convertToTypeScriptType(
            type: $type->properties[$propertyName],
            context: new TypeScriptRenderContext($scope, $config->getTypeScriptConfig()),
        );
    }


    private static function untypedValue(): mixed
    {
        return json_decode('null');
    }
}
