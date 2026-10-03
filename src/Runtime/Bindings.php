<?php
declare(strict_types=1);

namespace Meraki\MessageFormat\Runtime;

use Meraki\MessageFormat\Icu\Normalisation;
use Meraki\MessageFormat\Model\InputDeclaration;
use Meraki\MessageFormat\Model\LocalDeclaration;

/**
 * What names a message can read: its declarations, and the arguments it was given.
 *
 * Both sides are keyed by the **NFC** form of the name, normalised once here rather than at
 * every lookup. The specification compares names "as-if normalized", and syntax.json pins it:
 * a variable written `D` + U+0323 + U+0307 is the same variable as one written U+1E0C + U+0307,
 * and an argument supplied under either spelling answers a read of the other.
 *
 * Declarations win over arguments, and that is not a default-value relationship:
 * `.local $foo = {|bar|}` formats as "bar" even when an argument named `foo` is supplied, per
 * syntax.json[93]. A declaration rebinds the name for the whole message.
 *
 * Holds no resolved values. Evaluation is lazy and memoised by the formatter, because an unused
 * declaration must never be evaluated — syntax.json expects `.input{$x}{{}}` with no arguments
 * to format without a single error.
 */
final class Bindings
{
	/**
	 * @param array<string, InputDeclaration|LocalDeclaration> $declarations keyed by NFC name
	 * @param array<string, mixed> $arguments keyed by NFC name
	 */
	private function __construct(
		private readonly array $declarations,
		private readonly array $arguments,
	) {
	}

	/**
	 * @param list<InputDeclaration|LocalDeclaration> $declarations
	 * @param array<string, mixed> $arguments
	 */
	public static function of(array $declarations, array $arguments): self
	{
		$byName = [];

		foreach ($declarations as $declaration) {
			// A later declaration cannot overwrite an earlier one: that is a Duplicate
			// Declaration, which the validator has already refused.
			$byName[Normalisation::nfc($declaration->name)] = $declaration;
		}

		$normalised = [];

		foreach ($arguments as $name => $value) {
			$normalised[Normalisation::nfc($name)] = $value;
		}

		return new self($byName, $normalised);
	}

	/** @param string $name already in NFC */
	public function declarationFor(string $name): InputDeclaration|LocalDeclaration|null
	{
		return $this->declarations[$name] ?? null;
	}

	/** @param string $name already in NFC */
	public function hasArgument(string $name): bool
	{
		return array_key_exists($name, $this->arguments);
	}

	/** @param string $name already in NFC */
	public function argument(string $name): mixed
	{
		return $this->arguments[$name] ?? null;
	}
}
