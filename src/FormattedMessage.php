<?php
declare(strict_types=1);

namespace Meraki\MessageFormat;

use Meraki\MessageFormat\Error\MessageFormatError;

/**
 * What came out, and what went wrong on the way.
 *
 * Both, because the specification asks for both: an implementation "MUST enable a user to get a
 * formatted result" for any valid message, and "MUST provide a mechanism to discover and
 * identify at least one of the errors". A formatter returning only the string would satisfy the
 * first and quietly fail the second — the fallback text is evidence that something went wrong
 * but not of what.
 */
final class FormattedMessage
{
	/** @param list<MessageFormatError> $errors in the order they occurred */
	public function __construct(
		public readonly string $text,
		public readonly array $errors = [],
	) {
	}

	public function hasErrors(): bool
	{
		return $this->errors !== [];
	}
}
