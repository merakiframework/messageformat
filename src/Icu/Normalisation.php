<?php
declare(strict_types=1);

namespace Meraki\MessageFormat\Icu;

use Normalizer;

/**
 * Unicode normalisation, which is the one thing outside formatting that needs ext-intl.
 *
 * In this namespace because the rule is that `Meraki\MessageFormat\Icu` is the only place
 * permitted to mention ext-intl, and `Normalizer` is part of it. It sits a little oddly beside
 * the formatters that will join it: normalisation is stability-guaranteed by Unicode and does
 * not move with CLDR, so unlike number and date formatting its answers do not change when ICU
 * upgrades. The rule is kept anyway, because one exception is how a boundary stops being one.
 *
 * ## Why the library needs it at all
 *
 * The specification compares names "as-if normalized". syntax.json pins it six times: a variable
 * written `D` + U+0323 + U+0307 and one written U+1E0C + U+0307 are the same variable, and so are
 * `A` + U+030A + U+0301 and U+01FA. Comparing the bytes would make a message that reads
 * identically on screen fail to resolve.
 */
final class Normalisation
{
	/** @var array<string, string> */
	private static array $cache = [];

	/**
	 * `$text` in Normalization Form C.
	 *
	 * Checked before converting, because almost every name in almost every message is already
	 * normalised and `isNormalized` is much cheaper than `normalize`. Memoised on top of that,
	 * since the same handful of names are looked up once per placeholder.
	 */
	public static function nfc(string $text): string
	{
		if (isset(self::$cache[$text])) {
			return self::$cache[$text];
		}

		if (Normalizer::isNormalized($text, Normalizer::FORM_C)) {
			self::$cache[$text] = $text;

			return $text;
		}

		$normalised = Normalizer::normalize($text, Normalizer::FORM_C);

		// Only ill-formed UTF-8 makes this fail, and Syntax\Utf8 has already refused that on the
		// way in, so this is unreachable for anything that came from a parsed message. Returning
		// the input unchanged degrades to byte comparison rather than to an empty name.
		$result = is_string($normalised) ? $normalised : $text;
		self::$cache[$text] = $result;

		return $result;
	}
}
