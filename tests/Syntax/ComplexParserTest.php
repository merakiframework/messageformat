<?php
declare(strict_types=1);

namespace Meraki\MessageFormat\Syntax;

use Meraki\MessageFormat\Error\SyntaxError;
use Meraki\MessageFormat\Model\Serializer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * `complex-message`: declarations, quoted patterns and the matcher.
 *
 * The mirror of {@see ParserTest}, which covers `simple-message`. The two are worth keeping apart
 * because the one thing they share is the hardest thing to get right: a complex message's leading
 * and trailing whitespace is **insignificant**, and a simple message's is text. Fixtures 25 and 7
 * of syntax.json pin the two halves of that, and reading them side by side in one file made the
 * difference look like an inconsistency rather than the rule.
 */
#[Group('syntax')]
#[CoversClass(Parser::class)]
final class ComplexParserTest extends TestCase
{
	/** @return array<array-key, mixed> */
	private static function parse(string $source): array
	{
		return Serializer::toArray(Parser::parse($source));
	}

	/**
	 * @param list<mixed> $pattern
	 * @param list<mixed> $declarations
	 * @return array<string, mixed>
	 */
	private static function message(array $pattern, array $declarations = []): array
	{
		return ['type' => 'message', 'declarations' => $declarations, 'pattern' => $pattern];
	}

	/** @return array<string, mixed> */
	private static function variable(string $name): array
	{
		return ['type' => 'variable', 'name' => $name];
	}

	/** @return array<string, mixed> */
	private static function literal(string $value): array
	{
		return ['type' => 'literal', 'value' => $value];
	}

	// ------------------------------------------------------------------ quoted patterns

	#[Test]
	public function a_quoted_pattern_alone_is_a_message_with_no_declarations(): void
	{
		self::assertSame(self::message(['hello']), self::parse('{{hello}}'));
	}

	#[Test]
	public function an_empty_quoted_pattern_is_valid(): void
	{
		self::assertSame(self::message([]), self::parse('{{}}'));
	}

	/** @return iterable<string, array{string, list<mixed>}> */
	public static function quotedPatternWhitespace(): iterable
	{
		// Outside the braces is insignificant; inside is text. syntax.json pins both:
		// case 25 gives "" for "  {{}}  ", and case 33 keeps the space in "{{ .local ...}}".
		yield 'stripped on both sides' => ['  {{}}  ', []];
		yield 'stripped around content' => ["\t{{hi}}\n", ['hi']];
		yield 'kept inside' => ['{{ hi }}', [' hi ']];
		yield 'a leading dot inside is text' => ['{{.input}}', ['.input']];
	}

	/**
	 * @param list<mixed> $pattern
	 */
	#[Test]
	#[DataProvider('quotedPatternWhitespace')]
	public function whitespace_outside_a_quoted_pattern_is_not_part_of_it(
		string $source,
		array $pattern,
	): void {
		self::assertSame(self::message($pattern), self::parse($source));
	}

	#[Test]
	public function a_declaration_keyword_inside_a_quoted_pattern_is_just_text(): void
	{
		// syntax.json[33]. Inside a pattern, `.` is an ordinary text-char, so none of this is a
		// declaration — including the `{$y}` which is a real placeholder in the middle of it.
		self::assertSame(
			self::message([' .local $x = ', ['type' => 'expression', 'arg' => self::variable('y')]]),
			self::parse('{{ .local $x = {$y}}}'),
		);
	}

	// -------------------------------------------------------------------- declarations

	#[Test]
	public function an_input_declaration_names_the_variable_in_its_expression(): void
	{
		self::assertSame(
			self::message([], [[
				'type' => 'input',
				'name' => 'x',
				'value' => ['type' => 'expression', 'arg' => self::variable('x')],
			]]),
			self::parse('.input {$x} {{}}'),
		);
	}

	#[Test]
	public function a_local_declaration_names_its_own_variable(): void
	{
		self::assertSame(
			self::message([], [[
				'type' => 'local',
				'name' => 'x',
				'value' => ['type' => 'expression', 'arg' => self::literal('1')],
			]]),
			self::parse('.local $x = {1} {{}}'),
		);
	}

	/** @return iterable<string, array{string}> */
	public static function sparseWhitespace(): iterable
	{
		// `input o variable-expression` and `local s variable o "=" o expression`, so the only
		// required space in either is the one after `.local`. All of these are syntax.json cases.
		yield 'no space after .input' => ['.input{$x}{{}}'];
		yield 'space after .input' => ['.input {$x}{{}}'];
		yield 'no space before the equals' => ['.local $x= {a}{{}}'];
		yield 'no space after the equals' => ['.local $x ={a}{{}}'];
		yield 'spaces around the equals' => ['.local $x = {a}{{}}'];
		yield 'no space before the quoted pattern' => ['.local $x = {a}{{}}'];
	}

	#[Test]
	#[DataProvider('sparseWhitespace')]
	public function whitespace_between_the_parts_of_a_declaration_is_mostly_optional(string $source): void
	{
		// Asserted only as "parses", because what each one produces is covered above.
		self::assertArrayHasKey('declarations', self::parse($source));
	}

	#[Test]
	public function declarations_keep_their_source_order(): void
	{
		self::assertSame(
			self::message([], [
				[
					'type' => 'local',
					'name' => 'x',
					'value' => ['type' => 'expression', 'arg' => self::literal('a')],
				],
				[
					'type' => 'input',
					'name' => 'y',
					'value' => ['type' => 'expression', 'arg' => self::variable('y')],
				],
			]),
			self::parse('.local $x ={a}.input{$y}{{}}'),
		);
	}

	#[Test]
	public function a_declaration_may_carry_a_function_and_options(): void
	{
		self::assertSame(
			self::message([], [[
				'type' => 'input',
				'name' => 'n',
				'value' => [
					'type' => 'expression',
					'arg' => self::variable('n'),
					'function' => [
						'type' => 'function',
						'name' => 'number',
						'options' => ['minimumFractionDigits' => self::literal('2')],
					],
				],
			]]),
			self::parse('.input {$n :number minimumFractionDigits=2} {{}}'),
		);
	}

	// ------------------------------------------------------------------------ matchers

	#[Test]
	public function a_matcher_becomes_a_select_message(): void
	{
		self::assertSame(
			[
				'type' => 'select',
				'declarations' => [[
					'type' => 'input',
					'name' => 'n',
					'value' => [
						'type' => 'expression',
						'arg' => self::variable('n'),
						'function' => ['type' => 'function', 'name' => 'number'],
					],
				]],
				'selectors' => [self::variable('n')],
				'variants' => [
					['keys' => [self::literal('one')], 'value' => ['a']],
					['keys' => [['type' => '*']], 'value' => ['b']],
				],
			],
			self::parse('.input {$n :number} .match $n one {{a}} * {{b}}'),
		);
	}

	#[Test]
	public function a_matcher_can_have_several_selectors_and_several_keys(): void
	{
		$parsed = self::parse('.input {$a :f} .input {$b :f} .match $a $b 1 2 {{x}} * * {{y}}');

		self::assertSame('select', $parsed['type']);
		self::assertSame([self::variable('a'), self::variable('b')], $parsed['selectors']);
		self::assertSame(
			[
				['keys' => [self::literal('1'), self::literal('2')], 'value' => ['x']],
				['keys' => [['type' => '*'], ['type' => '*']], 'value' => ['y']],
			],
			$parsed['variants'],
		);
	}

	#[Test]
	public function a_variant_key_may_be_a_quoted_literal(): void
	{
		// data-model-errors.json[22] relies on this: `|*|` is the literal asterisk, which is a
		// different key from the catch-all `*`.
		$parsed = self::parse('.local $s = {star :string} .match $s |*| {{literal}} * {{fallback}}');

		self::assertSame(
			[
				['keys' => [self::literal('*')], 'value' => ['literal']],
				['keys' => [['type' => '*']], 'value' => ['fallback']],
			],
			$parsed['variants'],
		);
	}

	/** @return iterable<string, array{string}> */
	public static function matcherSpacing(): iterable
	{
		// `matcher = match-statement s variant *(o variant)`, and
		// `variant = key *(s key) o quoted-pattern`.
		yield 'no space anywhere optional' => ['.local $a={a :f}.match $a a{{}}*{{}}'];
		yield 'space before the pattern' => ['.local $a={a :f}.match $a a {{}}*{{}}'];
		yield 'three variants' => ['.local $a={a :f}.match $a a{{}}b{{}}*{{}}'];
		yield 'only a catch-all' => ['.local $a={a :f}.match $a *{{}}'];
	}

	#[Test]
	#[DataProvider('matcherSpacing')]
	public function a_matcher_tolerates_the_whitespace_the_grammar_makes_optional(string $source): void
	{
		self::assertSame('select', self::parse($source)['type']);
	}

	/** @return iterable<string, array{string}> */
	public static function missingRequiredWhitespace(): iterable
	{
		// `matcher = match-statement s variant`, and `s = *bidi ws o`. So the separator before
		// the first variant is required, and — the part that is easy to miss — a bidi mark on
		// its own does not provide it. All three are fixtures: syntax-errors.json 58 and 59, and
		// bidi.json[11].
		yield 'nothing between selector and catch-all' => ['.input {$x :x} .match $x* {{foo}}'];
		yield 'nothing between selector and a literal key' => [
			'.input {$x :x} .match $x|x| {{foo}} * {{foo}}',
		];
		yield 'only an arabic letter mark between them' => [
			".local \$x = {1 :number}.match \$x\u{061C}1 {{one}}* {{other}}",
		];
		yield 'nothing after .match' => ['.input {$x :x} .match$x * {{foo}}'];
	}

	#[Test]
	#[DataProvider('missingRequiredWhitespace')]
	public function required_whitespace_is_not_satisfied_by_a_bidi_mark(string $source): void
	{
		// Conflating `s` with `o` in one helper accepted every one of these. They are the reason
		// the parser has two whitespace methods rather than one that returns a count.
		$this->expectException(SyntaxError::class);

		Parser::parse($source);
	}

	#[Test]
	public function a_bidi_mark_is_still_fine_where_the_grammar_asks_only_for_o(): void
	{
		// `o = *(ws / bidi)`, so the same character that cannot stand in for `s` is perfectly
		// good as optional whitespace. Refusing it everywhere would be the opposite mistake.
		self::assertSame('select', self::parse(".local \$x = {1 :number}\u{200E}.match \$x 1 {{one}}* {{other}}")['type']);
	}

	// -------------------------------------------------------------------------- refusals

	/** @return iterable<string, array{string}> */
	public static function malformed(): iterable
	{
		yield 'a bare dot' => ['.'];
		yield 'an unknown keyword' => ['.foo {{}}'];
		yield 'a declaration with no body' => ['.input {$x}'];
		yield 'input with a literal operand' => ['.input {42} {{}}'];
		yield 'local with no equals' => ['.local $x {1} {{}}'];
		yield 'local with no space after the keyword' => ['.local$x = {1} {{}}'];
		yield 'a quoted pattern never closed' => ['{{hello'];
		yield 'a quoted pattern closed once' => ['{{hello}'];
		yield 'a matcher with no variant' => ['.local $a={a :f}.match $a'];
		yield 'a matcher with no selector' => ['.match {{a}}'];
		yield 'a variant with no pattern' => ['.local $a={a :f}.match $a *'];
		yield 'a selector that is not a variable' => ['.match 42 *{{a}}'];
		yield 'text after the complex body' => ['{{a}} trailing'];
		yield 'a declaration after the body' => ['{{a}} .input {$x}'];
	}

	#[Test]
	#[DataProvider('malformed')]
	public function it_refuses_what_is_not_well_formed(string $source): void
	{
		$this->expectException(SyntaxError::class);

		Parser::parse($source);
	}

	#[Test]
	public function a_complex_message_is_no_longer_refused_as_unimplemented(): void
	{
		// The whole point of this milestone. Error\Unsupported survives only for executing a
		// matcher, which needs the function registry.
		self::assertSame(self::message(['hello']), self::parse('{{hello}}'));
		self::assertArrayHasKey('declarations', self::parse('.input {$x} {{}}'));
	}
}
