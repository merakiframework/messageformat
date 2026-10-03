<?php
declare(strict_types=1);

namespace Meraki\MessageFormat\Model;

/** `{"type": "variable", "name": "..."}` — the name without its `$`. */
final class VariableRef
{
	public function __construct(public readonly string $name)
	{
	}
}
