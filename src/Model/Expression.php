<?php
declare(strict_types=1);

namespace Meraki\MessageFormat\Model;

/**
 * `{"type": "expression", "arg": ..., "function": ..., "attributes": {...}}`
 *
 * The data model requires at least one of `arg` and `function`, which is what makes the three
 * ABNF productions — literal-expression, variable-expression, function-expression — one node
 * here rather than three. Both being null is unrepresentable in valid MF2 and the parser never
 * builds it.
 */
final class Expression
{
	/** @param array<string, Literal|true> $attributes */
	public function __construct(
		public readonly Literal|VariableRef|null $arg = null,
		public readonly ?FunctionRef $function = null,
		public readonly array $attributes = [],
	) {
	}
}
