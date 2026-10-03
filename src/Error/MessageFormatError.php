<?php
declare(strict_types=1);

namespace Meraki\MessageFormat\Error;

use Throwable;

/**
 * Everything this library throws.
 *
 * One interface rather than one base class, because the specification's error categories map
 * onto different SPL parents: a Syntax Error is a statement about input that was handed over
 * (DomainException), while an Unresolved Variable is a statement about the state at the moment
 * of formatting (RuntimeException). Forcing them under one parent would lose that, and callers
 * who want the lot can catch this.
 */
interface MessageFormatError extends Throwable
{
	/**
	 * Which of the specification's errors this is, or null if it is not one of them.
	 *
	 * Nullable for {@see Unsupported}, which reports a feature this library has not built yet.
	 * That is a fact about the implementation rather than about the message, so it has no slug
	 * and the conformance runner must never match a fixture against it.
	 */
	public function type(): ?ErrorType;
}
