<?php
declare(strict_types=1);

namespace Meraki\MessageFormat;

use Meraki\MessageFormat\Error\FallbackString;
use Meraki\MessageFormat\Error\MessageFormatError;
use Meraki\MessageFormat\Error\ResolutionError;
use Meraki\MessageFormat\Error\Unsupported;
use Meraki\MessageFormat\Icu\Normalisation;
use Meraki\MessageFormat\Model\Expression;
use Meraki\MessageFormat\Model\InputDeclaration;
use Meraki\MessageFormat\Model\Literal;
use Meraki\MessageFormat\Model\Markup;
use Meraki\MessageFormat\Model\Message;
use Meraki\MessageFormat\Model\PatternMessage;
use Meraki\MessageFormat\Model\VariableRef;
use Meraki\MessageFormat\Runtime\Bindings;
use Meraki\MessageFormat\Syntax\Parser;
use Stringable;

/**
 * Source and arguments in, a string out.
 *
 * ### No functions are registered, and that is conformant
 *
 * There is no function registry yet, so every `:name` is an **Unknown Function** — which is a
 * Resolution Error the specification answers with a fallback value and a continued format, not
 * an implementation gap to apologise for. `{:number}` formatting to `{:number}` is the behaviour
 * the spec asks of a formatter that does not know `:number`. What the registry changes is which
 * names are unknown, not what happens to the ones that are.
 *
 * ### Declarations are lazy, and that is load-bearing
 *
 * A declaration is evaluated the first time the pattern reads it, and never if it does not.
 * syntax.json expects `.input{$x}{{}}` with no arguments at all to format to the empty string
 * with **no errors**, so evaluating declarations up front would invent an unresolved-variable
 * error for a message the specification says is clean. Results are memoised, which is also what
 * satisfies "every expression is evaluated at most once": reading `{$a} {$a}` with nothing bound
 * reports one error, not two.
 *
 * ### What is not here yet
 *
 * Executing a matcher. A valid `.match` always has an annotated selector — an unannotated one is
 * a Missing Selector Annotation error — so selection cannot run before the function registry
 * exists. Parsing and validating a matcher needs no registry, which is why this milestone goes
 * that far and stops. Formatted parts, and so any access to markup, arrive with the parts API.
 * `$locale` is held and not yet consulted, which is honest about nothing in this milestone
 * varying by it.
 */
final class MessageFormatter
{
	private function __construct(
		private readonly string $locale,
		private readonly ErrorHandling $errorHandling,
	) {
	}

	public static function for(
		string $locale,
		ErrorHandling $errorHandling = ErrorHandling::Fallback,
	): self {
		return new self($locale, $errorHandling);
	}

	/** The locale this formatter was built for. */
	public function locale(): string
	{
		return $this->locale;
	}

	/**
	 * @param array<string, mixed> $arguments
	 * @throws Error\SyntaxError if the message is not well-formed
	 * @throws Error\DataModelError if it is well-formed and still not a valid message
	 * @throws Unsupported if it uses something not implemented yet
	 * @throws MessageFormatError in strict mode, on the first resolution error
	 */
	public function format(string $source, array $arguments = []): string
	{
		return $this->formatWithDiagnostics($source, $arguments)->text;
	}

	/**
	 * The format, plus the errors that happened on the way.
	 *
	 * The honest entry point: {@see self::format()} is sugar over it. The specification requires
	 * both a result and a way to discover the errors, and a method returning only the string
	 * cannot provide the second.
	 *
	 * @param array<string, mixed> $arguments
	 * @throws Error\SyntaxError|Error\DataModelError|Unsupported|MessageFormatError
	 */
	public function formatWithDiagnostics(string $source, array $arguments = []): FormattedMessage
	{
		// Outside the error collector on purpose. A message that is malformed has no data model
		// and one that is invalid has no meaning, so there is nothing to fall back to, and the
		// spec prioritises both over every other error. Both modes raise.
		return $this->formatMessage(Parser::parse($source), $arguments);
	}

	/**
	 * @param array<string, mixed> $arguments
	 * @throws Unsupported if the message is a matcher
	 * @throws MessageFormatError in strict mode
	 */
	public function formatMessage(Message $message, array $arguments = []): FormattedMessage
	{
		if (!$message instanceof PatternMessage) {
			throw Unsupported::feature('choosing between the variants of a matcher');
		}

		$bindings = Bindings::of($message->declarations(), $arguments);
		$errors = [];
		$resolved = [];
		$text = '';

		foreach ($message->pattern as $element) {
			if (is_string($element)) {
				$text .= $element;
				continue;
			}

			if ($element instanceof Markup) {
				// Markup has no string rendering: the spec defines no vocabulary for it and
				// leaves it to the application, which reaches it through the formatted parts.
				continue;
			}

			$text .= $this->expression($element, $bindings, $resolved, $errors);
		}

		return new FormattedMessage($text, $errors);
	}

	/**
	 * One placeholder, as text.
	 *
	 * @param array<string, string|null> $resolved
	 * @param list<MessageFormatError> $errors
	 * @throws MessageFormatError in strict mode
	 */
	private function expression(
		Expression $expression,
		Bindings $bindings,
		array &$resolved,
		array &$errors,
	): string {
		// Checked before the operand, because with no registry there is nothing the operand
		// could be handed to. Once functions exist the operand resolves first, which is what
		// lets `{$missing :number}` report both an unresolved variable and a bad operand.
		if ($expression->function !== null) {
			$this->report(ResolutionError::unknownFunction($expression->function->name), $errors);

			return FallbackString::for($expression);
		}

		$operand = $expression->arg;

		if ($operand instanceof Literal) {
			return $operand->value;
		}

		if (!$operand instanceof VariableRef) {
			// Unreachable against a parsed message: the data model requires an operand or a
			// function, and the function was handled above.
			$this->report(
				ResolutionError::badOperand('the placeholder has neither an operand nor a function'),
				$errors,
			);

			return FallbackString::for($expression);
		}

		$value = $this->resolve($operand->name, $bindings, $resolved, $errors);

		return $value ?? FallbackString::for($expression);
	}

	/**
	 * A variable's value, evaluated once and remembered.
	 *
	 * Null means "could not be resolved", with the reason already reported. The caller turns that
	 * into a fallback string built from **its own** expression rather than from the declaration:
	 * fallback.json[4] formats `.local $var = {|val| :test:undefined} {{{$var}}}` as `{$var}`,
	 * naming the placeholder that was read and not the literal inside the declaration.
	 *
	 * @param array<string, string|null> $resolved
	 * @param list<MessageFormatError> $errors
	 * @throws MessageFormatError in strict mode
	 */
	private function resolve(
		string $name,
		Bindings $bindings,
		array &$resolved,
		array &$errors,
	): ?string {
		$key = Normalisation::nfc($name);

		if (array_key_exists($key, $resolved)) {
			return $resolved[$key];
		}

		// Written before resolving, so a re-entrant read answers null rather than recursing for
		// ever. A cycle requires using a variable before declaring it, which is a Duplicate
		// Declaration and already refused — this is here so that a change to that check cannot
		// turn into a hang.
		$resolved[$key] = null;

		$declaration = $bindings->declarationFor($key);

		if ($declaration === null) {
			// An implicit input: no declaration, so the argument is the whole of it.
			return $resolved[$key] = $this->argument($key, $name, $bindings, $errors);
		}

		if ($declaration->value->function !== null) {
			$this->report(ResolutionError::unknownFunction($declaration->value->function->name), $errors);

			return $resolved[$key] = null;
		}

		if ($declaration instanceof InputDeclaration) {
			// The operand of an input declaration *is* the declared variable, so it names the
			// external argument rather than something to resolve further.
			return $resolved[$key] = $this->argument($key, $name, $bindings, $errors);
		}

		$operand = $declaration->value->arg;

		if ($operand instanceof Literal) {
			return $resolved[$key] = $operand->value;
		}

		if ($operand instanceof VariableRef) {
			return $resolved[$key] = $this->resolve($operand->name, $bindings, $resolved, $errors);
		}

		return $resolved[$key] = null;
	}

	/**
	 * @param string $key the name in NFC
	 * @param string $name the name as written, for the error message
	 * @param list<MessageFormatError> $errors
	 * @throws MessageFormatError in strict mode
	 */
	private function argument(
		string $key,
		string $name,
		Bindings $bindings,
		array &$errors,
	): ?string {
		if (!$bindings->hasArgument($key)) {
			$this->report(ResolutionError::unresolvedVariable($name), $errors);

			return null;
		}

		return $this->asText($bindings->argument($key), $name, $errors);
	}

	/**
	 * An unannotated operand, as text.
	 *
	 * **A known divergence.** This converts plainly, and the specification does not: an
	 * unannotated numeric operand is formatted for the locale, so `{$one} et {$two}` in `fr` with
	 * 1.3 and 4.2 is "1,3 et 4,2" and not "1.3 et 4.2" — syntax.json[90] says so. Getting that
	 * right needs ICU, which belongs with the functions built on it, so until then this is wrong
	 * on purpose and the conformance runner skips the cases that prove it.
	 *
	 * @param list<MessageFormatError> $errors
	 * @throws MessageFormatError in strict mode
	 */
	private function asText(mixed $value, string $name, array &$errors): ?string
	{
		if (is_string($value)) {
			return $value;
		}

		if (is_int($value) || is_float($value)) {
			return (string) $value;
		}

		if ($value instanceof Stringable) {
			return (string) $value;
		}

		$this->report(
			ResolutionError::badOperand(sprintf(
				'"$%s" holds a %s, which has no text form without a function to give it one',
				$name,
				get_debug_type($value),
			)),
			$errors,
		);

		return null;
	}

	/**
	 * Record an error, or raise it.
	 *
	 * The one place the two modes differ, which is what keeps them two doors onto one engine
	 * rather than two code paths.
	 *
	 * @param list<MessageFormatError> $errors
	 * @throws MessageFormatError in strict mode
	 */
	private function report(MessageFormatError $error, array &$errors): void
	{
		if ($this->errorHandling === ErrorHandling::Strict) {
			throw $error;
		}

		$errors[] = $error;
	}
}
