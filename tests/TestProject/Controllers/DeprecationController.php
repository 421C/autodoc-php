<?php declare(strict_types=1);

namespace AutoDoc\Tests\TestProject\Controllers;

use AutoDoc\Tests\Attributes\ExpectedOperationSchema;
use Deprecated;

/**
 * Tests for operation-level deprecation from PHPDoc tags and the native attribute.
 */
class DeprecationController
{
    /**
     * Deprecated with a reason
     *
     * @deprecated Use the v2 endpoint instead.
     */
    #[ExpectedOperationSchema('showValuesForScalarTypes', [
        'summary' => 'Deprecated with a reason',
        'deprecated' => true,
        'x-deprecated-description' => 'Use the v2 endpoint instead.',
        'responses' => [
            200 => [
                'content' => [
                    'text/plain' => [
                        'schema' => [
                            'type' => 'boolean',
                        ],
                    ],
                ],
                'description' => '',
            ],
        ],
    ])]
    public function phpDocTagWithDescription(): bool
    {
        return true;
    }


    /**
     * @deprecated
     */
    #[ExpectedOperationSchema('showValuesForScalarTypes', [
        'deprecated' => true,
        'responses' => [
            200 => [
                'content' => [
                    'text/plain' => [
                        'schema' => [
                            'type' => 'boolean',
                        ],
                    ],
                ],
                'description' => '',
            ],
        ],
    ])]
    public function phpDocTagWithoutDescription(): bool
    {
        return true;
    }


    #[Deprecated(message: 'Use the v2 endpoint instead.', since: '2.1')]
    #[ExpectedOperationSchema('showValuesForScalarTypes', [
        'deprecated' => true,
        'x-deprecated-description' => 'Since 2.1: Use the v2 endpoint instead.',
        'responses' => [
            200 => [
                'content' => [
                    'text/plain' => [
                        'schema' => [
                            'type' => 'boolean',
                        ],
                    ],
                ],
                'description' => '',
            ],
        ],
    ])]
    public function nativeAttributeWithMessageAndSince(): bool
    {
        return true;
    }


    #[Deprecated]
    #[ExpectedOperationSchema('showValuesForScalarTypes', [
        'deprecated' => true,
        'responses' => [
            200 => [
                'content' => [
                    'text/plain' => [
                        'schema' => [
                            'type' => 'boolean',
                        ],
                    ],
                ],
                'description' => '',
            ],
        ],
    ])]
    public function nativeAttributeWithoutArguments(): bool
    {
        return true;
    }


    /**
     * @deprecated Described by the PHPDoc tag.
     */
    #[Deprecated(message: 'Described by the native attribute.')]
    #[ExpectedOperationSchema('showValuesForScalarTypes', [
        'deprecated' => true,
        'x-deprecated-description' => 'Described by the PHPDoc tag.',
        'responses' => [
            200 => [
                'content' => [
                    'text/plain' => [
                        'schema' => [
                            'type' => 'boolean',
                        ],
                    ],
                ],
                'description' => '',
            ],
        ],
    ])]
    public function phpDocTagOverridesNativeAttribute(): bool
    {
        return true;
    }
}
