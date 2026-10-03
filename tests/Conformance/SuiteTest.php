<?php
declare(strict_types=1);

namespace Meraki\MessageFormat\Conformance;

use Meraki\MessageFormat\Error\DataModelError;
use Meraki\MessageFormat\Error\ErrorCategory;
use Meraki\MessageFormat\Error\ErrorType;
use Meraki\MessageFormat\Error\SyntaxError;
use Meraki\MessageFormat\Error\Unsupported;
use Meraki\MessageFormat\MessageFormatter;
use Meraki\MessageFormat\Model\Expression;
use Meraki\MessageFormat\Model\Message;
use Meraki\MessageFormat\Syntax\Parser;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The Unicode working group's own fixtures, run as they are.
 *
 * Every case in `tests/fixtures/conformance` is driven through this, with no curated subset.
 * That was the alternative — the plan for this milestone originally said "the function-free
 * subset of syntax.json" — and a hand-maintained subset is a file somebody has to remember to
 * grow, which means the day it stops growing is invisible. Instead every case runs, and a case
 * this library cannot yet answer is **skipped with the reason**, so the skip list is the to-do
 * list and it shrinks on its own as milestones land.
 *
 * #[CoversNothing] rather than a list of classes: a conformance case legitimately exercises the
 * scanner, the parser, the model and the formatter at once, and attributing that to one class
 * would be a fiction. Coverage comes from the unit tests beside it.
 *
 * ### What is asserted today
 *
 * Output (`exp`) and the two error categories that have no fallback — Syntax and Data Model.
 * Expected *runtime* errors (`expErrors`) and formatted parts (`expParts`) are not compared yet;
 * both arrive with the milestones that build them, and comparing them now would mean asserting
 * against behaviour this library has not got.
 */
#[Group('conformance')]
#[CoversNothing]
final class SuiteTest extends TestCase
{
	private const FIXTURES = __DIR__ . '/../fixtures/conformance/tests';

	/**
	 * The number of cases that must be really asserted, not skipped.
	 *
	 * Set to exactly what is asserted today, which makes it a ratchet: implementing a milestone
	 * raises the real number and this keeps passing, while a change that turns assertions back
	 * into skips fails here and prints the breakdown.
	 *
	 * It exists because of two near-misses. This package's phpunit.xml once pointed a testsuite
	 * at a directory that did not exist and reported OK with zero tests. And the first version of
	 * this runner checked bidi isolation before parsing, which skipped all 133 cases of
	 * syntax-errors.json -- the count dropped to 68 and nothing failed. A green suite that
	 * asserts almost nothing is the failure mode worth guarding.
	 *
	 * Today: 171 of 462. The remainder are complex messages (179), the function registry (105),
	 * bidi isolation (5) and locale formatting of a non-string argument (2); the summary test
	 * names each one and its count.
	 */
	private const ASSERTED_FLOOR = 171;

	/** @return iterable<string, array{array<array-key, mixed>}> */
	public static function cases(): iterable
	{
		foreach (self::files() as $file) {
			$raw = file_get_contents($file);
			self::assertNotFalse($raw, sprintf('could not read %s', $file));

			$decoded = json_decode($raw, true);
			self::assertIsArray($decoded, sprintf('%s is not JSON', $file));

			$defaults = $decoded['defaultTestProperties'] ?? [];
			self::assertIsArray($defaults);

			$tests = $decoded['tests'] ?? [];
			self::assertIsArray($tests);

			$label = substr($file, strlen(self::FIXTURES) + 1);

			foreach ($tests as $index => $test) {
				self::assertIsArray($test);

				$case = array_merge($defaults, $test);
				$source = is_string($case['src'] ?? null) ? $case['src'] : '';

				// The source is in the name so a failure says which message, not which index.
				yield sprintf('%s[%d] %s', $label, $index, json_encode($source)) => [$case];
			}
		}
	}

	/** @return list<string> */
	private static function files(): array
	{
		$top = glob(self::FIXTURES . '/*.json');
		$functions = glob(self::FIXTURES . '/functions/*.json');

		// Not swallowed with ?:. An unreadable fixture directory would otherwise look like an
		// empty suite, which is the one failure that reports itself as a pass.
		self::assertNotFalse($top, 'could not list the fixture directory');
		self::assertNotFalse($functions, 'could not list the function fixtures');

		$files = array_merge($top, $functions);

		sort($files);

		return $files;
	}

	/** @param array<array-key, mixed> $case */
	#[Test]
	#[DataProvider('cases')]
	public function the_working_group_says(array $case): void
	{
		$verdict = self::verdictOn($case);

		if ($verdict['skip'] !== null) {
			self::markTestSkipped($verdict['skip']);
		}

		self::assertTrue($verdict['ok'], $verdict['why']);
	}

	#[Test]
	public function enough_of_the_suite_is_asserted_for_a_pass_to_mean_something(): void
	{
		$asserted = 0;
		$skipped = [];

		foreach (self::cases() as [$case]) {
			$verdict = self::verdictOn($case);

			if ($verdict['skip'] === null) {
				$asserted++;
				continue;
			}

			$skipped[$verdict['skip']] = ($skipped[$verdict['skip']] ?? 0) + 1;
		}

		// Printed so the to-do list is visible in a passing run, not only in a failing one.
		ksort($skipped);
		$report = sprintf('%d of %d cases asserted.', $asserted, $asserted + array_sum($skipped));

		foreach ($skipped as $reason => $count) {
			$report .= sprintf('%s  %4d skipped: %s', PHP_EOL, $count, $reason);
		}

		self::assertGreaterThanOrEqual(self::ASSERTED_FLOOR, $asserted, $report);
	}

	/**
	 * One case, decided without touching PHPUnit's state.
	 *
	 * Separated from the test method so the summary above can run the same logic over every case
	 * without reporting hundreds of skips. Two implementations of this decision would drift, and
	 * the drift would make the summary lie.
	 *
	 * @param array<array-key, mixed> $case
	 * @return array{skip: string|null, ok: bool, why: string}
	 */
	private static function verdictOn(array $case): array
	{
		$source = is_string($case['src'] ?? null) ? $case['src'] : '';
		$expected = $case['exp'] ?? null;
		$expectedErrors = self::expectedErrors($case);

		try {
			$message = Parser::parse($source);
		} catch (Unsupported $unsupported) {
			return self::skip($unsupported->getMessage());
		} catch (SyntaxError $error) {
			return self::decide(
				in_array(ErrorType::Syntax->slug(), $expectedErrors, true),
				sprintf('unexpected syntax error: %s', $error->getMessage()),
			);
		} catch (DataModelError $error) {
			return self::decide(
				in_array($error->type()->slug(), $expectedErrors, true),
				sprintf(
					'reported %s; the case expects %s',
					$error->type()->slug(),
					$expectedErrors === [] ? 'no error' : implode(', ', $expectedErrors),
				),
			);
		}

		foreach ($expectedErrors as $slug) {
			$type = ErrorType::tryFrom($slug);

			// Syntax and Data Model errors have no fallback: a case expecting one and getting a
			// parsed message is a real failure, not something to defer.
			if ($type !== null && in_array($type->category(), [ErrorCategory::Syntax, ErrorCategory::DataModel], true)) {
				return self::decide(false, sprintf('expected %s, and the message parsed', $slug));
			}
		}

		if (self::usesAFunction($message)) {
			return self::skip('a function registry');
		}

		if (!is_string($expected)) {
			return self::skip('asserts errors or parts rather than output');
		}

		$params = $case['params'] ?? [];
		self::assertIsArray($params);
		$arguments = [];

		foreach ($params as $param) {
			if (!is_array($param) || !is_string($param['name'] ?? null)) {
				return self::skip('a parameter shape this runner does not read');
			}

			if (isset($param['type'])) {
				// A typed operand (a datetime, say) means nothing without the function that
				// understands it.
				return self::skip('typed operands');
			}

			if (!is_string($param['value'] ?? null)) {
				// A non-string argument is formatted for the locale even with no function on it:
				// syntax.json[90] formats 1.3 in `fr` as "1,3". That needs ICU, which arrives
				// with the functions that use it, so this is a real gap rather than a quirk of
				// the runner.
				return self::skip('locale formatting of a non-string argument');
			}

			$arguments[$param['name']] = $param['value'];
		}

		// Only now does isolation matter. An absent `bidiIsolation` means the spec's default
		// strategy, which wraps placeholder parts in isolating controls this milestone does not
		// produce — but it wraps nothing in a message that has no placeholders, and it never
		// reaches a message that failed to parse. Checking it any earlier skipped all 133 of
		// syntax-errors.json, whose file sets no default and whose cases never format at all.
		if (($case['bidiIsolation'] ?? 'default') !== 'none' && self::hasPlaceholder($message)) {
			return self::skip('bidi isolation');
		}

		$locale = is_string($case['locale'] ?? null) ? $case['locale'] : 'en-US';
		$actual = MessageFormatter::for($locale)->format($source, $arguments);

		return self::decide(
			$actual === $expected,
			sprintf('formatted %s; expected %s', json_encode($actual), json_encode($expected)),
		);
	}

	/**
	 * @param array<array-key, mixed> $case
	 * @return list<string>
	 */
	private static function expectedErrors(array $case): array
	{
		$errors = $case['expErrors'] ?? [];

		if (!is_array($errors)) {
			return [];
		}

		$slugs = [];

		foreach ($errors as $error) {
			if (is_array($error) && is_string($error['type'] ?? null)) {
				$slugs[] = $error['type'];
			}
		}

		return $slugs;
	}

	private static function usesAFunction(Message $message): bool
	{
		foreach ($message->pattern as $element) {
			if ($element instanceof Expression && $element->function !== null) {
				return true;
			}
		}

		return false;
	}

	/** Whether anything in the pattern is a thing the bidi strategy would isolate. */
	private static function hasPlaceholder(Message $message): bool
	{
		foreach ($message->pattern as $element) {
			if (!is_string($element)) {
				return true;
			}
		}

		return false;
	}

	/** @return array{skip: string|null, ok: bool, why: string} */
	private static function skip(string $reason): array
	{
		return ['skip' => $reason, 'ok' => true, 'why' => ''];
	}

	/** @return array{skip: string|null, ok: bool, why: string} */
	private static function decide(bool $ok, string $why): array
	{
		return ['skip' => null, 'ok' => $ok, 'why' => $why];
	}
}
