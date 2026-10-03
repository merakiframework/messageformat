<?php
declare(strict_types=1);

namespace Meraki\MessageFormat\Syntax;

use Meraki\MessageFormat\Error\SyntaxError;
use Meraki\MessageFormat\Error\Unsupported;
use Meraki\MessageFormat\Model\Serializer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * `simple-message`, against the spec's own data model.
 *
 * Assertions are made on the serialised form rather than by walking objects, because the
 * serialised form *is* spec/data-model/message.json. A test that asserted on getters would be
 * asserting this library's shape; this way it asserts the shape the specification defines, and
 * the same arrays can be compared against other implementations.
 */
#[Group('syntax')]
#[CoversClass(Parser::class)]
final class ParserTest extends TestCase
{
	/** @return array<string, mixed> */
	private static function parse(string $source): array
	{
		return Serializer::toArray(Parser::parse($source));
	}

	/**
	 * @param list<mixed> $pattern
	 * @return array<string, mixed>
	 */
	private static function message(array $pattern): array
	{
		return ['type' => 'message', 'declarations' => [], 'pattern' => $pattern];
	}

	#[Test]
	public function an_empty_string_is_a_valid_message_with_an_empty_pattern(): void
	{
		self::assertSame(self::message([]), self::parse(''));
	}

	#[Test]
	public function text_is_one_pattern_element(): void
	{
		self::assertSame(self::message(['hello']), self::parse('hello'));
	}

	/** @return iterable<string, array{string, string}> */
	public static function significantWhitespace(): iterable
	{
		// "Whitespace at the start or end of a simple message is significant, and a part of the
		// text of the message." The leading `o` in `simple-message = o [simple-start pattern]`
		// exists so that the first NON-whitespace character can be constrained by
		// simple-start-char -- it does not discard what it matched.
		yield 'a leading newline and space' => ["\n hello\t", "\n hello\t"];
		yield 'a leading space' => [' hello', ' hello'];
		yield 'a trailing space' => ['hello ', 'hello '];
		yield 'whitespace on both sides' => ["\t hello \n", "\t hello \n"];
		yield 'nothing but whitespace' => ['   ', '   '];
		yield 'an ideographic space' => ["\u{3000}hi", "\u{3000}hi"];
	}

	#[Test]
	#[DataProvider('significantWhitespace')]
	public function whitespace_around_a_simple_message_is_text(string $source, string $text): void
	{
		self::assertSame(self::message([$text]), self::parse($source));
	}

	/** @return iterable<string, array{string, string}> */
	public static function escapes(): iterable
	{
		yield 'a backslash' => ['\\\\', '\\'];
		yield 'an opening brace' => ['\\{', '{'];
		yield 'a closing brace' => ['\\}', '}'];
		yield 'a pipe' => ['\\|', '|'];
		yield 'all four in a row' => ['\\\\\\{\\|\\}', '\\{|}'];
		yield 'an escape inside text' => ['a\\{b', 'a{b'];
	}

	#[Test]
	#[DataProvider('escapes')]
	public function an_escape_contributes_the_character_it_names(string $source, string $text): void
	{
		self::assertSame(self::message([$text]), self::parse($source));
	}

	#[Test]
	public function text_and_a_placeholder_are_separate_pattern_elements(): void
	{
		self::assertSame(
			self::message([
				'hello ',
				['type' => 'expression', 'arg' => ['type' => 'variable', 'name' => 'place']],
			]),
			self::parse('hello {$place}'),
		);
	}

	#[Test]
	public function an_unquoted_literal_is_an_expression_argument(): void
	{
		self::assertSame(
			self::message([['type' => 'expression', 'arg' => ['type' => 'literal', 'value' => 'foo']]]),
			self::parse('{foo}'),
		);
	}

	/** @return iterable<string, array{string, string}> */
	public static function quotedLiterals(): iterable
	{
		yield 'with a space' => ['{|a b|}', 'a b'];
		yield 'empty' => ['{||}', ''];
		// The pipe is the delimiter, so it is the one character that must be escaped inside.
		// Braces need no escape here, which is why a pack can write a separator containing one.
		yield 'an escaped pipe' => ['{|a\\|b|}', 'a|b'];
		yield 'a brace, unescaped' => ['{|a{b|}', 'a{b'];
		yield 'a leading dot' => ['{|.input|}', '.input'];
	}

	#[Test]
	#[DataProvider('quotedLiterals')]
	public function a_quoted_literal_carries_exactly_what_is_between_the_pipes(
		string $source,
		string $value,
	): void {
		self::assertSame(
			self::message([['type' => 'expression', 'arg' => ['type' => 'literal', 'value' => $value]]]),
			self::parse($source),
		);
	}

	#[Test]
	public function a_function_with_no_operand_is_an_expression_with_only_a_function(): void
	{
		self::assertSame(
			self::message([['type' => 'expression', 'function' => ['type' => 'function', 'name' => 'number']]]),
			self::parse('{:number}'),
		);
	}

	#[Test]
	public function a_function_with_no_operand_still_carries_its_options(): void
	{
		// `function-expression = "{" o function *(s attribute) o "}"` and
		// `function = ":" identifier *(s option)`, so these options belong to the function even
		// though there is no operand for it to act on.
		self::assertSame(
			self::message([[
				'type' => 'expression',
				'function' => [
					'type' => 'function',
					'name' => 'number',
					'options' => ['minimumFractionDigits' => ['type' => 'literal', 'value' => '2']],
				],
			]]),
			self::parse('{:number minimumFractionDigits=2}'),
		);
	}

	#[Test]
	public function a_function_with_no_operand_can_carry_attributes_too(): void
	{
		self::assertSame(
			self::message([[
				'type' => 'expression',
				'function' => ['type' => 'function', 'name' => 'number'],
				'attributes' => ['translate' => true],
			]]),
			self::parse('{:number @translate}'),
		);
	}

	#[Test]
	public function options_and_attributes_are_told_apart_after_a_function(): void
	{
		self::assertSame(
			self::message([[
				'type' => 'expression',
				'arg' => ['type' => 'variable', 'name' => 'n'],
				'function' => [
					'type' => 'function',
					'name' => 'number',
					'options' => ['useGrouping' => ['type' => 'literal', 'value' => 'never']],
				],
				'attributes' => ['translate' => true],
			]]),
			self::parse('{$n :number useGrouping=never @translate}'),
		);
	}

	#[Test]
	public function a_variable_can_carry_a_function(): void
	{
		self::assertSame(
			self::message([[
				'type' => 'expression',
				'arg' => ['type' => 'variable', 'name' => 'count'],
				'function' => ['type' => 'function', 'name' => 'number'],
			]]),
			self::parse('{$count :number}'),
		);
	}

	#[Test]
	public function a_function_name_may_carry_a_namespace(): void
	{
		self::assertSame(
			self::message([['type' => 'expression', 'function' => ['type' => 'function', 'name' => 'ns:fn']]]),
			self::parse('{:ns:fn}'),
		);
	}

	#[Test]
	public function function_options_are_a_map_in_source_order(): void
	{
		self::assertSame(
			self::message([[
				'type' => 'expression',
				'arg' => ['type' => 'variable', 'name' => 'n'],
				'function' => [
					'type' => 'function',
					'name' => 'number',
					'options' => [
						'minimumFractionDigits' => ['type' => 'literal', 'value' => '2'],
						'useGrouping' => ['type' => 'literal', 'value' => 'never'],
					],
				],
			]]),
			self::parse('{$n :number minimumFractionDigits=2 useGrouping=never}'),
		);
	}

	#[Test]
	public function an_option_value_may_be_a_variable(): void
	{
		self::assertSame(
			self::message([[
				'type' => 'expression',
				'arg' => ['type' => 'variable', 'name' => 'n'],
				'function' => [
					'type' => 'function',
					'name' => 'number',
					'options' => ['maximumFractionDigits' => ['type' => 'variable', 'name' => 'places']],
				],
			]]),
			self::parse('{$n :number maximumFractionDigits=$places}'),
		);
	}

	#[Test]
	public function an_attribute_without_a_value_is_true(): void
	{
		self::assertSame(
			self::message([[
				'type' => 'expression',
				'arg' => ['type' => 'variable', 'name' => 'n'],
				'attributes' => ['translate' => true],
			]]),
			self::parse('{$n @translate}'),
		);
	}

	#[Test]
	public function an_attribute_with_a_value_carries_a_literal(): void
	{
		self::assertSame(
			self::message([[
				'type' => 'expression',
				'arg' => ['type' => 'variable', 'name' => 'n'],
				'attributes' => ['locale' => ['type' => 'literal', 'value' => 'fr']],
			]]),
			self::parse('{$n @locale=fr}'),
		);
	}

	/** @return iterable<string, array{string, string, string}> */
	public static function markup(): iterable
	{
		yield 'open' => ['{#b}', 'open', 'b'];
		yield 'close' => ['{/b}', 'close', 'b'];
		yield 'standalone' => ['{#br /}', 'standalone', 'br'];
		yield 'standalone with no space' => ['{#br/}', 'standalone', 'br'];
		yield 'namespaced' => ['{#ns:tag}', 'open', 'ns:tag'];
	}

	#[Test]
	#[DataProvider('markup')]
	public function markup_is_a_pattern_element_of_its_own(string $source, string $kind, string $name): void
	{
		self::assertSame(
			self::message([['type' => 'markup', 'kind' => $kind, 'name' => $name]]),
			self::parse($source),
		);
	}

	#[Test]
	public function markup_carries_options_and_attributes(): void
	{
		self::assertSame(
			self::message([[
				'type' => 'markup',
				'kind' => 'standalone',
				'name' => 'img',
				'options' => ['src' => ['type' => 'literal', 'value' => 'a.png']],
				'attributes' => ['alt' => ['type' => 'literal', 'value' => 'x']],
			]]),
			self::parse('{#img src=a.png @alt=x /}'),
		);
	}

	#[Test]
	public function whitespace_is_allowed_inside_a_placeholder(): void
	{
		self::assertSame(
			self::message([[
				'type' => 'expression',
				'arg' => ['type' => 'variable', 'name' => 'n'],
				'function' => ['type' => 'function', 'name' => 'number'],
			]]),
			self::parse('{  $n   :number  }'),
		);
	}

	/** @return iterable<string, array{string}> */
	public static function malformed(): iterable
	{
		yield 'a closing brace with no opener' => ['}'];
		yield 'an opening brace that is never closed' => ['{'];
		yield 'an empty placeholder' => ['{}'];
		yield 'an escape of something not escapable' => ['\\n'];
		yield 'a trailing backslash' => ['\\'];
		yield 'a quoted literal that is never closed' => ['{|abc}'];
		yield 'a variable with no name' => ['{$}'];
		yield 'a function with no name' => ['{:}'];
		yield 'an option with no value' => ['{$n :fn opt=}'];
		yield 'a name starting with a digit' => ['{$1x}'];
		yield 'markup with no name' => ['{#}'];
		yield 'a stray closing brace after a placeholder' => ['{$x}}'];
	}

	#[Test]
	#[DataProvider('malformed')]
	public function it_refuses_what_is_not_well_formed(string $source): void
	{
		$this->expectException(SyntaxError::class);

		Parser::parse($source);
	}

	#[Test]
	public function a_syntax_error_says_where_it_is(): void
	{
		try {
			Parser::parse("ab\n}cd");
			self::fail('expected a SyntaxError');
		} catch (SyntaxError $error) {
			self::assertStringContainsString('line 2, column 1', $error->getMessage());
		}
	}

	/** @return iterable<string, array{string}> */
	public static function complexMessages(): iterable
	{
		// Well-formed, and not simple-message. Refusing these as syntax errors would be a lie
		// about them; they belong to the next milestone, and Unsupported says so by name.
		yield 'an input declaration' => ['.input {$x} {{hello}}'];
		yield 'a local declaration' => ['.local $x = {1} {{hello}}'];
		yield 'a matcher' => ['.match $x one {{a}} * {{b}}'];
		yield 'a quoted pattern alone' => ['{{hello}}'];
		yield 'a quoted pattern with leading whitespace' => ['  {{hello}}'];
	}

	#[Test]
	#[DataProvider('complexMessages')]
	public function a_complex_message_is_not_yet_implemented_rather_than_malformed(string $source): void
	{
		$this->expectException(Unsupported::class);

		Parser::parse($source);
	}

	#[Test]
	public function a_lone_dot_is_malformed_rather_than_unimplemented(): void
	{
		// `.` begins no declaration and no quoted pattern, so it is not a complex message that
		// this milestone has yet to reach -- it is not a message at all.
		$this->expectException(SyntaxError::class);

		Parser::parse('.');
	}
}
