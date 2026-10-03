<?php
declare(strict_types=1);

namespace Meraki\MessageFormat;

/**
 * Two doors onto one engine.
 *
 * Fallback is what the specification requires of a formatter: a valid message always produces a
 * result, and an expression that cannot be resolved contributes a fallback string rather than
 * stopping the whole message. A reader missing one value still gets the other words.
 *
 * Strict is for the other audience. When a message catalogue is being built or linted, a
 * fallback that reaches a reader is the thing you are trying to prevent, so the first error
 * should stop the build rather than be rendered.
 *
 * Neither affects a Syntax Error. A malformed message has no data model, so there is nothing to
 * fall back to and both modes raise.
 */
enum ErrorHandling
{
	case Fallback;
	case Strict;
}
