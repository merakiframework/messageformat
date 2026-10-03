<?php
declare(strict_types=1);

namespace Meraki\MessageFormat\Model;

/**
 * `{"type": "select", "declarations": [...], "selectors": [...], "variants": [...]}`
 *
 * A selector is always a variable — the ABNF says `selector = variable`, with no literal and no
 * inline expression. That is why a matcher needs a declaration to annotate: the function that
 * makes selection possible can only be attached in a `.input` or `.local`, never in the
 * `.match` itself.
 */
final class SelectMessage implements Message
{
	/**
	 * @param list<VariableRef> $selectors
	 * @param list<Variant> $variants in source order, which is not the order they are tried
	 * @param list<InputDeclaration|LocalDeclaration> $declarations
	 */
	public function __construct(
		public readonly array $selectors,
		public readonly array $variants,
		private readonly array $declarations = [],
	) {
	}

	/** @return list<InputDeclaration|LocalDeclaration> */
	public function declarations(): array
	{
		return $this->declarations;
	}
}
