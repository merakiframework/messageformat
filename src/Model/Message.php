<?php
declare(strict_types=1);

namespace Meraki\MessageFormat\Model;

/**
 * Either of the data model's two top-level shapes.
 *
 * message.json is `oneOf [message, select]`, and the two differ in how they choose what to
 * format: {@see PatternMessage} has one pattern, {@see SelectMessage} picks between several. What
 * they share is declarations, which is what this interface carries — and it is the whole of it,
 * because a shared method nothing needs would be speculation.
 *
 * Named for the specification's `message`, not for the ABNF's `simple-message`. Those are
 * different cuts: an ABNF `complex-message` is a quoted pattern *or* a matcher, so it becomes
 * either shape depending on which.
 */
interface Message
{
	/** @return list<InputDeclaration|LocalDeclaration> in source order */
	public function declarations(): array;
}
