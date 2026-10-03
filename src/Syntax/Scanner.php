<?php
declare(strict_types=1);

namespace Meraki\MessageFormat\Syntax;

use Meraki\MessageFormat\Error\SyntaxError;

/**
 * A cursor over the decoded source.
 *
 * Deliberately knows nothing about the grammar: no tokens, no productions, no modes. MF2's
 * character classes are context-sensitive — a brace opens a placeholder in a pattern and is
 * ordinary text inside a quoted literal, and a dot starts a declaration at the beginning of a
 * message and is text anywhere else — so a lexer would have to be told which context it was in
 * by the parser. It would then be a second place for the grammar to live, and the two would
 * drift.
 *
 * So this offers five things and nothing more: look, move, move-if, move-or-fail, and where am
 * I. {@see CodePoints} owns which characters are which, {@see Parser} owns what order they come
 * in, and this owns the index.
 */
final class Scanner
{
	/** @var list<int> */
	private readonly array $points;

	private readonly int $length;

	private int $at = 0;

	/** @param list<int> $points */
	private function __construct(array $points)
	{
		$this->points = $points;
		$this->length = count($points);
	}

	/** @throws SyntaxError if the source is not well-formed UTF-8 */
	public static function over(string $source): self
	{
		return new self(Utf8::decode($source));
	}

	public function atEnd(): bool
	{
		return $this->at >= $this->length;
	}

	public function offset(): int
	{
		return $this->at;
	}

	public function position(): Position
	{
		return Position::in($this->points, $this->at);
	}

	/** The code point `$ahead` places from the cursor, or null past the end. */
	public function peek(int $ahead = 0): ?int
	{
		return $this->points[$this->at + $ahead] ?? null;
	}

	/**
	 * Moves the cursor, stopping at the end.
	 *
	 * Clamped rather than allowed to run past, because the parser's loops are written against
	 * {@see self::atEnd()} and an offset beyond the source is one {@see Position} refuses.
	 */
	public function advance(int $count = 1): void
	{
		$this->at = min($this->at + $count, $this->length);
	}

	/** Moves one place and returns what was there, or null at the end. */
	public function consume(): ?int
	{
		$point = $this->peek();

		if ($point !== null) {
			$this->at++;
		}

		return $point;
	}

	/** Moves one place if the cursor is on `$point`. Reports whether it did. */
	public function take(int $point): bool
	{
		if ($this->peek() !== $point) {
			return false;
		}

		$this->at++;

		return true;
	}

	/**
	 * Moves one place, or fails saying what it wanted.
	 *
	 * @param string $what the production, in words somebody writing a message would recognise
	 * @throws SyntaxError
	 */
	public function expect(int $point, string $what): void
	{
		if ($this->take($point)) {
			return;
		}

		throw SyntaxError::expected($what, $this->peek(), $this->position());
	}

	/**
	 * Every code point from the cursor for as long as `$matches` holds.
	 *
	 * How a text run, an unquoted literal and a name are all read. The predicate comes from
	 * {@see CodePoints}, which keeps the character classes there and the productions in the
	 * parser; passing it in rather than hard-coding a class here is what stops this file
	 * accumulating grammar.
	 *
	 * @param callable(int): bool $matches
	 * @return list<int> empty if the cursor is not on a match, in which case nothing moved
	 */
	public function takeWhile(callable $matches): array
	{
		$run = [];

		while (($point = $this->peek()) !== null && $matches($point)) {
			$run[] = $point;
			$this->at++;
		}

		return $run;
	}
}
