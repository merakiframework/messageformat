<?php
declare(strict_types=1);

namespace Meraki\MessageFormat\Model;

/** The three shapes markup comes in. Backed by the strings the data model uses. */
enum MarkupKind: string
{
	case Open = 'open';
	case Standalone = 'standalone';
	case Close = 'close';
}
