<?php
declare(strict_types=1);

namespace Meraki\MessageFormat\Syntax;

/**
 * The grammar's character classes, as code point ranges.
 *
 * A transcription of message.abnf and nothing else. There is no cleverness to find here, which
 * is the point: {@see \Meraki\MessageFormat\Syntax\AbnfAgreementTest} re-derives these tables
 * from the vendored grammar and fails if they have drifted, so the only way to keep that test
 * green is to stay a transcription.
 *
 * ### Why ranges rather than a regular expression
 *
 * PCRE could express these classes, and `preg_match` on a one-character string would even be
 * fast. But the scanner holds code points as integers — it has to, because MF2's classes are
 * defined over code points — so a regex would mean encoding each integer back to UTF-8 to ask
 * one question about it. The ranges are sorted and the scan exits as soon as it passes the
 * code point, so the common cases (ASCII letters, ASCII text) settle in a handful of integer
 * comparisons.
 *
 * ### Why not IntlChar
 *
 * `IntlChar` answers questions about Unicode properties. These classes are not property-based:
 * `name-start` admits every private use character and every unassigned code point, and refuses
 * five specific whitespace characters and the bidi controls. No property expresses that, and
 * tying the grammar to ICU's Unicode version would make the parser's answers move when ICU
 * upgraded.
 */
final class CodePoints
{
	/**
	 * `simple-start-char`: what a message may begin with.
	 *
	 * Narrower than {@see self::TEXT} in five places — HTAB, LF, SP, FULL STOP and IDEOGRAPHIC
	 * SPACE — because a message starting with any of them could be read as a declaration or as
	 * leading whitespace before one.
	 *
	 * @var list<array{int, int}>
	 */
	private const SIMPLE_START = [
		[0x01, 0x08],       // omit NULL, HTAB
		[0x0B, 0x0C],       // omit LF, CR
		[0x0E, 0x1F],       // omit CR, SP
		[0x21, 0x2D],       // omit SP, FULL STOP
		[0x2F, 0x5B],       // omit FULL STOP, REVERSE SOLIDUS
		[0x5D, 0x7A],       // omit REVERSE SOLIDUS, LEFT CURLY BRACKET
		[0x7C, 0x7C],       // omit LEFT and RIGHT CURLY BRACKET
		[0x7E, 0x2FFF],     // omit RIGHT CURLY BRACKET, IDEOGRAPHIC SPACE
		[0x3001, 0x10FFFF], // omit IDEOGRAPHIC SPACE
	];

	/**
	 * `text-char`: what a pattern may contain.
	 *
	 * @var list<array{int, int}>
	 */
	private const TEXT = [
		[0x01, 0x5B],       // omit NULL, REVERSE SOLIDUS
		[0x5D, 0x7A],       // omit REVERSE SOLIDUS, LEFT CURLY BRACKET
		[0x7C, 0x7C],       // omit LEFT and RIGHT CURLY BRACKET
		[0x7E, 0x10FFFF],   // omit RIGHT CURLY BRACKET
	];

	/**
	 * `quoted-char`: what a quoted literal may contain.
	 *
	 * Braces need no escape inside one, because the delimiter is the pipe. The pipe does.
	 *
	 * @var list<array{int, int}>
	 */
	private const QUOTED = [
		[0x01, 0x5B],       // omit NULL, REVERSE SOLIDUS
		[0x5D, 0x7B],       // omit REVERSE SOLIDUS, VERTICAL LINE
		[0x7D, 0x10FFFF],   // omit VERTICAL LINE
	];

	/**
	 * `name-start`: what a name, namespace or identifier may begin with.
	 *
	 * Deliberately enormous. The grammar admits almost everything and then carves out five
	 * whitespace characters, the bidi controls, the surrogates and the noncharacters — so the
	 * gaps between these ranges are the whole content of the rule.
	 *
	 * @var list<array{int, int}>
	 */
	private const NAME_START = [
		[0x2B, 0x2B],       // PLUS SIGN
		[0x41, 0x5A],       // ALPHA, upper
		[0x5F, 0x5F],       // LOW LINE
		[0x61, 0x7A],       // ALPHA, lower
		[0xA1, 0x61B],      // omit NO-BREAK SPACE, ARABIC LETTER MARK
		[0x61D, 0x167F],    // omit ARABIC LETTER MARK, OGHAM SPACE MARK
		[0x1681, 0x1FFF],   // omit OGHAM SPACE MARK, EN QUAD..HAIR SPACE
		[0x200B, 0x200D],   // omit HAIR SPACE, LRM and RLM
		[0x2010, 0x2027],   // omit LRM and RLM, LINE and PARAGRAPH SEPARATOR
		[0x2030, 0x205E],   // omit the separators and the embedding controls, MMSP
		[0x2060, 0x2065],   // omit MEDIUM MATHEMATICAL SPACE, the isolates
		[0x206A, 0x2FFF],   // omit the isolates, IDEOGRAPHIC SPACE
		[0x3001, 0xD7FF],   // omit IDEOGRAPHIC SPACE, the surrogates
		[0xE000, 0xFDCF],   // omit the surrogates, the Arabic noncharacters
		[0xFDF0, 0xFFFD],   // omit the Arabic noncharacters, U+FFFE and U+FFFF
		[0x10000, 0x1FFFD], // each plane below drops its own last two noncharacters
		[0x20000, 0x2FFFD],
		[0x30000, 0x3FFFD],
		[0x40000, 0x4FFFD],
		[0x50000, 0x5FFFD],
		[0x60000, 0x6FFFD],
		[0x70000, 0x7FFFD],
		[0x80000, 0x8FFFD],
		[0x90000, 0x9FFFD],
		[0xA0000, 0xAFFFD],
		[0xB0000, 0xBFFFD],
		[0xC0000, 0xCFFFD],
		[0xD0000, 0xDFFFD],
		[0xE0000, 0xEFFFD],
		[0xF0000, 0xFFFFD],
		[0x100000, 0x10FFFD],
	];

	/** `bidi`: ALM, LRM, RLM and the four isolate controls. */
	private const BIDI = [0x061C, 0x200E, 0x200F, 0x2066, 0x2067, 0x2068, 0x2069];

	/** `ws`: SP, HTAB, CR, LF and IDEOGRAPHIC SPACE. Five, and no others. */
	private const WHITESPACE = [0x20, 0x09, 0x0D, 0x0A, 0x3000];

	/** `simple-start-char` */
	public static function isSimpleStart(int $point): bool
	{
		return self::within($point, self::SIMPLE_START);
	}

	/** `text-char` */
	public static function isText(int $point): bool
	{
		return self::within($point, self::TEXT);
	}

	/** `quoted-char` */
	public static function isQuoted(int $point): bool
	{
		return self::within($point, self::QUOTED);
	}

	/** `name-start` */
	public static function isNameStart(int $point): bool
	{
		return self::within($point, self::NAME_START);
	}

	/** `name-char = name-start / DIGIT / "-" / "."` */
	public static function isNameChar(int $point): bool
	{
		if ($point >= 0x30 && $point <= 0x39) {
			return true;
		}

		if ($point === 0x2D || $point === 0x2E) {
			return true;
		}

		return self::isNameStart($point);
	}

	/** `bidi` */
	public static function isBidi(int $point): bool
	{
		return in_array($point, self::BIDI, true);
	}

	/** `ws` */
	public static function isWhitespace(int $point): bool
	{
		return in_array($point, self::WHITESPACE, true);
	}

	/**
	 * @param list<array{int, int}> $ranges sorted ascending and non-overlapping
	 */
	private static function within(int $point, array $ranges): bool
	{
		foreach ($ranges as [$low, $high]) {
			if ($point < $low) {
				// Sorted, so nothing later can contain it either.
				return false;
			}

			if ($point <= $high) {
				return true;
			}
		}

		return false;
	}
}
