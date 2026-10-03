<?php
declare(strict_types=1);

namespace Meraki\MessageFormat\Model;

/**
 * `{"type": "function", "name": "...", "options": {...}}`
 *
 * Named FunctionRef rather than Function because `function` is a reserved word and cannot be a
 * class name. The data model calls this type "function"; {@see Serializer} emits that.
 */
final class FunctionRef
{
	/** @param array<string, Literal|VariableRef> $options in source order */
	public function __construct(
		public readonly string $name,
		public readonly array $options = [],
	) {
	}
}
