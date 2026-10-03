<?php
declare(strict_types=1);

namespace Meraki\MessageFormat\Syntax;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The grammar's character classes, at their boundaries.
 *
 * Every case here is one end of a range in message.abnf, because an off-by-one in a range is
 * the defect this file exists to catch and it is invisible in ordinary use: a class that
 * admitted a brace by mistake would parse almost everything correctly, then mis-read exactly
 * the messages that matter.
 *
 * {@see AbnfAgreementTest} proves these ranges still say what the vendored grammar says. This
 * file proves the predicates implement the ranges.
 */
#[Group('syntax')]
#[CoversClass(CodePoints::class)]
final class CodePointsTest extends TestCase
{
	/** @return iterable<string, array{int}> */
	public static function notSimpleStart(): iterable
	{
		yield 'NULL' => [0x00];
		yield 'HTAB' => [0x09];
		yield 'LF' => [0x0A];
		yield 'CR' => [0x0D];
		yield 'SPACE' => [0x20];
		yield 'FULL STOP, which would start a declaration' => [0x2E];
		yield 'REVERSE SOLIDUS, which starts an escape' => [0x5C];
		yield 'LEFT CURLY BRACKET, which starts a placeholder' => [0x7B];
		yield 'RIGHT CURLY BRACKET, which has no opener' => [0x7D];
		yield 'IDEOGRAPHIC SPACE' => [0x3000];
	}

	/** @return iterable<string, array{int}> */
	public static function isSimpleStart(): iterable
	{
		yield 'the code point after NULL' => [0x01];
		yield 'the code point before HTAB' => [0x08];
		yield 'the code point after LF' => [0x0B];
		yield 'the code point before CR' => [0x0C];
		yield 'the code point after CR' => [0x0E];
		yield 'the code point before SPACE' => [0x1F];
		yield 'the code point after SPACE' => [0x21];
		yield 'the code point before FULL STOP' => [0x2D];
		yield 'the code point after FULL STOP' => [0x2F];
		yield 'the code point before REVERSE SOLIDUS' => [0x5B];
		yield 'the code point after REVERSE SOLIDUS' => [0x5D];
		yield 'the code point before LEFT CURLY BRACKET' => [0x7A];
		yield 'VERTICAL LINE, between the two brackets' => [0x7C];
		yield 'the code point after RIGHT CURLY BRACKET' => [0x7E];
		yield 'the code point before IDEOGRAPHIC SPACE' => [0x2FFF];
		yield 'the code point after IDEOGRAPHIC SPACE' => [0x3001];
		yield 'the last code point' => [0x10FFFF];
	}

	#[Test]
	#[DataProvider('notSimpleStart')]
	public function a_simple_message_cannot_begin_with(int $point): void
	{
		self::assertFalse(CodePoints::isSimpleStart($point));
	}

	#[Test]
	#[DataProvider('isSimpleStart')]
	public function a_simple_message_can_begin_with(int $point): void
	{
		self::assertTrue(CodePoints::isSimpleStart($point));
	}

	/** @return iterable<string, array{int}> */
	public static function notText(): iterable
	{
		yield 'NULL' => [0x00];
		yield 'REVERSE SOLIDUS' => [0x5C];
		yield 'LEFT CURLY BRACKET' => [0x7B];
		yield 'RIGHT CURLY BRACKET' => [0x7D];
	}

	/** @return iterable<string, array{int}> */
	public static function isText(): iterable
	{
		yield 'the code point after NULL' => [0x01];
		// text-char is wider than simple-start-char in exactly these five places: once a
		// pattern has started, whitespace and a leading dot can no longer be mistaken for the
		// start of a declaration.
		yield 'HTAB, which cannot start a message' => [0x09];
		yield 'LF, which cannot start a message' => [0x0A];
		yield 'SPACE, which cannot start a message' => [0x20];
		yield 'FULL STOP, which cannot start a message' => [0x2E];
		yield 'IDEOGRAPHIC SPACE, which cannot start a message' => [0x3000];
		yield 'the code point before REVERSE SOLIDUS' => [0x5B];
		yield 'the code point after REVERSE SOLIDUS' => [0x5D];
		yield 'the code point before LEFT CURLY BRACKET' => [0x7A];
		yield 'VERTICAL LINE' => [0x7C];
		yield 'the code point after RIGHT CURLY BRACKET' => [0x7E];
		yield 'the last code point' => [0x10FFFF];
	}

	#[Test]
	#[DataProvider('notText')]
	public function a_pattern_cannot_contain(int $point): void
	{
		self::assertFalse(CodePoints::isText($point));
	}

	#[Test]
	#[DataProvider('isText')]
	public function a_pattern_can_contain(int $point): void
	{
		self::assertTrue(CodePoints::isText($point));
	}

	/** @return iterable<string, array{int}> */
	public static function notQuoted(): iterable
	{
		yield 'NULL' => [0x00];
		yield 'REVERSE SOLIDUS' => [0x5C];
		yield 'VERTICAL LINE, which closes the literal' => [0x7C];
	}

	/** @return iterable<string, array{int}> */
	public static function isQuoted(): iterable
	{
		yield 'the code point after NULL' => [0x01];
		yield 'SPACE, which is why quoted literals exist' => [0x20];
		yield 'the code point before REVERSE SOLIDUS' => [0x5B];
		yield 'the code point after REVERSE SOLIDUS' => [0x5D];
		// A quoted literal is the one place braces need no escape: the delimiter is the pipe,
		// so a brace inside it is not ambiguous.
		yield 'LEFT CURLY BRACKET, which needs no escape here' => [0x7B];
		yield 'RIGHT CURLY BRACKET, which needs no escape here' => [0x7D];
		yield 'the last code point' => [0x10FFFF];
	}

	#[Test]
	#[DataProvider('notQuoted')]
	public function a_quoted_literal_cannot_contain(int $point): void
	{
		self::assertFalse(CodePoints::isQuoted($point));
	}

	#[Test]
	#[DataProvider('isQuoted')]
	public function a_quoted_literal_can_contain(int $point): void
	{
		self::assertTrue(CodePoints::isQuoted($point));
	}

	/** @return iterable<string, array{int}> */
	public static function isNameStart(): iterable
	{
		yield 'A' => [0x41];
		yield 'Z' => [0x5A];
		yield 'a' => [0x61];
		yield 'z' => [0x7A];
		yield 'PLUS SIGN' => [0x2B];
		yield 'LOW LINE' => [0x5F];
		yield 'the code point after NO-BREAK SPACE' => [0xA1];
		yield 'the code point before ARABIC LETTER MARK' => [0x61B];
		yield 'the code point after ARABIC LETTER MARK' => [0x61D];
		yield 'the code point before OGHAM SPACE MARK' => [0x167F];
		yield 'the code point after OGHAM SPACE MARK' => [0x1681];
		yield 'the code point before EN QUAD' => [0x1FFF];
		yield 'ZERO WIDTH SPACE' => [0x200B];
		yield 'the code point before LEFT-TO-RIGHT MARK' => [0x200D];
		yield 'the code point after RIGHT-TO-LEFT MARK' => [0x2010];
		yield 'the code point before LINE SEPARATOR' => [0x2027];
		yield 'the code point after RIGHT-TO-LEFT OVERRIDE' => [0x2030];
		yield 'the code point before MEDIUM MATHEMATICAL SPACE' => [0x205E];
		yield 'WORD JOINER' => [0x2060];
		yield 'the code point before LEFT-TO-RIGHT ISOLATE' => [0x2065];
		yield 'the code point after POP DIRECTIONAL ISOLATE' => [0x206A];
		yield 'the code point before the surrogate block' => [0xD7FF];
		yield 'the first private use code point' => [0xE000];
		yield 'the code point before the Arabic noncharacter block' => [0xFDCF];
		yield 'the code point after the Arabic noncharacter block' => [0xFDF0];
		yield 'the last code point of the BMP that is not a noncharacter' => [0xFFFD];
		yield 'the first supplementary code point' => [0x10000];
		yield 'the last usable code point of plane 1' => [0x1FFFD];
		yield 'the first code point of plane 16' => [0x100000];
		yield 'the last usable code point' => [0x10FFFD];
	}

	/** @return iterable<string, array{int}> */
	public static function notNameStart(): iterable
	{
		yield 'DIGIT ZERO' => [0x30];
		yield 'DIGIT NINE' => [0x39];
		yield 'HYPHEN-MINUS' => [0x2D];
		yield 'FULL STOP' => [0x2E];
		yield 'SPACE' => [0x20];
		yield 'COLON, which separates a namespace' => [0x3A];
		yield 'DOLLAR SIGN, which marks a variable' => [0x24];
		yield 'NO-BREAK SPACE' => [0xA0];
		yield 'ARABIC LETTER MARK' => [0x61C];
		yield 'OGHAM SPACE MARK' => [0x1680];
		yield 'EN QUAD' => [0x2000];
		yield 'HAIR SPACE' => [0x200A];
		yield 'LEFT-TO-RIGHT MARK' => [0x200E];
		yield 'RIGHT-TO-LEFT MARK' => [0x200F];
		yield 'LINE SEPARATOR' => [0x2028];
		yield 'PARAGRAPH SEPARATOR' => [0x2029];
		yield 'LEFT-TO-RIGHT EMBEDDING' => [0x202A];
		yield 'RIGHT-TO-LEFT OVERRIDE' => [0x202E];
		yield 'NARROW NO-BREAK SPACE' => [0x202F];
		yield 'MEDIUM MATHEMATICAL SPACE' => [0x205F];
		yield 'LEFT-TO-RIGHT ISOLATE' => [0x2066];
		yield 'POP DIRECTIONAL ISOLATE' => [0x2069];
		yield 'IDEOGRAPHIC SPACE' => [0x3000];
		yield 'the first surrogate' => [0xD800];
		yield 'the last surrogate' => [0xDFFF];
		yield 'the first noncharacter of the Arabic block' => [0xFDD0];
		yield 'the last noncharacter of the Arabic block' => [0xFDEF];
		yield 'the first noncharacter of the BMP' => [0xFFFE];
		yield 'the last noncharacter of the BMP' => [0xFFFF];
		yield 'the first noncharacter of plane 1' => [0x1FFFE];
		yield 'the last noncharacter of plane 16' => [0x10FFFF];
	}

	#[Test]
	#[DataProvider('isNameStart')]
	public function a_name_can_begin_with(int $point): void
	{
		self::assertTrue(CodePoints::isNameStart($point));
	}

	#[Test]
	#[DataProvider('notNameStart')]
	public function a_name_cannot_begin_with(int $point): void
	{
		self::assertFalse(CodePoints::isNameStart($point));
	}

	/** @return iterable<string, array{int}> */
	public static function isNameCharButNotNameStart(): iterable
	{
		yield 'DIGIT ZERO' => [0x30];
		yield 'DIGIT NINE' => [0x39];
		yield 'HYPHEN-MINUS' => [0x2D];
		yield 'FULL STOP' => [0x2E];
	}

	#[Test]
	#[DataProvider('isNameCharButNotNameStart')]
	public function a_name_can_continue_but_not_begin_with(int $point): void
	{
		self::assertTrue(CodePoints::isNameChar($point), 'should be a name-char');
		self::assertFalse(CodePoints::isNameStart($point), 'should not be a name-start');
	}

	#[Test]
	public function every_name_start_is_also_a_name_char(): void
	{
		// name-char = name-start / DIGIT / "-" / "." — the containment is structural, and a
		// predicate that broke it would reject a name whose second character is legal as its
		// first.
		foreach ([0x41, 0x2B, 0x5F, 0xA1, 0x10FFFD] as $point) {
			self::assertTrue(CodePoints::isNameChar($point), sprintf('U+%04X', $point));
		}
	}

	/** @return iterable<string, array{int}> */
	public static function bidiCharacters(): iterable
	{
		yield 'ARABIC LETTER MARK' => [0x61C];
		yield 'LEFT-TO-RIGHT MARK' => [0x200E];
		yield 'RIGHT-TO-LEFT MARK' => [0x200F];
		yield 'LEFT-TO-RIGHT ISOLATE' => [0x2066];
		yield 'RIGHT-TO-LEFT ISOLATE' => [0x2067];
		yield 'FIRST STRONG ISOLATE' => [0x2068];
		yield 'POP DIRECTIONAL ISOLATE' => [0x2069];
	}

	#[Test]
	#[DataProvider('bidiCharacters')]
	public function it_recognises_the_bidi_marks_and_isolates(int $point): void
	{
		self::assertTrue(CodePoints::isBidi($point));
	}

	#[Test]
	public function the_code_points_either_side_of_the_isolate_block_are_not_bidi(): void
	{
		self::assertFalse(CodePoints::isBidi(0x2065));
		self::assertFalse(CodePoints::isBidi(0x206A));
	}

	/** @return iterable<string, array{int}> */
	public static function whitespaceCharacters(): iterable
	{
		yield 'SPACE' => [0x20];
		yield 'HTAB' => [0x09];
		yield 'CR' => [0x0D];
		yield 'LF' => [0x0A];
		yield 'IDEOGRAPHIC SPACE' => [0x3000];
	}

	#[Test]
	#[DataProvider('whitespaceCharacters')]
	public function it_recognises_the_five_whitespace_characters(int $point): void
	{
		self::assertTrue(CodePoints::isWhitespace($point));
	}

	#[Test]
	public function no_break_space_is_not_whitespace(): void
	{
		// U+00A0 looks like a space and is not one of the five the grammar lists. Treating it
		// as whitespace would silently accept a declaration indented with one, and the
		// grammar would disagree.
		self::assertFalse(CodePoints::isWhitespace(0xA0));
	}
}
