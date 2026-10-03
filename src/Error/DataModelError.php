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
 * Note what is *not* here. errors.md names Duplicate Option Name for options and nothing for
 * attributes, so a repeated attribute is not an error and must not be treated as one — see
 * {@see \Meraki\MessageFormat\Syntax\Parser}.
 */
final class DataModelError extends DomainException implements MessageFormatError
{
	private function __construct(string $message, private readonly ErrorType $type)
	{
		parent::__construct($message);
	}

	/** The identifier includes its namespace: `ns:opt` and `opt` are different options. */
	public static function duplicateOptionName(string $name): self
	{
		return new self(
			sprintf('The option "%s" is set more than once in one expression.', $name),
			ErrorType::DuplicateOptionName,
		);
	}

	public function type(): ErrorType
	{
		return $this->type;
	}
}
