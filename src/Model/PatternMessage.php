<?php
declare(strict_types=1);

namespace Meraki\MessageFormat\Model;

/** `{"type": "message", "declarations": [...], "pattern": [...]}` */
final class PatternMessage implements Message
{
	/**
	 * @param list<string|Expression|Markup> $pattern text runs, placeholders and markup, in order
	 * @param list<InputDeclaration|LocalDeclaration> $declarations
	 */
	public function __construct(
		public readonly array $pattern = [],
		private readonly array $declarations = [],
	) {
	}

	/** @return list<InputDeclaration|LocalDeclaration> */
	public function declarations(): array
	{
		return $this->declarations;
	}
}
