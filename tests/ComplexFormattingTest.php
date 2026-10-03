<?php
declare(strict_types=1);

namespace Meraki\MessageFormat;

use Meraki\MessageFormat\Error\Unsupported;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Formatting a complex message: declarations bind, quoted patterns print.
 *
 * Every expectation here comes from a fixture, because three of the rules are not guessable:
 * declarations are evaluated **lazily**, a local declaration **shadows** an external argument of
 * the same name, and variable names are matched under **NFC**.
 */
#[Group('formatting')]
#[CoversClass(MessageFormatter::class)]
#[CoversClass(Runtime\Bindings::class)]
final class ComplexFormattingTest extends TestCase
{
	/** @param array<string, mixed> $arguments */
	private static function format(string $source, array $arguments = []): string
	{
		return MessageFormatter::for('en-US')->format($source, $arguments);
	}

	/** @return iterable<string, array{string, string}> */
	public static function quotedPatterns(): iterable
	{
		yield 'plain' => ['{{hello}}', 'hello'];
		yield 'empty' => ['{{}}', ''];
		// Outside the braces is insignificant, inside is text — the opposite of a simple message.
		yield 'outer whitespace dropped' => ['  {{hello}}  ', 'hello'];
		yield 'inner whitespace kept' => ['{{ hello }}', ' hello '];
		yield 'a keyword inside is text' => ['{{.input $x}}', '.input $x'];
	}

	#[Test]
	#[DataProvider('quotedPatterns')]
	public function a_quoted_pattern_formats_its_contents(string $source, string $expected): void
	{
		self::assertSame($expected, self::format($source));
	}

	/** @return iterable<string, array{string, array<string, mixed>, string}> */
	public static function declarations(): iterable
	{
		// All from syntax.json, cases 91 to 99.
		yield 'a local bound to an unquoted literal' => [
			'.local $foo = {bar} {{bar {$foo}}}', [], 'bar bar',
		];
		yield 'a local bound to a quoted literal' => [
			'.local $foo = {|bar|} {{bar {$foo}}}', [], 'bar bar',
		];
		yield 'a local bound to a variable' => [
			'.local $foo = {$bar} {{bar {$foo}}}', ['bar' => 'foo'], 'bar foo',
		];
		yield 'a chain of two locals' => [
			'.local $foo = {$baz} .local $bar = {$foo} {{bar {$bar}}}', ['baz' => 'foo'], 'bar foo',
		];
		yield 'an input declaration' => [
			'.input {$foo} {{bar {$foo}}}', ['foo' => 'foo'], 'bar foo',
		];
		yield 'a local reading an input' => [
			'.input {$foo} .local $bar = {$foo} {{bar {$bar}}}', ['foo' => 'foo'], 'bar foo',
		];
		yield 'one local read twice' => [
			'.local $x = {42} .local $y = {$x} {{{$x} {$y}}}', [], '42 42',
		];
	}

	/** @param array<string, mixed> $arguments */
	#[Test]
	#[DataProvider('declarations')]
	public function a_declaration_binds_a_name_for_the_pattern(
		string $source,
		array $arguments,
		string $expected,
	): void {
		self::assertSame($expected, self::format($source, $arguments));
	}

	#[Test]
	public function a_local_declaration_shadows_an_argument_of_the_same_name(): void
	{
		// syntax.json[93]: the argument `foo` is supplied as "foo" and ignored, because the
		// declaration binds the name to "bar". A declaration is not a default.
		self::assertSame(
			'bar bar',
			self::format('.local $foo = {|bar|} {{bar {$foo}}}', ['foo' => 'foo']),
		);
	}

	/** @return iterable<string, array{string}> */
	public static function unusedDeclarations(): iterable
	{
		// syntax.json 21 to 31, every one of which expects "" and **no errors** — even with no
		// arguments supplied at all. So a declaration is evaluated only if the pattern reads it.
		yield 'one input' => ['.input{$x}{{}}'];
		yield 'two inputs' => ['.input{$x}.input{$y}{{}}'];
		yield 'a local and an input' => ['.local $x ={a}.input{$y}{{}}'];
		yield 'surrounded by whitespace' => ["\t.input{\$x}{{}}\n"];
	}

	#[Test]
	#[DataProvider('unusedDeclarations')]
	public function a_declaration_the_pattern_never_reads_is_never_evaluated(string $source): void
	{
		$result = MessageFormatter::for('en-US')->formatWithDiagnostics($source, []);

		self::assertSame('', $result->text);
		self::assertSame([], $result->errors, 'an unused declaration must not report anything');
	}

	#[Test]
	public function an_unresolved_variable_is_reported_once_however_often_it_is_read(): void
	{
		// The specification requires each expression to be evaluated at most once. Reading the
		// same unresolved name twice is still one failure.
		$result = MessageFormatter::for('en-US')->formatWithDiagnostics('{$a} {$a}', []);

		self::assertSame('{$a} {$a}', $result->text);
		self::assertCount(1, $result->errors);
	}

	/** @return iterable<string, array{string, array<string, mixed>}> */
	public static function normalisedNames(): iterable
	{
		// syntax.json 108 to 113. The two spellings are canonically equivalent, so they are the
		// same variable; comparing bytes would make a message that reads identically fail.
		yield 'declared decomposed, read composed' => [
			".local \$\u{0044}\u{0323}\u{0307} = {foo} {{{\$\u{1E0C}\u{0307}}}}", [],
		];
		yield 'declared composed, read decomposed' => [
			".local \$\u{1E0C}\u{0307} = {foo} {{{\$\u{0044}\u{0323}\u{0307}}}}", [],
		];
		yield 'combining marks in the other order' => [
			".local \$\u{0044}\u{0307}\u{0323} = {foo} {{{\$\u{1E0C}\u{0307}}}}", [],
		];
		yield 'a different letter entirely' => [
			".local \$\u{0041}\u{030A}\u{0301} = {foo} {{{\$\u{01FA}}}}", [],
		];
		yield 'an argument name, not a declaration' => [
			".input {\$\u{1E0C}\u{0307}} {{{\$\u{0044}\u{0323}\u{0307}}}}", ["\u{1E0C}\u{0307}" => 'foo'],
		];
	}

	/** @param array<string, mixed> $arguments */
	#[Test]
	#[DataProvider('normalisedNames')]
	public function variable_names_are_matched_after_normalisation(
		string $source,
		array $arguments,
	): void {
		self::assertSame('foo', self::format($source, $arguments));
	}

	#[Test]
	public function a_matcher_cannot_be_formatted_yet_and_says_so(): void
	{
		// The last remaining use of Unsupported. A valid matcher always has an annotated
		// selector, so executing one needs the function registry; parsing and validating it does
		// not, which is why this milestone goes this far and no further.
		$this->expectException(Unsupported::class);

		self::format('.input {$n :number} .match $n one {{a}} * {{b}}');
	}

	#[Test]
	public function a_declaration_with_an_unknown_function_falls_back_where_it_is_read(): void
	{
		// fallback.json[4]: the fallback names the placeholder in the pattern, `{$var}`, not the
		// literal inside the declaration.
		self::assertSame('{$var}', self::format('.local $var = {|val| :test:undefined} {{{$var}}}'));
	}
}
