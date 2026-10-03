<?php
declare(strict_types=1);

namespace Meraki\MessageFormat\Model;

/**
 * `{"type": "message", "declarations": [...], "pattern": [...]}`
 *
 * A message with a single pattern. The data model's other top-level shape, `select`, arrives
 * with the matcher; until then `declarations` is always empty, and it is present rather than
 * omitted because the data model requires the key.
 */
final class Message
{
	/** @param list<string|Expression|Markup> $pattern text runs, placeholders and markup, in order */
	public function __construct(public readonly array $pattern = [])
	{
	}
}
