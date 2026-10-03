<?php
declare(strict_types=1);

namespace Meraki\MessageFormat\Model;

/** `{"keys": [...], "value": [...]}` — one arm of a matcher. */
final class Variant
{
	/**
	 * @param list<Literal|CatchAll> $keys one per selector, which the validator enforces
	 * @param list<string|Expression|Markup> $value the pattern to format if this arm is chosen
	 */
	public function __construct(
		public readonly array $keys,
		public readonly array $value,
	) {
	}
}
