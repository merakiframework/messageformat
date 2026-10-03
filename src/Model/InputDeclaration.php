<?php
declare(strict_types=1);

namespace Meraki\MessageFormat\Model;

/**
 * `{"type": "input", "name": "...", "value": {...}}`
 *
 * The expression's operand **is** the declared variable: `.input {$x :number}` declares `x` and
 * annotates the external argument of that name. So the operand is not a *use* of `x` — treating
 * it as one would make every input declaration a self-reference, and self-reference is a
 * Duplicate Declaration error.
 */
final class InputDeclaration
{
	public function __construct(
		public readonly string $name,
		public readonly Expression $value,
	) {
	}
}
