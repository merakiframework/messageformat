<?php
declare(strict_types=1);

namespace Meraki\MessageFormat\Syntax;

use Meraki\MessageFormat\Error\SyntaxError;

/**
 * Source text as code points.
 *
 * MF2's character classes are defined over code points, not bytes and not UTF-16 units, so that
 * is the unit the scanner has to work in. Decoding once up front rather than per-read is what
 * lets {@see Scanner} be an integer cursor over an array.
 */
final class Utf8
{
	/**
	 * @return list<int>
	 * @throws SyntaxError if the input is not well-formed UTF-8
	 */
	public static function decode(string $source): array
	{
		if ($source === '') {
			return [];
		}

		// Checked rather than repaired. mb_convert_encoding substitutes U+FFFD for anything it
		// cannot read, so skipping this would turn a broken byte into a legal character and
		// format the message anyway — the one failure mode that cannot be seen in the output.
		if (!mb_check_encoding($source, 'UTF-8')) {
			throw SyntaxError::illFormedInput('the bytes do not decode');
		}

		$utf32 = mb_convert_encoding($source, 'UTF-32BE', 'UTF-8');
		$length = strlen($utf32);

		// Read big-endian quads by hand rather than with unpack('N*'). Two reasons: unpack is
		// declared as returning mixed values, so a list<int> could only be claimed by a cast,
		// and this avoids building the intermediate array of one-character strings that
		// mb_str_split would. ord() is int, and shifting and or-ing ints gives an int, so the
		// element type here is proven rather than asserted.
		$points = [];

		for ($at = 0; $at + 3 < $length; $at += 4) {
			$points[] = (ord($utf32[$at]) << 24)
				| (ord($utf32[$at + 1]) << 16)
				| (ord($utf32[$at + 2]) << 8)
				| ord($utf32[$at + 3]);
		}

		// UTF-32 is fixed-width, so a remainder means the conversion produced a partial unit.
		// mb_check_encoding has already passed at this point, so this is unreachable by way of
		// bad input; it is here because silently dropping a trailing byte would be worse than
		// any message it could produce.
		if ($length % 4 !== 0) {
			throw SyntaxError::illFormedInput('the decoded form has a partial code unit');
		}

		return $points;
	}

	/**
	 * Code points back to UTF-8.
	 *
	 * The parser accumulates runs — text, literal contents, names — because the scanner deals in
	 * code points, and encoding once per run rather than per character is why this takes a list.
	 *
	 * Encoded by hand for the same reason {@see self::decode()} decodes by hand: mb_chr answers
	 * an invalid code point with `false`, which would have to be turned back into an error
	 * anyway, and this way the arithmetic is visible.
	 *
	 * @param list<int> $points
	 * @throws SyntaxError if any value is not a Unicode scalar value
	 */
	public static function encode(array $points): string
	{
		$out = '';

		foreach ($points as $point) {
			// Surrogates are excluded as well as the out-of-range values: they are code points
			// but not scalar values, and UTF-8 has no encoding for them.
			if ($point < 0 || $point > 0x10FFFF || ($point >= 0xD800 && $point <= 0xDFFF)) {
				throw SyntaxError::notAScalarValue($point);
			}

			if ($point < 0x80) {
				$out .= chr($point);
				continue;
			}

			if ($point < 0x800) {
				$out .= chr(0xC0 | ($point >> 6))
					. chr(0x80 | ($point & 0x3F));
				continue;
			}

			if ($point < 0x10000) {
				$out .= chr(0xE0 | ($point >> 12))
					. chr(0x80 | (($point >> 6) & 0x3F))
					. chr(0x80 | ($point & 0x3F));
				continue;
			}

			$out .= chr(0xF0 | ($point >> 18))
				. chr(0x80 | (($point >> 12) & 0x3F))
				. chr(0x80 | (($point >> 6) & 0x3F))
				. chr(0x80 | ($point & 0x3F));
		}

		return $out;
	}
}
