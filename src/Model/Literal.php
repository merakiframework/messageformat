<?php
declare(strict_types=1);

namespace Meraki\MessageFormat\Model;

/** `{"type": "literal", "value": "..."}` */
final class Literal
{
	public function __construct(public readonly string $value)
	{
	}
}
