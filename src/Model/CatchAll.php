<?php
declare(strict_types=1);

namespace Meraki\MessageFormat\Model;

/**
 * `{"type": "*"}` — the variant key that matches anything.
 *
 * A type of its own rather than a Literal holding `*`, because the two are different keys and
 * the specification is explicit about it: `|*|` is the literal asterisk and `*` is the catch-all,
 * and a message using both is not using the same key twice.
 */
final class CatchAll
{
}
