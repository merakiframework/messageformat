<?php
declare(strict_types=1);

namespace Meraki\MessageFormat\Model;

use Meraki\MessageFormat\Error\DataModelError;
use Meraki\MessageFormat\Icu\Normalisation;

/**
 * The validity gate: five of the specification's six Data Model Errors.
 *
 * Separate from the parser because these are not questions about the text. Every message this
 * refuses is well-formed — braces matched, productions satisfied — and still not a valid
 * message. The parser answers "can this be read"; this answers "does what it says make sense".
 *
 * The sixth, Duplicate Option Name, stays in the parser. An options map is keyed by name, so a
 * duplicate cannot survive into the model for this to find; it has to be caught while the map is
 * being built. That is a real constraint rather than a tidiness failure, and the alternative —
 * carrying duplicates through the model so they can be rejected later — would mean every
 * consumer of an options map handling a state no valid message can have.
 *
 * ## Names are compared under NFC
 *
 * Throughout. The specification compares names "as-if normalized", and syntax.json pins it: a
 * variable written `D` + U+0323 + U+0307 is the same variable as one written U+1E0C + U+0307.
 * Comparing bytes would make two spellings of one name two different names, and a redeclaration
 * would slip through.
 */
final class Validator
{
	/** Deep enough for any real declaration chain, and a stop if one somehow cycles. */
	private const MAX_CHAIN = 32;

	/** @throws DataModelError */
	public static function check(Message $message): void
	{
		self::declarationsAreUnique($message->declarations());

		if (!$message instanceof SelectMessage) {
			return;
		}

		// Ordered so that each check can assume the previous one passed. Key counts come before
		// the fallback check, because "a variant whose keys are all catch-all" is a question
		// worth asking only once every variant has the right number of keys.
		self::selectorsAreAnnotated($message);
		self::variantsHaveOneKeyPerSelector($message);
		self::variantsAreDistinct($message);
		self::aFallbackVariantExists($message);
	}

	/**
	 * Duplicate Declaration, including the half the name does not suggest.
	 *
	 * errors.md: *"an input variable is implicitly declared when it is first used, so explicitly
	 * declaring it after such use is also an error."* So every variable an expression refers to
	 * joins the declared set before the declaration's own name is considered — which is what
	 * makes `.local $foo = {$bar}` followed by `.local $bar = {42}` an error, and
	 * `.local $foo = {$foo}` an error against itself.
	 *
	 * @param list<InputDeclaration|LocalDeclaration> $declarations
	 * @throws DataModelError
	 */
	private static function declarationsAreUnique(array $declarations): void
	{
		$declared = [];

		foreach ($declarations as $declaration) {
			foreach (self::variablesUsedBy($declaration) as $used) {
				$declared[Normalisation::nfc($used)] = true;
			}

			$name = Normalisation::nfc($declaration->name);

			if (array_key_exists($name, $declared)) {
				throw DataModelError::duplicateDeclaration($declaration->name);
			}

			$declared[$name] = true;
		}
	}

	/**
	 * The variables a declaration's expression refers to.
	 *
	 * An input declaration's operand is excluded, because it **is** the declared variable:
	 * `.input {$x}` declares `x` and annotates the external argument of that name. Counting it
	 * as a use would make every input declaration a self-reference, and so an error, which would
	 * make the production unusable.
	 *
	 * @return list<string>
	 */
	private static function variablesUsedBy(InputDeclaration|LocalDeclaration $declaration): array
	{
		$used = [];
		$expression = $declaration->value;
		$operand = $expression->arg;

		if ($declaration instanceof LocalDeclaration && $operand instanceof VariableRef) {
			$used[] = $operand->name;
		}

		$function = $expression->function;

		foreach ($function === null ? [] : $function->options as $value) {
			if ($value instanceof VariableRef) {
				$used[] = $value->name;
			}
		}

		return $used;
	}

	/** @throws DataModelError */
	private static function selectorsAreAnnotated(SelectMessage $message): void
	{
		$byName = [];

		foreach ($message->declarations() as $declaration) {
			$byName[Normalisation::nfc($declaration->name)] = $declaration;
		}

		foreach ($message->selectors as $selector) {
			if (!self::reachesAFunction($selector->name, $byName, 0)) {
				throw DataModelError::missingSelectorAnnotation($selector->name);
			}
		}
	}

	/**
	 * Whether a selector reaches a function, "directly or indirectly".
	 *
	 * The chain is followed because the specification says it is: a selector may point at a
	 * declaration that points at another, and only a function anywhere along it counts.
	 *
	 * An input declaration terminates the walk whatever its operand says — its operand is the
	 * declared variable, so recursing into it would loop forever on `.input {$x}`.
	 *
	 * @param array<string, InputDeclaration|LocalDeclaration> $byName
	 */
	private static function reachesAFunction(string $name, array $byName, int $depth): bool
	{
		if ($depth >= self::MAX_CHAIN) {
			// Unreachable for a message that passed the duplicate-declaration check, since a
			// cycle requires using a variable before declaring it. Here so that a future change
			// to that check cannot turn into a hang.
			return false;
		}

		$declaration = $byName[Normalisation::nfc($name)] ?? null;

		if ($declaration === null) {
			return false;
		}

		if ($declaration->value->function !== null) {
			return true;
		}

		if ($declaration instanceof InputDeclaration) {
			return false;
		}

		$operand = $declaration->value->arg;

		return $operand instanceof VariableRef
			&& self::reachesAFunction($operand->name, $byName, $depth + 1);
	}

	/** @throws DataModelError */
	private static function variantsHaveOneKeyPerSelector(SelectMessage $message): void
	{
		$expected = count($message->selectors);

		foreach ($message->variants as $variant) {
			if (count($variant->keys) !== $expected) {
				throw DataModelError::variantKeyMismatch($expected, count($variant->keys));
			}
		}
	}

	/** @throws DataModelError */
	private static function variantsAreDistinct(SelectMessage $message): void
	{
		$seen = [];

		foreach ($message->variants as $variant) {
			$signature = self::signatureOf($variant->keys);

			if (array_key_exists($signature, $seen)) {
				throw DataModelError::duplicateVariant();
			}

			$seen[$signature] = true;
		}
	}

	/**
	 * A comparable form of one variant's key list.
	 *
	 * JSON rather than a joined string, because a literal key may contain any character
	 * including whatever separator a join would pick. The catch-all is a one-element entry and a
	 * literal is a two-element one, so `|*|` and `*` cannot collide — which matters, because
	 * they are different keys and a matcher using both is not repeating one.
	 *
	 * @param list<Literal|CatchAll> $keys
	 */
	private static function signatureOf(array $keys): string
	{
		$parts = [];

		foreach ($keys as $key) {
			$parts[] = $key instanceof CatchAll ? ['*'] : ['literal', Normalisation::nfc($key->value)];
		}

		$encoded = json_encode($parts);

		if ($encoded === false) {
			// Only invalid UTF-8 can do this, and the parser has already refused that.
			return serialize($parts);
		}

		return $encoded;
	}

	/** @throws DataModelError */
	private static function aFallbackVariantExists(SelectMessage $message): void
	{
		foreach ($message->variants as $variant) {
			if (self::isAllCatchAll($variant)) {
				return;
			}
		}

		throw DataModelError::missingFallbackVariant();
	}

	private static function isAllCatchAll(Variant $variant): bool
	{
		foreach ($variant->keys as $key) {
			if (!$key instanceof CatchAll) {
				return false;
			}
		}

		// Key counts have already been checked against the selector count, so a variant that is
		// all catch-all here is one that matches everything.
		return true;
	}
}
