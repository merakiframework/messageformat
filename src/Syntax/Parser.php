<?php
declare(strict_types=1);

namespace Meraki\MessageFormat\Syntax;

use Meraki\MessageFormat\Error\DataModelError;
use Meraki\MessageFormat\Error\SyntaxError;
use Meraki\MessageFormat\Model\CatchAll;
use Meraki\MessageFormat\Model\Expression;
use Meraki\MessageFormat\Model\FunctionRef;
use Meraki\MessageFormat\Model\InputDeclaration;
use Meraki\MessageFormat\Model\Literal;
use Meraki\MessageFormat\Model\LocalDeclaration;
use Meraki\MessageFormat\Model\Markup;
use Meraki\MessageFormat\Model\MarkupKind;
use Meraki\MessageFormat\Model\Message;
use Meraki\MessageFormat\Model\PatternMessage;
use Meraki\MessageFormat\Model\SelectMessage;
use Meraki\MessageFormat\Model\VariableRef;
use Meraki\MessageFormat\Model\Validator;
use Meraki\MessageFormat\Model\Variant;

/**
 * Recursive descent over the whole grammar.
 *
 * Follows the ABNF production by production, so each method is named after the rule it reads and
 * can be checked against message.abnf by eye. {@see CodePoints} answers which characters are
 * which and {@see Scanner} holds the index; this owns only the order they may come in.
 *
 * ### Whitespace means opposite things in the two kinds of message
 *
 * `simple-message = o [simple-start pattern]` looks like it discards leading whitespace, and it
 * does not. The specification is explicit: *"Whitespace at the start or end of a simple message
 * is significant, and a part of the text of the message."* The `o` is there so the first
 * **non**-whitespace character can be constrained by `simple-start-char`, which forbids `.` and
 * so makes a leading dot unambiguously the start of a complex message. What it matched is still
 * text.
 *
 * `complex-message = o *(declaration o) complex-body o` is the opposite: *"Whitespace at the
 * start or end of a complex message is not significant."* So `"  {{}}  "` formats to the empty
 * string while `"\n hello\t"` formats to itself.
 *
 * Inside `{{…}}` the rules change again, because the content is an ordinary `pattern`: there a
 * leading space is text and a leading dot is text, which is why `{{ .local $x = {$y}}}` formats
 * to `" .local $x = Y"` rather than declaring anything.
 *
 * Those three readings of the same characters are decided before anything is parsed, in
 * {@see self::message()}, and each is pinned by a fixture.
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
	private const ASTERISK = 0x2A;

	/** `escaped-char = backslash ( backslash / "{" / "|" / "}" )` */
	private const ESCAPABLE = [self::BACKSLASH, self::BRACE_OPEN, self::PIPE, self::BRACE_CLOSE];

	/** Every keyword is six code points, which is why advancing past one needs no measuring. */
	private const KEYWORD_LENGTH = 6;

	private function __construct(private readonly Scanner $scanner)
	{
	}

	/**
	 * @throws SyntaxError if the source is not well-formed
	 * @throws DataModelError if it is well-formed and still not a valid message
	 */
	public static function parse(string $source): Message
	{
		$message = (new self(Scanner::over($source)))->message();

		// Validity is checked here so that this is the only door, and everything past it
		// holds a message that is both well-formed and valid. A caller that could get an
		// unvalidated model would eventually format one.
		Validator::check($message);

		return $message;
	}

	/** `message = simple-message / complex-message` */
	private function message(): Message
	{
		// Collected rather than discarded, because for a simple message it is text. For a complex
		// one it is dropped, which is why the two are told apart before either is parsed.
		$leading = $this->scanner->takeWhile(
			static fn(int $point): bool => CodePoints::isWhitespace($point) || CodePoints::isBidi($point),
		);

		$next = $this->scanner->peek();

		if ($next === self::DOT || ($next === self::BRACE_OPEN && $this->scanner->peek(1) === self::BRACE_OPEN)) {
			return $this->complexMessage();
		}

		if ($next === null) {
			// `o` with nothing after it. Still a simple message, and still that text.
			return new PatternMessage($leading === [] ? [] : [Utf8::encode($leading)]);
		}

		// `simple-start = simple-start-char / escaped-char / placeholder`
		if (
			!CodePoints::isSimpleStart($next)
			&& $next !== self::BACKSLASH
			&& $next !== self::BRACE_OPEN
		) {
			throw SyntaxError::expected('the start of a message', $next, $this->scanner->position());
		}

		return new PatternMessage($this->pattern($leading));
	}

	/** `complex-message = o *(declaration o) complex-body o` — the leading `o` is already read. */
	private function complexMessage(): Message
	{
		$declarations = [];

		while ($this->scanner->peek() === self::DOT) {
			// `.match` ends the declarations and begins the body. Checked before trying to read a
			// declaration, because otherwise the declaration reader would reject it by name.
			if ($this->looksLike('.match')) {
				break;
			}

			$declarations[] = $this->declaration();
			$this->optional();
		}

		$body = $this->scanner->peek() === self::DOT
			? $this->matcher($declarations)
			: new PatternMessage($this->quotedPattern(), $declarations);

		$this->optional();

		if (!$this->scanner->atEnd()) {
			throw SyntaxError::expected(
				'the end of the message, because the body is already complete',
				$this->scanner->peek(),
				$this->scanner->position(),
			);
		}

		return $body;
	}

	/** `declaration = input-declaration / local-declaration` */
	private function declaration(): InputDeclaration|LocalDeclaration
	{
		if ($this->looksLike('.input')) {
			// `input-declaration = input o variable-expression` -- `o`, so `.input{$x}` is legal.
			$this->scanner->advance(self::KEYWORD_LENGTH);
			$this->optional();

			$at = $this->scanner->position();
			$expression = $this->expressionIn('an input declaration');
			$operand = $expression->arg;

			if (!$operand instanceof VariableRef) {
				// The schema narrows the expression's `arg` to a variable, and the declared name
				// comes from it, so there is nothing to declare without one.
				throw SyntaxError::expected('a variable in the input declaration', null, $at);
			}

			return new InputDeclaration($operand->name, $expression);
		}

		if ($this->looksLike('.local')) {
			// `local-declaration = local s variable o "=" o expression` -- the `s` is the only
			// required whitespace in either declaration.
			$this->scanner->advance(self::KEYWORD_LENGTH);

			if (!$this->required()) {
				throw SyntaxError::expected(
					'whitespace after ".local"',
					$this->scanner->peek(),
					$this->scanner->position(),
				);
			}

			$variable = $this->variable();
			$this->optional();
			$this->scanner->expect(self::EQUALS, 'an "=" after the declared variable');
			$this->optional();

			return new LocalDeclaration($variable->name, $this->expressionIn('a local declaration'));
		}

		throw SyntaxError::expected(
			'".input", ".local" or ".match"',
			$this->scanner->peek(1),
			$this->scanner->position(),
		);
	}

	/** A declaration's value: a placeholder that must be an expression rather than markup. */
	private function expressionIn(string $what): Expression
	{
		$at = $this->scanner->position();
		$placeholder = $this->placeholder();

		if ($placeholder instanceof Markup) {
			throw SyntaxError::expected('an expression in ' . $what . ', not markup', null, $at);
		}

		return $placeholder;
	}

	/**
	 * `quoted-pattern = "{{" pattern "}}"`
	 *
	 * @return list<string|Expression|Markup>
	 */
	private function quotedPattern(): array
	{
		$at = $this->scanner->position();
		$this->scanner->expect(self::BRACE_OPEN, 'a quoted pattern');
		$this->scanner->expect(self::BRACE_OPEN, 'the second "{" of a quoted pattern');

		$elements = $this->pattern([], quoted: true);

		if ($this->scanner->peek() !== self::BRACE_CLOSE || $this->scanner->peek(1) !== self::BRACE_CLOSE) {
			throw SyntaxError::expected('a "}}" to close the quoted pattern', $this->scanner->peek(), $at);
		}

		$this->scanner->advance(2);

		return $elements;
	}

	/**
	 * `matcher = match-statement s variant *(o variant)`
	 *
	 * @param list<InputDeclaration|LocalDeclaration> $declarations
	 */
	private function matcher(array $declarations): SelectMessage
	{
		$this->scanner->expect(self::DOT, 'a matcher');
		$this->scanner->advance(self::KEYWORD_LENGTH - 1);

		// `match-statement = match 1*(s selector)`, and `selector = variable`.
		$selectors = [];
		$spaced = false;

		while (true) {
			$spaced = $this->required();

			if ($this->scanner->peek() !== self::DOLLAR) {
				break;
			}

			if (!$spaced) {
				throw SyntaxError::expected(
					'whitespace before a selector',
					$this->scanner->peek(),
					$this->scanner->position(),
				);
			}

			$selectors[] = $this->variable();
		}

		if ($selectors === []) {
			throw SyntaxError::expected(
				'at least one selector after ".match"',
				$this->scanner->peek(),
				$this->scanner->position(),
			);
		}

		// `matcher = match-statement s variant *(o variant)`: the whitespace before the **first**
		// variant is required, and the loop above has already measured whatever followed the last
		// selector. So `.match $x* {{foo}}` is a syntax error, and so is a separator made only of
		// bidi marks — syntax-errors.json 58 and 59 and bidi.json[11].
		if (!$spaced) {
			throw SyntaxError::expected(
				'whitespace between the last selector and the first variant',
				$this->scanner->peek(),
				$this->scanner->position(),
			);
		}

		$variants = [];

		while ($this->startsAKey($this->scanner->peek())) {
			$variants[] = $this->variant();
			$this->optional();
		}

		if ($variants === []) {
			throw SyntaxError::expected(
				'at least one variant after the selectors',
				$this->scanner->peek(),
				$this->scanner->position(),
			);
		}

		return new SelectMessage($selectors, $variants, $declarations);
	}

	/**
	 * Whether a key could start here.
	 *
	 * `*` is not a name-char — name-start begins at `+` — so the catch-all can never be confused
	 * with an unquoted literal, and `|*|` is a different key from `*`.
	 */
	private function startsAKey(?int $point): bool
	{
		return $point !== null
			&& ($point === self::ASTERISK || $point === self::PIPE || CodePoints::isNameChar($point));
	}

	/** `variant = key *(s key) o quoted-pattern` */
	private function variant(): Variant
	{
		$keys = [$this->variantKey()];

		while (true) {
			$spaced = $this->required();
			$point = $this->scanner->peek();

			if ($point === self::BRACE_OPEN) {
				break;
			}

			if ($point === null) {
				throw SyntaxError::expected('a quoted pattern for this variant', null, $this->scanner->position());
			}

			if (!$spaced) {
				throw SyntaxError::expected('whitespace between variant keys', $point, $this->scanner->position());
			}

			$keys[] = $this->variantKey();
		}

		return new Variant($keys, $this->quotedPattern());
	}

	/** `key = literal / "*"` */
	private function variantKey(): Literal|CatchAll
	{
		if ($this->scanner->peek() === self::ASTERISK) {
			$this->scanner->advance();

			return new CatchAll();
		}

		return $this->literal();
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
	 * @param bool $quoted true inside `{{…}}`, where `}}` ends the pattern and a lone `}` is still
	 *        an error — the same two characters mean different things in the two contexts
	 * @return list<string|Expression|Markup>
	 */
	private function pattern(array $leading, bool $quoted = false): array
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
				if ($quoted && $this->scanner->peek(1) === self::BRACE_CLOSE) {
					break;
				}

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
		$this->optional();

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
			$spaced = $this->required();

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
				$spaced = $this->required();
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
				[$name, $value, $spacedAfter] = $this->attribute();

				// Not checked for duplicates. errors.md names Duplicate Option Name for options
				// and nothing at all for attributes, so a repeated attribute is valid and the
				// later one simply wins. Refusing it would reject a message the spec accepts.
				$attributes[$name] = $value;

				// A valueless attribute swallows the whitespace that separates it from the next
				// item, so it hands back whether it did.
				$spaced = $spacedAfter;
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

			// A Data Model Error, not a Syntax Error. `bad {:p option=x option=x}` is
			// well-formed -- every production is satisfied -- and still not a valid message, and
			// data-model-errors.json expects `duplicate-option-name` for exactly it.
			if (array_key_exists($name, $options)) {
				throw DataModelError::duplicateOptionName($name);
			}

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
		$this->optional();
		$this->scanner->expect(self::EQUALS, 'an "=" after the option name');
		$this->optional();

		$value = $this->scanner->peek() === self::DOLLAR ? $this->variable() : $this->literal();

		return [$name, $value];
	}

	/**
	 * `attribute = "@" identifier [o "=" o literal]`
	 *
	 * @return array{string, Literal|true, bool} the name, the value, and whether trailing
	 *         whitespace was consumed — which the caller needs, because the `o` before a possible
	 *         `=` and the `s` before the next item are the same characters and there is no rewind
	 */
	private function attribute(): array
	{
		$this->scanner->expect(self::AT, 'an attribute');
		$name = $this->identifier();
		$spacedAfter = $this->required();

		if (!$this->scanner->take(self::EQUALS)) {
			// No value, so that whitespace was the `s` before whatever comes next. Reporting it
			// is what stops `{42 @foo @bar=13}` being rejected for a missing space that was in
			// fact eaten here. An earlier comment claimed the trailer could just read whitespace
			// again and get "the same answer"; it cannot, because the trailer uses "no
			// whitespace" to mean "required space missing". The conformance suite caught it
			// twice, in syntax.json and functions/number.json.
			return [$name, true, $spacedAfter];
		}

		$this->optional();

		return [$name, $this->literal(), false];
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

	/** `o = *(ws / bidi)` — optional whitespace, where a bidi mark counts. */
	private function optional(): void
	{
		$this->scanner->takeWhile(
			static fn(int $point): bool => CodePoints::isWhitespace($point) || CodePoints::isBidi($point),
		);
	}

	/**
	 * `s = *bidi ws o` — required whitespace, which a bidi mark alone does **not** satisfy.
	 *
	 * The distinction is easy to miss and the conformance suite is strict about it: in
	 * `.match $x` followed immediately by `*`, and in `.match $x` separated from its variant by
	 * nothing but U+061C ARABIC LETTER MARK, there is no `s` and both are syntax errors.
	 * Treating the two as one helper accepted both — bidi.json[11] and syntax-errors.json
	 * 58 and 59 caught it.
	 *
	 * Whatever bidi marks precede the whitespace are consumed either way, which is harmless:
	 * `o` admits them, so a caller that finds no `s` has lost nothing it could have used.
	 *
	 * @return bool whether an actual whitespace character was there
	 */
	private function required(): bool
	{
		$this->scanner->takeWhile(CodePoints::isBidi(...));

		$point = $this->scanner->peek();

		if ($point === null || !CodePoints::isWhitespace($point)) {
			return false;
		}

		$this->scanner->advance();
		$this->optional();

		return true;
	}
}
