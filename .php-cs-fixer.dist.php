<?php
declare(strict_types=1);

/**
 * Formatting, fixed automatically. Only rules that cannot change behaviour.
 *
 * This is meraki/schema's configuration minus the three custom fixers — Meraki/grouped_imports,
 * Meraki/no_blank_line_after_opening_tag and Meraki/parenthesized_ternary_condition — which
 * live in that package's tools/CodeStyle and are not worth vendoring a second copy of. The
 * conventions they enforce still apply here and are written by hand; if a third package ever
 * wants them, they belong in a meraki/code-style package rather than in a third copy.
 *
 * The rules that must never be enabled are documented at length in meraki/schema's copy. The
 * short list: no_unneeded_control_parentheses, no_useless_concat_operator, strict_comparison,
 * native_function_invocation, ordered_imports, ordered_class_elements, binary_operator_spaces
 * with its default config, and the phpdoc_* reflow rules.
 */

$finder = PhpCsFixer\Finder::create()
	->in([__DIR__ . '/src', __DIR__ . '/tests', __DIR__ . '/tools'])
	// Upstream's fixtures and the generated tables are not ours to format.
	->exclude(['conformance'])
	->notPath('Plural/Data/CardinalRules.php')
	->notPath('Plural/Data/OrdinalRules.php');

return (new PhpCsFixer\Config())
	->setFinder($finder)
	->setIndent("\t")
	->setLineEnding("\n")
	->setRiskyAllowed(false)
	->setRules([
		'@PSR12' => true,

		// declare(strict_types=1) goes on line 2, as in every other Meraki package.
		'blank_line_after_opening_tag' => false,

		'method_argument_space' => ['on_multiline' => 'ignore'],
		'function_declaration' => ['closure_fn_spacing' => 'none'],
		'class_definition' => ['space_before_parenthesis' => false],

		'no_unused_imports' => true,
		'single_import_per_statement' => true,

		'explicit_string_variable' => true,
		'simple_to_complex_string_variable' => true,
		'single_quote' => true,
		'concat_space' => ['spacing' => 'one'],

		// '=>' => null preserves the aligned tables in the data providers.
		'binary_operator_spaces' => ['default' => 'single_space', 'operators' => ['=>' => null]],
		'trailing_comma_in_multiline' => true,

		'indentation_type' => true,
		'line_ending' => true,
		'phpdoc_indent' => true,
		'single_blank_line_at_eof' => true,
	]);
