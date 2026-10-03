<?php
declare(strict_types=1);

namespace Meraki\MessageFormat\Error;

use RuntimeException;

/**
 * An expression could not be resolved against the arguments and functions on hand.
 *
 * A RuntimeException rather than a DomainException because nothing is wrong with the message:
 * the same source formats without complaint once the variable is supplied or the function is
 * registered. What was wrong was the state at the moment of formatting.
 *
 * None of these stop a format in fallback mode. The specification requires a result for any
 * valid message, so each of these contributes a fallback string and the rest of the pattern
 * carries on.
 */
final class ResolutionError extends RuntimeException implements MessageFormatError
{
	private function __construct(string $message, private readonly ErrorType $type)
	{
		parent::__construct($message);
	}

	public static function unresolvedVariable(string $name): self
	{
		return new self(
			sprintf('No value was supplied for the variable "%s".', $name),
			ErrorType::UnresolvedVariable,
		);
	}

	public static function unknownFunction(string $name): self
	{
		return new self(
			sprintf('No handler is registered for the function ":%s".', $name),
			ErrorType::UnknownFunction,
		);
	}

	public static function badOperand(string $why): self
	{
		return new self('That operand cannot be used here: ' . $why . '.', ErrorType::BadOperand);
	}

	public function type(): ErrorType
	{
		return $this->type;
	}
}
