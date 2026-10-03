<?php
declare(strict_types=1);

namespace Meraki\MessageFormat\Model;

/**
 * `{"type": "local", "name": "...", "value": {...}}`
 *
 * Unlike an input declaration, everything in the expression is a genuine use: `.local $x = {$y}`
 * uses `y`, and `.local $x = {$x}` uses `x` while declaring it, which is why that is a Duplicate
 * Declaration rather than a sensible identity.
 */
final class LocalDeclaration
{
	public function __construct(
		public readonly string $name,
		public readonly Expression $value,
	) {
	}
}
