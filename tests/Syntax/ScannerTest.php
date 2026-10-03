<?php
declare(strict_types=1);

namespace Meraki\MessageFormat\Syntax;

use Meraki\MessageFormat\Error\SyntaxError;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The cursor the parser moves.
 *
 * Deliberately knows nothing about the grammar: no tokens, no productions, no lookahead tables.
 * MF2's character classes are context-sensitive — a brace opens a placeholder in a pattern and
 * is ordinary text inside a quoted literal — so a lexer would have to be told which mode it was
 * in by the parser anyway, and would then be a second place for the grammar to live.
 */
#[Group('syntax')]
#[CoversClass(Scanner::class)]
final class ScannerTest extends TestCase
{
	#[Test]
	public function an_empty_source_is_immediately_at_the_end(): void
	{
		$scanner = Scanner::over('');

		self::assertTrue($scanner->atEnd());
		self::assertNull($scanner->peek());
		self::assertSame(0, $scanner->offset());
	}

	#[Test]
	public function it_peeks_without_moving(): void
	{
		$scanner = Scanner::over('abc');

		self::assertSame(0x61, $scanner->peek());

		// The cursor is what is asserted, not the value a second time. peek() is pure, so
		// asserting it twice says nothing about whether it moved anything — PHPStan rejects
		// the comparison as already-known, and it is right to.
		self::assertSame(0, $scanner->offset());
		self::assertFalse($scanner->atEnd());
	}

	#[Test]
	public function it_peeks_ahead(): void
	{
		$scanner = Scanner::over('abc');

		self::assertSame(0x62, $scanner->peek(1));
		self::assertSame(0x63, $scanner->peek(2));
		self::assertNull($scanner->peek(3));
		self::assertSame(0, $scanner->offset());
	}

	#[Test]
	public function advancing_moves_one_code_point_at_a_time_not_one_byte(): void
	{
		$scanner = Scanner::over("\u{1F600}b");

		self::assertSame(0x1F600, $scanner->peek());
		$scanner->advance();
		self::assertSame(1, $scanner->offset());
		self::assertSame(0x62, $scanner->peek());
	}

	#[Test]
	public function consuming_returns_what_it_moved_past(): void
	{
		$scanner = Scanner::over('ab');

		self::assertSame(0x61, $scanner->consume());
		self::assertSame(0x62, $scanner->consume());
		self::assertNull($scanner->consume());
		self::assertTrue($scanner->atEnd());
	}

	#[Test]
	public function advancing_past_the_end_stops_at_the_end(): void
	{
		// The parser's loops are written against atEnd(), so an advance at the end is reachable
		// and must not produce an offset that Position would reject.
		$scanner = Scanner::over('a');
		$scanner->advance();
		$scanner->advance();

		self::assertSame(1, $scanner->offset());
		self::assertTrue($scanner->atEnd());
	}

	#[Test]
	public function taking_a_match_consumes_it(): void
	{
		$scanner = Scanner::over('{$x}');

		self::assertTrue($scanner->take(0x7B));
		self::assertSame(1, $scanner->offset());
	}

	#[Test]
	public function taking_something_that_is_not_there_consumes_nothing(): void
	{
		$scanner = Scanner::over('abc');

		self::assertFalse($scanner->take(0x7B));
		self::assertSame(0, $scanner->offset());
	}

	#[Test]
	public function taking_at_the_end_consumes_nothing(): void
	{
		$scanner = Scanner::over('');

		self::assertFalse($scanner->take(0x7B));
	}

	#[Test]
	public function expecting_what_is_there_consumes_it(): void
	{
		$scanner = Scanner::over('}');
		$scanner->expect(0x7D, 'the end of a placeholder');

		self::assertTrue($scanner->atEnd());
	}

	#[Test]
	public function expecting_something_else_says_what_it_wanted_and_where(): void
	{
		$scanner = Scanner::over("ab\ncd");
		$scanner->advance(3);

		try {
			$scanner->expect(0x7D, 'the end of a placeholder');
			self::fail('expected a SyntaxError');
		} catch (SyntaxError $error) {
			// What was wanted, what was found, and where — all three, because an error naming
			// only one of them makes the reader go and count characters.
			self::assertStringContainsString('the end of a placeholder', $error->getMessage());
			self::assertStringContainsString('line 2, column 1', $error->getMessage());
		}
	}

	#[Test]
	public function expecting_something_at_the_end_reports_the_end(): void
	{
		$scanner = Scanner::over('{');
		$scanner->advance();

		try {
			$scanner->expect(0x7D, 'the end of a placeholder');
			self::fail('expected a SyntaxError');
		} catch (SyntaxError $error) {
			self::assertStringContainsString('line 1, column 2', $error->getMessage());
			self::assertStringContainsString('end of the message', $error->getMessage());
		}
	}

	#[Test]
	public function the_position_tracks_the_cursor(): void
	{
		$scanner = Scanner::over("ab\ncd");

		self::assertSame('line 1, column 1', (string) $scanner->position());
		$scanner->advance(3);
		self::assertSame('line 2, column 1', (string) $scanner->position());
	}

	#[Test]
	public function ill_formed_input_is_refused_before_scanning_begins(): void
	{
		$this->expectException(SyntaxError::class);

		Scanner::over("\xFF");
	}

	#[Test]
	public function it_collects_a_run_of_code_points_while_a_predicate_holds(): void
	{
		// How a text run and an unquoted literal are both read: the parser supplies the class
		// and gets back what matched, which keeps the character classes in CodePoints and the
		// productions in the parser.
		$scanner = Scanner::over('abc{$x}');
		$run = $scanner->takeWhile(CodePoints::isNameChar(...));

		self::assertSame([0x61, 0x62, 0x63], $run);
		self::assertSame(0x7B, $scanner->peek());
	}

	#[Test]
	public function collecting_a_run_that_matches_nothing_returns_nothing_and_moves_nothing(): void
	{
		$scanner = Scanner::over('{$x}');
		$run = $scanner->takeWhile(CodePoints::isNameChar(...));

		self::assertSame([], $run);
		self::assertSame(0, $scanner->offset());
	}
}
