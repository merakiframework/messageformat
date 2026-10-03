<?php
declare(strict_types=1);

namespace Meraki\MessageFormat\Model;

use LogicException;

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
 * makes them optional and a reader cannot tell an absent map from an empty one. `declarations`
 * is **not** omitted when empty, because the schema requires the key.
 */
final class Serializer
{
	/** @return array<string, mixed> */
	public static function toArray(Message $message): array
	{
		if ($message instanceof SelectMessage) {
			return [
				'type' => 'select',
				'declarations' => self::declarations($message->declarations()),
				'selectors' => array_map(self::operand(...), $message->selectors),
				'variants' => array_map(self::variant(...), $message->variants),
			];
		}

		if (!$message instanceof PatternMessage) {
			// The interface has exactly these two implementations, and a third would be a
			// programming error rather than a message somebody wrote. Said out loud rather
			// than narrowed by elimination, which no analyser can check.
			throw new LogicException(sprintf('There is no serialisation for %s.', $message::class));
		}

		return [
			'type' => 'message',
			'declarations' => self::declarations($message->declarations()),
			'pattern' => self::pattern($message->pattern),
		];
	}

	/**
	 * @param list<InputDeclaration|LocalDeclaration> $declarations
	 * @return list<array<string, mixed>>
	 */
	private static function declarations(array $declarations): array
	{
		$out = [];

		foreach ($declarations as $declaration) {
			$out[] = [
				'type' => $declaration instanceof InputDeclaration ? 'input' : 'local',
				'name' => $declaration->name,
				'value' => self::expression($declaration->value),
			];
		}

		return $out;
	}

	/** @return array<string, mixed> */
	private static function variant(Variant $variant): array
	{
		return [
			'keys' => array_map(self::key(...), $variant->keys),
			'value' => self::pattern($variant->value),
		];
	}

	/** @return array<string, mixed> */
	private static function key(Literal|CatchAll $key): array
	{
		// The catch-all's `value` is optional in the schema and carries nothing, so it is left
		// out: `{"type": "*"}` is the whole of it.
		return $key instanceof CatchAll ? ['type' => '*'] : self::operand($key);
	}

	/**
	 * @param list<string|Expression|Markup> $pattern
	 * @return list<string|array<string, mixed>>
	 */
	private static function pattern(array $pattern): array
	{
		return array_map(self::element(...), $pattern);
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
