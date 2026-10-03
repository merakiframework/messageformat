<?php
declare(strict_types=1);

namespace Meraki\MessageFormat\Error;

use Meraki\MessageFormat\Syntax\Position;
use Meraki\MessageFormat\Syntax\Utf8;
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
	public function type(): ErrorType
	{
		return ErrorType::Syntax;
	}

	public static function illFormedInput(string $why): self
	{
		return new self('The message is not well-formed UTF-8: ' . $why . '.');
	}

	/**
	 * What the parser wanted, what was there instead, and where.
	 *
	 * All three, because an error naming only one of them makes the reader go and count
	 * characters to find out which brace it meant.
	 *
	 * @param string $what the production, in words a pack author would recognise
	 * @param int|null $found the code point that was there, or null at the end of the source
	 */
	public static function expected(string $what, ?int $found, Position $at): self
	{
		if ($found === null) {
			return new self(sprintf(
				'Expected %s at %s, but reached the end of the message.',
				$what,
				$at,
			));
		}

		return new self(sprintf(
			'Expected %s at %s, but found "%s".',
			$what,
			$at,
			Utf8::encode([$found]),
		));
	}

	/**
	 * A code point that cannot exist in text.
	 *
	 * Unreachable from {@see Utf8::decode()}, which rejects these on the way in, so this means
	 * something downstream *built* a code point rather than reading one — a name assembled
	 * wrongly, an escape mishandled. Encoding it anyway would put U+FFFD in a user's message.
	 */
	public static function notAScalarValue(int $point): self
	{
		return new self(sprintf(
			'U+%04X is not a Unicode scalar value, so it cannot appear in a message.',
			$point,
		));
	}
}
