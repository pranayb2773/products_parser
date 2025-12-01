<?php

$finder = new PhpCsFixer\Finder()
    ->in(__DIR__)
    ->exclude('vendor')
    ->name('*.php')
    ->ignoreDotFiles(true)
    ->ignoreVCS(true);

return new PhpCsFixer\Config()
    ->setRiskyAllowed(true) // REQUIRED for strict_types and mb_str_functions
    ->setRules([
        '@PSR12' => true,

        // --- Your Strict Rules ---
        'array_push' => true,
        'backtick_to_shell_exec' => true,
        'date_time_immutable' => true,
        'declare_strict_types' => true,
        'lowercase_keywords' => true,
        'lowercase_static_reference' => true,
        'explicit_string_variable' => true,
        'final_class' => true,
        'final_internal_class' => true,
        'final_public_method_for_abstract_class' => true,
        'fully_qualified_strict_types' => true,
        'global_namespace_import' => [
            'import_classes' => true,
            'import_constants' => true,
            'import_functions' => true
        ],
        'mb_str_functions' => true,
        'modernize_types_casting' => true,
        'new_with_parentheses' => false,
        'no_extra_blank_lines' => true,
        'no_superfluous_elseif' => true,
        'no_useless_else' => true,
        'no_multiple_statements_per_line' => true,
        'ordered_class_elements' => [
            'order' => [
                'use_trait', 'case', 'constant', 'constant_public', 'constant_protected',
                'constant_private', 'property_public', 'property_protected', 'property_private',
                'construct', 'destruct', 'magic', 'phpunit', 'method_abstract', 'method_public_static',
                'method_public', 'method_protected_static', 'method_protected', 'method_private_static',
                'method_private'
            ],
            'sort_algorithm' => 'none'
        ],
        'ordered_interfaces' => true,
        'ordered_traits' => true,
        'protected_to_private' => true,
        'self_accessor' => true,
        'self_static_accessor' => true,
        'single_quote' => true,
        'strict_comparison' => true,
        'visibility_required' => true,

        // --- Visual / Formatting Extras ---
        'array_syntax' => ['syntax' => 'short'],
        'ordered_imports' => ['sort_algorithm' => 'alpha'],
        'no_unused_imports' => true,
        'trailing_comma_in_multiline' => true,
    ])
    ->setFinder($finder);
