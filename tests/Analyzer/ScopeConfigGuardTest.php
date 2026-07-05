<?php declare(strict_types=1);

namespace AutoDoc\Tests\Analyzer;

use AutoDoc\Analyzer\Scope;
use AutoDoc\Config;
use AutoDoc\Tests\Traits\LoadsConfig;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The `with*` guards flip config flags around a callback. Analysis is
 * fault-tolerant, so a throwing callback whose flag is not restored in a
 * `finally` leaks the transient mode into the whole remaining run. Flags are
 * seeded with the opposite of the value each guard forces, so a missing
 * restore is observable.
 */
final class ScopeConfigGuardTest extends TestCase
{
    use LoadsConfig;

    private Config $config;

    private Scope $scope;

    protected function setUp(): void
    {
        $this->config = self::loadConfig();
        $this->scope = new Scope($this->config);
    }

    #[Test]
    public function withoutScalarTypeValueMergingRestoresFlagsWhenTheCallbackThrows(): void
    {
        $this->config->data['openapi']['show_values_for_scalar_types'] = false;
        $this->config->data['arrays']['remove_scalar_type_values_when_merging_with_unknown_types'] = true;

        $this->assertGuardLetsThrowPropagate(
            fn (callable $callback) => $this->scope->withoutScalarTypeValueMerging($callback),
        );

        $this->assertFalse($this->config->data['openapi']['show_values_for_scalar_types'] ?? null);
        $this->assertTrue($this->config->data['arrays']['remove_scalar_type_values_when_merging_with_unknown_types'] ?? null);
    }

    #[Test]
    public function withScalarTypeValueMergingRestoresFlagsWhenTheCallbackThrows(): void
    {
        $this->config->data['openapi']['show_values_for_scalar_types'] = true;
        $this->config->data['arrays']['remove_scalar_type_values_when_merging_with_unknown_types'] = false;

        $this->assertGuardLetsThrowPropagate(
            fn (callable $callback) => $this->scope->withScalarTypeValueMerging($callback),
        );

        $this->assertTrue($this->config->data['openapi']['show_values_for_scalar_types'] ?? null);
        $this->assertFalse($this->config->data['arrays']['remove_scalar_type_values_when_merging_with_unknown_types'] ?? null);
    }

    #[Test]
    public function withPartialArraysResolvingAsShapesRestoresItsFlagWhenTheCallbackThrows(): void
    {
        $this->config->data['arrays']['resolve_partial_shapes'] = false;

        $this->assertGuardLetsThrowPropagate(
            fn (callable $callback) => $this->scope->withPartialArraysResolvingAsShapes($callback),
        );

        $this->assertFalse($this->config->data['arrays']['resolve_partial_shapes'] ?? null);
    }

    #[Test]
    public function withDeepShapeInferenceRestoresItsFlagWhenTheCallbackThrows(): void
    {
        $this->config->data['arrays']['deep_shape_inference'] = false;

        $this->assertGuardLetsThrowPropagate(
            fn (callable $callback) => $this->scope->withDeepShapeInference($callback),
        );

        $this->assertFalse($this->config->data['arrays']['deep_shape_inference'] ?? null);
    }

    #[Test]
    public function withShapeMergingRestoresItsFlagsWhenTheCallbackThrows(): void
    {
        $this->config->data['arrays']['merge_shapes_in_type_unions'] = false;
        $this->config->data['objects']['merge_shapes_in_type_unions'] = false;

        $this->assertGuardLetsThrowPropagate(
            fn (callable $callback) => $this->scope->withShapeMerging($callback),
        );

        $this->assertFalse($this->config->data['arrays']['merge_shapes_in_type_unions'] ?? null);
        $this->assertFalse($this->config->data['objects']['merge_shapes_in_type_unions'] ?? null);
    }

    #[Test]
    public function withCoerciveScalarOverlapRestoresItsFlagWhenTheCallbackThrows(): void
    {
        $this->config->data['intersections']['coercive_scalar_overlap'] = false;

        $this->assertGuardLetsThrowPropagate(
            fn (callable $callback) => $this->scope->withCoerciveScalarOverlap($callback),
        );

        $this->assertFalse($this->config->data['intersections']['coercive_scalar_overlap'] ?? null);
    }

    /**
     * @param callable(callable(): never): mixed $runGuard
     */
    private function assertGuardLetsThrowPropagate(callable $runGuard): void
    {
        try {
            $runGuard(function (): never {
                throw new RuntimeException('boom');
            });

            $this->fail('The guard should let the callback exception propagate.');

        } catch (RuntimeException) {
        }
    }
}
