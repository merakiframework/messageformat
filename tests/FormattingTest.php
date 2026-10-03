<?php
declare(strict_types=1);

namespace Meraki\MessageFormat;

use Meraki\MessageFormat\Error\ErrorType;
use Meraki\MessageFormat\Error\MessageFormatError;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Source and arguments in, a string out.
 *
 * No functions are registered yet, which is not a gap in conformance: an unregistered function
 * is an *Unknown Function*, a Resolution Error the specification answers with a fallback value
 * and a continued format. So the behaviour asserted here for `{:number}` is what the spec asks
 * for, not a placeholder for it — what changes when the registry lands is which names are
 * unknown.
 */
#[Group('formatting')]
#[CoversClass(MessageFormatter::class)]
#[CoversClass(FormattedMessage::class)]
final class FormattingTest extends TestCase
{
	/** @param array<string, mixed> $arguments */
	private static function format(string $source, array $arguments = []): string
	{
		return MessageFormatter::for('en-US')->format($source, $arguments);
	}

	#[Test]
	public function an_empty_message_formats_to_an_empty_string(): void
	{
		self::assertSame('', self::format(''));
	}

	#[Test]
	public function text_passes_through(): void
	{
		self::assertSame('hello', self::format('hello'));
	}

	#[Test]
	public function a_variable_is_replaced_by_its_argument(): void
	{
		self::assertSame('Hello, John!', self::format('Hello, {$userName}!', ['userName' => 'John']));
	}

	#[Test]
	public function whitespace_around_a_simple_message_survives_formatting(): void
	{
		self::assertSame("\n hello\t", self::format("\n hello\t"));
	}

	/** @return iterable<string, array{string, string}> */
	public static function literals(): iterable
	{
		yield 'unquoted' => ['{foo}', 'foo'];
		yield 'quoted with a space' => ['{|a b|}', 'a b'];
		yield 'quoted and empty' => ['{||}', ''];
		yield 'an escaped pipe' => ['{|a\\|b|}', 'a|b'];
		yield 'a number-looking literal' => ['{42}', '42'];
	}

	#[Test]
	#[DataProvider('literals')]
	public function a_literal_operand_formats_as_itself(string $source, string $expected): void
	{
		self::assertSame($expected, self::format($source));
	}

	#[Test]
	public function markup_contributes_nothing_to_a_string(): void
	{
		// The spec defines no markup vocabulary and no string rendering for it. It reaches the
		// caller through the formatted parts instead, which is M8.
		self::assertSame('ab', self::format('a{#b}{/b}b'));
	}

	/** @return iterable<string, array{string, string, array<string, mixed>}> */
	public static function fallbacks(): iterable
	{
		// The fallback table, from fallback.json. A literal is always re-quoted, whether or not
		// it was quoted in the source.
		yield 'an unresolved variable' => ['{$var}', '{$var}', []];
		yield 'an unknown function with no operand' => ['{:test:undefined}', '{:test:undefined}', []];
		yield 'an unknown function on an unquoted literal' => ['{42 :test:undefined}', '{|42|}', []];
		yield 'an unknown function on a quoted literal' => ['{|a b| :test:undefined}', '{|a b|}', []];
		yield 'an unknown function on a variable' => ['{$var :test:undefined}', '{$var}', ['var' => 'x']];
		yield 'a pipe inside a fallback literal is escaped' => ['{|a\\|b| :x:y}', '{|a\\|b|}', []];
		yield 'a backslash inside a fallback literal is escaped' => ['{|C:\\\\| :x:y}', '{|C:\\\\|}', []];
	}

	/**
	 * @param array<string, mixed> $arguments
	 */
	#[Test]
	#[DataProvider('fallbacks')]
	public function a_placeholder_that_cannot_be_resolved_falls_back(
		string $source,
		string $expected,
		array $arguments,
	): void {
		self::assertSame($expected, self::format($source, $arguments));
	}

	#[Test]
	public function the_rest_of_the_message_still_formats_around_a_fallback(): void
	{
		// "An implementation MUST enable a user to get a formatted result" for a valid message.
		// One unresolvable placeholder does not cost the reader the other words.
		self::assertSame(
			'Hello, {$missing}, you are 7.',
			self::format('Hello, {$missing}, you are {$age}.', ['age' => '7']),
		);
	}

	#[Test]
	public function the_errors_are_discoverable_rather_than_only_implied_by_the_output(): void
	{
		$result = MessageFormatter::for('en-US')->formatWithDiagnostics('{$a} {:b}', []);

		self::assertSame('{$a} {:b}', $result->text);
		self::assertSame(
			[ErrorType::UnresolvedVariable, ErrorType::UnknownFunction],
			array_map(static fn(MessageFormatError $e): ?ErrorType => $e->type(), $result->errors),
		);
	}

	#[Test]
	public function a_message_with_nothing_wrong_reports_no_errors(): void
	{
		$result = MessageFormatter::for('en-US')->formatWithDiagnostics('hi {$who}', ['who' => 'you']);

		self::assertSame('hi you', $result->text);
		self::assertSame([], $result->errors);
		self::assertFalse($result->hasErrors());
	}

	#[Test]
	public function strict_mode_raises_the_first_error_instead_of_falling_back(): void
	{
		// Two doors, one engine. Fallback mode is what the spec requires of a formatter; strict
		// mode is for the build of a message catalogue, where a fallback shipped to a reader is
		// the thing you are trying to prevent.
		$this->expectException(MessageFormatError::class);

		MessageFormatter::for('en-US', ErrorHandling::Strict)->format('{$missing}', []);
	}

	#[Test]
	public function strict_mode_formats_a_message_with_nothing_wrong(): void
	{
		self::assertSame(
			'hi you',
			MessageFormatter::for('en-US', ErrorHandling::Strict)->format('hi {$who}', ['who' => 'you']),
		);
	}

	#[Test]
	public function a_syntax_error_is_raised_in_both_modes(): void
	{
		// Unlike a resolution error, a malformed message has no data model, so there is nothing
		// to fall back to. The spec prioritises syntax errors over everything else.
		$this->expectException(Error\SyntaxError::class);

		self::format('}');
	}

	#[Test]
	public function a_numeric_argument_is_not_yet_formatted_for_its_locale(): void
	{
		// A known divergence from the specification, pinned here so it is visible rather than
		// forgotten.
		//
		// This originally asserted plain conversion as though it were correct, with a comment
		// arguing that locale formatting is `:number`'s job. The conformance suite disagreed:
		// syntax.json[90] formats `{$one} et {$two}` in `fr` with 1.3 and 4.2 as "1,3 et 4,2".
		// So an **unannotated** numeric operand is locale-formatted too, and getting there needs
		// ICU — which belongs with the functions that use it.
		//
		// When that lands, this test changes to expect "1 000" and "1,3", and the runner's
		// "locale formatting of a non-string argument" skips disappear.
		self::assertSame('1000', self::format('{$n}', ['n' => 1000]));
		self::assertSame('1.3', MessageFormatter::for('fr')->format('{$n}', ['n' => 1.3]));
	}
}
