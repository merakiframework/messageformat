<?php
declare(strict_types=1);

namespace Meraki\MessageFormat\Syntax;

use OutOfRangeException;
use Stringable;

/**
 * Where in the source something is, for a {@see \Meraki\MessageFormat\Error\SyntaxError} to say.
 *
 * Derived from an offset rather than constructible by hand. A position assembled from a line and
 * a column somebody worked out separately can disagree with the offset it claims to describe,
 * and the whole value of this type is that it cannot: {@see self::in()} is the only way in, so
 * the three numbers always describe the same place.
 *
 * Offsets and columns are counted in **code points**, matching {@see Utf8} and the scanner. A
 * column counted in bytes would point past the end of any line containing a non-ASCII character,
 * which is most lines worth reporting an error in.
 */
final class Position implements Stringable
{
	private function __construct(
		public readonly int $offset,
		public readonly int $line,
		public readonly int $column,
	) {
	}

	/**
	 * @param list<int> $points the decoded source
	 * @param int $offset a code point index, where `count($points)` means "at the end"
	 * @throws OutOfRangeException if the offset is not somewhere in, or just past, the source
	 */
	public static function in(array $points, int $offset): self
	{
		$length = count($points);

		// One past the end is legitimate, and is where anything unterminated gets reported.
		// Further than that is a bug in the caller, and clamping would answer it with a
		// plausible-looking location instead of saying so.
		if ($offset < 0 || $offset > $length) {
			throw new OutOfRangeException(sprintf(
				'Offset %d is not within a source of %d code points.',
				$offset,
				$length,
			));
		}

		$line = 1;
		$column = 1;
		$previous = null;

		for ($at = 0; $at < $offset; $at++) {
			$point = $points[$at];

			// The LF of a CRLF pair. Its break was counted at the CR, and counting it again
			// would report every line after the first as two.
			if ($point === 0x0A && $previous === 0x0D) {
				$previous = $point;
				continue;
			}

			// The grammar's `ws` admits CR on its own, so a lone CR is a break too.
			if ($point === 0x0A || $point === 0x0D) {
				$line++;
				$column = 1;
				$previous = $point;
				continue;
			}

			$column++;
			$previous = $point;
		}

		return new self($offset, $line, $column);
	}

	public function __toString(): string
	{
		return sprintf('line %d, column %d', $this->line, $this->column);
	}
}
