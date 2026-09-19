<?php

declare(strict_types=1);

use Rector\CodeQuality\Rector\BooleanOr\RepeatedOrEqualToInArrayRector;
use Rector\CodeQuality\Rector\Class_\CompleteDynamicPropertiesRector;
use Rector\CodeQuality\Rector\ClassMethod\LocallyCalledStaticMethodToNonStaticRector;
use Rector\CodeQuality\Rector\Empty_\SimplifyEmptyCheckOnEmptyArrayRector;
use Rector\CodeQuality\Rector\Identical\FlipTypeControlToUseExclusiveTypeRector;
use Rector\CodeQuality\Rector\If_\ArrayExplicitBoolCompareRector;
use Rector\CodeQuality\Rector\If_\ObjectExplicitBoolCompareRector;
use Rector\Config\RectorConfig;
use Rector\EarlyReturn\Rector\If_\RemoveAlwaysElseRector;
use Rector\Php81\Rector\MethodCall\RemoveReflectionSetAccessibleCallsRector;

return RectorConfig::configure()
    ->withPaths([
        __DIR__ . '/src',
    ])
    ->withSkip([
        CompleteDynamicPropertiesRector::class,
        FlipTypeControlToUseExclusiveTypeRector::class,
        LocallyCalledStaticMethodToNonStaticRector::class,
        RemoveAlwaysElseRector::class,
        RemoveReflectionSetAccessibleCallsRector::class,
        RepeatedOrEqualToInArrayRector::class,
        SimplifyEmptyCheckOnEmptyArrayRector::class,
        ArrayExplicitBoolCompareRector::class,
        ObjectExplicitBoolCompareRector::class,
    ])
    ->withPreparedSets(
        codeQuality: true,
        earlyReturn: true,
    )
    ->withPhpSets();
