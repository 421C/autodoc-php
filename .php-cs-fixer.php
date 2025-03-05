<?php declare(strict_types=1);

use PhpCsFixer\Config;
use PhpCsFixer\Finder;

return (new Config)
    ->setFinder(
        Finder::create()
            ->in([
                __DIR__ . '/src',
            ])
            ->name('*.php')
            ->ignoreDotFiles(true)
            ->ignoreVCS(true)
    )
    ->setRiskyAllowed(true)
    ->setRules([
        '@PSR12' => true,
        'array_indentation' => true,
        'array_syntax' => ['syntax' => 'short'],
        'blank_line_after_namespace' => true,
        'blank_line_after_opening_tag' => false,
        'blank_line_before_statement' => [
            'statements' => ['continue', 'declare', 'do', 'for', 'foreach', 'if', 'phpdoc', 'return', 'switch', 'throw', 'try', 'while', 'yield', 'yield_from'],
        ],
        'blank_lines_before_namespace' => ['min_line_breaks' => 2, 'max_line_breaks' => 2],
        'braces_position' => [
            'control_structures_opening_brace' => 'same_line',
            'functions_opening_brace' => 'next_line_unless_newline_at_signature_end',
            'anonymous_functions_opening_brace' => 'same_line',
            'classes_opening_brace' => 'next_line_unless_newline_at_signature_end',
            'anonymous_classes_opening_brace' => 'next_line_unless_newline_at_signature_end',
            'allow_single_line_empty_anonymous_classes' => false,
            'allow_single_line_anonymous_functions' => true,
        ],
        'cast_spaces' => true,
        'declare_strict_types' => true,
        'elseif' => false,
        'lambda_not_used_import' => true,
        'list_syntax' => ['syntax' => 'short'],
        'method_chaining_indentation' => true,
        'new_with_parentheses' => ['anonymous_class' => false, 'named_class' => false],
        'no_break_comment' => false,
        'no_empty_phpdoc' => true,
        'no_extra_blank_lines' => ['tokens' => ['attribute', 'case', 'switch', 'use']],
        'no_spaces_around_offset' => true,
        'no_trailing_comma_in_singleline' => false,
        'no_unused_imports' => true,
        'no_useless_return' => true,
        'no_whitespace_before_comma_in_array' => true,
        'ordered_imports' => ['sort_algorithm' => 'alpha', 'imports_order' => ['const', 'class', 'function']],
        'phpdoc_indent' => true,
        'phpdoc_no_useless_inheritdoc' => true,
        'phpdoc_scalar' => true,
        'phpdoc_trim' => true,
        'single_line_after_imports' => false,
        'single_line_comment_spacing' => true,
        'single_line_comment_style' => [
            'comment_types' => ['hash'],
        ],
        'single_line_empty_body' => true,
        'single_quote' => true,
        'single_space_around_construct' => true,
        'single_trait_insert_per_statement' => false,
        'trailing_comma_in_multiline' => true,
        'trim_array_spaces' => true,
        'whitespace_after_comma_in_array' => true,
    ]);
