<?php
declare(strict_types=1);

namespace Meraki\MessageFormat;

use Meraki\MessageFormat\Error\FallbackString;
use Meraki\MessageFormat\Error\MessageFormatError;
use Meraki\MessageFormat\Error\ResolutionError;
use Meraki\MessageFormat\Model\Expression;
use Meraki\MessageFormat\Model\Literal;
use Meraki\MessageFormat\Model\Markup;
use Meraki\MessageFormat\Model\Message;
use Meraki\MessageFormat\Model\VariableRef;
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
 * ### What is not here yet
 *
 * Complex messages — declarations, matchers, quoted patterns — raise
 * {@see \Meraki\MessageFormat\Error\Unsupported} from the parser. Formatted parts, and so any
 * access to markup, arrive with the parts API. Locale-aware number and date formatting arrives
 * with the functions that do it; `$locale` is held here and not yet consulted, which is honest
 * about the fact that nothing in this milestone varies by it.
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
	 * @throws Error\Unsupported if the message uses something not implemented yet
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
	 * @throws Error\SyntaxError|Error\Unsupported|MessageFormatError
	 */
	public function formatWithDiagnostics(string $source, array $arguments = []): FormattedMessage
	{
		// Outside the error collector on purpose. A malformed message has no data model, so
		// there is nothing to fall back to, and the spec prioritises syntax errors over all
		// others. Both modes raise.
		$message = Parser::parse($source);

		return $this->formatMessage($message, $arguments);
	}

	/**
	 * @param array<string, mixed> $arguments
	 * @throws MessageFormatError in strict mode
	 */
	public function formatMessage(Message $message, array $arguments = []): FormattedMessage
	{
		$text = '';
		$errors = [];

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

			$text .= $this->expression($element, $arguments, $errors);
		}

		return new FormattedMessage($text, $errors);
	}

	/**
	 * @param array<string, mixed> $arguments
	 * @param list<MessageFormatError> $errors
	 * @throws MessageFormatError in strict mode
	 */
	private function expression(Expression $expression, array $arguments, array &$errors): string
	{
		// Checked before the operand, because with no registry there is nothing the operand
		// could be handed to. Once functions exist the operand resolves first, which is what
		// lets `{$missing :number}` report both an unresolved variable and a bad operand.
		if ($expression->function !== null) {
			return $this->fallBack(
				ResolutionError::unknownFunction($expression->function->name),
				$expression,
				$errors,
			);
		}

		$arg = $expression->arg;

		if ($arg instanceof Literal) {
			return $arg->value;
		}

		if (!$arg instanceof VariableRef) {
			// Unreachable against a parsed message: the data model requires an operand or a
			// function, and the function was handled above.
			return $this->fallBack(
				ResolutionError::badOperand('the placeholder has neither an operand nor a function'),
				$expression,
				$errors,
			);
		}

		if (!array_key_exists($arg->name, $arguments)) {
			return $this->fallBack(
				ResolutionError::unresolvedVariable($arg->name),
				$expression,
				$errors,
			);
		}

		return $this->asText($arguments[$arg->name], $expression, $errors);
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
	private function asText(mixed $value, Expression $expression, array &$errors): string
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

		return $this->fallBack(
			ResolutionError::badOperand(sprintf(
				'a %s has no text form without a function to give it one',
				get_debug_type($value),
			)),
			$expression,
			$errors,
		);
	}

	/**
	 * @param list<MessageFormatError> $errors
	 * @throws MessageFormatError in strict mode
	 */
	private function fallBack(
		MessageFormatError $error,
		Expression $expression,
		array &$errors,
	): string {
		if ($this->errorHandling === ErrorHandling::Strict) {
			throw $error;
		}

		$errors[] = $error;

		return FallbackString::for($expression);
	}
}
