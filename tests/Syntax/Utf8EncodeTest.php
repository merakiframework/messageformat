<?php
declare(strict_types=1);

namespace Meraki\MessageFormat\Syntax;

use Meraki\MessageFormat\Error\SyntaxError;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The way back out.
 *
 * The parser accumulates code points — text runs, literal contents, names — because that is what
 * the scanner deals in, and has to hand back strings. Encoding once at the end of a run rather
 * than per character is the reason this exists as a separate operation.
 */
#[Group('syntax')]
#[CoversClass(Utf8::class)]
final class Utf8EncodeTest extends TestCase
{
	#[Test]
	public function no_code_points_encode_to_an_empty_string(): void
	{
		self::assertSame('', Utf8::encode([]));
	}

	#[Test]
	public function it_encodes_ascii(): void
	{
		self::assertSame('abc', Utf8::encode([97, 98, 99]));
	}

	#[Test]
	public function it_encodes_above_the_ascii_range(): void
	{
		self::assertSame("\u{00E9}n", Utf8::encode([0xE9, 0x6E]));
	}

	#[Test]
	public function it_encodes_above_the_basic_multilingual_plane(): void
	{
		self::assertSame("\u{1F600}", Utf8::encode([0x1F600]));
	}

	/** @return iterable<string, array{string}> */
	public static function roundTrippable(): iterable
	{
		yield 'ascii' => ['Hello, world!'];
		yield 'a message with a placeholder' => ['Hello, {$userName}!'];
		yield 'accented' => ["Cl\u{00E9}ment"];
		yield 'outside the BMP' => ["a\u{1F600}b"];
		yield 'the replacement character' => ["\u{FFFD}"];
		yield 'the last usable code point' => ["\u{10FFFD}"];
		yield 'every escapable character' => ['\\{|}'];
	}

	#[Test]
	#[DataProvider('roundTrippable')]
	public function decoding_and_encoding_are_inverses(string $text): void
	{
		self::assertSame($text, Utf8::encode(Utf8::decode($text)));
	}

	/** @return iterable<string, array{int}> */
	public static function notScalarValues(): iterable
	{
		yield 'a high surrogate' => [0xD800];
		yield 'a low surrogate' => [0xDFFF];
		yield 'one past the last code point' => [0x110000];
		yield 'a negative value' => [-1];
	}

	#[Test]
	#[DataProvider('notScalarValues')]
	public function a_value_that_is_not_a_scalar_value_is_refused(int $point): void
	{
		// These cannot come out of decode(), so reaching here means the parser built a code
		// point rather than read one — a name assembled wrongly, an escape mishandled. Encoding
		// it to U+FFFD would put a replacement character in somebody's message instead.
		$this->expectException(SyntaxError::class);

		Utf8::encode([$point]);
	}

	#[Test]
	public function the_noncharacters_are_still_encodable(): void
	{
		// U+FFFE is a noncharacter and not a name-start, but it is a legal scalar value and
		// legal in text. Refusing it here would reject a message the grammar accepts.
		self::assertSame("\u{FFFE}", Utf8::encode([0xFFFE]));
	}
}
