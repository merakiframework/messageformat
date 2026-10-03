<?php
declare(strict_types=1);

namespace Meraki\MessageFormat\Syntax;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[Group('syntax')]
#[CoversClass(Utf8::class)]
final class Utf8Test extends TestCase
{
	#[Test]
	public function it_decodes_ascii_into_code_points(): void
	{
		self::assertSame([97, 98, 99], Utf8::decode('abc'));
	}

	#[Test]
	public function an_empty_string_decodes_to_no_code_points(): void
	{
		self::assertSame([], Utf8::decode(''));
	}

	#[Test]
	public function a_multi_byte_character_is_one_code_point_not_one_per_byte(): void
	{
		// U+00E9 is two bytes in UTF-8. A byte-oriented scanner sees two units here and
		// would let a pattern match half a character.
		self::assertSame([0xE9, 0x6E], Utf8::decode("\u{00E9}n"));
	}

	#[Test]
	public function a_character_outside_the_basic_multilingual_plane_is_one_code_point(): void
	{
		// U+1F600 is four bytes and, in UTF-16, a surrogate pair. Neither is visible here:
		// MF2's character classes are defined over code points, so that is the unit.
		self::assertSame([0x1F600], Utf8::decode("\u{1F600}"));
	}
}
