<?php declare(strict_types=1);

namespace AutoDoc\Tests;

use AutoDoc\Config;
use AutoDoc\Analyzer\Scope;
use AutoDoc\TypeScript\TypeScriptFile;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use AutoDoc\Tests\Traits\LoadsConfig;

final class TypeScriptSchemaTest extends TestCase
{
    use LoadsConfig;

    #[Test]
    public function simpleBuiltInClass(): void
    {
        $this->assertTypeScriptGeneratedCorrectly(
            input: '
            /** @autodoc ReflectionEnum */
            ',

            expected: '
            /** @autodoc ReflectionEnum */
            export interface ReflectionEnum {
                name: string
            }
            ',
        );
    }

    #[Test]
    public function genericClassNoIndent(): void
    {
        $this->assertTypeScriptGeneratedCorrectly(
            input: ''
                . '/*' . "\n"
                . '@autodoc AutoDoc\Tests\TestProject\Entities\GenericClass' . "\n"
                . '*/' . "\n",

            expected: ''
                . '/*' . "\n"
                . '@autodoc AutoDoc\Tests\TestProject\Entities\GenericClass' . "\n"
                . '*/' . "\n"
                . 'export interface GenericClass {' . "\n"
                . '    data: unknown' . "\n"
                . '}' . "\n",
        );
    }

    #[Test]
    public function enumDeclarationUpdate(): void
    {
        $this->assertTypeScriptGeneratedCorrectly(
            input: '
                /*@autodoc AutoDoc\Tests\TestProject\Entities\RocketCategory
            */
            export enum RocketCategoryEnum {}
            export type RocketName = string
            ',

            expected: "
                /*@autodoc AutoDoc\Tests\TestProject\Entities\RocketCategory
            */
            export enum RocketCategoryEnum {
                Big = 'Big',
                Small = 'Small',
            }
            export type RocketName = string
            ",
        );
    }

    #[Test]
    public function typeDeclarationUpdate(): void
    {
        $this->assertTypeScriptGeneratedCorrectly(
            input: '
            /** @autodoc AutoDoc\Tests\TestProject\Entities\SimpleClass */
            type SimpleClass = {
            }
            ',

            expected: '
            /** @autodoc AutoDoc\Tests\TestProject\Entities\SimpleClass */
            type SimpleClass = {
                n: number|null
            }
            ',
        );
    }

    #[Test]
    public function enumUpdateAndThreeClasses(): void
    {
        $this->assertTypeScriptGeneratedCorrectly(
            input: '
            yoo

            /**
             * @autodoc AutoDoc\Tests\TestProject\Entities\StateEnum
             */
            enum StateEnum {
                One = 1,
            }

            /*
            yoyo
            @autodoc   AutoDoc\Route  

            */

            text ...

            /** @autodoc AutoDoc\OpenApi\InfoObject */

            /** @autodoc ReflectionClass */',

            expected: '
            yoo

            /**
             * @autodoc AutoDoc\Tests\TestProject\Entities\StateEnum
             */
            enum StateEnum {
                One = 1,
                Two = 2,
            }

            /*
            yoyo
            @autodoc   AutoDoc\Route  

            */
            export interface Route {
                uri: string
                method: string
                className: string|null
                classMethod: string|null
                closure: {}|null
                meta: unknown[]
                responses: Array<{
                    status?: number
                    contentType?: string
                    body?: unknown
                }>
            }

            text ...

            /** @autodoc AutoDoc\OpenApi\InfoObject */
            export interface InfoObject {
                title: string
                version: string
                description: string
            }

            /** @autodoc ReflectionClass */
            export interface ReflectionClass {
                name: string
            }',
        );
    }

    #[Test]
    public function closureResponse(): void
    {
        $this->assertTypeScriptGeneratedCorrectly(
            input: '
            /** @autodoc GET /api/test/closure1 */
            ',

            expected: '
            /** @autodoc GET /api/test/closure1 */
            export type Closure1Response = Array<{
                test: boolean
            }>
            ',
        );
    }

    #[Test]
    public function closureWithScalarResponse(): void
    {
        $this->assertTypeScriptGeneratedCorrectly(
            input: '
            /** @autodoc GET /api/test/closure3 200 */
            ',

            expected: '
            /** @autodoc GET /api/test/closure3 200 */
            export type Closure3Response = number|null
            ',
        );
    }

    #[Test]
    public function controllerMethodWithArrayShapeResponse(): void
    {
        $this->assertTypeScriptGeneratedCorrectly(
            input: '
            /** @autodoc get /api/test/route9 */
            ',

            expected: '
            /** @autodoc get /api/test/route9 */
            export interface Route9Response {
                text: string
                encoded: string
                count: number
                enum: 1|2
            }
            ',
        );
    }

    #[Test]
    public function controllerMethodWithExistingNumberType(): void
    {
        $this->assertTypeScriptGeneratedCorrectly(
            input: '
            /** @autodoc GET /api/test/route6 */
            type TypeWithExistingName = number
            ',

            expected: "
            /** @autodoc GET /api/test/route6 */
            type TypeWithExistingName = {
                data: Array<'abc'|123>
            }
            ",
        );
    }

    #[Test]
    public function controllerMethodWithObjectHavingArrayOfObjects(): void
    {
        $this->assertTypeScriptGeneratedCorrectly(
            input: '
            /** @autodoc GET /api/test/closure4 */
            ',

            expected: '
            /** @autodoc GET /api/test/closure4 */
            export interface Closure4Response {
                list: Array<{
                    id: number
                    name: string
                }>
            }
            ',
        );
    }

    #[Test]
    public function controllerMethodWithTupleResponse(): void
    {
        $this->assertTypeScriptGeneratedCorrectly(
            input: '
            /** @autodoc GET /api/test/route10 */
            interface TestResponseInterface {
                {{{}}}
            }
            ',

            expected: '
            /** @autodoc GET /api/test/route10 */
            type TestResponseInterface = [0|1, string]
            ',
        );
    }

    #[Test]
    public function controllerMethodWithIntersectionType(): void
    {
        $this->assertTypeScriptGeneratedCorrectly(
            input: '
            /** @autodoc GET /api/test/route16 */
            type TypeWithExistingName = number
            ',

            expected: '
            /** @autodoc GET /api/test/route16 */
            type TypeWithExistingName = {
                created_at: string
            }
            ',
        );
    }

    #[Test]
    public function controllerMethodWithIntersectionAndUnionType(): void
    {
        $this->assertTypeScriptGeneratedCorrectly(
            input: '
            /** @autodoc GET /api/test/route18 */
            ',

            expected: '
            /** @autodoc GET /api/test/route18 */
            export type Route18Response = {
                id: number
                name: string
                uuid: {
                    x?: number
                }|string
            }
            ',
        );
    }

    #[Test]
    public function controllerMethodRequestBody(): void
    {
        $this->assertTypeScriptGeneratedCorrectly(
            input: '
            /** @autodoc GET /api/test/route14 request */
            ',

            expected: '
            /** @autodoc GET /api/test/route14 request */
            export interface Route14Request {
                data: Record<string, {
                    id: number
                    name?: string
                }>
            }
            ',
        );
    }


    private function assertTypeScriptGeneratedCorrectly(string $input, string $expected): void
    {
        $scope = new Scope($this->loadConfig());
        $tsFile = new TypeScriptFile;

        $tsFile->lines = explode("\n", str_replace("\r\n", "\n", $input));

        $tsFile->processAutodocTags($scope);

        $result = implode("\n", $tsFile->lines);
        $expected = str_replace("\r\n", "\n", $expected);

        $this->assertEquals($expected, $result);
    }
}
