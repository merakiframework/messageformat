<?php
declare(strict_types=1);

namespace Meraki\MessageFormat\Model;

/**
 * The model as `spec/data-model/message.json` describes it.
 *
 * One file, so that the specification's shape lives in exactly one place. The nodes themselves
 * stay pure data with no opinion about how they are written down — which is what lets this be
 * the oracle the parser's tests assert against: a test comparing arrays is comparing against
 * the specification, where a test walking getters would only be comparing against this library.
 *
 * It is also the interchange format. The same arrays can be handed to another implementation,
 * and the catalogue package can cache them instead of re-parsing.
 *
 * ### Key order is part of the contract
 *
 * PHP's `===` on arrays compares order, and the tests use it. Emitting `arg` before `function`
 * is therefore load-bearing rather than cosmetic, and the order here follows the order the
 * schema lists the properties in.
 *
 * Empty `options` and `attributes` are omitted rather than emitted as `{}`, because the schema
 * makes them optional and a reader cannot tell an absent map from an empty one.
 */
final class Serializer
{
	/** @return array<string, mixed> */
	public static function toArray(Message $message): array
	{
		return [
			'type' => 'message',
			'declarations' => [],
			'pattern' => array_map(self::element(...), $message->pattern),
		];
	}

	/** @return string|array<string, mixed> */
	private static function element(string|Expression|Markup $element): string|array
	{
		if (is_string($element)) {
			return $element;
		}

		if ($element instanceof Expression) {
			return self::expression($element);
		}

		return self::markup($element);
	}

	/** @return array<string, mixed> */
	private static function expression(Expression $expression): array
	{
		$out = ['type' => 'expression'];

		if ($expression->arg !== null) {
			$out['arg'] = self::operand($expression->arg);
		}

		if ($expression->function !== null) {
			$out['function'] = self::functionRef($expression->function);
		}

		if ($expression->attributes !== []) {
			$out['attributes'] = self::attributes($expression->attributes);
		}

		return $out;
	}

	/** @return array<string, mixed> */
	private static function functionRef(FunctionRef $function): array
	{
		$out = ['type' => 'function', 'name' => $function->name];

		if ($function->options !== []) {
			$out['options'] = self::options($function->options);
		}

		return $out;
	}

	/** @return array<string, mixed> */
	private static function markup(Markup $markup): array
	{
		$out = [
			'type' => 'markup',
			'kind' => $markup->kind->value,
			'name' => $markup->name,
		];

		if ($markup->options !== []) {
			$out['options'] = self::options($markup->options);
		}

		if ($markup->attributes !== []) {
			$out['attributes'] = self::attributes($markup->attributes);
		}

		return $out;
	}

	/** @return array<string, mixed> */
	private static function operand(Literal|VariableRef $operand): array
	{
		if ($operand instanceof Literal) {
			return ['type' => 'literal', 'value' => $operand->value];
		}

		return ['type' => 'variable', 'name' => $operand->name];
	}

	/**
	 * @param array<string, Literal|VariableRef> $options
	 * @return array<string, array<string, mixed>>
	 */
	private static function options(array $options): array
	{
		return array_map(self::operand(...), $options);
	}

	/**
	 * @param array<string, Literal|true> $attributes
	 * @return array<string, array<string, mixed>|true>
	 */
	private static function attributes(array $attributes): array
	{
		$out = [];

		foreach ($attributes as $name => $value) {
			// An attribute with no value is `true`, not an empty literal: `@translate` and
			// `@translate=` mean different things, and the second is not valid syntax.
			$out[$name] = $value === true ? true : self::operand($value);
		}

		return $out;
	}
}
