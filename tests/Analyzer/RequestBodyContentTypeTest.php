<?php declare(strict_types=1);

namespace AutoDoc\Tests\Analyzer;

use AutoDoc\Analyzer\PhpCallable;
use AutoDoc\Analyzer\Scope;
use AutoDoc\DataTypes\ArrayType;
use AutoDoc\DataTypes\NullType;
use AutoDoc\DataTypes\ObjectType;
use AutoDoc\DataTypes\StringType;
use AutoDoc\DataTypes\Type;
use AutoDoc\DataTypes\UnionType;
use AutoDoc\Route;
use AutoDoc\Tests\Traits\LoadsConfig;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionFunction;

final class RequestBodyContentTypeTest extends TestCase
{
    use LoadsConfig;

    /**
     * @return iterable<string, array{list<Type>, string}>
     */
    public static function recordedBodies(): iterable
    {
        yield 'binary property' => [
            [new ObjectType(properties: ['title' => new StringType, 'avatar' => self::binary()])],
            'multipart/form-data',
        ];

        yield 'binary array items' => [
            [new ObjectType(properties: ['photos' => new ArrayType(itemType: self::binary())])],
            'multipart/form-data',
        ];

        yield 'binary entry of a nested shape' => [
            [new ArrayType(shape: ['document' => new ArrayType(shape: ['file' => self::binary()])])],
            'multipart/form-data',
        ];

        yield 'nullable binary property' => [
            [new ObjectType(properties: ['avatar' => new UnionType([self::binary(), new NullType])])],
            'multipart/form-data',
        ];

        yield 'binary display type' => [
            [new ObjectType(properties: ['avatar' => new ObjectType(typeToDisplay: self::binary())])],
            'multipart/form-data',
        ];

        yield 'binary field in a later recorded body' => [
            [
                new ObjectType(properties: ['title' => new StringType]),
                new ObjectType(properties: ['avatar' => self::binary()]),
            ],
            'multipart/form-data',
        ];

        yield 'formatted string that is not binary' => [
            [new ObjectType(properties: ['publishedAt' => new StringType(format: 'date-time')])],
            'application/json',
        ];

        yield 'list of binary strings without field names' => [
            [new ArrayType(itemType: self::binary())],
            'application/json',
        ];

        yield 'body that is itself binary' => [
            [self::binary()],
            'application/octet-stream',
        ];
    }


    /**
     * @param list<Type> $bodies
     */
    #[Test]
    #[DataProvider('recordedBodies')]
    public function theMediaTypeFollowsTheFinalizedBody(array $bodies, string $expectedMediaType): void
    {
        $this->assertSame($expectedMediaType, $this->requestBodyMediaType($bodies));
    }


    #[Test]
    public function explicitMediaTypesWinOverMultipart(): void
    {
        $explicitBody = new ObjectType(properties: ['avatar' => self::binary()]);
        $explicitBody->contentType = 'application/x-www-form-urlencoded';

        $this->assertSame('application/x-www-form-urlencoded', $this->requestBodyMediaType([$explicitBody]));

        $this->assertSame('text/csv', $this->requestBodyMediaType(
            bodies: [new ObjectType(properties: ['avatar' => self::binary()])],
            headers: ['Content-Type' => new StringType('text/csv')],
        ));
    }


    private static function binary(): StringType
    {
        return new StringType(format: 'binary');
    }

    /**
     * @param list<Type> $bodies
     * @param array<string, Type> $headers
     */
    private function requestBodyMediaType(array $bodies, array $headers = []): ?string
    {
        $route = new Route('/api/test/request-body-content-type', 'post');
        $route->requestHeaders = $headers;

        foreach ($bodies as $body) {
            $route->addRequestBodyType($body);
        }

        $operation = new PhpCallable(
            scope: new Scope(self::loadConfig(), route: $route),
            reflection: new ReflectionFunction(function (): void {}),
        )->toOperation();

        return array_key_first($operation->requestBody->content ?? []);
    }
}
