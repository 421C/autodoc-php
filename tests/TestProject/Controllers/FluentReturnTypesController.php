<?php declare(strict_types=1);

namespace AutoDoc\Tests\TestProject\Controllers;

use AutoDoc\Tests\Attributes\ExpectedOperationSchema;
use AutoDoc\Tests\TestProject\Entities\FluentBuilder;
use AutoDoc\Tests\TestProject\Entities\FluentBuilderChild;

class FluentReturnTypesController
{
    #[ExpectedOperationSchema('showValuesForScalarTypes', [
        'responses' => [
            200 => [
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'properties' => [
                                'docSelf' => [
                                    'properties' => [
                                        'baseValue' => [
                                            'type' => 'integer',
                                        ],
                                        'childValue' => [
                                            'type' => 'string',
                                        ],
                                    ],
                                    'required' => [
                                        'childValue',
                                        'baseValue',
                                    ],
                                    'type' => 'object',
                                ],
                                'docStatic' => [
                                    'properties' => [
                                        'baseValue' => [
                                            'type' => 'integer',
                                        ],
                                        'childValue' => [
                                            'type' => 'string',
                                        ],
                                    ],
                                    'required' => [
                                        'childValue',
                                        'baseValue',
                                    ],
                                    'type' => 'object',
                                ],
                                'docThis' => [
                                    'properties' => [
                                        'baseValue' => [
                                            'type' => 'integer',
                                        ],
                                        'childValue' => [
                                            'type' => 'string',
                                        ],
                                    ],
                                    'required' => [
                                        'childValue',
                                        'baseValue',
                                    ],
                                    'type' => 'object',
                                ],
                                'docThisWithoutNativeType' => [
                                    'properties' => [
                                        'baseValue' => [
                                            'type' => 'integer',
                                        ],
                                        'childValue' => [
                                            'type' => 'string',
                                        ],
                                    ],
                                    'required' => [
                                        'childValue',
                                        'baseValue',
                                    ],
                                    'type' => 'object',
                                ],
                                'nativeStatic' => [
                                    'properties' => [
                                        'baseValue' => [
                                            'type' => 'integer',
                                        ],
                                        'childValue' => [
                                            'type' => 'string',
                                        ],
                                    ],
                                    'required' => [
                                        'childValue',
                                        'baseValue',
                                    ],
                                    'type' => 'object',
                                ],
                            ],
                            'required' => [
                                'docThis',
                                'docThisWithoutNativeType',
                                'docStatic',
                                'docSelf',
                                'nativeStatic',
                            ],
                            'type' => 'object',
                        ],
                    ],
                ],
                'description' => '',
            ],
        ],
    ])]
    public function receiverReturningMethods(): mixed
    {
        $builder = new FluentBuilderChild;

        return [
            'docThis' => $builder->withThis(),
            'docThisWithoutNativeType' => $builder->withThisAndNoNativeType(),
            'docStatic' => $builder->withDocStatic(),
            'docSelf' => $builder->withDocSelf(),
            'nativeStatic' => $builder->withNativeStatic(),
        ];
    }


    #[ExpectedOperationSchema('showValuesForScalarTypes', [
        'responses' => [
            200 => [
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'properties' => [
                                'explicitClass' => [
                                    'properties' => [
                                        'baseValue' => [
                                            'type' => 'integer',
                                        ],
                                    ],
                                    'required' => [
                                        'baseValue',
                                    ],
                                    'type' => 'object',
                                ],
                                'nativeParent' => [
                                    'properties' => [
                                        'baseValue' => [
                                            'type' => 'integer',
                                        ],
                                    ],
                                    'required' => [
                                        'baseValue',
                                    ],
                                    'type' => 'object',
                                ],
                                'nativeSelf' => [
                                    'properties' => [
                                        'baseValue' => [
                                            'type' => 'integer',
                                        ],
                                    ],
                                    'required' => [
                                        'baseValue',
                                    ],
                                    'type' => 'object',
                                ],
                            ],
                            'required' => [
                                'nativeSelf',
                                'nativeParent',
                                'explicitClass',
                            ],
                            'type' => 'object',
                        ],
                    ],
                ],
                'description' => '',
            ],
        ],
    ])]
    public function declaringClassReturningMethods(): mixed
    {
        $builder = new FluentBuilderChild;

        return [
            'nativeSelf' => $builder->withNativeSelf(),
            'nativeParent' => $builder->withNativeParent(),
            'explicitClass' => $builder->withExplicitClass(),
        ];
    }


    #[ExpectedOperationSchema('showValuesForScalarTypes', [
        'responses' => [
            200 => [
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'properties' => [
                                'baseValue' => [
                                    'type' => 'integer',
                                ],
                            ],
                            'required' => [
                                'baseValue',
                            ],
                            'type' => 'object',
                        ],
                    ],
                ],
                'description' => '',
            ],
        ],
    ])]
    public function dynamicMethodReturningThis(): mixed
    {
        $builder = new FluentBuilder;

        return $builder->withMagic();
    }
}
