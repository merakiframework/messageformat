<?php
declare(strict_types=1);

namespace Meraki\MessageFormat\Syntax;

use Meraki\MessageFormat\Error\SyntaxError;
use Meraki\MessageFormat\Error\Unsupported;
use Meraki\MessageFormat\Model\Expression;
use Meraki\MessageFormat\Model\FunctionRef;
use Meraki\MessageFormat\Model\Literal;
use Meraki\MessageFormat\Model\Markup;
use Meraki\MessageFormat\Model\MarkupKind;
use Meraki\MessageFormat\Model\Message;
use Meraki\MessageFormat\Model\VariableRef;

/**
 * Recursive descent over `simple-message`.
 *
 * Follows the ABNF production by production, so each method is named after the rule it reads and
 * can be checked against message.abnf by eye. {@see CodePoints} answers which characters are
 * which and {@see Scanner} holds the index; this owns only the order they may come in.
 *
 * ### The leading whitespace trap
 *
 * `simple-message = o [simple-start pattern]` looks like it discards leading whitespace, and it
 * does not. The specification is explicit: *"Whitespace at the start or end of a simple message
 * is significant, and a part of the text of the message."* The `o` is there so the first
 * **non**-whitespace character can be constrained by `simple-start-char`, which forbids `.` and
 * so makes a leading dot unambiguously the start of a complex message. What it matched is still
 * text.
 *
 * A complex message is the opposite — its leading and trailing whitespace is insignificant —
 * which is why the two are decided before either is parsed.
 */
final class Parser
{
	private const BRACE_OPEN = 0x7B;
	private const BRACE_CLOSE = 0x7D;
	private const BACKSLASH = 0x5C;
	private const PIPE = 0x7C;
	private const DOLLAR = 0x24;
	private const COLON = 0x3A;
	private const AT = 0x40;
	private const HASH = 0x23;
	private const SLASH = 0x2F;
	private const DOT = 0x2E;
	private const EQUALS = 0x3D;

	/** `escaped-char = backslash ( backslash / "{" / "|" / "}" )` */
	private const ESCAPABLE = [self::BACKSLASH, self::BRACE_OPEN, self::PIPE, self::BRACE_CLOSE];

	private function __construct(private readonly Scanner $scanner)
	{
	}

	/**
	 * @throws SyntaxError if the source is not well-formed
	 * @throws Unsupported if it is a complex message, which is valid but not implemented yet
	 */
	public static function parse(string $source): Message
	{
		return (new self(Scanner::over($source)))->message();
	}

	/** `message = simple-message / complex-message` */
	private function message(): Message
	{
		// Collected, not discarded: for a simple message this is text. See the class docblock.
		$leading = $this->scanner->takeWhile(
			static fn(int $point): bool => CodePoints::isWhitespace($point) || CodePoints::isBidi($point),
		);

		$next = $this->scanner->peek();

		// A quoted pattern or a declaration keyword means complex-message, whose leading
		// whitespace is insignificant -- so $leading is dropped on these two paths.
		if ($next === self::BRACE_OPEN && $this->scanner->peek(1) === self::BRACE_OPEN) {
			throw Unsupported::feature('a quoted pattern');
		}

		if ($next === self::DOT) {
			throw $this->declarationOrNothing();
		}

		if ($next === null) {
			// `o` with nothing after it. Still a simple message, and still that text.
			return new Message($leading === [] ? [] : [Utf8::encode($leading)]);
		}

		// `simple-start = simple-start-char / escaped-char / placeholder`
		if (
			!CodePoints::isSimpleStart($next)
			&& $next !== self::BACKSLASH
			&& $next !== self::BRACE_OPEN
		) {
			throw SyntaxError::expected(
				'the start of a message',
				$next,
				$this->scanner->position(),
			);
		}

		return new Message($this->pattern($leading));
	}

	/**
	 * Which of the two complex-message openings this is, so the refusal can name it.
	 *
	 * Returns the exception rather than throwing it, so the caller's `throw` keeps the control
	 * flow visible at the call site.
	 */
	private function declarationOrNothing(): SyntaxError|Unsupported
	{
		foreach (['.input' => 'an input declaration', '.local' => 'a local declaration', '.match' => 'a matcher'] as $keyword => $named) {
			if ($this->looksLike($keyword)) {
				return Unsupported::feature($named);
			}
		}

		// A dot that begins no keyword is not a complex message waiting for a later milestone.
		// It is not a message at all.
		return SyntaxError::expected(
			'a declaration or a matcher after "."',
			$this->scanner->peek(1),
			$this->scanner->position(),
		);
	}

	/** Whether the cursor sits on this exact ASCII keyword. Keywords are case-sensitive. */
	private function looksLike(string $keyword): bool
	{
		foreach (Utf8::decode($keyword) as $ahead => $point) {
			if ($this->scanner->peek($ahead) !== $point) {
				return false;
			}
		}

		return true;
	}

	/**
	 * `pattern = *(text-char / escaped-char / placeholder)`
	 *
	 * @param list<int> $leading code points already read, which belong to the first text run
	 * @return list<string|Expression|Markup>
	 */
	private function pattern(array $leading): array
	{
		$elements = [];
		$text = $leading;

		while (!$this->scanner->atEnd()) {
			$point = $this->scanner->peek();

			if ($point === self::BRACE_OPEN) {
				if ($text !== []) {
					$elements[] = Utf8::encode($text);
					$text = [];
				}

				$elements[] = $this->placeholder();
				continue;
			}

			if ($point === self::BRACE_CLOSE) {
				throw SyntaxError::expected(
					'text or a placeholder (write "\\}" for a literal brace)',
					$point,
					$this->scanner->position(),
				);
			}

			if ($point === self::BACKSLASH) {
				$text[] = $this->escapedChar();
				continue;
			}

			if ($point === null || !CodePoints::isText($point)) {
				throw SyntaxError::expected('text', $point, $this->scanner->position());
			}

			$text[] = $point;
			$this->scanner->advance();
		}

		if ($text !== []) {
			$elements[] = Utf8::encode($text);
		}

		return $elements;
	}

	/** `escaped-char = backslash ( backslash / "{" / "|" / "}" )` */
	private function escapedChar(): int
	{
		$at = $this->scanner->position();
		$this->scanner->advance();
		$escaped = $this->scanner->consume();

		if ($escaped === null || !in_array($escaped, self::ESCAPABLE, true)) {
			throw SyntaxError::expected('one of \\\\, \\{, \\| or \\} after a backslash', $escaped, $at);
		}

		return $escaped;
	}

	/** `placeholder = expression / markup` */
	private function placeholder(): Expression|Markup
	{
		$this->scanner->expect(self::BRACE_OPEN, 'a placeholder');
		$this->skipWhitespace();

		$point = $this->scanner->peek();

		if ($point === self::HASH || $point === self::SLASH) {
			return $this->markup();
		}

		return $this->expression();
	}

	/**
	 * `literal-expression / variable-expression / function-expression`
	 *
	 * One method for three productions, because they differ only in what the operand is and the
	 * data model joins them into one node.
	 */
	private function expression(): Expression
	{
		$point = $this->scanner->peek();

		if ($point === null || $point === self::BRACE_CLOSE) {
			throw SyntaxError::expected(
				'a literal, a variable or a function inside the placeholder',
				$point,
				$this->scanner->position(),
			);
		}

		$arg = null;
		$function = null;
		$attributes = [];

		if ($point === self::COLON) {
			// function-expression: no operand, and no `s` before the function.
			[$function, $attributes] = $this->functionAndTrailer();
		} else {
			$arg = $point === self::DOLLAR ? $this->variable() : $this->literal();
			$spaced = $this->skipWhitespace() > 0;

			if ($spaced && $this->scanner->peek() === self::COLON) {
				[$function, $attributes] = $this->functionAndTrailer();
			} else {
				// `*(s attribute)` only. An identifier here would need an `s function` before
				// it, so the trailer refuses options.
				[, $attributes] = $this->trailer($spaced, allowOptions: false);
			}
		}

		$this->scanner->expect(self::BRACE_CLOSE, 'the end of the placeholder');

		return new Expression($arg, $function, $attributes);
	}

	/** `markup` — open, standalone or close. */
	private function markup(): Markup
	{
		$opener = $this->scanner->consume();
		$name = $this->identifier();
		[$options, $attributes] = $this->trailer(false);

		$standalone = false;

		if ($this->scanner->peek() === self::SLASH) {
			if ($opener === self::SLASH) {
				throw SyntaxError::expected(
					'the end of closing markup, which cannot also be standalone',
					$this->scanner->peek(),
					$this->scanner->position(),
				);
			}

			$this->scanner->advance();
			$standalone = true;
		}

		$this->scanner->expect(self::BRACE_CLOSE, 'the end of the markup');

		$kind = match (true) {
			$opener === self::SLASH => MarkupKind::Close,
			$standalone => MarkupKind::Standalone,
			default => MarkupKind::Open,
		};

		return new Markup($kind, $name, $options, $attributes);
	}

	/**
	 * `function = ":" identifier *(s option)`, plus the `*(s attribute)` that follows it.
	 *
	 * Both at once, and that is the point. The options belong to the function and the attributes
	 * to the expression around it, but they arrive in one token stream and are told apart only
	 * by the `@`. Reading the function without its options — as an earlier version of this did —
	 * silently dropped every option on a function-expression, because the caller took the
	 * attributes from the trailer and threw the options away.
	 *
	 * @return array{FunctionRef, array<string, Literal|true>}
	 */
	private function functionAndTrailer(): array
	{
		$this->scanner->expect(self::COLON, 'a function name');
		$name = $this->identifier();
		[$options, $attributes] = $this->trailer(false);

		return [new FunctionRef($name, $options), $attributes];
	}

	/**
	 * `*(s option) *(s attribute) o` — everything between an operand and the closing brace.
	 *
	 * One loop for options and attributes because they share the token stream: in
	 * `{$n :number minFrac=2 @attr}` the option belongs to the function and the attribute to the
	 * expression, but they are read by the same pass. Reading them separately would mean the
	 * option pass consuming the whitespace that `s attribute` requires, and then having to give
	 * it back.
	 *
	 * @param bool $alreadySpaced true when the caller has already consumed the `s` that would
	 *        precede the first item
	 * @return array{array<string, Literal|VariableRef>, array<string, Literal|true>}
	 */
	private function trailer(bool $alreadySpaced, bool $allowOptions = true): array
	{
		$options = [];
		$attributes = [];
		$spaced = $alreadySpaced;

		while (true) {
			if (!$spaced) {
				$spaced = $this->skipWhitespace() > 0;
			}

			$point = $this->scanner->peek();

			// The whitespace just read was the trailing `o`, not an `s` before an item.
			if ($point === null || $point === self::BRACE_CLOSE || $point === self::SLASH) {
				return [$options, $attributes];
			}

			if (!$spaced) {
				throw SyntaxError::expected(
					'whitespace before an option or an attribute',
					$point,
					$this->scanner->position(),
				);
			}

			if ($point === self::AT) {
				[$name, $value] = $this->attribute();
				$this->refuseDuplicate($attributes, $name, 'attribute');
				$attributes[$name] = $value;
				$spaced = false;
				continue;
			}

			if ($attributes !== []) {
				// `*(s option) *(s attribute)`: once attributes start, options are over.
				throw SyntaxError::expected(
					'an attribute, because options cannot follow one',
					$point,
					$this->scanner->position(),
				);
			}

			if (!$allowOptions) {
				throw SyntaxError::expected(
					'an attribute, or a function for these options to belong to',
					$point,
					$this->scanner->position(),
				);
			}

			[$name, $value] = $this->option();
			$this->refuseDuplicate($options, $name, 'option');
			$options[$name] = $value;
			$spaced = false;
		}
	}

	/**
	 * `option = identifier o "=" o (literal / variable)`
	 *
	 * @return array{string, Literal|VariableRef}
	 */
	private function option(): array
	{
		$name = $this->identifier();
		$this->skipWhitespace();
		$this->scanner->expect(self::EQUALS, 'an "=" after the option name');
		$this->skipWhitespace();

		$value = $this->scanner->peek() === self::DOLLAR ? $this->variable() : $this->literal();

		return [$name, $value];
	}

	/**
	 * `attribute = "@" identifier [o "=" o literal]`
	 *
	 * @return array{string, Literal|true}
	 */
	private function attribute(): array
	{
		$this->scanner->expect(self::AT, 'an attribute');
		$name = $this->identifier();
		$this->skipWhitespace();

		if (!$this->scanner->take(self::EQUALS)) {
			// No value. The whitespace just read may have been the `s` before the next item, so
			// nothing is consumed here that the trailer needs -- it reads whitespace again and
			// finds none, which is the same answer.
			return [$name, true];
		}

		$this->skipWhitespace();

		return [$name, $this->literal()];
	}

	/** `variable = "$" name` */
	private function variable(): VariableRef
	{
		$this->scanner->expect(self::DOLLAR, 'a variable');

		return new VariableRef($this->name('a variable name'));
	}

	/** `literal = quoted-literal / unquoted-literal` */
	private function literal(): Literal
	{
		if ($this->scanner->peek() === self::PIPE) {
			return new Literal($this->quotedLiteral());
		}

		// `unquoted-literal = 1*name-char` -- note name-char, not name, so a digit may lead.
		$run = $this->scanner->takeWhile(CodePoints::isNameChar(...));

		if ($run === []) {
			throw SyntaxError::expected('a literal', $this->scanner->peek(), $this->scanner->position());
		}

		return new Literal(Utf8::encode($run));
	}

	/** `quoted-literal = "|" *(quoted-char / escaped-char) "|"` */
	private function quotedLiteral(): string
	{
		$at = $this->scanner->position();
		$this->scanner->expect(self::PIPE, 'a quoted literal');
		$points = [];

		while (true) {
			$point = $this->scanner->peek();

			if ($point === null) {
				throw SyntaxError::expected('a "|" to close the quoted literal', null, $at);
			}

			if ($point === self::PIPE) {
				$this->scanner->advance();

				return Utf8::encode($points);
			}

			if ($point === self::BACKSLASH) {
				$points[] = $this->escapedChar();
				continue;
			}

			if (!CodePoints::isQuoted($point)) {
				throw SyntaxError::expected(
					'a character allowed in a quoted literal',
					$point,
					$this->scanner->position(),
				);
			}

			$points[] = $point;
			$this->scanner->advance();
		}
	}

	/** `identifier = [namespace ":"] name` */
	private function identifier(): string
	{
		$name = $this->name('a name');

		if ($this->scanner->peek() !== self::COLON) {
			return $name;
		}

		$this->scanner->advance();

		return $name . ':' . $this->name('a name after the namespace');
	}

	/**
	 * `name = [bidi] name-start *name-char [bidi]`
	 *
	 * The surrounding bidi marks are read and dropped. They are display controls rather than
	 * part of what the name identifies, and keeping them would make `$x` and a bidi-wrapped `$x`
	 * two different variables. bidi.json settles this properly when directionality lands.
	 */
	private function name(string $what): string
	{
		$this->scanner->takeWhile(CodePoints::isBidi(...));
		$start = $this->scanner->peek();

		if ($start === null || !CodePoints::isNameStart($start)) {
			throw SyntaxError::expected($what, $start, $this->scanner->position());
		}

		$run = $this->scanner->takeWhile(CodePoints::isNameChar(...));
		$this->scanner->takeWhile(CodePoints::isBidi(...));

		return Utf8::encode($run);
	}

	/** `o = *(ws / bidi)`, and the count is how a caller tells `s` from `o`. */
	private function skipWhitespace(): int
	{
		return count($this->scanner->takeWhile(
			static fn(int $point): bool => CodePoints::isWhitespace($point) || CodePoints::isBidi($point),
		));
	}

	/**
	 * @param array<string, mixed> $seen
	 * @throws SyntaxError
	 */
	private function refuseDuplicate(array $seen, string $name, string $kind): void
	{
		if (array_key_exists($name, $seen)) {
			throw SyntaxError::expected(
				sprintf('a %s other than "%s", which is already set', $kind, $name),
				$this->scanner->peek(),
				$this->scanner->position(),
			);
		}
	}
}
