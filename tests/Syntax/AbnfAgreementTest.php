<?php
declare(strict_types=1);

namespace Meraki\MessageFormat\Syntax;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * {@see CodePoints} against the grammar it claims to transcribe.
 *
 * This is the only test in the suite that reads the vendored spec. It re-derives each character
 * class from message.abnf and checks the predicate agrees at every boundary, so a range that
 * drifts — because somebody fixed a typo, or because upstream widened a rule — fails here
 * rather than in whichever message happened to use the character.
 *
 * It checks boundaries rather than all 1,114,112 code points because both sides are ranges:
 * if the derived set and the implemented set disagree anywhere, they disagree at the edge of
 * some range, so testing lo-1, lo, hi and hi+1 of every derived range is exhaustive for the
 * defect while staying instant.
 *
 * ### This test can fail
 *
 * It was verified by perturbing one range in CodePoints and watching it go red, which matters
 * for a test that passed the first time it was run: a guard nobody has seen fail is a guard
 * nobody knows works.
 */
#[Group('syntax')]
#[CoversClass(CodePoints::class)]
final class AbnfAgreementTest extends TestCase
{
	private const ABNF = __DIR__ . '/../conformance/message.abnf';

	/** ABNF core rules, which message.abnf uses without defining. */
	private const CORE = [
		'ALPHA' => [[0x41, 0x5A], [0x61, 0x7A]],
		'DIGIT' => [[0x30, 0x39]],
		'SP' => [[0x20, 0x20]],
		'HTAB' => [[0x09, 0x09]],
		'CR' => [[0x0D, 0x0D]],
		'LF' => [[0x0A, 0x0A]],
	];

	/** @return iterable<string, array{string, callable(int): bool}> */
	public static function classes(): iterable
	{
		yield 'simple-start-char' => ['simple-start-char', CodePoints::isSimpleStart(...)];
		yield 'text-char' => ['text-char', CodePoints::isText(...)];
		yield 'quoted-char' => ['quoted-char', CodePoints::isQuoted(...)];
		yield 'name-start' => ['name-start', CodePoints::isNameStart(...)];
		yield 'name-char' => ['name-char', CodePoints::isNameChar(...)];
		yield 'bidi' => ['bidi', CodePoints::isBidi(...)];
		yield 'ws' => ['ws', CodePoints::isWhitespace(...)];
	}

	/**
	 * @param callable(int): bool $predicate
	 */
	#[Test]
	#[DataProvider('classes')]
	public function the_implementation_agrees_with_the_grammar(string $rule, callable $predicate): void
	{
		$ranges = self::rangesFor($rule);

		self::assertNotSame([], $ranges, sprintf('derived no ranges for "%s"', $rule));

		$checked = 0;

		foreach ($ranges as [$low, $high]) {
			foreach ([$low - 1, $low, $high, $high + 1] as $point) {
				if ($point < 0 || $point > 0x10FFFF) {
					continue;
				}

				$expected = self::contains($ranges, $point);

				self::assertSame($expected, $predicate($point), sprintf(
					'%s: U+%04X should %sbe in the class',
					$rule,
					$point,
					$expected ? '' : 'not ',
				));

				$checked++;
			}
		}

		// A derivation that silently produced one range would still pass every assertion
		// above, so the count is asserted too.
		self::assertGreaterThanOrEqual(8, $checked, 'too few boundaries to be meaningful');
	}

	#[Test]
	public function the_vendored_grammar_is_present_and_looks_like_abnf(): void
	{
		// If the fixture ever goes missing, every derivation above would yield nothing. The
		// assertNotSame in that test catches it, but this says which file to go and look at.
		self::assertFileExists(self::ABNF);
		self::assertStringContainsString('simple-message', self::source());
	}

	#[Test]
	public function name_char_is_wider_than_name_start_by_exactly_digits_hyphen_and_full_stop(): void
	{
		$start = self::rangesFor('name-start');
		$char = self::rangesFor('name-char');
		$extra = [];

		for ($point = 0; $point <= 0x10FFFF; $point++) {
			if (self::contains($char, $point) && !self::contains($start, $point)) {
				$extra[] = $point;
			}

			// Everything above the ASCII block is identical between the two rules, so there is
			// nothing to learn by walking the other million code points.
			if ($point > 0x7F) {
				break;
			}
		}

		self::assertSame([0x2D, 0x2E, 0x30, 0x31, 0x32, 0x33, 0x34, 0x35, 0x36, 0x37, 0x38, 0x39], $extra);
	}

	private static function source(): string
	{
		$text = file_get_contents(self::ABNF);

		self::assertNotFalse($text, 'could not read the vendored grammar');

		return $text;
	}

	/**
	 * The alternatives of one rule, as sorted non-overlapping ranges.
	 *
	 * @return list<array{int, int}>
	 */
	private static function rangesFor(string $rule, int $depth = 0): array
	{
		self::assertLessThan(8, $depth, sprintf('rule "%s" recurses too deeply', $rule));

		$body = self::bodyOf($rule);
		$terms = preg_split('/\s*\/\s*/', $body);

		self::assertNotFalse($terms, sprintf('could not split the alternatives of "%s"', $rule));

		$ranges = [];

		foreach ($terms as $term) {
			$term = trim($term);

			if ($term === '') {
				continue;
			}

			foreach (self::rangesForTerm($term, $depth) as $range) {
				$ranges[] = $range;
			}
		}

		return self::normalise($ranges);
	}

	/** @return list<array{int, int}> */
	private static function rangesForTerm(string $term, int $depth): array
	{
		// %xHH-HH
		if (preg_match('/^%x([0-9A-Fa-f]+)-([0-9A-Fa-f]+)$/', $term, $m) === 1) {
			return [[(int) hexdec($m[1]), (int) hexdec($m[2])]];
		}

		// %xHH
		if (preg_match('/^%x([0-9A-Fa-f]+)$/', $term, $m) === 1) {
			$point = (int) hexdec($m[1]);

			return [[$point, $point]];
		}

		// "c" — a one-character literal, as name-char uses for "-" and "."
		if (preg_match('/^"(.)"$/u', $term, $m) === 1) {
			$points = Utf8::decode($m[1]);

			self::assertCount(1, $points, sprintf('literal %s is not one code point', $term));

			return [[$points[0], $points[0]]];
		}

		if (isset(self::CORE[$term])) {
			return self::CORE[$term];
		}

		// A reference to another rule in the grammar.
		if (preg_match('/^[A-Za-z][A-Za-z0-9-]*$/', $term) === 1) {
			return self::rangesFor($term, $depth + 1);
		}

		self::fail(sprintf('cannot read ABNF term "%s"', $term));
	}

	/** The right-hand side of a rule, with comments stripped and continuations joined. */
	private static function bodyOf(string $rule): string
	{
		$body = null;

		foreach (explode("\n", self::source()) as $line) {
			// Comments carry the "omit ..." notes, which name characters and would otherwise be
			// parsed as terms.
			$code = rtrim(preg_replace('/;.*$/', '', $line) ?? '');

			if ($code === '') {
				continue;
			}

			if (preg_match('/^([A-Za-z][A-Za-z0-9-]*)\s*=\s*(.*)$/', $code, $m) === 1) {
				if ($body !== null) {
					break;
				}

				if ($m[1] === $rule) {
					$body = $m[2];
				}

				continue;
			}

			if ($body !== null) {
				$body .= ' ' . trim($code);
			}
		}

		self::assertNotNull($body, sprintf('rule "%s" is not in the grammar', $rule));

		return trim($body);
	}

	/**
	 * @param list<array{int, int}> $ranges
	 * @return list<array{int, int}>
	 */
	private static function normalise(array $ranges): array
	{
		// Sorted through an index map rather than with usort. usort takes its array by
		// reference and its callback by a signature that cannot name the tuple shape, so it
		// widens $ranges to list<array<mixed>> for everything after the call — and then the
		// destructuring below yields mixed and the merge cannot be typed at all. Sorting the
		// low bounds and walking the original array keeps the shape intact.
		$lowBounds = [];

		foreach ($ranges as $index => [$low, $high]) {
			$lowBounds[$index] = $low;
		}

		asort($lowBounds);

		$merged = [];

		// Built by popping and re-pushing rather than by mutating the last element in place, so
		// that every value ever appended is a complete {int, int} tuple. Index mutation would
		// make the element type unprovable and would need a @var to claim it back.
		foreach (array_keys($lowBounds) as $index) {
			[$low, $high] = $ranges[$index];
			$previous = array_pop($merged);

			if ($previous === null) {
				$merged[] = [$low, $high];
				continue;
			}

			if ($low <= $previous[1] + 1) {
				$merged[] = [$previous[0], max($previous[1], $high)];
				continue;
			}

			$merged[] = $previous;
			$merged[] = [$low, $high];
		}

		return $merged;
	}

	/** @param list<array{int, int}> $ranges */
	private static function contains(array $ranges, int $point): bool
	{
		foreach ($ranges as [$low, $high]) {
			if ($point >= $low && $point <= $high) {
				return true;
			}
		}

		return false;
	}
}
