<?php
declare(strict_types=1);

namespace Meraki\MessageFormat\Error;

use Throwable;

/**
 * Everything this library throws.
 *
 * One interface rather than one base class, because the spec's five error categories map onto
 * different SPL parents: a Syntax Error is a statement about input that was handed over
 * (DomainException), while an Unresolved Variable is a statement about the state at the moment
 * of formatting (RuntimeException). Forcing them under one parent would lose that, and callers
 * who want to catch the lot can catch this.
 */
interface MessageFormatError extends Throwable
{
}
