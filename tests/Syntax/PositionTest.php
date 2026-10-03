<?php
declare(strict_types=1);

namespace Meraki\MessageFormat\Syntax;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[Group('syntax')]
#[CoversClass(Position::class)]
final class PositionTest extends TestCase
{
	#[Test]
	public function the_start_of_an_empty_message_is_line_one_column_one(): void
	{
		$at = Position::in([], 0);

		self::assertSame(0, $at->offset);
		self::assertSame(1, $at->line);
		self::assertSame(1, $at->column);
	}

	#[Test]
	public function a_column_counts_from_one_so_that_it_matches_what_an_editor_shows(): void
	{
		$at = Position::in(Utf8::decode('abc'), 2);

		self::assertSame(2, $at->offset);
		self::assertSame(1, $at->line);
		self::assertSame(3, $at->column);
	}

	#[Test]
	public function a_line_feed_starts_the_next_line(): void
	{
		$at = Position::in(Utf8::decode("ab\ncd"), 3);

		self::assertSame(2, $at->line);
		self::assertSame(1, $at->column);
	}

	#[Test]
	public function the_line_feed_itself_is_still_on_the_line_it_ends(): void
	{
		// Reporting the newline as the first character of the next line would point a reader at
		// the wrong line for the most common syntax error there is: something left unclosed at
		// the end of one.
		$at = Position::in(Utf8::decode("ab\ncd"), 2);

		self::assertSame(1, $at->line);
		self::assertSame(3, $at->column);
	}

	#[Test]
	public function a_carriage_return_and_line_feed_pair_is_one_line_break(): void
	{
		$at = Position::in(Utf8::decode("ab\r\ncd"), 4);

		self::assertSame(2, $at->line);
		self::assertSame(1, $at->column);
	}

	#[Test]
	public function a_lone_carriage_return_is_also_a_line_break(): void
	{
		// The grammar's `ws` admits CR on its own, so a message can contain one without an LF.
		$at = Position::in(Utf8::decode("ab\rcd"), 3);

		self::assertSame(2, $at->line);
		self::assertSame(1, $at->column);
	}

	#[Test]
	public function a_column_is_counted_in_code_points_not_bytes(): void
	{
		// Two four-byte characters. A byte-counted column would say 9 and point past the end.
		$at = Position::in(Utf8::decode("\u{1F600}\u{1F600}x"), 2);

		self::assertSame(3, $at->column);
	}

	#[Test]
	public function the_position_one_past_the_end_is_where_an_unterminated_construct_is_reported(): void
	{
		$points = Utf8::decode('ab');
		$at = Position::in($points, 2);

		self::assertSame(2, $at->offset);
		self::assertSame(1, $at->line);
		self::assertSame(3, $at->column);
	}

	/** @return iterable<string, array{int, string}> */
	public static function renderings(): iterable
	{
		yield 'the first character' => [0, 'line 1, column 1'];
		yield 'later on the first line' => [2, 'line 1, column 3'];
		yield 'the second line' => [3, 'line 2, column 1'];
	}

	#[Test]
	#[DataProvider('renderings')]
	public function it_reads_as_something_an_error_message_can_contain(int $offset, string $expected): void
	{
		self::assertSame($expected, (string) Position::in(Utf8::decode("ab\ncd"), $offset));
	}

	#[Test]
	public function an_offset_beyond_the_input_is_refused_rather_than_guessed(): void
	{
		// A position is derived from an offset the scanner holds, so one past the end is the
		// most it can legitimately be. Anything further is a bug in the caller, and clamping it
		// would report a plausible-looking location for it.
		$this->expectException(\OutOfRangeException::class);

		Position::in(Utf8::decode('ab'), 3);
	}
}
