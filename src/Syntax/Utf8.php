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
}
