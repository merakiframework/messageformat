<?php
declare(strict_types=1);

namespace Meraki\MessageFormat\Error;

use DomainException;

/**
 * The message parses, and what it says is not a valid message.
 *
 * The specification's second category, and separate from {@see SyntaxError} for a reason the
 * conformance suite is strict about: `bad {:placeholder option=x option=x}` is well-formed —
 * every brace matches and every production is satisfied — and is still invalid, because an
 * option name appears twice in one expression. The fixtures expect `duplicate-option-name` for
 * it, so reporting a syntax error there is wrong even though the message is equally rejected.
 *
 * A DomainException for the same reason SyntaxError is one: it is a statement about the input
 * that was handed over, not about the state at the moment of formatting.
 *
 * Five of the six are raised by {@see \Meraki\MessageFormat\Model\Validator}. The sixth,
 * Duplicate Option Name, is raised by the parser instead — see its factory below.
 */
final class DataModelError extends DomainException implements MessageFormatError
{
	private function __construct(string $message, private readonly ErrorType $type)
	{
		parent::__construct($message);
	}

	/**
	 * The identifier includes its namespace: `ns:opt` and `opt` are different options.
	 *
	 * Raised while parsing rather than while validating, because the options map is keyed by
	 * name and a duplicate therefore cannot survive into the model to be found afterwards. It
	 * has to be caught as the map is built.
	 */
	public static function duplicateOptionName(string $name): self
	{
		return new self(
			sprintf('The option "%s" is set more than once in one expression.', $name),
			ErrorType::DuplicateOptionName,
		);
	}

	public static function variantKeyMismatch(int $selectors, int $keys): self
	{
		return new self(
			sprintf(
				'A variant has %d key(s) but the matcher has %d selector(s). Every variant needs'
					. ' exactly one key per selector.',
				$keys,
				$selectors,
			),
			ErrorType::VariantKeyMismatch,
		);
	}

	public static function missingFallbackVariant(): self
	{
		return new self(
			'The matcher has no variant whose keys are all "*". Without one there is a value that'
				. ' would match nothing, and a message must always be able to format.',
			ErrorType::MissingFallbackVariant,
		);
	}

	public static function missingSelectorAnnotation(string $name): self
	{
		return new self(
			sprintf(
				'The selector "$%s" does not reach a declaration with a function, directly or'
					. ' through other declarations. Selection needs one to decide how to compare'
					. ' the value, and a selector cannot carry a function itself.',
				$name,
			),
			ErrorType::MissingSelectorAnnotation,
		);
	}

	public static function duplicateDeclaration(string $name): self
	{
		return new self(
			sprintf(
				'The variable "$%s" is declared more than once. Note that using a variable'
					. ' declares it implicitly, so declaring it after use -- or referring to it in'
					. ' its own declaration -- is also a redeclaration.',
				$name,
			),
			ErrorType::DuplicateDeclaration,
		);
	}

	public static function duplicateVariant(): self
	{
		return new self(
			'Two variants use the same list of keys, so one of them could never be chosen.'
				. ' Quoting does not make a key different: `foo` and `|foo|` are one key.',
			ErrorType::DuplicateVariant,
		);
	}

	public function type(): ErrorType
	{
		return $this->type;
	}
}
