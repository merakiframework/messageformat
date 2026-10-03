<?php
declare(strict_types=1);

namespace Meraki\MessageFormat\Error;

use DomainException;

/**
 * Well-formed, and not implemented yet.
 *
 * Distinct from {@see SyntaxError} on purpose. A complex message — declarations, a matcher, a
 * quoted pattern — is valid MessageFormat 2 that this milestone has not reached, and reporting
 * it as malformed would be a lie about the message. Somebody would go looking for the typo.
 *
 * Every use of this is temporary by construction: each one names a feature, and the milestone
 * that implements that feature deletes its use. When the class has no callers left it goes too.
 */
final class Unsupported extends DomainException implements MessageFormatError
{
	/** Not one of the specification's errors: this is a fact about the library, not the message. */
	public function type(): ?ErrorType
	{
		return null;
	}

	public static function feature(string $named): self
	{
		return new self(sprintf(
			'%s is valid MessageFormat 2 but is not implemented yet.',
			ucfirst($named),
		));
	}
}
