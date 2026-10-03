<?php
declare(strict_types=1);

namespace Meraki\MessageFormat\Error;

use DomainException;

/**
 * The source is not well-formed.
 *
 * The spec's first error category, and the one that must be reported ahead of every other: a
 * message that does not parse has no data model, so nothing later in the pipeline has anything
 * to say about it.
 */
final class SyntaxError extends DomainException implements MessageFormatError
{
	public static function illFormedInput(string $why): self
	{
		return new self('The message is not well-formed UTF-8: ' . $why . '.');
	}
}
