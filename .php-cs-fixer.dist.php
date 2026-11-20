<?php

declare(strict_types=1);

$finder = PhpCsFixer\Finder::create()
    ->in(__DIR__)
    ->exclude('vendor')
    ->exclude('node_modules')
    ->exclude('.git')
    ->name('*.php');

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(false)
    ->setUsingCache(false)
    ->setIndent('    ')
    ->setLineEnding("\n")
    ->setRules([
        'indentation_type' => true,
        'statement_indentation' => true,
        'array_indentation' => true,
        'braces_position' => true,
        'control_structure_braces' => true,
        'control_structure_continuation_position' => true,
        'single_space_around_construct' => true,
        'binary_operator_spaces' => ['default' => 'single_space'],
        'concat_space' => ['spacing' => 'one'],
        'type_declaration_spaces' => true,
        'function_declaration' => true,
        'method_argument_space' => ['on_multiline' => 'ensure_fully_multiline'],
        'no_spaces_after_function_name' => true,
        'spaces_inside_parentheses' => true,
        'whitespace_after_comma_in_array' => true,
        'no_whitespace_before_comma_in_array' => true,
        'no_multiline_whitespace_around_double_arrow' => true,
        'no_trailing_whitespace' => true,
        'no_trailing_whitespace_in_comment' => true,
        'no_extra_blank_lines' => true,
        'blank_line_after_opening_tag' => true,
        'single_blank_line_at_eof' => true,
        'line_ending' => true,
    ])
    ->setFinder($finder);
