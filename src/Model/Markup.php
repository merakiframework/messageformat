<?php
declare(strict_types=1);

namespace Meraki\MessageFormat\Model;

/**
 * `{"type": "markup", "kind": ..., "name": ..., "options": {...}, "attributes": {...}}`
 *
 * MessageFormat 2 defines no markup vocabulary — no tag names, no meaning. It carries whatever
 * the application puts there and hands it back through the formatted parts, which is why this
 * node is pure data with no notion of being balanced or valid.
 */
final class Markup
{
	/**
	 * @param array<string, Literal|VariableRef> $options
	 * @param array<string, Literal|true> $attributes
	 */
	public function __construct(
		public readonly MarkupKind $kind,
		public readonly string $name,
		public readonly array $options = [],
		public readonly array $attributes = [],
	) {
	}
}
