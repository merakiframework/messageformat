<?php
declare(strict_types=1);

namespace Meraki\MessageFormat\Model;

use Meraki\MessageFormat\Error\DataModelError;
use Meraki\MessageFormat\Error\ErrorType;
use Meraki\MessageFormat\Syntax\Parser;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The six Data Model Errors.
 *
 * Driven through {@see Parser::parse()} rather than by assembling model nodes by hand, because
 * the point of each rule is that a **well-formed** message can still be invalid. Building the
 * nodes directly would let a test construct something the parser could never produce, and then
 * prove nothing about any message anybody could write.
 *
 * Two of the six are much broader than their names, and both were read off errors.md rather than
 * guessed:
 *
 * - *Duplicate Declaration* covers using a variable and **then** declaring it, because "an input
 *   variable is implicitly declared when it is first used". That is why `.local $foo = {$bar}`
 *   followed by `.local $bar = {42}` is an error, and why `.local $foo = {$foo}` is one too.
 * - *Missing Selector Annotation* follows the declaration chain, so a selector pointing at a
 *   declaration that points at an unannotated one is still unannotated.
 */
#[Group('model')]
#[CoversClass(Validator::class)]
final class ValidatorTest extends TestCase
{
	private static function expect(ErrorType $type, string $source): void
	{
		$caught = null;

		try {
			Parser::parse($source);
		} catch (DataModelError $error) {
			$caught = $error;
		}

		self::assertNotNull($caught, sprintf('expected %s for %s', $type->slug(), $source));
		self::assertSame($type, $caught->type(), $source);
	}

	/** @return iterable<string, array{string}> */
	public static function variantKeyMismatch(): iterable
	{
		// Both from errors.md.
		yield 'too many keys' => ['.input {$one :ns:func} .match $one 1 2 {{Too many}} * {{Otherwise}}'];
		yield 'too few keys' => [
			'.input {$one :ns:func} .input {$two :ns:func} .match $one $two'
				. ' 1 2 {{Two keys}} * {{Missing a key}} * * {{Otherwise}}',
		];
	}

	#[Test]
	#[DataProvider('variantKeyMismatch')]
	public function a_variant_must_have_one_key_per_selector(string $source): void
	{
		self::expect(ErrorType::VariantKeyMismatch, $source);
	}

	/** @return iterable<string, array{string}> */
	public static function missingFallbackVariant(): iterable
	{
		yield 'no catch-all at all' => [
			'.input {$one :ns:func} .match $one 1 {{Value is one}} 2 {{Value is two}}',
		];
		yield 'catch-all in every position but never together' => [
			'.input {$one :ns:func} .input {$two :ns:func} .match $one $two'
				. ' 1 * {{First is one}} * 1 {{Second is one}}',
		];
	}

	#[Test]
	#[DataProvider('missingFallbackVariant')]
	public function a_matcher_needs_a_variant_whose_keys_are_all_catch_all(string $source): void
	{
		self::expect(ErrorType::MissingFallbackVariant, $source);
	}

	/** @return iterable<string, array{string}> */
	public static function missingSelectorAnnotation(): iterable
	{
		yield 'no declaration at all' => ['.match $one 1 {{Value is one}} * {{Not one}}'];
		yield 'a local with a literal operand' => [
			'.local $one = {|The one|} .match $one 1 {{Value is one}} * {{Not one}}',
		];
		yield 'an unannotated input' => ['.input {$one} .match $one 1 {{Value is one}} * {{Not one}}'];
		// The chain is followed, so an unannotated declaration pointing at another unannotated
		// one is still unannotated. data-model-errors.json[7].
		yield 'a chain that never reaches a function' => [
			'.input {$bar} .local $foo = {$bar} .match $foo one {{one}} * {{other}}',
		];
	}

	#[Test]
	#[DataProvider('missingSelectorAnnotation')]
	public function a_selector_must_reach_a_declaration_with_a_function(string $source): void
	{
		self::expect(ErrorType::MissingSelectorAnnotation, $source);
	}

	/** @return iterable<string, array{string}> */
	public static function annotatedSelectors(): iterable
	{
		yield 'directly annotated input' => ['.input {$one :ns:f} .match $one * {{ok}}'];
		yield 'directly annotated local' => ['.local $one = {1 :ns:f} .match $one * {{ok}}'];
		yield 'annotated through one hop' => [
			'.input {$bar :ns:f} .local $foo = {$bar} .match $foo * {{ok}}',
		];
		yield 'annotated through two hops' => [
			'.input {$c :ns:f} .local $b = {$c} .local $a = {$b} .match $a * {{ok}}',
		];
	}

	#[Test]
	#[DataProvider('annotatedSelectors')]
	public function an_annotation_anywhere_in_the_chain_is_enough(string $source): void
	{
		// No exception: the message is valid even though nothing here can format it yet.
		self::assertInstanceOf(SelectMessage::class, Parser::parse($source));
	}

	/** @return iterable<string, array{string}> */
	public static function duplicateDeclaration(): iterable
	{
		yield 'the same input twice' => ['.input {$foo} .input {$foo} {{_}}'];
		yield 'input then local' => ['.input {$foo} .local $foo = {42} {{_}}'];
		yield 'local then input' => ['.local $foo = {42} .input {$foo} {{_}}'];
		yield 'the same local twice' => ['.local $foo = {:unknown} .local $foo = {42} {{_}}'];

		// These are the ones the name does not suggest. Using a variable implicitly declares it,
		// so declaring it afterwards is a redeclaration.
		yield 'used in an operand, then declared' => ['.local $foo = {$bar} .local $bar = {42} {{_}}'];
		yield 'referring to itself' => ['.local $foo = {$foo} {{_}}'];
		yield 'used two declarations earlier' => [
			'.local $foo = {$bar} .local $bar = {$baz} {{_}}',
		];
		yield 'used in an option, then declared' => [
			'.local $foo = {42 :func opt=$bar} .local $bar = {42} {{_}}',
		];
		yield 'referring to itself through an option' => ['.local $foo = {42 :func opt=$foo} {{_}}'];
		yield 'the implicit input of an option' => [
			'.input {$var :number minimumFractionDigits=$var2} .input {$var2 :number} {{_}}',
		];
	}

	#[Test]
	#[DataProvider('duplicateDeclaration')]
	public function a_variable_may_be_declared_once(string $source): void
	{
		self::expect(ErrorType::DuplicateDeclaration, $source);
	}

	#[Test]
	public function an_input_declaration_does_not_count_as_using_its_own_variable(): void
	{
		// `.input {$x}` declares `x` and annotates the external argument of that name. If the
		// operand counted as a use, every input declaration would be a self-reference and so a
		// Duplicate Declaration -- which would make the production unusable.
		self::assertInstanceOf(PatternMessage::class, Parser::parse('.input {$x :number} {{ok}}'));
	}

	#[Test]
	public function declaring_in_the_order_things_are_used_is_fine(): void
	{
		// The mirror of the "used then declared" cases: declared first, used after, which is the
		// order every working message is written in.
		self::assertInstanceOf(
			PatternMessage::class,
			Parser::parse('.local $bar = {42} .local $foo = {$bar} {{ok}}'),
		);
	}

	/** @return iterable<string, array{string}> */
	public static function duplicateVariant(): iterable
	{
		yield 'two catch-alls' => [
			'.input {$var :string} .match $var * {{The first default}} * {{The second default}}',
		];
		// `foo` and `|foo|` are the same key: quoting changes the syntax, not the value.
		yield 'quoted and unquoted spelling of one key' => [
			'.input {$x :string} .input {$y :string} .match $x $y'
				. ' * foo {{first}} bar * {{bar}} * |foo| {{second}} * * {{default}}',
		];
	}

	#[Test]
	#[DataProvider('duplicateVariant')]
	public function one_list_of_keys_may_be_used_once(string $source): void
	{
		self::expect(ErrorType::DuplicateVariant, $source);
	}

	#[Test]
	public function the_literal_asterisk_is_a_different_key_from_the_catch_all(): void
	{
		// data-model-errors.json[22], which expects no error at all. `|*|` matches the string
		// "*" and `*` matches anything, so a matcher using both is not repeating a key.
		self::assertInstanceOf(
			SelectMessage::class,
			Parser::parse('.local $star = {star :string} .match $star |*| {{Literal star}} * {{The default}}'),
		);
	}

	#[Test]
	public function a_duplicate_option_is_still_reported_from_the_parser(): void
	{
		// The one Data Model Error this class does not own, and deliberately: the options map is
		// keyed by name, so a duplicate cannot survive into the model to be found later. It has
		// to be caught while the map is being built.
		self::expect(ErrorType::DuplicateOptionName, 'bad {:placeholder option=x option=x}');
	}
}
