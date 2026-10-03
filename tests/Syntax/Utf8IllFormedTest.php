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
 * Ill-formed input is refused rather than repaired.
 *
 * mb_convert_encoding substitutes U+FFFD for anything it cannot read, so decoding without
 * checking first would turn a broken byte into a legal character and carry on. The message
 * would then format, and what came out would not be what went in.
 */
#[Group('syntax')]
#[CoversClass(Utf8::class)]
final class Utf8IllFormedTest extends TestCase
{
	/** @return iterable<string, array{string}> */
	public static function illFormedSequences(): iterable
	{
		yield 'a byte that cannot start a sequence' => ["\xFF"];
		yield 'a continuation byte with no lead' => ["\x80"];
		yield 'a truncated three-byte sequence' => ["\xE2\x82"];
		yield 'an overlong encoding of U+002F' => ["\xC0\xAF"];
		yield 'a lone UTF-16 high surrogate' => ["\xED\xA0\x80"];
	}

	#[Test]
	#[DataProvider('illFormedSequences')]
	public function ill_formed_utf8_is_a_syntax_error(string $bytes): void
	{
		$this->expectException(SyntaxError::class);

		Utf8::decode($bytes);
	}

	#[Test]
	public function a_replacement_character_written_deliberately_is_still_accepted(): void
	{
		// U+FFFD is a legal code point. Refusing it because it is also what a repair would
		// have produced would make the check reject valid input.
		self::assertSame([0xFFFD], Utf8::decode("\u{FFFD}"));
	}
}
