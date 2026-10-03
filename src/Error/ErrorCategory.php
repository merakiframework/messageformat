<?php
declare(strict_types=1);

namespace Meraki\MessageFormat\Error;

/**
 * The specification's five error categories.
 *
 * The order matters: "implementations MUST prioritise Syntax Errors and Data Model Errors over
 * others" when a message has several. Declaration order here is that priority.
 *
 * errors.md also names a Selection category in passing, and nothing belongs to it: Bad
 * Selector is grouped under Resolution and Bad Variant Key under Message Function. It is left
 * out rather than carried as a case nothing can ever be.
 */
enum ErrorCategory
{
	case Syntax;
	case DataModel;
	case Resolution;
	case MessageFunction;
}
