<?php
declare(strict_types=1);

namespace Meraki\MessageFormat\Error;

use Meraki\MessageFormat\Model\Expression;
use Meraki\MessageFormat\Model\Literal;
use Meraki\MessageFormat\Model\VariableRef;

/**
 * What a placeholder contributes when it cannot be resolved.
 *
 * The specification's table, in one place, because it is the output a reader actually sees when
 * something is wrong and getting it almost right is worse than getting it wrong: `{$name}` tells
 * somebody which value was missing, while `{???}` tells them nothing and `name` looks like it
 * worked.
 *
 * Verified against fallback.json, which pins the three shapes and one surprise — a literal
 * operand comes back **quoted whether or not it was quoted in the source**, and re-escaped. So
 * `{42 :unknown}` falls back to `{|42|}`, not `{42}`.
 */
final class FallbackString
{
	/** The whole placeholder, braces included: what gets concatenated into the output. */
	public static function for(Expression $expression): string
	{
		return '{' . self::sourceOf($expression) . '}';
	}

	/**
	 * The part inside the braces, which is also what a `fallback` formatted part carries as its
	 * `source`.
	 */
	public static function sourceOf(Expression $expression): string
	{
		$arg = $expression->arg;

		if ($arg instanceof VariableRef) {
			return '$' . $arg->name;
		}

		if ($arg instanceof Literal) {
			return '|' . self::escape($arg->value) . '|';
		}

		// No operand, so this is a function-expression and the function is what to name.
		$function = $expression->function;

		// The data model requires an operand or a function, so a parsed expression always has
		// one here. Written as a check rather than a nullsafe so that the guarantee is visible
		// instead of being asserted by punctuation.
		return $function === null ? '' : ':' . $function->name;
	}

	/**
	 * The two characters a quoted literal cannot carry raw.
	 *
	 * Order matters: escaping the pipe first would then have its own backslash doubled by the
	 * second pass, turning `a|b` into `a\\|b`.
	 */
	private static function escape(string $value): string
	{
		return str_replace(['\\', '|'], ['\\\\', '\\|'], $value);
	}
}
